var gEventSource;

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

gEventSource.addEventListener('progress', 
	function(aEvent)
	{
		displayProgress(aEvent.data);
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

function statusSetData(aContainer, aDataID, aDataValue)
{
	const aDataElements = aContainer.getElementsByTagName('data');
	let aDataElement = null;
	for(let i = 0; i < aDataElements.length; i++)
		if(aDataElements[i].getAttribute('dataID') == aDataID)
		{
			aDataElement = aDataElements[i];
			break;
		}
		
	let aLabel;
	
	if(aDataElement == null)
	{
		aLabel = document.createElement('label');
		aContainer.appendChild(aLabel);
		aDataElement = document.createElement('data');
		aDataElement.setAttribute('dataID', aDataID);
		aContainer.appendChild(aDataElement);
	}
	else
		aLabel = aDataElement.previousElementSibling;
	
	let aLabelText = aDataID.charAt(0).toUpperCase() + aDataID.slice(1);
	
	if(aDataValue != null)
	{
		aLabel.innerHTML = aLabelText;
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
	const aID = aContainer.getAttribute('id');
	let aActionElements = aContainer.getElementsByTagName('actions');
	let aActionElement = null;
	
	if(aActionElements.length == 0)
	{
		let aLabel = document.createElement('label');
		aLabel.innerHTML = 'Action';
		aContainer.appendChild(aLabel);
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
			aImage = 'broom1-16.png';
			break;
		case 'pause':
			aImage = 'pause1-16.png';
			break;
		case 'resume':
			aImage = 'play1-16.png';
			break;
		default:
			return false;
	}
	aTargetAction.innerHTML = '<button onclick="listItemAction(\'' + aID + '\', \'' + aAction + '\')"><img src="img/' + aImage + '" alt="' + aAction + '"/></button>'
}

function displayProgress(aProgressData)
{
	let aData = JSON.parse(aProgressData);
	let aStatusContainer = document.getElementById('statusContainer');
	
	let aItemContainer = document.getElementById(aData.id);
	if(aItemContainer === null)
	{
		aItemContainer = document.createElement('statusItemContainer');
		aItemContainer.setAttribute('id', aData.id);
		aStatusContainer.appendChild(aItemContainer);
	}
	
	let aTime = '';
	let aDataKeys = Object.keys(aData);
	for(let i = 0; i < aDataKeys.length; i++)
	{
		let aLabelText = aDataKeys[i][0].toUpperCase() + aDataKeys[i].substring(1) + ':';
		
		switch(aDataKeys[i])
		{
			case 'id':
				continue;
			case 'time':
				aTime = aData[aDataKeys[i]];
				break;
			case 'infile':
				if(typeof aData[aDataKeys[i]] !== 'string')
				{
					if(aData[aDataKeys[i]].length > 1)
					{
						let aInFiles = aData[aDataKeys[i]];
						aData[aDataKeys[i]] = '<group content="condensed"><file alwaysvisible>' + aInFiles[0] + '(<a href="javascript:dummy()" onclick="toggleHiddenGroup(this)">+' + (aInFiles.length - 1) + ' more</a>)</file>';
						
						for(const aInFile of aInFiles)
							aData[aDataKeys[i]] += '<file hidden>' + aInFile + '</file>';
						
						aData[aDataKeys[i]] += '</group>';
					}
					else
						aData[aDataKeys[i]] = aData[aDataKeys[i]][0];
				}
				statusSetData(aItemContainer, aDataKeys[i], aData[aDataKeys[i]]);
				break;
			case 'duration':
				if(aData[aDataKeys[i]] == null)
					break;

				let aDuration = '';
				if(typeof aData[aDataKeys[i]] === 'string')
					aDuration = aData[aDataKeys[i]];
				else
					aDuration = aData[aDataKeys[i]][0];
				
				aItemContainer.setAttribute('duration', aDuration);
				if(aDuration != '')
				{
					let aRawDuration = aDuration;
					let aSeekLeft = Math.round(aRawDuration, 0) % 3600;
					
					aData[aDataKeys[i]] =	Math.floor(aRawDuration / 3600).toString().padStart(2, '0') + ':' + 
											Math.floor(aSeekLeft / 60).toString().padStart(2, '0') + ':' +
											Math.floor(aSeekLeft % 60).toString().padStart(2, '0');
				}
				statusSetData(aItemContainer, aDataKeys[i], aData[aDataKeys[i]]);
				break;
			default:
				statusSetData(aItemContainer, aDataKeys[i], aData[aDataKeys[i]]);
				break;
		}
		
	}
	if(aItemContainer.getAttribute('duration') != null && aTime != '')
	{
		const aTimeRegEx = /^(\d+):(\d+):(\d+\.\d+)$/;
		let aTimeMatches = aTime.match(aTimeRegEx);
		let aHours = aTimeMatches[1];
		let aMinutes = aTimeMatches[2];
		let aSeconds = aTimeMatches[3];
		let aTimeSeconds = (aHours * 3600) + (aMinutes * 60) + (aSeconds * 1);
		let aDuration = aItemContainer.getAttribute('duration');
		let aPercent = Math.round(aTimeSeconds / aDuration * 100);
		statusSetData(aItemContainer, 'progress', aPercent + ' %');
	}
}

function changeStatus(aStatusData)
{
	let aData = JSON.parse(aStatusData);
	let aStatusContainer = document.getElementById('statusContainer');
	
	let aItemContainer = document.getElementById(aData.id);
	
	let aStatusText;
	
	switch(aData.status)
	{
		case 0:
		case 10:
			aStatusText = 'waiting'; 
			statusAddAction(aItemContainer, 'delete');
			break;
		case 1: 
			aStatusText = 'readyToScan';
			statusAddAction(aItemContainer, 'delete');
			break;
		case 2:
			aStatusText = 'scanning';
			statusAddAction(aItemContainer, 'pause');
			break;
		case 3:
			aStatusText = 'readyToConvert';
			statusAddAction(aItemContainer, 'delete');
			break;
		case 4: 
			aStatusText = 'converting';
			statusAddAction(aItemContainer, 'pause');
			break;
		case 5:
		case 15:
			aStatusText = 'done'; 
			statusRemoveData(aItemContainer, 'progress');
			statusAddAction(aItemContainer, 'delete');
			break;
		case 11:
			aStatusText = 'readyToExtract';
			statusAddAction(aItemContainer, 'delete');
			break;
		case 12: aStatusText = 'extracting'; break;
		case 82: 
		case 84: 
			aStatusText = 'suspended';
			statusAddAction(aItemContainer, 'resume');
			break;
		case 90:
		case 91:
		case 92:
		case 93:
		case 94:
		case 95:
			aStatusText = 'error'; 
			statusAddAction(aItemContainer, 'delete');
			break;
		case 99: 
			aStatusText = 'abort';
			statusAddAction(aItemContainer, 'delete');
			break;
	}

	
	if(aItemContainer === null)
	{
		aItemContainer = document.createElement('statusItemContainer');
		aItemContainer.setAttribute('id', aData.id);
		aItemContainer.setAttribute('class', aStatusText);
		aItemContainer.innerHTML = '<label>Infile:</label><data dataID="infile">' + aData.infile + '</data>';
		if(aData.outfile != null)
			aItemContainer.innerHTML += '<label>Outfile:</label><data dataID="outfile">' + aData.outfile + '</data>';
		aItemContainer.innerHTML += '<label>Action:</label><actions></actions>'
		aStatusContainer.appendChild(aItemContainer);
	}
	else
		aItemContainer.setAttribute('class', aStatusText);
	
}

function listItemAction(aID, aAction)
{
	fetch('query/queueitemaction.php?id=' + aID + '&action=' + aAction, 
			{
				method: 'GET',
				credentials: 'include',
				mode: 'no-cors',
			})
		.then((aResponse) => { return aResponse.json();})
		.then((aData) => { 
				console.log(aData);
				if(aData.success)
					location.reload();
			})
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

