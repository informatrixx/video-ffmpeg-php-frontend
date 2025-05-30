<?php
	header('Content-Type: text/plain; charset=utf-8');
	
	require('../../shared/common.inc.php');
	
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));
	define(constant_name: 'DECISIONS', value: json_decode(json: file_get_contents(ROOT . 'config/decision_template.json'), associative: true));
	define(constant_name: 'OUTFOLDER_HISTORY', value: json_decode(json: file_get_contents(ROOT . 'config/outfolder_history.json'), associative: true));

	if(filemtime(__FILE__) < filemtime(ROOT . 'config/outfolder_history.json'))
		$aETag = '"' . filemtime(ROOT . 'config/outfolder_history.json') . '"';
	else
		$aETag = '"' . filemtime(__FILE__) . '"';

	header('Cache-Control: no-cache, must-revalidate, max-age=86400');
	header('ETag: ' . $aETag);
	
	if(isset($_SERVER['HTTP_IF_NONE_MATCH']))
	{
		if($_SERVER['HTTP_IF_NONE_MATCH'] == $aETag)
		{
			header('HTTP/1.1 304 Not Modified', true, 304);
			exit();
		}
	}

	
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
	<label>Ausgabepfad:</label>
	<select style='grid-column: span 2; max-width: 100%' name='outfolder' onChange='outFolderChange(this)'>
		<optgroup label='Verlauf'>
		<?php
		foreach(OUTFOLDER_HISTORY as $aOutFolder)
			echo "<option>$aOutFolder</option>"
		?>
		</optgroup>
		<optgroup label='Andere Ordner'>
			<option>##DATA:outfile:folder##</option>
			<option disabled>Durchsuchen...</option>
			<option data-do='promptOutFolder' value='##DATA:outfile:folder##'>Manuell...</option>
		</optgroup>
	</select>
	<label>Ausgabedatei:</label><div style='grid-column: span 2;'><input style='width: 90%' name='outfile' value='##DATA:outfile:fileName##'><button type="button" onclick='autoTitle(this, "filename")' style='width: 10%; top: 4px; position: relative' data-autoTitle='filename'><img src='img/note1-16.png' alt='Auto-File'/></button></div>
	<label>Tuning:</label><p><input type='checkbox' name='max_interleave_delta_null' value='1'> max_interleave_delta = 0</p>
	<delimiter></delimiter>
	<label>Auto-Titel:</label><button type="button" onclick='autoTitleAll()'><img src='img/note1-16.png' alt='Auto-Name'/> Automatisch</button></div>
	<label>Konvertieren:</label><button type='submit'>Weiter...</button>
</selectContent>