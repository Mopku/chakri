<?php
session_start();
require_once 'db.php';

// ตรวจสอบสถานะการล็อกอิน
$is_logged_in = isset($_SESSION['user_id']) && $_SESSION['role'] === 'student';
$total_hours = 0;
$student_name = '';
$history_result = null;

if ($is_logged_in) {
    $user_id = $_SESSION['user_id'];
    $student_name = $_SESSION['fullname'];

    // 1. คำนวณชั่วโมงกิจกรรมสะสมรวม
    $sum_sql = "SELECT SUM(a.hours) AS total_hours 
                FROM attendance att 
                JOIN activities a ON att.activity_id = a.id 
                WHERE att.user_id = ? AND att.status = 'attended'";
    $stmt = $conn->prepare($sum_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $total_hours = $stmt->get_result()->fetch_assoc()['total_hours'] ?? 0;

    // 2. ดึงประวัติกิจกรรม 3 รายการล่าสุด
    $history_sql = "SELECT a.title, a.hours, att.checked_in_at 
                    FROM attendance att 
                    JOIN activities a ON att.activity_id = a.id 
                    WHERE att.user_id = ? AND att.status = 'attended' 
                    ORDER BY att.checked_in_at DESC LIMIT 3";
    $stmt_hist = $conn->prepare($history_sql);
    $stmt_hist->bind_param("i", $user_id);
    $stmt_hist->execute();
    $history_result = $stmt_hist->get_result();
}

// 3. ดึงรายการแข่งขันกีฬาล่าสุดที่แอดมินสร้างขึ้น
$latest_matches = $conn->query("
    SELECT m.*, 
           t1.team_name AS team1_name, t1.team_color AS team1_color,
           t2.team_name AS team2_name, t2.team_color AS team2_color
    FROM matches m
    JOIN teams t1 ON m.team1_id = t1.id
    JOIN teams t2 ON m.team2_id = t2.id
    ORDER BY m.created_at DESC, m.id DESC
");

// 4. จัดการการส่งข้อร้องเรียน/แจ้งเหตุผล
$complaint_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_complaint'])) {
    if ($is_logged_in) {
        $subject = trim($_POST['subject']);
        $message = trim($_POST['message']);

        if (!empty($subject) && !empty($message)) {
            $stmt_c = $conn->prepare("INSERT INTO complaints (user_id, subject, message) VALUES (?, ?, ?)");
            $stmt_c->bind_param("iss", $_SESSION['user_id'], $subject, $message);
            if ($stmt_c->execute()) {
                $complaint_msg = '<div class="alert alert-success mt-2">ส่งข้อร้องเรียน/แจ้งปัญหาเรียบร้อยแล้ว แอดมินจะดำเนินการตรวจสอบครับ</div>';
            } else {
                $complaint_msg = '<div class="alert alert-danger mt-2">เกิดข้อผิดพลาด ไม่สามารถส่งข้อมูลได้</div>';
            }
        }
    } else {
        header("Location: login.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตารางแข่งขัน & ระบบสะสมชั่วโมงกิจกรรม</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .header-banner {
            background: linear-gradient(135deg, #0d6efd, #0dcaf0);
            color: white;
            border-radius: 0 0 25px 25px;
            padding: 25px 15px;
        }
        .card-custom {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .badge-status {
            font-size: 0.8rem;
            padding: 6px 12px;
            border-radius: 20px;
        }
    </style>
</head>
<body>

<!-- Header Banner -->
<div class="header-banner text-center shadow-sm mb-4">
    <span class="badge bg-light text-primary fw-bold mb-2"><i class="bi bi-trophy-fill"></i> กิจกรรมและตารางแข่งขัน</span>
    <h3 class="fw-bold mb-1">ตารางการแข่งขัน & ชั่วโมงสะสม</h3>
    <p class="mb-0 opacity-75 small"><i class="bi bi-geo-alt-fill"></i> อาคารศูนย์กีฬา มหาวิทยาลัย</p>
</div>

<div class="container px-3" style="max-width: 900px;">

    <!-- ส่วนที่ 1: แสดงสถานะผู้ใช้ / ชั่วโมงสะสม -->
    <?php if ($is_logged_in): ?>
        <div class="card card-custom bg-primary text-white mb-4 p-3 shadow-sm">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <small class="text-white-50"><i class="bi bi-person-fill"></i> <?= htmlspecialchars($student_name) ?></small>
                    <h5 class="fw-bold mb-0">ชั่วโมงกิจกรรมสะสมรวม</h5>
                </div>
                <div class="text-end">
                    <span class="display-5 fw-bold text-warning"><?= $total_hours ?></span>
                    <span class="fs-6">ชม.</span>
                </div>
            </div>
            
            <?php if ($history_result && $history_result->num_rows > 0): ?>
                <hr class="my-2 opacity-25">
                <small class="text-white-50 mb-1 d-block"><i class="bi bi-clock-history"></i> กิจกรรมที่ได้รับชั่วโมงล่าสุด:</small>
                <ul class="list-unstyled mb-0 small">
                    <?php while ($row = $history_result->fetch_assoc()): ?>
                        <li class="d-flex justify-content-between">
                            <span><?= htmlspecialchars($row['title']) ?></span>
                            <span class="badge bg-warning text-dark">+<?= $row['hours'] ?> ชม.</span>
                        </li>
                    <?php endwhile; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card card-custom bg-warning bg-opacity-10 border-warning mb-4 p-3 text-center">
            <h6 class="fw-bold text-dark mb-1"><i class="bi bi-exclamation-triangle-fill text-warning"></i> ยังไม่ได้เข้าสู่ระบบ</h6>
            <p class="small text-muted mb-2">กรุณาเข้าสู่ระบบเพื่อดูข้อมูลชั่วโมงกิจกรรมสะสมของคุณ</p>
            <a href="login.php" class="btn btn-warning btn-sm fw-bold">เข้าสู่ระบบ / สมัครสมาชิก</a>
        </div>
    <?php endif; ?>

    <?= $complaint_msg ?>

    <!-- ส่วนที่ 2: ตารางการแข่งขันประจำวัน -->
    <div class="card card-custom mb-4" id="match-schedule">
        <div class="card-header bg-white border-0 pt-3 px-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-calendar-event me-1"></i> ตารางการแข่งขันกีฬาประจำวัน</h6>
            <span class="badge bg-danger"><i class="bi bi-record-fill"></i> LIVE</span>
        </div>
        <div class="card-body p-2">
            
            <?php if ($latest_matches && $latest_matches->num_rows > 0): ?>
                <?php while ($mc = $latest_matches->fetch_assoc()): ?>
                    <div class="p-3 border-bottom">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-primary fw-bold">
                                <i class="bi bi-trophy me-1"></i><?= htmlspecialchars($mc['sport_name']) ?>
                                <span class="text-muted opacity-75 font-normal ms-1">(<?= htmlspecialchars($mc['match_time']) ?>)</span>
                            </small>
                            <?php if ($mc['status'] === 'live'): ?>
                                <span class="badge bg-danger badge-status"><i class="bi bi-record-fill"></i> กำลังแข่งขัน</span>
                            <?php elseif ($mc['status'] === 'finished'): ?>
                                <span class="badge bg-success badge-status">แข่งเสร็จแล้ว</span>
                            <?php else: ?>
                                <span class="badge bg-secondary badge-status">รอดำเนินการ</span>
                            <?php endif; ?>
                        </div>
                        <div class="row text-center fw-bold align-items-center my-2">
                            <div class="col-5 text-end text-primary">
                                <?= htmlspecialchars($mc['team1_name']) ?>
                                <small class="d-block text-muted opacity-75 fw-normal" style="font-size: 0.75rem;"><?= htmlspecialchars($mc['team1_color']) ?></small>
                            </div>
                            <div class="col-2 text-muted fs-6">VS</div>
                            <div class="col-5 text-start text-danger">
                                <?= htmlspecialchars($mc['team2_name']) ?>
                                <small class="d-block text-muted opacity-75 fw-normal" style="font-size: 0.75rem;"><?= htmlspecialchars($mc['team2_color']) ?></small>
                            </div>
                        </div>
                        <div class="text-center">
                            <small class="text-muted bg-light px-2 py-1 rounded"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($mc['location']) ?></small>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="p-4 text-center text-muted">ยังไม่มีรายการแข่งขันในขณะนี้</div>
            <?php endif; ?>

        </div>
    </div>

    <!-- ส่วนที่ 3: ปุ่มกด Action (เอาปุ่มจัดการทีม/สร้างทีมออกแล้ว) -->
    <div class="d-grid gap-2 mb-4">
        <?php if ($is_logged_in): ?>
            <a href="student_dashboard.php" class="btn btn-primary btn-lg rounded-pill shadow-sm fw-bold">
                <i class="bi bi-person-badge-fill me-1"></i> ดูประวัติชั่วโมงสะสมแบบเต็ม
            </a>
            <button class="btn btn-outline-danger btn-lg rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#complaintModal">
                <i class="bi bi-exclamation-diamond-fill me-1"></i> แจ้งปัญหา / ส่งข้อร้องเรียนถึงแอดมิน
            </button>
        <?php else: ?>
            <a href="login.php" class="btn btn-primary btn-lg rounded-pill shadow-sm fw-bold">
                <i class="bi bi-person-badge-fill me-1"></i> เข้าสู่ระบบเพื่อดูประวัติชั่วโมงสะสม
            </a>
            <button class="btn btn-outline-danger btn-lg rounded-pill shadow-sm" onclick="if(confirm('กรุณาเข้าสู่ระบบก่อนส่งข้อร้องเรียน/แจ้งปัญหา กด OK เพื่อไปหน้าล็อกอิน')) { window.location='login.php'; }">
                <i class="bi bi-exclamation-diamond-fill me-1"></i> แจ้งปัญหา / ส่งข้อร้องเรียนถึงแอดมิน
            </button>
        <?php endif; ?>
    </div>

</div>

<!-- Modal ฟอร์มเขียนข้อร้องเรียน/แจ้งเหตุผล -->
<div class="modal fade" id="complaintModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-chat-square-text-fill"></i> เขียนข้อร้องเรียน / แจ้งเหตุผล</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">หัวข้อเรื่อง</label>
                        <select name="subject" class="form-select" required>
                            <option value="สแกน QR Code ไม่ติด">สแกน QR Code ไม่ติด</option>
                            <option value="ชั่วโมงกิจกรรมไม่ขึ้น/ไม่ครบ">ชั่วโมงกิจกรรมไม่ขึ้น / ไม่ครบ</option>
                            <option value="แจ้งเหตุผลการขาดกิจกรรม">แจ้งเหตุผลการขาดกิจกรรม</option>
                            <option value="อื่นๆ">อื่นๆ</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">รายละเอียด / เหตุผล</label>
                        <textarea name="message" class="form-control" rows="4" placeholder="พิมพ์รายละเอียดที่ต้องการแจ้งแอดมิน..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="submit_complaint" class="btn btn-danger"><i class="bi bi-send-fill"></i> ส่งข้อความ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>