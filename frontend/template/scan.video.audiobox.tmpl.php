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
		<input type='checkbox' name='map[##VAR:index##]' value='##VAR:fileIndex##:##DATA:streamIndex##' checked> ##DATA:channels:layout##, ##DATA:language:shortUC##, ##DATA:codec:nameUC##, ##DATA:bitrate:humanShort## <img onclick='duplicateStream(this)' class='duplicate' src='img/duplicate1-32.png' />
	</selectButton>
</selectButtons>
<selectContent index='##VAR:index##' streamindex='##DATA:streamIndex##' audio>
	<label>Sprache:</label><text>##DATA:language:human##</text>
	<label>Größe:</label><text>##DATA:size:human##</text>
	<delimiter></delimiter>
	<label>Titel:</label><input style='grid-column: span 2;' name='title[##VAR:index##]' value='##DATA:title##'>
	<label>Codec:</label><select name='c[##VAR:index##]' onchange='selectAudioCodec(this)'>
		<?php
		foreach(STATIC_CONFIG['audio']['codecs'] as $aCodecValue => $aCodecData)
		{
			echo "<optgroup label='{$aCodecData['name']}'>";
			if(isset($aCodecData['profile']))
				foreach($aCodecData['profile'] as $aProfileValue => $aText)
					echo "<option value='$aCodecValue' moreParams='profile[##VAR:index##]=$aProfileValue' ##SELECT:conversionSettings:profile=$aProfileValue##>$aText</option>";
			else
				echo "<option value='$aCodecValue' ##SELECT:conversionSettings:codec=$aCodecValue##>{$aCodecData['name']}</option>";
			echo "</optgroup>";
		}
		?>
		</select><p>##DATA:codec:nameFull## ##DATA:codec:profile##</p>
	<label>Kanäle:</label><select name='ac[##VAR:index##]'>
		<?php
		foreach(STATIC_CONFIG['audio']['channels'] as $aValue => $aText)
			echo "<option value='$aValue' ##SELECT:conversionSettings:channels=$aValue##>$aText</option>";
		?>
		</select><p>##DATA:channels:layout##</p>
	<label>Bitrate:</label><select name='b[##VAR:index##]'>
		<?php
		foreach(STATIC_CONFIG['audio']['bitrate'] as $aValue)
			echo "<option value='$aValue' ##SELECT:conversionSettings:bitrate=$aValue##>$aValue</option>";	
		?>
		</select><p>##DATA:bitrate:human##</p>
	<label>Samplerate:</label><select name='ar[##VAR:index##]'>
		<?php
		foreach(STATIC_CONFIG['audio']['samplerate'] as $aValue)
			echo "<option value='$aValue' ##SELECT:conversionSettings:samplerate=$aValue##>$aValue</option>";	
		?>
		</select><p>##DATA:sampleRate##</p>
	<label>Loudnorm:</label><select name='loudnorm[##VAR:index##]'>
		<?php
		foreach(STATIC_CONFIG['audio']['loudnorm'] as $aValue => $aLNData)
			echo "<option value='$aValue' ##SELECT:conversionSettings:loudnorm=$aValue##>{$aLNData['name']}</option>";	
		?>
		</select>
	<label>Default:</label><input type='checkbox' name='default[##VAR:index##]' value='1' ##CHECK:disposition:default##>
	<label>Forced:</label><input type='checkbox' name='forced[##VAR:index##]' value='1' ##CHECK:disposition:forced##>
</selectContent>