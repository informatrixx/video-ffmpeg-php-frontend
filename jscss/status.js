var gEventSource;

if(!!window.EventSource)
{
    gEventSource = new EventSource('status.php');
}
else
{
    alert('Dein Browser untestützt keine HTML5 Server-Sent Events');
}

gEventSource.addEventListener('open', 
	function(aEvent)
	{
		console.log('Verbindung wurde erfolgreich hergestellt.');
	});

gEventSource.addEventListener('message', 
	function(aEvent)
	{
		console.log('Nachricht: ' + aEvent.data);
	});

gEventSource.addEventListener('time', 
	function(aEvent)
	{
		console.log('Serverzeit: ' + aEvent.data);
	});

gEventSource.addEventListener('error', 
	function(aEvent)
	{
		if (aEvent.readyState === EventSource.CLOSED)
		{
			console.log('Fehler aufgetreten - die Verbindung wurde getrennt.');
		}
	});