<?php

	header('Content-Type: application/json; charset=utf-8');
	
	require('../../shared/common.inc.php');
	
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));
	define(constant_name: 'DECISIONS', value: json_decode(json: file_get_contents(ROOT . 'config/decision_template.json'), associative: true));

	if(isset($_GET['preset']) && isset(DECISIONS['presets']))
		define(constant_name: 'CONV_PRESET', value: $_GET['preset']);
	else
		define(constant_name: 'CONV_PRESET', value: array_keys(DECISIONS['presets'])[0]);

		
	const MORE_TEXT = '...';
	const CHANNELS_TEXT = 'Kanäle';
	
	
	if(isset($_GET['file']))
	{
		$aScanFileName = realpath($_GET['file']);

		if(!pathIsInConversionRoot($aScanFileName))
			die("Wrong file path! ($aScanFileName)");
		
		if(array_key_exists(key: $_GET['type'], array: STATIC_CONFIG['scanModules']))
			require("scan/{$_GET['type']}.inc.php");
		else
			die("Wrong module selection! ({$_GET['type']})");
	}
	
?>