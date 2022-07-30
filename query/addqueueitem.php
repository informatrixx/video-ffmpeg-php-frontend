<?php
	header('Content-Type: text/json');
	header('Cache-Control: no-store, no-cache, must-revalidate');
	header('X-Accel-Buffering: no');
	
	define(constant_name: 'SCRIPT_DIR', value: rtrim(string: __DIR__, characters: '/') . '/');
	define(constant_name: 'RUN_DIR', value: rtrim(string: realpath(SCRIPT_DIR . '../run'), characters: '/') . '/');

	define(constant_name: 'RESPONSE_SOCKET_FILE', value: tempnam(directory: RUN_DIR, prefix: '.sock.'));

	define(constant_name: 'QUMA_ID', value: file_get_contents('../config/ID'));
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents('../config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents('../config/static_config.json'), associative: true));

	$aResult = array(
		'success' =>	false,
		'error' => 		'',
		);

	
	/* To-Do: Data Integrity checking!!! */

	
	//Prepare message data
	$aSockMessageData = array(
		'qumaID' =>			QUMA_ID,
		'action' =>			'add_queue_item',	
		'response_sock' =>	RESPONSE_SOCKET_FILE,
		'queue_item' => 	array(
			'status' =>		0,
			'id' => md5(json_encode(value: $_GET)),
			'settings' =>	$_GET,
			)
		);
	
	$aSockMessage = json_encode(value: $aSockMessageData);
	
	//Create socket
	$aQueueManagerSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
	
	//Send message to socket without connection
	if(socket_sendto(socket: $aQueueManagerSocket, data: $aSockMessage, length: strlen($aSockMessage), flags: null, address: "../run/quma.sock") === false)
		$aResult['error'] = socket_strerror(socket_last_error());
	
	//Create a temporary socket for the response
	$aResponseSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
	if(file_exists(RESPONSE_SOCKET_FILE))
	   unlink(RESPONSE_SOCKET_FILE);
	socket_bind($aResponseSocket, RESPONSE_SOCKET_FILE);
	chmod(filename: RESPONSE_SOCKET_FILE, permissions: 0666);
	
	//Wait for response
	if(socket_recvfrom(socket: $aResponseSocket, data: $aMessage, length: 64 * 1024, flags: MSG_WAITALL, address: $aSockAddr) === false)
		$aResult['error'] = 'Socket read failed: ' . socket_strerror(socket_last_error());
		
	$aResult = json_decode(json: $aMessage, associative: true);
	
	//Remove temporary socket file
	unlink(RESPONSE_SOCKET_FILE);
	
	//Output result
	echo json_encode(value: $aResult);
?>
