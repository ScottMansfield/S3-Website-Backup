# Getting Started

### Install AWS SDK with composer: 
```
composer install
```
or 
```
php composer.phar install
```

### Update the config:
```
$config = array(
	'user'               => 'user',
	'path_to_sites'      => '/path/to/sites',
	'local_backup_days'  => 5,
	'home_dir'           => '/path/to/home/directory',
	's3_key'             => 'OMGTHISISMYKEY',
	's3_secret'          => 'PLEASEDONTSHARETHISSECRETKEYWITHANYONE',
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
```

### Test: 
```
php backup_and_upload_to_s3.php
```

### Cron:
```
0 0 * * * /path/to/backup/backup_and_upload_to_s3.php
```
or
```
0 0 * * * php /path/to/backup/backup_and_upload_to_s3.php
```