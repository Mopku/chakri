<?php
session_start();
require_once 'db.php';

// ตรวจสอบสิทธิ์เฉพาะแอดมิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$message = '';

// 1. แอดมินเพิ่มรายการเข้าร่วมกิจกรรมใหม่ให้นักศึกษา
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_attendance'])) {
    $user_id       = intval($_POST['user_id']);
    $activity_id   = intval($_POST['activity_id']);
    $checked_in_at = $_POST['checked_in_at'];
    $score         = intval($_POST['score']);

    if ($user_id > 0 && $activity_id > 0 && !empty($checked_in_at)) {
        $stmt_add = $conn->prepare("INSERT INTO attendance (user_id, activity_id, status, checked_in_at, score, graded_at) VALUES (?, ?, 'attended', ?, ?, NOW())");
        $stmt_add->bind_param("iisi", $user_id, $activity_id, $checked_in_at, $score);
        if ($stmt_add->execute()) {
            $message = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-1"></i> เพิ่มการเข้าร่วมกิจกรรมและคะแนนเรียบร้อยแล้ว! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $message = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการบันทึกข้อมูล</div>';
        }
    }
}

// 2. แอดมินอัปเดตคะแนน/เวลาที่มีอยู่เดิม
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_attendance'])) {
    $attendance_id = intval($_POST['attendance_id']);
    $score         = intval($_POST['score']);
    $checked_in_at = $_POST['checked_in_at'];

    if (!empty($checked_in_at)) {
        $stmt_u = $conn->prepare("UPDATE attendance SET score = ?, checked_in_at = ?, graded_at = NOW() WHERE id = ?");
        $stmt_u->bind_param("isi", $score, $checked_in_at, $attendance_id);
        if ($stmt_u->execute()) {
            $message = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-1"></i> บันทึกคะแนนและเวลาเรียบร้อยแล้ว! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }
}

// ดึงข้อมูลนักศึกษาทั้งหมด (สำหรับดร็อปดาวน์เลือกนักศึกษา)
$students_list = $conn->query("SELECT id, fullname, student_id FROM users WHERE role = 'student' ORDER BY fullname ASC");

// ดึงข้อมูลกิจกรรมทั้งหมด (สำหรับดร็อปดาวน์เลือกกิจกรรม)
$activities_list = $conn->query("SELECT id, title FROM activities ORDER BY title ASC");

// ดึงข้อมูลกิจกรรมทั้งหมดสำหรับตารางและ QR Code
$activities = $conn->query("SELECT id, title, description, hours, qr_token FROM activities ORDER BY title ASC");

// ดึงรายการเข้าร่วมกิจกรรมทั้งหมด
$attendances = $conn->query("
    SELECT att.id AS attendance_id, att.checked_in_at, att.summary_text, att.score, 
           u.fullname, u.student_id, a.title AS activity_title, a.hours AS default_hours
    FROM attendance att
    JOIN users u ON att.user_id = u.id
    JOIN activities a ON att.activity_id = a.id
    ORDER BY att.checked_in_at DESC
");

// ดึงรายการข้อร้องเรียน
$complaints = $conn->query("
    SELECT c.*, u.fullname, u.student_id 
    FROM complaints c 
    JOIN users u ON c.user_id = u.id 
    ORDER BY c.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - จัดการเวลาและคะแนน</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="#"><i class="bi bi-shield-lock-fill"></i> ระบบจัดการสำหรับแอดมิน</a>
        <div class="d-flex align-items-center gap-3">
            <span class="text-white small">ผู้ดูแลระบบ: <?= htmlspecialchars($_SESSION['fullname']) ?></span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">ออกจากระบบ</a>
        </div>
    </div>
</nav>

<div class="container my-4">

    <?= $message ?>

    <!-- ส่วนที่ 1: ตารางจัดการเวลาและคะแนน -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0 fw-bold"><i class="bi bi-person-check-fill me-2"></i> จัดการคะแนน & เวลาเข้าร่วมกิจกรรม</h5>
            <!-- ปุ่มเปิด Modal เพิ่มรายการใหม่ -->
            <button class="btn btn-light btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#addAttendanceModal">
                <i class="bi bi-plus-circle-fill text-primary"></i> เพิ่มเข้าร่วมกิจกรรมใหม่
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 18%;">นักศึกษา</th>
                            <th style="width: 15%;">กิจกรรม</th>
                            <th style="width: 22%;">สรุปผลกิจกรรม</th>
                            <th style="width: 20%;">เวลาเข้าร่วมกิจกรรม</th>
                            <th style="width: 10%;">คะแนน (100)</th>
                            <th style="width: 15%; text-align: center;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($attendances && $attendances->num_rows > 0): ?>
                            <?php while ($row = $attendances->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['fullname']) ?></strong>
                                        <br><small class="text-muted">รหัส: <?= htmlspecialchars($row['student_id'] ?: '-') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary mb-1"><?= htmlspecialchars($row['activity_title']) ?></span>
                                        <br><small class="text-muted">+<?= $row['default_hours'] ?> ชม.</small>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['summary_text'])): ?>
                                            <small class="d-block text-dark bg-white p-2 border rounded" style="max-height: 80px; overflow-y: auto;">
                                                <?= nl2br(htmlspecialchars($row['summary_text'])) ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="badge bg-secondary opacity-75">ยังไม่ส่งสรุปผล</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <form method="POST">
                                        <input type="hidden" name="attendance_id" value="<?= $row['attendance_id'] ?>">
                                        <td>
                                            <?php $formatted_time = $row['checked_in_at'] ? date('Y-m-d\TH:i', strtotime($row['checked_in_at'])) : ''; ?>
                                            <input type="datetime-local" name="checked_in_at" class="form-control form-control-sm" value="<?= $formatted_time ?>" required>
                                        </td>
                                        <td>
                                            <input type="number" name="score" class="form-control form-control-sm text-center" min="0" max="100" value="<?= $row['score'] ?>" placeholder="0-100" required>
                                        </td>
                                        <td class="text-center">
                                            <button type="submit" name="update_attendance" class="btn btn-sm btn-success w-100">
                                                <i class="bi bi-save-fill"></i> บันทึก
                                            </button>
                                        </td>
                                    </form>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">ยังไม่มีรายการเข้าร่วมกิจกรรม (กดปุ่ม "เพิ่มเข้าร่วมกิจกรรมใหม่" ด้านบนเพื่อเริ่มบันทึก)</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ส่วนรายการกิจกรรมทั้งหมด + ปุ่มแสดง QR Code -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-secondary text-white fw-bold">
            <h5 class="card-title mb-0 fs-6"><i class="bi bi-qr-code-scan me-2"></i> รายการกิจกรรม &amp; สแกนรับ QR Code</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ชื่อกิจกรรม</th>
                            <th>รายละเอียด</th>
                            <th>ชั่วโมง</th>
                            <th class="text-center">สร้าง QR Code เช็กชื่อ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($activities && $activities->num_rows > 0): ?>
                            <?php while ($act = $activities->fetch_assoc()): ?>
                                <?php
                                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                                    $app_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
                                    $checkin_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $app_path . '/checkin_direct.php?token=' . rawurlencode($act['qr_token']);
                                    $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($checkin_url);
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($act['title']) ?></strong></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($act['description'] ?? '') ?></small></td>
                                    <td><span class="badge bg-success">+<?= htmlspecialchars((string) $act['hours']) ?> ชม.</span></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#qrModal<?= (int) $act['id'] ?>">
                                            <i class="bi bi-qr-code"></i> แสดง QR Code
                                        </button>

                                        <div class="modal fade" id="qrModal<?= (int) $act['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content text-center p-3">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title fw-bold">QR Code เช็กชื่อ: <?= htmlspecialchars($act['title']) ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body my-2">
                                                        <img src="<?= htmlspecialchars($qr_api_url) ?>" alt="QR Code" class="img-fluid border p-2 rounded shadow-sm mb-3">
                                                        <p class="text-muted small mb-1">ให้นักศึกษาสแกน QR Code นี้ด้วยกล้องมือถือเพื่อเช็กชื่อ</p>
                                                        <small class="text-primary d-block text-break">URL: <?= htmlspecialchars($checkin_url) ?></small>
                                                    </div>
                                                    <div class="modal-footer justify-content-center">
                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted">ยังไม่มีกิจกรรม</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Modal สำหรับเพิ่มรายการเข้าร่วมกิจกรรมใหม่ -->
<div class="modal fade" id="addAttendanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle"></i> เพิ่มการเข้าร่วมกิจกรรมให้นักศึกษา</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">เลือกนักศึกษา</label>
                        <select name="user_id" class="form-select" required>
                            <option value="">-- เลือกนักศึกษา --</option>
                            <?php if ($students_list): ?>
                                <?php while ($st = $students_list->fetch_assoc()): ?>
                                    <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['fullname']) ?> (รหัส: <?= htmlspecialchars($st['student_id'] ?: '-') ?>)</option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">เลือกกิจกรรม</label>
                        <select name="activity_id" class="form-select" required>
                            <option value="">-- เลือกกิจกรรม --</option>
                            <?php if ($activities_list): ?>
                                <?php while ($act = $activities_list->fetch_assoc()): ?>
                                    <option value="<?= $act['id'] ?>"><?= htmlspecialchars($act['title']) ?></option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">วันที่และเวลาเข้าร่วม</label>
                        <input type="datetime-local" name="checked_in_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">คะแนน (0-100)</label>
                        <input type="number" name="score" class="form-control" min="0" max="100" value="0" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="add_attendance" class="btn btn-primary"><i class="bi bi-check-lg"></i> บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>