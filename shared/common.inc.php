<?php

	//Human readable Filesize output 
	function humanFilesize($aBytes, $aDecimals = 2, $aCustDecimals = array("B"=>0, "K"=>0, "M"=>0))
	{
		$aPrefix = 'BKMGTP';
		$aFactor = floor((strlen($aBytes) - 1) / 3);
		$aUnit = @$aPrefix[$aFactor];
		if(array_key_exists(key: $aUnit, array: $aCustDecimals))
			$aDecimals = $aCustDecimals[$aUnit];
		$aValue = $aBytes / pow(num: 1024, exponent: $aFactor);
		return sprintf("%.{$aDecimals}f", $aValue) . $aUnit;
	}
	
	//Define path constants
	define(constant_name: 'ROOT', value: rtrim(string: realpath(__DIR__ . '/..'), characters: '/') . '/');					//absolute root for scripts
	define(constant_name: 'REL_PATH', value: str_replace(search: ROOT, replace: '', subject: $_SERVER['SCRIPT_FILENAME']));	//current relative path of the main script (url)
	define(constant_name: 'REL_ROOT', value: str_replace(search: REL_PATH, replace: '', subject: $_SERVER['SCRIPT_NAME']));	//relative root from client side
	define(constant_name: 'FE_ROOT', value: REL_ROOT . 'frontend/');														//relative frontend root from client side
	
	
?>