var gXHRData = new Array();

function escapeQuotes(aText)
{
	var aMap = {
		'"': '\\x22',
		"'": '\\x27'
	};
	
	return aText.replace(/[&<>"']/g, function(m) { return aMap[m]; });
}

function scanFileQuery(aFile, aType)
{
	let newQuery = new XMLHttpRequest();
	newQuery.addEventListener('load', scanFileResult);
	newQuery.open('GET', 'query/scan.php?file=' + aFile + '&type=' + aType);
	newQuery.send();
}

function joinFile(aFile)
{
	gResultVars['fileIndex']++;
	scanFileQuery(aFile, gScanType);
}

function showTab(aObject, aCropTab = false)
{
	let aIndex = aObject.getAttribute('index');
	let aParentNode = aObject.parentNode;
	
	let aTargetContainerName = 'selectcontainer';
	let aButtonsName = 'selectButton';
	
	if(aCropTab)
	{
		aTargetContainerName = 'croppreviewcontainer';
		aButtonsName = 'cropButton';
	}
	
	while(aParentNode.tagName.toLowerCase() != aTargetContainerName)
	{
		aParentNode = aParentNode.parentNode;
		if(aParentNode.tagName.toLowerCase() == 'body')
			return false;
	}
	
	for(var i = 0; i < aParentNode.getElementsByTagName(aButtonsName).length; i++)
		aParentNode.getElementsByTagName(aButtonsName)[i].removeAttribute('active');
	var aActiveAttr = document.createAttribute('active');
	aObject.setAttributeNode(aActiveAttr);
	for(var i = 0; i < aParentNode.getElementsByTagName('selectContent').length; i++)
	{
		if(aParentNode.getElementsByTagName('selectContent')[i].getAttribute('index') == aIndex)
			aParentNode.getElementsByTagName('selectContent')[i].removeAttribute('hidden');
		else
		{
			var aHiddenAttr = document.createAttribute('hidden');	
			aParentNode.getElementsByTagName('selectContent')[i].setAttributeNode(aHiddenAttr);
		}
	}
}

function duplicateStream(aObject)
{
	function updateNodeNameIndex(aNode, aOldIndex, aNewIndex)
	{
		var aRegex = new RegExp('(.+)\\[' + aOldIndex + '\\]', '')
		var aReplace = '$1[' + aNewIndex + ']';
		if(aNode.getAttribute('name') != null)
			aNode.setAttribute('name', aNode.getAttribute('name').replace(aRegex, aReplace));
		if(aNode.getAttribute('moreParams') != null)
			aNode.setAttribute('moreParams', aNode.getAttribute('moreParams').replace(aRegex, aReplace));
		if(aNode.children != null)
		{
			for(var i = 0; i < aNode.children.length; i++)
				updateNodeNameIndex(aNode.children[i], aOldIndex, aNewIndex);
		}
	}
	
	var aParentNode = aObject.parentNode;
	
	while(aParentNode.tagName.toLowerCase() != 'selectbutton')
	{
		aParentNode = aParentNode.parentNode;
		if(aParentNode.tagName.toLowerCase() == 'body')
			return false;
	}

	var aButtonNode = aParentNode;
	
	var aIndex = aButtonNode.getAttribute('index');
	var aIndexArray = aIndex.split(".");
	if(aIndexArray.length > 1)
		var aNewIndex = aIndexArray[0] + '.' + (parseInt(aIndexArray[1], 10) + 1);
	else
		var aNewIndex = aIndexArray[0] + '.1';
	
	
	var aDuplicateButton = aButtonNode.cloneNode(true);
	aDuplicateButton.setAttribute('index', aNewIndex);
	updateNodeNameIndex(aDuplicateButton, aIndex, aNewIndex);
	aButtonNode.parentNode.insertBefore(aDuplicateButton, aButtonNode.nextSibling);
	
	
	while(aParentNode.tagName.toLowerCase() != 'selectcontainer')
	{
		aParentNode = aParentNode.parentNode;
		if(aParentNode.tagName.toLowerCase() == 'body')
			return false;
	}
	for(var i = 0; i < aParentNode.getElementsByTagName('selectContent').length; i++)
	{
		if(aParentNode.getElementsByTagName('selectContent')[i].getAttribute('index') == aIndex)
		{
			var aContentNode = aParentNode.getElementsByTagName('selectContent')[i];
			break;
		}
	}
	
	var aDuplicateContent = aContentNode.cloneNode(true);
	aDuplicateContent.setAttribute('index', aNewIndex);
	updateNodeNameIndex(aDuplicateContent, aIndex, aNewIndex);
	aContentNode.parentNode.insertBefore(aDuplicateContent, aContentNode.nextSibling);	
}

function collectFormSubmit(aForm)
{
	let aMapList = new Array();
	for(let i = 0; i < document.getElementsByTagName('selectContainer').length; i++)
	{
		let aMajorContainer = document.getElementsByTagName('selectContainer')[i];
		for(let j = 0; j < aMajorContainer.getElementsByTagName('selectButtons').length; j++)
		{
			let aSelectButton = aMajorContainer.getElementsByTagName('selectButtons')[j];
			for(let k = 0; k < aSelectButton.getElementsByTagName('input').length; k++)
			{
				let aInput = aSelectButton.getElementsByTagName('input')[k];
				const aMapRegex = /^map\[([\d\.]+)]$/;
				let aMapMatch;
				if((aMapMatch = aMapRegex.exec(aInput.name)) !== null)
				{
					if(aInput.checked)
						aMapList[aMapList.length] = aMapMatch[1];
				}
			}
		}
	}
	
	let aParamList = new Array();
	for(let i = 0; i < document.getElementsByTagName('input').length; i++)
	{
		const aNameRegex = /^[A-Za-z\d_]+\[([\d\.]+)]$/;
		let aMapMatch;
		let aInput = document.getElementsByTagName('input')[i];
		let aMatchedInput = false;
		if((aNameMatch = aNameRegex.exec(aInput.name)) !== null)
		{
			if(aMapList.includes(aNameMatch[1]) && aInput.style.visibility != 'hidden')
				aMatchedInput = true;
		}
		else
			aMatchedInput = true;
		
		if((aMatchedInput || aInput.getAttribute('nomapmatch') != null) && (aInput.getAttribute('type') != 'checkbox' || aInput.checked))
			aParamList[aParamList.length] = aInput.name + '=' + encodeURIComponent(aInput.value);
	}

	for(let i = 0; i < document.getElementsByTagName('select').length; i++)
	{
		const aNameRegex = /^[A-Za-z\d_]+\[([\d\.]+)]$/;
		let aMapMatch;
		let aSelect = document.getElementsByTagName('select')[i];
		let aMatchedSelect = false;
		if((aNameMatch = aNameRegex.exec(aSelect.name)) !== null)
		{
			if(aMapList.includes(aNameMatch[1]) && aSelect.style.visibility != 'hidden')
				aMatchedSelect = true;
		}
		else
			aMatchedSelect = true;
		
		if(aMatchedSelect)
		{
			aParamList[aParamList.length] = aSelect.name + '=' + aSelect.value;
			if(aSelect.options[aSelect.selectedIndex].getAttribute('moreParams') != null)
				aParamList[aParamList.length] = aSelect.options[aSelect.selectedIndex].getAttribute('moreParams');
		}
	}

	aParamList.forEach(element => console.log(element));
	let aURl = aForm.getAttribute('action');
	
	location.href = aURl + '?' + aParamList.join('&');
	return false;
}

function processTemplateData(aTemplateData, aJSONData, aContainer)
{
	let aContent = aTemplateData;
	
	//const aTmplRegex = /##DATA:(.+?)##/g;
	const aTmplRegex = /##([A-Z]+):?([\w:+-]*?)(=\w*?)?##/g;
	while ((aMatch = aTmplRegex.exec(aTemplateData)) !== null)
	{
		var aReplacement = '';
		switch(aMatch[1])
		{
			case 'DATA':
				var aDataNode = aJSONData;
				var aKeyArray = aMatch[2].split(':');
				
				aKeyArray.forEach((aKeyIndex) => {
						if(aDataNode != null && aKeyIndex in aDataNode) 
							aDataNode = aDataNode[aKeyIndex];
						else
							aDataNode = null;
					});
				
				switch(typeof aDataNode)
				{
					case 'string':
						aReplacement = escapeHTML(aDataNode);
						break;
					case 'number':
						aReplacement = aDataNode;
						break;
					case 'object':
						if(aDataNode != null)
							aReplacement = escapeHTML(aDataNode.join(', '));
					break;
				}
			break;
			case 'VAR':
				var aVarName = aMatch[2];
				var aVarValue = '';
				if(aMatch[2].slice(-1) == '+' || aMatch[2].slice(-1) == '-')
					aVarName = aVarName.slice(0, -1);
				
				if(aVarName in gResultVars)
				{
					if(aMatch[2].slice(-1) == '+')
						gResultVars[aVarName] += 1;
					if(aMatch[2].slice(-1) == '-')
						gResultVars[aVarName] -= 1;
					aVarValue = gResultVars[aVarName];
				}
				aReplacement = aVarValue;
			break;
			case 'FILE_INDEX':
				aReplacement = gFileIndexCounter;
			break;
			case 'SELECT':
				var aDataNode = aJSONData;
				var aKeyArray = aMatch[2].split(':');
				
				aKeyArray.forEach((aKeyIndex) => {
						if(aDataNode != null && aKeyIndex in aDataNode) 
							aDataNode = aDataNode[aKeyIndex];
						else
							aDataNode = null;
					});
				
				if(aDataNode == aMatch[3].substring(1))
					aReplacement = 'selected';
			break;
			case 'CHECK':
				var aDataNode = aJSONData;
				var aKeyArray = aMatch[2].split(':');
				
				aKeyArray.forEach((aKeyIndex) => {
						if(aDataNode != null && aKeyIndex in aDataNode) 
							aDataNode = aDataNode[aKeyIndex];
						else
							aDataNode = null;
					});
				
				if(aDataNode > 0)
					aReplacement = 'checked';
			break;
			case 'BUILDVIDEOSETTINGS':
				var aDataNode = aJSONData;
				var aKeyArray = aMatch[2].split(':');
				
				aKeyArray.forEach((aKeyIndex) => {
						if(aDataNode != null && aKeyIndex in aDataNode) 
							aDataNode = aDataNode[aKeyIndex];
						else
							aDataNode = null;
					});
							
				aReplacement = '';
				if(aDataNode != null)
					aReplacement = buildVideoSettings(aDataNode);
			break;
			case 'ARCHIVEFILES':
				for(let i = 0; i < aJSONData.length; i++)
				{
					aReplacement += '<file><input type="checkbox" name="archivefile[]" checked value="' + escapeHTML(aJSONData[i]['fileName']) + '" />' + escapeHTML(aJSONData[i]['fileName']) + '</file><size>' + aJSONData[i]['size']['human'] + '</size>';
				}
				
			break;
		}
		aContent = aContent.replaceAll(aMatch[0], aReplacement);
	}

	let aSelectButtons;
	
	for(let i = 0; i < aContainer.children.length; i++)
	{
		if(aContainer.children[i].nodeName == 'SELECTBUTTONS')
			aSelectButtons = aContainer.children[i];
	}
	if(aSelectButtons === undefined)
	{
		aSelectButtons = document.createElement('selectButtons');
		aContainer.appendChild(aSelectButtons);
	}
	
	const aButtonsRegex = /<selectButtons>(.+)<\/selectButtons>/s;
	if((aMatch = aButtonsRegex.exec(aContent)) !== null)
		aSelectButtons.innerHTML += aMatch[1];

	const aContentRegex = /(<selectContent.+<\/selectContent>)/s;
	if((aMatch = aContentRegex.exec(aContent)) !== null)
		aContainer.innerHTML += aMatch[1];

	let aFirstButton = true;
	for(let i = 0; i < aContainer.getElementsByTagName('selectButton').length; i++)
		if(aFirstButton)
			aFirstButton = false;
		else
			aContainer.getElementsByTagName('selectButton')[i].removeAttribute('active');
		
	let aFirstContent = true;
	for(let i = 0; i < aContainer.getElementsByTagName('selectContent').length; i++)
		if(aFirstContent)
			aFirstContent = false;
		else
		{
			var aHiddenAttr = document.createAttribute('hidden');
			aContainer.getElementsByTagName('selectContent')[i].setAttributeNode(aHiddenAttr);
		}
}


