<?php
// ดึงค่าคอนฟิกจาก Railway
$host     = getenv('MYSQLHOST')     ?: 'localhost';
$user     = getenv('MYSQLUSER')     ?: 'root';
$password = getenv('MYSQLPASSWORD') ?: '';
$dbname   = getenv('MYSQLDATABASE') ?: 'activity_app';
$port     = getenv('MYSQLPORT')     ?: 3306;

// สร้างการเชื่อมต่อพร้อมระบุ Port
$conn = new mysqli($host, $user, $password, $dbname, (int)$port);

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>