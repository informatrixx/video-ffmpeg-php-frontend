var gEventSource;
var gStatusItemDurations = new Object();

function dummy(){}

if(!!window.EventSource)
{
    gEventSource = new EventSource('query/statuseventsream.php');
}
else
{
    alert('Dein Browser untestützt keine HTML5 Server-Sent Events');
}

gEventSource.addEventListener('open', 
	function(aEvent)
	{
		console.log('Event stream started...');
	});

gEventSource.addEventListener('message', 
	function(aEvent)
	{
		//console.log('Message: ' + aEvent.data);
	});

gEventSource.addEventListener('info', 
	function(aEvent)
	{
		const aData = JSON.parse(aEvent.data);
		console.log(aData['topic'] + ' => ' + aData['message']);
		
		
		let aItemContainer = document.getElementById(aData.id);
		if(aItemContainer === null)
			return false;
		
		if(aData.topic == 'matroska')
			switch(aData.message)
			{
				case 'Starting new cluster due to timestamp':
					statusAddData(aItemContainer, 'WarningCluster', 'Warning', '<img src="img/warning1-16.png" alt=""> ' + aData.message);
					break;
			}
	});

gEventSource.addEventListener('progress', 
	function(aEvent)
	{
		displayProgress(aEvent.data);
	});

gEventSource.addEventListener('scanProgress', 
	function(aEvent)
	{
		displayScanProgress(aEvent.data);
	});

gEventSource.addEventListener('extract', 
	function(aEvent)	
	{
		displayProgress(aEvent.data);
	});

gEventSource.addEventListener('status', 
	function(aEvent)
	{
		changeStatus(aEvent.data);
	});

gEventSource.addEventListener('error', 
	function(aEvent)
	{
		if (aEvent.readyState === EventSource.CLOSED)
		{
			console.log('Error: Connection closed.');
		}
	});

function statusSetData(aStatusItemID, aDataID, aDataValue, aCaption, aGroup)
{
	const aGroupOrder = ['base', 'full', 'more'];
	
	let aStatusItemContainer = document.getElementById(aStatusItemID);
	if(aStatusItemContainer === null)
	{
		aStatusItemContainer = document.createElement('statusItemContainer');
		aStatusItemContainer.setAttribute('id', aStatusItemID);
		gStatusContainer.appendChild(aStatusItemContainer);
	}	
	
	const aDataElements = aStatusItemContainer.getElementsByTagName('data');
	let aDataElement = null;
	for(let i = 0; i < aDataElements.length; i++)
		if(aDataElements[i].getAttribute('dataID') == aDataID)
		{
			aDataElement = aDataElements[i];
			break;
		}
		
	
	let aCaptionElement;
	
	if(aDataElement == null)
	{
		aCaptionElement = document.createElement('caption');
		aCaptionElement.setAttribute('group', aGroup);
		aDataElement = document.createElement('data');
		aDataElement.setAttribute('dataID', aDataID);
		aDataElement.setAttribute('group', aGroup);
		const aTargetGroupIndex = aGroupOrder.indexOf(aGroup);
		let aInserted = false;
		for(let i = 0; i < aStatusItemContainer.children.length; i++)
			if(aGroupOrder.indexOf(aStatusItemContainer.children[i].getAttribute('group')) > aTargetGroupIndex)
			{
				aInserted = true;
				aStatusItemContainer.insertBefore(aDataElement, aStatusItemContainer.children[i]);
				aStatusItemContainer.insertBefore(aCaptionElement, aStatusItemContainer.children[i]);
				break;
			}
		if(!aInserted)
		{
			aStatusItemContainer.appendChild(aCaptionElement);
			aStatusItemContainer.appendChild(aDataElement);
		}
	}
	else
		aCaptionElement = aDataElement.previousElementSibling;
	
	if(aDataValue != null)
	{
		if(aCaptionElement.innerHTML != aCaption)
			aCaptionElement.innerHTML = aCaption;
		aDataElement.innerHTML = '<span>' + aDataValue + '</span>';
	}		
}

function statusRemoveData(aContainer, aDataID)
{
	let aDataRow = aContainer.getElementsByTagName(aDataID);
	if(aDataRow.length > 0)
		aDataRow[0].parentNode.removeChild(aDataRow[0]);
}

function statusAddAction(aContainer, aAction)
{
	const aStatusItemID = aContainer.getAttribute('id');
	let aActionElements = aContainer.getElementsByTagName('actions');
	let aActionElement = null;
	
	if(aActionElements.length == 0)
	{
		let aCaptionElement = document.createElement('caption');
		aContainer.appendChild(aCaptionElement);
		aActionElement = document.createElement('actions');
		aContainer.appendChild(aActionElement);
	}
	else
		aActionElement = aActionElements[0];
	
	let aTargetActions = aActionElement.getElementsByTagName(aAction);
	let aTargetAction = null;
	
	if(aTargetActions.length == 0)
	{
		aTargetAction = document.createElement(aAction);
		aActionElement.appendChild(aTargetAction);
	}
	else
		aTargetAction = aTargetActions[0];
	
	let aImage;
	
	switch(aAction)
	{
		case 'delete':
			aImage = 'remove1-16.png';
			break;
		case 'hideFull':
			aImage = 'hide1-16.png';
			break;
		case 'infoFull':
			aImage = 'info1-16.png';
			break;
		case 'pause':
			aImage = 'pause1-16.png';
			break;
		case 'resume':
			aImage = 'play1-16.png';
			break;
		case 'retry':
			aImage = 'retry1-16.png';
			break;
		default:
			return false;
	}
	aTargetAction.innerHTML = '<button onclick="queueItemAction(\'' + aStatusItemID + '\', \'' + aAction + '\', this)"><img src="img/' + aImage + '" alt="' + aAction + '"></button>'
	
	for(let i = 0; i < aTargetActions.length; i++)
		aTargetActions[i].disabled = false;
}

function statusRemoveAction(aContainer, aAction)
{
	let aActionElements = aContainer.getElementsByTagName('actions');
	if(aActionElements.length == 0)
		return false;
	
	let aActionElement = aActionElements[0].getElementsByTagName(aAction)[0];

	if(aActionElement == null)
		return false;
	
	
	aActionElement.remove();
	return true;
}



function displayProgress(aProgressData)
{
	const aData = JSON.parse(aProgressData);
	const aStatusItemID = aData.id;
	const aIDRegEx = /^[a-f0-9]+$/i;
	if(aIDRegEx.test(aStatusItemID) == false)
		return false;
	
	let aTime = '';
	const aDataKeys = Object.keys(aData);
	for(let i = 0; i < aDataKeys.length; i++)
	{
		switch(aDataKeys[i])
		{
			case 'id':
			case 'scanning':
			case 'status':
			case 'streamID':
				continue;
			case 'bitrate':
				statusSetData(aStatusItemID, 'bitrate', aData[aDataKeys[i]], '<img src="img/bitrate1-16.png" alt="Bitrate">', 'full');
				break;
			case 'duration':
				if(aData[aDataKeys[i]] == null)
					break;

				let aDuration = '';
				if(typeof aData[aDataKeys[i]] === 'string')
					aDuration = aData[aDataKeys[i]];
				else
					aDuration = aData[aDataKeys[i]][0];
				
				if(aDuration != '')
				{
					gStatusItemDurations[aStatusItemID] = aDuration;
					const aRawDuration = aDuration;
					const aSeekLeft = Math.round(aRawDuration, 0) % 3600;
					
					const aDurationValue =	Math.floor(aRawDuration / 3600).toString().padStart(2, '0') + ':' + 
											Math.floor(aSeekLeft / 60).toString().padStart(2, '0') + ':' +
											Math.floor(aSeekLeft % 60).toString().padStart(2, '0');
					statusSetData(aStatusItemID, 'duration', aDurationValue, '<img src="img/duration1-16.png" alt="Duration">', 'full');
				}
				break;
			case 'fps':
			case 'frame':
				if(aData['frame'] != null && aData['fps'] != null)
					statusSetData(aStatusItemID, 'frame', 'Frame ' + aData['frame'] + ' (' + aData['fps'] + ' fps)', '<img src="img/frame1-16.png" alt="FPS">', 'full');
				break;
			case 'infile':
				let aCaption = 'Source';
				let aFilesValue = aData[aDataKeys[i]];
				let aShortValue;
				if(typeof aData[aDataKeys[i]] !== 'string')
				{
					aCaption = 'Source Files';
					aShortValue = aData[aDataKeys[i]][0].split(/[\\/]/).pop();
					if(aData[aDataKeys[i]].length > 1)
					{
						let aInFiles = aData[aDataKeys[i]];
						aFilesValue = aInFiles[0];
						
						aFilesValue += '<more>';
						for(const aInFile of aInFiles)
							aFilesValue += '<file>' + aInFile + '</file>';
						aFilesValue += '</more>';
						
						aShortValue += ' +' + (aData[aDataKeys[i]].length - 1);
					}
					else
						aFilesValue = aData[aDataKeys[i]][0];
				}
					
				statusSetData(aStatusItemID, 'infile', aFilesValue, aCaption, 'more');
				statusSetData(aStatusItemID, 'infileShort', aShortValue, '<img src="img/input1-16.png" alt="Source">', 'base');
				break;
			case 'outfile':
				statusSetData(aStatusItemID, 'outfile', aData[aDataKeys[i]], '<img src="img/save1-16.png" alt="Output">', 'full');
				break;
			case 'q':
				statusSetData(aStatusItemID, 'q', 'Q ' + aData[aDataKeys[i]], '<img src="img/quality1-16.png" alt="Output">', 'full');
				break;
			case 'size':
				if(aData[aDataKeys[i]] == null)
					continue;
				statusSetData(aStatusItemID, 'size', aData[aDataKeys[i]].human, '<img src="img/size1-16.png" alt="Size">', 'full');
				break;
			case 'speed':
				statusSetData(aStatusItemID, 'speed', aData[aDataKeys[i]], '<img src="img/speed1-16.png" alt="Speed">', 'full');
				break;
			case 'time':
				aTime = aData[aDataKeys[i]];
				break;
			default:
				statusSetData(aStatusItemID, aDataKeys[i], aData[aDataKeys[i]], aDataKeys[i].charAt(0).toUpperCase() + aDataKeys[i].slice(1), 'full');
				break;
		}
		
	}
	if(gStatusItemDurations[aStatusItemID] != null && aTime != '')
	{
		const aTimeRegEx = /^(\d+):(\d+):(\d+\.\d+)$/;
		const aTimeMatches = aTime.match(aTimeRegEx);
		const aHours = aTimeMatches[1];
		const aMinutes = aTimeMatches[2];
		const aSeconds = aTimeMatches[3];
		const aTimeSeconds = (aHours * 3600) + (aMinutes * 60) + (aSeconds * 1);
		const aPercent = Math.round(aTimeSeconds / gStatusItemDurations[aStatusItemID] * 100);
		statusSetData(aStatusItemID, 'progress', aPercent + ' %', '<img src="img/progress1-16.png" alt="Progress">', 'base');
	}
}

function displayScanProgress(aProgressData)
{
	let aData = JSON.parse(aProgressData);
	const aIDRegEx = /^[a-f0-9]+$/i; 
	if(aIDRegEx.test(aData.id) == false)
		return false;
	
	const aTime = aData['time'];
	let aSpeedString = '';
	if(aData['speed'] != null)
		aSpeedString = ' (' + aData['speed'] + ')';
	const aStreamID = aData['streamID'];
	
	if(gStatusItemDurations[aStatusItemID] != null && aTime != '')
	{
		const aTimeRegEx = /^(\d+):(\d+):(\d+\.\d+)$/;
		const aTimeMatches = aTime.match(aTimeRegEx);
		const aHours = aTimeMatches[1];
		const aMinutes = aTimeMatches[2];
		const aSeconds = aTimeMatches[3];
		const aTimeSeconds = (aHours * 3600) + (aMinutes * 60) + (aSeconds * 1);
		const aPercent = Math.round(aTimeSeconds / gStatusItemDurations[aStatusItemID] * 100);
		statusSetData(aStatusItemID, 'scanProgress' + aStreamID, aPercent + ' %' + aSpeedString, '<img src="img/progress1-16.png" alt="Progress"> ' + aStreamID, 'base');
	}
}

function changeStatus(aStatusData)
{
	let aData = JSON.parse(aStatusData);
	const aStatusItemID = aData.id;
	const aIDRegEx = /^[a-f0-9]+$/i; 
	if(aIDRegEx.test(aStatusItemID) == false)
		return false;

	displayProgress(aStatusData);
	
	const aStatusItemContainer = document.getElementById(aStatusItemID);
	aStatusItemContainer.setAttribute('status', QUMA_CODE_STATUS[aData.status]);
	
	
	statusAddAction(aStatusItemContainer, 'infoFull');
	
	switch(parseInt(aData.status, 10))
	{
		case QUMA_STATUS_WAITING:
		case QUMA_STATUS_UNRAR_WAITING:
			statusAddAction(aStatusItemContainer, 'delete');
			break;
		case QUMA_STATUS_SCAN_READY: 
			statusAddAction(aStatusItemContainer, 'delete');
			break;
		case QUMA_STATUS_SCAN:
			statusAddAction(aStatusItemContainer, 'pause');
			statusRemoveAction(aStatusItemContainer, 'delete');
			break;
		case QUMA_STATUS_CONVERT_READY:
			statusAddAction(aStatusItemContainer, 'delete');
			statusRemoveAction(aStatusItemContainer, 'pause');
			statusRemoveData(aStatusItemContainer, 'progress');
			statusRemoveData(aStatusItemContainer, 'speed');
			break;
		case QUMA_STATUS_CONVERT: 
			statusAddAction(aStatusItemContainer, 'pause');
			statusRemoveAction(aStatusItemContainer, 'delete');
			break;
		case QUMA_STATUS_CONVERT_DONE:
		case QUMA_STATUS_UNRAR_DONE:
			statusRemoveData(aStatusItemContainer, 'progress');
			statusAddAction(aStatusItemContainer, 'delete');
			statusRemoveAction(aStatusItemContainer, 'pause');
			break;
		case QUMA_STATUS_UNRAR_READY:
			statusAddAction(aStatusItemContainer, 'delete');
			break;
		case QUMA_STATUS_UNRAR:
			break;
		case QUMA_STATUS_SCAN_PAUSE: 
		case QUMA_STATUS_CONVERT_PAUSE: 
			statusAddAction(aStatusItemContainer, 'resume');
			break;
		case QUMA_STATUS_SCAN_ABORT:
		case QUMA_STATUS_CONVERT_ABORT:
		case QUMA_STATUS_SCAN_ERROR:
		case QUMA_STATUS_CONVERT_ERROR:
		case 991:
			statusRemoveAction(aStatusItemContainer, 'pause');
			statusAddAction(aStatusItemContainer, 'delete');
			statusAddAction(aStatusItemContainer, 'retry');
			break;
	}
}

function queueItemAction(aStatusItemID, aAction, aObject)
{
	let aStatusItemContainer = document.getElementById(aStatusItemID);
	let aActionElements = aStatusItemContainer.getElementsByTagName('actions');
	
	if(aAction == 'infoFull')
	{
		for(let i = 0; i < aStatusItemContainer.children.length; i++)
			if(aStatusItemContainer.children[i].getAttribute('group') == 'full')
				aStatusItemContainer.children[i].style.display = 'block';
		statusRemoveAction(aStatusItemContainer, 'infoFull');
		statusAddAction(aStatusItemContainer, 'hideFull');
	}
	if(aAction == 'hideFull')
	{
		for(let i = 0; i < aStatusItemContainer.children.length; i++)
			if(aStatusItemContainer.children[i].getAttribute('group') == 'full')
				aStatusItemContainer.children[i].style.display = 'none';
		statusAddAction(aStatusItemContainer, 'infoFull');
		statusRemoveAction(aStatusItemContainer, 'hideFull');
	}
	
	for(let i = 0; i < aActionElements[0].length; i++)
		aActionElements[0][i].disabled = true;
	
	fetch('query/queueitemaction.php?id=' + aStatusItemID + '&action=' + aAction, 
			{
				method: 'GET',
				credentials: 'include',
				mode: 'no-cors',
			})
		.then((aResponse) => { return aResponse.json();})
		.then((aData) => {
				if(aData.success)
					queueItemActionResult(aData, aStatusItemID, aAction);
			})
}

function queueItemActionResult(aData, aStatusItemID, aAction)
{
	let aStatusItemContainer = document.getElementById(aStatusItemID);
	switch(aAction)
	{
		case 'delete':
			aStatusItemContainer.remove();
			break;
		case 'pause':
			statusRemoveAction(aStatusItemContainer, 'pause');
			statusAddAction(aStatusItemContainer, 'resume');
			break;
		case 'resume':
			statusRemoveAction(aStatusItemContainer, 'resume');
			statusAddAction(aStatusItemContainer, 'pause');
			break;
		case 'retry':
			statusRemoveAction(aStatusItemContainer, 'retry');
			statusAddAction(aStatusItemContainer, 'delete');
			break;
	}
}

function toggleHiddenGroup(aObject)
{
	let aGroupNode = aObject.parentNode;
	while(aGroupNode && aGroupNode.nodeName.toLowerCase() != 'group')
		aGroupNode = aGroupNode.parentNode;
	
	if(aGroupNode == null)
		return false;
	
	let aExpand = aGroupNode.getAttribute('content') != 'expanded';

	if(aExpand)
		aGroupNode.setAttribute('content', 'expanded');
	else
		aGroupNode.setAttribute('content', 'condensed');
	
	
	for(const aChildNode of aGroupNode.children)
		if(aExpand)
			aChildNode.removeAttribute('hidden');
		else
			if(aChildNode.getAttribute('alwaysvisible') == null)
				aChildNode.setAttribute('hidden', 'hidden');
}

