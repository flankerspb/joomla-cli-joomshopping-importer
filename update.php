<?php

// ============== Restrictred web access ==============

if(PHP_SAPI !== 'cli')
{
	header('HTTP/1.0 404 Not Found');
	header('Status: 404 Not Found');
	
	die();
}

define('_JEXEC', 1);
define('FL_JSHOP_IMPORTER', 1);

require_once(__DIR__ . '/src/JoomShoppingImporter.php');
JoomShoppingImporter::init();

$classes = JoomShoppingImporter::$children;

$actions = getopt('ps');
$importers = getopt('', array_keys($classes));

if(count($actions) && count($importers))
{
	foreach($importers as $key => $value)
	{
		$importer = JoomShoppingImporter::getInstance($key);
		
		if(array_key_exists('p', $actions))
		{
			$importer->updateProducts();
		}
		
		if(array_key_exists('s', $actions))
		{
			$importer->updateStock();
		}
	}
}
else
{
	echo 'Script options:' . PHP_EOL;
	echo '  Sources:' . PHP_EOL;
	
	foreach($classes as $key => $value)
	{
		echo '    --' . $key . PHP_EOL;
	}
	
	echo '  Actions:' . PHP_EOL;
	echo '    -p - Import Products' . PHP_EOL;
	echo '    -s - Import Stock' . PHP_EOL;
}
