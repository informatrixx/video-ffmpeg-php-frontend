<?php

	define(constant_name: 'THIS_MTIME', value: filemtime(__FILE__));

	function provideStaticFile(string $fileName)
	{
		$aAbsoluteFileName = SCRIPT_PATH . $fileName;
		$aHash = md5(THIS_MTIME . filemtime($aAbsoluteFileName));
		$aPathInfo = pathinfo(path: $aAbsoluteFileName);
		$aHashedFileName = "{$aPathInfo['filename']}-$aHash.{$aPathInfo['extension']}";
		
		$aStaticOutFileName = ROOT . "frontend/static/$aHashedFileName";
		$aRelativeOutFileName = FE_ROOT . "static/$aHashedFileName";
		
		if(file_exists($aStaticOutFileName))
			return $aRelativeOutFileName;
		elseif(STATIC_CONFIG['caching']['minify']['useToptalAPI'] && ($aPathInfo['extension'] == 'js' || $aPathInfo['extension'] == 'css'))
		{
			$aCMD = '/usr/bin/php -r "require(' . escapeshellarg(__FILE__) . '); standaloneMinifyToptalAPI(' . escapeshellarg($aAbsoluteFileName) . ', ' . escapeshellarg($aStaticOutFileName) . ');"';
			exec("exec nohup setsid $aCMD > /dev/null 2>&1 &");
			return "$fileName?hash=$aHash";
		}
		else
			file_put_contents(filename: $aStaticOutFileName, data: file_get_contents($aAbsoluteFileName));
		
		return $aRelativeOutFileName;
	}

	function standaloneMinifyToptalAPI(string $inFile, string $outFile)
	{
		$aExtension = pathinfo(path: $inFile, flags: PATHINFO_EXTENSION);
		$aUrl = match($aExtension)
		{
			'js' =>		'https://www.toptal.com/developers/javascript-minifier/api/raw',
			'css' =>	'https://www.toptal.com/developers/cssminifier/api/raw',
		};

		$aCUrl = curl_init();
	
		curl_setopt_array($aCUrl, [
			CURLOPT_URL => $aUrl,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded"],
			CURLOPT_POSTFIELDS => http_build_query([ "input" => file_get_contents($inFile)])
		]);
	
		$aMinifiedContent = curl_exec($aCUrl);
		
		if(curl_getinfo($aCUrl, CURLINFO_RESPONSE_CODE) == "200")
			file_put_contents(filename: $outFile, data: $aMinifiedContent);
			
		curl_close($aCUrl);
	}
	
?>