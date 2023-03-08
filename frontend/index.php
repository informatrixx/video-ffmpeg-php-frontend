<!DOCTYPE html>
<html lang="en">
<?php

	require('../shared/common.inc.php');
	require('../shared/cache-gen.inc.php');

	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config.json'), associative: true));
	define(constant_name: 'QUMA_DIR', value: ROOT . '/quma/');
	define(constant_name: 'QUEUE_FILE', value: QUMA_DIR . 'queue.json');
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));
	
	define(constant_name: 'MORE_TEXT', value: '...');
	
	if(CONFIG['Debugging'] == true)
	{
		ini_set(option: 'display_errors', value: 1);
		ini_set(option: 'display_startup_errors', value: 1);
		error_reporting(E_ALL);
	}

	#Set scan folder
	$aFolder = '';
	$aFolderString = ' HOME ';
	if(isset($_GET['folder']) && !empty($_GET['folder']))
	{
		$aFolder = $_GET['folder'];
		$aFolderString = $aFolder;
	}
?>
<head>
	<title>FFMPEG - <?=$aFolder?></title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">

	<link rel="stylesheet" href="<?= provideStaticFile('css/index.css')?>">
	<link rel="stylesheet" href="<?= provideStaticFile('css/explore.css')?>">
	<link rel="stylesheet" href="<?= provideStaticFile('css/status.css')?>">

	<script src="<?= provideStaticFile('js/explore.js')?>"></script>
	<script src="<?= provideStaticFile('js/status.js')?>"></script>
	<script src="<?= provideStaticFile('js/quma-status-codes.js')?>"></script>
	<script>
		const PAGE_TITLE_PREFIX = 'FFMPEG - ';
		function dummy(){}
	</script>
</head>
<body>
<grid>
	<status info="base">
		<h1>Status<actions><img src='img/hide1-16.png' alt='Hide Status'><a href='status.php' target='quma_status'><img src='img/expand1-16.png' alt='Show full Status'></a></actions></h1>
	</status>
	<explore></explore>
</grid>
</body>
<script>

	const gStatusContainer = document.getElementsByTagName('status')[0];
	const gShowFullInfo = false;

	window.addEventListener('popstate', historyEvent);

	exploreFolderQuery('<?=str_replace(search: "'", replace: '\\x27', subject:$aFolder);?>', true, false);

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
	?>
</script>
</html>