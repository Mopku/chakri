<?php
session_start();
require_once 'db.php';

// ตรวจสอบสิทธิ์เข้าใช้งานเฉพาะนักศึกษา
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$student_name = $_SESSION['fullname'];

// 1. จัดการเมื่อนักศึกษาส่งสรุปผลกิจกรรม (ส่งได้เฉพาะข้อความ summary_text)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_summary'])) {
    $attendance_id = intval($_POST['attendance_id']);
    $summary_text  = trim($_POST['summary_text']);

    if (!empty($summary_text)) {
        $stmt_s = $conn->prepare("UPDATE attendance SET summary_text = ? WHERE id = ? AND user_id = ?");
        $stmt_s->bind_param("sii", $summary_text, $attendance_id, $user_id);
        if ($stmt_s->execute()) {
            echo "<script>alert('ส่งสรุปผลกิจกรรมเรียบร้อยแล้ว'); window.location='student_dashboard.php';</script>";
            exit();
        }
    }
}

// 2. คำนวณชั่วโมงกิจกรรมสะสมรวม
$sum_sql = "SELECT SUM(a.hours) AS total_hours 
            FROM attendance att 
            JOIN activities a ON att.activity_id = a.id 
            WHERE att.user_id = ? AND att.status = 'attended'";
$stmt_sum = $conn->prepare($sum_sql);
$stmt_sum->bind_param("i", $user_id);
$stmt_sum->execute();
$total_hours = $stmt_sum->get_result()->fetch_assoc()['total_hours'] ?? 0;

// 3. ดึงรายการประวัติเข้าร่วมกิจกรรมทั้งหมดพร้อมสรุปผลและคะแนน
$history_sql = "SELECT att.id, a.title, a.hours, att.checked_in_at, att.summary_text, att.score 
                FROM attendance att 
                JOIN activities a ON att.activity_id = a.id 
                WHERE att.user_id = ? AND att.status = 'attended' 
                ORDER BY att.checked_in_at DESC";
$stmt_hist = $conn->prepare($history_sql);
$stmt_hist->bind_param("i", $user_id);
$stmt_hist->execute();
$history = $stmt_hist->get_result();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - ระบบชั่วโมงสะสม</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand fw-bold" href="#"><i class="bi bi-person-badge-fill"></i> ระบบกิจกรรมนักศึกษา</a>
        <div class="d-flex align-items-center gap-3">
            <span class="text-white small">สวัสดี, <?= htmlspecialchars($student_name) ?></span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">ออกจากระบบ</a>
        </div>
    </div>
</nav>

<div class="container my-4">

    <!-- Card สรุปชั่วโมงรวม -->
    <div class="card bg-primary text-white shadow-sm border-0 mb-4 p-4 rounded-4">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <h5 class="fw-bold mb-1"><i class="bi bi-trophy-fill text-warning"></i> ชั่วโมงกิจกรรมสะสมทั้งหมด</h5>
                <p class="mb-0 opacity-75 small">รหัสนักศึกษา: <?= htmlspecialchars($_SESSION['student_id'] ?? '-') ?></p>
            </div>
            <div class="text-end">
                <span class="display-4 fw-bold text-warning"><?= $total_hours ?></span>
                <span class="fs-5">ชม.</span>
            </div>
        </div>
    </div>

    <!-- ตารางประวัติกิจกรรม และคะแนน -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h5 class="card-title mb-0 fw-bold text-dark"><i class="bi bi-clock-history"></i> ประวัติการเข้าร่วมกิจกรรม & คะแนนสรุปผล</h5>
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
                            <th class="text-center">คะแนนจากแอดมิน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($history && $history->num_rows > 0): ?>
                            <?php while ($row = $history->fetch_assoc()): ?>
                                <tr>
                                    <td><small class="text-muted"><?= date('d/m/Y H:i', strtotime($row['checked_in_at'])) ?> น.</small></td>
                                    <td><strong><?= htmlspecialchars($row['title']) ?></strong></td>
                                    <td><span class="badge bg-success">+<?= $row['hours'] ?> ชม.</span></td>
                                    
                                    <!-- สถานะการส่งสรุปผล -->
                                    <td>
                                        <?php if (!empty($row['summary_text'])): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success">
                                                <i class="bi bi-check-circle-fill me-1"></i> ส่งสรุปผลแล้ว
                                            </span>
                                            <button class="btn btn-sm btn-link text-decoration-none" data-bs-toggle="modal" data-bs-target="#viewSummaryModal<?= $row['id'] ?>">
                                                (ดูสรุป)
                                            </button>

                                            <!-- Modal ดูสรุปผลที่เคยส่ง -->
                                            <div class="modal fade" id="viewSummaryModal<?= $row['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-light">
                                                            <h5 class="modal-title fs-6 fw-bold">สรุปผลกิจกรรม: <?= htmlspecialchars($row['title']) ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p class="mb-0 text-secondary"><?= nl2br(htmlspecialchars($row['summary_text'])) ?></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-warning text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#summaryModal<?= $row['id'] ?>">
                                                <i class="bi bi-pencil-square"></i> พิมพ์สรุปผล
                                            </button>

                                            <!-- Modal พิมพ์สรุปผล (นักศึกษากรอกได้เฉพาะข้อความเท่านั้น) -->
                                            <div class="modal fade" id="summaryModal<?= $row['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-warning text-dark">
                                                            <h5 class="modal-title fs-6 fw-bold">ส่งสรุปผลกิจกรรม: <?= htmlspecialchars($row['title']) ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form method="POST">
                                                            <div class="modal-body text-start">
                                                                <input type="hidden" name="attendance_id" value="<?= $row['id'] ?>">
                                                                <div class="mb-3">
                                                                    <label class="form-label fw-bold">สรุปองค์ความรู้ / สรุปผลที่ได้เข้าร่วมกิจกรรม</label>
                                                                    <textarea name="summary_text" class="form-control" rows="5" placeholder="พิมพ์สรุปผลกิจกรรมของคุณที่นี่..." required></textarea>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                                                                <button type="submit" name="submit_summary" class="btn btn-warning fw-bold"><i class="bi bi-send-fill"></i> ส่งสรุปผล</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- แสดงคะแนนจากแอดมิน (Read-Only) -->
                                    <td class="text-center">
                                        <?php if ($row['score'] > 0): ?>
                                            <span class="badge bg-primary fs-6 px-3 py-2"><?= $row['score'] ?> / 100 คะแนน</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary bg-opacity-75 text-white">รอแอดมินตรวจ/ให้คะแนน</span>
                                        <?php endif; ?>
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