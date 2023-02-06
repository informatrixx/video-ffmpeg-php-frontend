<?php

	$aRARListCmd = CONFIG['Binaries']['unrar'] . ' l -v -- ' . escapeshellarg($aScanFileName);
	$aRARListData = shell_exec($aRARListCmd);
	
	if(empty($aRARListData))
	{
		echo json_encode(value: array(
			'success' =>	false,
			), flags: JSON_PRETTY_PRINT);
		exit;	
	}
	
	$aRARFiles = array();
	if(preg_match_all(pattern: '/^\s*[.\-A-Za-z]+\s+(\d+)\s+([\d\-]+\s+[\d:]+)\s+(.+)$/m', subject: $aRARListData, matches: $aRARFileMatches, flags: PREG_SET_ORDER))
	{
		foreach($aRARFileMatches as $aRARFileData)
		{
			$aRARFiles[$aRARFileData[3]] = array(
				'fileName' =>	pathinfo(path: $aRARFileData[3], flags: PATHINFO_BASENAME),
				'size' =>		array(
					'bytes' =>	$aRARFileData[1],
					'human' =>	humanFilesize($aRARFileData[1]) . 'B',
				),
				'date' =>		$aRARFileData[2],
				);
		}
	}

	$aRARFilesArray = array();
	foreach($aRARFiles as $aFileItem)
		$aRARFilesArray[] = $aFileItem;
	
	
	
	
	$aResult = array(
		'archiveFiles' =>		$aRARFilesArray,
		'file' =>				$aScanFileName,
		'fileName' =>			pathinfo(path: $aScanFileName, flags: PATHINFO_BASENAME),
		'info' => array(
			'formatName' =>			'RAR Archive',
			),
		'outfile' => array(
			'folder' => 	rtrim(string: dirname($aScanFileName), characters: '/') . '/',
			),
		);

	echo json_encode(value: $aResult, flags: JSON_PRETTY_PRINT);
?>