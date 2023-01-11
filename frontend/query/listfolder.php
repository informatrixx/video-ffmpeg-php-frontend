<?php

	header('Content-Type: application/json; charset=utf-8');
	
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents('../../config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents('../../config/static_config.json'), associative: true));
	
	if(CONFIG['Debugging'] == true)
	{
		ini_set(option: 'display_errors', value: 1);
		ini_set(option: 'display_startup_errors', value: 1);
		error_reporting(E_ALL);
	}

	
	$aSuccess = true;
	$aError = '';
	$aUpdateHistory = isset($_GET['history']) && $_GET['history'] != 0;
	$aScanFolders = array();
	$aScanFiles = array();

	function humanFilesize($aBytes, $aDecimals = 2, $aCustDecimals = array("B"=>0, "K"=>0, "M"=>0))
	{
		$sz = 'BKMGTP';
		$aFactor = floor((strlen($aBytes) - 1) / 3);
		$aUnit = @$sz[$aFactor];
		if(array_key_exists(key: $aUnit, array: $aCustDecimals))
			$aDecimals = $aCustDecimals[$aUnit];
		$aValue = $aBytes / pow(num: 1024, exponent: $aFactor);
		return sprintf("%.{$aDecimals}f", $aValue) . $aUnit;
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
	
	
	
	#Set scan folder
	if(isset($_GET['folder']) && !empty($_GET['folder']))
		$aFolder = $_GET['folder'];
	elseif(count(CONFIG['ConvertRoots']) == 1)
		$aFolder = array_values(CONFIG['ConvertRoots'])[0];
	else
		$aFolder = CONFIG['ConvertRoots'];
		
	if(is_string($aFolder))
	{
		if(!pathIsInConversionRoot($aFolder))
		{
			$aSuccess = false;
			$aError = "Wrong folder path! ($aFolder)";
		}
		else
		{
			$aFolder = rtrim(string: $aFolder, characters: '/') . '/';
			$aScan = glob(pattern: "$aFolder*");
			foreach($aScan as $aScanPath)
			{
				$aScanItemName = rtrim(string: str_replace(search: $aFolder, replace: '', subject: $aScanPath), characters: '/');
				if(is_dir($aScanPath))
					$aScanFolders[$aScanItemName] = rtrim(string: $aScanPath, characters: '/') . '/';
				elseif(file_exists($aScanPath))
					$aScanFiles[$aScanItemName] = $aScanPath;
			}		
			
		}
	}
	else
	{
		foreach(CONFIG['ConvertRoots'] as $aRootID => $aRootPath)
			$aScanFolders[$aRootID] = rtrim(string: $aRootPath, characters: '/') . '/';
		$aFolder = '';
	}
	
	$aResult = array(
		'success' =>	$aSuccess,
		'error' => 		$aError,
		'folder' =>		$aFolder,
		'folders' =>	$aScanFolders,
		'files' =>		$aScanFiles,
		'history' =>	$aUpdateHistory,
		);
	
	echo json_encode($aResult, JSON_PRETTY_PRINT);
?>