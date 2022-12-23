<html>
<head>
	<title>Status</title>
	<link rel="stylesheet" href="jscss/status.css">
	<script src="jscss/status.js"></script>
</head>
<body>
	<div id='statusContainer'>
	<?php
		
	define(constant_name: 'SCRIPT_DIR', value: rtrim(string: __DIR__, characters: '/') . '/');
	define(constant_name: 'QUMA_DIR', value: rtrim(string: realpath(SCRIPT_DIR . '../quma'), characters: '/') . '/');
	
	define(constant_name: 'QUEUE_FILE', value: QUMA_DIR . 'queue.json');
	
	$aConvertQueue = json_decode(json: file_get_contents(QUEUE_FILE), associative: true);
	
	foreach($aConvertQueue as $aQueueItem)
	{
		$aStatusText = match($aQueueItem['status'])
		{
			0 => 'waiting',
			1 => 'readyToScan',
			2 => 'scanning',
			3 => 'readyToConvert',
			4 => 'converting',
			5 => 'done',
			90, 91, 92, 93, 94, 95 => 'error',
			99 => 'abort',
		};
		echo "<div id='{$aQueueItem['id']}' class='statusItem $aStatusText'>";
		echo "<div class='outfile'><div class='label'>Outfile:</div><div class='data'>{$aQueueItem['settings']['outfile']}</div></div>";
		echo '</div>';
	}
	?>
	</div>
</body>
</html>
