<html>
<head>
	<title>Status</title>
	<link rel="stylesheet" href="jscss/status.css">
	<script src="jscss/status.js"></script>
</head>
<body>
	<div id='statusContainer'>
	<script>
	<?php
		
	define(constant_name: 'SCRIPT_DIR', value: rtrim(string: __DIR__, characters: '/') . '/');
	define(constant_name: 'QUMA_DIR', value: rtrim(string: realpath(SCRIPT_DIR . '../quma'), characters: '/') . '/');
	
	define(constant_name: 'QUEUE_FILE', value: QUMA_DIR . 'queue.json');
	
	$aConvertQueue = json_decode(json: file_get_contents(QUEUE_FILE), associative: true);
	
	
	foreach($aConvertQueue as $aQueueItem)
	{
		$aProgressData = array(
			'id' =>			$aQueueItem['id'],
			'infile' =>		$aQueueItem['settings']['infile'],
			'outfile' =>	$aQueueItem['settings']['outfile'],
			'duration' =>	$aQueueItem['settings']['duration'],
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
