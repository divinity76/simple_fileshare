<?php 
init();
$dbpath=hhb_combine_filepaths(__DIR__,'simple_fileshare_db.sqlite3');
$filesfolder=hhb_combine_filepaths(__DIR__,'files_folder').'/';
if(file_exists($dbpath)){
	die('db already exist! delete the old db before creating a new 1...');
}
if(is_dir($filesfolder) || file_exists($filesfolder)){
	die('filesfolder already exists! delete the filesfolder before recreating the database. '.$dbpath);
}
if(!mkdir($filesfolder,0664)){//-rw-rw-r--
	die('unable to create folder '.$filesfolder);
}
if(false===file_put_contents(hhb_combine_filepaths($filesfolder,'index.html'),'NO AUTOINDEX ON THIS FOLDER!')){
	die('uname to create file inside folder.');
}
if(false===file_put_contents($dbpath,'test if we can create the db file')){
	die("Maybe db folder is readonly! cannot create the db file: ".$dbpath);
}
if(false===file_put_contents($dbpath,'')){
	die("Cannot truncate the dbfile! : ".$dbpath);
}
$db=new PDO('sqlite:'.$dbpath,'','',array(PDO::ATTR_EMULATE_PREPARES => false,PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$schema=file_get_contents('sqlite3_schema.sql');
assert(false!==$schema);
$configsql='INSERT INTO `config` (`id`,`default_compression`,`filesfolder`,`download_api_v1_url`) VALUES(1,0,'.$db->quote($filesfolder).','.$db->quote('https://ratma.net/simple_fileshare/download.php').');';
$hash_types_sql='
INSERT INTO `hash_types` (`id`,`hash_description`) 
VALUES(1,
'.$db->quote('str_replace(array(\'+\',\'/\',\'=\'),array(\'-\',\'_\',\'.\'),base64_encode(hash(\'sha1\',hash(\'sha256\',$phrase,true),true)))').');';
var_dump('schema:',$schema,'configsql:',$configsql,'hash_types_sql',$hash_types_sql);
$db->query('BEGIN EXCLUSIVE TRANSACTION;');
$db->exec($schema);
//var_dump($db->query('SELECT * FROM sqlite_master')->fetchAll(PDO::FETCH_ASSOC));die();
$db->query($configsql);

$db->query($hash_types_sql);
$db->query('COMMIT;');
die("Database created!");







function init(){
	require_once('hhb_.inc.php');
	hhb_init();
}