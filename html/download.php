<?php
init();
$id=getID();
if(false===$id){
	die('No publicid. no hiddenid. nothing to do...');
}
require_once('./../getdb.inc.php');
//debug code b64: dmFyX2R1bXAoJ3B1YmxpYyBqb2luOicsDQokZGItPnF1ZXJ5KA0KJ1NFTEVDVCAqIEZST00gYHB1YmxpY19maWxlc2AgTEVGVCBKT0lOIGBmaWxlc19tZXRhZGF0YWAgT04gYHB1YmxpY19maWxlc2AuYGZpbGVfbWV0YWRhdGFfaWRgID0gYGZpbGVzX21ldGFkYXRhYC5gaWRgJw0KKS0+ZmV0Y2hBbGwoUERPOjpGRVRDSF9BU1NPQyksDQonaGlkZGVuIGpvaW46JywNCiRkYi0+cXVlcnkoDQonU0VMRUNUICogRlJPTSBgaGlkZGVuX2ZpbGVzYCBMRUZUIEpPSU4gYGZpbGVzX21ldGFkYXRhYCBPTiBgaGlkZGVuX2ZpbGVzYC5gZmlsZV9tZXRhZGF0YV9pZGAgPSBgZmlsZXNfbWV0YWRhdGFgLmBpZGAnDQopLT5mZXRjaEFsbChQRE86OkZFVENIX0FTU09DKSwNCidldmVyeXRoaW5nIHB1YmxpYzonLA0KJGRiLT5xdWVyeSgnDQpTRUxFQ1QgKiBGUk9NIHB1YmxpY19maWxlcw0KJyktPmZldGNoQWxsKFBETzo6RkVUQ0hfQVNTT0MpLA0KJ2V2ZXJ5dGhpbmcgaGlkZGVuOicsDQokZGItPnF1ZXJ5KCcNClNFTEVDVCAqIEZST00gaGlkZGVuX2ZpbGVzDQonKS0+ZmV0Y2hBbGwoUERPOjpGRVRDSF9BU1NPQyksDQonZXZlcnl0aGluZyBtZXRhZGF0YTonLA0KJGRiLT5xdWVyeSgnDQpTRUxFQ1QgKiBGUk9NIGZpbGVzX21ldGFkYXRhDQonKS0+ZmV0Y2hBbGwoUERPOjpGRVRDSF9BU1NPQykNCik7ZGllKCk7


//header("content-type: application/json;charset=utf8");
header("content-type: text/plain;charset=utf8");
if($id[0]==='hiddenid'){
	$stm=$db->prepare('
	SELECT `file_content_type`,`data_name`,`password_hash`,`password_hash_type`,
	`expire`,`local_filename`,`compression` FROM `hidden_files` LEFT JOIN `files_metadata` ON `hidden_files`.`file_metadata_id` = `files_metadata`.`id` WHERE `hidden_files`.`hiddenid` = :id');
}elseif($id[0]==='publicid'){
	$stm=$db->prepare('
	SELECT `file_content_type`,`data_name`,`password_hash`,`password_hash_type`,
	`expire`,`local_filename`,`compression` FROM `public_files` LEFT JOIN `files_metadata` ON `public_files`.`file_metadata_id` = `files_metadata`.`id` WHERE `public_files`.`id` = :id');
} else {
	throw new LogicException('UNREACHABLE');
}

$stm->execute(array(':id'=>$id[1]));
$res=$stm->fetch(PDO::FETCH_ASSOC);
if(false===$res){
	header("HTTP/1.0 404 Not Found",true,404);
	die('404 Not Found: '.$id[0].' '.$id[1].' is not found. it may have expired, been deleted, or never existed at all.');
}
if($res['expire']!=='-1' && time()>=(int)$res['expire']){
header("HTTP/1.0 410 Gone",true,410);
	die('410 Gone: this file expired on '.date(DateTime::ISO8601,(int)$res['expire']));
}
if(is_string($res['password_hash']) && 0<strlen($res['password_hash'])){
	if(!isset($_GET['password'])){
	header("HTTP/1.0 403 Forbidden",true,403);
	die('this file is password protected, and no password supplied.');
	}
	if(passwordHashV1($_GET['password'])!==$res['password_hash']){
	header("HTTP/1.0 403 Forbidden",true,403);
	die('wrong password');
	}
}
$fullFilePath=hhb_combine_filepaths($files_folder,$res['local_filename']);
if(!file_exists($fullFilePath)){
	throw new Exception("CORRUPTED DATABASE! FILE FOR ".var_export($id,true).' DOES NOT EXIST!');
}

    header('Content-Description: File Transfer');
    header('Content-Type: '.$res['file_content_type']);
    header('Content-Disposition: attachment; filename="'.$res['data_name'].'"');//dont worry, data_name in db is already sanitized... or is supposed to be....
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
if($res['compression']==='0'){
$size=filesize($fullFilePath);
    header('Content-Length: '.$size);
	if(($read=readfile($fullFilePath))!==$size){
		throw new Exception('Could only read '.$read.' bytes of a .'.$size.' bytes file! id: '.var_export($id,true));
	}
	die();//done :)
}elseif($res['compression']==='1'){
//<hanshenrik> how do i get uncompressed size of a gz compressed file?
//<hanshenrik> open and seek? 
//<TML-prv> hanshenrik: if the compressed size was <4GB, it's the last 4 bytes of the file
// (pos1 << 24) | (pos2 << 16) + (pos3 << 8) + pos4
// unpack('V',file_get_contents(fpath,false,NULL,filesize(fpath)-4,4)))
// TODO: optimize the code to find the length.
	ob_start();
	readgzfile($fullFilePath);
	$data=ob_get_clean();
	$size=strlen($data);
    header('Content-Length: ' . $size);
	echo $data;
	die();//done :)
} else {
	throw new Exception('Unknown compression level: '.var_export($res['compression'],true));
}
throw new LogicException('UNREACHABLE');




function getID(){
	if(isset($_GET['publicid'])){
		return array('publicid',(string)$_GET['publicid']);
	}elseif(isset($_GET['hiddenid'])){
		return array('hiddenid',(string)$_GET['hiddenid']);
	}else {
		return false;
	}
}
function init(){
	require_once('./../hhb_.inc.php');
	hhb_init();
}