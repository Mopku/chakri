<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

// ตรวจสอบสิทธิ์
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบในฐานะนักศึกษา']);
    exit;
}

$user_id  = $_SESSION['user_id'];
$qr_token = trim($_POST['qr_token'] ?? '');

if (empty($qr_token)) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบรหัส QR Code']);
    exit;
}

// 1. ตรวจสอบว่า QR Token ตรงกับกิจกรรมใดในระบบ
$stmt = $conn->prepare("SELECT id, title, hours FROM activities WHERE qr_token = ?");
$stmt->bind_param("s", $qr_token);
$stmt->execute();
$activity = $stmt->get_result()->fetch_assoc();

if (!$activity) {
    echo json_encode(['success' => false, 'message' => 'QR Code ไม่ถูกต้อง หรือไม่พบกิจกรรมนี้']);
    exit;
}

$activity_id = $activity['id'];

// 2. ตรวจสอบว่านักศึกษาเคยเช็กชื่อกิจกรรมนี้ไปแล้วหรือยัง
$check_stmt = $conn->prepare("SELECT id, status FROM attendance WHERE user_id = ? AND activity_id = ?");
$check_stmt->bind_param("ii", $user_id, $activity_id);
$check_stmt->execute();
$existing = $check_stmt->get_result()->fetch_assoc();

if ($existing) {
    if ($existing['status'] === 'attended') {
        echo json_encode(['success' => false, 'message' => 'คุณเคยเช็กชื่อเข้าร่วมกิจกรรม "' . $activity['title'] . '" ไปแล้ว']);
        exit;
    } else {
        // หากเคยลงทะเบียนไว้เฉลี่ย ให้เปลี่ยนสถานะเป็น attended
        $update_stmt = $conn->prepare("UPDATE attendance SET status = 'attended', checked_in_at = NOW() WHERE id = ?");
        $update_stmt->bind_param("i", $existing['id']);
        $update_stmt->execute();
    }
} else {
    // บันทึกการเช็กชื่อใหม่
    $insert_stmt = $conn->prepare("INSERT INTO attendance (user_id, activity_id, status, checked_in_at) VALUES (?, ?, 'attended', NOW())");
    $insert_stmt->bind_param("ii", $user_id, $activity_id);
    $insert_stmt->execute();
}

// ตอบกลับข้อความสำเร็จ
echo json_encode([
    'success' => true, 
    'message' => 'เช็กชื่อสำเร็จ! คุณได้รับ ' . $activity['hours'] . ' ชั่วโมง จากกิจกรรม "' . $activity['title'] . '"'
]);
?>