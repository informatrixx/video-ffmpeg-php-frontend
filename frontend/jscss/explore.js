function escapeHTML(aText)
{
	var aMap = {
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#039;'
	};
	
	return aText.replace(/[&<>"']/g, function(m) { return aMap[m]; });
}

function exploreFolderQuery(aFolder, aUpdateHistory = true, aModuleJoin = false)
{
	let newQuery = new XMLHttpRequest();
	let aUpdateHistoryString = '';
	let aModuleJoinString = '';
	if(aUpdateHistory == true)
		aUpdateHistoryString = '&history=1';
	if(aModuleJoin != false)
		aModuleJoinString = '&join=' + aModuleJoin;
	newQuery.addEventListener("load", exploreFolderResult);
	newQuery.open("GET", "query/explore.php?folder=" + aFolder + aUpdateHistoryString + aModuleJoinString);
	newQuery.send();
}

function exploreFolderResult()
{
	var aJSONData = JSON.parse(this.responseText);
	if(aJSONData.success == false)
		return false;
	
	const aNewUrl = new URL(window.location);
	aNewUrl.searchParams.set('folder', aJSONData.folder);
	if(aJSONData.history == true)
		window.history.pushState({folder: aJSONData.folder}, '', aNewUrl);
	else
		window.history.replaceState({folder: aJSONData.folder}, '', aNewUrl);

	let aFolderName = aJSONData.folder;
	let aDocTitle = aFolderName;
	if(aDocTitle == '')
		aDocTitle = 'HOME';
	document.title = PAGE_TITLE_PREFIX + aDocTitle;

	let aExploreBox = document.getElementsByTagName('explore')[0];
	
	aExploreBox.innerHTML = '';
	
	
	
	for(aScanFolderName in aJSONData.folders)
	{
		let aFolderPath = aJSONData.folders[aScanFolderName];
		let aNewFolderElement = document.createElement('folder');
		aNewFolderElement.innerHTML = '<a href="javaScript:dummy()" onclick="exploreFolderQuery(\'' + escapeHTML(aFolderPath) + '\')">' + escapeHTML(aScanFolderName) + '</a>';
		aExploreBox.appendChild(aNewFolderElement);
	}

	let aScanFileName;
	for(aScanFileName in aJSONData.files)
	{
		let aFilePath = aJSONData.files[aScanFileName].path;
		let aScanModule = aJSONData.files[aScanFileName].scan;
		
		let aNewFileElement = document.createElement('file');
		if(aScanModule != false)
			aNewFileElement.innerHTML = '<a href="scan.php?folder=' + escapeHTML(aFolderName) + '&file=' + escapeHTML(aFilePath) + '&type=' + aScanModule + '">' + escapeHTML(aScanFileName) + '</a>';
		else
			aNewFileElement.innerHTML = escapeHTML(aScanFileName);
		
		if("group" in aJSONData.files[aScanFileName] && aJSONData.files[aScanFileName].group.length > 0)
		{
			let aGroup = "(<a href='javascript:dummy()' onclick='toggleHiddenGroup(this)'>" + (aJSONData.files[aScanFileName].group.length*1 + 1) + " Parts</a>)<group class='hidden'>";
			for(let i = 0; i < aJSONData.files[aScanFileName].group.length; i++)
				aGroup = aGroup + '<file>' + aJSONData.files[aScanFileName].group[i] + '</file>';
			aGroup = aGroup + '</group>';
			aNewFileElement.innerHTML = aNewFileElement.innerHTML + aGroup;
		}
		aExploreBox.appendChild(aNewFileElement);
	}
}

function historyEvent(aEvent)
{
	if(aEvent.state != null && "folder" in aEvent.state)
		exploreFolderQuery(aEvent.state.folder, false);
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
