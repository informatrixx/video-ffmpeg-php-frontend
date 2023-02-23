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
		
		$aResult['codec'] = isset(DECISIONS['video'][$aVideoSizeIndex][$preset]['codec']) ? DECISIONS['video'][$aVideoSizeIndex][$preset]['codec'] : DECISIONS['video'][$aVideoSizeIndex]['codec'];
		$aResult['mode'] = isset(DECISIONS['video'][$aVideoSizeIndex][$preset]['mode']) ? DECISIONS['video'][$aVideoSizeIndex][$preset]['mode'] : DECISIONS['video'][$aVideoSizeIndex]['mode'];
		$aResult['codecMode'] = $aResult['codec'] . '_' . $aResult['mode'];

		$aResult['modeValue'] = isset(DECISIONS['video'][$aVideoSizeIndex][$preset]['setting']) ? DECISIONS['video'][$aVideoSizeIndex][$preset]['setting'] : DECISIONS['video'][$aVideoSizeIndex]['setting'];

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
		$aResult['codec'] = isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]) && isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['codec']) ? DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['codec'] : DECISIONS['audio']['profiles'][$aSelectedProfile]['codec'];
		$aResult['profile'] = isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]) && isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['profile']) ? DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['profile'] : DECISIONS['audio']['profiles'][$aSelectedProfile]['profile'];
		$aResult['bitrate'] = isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]) && isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['bitrate']) ? DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['bitrate'] : DECISIONS['audio']['profiles'][$aSelectedProfile]['bitrate'];
		$aResult['samplerate'] = isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]) && isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['samplerate']) ? DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['samplerate'] : DECISIONS['audio']['profiles'][$aSelectedProfile]['samplerate'];
		$aResult['channels'] = isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]) && isset(DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['channels']) ? DECISIONS['audio']['profiles'][$aSelectedProfile][$preset]['channels'] : DECISIONS['audio']['profiles'][$aSelectedProfile]['channels'];
		
		return $aResult;
	}
	
	
	
	$aFFProbeCmd = CONFIG['Binaries']['ffprobe'] . ' -show_chapters -show_format -show_streams -print_format json -loglevel quiet ' . escapeshellarg($aScanFileName);
	
	$aJSONProbeData = shell_exec($aFFProbeCmd);
	$aFFProbeData = json_decode(json: $aJSONProbeData, associative: true);
	
	if(empty($aFFProbeData))
	{
		echo json_encode(value: array(
			'success' =>	false,
			), flags: JSON_PRETTY_PRINT);
		exit;	
	}
	
	$aFileDuration = null;
	$aVideoStreamsCount = 0;
	$aAudioLanguages = array();
	$aSubtitleLanguages = array();
	$aVideoStreams = array();
	$aAudioStreams = array();
	
	
	if(isset($aFFProbeData['format']['duration']))
	{
		$aRawDuration = $aFFProbeData['format']['duration'];
		$aSeekLeft = round($aRawDuration, 0) % 3600;
		
		$aFileDuration = array(
			'human' =>		str_pad(floor($aRawDuration / 3600), 2, "0", STR_PAD_LEFT) . ':' . 
							str_pad(floor($aSeekLeft / 60), 2, "0", STR_PAD_LEFT) . ':' . 
							str_pad(floor($aSeekLeft % 60), 2, "0", STR_PAD_LEFT),
			'seconds' =>	$aRawDuration,
			);
	}
	
	foreach($aFFProbeData['streams'] as $aStreamData)
	{
		$aSI = (string) $aStreamData['index'];

		switch($aStreamData['codec_type'])
		{
			case 'video': 
				if($aStreamData['disposition']['attached_pic'] != 1)
					$aVideoStreamsCount++;
				
				$aSizeInBytes = match(true) 
				{
					isset($aStreamData['tags']['NUMBER_OF_BYTES']) =>		$aStreamData['tags']['NUMBER_OF_BYTES'],
					isset($aStreamData['tags']['NUMBER_OF_BYTES-eng']) =>	$aStreamData['tags']['NUMBER_OF_BYTES-eng'],
					default => null
				};

				$aInputDARDiv = explode(separator: ':', string: $aStreamData['display_aspect_ratio']);
				$aInputSAR = $aStreamData['sample_aspect_ratio'];
				$aInputSARDiv = explode(separator: ':', string: $aStreamData['sample_aspect_ratio']);

				$aCropPreviewSeek = array();
				for($i = 1; $i < 11; $i++)
					{
						$aSeek = $aFileDuration['seconds'] / 11 * $i;
						$aHours = str_pad(floor($aSeek / 3600), 2, "0", STR_PAD_LEFT);
						$aSeekLeft = round($aSeek, 0) % 3600;
						$aMinutes = str_pad(floor($aSeekLeft / 60), 2, "0", STR_PAD_LEFT);
						$aSeconds = str_pad(floor($aSeekLeft % 60), 2, "0", STR_PAD_LEFT);
						$aCropPreviewSeek[$i] = array(
							'seconds' =>	$aSeek,
							'human' =>		"$aHours:$aMinutes:$aSeconds",
						);
					}				
				
				$aVideoStreams[] = array(
					'codec' => array(
						'name' =>		$aStreamData['codec_name'],
						'nameFull' =>	$aStreamData['codec_long_name'],
						),
					'conversionSettings' => makeVideoConversionDecision(width: $aStreamData['width'], preset: CONV_PRESET, videoSizeName: $aVideoSizeNaming),
					'cropPreviewSeek' =>	$aCropPreviewSeek,
					'displayAspectRatio' => match(round(num: $aInputDARDiv[0] / $aInputDARDiv[1], precision: 1))
						{
							2.3, 2.4	=> '21:9',
							1.7			=> '16:9',
							1.6			=> '16:10',
							1.8			=> '17:9',
							default 	=> round(num: $aInputDARDiv[0] / $aInputDARDiv[1], precision: 2) . ":1",
						},
					'height' => $aStreamData['height'],
					'resolutionName' => $aVideoSizeNaming,
					'sampleAspectRatio' => array(
						'human' =>		$aStreamData['sample_aspect_ratio'],
						'pxRatio' =>	count($aInputSARDiv) == 2 ? $aInputSARDiv[0] / $aInputSARDiv[1] : 1,
						),
					'size' => array(
						'bytes' =>	isset($aSizeInBytes) ? $aSizeInBytes : null,
						'human' =>	isset($aSizeInBytes) ? humanFilesize($aSizeInBytes) . 'B' : null,
						),
					'streamIndex' => $aSI,
					'width' => $aStreamData['width'],
					);
				
				break;
			case 'audio':
				if(isset($aStreamData['tags']['language']))
					$aAudioLanguages[] = array_key_exists(key: $aStreamData['tags']['language'], array: STATIC_CONFIG['languages']) ? STATIC_CONFIG['languages'][$aStreamData['tags']['language']] : strtoupper($aStreamData['tags']['language']);
				
				$aBitRate = match(true)
				{
					isset($aStreamData['bit_rate']) =>			$aStreamData['bit_rate'],
					isset($aStreamData['tags']['BPS']) =>		$aStreamData['tags']['BPS'],
					isset($aStreamData['tags']['BPS-eng']) =>	$aStreamData['tags']['BPS-eng'],
					default => null
				};
				$aSizeInBytes = match(true)
				{
					isset($aStreamData['tags']['NUMBER_OF_BYTES']) =>		$aStreamData['tags']['NUMBER_OF_BYTES'],
					isset($aStreamData['tags']['NUMBER_OF_BYTES-eng']) =>	$aStreamData['tags']['NUMBER_OF_BYTES-eng'],
					default => null
				};
				
				$aAudioStreams[] = array(
					'bitrate' => array(
						'bps' =>		$aBitRate,
						'human' =>		round($aBitRate / 1024, 0) . ' KB/s',
						'humanShort' =>	round($aBitRate / 1024, 0) . 'K/s',
						),
					'channels' => array(
						'count' =>	$aStreamData['channels'],
						'layout' =>	array_key_exists(key: 'channel_layout', array: $aStreamData) ? $aStreamData['channel_layout'] : null,
						),
					'codec' => array(
						'name' =>		$aStreamData['codec_name'],
						'nameUC' =>		strtoupper($aStreamData['codec_name']),
						'nameFull' =>	$aStreamData['codec_long_name'],
						'profile' =>	isset($aStreamData['profile']) ? $aStreamData['profile'] : null,
						),
					'conversionSettings' => makeAudioConversionDecision(language: isset($aStreamData['tags']['language']) ? $aStreamData['tags']['language'] : 'default', preset: CONV_PRESET, channels: $aStreamData['channels']),
					'disposition' => array(
						'default' =>	$aStreamData['disposition']['default'],
						'forced' =>		$aStreamData['disposition']['forced'],
						),
					'language' => array(
						'human' =>		array_key_exists(key: $aStreamData['tags']['language'], array: STATIC_CONFIG['languages']) ? STATIC_CONFIG['languages'][$aStreamData['tags']['language']] : strtoupper($aStreamData['tags']['language']),
						'short' =>		$aStreamData['tags']['language'],
						'shortUC' =>	strtoupper($aStreamData['tags']['language']),
						),
					'sampleRate' => $aStreamData['sample_rate'],
					'size' => array(
						'bytes' =>	isset($aSizeInBytes) ? $aSizeInBytes : null,
						'human' =>	isset($aSizeInBytes) ? humanFilesize($aSizeInBytes) . 'B' : null,
						),
					'streamIndex' => $aSI,
					'title' => isset($aStreamData['tags']['title']) ? $aStreamData['tags']['title'] : null,
					);
				
				break;
			case 'subtitle':
				if(isset($aStreamData['tags']) && array_key_exists(key: 'language', array: $aStreamData['tags']))
					if(in_array(needle: $aStreamData['tags']['language'], haystack: DECISIONS['subtitles']['pick']))
						$aSubtitleLanguages[] = array_key_exists(key: $aStreamData['tags']['language'], array: STATIC_CONFIG['languages']) ? STATIC_CONFIG['languages'][$aStreamData['tags']['language']] : strtoupper($aStreamData['tags']['language']);
					else
						$aSubtitleLanguages[] = MORE_TEXT;
						
				$aSubtitleStreams[] = array(
					'codec' => array(
						'name' =>		$aStreamData['codec_name'],
						'nameFull' =>	$aStreamData['codec_long_name'],
						),
					'conversionSettings' => array(
						'convert' =>	isset($aStreamData['tags']) && array_key_exists(key: 'language', array: $aStreamData['tags']) && in_array(needle: $aStreamData['tags']['language'], haystack: DECISIONS['subtitles']['pick']),
						),
					'disposition' => array(
						'default' =>	$aStreamData['disposition']['default'],
						'forced' =>		$aStreamData['disposition']['forced'],
						),
					'language' => array(
						'human' =>	isset($aStreamData['tags']) && array_key_exists(key: $aStreamData['tags']['language'], array: STATIC_CONFIG['languages']) ? STATIC_CONFIG['languages'][$aStreamData['tags']['language']] : strtoupper($aStreamData['tags']['language']),
						'short' =>	$aStreamData['tags']['language'],
						),
					'streamIndex' => $aSI,
					);
				break;
		}
	}	

	$aAudioLanguages = array_values(array_unique($aAudioLanguages));
	$aSubtitleLanguages = array_values(array_unique($aSubtitleLanguages));

	$aSubtitlesMore = in_array(needle: MORE_TEXT, haystack: $aSubtitleLanguages);
	$aSubtitleLanguagesOrdered = $aSubtitleLanguages;
	$aSubtitleLanguages = array();
	foreach($aSubtitleLanguagesOrdered as $aValue)
	{
		if($aValue != MORE_TEXT)
			$aSubtitleLanguages[] = $aValue;
	}
	if($aSubtitlesMore)
		$aSubtitleLanguages[] = MORE_TEXT;
	
	$aResult = array(
		'autoNaming'		=> array(
			'subtitle'	=>	DECISIONS['subtitles']['autoNaming'],
			),
		'file' =>				$aScanFileName,
		'fileName' =>			pathinfo(path: $aScanFileName, flags: PATHINFO_BASENAME),
		'info' => array(
			'chapters' =>			isset($aFFProbeData['chapters']) && count($aFFProbeData['chapters']) > 1 ? 'Vorhanden' : '-keine Information-',
			'duration' =>			$aFileDuration,
			'formatName' =>			$aFFProbeData['format']['format_long_name'],
			'languages' => array(
				'audio' =>		$aAudioLanguages,
				'subtitle' =>	$aSubtitleLanguages,
				),
			'size' => array(
				'bytes' =>	$aFFProbeData['format']['size'],
				'human' =>	humanFilesize($aFFProbeData['format']['size']) . 'B',
				),
			'title' =>				isset($aFFProbeData['format']['tags']['title']) ? $aFFProbeData['format']['tags']['title'] : null,
			'videoStreamCount' =>	$aVideoStreamsCount,
			),
		'outfile' => array(
			'fileName' => 	pathinfo(path: $aScanFileName, flags: PATHINFO_FILENAME) . '.mkv',
			'folder' => 	rtrim(string: dirname($aScanFileName), characters: '/') . '/',
			),
		'streams' => array(
			'audio' =>				$aAudioStreams,
			'subtitle' =>			isset($aSubtitleStreams) ? $aSubtitleStreams : null,
			'video' =>				$aVideoStreams,
			),
		);

	echo json_encode(value: $aResult, flags: JSON_PRETTY_PRINT);
?>