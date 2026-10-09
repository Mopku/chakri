<?php
// ดึงค่าการเชื่อมต่อจาก Environment Variables ของ Railway
$host     = getenv('MYSQLHOST')     ?: getenv('MYSQL_HOST')     ?: 'localhost';
$user     = getenv('MYSQLUSER')     ?: getenv('MYSQL_USER')     ?: 'root';
$password = getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '';
$dbname   = getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: 'activity_app';
$port     = getenv('MYSQLPORT')     ?: getenv('MYSQL_PORT')     ?: 3306;

// เชื่อมต่อฐานข้อมูล
$conn = new mysqli($host, $user, $password, $dbname, (int)$port);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>