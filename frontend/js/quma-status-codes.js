const QUMA_STATUS_WAITING = 0;
const QUMA_STATUS_SCAN_READY = 1;
const QUMA_STATUS_SCAN = 2;
const QUMA_STATUS_SCAN_CHILD_READY = 3;
const QUMA_STATUS_SCAN_CHILD = 4;
const QUMA_STATUS_SCAN_WAITING = 6;
const QUMA_STATUS_CONVERT_READY = 7;
const QUMA_STATUS_CONVERT = 8;
const QUMA_STATUS_CONVERT_DONE = 9;
const QUMA_STATUS_UNRAR_WAITING = 10;
const QUMA_STATUS_UNRAR_READY = 11;
const QUMA_STATUS_UNRAR = 12;
const QUMA_STATUS_UNRAR_DONE = 15;
const QUMA_STATUS_UNRAR_ERROR = 19;
const QUMA_STATUS_SCAN_ABORT = 82;
const QUMA_STATUS_CONVERT_ABORT = 86;
const QUMA_STATUS_UNRAR_ABORT = 812;
const QUMA_STATUS_SCAN_PAUSE = 91;
const QUMA_STATUS_SCAN_ERROR = 92;
const QUMA_STATUS_CONVERT_PAUSE = 97;
const QUMA_STATUS_CONVERT_ERROR = 98;
const QUMA_CODE_STATUS = {
	0: 'QUMA_STATUS_WAITING',
	1: 'QUMA_STATUS_SCAN_READY',
	2: 'QUMA_STATUS_SCAN',
	3: 'QUMA_STATUS_SCAN_CHILD_READY',
	4: 'QUMA_STATUS_SCAN_CHILD',
	6: 'QUMA_STATUS_SCAN_WAITING',
	7: 'QUMA_STATUS_CONVERT_READY',
	8: 'QUMA_STATUS_CONVERT',
	9: 'QUMA_STATUS_CONVERT_DONE',
	10: 'QUMA_STATUS_UNRAR_WAITING',
	11: 'QUMA_STATUS_UNRAR_READY',
	12: 'QUMA_STATUS_UNRAR',
	15: 'QUMA_STATUS_UNRAR_DONE',
	19: 'QUMA_STATUS_UNRAR_ERROR',
	82: 'QUMA_STATUS_SCAN_ABORT',
	86: 'QUMA_STATUS_CONVERT_ABORT',
	812: 'QUMA_STATUS_UNRAR_ABORT',
	91: 'QUMA_STATUS_SCAN_PAUSE',
	92: 'QUMA_STATUS_SCAN_ERROR',
	97: 'QUMA_STATUS_CONVERT_PAUSE',
	98: 'QUMA_STATUS_CONVERT_ERROR'
};const QUMA_TEXT_STATUS = {
	'0': 'Waiting', 
	'1': 'Ready to scan', 
	'2': 'Scanning', 
	'3': 'Ready to scan', 
	'4': 'Scanning', 
	'6': 'Waiting for scan results', 
	'7': 'Ready to convert / scan done', 
	'8': 'Converting', 
	'9': 'Done', 
	'10': 'Waiting', 
	'11': 'Ready to extract (unrar)', 
	'12': 'Extracting (unrar)', 
	'15': 'Done  extracting (unrar)', 
	'19': 'Error extracting (unrar)', 
	'82': 'Abort scanning', 
	'86': 'Abort converting', 
	'812': 'Abort extracting (unrar)', 
	'91': 'Pause scanning', 
	'92': 'Error scanning', 
	'97': 'Pause converting', 
	'98': 'Error converting'
};