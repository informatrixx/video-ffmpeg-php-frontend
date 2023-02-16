var gCropDetect = new Array();
var gCropMaxWidth = 0;
var gCropMaxHeight = 0;
var gPreferredCropString = "";

var gBoxTemplates = {
	'audioBox': '',
	'globalBox': '',
	'infoBox': '',
	'subtitleBox': '',
	'videoBox': ''
};

var gResultVars = {
	'index': 0,
	'audioIndex': 0,
	'fileIndex': 0,
	'subtitleIndex': 0,
	'videoIndex': 0
};

function scanFileResult()
{
	var aJSONData = JSON.parse(this.responseText);
	if(aJSONData.success == false)
		return false;
	
	let aScanBox = document.getElementsByTagName('grid')[0];
	let aGlobalContainer = document.getElementById('globalContainer');
	let aInfoContainer = document.getElementById('infoContainer');
	let aVideoContainer = document.getElementById('videoContainer');
	let aAudioContainer = document.getElementById('audioContainer');
	let aSubtitleContainer = document.getElementById('subtitleContainer');
	
	
	if(aGlobalContainer === null)
	{
		aGlobalContainer = document.createElement('selectContainer');
		aGlobalContainer.setAttribute('id', 'globalContainer');
		aScanBox.appendChild(aGlobalContainer);
	}
	if(aInfoContainer === null)
	{
		aInfoContainer = document.createElement('selectContainer');
		aInfoContainer.setAttribute('id', 'infoContainer');
		aScanBox.appendChild(aInfoContainer);
	}
	if(aVideoContainer === null)
	{
		aVideoContainer = document.createElement('selectContainer');
		aVideoContainer.setAttribute('id', 'videoContainer');
		aScanBox.appendChild(aVideoContainer);
	}
	if(aAudioContainer === null)
	{
		aAudioContainer = document.createElement('selectContainer');
		aAudioContainer.setAttribute('id', 'audioContainer');
		aScanBox.appendChild(aAudioContainer);
	}
	if(aSubtitleContainer === null)
	{
		aSubtitleContainer = document.createElement('selectContainer');
		aSubtitleContainer.setAttribute('id', 'subtitleContainer');
		aScanBox.appendChild(aSubtitleContainer);
	}
	
	if(gResultVars['fileIndex'] == 0)
	{
		if(gBoxTemplates["globalBox"] == '')
			fetch('template/scan.video.globalbox.tmpl.php', 
				{
					method: 'GET',
					credentials: 'include',
					mode: 'no-cors',
				})
			.then((aResponse) => { return aResponse.text();})
			.then((aData) => { 
					gBoxTemplates["globalBox"] = aData;
					processTemplateData(gBoxTemplates["globalBox"], aJSONData, aGlobalContainer);
				})
		else
			processTemplateData(gBoxTemplates["infoBox"], aJSONData, aGlobalContainer);
	}

	if(gBoxTemplates["infoBox"] == '')
		fetch('template/scan.video.infobox.tmpl.php', 
			{
				method: 'GET',
				credentials: 'include',
				mode: 'no-cors',
			})
		.then((aResponse) => { return aResponse.text();})
		.then((aData) => { 
				gBoxTemplates["infoBox"] = aData;
				processTemplateData(gBoxTemplates["infoBox"], aJSONData, aInfoContainer);
				if(aJSONData['info']['videoStreamCount'] > 0)
					chainLoadCropPreviewQuery(1, gResultVars['fileIndex']);
			})
	else
	{
		processTemplateData(gBoxTemplates["infoBox"], aJSONData, aInfoContainer);
		if(aJSONData['info']['videoStreamCount'] > 0)
			chainLoadCropPreviewQuery(1, gResultVars['fileIndex']);
	}
	
	if(gBoxTemplates["videoBox"] == '')
		fetch('template/scan.video.videobox.tmpl.php', 
			{
				method: 'GET',
				credentials: 'include',
				mode: 'no-cors',
			})
		.then((aResponse) => { return aResponse.text();})
		.then((aData) => { 
				gBoxTemplates["videoBox"] = aData;
				aJSONData['streams']['video'].forEach((aVideoStream) => processTemplateData(gBoxTemplates["videoBox"], aVideoStream, aVideoContainer));
			})
	else
		aJSONData['streams']['video'].forEach((aVideoStream) => processTemplateData(gBoxTemplates["videoBox"], aVideoStream, aVideoContainer));

	if(gBoxTemplates["audioBox"] == '')
		fetch('template/scan.video.audiobox.tmpl.php', 
			{
				method: 'GET',
				credentials: 'include',
				mode: 'no-cors',
			})
		.then((aResponse) => { return aResponse.text();})
		.then((aData) => { 
				gBoxTemplates["audioBox"] = aData;
				aJSONData['streams']['audio'].forEach((aAudioStream) => processTemplateData(gBoxTemplates["audioBox"], aAudioStream, aAudioContainer));
			})
	else
		aJSONData['streams']['audio'].forEach((aAudioStream) => processTemplateData(gBoxTemplates["audioBox"], aAudioStream, aAudioContainer));

	if(gBoxTemplates["subtitleBox"] == '')
		fetch('template/scan.video.subtitlebox.tmpl.php', 
			{
				method: 'GET',
				credentials: 'include',
				mode: 'no-cors',
			})
		.then((aResponse) => { return aResponse.text();})
		.then((aData) => { 
				gBoxTemplates["subtitleBox"] = aData;
				if(aJSONData['streams']['subtitle'] != null)
					aJSONData['streams']['subtitle'].forEach((aSubtitleStream) => processTemplateData(gBoxTemplates["subtitleBox"], aSubtitleStream, aSubtitleContainer));
			})
	else
		if(aJSONData['streams']['subtitle'] != null)
			aJSONData['streams']['subtitle'].forEach((aSubtitleStream) => processTemplateData(gBoxTemplates["subtitleBox"], aSubtitleStream, aSubtitleContainer));

}

function chainLoadCropPreviewQuery(aIndex, aFileIndex)
{
	let aCropPreviewContainer;
	for(let i = 0; i < document.getElementsByTagName('cropPreviewContainer').length; i++)
		if(document.getElementsByTagName('cropPreviewContainer')[i].getAttribute('fileIndex') == aFileIndex)
		{
			aCropPreviewContainer = document.getElementsByTagName('cropPreviewContainer')[i];
			break;
		}
	let aSeek = aCropPreviewContainer.getAttribute('duration') / 11 * aIndex;
	
	let aSARString;
	if(aCropPreviewContainer.hasAttribute('sar'))
		aSARString = '&sar=' + aCropPreviewContainer.getAttribute('sar');
	else
		aSARString = '';
	
	var newQuery = new XMLHttpRequest();
	newQuery.addEventListener("load", cropPreviewResult);
	newQuery.open("GET", "query/croppreview.php?file=" + gFileName + "&seek=" + aSeek + "&index=" + aIndex + aSARString + "&fileIndex=" + aFileIndex);
	newQuery.send();	
}

function cropPreviewResult()
{
	let aJSONData = JSON.parse(this.responseText);
	let aIndex = aJSONData.index * 1;
	let aSAR = aJSONData.sar * 1;
	let aSeekHuman = aJSONData.seekHuman;
	let aFileIndex = aJSONData.fileIndex * 1;
	
	let aCropPreviewContainer;
	for(let i = 0; i < document.getElementsByTagName('cropPreviewContainer').length; i++)
		if(document.getElementsByTagName('cropPreviewContainer')[i].getAttribute('fileIndex') == aFileIndex)
		{
			aCropPreviewContainer = document.getElementsByTagName('cropPreviewContainer')[i];
			break;
		}
		
	if(aCropPreviewContainer.hasAttribute('sar') == false)
		aCropPreviewContainer.setAttribute('sar', aSAR);
		
	if(aJSONData.crop != null)
	{
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
	
		for(var i = 0; i < aCropPreviewContainer.getElementsByTagName('selectContent').length; i++)
			if(aCropPreviewContainer.getElementsByTagName('selectContent')[i].getAttribute('index') == aIndex)
			{
				if(i == 0)
					aCropPreviewContainer.getElementsByTagName('selectContent')[i].removeAttribute('hidden')
				aCropPreviewContainer.getElementsByTagName('selectContent')[i].innerHTML = "<img src='query/" + aJSONData.file + "'>";
				break;
			}
		gCropDetect[aJSONData.index] = [aJSONData.crop.width * 1, aJSONData.crop.height * 1];

		for(var i = 0; i < aCropPreviewContainer.getElementsByTagName('cropButton').length; i++)
			if(aCropPreviewContainer.getElementsByTagName('cropButton')[i].getAttribute('index') == aIndex)
				aCropPreviewContainer.getElementsByTagName('cropButton')[i].innerHTML = aSeekHuman;
	
		for(var i = 1; i <= aIndex; i++)
		{
			if((gCropDetect[i][0] + 2) < gCropMaxWidth || (gCropDetect[i][1] + 2) < gCropMaxHeight)
			{
				for(var j = 0; j < aCropPreviewContainer.getElementsByTagName('cropButton').length; j++)
					if(aCropPreviewContainer.getElementsByTagName('cropButton')[j].getAttribute('index') == i)
					{
						aCropPreviewContainer.getElementsByTagName('cropButton')[j].removeAttribute("seeking");
						var aAttentionAttr = document.createAttribute('attention');
						aCropPreviewContainer.getElementsByTagName('cropButton')[j].setAttributeNode(aAttentionAttr);
						break;
					}		
			}
			else
			{
				for(var j = 0; j < aCropPreviewContainer.getElementsByTagName('cropButton').length; j++)
					if(aCropPreviewContainer.getElementsByTagName('cropButton')[j].getAttribute('index') == i)
					{
						aCropPreviewContainer.getElementsByTagName('cropButton')[j].removeAttribute("seeking");
						aCropPreviewContainer.getElementsByTagName('cropButton')[j].removeAttribute("attention");
						break;
					}		
			}
		}
	}
	
	if(aIndex < 10)
		chainLoadCropPreviewQuery(aIndex + 1, aFileIndex);
	else
		for(let i = 0; i < document.getElementsByTagName('cropAutoChoice').length; i++)
			if(document.getElementsByTagName('cropAutoChoice')[i].getAttribute('fileIndex') == aFileIndex)
				document.getElementsByTagName('cropAutoChoice')[i].innerHTML = '(auto: ' + gPreferredCropString + ")<input type='hidden' name='cropstring[" + document.getElementsByTagName('cropAutoChoice')[i].getAttribute('index') + "]' value='" + gPreferredCropString + "' />";
}
