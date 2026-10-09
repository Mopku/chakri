<?php
session_start();
require_once 'db.php';

// ตรวจสอบว่าล็อกอินหรือยัง ถ้ายังไม่ได้ล็อกอิน ให้ไปหน้า login ก่อน
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id  = $_SESSION['user_id'];
$qr_token = trim($_GET['token'] ?? '');

if (empty($qr_token)) {
    echo "<script>alert('รหัส QR Code ไม่ถูกต้อง'); window.location='student_dashboard.php';</script>";
    exit;
}

// ค้นหากิจกรรม
$stmt = $conn->prepare("SELECT id, title, hours FROM activities WHERE qr_token = ?");
$stmt->bind_param("s", $qr_token);
$stmt->execute();
$activity = $stmt->get_result()->fetch_assoc();

if (!$activity) {
    echo "<script>alert('ไม่พบกิจกรรมนี้ในระบบ'); window.location='student_dashboard.php';</script>";
    exit;
}

$activity_id = $activity['id'];

// ตรวจสอบการเช็กชื่อซ้ำ
$check_stmt = $conn->prepare("SELECT id, status FROM attendance WHERE user_id = ? AND activity_id = ?");
$check_stmt->bind_param("ii", $user_id, $activity_id);
$check_stmt->execute();
$existing = $check_stmt->get_result()->fetch_assoc();

if ($existing && $existing['status'] === 'attended') {
    echo "<script>alert('คุณเคยเช็กชื่อเข้าร่วมกิจกรรม " . $activity['title'] . " ไปแล้ว'); window.location='student_dashboard.php';</script>";
} else {
    if ($existing) {
        $update_stmt = $conn->prepare("UPDATE attendance SET status = 'attended', checked_in_at = NOW() WHERE id = ?");
        $update_stmt->bind_param("i", $existing['id']);
        $update_stmt->execute();
    } else {
        $insert_stmt = $conn->prepare("INSERT INTO attendance (user_id, activity_id, status, checked_in_at) VALUES (?, ?, 'attended', NOW())");
        $insert_stmt->bind_param("ii", $user_id, $activity_id);
        $insert_stmt->execute();
    }
    echo "<script>alert('เช็กชื่อสำเร็จ! คุณได้รับ " . $activity['hours'] . " ชั่วโมง จากกิจกรรม " . $activity['title'] . "'); window.location='student_dashboard.php';</script>";
}
?>