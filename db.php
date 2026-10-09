<?php
$host = 'localhost';
$user = 'root';
$pass = ''; // ปกติ XAMPP จะไม่มีรหัสผ่าน
$dbname = 'activity_db';

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("เชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error);
}

// กำหนดให้รองรับภาษาไทย
$conn->set_charset("utf8mb4");
?>