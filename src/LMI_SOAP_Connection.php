<?php
class LMI_SOAP_Connection
{
	private $authCode;
	private $soapClient;
	const LOGIN_OK = "login_OK";
	const REQUEST_AUTH_CODE_OK = "requestAuthCode_OK";
	const RUN_REPORT_OK = "getReport_OK";
	private $loggedIn;
	private $reportData;

	public function __construct( $url, $user, $pass )
	{
		$this->loggedIn = false;
		//$soapClient = new SoapClient("https://secure.logmeinrescue.com/api/api.asmx?wsdl");

		$this->soapClient = new SoapClient($url);

		//define parameters
		$loginParams = array
		(
			'sEmail' => $user,
			'sPassword' => $pass
		);
		
		$login = $this->soapClient->login($loginParams);

		if($login->loginResult == LMI_SOAP_CONNECTION::LOGIN_OK)
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
		if($request->requestAuthCodeResult != LMI_SOAP_CONNECTION::REQUEST_AUTH_CODE_OK )
		{
			throw new Exception("Failed to request LMI Auth Code");
		}

		return $request->sAuthCode;
	}

	public function getAuthCode()
	{
		return $this->authCode;
	}
	
	public function runReport($reportAreaParams,$reportDateParams, $getReportParams, $reportDelimiter)
	{
		$this->reportData = "";
		
		$delimiterParams = array
		(
			//'sDelimiter' => '","',
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
		//$setReportTimeResponse = $this->soapClient->setReportTime($reportTimeParams);
		
		//$setReportDateResponse = $this->soapClient->setReportDate($reportDateParams);
		$setReportDateResponse = $this->soapClient->setReportDate_v2($reportDateParams);
		
		//var_dump( $this->soapClient->getReportDate_v2());
		
		$setReportDelimiterResponse = $this->soapClient->setDelimiter($delimiterParams);
		
		$getReportResponse = $this->soapClient->getReport($getReportParams);
		
		if($getReportResponse->getReportResult != LMI_SOAP_Connection::RUN_REPORT_OK)
		{
			throw new Exception ("Bad response running report: $getReportResponse->getReportResult");
		}
		
		
		if (is_soap_fault($getReportResponse)) 
		{
    		//trigger_error("SOAP Fault: (faultcode: {$getReportResponse->faultcode}, faultstring: {$getReportResponse->faultstring})", E_USER_ERROR);
    		echo "SOAP Fault: (faultcode: {$getReportResponse->faultcode}, faultstring: {$getReportResponse->faultstring})", E_USER_ERROR, PHP_EOL;
		}
		else
		{
			$this->reportData = preg_replace('/\"/', '","',  preg_replace('/\,/', '', $getReportResponse->sReport));
		}
		
		//quotes are returned as #quot; so we can seperate on that and remove commas, then give it a csv-like seperator
		//$this->reportData = preg_replace('/\,/', '', $this->reportData);
		
		return  $this->reportData;
	}

	function __destruct()
	{		
		if($this->loggedIn)
		{
			echo "logging out\n";
			$logoutResult = $this->soapClient->logout();

			//var_dump($logoutResult);
			if($logoutResult->logoutResult == "logout_OK")
			{
				echo "logout good\n";
			}
			else
			{
				echo "logout bad\n";
			}
		}
		else
		{
			echo "not logged in, so not logging out\n";
		}
		
		unset($this->soapClient);
		$this->soapClient = null;
		
		echo "Destructing soap client, memory usage: ", memory_get_usage(true)/1000,"kb", PHP_EOL;
	}
}
?>