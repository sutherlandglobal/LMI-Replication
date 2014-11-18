<?php

class ReportFormatter
{
	private $reportArea;
	private $schemaString;
	private $schemaFieldCount;
	private $reportDelim;
	
	const SQL_DELIM = '","';
	const MAX_SCHEMA_DESC_LEN = "43";

	public function __construct($reportArea, $reportDelim)
	{
		$this->reportDelim = $reportDelim;
		$this->reportArea = $reportArea;
	}
	
	public function __destruct()
	{	
		echo "Destructing formatter, memory usage: ", memory_get_usage(true)/1000,"kb", PHP_EOL;
	}

	public function formatData($rawData)
	{		
		//schema string is first ^ to $
		$this->schemaString = trim(substr($rawData, 0, strpos($rawData, "\n" )));
		
		$this->schemaString = mb_ereg_replace($this->reportDelim, ReportFormatter::SQL_DELIM, $this->schemaString);
		echo "Schema String: ", $this->schemaString, PHP_EOL;

		$this->schemaFieldCount = substr_count($this->schemaString, ReportFormatter::SQL_DELIM );

		echo "Schema delimiter count: " . $this->schemaFieldCount, PHP_EOL;
		
		switch($this->reportArea)
		{
			case "CHAT_LOG":
			case "CUSTOMER_SURVEY":
			case "SESSION":
				return $this->formatCustomerSurvey($rawData);
			#case "CHAT_LOG":
			case "MISSED_SESSION":
			case "CUSTOM_FIELDS":
			case "LOGIN":
			case "TECHNICIAN_SURVEY":
				return $this->formatLogin($rawData);
			case "FAILED_SESSION":
			case "TRANSFERRED_SESSIONS":
			case "COLLABORATION_CHAT_LOG":
				return $this->formatCollabChatLog($rawData);
			//case "TRANSFERRED_SESSIONS":
			//	return $this->formatTransferredSessions($rawData);
			default:
				echo "Error: Unrecogized reportArea: ", $this->reportArea, PHP_EOL;
				return array();
		}
	}

	private function formatCustomerSurvey($rawData)
	{
		//some report datas are empty, idgaf replicate it anyway

		//schema sanity check
		//lmi allows the seperator character in free text fields, and passes the savings along to the api user
		$index = 0;
		$delimCount = 0;

		//remove all newlines, add them in considering the schema.
		//only reliable way without some pretty dark regex magic, since newlines can be anywhere
		
		//if a row contains less than the expected number of schema delims, append the next row onto it and retry
		//since the last field is a chat log, every schemaFieldCount + 1 fields, define the chatlog as explode(delim, field -1)
		
		//replace unique field delimiter
		$rawData = mb_ereg_replace("\"", "", $rawData);
		$rawData = mb_ereg_replace($this->reportDelim, ReportFormatter::SQL_DELIM, $rawData);
		$rawFields = explode(ReportFormatter::SQL_DELIM,$rawData);
		$rawData = preg_replace('/\n/', ' ', $rawData);

		$formattedData = array();

		$thisRow = "";
		for($index = 0; $index < strlen($rawData); $index++)
		{
			$thisRow .= $rawData[$index];

			if(substr($thisRow, count($index)-4) == ReportFormatter::SQL_DELIM )
			{
				$delimCount++;

				if($delimCount == $this->schemaFieldCount )
				{
					$thisRow = str_replace('\\', '',  trim(chop($thisRow, $this-reportDelim)));
					
					//hating myself for this, with some reports the schema changes to include other reporting groups
					//comment fields allow for
					if
					(
						$thisRow && substr_compare( $thisRow,$this->schemaString, 0, ReportFormatter::MAX_SCHEMA_DESC_LEN)
					)
					{					
						$thisRow = preg_replace("/".  ReportFormatter::SQL_DELIM . "$/","", $thisRow);
						$thisRow = str_replace("\\", "", $thisRow);
						$thisRow = str_replace(";", "", $thisRow);
						$thisRow = str_replace("'", "", $thisRow);

						if($thisRow[0] != '"')
						{
							$thisRow = '"' . $thisRow;
						}
						
						if(substr($thisRow, -1) != "\"")
						{
							$thisRow .= "\"";
						}

						//schema tacks on an extra empty field

						#echo "Adding row: ", $thisRow, PHP_EOL;
						
						$formattedData[] = str_getcsv($thisRow, ",", '"');

						//if the csv string comes up short, add some empty elements and hope for the best
						//if there was a geneva convention for soap apis, LMI would be in serious trouble
						while(count($formattedData[count($formattedData) - 1] ) < $this->schemaFieldCount)
						{
							array_push($formattedData[count($formattedData) - 1], "");
						}
					}

					$delimCount = 0;

					$thisRow = "";
				}
			}
		}

		return $formattedData;
	}

	private function formatTransferredSessions($rawData)
	{
		//if a row contains less than the expected number of schema delims, append the next row onto it and retry
		
		$rawLines = explode("\n",$rawData);
		$formattedLines = array();		
		
		//start at 1 to avoid the schema
		for($index = 1; $index < count($rawLines); $index++)
		{
			$thisRow = rtrim($rawLines[$index]);
			
			if($thisRow)
			{
				while(substr_count($thisRow, $this-reportDelim) < $this->schemaFieldCount )
				{
					//echo "Row: " . $thisRow, PHP_EOL;
					//echo "Found: " . substr_count($thisRow, $this-reportDelim) . " vs " . $this->schemaFieldCount, PHP_EOL;

					$index++;
					
					//echo "Adding " . $rawLines[$index], PHP_EOL;
					
					$thisRow .= rtrim($rawLines[$index]) . $this-reportDelim ;
					
					//echo "New row: " . $thisRow, PHP_EOL;
				}

				$formattedLines[] = $thisRow;
				//echo "Processed: " . $formattedLines[count($formattedLines) -1 ], PHP_EOL;
			}
		}
		
		//$formattedData = array();

		foreach( $formattedLines as $thisRow )
		{
			//hating myself for this, with some reports the schema changes to include other reporting groups
			if
			(
				$thisRow //&& substr_compare(  $thisRow,$this->schemaString, 0, ReportFormatter::MAX_SCHEMA_DESC_LEN)
			)
			{
				$thisRow = str_replace('\\', '', $thisRow);
				$thisRow = preg_replace("/". $this-reportDelim . "$/","", $thisRow);
				
				if($thisRow[0] != '"')
				{
					$thisRow = '"' . $thisRow;
				}

				if(substr($thisRow, -1) != '"' && substr($thisRow, -1) != ',' )
				{
					$thisRow .= '"';
				}

				//$formattedData[] =  explode($this-reportDelim,$thisRow);

				$formattedData[] = str_getcsv($thisRow, ",", '"');

				//echo "adding row: " . $thisRow, PHP_EOL;
			}
		}

		return $formattedData;
	}

	private function formatLogin($rawData)
	{
		$formattedData = array();
		
		foreach( explode("\n",$rawData) as $thisRow )
		{
			//replace unique field delimiter
			//after this point, $thisRow shouldn't be an mb string
			$thisRow = mb_ereg_replace("\"", "", $thisRow);
			$thisRow = mb_ereg_replace($this->reportDelim, ReportFormatter::SQL_DELIM, $thisRow);
			
			//hating myself for this, with some reports the schema changes to include other reporting groups
			if
			(
				$thisRow && 
				substr_compare(  $thisRow, $this->schemaString, 0, ReportFormatter::MAX_SCHEMA_DESC_LEN)
			)
			{
				
				//remove the trailing one. factor in the substitution having been made
				$thisRow = preg_replace("/". ReportFormatter::SQL_DELIM . "$/", "", $thisRow );
				$thisRow = str_replace("\\", "", $thisRow);
				$thisRow = str_replace(";", "", $thisRow);
				$thisRow = str_replace("'", "", $thisRow);
				
				//add opening quote to close first field
				if($thisRow[0] != '"')
				{
					$thisRow = '"' . $thisRow;
				}

// 				if(substr($thisRow, -1) != '"' && substr($thisRow, -1) != ',' )
// 				{
// 					$thisRow .= '"';
// 				}

				$formattedData[] = str_getcsv($thisRow, ",", '"');

				#echo "adding row: " . $thisRow, PHP_EOL;
			}
			
			//echo "FormatLogin iteration ". count($formattedData) . ", memory usage: ", memory_get_usage(true)/1000,"kb", PHP_EOL;
		}

		return $formattedData;
	}
	
	private function formatCollabChatLog($rawData)
	{
		$formattedData = array();
				
		//if a row contains less than the expected number of schema delims, append the next row onto it and retry
		//since the last field is a chat log, every schemaFieldCount + 1 fields, define the chatlog as explode(delim, field -1)
		
		//replace unique field delimiter
		$rawData = mb_ereg_replace("\"", "", $rawData);
		$rawData = mb_ereg_replace($this->reportDelim, ReportFormatter::SQL_DELIM, $rawData);
		$rawFields = explode(ReportFormatter::SQL_DELIM,$rawData);
		
		//$formattedLines = array();		

		$thisRow = "";
		
		for($index = 0; $index < count($rawFields); $index++)
		{
			if
			(
				$thisRow && 
				$index  && 
				$index % $this->schemaFieldCount == 0
			)
			{
				$chatLogLines = explode("\n", $rawFields[$index]);
				
				$nextRowStart = array_pop($chatLogLines);
				
				$thisRow .= rtrim( implode(" ", $chatLogLines));// .  ReportFormatter::SQL_DELIM;
				
				if( substr_compare(  $thisRow,$this->schemaString, 0, ReportFormatter::MAX_SCHEMA_DESC_LEN) )
				{
					$thisRow = preg_replace("/".  ReportFormatter::SQL_DELIM . "$/","", $thisRow);
					$thisRow = str_replace("\\", "", $thisRow);
					$thisRow = str_replace(";", "", $thisRow);
					$thisRow = str_replace("'", "", $thisRow);
				
					//sometimes the chatlog is entirely absent					
					if($chatLogLines[0] == "")
					{
						$thisRow .= '"';
					}
					
					if($thisRow[0] != '"')
					{
						$thisRow = '"' . $thisRow;
					}

// 					if(substr($thisRow, -1) != '"' && substr($thisRow, -1) != ',' )
// 					{
// 						$thisRow .= '"';
// 					}

					
					
					#echo "Adding row: ", $thisRow, PHP_EOL;
					
					$formattedData[] = str_getcsv($thisRow, ",", '"');
					
					
				}
				
				$thisRow = $nextRowStart .  ReportFormatter::SQL_DELIM;
				//$formattedRow = "";
			}
			else
			{
				$thisRow .= rtrim($rawFields[$index]) . ReportFormatter::SQL_DELIM;
			}
		}

//		foreach( $formattedLines as $thisRow )
//		{
//			//hating myself for this, with some reports the schema changes to include other reporting groups
//			if
//			(
//				$thisRow //&& substr_compare(  $thisRow,$this->schemaString, 0, ReportFormatter::MAX_SCHEMA_DESC_LEN)
//			)
//			{
//				$thisRow = str_replace('\\', '', $thisRow);
//				$thisRow = preg_replace("/". $this-reportDelim . "$/","", $thisRow);
//				
//				if($thisRow[0] != '"')
//				{
//					$thisRow = '"' . $thisRow;
//				}
//
//				if(substr($thisRow, -1) != '"' && substr($thisRow, -1) != ',' )
//				{
//					$thisRow .= '"';
//				}
//
//				//$formattedData[] =  explode($this-reportDelim,$thisRow);
//
//				$formattedData[] = str_getcsv($thisRow, ",", '"');
//
//				//echo "adding row: " . $thisRow, PHP_EOL;				
//			}
//		}

		return $formattedData;
	}
}
?>