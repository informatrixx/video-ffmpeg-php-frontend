var gEventSource;

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
		
		if(aDataKeys[i] == 'id')
			continue;
		if(aDataKeys[i] == 'time')
			aTime = aData[aDataKeys[i]];
		if(aItemContainer.getElementsByClassName(aDataKeys[i]).length == 0)
		{
			var aDataRow = document.createElement('div');
			aDataRow.setAttribute('class', aDataKeys[i]);
			aItemContainer.appendChild(aDataRow);
		}
		else
			var aDataRow = aItemContainer.getElementsByClassName(aDataKeys[i])[0];
		
		if(aData[aDataKeys[i]] != null)
			aDataRow.innerHTML = '<div class="label">' + aLabelText + '</div><div class="data">' + aData[aDataKeys[i]] + '</div>';
	}
	if(aItemContainer.getElementsByClassName('progress').length > 0 && aTime != '')
	{
		const aTimeRegEx = /^(\d+):(\d+):(\d+\.\d+)$/;
		let aTimeMatches = aTime.match(aTimeRegEx);
		let aHours = aTimeMatches[1];
		let aMinutes = aTimeMatches[2];
		let aSeconds = aTimeMatches[3];
		let aTimeSeconds = (aHours * 3600) + (aMinutes * 60) + (aSeconds * 1);
		let aDuration = aItemContainer.getElementsByClassName('progress')[0].getAttribute('duration');
		let aPercent = Math.round(aTimeSeconds / aDuration * 1000) / 10;
		aItemContainer.getElementsByClassName('progress')[0].innerHTML = '<div class="label">Progress:</div><div class="data">' + aPercent + ' %</div>';
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
