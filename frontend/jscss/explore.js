function listFolderQuery(aFolder, aUpdateHistory = true)
{
	let newQuery = new XMLHttpRequest();
	let aUpdateHistoryString = '';
	if(aUpdateHistory == true)
		aUpdateHistoryString = '&history=1';
	newQuery.addEventListener("load", listFolderResult);
	newQuery.open("GET", "query/listfolder.php?folder=" + aFolder + aUpdateHistoryString);
	newQuery.send();
}

function listFolderResult()
{
	var aJSONData = JSON.parse(this.responseText);
	if(aJSONData.success == false)
		return false;
	
	if(aJSONData.history == true)
	{
		const aNewUrl = new URL(window.location);
		aNewUrl.searchParams.set('folder', aJSONData.folder);
		window.history.pushState({folder: aJSONData.folder}, '', aNewUrl);
	}

	let aFolderName = aJSONData.folder;
	if(aFolderName == '')
		aFolderName = 'HOME';
	document.title = 'FFMPEG - ' + aFolderName;

	let aExploreBox = document.getElementsByTagName('explore')[0];
	
	aExploreBox.innerHTML = '';
	
	
	
	for(aScanFolderName in aJSONData.folders)
	{
		let aFolderPath = aJSONData.folders[aScanFolderName];
		let aNewFolder = document.createElement('folder');
		aNewFolder.innerHTML = '<a href="javaScript:dummy()" onclick="listFolderQuery(\'' + escapeHTML(aFolderPath) + '\')">' + escapeHTML(aScanFolderName) + '</a>';
		aExploreBox.appendChild(aNewFolder);
	}

	let aScanFileName;
	for(aScanFileName in aJSONData.files)
	{
		let aFilePath = aJSONData.files[aScanFileName];
		let aNewFile = document.createElement('file');
		aNewFile.innerHTML = '<a href="javaScript:dummy()" onclick="listFolder(\'' + escapeHTML(aFilePath) + '\')">' + escapeHTML(aScanFileName) + '</a>';
		aExploreBox.appendChild(aNewFile);
	}
}

function historyEvent(aEvent)
{
	if("folder" in aEvent.state)
		listFolderQuery(aEvent.state.folder, false);
}

function toggleHiddenGroup(aObject)
{
	let aNextNode = aObject.nextSibling;
	while(aNextNode && aNextNode.nodeName.toLowerCase() != 'group')
		aNextNode = aNextNode.nextSibling;
	
	if(aNextNode)
	{
		if(aNextNode.getAttribute('class').match(/hidden/))
			aNextNode.setAttribute('class', aNextNode.getAttribute('class').replace(/hidden/, ''));
		else
			aNextNode.setAttribute('class', aNextNode.getAttribute('class') + ' hidden');
	}
}
