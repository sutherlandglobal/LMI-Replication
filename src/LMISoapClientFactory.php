<?php

include 'LMISoapClient.php';

class LMISoapClientFactory
{
	private $url;

	public function __construct( $url )
	{
		$this->setUrl($url);
	}

	public function buildSoapClient($user, $pass)
	{
		//build the object and login
		return new LMISoapClient($this->url, $user, $pass);
	}
	
	public function setUrl($url)
	{
		$this->url = $url;
	}
}
?>