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
- nginx (1.18.0-6ubuntu14.1 amd64)
- PHP 8.1 (8.1.8-1+ubuntu22.04.1+deb.sury.org+1)
- ffmpeg (N-106733-gec07b15477)
- x265/HEVC encoder (3.5+1-f0c1022b6)
- fdk-aac Frauenhofer AAC Codec (2.0.2)
- JavaScript capable WebBrowser (I am simply using Chrome for testing)
- Ubuntu Linux (22.04)
- *I am sometimes using a Debian environment as well, so dependencies are not yet named or very loose*

## To-Do...
#### ...a lot
At the moment the early development goal is to make a running application that can do basic conversion to my favours (HEVC -> software encode | AAC with loudnorm filters).

### ...(possible) Next steps
- Multiple language support
- Multiple audio and video codec support
- Picking and combining multiple input files
- Installation scripts

## Installation/Configuration
**Not yet documented 
No configuration files provided** -> So it's not yet installable without them**

## Notes
I am from Austria!
