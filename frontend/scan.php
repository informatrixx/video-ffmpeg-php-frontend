<?php

	if(empty($_GET['file']))
	{
		header("HTTP/1.0 400 Bad Request");
		die();
	}
	
	require('../shared/common.inc.php');
	require('../shared/cache-gen.inc.php');

	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents('../config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents('../config/static_config.json'), associative: true));
	define(constant_name: 'DECISIONS', value: json_decode(json: file_get_contents('../config/decision_template.json'), associative: true));
	
	define(constant_name: 'MORE_TEXT', value: '...');
	
	if(CONFIG['Debugging'] == true)
	{
		ini_set(option: 'display_errors', value: 1);
		ini_set(option: 'display_startup_errors', value: 1);
		error_reporting(E_ALL);
	}
	
		
	$aScanFileName = realpath($_GET['file']);

	if(!pathIsInConversionRoot($aScanFileName))
	{
		header("HTTP/1.0 400 Bad Request");
		die("Wrong file path! ($aScanFileName)");
	}

	if(!array_key_exists(key: $_GET['type'], array: STATIC_CONFIG['scanModules']))
	{
		header("HTTP/1.0 400 Bad Request");
		die("Wrong module selection! ({$_GET['type']})");
	}


?>
<html>
<head>
	<title>SCAN - <?=htmlspecialchars(basename($_GET['file']))?></title>
	<link rel="stylesheet" href="<?= provideStaticContent('jscss/scan.css')?>">
	<link rel="stylesheet" href="<?= provideStaticContent('jscss/explore.css')?>">
	<script src="<?= provideStaticContent('jscss/scan.js')?>"></script>
	<script src="<?= provideStaticContent('jscss/explore.js')?>"></script>
	<?php
		switch($_GET['type'])
		{
			case 'video' :
				echo '<script src="' . provideStaticContent('jscss/scan.video.js') . '"></script>' . PHP_EOL;
				echo '<link rel="preload" as="fetch" href="template/scan.video.globalbox.tmpl.php">' . PHP_EOL;
				echo '<link rel="preload" as="fetch" href="template/scan.video.infobox.tmpl.php">' . PHP_EOL;
				echo '<link rel="preload" as="fetch" href="template/scan.video.videobox.tmpl.php">' . PHP_EOL;
				echo '<link rel="preload" as="fetch" href="template/scan.video.audiobox.tmpl.php">' . PHP_EOL;
				echo '<link rel="preload" as="fetch" href="template/scan.video.subtitlebox.tmpl.php">' . PHP_EOL;
				break;
			case 'rar' :
				echo '<script src="' . provideStaticContent('jscss/scan.rar.js') . '"></script>' . PHP_EOL;
				echo '<link rel="preload" as="fetch" href="template/scan.rar.globalbox.tmpl.php">' . PHP_EOL;
				echo '<link rel="preload" as="fetch" href="template/scan.rar.filesbox.tmpl.php">' . PHP_EOL;
				break;
		}
	?>
	<script>
		var gFileName = "<?=htmlspecialchars($_GET['file'])?>";
		var gScanType = "<?=htmlspecialchars($_GET['type'])?>";


		const PAGE_TITLE_PREFIX = 'SCAN - ';
		
		function dummy(){}
	</script>
</head>
<body>
<form action="query/addqueueitem.php" method="post" onsubmit="return collectFormSubmit(this)">
<grid>
	<explore></explore>
	<selectContainer id='globalContainer'></selectContainer>
</grid>
</form>
</body>
<script>
	
	window.addEventListener('popstate', historyEvent);

	exploreFolderQuery('<?=isset($_GET['folder']) && !empty($_GET['folder']) ? htmlspecialchars($_GET['folder']) : '';?>', false, gFileName);
	scanFileQuery(gFileName, "<?=htmlspecialchars($_GET['type']);?>");

</script>
</html>