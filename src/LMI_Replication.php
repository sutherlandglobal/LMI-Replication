#!/usr/bin/php

<?php

include 'LMI_SOAP_Connection.php';
include 'ReplicationSite.php';
include 'LMI_Report.php';
include 'Thread.php';

ini_set('memory_limit', '512M');

$path = dirname(__FILE__) . "/";
$confDir = $path . "../conf/";
$confSuffix = ".conf";
$confFile = $confDir . "rep.conf";

$LOCKFILE_NAME = $confDir . "lockfile";

//check for lockfiles in the localdir, only once.
if(file_exists($LOCKFILE_NAME))
{
	echo "Aborting...lockfile found.", PHP_EOL;
	exit(1);
}
else if( !file_put_contents($LOCKFILE_NAME, getmypid(), LOCK_EX) )
{
	echo "Aborting...could not create lockfile.", PHP_EOL;
	exit(1);
}

//bad guess is it can take ~1.25 logins per minute.
// $REPORT_SLEEP_MIN = 1;
// $REPORT_SLEEP_MAX = 2;

//minimum replication time in seconds before sleeping
$MIN_REPTIME = 150;

$LMI_TABLE_PREFIX = "LMI_";

//for debugging, ignore sleep if force arg passed
if(count($argv) > 0 && $argv[1] != "-f")
{
	//sleep for random number of minutes
	$REPLICATION_SLEEP_MIN = 3;
	$REPLICATION_SLEEP_MAX = 17;

	$replicationSleepTime = (rand($REPLICATION_SLEEP_MIN, $REPLICATION_SLEEP_MAX) * 60 )+ rand(0,60);

	echo "Sleeping for " . $replicationSleepTime , PHP_EOL;
	sleep($replicationSleepTime);
}

$reportAreaDirective = "reportArea";
$nodeIDDirective = "nodeID";
$nodeRefDirective = "nodeRef";
$targetTableDirective = "targetTable";
$dateFieldDirective = "dateField";
$databaseDirective = "databaseName";

$soapURLDirective ="lmiURL";
$soapUserDirective ="lmiUser";
$soapPassDirective ="lmiPass";
$dbHostDirective = "dbHost";
$dbUserDirective ="dbUser";
$dbPassDirective ="dbPass";
$startDateDirective ="startDate";

$finalStats = array
(
	'repTime' =>0,
	'repRows' => 0,
	'repAttempts' => 0, 
);

$soapURL = "";
$soapUser = "";
$soapPass = "";
$dbHost = "";
$dbUser = "";
$dbPass = "";
$startDate ="";

$reports = array();

//supports a generic authentication conf file for soap and db access
//if it is not found, the lmi group conf file must specify logins
if(file_exists($confFile))
{
	echo "Reading login info from $confFile", PHP_EOL;

	foreach( file($confFile) as $line)
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
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($confDir), RecursiveIteratorIterator::CHILD_FIRST);

$confFiles = array();

foreach($iterator as $file)
{
	if(preg_match('/\.conf$/', $file) && $file != $confFile)
	{
		echo "Adding report conf file: ", $file, PHP_EOL;
		array_push($confFiles, $file);
	}
	
	//randomize the conf files a lot, so even if the login threshold is lowered again, replication should survive abeit run slower
	shuffle($confFiles);
}

#roster replication

foreach($confFiles as $file)
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
		$databaseName = "";

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
				else if($lineFields[0] == $databaseDirective)
				{
					$databaseName = $lineFields[1];
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

		if(!$reportArea || !$nodeID || !$nodeRef || !$targetTable || !$dateField || !$soapURL || !$soapUser || !$soapPass || !$dbHost || !$dbUser || !$dbPass || !$startDate)
		{
			var_dump(array($reportArea,  $nodeID,  $nodeRef,  $targetTable,  $dateField,  $soapURL,  $soapUser,  $soapPass,  $dbHost,  $dbUser,  $dbPass,  $startDate ));
			throw new Exception("Missing config directives");
		}

		$targetTable = $LMI_TABLE_PREFIX .  $nodeID . "_" . $targetTable;

		$lmiConn = new LMI_SOAP_Connection($soapURL,$soapUser,$soapPass);

		$replication = new ReplicationSite($dbHost,$dbUser, $dbPass, $targetTable,$dateField,$databaseName, $startDate);

		$r = new LMI_Report($lmiConn, $replication, $nodeID, $nodeRef, $reportArea );

		//$reports[] = $r;
		$r->replicate();

		$stats = $r->getReplicationStats();

		unset ($lmiConn);
		$lmiConn = null;
		
		unset($replication);
		$replication = null;

		unset($r);
		$r = null;

		echo "__Replicated to $targetTable: " , $stats['repRows'] , " rows vs " , $stats['repTries'] , " attempts in ", $stats['repTime'], " msec", PHP_EOL;

		$finalStats['repTime'] += $stats['repTime'];
		$finalStats['repRows'] += $stats['repRows'];
		$finalStats['repTries'] += $stats['repTries'];
			
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

		unset($stats);
		$stats = null;
	}
	catch (Exception $e)
	{
		echo $e->getMessage() , PHP_EOL;
		echo $e->getLine() , PHP_EOL;
	}

	echo "Ending memory peak usage: ", memory_get_peak_usage(true)/1000,"kb", PHP_EOL;
	echo "Report finished in (sec): ", $end/1000, PHP_EOL;
	echo "===============", PHP_EOL;

	$end = number_format((microTime(true) - $start)*1000, 2, '.', '');



	$elapsedTime += $end;
}

//	$threads = array();
//	foreach($reports as $report)
//	{
//		$threads[] = new Thread($r->replicate);
//		end($threads)->start();
//	}
//
//	while( !empty( $threads ) )
//	{
//		foreach( $threads as $index => $thread )
//		{
//			if( ! $thread->isAlive() )
//			{
//				unset( $threads[$index] );
//			}
//		}
//		// let the CPU do its work
//		sleep( $sleepThread );
//	}

echo "============", PHP_EOL;
echo "Total Rows replicated: ", $finalStats['repRows'], PHP_EOL;
echo "Total Rows attempted: ", $finalStats['repTries'], PHP_EOL;
echo "Total Replication time (msec): ", $finalStats['repTime'], PHP_EOL;
echo "Total Replication time (min): ", $finalStats['repTime']/60000, PHP_EOL;
echo "Total Elapsed time (min): ", $elapsedTime/60000, PHP_EOL;

//remove lockfiles
unlink($LOCKFILE_NAME);
?>
