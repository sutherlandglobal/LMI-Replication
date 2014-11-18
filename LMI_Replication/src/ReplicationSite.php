<?php

class ReplicationSite
{
	private $targetTable;
	private $dateField;
	private $startDate;
	private $endDate;
	private $lmiStartDate;

	private $host;
	private $user;
	private $pass;
	private $databaseName;

	const LMI_START_TIME = "00:00:00";

	private $repTime = 0;
	private $repRows = 0;
	private $repTries = 0;

	public function __construct( $host, $user, $pass, $targetTable, $dateField, $databaseName = "", $lmiStartDate)
	{
		ini_set('mssql.secure_connection', 1);

		$this->host = $host;
		$this->user = $user;
		$this->pass = $pass;
		$this->databaseName = $databaseName;

		$this->targetTable = $targetTable;

		$this->dateField = $dateField;

		if(!$this->canConnect())
		{
			throw new Exception("Error establishing replication link");
		}
		else
		{
			//startDate must be set, else LMI gives you only a window of a month or less
				
			$this->lmiStartDate = $lmiStartDate;

			if(preg_match('/\d\d?\/\d\d?\/\d\d\d\d/',$this->lmiStartDate))
			{
					
				$this->calculateStartDate() ;
				$this->calculateEndDate();

				if(!$this->startDate || !$this->startTime || !$this->endDate)
				{
					throw new Exception("Error determining report startDate/Time");
				}
			}
			else
			{
				throw new Exception("Invalid LMI Start Date: $this->lmiStartDate");
			}
		}

		$dbNameString = $this->databaseName;

		if(!$dbNameString)
		{
			$dbNameString = "Default";
		}

		echo "Built replication site for database server: $this->host, database: $dbNameString, table: $this->targetTable", PHP_EOL;
	}

	function __destruct()
	{
		echo "Destructing replication site, memory usage: ", memory_get_peak_usage(true)/1000,"kb", PHP_EOL;
	}

	private function canConnect()
	{
		$retval = false;
		try
		{
			$dbConnection = $this->getDatabaseConnection();

			if($dbConnection )
			{
				if($this->checkTarget($dbConnection))
				{
					$retval = true;
				}

				mssql_close($dbConnection);

				unset($dbConnection);
				$dbConnection = null;
			}
			else
			{
				echo "connection test failed for table " . $this->targetTable , PHP_EOL ;
			}
		}
		catch (Exception $e)
		{
			echo $e->getMessage() , PHP_EOL;
			echo $e->getLine() , PHP_EOL;
		}

		return $retval;
	}

	private function getDatabaseConnection()
	{
		$dbConnection = mssql_connect($this->host, $this->user, $this->pass);

		if($dbConnection)
		{
			if($this->databaseName != "")
			{
				if(!mssql_select_db($this->databaseName))
				{
					echo "Could not find databaseName ", $this->databaseName, " on server ", $this->host, PHP_EOL;

					mssql_close($dbConnection);

					unset($dbConnection);
					$dbConnection = null;
				}
			}
		}
		else
		{
			echo "Initial MS SQL connection failed", PHP_EOL;
		}

		return $dbConnection;
	}

	private function calculateStartDate()
	{
		$startDate = "";
		$startTime = "";

		$dbConnection = $this->getDatabaseConnection();

		//need this to prevent php from converting datetimes from mssql query results. i'll handle that myself, thank you
		ini_set('mssql.datetimeconvert', "0");

		try
		{

			$query = "select convert(varchar(19), (select top 1 [$this->dateField] from [$this->targetTable] order by [$this->dateField] desc), 20) as [$this->dateField]";
//insert into LMI_5452038_Collab_Chat_Log Values ('10/13/2011 3:12:27 PM','10/13/2011 3:32:54 PM','00:20:27','107668521','jack talvert','Kevin Justice','5579974','4:12 PM Incoming collaboration session from: Soundarajan G 4:12 PM Connecting to: control.app04-12.logmeinrescue.com (64.74.103.140:443) 4:12 PM Connected to Applet (RSA 2048 bits AES256-SHA 256 bits) 4:12 PM Waiting for customer permission to remote control desktop. 4:12 PM Remote Control successfully initiated. 4:32 PM The technician ended the session.')
				
			$start = microTime(true);
			$sql_result = mssql_query($query ,$dbConnection);
			echo "Retrieved last replicated date in " . (microTime(true) - $start)*1000 . " msec via query: ", $query , PHP_EOL;

			//nothing returned likely empty table
			if(!$sql_result)
			{
				throw new Exception("Sql Error: ". mssql_get_last_message() );
			}
			else
			{
				$row =  mssql_fetch_array($sql_result);
				
				if($row[$this->dateField])
				{
					date_default_timezone_set('America/New_York');
	
					//YYYY-MM-DD HH:MM:SS.T
	
					$fullDate = $row[$this->dateField];
	
					$dateFields = date_create_from_format('Y-m-d H:i:s', $fullDate) ;
	
					if(!$dateFields)
					{
						echo "Error parsing start date ($fullDate): ";
						print_r(date_get_last_errors());
						echo "Query returned:";
						print_r($row);
					}
					else
					{
						//$startDate = $dateFields['month'] . "/" . $dateFields['day'] . "/" . $dateFields['year'];
						//$startTime = $dateFields['hour'] . ":" . $dateFields['minute'] . ":" . $dateFields['second'];
						$startDate = date_format($dateFields, 'm/d/Y');
						$startTime = date_format($dateFields, 'H:i:s');
					}
				}
				else
				{
					//$startDate = ReplicationSite::LMI_START_DATE;
					$startDate = $this->lmiStartDate;
					$startTime = ReplicationSite::LMI_START_TIME;
				}
			}

			mssql_free_result($sql_result);
		}
		catch(Exception $e)
		{
			echo $e->getMessage(), " at line: ", $e->getLine() , PHP_EOL;
			echo "Query: ", $query, PHP_EOL;
			echo "Full date found: ", $fullDate, PHP_EOL;
		}

		mssql_close($dbConnection);

		unset($dbConnection);
		$dbConnection = null;

		echo "Beginning replication at date: ", $startDate, " ", $startTime,PHP_EOL;

		$this->startDate = $startDate;
		$this->startTime = $startTime;
	}

	private function calculateEndDate()
	{
		date_default_timezone_set('America/New_York');

		//add a day so mid-day replication actually does something

		$tomorrow = date_add(date_create(), date_interval_create_from_date_string(1 . " days"));

		$endDate = date_format($tomorrow, 'm/d/Y');

		//$endDate = "7/30/2010";

		echo "Ending replication at date: ", $endDate , PHP_EOL;

		$this->endDate = $endDate;
	}

	public function replicate($formattedData)
	{
		//missing table fails construction, don't worry about it here

		$i = 0;

		echo "Replication started with rows: " , count($formattedData),", memory usage: ", memory_get_peak_usage(true)/1000,"kb", PHP_EOL;

		$start = microTime(true);

		//re-create the connection here, doesn't make sense to leave it open while 50 other reports are replicating
		//although fuck mssql for timing it out anyway, i could have ini_set the timeout to a billion if i wanted to
		$dbConnection =  $this->getDatabaseConnection();

		$insertRowPrefix = "insert into $this->targetTable Values (";
		foreach($formattedData as $row)
		{
			$encodedStatement = "";
			//			$statement = "";

			//$numVals = 0;
			foreach($row as $col)
			{
				//remove non-ascii chars
				$col = preg_replace('/[^(\x20-\x7F)\x0A]*/','', $col);

				//remove commas, apostrophes, quotes, newlines, and slashes
				$col = str_replace("'", "", $col);
				$col = str_replace(",", "", $col);
				$col = str_replace('"', "", $col);
				$col = str_replace("\\", "", $col);
				//$col = str_replace("/", "", $col);
				$col = str_replace("\n", " ", $col);

				// 				if( $col == "" || preg_match('/\d[\d]?\/\d[\d]?\/\d\d\d\d\ \d[\d]?\:\d\d\:\d\d/', $col))
				// 				{
				$encodedStatement .= "'$col',";
				// 				}
				// 				else
				// 				{
				// 					//$encodedStatement .= $this->mssql_escape($col) . ",";
				// 					$encodedStatement .= "'$col',";
				// 				}

				//				$statement .=  "'$col',";

				//$numVals++;
				}

				$encodedQuery = $insertRowPrefix . chop($encodedStatement,",") . ")";

				#echo "Attempted Query: ",$encodedQuery, PHP_EOL;
					
				try
				{
					if($dbConnection && mssql_query( $encodedQuery ,$dbConnection))// or die("Sql Error: ". mssql_get_last_message() .  PHP_EOL );)
					{
						$i++;
					}
					else
					{
						echo "Original Row: ",$encodedQuery, PHP_EOL;
						var_dump($row);
						throw new Exception("Failure running query: $encodedQuery --> " . mssql_get_last_message());
					}
				}
				catch (Exception $e)
				{
					echo $e->getMessage(), " at line: ", $e->getLine() , PHP_EOL;

					//break, if we re-run it, it'll start at the last failure point
					break;
				}
			}

			$this->repTime = (microTime(true) - $start)*1000;
			$this->repRows = $i;
			$this->repTries = count($formattedData);

			unset($formattedData);
			$formattedData = null;

			mssql_close($dbConnection);

			unset($dbConnection);
			$dbConnection = null;

			echo "Replication ended, memory usage: ", memory_get_usage(true)/1000,"kb", PHP_EOL;
		}

		private function checkTarget($dbConnection)
		{
			$retval = false;

			try
			{
				if(!mssql_query( "select top 1 * from " . $this->targetTable, $dbConnection))
				{
					throw new Exception( "Sql Error determining table existence: " . mssql_get_last_message() );
				}

				$retval = true;
			}
			catch (Exception $e)
			{
				echo $e->getMessage(), " at line: ", $e->getLine() , PHP_EOL;
			}

			return $retval;
		}

		public function getReplicationStats()
		{
			$stats = array
			(
			'repTime' => $this->repTime,
			'repRows' => $this->repRows,
			'repTries' => $this->repTries,
			);

			return $stats;
		}

		public function getStartTime()
		{
			return $this->startTime;
		}

		public function getStartDate()
		{
			return $this->startDate;
		}

		public function getEndDate()
		{
			return $this->endDate;
		}

		private function mssql_escape($data)
		{
			//    	if(is_numeric($data))
			//    	{
			//        	return $data;
				//    	}
				$unpacked = unpack('H*hex', $data);

				return '0x' . $unpacked['hex'] ;
		}
	}

	?>