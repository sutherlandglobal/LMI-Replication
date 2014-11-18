<?php 

class DelimiterFactory
{
	const GREEK_ALPHA_STR = "Α,α,Β,β,Γ,γ,Δ,δ,Ε,ε,Ζ,ζ,Η,η,Θ,θ,Ι,ι,Κ,κ,Λ,λ,Μ,μ,Ν,ν,Ξ,ξ,Ο,ο,Π,π,Ρ,ρ,Σ,σ,Τ,τ,Υ,υ,Φ,φ,Χ,χ,Ψ,ψ,Ω,ω"; 
	const ENG_ALPHA_STR = "A,a,B,b,C,c,D,d,E,e,F,f,G,g,H,h,I,i,J,j,K,k,L,l,M,m,N,n,O,o,P,p,Q,q,R,r,S,s,T,t,U,u,V,v,W,w,X,x,Y,y,Z,z";
	const CYRILLIC_ALPHA_STR = "а,б,в,г,д,е,ё,ж,з,и,й,к,л,м,н,о,п,р,с,т,у,ф,х,ц,ч,ш,щ,ъ,ы,ь,э,ю,я,а,б,в,г,д,е,ё,ж,з,и,й,к,л,м,н,о,п,р,с,т,у,ф,х,ц,ч,ш,щ,ъ,ы,ь,э,ю,я";
	const NUMBERS_ALPHA_STR = "0,1,2,3,4,5,6,7,8,9";
	const ALPHA_SEP = ",";
	
	private $alphabets;
	
	private $alpha_array;
	private $maxLen;
	private $currentAlphabet;
	
	public function __construct($maxLen, $alphabetName)
	{
		$this->setLength($maxLen);
		
		$this->setAlphabet($alphabetName);
		
		$this->alphabets = array();
		$this->alphabets[] = DelimiterFactory::GREEK_ALPHA_STR;
		$this->alphabets[] = DelimiterFactory::ENG_ALPHA_STR;
		$this->alphabets[] = DelimiterFactory::CYRILLIC_ALPHA_STR;
		$this->alphabets[] = DelimiterFactory::NUMBERS_ALPHA_STR;
	}
	
	public function setLength($maxLen)
	{
		$this->maxLen = $maxLen;
	}
	
	public function setAlphabet($alphabetName)
	{
		$this->alpha_array = array();
		
		//greek is the default
		$this->currentAlphabet = DelimiterFactory::GREEK_ALPHA_STR;
		
		if($alphabetName == '1')
		{
			//english
			$this->currentAlphabet = DelimiterFactory::ENG_ALPHA_STR;
		}
		else if($alphabetName == '2')
		{
			//greek
			$this->currentAlphabet = DelimiterFactory::GREEK_ALPHA_STR;
		}
		else if($alphabetName == '3')
		{
			//CYRILLIC
			$this->currentAlphabet = DelimiterFactory::CYRILLIC_ALPHA_STR;
		}
		else if($alphabetName == '4')
		{
			$this->currentAlphabet = DelimiterFactory::NUMBERS_ALPHA_STR;
		}
		
		foreach (mb_split(DelimiterFactory::ALPHA_SEP,$this->currentAlphabet) as $letter)
		{
			array_push($this->alpha_array, $letter);
		}
	}
	
	public function genDiverseDelimiter($subAlphaLen)
	{
		//save old values to restore, this function shouldn't alter the factory state
		$oldMaxLen = $this->maxLen;
		$oldAlphabet = $this->currentAlphabet;
		
		$output = "";
		
		$this->setLength($subAlphaLen);

		for ($i = 0; $i< count($this->alphabets); $i++)
		{			
			$this->setAlphabet($i + 1);
			
			$output .= $this->genDelimiter();
		}
		
		//return factory to orginal state
		$this->setLength($oldMaxLen);
		$this->setAlphabet($oldAlphabet);
		
		//less likely delimiter will be intersperced with random alphabets out of sequence
		return $this->mb_str_shuffle($output);
	}
	
	private function mb_str_shuffle($string)
	{
		$len = mb_strlen($string);
		
		$chars = Array();
		
		while($len-- > 0) 
		{
			$chars[] = mb_substr($string, $len, 1, 'UTF-8');
		}
		shuffle($chars);
		
		return join('', $chars);
		
	}
	
	public function genDelimiter()
	{
		$output = "";
		$maxIndex = count($this->alpha_array)-1;
		for($i = 0; $i<$this->maxLen; $i++)
		{
			$index = mt_rand(0, $maxIndex);
		
			$output = $output . $this->alpha_array[$index];
		}
		
		return $output;
	}
}


?>