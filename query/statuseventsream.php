<?php
	header('Content-Type: text/event-stream');
	header('Cache-Control: no-store, no-cache, must-revalidate');
	header('Connection: keep-alive');
	header('X-Accel-Buffering: no');

	set_time_limit(0);
	error_reporting(E_ALL);
	ini_set('error_reporting', true);

	define(constant_name: 'SCRIPT_DIR', value: rtrim(string: __DIR__, characters: '/') . '/');
	define(constant_name: 'RUN_DIR', value: rtrim(string: realpath(SCRIPT_DIR . '../run'), characters: '/') . '/');

	define(constant_name: 'RANDOM_ID', value: hash('crc32', rand(1000000, 9999999)));
	define(constant_name: 'RESPONSE_SOCKET_FILE', value: RUN_DIR . '.' . RANDOM_ID . '.sock');

	define(constant_name: 'QUMA_ID', value: file_get_contents('../config/ID'));

	register_shutdown_function('shutDownFunction');
	
	function shutDownFunction()
	{
		global $gResponseSocket;
		@socket_shutdown($gResponseSocket, 2);
		@socket_close($gResponseSocket);
		
		unlink(RESPONSE_SOCKET_FILE);
		
		echo 	"id: $aID\n" .
				"event: shutdown\n" .
				"data: shutdown\n\n";
	}
	
	//Message ID counter
	$aID = 0;
	$aInit = true;
	
	//Prepare message data
	$aSockMessageData = array(
		'qumaID'		=> QUMA_ID,
		'randomID'		=> RANDOM_ID,	
		'action'		=> 'add_status_socket',	
		'responseSock' => RESPONSE_SOCKET_FILE
		);
	
	$aSockMessage = json_encode(value: $aSockMessageData);
	
	//Create socket
	$aQueueManagerSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
	
	//Create a temporary socket for the status messages
	$gResponseSocket = socket_create(AF_UNIX, SOCK_STREAM, 0);
	if(file_exists(RESPONSE_SOCKET_FILE))
	   unlink(RESPONSE_SOCKET_FILE);
	socket_bind($gResponseSocket, RESPONSE_SOCKET_FILE);
	chmod(filename: RESPONSE_SOCKET_FILE, permissions: 0666);


	$aID = 0;
	$aInit = true;

	$aOldData = '';
	$aData = '';

	//Display startup message
	echo "id: $aID\n" .
		"event: process\n" .
		"data: Idle (Waiting for connection)\n\n";
	ob_flush();
	flush();

	while($aInit)
	{
		//Check if QueueManager service socket is available...
		if(file_exists(RUN_DIR . 'quma.sock'))
		{
			//Send message to socket without connection
			if(socket_sendto(socket: $aQueueManagerSocket, data: $aSockMessage, length: strlen($aSockMessage), flags: null, address: "../run/quma.sock") === false)
			{
				$aID++;
				echo "id: $aID\n" .
					"event: connection\n" .
					"data: Error: " . socket_strerror(socket_last_error()) . "\n\n";
				ob_flush();
				flush();
			}
			else
			{
				//Listen on private response socket and wait for QueueManager to connect to it
				socket_listen(socket: $gResponseSocket, backlog: 1);
				//Determine if response socket got activity to read
				$aReadSockets = array($gResponseSocket);
				$aWriteSockets = null;
				$aExceptSockets = null;
				$aSockChanged = socket_select(read: $aReadSockets, write: $aWriteSockets, except: $aExceptSockets, seconds: 10);
				if($aSockChanged === false)
				{
					$aID++;
					echo "id: $aID\n" .
						"event: connection\n" .
						"data: Not connected to Queue Manager. Error: " . socket_strerror(socket_last_error()) . "\n\n";
					ob_flush();
					flush();
					exit;
				}
				elseif($aSockChanged > 0)
				{
					$aStatusSocket = socket_accept($gResponseSocket);
					if($aStatusSocket !== false)
					{
						$aID++;
						echo "id: $aID\n" .
							"event: connection\n" .
							"data: Connected to Queue Manager\n\n";
						ob_flush();
						flush();
						$aInit = false;
					}
					else
					{
						$aID++;
						echo "id: $aID\n" .
							"event: connection\n" .
							"data: Not connected to Queue Manager. Error: " . socket_strerror(socket_last_error()) . "\n\n";
						ob_flush();
						flush();
						exit;
					}
				}
			}
		}
		if($aInit)
			sleep(1);
	}
	
	while($aStatusSocket !== false)
	{
		//Determine if control socket got data pending to read
		$aReadSockets = array($aStatusSocket);
		$aWriteSockets = null;
		$aExceptSockets = null;
		$aSockChanged = socket_select(read: $aReadSockets, write: $aWriteSockets, except: $aExceptSockets, seconds: 0, microseconds: 100);
		if($aSockChanged === false)
		{
			$aID++;
			echo	"id: $aID\n" .
					"event: connection\n" .
					"data: Error receiving data from Queue Manager\n\n";
			exit;
		}
		elseif($aSockChanged > 0)
		{	
			$aID++;
			//Read data from control socket
			if(socket_recv(socket: $aStatusSocket, data: $aMessage, length: 64 * 1024, flags: MSG_DONTWAIT) === false)
				echo	"id: $aID\n" .
						"event: connection\n" .
						"data: Error receiving data from Queue Manager\n\n";
			else
			{
				$aMessageArray = explode(separator: '\0', string: $aMessage);
				foreach($aMessageArray as $aMessageString)
				{
					$aDataMessage = json_decode(json: $aMessageString, associative: true); 
	
					if(is_array($aDataMessage))
					{
						$aTopic = $aDataMessage['topic'];
						$aData = json_encode(value: $aDataMessage['data']);
						
						if($aData != $aOldData)
						{
							echo "id: $aID\n";
							echo "event: $aTopic\n";
							echo "data: $aData\n\n";
							$aOldData = $aData;
						}
					}
					elseif($aDataMessage != '')
						echo "id: $aID\n" .
							"event: unknown\n" .
							"data: $aMessage\n\n";
				}
			}
		}
		ob_flush();
		flush();
	}
	
?>