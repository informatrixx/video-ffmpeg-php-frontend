function escapeHTML(aText)
{
	var aMap = {
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#039;'
	};
	
	try {
		return aText.replace(/[&<>"']/g, function(m) { return aMap[m]; });
	}
	catch (error) {
	  console.error(error);
	  alert(typeof aText);
	  alert(aText);
	}
}

function escapeQuotes(aText)
{
	var aMap = {
		'"': '\\x22',
		"'": '\\x27'
	};
	
	return aText.replace(/[&<>"']/g, function(m) { return aMap[m]; });
}

function explorerScrollTo(aID)
{
	aID = aID.toLowerCase();

	const aTarget = document.getElementById('explore_' + aID);
	if(aTarget == null)
		return false;
	
	const aTop = document.getElementById('explore_' + aID).offsetTop;
	window.scrollTo(0, aTop);
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
	
	
	let aHistoryParam = aJSONData.history;
	let aJoinParam = '';
	if(aJSONData.join != false)
		aJoinParam = escapeHTML(aJSONData.join);
	
	let aAlphaChar = '';
	
	for(aScanFolderName in aJSONData.folders)
	{
		let aFolderPath = aJSONData.folders[aScanFolderName];
		
		let aAnchorHash = '';
		if(aAlphaChar != aScanFolderName.charAt(0).toLowerCase())
		{
			aAlphaChar = aScanFolderName.charAt(0).toLowerCase();
			aAnchorHash = 'id="explore_' + aAlphaChar + '"';
		}
		
		let aNewFolderElement = document.createElement('folder');
		if(aScanFolderName == '..')
		{
			aNewFolderElement.setAttribute('back', 'back');
			aAnchorHash = 'id="explore_top"';
		}			
		if(aScanFolderName == '.')
		{
			aNewFolderElement.setAttribute('root', 'root');
			aScanFolderName = '..';
			aAnchorHash = '';
		}
		aNewFolderElement.innerHTML = '<span><a href="javascript:dummy()" onclick="exploreFolderQuery(\'' + escapeQuotes(aFolderPath) + '\', ' + aHistoryParam + ', \'' + escapeQuotes(aJoinParam) + '\')" ' + aAnchorHash + '>' + escapeHTML(aScanFolderName) + '</a></span>';
		aExploreBox.appendChild(aNewFolderElement);
	}

	let aScanFileName;
	for(aScanFileName in aJSONData.files)
	{
		let aFilePath = aJSONData.files[aScanFileName].path;
		let aScanModule = aJSONData.files[aScanFileName].scan;
		let aJoin = aJSONData.files[aScanFileName].join;
		
		let aNewFileElement = document.createElement('file');
		let aJoinLink = '';
		if(aJoin == true)
			aJoinLink = "<img src='img/add1-16.png' class='join' onclick='joinFile(\"" + escapeQuotes(aFilePath) + "\")'>";
		
		let aContent = '';
		
		if(aScanModule != false)
			aContent = '<span>' + aJoinLink + '<a href="scan.php?folder=' + escapeQuotes(aFolderName) + '&file=' + escapeQuotes(aFilePath) + '&type=' + aScanModule + '">' + escapeHTML(aScanFileName) + '</a>';
		else
			aContent = '<span>' + aJoinLink + escapeHTML(aScanFileName);
		
		if("group" in aJSONData.files[aScanFileName] && aJSONData.files[aScanFileName].group.length > 0)
		{
			let aGroup = " (<a class='info' href='javascript:dummy()' onmousedown='toggleHiddenGroup(this)' onmouseout='toggleHiddenGroup(this, true)'>" + (aJSONData.files[aScanFileName].group.length*1 + 1) + " Parts</a>)<group class='hidden'>";
			for(let i = 0; i < aJSONData.files[aScanFileName].group.length; i++)
				aGroup = aGroup + '<file>' + aJSONData.files[aScanFileName].group[i] + '</file>';
			aGroup = aGroup + '</group>';
			aContent += aGroup;
		}
			
		 aContent += '</span>';
		 aNewFileElement.innerHTML = aContent;
		
		aExploreBox.appendChild(aNewFileElement);
	}
	
	explorerScrollTo('top');
	
	document.addEventListener("keypress", function onPress(event) {
			const aRegEx = /[a-zA-Z0-9]/;
			if (aRegEx.test(event.key))
				explorerScrollTo(event.key);
		});
}

function historyEvent(aEvent)
{
	if(aEvent.state != null && "folder" in aEvent.state)
		exploreFolderQuery(escapeQuotes(aEvent.state.folder), false);
}

function toggleHiddenGroup(aObject, aHide)
{
	let aNextNode = aObject.nextSibling;
	while(aNextNode && aNextNode.nodeName.toLowerCase() != 'group')
		aNextNode = aNextNode.nextSibling;
	
	if(aNextNode)
	{
		if(aHide == undefined)
			aHide = ! aNextNode.getAttribute('class').match(/hidden/);
		if(aHide)
		{
			if(!aNextNode.getAttribute('class').match(/hidden/))
				aNextNode.setAttribute('class', aNextNode.getAttribute('class') + ' hidden');
		}
		else
			aNextNode.setAttribute('class', aNextNode.getAttribute('class').replace(/hidden/, ''));
	}
}
