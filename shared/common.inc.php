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

	function processStringBackspace(string $string)
	{
		$aSplit = mb_str_split($string);

		$i = 0;

		while($i < count($aSplit))
		{
			if($aSplit[$i] == chr(8))
			{
				if($i > 0)
					$i--;
				array_splice($aSplit, $i, 2);
			}
			else
				$i++;
		}
		return implode($aSplit);	
	}
	
	function pathIsInConversionRoot(string $aPathString)
	{
		$aPathString = realpath($aPathString);
		$aIsInConvertRoot = false;
		foreach(CONFIG['ConvertRoots'] as $aRootKey => $aConvertRootPath)
			if(preg_match(pattern: '/^' . str_replace(search: '/', replace: '\/', subject: $aConvertRootPath) . '/', subject: $aPathString))
			{
				$aIsInConvertRoot = true;
				break;
			}
		return $aIsInConvertRoot;
	}

	function compareExtensionToScanModules(string $fileExtension, string $select = 'match')
	{
		$compare = function(string $fileExtension, string|array $compareData)
		{
			if(is_array($compareData))
			{
				if(in_array(needle: strtolower($fileExtension), haystack: $compareData))
					return true;
			}
			elseif(is_string($compareData) && $compareData != '')
			{
				if($compareData[0] == '/')
				{
					if(preg_match(pattern: $compareData, subject: $fileExtension))
						return true;
				}
				else
					if($compareData == strtolower($fileExtension))
						return true;
			}	
		};
			
		foreach(STATIC_CONFIG['scanModules'] as $aScanModule => $aScanModuleData)
			if(isset($aScanModuleData['fileExtensions'][$select]) && $compare(fileExtension: $fileExtension, compareData: $aScanModuleData['fileExtensions'][$select]))
				return $aScanModule;
		
		return false;
	}
	
	function globEscapeString(string $path)
	{
		$path = rtrim(string: $path, characters: '/') . '/';
		$path = preg_replace(pattern: '/([?\[\]*])/', replacement: '\\\$1', subject: $path);
		return $path;
	}
	
	//Define path constants
	define(constant_name: 'ROOT', value: rtrim(string: realpath(__DIR__ . '/..'), characters: '/') . '/');					//absolute root for scripts
	define(constant_name: 'REL_PATH', value: str_replace(search: ROOT, replace: '', subject: $_SERVER['SCRIPT_FILENAME']));	//current relative path of the main script (url)
	define(constant_name: 'REL_ROOT', value: str_replace(search: REL_PATH, replace: '', subject: $_SERVER['SCRIPT_NAME']));	//relative root from client side
	define(constant_name: 'FE_ROOT', value: REL_ROOT . 'frontend/');														//relative frontend root from client side
	define(constant_name: 'SCRIPT_PATH', value: rtrim(string: str_replace(search: pathinfo(path: $_SERVER['SCRIPT_FILENAME'], flags: PATHINFO_BASENAME), replace: '', subject: $_SERVER['SCRIPT_FILENAME']), characters: '/') . '/');	//absolute path of the current script													//relative frontend root from client side
	
	
?>