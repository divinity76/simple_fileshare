<?php
init();
if(isXHR()){
	die('XMLHttpRequest uploads are currently blocked, sorry...');
}
//hhb_var_dump(validateDataName(getDataName()),getDataName());
//dumpInputAndDie();

class response {
	function toJson(){
		$ret=(array)($this);//is this considered abuse?
		if(empty($ret['errors'])){
			unset($ret['errors']);
			if(0>=strlen($ret['status'])){
				$ret['status']='OK';//i guess....
			}
		} else {
			if(0>=strlen($ret['status'])){
				$ret['status']='ERROR';//i guess....
			}
		}
		if(empty($ret['warnings'])){
			unset($ret['warnings']);
		}
		if(0>=strlen($ret['url'])){
			unset($ret['url']);
		}
		if(0>=strlen($ret['final_filename'])){
			unset($ret['final_filename']);
			
		}
		if(0>=strlen($ret['final_content_type'])){
			unset($ret['final_content_type']);
		}
		$ret=json_encode($ret, JSON_BIGINT_AS_STRING | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ~JSON_HEX_TAG);
		$ret=str_replace('\u0026password=','&password=',$ret);//UGLY HACK! Its either this hack, or making a php userland json encoder..
		//What appears to be a bug in PHP from at least 5.4.0 makes JSON_HEX_TAG implicitly enabled, with no way to disable it..
		//i should open a bugreport..
		return $ret;
	}
	public $errors=array();
	public $warnings=array();
	public $url="";
	public $status="error";
	public $final_filename="";
	public $final_content_type;
	public $final_expiration_timestamp="";
};

$response=new response();
//var_dump($response->toJson());
register_shutdown_function(function() use(&$response){
	echo $response->toJson();
});
if(!hasData()){
	$response->errors[]='upload_data NOT found in POST data! this variable is required.';
	return false;
}
$dataContentType=getDataContentType();
$response->final_content_type=$dataContentType;
$strictFilename=getStrictFilenameOption();
$dataName=getDataName($dataContentType);
$expire=getExpire();
$response->final_expiration_timestamp=$expire;
$hidden=getHidden();
$uploadDate=time();
$failOffset=0;
if(!validateDataName($dataName,$failOffset)){
	if($strictFilename){
		$response->errors[]='dataName is invalid, and strict_filename is enabled. can not continue. offending dataName character start at byte offset '.$failOffset;
		return false;
	}
	$dataName=sanitizeDataName($dataName);
	$response->warnings[]='dataName is invalid. offending dataName character start at byte offset '.$failOffset.'. dataName has been transliterated/sanitized from UTF8 to ASCII with iconv, and truncated to 255 bytes. the new dataName is: '.$dataName;
}
$response->final_filename=$dataName;
$clientIP=getClientIP();


if((!isset($_GET['response_type']) && !isset($_POST['response_type']))){
	$responseType='json';//currently unused...
}

require_once('./../getdb.inc.php');
$passwordHash=getPasswordHash();
$localFilename=generateLocalFilename();
$fullFilePath=hhb_combine_filepaths($files_folder,$localFilename);
if(!file_exists($fullFilePath)){
	if(isset($_POST['upload_data'])){
		if(($tmpi1=strlen($_POST['upload_data']))!==($tmpi2=file_put_contents($fullFilePath,$_POST['upload_data']))){
			@unlink($fullFilePath);//attempt cleanup of corrupted file...
			$response->errors[]='internal server error. tried to write '.var_export($tmpi1,true).' bytes to disk, but could only write '.var_export($tmpi2,true).' bytes!';
			return false;
			throw new Exception('TODO: HANDLE THIS ERROR');
		}
	}elseif(is_string($_FILES['upload_data']['tmp_name'])){
		if(!move_uploaded_file($_FILES['upload_data']['tmp_name'],$fullFilePath)){
			$response->errors[]='internal server error. could not move the uploaded file to the files directory.';
			return false;
		}
	} else {
		throw new LogicException('UNREACHABLE');
	}
	$stm=$db->prepare('INSERT OR REPLACE INTO `files_metadata` (`local_filename`,`compression`) VALUES(:local_filename,0);');
	$stm->execute(array(':local_filename'=>$localFilename));
	$file_metadata_id=$db->lastInsertID();
	unset($stm,$tmpi1,$tmpi2);
}
if(empty($file_metadata_id)){
	$file_metadata_id=$db->query('SELECT `id` FROM `files_metadata` WHERE `local_filename` = '.$db->quote($localFilename))->fetch(PDO::FETCH_NUM)[0];
}
assert(!empty($file_metadata_id));

if($hidden){
	$hiddenId=getRandomHiddenId();
	$stm=$db->prepare('INSERT INTO `hidden_files` 
	(`hiddenid`,`file_metadata_id`,`file_content_type`,`data_name`,`password_hash`,`password_hash_type`,`expire`,`upload_ip`,`upload_date`) VALUES(
	:hiddenid,:file_metadata_id,:file_content_type,:data_name,:password_hash,:password_hash_type,:expire,:upload_ip,:upload_date);');
	$stm->execute(array(
	':hiddenid'=>$hiddenId,':file_metadata_id'=>$file_metadata_id,':file_content_type'=>$dataContentType,':data_name'=>$dataName,':password_hash'=>$passwordHash,
	':password_hash_type'=>'1',':expire'=>$expire,
	':upload_ip'=>$clientIP,':upload_date'=>$uploadDate
	));
	$response->url=$download_url.'?hiddenid='.urlencode($hiddenId).(isset($_POST['password'])?'&password='.urlencode($_POST['password']):'');
} else {
	$stm=$db->prepare('INSERT INTO `public_files`(`file_metadata_id`,`file_content_type`,`data_name`,`password_hash`,`password_hash_type`,`expire`,`upload_ip`,`upload_date`) 
	VALUES(:file_metadata_id,:file_content_type,:data_name,:password_hash,:password_hash_type,:expire,:upload_ip,:upload_date);');
	$stm->execute(array(
	':file_metadata_id'=>$file_metadata_id,':file_content_type'=>$dataContentType,':data_name'=>$dataName,':password_hash'=>$passwordHash,':password_hash_type'=>'1',
	':expire'=>$expire,':upload_ip'=>$clientIP,':upload_date'=>$uploadDate
	));
	$id=$db->lastInsertID();
	$response->url=$download_url.'?publicid='.urlencode($id).(isset($_POST['password'])?'&password='.urlencode($_POST['password']):'');
}
$response->status="OK";
return true;
function getRandomHiddenId(){
	global $db;
	$ret="";
	do{
	$ret=str_replace(array('+','/','='),array('-','_','.'),base64_encode(openssl_random_pseudo_bytes(12)));
	}while('0'!=$db->query('SELECT COUNT(*) FROM `hidden_files` WHERE `hiddenid` = '.$db->quote($ret))->fetch(PDO::FETCH_NUM)[0]);//on most systems, the chance of this running twice is probably astronomical
	return $ret;
}
function isXHR(){
	if(isset($_POST['X-Requested-With']) && $_POST['X-Requested-With']==='XMLHttpRequest'){
		return true;
	}
	if(isset($_POST['Requested-With']) && $_POST['Requested-With']==='XMLHttpRequest'){
		return true;
	}
	return false;
}
function getPasswordHash(){
	//
	if(!isset($_POST['password'])){
		return '';
	}
	return passwordHashV1($_POST['password']);
}
function getHidden(){
	return !empty($_POST['hidden']);
}
function getExpire(){
	if(empty($_POST['expire'])){
		$ret=1*60*60*24*365;//1 year.
		$ret=time()+$ret;
		return $ret;
	}elseif($_POST['expire']==='-1'){
		return -1;
	}
	$ret=abs((int)$_POST['expire']);
	if($ret===0){
		$ret=1*60*60*24*365;
	}
	$ret+=time();
	return $ret;
}
function generateLocalFilename(){
	//sha1 deduplication scheme..
	if(isset($_POST['upload_data'])){
		return hash('sha1',$_POST['upload_data'],false);//be happy PHP does COW'ing and string deduplication behind the scene...
	}elseif(is_string($_FILES['upload_data']['tmp_name'])){
		return hash_file('sha1',$_FILES['upload_data']['tmp_name'],false);
	}
	throw new LogicException('failed to find the data content!');
}
function getClientIP(){
	return ip2long($_SERVER['REMOTE_ADDR']);
}
function getStrictFilenameOption(){
	if(!empty($_POST['strict_filename'])){
		return true;
	}
	return false;
}
function sanitizeDataName($dataName,$dataContentType='application/octet-stream'){
	//setlocale(LC_ALL, 'en_US.UTF8');
	$dataName=iconv('utf8','ASCII//TRANSLIT',$dataName);
	$dnl=strlen($dataName);
	$retName="";
	for($i=0;$i<$dnl;++$i){
		//TODO: is this a fast way to do it? kinda feel like it is not...
		if(""===trim($dataName[$i],' !#$%&0\'()+,-.0123456789;=@ABCDEFGHIJKLMNOPQRSTUVWXYZ[]^_`abcdefghijklmnopqrstuvwxyz{}~')){
			$retName.=$dataName[$i];
		}
	}
	if($retName==='.' || $retName === '..'){
		$retName='....';
	}
	if(strlen($retName)>255){
		$retName=substr($retName,0,255);
	}
	if(strlen($retName)<=0){
		if($dataContentType==='text/plain;charset=utf8'){
			return 'untitled.txt';
		}else {
			return 'untitled.bin';
		}
	}
	if(!validateDataName($retname)){
		throw new LogicException();//should never happen...
	}
	return $retName;
}
function validateDataName($dataName,&$failOffset=0){
	//valid characters: 
	//  !#$%&0'()+,-.0123456789;=@ABCDEFGHIJKLMNOPQRSTUVWXYZ[]^_`abcdefghijklmnopqrstuvwxyz{}~ 
	// max dataName length: 255 bytes
	if(strlen($dataName)<=0){
		$failOffset=0;
		return false;
	}
	if(strlen($dataName)>255){
		$failOffset=256;
		return false;
	}

	//. and .. are blacklisted filenames... TODO: document this.
	if($dataName==='.'){
		$failOffset=0;
		return false;		
	}
	if($dataName==='..'){
		$failOffset=1;
		return false;		
	}
	$matches=array();
	$ret=preg_match('/(?:[^'.preg_quote(' !#$%&0\'()+,-.0123456789;=@ABCDEFGHIJKLMNOPQRSTUVWXYZ[]^_`abcdefghijklmnopqrstuvwxyz{}~','/').'])/',$dataName,$matches,PREG_OFFSET_CAPTURE);
	if($ret===0){
		return true;
	}
	$failOffset=$matches[0][1];
	return false;
}

function getUploadData(){
	assert(true===hasData());//Do not call this function before hasData()
	if(isset($_POST['upload_data'])){
		return (string)$_POST['upload_data'];//be happy PHP does COW'ing and string deduplication behind the scene...
	}elseif(is_string($_FILES['upload_data']['tmp_name'])){
		$fp=fopen($_FILES['upload_data']['tmp_name'],'rb');
		assert(false!==$fp);
		return $fp;//up to the caller to fclose(); ...
	}else{
		throw new LogicException('getUploadData failed to find the data!');
	}
}

function getDataContentType(){
	global $response;
	$isAscii=function($data){
		$dlen=strlen($data);
		$cur='';
		for($i=0;$i<$dlen;++$i){
			$cur=ord($data[$i]);
			if($cur<0x20 || $cur>0x7E){
				return false;
			}
		}
		return true;
	};
	$type='';
	if(empty($_POST['upload_data_content_type']) || $_POST['upload_data_content_type']==='autodetect'){
		$type='autodetect';
	} elseif($_POST['upload_data_content_type']==='application/octet-stream'){
		$type='application/octet-stream';
	}elseif($_POST['upload_data_content_type']==='text/plain;charset=utf8'){
		$type='text/plain;charset=utf8';
	} else {
		$response->warnings[]='unsupported upload_data_content_type! treating as application/octet-stream';
		$type='application/octet-stream';
	}
	if($type==='autodetect'){
		 $data=getUploadData();
		 if(is_string($data)){
			 if($isAscii($data)){
				 return 'text/plain;charset=utf8';
			 } else {
				 return 'application/octet-stream';
			 }
		 } else {
			 $datapiece="";
			 while(false!==($datapiece=fread($data,10*1024*1024))){
				if(!$isAscii($datapiece)){
					fclose($data);
					return 'application/octet-stream';
				}
			 }
			 fclose($data);
			 return 'text/plain;charset=utf8';
		 }
	}elseif($type==='text/plain;charset=utf8'){
		return 'text/plain;charset=utf8';
	}elseif($type==='application/octet-stream'){
		return 'application/octet-stream';
	} else {
		throw new LogicException('THIS SHOULD BE UNREACHABLE! FIX YOUR CODE');
	}
}
function getDataName($dataContentType='application/octet-stream'){
	global $response;
	if(!empty($_POST['upload_data_name'])){
		if(!empty($_FILES['upload_data']['filename'])){
			$response->warnings[]='Content-Disposition filename ignored because upload_data_name is provided.';
		}
		return (string)$_POST['upload_data_name'];
	}
	if(!empty($_FILES['upload_data']['name'])){
		return (string)$_FILES['upload_data']['name'];
	}
	if($dataContentType==='text/plain;charset=utf8'){
		return 'untitled.txt';
	} else{
		return "untitled.bin";
	}
}
function hasData(){
	if(isset($_POST['upload_data'])){
		return true;
	}
	if(isset($_FILES['upload_data'])){
		return true;
	}
	return false;
}



function init(){
	require_once('./../hhb_.inc.php');
	hhb_init();
	//header("content-type: application/json;charset=utf8");
	//I wish i could use the application/json, but it does not play well with Internet Explorer.
	header("content-type: text/plain;charset=utf8");
}
function dumpInputAndDie(){
header("content-type: text/plain;charset=utf8");
var_dump('$_GET:',$_GET,'$_POST:',$_POST,'$_COOKIE',$_COOKIE,'$_FILES',$_FILES);
	die();
}