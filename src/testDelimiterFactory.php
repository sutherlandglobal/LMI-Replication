<?php

include 'DelimiterFactory.php';

$dgen = new DelimiterFactory(10, 3);
echo $dgen->genDelimiter(), PHP_EOL;

$dgen->setLength(20);
$dgen->setAlphabet(2);
echo $dgen->genDelimiter(), PHP_EOL;

$dgen->setLength(50);
$dgen->setAlphabet(1);
echo $dgen->genDelimiter(), PHP_EOL;

$dgen->setLength(50);
$dgen->setAlphabet(6);
echo $dgen->genDelimiter(), PHP_EOL;

$dgen->setLength(50);
$dgen->setAlphabet(4);
echo $dgen->genDelimiter(), PHP_EOL;

echo "=========", PHP_EOL, $dgen->genDiverseDelimiter(8000), PHP_EOL,"=========", PHP_EOL;

echo $dgen->genDelimiter(), PHP_EOL;

?>