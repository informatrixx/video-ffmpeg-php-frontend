<?php
	header('Content-Type: text/plain; charset=utf-8');
	
	$aETag = '"' . filemtime(__FILE__) . '"';

	header('Cache-Control: max-age=86400');
	header('ETag: ' . $aETag);

	if(isset($_SERVER['HTTP_IF_NONE_MATCH']))
	{
		if($_SERVER['HTTP_IF_NONE_MATCH'] == $aETag)
		{
			header('HTTP/1.1 304 Not Modified', true, 304);
			exit();
		}
	}

	require('../../shared/common.inc.php');
	
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));
	define(constant_name: 'DECISIONS', value: json_decode(json: file_get_contents(ROOT . 'config/decision_template.json'), associative: true));
	
?>
<selectButtons>
	<selectButton>Global</selectButton>
</selectButtons>
<selectContent class='archiveGlobal'>
	<label>Ausgabepfad:</label><input name='outfolder' value='##DATA:outfile:folder##'>
	<label>Pfade ignorieren:</label><p><input type='checkbox' name='ignorepaths' checked></p>
	<label>Vorhandene überschreiben:</label><p><input type='checkbox' name='overwrite' checked></p>
	<label>Entpacken:</label><button type='submit'>Weiter...</button>
	<input type='hidden' name='infile' value='##DATA:file##' />
</selectContent>