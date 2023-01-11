<html>
<?php

	require('../shared/common.inc.php');

	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));
	
	define(constant_name: 'MORE_TEXT', value: '...');
	
	if(CONFIG['Debugging'] == true)
	{
		ini_set(option: 'display_errors', value: 1);
		ini_set(option: 'display_startup_errors', value: 1);
		error_reporting(E_ALL);
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
	$aScanRarFilesGroup = array();

	#If a folder is given, scan items
	if(is_string($aFolder))
	{
		$aFolder = rtrim(string: $aFolder, characters: '/') . '/';
		$aScan = glob(pattern: "$aFolder*");
		foreach($aScan as $aScanPath)
		{
			$aScanItemName = rtrim(string: str_replace(search: $aFolder, replace: '', subject: $aScanPath), characters: '/');
			if(is_dir($aScanPath))
				$aScanFolders[$aScanItemName] = rtrim(string: $aScanPath, characters: '/') . '/';
			elseif(file_exists($aScanPath))
			{
				$aScanFiles[$aScanItemName] = $aScanPath;
				$aFilePathInfo = pathinfo($aScanPath);
				
				if(isset($aFilePathInfo['extension']) && preg_match('/^r[\d]+$/i', $aFilePathInfo['extension']) && in_array(needle: "{$aFilePathInfo['dirname']}/{$aFilePathInfo['filename']}.rar", haystack: $aScan))
					$aScanRarFilesGroup[$aFilePathInfo['filename']][] = $aFilePathInfo['basename'];
			}
		}
	}
	else
	{	//If no folder is a root array, use root items  
		foreach(CONFIG['ConvertRoots'] as $aRootID => $aRootPath)
			$aScanFolders[$aRootID] = rtrim(string: $aRootPath, characters: '/') . '/';
		$aFolder = 'HOME';
	}
	
	$aProbeData = null;
	
	
	
?>
<head>
	<title>FFMPEG - <?=$aFolder?></title>
	<link rel="stylesheet" href="jscss/index.css">
	<script src="jscss/explore.js"></script>
</head>
<body><grid>
<explore>
	<script>
	function dummy(){}

	function escapeHTML(aText)
	{
		var aMap = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		
		return aText.replace(/[&<>"']/g, function(m) { return aMap[m]; });
	}

	window.addEventListener('popstate', historyEvent);

	listFolderQuery('<?=isset($_GET['folder']) && !empty($_GET['folder']) ? htmlspecialchars($_GET['folder']) : '';?>');</script>
<?php
/*		foreach($aScanFolders as $aFolderName => $aFolderPath)
			echo "<folder><a href='?folder=" . urlencode($aFolderPath) . "'>$aFolderName</a></folder>";
		foreach($aScanFiles as $aFileName => $aFilePath)
		{
			$aFilePathInfo = pathinfo($aFilePath);
			$aPrint = true;
			$aScanLink = false;
			$aExtras = '';
			if(isset($aFilePathInfo['extension']))
				foreach(STATIC_CONFIG['scanmodules'] as $aScanModule => $aScanModuleData)
				{
					if(in_array(needle: $aFilePathInfo['extension'], haystack: $aScanModuleData['file_extensions']))
					{
						$aScanLink = true;
					}
					
				}
/*				switch(true)
				{
					case $aFilePathInfo['extension'] == "mkv":
					case $aFilePathInfo['extension'] == "mp4":
						$aScanLink = true;
						break;
					case $aFilePathInfo['extension'] == "rar":
						$aScanLink = isset(CONFIG['Binaries']['unrar']);
						if(isset($aScanRarFilesGroup[$aFilePathInfo['filename']]))
						{
							$aExtras = "(<a href='#' onclick='toggleHiddenGroup(this)'>" . count($aScanRarFilesGroup[$aFilePathInfo['filename']]) + 1 . " Parts</a>)";
							$aExtras .= "<group class='hidden'>";
							foreach($aScanRarFilesGroup[$aFilePathInfo['filename']] as $aGroupItem)
								$aExtras .= "<file>$aGroupItem</file>";
							$aExtras .= "</group>";
						}
						break;
					case preg_match('/^r[\d]+$/i', $aFilePathInfo['extension']) && isset($aScanFiles["{$aFilePathInfo['filename']}.rar"]):
						$aPrint = false;
						break;
				}
			if($aPrint)
			{
				echo '<file>';
				if($aScanLink)
					echo "<a href='" . FE_ROOT . "scan.php?type={$aFilePathInfo['extension']}&folder=" . urlencode($aFolder) . "&file=" . urlencode($aFilePath) . "'>";
				echo "$aFileName";
				if($aScanLink)
					echo '</a>';
				echo " $aExtras</file>";
			}
		}//*/
?></explore>
</grid></body>
</html>




<?php
// /DataVolume/scripts/software/bin/ffprobe -show_chapters -show_format     -show_streams     -print_format json     -loglevel quiet Windfall.2022.German.Netflix.DL.720p.x265.AAC-2BA/Windfall.2022.German.Netflix.DL.720p.x265.AAC-2BA.mkv 
?>
