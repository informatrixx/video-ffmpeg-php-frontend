<pre><?php
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents('config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents('config/static_config.json'), associative: true));

	$aConvertString = CONFIG['Binaries']['ffmpeg'] . PHP_EOL;
	$aConvertString .= ' -i ' . escapeshellarg($_GET['infile']) . PHP_EOL;

	$aAudioScanString = "$aConvertString -vn -sn -dn -map_chapters -1" . PHP_EOL;
	
	$aStreamIndex = 0;
	$aAudioScanIndex = 0;
	foreach($_GET['map'] as $aMapIndex => $aMapValue)
	{
		$aFilters = array();
		$aAudioScanFilters = array();
		$aDisposition = array('default' => '-', 'forced' => '-');
		$aConvertString .= "-map $aMapValue" . PHP_EOL;
		foreach($_GET as $aKey => $aData)
			if($aKey != 'map' && is_array($aData)) 
			{
				foreach($aData as $aDataMapIndex => $aDataValue)
					if($aDataMapIndex == $aMapIndex)
						switch($aKey)
						{
							case 'title':
								$aConvertString .= " -metadata:s:$aStreamIndex " . escapeshellarg("title=$aDataValue") . PHP_EOL;
								break;
							case 'loudnorm':
								if($aDataValue != 'off' && isset(STATIC_CONFIG['audio']['loudnorm'][$aDataValue]))
								{
									$aLNData = STATIC_CONFIG['audio']['loudnorm'][$aDataValue];
									$aAudioScanFilters[10] = "loudnorm=I={$aLNData['I']}:TP={$aLNData['TP']}:LRA={$aLNData['LRA']}:print_format=json";
									$aAudioScanString .= "-map $aMapValue" . PHP_EOL;
								}
								break;
							case 'ac':
								if($aDataValue != 'dpl')
									$aConvertString .= " -$aKey:$aStreamIndex $aDataValue" . PHP_EOL;
								else
								{
									$aConvertString .= " -ac:$aStreamIndex 2" . PHP_EOL;
									$aFilters[20] = "aresample=matrix_encoding=dplii";
								}
								break;
							case 'nlmeans':
								if($aDataValue != 'off')
									$aFilters[10] = 'nlmeans=' . STATIC_CONFIG['video']['nlmeans'][$aDataValue]['value'];
								break;
							case 'crop':
								if($aDataValue == 'auto')
									$aFilters[20] = $_GET['cropstring'];
								break;
							case 'resize':
								if($aDataValue != '0')
									$aFilters[30] = "scale=$aDataValue:-1";
								break;
							case 'default':
							case 'forced':
								$aDisposition[$aKey] = $aDataValue != '0' ? '+' : '-';
								break;
							default:
								$aConvertString .= " -$aKey:$aStreamIndex $aDataValue" . PHP_EOL;
								break;
						}
			}
		if(count($aFilters) > 0)
		{
			ksort($aFilters);
			$aConvertString .=	" -filter:$aStreamIndex " . escapeshellarg(implode(separator: ',', array: $aFilters)) . PHP_EOL;
		}
		
		$aConvertString .= " -disposition:$aStreamIndex ";
		foreach($aDisposition as $aKey => $aValue)
			$aConvertString .= "$aValue$aKey";
		$aConvertString .= PHP_EOL;

		if(count($aAudioScanFilters) > 0)
		{
			ksort($aAudioScanFilters);
			$aAudioScanString .=	" -filter:$aAudioScanIndex " . escapeshellarg(implode(separator: ',', array: $aAudioScanFilters)) . PHP_EOL;
			$aAudioScanIndex++;
		}
		
		$aStreamIndex++;
	}
	$aConvertString .= PHP_EOL . ' -c:v libx265' . PHP_EOL;
	$aConvertString .= ' -x265-params "level-idc=5:deblock=false:sao=false:b-intra=false"' . PHP_EOL;
	$aConvertString .= ' -c:a libfdk_aac' . PHP_EOL;
	$aConvertString .= ' -c:s copy' . PHP_EOL;
	$aConvertString .= ' -reserve_index_space 100k ' . PHP_EOL;
	$aConvertString .= ' -cues_to_front 1 ' . PHP_EOL;
	$aConvertString .= ' -metadata ' . escapeshellarg("title={$_GET['filetitle']}") . PHP_EOL;
	$aConvertString .= ' -metadata:s:v ' . escapeshellarg("title={$_GET['filetitle']}") . PHP_EOL;
	
	$aOutFolder = rtrim(string: $_GET['outfolder'], characters: '/') . '/';
	$aOutFile = $_GET['outfile'];
	$aConvertString .= " " . escapeshellarg("$aOutFolder$aOutFile") . PHP_EOL;
	echo $aConvertString . PHP_EOL . PHP_EOL;
	
	$aAudioScanString .= " -f null -" . PHP_EOL;
	echo $aAudioScanString;
	
?></pre>
