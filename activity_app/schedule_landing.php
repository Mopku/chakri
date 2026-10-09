<?php
session_start();
require_once 'db.php';

// ตรวจสอบว่าผู้ใช้ล็อกอินในฐานะนักศึกษาหรือไม่
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

    // 2. ดึงประวัติกิจกรรมที่สะสมชั่วโมงสำเร็จแล้ว 3 รายการล่าสุด
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

// 3. จัดการเมื่อกดส่งข้อร้องเรียน
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

<div class="container px-3">

    <!-- แสดงชั่วโมงสะสม -->
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
                            <span>• <?= htmlspecialchars($row['title']) ?></span>
                            <span class="badge bg-warning text-dark">+<?= $row['hours'] ?> ชม.</span>
                        </li>
                    <?php endwhile; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card card-custom bg-warning bg-opacity-10 border-warning mb-4 p-3 text-center">
            <h6 class="fw-bold text-dark mb-1"><i class="bi bi-exclamation-triangle-fill text-warning"></i> ยังไม่ได้เข้าสู่ระบบ</h6>
            <p class="small text-muted mb-2">กรุณาเข้าสู่ระบบเพื่อบันทึกและดูชั่วโมงกิจกรรมสะสมของคุณ</p>
            <a href="login.php" class="btn btn-warning btn-sm fw-bold">เข้าสู่ระบบ / สมัครสมาชิก</a>
        </div>
    <?php endif; ?>

    <!-- ข้อความแจ้งเตือนเมื่อส่งข้อร้องเรียนสำเร็จ -->
    <?= $complaint_msg ?>

    <!-- ตารางการแข่งขันประจำวัน -->
    <div class="card card-custom mb-4">
        <div class="card-header bg-white border-0 pt-3 px-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-calendar-event me-1"></i> ตารางแข่งขันวันนี้</h6>
            <span class="badge bg-danger"><i class="bi bi-record-fill"></i> LIVE</span>
        </div>
        <div class="card-body p-2">
            
            <!-- Item 1: กำลังแข่ง -->
            <div class="p-3 border-bottom">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted"><i class="bi bi-clock"></i> 10:00 - 11:30 น. (รอบรองฯ)</small>
                    <span class="badge bg-warning text-dark badge-status">กำลังแข่งขัน</span>
                </div>
                <div class="row text-center fw-bold align-items-center my-2">
                    <div class="col-5 text-end text-primary">สาขาเทคโนโลยีฯ</div>
                    <div class="col-2 text-muted fs-6">VS</div>
                    <div class="col-5 text-start text-danger">สาขาบริหารธุรกิจ</div>
                </div>
                <div class="text-center">
                    <small class="text-muted bg-light px-2 py-1 rounded">สนาม 1 (คอร์ท A)</small>
                </div>
            </div>

            <!-- Item 2: คู่ถัดไป -->
            <div class="p-3 border-bottom">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted"><i class="bi bi-clock"></i> 13:00 - 14:30 น. (รอบชิงฯ)</small>
                    <span class="badge bg-secondary badge-status">รอดำเนินการ</span>
                </div>
                <div class="row text-center fw-bold align-items-center my-2">
                    <div class="col-5 text-end">ผู้ชนะคู่ที่ 1</div>
                    <div class="col-2 text-muted fs-6">VS</div>
                    <div class="col-5 text-start">ผู้ชนะคู่ที่ 2</div>
                </div>
                <div class="text-center">
                    <small class="text-muted bg-light px-2 py-1 rounded">สนามกลาง</small>
                </div>
            </div>

            <!-- Item 3: จบแล้ว -->
            <div class="p-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted"><i class="bi bi-clock"></i> 08:30 - 09:30 น.</small>
                    <span class="badge bg-success badge-status">แข่งเสร็จแล้ว</span>
                </div>
                <div class="row text-center fw-bold align-items-center my-2">
                    <div class="col-5 text-end text-success">สาขาวิศวกรรมฯ (2)</div>
                    <div class="col-2 text-muted fs-6">:</div>
                    <div class="col-5 text-start text-muted">สาขานิเทศศาสตร์ (0)</div>
                </div>
                <div class="text-center">
                    <small class="text-muted bg-light px-2 py-1 rounded">สนาม 2</small>
                </div>
            </div>

        </div>
    </div>

    <!-- ปุ่ม Action -->
    <div class="d-grid gap-2 mb-4">
        <a href="student_dashboard.php" class="btn btn-outline-primary btn-lg rounded-pill shadow-sm">
            <i class="bi bi-person-badge-fill me-1"></i> ดูประวัติชั่วโมงสะสมแบบเต็ม
        </a>

        <?php if ($is_logged_in): ?>
            <button class="btn btn-outline-danger btn-lg rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#complaintModal">
                <i class="bi bi-exclamation-diamond-fill me-1"></i> แจ้งปัญหา / ส่งข้อร้องเรียนถึงแอดมิน
            </button>
        <?php else: ?>
            <button class="btn btn-outline-secondary btn-lg rounded-pill shadow-sm" onclick="alert('กรุณาเข้าสู่ระบบก่อนส่งข้อร้องเรียน'); window.location='login.php';">
                <i class="bi bi-exclamation-diamond-fill me-1"></i> แจ้งปัญหา / ส่งข้อร้องเรียนถึงแอดมิน
            </button>
        <?php endif; ?>
    </div>

</div>

<!-- Modal ฟอร์มเขียนข้อร้องเรียน -->
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

<!-- สคริปต์ Bootstrap JS สั่งให้ Modal และส่วนโต้ตอบทำงาน -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>