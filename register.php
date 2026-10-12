<?php
session_start();
require_once 'db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = trim($_POST['student_id']);
    $fullname   = trim($_POST['fullname']);
    $email      = trim($_POST['email']);
    $password   = $_POST['password'];
    $team_color = trim($_POST['team_color']); // สี/ชื่อทีมกีฬาที่เลือก
    $role       = 'student'; // กำหนดสิทธิ์เป็นนักศึกษาทุกคนโดยอัตโนมัติ (เอาตัวเลือกแอดมินออก)

    if (!empty($student_id) && !empty($fullname) && !empty($email) && !empty($password) && !empty($team_color)) {
        // ตรวจสอบว่าอีเมลหรือรหัสนักศึกษาซ้ำหรือไม่
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ? OR student_id = ?");
        $stmt_check->bind_param("ss", $email, $student_id);
        $stmt_check->execute();
        
        if ($stmt_check->get_result()->num_rows > 0) {
            $message = '<div class="alert alert-danger">อีเมลหรือรหัสนักศึกษานี้ถูกใช้งานในระบบแล้ว</div>';
        } else {
            // แฮชรหัสผ่านเพื่อความปลอดภัย
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // บันทึกข้อมูลเข้าสู่ฐานข้อมูล
            $stmt = $conn->prepare("INSERT INTO users (student_id, fullname, email, password, role, team_color) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $student_id, $fullname, $email, $hashed_password, $role, $team_color);

            if ($stmt->execute()) {
                echo "<script>alert('สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ'); window.location='login.php';</script>";
                exit();
            } else {
                $message = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการบันทึกข้อมูล</div>';
            }
        }
    } else {
        $message = '<div class="alert alert-warning">กรุณากรอกข้อมูลให้ครบทุกช่อง</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก - ระบบกิจกรรมและกีฬา</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #0d6efd, #0dcaf0);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 0;
        }
        .card-register {
            border: none;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            max-width: 450px;
            width: 100%;
        }
    </style>
</head>
<body>

<div class="container d-flex justify-content-center">
    <div class="card card-register bg-white p-4">
        <div class="text-center mb-3">
            <h4 class="fw-bold text-primary"><i class="bi bi-person-plus-fill"></i> สมัครสมาชิกนักศึกษา</h4>
            <p class="text-muted small">กรอกข้อมูลเพื่อลงทะเบียนเข้าร่วมกิจกรรมและเลือกสีทีมกีฬา</p>
        </div>

        <?= $message ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-bold">รหัสนักศึกษา</label>
                <input type="text" name="student_id" class="form-control" placeholder="เช่น 670278" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">ชื่อ - นามสกุล</label>
                <input type="text" name="fullname" class="form-control" placeholder="ชื่อ นามสกุลจริง" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">อีเมล</label>
                <input type="email" name="email" class="form-control" placeholder="example@gmail.com" required>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">รหัสผ่าน</label>
                <input type="password" name="password" class="form-control" placeholder="ตั้งรหัสผ่าน" required>
            </div>

            <!-- เพิ่มส่วนเลือกสีทีมกีฬา -->
            <div class="mb-3">
                <label class="form-label fw-bold"><i class="bi bi-flag-fill me-1"></i> เลือกสี/ทีมกีฬา</label>
                <select name="team_color" class="form-select" required>
                    <option value="">-- เลือกสีทีมกีฬา --</option>
                    <option value="สีแดง (Red Team)">🔴 สีแดง</option>
                    <option value="สีน้ำเงิน (Blue Team)">🔵 สีน้ำเงิน</option>
                    <option value="สีเหลือง (Yellow Team)">🟡 สีเหลือง</option>
                    <option value="สีเขียว (Green Team)">🟢 สีเขียว</option>
                    <option value="สีแสด/ส้ม (Orange Team)">🟠 สีแสด/ส้ม</option>
                    <option value="สีม่วง (Purple Team)">🟣 สีม่วง</option>
                </select>
            </div>

            <div class="d-grid gap-2 mt-4">
                <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold">สมัครสมาชิก</button>
                <a href="login.php" class="btn btn-outline-secondary rounded-pill">มีบัญชีอยู่แล้ว? เข้าสู่ระบบ</a>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>