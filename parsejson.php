<?PHP
define('PIDFILE', '/dev/shm/upload/parsejson.pid');
$filesSrc = array(1090=>"/dev/shm/1090data/aircraft.json", 978=>"/dev/shm/978data/aircraft.json");

file_put_contents(PIDFILE, posix_getpid());
function removePidFile() {
    unlink(PIDFILE);
}
register_shutdown_function('removePidFile');

function readConfiguration(){
        $file = file_get_contents("/etc/naboor.cfg");
        $config = json_decode($file);
        return $config;
}

$files = array(978=>"/dev/shm/978data/aircraft.json");
function getProperty($obj, $prop){
	if (property_exists($obj,$prop))
		return trim($obj->$prop);
	else
		return "";
}
$nowArr=array();
foreach($files as $freq=>$file)
	$nowArr[$file] = "";
while (true){
	$config=readConfiguration();
	$output = "";
	$files=array();
	if ($config->adsbFreq0=='978' || $config->adsbFreq1=='978')
		$files['978'] = $filesSrc['978'];
	if ($config->adsbFreq0=='1090' || $config->adsbFreq1=='1090')
		$files['1090'] = $filesSrc['1090'];

	foreach ($files as $freq=>$filename){
		$file = file_get_contents($filename);
		$json = json_decode($file);
//		echo "*************************************$filename*********************************\n";
		$now = floor($json->now);
		if ($now>$nowArr[$filename]){
			$nowArr[$filename] = $now;
			foreach ($json->aircraft as $aircraft){
				if (property_exists($aircraft,"lon")){
					$str = $now.",";
					$str.=getProperty($aircraft,"hex").",";
					$str.=getProperty($aircraft,"lat").",";
					$str.=getProperty($aircraft,"lon").",";
					$str.=getProperty($aircraft,"altitude").",";
					$str.="BARO".",";
					$str.=getProperty($aircraft,"track").",";
					$str.="TRACK,";
					$str.=getProperty($aircraft,"speed").",";
					$str.=getProperty($aircraft,"vert_rate").",";
					$str.="BARO,";
					$str.=getProperty($aircraft,"flight").",";
					$str.=$freq;
					$str.="\n";
					$output.=$str;
				}
			}
		}
	}
	file_put_contents("/dev/shm/upload/adsb.txt",$output,FILE_APPEND);
	sleep(3);
}

?>
