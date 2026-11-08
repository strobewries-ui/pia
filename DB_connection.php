<?php

$sName = 'localhost';
$uName = 'root';
$pass = '';
$db_name = 'task3_management_db';

$conn = new PDO(
	"mysql:host=$sName;dbname=$db_name;charset=utf8mb4",
	$uName,
	$pass,
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);