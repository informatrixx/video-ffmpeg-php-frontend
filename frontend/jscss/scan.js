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

function chainLoadCropPreviewQuery(aIndex, aAspectRatio)
{
	var aCropPreviewContainer = document.getElementById('cropPreviewContainer');

	for(var i = 0; i < aCropPreviewContainer.getElementsByTagName('selectContent').length; i++)
		if(aCropPreviewContainer.getElementsByTagName('selectContent')[i].getAttribute('index') == aIndex)
		{
			var aSeek = aCropPreviewContainer.getElementsByTagName('selectContent')[i].getAttribute('seek');
			break;
		}
		
	var newQuery = new XMLHttpRequest();
	newQuery.addEventListener("load", cropPreviewResult);
	newQuery.open("GET", "query/croppreview.php?file=" + gFileName + "&seek=" + aSeek + "&index=" + aIndex + '&sar=' + gAspectRatio);
	newQuery.send();	
}

function cropPreviewResult()
{
	var aJSONData = JSON.parse(this.responseText);
	var aIndex = aJSONData.index * 1; 
	if(gCropMaxWidth < aJSONData.crop.width)
	{
		gCropMaxWidth = aJSONData.crop.width * 1;
		gPreferredCropString = aJSONData.crop.string;
	}
	if(gCropMaxHeight < aJSONData.crop.height)
	{
		gCropMaxHeight = aJSONData.crop.height * 1;
		gPreferredCropString = aJSONData.crop.string;
	}
	
	var aCropPreviewContainer = document.getElementById('cropPreviewContainer');
	
	for(var i = 0; i < aCropPreviewContainer.getElementsByTagName('selectContent').length; i++)
		if(aCropPreviewContainer.getElementsByTagName('selectContent')[i].getAttribute('index') == aIndex)
		{
			aCropPreviewContainer.getElementsByTagName('selectContent')[i].innerHTML = "<img src='query/" + aJSONData.file + "'>";
			break;
		}
	gCropDetect[aJSONData.index] = [aJSONData.crop.width * 1, aJSONData.crop.height * 1];

	for(var i = 1; i <= aIndex; i++)
	{
		if((gCropDetect[i][0] + 2) < gCropMaxWidth || (gCropDetect[i][1] + 2) < gCropMaxHeight)
		{
			for(var j = 0; j < aCropPreviewContainer.getElementsByTagName('selectButton').length; j++)
				if(aCropPreviewContainer.getElementsByTagName('selectButton')[j].getAttribute('index') == i)
				{
					aCropPreviewContainer.getElementsByTagName('selectButton')[j].removeAttribute("seeking");
					var aAttentionAttr = document.createAttribute('attention');
					aCropPreviewContainer.getElementsByTagName('selectButton')[j].setAttributeNode(aAttentionAttr);
					break;
				}		
		}
		else
		{
			for(var j = 0; j < aCropPreviewContainer.getElementsByTagName('selectButton').length; j++)
				if(aCropPreviewContainer.getElementsByTagName('selectButton')[j].getAttribute('index') == i)
				{
					aCropPreviewContainer.getElementsByTagName('selectButton')[j].removeAttribute("seeking");
					aCropPreviewContainer.getElementsByTagName('selectButton')[j].removeAttribute("attention");
					break;
				}		
		}
	}
	
	if(aIndex < 10)
		chainLoadCropPreviewQuery(aIndex + 1);
	else
		document.getElementsByTagName('cropAutoChoice')[0].innerHTML = '(auto: ' + gPreferredCropString + ")<input type='hidden' name='cropstring' value='" + gPreferredCropString + "' />";
}
