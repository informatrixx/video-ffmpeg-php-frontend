<?php

	header('Content-Type: application/json; charset=utf-8');
	
	require('../../shared/common.inc.php');
	
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));
	
	
	$aSuccess = true;
	$aError = '';
	$aUpdateHistory = isset($_GET['history']) && $_GET['history'] != 0;
	$aModuleJoin = isset($_GET['join']) ? $_GET['join'] : false;
	$aFoldersList = array();
	$aFilesList = array();

	
	
	#Set scan folder
	if(isset($_GET['folder']) && !empty($_GET['folder']))
		$aFolder = $_GET['folder'];
	elseif(count(CONFIG['ConvertRoots']) == 1)
		$aFolder = array_values(CONFIG['ConvertRoots'])[0];
	else
		$aFolder = CONFIG['ConvertRoots'];
		
	if(is_string($aFolder))
	{
		//Test if path is valid. If path is not in configured root -> error
		if(!pathIsInConversionRoot($aFolder))
		{
			$aSuccess = false;
			$aError = "Wrong folder path! ($aFolder)";
		}
		else
		{
			$aFolder = globEscapeString($aFolder);
			$aParentFolder = preg_replace(pattern: '@[^/]+/$@', replacement: '', subject: $aFolder);
			if(pathIsInConversionRoot($aParentFolder))
				$aFoldersList['..'] = $aParentFolder;
			elseif(!empty($_GET['folder']))
				$aFoldersList['.'] = '';
			
			$aGlob = glob(pattern: "$aFolder*");
			
			foreach($aGlob as $aGlobPath)
			{
				$aItemName = rtrim(string: str_replace(search: $aFolder, replace: '', subject: $aGlobPath), characters: '/');
				if(is_dir($aGlobPath))	//subfolder
					$aFoldersList[$aItemName] = rtrim(string: $aGlobPath, characters: '/') . '/';
				elseif(file_exists($aGlobPath))	//if it's a file
				{
					$aScanModules = false;
					$aJoin = false;
					
					$aFilePathInfo = pathinfo($aGlobPath);
					
					if(isset($aFilePathInfo['extension']))	//if it has a extension, check if a scan module is configured to handle it
						$aScanModules = compareExtensionToScanModules(fileExtension: $aFilePathInfo['extension'], select: 'match');
						
					if(isset($aFilePathInfo['extension']) && !empty($aModuleJoin))	//if a file has an extension, which would make it joinable to the last scanned file, mark it as 'join' 
						$aJoin = compareExtensionToScanModules(fileExtension: $aFilePathInfo['extension'], select: 'join') != false;
					
					$aFilesList[$aItemName] = array(
						'path' =>	$aGlobPath,
						'scan' =>	$aScanModules,
						'join' =>	$aJoin,
						);
				}
			}		
			foreach($aFilesList as $aItemName => $aFileItem)	//check for grouping files. For example .rar files with parts (.r00, .r01, ...)
			{
				//iterating through all listed files, that are identified to be scannable by a scan module
				if($aFileItem['scan'] !== false && isset(STATIC_CONFIG['scanModules'][$aFileItem['scan']]['fileExtensions']['group']))	//if the scan module has group definitions configured
					foreach($aGlob as $aGlobPath)	//iterate through all files from the glob dataset
						if(file_exists($aGlobPath))	
						{
							$aFilePathInfo = pathinfo($aGlobPath);
							if($aFilePathInfo['filename'] == pathinfo(path: $aItemName, flags: PATHINFO_FILENAME) && compareExtensionToScanModules(fileExtension: $aFilePathInfo['extension'], select: 'group') == $aFileItem['scan'])
							//if the file found in the glob dataset has the same 'filename' and the extension can be found in the group definition -> group it 
							{
								$aFileName = str_replace(search: $aFolder, replace: '', subject: $aGlobPath);
								$aFilesList[$aItemName]['group'][] = $aFileName;
								unset($aFilesList[$aFileName]);
							}
						}
			}
		}
	}
	else
	{
		foreach(CONFIG['ConvertRoots'] as $aRootID => $aRootPath)
			$aFoldersList[$aRootID] = rtrim(string: $aRootPath, characters: '/') . '/';
		$aFolder = '';
	}
	
	$aResult = array(
		'success' =>	$aSuccess,
		'error' => 		$aError,
		'folder' =>		$aFolder,
		'folders' =>	$aFoldersList,
		'files' =>		$aFilesList,
		'history' =>	$aUpdateHistory,
		'join' =>		$aModuleJoin,
		);
	
	echo json_encode($aResult, JSON_PRETTY_PRINT);
?>