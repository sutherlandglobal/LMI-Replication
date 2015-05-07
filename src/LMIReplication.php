<?php

include 'LMISoapClientFactory.php';
include 'ReplicationSite.php';
include 'RosterReplicator.php';
include 'LMIReport.php';

ini_set('memory_limit', '512M');

$path = dirname(__FILE__) . "/";
$confDir = $path . "../conf/";
$confSuffix = ".conf";
$confFile = "rep.conf";


//check to see if replication proc is already running
if(system("ps -ef | grep " . __FILE__ ." | grep php |  grep -v grep | wc -l") > 2 )
{
	echo "Aborting...replication process already running.", PHP_EOL;
	exit(1);
}
	
//bad guess is lmi can take ~1.25 logins per minute.
// $REPORT_SLEEP_MIN = 1;
// $REPORT_SLEEP_MAX = 2;

//minimum replication time in seconds before sleeping
$MIN_REPTIME = 100;

$LMI_TABLE_PREFIX = "LMI_";

//for debugging, ignore sleep if force arg passed
if(count($argv) > 0 && $argv[1] != "-f")
{
	//sleep for random number of minutes
	$REPLICATION_SLEEP_MIN = 1;
	$REPLICATION_SLEEP_MAX = 6;

	$replicationSleepTime = (rand($REPLICATION_SLEEP_MIN, $REPLICATION_SLEEP_MAX) * 60 )+ rand(0,60);

	echo "Sleeping for " . $replicationSleepTime , PHP_EOL;
	sleep($replicationSleepTime);
}

$reportAreaDirective = "reportArea";
$nodeIDDirective = "nodeID";
$nodeRefDirective = "nodeRef";
$targetTableDirective = "targetTable";
$rosterTableDirective = "rosterTable";
$dateFieldDirective = "dateField";
$databaseDirective = "databaseName";

$soapURLDirective ="lmiURL";
$soapUserDirective ="lmiUser";
$soapPassDirective ="lmiPass";
$dbHostDirective = "dbHost";
$dbNameDirective = "dbName";
$dbUserDirective ="dbUser";
$dbPassDirective ="dbPass";
$startDateDirective ="startDate";

$finalStats = array
(
	'repTime' =>0,
	'repRows' => 0,
	'repAttempts' => 0, 
);



$reports = array();

//supports a generic authentication conf file for soap and db access
//if it is not found, the lmi group conf file must specify logins

//conf holds sites
	//each site has a rep.conf with site info called rep.conf
	//each site has other conf files that specify replication information for a report/table
	
//find sites
$dir  = new DirectoryIterator($confDir);

foreach ($dir as $siteDir) 
{
	$soapURL = "";
	$soapUser = "";
	$soapPass = "";
	$rosterTable = "";
	$dbHost = "";
	$dbName = "";
	$dbUser = "";
	$dbPass = "";
	$startDate ="";
	
	//exclude ./ and ../
	if($siteDir->isDir() && !$siteDir->isDot())
	{
	
		$siteDir = $confDir . "/" . $siteDir;
		//rep.conf should be at siteDir/rep.conf
		if(file_exists($siteDir . "/" . $confFile))
		{
			echo "Reading site config from $siteDir/$confFile", PHP_EOL;
			
			foreach( file($siteDir . "/" . $confFile) as $line)
			{
				if(!preg_match('/^\s*$/', $line) && !preg_match('/^#/', $line))
				{
					$line = chop($line);
					//echo $line . "\n";
			
					$lineFields = explode('=', $line);
			
					if(count($lineFields) > 2)
					{
						throw new Exception("Malformed config line in file $confFile: $line");
					}
			
					if($lineFields[0] == $soapURLDirective)
					{
						$soapURL = $lineFields[1];
					}
					else if($lineFields[0] == $soapUserDirective)
					{
						$soapUser = $lineFields[1];
					}
					else if($lineFields[0] == $soapPassDirective)
					{
						$soapPass = $lineFields[1];
					}
					else if($lineFields[0] == $rosterTableDirective)
					{
						$rosterTable = $lineFields[1];
					}
					else if($lineFields[0] == $dbHostDirective)
					{
						$dbHost = $lineFields[1];
					}
					else if($lineFields[0] == $dbNameDirective)
					{
						$dbName = $lineFields[1];
					}
					else if($lineFields[0] == $dbUserDirective)
					{
						$dbUser = $lineFields[1];
					}
					else if($lineFields[0] == $dbPassDirective)
					{
						$dbPass = $lineFields[1];
					}
					else if($lineFields[0] == $startDateDirective)
					{
						$startDate = $lineFields[1];
					}
				}
			}
			
			$soapClientFactory = new LMISoapClientFactory($soapURL);
			$soapClientFactory->setUrl($soapURL);
			$lmiConn = $soapClientFactory->buildSoapClient($soapUser,$soapPass);
			
			#begin roster replication if rostertable is defined
			if($rosterTable)
			{
				$roster = new RosterReplicator($dbHost, $dbName, $rosterTable, $dbUser, $dbPass, $lmiConn);
				$roster->replicate();
			}

			$replicationSites = array();
			
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($siteDir), RecursiveIteratorIterator::CHILD_FIRST);
			
			foreach($iterator as $file)
			{
				if(preg_match('/\.conf$/', $file) && !preg_match("/$confFile$/", $file))
				{
					echo "Adding report conf file: ", $file, PHP_EOL;
					array_push($replicationSites, $file);
				}
			
				//randomize the conf files a lot, so even if the login threshold is lowered again, replication should survive albeit run slower
				shuffle($replicationSites);
			}
			
			foreach($replicationSites as $file)
			{
				try
				{
					$start = microTime(true);
						
					echo "Replicating report $file", PHP_EOL;
			
					$reportArea = "";
					$nodeID = "";
					$nodeRef = "";
					$targetTable = "";
					$dateField = "";
					#$databaseName = "";
			
					foreach( file($file) as $line)
					{
						if(!preg_match('/^\s*$/', $line) && !preg_match('/^#/', $line))
						{
							$line = chop($line);
			
							$lineFields = explode('=', $line);
			
							if(count($lineFields) > 2)
							{
								throw new Exception("Malformed config line in file $confDir$file: $line");
							}
			
							if($lineFields[0] == $reportAreaDirective)
							{
								$reportArea = $lineFields[1];
							}
							else if($lineFields[0] == $nodeIDDirective)
							{
								$nodeID = $lineFields[1];
							}
							else if($lineFields[0] == $nodeRefDirective)
							{
								$nodeRef = $lineFields[1];
							}
							else if($lineFields[0] == $targetTableDirective)
							{
								$targetTable = $lineFields[1];
							}
							else if($lineFields[0] == $dateFieldDirective)
							{
								$dateField = $lineFields[1];
							}
							else if($lineFields[0] == $dbNameDirective)
							{
								$dbName = $lineFields[1];
							}
							else	if($lineFields[0] == $soapURLDirective)
							{
								$soapURL = $lineFields[1];
							}
							else if($lineFields[0] == $soapUserDirective)
							{
								$soapUser = $lineFields[1];
							}
							else if($lineFields[0] == $soapPassDirective)
							{
								$soapPass = $lineFields[1];
							}
							else if($lineFields[0] == $dbHostDirective)
							{
								$dbHost = $lineFields[1];
							}
							else if($lineFields[0] == $dbUserDirective)
							{
								$dbUser = $lineFields[1];
							}
							else if($lineFields[0] == $dbPassDirective)
							{
								$dbPass = $lineFields[1];
							}
							else if($lineFields[0] == $startDateDirective)
							{
								$startDate = $lineFields[1];
							}
						}
					}
			
					if(!$reportArea || !$nodeID || !$nodeRef || !$targetTable || !$dateField || !$soapURL || !$soapUser || !$soapPass || !$dbHost || !$dbName || !$dbUser || !$dbPass || !$startDate)
					{
						var_dump(array($reportArea,  $nodeID,  $nodeRef,  $targetTable,  $dateField,  $soapURL,  $soapUser,  $soapPass,  $dbHost, $dbName,  $dbUser,  $dbPass,  $startDate ));
						throw new Exception("Missing config directives");
					}
			

					
					$targetTable = $LMI_TABLE_PREFIX .  $nodeID . "_" . $targetTable;
			

			
					$replication = new ReplicationSite($dbHost,$dbUser, $dbPass, $targetTable,$dateField,$dbName, $startDate);
			
					$r = new LMIReport($lmiConn, $replication, $nodeID, $nodeRef, $reportArea );
			
					//$reports[] = $r;
					$r->replicate();
			
					$stats = $r->getReplicationStats();
			

			
					echo "__Replicated to $targetTable: " , $stats['repRows'] , " rows vs " , $stats['repTries'] , " attempts in ", $stats['repTime'], " msec", PHP_EOL;
			
					$finalStats['repTime'] += $stats['repTime'];
					$finalStats['repRows'] += $stats['repRows'];
					$finalStats['repTries'] += $stats['repTries'];
						
					echo "Ending memory peak usage: ", memory_get_peak_usage(true)/1000,"kb", PHP_EOL;
					echo "Report finished in (sec): ", $end/1000, PHP_EOL;

					if($stats['repTime']/1000 < $MIN_REPTIME)
					{
						//repTime in msec
						$reportSleepTime = 60 + mt_rand(0, 60);
			
						echo "Delaying next report for " . $reportSleepTime, " seconds" , PHP_EOL;
						if($reportSleepTime > 0)
						{
							sleep($reportSleepTime);
						}
					}
					
					echo "===============", PHP_EOL;
				}
				catch (Exception $e)
				{
					echo $e->getMessage() , PHP_EOL;
					echo $e->getLine() , PHP_EOL;
				}
				

					
				unset($replication);
				$replication = null;
					
				unset($r);
				$r = null;
				
				unset($stats);
				$stats = null;
			
				$end = number_format((microTime(true) - $start)*1000, 2, '.', '');
			
				$elapsedTime += $end;
			}
			
			$lmiConn->close();
			unset ($lmiConn);
			$lmiConn = null;
		}
		else
		{
			echo "Site at" , $siteDir, " is missing its config", PHP_EOL;
		}
	}
}



echo "============", PHP_EOL;
echo "Total Rows replicated: ", $finalStats['repRows'], PHP_EOL;
echo "Total Rows attempted: ", $finalStats['repTries'], PHP_EOL;
echo "Total Replication time (msec): ", $finalStats['repTime'], PHP_EOL;
echo "Total Replication time (min): ", $finalStats['repTime']/60000, PHP_EOL;
echo "Total Elapsed time (min): ", $elapsedTime/60000, PHP_EOL;

?>
