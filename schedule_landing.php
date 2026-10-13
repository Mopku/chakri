<?php
session_start();
require_once 'db.php';

// ตรวจสอบสถานะการล็อกอินทุกรูปแบบ (นักศึกษา / แอดมิน)
$is_logged_in = isset($_SESSION['user_id']) || isset($_SESSION['role']) || isset($_SESSION['admin_id']);
$user_role    = $_SESSION['role'] ?? ($_SESSION['admin_id'] ? 'admin' : '');
$user_name    = $_SESSION['fullname'] ?? ($_SESSION['name'] ?? 'ผู้ใช้งาน');

// ดึงรายการแข่งขันกีฬาทั้งหมดที่แอดมินสร้างขึ้น
$latest_matches = $conn->query("
    SELECT m.*, 
           t1.team_name AS team1_name, t1.team_color AS team1_color,
           t2.team_name AS team2_name, t2.team_color AS team2_color
    FROM matches m
    JOIN teams t1 ON m.team1_id = t1.id
    JOIN teams t2 ON m.team2_id = t2.id
    ORDER BY m.id DESC
");

// ดึงรายชื่อทีมทั้งหมดในระบบพร้อมชนิดกีฬา ให้เลือกในฟอร์มแจ้งปัญหา
$teams_dropdown = $conn->query("SELECT id, team_name, team_color, sport_name FROM teams ORDER BY sport_name ASC, team_name ASC");

// จัดการการส่งข้อร้องเรียน/แจ้งปัญหา
$complaint_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_complaint'])) {
    if ($is_logged_in) {
        $subject = trim($_POST['subject']);
        $team_id = !empty($_POST['team_id']) ? intval($_POST['team_id']) : null;
        $message = trim($_POST['message']);
        $uid     = $_SESSION['user_id'] ?? 0;

        // หากมีการเลือกทีม ให้นำชื่อทีมระบุเพิ่มเข้าไปในรายละเอียดข้อความ
        if ($team_id) {
            $stmt_t = $conn->prepare("SELECT team_name, sport_name FROM teams WHERE id = ?");
            $stmt_t->bind_param("i", $team_id);
            $stmt_t->execute();
            $res_t = $stmt_t->get_result();
            if ($row_t = $res_t->fetch_assoc()) {
                $sport_txt = !empty($row_t['sport_name']) ? " (" . $row_t['sport_name'] . ")" : "";
                $message = "[เกี่ยวกับทีม: " . $row_t['team_name'] . $sport_txt . "]\n" . $message;
            }
        }

        if (!empty($subject) && !empty($message)) {
            $stmt_c = $conn->prepare("INSERT INTO complaints (user_id, subject, message) VALUES (?, ?, ?)");
            $stmt_c->bind_param("iss", $uid, $subject, $message);
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
    <title>ตารางแข่งขัน & ระบบกิจกรรม</title>
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
    <h3 class="fw-bold mb-1">ตารางการแข่งขันประจำวัน</h3>
    <p class="mb-0 opacity-75 small"><i class="bi bi-geo-alt-fill"></i> อาคารศูนย์กีฬา มหาวิทยาลัย</p>
</div>

<div class="container px-3" style="max-width: 900px;">

    <!-- แสดงสถานะผู้ใช้งาน -->
    <?php if ($is_logged_in): ?>
        <div class="d-flex justify-content-between align-items-center mb-3 px-1 flex-wrap gap-2">
            <span class="fw-bold text-primary">
                <i class="bi bi-person-circle me-1"></i> สวัสดี, <?= htmlspecialchars($user_name) ?> 
                <?php if (!empty($user_role)): ?>
                    <span class="badge bg-<?= $user_role === 'admin' ? 'danger' : 'info text-dark' ?> ms-1">
                        <?= $user_role === 'admin' ? 'ผู้ดูแลระบบ' : 'นักศึกษา' ?>
                    </span>
                <?php endif; ?>
            </span>
            <div>
                <?php if ($user_role === 'admin'): ?>
                    <a href="admin_dashboard.php" class="btn btn-dark btn-sm rounded-pill fw-bold">
                        <i class="bi bi-speedometer2 me-1"></i> กลับหน้าจัดการแอดมิน
                    </a>
                <?php else: ?>
                    <a href="student_dashboard.php" class="btn btn-outline-primary btn-sm rounded-pill fw-bold">
                        <i class="bi bi-shield-fill me-1"></i> ดูทีมกีฬาของคุณ
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card card-custom bg-warning bg-opacity-10 border-warning mb-4 p-3 text-center">
            <h6 class="fw-bold text-dark mb-1"><i class="bi bi-exclamation-triangle-fill text-warning"></i> ดูตารางแข่งขันได้ทันทีโดยไม่ต้องเข้าสู่ระบบ</h6>
            <p class="small text-muted mb-2">หากต้องการจัดการทีมหรือแจ้งปัญหา สามารถเข้าสู่ระบบได้ที่นี่</p>
            <a href="login.php" class="btn btn-warning btn-sm fw-bold px-4 rounded-pill">เข้าสู่ระบบ / สมัครสมาชิก</a>
        </div>
    <?php endif; ?>

    <?= $complaint_msg ?>

    <!-- ตารางการแข่งขันประจำวัน -->
    <div class="card card-custom mb-4" id="match-schedule">
        <div class="card-header bg-white border-0 pt-3 px-3">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-calendar-event me-1"></i> รายการแข่งขันกีฬาประจำวัน</h6>
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
                                <span class="badge bg-warning text-dark badge-status"><i class="bi bi-clock-history"></i> รอดำเนินการ</span>
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

    <!-- ปุ่มกดส่งข้อร้องเรียน -->
    <div class="d-grid gap-2 mb-4">
        <?php if ($is_logged_in): ?>
            <button class="btn btn-outline-danger btn-lg rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#complaintModal">
                <i class="bi bi-exclamation-diamond-fill me-1"></i> แจ้งปัญหา / ส่งข้อร้องเรียนถึงแอดมิน
            </button>
        <?php else: ?>
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
                            <option value="แจ้งเหตุผลเกี่ยวกับทีมกีฬา">แจ้งเหตุผลเกี่ยวกับทีมกีฬา</option>
                            <option value="แจ้งเหตุผลการขาดกิจกรรม">แจ้งเหตุผลการขาดกิจกรรม</option>
                            <option value="อื่นๆ">อื่นๆ</option>
                        </select>
                    </div>

                    <!-- ดร็อปดาวน์เลือกทีมกีฬาในระบบ (แสดงชื่อทีม + สี + ชนิดกีฬา) -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">เลือกทีมกีฬาที่ต้องการแจ้งปัญหา (ถ้ามี)</label>
                        <select name="team_id" class="form-select">
                            <option value="">-- ไม่ระบุ / แจ้งปัญหาระบบทั่วไป --</option>
                            <?php if ($teams_dropdown && $teams_dropdown->num_rows > 0): ?>
                                <?php while ($tm = $teams_dropdown->fetch_assoc()): ?>
                                    <option value="<?= $tm['id'] ?>">
                                        <?= htmlspecialchars($tm['team_name']) ?> (<?= htmlspecialchars($tm['team_color']) ?>) 
                                        <?= !empty($tm['sport_name']) ? ' - [ ' . htmlspecialchars($tm['sport_name']) . ' ]' : '' ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
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