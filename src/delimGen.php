<?php


#we have a num range and an offset of utf 8 chars, the greek alphabet

$len = 30;

mb_regex_encoding('UTF-8');
mb_internal_encoding("UTF-8");

$alphabet = "Α,α,Β,β,Γ,γ,Δ,δ,Ε,ε,Ζ,ζ,Η,η,Θ,θ,Ι,ι,Κ,κ,Λ,λ,Μ,μ,Ν,ν,Ξ,ξ,Ο,ο,Π,π,Ρ,ρ,Σ,σ,Τ,τ,Υ,υ,Φ,φ,Χ,χ,Ψ,ψ,Ω,ω";

$array = array();

foreach (mb_split(",",$alphabet) as $letter)
{
 	array_push($array, $letter);
}

$output = "";
for($i = 0; $i<$len; $i++)
{
 	$index = mt_rand(0, count($array)-1);

 	$output = $output . $array[$index];
}

 echo $output, PHP_EOL;

?>