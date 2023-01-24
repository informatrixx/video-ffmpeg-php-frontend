<?php
	header('Content-Type: text/plain; charset=utf-8');
	
	require('../../shared/common.inc.php');
	
	define(constant_name: 'STATIC_CONFIG', value: json_decode(json: file_get_contents(ROOT . 'config/static_config.json'), associative: true));
	define(constant_name: 'DECISIONS', value: json_decode(json: file_get_contents('../config/decision_template.json'), associative: true));
	
?>
<selectButtons>
	<selectButton fileIndex='##VAR:fileIndex##'>##DATA:fileName##</selectButton>
</selectButtons>
<selectContent fileIndex='##VAR:fileIndex##'>
	<label>Format:</label><text>##DATA:info:formatName##</text>
	<label>Größe:</label><text>##DATA:info:size:human##</text>
	<label>Dauer:</label><text>##DATA:info:duration:human##</text>
	<label>Video Streams:</label><text>##DATA:info:videoStreamCount##</text>
	<label>Audio Sprachen:</label><text>##DATA:info:languages:audio##</text>
	<label>Untertitel:</label><text>##DATA:info:languages:subtitle##</text>
	<label>Kapitel:</label><text>##DATA:info:chapters##</text>
	<cropPreviewContainer style='grid-column: span 3;' fileIndex='##VAR:fileIndex##' duration='##DATA:info:duration:seconds##'>
		<cropButtons>
		<?php
			$aActive = "active";
			for($i = 1; $i < 11; $i++)
			{
				echo "<cropButton index='$i' $aActive seeking onclick='showTab(this, true)'></cropButton>";
				$aActive = "";
			}
		?>
		</cropButtons>
		<?php
			$aHidden = "";
			for($i = 1; $i < 11; $i++)
			{
				echo "<selectContent index='$i' $aHidden><img class='loader' src='img/loader.svg'></selectContent>";
				$aHidden = "hidden";
			}
		?>
		
	</cropPreviewContainer>	
	
</selectContent>