function showTab(aObject)
{
	var aIndex = aObject.getAttribute('index');
	var aParentNode = aObject.parentNode;
	
	while(aParentNode.tagName.toLowerCase() != 'selectcontainer')
	{
		aParentNode = aParentNode.parentNode;
		if(aParentNode.tagName.toLowerCase() == 'body')
			return false;
	}
	
	for(var i = 0; i < aParentNode.getElementsByTagName('selectButton').length; i++)
		aParentNode.getElementsByTagName('selectButton')[i].removeAttribute('active');
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

function duplicateStream(aObject)
{
	function updateNodeNameIndex(aNode, aOldIndex, aNewIndex)
	{
		var aRegex = new RegExp('(.+)\\[' + aOldIndex + '\\]', '')
		var aReplace = '$1[' + aNewIndex + ']';
		if(aNode.getAttribute('name') != null)
			aNode.setAttribute('name', aNode.getAttribute('name').replace(aRegex, aReplace));
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
			if(aMapList.includes(aNameMatch[1]))
				aMatchedInput = true;
		}
		else
			aMatchedInput = true;
		
		if(aMatchedInput && (aInput.getAttribute('type') != 'checkbox' || aInput.checked))
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
			if(aMapList.includes(aNameMatch[1]))
				aMatchedSelect = true;
		}
		else
			aMatchedSelect = true;
		
		if(aMatchedSelect)
			aParamList[aParamList.length] = aSelect.name + '=' + aSelect.value;
	}

	aParamList.forEach(element => console.log(element));
	let aURl = aForm.getAttribute('action');
	
	location.href = aURl + '?' + aParamList.join('&');
	return false;
}
