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

function statusSetData(aContainer, aDataClass, aDataValue)
{
	let aDataRow = aContainer.getElementsByClassName(aDataClass);
	if(aDataRow.length == 0)
	{
		aDataRow = document.createElement('div');
		aDataRow.setAttribute('class', aDataClass);
		aContainer.appendChild(aDataRow);
	}
	else
		aDataRow = aDataRow[0];
	
	let aLabelText = aDataClass.charAt(0).toUpperCase() + aDataClass.slice(1);
	
	if(aDataValue != null)
		aDataRow.innerHTML = '<div class="label">' + aLabelText + '</div><div class="data">' + aDataValue + '</div>';
}

function displayProgress(aProgressData)
{
	let aData = JSON.parse(aProgressData);
	let aStatusContainer = document.getElementById('statusContainer');
	
	let aItemContainer = document.getElementById(aData.id);
	if(aItemContainer === null)
	{
		aItemContainer = document.createElement('div');
		aItemContainer.setAttribute('id', aData.id);
		aItemContainer.setAttribute('class', 'statusItem');
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
		let aPercent = Math.round(aTimeSeconds / aDuration * 1000) / 10;
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
			break;
		case 1: aStatusText = 'readyToScan'; break;
		case 2: aStatusText = 'scanning'; break;
		case 3: aStatusText = 'readyToConvert'; break;
		case 4: aStatusText = 'converting'; break;
		case 5:
		case 15:
			aStatusText = 'done'; 
			break;
		case 11: aStatusText = 'readyToExtract'; break;
		case 12: aStatusText = 'extracting'; break;
		case 90:
		case 91:
		case 92:
		case 93:
		case 94:
		case 95:
			aStatusText = 'error'; 
			break;
		case 99: aStatusText = 'abort'; break;
	}

	
	if(aItemContainer === null)
	{
		aItemContainer = document.createElement('div');
		aItemContainer.setAttribute('id', aData.id);
		aItemContainer.setAttribute('class', 'statusItem ' + aStatusText);
		aItemContainer.innerHTML = '<div class="infile"><div class="label">Infile:</div><div class="data">' + aData.infile + '</div></div>';
		if(aData.outfile != null)
			aItemContainer.innerHTML += '<div class="outfile"><div class="label">Outfile:</div><div class="data">' + aData.outfile + '</div></div>';
		aStatusContainer.appendChild(aItemContainer);
	}
	else
		aItemContainer.setAttribute('class', 'statusItem ' + aStatusText);
	
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

