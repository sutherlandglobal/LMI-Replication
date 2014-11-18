<?php

include 'ReportFormatter.php';
include 'DelimiterFactory.php';

class LMI_Report
{
	private $soapClient;
	private $reportArea;
	private $nodeID;
	private $nodeRef;
	private $localDB;
	private $schemaString;
	private $delimiter;

	const REPORT_MAX_WINDOW_RANGE_IN_WEEKS = 1;
	const REPORT_MAX_DATE_RANGE_IN_WEEKS = 10;
	const MAX_REPORT_DATA_LEN = 5000000;
	const MIN_REPORT_DATA_LEN = 50000;
	const DELIM_ALPHA_LEN = 8;

	private $data;

	// constructor
	public function __construct( $sc, $localDB, $nodeID, $nodeRef,$reportArea)
	{
		$this->soapClient = $sc;
		$this->reportArea = $reportArea;
		$this->nodeID = $nodeID;
		$this->nodeRef = $nodeRef;
		$this->localDB = $localDB;
		
		$alphabetsUsed = Array();
		
		#generate the report field delimiter
		$delimFactory = new DelimiterFactory(LMI_REPORT::DELIM_ALPHA_LEN, 1);
		$this->delimiter = $delimFactory->genDiverseDelimiter(LMI_REPORT::DELIM_ALPHA_LEN);
	}

	private function formatData()
	{
		$formattedData = array();

		//echo $this->data, PHP_EOL;

		if($this->data)
		{
			$formatter = new ReportFormatter($this->reportArea, $this->delimiter);


			$formattedData = $formatter->formatData($this->data);

			//echo "_____formattedData size: " . count($formattedData), PHP_EOL;
			//unset($formatter);
			//$formatter = null;

			unset($this->data);
			$this->data = null;

		}

		return $formattedData;
	}

	public function runReport()
	{
		date_default_timezone_set('America/New_York');

		$startDate = $this->localDB->getStartDate();
		$startTime = $this->localDB->getStartTime();
		$endDate = $this->localDB->getEndDate();

		if($startDate != "" && $endDate != "")
		{
			$this->data = "";

			//set the type of report
			$reportAreaParams = array (
				'eReportArea' => $this->reportArea,
			);

			//finally, get the report
			//set up array
			$getReportParams = array
			(
				'iNodeID' => $this->nodeID,
				'eNodeRef' => $this->nodeRef,
			);

			$dateFields = date_parse_from_format("n/j/Y", $startDate);

			$reportStartDate =  date_create();
			$reportStartDate = date_date_set($reportStartDate, $dateFields['year'], $dateFields['month'], $dateFields['day'] );

			$windowStartDate = date_create();
			$windowStartDate = date_date_set($windowStartDate, $dateFields['year'], $dateFields['month'], $dateFields['day'] );

			$windowEndDate = date_create();
			date_date_set($windowEndDate, $dateFields['year'], $dateFields['month'], $dateFields['day']);

			$dateFields = date_parse_from_format("H:i:s", $startTime);
			$windowStartDate = date_time_set($windowStartDate, $dateFields['hour'], $dateFields['minute'], $dateFields['second']);
			$reportStartDate =  date_time_set($reportStartDate, $dateFields['hour'], $dateFields['minute'], $dateFields['second']);

			$windowEndDate = date_add($windowEndDate, date_interval_create_from_date_string(LMI_Report::REPORT_MAX_WINDOW_RANGE_IN_WEEKS . " weeks"));

			$dateFields = date_parse_from_format("n/j/Y", $endDate);
			$reportEndDate =  date_create($dateFields['month'] . "/" . $dateFields['day'] . "/" . $dateFields['year'] . " 00:00:00" );

			$iterations=0;

			$soapTime = 0;
			$reportResponse ="";

			$maxReportEndDate = clone $reportStartDate;
			$maxReportEndDate = date_add($reportStartDate, date_interval_create_from_date_string(LMI_Report::REPORT_MAX_DATE_RANGE_IN_WEEKS . " weeks"));

			try
			{
				//enforce a report window, but tolerate large gaps in data between groups
				while
				(
					$windowStartDate < $reportEndDate &&
					strlen($this->data) < LMI_Report::MAX_REPORT_DATA_LEN &&
					(
						strlen($this->data) == 0 ||
						(
							(
								strlen($this->data) > 0 &&
								strlen($this->data) < LMI_REPORT::MIN_REPORT_DATA_LEN
							) ||
							$windowStartDate < $maxReportEndDate
						)
					)
				)
				{
					//echo date_format($windowStartDate, 'm/d/Y H:i:s.u'), " vs ", date_format($maxReportEndDate, 'm/d/Y H:i:s.u'), PHP_EOL;
					$startReportLen = strlen($this->data);

					if($windowEndDate > $reportEndDate)
					{
						$windowEndDate = clone $reportEndDate;
					}

					//set the date frame
					$reportDateParams= array
					(
						'dBeginDate' => date_format($windowStartDate, 'Y-m-d').'T'.date_format($windowStartDate,'H:i:s.u') . "Z",
						'dEndDate' =>  date_format($windowEndDate, 'Y-m-d').'T'.date_format($windowEndDate,'H:i:s.u') . "Z",
					);

					$start = microTime(true);

					#run the report
					$subIntervalData = $this->soapClient->runReport($reportAreaParams,$reportDateParams, $getReportParams, $this->delimiter );
					
					#only care if the report returns data
					if(substr_count($subIntervalData, "\n") > 2 && !mb_ereg_match('/^\s*$/', end(explode("\n", $subIntervalData)) ))
					{
						#if we run the report sub-interval and it returns nothing, we have to use the schema string from the next sub-interval that returns data
						#first sub interval can be empty, and have weird schema strings that need to be rejected
						if($iterations && $this->data )
						{
							#echo "replacing schema string", PHP_EOL;
							$this->data .= mb_ereg_replace('/^.*\n/', "", $subIntervalData, 1);
						}
						else
						{
							#echo "keeping schema string",PHP_EOL;
							$this->data .= $subIntervalData;
						}
					}
					else
					{
						#echo "ignoring empty report: ", $subIntervalData, PHP_EOL;
					}

					$endReportLen = strlen($this->data);

					$end = number_format((microTime(true) - $start)*1000, 2, '.', '');
					echo "SOAP Report ", $this->nodeID,"_", $this->reportArea . " iteration " . ($iterations + 1) .
					" starting at " . date_format($windowStartDate, 'm/d/Y H:i:s.u') .  
					" ending at " . date_format($windowEndDate, 'm/d/Y H:i:s.u')  . 
					" finished in $end msec and collected chars: ",($endReportLen - $startReportLen) , PHP_EOL;

					$soapTime += $end;

					$windowStartDate = clone $windowEndDate;

					$windowEndDate = date_add($windowEndDate, date_interval_create_from_date_string(LMI_Report::REPORT_MAX_WINDOW_RANGE_IN_WEEKS . " weeks"));

					$iterations++;
					
					#recho mb_ereg_replace( $this->delimiter, "\",\"", $this->data), PHP_EOL;
				}
				

				
				echo "Soap Report ", $this->nodeID,"_", $this->reportArea, " completed in ", $soapTime, " msec, with length ", strlen($this->data),PHP_EOL;
				echo "Soap Report memory usage: ", memory_get_usage(true)/1000,"kb", PHP_EOL;
			}
			catch(Exception $e)
			{
				echo "Report Error: ", $e->getMessage(), EOL;
				$this->data = "";
			}
		}
		else
		{
			echo "invalid date params", PHP_EOL;
		}

		return $this->data;
	}

	public function replicate()
	{
		//load $this->data from soap reports
		$this->runReport();

		//destruct the soap client here, since we won't need it after the reports are run
		unset($this->soapClient);
		$this->soapClient = null;

		$this->localDB->replicate( $this->formatData($this->data) );
	}

	function __destruct()
	{
		unset($this->soapClient);
		$this->soapClient = null;

		unset($this->localDB);
		$this->localDB = null;
	}

	public function getReplicationStats()
	{
		return $this->localDB->getReplicationStats();
	}
}

?>