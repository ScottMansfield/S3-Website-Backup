#!/usr/bin/php5

<?php

// Autoload composer packages!
require 'vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Common\Enum\Size;
use Aws\Common\Exception\MultipartUploadException;
use Aws\S3\Model\MultipartUpload\UploadBuilder;

// the above should be changed to the path of the php 5.3
// executable on your system

error_reporting(E_ALL);

################################################################################
### Config Section
################################################################################

$config = array(
	'user'               => 'user',
	'path_to_sites'      => '/path/to/sites',
	'local_backup_days'  => 5,
	'home_dir'           => '/path/to/home/directory',
	's3_key'             => 'OMGTHISISMYKEY',
	's3_secret'          => 'PLEASEDONTSHARETHISSECRETKEYWITHANYONE', // must have trailing forward slash
	'bucket'             => 'mr-bucket-rules',
	'chunk_size_in_MB'   => 10,
	'remote_backup_days' => 10
);

$sites = array(
	'example.com' => array(
		'has_db'  => false),
	'blog.example.com' => array(
		'has_db'  => true,
		'db_host' => 'mysql.example.com',
		'db_name' => 'my_blog_db',
		'db_user' => 'bloguser',
		'db_pass' => 'correct horse battery staple')
	);

################################################################################
### Backup Section
################################################################################

$now = time();
$date = date("Y-m-d-H:i:s", $now);

// Takes script location and creates temp directory for backups
$backup_dir = __DIR__ . '/backup_' . $date . '/';
ensure_dir_exists($backup_dir);
	
// Backup all mysql databases
$mysql_backup_dir = $backup_dir . '/mysql/';
ensure_dir_exists($mysql_backup_dir);

foreach($sites as $site_name => $details)
{
	if ($details['has_db'])
	{
		echo 'Dumping ' . $details['db_name'] . PHP_EOL;
		
		// backup_dir/mysql/db_backup_dbname_0000_00_00_00:00:00.bak.gz
		$path = $mysql_backup_dir . 'db_backup_' .
				escapeshellarg($details['db_name']) . '_' . $date . '.bak.sql.gz';
		
		// mysqldump -h host.example.com -u user -ppassword dbname | gzip > path
		$command = 'mysqldump -h ' . escapeshellarg($details['db_host']) .
				   ' -u ' . escapeshellarg($details['db_user']) . ' -p' .
				   escapeshellarg($details['db_pass']) . ' ' .
				   escapeshellarg($details['db_name']). ' | gzip > ' . $path;
		
		exec($command);
	}
}

echo 'MySQL dumps complete.' . PHP_EOL . PHP_EOL;

// Backup all website data
$site_backup_dir = $backup_dir . '/sites/';
ensure_dir_exists($site_backup_dir);

foreach($sites as $site_name => $details)
{
	echo 'Archiving ' . $site_name . PHP_EOL;
	
	// this script is meant to reside in a backup dir beside website dirs
	$site_dir = $config['path_to_sites'] . '/' . $site_name;
	$path = $site_backup_dir . 'site_backup_' . $site_name . '_' . $date .
			'.bak.tar.gz';
	
	
	$command = 'tar czf ' . escapeshellarg($path) . ' -C ' .
				escapeshellarg($site_dir) . ' ' . escapeshellarg($site_dir);
	exec($command);
}
echo 'Site archiving complete.' . PHP_EOL . PHP_EOL;

// Bundle into one big archive
echo 'Bundling into one big file' . PHP_EOL;

$file_name = __DIR__ . '/backup_' . $config['user'] . '_' . $date . '.bak.tar.gz';

// --force-local is needed because of the colons in the file name
$command = 'tar czf ' . escapeshellarg($file_name) . ' ' .
			escapeshellarg($backup_dir) . ' --force-local';
exec($command);

echo 'Bundling complete.' . PHP_EOL . PHP_EOL;

// Delete all temp files
// rmdir() function requires the file to be empty, it's easier to rely on
// the rm -R shell command to recursively delete the files
echo 'Cleaning up temporary files.' . PHP_EOL;
exec('rm -R ' . $backup_dir);
echo 'Temp files deleted.' . PHP_EOL . PHP_EOL;

// Delete local archive files older than configured number of days
$dir_handle = opendir(__DIR__);
$days = intval($config['local_backup_days']);

echo "Removing backups older than $days days from local system." . PHP_EOL;

$file_cutoff_time = $now - ($days*24*60*60);

// if it isnt a implied directory and is an old backup, delete the file
while (($file = readdir($dir_handle)) !== false)
{
	if ($file != '.' &&
		$file != '..' &&
		strpos($file, 'backup_' . $config['user']) === 0 &&
		$file_cutoff_time > filemtime(__DIR__ . '/' . $file))
	{
		echo 'Deleting ' . $file . PHP_EOL;
		unlink(__DIR__ . '/' . $file);
	}
}

echo "Backups older than $days days removed from local system." .
		PHP_EOL . PHP_EOL;

echo 'Cleanup complete.' . PHP_EOL . PHP_EOL;

################################################################################
### S3 section
################################################################################

putenv('HOME=' . $config['home_dir']);

define('MB', 1024 * 1024);

$s3 = S3Client::factory(array(
	'key' => $config['s3_key'],
	'secret' => $config['s3_secret']
));

$bucket = $config['bucket'];
$file = fopen($file_name, 'r');

if (!$file) die("Could not open $file_name for upload." . PHP_EOL);

$upload_file_name = basename($file_name);

echo 'Creating object.' . PHP_EOL . PHP_EOL;
$uploader = UploadBuilder::newInstance()
    ->setClient($s3)
    ->setSource($file)
    ->setBucket($bucket)
    ->setKey($upload_file_name)
    ->setConcurrency(3)
    ->build();

// Perform the upload. Abort the upload if something goes wrong
try {
    $uploader->upload();
    echo "Upload complete.\n";
    echo "File $upload_file_name successfully uploaded to $bucket ." . PHP_EOL . PHP_EOL;
} catch (MultipartUploadException $e) {
    $uploader->abort();
    echo "Upload failed.\n";
    echo "Upload of file $upload_file_name to $bucket failed." . PHP_EOL . PHP_EOL;
	die();
}

echo PHP_EOL;

// release resources
fclose($file);

echo "Finished uploading." . PHP_EOL . PHP_EOL;

// delete remote backups older than the specified number of days
$days = intval($config['remote_backup_days']);

echo "Removing backups older than $days days from S3." . PHP_EOL;

$file_cutoff_time = $now - ($days*24*60*60);
$interval = new DateInterval('P' . $days . 'D');

// grab only the backup files from the bucket
// will return 1000 results (if there are that many)
$iterator = $s3->getIterator('ListObjects', array(
    'Bucket' => $bucket,
));

// essentially I am using the DateTime class to parse the string I get back
foreach($iterator as $file)
{
	$time = new DateTime($file['LastModified']);
	$stamp = $time->getTimestamp();
	if ($stamp < $file_cutoff_time)
	{
		echo 'Deleting ' . $file['Key'] . PHP_EOL;
		$s3->deleteObjects(array(
    		'Bucket' => $bucket,
    		'Objects' => array(
    				array( 'key' => $file['Key'] )
    		)
    	));
	}
}

echo "Backups older than $days days removed from S3." .
		PHP_EOL . PHP_EOL;

echo 'Process Complete.' . PHP_EOL . PHP_EOL;

################################################################################
### Helper functions
################################################################################

function ensure_dir_exists($dir)
{
	if (!file_exists($dir))
	{
		$old_umask = umask(0);
		mkdir($dir, 0777, true);
		umask($old_umask);
	}
}
