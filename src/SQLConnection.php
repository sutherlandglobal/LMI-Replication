<?php
class SQLConnection 
{
	private $host;
	private $databaseName;
	private $dbConnection;
	
	public function __construct($host, $databaseName) 
	{
		ini_set( 'mssql.secure_connection', 1 );
		
		//need this to prevent php from converting datetimes from mssql query results. i'll handle that myself, thank you
		ini_set( 'mssql.datetimeconvert', "0");
		
		$this->host = $host;
		
		if($databaseName == "")
		{
			$this->databaseName = "Default";
		}
		else
		{
			$this->databaseName = $databaseName;
		}
		
		$this->dbConnection = null;
	}
	
	function __destruct() 
	{
		$this->close();
		echo "Destructing replication site, memory usage: ", memory_get_peak_usage ( true ) / 1000, "kb", PHP_EOL;
	}
	
	public function connect($user, $pass) 
	{
		if($this->databaseConnection)
		{
			echo "Closing open connection", PHP_EOL;
			$this->close();
		}
		
		$this->dbConnection = mssql_connect ( $this->host, $user, $pass );
		
		if ($this->dbConnection) 
		{
			echo "Using database ", $this->databaseName, PHP_EOL;
			
			if ($this->databaseName != "") 
			{
				if (! mssql_select_db ( $this->databaseName )) 
				{
					throw new Exception( "Could not find databaseName ", $this->databaseName, " on server ", $this->host);
				}
			}
		} 
		else 
		{
			throw new Exception("Database connection failed");
		}
	}
	
	public function tableExists($tableName)
	{
		$retval = false;
		
		try
		{
			if(!mssql_query( "select top 1 * from " . $tableName, $this->dbConnection))
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
	
	public function close()
	{
		mssql_close($this->dbConnection);
	}
	
	public function execute( $sqlString )
	{
		if($this->dbConnection)
		{
			$result = mssql_query( $sqlString ,$this->dbConnection);
		}
		else
		{
			throw new Exception("Attempted query on closed connection");
		}
		
		return $result;
	}
	
	private function mssql_escape($data) 
	{
		$unpacked = unpack ( 'H*hex', $data );
		
		return '0x' . $unpacked ['hex'];
	}
}

?>