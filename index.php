<?php
//
//  Copyright (c) 2016 Mr. Gecko's Media (James Coleman). http://mrgeckosmedia.com/
//
//  Permission to use, copy, modify, and/or distribute this software for any purpose
//  with or without fee is hereby granted, provided that the above copyright notice
//  and this permission notice appear in all copies.
//
//  THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH
//  REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY AND
//  FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT, INDIRECT,
//  OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES WHATSOEVER RESULTING FROM LOSS OF USE,
//  DATA OR PROFITS, WHETHER IN AN ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS
//  ACTION, ARISING OUT OF OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
//

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_STRICT);

function error($error) {
	echo $error;
	exit();
}

$_MGM = array();
$_MGM['version'] = "1";
$_MGM['title'] = "GeckoDNS";
$_MGM['author'] = "Mr. Gecko";
$_MGM['DBType'] = "SQLITE"; // MYSQL, POSTGRESQL, SQLITE.
$_MGM['DBPersistent'] = false;
$_MGM['DBHost'] = "localhost";
$_MGM['DBUser'] = "";
$_MGM['DBPassword'] = "";
$_MGM['DBName'] = "databases/main.db"; // File location for SQLite.
$_MGM['DBPort'] = 0; // 3306 = MySQL Default, 5432 = PostgreSQL Default.
$_MGM['DBPrefix'] = "";
$_MGM['adminEmail'] = "default@domain.com";
require_once("db{$_MGM['DBType']}.php");

putenv("TZ=US/Central");
$_MGM['time'] = time();

if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR']!="")
	$_MGM['ip'] = $_SERVER['REMOTE_ADDR'];
/*if (isset($_SERVER['HTTP_PC_REMOTE_ADDR']) && $_SERVER['HTTP_PC_REMOTE_ADDR']!="")	
	$_MGM['ip'] = $_SERVER['HTTP_PC_REMOTE_ADDR'];
if (isset($_SERVER['HTTP_CLIENT_IP']) && $_SERVER['HTTP_CLIENT_IP']!="")
	$_MGM['ip'] = $_SERVER['HTTP_CLIENT_IP'];
if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR']!="")
	$_MGM['ip'] = $_SERVER['HTTP_X_FORWARDED_FOR'];*/

$_MGM['nsupdatePath'] = "/usr/local/bin/nsupdate";
$_MGM['defaultTTL'] = 1800;
$_MGM['debug'] = false;

function hashPassword($password, $salt) {
	return hash_pbkdf2("sha512", $password, $salt, 1000);
}

if (isset($_REQUEST['passwd']))  {
	if (isset($_REQUEST['password'])) {
		$salt = mcrypt_create_iv(16, MCRYPT_DEV_URANDOM);
		
		$password = hashPassword($_REQUEST['password'], $salt);
		echo bin2hex($salt).$password;
		exit();
	}
	?>
	<html>
	<head>
		<title>Generate password for storage in <?=$_MGM['title']?> database.</title>
	</head>
	<body>
		<form action="?passwd" method="post">
			<input type="text" name="password" placeholder="Password" autocomplete="off" />
			<input type="submit" value="Generate Password" />
		</form>
	</body>
	<?
	exit();
}

connectToDatabase();

if (isset($_REQUEST['username']) && isset($_REQUEST['password'])) {
	$result = databaseQuery("SELECT * FROM users WHERE username=%s AND level!=0", $_REQUEST['username']);
	$user = databaseFetchAssoc($result);
	
	if ($user!=NULL) {
		$salt = hex2bin(substr($user['password'], 0, 32));
		$password = substr($user['password'], 32);
		if ($password==hashPassword($_REQUEST['password'], $salt)) {
			$_MGM['user'] = $user;
		}
	}
}

header("Content-Type: text/plain");

if (!isset($_MGM['user'])) {
	echo "Invalid Credentials";
	closeDatabase();
	exit();
}

databaseQuery("UPDATE users SET lastmessage=%s WHERE username=%s", $_MGM['time'], $_MGM['user']['username']);

$dblast = "";
$nsrecord = "";
if (filter_var($_MGM['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
	$dblast = "lastv4";
	$nsrecord = "A";
} else if (filter_var($_MGM['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
	$dblast = "lastv6";
	$nsrecord = "AAAA";
}

if ($dblast=="") {
	echo "Invalid IP Address Detected";
	closeDatabase();
	exit();
}

if ($_MGM['user'][$dblast]!=$_MGM['ip']) {
	echo "New IP: ".$_MGM['ip']."\n\n";
	
	$updateFile = tempnam(sys_get_temp_dir(), 'GeckoDNS');
	$fh = fopen($updateFile, 'w');
	fwrite($fh, "server ".$_MGM['user']['server']."\n");
	fwrite($fh, "debug yes\n");
	fwrite($fh, "zone ".$_MGM['user']['zone']."\n");
	
	$hosts = explode(",", $_MGM['user']['hosts']);
	foreach ($hosts as $host) {
		$suffix = substr($host, strlen($host)-strlen($_MGM['user']['zone']));
		if ($suffix!=$_MGM['user']['zone'] && !preg_match('/^[a-zA-Z0-9.-*]+$/', $host)) {
			echo "Host: ".$host."\n\n";
			echo "Invalid Configuration";
			fclose($fh);
			unlink($updateFile);
			closeDatabase();
			exit();
		}
		fwrite($fh, "update delete ".$host." ".$nsrecord."\n");
		fwrite($fh, "update add ".$host." ".$_MGM['defaultTTL']." ".$nsrecord." ".$_MGM['ip']."\n");
	}
	fwrite($fh, "show\n");
	fwrite($fh, "send\n");
	fclose($fh);
	
	$command = $_MGM['nsupdatePath']." -k ".escapeshellarg("keys/".$_MGM['user']['key'])." ".$updateFile;
	$result = exec($command." 2>&1", $output);
	
	if ($_MGM['debug']) {
		echo "Output: \n";
	}
	$foundReply = false;
	$status = 0;
	foreach ($output as $line) {
		if ($_MGM['debug']) {
			echo $line."\n";
		}

		if ($foundReply && $status==0) {
			if (strpos($line, "status: NOERROR")!==false) {
				$status = 1;
			} else {
				$status = 2;
			}
		}
		if (strpos($line, "Reply from update query:")!==false) {
			$foundReply = true;
		}
	}
	
	if ($status==1) {
		echo "\nUpdate Successful";
		databaseQuery("UPDATE users SET lastupdate=%s, $dblast=%s WHERE username=%s", $_MGM['time'], $_MGM['ip'], $_MGM['user']['username']);
	} else {
		if ($_MGM['debug']) {
			echo "\nCommand: ".$command."\n";
			echo "\nUpdate File:\n";
			readfile($updateFile);
		}
		echo "\nUpdate Unsuccessful";
	}
	unlink($updateFile);
} else {
	echo "No Update";
}

closeDatabase();
?>