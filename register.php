<?php
session_start();
require_once 'db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname   = trim($_POST['fullname']);
    $student_id = trim($_POST['student_id']);
    $email      = trim($_POST['email']);
    $password   = $_POST['password'];
    $role       = $_POST['role'];

    // เข้ารหัสรหัสผ่านเพื่อความปลอดภัย
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // ตรวจสอบอีเมลซ้ำ
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $message = "อีเมลนี้ถูกใช้งานในระบบแล้ว";
    } else {
        // บันทึกข้อมูล
        $stmt = $conn->prepare("INSERT INTO users (student_id, fullname, email, password, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $student_id, $fullname, $email, $hashed_password, $role);
        
        if ($stmt->execute()) {
            echo "<script>alert('สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ'); window.location='login.php';</script>";
            exit;
        } else {
            $message = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก - ระบบสะสมชั่วโมงกิจกรรม</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white text-center">
                    <h4>สมัครสมาชิก</h4>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert alert-danger"><?= $message ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">ประเภทผู้ใช้งาน</label>
                            <select name="role" class="form-select" required>
                                <option value="student">นักศึกษา</option>
                                <option value="admin">ผู้จัดกิจกรรม / แอดมิน</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">รหัสนักศึกษา (ถ้าเป็นนักศึกษา)</label>
                            <input type="text" name="student_id" class="form-control" placeholder="เช่น 6501234567">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">ชื่อ-นามสกุล</label>
                            <input type="text" name="fullname" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">อีเมล</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">รหัสผ่าน</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">ลงทะเบียน</button>
                    </form>
                    <div class="text-center mt-3">
                        <a href="login.php">มีบัญชีอยู่แล้ว? เข้าสู่ระบบ</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>