<?php

	header('Content-Type: application/json; charset=utf-8');
	
	require('../../shared/common.inc.php');
	require(ROOT . 'shared/iptc.inc.php');
	
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));
	
	$aInputFile = $_GET['file'];
	$aSeek = escapeshellcmd($_GET['seek']);
	$aSAR = escapeshellcmd($_GET['sar']);
	
	if(empty($_GET['sar']))
	{
		$aSARString = shell_exec(CONFIG['Binaries']['ffprobe'] . '-of csv="p=0" -v quiet -select_streams v -show_entries stream=sample_aspect_ratio ' . escapeshellarg($aInputFile));
		$aSARDiv = explode(separator: ':', string: $aSARString);
		
		if(count($aSARDiv) == 2)
			$aSAR = $aInputSARDiv[0] / $aInputSARDiv[1];
		else
			$aSAR = 1;
	}
	
	$aHours = str_pad(floor($aSeek / 3600), 2, "0", STR_PAD_LEFT);
	$aSeekLeft = round($aSeek, 0) % 3600;
	$aMinutes = str_pad(floor($aSeekLeft / 60), 2, "0", STR_PAD_LEFT);
	$aSeconds = str_pad(floor($aSeekLeft % 60), 2, "0", STR_PAD_LEFT);
	
	$aOutFileJPG = rtrim(__DIR__, '/') . '/cache/scan-' . basename($_GET['file']) . '-' . $aSeek . '.jpg';
	$aTmpFileBMP = rtrim(__DIR__, '/') . '/cache/scan-' . basename($_GET['file']) . '-' . $aSeek . '.bmp';

	
	
	//See if image already exists
	if(file_exists($aOutFileJPG) && filemtime($aOutFileJPG) == filemtime($aInputFile))
	{
		$aReturn = array(
			'file' =>	str_replace(rtrim(__DIR__, '/') . '/', '', $aOutFileJPG),
			'fileIndex'	=> 	$_GET['fileIndex'],
			'index' =>	$_GET['index'],
			'crop' =>	json_decode(getIPTCCaption(filename: $aOutFileJPG)),
			'sar'	=> $aSAR,
			'seekHuman' =>	"$aHours:$aMinutes:$aSeconds",
			'cached' =>	true,
			);
	}
	else
	{
		$aOutFile = escapeshellcmd($aTmpFileBMP);
		$aFFExtractCmd = CONFIG['Binaries']['ffmpeg'] . " -ss $aSeek -i " . escapeshellarg($aInputFile) . " -frames:v 1 -vf  scale='trunc(ih*dar):ih',setsar=1 -an -sn '$aOutFile' 2> /srv/www/movie-ffmpeg-php-frontend/log/extract_bmp.log";
		shell_exec($aFFExtractCmd);
		
		$aFFCropDetectCmd = CONFIG['Binaries']['ffmpeg'] . " -ss $aSeek -skip_frame nokey -i " . escapeshellarg($aInputFile) . " -frames:v " . STATIC_CONFIG['cropPreview']['detectNumberFrames'] . ' -vf cropdetect=round=2 -an -sn -f null - 2>&1 ';
		$aCropDetectString = shell_exec($aFFCropDetectCmd);
	
		$aScanImg = new Imagick();
		$aScanImg->readImage($aTmpFileBMP);
		$aCropData = array();
				
		if(preg_match('/.*(crop=(\d+):(\d+):(\d+):(\d+))/s', $aCropDetectString, $aCropMatches))
		{
			$aWidth = $aCropMatches[2];
			$aHeight = $aCropMatches[3];
			$aX = $aCropMatches[4];
			$aY = $aCropMatches[5];
			
			$aDrawWhite = new ImagickDraw();
			$aStrokeColor = new ImagickPixel('blue');
			$aDrawWhite->setStrokeColor($aStrokeColor);
			$aDrawWhite->setStrokeWidth(2);
			$aDrawWhite->setFillOpacity(0);
			
			$aDrawWhite->rectangle($aX, $aY, ($aX + $aWidth - 1) * $aSAR, $aY + $aHeight);
	
			$aDrawRed = new ImagickDraw();
			$aStrokeColor = new ImagickPixel('red');
			$aDrawRed->setStrokeColor($aStrokeColor);
			$aDrawRed->setStrokeWidth(2);
			$aDrawRed->setFillOpacity(0);
			$aDrawRed->setStrokeDashArray([20, 20]);
			$aDrawRed->setFont('Courier');
			$aDrawRed->setFontSize($aScanImg->getImageHeight() / 20);
			
			$aDrawRed->rectangle($aX, $aY, ($aX + $aWidth - 1) * $aSAR, $aY + $aHeight);
	
			$aScanImg->drawImage($aDrawWhite);
			$aScanImg->drawImage($aDrawRed);
			
			$aDrawRed->setTextUnderColor('black');
			$aScanImg->annotateImage($aDrawRed, 50, $aScanImg->getImageHeight() / 15, 0, $aCropMatches[1]);
			
			
			$aCropData = array(
				'string'		=> $aCropMatches[1],
				'X'				=> $aX,
				'Y'				=> $aY,
				'width'			=> $aWidth,
				'height'		=> $aHeight,
				'width_sar'		=> $aWidth * $aSAR,
				);
		}
		$aScanImg->thumbnailImage(800, 600, true);
		
		$aScanImg->writeImage($aOutFileJPG);
		
		$aIPTCData = makeIPTCTag(2, '120', json_encode(value: $aCropData));
		
		$aIPTCImageString = iptcembed($aIPTCData, $aOutFileJPG);
	
		unlink($aOutFileJPG);
		
		$aFHandle = fopen($aOutFileJPG, "wb");
		fwrite($aFHandle, $aIPTCImageString);
		fclose($aFHandle);
		
		touch(filename: $aOutFileJPG, mtime: filemtime($aInputFile));
				
				
		unlink($aTmpFileBMP);
		
		$aReturn = array(
			'file'	=> 		str_replace(rtrim(__DIR__, '/') . '/', '', $aOutFileJPG),
			'index'	=> 		$_GET['index'],
			'fileIndex'	=> 	$_GET['fileIndex'],
			'sar'	=>		$aSAR,
			'seekHuman' =>	"$aHours:$aMinutes:$aSeconds",
			'crop'	=>		$aCropData,
			);
	}
	echo json_encode($aReturn, JSON_PRETTY_PRINT);
?>