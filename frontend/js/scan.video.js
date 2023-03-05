var gCropDetect = new Array();
var gCropMaxWidth = 0;
var gCropMaxHeight = 0;
var gPreferredCropString = "";
var gCodecSettings = null;
var gLastBuiltVideoSettings = null;

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

var gScanFileResult = new Array();



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
	
	gScanFileResult[gResultVars['fileIndex']] = aJSONData;
	
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
	
	if(gCodecSettings == null)
		fetch('query/getcodecsettings.php', 
				{
					method: 'GET',
					credentials: 'include',
					mode: 'no-cors',
				})
			.then((aResponse) => { return aResponse.json();})
			.then((aJSONData) => { 
					gCodecSettings = aJSONData;
				});
	
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
	
	let aInFileInputs = document.getElementsByName('infile[' + aFileIndex + ']');
	let aScanFile = encodeURIComponent(gFileName);
	if(aInFileInputs.length > 0)
		aScanFile = aInFileInputs[0].value;
	
	newQuery.open("GET", "query/croppreview.php?file=" + aScanFile + "&seek=" + aSeek + "&index=" + aIndex + aSARString + "&fileIndex=" + aFileIndex);
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

function selectAudioCodec(aObject)
{
	let aVisibility;
	if(aObject.value == 'copy')
		aVisibility = 'hidden'
	else
		aVisibility = 'visible';
		
	let aContentContainer = aObject.parentNode;
	while(aContentContainer.nodeName.toUpperCase() != 'SELECTCONTENT')
	{
		aContentContainer = aContentContainer.parentNode;
		if(aContentContainer.nodeName.toUpperCase() == 'BODY')
			return false;
	}
	
	let aIndex = aContentContainer.getAttribute('index');
	
	for(let i = 0; i < aContentContainer.children.length; i++)
	{
		switch(aContentContainer.children[i].name)
		{
			case 'ac[' + aIndex + ']':
			case 'b[' + aIndex + ']':
			case 'ar[' + aIndex + ']':
			case 'loudnorm[' + aIndex + ']':
				aContentContainer.children[i].style.visibility = aVisibility;
				break;
		}
	}
}

function selectVideoCodec(aObject)
{
	let aContentContainer = aObject.parentNode;
	while(aContentContainer.nodeName.toUpperCase() != 'SELECTCONTENT')
	{
		aContentContainer = aContentContainer.parentNode;
		if(aContentContainer.nodeName.toUpperCase() == 'BODY')
			return false;
	}
	
	let aIndex = aContentContainer.getAttribute('index');
	let aVisibility;
	if(aObject.value == 'copy')
		aVisibility = 'hidden'
	else 
		if(gLastBuiltVideoSettings['codec'] == aObject.value && gLastBuiltVideoSettings['mode'] == aObject.options[aObject.selectedIndex].getAttribute('mode'))
			aVisibility = 'visible';
		else
		{
			for(let i = aContentContainer.children.length - 1; i >= 0; i--)
				if(aContentContainer.children[i].getAttribute('built') == 'videoSettings')
					aContentContainer.children[i].remove();
				
			let aConversionSetting = {
				'codec':		aObject.value,
				'mode':			aObject.options[aObject.selectedIndex].getAttribute('mode'),
				'modeValue':	gCodecSettings['video'][aObject.value]['modes'][aObject.options[aObject.selectedIndex].getAttribute('mode')]['default']
			};
			let aInsertHTML = buildVideoSettings(aConversionSetting);
			aObject.insertAdjacentHTML('afterend', aInsertHTML);
			aVisibility = 'visible';
		}
	
	for(let i = 0; i < aContentContainer.children.length; i++)
	{
		if(aContentContainer.children[i].getAttribute('built') != null && aContentContainer.children[i].nodeName.toUpperCase() != 'LABEL')
			aContentContainer.children[i].style.visibility = aVisibility;
		switch(aContentContainer.children[i].name)
		{
			case 'resize[' + aIndex + ']':
			case 'crop[' + aIndex + ']':
			case 'nlmeans[' + aIndex + ']':
				aContentContainer.children[i].style.visibility = aVisibility;
				break;
		}
	}
}

function buildVideoSettings(aConversionSettings)
{
	
	let aCodec = aConversionSettings['codec'];
	let aMode = aConversionSettings['mode'];
	let aModeValue = aConversionSettings['modeValue'];
	let aUnit = '';
	if(gCodecSettings['video'][aCodec]['modes'][aMode]['unit'] != null)
		aUnit = gCodecSettings['video'][aCodec]['modes'][aMode]['unit'];

	gLastBuiltVideoSettings = {
		'codec':		aCodec,	
		'mode':			aMode,	
		'modeValue':	aModeValue	
	};

	aReplacement = '<label built="videoSettings">' + gCodecSettings['video'][aCodec]['modes'][aMode]['settingsName'] + ':</label><p  built="videoSettings"><input type="number" name="' + gCodecSettings['video'][aCodec]['modes'][aMode]['param'] + '[' + gResultVars['index'] + ']" min="' + gCodecSettings['video'][aCodec]['modes'][aMode]['min'] + '" max="' + gCodecSettings['video'][aCodec]['modes'][aMode]['max'] + '" value="' + aModeValue + '">' + aUnit + '</p>';
	Object.keys(gCodecSettings['video'][aCodec]['settings']).forEach((aDataName) => {
		aReplacement += '<label  built="videoSettings">' + aDataName.charAt(0).toUpperCase() + aDataName.slice(1) + ':</label><select  built="videoSettings" name="' + aDataName + '[' + gResultVars['index'] + ']">';
		var aOptions = gCodecSettings['video'][aCodec]['settings'][aDataName];
		Object.keys(aOptions).forEach((aOptionKey) => {
			let aSelected = '';
			if((aConversionSettings[aDataName] != null && aConversionSettings[aDataName] == aOptionKey) || typeof aOptions[aOptionKey] == 'object')
				aSelected = ' selected="selected"';
			aReplacement += '<option value="' + aOptionKey + '"' + aSelected + '>' + aOptions[aOptionKey] + '</option>';
		});
		aReplacement += '</select>';
	});
	
	return aReplacement;
}

function autoTitle(aObject, aTopic)
{
	let aSelectContainer = aObject.parentNode;
	let aContentContainer = aObject.parentNode;
	while(aSelectContainer != null && aSelectContainer.nodeName.toUpperCase() != 'SELECTCONTAINER')
		aSelectContainer = aSelectContainer.parentNode;
	while(aContentContainer != null && aContentContainer.nodeName.toUpperCase() != 'SELECTCONTENT')
		aContentContainer = aContentContainer.parentNode;
	
	if(aSelectContainer == null || aContentContainer == null || aSelectContainer.getElementsByTagName('selectButtons').length == 0)
		return false;
	
	const aSelectButtons = aSelectContainer.getElementsByTagName('selectButtons')[0];
	const aIndex = aContentContainer.getAttribute('index');
	const aStreamIndex = aContentContainer.getAttribute('streamindex');
	const aFileIndex = aContentContainer.getAttribute('fileindex');
	
	let aCount = 0;
	let aNamingIndex = 0;
	let aNamingPattern = gScanFileResult[aFileIndex]['autoNaming'][aTopic];
	const aTmplRegex = /##([A-Z]+)([:=].*?)?##/g;
	
	for(let i = 0; i < aSelectButtons.getElementsByTagName('input').length; i++)
	{
		let aSelectButton = aSelectButtons.getElementsByTagName('input')[i];
		if(aSelectButton.checked)
			aCount++;
		
		if(aSelectButton.name == 'map[' + aIndex + ']')
		{
			aNamingIndex = aCount;
			let aTitle = aNamingPattern;
			let aReplacement = '';
			
			while((aMatch = aTmplRegex.exec(aNamingPattern)) !== null)
			{
				aReplacement = '';
				switch(aMatch[1])
				{
					case 'INDEX':
						aReplacement = aNamingIndex;
						break;
					case 'LANG':
						if(aMatch[2].charAt(0) != ':')
							break;
						let aLangTopic = aMatch[2].slice(1);
						for(let j = 0; j < gScanFileResult[aFileIndex]['streams'][aTopic].length; j++)
							if(gScanFileResult[aFileIndex]['streams'][aTopic][j]['streamIndex'] == aStreamIndex)
							{
								if(gScanFileResult[aFileIndex]['streams'][aTopic][j]['language'][aLangTopic] != null)
									aReplacement = gScanFileResult[aFileIndex]['streams'][aTopic][j]['language'][aLangTopic];
								break;
							}
						break;
					case 'FORCED':
						if(aMatch[2].charAt(0) != '=')
							break;
						if(document.getElementsByName('forced[' + aIndex + ']') != null && document.getElementsByName('forced[' + aIndex + ']')[0].checked)
							aReplacement = aMatch[2].slice(1);
						break;
				}
				aTitle = aTitle.replaceAll(aMatch[0], aReplacement);
			}
			document.getElementsByName('title[' + aIndex + ']')[0].value = aTitle;
			break;
		}
	}
}
