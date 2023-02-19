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
		case 'retry':
			aImage = 'retry1-16.png';
			break;
		default:
			return false;
	}
	aTargetAction.innerHTML = '<button onclick="queueItemAction(\'' + aID + '\', \'' + aAction + '\', this)"><img src="img/' + aImage + '" alt="' + aAction + '"/></button>'
	
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
						aData[aDataKeys[i]] = aInFiles[0];
						
						aData[aDataKeys[i]] += '<more>';
						for(const aInFile of aInFiles)
							aData[aDataKeys[i]] += '<file>' + aInFile + '</file>';
						aData[aDataKeys[i]] += '</more>';
					}
					else
						aData[aDataKeys[i]] = aData[aDataKeys[i]][0];
				}
				statusSetData(aItemContainer, aDataKeys[i], aData[aDataKeys[i]]);
				break;
			case 'size':
				if(aData[aDataKeys[i]] == null)
					continue;
				statusSetData(aItemContainer, aDataKeys[i], aData[aDataKeys[i]].human);
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
	
	if(aItemContainer === null)
	{
		aItemContainer = document.createElement('statusItemContainer');
		aItemContainer.setAttribute('id', aData.id);
		aItemContainer.setAttribute('status', aData.status);
		aItemContainer.innerHTML = '<label>Infile:</label><data dataID="infile"><span>' + aData.infile + '</span></data>';
		if(aData.outfile != null)
			aItemContainer.innerHTML += '<label>Outfile:</label><data dataID="outfile">' + aData.outfile + '</data>';
		aItemContainer.innerHTML += '<label>Action:</label><actions></actions>'
		aStatusContainer.appendChild(aItemContainer);
	}
	else
		aItemContainer.setAttribute('status', aData.status);

	
	switch(parseInt(aData.status, 10))
	{
		case 0:
		case 10:
			statusAddAction(aItemContainer, 'delete');
			break;
		case 1: 
			statusAddAction(aItemContainer, 'delete');
			break;
		case 2:
			statusAddAction(aItemContainer, 'pause');
			statusRemoveAction(aItemContainer, 'delete');
			break;
		case 3:
			statusAddAction(aItemContainer, 'delete');
			statusRemoveAction(aItemContainer, 'pause');
			statusRemoveData(aItemContainer, 'progress');
			statusRemoveData(aItemContainer, 'speed');
			break;
		case 4: 
			statusAddAction(aItemContainer, 'pause');
			statusRemoveAction(aItemContainer, 'delete');
			break;
		case 5:
		case 15:
			statusRemoveData(aItemContainer, 'progress');
			statusAddAction(aItemContainer, 'delete');
			break;
		case 11:
			statusAddAction(aItemContainer, 'delete');
			break;
		case 12:
			break;
		case 91: 
		case 93: 
			statusAddAction(aItemContainer, 'resume');
			break;
		case 82:
		case 84:
		case 92:
		case 94:
		case 991:
			statusRemoveAction(aItemContainer, 'pause');
			statusAddAction(aItemContainer, 'delete');
			statusAddAction(aItemContainer, 'retry');
			break;
	}
}

function queueItemAction(aID, aAction, aObject)
{
	let aItemContainer = document.getElementById(aID);
	let aActionElements = aItemContainer.getElementsByTagName('actions');
	
	for(let i = 0; i < aActionElements[0].length; i++)
		aActionElements[0][i].disabled = true;
	
	fetch('query/queueitemaction.php?id=' + aID + '&action=' + aAction, 
			{
				method: 'GET',
				credentials: 'include',
				mode: 'no-cors',
			})
		.then((aResponse) => { return aResponse.json();})
		.then((aData) => {
				if(aData.success)
					queueItemActionResult(aData, aID, aAction);
			})
}

function queueItemActionResult(aData, aID, aAction)
{
	let aStatusItemContainer = document.getElementById(aID);
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

