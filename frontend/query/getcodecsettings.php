<?php
	header('Content-Type: text/json');
	header('Cache-Control: no-cache');
	
	define(constant_name: 'SCRIPT_DIR', value: rtrim(string: __DIR__, characters: '/') . '/');
	define(constant_name: 'ROOT', value: rtrim(string: realpath(SCRIPT_DIR . '../..'), characters: '/') . '/');					//absolute root for scripts
	
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));
	define(constant_name: 'THIS_MTIME', value: filemtime(__FILE__));

	$aMTime = THIS_MTIME > filemtime(ROOT . 'config/static_config.json') ? THIS_MTIME : filemtime(ROOT . 'config/static_config.json');
    $aHash = md5_file(ROOT . 'config/static_config.json'); 

    header("Last-Modified: ".gmdate("D, d M Y H:i:s", $aMTime)." GMT"); 
    header("Etag: $aHash"); 

    if (@strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == $aMTime || 
        trim($_SERVER['HTTP_IF_NONE_MATCH']) == $aHash)
    { 
        header("HTTP/1.1 304 Not Modified"); 
        exit; 
    }

	$aResult = array(
		'audio' => STATIC_CONFIG['audio']['codecs'],
		'video'	=> STATIC_CONFIG['video']['codecs'],
		);

	//Output result
	echo json_encode(value: $aResult, flags: JSON_PRETTY_PRINT);
?>
