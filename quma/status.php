<?php
	header('Content-Type: text/event-stream');
	header('Cache-Control: no-store, no-cache, must-revalidate');
	header('Connection: keep-alive');
	header('X-Accel-Buffering: no');

	set_time_limit(0);
	error_reporting(0);
	ini_set('error_reporting', false);

	$aID = 0;
	$aInit = true;
	
	$aSHMKey = ftok(filename: realpath('../config/static_config.json'), project_id: 'a');
	while(!$aSHMConnected)
	{
		$aSHMId = shmop_open(key: $aSHMKey, mode: "a", permissions: 0, size: 0);
		$aSHMConnected = $aSHMId !== false;
		if(!$aSHMConnected)
		{
			sleep(1);
			if($aInit)
			{
				$aID++;
				$aInit = false;
				echo "id: $aID\n" .
					"event: process\n" .
					"data: Idle (Not connceted)\n\n";
				ob_flush();
				flush();
			}
		}
		else
		{
			$aID++;
			echo "id: $aID\n" .
				"event: process\n" .
				"data: Running (Connected)\n\n";
			ob_flush();
			flush();
		}
	}
	
	$aOldData = "";
	$aData = "";
	
	while(true)
	{
		$aMessage = rtrim(string: shmop_read(shmop: $aSHMId, offset: 0, size: 0), characters: "\0");
		$aDataMessage = json_decode(json: $aMessage, associative: true);
		
		if(is_array($aDataMessage))
		{
			$aType = $aDataMessage['type'];
			$aData = json_encode(value: $aDataMessage['data']);
			
			if($aData != $aOldData)
			{
				echo "id: " . $aID++ . "\n";
				echo "event: $aType\n";
				echo "data: $aData\n\n";
				$aOldData = $aData;
			}
		}
		else
			echo "id: " . $aID++ . "\n" .
				"event: unknown\n" .
				"data: $aMessage\n\n";
		ob_flush();
		flush();
		sleep(1);
	}
?>