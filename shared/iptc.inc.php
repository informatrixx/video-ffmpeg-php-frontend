<?php
// derived from iptc_make_tag() function by Thies C. Arntzen

function makeIPTCTag($rec, $data, $value)
{
	$aLength = strlen($value);
	$aRetVal = chr(0x1C) . chr($rec) . chr($data);

    if($aLength < 0x8000)
    {
    	$aRetVal .= chr($aLength >> 8) . chr($aLength & 0xFF);
    }
    else
    {
        $aRetVal .= chr(0x80) . 
                   chr(0x04) . 
                   chr(($aLength >> 24) & 0xFF) . 
                   chr(($aLength >> 16) & 0xFF) . 
                   chr(($aLength >> 8) & 0xFF) . 
                   chr($aLength & 0xFF);
    }

    return $aRetVal . $value;
}

function getIPTCCaption(string $filename)
{
	$aInfo = array();
	$aImgData = array();
 
	
	$aSize = getimagesize($filename, $aInfo);
 
	$aIPTCData = iptcparse($aInfo['APP13']);
	
	return $aIPTCData["2#120"][0];
}

?>