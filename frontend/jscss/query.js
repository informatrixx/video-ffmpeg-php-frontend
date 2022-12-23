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