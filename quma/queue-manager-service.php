<?php

	if (php_sapi_name() != "cli")
	{
		header("HTTP/1.1 418 I'm a teapot");
		die('This script is a standalone CLI script...');
	}


	define(constant_name: 'SCRIPT_DIR', value: rtrim(string: __DIR__, characters: '/') . '/');
	define(constant_name: 'RUN_DIR', value: rtrim(string: realpath(SCRIPT_DIR . '../run'), characters: '/') . '/');
	
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
				$aQueueItem['status'] = 99;
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
			_msg(message: 'Error writing queue file to disk: ' . QUEUE_FILE, toSTDERROR: true);
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
		
		_msg(message: 'Adding queue item: ' . $newItem['settings']['outfile'], CRF: '');
		
        //Check for duplicate item
        foreach($gConvertQueue as $aQueueItem)
        {
        	if($newItem['settings']['outfolder'] == $aQueueItem['settings']['outfolder'] && $newItem['settings']['outfile'] == $aQueueItem['settings']['outfile'])
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
			case 94:
				_msg(message: 'Change queue item status to "Error converting (94)": ' . $gConvertQueue[$queueItemIndex]['settings']['outfile']);
				break;
			case 99:
				_msg(message: 'Change queue item status to "Abort (99)": ' . $gConvertQueue[$queueItemIndex]['settings']['outfile']);
				break;
		}
	}
	
	
	
	
	/*	SCRIPT BODY	*/
	
	define(constant_name: 'CONFIG', value: json_decode(json: file_get_contents(SCRIPT_DIR . '../config.json'), associative: true));
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(SCRIPT_DIR . '../config/static_config.json'), associative: true));

	define(constant_name: 'MY_ID', value: file_get_contents(SCRIPT_DIR . '../config/ID'));

	
	
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
							case 'add_queue_item':
								$aSockMessage = json_encode(value: addQueueItem($aControlMessage['queue_item']));
								$aResponseSocket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
								if(socket_sendto(socket: $aResponseSocket, data: $aSockMessage, length: strlen($aSockMessage), flags: MSG_EOF, address: $aControlMessage['response_sock']) === false)
									_msg(message: 'Socket response send failed: ' . socket_strerror(socket_last_error()), toSTDERR: true);
								if(socket_shutdown(socket: $aResponseSocket) === false)
									_msg(message: 'Response socket shutdown failed: ' . socket_strerror(socket_last_error()), toSTDERR: true);
								if(socket_close(socket: $aResponseSocket) === false)
									_msg(message: 'Response socket closing failed: ' . socket_strerror(socket_last_error()), toSTDERR: true);
							break;
							case 'add_status_socket':
								_msg(message: 'Got status client request...', CRF: '');
								$aNewStatusSocket = socket_create(AF_UNIX, SOCK_STREAM, 0);
								 
								usleep(100);
								if(socket_connect(socket: $aNewStatusSocket, address: $aControlMessage['response_sock']) === false)
									_msg(message: 'Could not connect: ' . socket_strerror(socket_last_error()), toSTDERR: true);
								else
								{
									_msg(message: 'OK', fixedWidth: 6);
									$gStatusSockets[] = $aNewStatusSocket;
								}
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
			foreach($gConvertQueue as $aItemIndex => &$aQueueItem)
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
					
					
					_msg(message: 'Start scanning of: ' . $aQueueItem['settings']['outfile'], CRF: '');
					
					//Preparing I/O pipes
					$aDescriptorSpec = array(
						0 => array('pipe', 'r'), 
						1 => array('pipe', 'w'),
						2 => array('pipe', 'w')
						);
					
					$aQueueItem['proc']['ressource'] = proc_open(command: $aAudioScanString, descriptor_spec: $aDescriptorSpec, pipes: $aQueueItem['proc']['pipes']);
					if(is_resource($aQueueItem['proc']['ressource']))
					{
						_msg(message: 'PID: ' . proc_get_status(process: $aQueueItem['proc']['ressource'])['pid'], fixedWidth: 12);
						stream_set_blocking(stream: $aQueueItem['proc']['pipes'][1], enable: false);
						stream_set_blocking(stream: $aQueueItem['proc']['pipes'][2], enable: false);
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: 2);
					}
					else
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: 92);
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
				
				if($aItemStatus == 3)
				{
					$aConvertString = CONFIG['Binaries']['ffmpeg'] . ' \\' . PHP_EOL;
					$aConvertString .= ' -i ' . escapeshellarg($aItemSettings['infile']) . ' \\' . PHP_EOL;
					
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
												if($aDataValue != 'off' && isset(STATIC_CONFIG['audio']['loudnorm'][$aDataValue]))
												{
													$aLNData = STATIC_CONFIG['audio']['loudnorm'][$aDataValue];
													$aLNScan = $aQueueItem['loudnorm_scan'][$aLoudnormIndex++];
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
													if($aDataValue != 'off')
														$aFilters[10] = 'nlmeans=' . STATIC_CONFIG['video']['nlmeans'][$aDataValue]['value'];
													break;
												case 'crop':
													if($aDataValue == 'auto')
														$aFilters[20] = $aItemSettings['cropstring'];
													break;
												case 'resize':
													if($aDataValue != '0')
														$aFilters[30] = "scale=$aDataValue:-1";
													break;
												case 'default':
												case 'forced':
													$aDisposition[$aKey] = $aDataValue != '0' ? '+' : '-';
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
					//Video codec settings x265
					$aConvertString .= ' -c:v libx265' . ' \\' . PHP_EOL;
					$aConvertString .= ' -x265-params "level-idc=5:deblock=false:sao=false:b-intra=false"' . ' \\' . PHP_EOL;
					//Audio codec fdk AAC
					$aConvertString .= ' -c:a libfdk_aac' . ' \\' . PHP_EOL;
					//Subtitles just copy (if mapped)
					$aConvertString .= ' -c:s copy' . ' \\' . PHP_EOL;
					$aConvertString .= ' -reserve_index_space 100k ' . ' \\' . PHP_EOL;
					$aConvertString .= ' -cues_to_front 1 ' . ' \\' . PHP_EOL;
					//Filetitle and video title
					$aConvertString .= ' -metadata ' . escapeshellarg("title={$aItemSettings['filetitle']}") . ' \\' . PHP_EOL;
					$aConvertString .= ' -metadata:s:v ' . escapeshellarg("title={$aItemSettings['filetitle']}") . ' \\' . PHP_EOL;
	
					$aOutFolder = rtrim(string: $aItemSettings['outfolder'], characters: '/') . '/';
					$aOutFile = $aItemSettings['outfile'];
					$aConvertString .= " " . escapeshellarg("$aOutFolder$aOutFile") . PHP_EOL;
					
					//Add itemID to scan list for identification
					$gQueueTasks['convert'][] = $aItemID;
					
					
					_msg(message: 'Start conversion of: ' . $aQueueItem['settings']['outfile'], CRF: '');
					
					//Preparing I/O pipes
					$aDescriptorSpec = array(
						0 => array('pipe', 'r'), 
						1 => array('pipe', 'w'),
						2 => array('pipe', 'w')
						);
					
					$aQueueItem['proc']['ressource'] = proc_open(command: $aConvertString, descriptor_spec: $aDescriptorSpec, pipes: $aQueueItem['proc']['pipes']);
					if(is_resource($aQueueItem['proc']['ressource']))
					{
						_msg(message: 'PID: ' . proc_get_status(process: $aQueueItem['proc']['ressource'])['pid'], fixedWidth: 12);
						stream_set_blocking(stream: $aQueueItem['proc']['pipes'][1], enable: false);
						stream_set_blocking(stream: $aQueueItem['proc']['pipes'][2], enable: false);
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: 4);
					}
					else
						changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: 94);
				}
				if(count($gQueueTasks['convert']) >= STATIC_CONFIG['queue']['max_convert_tasks'])
					break;
			}
			unset($aQueueItem);
		}
		
		//Check if processes have finished
		foreach($gConvertQueue as &$aQueueItem)
			if(isset($aQueueItem['proc']))
			{
				//Read process output and decide what to keep...
				$aOutput = stream_get_contents(stream: $aQueueItem['proc']['pipes'][2]);
				switch(true)
				{
					case preg_match_all(pattern: '/\[parsed_loudnorm.+\]\s+({[^[]+})/i', subject: $aOutput, matches: $aMatches, flags: PREG_SET_ORDER) > 0:
						_msg(message: "Got Loudnorm scan results for: " . $aQueueItem['settings']['outfile']);
						foreach($aMatches as $aScanIndex => $aLoudnormJSON)
							$aQueueItem['loudnorm_scan'][$aScanIndex] = json_decode(json: $aLoudnormJSON[1], associative: true);
					break;
					case preg_match(pattern: '@size=N/A\s+time=([\d:.]+)\sbitrate=N/A\sspeed=\s*([\d.]+x)@mi', subject: $aOutput, matches: $aMatches) > 0:
						$aStatusArray = array(
							'id'		=> $aQueueItem['id'],
							'outfile'	=> $aQueueItem['settings']['outfile'],
							'time' 		=> $aMatches[1],
							'speed'		=> $aMatches[2],
							);
						statusEcho(topic: 'progress', statusArray: $aStatusArray);
					break;
					case preg_match(pattern: '@frame=\s*([\d.]+)\s+fps=\s*(\d+)\s+q=\s*([\d.]+)\s+size=\s*([\d]+\SB)\s+time=\s*([\d:.]+)\s+bitrate=\s*([\d.]+\Sbits/s)\s+speed=\s*([\d.]+x)@mi', subject: $aOutput, matches: $aMatches) > 0:
						$aStatusArray = array(
							'id'		=> $aQueueItem['id'],
							'outfile'	=> $aQueueItem['settings']['outfile'],
							'frame' 	=> $aMatches[1],
							'fps'		=> $aMatches[2],
							'q'			=> $aMatches[3],
							'size'		=> $aMatches[4],
							'time'		=> $aMatches[5],
							'bitrate'	=> $aMatches[6],
							'speed'		=> $aMatches[7],
							);
						statusEcho(topic: 'progress', statusArray: $aStatusArray);
					break;
					default:
						echo $aOutput;
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
						case 2:
							//Scanning done... continue
							if(isset($aQueueItem['loudnorm_scan']))
								changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: 3);
							else
								changeQueueItemStatus(queueItemIndex: $aItemIndex, newStatus: "9$aItemStatus");
							continue(2);
						break;
					}
				}
			}
		unset($aQueueItem);
		
		
		sleep(1);
	}
?>