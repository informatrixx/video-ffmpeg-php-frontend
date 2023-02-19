<?php
	header('Content-Type: text/json');
	header('Cache-Control: no-store, no-cache, must-revalidate');
	header('X-Accel-Buffering: no');
	
	define(constant_name: 'SCRIPT_DIR', value: rtrim(string: __DIR__, characters: '/') . '/');
	define(constant_name: 'ROOT', value: rtrim(string: realpath(SCRIPT_DIR . '../..'), characters: '/') . '/');					//absolute root for scripts
	define(constant_name: 'RUN_DIR', value: ROOT . 'run/');

	define(constant_name: 'RESPONSE_SOCKET_FILE', value: tempnam(directory: RUN_DIR, prefix: '.sock.'));

	define(constant_name: 'QUMA_ID', value: file_get_contents(ROOT . 'config/ID'));
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));

	$aResult = array(
		'success' =>	false,
		'error' => 		'',
		'action' =>		$_GET['action'],
		'id' =>			$_GET['id'],
		);

	
	// Data Integrity checking 
	if(!preg_match('/[0-9a-f]{32}/i', $_GET['id']))
	{
		$aResult['error'] = "Invalid ID: {$_GET['id']}";
		echo json_encode(value: $aResult);
		exit;
	}
	switch($_GET['action'])
	{
		case 'delete':
		case 'pause':
		case 'resume':
		case 'retry':
			$aAction = 'queueItem:' . $_GET['action'];
			break;
		default:
			$aResult['error'] = "Invalid action: {$_GET['action']}";
			echo json_encode(value: $aResult);
			exit;			
	}
	
	//Prepare message data
	$aSockMessageData = array(
		'qumaID' =>			QUMA_ID,
		'action' =>			$aAction,	
		'responseSock' =>	RESPONSE_SOCKET_FILE,
		'queueItemID' => 	$_GET['id'],
		);
	
	//Prepare temporary response socket
	if(file_exists(RESPONSE_SOCKET_FILE))	//Delete old file, if exists...
	   unlink(RESPONSE_SOCKET_FILE);
	$aResponseSocket = socket_create(domain: AF_UNIX, type: SOCK_DGRAM, protocol: 0);
	if(!is_resource($aResponseSocket))
		$aResult['error'] = socket_strerror(socket_last_error());
	if(socket_set_option(socket: $aResponseSocket, level: SOL_SOCKET, option: SO_RCVTIMEO, value: array("sec"=>5,"usec"=>0)) === false)
		$aResult['error'] = 'Socket set option (timeout) failed: ' . socket_strerror(socket_last_error());
	if(socket_bind($aResponseSocket, RESPONSE_SOCKET_FILE) === false)
		$aResult['error'] = 'Socket bind failed: ' . socket_strerror(socket_last_error());
	chmod(filename: RESPONSE_SOCKET_FILE, permissions: 0666);
	
	
	$aSockMessage = json_encode(value: $aSockMessageData);
	
	//Create socket
	$aQueueManagerSocket = socket_create(domain: AF_UNIX, type: SOCK_DGRAM, protocol: 0);
	
	//Send message to socket without connection
	if(socket_sendto(socket: $aQueueManagerSocket, data: $aSockMessage, length: strlen($aSockMessage), flags: null, address: RUN_DIR . "quma.sock") === false)
		$aResult['error'] = 'Unable to send to QUMA process socket: ' . socket_strerror(socket_last_error());
	else
	{
		//Wait for response
		$aSockAddr = RESPONSE_SOCKET_FILE;
		if(socket_recvfrom(socket: $aResponseSocket, data: $aMessage, length: 64 * 1024, flags: MSG_WAITALL, address: $aSockAddr) === false)
			$aResult['error'] = 'Socket read failed: ' . socket_strerror(socket_last_error());
	
		$aResult = json_decode(json: $aMessage, associative: true);
	}
	
	//Remove temporary socket file
	unlink(RESPONSE_SOCKET_FILE);
	
	//Output result
	echo json_encode(value: $aResult);
?>
