<!DOCTYPE html>
<html>
<head>
	<title>Status</title>
	<?php

	require('../shared/common.inc.php');
	require('../shared/cache-gen.inc.php');
	
	define(constant_name: 'QUMA_DIR', value: ROOT . '/quma/');
	define(constant_name: 'QUEUE_FILE', value: QUMA_DIR . 'queue.json');
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));

	?>
	<link rel="stylesheet" href="<?= provideStaticFile('css/status.css')?>">
	<script src="<?= provideStaticFile('js/status.js')?>"></script>
	<script src="<?= provideStaticFile('js/quma-status-codes.js')?>"></script>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
	<div id='statusContainer'>
	<script>
	<?php
		
	
	$aConvertQueue = json_decode(json: file_get_contents(QUEUE_FILE), associative: true);
	
	
	foreach($aConvertQueue as $aQueueItem)
	{
		if(isset($aQueueItem['result']) && isset($aQueueItem['result']['fileSize']))
			$aSizeArray = array(
				'human'	=> humanFilesize($aQueueItem['result']['fileSize']) . 'B',
				'bytes'	=> $aQueueItem['result']['fileSize'],
				);
		else
			$aSizeArray = null;
			
		$aProgressData = array(
			'id'		=> $aQueueItem['id'],
			'infile'	=> $aQueueItem['settings']['infile'],
			'outfile'	=> $aQueueItem['settings']['outfile'],
			'duration'	=> $aQueueItem['settings']['duration'],
			'size'		=> $aSizeArray,
			);
		$aStatusData = array(
			'id' =>			$aQueueItem['id'],
			'status' =>		$aQueueItem['status'],
			);
		
		echo "	displayProgress('" . json_encode(value: $aProgressData, flags: JSON_HEX_APOS + JSON_HEX_QUOT) . "');\r\n";
		echo "	changeStatus('" . json_encode(value: $aStatusData, flags: JSON_HEX_APOS + JSON_HEX_QUOT) . "');\r\n";
	}
	?></script>
	</div>
</body>
</html>
