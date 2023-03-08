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
	<status>
	</status>
	<script>

	const gStatusContainer = document.getElementsByTagName('status')[0];
	const gShowFullInfo = true;
	
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
				
				$aStatusData = array(
					'duration'	=> isset($aQueueItem['settings']['duration']) ? $aQueueItem['settings']['duration'] : null,
					'id'		=> $aQueueItem['id'],
					'infile'	=> $aQueueItem['settings']['infile'],
					'outfile'	=> isset($aQueueItem['settings']['outfile']) ? $aQueueItem['settings']['outfile'] : null,
					'size'		=> $aSizeArray,
					'id'		=> $aQueueItem['id'],
					'status' 	=> $aQueueItem['status'],
					'type'	 	=> $aQueueItem['settings']['type'],
					);
			
			if(preg_match(pattern: '/^(?<id>[0-9a-f]+)(?>x(?<subIndex>\d+))/', subject: $aQueueItem['id'], matches: $aIDMatches))
				continue;
			
			echo "	changeStatus('" . json_encode(value: $aStatusData, flags: JSON_HEX_APOS + JSON_HEX_QUOT) . "');\r\n";
		}
	?></script>
</body>
</html>
