<?php

	const QUMA_STATUS_WAITING			= 0;
	const QUMA_STATUS_SCAN_READY		= 1;
	const QUMA_STATUS_SCAN				= 2;
	const QUMA_STATUS_SCAN_CHILD_READY	= 3;
	const QUMA_STATUS_SCAN_CHILD		= 4;
	const QUMA_STATUS_SCAN_WAITING		= 6;
	const QUMA_STATUS_CONVERT_READY		= 7;
	const QUMA_STATUS_CONVERT			= 8;
	const QUMA_STATUS_CONVERT_DONE		= 9;
	const QUMA_STATUS_UNRAR_WAITING		= 10;
	const QUMA_STATUS_UNRAR_READY		= 11;
	const QUMA_STATUS_UNRAR				= 12;
	const QUMA_STATUS_UNRAR_DONE		= 15;
	const QUMA_STATUS_UNRAR_ERROR		= 19;
	const QUMA_STATUS_SCAN_ABORT		= 82;
	const QUMA_STATUS_CONVERT_ABORT		= 86;
	const QUMA_STATUS_UNRAR_ABORT		= 812;
	const QUMA_STATUS_SCAN_PAUSE		= 91;
	const QUMA_STATUS_SCAN_ERROR		= 92;
	const QUMA_STATUS_CONVERT_PAUSE		= 97;
	const QUMA_STATUS_CONVERT_ERROR		= 98;
	
	const QUMA_TEXT_STATUS = array(
		QUMA_STATUS_WAITING				=> 'Waiting',
		QUMA_STATUS_SCAN_READY			=> 'Ready to scan',
		QUMA_STATUS_SCAN				=> 'Scanning',
		QUMA_STATUS_SCAN_CHILD_READY	=> 'Ready to scan',
		QUMA_STATUS_SCAN_CHILD			=> 'Scanning',
		QUMA_STATUS_SCAN_WAITING		=> 'Waiting for scan results',
		QUMA_STATUS_CONVERT_READY		=> 'Ready to convert / scan done',
		QUMA_STATUS_CONVERT				=> 'Converting',
		QUMA_STATUS_CONVERT_DONE		=> 'Done',
		QUMA_STATUS_UNRAR_WAITING		=> 'Waiting',
		QUMA_STATUS_UNRAR_READY			=> 'Ready to extract (unrar)',
		QUMA_STATUS_UNRAR				=> 'Extracting (unrar)',
		QUMA_STATUS_UNRAR_DONE			=> 'Done  extracting (unrar)',
		QUMA_STATUS_UNRAR_ERROR			=> 'Error extracting (unrar)',
		QUMA_STATUS_SCAN_ABORT			=> 'Abort scanning',
		QUMA_STATUS_CONVERT_ABORT		=> 'Abort converting',
		QUMA_STATUS_UNRAR_ABORT			=> 'Abort extracting (unrar)',
		QUMA_STATUS_SCAN_PAUSE			=> 'Pause scanning',
		QUMA_STATUS_SCAN_ERROR			=> 'Error scanning',
		QUMA_STATUS_CONVERT_PAUSE		=> 'Pause converting',
		QUMA_STATUS_CONVERT_ERROR		=> 'Error converting',
		);
	
	include_once('common.inc.php');
	
	$aTargetJSFile = ROOT . 'frontend/js/quma-status-codes.js';
	if(!file_exists($aTargetJSFile) || filemtime($aTargetJSFile) != filemtime(__FILE__))
	{
		$aSelfContent = file_get_contents(__FILE__);
		$aJSContent = '';
		if(preg_match_all(pattern: '/const (?<constName>[A-Z_]+)\s*=\s*(?<constValue>\d+);/m', subject: $aSelfContent, matches: $aMatches, flags: PREG_SET_ORDER))
		{
			$aConstNames = array();
			foreach($aMatches as $aMatchData)
			{
				$aJSContent .= "const {$aMatchData['constName']} = {$aMatchData['constValue']};" . PHP_EOL;
				$aConstNames[] = "\t{$aMatchData['constValue']}: '{$aMatchData['constName']}'";
			}
			$aJSContent .= 'const QUMA_CODE_STATUS = {' . PHP_EOL;
			$aJSContent .= implode(separator: ',' . PHP_EOL, array: $aConstNames);
			$aJSContent .= PHP_EOL . '};';
		}
		
		$aJSContent .= 'const QUMA_TEXT_STATUS = {' . PHP_EOL;
		$i = 0;
		foreach(QUMA_TEXT_STATUS as $aKey => $aValue)
		{
			if($i++ > 0)
				$aJSContent .= ', ' . PHP_EOL;
			$aJSContent .= "\t'$aKey': '$aValue'";
		}
		$aJSContent .= PHP_EOL . '};';
		
		file_put_contents(filename: $aTargetJSFile, data: $aJSContent);
	}
	
?>