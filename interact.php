<?PHP
	//NABOORVERSION=0.322
	$configFile = "/etc/naboor.cfg";
	$config =""; //leave blank, we fill in later
function make_thumb($src, $dest, $desired_width) {

	/* read the source image */
	$source_image = imagecreatefromjpeg($src);
	$width = imagesx($source_image);
	$height = imagesy($source_image);
	/* find the "desired height" of this thumbnail, relative to the desired width  */
	$desired_height = floor($height * ($desired_width / $width));
	/* create a new, "virtual" image */
	$virtual_image = imagecreatetruecolor($desired_width, $desired_height);
	/* copy source image at a resized size */
	imagecopyresampled($virtual_image, $source_image, 0, 0, 0, 0, $desired_width, $desired_height, $width, $height);
	/* create the physical thumbnail image to its destination */
	imagejpeg($virtual_image, $dest);
}
function uploadADSB(){
	global $config;
	logMessage("Beginning ADSB Interaction");
	$date= time();
	$newfilename = $config->adsbFile.$date;
	rename($config->adsbFile, $newfilename);
	$content = file_get_contents($newfilename);
	if ($content==""){
		$result =unlink($newfilename);
		logMessage("No ADSB data to upload, Interaction complete: unlink result: $result");
		return true;
	}
	$gzcontent = gzencode($content);
	$post = array('action' => 'ADSB', 'PID' => $config->PID,'file_contents'=>"$gzcontent");
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $config->serverurl);
	curl_setopt($ch, CURLOPT_POST,1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
	$result=curl_exec ($ch);
	curl_close ($ch);
	$result =unlink($newfilename);
	logMessage("ADSB Interaction complete: unlink result: $result");
	return true;
}

function connect(){
	return true;
}

function checkConnect(){
	global $config;
	$connected = @fsockopen($config->connectionTestServer, $config->connectionTestPort);
	$result = $connected;
	fclose($connected);
	return $result;
}

function readConfiguration(){
	global $configFile;
	$file = file_get_contents($configFile);
	$config = json_decode($file);
	return $config;
}
function logMessage($msg){
	global $config;
	if (property_exists($config,"debug") && $config->debug)
		syslog(LOG_INFO, "Naboor: ".$msg);
	echo "$msg\n"; //DEBUG
}
function getRelaySchedule(){
	global $config;
	$query = "select * from RelaySchedule where complete=0;";
	$result = getSQLResult("getrelaySchedule 1",$query);
	$incompleteRSIDs=array();
        for ($row = $result->fetch_object(); $row; $row = $result->fetch_object()){
        	$RSID = $row->RSID;
		$temp["ActualOn"]=$row->ActualOn;
		$temp["ActualOff"]=$row->ActualOff;
		$temp["RSID"]=$RSID;
		array_push($incompleteRSIDs,$temp);
	}
	$jsondata = json_encode($incompleteRSIDs);
	$gzcontent = gzencode($jsondata);
        $post = array('action' => 'syncRelay', 'PID' => $config->PID,'file_contents'=>"$gzcontent");
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $config->serverurl);
        curl_setopt($ch, CURLOPT_POST,1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        $result=curl_exec($ch);
        curl_close ($ch);
	$output = gzdecode($result);
	if (!$output)
		logMessage("Output not GZ format: $output");
	$jsonout = json_decode($output);;
	foreach($jsonout as $value){
		$RSID = $value->RSID;
		$ScheduleOn=$value->ScheduleOn;
		$GPIO=$value->GPIO;
		$ScheduleOff=$value->ScheduleOff;
		$completed=$value->Completed;
		$query = "insert into RelaySchedule (RSID,GPIO,ScheduleOn, ScheduleOff, Complete) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE ";
		$query .=" ScheduleOn=VALUES(ScheduleON), ScheduleOff=VALUES(ScheduleOff), Complete=VALUES(Complete),GPIO=VALUES(GPIO);";
		//echo "RSID=$RSID\nGPIO=$GPIO\nScheduleON=$ScheduleOn\nScheduleOff=$ScheduleOff\nComplete=$completed\n";
		$res = execSQL("GetRelaySchedule2",$query,"iissi",$RSID,$GPIO,$ScheduleOn,$ScheduleOff,$completed);
		$log =$res?"Success":"Fail";
		logMessage("Synced RSID $RSID from server with result: $log");
	}

}

function checkRelaySchedule(){
	global $config;
	$query = "select * from RelaySchedule where ScheduleOn<now() and isnull(ActualOn)"; //Should be on but isn't
	$result = getSQLResult("checkrelaySchedule 1",$query);
	for ($row = $result->fetch_object(); $row; $row = $result->fetch_object()){
		$RSID=$row->RSID;
		$GPIO=$row->GPIO;
		$output = shell_exec('gpio write '.$GPIO.' '.$config->gpioOnMode);
		$query = "Update RelaySchedule set ActualOn=now() where RSID=?";
		$rs2 = execSQL("chekRelaySchedule 2",$query,'i',$RSID);
		$log =$rs2?"Success":"Fail";
		logMessage("Completed turn on of RSID $RSID: SQL result $log");
	}
	$query = "select * from RelaySchedule where ScheduleOff<now() and isnull(ActualOff)"; //Should be off but isn't
	$result = getSQLResult("checkrelaySchedule 3",$query);
	for ($row = $result->fetch_object(); $row; $row = $result->fetch_object()){
		$RSID=$row->RSID;
		$GPIO=$row->GPIO;
		$output = shell_exec('gpio write '.$GPIO.' '.$config->gpioOffMode);
		$query = "Update RelaySchedule set ActualOff=now() where RSID=?";
		$rs2 = execSQL("chekRelaySchedule 4",$query,'i',$RSID);
		$log =$rs2?"Success":"Fail";
		logMessage("Completed turn OFF of RSID $RSID: SQL result $log");
	}
}
function killprocess($processName){
	global $config;
	logMessage("Killing $processName");
	exec("pgrep $processName", $pids);
	foreach($pids as $pid){
		logMessage("Sending process $pid SIGTERM");
		$result = posix_kill($pid,SIGTERM);
		logMessage("Result of SIGTERM on $pid is: $result");
	}
}

function check1090Running(){
	global $config;
	if ($config->adsbFreq0=="1090" || $config->adsbFreq1=="1090"){
		logMessage("Checking dump1090 running");
		exec("pgrep dump1090", $pids);
		if(empty($pids)) {
			logMessage("dump1090 isn't running, we will start it");
			$str="";
			if($config->adsbFreq0=="1090"){
				$str = str_replace("<DEVINDEX>",0,$config->adsb1090String);
			}
			elseif($config->adsbFreq1=="1090"){
				$str = str_replace("<DEVINDEX>",1,$config->adsb1090String);
			}
			$str = str_replace("<TIME>",time(),$str);
			if ($str!=""){
				$pidfile="/dev/shm/1090data/dump1090.pid";
				exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $str, "/dev/null", $pidfile));
			}
		}
		else{
			$dumpPid=$pids[0];
			logMessage("dump1090 detected at PID: $dumpPid, check devNumber");
			$file = file_get_contents("/proc/$dumpPid/cmdline");
			$parts = explode("\0",$file);
			$dev=-1;
			if ($config->adsbFreq0=="1090")
				$dev=0;
			elseif ($config->adsbFreq1=="1090")
				$dev=1;
			$next = 0;
			$procDev=-1;
			foreach ($parts as $part){
				if (strstr(trim($part), "--device-index"))
					$next=1;
				elseif ($next==1){
					$procDev= $part;
					$next=0;
				}
			}
			if ($procDev==-1)
				logMessage("dump1090 is active but we could not determine which devNumber it is");
			elseif ($procDev==$dev)
				logMessage("No change to dump1090's devNumber, leaving it alone");
			else{
				logMessage("devNumber change detected. Killing dump1090 and restarting with new devNumber");
				exec("kill $dumpPid",$result);
				sleep(1); //let it die
				$str = str_replace("<DEVINDEX>",$dev,$config->adsb1090String);
				if ($str!=""){
					$pidfile="/dev/shm/1090data/dump1090.pid";
					exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $str, "/dev/null", $pidfile));
				}
			}
		}
		checkParseJsonRunning();
	}
	else{ //dump1090 should not be running!
		logMessage("Checking dump1090 is NOT running");
		exec("pgrep dump1090", $pids);
		if(!empty($pids)) {
			$pid = $pids[0];
			logMessage("rtl_sdr is running at pid $pid, have to kill it");
			exec("kill $pid");
			sleep(1);
			logMessage("Killed it.. hope its dead");
		}
		else
			logMessage("dump1090 is not running");
	}
}
function checkMotionRunning(){
	global $config;
	if ($config->motion=="1"){
		logMessage("Checking that \"motion\" is running");
		exec("pgrep motion", $pids);
		if(empty($pids)) {
			logMessage("motion isn't running, we will start it");
			$pidfile="/dev/shm/motion.pid";
			$str="motion";
			exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $str, "/dev/null", $pidfile));
		}
		else
			logMessage("Motion is running on PID: ".$pids[0]);
	}
}
function isRunning($pid){
    try{
	logMessage("Checking if pid $pid is running");
        $result = shell_exec(sprintf("ps -p %d", $pid));
        if( count(preg_split("/\n/", $result)) > 2){
		logMessage("PID $pid is running");
            return true;
        }
    }catch(Exception $e){}
	logMessage("PID $pid is NOT running");
    return false;
}
function checkParseJsonRunning(){
	global $config;
	if ($config->adsbFreq0=="978" || $config->adsbFreq0=="1090"){
		logMessage("Checking parsejson running");
		$pid=0;
		if (is_file("/dev/shm/upload/parsejson.pid"))
			$pid = file_get_contents("/dev/shm/upload/parsejson.pid");
		/*exec("pgrep php", $pids);
		$found=false;
		foreach ($pids as $pid){
			$info=file_get_contents("/proc/$pid/cmdline");
			if (strpos($info, "parsejson.php")!==false){
				$found==true;
				logMessage("Found ParseJson at PID: $pid");
			}
		}*/
		if ($pid==0 || !isRunning($pid)){
			logMessage("parsejson.php not running, starting it now!");
			$pidfile="/dev/shm/upload/parsejson.pid";
			$str="/usr/bin/php /home/pi/naboor/parsejson.php";
                        exec(sprintf("%s > %s 2>&1 & ", $str, "/dev/null"));
		}
	}
}
function check978Running(){
	global $config;
	if ($config->adsbFreq0=="978" || $config->adsbFreq1=="978"){
		logMessage("Checking dump978 running");
		exec("pgrep rtl_sdr", $pids);
		if(empty($pids)) {
			logMessage("dump978 isn't running, we will start it");
			$str="";
            if($config->adsbFreq0=="978")
                    $str = str_replace("<DEVINDEX>",0,$config->adsb978String);
            elseif($config->adsbFreq1=="978")
                    $str = str_replace("<DEVINDEX>",1,$config->adsb978String);
			$str = str_replace("<TIME>",time(),$str);
            if ($str!=""){
				$pidfile="/dev/shm/978data/dump978.pid";
    			exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $str, "/dev/null", $pidfile));
				}
		}
		else{
			$dumpPid=$pids[0];
			logMessage("dump978 detected at PID: $dumpPid, check devNumber");
			$file = file_get_contents("/proc/$dumpPid/cmdline");
			$parts = explode("\0",$file);
			$dev=-1;
			if ($config->adsbFreq0=="978")
				$dev=0;
			elseif ($config->adsbFreq1=="978")
				$dev=1;
			$next = 0;
			$procDev=-1;
			foreach ($parts as $part){
				if (strstr(trim($part), "-d"))
					$next=1;
				elseif ($next==1){
					$procDev= $part;
					$next=0;
				}
			}
			if ($procDev==-1)
				logMessage("dump978 is active but we could not determine which devNumber it is");
			elseif ($procDev==$dev)
				logMessage("No change to dump978's devNumber, leaving it alone");
			else{
				logMessage("devNumber change detected. Killing dump978 and restarting with new devNumber");
				exec("kill $dumpPid",$result);
				sleep(1); //let it die
				$str = str_replace("<DEVINDEX>",$dev,$config->adsb1090String);
				if ($str!=""){
					$pidfile="/dev/shm/978data/dump978.pid";
					exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $str, "/dev/null", $pidfile));
				}
			}
		}
		checkParseJsonRunning();
	}
	else{ //dump978 should not be running!
		logMessage("Checking dump978 is NOT running");
		exec("pgrep rtl_sdr", $pids);
		if(!empty($pids)) {
			$pid = $pids[0];
			logMessage("rtl_sdr is running at pid $pid, have to kill it");
			exec("kill $pid");
			sleep(1);
			logMessage("Killed it.. hope its dead");
		}
		else
			logMessage("dump978 is not running");
	}
	
}
function generateError($error, $subject, $point, $array){
	logMessage("Error was generated at $point; $subject; $error");
}

function makeValuesReferenced($arr){
    $refs = array();
    foreach($arr as $key => $value)
        $refs[$key] = &$arr[$key];
    return $refs;

}
function dbConnect()
{
        $dbserver="localhost";
        $dbuser="naboor";
        $dbpass="naboor";
        $dbname="naboor";
	$myDB = new mysqli($dbserver,$dbuser,$dbpass,$dbname);
	if ($myDB->connect_errno >0)
		logMessage("Connection failed on DB ".$dbname);
        return $myDB;
}
function getSQLResult(){ //point,query,format,params
        $array = func_get_args();
        $count = func_num_args();
        if ($count<1)
                generateError("Insufficient arguments","CRITIAL ERROR","NO POINT GIVEN",$array);
        if ($count<2)
                generateError("Insufficient arguments","Insufficient Arguments",$array[0],$array);
        $myDB=dbConnect();
        $query = $myDB->prepare($array[1]);
        if (!$query){
                generateError("Query went false","Query failure","GetSQLResult 1+".$array[0],$array);
        }
        if ($count>2){
                $passArr = array();
                array_push($passArr,$array[2]);
                for($i = 3;$i<$count; $i++){
                        array_push($passArr,$array[$i]);
                }
                call_user_func_array(array($query, "bind_param"),makeValuesReferenced($passArr));
                if (!$query){
                        generateError("Query went false","Query failure","GetSQLResult 2+".$array[0],$array);
                }
        }
        $query->execute();
        if (!$query ||$query->error){
                if ($query)
                        $msg="Query errored: ".$query->errorInfo();
                else
                        $msg="Query went false";
                generateError($msg,"Query failure","GetSQLResult 3+".$array[0],$array);
        }
        $result = $query->get_result();
        if (!$result)
                generateError($msg,"Result failure".$query->errorInfo(),"GetSQLResult 4+".$array[0],$array);
        return $result;
}

function execSQL(){ //point,query,format,params
        $array = func_get_args();
        $count = func_num_args();
        if ($count<1)
                generateError("Insufficient arguments","CRITIAL ERROR","NO POINT GIVEN",$array);
        if ($count<2)
                generateError("Insufficient arguments","Insufficient Arguments",$array[0],$array);
        $myDB=dbConnect();
        $query = $myDB->prepare($array[1]);
        if (!$query){
                generateError("Query went false","Query failure","execSQL 1+".$array[0],$array);
        }
        if ($count>2){
                $passArr = array();
                array_push($passArr,$array[2]);
                for($i = 3;$i<$count; $i++){
                        array_push($passArr,$array[$i]);
                }
                call_user_func_array(array($query, "bind_param"),makeValuesReferenced($passArr));
                if (!$query){
                        generateError("Query went false","Query failure","execSQL 2+".$array[0],$array);
                }
        }
        $query->execute();
        if (!$query ||$query->error){
                if ($query)
                        $msg="Query errored: ".$query->errorInfo();
                else
                        $msg="Query went false";
                generateError($msg,"Query failure","execSQL 3+".$array[0],$array);
                return false;
        }
        if (strtolower(substr($array[1],0,6))=='insert')
        	return $query->insert_id;
       	else //not an Insert, so just return true;
       		return true;
}
function uploadPic($pic, $time, $thumb, $MID){
	logMessage("Uploading file at $time, thumb status is $thumb");
	global $config;
	$post = array('action' => 'uploadPic', 'PID' => $config->PID);
	if ($thumb)
		$post["uploadType"]="thumb";
	else
		$post["uploadType"]="full";
	$post["file_contents"]=$pic;
	$post["time"]=$time;
	$query = "select PicID from Motion where MID=?";
	$result = getSQLResult("uploadPic",$query,"i",$MID);
	$row = $result->fetch_object();
	$post["PicID"]=(($row->PicID=="")?"":$row->PicID);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config->serverurl);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    $result=curl_exec($ch);
    curl_close ($ch);	
    logMessage("Upload complete, result is $result");
    $query = "Update Motion set PicID=? where MID=?";
    execSQL("interact,uploadPic",$query,"ii",$result,$MID);
    return $result;
}
function resetFlags(){
	logMessage("Resetting remote flags");
	global $config;
    $post = array('action' => 'resetFlags', 'PID' => $config->PID);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config->serverurl);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    $result=curl_exec($ch);
    curl_close ($ch);
	logMessage("Resetting complete, result is ".$result);
}
function changeSettings(){
	global $config;
	logMessage("Starting to check settings");
	$post = array('action' => 'getSettings', 'PID' => $config->PID);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config->serverurl);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    $result=curl_exec($ch);
    curl_close ($ch);
	$output = gzdecode($result);
	if (!$output)
		logMessage("Output not GZ format: $output; $result");
	$jsonout = json_decode($output);
	logMessage("Settings read from server are: $output");
	$time=time();
	foreach($jsonout as $value){
		$filename=$value->FileName;
		rename($filename,$filename.$time);
		file_put_contents($filename,$value->Value);
	}
	$config = readConfiguration();
	killprocess('motion');
	killprocess('rtl_sdr');
	killprocess('dump1090');
	check978Running();
	check1090Running();
	checkMotionRunning();
}
function checkFileVersion($filename){
	logMessage("Determining version of file: $filename");
	$file = file_get_contents($filename);
	$parts = explode("\n",$file);
	for ($i=0; $i<count($parts); $i++){
		if (strstr($parts[$i],"NABOORVERSION")!=false){
			$split = explode("=",$parts[$i]);
			if (count($split)>=2){
				$version = $split[1];
				logMessage("$filename is version $version");
				return $version;
			}
		}
	}
	logMessage("Searched the whole file and found not NABOORVERSION line");
	return 0;
}
function loadNewFile($filename){
	global $config;
	logMessage("Getting new version of File $filename");
    $post = array('action' => 'getSoftware', 'PID' => $config->PID, 'Filename'=>$filename);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config->serverurl);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    $result=curl_exec($ch);
    curl_close ($ch);
    $output = gzdecode($result);
    if (!$output){
    	logMessage("ERROR: response not in GZIP format: ".$result);
    	return false;
    }
    $size = strlen($output);
 	$result = file_put_contents("/dev/shm/tempFile",$output);
 	$newVersion = checkFileVersion("/dev/shm/tempFile");
	logMessage("Got result of size $size with version $newVersion");
	if ($newVersion>0){
		$result = rename("/dev/shm/tempFile",$filename);
		logMessage("Result of file copy is $result");
		return true;
	}
	return false;
	
}
function checkFlags(){
	global $config;
	logMessage("Checking remote Flags");
    $post = array('action' => 'getFlags', 'PID' => $config->PID);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config->serverurl);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    $result=curl_exec($ch);
    curl_close ($ch);
	logMessage("Got result: ".$result);
    $jsonout = json_decode($result);
	if ($jsonout->takePic==1){
		logMessage("Taking pic, say cheese!");
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "http://localhost:8080/0/action/snapshot");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		$result =curl_exec($ch);
		curl_close($ch);
		sleep(3); // just a little delay to take the pic
		uploadNewPics();
	}
	if ($jsonout->changeSettings==1){
		changeSettings();
	}
	if (count($jsonout->needPics)>0){
		foreach($jsonout->needPics as $PicID){
			uploadOnePic($PicID);
		}
	}
	if ($jsonout->takePic || $jsonout->changeSettings || $jsonout->forceReboot)
		resetFlags();
	
	foreach ($jsonout->Files as $filename=>$version){
		if (checkFileVersion($filename)<$version){
			loadNewFile($filename);
			if (basename($filename)=='interact.php'){
				$str="/usr/bin/php $filename";
				$pidfile="/dev/shm/naboor.pid";
				exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $str, "/dev/null", $pidfile));
				die(); 
			}
			elseif (basename($filename)=='parsejson.php'){
				if (is_file("/dev/shm/upload/parsejson.pid")){
					$pid = file_get_contents("/dev/shm/upload/parsejson.pid");
					exec("kill $pid");
					checkParseJsonRunning();
				}
			}
		}
	}
	if (property_exists($jsonout,"command")){
		//TODO:execute command and upload results to server
	}
	if (property_exists($jsonout,"mayday") && $jsonout->mayday==1){
		//TODO:open SSH session
	}
	if ($jsonout->forceReboot==1){
		//TODO:Dan, add some code here
		$str="shutdown -h now";
		exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $str, "/dev/null", $pidfile));
		die(); //should never get here
	}
	

}
function checkImmediatePic(){
	global $config;
	logMessage("Checking ImmediatePic flag");
    $post = array('action' => 'immediatePic', 'PID' => $config->PID);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config->serverurl);
    curl_setopt($ch, CURLOPT_POST,1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    $result=curl_exec($ch);
    curl_close ($ch);
	logMessage("Got result: ".$result);
    $jsonout = json_decode($result);
	if ($jsonout->takePic==1){
		logMessage("Taking pic, say cheese!");
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "http://localhost:8080/0/action/snapshot");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		$result =curl_exec($ch);
		curl_close($ch);
		sleep(3); // just a little delay to take the pic
		uploadNewPics();
		/*$pic = file_get_contents("/dev/shm/cam1/lastsnap.jpg");
		logMessage("Pic taken, file size is ".strlen($pic)." bytes");
		//dan, pick up here, need to send a file!!!
		uploadPic($pic,time(),false);
		*/
		resetFlags();
		
	}

}
function uploadOnePic($PicID){
	global $config;
	$PicIDs=array();
	logMessage("Uploading PicID $PicID");
	$query = "select MID,FileName,ThumbUpload,FullUpload,UNIX_TIMESTAMP(MotionTime) MotionTime from Motion where PicID=$PicID";
	$result = getSQLResult("uploadNewPics 2",$query);
	for($row = $result->fetch_object(); $row;$row=$result->fetch_object()){
		$PicIDs[$row->MID]=array('Filename'=>$row->FileName, 'PictureTime'=>$row->MotionTime);
	}
	foreach ($PicIDs as $PicID=>$values){
		$filename = $values["Filename"];
		$pic = file_get_contents($filename);
		logMessage("Found pic to upload PicID=$PicID filename=$filename, size=".strlen($pic));
		$upload =uploadPic($pic,$values['PictureTime'],false,$PicID);
		if ($upload){
			$query = "update Motion set ThumbUpload=1,FullUpload=1 where MID=?";
			$result = execSQL("interact,uploadNewPics $PicID",$query,"i",$PicID);
		}
	}

}
function uploadNewPics($overRideThumb=false){
	global $config;
	$thumbOnly = $config->uploadThumbOnly;
	$PicIDs=array();
	if ($thumbOnly && !($overRideThumb)){
		$query = "select MID,FileName,ThumbUpload,FullUpload,UNIX_TIMESTAMP(MotionTime) MotionTime from Motion where ThumbUpload=0";
		$result = getSQLResult("uploadNewPics 1",$query);
		for($row = $result->fetch_object(); $row;$row=$result->fetch_object()){
			$PicIDs[$row->MID]=array('Filename'=>$row->FileName, 'PictureTime'=>$row->MotionTime);
		}
		foreach ($PicIDs as $PicID=>$values){
			$filename = $values["Filename"];
			make_thumb($filename,"/dev/shm/upload/thumb.jpg",240);
			$pic = file_get_contents("/dev/shm/upload/thumb.jpg");
			$upload =uploadPic($pic,$values['PictureTime'],true,$PicID);
			if ($upload){
				$query = "update Motion set ThumbUpload=1 where MID=?";
				$result = execSQL("interact,uploadNewPics $PicID",$query,"i",$PicID);
			}
		}
	}
	else{
		$query = "select MID,FileName,ThumbUpload,FullUpload,UNIX_TIMESTAMP(MotionTime) MotionTime from Motion where FullUpload=0";
		$result = getSQLResult("uploadNewPics 2",$query);
		for($row = $result->fetch_object(); $row;$row=$result->fetch_object()){
			$PicIDs[$row->MID]=array('Filename'=>$row->FileName, 'PictureTime'=>$row->MotionTime);
		}
		foreach ($PicIDs as $PicID=>$values){
			$filename = $values["Filename"];
			$pic = file_get_contents($filename);
			logMessage("Found pic to upload PicID=$PicID filename=$filename, size=".strlen($pic));
			$upload =uploadPic($pic,$values['PictureTime'],false,$PicID);
			if ($upload){
				$query = "update Motion set ThumbUpload=1,FullUpload=1 where MID=?";
				$result = execSQL("interact,uploadNewPics $PicID",$query,"i",$PicID);
			}
		}
	}
}

//Start main code here
if (!is_dir("/dev/shm/978data"))
	mkdir("/dev/shm/978data");
if (!is_dir("/dev/shm/1090data"))
	mkdir("/dev/shm/1090data");
if (!is_dir("/dev/shm/upload"))
	mkdir("/dev/shm/upload");
if (!is_dir("/dev/shm/cam1"))
	mkdir("/dev/shm/cam1");
while (true){
	$config = readConfiguration();
	logMessage("Configuration has been read: ".json_encode($config));
	check978Running();
	check1090Running();
	checkMotionRunning();
	while (!checkConnect()){
		logMessage("No network Connection detected, connecting");
		if (!connect())
			logMessage("Connection failed");
	}
	if (!uploadADSB())
		logMessage("ADSB Upload failed.");
	getRelaySchedule();
	checkrelaySchedule();
	checkFlags();
	uploadNewPics();

	sleep($config->interactTimer);
}


?>
