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
		console.log('Message: ' + aEvent.data);
	});

gEventSource.addEventListener('progress', 
	function(aEvent)
	{
		displayProgress(aEvent.data);
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
	let aDataTable = document.getElementById('dataTable');
	
	let aTargetTable = document.getElementById(aData.id);
	if(aTargetTable === null)
	{
		aTargetTable = document.createElement('table');
		aTargetTable.setAttribute('id', aData.id);
		aDataTable.appendChild(aTargetTable);
	}
	
	let aDataKeys = Object.keys(aData);
	for(let i = 0; i < aDataKeys.length; i++)
	{
		if(aDataKeys[i] == 'id')
			continue;
		if(aTargetTable.getElementsByClassName(aDataKeys[i]).length == 0)
		{
			var aTargetTR = document.createElement('tr');
			aTargetTR.setAttribute('class', aDataKeys[i]);
			aTargetTable.appendChild(aTargetTR);
		}
		else
			var aTargetTR = aTargetTable.getElementsByClassName(aDataKeys[i])[0];

		aTargetTR.innerHTML = '<td>' + aDataKeys[i] + '</td><td>' + aData[aDataKeys[i]] + '</td>';
	}
}