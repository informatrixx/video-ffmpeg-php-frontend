<?php

	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents('../config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents('../config/static_config.json'), associative: true));
	
	define(constant_name: 'MORE_TEXT', value: '...');
	
	if(CONFIG['Debugging'] == true)
	{
		ini_set(option: 'display_errors', value: 1);
		ini_set(option: 'display_startup_errors', value: 1);
		error_reporting(E_ALL);
	}


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
	if(isset($_GET["folder"]))
		$aFolder = $_GET['folder'];
	elseif(count(CONFIG['ConvertRoots']) == 1)
		$aFolder = array_values(CONFIG['ConvertRoots'])[0];
	else
		$aFolder = CONFIG['ConvertRoots'];
	
	$aScanFolders = array();
	$aScanFiles = array();

	#If a folder is given, scan items
	if(is_string($aFolder))
	{
		if(!pathIsInConversionRoot($aFolder))
			die("Wrong folder path! ($aFolder)");
		
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
	else
	{	//If no folder is a root array, use root items  
		foreach(CONFIG['ConvertRoots'] as $aRootID => $aRootPath)
			$aScanFolders[$aRootID] = rtrim(string: $aRootPath, characters: '/') . '/';
	}
	
	$aProbeData = null;
	
	
?>
<html>
<head>
	<title>SCAN :: <?=$aFolder?></title>
	<link rel="stylesheet" href="jscss/scan.css">
	<script src="jscss/query.js"></script>
	<script src="jscss/index.js"></script>
	<script>
		var gFileName = "<?=$_GET['file']?>";
		var gCropDetect = new Array();
		var gCropMaxWidth = 0;
		var gCropMaxHeight = 0;
		var gPreferredCropString = "";
	</script>
</head>
<body><form action="query/addqueueitem.php" method="get" onsubmit="return collectFormSubmit(this)">
<explore><?php
	foreach($aScanFolders as $aFolderName => $aFolderPath)
		echo "<folder><a href='?folder=" . urlencode($aFolderPath) . "'>$aFolderName</a></folder>";
	foreach($aScanFiles as $aFileName => $aFilePath)
	{
		switch(pathinfo(path: $aFilePath, flags: PATHINFO_EXTENSION))
		{
			case "mkv":
			case "mp4":
				echo "<file><a href='scan.php?folder=" . urlencode($aFolder) . "&file=" . urlencode($aFilePath) . "'>$aFileName</a></file>";
				break;
			default:
				echo "<file>$aFileName</file>";
		}
	}
?></explore>
<?php

	if(isset($_GET['file']))
	{
		$aInputFile = realpath($_GET['file']);
		
		if(!pathIsInConversionRoot($aInputFile))
			die("Wrong file path! ($aInputFile)");
		
		foreach(STATIC_CONFIG['scanmodules'] as $aScanModule => $aScanModuleData)
		{
			if(in_array(needle: pathinfo(path: $aInputFile, flags: PATHINFO_EXTENSION), haystack: $aScanModuleData['file_extensions']))
			{
				$aIncludeFile = "scan/$aScanModule.inc.php";
				break;
			}
		}
		
		if(isset($aIncludeFile))
			require($aIncludeFile);
		else
			die("No matching scan module available! ($aInputFile)");
	}
?>