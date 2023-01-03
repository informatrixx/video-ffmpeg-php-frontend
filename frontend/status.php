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
		if(isset($aQueueItem['settings']['duration']))
		{
			$aDuration = $aQueueItem['settings']['duration'];
			$aHours = str_pad(floor($aDuration / 3600), 2, "0", STR_PAD_LEFT);
			$aSeekLeft = round($aDuration, 0) % 3600;
			$aMinutes = str_pad(floor($aSeekLeft / 60), 2, "0", STR_PAD_LEFT);
			$aSeconds = str_pad(floor($aSeekLeft % 60), 2, "0", STR_PAD_LEFT);
			echo "<div class='duration'><div class='label'>Duration:</div><div class='data'>$aHours:$aMinutes:$aSeconds</div></div>";
			echo "<div class='progress' duration='$aDuration'><div class='label'>Progress:</div><div class='data'></div></div>";
		}
		echo '</div>';
	}
	?>
	</div>
</body>
</html>
