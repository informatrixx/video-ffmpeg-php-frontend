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
	
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));
	define(constant_name: 'DECISIONS', value: json_decode(json: file_get_contents(ROOT . 'config/decision_template.json'), associative: true));
	
?>
<selectButtons>
	<selectButton index='##VAR:index+##' streamindex='##DATA:streamIndex##' onclick='showTab(this)' active>
		<input type='checkbox' name='map[##VAR:index##]' value='##VAR:fileIndex##:##DATA:streamIndex##' ##CHECK:conversionSettings:convert##> ##DATA:language:short## ##DATA:title##
	</selectButton>
</selectButtons>
<selectContent index='##VAR:index##' fileindex='##VAR:fileIndex##' streamindex='##DATA:streamIndex##' subtitle>
	<label>Sprache:</label><text>##DATA:language:human##</text>
	<label>Codec:</label><text>##DATA:codec:nameFull##</text>
	<label>Größe:</label><text>##DATA:size:human##</text>
	<label>Titel:</label><input name='title[##VAR:index##]' value='##DATA:title##'><button type="button" onclick='autoTitle(this, "subtitle")'><img src='img/note1-16.png' alt='Auto-Name'/></button>
	<label>Default:</label><input type='checkbox' name='default[##VAR:index##]' value='1' ##CHECK:disposition:default##>
	<label>Forced:</label><input type='checkbox' name='forced[##VAR:index##]' value='1' ##CHECK:disposition:forced##>
</selectContent>