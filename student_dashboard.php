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
$message = '';

// =========================================================
// 📌 ระบบตรวจสอบและสร้างตาราง notifications อัตโนมัติ
// =========================================================
$conn->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// =========================================================
// 📌 ระบบจัดการการแจ้งเตือน (กดรับทราบเพื่อให้ข้อความหายไป)
// =========================================================
if (isset($_GET['read_noti'])) {
    $noti_id = intval($_GET['read_noti']);
    $conn->query("UPDATE notifications SET is_read = 1 WHERE id = $noti_id AND user_id = $user_id");
    header("Location: student_dashboard.php");
    exit();
}

// ดึงข้อความแจ้งเตือนใหม่ที่ยังไม่ได้อ่าน
$notifications_list = null;
$noti_count = 0;
try {
    $notifications_list = $conn->query("SELECT * FROM notifications WHERE user_id = $user_id AND is_read = 0 ORDER BY created_at DESC");
    if ($notifications_list) {
        $noti_count = $notifications_list->num_rows;
    }
} catch (Exception $e) {
    $noti_count = 0;
}

// =========================================================
// 📌 ระบบประมวลผลการสร้างทีมกีฬาใหม่
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_team'])) {
    $team_name  = trim($_POST['team_name']);  
    $sport_name = trim($_POST['sport_name']); 

    if (!empty($team_name) && !empty($sport_name)) {
        $stmt_check = $conn->prepare("SELECT id FROM teams WHERE team_name = ? AND sport_name = ?");
        $stmt_check->bind_param("ss", $team_name, $sport_name);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        if ($res_check->num_rows > 0) {
            $message = '<div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill me-1"></i> ไม่สามารถสร้างได้! ทีม <strong>' . htmlspecialchars($team_name) . '</strong> ในประเภทกีฬา <strong>' . htmlspecialchars($sport_name) . '</strong> มีอยู่ในระบบแล้ว
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>';
        } else {
            $team_color = $team_name; 

            $stmt_ins = $conn->prepare("INSERT INTO teams (team_name, team_color, sport_name, leader_id) VALUES (?, ?, ?, ?)");
            $stmt_ins->bind_param("sssi", $team_name, $team_color, $sport_name, $user_id);
            
            if ($stmt_ins->execute()) {
                $new_team_id = $stmt_ins->insert_id;

                $stmt_m = $conn->prepare("INSERT INTO team_members (team_id, user_id, status) VALUES (?, ?, 'accepted')");
                $stmt_m->bind_param("ii", $new_team_id, $user_id);
                $stmt_m->execute();

                $message = '<div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle-fill me-1"></i> สร้างทีม <strong>' . htmlspecialchars($team_name) . '</strong> (' . htmlspecialchars($sport_name) . ') สำเร็จเรียบร้อยแล้ว!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>';
            }
        }
    }
}

// 1. ดึงข้อมูลคำเชิญเข้าทีมที่ยังไม่ได้ตอบรับ
$pending_invites = $conn->query("
    SELECT tm.id AS member_id, t.team_name, t.team_color, u.fullname AS leader_name
    FROM team_members tm
    JOIN teams t ON tm.team_id = t.id
    JOIN users u ON t.leader_id = u.id
    WHERE tm.user_id = $user_id AND tm.status = 'pending'
");
$pending_count = $pending_invites ? $pending_invites->num_rows : 0;

// 2. ดึงข้อมูลทีมที่ผู้ใช้เป็นสมาชิกอยู่
$my_team = null;
$team_stmt = $conn->prepare("
    SELECT t.*, u.fullname AS leader_name,
           (SELECT COUNT(*) FROM team_members WHERE team_id = t.id AND status = 'accepted') AS member_count
    FROM teams t
    JOIN team_members tm ON t.id = tm.team_id
    JOIN users u ON t.leader_id = u.id
    WHERE tm.user_id = ? AND tm.status = 'accepted' LIMIT 1
");
$team_stmt->bind_param("i", $user_id);
$team_stmt->execute();
$my_team = $team_stmt->get_result()->fetch_assoc();
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
        .card-team {
            border: none;
            border-radius: 16px;
            background: linear-gradient(135deg, #ffffff, #f1f5f9);
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
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

    <?= $message ?>

    <!-- 🔔 การแจ้งเตือนจากแอดมิน (ตารางแข่งใหม่ / คำตอบกลับปัญหา) -->
    <?php if ($noti_count > 0 && $notifications_list): ?>
        <?php while ($noti = $notifications_list->fetch_assoc()): ?>
            <div class="alert alert-info d-flex align-items-center shadow-sm border-2 border-info mb-4">
                <i class="bi bi-info-circle-fill text-info fs-2 me-3"></i>
                <div class="flex-grow-1">
                    <h6 class="mb-1 fw-bold text-dark">แจ้งเตือนจากผู้ดูแลระบบ</h6>
                    <p class="mb-0 text-dark"><?= htmlspecialchars($noti['message']) ?></p>
                    <small class="text-muted"><i class="bi bi-clock me-1"></i> <?= date('d/m/Y H:i', strtotime($noti['created_at'])) ?> น.</small>
                </div>
                <a href="student_dashboard.php?read_noti=<?= $noti['id'] ?>" class="btn btn-outline-info fw-bold ms-3" title="รับทราบ">
                    รับทราบ <i class="bi bi-check2"></i>
                </a>
            </div>
        <?php endwhile; ?>
    <?php endif; ?>

    <!-- 🔔 การแจ้งเตือนคำเชิญเข้าทีม -->
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

    <!-- 🛡️ การ์ดแสดงทีมกีฬาที่เราสังกัดอยู่ -->
    <div class="card card-team p-4 mb-4 border border-secondary border-opacity-25">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <?php if ($my_team && !empty($my_team['logo_url'])): ?>
                    <img src="<?= htmlspecialchars($my_team['logo_url']) ?>" class="rounded-circle border" style="width: 60px; height: 60px; object-fit: cover;">
                <?php else: ?>
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 60px; height: 60px; font-size: 1.5rem;">
                        <i class="bi bi-shield-fill"></i>
                    </div>
                <?php endif; ?>

                <div>
                    <?php if ($my_team): ?>
                        <span class="badge bg-info text-dark mb-1"><?= htmlspecialchars($my_team['sport_name'] ?? $my_team['team_color']) ?></span>
                        <h5 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($my_team['team_name']) ?></h5>
                        <small class="text-muted">หัวหน้าทีม: <?= htmlspecialchars($my_team['leader_name']) ?> | สมาชิก: <?= $my_team['member_count'] ?> คน</small>
                    <?php else: ?>
                        <h6 class="fw-bold text-secondary mb-1">คุณยังไม่มีทีมกีฬา</h6>
                        <small class="text-muted">กดปุ่มสร้างทีมเพื่อลงทะเบียนแข่งขัน หรือรอเพื่อนส่งคำเชิญ</small>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <a href="team.php" class="btn btn-warning fw-bold text-dark rounded-pill px-4 shadow-sm position-relative">
                    <i class="bi bi-people-fill me-1"></i> <?= $my_team ? 'ดู/จัดการทีมของคุณ' : 'สร้างทีมกีฬา' ?>
                    <?php if ($pending_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= $pending_count ?>
                        </span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </div>

    <!-- ปุ่มย้อนกลับไปหน้าตารางแข่งขัน -->
    <div class="d-flex justify-content-start mb-3">
        <a href="schedule_landing.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> ดูตารางแข่งขัน & LIVE
        </a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>