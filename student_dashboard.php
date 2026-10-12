<?php
session_start();
require_once 'db.php';

// ตรวจสอบสถานะการเข้าสู่ระบบ
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'] ?? 'นักศึกษา';
$student_id = $_SESSION['student_id'] ?? '-';

// 1. ดึงข้อมูลคำเชิญเข้าทีมที่ยังไม่ได้ตอบรับ (Pending Invites Notification)
$pending_invites = $conn->query("
    SELECT tm.id AS member_id, t.team_name, t.team_color, u.fullname AS leader_name
    FROM team_members tm
    JOIN teams t ON tm.team_id = t.id
    JOIN users u ON t.leader_id = u.id
    WHERE tm.user_id = $user_id AND tm.status = 'pending'
");
$pending_count = $pending_invites ? $pending_invites->num_rows : 0;

// คำนวณชั่วโมงสะสมรวม
$sum_sql = "SELECT SUM(a.hours) AS total_hours 
            FROM attendance att 
            JOIN activities a ON att.activity_id = a.id 
            WHERE att.user_id = ? AND att.status = 'attended'";
$stmt = $conn->prepare($sum_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_hours = $stmt->get_result()->fetch_assoc()['total_hours'] ?? 0;

// ดึงประวัติกิจกรรมทั้งหมด
$history_sql = "SELECT a.title, a.hours, att.checked_in_at, att.summary_text, att.score 
                FROM attendance att 
                JOIN activities a ON att.activity_id = a.id 
                WHERE att.user_id = ? AND att.status = 'attended' 
                ORDER BY att.checked_in_at DESC";
$stmt_hist = $conn->prepare($history_sql);
$stmt_hist->bind_param("i", $user_id);
$stmt_hist->execute();
$history_result = $stmt_hist->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - ระบบกิจกรรมนักศึกษา</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="schedule_landing.php"><i class="bi bi-person-badge"></i> ระบบกิจกรรมนักศึกษา</a>
        <div class="d-flex align-items-center gap-3">
            <span class="text-white small">สวัสดี, <?= htmlspecialchars($fullname) ?></span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">ออกจากระบบ</a>
        </div>
    </div>
</nav>

<div class="container my-4" style="max-width: 900px;">

    <!-- 🔔 การแจ้งเตือนคำเชิญเข้าทีม (จะเด้งขึ้นมาเมื่อมีเพื่อนส่งคำเชิญมา) -->
    <?php if ($pending_count > 0): ?>
        <div class="card border-warning bg-warning bg-opacity-10 mb-4 shadow-sm border-2">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold text-dark mb-1">
                        <i class="bi bi-bell-fill text-warning me-1"></i> มีคำเชิญเข้าร่วมทีมส่งถึงคุณ (<?= $pending_count ?> รายการ)
                    </h6>
                    <small class="text-muted">เพื่อนได้ส่งคำเชิญให้คุณเข้าร่วมทีมกีฬา กดเพื่อตอบรับหรือปฏิเสธคำเชิญ</small>
                </div>
                <a href="team.php" class="btn btn-warning fw-bold text-dark shadow-sm">
                    ดูคำเชิญ / ตอบรับ <i class="bi bi-arrow-right-short"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- การ์ดแสดงชั่วโมงรวม -->
    <div class="card bg-primary text-white p-4 rounded-4 shadow-sm border-0 mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5 class="fw-bold mb-1"><i class="bi bi-trophy-fill text-warning me-2"></i> ชั่วโมงกิจกรรมสะสมทั้งหมด</h5>
                <small class="opacity-75">รหัสนักศึกษา: <?= htmlspecialchars($student_id) ?></small>
            </div>
            <div class="text-end">
                <span class="display-4 fw-bold text-warning"><?= $total_hours ?></span>
                <span class="fs-5">ชม.</span>
            </div>
        </div>
    </div>

    <!-- ปุ่มการทำงานหลัก -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="schedule_landing.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> ดูตารางแข่งขัน
        </a>
        
        <!-- ปุ่มกดไปจัดการทีมกีฬา (ติด Badge ตัวเลขแจ้งเตือนถ้ามีคำเชิญ) -->
        <a href="team.php" class="btn btn-warning fw-bold text-dark shadow-sm rounded-pill px-4 position-relative">
            <i class="bi bi-people-fill me-1"></i> สร้างทีมกีฬา / ชวนเพื่อนเข้าทีม
            <?php if ($pending_count > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                    <?= $pending_count ?>
                </span>
            <?php endif; ?>
        </a>
    </div>

    <!-- ตารางประวัติกิจกรรม -->
    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-header bg-white py-3 fw-bold">
            <i class="bi bi-clock-history me-2"></i> ประวัติการเข้าร่วมกิจกรรม & คะแนนสรุปผล
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>วันที่เข้าร่วม</th>
                            <th>ชื่อกิจกรรม</th>
                            <th>ชั่วโมง</th>
                            <th>สรุปผลกิจกรรม</th>
                            <th>คะแนนจากแอดมิน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($history_result && $history_result->num_rows > 0): ?>
                            <?php while ($row = $history_result->fetch_assoc()): ?>
                                <tr>
                                    <td><small class="text-muted"><?= date('d/m/Y H:i', strtotime($row['checked_in_at'])) ?> น.</small></td>
                                    <td><strong><?= htmlspecialchars($row['title']) ?></strong></td>
                                    <td><span class="badge bg-success">+<?= $row['hours'] ?> ชม.</span></td>
                                    <td>
                                        <?php if (!empty($row['summary_text'])): ?>
                                            <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check-circle"></i> ส่งสรุปผลแล้ว</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary opacity-75">ยังไม่ส่งสรุปผล</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary px-3 py-2"><?= $row['score'] ?? 0 ?> / 100 คะแนน</span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">ยังไม่มีประวัติการเข้าร่วมกิจกรรม</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>