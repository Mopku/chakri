<?php
session_start();
require_once 'db.php';

$qr_token = trim($_GET['token'] ?? '');

// 1. ถ้าไม่ได้ล็อกอิน หรือไม่มี Token ให้เด้งไปหน้า schedule_landing.php ทันที (ให้ดูตารางแข่ง/ไลฟ์ได้)
if (!isset($_SESSION['user_id']) || empty($qr_token)) {
    header("Location: schedule_landing.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// 2. ถ้าล็อกอินแล้ว ค้นหากิจกรรมเพื่อบันทึกเช็กชื่อ
$stmt = $conn->prepare("SELECT id, title, hours FROM activities WHERE qr_token = ?");
$stmt->bind_param("s", $qr_token);
$stmt->execute();
$activity = $stmt->get_result()->fetch_assoc();

if (!$activity) {
    echo "<script>alert('ไม่พบกิจกรรมนี้ในระบบ'); window.location='schedule_landing.php';</script>";
    exit();
}

$activity_id = $activity['id'];

// 3. ตรวจสอบการเช็กชื่อซ้ำ
$check_stmt = $conn->prepare("SELECT id, status FROM attendance WHERE user_id = ? AND activity_id = ?");
$check_stmt->bind_param("ii", $user_id, $activity_id);
$check_stmt->execute();
$existing = $check_stmt->get_result()->fetch_assoc();

if ($existing && $existing['status'] === 'attended') {
    echo "<script>alert('คุณเคยเช็กชื่อเข้าร่วมกิจกรรม " . addslashes($activity['title']) . " ไปแล้ว'); window.location='schedule_landing.php';</script>";
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
    echo "<script>alert('เช็กชื่อสำเร็จ! คุณได้รับ " . $activity['hours'] . " ชั่วโมง จากกิจกรรม " . addslashes($activity['title']) . "'); window.location='schedule_landing.php';</script>";
}
?>