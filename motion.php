<?PHP
$config="";
function makeValuesReferenced($arr){
    $refs = array();
    foreach($arr as $key => $value)
        $refs[$key] = &$arr[$key];
    return $refs;

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
		syslog(LOG_INFO, "Naboor motion: ".$msg);
	echo "$msg\n"; //DEBUG
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

if ($argc<2)
	logMessage("Insufficient arguments argc=$argc");
$filename = $argv[1];
logMessage("Motion detected with filename: $filename");
$newfilename = "/dev/shm/upload/".time().".jpg";
$result = copy($filename, $newfilename);
if ($result){
	logMessage("Moving file from: $filename to #newfilename result=$result");
	$query = "insert into Motion (Filename, MotionTime) VALUES (?,NOW())";
	$result = execSQL("motion 1",$query,"s",$newfilename);
	logMessage("Insert into SQL result=$result");
}


?>