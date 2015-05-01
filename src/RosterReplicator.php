<?php

class RosterReplicator
{
	private $dbHost;
	private $dbName;
	private $dbTable;	
	private $dbUser;
	private $dbPass;
	
	private $teams;
	
	private $soapClient;
	
	public function __construct( $dbHost, $dbName = "", $rosterTable, $dbUser, $dbPass, $soapClient )
	{
		//build the database connection and the soap client
		
		$this->dbHost = $dbHost;
		$this->dbName = $dbName;
		$this->rosterTable = $rosterTable;
		$this->dbUser = $dbUser;
		$this->dbPass = $dbPass;
		
		//build soapclient directly
		$this->soapClient = $soapClient;
		
		$this->teams = array();
	}
	
	private function getDatabaseConnection()
	{
		$dbConnection = mssql_connect($this->dbHost, $this->dbUser, $this->dbPass);
	
		if($dbConnection)
		{
			if($this->dbName != "")
			{
				if(!mssql_select_db($this->dbName))
				{
					echo "Could not find databaseName ", $this->dbName, " on server ", $this->dbHost, PHP_EOL;
	
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
	
	public function getTeamName($nodeID)
	{
		//nodeid is usually a user's parentid
		
		$teamName = "";
		if( $this->teams[$nodeID])
		{
			$teamName = $this->teams[$nodeID];
		}
		else
		{
			$teamName = $this->soapClient->getGroup($nodeID);

			$this->teams[$nodeID] = $teamName;
		}
		
		return $teamName;
	}
	
	public function replicate()
	{
		//get the list of users to try and replicate
		$userHierarchy = $this->loadRoster();
		
		try 
		{	
			$dbConnection =  $this->getDatabaseConnection();
			
			$insertRowPrefix = "insert $this->rosterTable Values (";
			
			$usersProcessed = 0;
					
			$this->teams = array();
						
			foreach($userHierarchy as $userObject)
			{				
				$nodeID = $userObject->iNodeID;
				$parentID = $userObject->iParentID;
				$name = $userObject->sName;
				$email = $userObject->sEmail;
				$desc = $userObject->sDescription;
				$status = $userObject->eStatus;
				$type = $userObject->eType;
				
				if($email)
				{
					//resolve parent id, and memoize it
					$team = $this->getTeamName($parentID);
					
					if($status != "Disabled")
					{
						$status = "Enabled";
					}
					
					$name = str_replace("'", "", $name);
					$name = str_replace(",", "", $name);
					$name = str_replace('"', "", $name);
					$name = str_replace("\\", "", $name);
					$name = str_replace("\n", " ", $name);
					
					$team = str_replace("'", "", $team);
					$team = str_replace(",", "", $team);
					$team = str_replace('"', "", $team);
					$team = str_replace("\\", "", $team);
					$team = str_replace("\n", " ", $team);
					
					$desc = str_replace("'", "", $desc);
					$desc = str_replace(",", "", $desc);
					$desc = str_replace('"', "", $desc);
					$desc = str_replace("\\", "", $desc);
					$desc = str_replace("\n", " ", $desc);
					
					$insertStatement = $insertRowPrefix .
						"\"" . $nodeID . "\"," .
						"\"" . $parentID . "\"," .
						"\"" . $team . "\"," .
						"\"" . $name . "\"," .
						"\"" . $email . "\"," .
						"\"" . $desc . "\"," .
						"\"" . $status . "\"," .
						"\"" . $type . "\")";
					
					//remove non-ascii characters
					$insertStatement = preg_replace('/[^(\x20-\x7F)\x0A]*/','', $insertStatement);
					
					//in a single transaction, update the entry by dropping it, then readding it
					$insertStatement = "begin tran; delete from " . $this->rosterTable . " where NODE_ID = '" . $nodeID . "'; " . $insertStatement . "; commit tran;";
					
					//echo $insertStatement, PHP_EOL;
					
					//fire and forget the insert, 
					try
					{
						if( !($dbConnection && mssql_query( $insertStatement ,$dbConnection)))
						{
							echo "Failed insert statement: ",$insertStatement, PHP_EOL;
	
							throw new Exception("Failure running query: $insertStatement --> " . mssql_get_last_message());
						}
						else
						{
							$usersProcessed++;
						}
					}
					catch (Exception $e)
					{
						echo $e->getMessage(), " at line: ", $e->getLine() , PHP_EOL;
					}
				}

			}
			
			echo "Roster replication processed ", $usersProcessed, " users for ", $this->rosterTable, PHP_EOL;
		}
		catch(Exception $e)
		{
			echo $e->getMessage(), " at line: ", $e->getLine() , PHP_EOL;
		}
		
		mssql_close($dbConnection);
		
		unset($dbConnection);
		$dbConnection = null;
	}
	
	private function loadRoster()
	{
		$hierarchyResult = $this->soapClient->getHierarchy();
		
		return $hierarchyResult;
	}
	
	function __destruct()
	{
// 		unset($this->soapClient);
// 		$this->soapClient = null;
	}
}

?>