<?php

	if (php_sapi_name() != "cli")
	{
		header("HTTP/1.1 418 I'm a teapot");
		die('This script is a standalone CLI script...');
	}


	define(constant_name: 'SCRIPT_DIR', value: rtrim(string: __DIR__, characters: '/') . '/');
	define(constant_name: 'PID_FILE', value: SCRIPT_DIR . pathinfo(path: __FILE__, flags: PATHINFO_FILENAME) . '.pid');
	define(constant_name: 'QUEUE_FILE', value: SCRIPT_DIR . 'queue.json');
	define(constant_name: 'CONTROL_SOCKET_FILE', value: SCRIPT_DIR . 'quma.sock');
	
	
	//Check if PID file is orphaned or an old Process is still running
	if(file_exists(PID_FILE))
	{
		$aOldPID = file_get_contents(PID_FILE);
		if(posix_kill(process_id: $aOldPID, signal: 0))
			die('...already running' . PHP_EOL);
	}

	function shutDownFunction()
	{
		global $gShMStatus, $gControlSocket, $gQueueProcesses, $gConvertQueue;
		
		_msg(message: 'Shutdown...');
		
		_msg(message: 'Terminating child processes...');
		foreach($gQueueProcesses as $aItemID => $aProcRessource)
		{
			_msg(message: 'Terminating child processes...');
			foreach($gConvertQueue as $gQueueItem)
				if($gQueueItem['id'] == $aItemID)
				{
					$gQueueItem['settings']['status'] = 99;
					_msg(message: 'Child PID: ' . proc_get_status(process: $aProcRessource)['pid'], CRF: '');
					proc_terminate(process: $aProcRessource);
					sleep(1);
					while(proc_get_status(process: $aProcRessource)['running'])
					{
						_msg(message: '.', CRF: '', fixedWidth: 1);
						sleep(1);
					}
					_msg(message: ' OK', fixedWidth: 3);
					break;
				}
		}
		
		_msg(message: 'Removing PID File...', CRF: '');
		_msg(message: unlink(PID_FILE) ? 'OK' : 'Error!', fixedWidth: 6);
			
		_msg(message: 'Deleting shared memory...', CRF: '');
		_msg(message: shmop_delete($gShMStatus) ? 'OK' : 'Error!', fixedWidth: 6);
		
		_msg(message: 'Deleting control socket...', CRF: '');
		socket_close($gControlSocket);
		_msg(message: unlink(CONTROL_SOCKET_FILE) ? 'OK' : 'Error!', fixedWidth: 6);
	}
	
	function signalHandlerFunction($aSignal)
	{
		switch($aSignal)
		{
			case SIGTERM:
			case SIGINT:
				_msg(message: 'Exiting...');
				exit;
			break;
			case SIGHUP:
				// Restart
				// To-Do
			break;
			case SIGUSR1:
				_msg(message: 'SIGUSR1 ... ', CRF: '');
				_msg(message: 'nice!', fixedWidth: 6);
			break;
		}
	}
	
	register_shutdown_function('shutDownFunction');
	
	//Signal handling...
	declare(ticks = 1);
	pcntl_signal(SIGINT, 'signalHandlerFunction');
	pcntl_signal(SIGTERM, 'signalHandlerFunction');
	pcntl_signal(SIGHUP, 'signalHandlerFunction');
	pcntl_signal(SIGUSR1, 'signalHandlerFunction');
	
	
	//PID handling...
	file_put_contents(filename: PID_FILE, data: getmypid());
	
	
	
	
	
	//Script functions
	function _msg(string $message, bool $toSTDOUT = true, string $CRF = PHP_EOL, int $fixedWidth = 80, bool $toSTDERR = false)
	{
		$aOutMessage = sprintf("%-{$fixedWidth}s$CRF", $message);
		if($toSTDOUT)
			echo $aOutMessage;
		if($toSTDERR)
			fwrite(STDERR, $aOutMessage);
	}
	
	function readConvertQueue()
	{
		global $gConvertQueue;
		
		_msg(message: 'Reading convert queue file... ', CRF: '');
		if(!file_exists(QUEUE_FILE))
			$gConvertQueue = array();
		else
			$gConvertQueue = json_decode(json: file_get_contents(QUEUE_FILE), associative: true);
		if(!is_array($gConvertQueue))
		{
			_msg(message: 'Error reading queue file!', fixedWidth: 25);
			return false;	
		}
		
		_msg(message: 'Found ' . count($gConvertQueue) . ' item(s)', fixedWidth: 25);
		return true;
	}
	
	function addQueueItem(array $newItem)
	{
		global $gConvertQueue;
		$aDuplicate = false;

		$aResult = array(
			'success' =>	false,
			'error' =>		'',
			);
		
        //Check for duplicate item
        foreach($gConvertQueue as $aQueueItem)
        {
        	if($newItem['settings']['outfolder'] == $aQueueItem['settings']['outfolder'] && $newItem['settings']['outfile'] == $aQueueItem['settings']['outfile'])
			{
				$aResult['error'] = 'Duplicate';
				return $aResult;
			}
        }
		
        $gConvertQueue[] = $newItem;
        $aResult['success'] = true;
        _msg(message: 'Adding queue item: ' . $newItem['settings']['outfile']);
        
        if(file_put_contents(filename: QUEUE_FILE, data: json_encode(value: $gConvertQueue, flags: JSON_PRETTY_PRINT)) === false)
           _msg(message: 'Error writing queue file to disk: ' . QUEUE_FILE, toSTDERROR: true);
        
        return $aResult;
	}
	
	function changeQueueItemStatus(int $queueItemIndex, int $newStatus)
	{
		global $gConvertQueue;
		$gConvertQueue[$queueItemIndex]['status'] = $newStatus;
		switch($newStatus)
		{
			/*	0	waiting
				1	ready to scan
				2	scanning
				3	ready to convert / scan done
				4	converting
				5	done
				
				9x	Error ...
				99	Abort
				*/
			case 1:
				_msg(message: 'Change queue item status to "Ready to scan (1)": ' . $gConvertQueue[$queueItemIndex]['settings']['outfile']);
				break;
			case 2:
				_msg(message: 'Change queue item status to "Scanning (2)": ' . $gConvertQueue[$queueItemIndex]['settings']['outfile']);
				break;
			case 3:
				_msg(message: 'Change queue item status to "Ready to convert (3)": ' . $gConvertQueue[$queueItemIndex]['settings']['outfile']);
				break;
			case 4:
				_msg(message: 'Change queue item status to "Converting (4)": ' . $gConvertQueue[$queueItemIndex]['settings']['outfile']);
				break;
			case 5:
				_msg(message: 'Change queue item status to "Done (5)": ' . $gConvertQueue[$queueItemIndex]['settings']['outfile']);
				break;
			case 92:
				_msg(message: 'Change queue item status to "Error scanning (92)": ' . $gConvertQueue[$queueItemIndex]['settings']['outfile']);
				break;
			case 99:
				_msg(message: 'Change queue item status to "Abort (99)": ' . $gConvertQueue[$queueItemIndex]['settings']['outfile']);
				break;
		}
	}
	
	
	
	
	/*	SCRIPT BODY	*/
	
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents('../config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents('../config/static_config.json'), associative: true));

	define(constant_name: 'MY_ID', value: file_get_contents('../config/ID'));

	
	//Init shared memory for status reports
	$aShMStatusKey = ftok(filename: realpath(__FILE__), project_id: 's');
	$gShMStatus = shmop_open(key: $aShMStatusKey, mode: "c", permissions: 0644, size: 1024);
	
	
	//Init socket for queue updates
	if(file_exists(CONTROL_SOCKET_FILE))
	   unlink(CONTROL_SOCKET_FILE);
	$gControlSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
	if(socket_bind($gControlSocket, CONTROL_SOCKET_FILE) === false)
		_msg(message: "Control socket bind failed", toSTDERR: true);
	chmod(filename: CONTROL_SOCKET_FILE, permissions: 0666);
	
	//Read queue
	readConvertQueue();
	
	$aOldControlMessage = '';
	$gQueueTasks = array(			//Array for indexes of current running tasks
		'convert' =>	array(),
		'scan' =>		array(),
		'unpack' =>		array(),
		);
//	$gQueueProcesses = array();		//Array for running processes
//	$gProcessPipes = array();		//Array for I/O pipes of running processes
	
	while(true)
	{
		//Determine if control socket got data pending to read
		$aReadSockets = array($gControlSocket);
		$aWriteSockets = null;
		$aExceptSockets = null;
		$aSockChanged = socket_select(read: $aReadSockets, write: $aWriteSockets, except: $aExceptSockets, seconds: 0);
		if($aSockChanged === false)
			_msg(message: 'Socket select failed: ' . socket_strerror(socket_last_error()), toSTDERR: true);
		elseif($aSockChanged > 0)
		{	
			//Read data from control socket
			if(socket_recvfrom(socket: $gControlSocket, data: $aMessage, length: 64 * 1024, flags: MSG_DONTWAIT, address: $aSockAddr) === false)
				_msg(message: 'Socket read failed: ' . socket_strerror(socket_last_error()));
			elseif($aOldControlMessage != $aMessage)
			{
				$aOldControlMessage = $aMessage;
				$aControlMessage = json_decode(json: $aMessage, associative: true);
				if(is_array($aControlMessage) && isset($aControlMessage['qumaID']) && $aControlMessage['qumaID'] == MY_ID)
				{
					if(isset($aControlMessage['action']))
						switch($aControlMessage['action'])
						{
							case 'add_queue_item':
								$aSockMessage = json_encode(value: addQueueItem($aControlMessage['queue_item']));
								$aResponseSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
								if(socket_sendto(socket: $aResponseSocket, data: $aSockMessage, length: strlen($aSockMessage), flags: MSG_EOF, address: $aControlMessage['response_sock']) === false)
									_msg(message: 'Socket response send failed: ' . socket_strerror(socket_last_error()), toSTDERR: true);
							break;
						}
				}
			}
		}
		
		
		//Read & process queue items
		foreach($gConvertQueue as $aItemIndex => $aQueueItem)
		{
			$aItemID = $aQueueItem['id'];
			$aItemStatus = $aQueueItem['status'];
			$aItemSettings = $aQueueItem['settings'];
			
			/*Queue item status
				0	waiting
				1	ready to scan
				2	scanning
				3	ready to convert / scan done
				4	converting
				5	done
				
				9x	error
			*/
			if($aItemStatus == 0)	//Fresh item
			{
				//If at least one audio stream is going to be normalized, put item to status=1
				foreach($aItemSettings['loudnorm'] as $aLoudnorm)
					if($aLoudnorm != 'off')
					{
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: 1);
						continue(2);
					}
				//..otherwise status=3
				changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: 3);
				continue;
			}
		}
		
		if(count($gQueueTasks['scan']) < STATIC_CONFIG['queue']['max_scan_tasks'])
		{
			//Scan task slots are available, check if queue items are status 1 (ready to scan)
			foreach($gConvertQueue as $aItemIndex => $aQueueItem)
			{
				$aItemID = $aQueueItem['id'];
				$aItemStatus = $aQueueItem['status'];
				$aItemSettings = $aQueueItem['settings'];
				
				if($aItemStatus == 1)
				{
					$aAudioScanString = CONFIG['Binaries']['ffmpeg'] . ' \\' . PHP_EOL;
					$aAudioScanString .= ' -i ' . escapeshellarg($aItemSettings['infile']) . ' \\' . PHP_EOL;
					$aAudioScanString .= '-vn -sn -dn -map_chapters -1' . ' \\' . PHP_EOL;
					
					$aAudioScanIndex = 0;
					
					//Find all audio tracks where loudnorm scanning is necessary
					foreach($aItemSettings['map'] as $aMapIndex => $aMapValue)
					{
						$aAudioScanFilters = array();
						foreach($aItemSettings as $aKey => $aData)
						{
							//Iterate through all given settings, which are not "map" informations
							if($aKey != 'map' && is_array($aData)) 
								foreach($aData as $aDataMapIndex => $aDataValue)
									if($aDataMapIndex == $aMapIndex && $aKey == 'loudnorm' && $aDataValue != 'off' && isset(STATIC_CONFIG['audio']['loudnorm'][$aDataValue]))
									{
										$aLoudNormData = STATIC_CONFIG['audio']['loudnorm'][$aDataValue];
										$aAudioScanFilters[10] = "loudnorm=I={$aLoudNormData['I']}:TP={$aLoudNormData['TP']}:LRA={$aLoudNormData['LRA']}:print_format=json";
										$aAudioScanString .= "-map $aMapValue" . ' \\' . PHP_EOL;
									}
						}
				
						//Summarize collected audio loudnorm filter settings
						if(count($aAudioScanFilters) > 0)
						{
							ksort($aAudioScanFilters);
							$aAudioScanString .=	" -filter:$aAudioScanIndex " . escapeshellarg(implode(separator: ',', array: $aAudioScanFilters)) . ' \\' . PHP_EOL;
							$aAudioScanIndex++;
						}
					}
					
					$aAudioScanString .= ' -f null -' . PHP_EOL;
										
					//Add itemID to scan list for identification
					$gQueueTasks['scan'][] = $aItemID;
					
					changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: 2);
					
					_msg(message: 'Start scanning of: ' . $aQueueItem['settings']['outfile'], CRF: '');
					
					//Preparing I/O pipes
					$aDescriptorSpec = array(
						0 => array('pipe', 'r'), 
						1 => array('pipe', 'w'),
						2 => array('pipe', 'w')
						);
					
					$gQueueProcesses[$aItemID] = proc_open(command: $aAudioScanString, descriptor_spec: $aDescriptorSpec, pipes: $gProcessPipes[$aItemID]);
					if(is_resource($gQueueProcesses[$aItemID]))
					{
						_msg(message: 'PID: ' . proc_get_status(process: $gQueueProcesses[$aItemID])['pid'], fixedWidth: 12);
						stream_set_blocking(stream: $gProcessPipes[$aItemID][1], enable: false);
						stream_set_blocking(stream: $gProcessPipes[$aItemID][2], enable: false);
					}
					else
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: 92);
				}
			}
		}
		
		//Check if processes have finished
		foreach($gQueueProcesses as $aQueueItemID => $aProcess)
		{
			$aProcStatus = proc_get_status(process: $aProcess);
			if($aProcStatus['running'] == false)
			{
				foreach($gConvertQueue as $aItemIndex => $aQueueItem)
					if($aQueueItem['id'] == $aQueueItemID)
					{
						$aItemID = $aQueueItem['id'];
						$aItemStatus = $aQueueItem['status'];
						$aItemSettings = $aQueueItem['settings'];
						break;
					}
				
			}
		}
		
		
		sleep(1);
	}
?>