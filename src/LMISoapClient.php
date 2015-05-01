<?php
class LMISoapClient
{
	private $authCode;
	private $soapClient;
	
	const LOGIN_OK = "login_OK";
	const REQUEST_AUTH_CODE_OK = "requestAuthCode_OK";
	const RUN_REPORT_OK = "getReport_OK";
	const LOGOUT_OK = "logout_OK";
	const GET_GROUP_OK = "getGroup_OK";
	const GET_HIERARCHY_OK = "OK";	
	
	private $loggedIn;

	public function __construct( $url, $user, $pass )
	{
		//build the php soap client and try a login, thow if failure
		
		$this->loggedIn = false;

		$this->soapClient = new SoapClient($url);

		//define parameters
		$loginParams = array
		(
			'sEmail' => $user,
			'sPassword' => $pass
		);
		
		$login = $this->soapClient->login($loginParams);

		if($login->loginResult == LMISoapClient::LOGIN_OK)
		{
			$this->authCode = $this->generateAuthCode($this->soapClient->requestAuthCode($loginParams));

			$this->loggedIn = true;
			
			echo "got authcode " . $this->authCode , PHP_EOL;
		}
		else
		{
			print_r($login);
			
			throw new Exception ( "SOAP API Login failed" );
		}
	}


	private function generateAuthCode($request)
	{
		if($request->requestAuthCodeResult != LMISoapClient::REQUEST_AUTH_CODE_OK )
		{
			throw new Exception("Failed to request LMI Auth Code");
		}

		return $request->sAuthCode;
	}

	public function getAuthCode()
	{
		return $this->authCode;
	}
	
	public function getHierarchy()
	{
		$hierparams = array
		(
				"sAuthCode" => $this->getAuthCode()		
		);
		
		$hierarchyResult = $this->soapClient->getHierarchy($hierparams);
		
		//make sure we got a good result, exception otherwise
		
		//return the raw data, handle the lmi context here
		return $hierarchyResult->aHierarchy->HIERARCHY;
	}
	
	public function getGroup($nodeID)
	{
		$teamName = "";
		
		//soap call to resolve teamname
		$teamParams = array
		(
				"iNodeID" => $nodeID,
				"sAuthCode" => $this->authCode
		);
			
		$groupQueryResult = $this->soapClient->getGroup($teamParams);

		if( $groupQueryResult->getGroupResult == LMISoapClient::GET_GROUP_OK)
		{
			//var_dump($groupQueryResult);
			$teamName = $groupQueryResult->oGroup->sName;
		}
		
		return $teamName;
	}
	
	public function runReport($reportAreaParams,$reportDateParams, $getReportParams, $reportDelimiter)
	{
		$reportData = "";
		
		$delimiterParams = array
		(
			'sDelimiter' => $reportDelimiter,
		);
		
		#$timezone = -240;  //UTC -4 hours = = -240 minutes (EST during Daylight Savings)
		$timezone = -300;  //UTC -5 hours = = -300 minutes (CST during Daylight Savings)
		
		date_default_timezone_set('America/New_York');
		
		$time = localtime(time(), true);
		
		if(!$time['tm_isdst'])
		{
			$timezone -= 60;
		}
		
		$timeZoneParams = array(
			'sTimezone' => $timezone,
			'sAuthCode' => $this->authCode
		);
		
		$reportAreaParams['sAuthCode'] = $this->authCode;
		$reportDateParams['sAuthCode'] = $this->authCode;		
		$getReportParams['sAuthCode'] = $this->authCode;
		$delimiterParams['sAuthCode'] = $this->authCode;
		
		$setReportTimeZoneResponse= $this->soapClient->setTimeZone($timeZoneParams);
		$setReportAreaResponse = $this->soapClient->setReportArea($reportAreaParams);
		$setReportDateResponse = $this->soapClient->setReportDate_v2($reportDateParams);
		$setReportDelimiterResponse = $this->soapClient->setDelimiter($delimiterParams);
		
		$getReportResponse = $this->soapClient->getReport($getReportParams);
		
		if($getReportResponse->getReportResult != LMISoapClient::RUN_REPORT_OK)
		{
			var_dump($getReportResponse);
			throw new Exception ("Bad response running report: {$getReportResponse->getReportResult}" );
		}
		
		if (is_soap_fault($getReportResponse)) 
		{
    		//trigger_error("SOAP Fault: (faultcode: {$getReportResponse->faultcode}, faultstring: {$getReportResponse->faultstring})", E_USER_ERROR);
    		echo "SOAP Fault: (faultcode: {$getReportResponse->faultcode}, faultstring: {$getReportResponse->faultstring})", E_USER_ERROR, PHP_EOL;
		}
		else
		{
			$reportData = preg_replace('/\"/', '","',  preg_replace('/\,/', '', $getReportResponse->sReport));
		}
		
		return  $reportData;
	}
	
	public function close()
	{
		if($this->loggedIn)
		{
			echo "logging out\n";
			$logoutResult = $this->soapClient->logout();
		
			//var_dump($logoutResult);
			if($logoutResult->logoutResult == LMISoapClient::LOGOUT_OK)
			{
				echo "logout good\n";
			}
			else
			{
				echo "logout bad\n";
			}
			
			$this->loggedIn = false;
		}
		else
		{
			echo "not logged in, so not logging out\n";
		}
		
		unset($this->soapClient);
		$this->soapClient = null;
	}

	function __destruct()
	{		
		echo "Destructing soap client, memory usage: ", memory_get_usage(true)/1000,"kb", PHP_EOL;
	}
}
?>