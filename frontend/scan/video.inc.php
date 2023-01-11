<?php

	function makeVideoConversionDecision(int $width, string $preset, ?string &$videoSizeName)
	{
		$aVideoSizeIndex = match(true)
		{
			$width > 7500	=> "8k", 
			$width > 3700	=> "4k",
			$width > 2400	=> "quad",
			$width > 1750	=> "1080",
			$width > 1100	=> "720",
			default			=> "sd"
		};
		
		$aResult = array();
		
		$videoSizeName = DECISIONS['video'][$aVideoSizeIndex]['name'];
		
		$aResult['crf'] = isset(DECISIONS['video'][$aVideoSizeIndex][$preset]['crf']) ? DECISIONS['video'][$aVideoSizeIndex][$preset]['crf'] : DECISIONS['video'][$aVideoSizeIndex]['crf'];
		$aResult['preset'] = isset(DECISIONS['video'][$aVideoSizeIndex][$preset]['preset']) ? DECISIONS['video'][$aVideoSizeIndex][$preset]['preset'] : DECISIONS['video'][$aVideoSizeIndex]['preset'];
		$aResult['crop'] = isset(DECISIONS['video'][$aVideoSizeIndex][$preset]['crop']) ? DECISIONS['video'][$aVideoSizeIndex][$preset]['crop'] : DECISIONS['video'][$aVideoSizeIndex]['crop'];
		$aResult['resize'] = isset(DECISIONS['video'][$aVideoSizeIndex][$preset]['crop']) ? DECISIONS['video'][$aVideoSizeIndex][$preset]['resize'] : DECISIONS['video'][$aVideoSizeIndex]['resize'];
		
		if(isset(DECISIONS['video'][$aVideoSizeIndex][$preset]['nlmeans']))
			$aResult['nlmeans'] = DECISIONS['video'][$aVideoSizeIndex][$preset]['nlmeans'];
		elseif(isset(DECISIONS['video'][$aVideoSizeIndex]['nlmeans']))
			$aResult['nlmeans'] = DECISIONS['video'][$aVideoSizeIndex]['nlmeans'];
		else
			foreach(STATIC_CONFIG['video']['nlmeans'] as $aNLMeansID => $aNLMeansData)
				if(isset($aNLMeansData['default']) && $aNLMeansData['default'] == true)
				{
					$aResult['nlmeans'] = $aNLMeansID;
					break;
				}
		
		return $aResult;
	}

	function makeAudioConversionDecision(string $language, string $preset, int $channels)
	{
		$aLangChoice = isset(DECISIONS['audio']['choices'][$language]) ? DECISIONS['audio']['choices'][$language] : DECISIONS['audio']['choices']['default'];
		$aSelectedProfile = $aLangChoice[$channels];
		
		$aResult = array();
		
		$aResult['loudnorm'] = $aLangChoice['loudnorm'];
		if(isset($aLangChoice['preset']))
			$preset = $aLangChoice['preset'];
		$aResult['profile'] = isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]) && isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['profile']) ? DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['profile'] : DECISIONS['audio']['profiles'][$aSelectedProfile]['profile'];
		$aResult['bitrate'] = isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]) && isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['bitrate']) ? DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['bitrate'] : DECISIONS['audio']['profiles'][$aSelectedProfile]['bitrate'];
		$aResult['samplerate'] = isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]) && isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['samplerate']) ? DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['samplerate'] : DECISIONS['audio']['profiles'][$aSelectedProfile]['samplerate'];
		$aResult['channels'] = isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]) && isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['channels']) ? DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['channels'] : DECISIONS['audio']['profiles'][$aSelectedProfile]['channels'];
		
		return $aResult;
	}
	
	
	
	$aFFProbeCmd = CONFIG['Binaries']['ffprobe'] . ' -show_chapters -show_format -show_streams -print_format json -loglevel quiet ' . escapeshellarg($aInputFile);
	
	$aJSONProbeData = shell_exec($aFFProbeCmd);
	$aProbeData = json_decode(json: $aJSONProbeData, associative: true);
	
	$aFileDuration = $aProbeData['format']['duration'];

	if(isset($_GET['json']))
		echo "<pre>" . print_r(value: $aJSONProbeData, return: true) . "</pre>";
	if(isset($_GET['raw']))
		echo "<pre>" . print_r(value: $aProbeData, return: true) . "</pre>";
		
	define(constant_name: 'DECISIONS', value: json_decode(json: file_get_contents('../config/decision_template.json'), associative: true));

	if(isset($_GET['preset']) && isset(DECISIONS['presets']))
		define(constant_name: 'CONV_PRESET', value: $_GET['preset']);
	else
		define(constant_name: 'CONV_PRESET', value: array_keys(DECISIONS['presets'])[0]);


		
		
	if(!empty($aProbeData))
	{
		echo "<selectContainer><selectButtons><selectButton>" . basename($_GET['file']) . "</selectButton></selectButtons>";
		echo "<selectContent>";
		if(isset($aProbeData['format']['format_long_name']))
			echo "<label>Format:</label><text>{$aProbeData['format']['format_long_name']}</text>";
		if(isset($aProbeData['format']['size']))
		{
			$aSize = humanFilesize($aProbeData['format']['size']) . 'B';
			echo "<label>Größe:</label><text>$aSize</text>";
		}
		if(isset($aProbeData['format']['duration']))
		{
			$aHours = str_pad(floor($aFileDuration / 3600), 2, "0", STR_PAD_LEFT);
			$aSeekLeft = round($aFileDuration, 0) % 3600;
			$aMinutes = str_pad(floor($aSeekLeft / 60), 2, "0", STR_PAD_LEFT);
			$aSeconds = str_pad(floor($aSeekLeft % 60), 2, "0", STR_PAD_LEFT);
			
			echo "<label>Dauer:</label><text>$aHours:$aMinutes:$aSeconds</text>";
			echo "<input type='hidden' name='duration' value='{$aProbeData['format']['duration']}' />";
		}
		$aVideoStreamsCount = 0;
		$aAudioLang = array();
		$aSubsLang = array();
		$aVideoSizeName = array();
		foreach($aProbeData['streams'] as $aStreamData)
		{
			switch($aStreamData['codec_type'])
			{
				case 'video': $aVideoStreamsCount++; break;
				case 'audio':
					$aAudioLang[] = array_key_exists(key: $aStreamData['tags']['language'], array: STATIC_CONFIG['languages']) ? STATIC_CONFIG['languages'][$aStreamData['tags']['language']] : strtoupper($aStreamData['tags']['language']);
					break;
				case 'subtitle':
					if(array_key_exists(key: 'language', array: $aStreamData['tags']))
						if(in_array(needle: $aStreamData['tags']['language'], haystack: DECISIONS['subtitles']['pick']))
							$aSubsLang[] = array_key_exists(key: $aStreamData['tags']['language'], array: STATIC_CONFIG['languages']) ? STATIC_CONFIG['languages'][$aStreamData['tags']['language']] : strtoupper($aStreamData['tags']['language']);
						else
							$aSubsLang[] = MORE_TEXT;
					break;
			}
		}
		$aSubsLang = array_unique($aSubsLang);
		if(in_array(needle: MORE_TEXT, haystack: $aSubsLang))
		{
			$aSubsLangOrdered = $aSubsLang;
			$aSubsLang = array();
			foreach($aSubsLangOrdered as $aValue)
			{
				if($aValue != MORE_TEXT)
					$aSubsLang[] = $aValue;
			}
			$aSubsLang[] = MORE_TEXT;
		}
			
			
		echo "<label>Video Streams:</label><text>$aVideoStreamsCount</text>";
		echo "<label>Audio Sprachen:</label><text>" . implode(separator: ', ', array: array_unique($aAudioLang)) . "</text>";
		echo "<label>Untertitel:</label><text>" . implode(separator: ', ', array: $aSubsLang) . "</text>";
		
		$aChapters = isset($aProbeData['chapters']) && count($aProbeData['chapters']) > 1 ? 'Vorhanden' : '-keine Information-';
		echo "<label>Kapitel:</label><text>$aChapters</text>";
		echo "<delimiter></delimiter>";
		
		echo "<label>Konvertierung Vorgabe:</label><select name='conversionchoice'>";
		foreach(DECISIONS['presets'] as $aPresetID => $aPresetName)
		{
			$aSelected = $aPresetID == CONV_PRESET ? 'selected' : '';
			echo "<option value='$aPresetID' $aSelected>$aPresetName</option>";
		}
		echo "</select>";
		$aTitle = isset($aProbeData['format']['tags']['title']) ? $aProbeData['format']['tags']['title'] : '';
		echo "<label>Titel:</label><input style='grid-column: span 2;' name='filetitle' value='$aTitle'>";
		$aInFile = str_replace(search: "'", replace: '\\\'', subject: $_GET['file']);
		$aOutFolder = str_replace(search: "'", replace: '\\\'', subject: dirname($_GET['file']));
		$aOutFile = str_replace(search: "'", replace: '', subject: basename($_GET['file'])) . '.mkv';
		echo "<input type='hidden' name='infile' value='$aInFile' />";
		echo "<label>Ausgabepfad:</label><input style='grid-column: span 2;' name='outfolder' value='$aOutFolder'>";
		echo "<label>Ausgabedatei:</label><input style='grid-column: span 2;' name='outfile' value='$aOutFile'>";
		echo "<label>Konvertieren:</label><button type='submit'>Weiter...</button>";
		echo "</selectContent></selectContainer>";
	}
?>
<selectContainer <?= empty($aProbeData) ? 'style="display:none"' : ''?>>
	<selectButtons><?php
		if(!empty($aProbeData))
		{
			$aActive = "active";
			foreach($aProbeData['streams'] as $aStreamData)
			{
				if($aStreamData['codec_type'] == 'video' && $aStreamData['disposition']['attached_pic'] != 1)
				{
					$aStreamIndex = $aStreamData['index'];
					$aVideoWidth = $aStreamData['width'];
					$aVideoHeight = $aStreamData['height'];
					$aVideoCodecName = $aStreamData['codec_name'];

					$aHours = str_pad(floor($aFileDuration / 3600), 2, "0", STR_PAD_LEFT);
					$aSeekLeft = round($aFileDuration, 0) % 3600;
					$aMinutes = str_pad(floor($aSeekLeft / 60), 2, "0", STR_PAD_LEFT);
					$aSeconds = str_pad(floor($aSeekLeft % 60), 2, "0", STR_PAD_LEFT);
					
					$aConvSettings[$aStreamIndex] = makeVideoConversionDecision(width: $aVideoWidth, preset: CONV_PRESET, videoSizeName: $aVideoSizeNaming);
					$aVideoSizeName[$aStreamIndex] = $aVideoSizeNaming;

					echo "<selectButton index='$aStreamIndex' $aActive onclick='showTab(this)'><input type='checkbox' name='map[$aStreamIndex]' value='0:$aStreamIndex' checked> $aVideoSizeNaming ({$aVideoWidth}x$aVideoHeight), $aVideoCodecName, $aHours:$aMinutes:$aSeconds</selectButton>";
					$aActive = "";
				}
				
			}	
		}
	?></selectButtons>
	<?php
	if(!empty($aProbeData))
	{
		foreach($aProbeData['streams'] as $aStreamData)
		{
			if($aStreamData['codec_type'] == 'video' && $aStreamData['disposition']['attached_pic'] != 1)
			{
				$aStreamIndex = $aStreamData['index'];
				$aInputWidth = $aStreamData['width'];
				$aInputHeight = $aStreamData['height'];
				$aInputCodec = $aStreamData['codec_long_name'];
				$aStreamSizeMB = isset($aStreamData['tags']['NUMBER_OF_BYTES']) ? humanFilesize($aStreamData['tags']['NUMBER_OF_BYTES']) . 'B' : '0';
				$aInputDARDiv = explode(separator: ':', string: $aProbeData['streams'][0]['display_aspect_ratio']);
				$aInputSAR = $aProbeData['streams'][0]['sample_aspect_ratio'];
				$aInputSARDiv = explode(separator: ':', string: $aProbeData['streams'][0]['sample_aspect_ratio']);

				$aDispAspectRatio = match(round(num: $aInputDARDiv[0] / $aInputDARDiv[1], precision: 1))
				{
					2.3, 2.4	=> '21:9',
					1.7			=> '16:9',
					1.6			=> '16:10',
					1.8			=> '17:9',
					default 	=> round(num: $aInputDARDiv[0] / $aInputDARDiv[1], precision: 2) . ":1",
				};
								
				echo "<selectContent index='$aStreamIndex'>";
				echo "<label>Codec:</label><text>$aInputCodec</text>";
				if($aStreamSizeMB != 0)
					echo "<label>Größe:</label><text>$aStreamSizeMB</text>";
				
				if(count($aInputSARDiv) == 2)
					$aPxAspectRatio = $aInputSARDiv[0] / $aInputSARDiv[1];
				else
					$aPxAspectRatio = 1;
					
				if($aPxAspectRatio != 1)
				{
					$aDisplayWidth = round(num: $aInputWidth * $aPxAspectRatio, precision: 0);
					echo "<label>Anzeige:</label><text>{$aDisplayWidth}x$aInputHeight, $aDispAspectRatio</text>";
					echo "<label>Pixel:</label><text>$aInputSAR</text>";
				}
				else
					echo "<label>Anzeige:</label><text>$aDispAspectRatio</text>";
				
				echo "<delimiter></delimiter>";
				echo "<label>Codec:</label><text>x265</text>";
				echo "<label>Qualität:</label><input type='number' name='crf[$aStreamIndex]' min='0' max='51' value='{$aConvSettings[$aStreamIndex]['crf']}'>";
				echo "<label>Voreinstellung:</label><select name='preset[$aStreamIndex]'>";
				foreach(STATIC_CONFIG['video']['presets'] as $aValue => $aText)
				{
					$aSelected = $aValue == $aConvSettings[$aStreamIndex]['preset'] ? 'selected' : '';
					echo "<option value='$aValue' $aSelected>$aText</option>";
				}
				echo "</select>";
				echo "<label>Größe ändern:</label><select name='resize[$aStreamIndex]'><option value='0'>-Original-</option>";
				foreach(DECISIONS['video'] as $aValue => $aVideoData)
				{
					$aSelected = ($aConvSettings[$aStreamIndex]['resize'] != false && $aValue == $aConvSettings[$aStreamIndex]['resize']) ? 'selected' : '';
					echo "<option value='$aValue' $aSelected>{$aVideoData['name']}</option>";
				}
				echo "	</select>";
				echo "<label>Zuschneiden:</label><select name='crop[$aStreamIndex]'>
							<option value='auto' selected>Auto</option><option value='man'>Manuell</option><option value='off'>-kein Zuschneiden-</option>
						</select><cropAutoChoice></cropAutoChoice>";
				echo "<label>Denoise:</label><select name='nlmeans[$aStreamIndex]'>";
				foreach(STATIC_CONFIG['video']['nlmeans'] as $aValue => $aNLMeansSettings)
				{
					$aSelected = $aValue == $aConvSettings[$aStreamIndex]['nlmeans'] ? 'selected' : '';
					echo "<option value='$aValue' $aSelected>{$aNLMeansSettings['name']}</option>";
				}
				echo "</select>";
				echo "</selectContent>";
			}
		}
	}
	

?>
</selectContainer>


<selectContainer <?= empty($aProbeData) ? 'style="display:none"' : ''?>>
	<selectButtons><?php
		if(!empty($aProbeData))
		{
			$aActive = "active";
			foreach($aProbeData['streams'] as $aStreamData)
			{
				if($aStreamData['codec_type'] == 'audio')
				{
					$aStreamIndex = $aStreamData['index'];
					if(array_key_exists(key: 'channel_layout', array: $aStreamData))
						$aChannelLayoutShort = ucfirst(str_replace(['(side)'], '', $aStreamData['channel_layout']));
					else
						$aChannelLayoutShort = $aStreamData['channels'] . 'ch';
					$aLanguageTag = strtoupper($aStreamData['tags']['language']);
					$aStreamLanguage = strtolower($aStreamData['tags']['language']);
					$aTitle = isset($aStreamData['tags']['title']) ? ', "' . $aStreamData['tags']['title'] . '"' : '';
					$aInputChannels = $aStreamData['channels'];
					
					echo "<selectButton index='$aStreamIndex' $aActive onclick='showTab(this)'><input type='checkbox' name='map[$aStreamIndex]' value='0:$aStreamIndex' checked> $aChannelLayoutShort, $aLanguageTag$aTitle <img onclick='duplicateStream(this)' class='duplicate' src='img/duplicate1-32.png' /></selectButton>";
					$aActive = "";
					$aConvSettings[$aStreamIndex] = makeAudioConversionDecision(language: $aStreamLanguage, preset: CONV_PRESET, channels: $aInputChannels);
				}
				
			}	
		}
	?></selectButtons>
	<?php
	if(!empty($aProbeData))
	{
		$aHidden = "";
		foreach($aProbeData['streams'] as $aStreamData)
		{
			if($aStreamData['codec_type'] == 'audio')
			{
				$aStreamIndex = $aStreamData['index'];
				$aInputProfileString = isset($aStreamData['profile']) ? ', ' . $aStreamData['profile'] : '';;
				$aInputCodec = $aStreamData['codec_long_name'];
				$aInputSampleRate = $aStreamData['sample_rate'];
				if(array_key_exists(key: 'channel_layout', array: $aStreamData))
						$aInputChannelLayout = ucfirst($aStreamData['channel_layout']);
					else
						$aInputChannelLayout = $aStreamData['channels'] . ' Kanäle';
				
				$aTitle = isset($aStreamData['tags']['title']) ? $aStreamData['tags']['title'] : '';
				$aStreamLanguage = array_key_exists(key: strtolower($aStreamData['tags']['language']), array: STATIC_CONFIG['languages']) ? STATIC_CONFIG['languages'][strtolower($aStreamData['tags']['language'])] : strtoupper($aStreamData['tags']['language']);
				$aDefaultChecked = $aStreamData['disposition']['default'] ? 'checked' : '';
				$aForcedChecked = $aStreamData['disposition']['forced'] ? 'checked' : '';
				
				echo "<selectContent index='$aStreamIndex' type='audio' $aHidden>";
				echo "<label>Sprache:</label> <text>$aStreamLanguage</text>";
				echo "<label>Codec:</label><text>$aInputCodec$aInputProfileString</text>";
				echo "<label>Ziel Codec:</label><p>AAC</p><select name='profile[$aStreamIndex]'>";
				foreach(STATIC_CONFIG['audio']['profile'] as $aValue => $aText)
				{
					$aSelected = $aValue == $aConvSettings[$aStreamIndex]['profile'] ? 'selected' : '';
					echo "<option value='$aValue' $aSelected>$aText</option>";
				}
				echo "</select>";
				if(isset($aStreamData['tags']['NUMBER_OF_BYTES']) || isset($aStreamData['tags']['NUMBER_OF_BYTES-eng']))
				{
					$aStreamSizeMB = humanFilesize(isset($aStreamData['tags']['NUMBER_OF_BYTES']) ? $aStreamData['tags']['NUMBER_OF_BYTES'] : $aStreamData['tags']['NUMBER_OF_BYTES-eng']) . 'B';
					echo "<label>Größe:</label><text>$aStreamSizeMB</text>";
				}

				echo "<label>Titel:</label><input style='grid-column: span 2;' name='title[$aStreamIndex]' value='$aTitle'>";

				echo "<label>Kanäle:</label><p>$aInputChannelLayout</p><select name='ac[$aStreamIndex]'>";
				foreach(STATIC_CONFIG['audio']['channels'] as $aValue => $aText)
				{
					$aSelected = $aValue == $aConvSettings[$aStreamIndex]['channels'] ? 'selected' : '';
					echo "<option value='$aValue' $aSelected>$aText</option>";
				}
				echo "</select>";
				
				echo "<label>Bitrate:</label><p>";
				if(isset($aStreamData['tags']['BPS']) || isset($aStreamData['tags']['BPS-eng']))
				{
					$aBPS = isset($aStreamData['tags']['BPS']) ? $aStreamData['tags']['BPS'] : $aStreamData['tags']['BPS-eng'];
					$aKBPS = round($aBPS / 1024, 0);
					echo "$aKBPS KB/s";
				}
				echo "</p><select name='b[$aStreamIndex]'>";
				foreach(STATIC_CONFIG['audio']['bitrate'] as $aValue)
				{
					$aSelected = $aValue == $aConvSettings[$aStreamIndex]['bitrate'] ? 'selected' : '';
					echo "<option value='$aValue' $aSelected>$aValue</option>";
				}
				echo "</select>";
				
				echo "<label>Samplerate:</label><p>$aInputSampleRate</p><select name='ar[$aStreamIndex]'>";
				foreach(STATIC_CONFIG['audio']['samplerate'] as $aValue)
				{
					$aSelected = $aValue == $aConvSettings[$aStreamIndex]['samplerate'] ? 'selected' : '';
					echo "<option value='$aValue' $aSelected>$aValue</option>";
				}
				echo "</select>";

				echo "<label>Loudnorm:</label><select name='loudnorm[$aStreamIndex]'>";
				foreach(STATIC_CONFIG['audio']['loudnorm'] as $aValue => $aLNData)
				{
					$aSelected = $aValue == $aConvSettings[$aStreamIndex]['loudnorm'] ? 'selected' : '';
					echo "<option value='$aValue' $aSelected>{$aLNData['name']}</option>";
				}
				echo "</select>";

				echo "<label>Default:</label><input type='checkbox' name='default[$aStreamIndex]' value='1' $aDefaultChecked>";
				echo "<label>Forced:</label><input type='checkbox' name='forced[$aStreamIndex]' value='1' $aForcedChecked>";

				echo "</selectContent>";
				
				$aHidden = "hidden";
			}
		}
	}
	

?>
</selectContainer>

<selectContainer <?= empty($aProbeData) ? 'style="display:none"' : ''?>>
	<selectButtons><selectButton>Untertitel</selectButton></selectButtons>
	<selectButtons><?php
		if(!empty($aProbeData))
		{
			$aActive = "active";
			foreach($aProbeData['streams'] as $aStreamData)
			{
				if($aStreamData['codec_type'] == 'subtitle')
				{
					$aStreamIndex = $aStreamData['index'];
					$aLanguageTag = array_key_exists(key: 'language', array: $aStreamData['tags']) ? strtoupper($aStreamData['tags']['language']) : '';
					$aStreamLanguage = array_key_exists(key: 'language', array: $aStreamData['tags']) ? strtolower($aStreamData['tags']['language']) : '';
					$aTitle = isset($aStreamData['tags']['title']) ? ', "' . $aStreamData['tags']['title'] . '"' : '';
					$aDefault = $aStreamData['disposition']['default'] ? 'default' : '';
					$aForced = $aStreamData['disposition']['forced'] ? 'forced' : '';
					
					$aChecked = array_key_exists(key: 'language', array: $aStreamData['tags']) && in_array(needle: $aStreamData['tags']['language'], haystack: DECISIONS['subtitles']['pick']) ? 'checked' : '';
						
					echo "<selectButton index='$aStreamIndex' $aActive $aDefault $aForced onclick='showTab(this)'><input type='checkbox' name='map[$aStreamIndex]' value='0:$aStreamIndex' $aChecked> $aLanguageTag$aTitle</selectButton>";
					$aActive = "";
				}
				
			}	
		}
?></selectButtons>
	<?php
	if(!empty($aProbeData))
	{
		$aSubsIndex = 1;
		$aHidden = "";
		foreach($aProbeData['streams'] as $aStreamData)
		{
			if($aStreamData['codec_type'] == 'subtitle')
			{
				$aStreamIndex = $aStreamData['index'];
				$aLanguageTag = array_key_exists(key: 'language', array: $aStreamData['tags']) ? strtoupper($aStreamData['tags']['language']) : '';
				if(array_key_exists(key: 'language', array: $aStreamData['tags']))
					$aStreamLanguage = array_key_exists(key: strtolower($aStreamData['tags']['language']), array: STATIC_CONFIG['languages']) ? STATIC_CONFIG['languages'][strtolower($aStreamData['tags']['language'])] : strtoupper($aStreamData['tags']['language']);
				else
					$aStreamLanguage = '';
				$aDefault = $aStreamData['disposition']['default'] ? 'default' : '';
				$aForced = $aStreamData['disposition']['forced'] ? 'forced' : '';
				$aDefaultChecked = $aStreamData['disposition']['default'] ? 'checked' : '';
				$aForcedChecked = $aStreamData['disposition']['forced'] ? 'checked' : '';
				$aInputCodec = $aStreamData['codec_long_name'];
				
				$aTitle = $aSubsIndex++ . ' - ';
				if(array_key_exists(key: 'language', array: $aStreamData['tags']) && array_key_exists(key: strtolower($aStreamData['tags']['language']), array: STATIC_CONFIG['languages']))
					$aTitle .= STATIC_CONFIG['languages'][strtolower($aStreamData['tags']['language'])];
				else
					$aTitle .= STATIC_CONFIG['languages']['unknown'];
					
				if($aForced)
					$aTitle .= ' (Forced)';
				
				echo "<selectContent index='$aStreamIndex' type='subtitle' $aHidden>";
				echo "<label>Sprache:</label> <text>$aStreamLanguage</text>";
				echo "<label>Codec:</label><text>$aInputCodec</text>";
				if(isset($aStreamData['tags']['NUMBER_OF_BYTES']) || isset($aStreamData['tags']['NUMBER_OF_BYTES-eng']))
				{
					$aStreamSizeMB = humanFilesize(isset($aStreamData['tags']['NUMBER_OF_BYTES']) ? $aStreamData['tags']['NUMBER_OF_BYTES'] : $aStreamData['tags']['NUMBER_OF_BYTES-eng']) . 'B';
					echo "<label>Größe:</label><text>$aStreamSizeMB</text>";
				}

				echo "<label>Titel:</label><input style='grid-column: span 2;' name='title[$aStreamIndex]' value='$aTitle'>";

				echo "<label>Default:</label><input type='checkbox' name='default[$aStreamIndex]' value='1' $aDefaultChecked>";
				echo "<label>Forced:</label><input type='checkbox' name='forced[$aStreamIndex]' value='1' $aForcedChecked>";
				echo "</selectContent>";
				
				$aHidden = "hidden";
			}
		}
	}
	

?></selectContainer>

<selectContainer id='cropPreviewContainer' <?= empty($aProbeData) ? 'style="display:none"' : ''?>>
	<selectButtons><?php
		if(!empty($aProbeData))
		{
			$aActive = "active";
			for($i = 1; $i < 11; $i++)
			{
				$aSeek = $aFileDuration / 11 * $i;
				$aHours = str_pad(floor($aSeek / 3600), 2, "0", STR_PAD_LEFT);
				$aSeekLeft = round($aSeek, 0) % 3600;
				$aMinutes = str_pad(floor($aSeekLeft / 60), 2, "0", STR_PAD_LEFT);
				$aSeconds = str_pad(floor($aSeekLeft % 60), 2, "0", STR_PAD_LEFT);
				echo "<selectButton index='$i' seek='$aSeek' $aActive seeking onclick='showTab(this)'>$aHours:$aMinutes:$aSeconds</selectButton>";
				$aActive = "";
			}
		}
	?></selectButtons>
<?php
	if(!empty($aProbeData))
	{
		$aHidden = "";
		for($i = 1; $i < 11; $i++)
		{
			$aSeek = $aFileDuration / 11 * $i;
			echo "<selectContent index='$i' seek='$aSeek' $aHidden><img class='loader' src='img/loader.svg'></selectContent>";
			$aHidden = "hidden";
		}
		echo "<script>var gAspectRatio = $aPxAspectRatio; chainLoadCropPreviewQuery(1);</script>";
	}
?></selectContainer>
</form></body>
</html>