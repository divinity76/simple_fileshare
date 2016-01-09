<?php
require_once('hhb_.inc.php');
//theoretically, you can use mysql like 
//$db = new PDO('mysql:host=localhost;dbname=simple_fileshare_db;charset=utf8', 'username', 'password',
//array(PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
//but for now, its SQLite.
$dbpath=hhb_combine_filepaths(__DIR__,'simple_fileshare_db.sqlite3');
if(!file_exists($dbpath)){
	die('dbpath does not exist! create the db with createdb.php first...');
}
$db=new PDO('sqlite:'.$dbpath,'','',array(PDO::ATTR_EMULATE_PREPARES => false,PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$files_folder=$db->query('SELECT `filesfolder` FROM `config` WHERE `id` = 1;')->fetch(PDO::FETCH_NUM)[0];
$default_compression=(int)$db->query('SELECT `default_compression` FROM `config` WHERE `id` = 1;')->fetch(PDO::FETCH_NUM)[0];
$download_url=$db->query('SELECT `download_api_v1_url` FROM `config` WHERE `id` = 1;')->fetch(PDO::FETCH_NUM)[0];


function passwordHashV1($password){
	if(!is_string($password) || 0>=strlen($password)){
		return '';
	}
	$ret=str_replace(array('+','/','='),array('-','_','.'),base64_encode(hash('sha1',hash('sha256',$password,true),true)));
	return $ret;
}
