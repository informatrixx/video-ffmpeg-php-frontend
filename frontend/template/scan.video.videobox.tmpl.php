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
	<selectButton index='##VAR:index+##' streamindex='##DATA:streamIndex##' active onclick='showTab(this)'>
		<input type='checkbox' name='map[##VAR:index##]' value='##VAR:fileIndex##:##DATA:streamIndex##' checked> ##DATA:resolutionName## (##DATA:width##x##DATA:height##), ##DATA:codec:name##
	</selectButton>
</selectButtons>
<selectContent index='##VAR:index##' streamindex='##DATA:streamIndex##' video>
	<label>Codec:</label><text>##DATA:codec:nameFull##</text>
	<label>Anzeige:</label><text>##DATA:displayAspectRatio##</text>
	<delimiter></delimiter>
	<label>Codec:</label><text>##DATA:codec:name##</text>
	<label>Qualität:</label><input type='number' name='crf[##VAR:index##]' min='0' max='51' value='##DATA:conversionSettings:crf##'>
	<label>Voreinstellung:</label><select name='preset[##VAR:index##]'>
		<?php
		foreach(STATIC_CONFIG['video']['presets'] as $aValue => $aText)
			echo "<option value='$aValue' ##SELECT:conversionSettings:preset=$aValue##>$aText</option>";
		?>
		</select>
	<label>Größe ändern:</label><select name='resize[##VAR:index##]'>
		<option value='0' ##SELECT:conversionSettings:resize=0##>-Original-</option>
		<?php
		foreach(DECISIONS['video'] as $aValue => $aVideoData)
			echo "<option value='$aValue' ##SELECT:conversionSettings:resize=$aValue##>{$aVideoData['name']}</option>";
		?>
		</select>
	<label>Zuschneiden:</label><select name='crop[##VAR:index##]'>
		<option value='auto' selected>Auto</option><option value='man'>Manuell</option><option value='off'>-kein Zuschneiden-</option>
		</select>
		<cropAutoChoice id='cropAutoChoice_##VAR:index##' index='##VAR:index##' fileIndex='##VAR:fileIndex##'></cropAutoChoice>
	<label>Denoise:</label><select name='nlmeans[##VAR:index##]'>
		<?php
		foreach(STATIC_CONFIG['video']['nlmeans'] as $aValue => $aNLMeansSettings)
			echo "<option value='$aValue' ##SELECT:conversionSettings:nlmeans=$aValue##>{$aNLMeansSettings['name']}</option>";
		?>
		</select>
</selectContent>