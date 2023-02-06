# video-ffmpeg-php-frontend
A frontend for FFMPEG video conversion, written in PHP

### Status: Early development

## Overview
A PHP frontend to be run on a web server, with the purpose to convert video clips using FFMPEG.
### Capabilities:
- Scanning input file to identify Video-/Audio-/Subtitle streams.
- Showing preview images and doing automatic crop detection for black borders.
- Using presets for audio stream conversions, depending on languages.
- Video denoise filter usage (NLMeans)
- Audio volume normalization filter usage (Loudnorm)
- Dolby Pro Logic II audio encoding

### (Current) Output codecs:
- x265/HEVC (Video)
- AAC/HE-AAC/HE-AACv2 (Audio)

## Current dependencies (Software I am using for development)
- nginx (1.22.0)
- PHP 8 (8.2.2)
- ffmpeg (N-109758-gbdc76f467f)
- x265/HEVC encoder (3.5+1-f0c1022b6)
- fdk-aac Frauenhofer AAC Codec (2.0.2)
- JavaScript capable WebBrowser (I am simply using Chrome for testing)
- Ubuntu Linux (22.04)
- *I am sometimes using a Debian environment as well, so dependencies are not yet named or very loose*

## To-Do...
#### ...a lot
At the moment the early development goal is to make a running application that can do basic conversion to my favours (HEVC -> software encode | AAC with loudnorm filters).

#### ...converting queue-manager PHP script into a standalone executable, probably C++?
Right now the queue-manager is a standard PHP script, that needs to be executed by PHP-cli. Maybe sooner or later I'll compile a executable...

### ...(possible) Next steps
- Multiple language support
- Multiple audio and video codec support
- Picking and combining multiple input files
- Installation scripts

## Installation/Configuration
- Edit config-example.json to your needs and rename it to **config.json**
- Create a custom ID file if wanted: config/ID
- Set up your web server to serve PHP scripts
  - Make sure the directories "config/", "run/", "quma/" are not accessible!
- The queue manager script needs to be run as a permanent service. 
  - For example as a system unit.
  - It needs to be executed by PHP (example: "/usr/bin/php8.1 quma/queue-manager-service.php")
  - Consider setting up a non-root user for this!
  
 **Detailled installation not yet documented**

## Notes
I am from Austria!
