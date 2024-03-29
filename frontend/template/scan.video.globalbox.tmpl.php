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
<selectContent>
	<label>Vorgabe:</label><select name='conversionchoice'>
		<?php
		foreach(DECISIONS['presets'] as $aPresetID => $aPresetName)
			echo "<option value='$aPresetID'>$aPresetName</option>";
		?>
		</select>
	<label>Titel:</label><input style='grid-column: span 2;' name='filetitle' value='##DATA:info:title##'>
	<label>Ausgabepfad:</label><input style='grid-column: span 2;' name='outfolder' value='##DATA:outfile:folder##'>
	<label>Ausgabedatei:</label><input style='grid-column: span 2;' name='outfile' value='##DATA:outfile:fileName##'>
	<label>Konvertieren:</label><button type='submit'>Weiter...</button>
</selectContent>