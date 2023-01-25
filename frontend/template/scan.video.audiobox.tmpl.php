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
	<selectButton index='##VAR:index+##' streamindex='##DATA:streamIndex##' onclick='showTab(this)' active>
		<input type='checkbox' name='map[##VAR:index##]' value='##VAR:fileIndex##:##DATA:streamIndex##' checked> ##DATA:channels:layout##, ##DATA:language:short####DATA:title## <img onclick='duplicateStream(this)' class='duplicate' src='img/duplicate1-32.png' />
	</selectButton>
</selectButtons>
<selectContent index='##VAR:index##' streamindex='##DATA:streamIndex##' audio>
	<label>Sprache:</label><text>##DATA:language:human##</text>
	<label>Codec:</label><text>##DATA:codec:nameFull## ##DATA:codec:profile##</text>
	<label>Ziel Codec:</label><p>AAC</p><select name='profile[##VAR:index##]'>
		<?php
		foreach(STATIC_CONFIG['audio']['profile'] as $aValue => $aText)
			echo "<option value='$aValue' ##SELECT:conversionSettings:profile=$aValue##>$aText</option>";
		?>
		</select>
	<label>Größe:</label><text>##DATA:size:human##</text>
	<label>Titel:</label><input style='grid-column: span 2;' name='title[##VAR:index##]' value='##DATA:title##'>
	<label>Kanäle:</label><p>##DATA:channels:layout##</p><select name='ac[##VAR:index##]'>
		<?php
		foreach(STATIC_CONFIG['audio']['channels'] as $aValue => $aText)
			echo "<option value='$aValue' ##SELECT:conversionSettings:channels=$aValue##>$aText</option>";
		?>
		</select>
	<label>Bitrate:</label><p>##DATA:bitrate:human##</p><select name='b[##VAR:index##]'>
		<?php
		foreach(STATIC_CONFIG['audio']['bitrate'] as $aValue)
			echo "<option value='$aValue' ##SELECT:conversionSettings:bitrate=$aValue##>$aValue</option>";	
		?>
		</select>
	<label>Samplerate:</label><p>##DATA:sampleRate##</p><select name='ar[##VAR:index##]'>
		<?php
		foreach(STATIC_CONFIG['audio']['samplerate'] as $aValue)
			echo "<option value='$aValue' ##SELECT:conversionSettings:samplerate=$aValue##>$aValue</option>";	
		?>
		</select>
	<label>Loudnorm:</label><select name='loudnorm[##VAR:index##]'>
		<?php
		foreach(STATIC_CONFIG['audio']['loudnorm'] as $aValue => $aLNData)
			echo "<option value='$aValue' ##SELECT:conversionSettings:loudnorm=$aValue##>{$aLNData['name']}</option>";	
		?>
		</select>
	<label>Default:</label><input type='checkbox' name='default[##VAR:index##]' value='1' ##CHECK:disposition:default##>
	<label>Forced:</label><input type='checkbox' name='forced[##VAR:index##]' value='1' ##CHECK:disposition:forced##>
</selectContent>