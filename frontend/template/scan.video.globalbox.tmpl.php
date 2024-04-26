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
	<label>Vorgabe:</label><select name='conversionchoice' onchange='reloadPreset(this)'>
		<?php
		foreach(DECISIONS['presets'] as $aPresetID => $aPresetName)
			echo "<option value='$aPresetID' ##SELECT:preset=$aPresetID##>$aPresetName</option>";
		?>
		</select>
	<delimiter></delimiter>
	<label>Titel:</label><input style='grid-column: span 2;' name='filetitle' value='##DATA:info:title##'>
	<label>Ausgabepfad:</label><div style='grid-column: span 2;'><input style='width: 90%' name='outfolder' value='##DATA:outfile:folder##'><button type='button' onclick='' style='width: 10%'>...</button></div>
	<label>Ausgabedatei:</label><input style='grid-column: span 2;' name='outfile' value='##DATA:outfile:fileName##'>
	<label>Tuning:</label><p><input type='checkbox' name='max_interleave_delta_null' value='1'> max_interleave_delta = 0</p>
	<delimiter></delimiter>
	<label>Konvertieren:</label><button type='submit'>Weiter...</button>
</selectContent>