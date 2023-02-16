<html>
<?php

	require('../shared/common.inc.php');
	require('../shared/cache-gen.inc.php');

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
	<link rel="stylesheet" href="<?= provideStaticContent('css/index.css')?>">
	<link rel="stylesheet" href="<?= provideStaticContent('css/explore.css')?>">
	<script src="<?= provideStaticContent('js/explore.js')?>"></script>
	<script>
		const PAGE_TITLE_PREFIX = 'FFMPEG - ';
		
		function dummy(){}
	</script>
</head>
<body>
<grid>
	<explore></explore>
</grid>
</body>
<script>
	
	window.addEventListener('popstate', historyEvent);

	exploreFolderQuery('<?=htmlspecialchars($aFolder);?>', false);
	
</script>
</html>