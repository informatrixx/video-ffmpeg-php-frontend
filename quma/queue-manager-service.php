<?php

	if (php_sapi_name() != "cli")
	{
		header("HTTP/1.1 418 I'm a teapot");
		die('This script is a standalone CLI script...');
	}


	define(constant_name: 'SCRIPT_DIR', value: rtrim(string: __DIR__, characters: '/') . '/');
	require_once(SCRIPT_DIR . '../shared/common.inc.php');
	require_once(ROOT . 'shared/quma-status-codes.inc.php');

	define(constant_name: 'PID_FILE', value: RUN_DIR . pathinfo(path: __FILE__, flags: PATHINFO_FILENAME) . '.pid');
	define(constant_name: 'CONTROL_SOCKET_FILE', value: RUN_DIR . 'quma.sock');

	define(constant_name: 'QUEUE_FILE', value: SCRIPT_DIR . 'queue.json');
	
	
	//Check if PID file is orphaned or an old Process is still running
	if(file_exists(PID_FILE))
	{
		$aOldPID = file_get_contents(PID_FILE);
		if(posix_kill(process_id: $aOldPID, signal: 0))
			die('...already running' . PHP_EOL);
	}

	function shutDownFunction()
	{
		global $gControlSocket, $gConvertQueue;
		
		_msg(message: 'Shutdown...');
		
		_msg(message: 'Terminating child processes...');
		foreach($gConvertQueue as &$aQueueItem)
			if(isset($aQueueItem['proc']))
			{
				$aQueueItem['status'] = '8' . $aQueueItem['status'];
				_msg(message: 'Child PID: ' . proc_get_status(process: $aQueueItem['proc']['ressource'])['pid'], CRF: '');
				proc_terminate(process: $aQueueItem['proc']['ressource']);
				sleep(1);
				while(proc_get_status(process: $aQueueItem['proc']['ressource'])['running'])
				{
					_msg(message: '.', CRF: '', fixedWidth: 1);
					sleep(1);
				}
				_msg(message: ' OK', fixedWidth: 3);
				break;
			}
		unset($aQueueItem);
		
		writeConvertQueue();
		
		_msg(message: 'Removing PID File...', CRF: '');
		_msg(message: unlink(PID_FILE) ? 'OK' : 'Error!', fixedWidth: 6);
			
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
	
	function statusEcho(string $topic, array $statusArray)
	{
		global $gStatusSockets;
		
		$aDataMessage = array(
			"topic"	=> $topic,
			"data"	=> $statusArray,
			);
		
		$aMessage = json_encode(value: $aDataMessage) . '\0';
		
		foreach($gStatusSockets as $aSocketIndex => $aStatusSocket)
			if(@socket_send(socket: $aStatusSocket, data: $aMessage, length: strlen($aMessage), flags: MSG_EOR) === false)
				unset($gStatusSockets[$aSocketIndex]);
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
	
	function writeConvertQueue()
	{
		global $gConvertQueue;
		
		_msg(message: 'Writing convert queue file... ', CRF: '');
		
		//Create temp object and remove unwanted elements...
		$aTempQueue = array();
		foreach($gConvertQueue as $aQueueItem)
		{
			if(isset($aQueueItem['proc']))
				unset($aQueueItem['proc']);
			$aTempQueue[] = $aQueueItem;
		}
		
		if(file_put_contents(filename: QUEUE_FILE, data: json_encode(value: $aTempQueue, flags: JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR )) === false)
		{
			_msg(message: 'Error!', fixedWidth: 6);
			_msg(message: 'Error writing queue file to disk: ' . QUEUE_FILE, toSTDERR: true);
		}
		else
			_msg(message: 'OK', fixedWidth: 6);
	}
	
	function addQueueItem(array $newItem)
	{
		global $gConvertQueue;
		$aDuplicate = false;

		$aResult = array(
			'success' =>	false,
			'error' =>		'',
			);
		
		$newItem['settings']['outfolder'] = rtrim(characters: '/', string: $newItem['settings']['outfolder']) . '/';
		
		if(isset($newItem['settings']['outfile']))
			_msg(message: 'Adding queue item: ' . $newItem['settings']['outfile'], CRF: '');
		else
			_msg(message: 'Adding queue item: ' . $newItem['settings']['infile'], CRF: '');
		
        //Check for duplicate item
        foreach($gConvertQueue as $aQueueItem)
        {
        	if($newItem['settings']['outfolder'] == $aQueueItem['settings']['outfolder'] && $newItem['settings']['infile'] == $aQueueItem['settings']['infile'])
			{
				$aResult['error'] = 'Duplicate';
				_msg(message: 'Duplicate', fixedWidth: 6);
				return $aResult;
			}
        }
		
        $gConvertQueue[] = $newItem;
        $aResult['success'] = true;
       _msg(message: 'OK', fixedWidth: 6);
       
        return $aResult;
	}
	
	function deleteQueueItem(string $itemID)
	{
		global $gConvertQueue;

		$aResult = array(
			'success' =>	false,
			'error' =>		'',
			);
		
		
        //Check for existing item
        foreach($gConvertQueue as $aQueueItemIndex => $aQueueItem)
        {
        	if($itemID == $aQueueItem['id'])
			{
				_msg(message: 'Deleting queue item: ' . $aQueueItem['settings']['outfile'], CRF: '');
				unset($gConvertQueue[$aQueueItemIndex]);
				$aResult['success'] = true;
				_msg(message: 'OK', fixedWidth: 6);
				writeConvertQueue();
				return $aResult;
			}
        }
       
        $aResult['error'] = "Cannot delete: Item (ID {$aQueueItem['id']}) doesn't exist...";
        return $aResult;
	}
	
	function retryQueueItem(string $itemID)
	{
		global $gConvertQueue;

		$aResult = array(
			'success' =>	false,
			'error' =>		'',
			);
		
		
        //Check for existing item
        foreach($gConvertQueue as $aQueueItemIndex => $aQueueItem)
        {
        	$aOldStatus = $aQueueItem['status'];
        	if($itemID == $aQueueItem['id'] && ($aOldStatus == QUMA_STATUS_SCAN_ABORT ||  $aOldStatus == QUMA_STATUS_CONVERT_ABORT || $aOldStatus == QUMA_STATUS_SCAN_ERROR ||  $aOldStatus == QUMA_STATUS_CONVERT_ERROR))
			{
				_msg(message: 'Resetting queue item: ' . $aQueueItem['settings']['outfile'], CRF: '');
				changeQueueItemStatus(queueItemIndex: $aQueueItemIndex, newStatus: (int)ltrim(string: $aOldStatus, characters: '89') - 1);
				$aResult['success'] = true;
				_msg(message: 'OK', fixedWidth: 6);
				writeConvertQueue();
				return $aResult;
			}
        }
       
        $aResult['error'] = "Cannot reset: Item (ID {$aQueueItem['id']}) doesn't exist...";
        return $aResult;
	}
	
	function pauseQueueItem(string $itemID, bool $doResume = false)
	{
		global $gConvertQueue;

		$aResult = array(
			'success' =>	false,
			'error' =>		'',
			);

		$aSignal = $doResume ? SIGCONT : SIGSTOP;
		
        //Check for existing item
        foreach($gConvertQueue as $aQueueItemIndex => $aQueueItem)
        {
        	$aOldStatus = $aQueueItem['status'];
        	if($itemID == $aQueueItem['id'] && ($aOldStatus == QUMA_STATUS_SCAN ||  $aOldStatus == QUMA_STATUS_CONVERT || $aOldStatus == QUMA_STATUS_SCAN_PAUSE ||  $aOldStatus == QUMA_STATUS_CONVERT_PAUSE))
			{
				if($doResume == false)
					_msg(message: 'Pausing queue item: ' . $aQueueItem['settings']['outfile'], CRF: '');
				else
					_msg(message: 'Resuming queue item: ' . $aQueueItem['settings']['outfile'], CRF: '');
				
				//Getting sub PIDs
				$aSubPIDs = preg_split('/\s+/', shell_exec('ps -o pid --no-heading --ppid ' . proc_get_status(process: $aQueueItem['proc']['ressource'])['pid']));
				$aSignalResult = true;
				
				foreach($aSubPIDs as $aSubPID)
					if(is_numeric($aSubPID))
						if(!posix_kill(process_id: $aSubPID, signal: $aSignal))
						{
							_msg(message: "Error (PID: $aSubPID)", fixedWidth: 6);
							$aSignalResult = false;
						}
					
				if($aSignalResult)
				{
					$aResult['success'] = true;
					_msg(message: 'OK', fixedWidth: 6);
					if($doResume == false)
						changeQueueItemStatus(queueItemIndex: $aQueueItemIndex, newStatus: '9' . ((int)$aOldStatus - 1));
					else
						changeQueueItemStatus(queueItemIndex: $aQueueItemIndex, newStatus: (int)ltrim(string: $aOldStatus, characters: '9') + 1);
					return $aResult;
				}
			}
        }
		
        $aResult['error'] = "Cannot pause/resume: Item (ID {$aQueueItem['id']}) doesn't exist...";
       
        return $aResult;
	}
	
	function changeQueueItemStatus(int $queueItemIndex, int $newStatus)
	{
		global $gConvertQueue;
		$gConvertQueue[$queueItemIndex]['status'] = $newStatus;

		if(isset($gConvertQueue[$queueItemIndex]['settings']['outfile']))
			$aFileText = 'Target: ' . $gConvertQueue[$queueItemIndex]['settings']['outfile'];
		else
			$aFileText = 'Source: ' . $gConvertQueue[$queueItemIndex]['settings']['infile'];
		if(isset(QUMA_TEXT_STATUS[$newStatus]))
			_msg(message: 'Change queue item status to "' . QUMA_TEXT_STATUS[$newStatus] . " ($newStatus)\": $aFileText");
		
		$aStatusArray = array(
			'id'		=> $gConvertQueue[$queueItemIndex]['id'],
			'infile'	=> $gConvertQueue[$queueItemIndex]['settings']['infile'],
			'outfile'	=> isset($gConvertQueue[$queueItemIndex]['settings']['outfile']) ? $gConvertQueue[$queueItemIndex]['settings']['outfile'] : null,
			'size'		=> isset($gConvertQueue[$queueItemIndex]['result']) && isset($gConvertQueue[$queueItemIndex]['result']['fileSize']) ? $gConvertQueue[$queueItemIndex]['result']['fileSize'] : null,
			'status'	=> $newStatus,
			'type'		=> $gConvertQueue[$queueItemIndex]['settings']['type'],
			);
		statusEcho(topic: 'status', statusArray: $aStatusArray);
		
		writeConvertQueue();
	}
	
	
	
	
	/*	SCRIPT BODY	*/
	
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));

	if(!file_exists(ROOT . 'config/ID')) //Create a unique ID if not yet set
	{
		file_put_contents(filename: ROOT . 'config/ID', data: md5(rand(123456789, 12345678900)));
		chmod(filename: ROOT . 'config/ID', permissions: 0660);
	}
	
	define(constant_name: 'MY_ID', value: file_get_contents(ROOT . 'config/ID'));

	
	
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
		'extract' =>	array(),
		);
	
	$gStatusSockets = array();
	
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
							case 'addStatusSocket':
								_msg(message: 'Got status client request...', CRF: '');
								$gStatusSockets[$aControlMessage['randomID']] = socket_create(AF_UNIX, SOCK_STREAM, 0);
								 
								usleep(100);
								if(socket_connect(socket: $gStatusSockets[$aControlMessage['randomID']], address: $aControlMessage['responseSock']) === false)
									_msg(message: 'Could not connect (' . $aControlMessage['responseSock'] . '): ' . socket_strerror(socket_last_error()), toSTDERR: true);
								else
									_msg(message: 'OK', fixedWidth: 6);
							break;
							case 'queueItem:delete':
							case 'queueItem:pause':
							case 'queueItem:resume':
							case 'queueItem:add':
							case 'queueItem:retry':
								
								$aSockMessage = match($aControlMessage['action'])
								{
									'queueItem:delete'	=> json_encode(value: deleteQueueItem(itemID: $aControlMessage['queueItemID'])),
									'queueItem:pause'	=> json_encode(value: pauseQueueItem(itemID: $aControlMessage['queueItemID'], doResume: false)),
									'queueItem:resume'	=> json_encode(value: pauseQueueItem(itemID: $aControlMessage['queueItemID'], doResume: true)),
									'queueItem:add'		=> json_encode(value: addQueueItem(newItem: $aControlMessage['queueItem'])),
									'queueItem:retry'	=> json_encode(value: retryQueueItem(itemID: $aControlMessage['queueItemID'])),
								};
								
								$aResponseSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
								if(socket_sendto(socket: $aResponseSocket, data: $aSockMessage, length: strlen($aSockMessage), flags: MSG_EOF, address: $aControlMessage['responseSock']) === false)
									_msg(message: 'Socket response send failed: ' . socket_strerror(socket_last_error()), toSTDERR: true);
								if(socket_shutdown(socket: $aResponseSocket) === false)
									_msg(message: 'Response socket shutdown failed: ' . socket_strerror(socket_last_error()), toSTDERR: true);
								if(socket_close(socket: $aResponseSocket) === false)
									_msg(message: 'Response socket closing failed: ' . socket_strerror(socket_last_error()), toSTDERR: true);
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
				
				10	waiting
				11	ready to unrar
				12	unrar (extracting)
				15	unrar done
				19  Error unrar

				8x	Abort
				9x	Error / Pause
			*/
			if($aItemStatus == QUMA_STATUS_WAITING)	//Fresh item
			{
				//If at least one audio stream is going to be normalized, put item to status=1
				foreach($aItemSettings['loudnorm'] as $aLoudnorm)
					if($aLoudnorm != '0')
					{
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_SCAN_READY);
						continue(2);
					}
				//..otherwise status=3
				changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_CONVERT_READY);
				continue;
			}
			if($aItemStatus == QUMA_STATUS_UNRAR_WAITING)	//Fresh item (unrar)
			{
				changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_UNRAR_READY);
				continue;
			}
		}
		
		if(count($gQueueTasks['scan']) < STATIC_CONFIG['queue']['max_scan_tasks'])
		{
			//Scan task slots are available, check if queue items are status 1 (ready to scan)
			foreach($gConvertQueue as $aItemIndex => &$aQueueItem)
			{
				$aItemID = $aQueueItem['id'];
				$aItemStatus = $aQueueItem['status'];
				$aItemSettings = $aQueueItem['settings'];
				
				if($aItemStatus == QUMA_STATUS_SCAN_READY || $aItemStatus == QUMA_STATUS_SCAN_CHILD_READY)
				{
					$aAudioScanString = CONFIG['Binaries']['ffmpeg'] . ' \\' . PHP_EOL;
					
					if(is_string($aItemSettings['infile']))
						$aAudioScanString .= ' -i ' . escapeshellarg($aItemSettings['infile']) . ' \\' . PHP_EOL;
					elseif(is_array($aItemSettings['infile']))
					{
						
						foreach($aItemSettings['infile'] as $aInFile)
							$aAudioScanString .= ' -i ' . escapeshellarg($aInFile) . ' \\' . PHP_EOL;

					}
					$aAudioScanString .= '-vn -sn -dn -map_chapters -1' . ' \\' . PHP_EOL;

					$aAudioScanIndex = 0;
					$i = 0;
					
					//Find all audio tracks where loudnorm scanning is necessary
					foreach($aItemSettings['map'] as $aMapIndex => $aMapValue)
					{
						$aAudioScanFilters = array();
						
						foreach($aItemSettings as $aKey => $aData)
						{
							//Iterate through all given settings, which are not "map" informations
							if($aKey != 'map' && is_array($aData)) 
								foreach($aData as $aDataMapIndex => $aDataValue)
									if($aDataMapIndex == $aMapIndex && $aKey == 'loudnorm' && $aDataValue != '0' && isset(STATIC_CONFIG['audio']['loudnorm'][$aDataValue]))
									{
										$aLoudNormData = STATIC_CONFIG['audio']['loudnorm'][$aDataValue];
										if($i++ == 0)
											$aAudioScanString .= "-map $aMapValue -filter loudnorm=I={$aLoudNormData['I']}:TP={$aLoudNormData['TP']}:LRA={$aLoudNormData['LRA']}:print_format=json" . ' \\' . PHP_EOL;
										else
										{
											//create a split task for follow up audio tracks to scan all of them separate
											$gConvertQueue[] = array(
												'status'		=> QUMA_STATUS_SCAN_CHILD_READY,
												'id'			=> "{$aItemID}x$i",
												'settings'		=> array(
													'type'		=> 'scan:' . $aItemSettings['type'],
													'infile'	=> $aItemSettings['infile'],
													'outfile'	=> $aItemSettings['outfile'],
													'map'		=> array($aDataMapIndex => $aMapValue),
													'loudnorm'	=> array($aDataMapIndex => $aItemSettings['loudnorm'][$aDataMapIndex]),
													'duration'	=> $aItemSettings['duration'],
													)
												);
										}
									}
						}
					}
					
					$aAudioScanString .= ' -f null -' . PHP_EOL;
										
					//Add itemID to scan list for identification
					$gQueueTasks['scan'][] = $aItemID;
					
					
					_msg(message: 'Start scanning of: ' . $aQueueItem['settings']['outfile'], CRF: '');
					
					if(CONFIG['Debugging'])
						file_put_contents(filename: LOG_DIR . 'scan_ffmpeg_' . date('Ymd-His') . "{$aQueueItem['settings']['outfile']}$aItemID.sh", data: '#!/bin/bash' . PHP_EOL . $aAudioScanString);

					//Preparing I/O pipes
					$aDescriptorSpec = array(
						0 => array('pipe', 'r'), 
						1 => array('pipe', 'w'),
						2 => array('pipe', 'w')
						);
					
					$aQueueItem['proc']['ressource'] = proc_open(command: $aAudioScanString, descriptor_spec: $aDescriptorSpec, pipes: $aQueueItem['proc']['pipes']);
					if(is_resource($aQueueItem['proc']['ressource']))
					{
						$aPID = proc_get_status(process: $aQueueItem['proc']['ressource'])['pid'];
						_msg(message: "PID: $aPID" , fixedWidth: 12);

						_msg(message: "Setting nice level (prio) of #$aPID -> " . STATIC_CONFIG['queue']['procNiceLevel'], CRF: '');
						if(pcntl_setpriority(priority: STATIC_CONFIG['queue']['procNiceLevel'], process_id: $aPID))
							_msg(message: 'OK', fixedWidth: 6);
								else
							_msg(message: 'Error!', fixedWidth: 6);
							
						stream_set_blocking(stream: $aQueueItem['proc']['pipes'][1], enable: false);
						stream_set_blocking(stream: $aQueueItem['proc']['pipes'][2], enable: false);
						if($aItemStatus == QUMA_STATUS_SCAN_READY)
							changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_SCAN);
						elseif($aItemStatus == QUMA_STATUS_SCAN_CHILD_READY)
							changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_SCAN_CHILD);
					}
					else
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_SCAN_ERROR);
				}
				if(count($gQueueTasks['scan']) >= STATIC_CONFIG['queue']['max_scan_tasks'])
					break;
			}
			unset($aQueueItem);
		}
		
		if(count($gQueueTasks['convert']) < STATIC_CONFIG['queue']['max_convert_tasks'])
		{
			//Convert task slots are available, check if queue items are status 3 (ready to convert)
			foreach($gConvertQueue as $aItemIndex => &$aQueueItem)
			{
				$aItemID = $aQueueItem['id'];
				$aItemStatus = $aQueueItem['status'];
				$aItemSettings = $aQueueItem['settings'];
				
				if($aItemStatus == QUMA_STATUS_CONVERT_READY)
				{
					$aConvertString = CONFIG['Binaries']['ffmpeg'] . ' \\' . PHP_EOL;
					
					if(is_string($aItemSettings['infile']))
						$aConvertString .= ' -i ' . escapeshellarg($aItemSettings['infile']) . ' \\' . PHP_EOL;
					elseif(is_array($aItemSettings['infile']))
						foreach($aItemSettings['infile'] as $aInFile)
							$aConvertString .= ' -i ' . escapeshellarg($aInFile) . ' \\' . PHP_EOL;
					
					$aStreamIndex = 0;
					$aLoudnormIndex = 0;
					foreach($aItemSettings['map'] as $aMapIndex => $aMapValue)
					{
						$aFilters = array();
						$aDisposition = array('default' => '-', 'forced' => '-');
						$aConvertString .= "-map $aMapValue \\" . PHP_EOL;
						foreach($aItemSettings as $aKey => $aData)
							if($aKey != 'map' && is_array($aData)) 
							{
								foreach($aData as $aDataMapIndex => $aDataValue)
									if($aDataMapIndex == $aMapIndex)
										switch($aKey)
										{
											case 'title':
												$aConvertString .= " -metadata:s:$aStreamIndex " . escapeshellarg("title=$aDataValue") . ' \\' . PHP_EOL;
												break;
											case 'loudnorm':
												if($aDataValue != '0' && isset(STATIC_CONFIG['audio']['loudnorm'][$aDataValue]))
												{
													$aLNData = STATIC_CONFIG['audio']['loudnorm'][$aDataValue];
													$aLNScan = $aQueueItem['loudnorm_scan'][$aMapIndex];
													$aFilters[10] =	"loudnorm=I={$aLNData['I']}:TP={$aLNData['TP']}:LRA={$aLNData['LRA']}:linear=true:" .
																	"measured_I={$aLNScan['output_i']}:measured_TP={$aLNScan['output_tp']}:measured_LRA={$aLNScan['output_lra']}:measured_thresh={$aLNScan['output_thresh']}:offset={$aLNScan['target_offset']}";
												}
											break;
											case 'ac':
												if($aDataValue != 'dpl')
													$aConvertString .= " -$aKey:$aStreamIndex $aDataValue" . ' \\' . PHP_EOL;
												else
												{
													$aConvertString .= " -ac:$aStreamIndex 2" . ' \\' . PHP_EOL;
													$aFilters[20] = "aresample=matrix_encoding=dplii";
												}
												break;
												case 'nlmeans':
													if($aDataValue != '0')
														$aFilters[20] = 'nlmeans=' . STATIC_CONFIG['video']['nlmeans'][$aDataValue]['value'];
													break;
												case 'crop':
													if($aDataValue == 'auto' && !empty($aItemSettings['cropstring'][$aDataMapIndex]))
														$aFilters[10] = $aItemSettings['cropstring'][$aDataMapIndex];
													break;
												case 'resize':
													if($aDataValue != '0')
														$aFilters[30] = "scale=$aDataValue:-2";
													break;
												case 'default':
												case 'forced':
													$aDisposition[$aKey] = $aDataValue != '0' ? '+' : '-';
													break;
												case 'b':
													if(preg_match(pattern: '/^(\d+)k$/', subject: $aDataValue, matches: $aBitRateMatches) !== false)
													{
														$aBPS = $aBitRateMatches[1] * 1000;
														$aConvertString .= " -metadata:s:$aStreamIndex " . escapeshellarg("BPS=$aBPS") . ' \\' . PHP_EOL;
														$aConvertString .= " -b:$aStreamIndex $aDataValue" . ' \\' . PHP_EOL;
													}
													break;
												case 'cbr':
													if(preg_match(pattern: '/^(\d+)k$/', subject: $aDataValue, matches: $aBitRateMatches) !== false)
													{
														$aBPS = $aBitRateMatches[1] * 1000;
														$aConvertString .= " -metadata:s:$aStreamIndex " . escapeshellarg("BPS=$aBPS") . ' \\' . PHP_EOL;
														$aConvertString .= " -b:$aStreamIndex $aDataValue" . ' \\' . PHP_EOL;
														$aConvertString .= " -minrate:$aStreamIndex $aDataValue" . ' \\' . PHP_EOL;
														$aConvertString .= " -maxrate:$aStreamIndex $aDataValue" . ' \\' . PHP_EOL;
													}
													break;
												case 'profile':
													if($aDataValue != 0)
														$aConvertString .= " -profile:$aStreamIndex $aDataValue" . ' \\' . PHP_EOL;
													break;
												case 'tune':
													if($aDataValue != 0)
														$aConvertString .= " -tune:$aStreamIndex $aDataValue" . ' \\' . PHP_EOL;
													break;
												case 'infile':
												case 'cropstring':
												case 'duration':
													break;
												default:
													$aConvertString .= " -$aKey:$aStreamIndex $aDataValue" . ' \\' . PHP_EOL;
													break;
											}
							}
							if(count($aFilters) > 0)
							{
								ksort($aFilters);
								$aConvertString .=	" -filter:$aStreamIndex " . escapeshellarg(implode(separator: ',', array: $aFilters)) . ' \\' . PHP_EOL;
							}
		
							$aConvertString .= " -disposition:$aStreamIndex ";
							foreach($aDisposition as $aKey => $aValue)
								$aConvertString .= "$aValue$aKey";
							$aConvertString .= ' \\' . PHP_EOL;

							$aStreamIndex++;
					}
					//If moreParams are given
					if(isset($aItemSettings['moreParams']))
						$aConvertString .= $aItemSettings['moreParams'] . ' \\' . PHP_EOL;
					
					
					//Starting new cluster due to timestamp -> -max_interleave_delta 0
					
					//Subtitles just copy (if mapped)
					$aConvertString .= ' -c:s copy' . ' \\' . PHP_EOL;
					$aConvertString .= ' -reserve_index_space 100k ' . ' \\' . PHP_EOL;
					$aConvertString .= ' -cues_to_front 1 ' . ' \\' . PHP_EOL;
					
					//Filetitle and video title
					$aConvertString .= ' -metadata ' . escapeshellarg("title={$aItemSettings['filetitle']}") . ' \\' . PHP_EOL;
					$aConvertString .= ' -metadata:s: "_STATISTICS_WRITING_APP=video-ffmpeg-php-frontend" \\' . PHP_EOL;
					$aConvertString .= ' -metadata:s: ' . escapeshellarg('_STATISTICS_WRITING_DATE_UTC=' . gmdate(format: 'Y-m-d H:i:s')) . ' \\' . PHP_EOL;
					$aConvertString .= ' -metadata:s: "NUMBER_OF_FRAMES=" \\' . PHP_EOL;
					$aConvertString .= ' -metadata:s: "NUMBER_OF_BYTES=" \\' . PHP_EOL;
					$aConvertString .= ' -metadata:s: "SOURCE_ID=" \\' . PHP_EOL;
					
					//Statistic tags video
					$aConvertString .= ' -metadata:s:v ' . escapeshellarg("title={$aItemSettings['filetitle']}") . ' \\' . PHP_EOL;
					$aConvertString .= ' -metadata:s:v "_STATISTICS_TAGS=DURATION" \\' . PHP_EOL;
					$aConvertString .= ' -metadata:s:v "BPS=" \\' . PHP_EOL;

					//Statistic tags audio
					$aConvertString .= ' -metadata:s:a "_STATISTICS_TAGS=DURATION BPS" \\' . PHP_EOL;

					$aOutFolder = rtrim(string: $aItemSettings['outfolder'], characters: '/') . '/';
					$aOutFile = $aItemSettings['outfile'];
					$aConvertString .= " " . escapeshellarg("$aOutFolder$aOutFile") . PHP_EOL;
					
					//Add itemID to scan list for identification
					$gQueueTasks['convert'][] = $aItemID;
					
					if(file_exists("$aOutFolder$aOutFile"))
						switch(CONFIG['OutputFileExists'])
						{
							case 'move':
								$i = 0;
								do
								{
									$i++;
									$aMvFileName = "$aOutFile~$i";
								} while(file_exists("$aOutFolder$aMvFileName"));
								_msg(message: 'Output file already exists! Move...', CRF: '');
								if(rename(from: "$aOutFolder$aOutFile", to: "$aOutFolder$aMvFileName"))
									_msg(message: 'OK', fixedWidth: 6);
								else
									_msg(message: 'Error!', fixedWidth: 6);
								break;
						}
					
					_msg(message: 'Start conversion of: ' . $aQueueItem['settings']['outfile'], CRF: '');
					
					if(CONFIG['Debugging'])
						file_put_contents(filename: LOG_DIR . 'convert_ffmpeg_' . date('Ymd-His') . "_$aOutFile.sh", data: '#!/bin/bash' . PHP_EOL . $aConvertString);
					
					//Preparing I/O pipes
					$aDescriptorSpec = array(
						0 => array('pipe', 'r'), 
						1 => array('pipe', 'w'),
						2 => array('pipe', 'w')
						);
					
					$aQueueItem['proc']['ressource'] = proc_open(command: $aConvertString, descriptor_spec: $aDescriptorSpec, pipes: $aQueueItem['proc']['pipes']);
					if(is_resource($aQueueItem['proc']['ressource']))
					{
						$aPID = proc_get_status(process: $aQueueItem['proc']['ressource'])['pid'];
						_msg(message: "PID: $aPID" , fixedWidth: 12);

						_msg(message: "Setting nice level (prio) of #$aPID -> " . STATIC_CONFIG['queue']['procNiceLevel'], CRF: '');
						if(pcntl_setpriority(priority: STATIC_CONFIG['queue']['procNiceLevel'], process_id: $aPID))
							_msg(message: 'OK', fixedWidth: 6);
								else
							_msg(message: 'Error!', fixedWidth: 6);
						
						stream_set_blocking(stream: $aQueueItem['proc']['pipes'][1], enable: false);
						stream_set_blocking(stream: $aQueueItem['proc']['pipes'][2], enable: false);
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_CONVERT);
					}
					else
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_CONVERT_ERROR);
				}
				if(count($gQueueTasks['convert']) >= STATIC_CONFIG['queue']['max_convert_tasks'])
					break;
			}
			unset($aQueueItem);
		}
		
		if(count($gQueueTasks['extract']) < STATIC_CONFIG['queue']['max_extract_tasks'])
		{
			//Extract task slots are available, check if queue items are status 11 (ready to extract)
			foreach($gConvertQueue as $aItemIndex => &$aQueueItem)
			{
				$aItemID = $aQueueItem['id'];
				$aItemStatus = $aQueueItem['status'];
				$aItemSettings = $aQueueItem['settings'];
				
				if($aItemStatus == QUMA_STATUS_UNRAR_READY)
				{
					$aExtractString = CONFIG['Binaries']['unrar'];
					$aExtractString .= isset($aItemSettings['ignorepaths']) ? ' e ' : ' x ';
					$aExtractString .= isset($aItemSettings['overwrite']) ? ' -o+ ' : ' -o- ';
					$aExtractString .= escapeshellarg($aItemSettings['infile']) . ' \\' . PHP_EOL;
					
					foreach($aItemSettings['archivefile'] as $aArchiveFile)
						$aExtractString .= escapeshellarg($aArchiveFile) . ' \\' . PHP_EOL;
					
					$aExtractString .= escapeshellarg(rtrim(string: $aItemSettings['outfolder'], characters: '/') . '/' ) . ' \\' . PHP_EOL;
					$aExtractString .= '1>&2' . PHP_EOL;
					
					//Add itemID to scan list for identification
					$gQueueTasks['extract'][] = $aItemID;
					
					_msg(message: 'Start extracting of: ' . $aItemSettings['infile'], CRF: '');
					
					if(CONFIG['Debugging'])
						file_put_contents(filename: LOG_DIR . 'extract_unrar_' . date('Ymd-His') . '_' . basename($aItemSettings['infile']) . '.sh', data: '#!/bin/bash' . PHP_EOL . $aExtractString);
					
					//Preparing I/O pipes
					$aDescriptorSpec = array(
						0 => array('pipe', 'r'), 
						1 => array('pipe', 'w'),
						2 => array('pipe', 'w')
						);
					
					$aQueueItem['proc']['ressource'] = proc_open(command: $aExtractString, descriptor_spec: $aDescriptorSpec, pipes: $aQueueItem['proc']['pipes']);
					if(is_resource($aQueueItem['proc']['ressource']))
					{
						_msg(message: 'PID: ' . proc_get_status(process: $aQueueItem['proc']['ressource'])['pid'], fixedWidth: 12);
						stream_set_blocking(stream: $aQueueItem['proc']['pipes'][1], enable: false);
						stream_set_blocking(stream: $aQueueItem['proc']['pipes'][2], enable: false);
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_UNRAR);
					}
					else
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_UNRAR_ERROR);
				}
				if(count($gQueueTasks['extract']) >= STATIC_CONFIG['queue']['max_extract_tasks'])
					break;
			}
		}
		
		
		//Check if processes have finished
		foreach($gConvertQueue as $aItemIndex => &$aQueueItem)
			if(isset($aQueueItem['proc']))
			{
				//Read process output and decide what to keep...
				$aOutput = stream_get_contents(stream: $aQueueItem['proc']['pipes'][2]);
				switch(true)
				{
					case preg_match_all(pattern: '/\[parsed_loudnorm_(?<index>\d+)[\s@]+(?<hexID>0x[0-9a-f]+)\][^{]+(?<loudnormData>{[^[]+})/is', subject: $aOutput, matches: $aMatches, flags: PREG_SET_ORDER) > 0:
						
						$aLoudnormResult = array();
						foreach($aMatches as $aMatchData)
						{
							$aLoudnormData = json_decode(json: $aMatchData['loudnormData'], associative: true);
							if($aLoudnormData['input_i'] == '-inf' || $aLoudnormData['input_tp'] == '-inf' || $aLoudnormData['target_offset'] == 'inf')
								continue;
							else
								$aLoudnormResult[] = $aLoudnormData;
						}

						$aMappedKey = null;
						$aMappedID = null;
						
						foreach($aQueueItem['settings']['loudnorm'] as $aLNKey => $aLNSettingName)
							if($aLNSettingName != 0)
							{
								$aMappedKey = $aLNKey;
								$aMappedID = $aQueueItem['settings']['map'][$aLNKey];
								break;	
							}

						$aItemID = $aQueueItem['id'];
						if(count($aLoudnormResult) == 1)
							$aLoudnormResult = $aLoudnormResult[0];
						if(count($aLoudnormResult) > 0)
							$aQueueItem['loudnorm_scan'][$aMappedKey] = $aLoudnormResult;
						
						if(preg_match(pattern: '/^(?<id>[0-9a-f]+)(?>x(?<subIndex>\d+))/', subject: $aItemID, matches: $aIDMatches))
						{//if it's a child scan, add result to parent as well
							$aItemID = $aIDMatches['id'];
						
							foreach($gConvertQueue as $aSearchItemIndex => &$aSearchQueueItem)
								if($aSearchQueueItem['id'] == $aItemID)
								{
									if(count($aLoudnormResult) > 0)
										$aSearchQueueItem['loudnorm_scan'][$aMappedKey] = $aLoudnormResult;
									//parent waiting for results?
									if($aSearchQueueItem['status'] == QUMA_STATUS_SCAN_WAITING)
									{
										//Figure out if loudnorm scans are still missing...
										$aMissingLN = false;
										foreach($aSearchQueueItem['settings']['loudnorm'] as $aLNKey => $aLNSettingName)
											if($aLNSettingName != 0 && !isset($aSearchQueueItem['loudnorm_scan'][$aLNKey]))
											{
												$aMissingLN = true;
												break;
											}
										if(!$aMissingLN)
										{
											changeQueueItemStatus(queueItemIndex: $aSearchItemIndex, newStatus: QUMA_STATUS_CONVERT_READY);
										}
									}
									unset($aSearchQueueItem);
									break;
								}
						}
						_msg(message: "Stream: $aMappedID -> got Loudnorm scan results for: " . $aQueueItem['settings']['outfile']);
					break;
					case preg_match_all(pattern: '/\[(?<topic>.+)\s+@\s+0x[a-z0-9]+\](?<message>.+)/m', subject: $aOutput, matches: $aMatches, flags: PREG_SET_ORDER) > 0:
						//Message from FFMpeg
						//[matroska @ 0x561cd5bf2d00] Starting new cluster due to timestamp
						foreach($aMatches as $aMessageMatch)
						{
							$aStatusArray = array(
								'id'		=> $aQueueItem['id'],
								'outfile'	=> $aQueueItem['settings']['outfile'],
								'topic'		=> trim($aMessageMatch['topic']),
								'message'	=> trim($aMessageMatch['message']),
								'type'		=> $aQueueItem['settings']['type'],
								);
							$aQueueItem['result']['messages'][trim($aMessageMatch['topic'])][] = trim($aMessageMatch['message']);
							
							statusEcho(topic: 'info', statusArray: $aStatusArray);
						}
					break;
					case preg_match(pattern: '@frame=\s*(?<frame>[\d.]+)\s+fps=\s*(?<fps>[\d.]+)\s+q=\s*(?<q>[\d.]+)\s+size=\s*(?<size>[\d]+\SB)\s+time=\s*(?<time>[\d:.]+)\s+bitrate=\s*(?<bitrate>[\d.]+\Sbits/s)\s+speed=\s*(?<speed>[\d.]+x)@mi', subject: $aOutput, matches: $aMatches) > 0:
					case preg_match(pattern: '@size=\s*(?<size>[\d]+\SB)\s+time=\s*(?<time>[\d:.]+)\s+bitrate=\s*(?<bitrate>[\d.]+\Sbits/s)\s+speed=\s*(?<speed>[\d.]+x)@mi', subject: $aOutput, matches: $aMatches) > 0:
					case preg_match(pattern: '@size=N/A\s+time=(?<time>[\d:.]+)\sbitrate=N/A\sspeed=\s*(?<speed>[\d.]+x)@mi', subject: $aOutput, matches: $aMatches) > 0:
						$aSize = null;
						$aItemID = $aQueueItem['id'];
						
						if(preg_match(pattern: '/^(?<id>[0-9a-f]+)(?>x(?<subIndex>\d+))/', subject: $aItemID, matches: $aIDMatches))
							$aItemID = $aIDMatches['id'];
						
						if(isset($aMatches['size']))
							if(preg_match(pattern: '/(\d+)(\S?B)/i', subject: $aMatches['size'], matches: $aSizeMatches))
							{
								$aSizeFactor = match(strtolower($aSizeMatches[2]))
								{
									'b'	 => 1,
									'kb' => 1024,
									'mb' => 1024 * 1024,
									'gb' => 1024 * 1024 * 1024,
								};
								$aSizeBytes = $aSizeMatches[1] * $aSizeFactor;
								$aSize = array(
									'human'	=> humanFilesize($aSizeBytes) . 'B',
									'bytes'	=> $aSizeBytes,
									);
							}
							
						$aScanMappedStream = null;
						if($aQueueItem['status'] == QUMA_STATUS_SCAN || $aQueueItem['status'] == QUMA_STATUS_SCAN_CHILD)
							foreach($aQueueItem['settings']['loudnorm'] as $aLNKey => $aLNSettingsName)
								if($aLNSettingsName != 0)
								{
									$aScanMappedStream = $aQueueItem['settings']['map'][$aLNKey];
									break;
								}
							
						$aStatusArray = array(
							'id'		=> $aItemID,
							'outfile'	=> $aQueueItem['settings']['outfile'],
							'frame'	 	=> !empty($aMatches['frame']) ? $aMatches['frame'] : null,
							'fps'		=> !empty($aMatches['fps']) ? $aMatches['fps'] : null,
							'q'			=> !empty($aMatches['q']) ? $aMatches['q'] : null,
							'size'		=> $aSize,
							'bitrate'	=> !empty($aMatches['bitrate']) ? $aMatches['bitrate'] : null,
							'type'		=> $aQueueItem['settings']['type'],
							);
						$aStatusSplitArray = array(
							'id'		=> $aItemID,
							'time'		=> !empty($aMatches['time']) ? $aMatches['time'] : null,
							'speed'		=> !empty($aMatches['speed']) ? $aMatches['speed'] : null,
							'streamID'	=> $aScanMappedStream,
							);
						if($aQueueItem['status'] == QUMA_STATUS_SCAN || $aQueueItem['status'] == QUMA_STATUS_SCAN_CHILD)
						{
							statusEcho(topic: 'progress', statusArray: $aStatusArray);
							statusEcho(topic: 'scanProgress', statusArray: $aStatusSplitArray);
						}
						else
							statusEcho(topic: 'progress', statusArray: $aStatusArray + $aStatusSplitArray);
					break;
					case preg_match_all(pattern: '/^Extracting\s+(.+)$/im', subject: $aOutput, matches: $aMatches, flags: PREG_SET_ORDER) > 0:
						foreach($aMatches as $aMatchSet)
						{
							$aMatchString = processStringBackspace(trim($aMatchSet[1]));
							if(preg_match(pattern: '/^(?!from)(.+?)(OK|\d{1,3}%)$/i', subject: $aMatchString, matches: $aSubMatches))
								$aFileName = trim($aSubMatches[1]);
							$aStatusArray = array(
								'id'		=> $aQueueItem['id'],
								'infile'	=> $aQueueItem['settings']['infile'],
								'file'	 	=> isset($aFileName) ? $aFileName : null,
								'progress'	=> isset($aSubMatches[2]) ? $aSubMatches[2] : null,
								'type'		=> $aQueueItem['settings']['type'],
								);
							statusEcho(topic: 'extract', statusArray: $aStatusArray);
						}
					break;
					case $aQueueItem['settings']['type'] == 'rar' && preg_match(pattern: '/(\d+%)$/m', subject: $aOutput, matches: $aMatches) > 0:
						$aStatusArray = array(
							'id'		=> $aQueueItem['id'],
							'infile'	=> $aQueueItem['settings']['infile'],
							'progress'	=> $aMatches[1],
							'type'		=> $aQueueItem['settings']['type'],
							);
						statusEcho(topic: 'extract', statusArray: $aStatusArray);
					break;
					default:
						$aStatusArray = array(
							'id'		=> $aQueueItem['id'],
							'infile'	=> $aQueueItem['settings']['infile'],
							'outfile'	=> isset($aQueueItem['settings']['outfile']) ? $aQueueItem['settings']['outfile'] : null,
							'message'	=> $aOutput,
							'type'		=> $aQueueItem['settings']['type'],
							);
						statusEcho(topic: 'message', statusArray: $aStatusArray);
					break;
				}
				
				$aProcStatus = proc_get_status(process: $aQueueItem['proc']['ressource']);
				if($aProcStatus['running'] == false)
				{
					_msg(message: "Process PID:{$aProcStatus['pid']} stopped running. Cleaning up ...");
					
					$aItemID = $aQueueItem['id'];
					$aItemStatus = $aQueueItem['status'];
					$aItemSettings = $aQueueItem['settings'];
					
					//Close the I/O pipes
					fclose($aQueueItem['proc']['pipes'][0]);
					fclose($aQueueItem['proc']['pipes'][1]);
					fclose($aQueueItem['proc']['pipes'][2]);
					
					//Shutdown process
					proc_close($aQueueItem['proc']['ressource']);
					
					//Unset variable
					unset($aQueueItem['proc']);
					
					//Unset identifier variable from task array
					foreach($gQueueTasks as $aTaskTopic => $aTaskTopicItems)
						foreach($aTaskTopicItems as $aTopicItemsIndex => $aTaskItemID)
							if($aTaskItemID == $aItemID)
							{
								unset($gQueueTasks[$aTaskTopic][$aTopicItemsIndex]);
								break(2);
							}
					
					if($aProcStatus['exitcode'] != 0)
					{
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: "9$aItemStatus");
						continue;
					}

					switch($aItemStatus)
					{
						case QUMA_STATUS_SCAN:
							//Scanning done... continue
							if(isset($aQueueItem['loudnorm_scan']))
							{
								//Figure out if loudnorm scans are still missing...
								foreach($aQueueItem['settings']['loudnorm'] as $aLNKey => $aLNSettingName)
									if($aLNSettingName != 0 && !isset($aQueueItem['loudnorm_scan'][$aLNKey]))
									{
										changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_SCAN_WAITING);
										continue(3);
									}

								changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_CONVERT_READY);
							}
							else
								changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_SCAN_ERROR);
							continue(2);
						break;
						case QUMA_STATUS_SCAN_CHILD:
							//Scanning of child done... continue
							if(isset($aQueueItem['loudnorm_scan']))
								deleteQueueItem(itemID: $aQueueItem['id']);
							else
								changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_SCAN_ERROR);
							continue(2);
						break;
						case QUMA_STATUS_CONVERT:
							//Conversion done...
							$aQueueItem['result'] = array(
								'fileSize' =>	filesize($aQueueItem['settings']['outfolder'] . $aQueueItem['settings']['outfile']),
								);
							changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_CONVERT_DONE);
							continue(2);
						break;
						case QUMA_STATUS_UNRAR:
							//Extracting done...
								changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: QUMA_STATUS_UNRAR_DONE);
							continue(2);
						break;
					}
				}
			}
		unset($aQueueItem);
		
		
		sleep(1);
	}
?>