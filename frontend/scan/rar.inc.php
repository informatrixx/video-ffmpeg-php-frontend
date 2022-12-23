<html>
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
	
?>
<head>
	<title>FFMPEG - <?=$aFolder?></title>
	<link rel="stylesheet" href="jscss/index.css">
</head>
<body><form action="query/addqueueitem.php" method="get" onsubmit="return collectFormSubmit(this)">
<?php

?>
</form></body>
</html>




<?php
// /DataVolume/scripts/software/bin/ffprobe -show_chapters -show_format     -show_streams     -print_format json     -loglevel quiet Windfall.2022.German.Netflix.DL.720p.x265.AAC-2BA/Windfall.2022.German.Netflix.DL.720p.x265.AAC-2BA.mkv 
?>
