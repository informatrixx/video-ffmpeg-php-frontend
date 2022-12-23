<?php

	header('Content-Type: application/json; charset=utf-8');

	const BIN_ROOT = '/DataVolume/scripts/software/bin';
	
	$aInputFile = escapeshellcmd($_GET['file']);
	$aSeek = escapeshellcmd($_GET['seek']);
	$aSAR = escapeshellcmd($_GET['sar']);
	
	$aOutFileBMP = rtrim(__DIR__, '/') . '/cache/scan-' . basename($_GET['file']) . '-' . $aSeek . '.bmp';
	
	$aOutFile = escapeshellcmd($aOutFileBMP);
	$aFFExtractCmd = rtrim(BIN_ROOT, '/') . "/ffmpeg -ss $aSeek -i '$aInputFile' -frames:v 1 -vf  scale='trunc(ih*dar):ih',setsar=1 -an -sn '$aOutFile'";
	shell_exec($aFFExtractCmd);

	$aFFCropDetectCmd = rtrim(BIN_ROOT, '/') . "/ffmpeg -ss $aSeek -skip_frame nokey -i '$aInputFile' -frames:v 10 -vf cropdetect=round=2 -an -sn -f null - 2>&1 ";
	$aCropDetectString = shell_exec($aFFCropDetectCmd);

	$aScanImg = new Imagick();
	$aScanImg->readImage($aOutFileBMP);
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
			
	$aOutFileJPG = str_replace('.bmp', '.jpg', $aOutFileBMP);
	$aScanImg->writeImage($aOutFileJPG);
			
	unlink($aOutFileBMP);
	
	$aReturn = array(
		'file'	=> str_replace(rtrim(__DIR__, '/') . '/', '', $aOutFileJPG),
		'index'	=> $_GET['index'],
		'crop'	=> $aCropData,
		);
	
	echo json_encode($aReturn, JSON_PRETTY_PRINT);
?>