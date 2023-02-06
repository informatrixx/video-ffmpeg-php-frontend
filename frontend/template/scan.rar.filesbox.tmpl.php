<?php
	header('Content-Type: text/plain; charset=utf-8');
	
	$aETag = '"' . filemtime(__FILE__) . '"';

	header('Cache-Control: max-age=86400');
	header('ETag: ' . $aETag);

	if(isset($_SERVER['HTTP_IF_NONE_MATCH']))
	{
		if($_SERVER['HTTP_IF_NONE_MATCH'] == $aETag)
		{
			header('HTTP/1.1 304 Not Modified', true, 304);
			exit();
		}
	}

	require('../../shared/common.inc.php');
	
?>
<selectButtons>
	<selectButton active>##DATA:fileName##</selectButton>
</selectButtons>
<selectContent class='archiveFiles'>
	##ARCHIVEFILES##
</selectContent>