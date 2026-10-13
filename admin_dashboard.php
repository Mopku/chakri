<?php
session_start();
require_once 'db.php';

// ตรวจสอบสิทธิ์เฉพาะแอดมิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$message = '';

// =========================================================
// 📌 ระบบแอดมินตอบกลับปัญหาและบันทึกประวัติการพิมพ์
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_complaint'])) {
    $complaint_id = intval($_POST['complaint_id']);
    $student_user_id = intval($_POST['student_user_id']);
    $reply_text = trim($_POST['admin_reply']);
    $admin_id = $_SESSION['user_id'];

    if ($complaint_id > 0 && !empty($reply_text)) {
        // 1. สร้างตารางเก็บประวัติการสนทนาอัตโนมัติ (เผื่อยังไม่มี)
        $conn->query("CREATE TABLE IF NOT EXISTS complaint_replies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            complaint_id INT NOT NULL,
            sender_type ENUM('student', 'admin') NOT NULL,
            sender_id INT NOT NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // 2. บันทึกข้อความตอบกลับของแอดมินลงใน complaint_replies
        $stmt_rep = $conn->prepare("INSERT INTO complaint_replies (complaint_id, sender_type, sender_id, message) VALUES (?, 'admin', ?, ?)");
        $stmt_rep->bind_param("iis", $complaint_id, $admin_id, $reply_text);
        
        if ($stmt_rep->execute()) {
            // 3. อัปเดตข้อความล่าสุดลงในตาราง complaints ด้วย
            $stmt_up = $conn->prepare("UPDATE complaints SET admin_reply = ? WHERE id = ?");
            $stmt_up->bind_param("si", $reply_text, $complaint_id);
            $stmt_up->execute();

            // 4. สร้างตารางแจ้งเตือนและส่งแจ้งเตือนหานักศึกษา
            $conn->query("CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                message TEXT NOT NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            $noti_msg = "💬 แอดมินตอบกลับข้อร้องเรียนของคุณแล้ว: \"{$reply_text}\"";
            $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $stmt_notif->bind_param("is", $student_user_id, $noti_msg);
            $stmt_notif->execute();

            $message = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-1"></i> บันทึกและส่งข้อความตอบกลับให้นักศึกษาเรียบร้อยแล้ว! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $message = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการบันทึกคำตอบกลับ</div>';
        }
    }
}

// 1. แอดมินลบทีมที่นักศึกษาสร้างขึ้น
if (isset($_GET['delete_team_by_admin'])) {
    $del_team_id = intval($_GET['delete_team_by_admin']);
    $stmt_del_t = $conn->prepare("DELETE FROM teams WHERE id = ?");
    $stmt_del_t->bind_param("i", $del_team_id);
    if ($stmt_del_t->execute()) {
        $message = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-1"></i> ลบทีมกีฬาเรียบร้อยแล้ว <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

// 2. แอดมินสร้างแมตช์การแข่งขันใหม่ (พร้อมระบบแจ้งเตือนลูกทีม)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_match'])) {
    $sport_name = trim($_POST['sport_name']);
    $team1_id   = intval($_POST['team1_id']);
    $team2_id   = intval($_POST['team2_id']);
    $match_time = trim($_POST['match_time']);
    $location   = trim($_POST['location']);
    $status     = trim($_POST['status'] ?? 'upcoming');

    if (!empty($sport_name) && $team1_id > 0 && $team2_id > 0 && $team1_id !== $team2_id) {
        $stmt_m = $conn->prepare("INSERT INTO matches (sport_name, team1_id, team2_id, match_time, location, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_m->bind_param("siisss", $sport_name, $team1_id, $team2_id, $match_time, $location, $status);
        
        if ($stmt_m->execute()) {
            $conn->query("CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                message TEXT NOT NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            $team_names = [];
            $q_teams = $conn->query("SELECT id, team_name FROM teams WHERE id IN ($team1_id, $team2_id)");
            while($rt = $q_teams->fetch_assoc()) {
                $team_names[$rt['id']] = $rt['team_name'];
            }
            $t1_name = $team_names[$team1_id] ?? 'ทีม A';
            $t2_name = $team_names[$team2_id] ?? 'ทีม B';

            $msg_team1 = "📢 ตารางแข่งใหม่! ทีมของคุณ ({$t1_name}) มีคิวแข่งกีฬา {$sport_name} พบกับ {$t2_name} | 🕒 เวลา: {$match_time} | 📍 สถานที่: {$location}";
            $msg_team2 = "📢 ตารางแข่งใหม่! ทีมของคุณ ({$t2_name}) มีคิวแข่งกีฬา {$sport_name} พบกับ {$t1_name} | 🕒 เวลา: {$match_time} | 📍 สถานที่: {$location}";

            $notify_stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");

            $m1 = $conn->query("SELECT user_id FROM team_members WHERE team_id = $team1_id AND status = 'accepted'");
            if($m1) {
                while($row = $m1->fetch_assoc()) {
                    $notify_stmt->bind_param("is", $row['user_id'], $msg_team1);
                    $notify_stmt->execute();
                }
            }

            $m2 = $conn->query("SELECT user_id FROM team_members WHERE team_id = $team2_id AND status = 'accepted'");
            if($m2) {
                while($row = $m2->fetch_assoc()) {
                    $notify_stmt->bind_param("is", $row['user_id'], $msg_team2);
                    $notify_stmt->execute();
                }
            }

            $message = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-1"></i> สร้างตารางแข่งขันและแจ้งเตือนไปยังนักศึกษาในทีมทุกคนเรียบร้อยแล้ว! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $message = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการบันทึกข้อมูล</div>';
        }
    } else {
        $message = '<div class="alert alert-warning">กรุณาเลือกทีมที่แข่งขันให้ถูกต้องและไม่ซ้ำกัน</div>';
    }
}

// 3. แอดมินอัปเดตสถานะแมตช์การแข่งขัน
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_match_status'])) {
    $match_id   = intval($_POST['match_id']);
    $new_status = trim($_POST['new_status']);

    if ($match_id > 0 && !empty($new_status)) {
        $stmt_up = $conn->prepare("UPDATE matches SET status = ? WHERE id = ?");
        $stmt_up->bind_param("si", $new_status, $match_id);
        if ($stmt_up->execute()) {
            $message = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-1"></i> อัปเดตสถานะการแข่งขันเรียบร้อยแล้ว! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }
}

// 4. แอดมินลบรายการแข่งขัน
if (isset($_GET['delete_match'])) {
    $del_id = intval($_GET['delete_match']);
    $stmt_del = $conn->prepare("DELETE FROM matches WHERE id = ?");
    $stmt_del->bind_param("i", $del_id);
    $stmt_del->execute();
    header("Location: admin_dashboard.php");
    exit();
}

$all_teams_list = $conn->query("SELECT id, team_name, team_color, sport_name FROM teams ORDER BY sport_name ASC, team_name ASC");

$matches_list = $conn->query("
    SELECT m.*, 
           t1.team_name AS team1_name, t1.team_color AS team1_color,
           t2.team_name AS team2_name, t2.team_color AS team2_color
    FROM matches m
    JOIN teams t1 ON m.team1_id = t1.id
    JOIN teams t2 ON m.team2_id = t2.id
    ORDER BY m.id DESC
");

$search_team = trim($_GET['search_team'] ?? '');
$team_query = "
    SELECT t.*, u.fullname AS leader_name, u.student_id AS leader_student_id,
           (SELECT COUNT(*) FROM team_members WHERE team_id = t.id AND status = 'accepted') AS member_count
    FROM teams t
    JOIN users u ON t.leader_id = u.id
";
if (!empty($search_team)) {
    $safe_search = $conn->real_escape_string($search_team);
    $team_query .= " WHERE t.team_name LIKE '%$safe_search%' OR u.fullname LIKE '%$safe_search%'";
}
$team_query .= " ORDER BY t.sport_name ASC, t.created_at DESC";
$teams_list = $conn->query($team_query);

$filter_date = trim($_GET['filter_date'] ?? '');
$filter_team = trim($_GET['filter_team'] ?? '');

$complaint_where = [];
if (!empty($filter_date)) {
    $safe_date = $conn->real_escape_string($filter_date);
    $complaint_where[] = "DATE(c.created_at) = '$safe_date'";
}
if (!empty($filter_team)) {
    $safe_team = $conn->real_escape_string($filter_team);
    $complaint_where[] = "c.message LIKE '%[เกี่ยวกับทีม: $safe_team]%'";
}

$complaint_sql = "
    SELECT c.*, u.fullname, u.student_id 
    FROM complaints c 
    JOIN users u ON c.user_id = u.id 
";

if (count($complaint_where) > 0) {
    $complaint_sql .= " WHERE " . implode(' AND ', $complaint_where);
}
$complaint_sql .= " ORDER BY c.created_at DESC";

$complaints = $conn->query($complaint_sql);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - จัดการระบบแข่งขันและกิจกรรม</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="#"><i class="bi bi-shield-lock-fill"></i> ระบบจัดการสำหรับแอดมิน</a>
        <div class="d-flex align-items-center gap-3">
            <span class="text-white small">ผู้ดูแลระบบ: <?= htmlspecialchars($_SESSION['fullname'] ?? 'Admin') ?></span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">ออกจากระบบ</a>
        </div>
    </div>
</nav>

<div class="container my-4">

    <?= $message ?>

    <!-- ⚽ ส่วนที่ 1: จัดการตารางแข่งขัน -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-success text-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title mb-0 fw-bold"><i class="bi bi-calendar-event-fill me-2"></i> จัดการตารางการแข่งขันกีฬา</h5>
            <div class="d-flex gap-2">
                <button class="btn btn-light btn-sm fw-bold text-success" data-bs-toggle="modal" data-bs-target="#createMatchModal">
                    <i class="bi bi-plus-circle-fill"></i> สร้างการแข่งขันใหม่
                </button>
                <a href="schedule_landing.php" class="btn btn-warning btn-sm fw-bold text-dark">
                    <i class="bi bi-eye-fill"></i> ดูตารางการแข่งขัน
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 15%;">ชนิดกีฬา</th>
                            <th style="width: 30%; text-align: center;">คู่แข่งขัน (ทีม A VS ทีม B)</th>
                            <th style="width: 18%;">เวลาการแข่งขัน</th>
                            <th style="width: 15%;">สนาม / สถานที่</th>
                            <th style="width: 17%; text-align: center;">ปรับเปลี่ยนสถานะ</th>
                            <th style="width: 5%; text-align: center;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($matches_list && $matches_list->num_rows > 0): ?>
                            <?php while ($mc = $matches_list->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="badge bg-primary fs-6"><?= htmlspecialchars($mc['sport_name']) ?></span></td>
                                    <td class="text-center fw-bold">
                                        <span class="text-primary"><?= htmlspecialchars($mc['team1_name']) ?></span>
                                        <small class="text-muted opacity-75">(<?= htmlspecialchars($mc['team1_color']) ?>)</small>
                                        <span class="mx-2 text-danger">VS</span>
                                        <span class="text-primary"><?= htmlspecialchars($mc['team2_name']) ?></span>
                                        <small class="text-muted opacity-75">(<?= htmlspecialchars($mc['team2_color']) ?>)</small>
                                    </td>
                                    <td><small class="text-muted"><i class="bi bi-clock me-1"></i><?= htmlspecialchars($mc['match_time']) ?></small></td>
                                    <td><small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($mc['location']) ?></small></td>
                                    
                                    <td class="text-center">
                                        <form method="POST" class="d-inline-block">
                                            <input type="hidden" name="match_id" value="<?= $mc['id'] ?>">
                                            <input type="hidden" name="update_match_status" value="1">
                                            <select name="new_status" class="form-select form-select-sm fw-bold <?= $mc['status'] === 'live' ? 'border-danger text-danger' : ($mc['status'] === 'finished' ? 'border-success text-success' : 'border-warning text-dark') ?>" onchange="this.form.submit()">
                                                <option value="upcoming" <?= $mc['status'] === 'upcoming' ? 'selected' : '' ?>>⏳ รอดำเนินการ</option>
                                                <option value="live" <?= $mc['status'] === 'live' ? 'selected' : '' ?>>🔴 กำลังแข่งขัน (LIVE)</option>
                                                <option value="finished" <?= $mc['status'] === 'finished' ? 'selected' : '' ?>>✅ แข่งเสร็จแล้ว</option>
                                            </select>
                                        </form>
                                    </td>

                                    <td class="text-center">
                                        <a href="admin_dashboard.php?delete_match=<?= $mc['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('ยืนยันลบตารางการแข่งขันนี้?');">
                                            <i class="bi bi-trash-fill"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">ยังไม่มีการจัดตารางแข่งขัน</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 🏆 ส่วนที่ 2: ทีมกีฬาที่สร้างขึ้นในระบบ -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-warning text-dark py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title mb-0 fw-bold">
                <i class="bi bi-trophy-fill me-2"></i> ทีมกีฬาที่สร้างขึ้นในระบบ 
                <span class="badge bg-dark text-white rounded-pill ms-1"><?= $teams_list ? $teams_list->num_rows : 0 ?> ทีม</span>
            </h5>
            <form method="GET" class="d-flex gap-2" style="max-width: 350px;">
                <input type="text" name="search_team" class="form-control form-control-sm" placeholder="พิมพ์ชื่อทีม หรือ ชื่อหัวหน้าทีม..." value="<?= htmlspecialchars($search_team) ?>">
                <button type="submit" class="btn btn-dark btn-sm fw-bold">ค้นหา</button>
                <?php if (!empty($search_team)): ?>
                    <a href="admin_dashboard.php" class="btn btn-outline-dark btn-sm">ล้าง</a>
                <?php endif; ?>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 8%; text-align: center;">โลโก้</th>
                            <th style="width: 17%;">ชื่อทีม</th>
                            <th style="width: 15%;">ชนิดกีฬา</th>
                            <th style="width: 15%;">สีประจำทีม</th>
                            <th style="width: 20%;">หัวหน้าทีม</th>
                            <th style="width: 10%; text-align: center;">จำนวนสมาชิก</th>
                            <th style="width: 10%;">วันที่สร้าง</th>
                            <th style="width: 5%; text-align: center;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($teams_list && $teams_list->num_rows > 0): ?>
                            <?php while ($tm = $teams_list->fetch_assoc()): ?>
                                <tr>
                                    <td class="text-center">
                                        <?php if (!empty($tm['logo_url'])): ?>
                                            <img src="<?= htmlspecialchars($tm['logo_url']) ?>" class="rounded-circle border" style="width: 45px; height: 45px; object-fit: cover;">
                                        <?php else: ?>
                                            <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center" style="width: 45px; height: 45px;">
                                                <i class="bi bi-shield-fill"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong class="text-primary fs-6"><?= htmlspecialchars($tm['team_name']) ?></strong></td>
                                    <td>
                                        <?php if (!empty($tm['sport_name'])): ?>
                                            <span class="badge bg-dark rounded-pill px-2 py-1"><?= htmlspecialchars($tm['sport_name']) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">ไม่ระบุ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($tm['team_color']) ?></span></td>
                                    <td>
                                        <strong><?= htmlspecialchars($tm['leader_name']) ?></strong>
                                        <br><small class="text-muted">รหัส: <?= htmlspecialchars($tm['leader_student_id'] ?: '-') ?></small>
                                    </td>
                                    <td class="text-center"><span class="badge bg-info text-dark rounded-pill px-3 py-2"><?= $tm['member_count'] ?> คน</span></td>
                                    <td><small class="text-muted"><?= date('d/m/Y', strtotime($tm['created_at'])) ?></small></td>
                                    <td class="text-center">
                                        <a href="admin_dashboard.php?delete_team_by_admin=<?= $tm['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('คุณต้องการลบทีม <?= htmlspecialchars($tm['team_name']) ?> ใช่หรือไม่?');" title="ยกเลิก/ลบทีมนี้">
                                            <i class="bi bi-trash-fill"></i> ลบทีม
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">ไม่พบทีมกีฬา</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 🚨 ส่วนที่ 3: ประวัติและรายการแจ้งปัญหาพร้อมระบบตอบกลับต่อเนื่อง -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-danger text-white fw-bold d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title mb-0 fs-6">
                <i class="bi bi-clock-history me-2"></i> ประวัติรายการแจ้งปัญหา / ข้อร้องเรียนจากนักศึกษา (ประวัติการสนทนาโต้ตอบ)
            </h5>
            <span class="badge bg-light text-danger fw-bold"><?= $complaints ? $complaints->num_rows : 0 ?> รายการ</span>
        </div>
        <div class="card-body bg-light border-bottom">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <label class="form-label mb-1 fw-bold small text-muted"><i class="bi bi-calendar3 me-1"></i> เลือกวันที่แจ้งปัญหา</label>
                    <input type="date" name="filter_date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1 fw-bold small text-muted"><i class="bi bi-shield-fill me-1"></i> กรองตามทีมกีฬา</label>
                    <select name="filter_team" class="form-select form-select-sm">
                        <option value="">-- แสดงทุกทีม --</option>
                        <?php 
                        if ($all_teams_list && $all_teams_list->num_rows > 0) {
                            $all_teams_list->data_seek(0);
                            while ($t = $all_teams_list->fetch_assoc()) {
                                $selected = ($filter_team === $t['team_name']) ? 'selected' : '';
                                echo '<option value="' . htmlspecialchars($t['team_name']) . '" ' . $selected . '>' . htmlspecialchars($t['team_name']) . ' (' . htmlspecialchars($t['team_color']) . ')</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2" style="margin-top: 28px;">
                    <button type="submit" class="btn btn-danger btn-sm fw-bold w-100"><i class="bi bi-search me-1"></i> ค้นหาประวัติ</button>
                    <?php if (!empty($filter_date) || !empty($filter_team)): ?>
                        <a href="admin_dashboard.php" class="btn btn-outline-secondary btn-sm w-50">ล้างค้นหา</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 20%;">นักศึกษา</th>
                            <th style="width: 15%;">หัวข้อ</th>
                            <th style="width: 30%;">รายละเอียดปัญหาเริ่มต้น</th>
                            <th style="width: 35%;">ประวัติการสนทนาโต้ตอบ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($complaints && $complaints->num_rows > 0): ?>
                            <?php while ($cp = $complaints->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($cp['fullname']) ?></strong>
                                        <br><small class="text-muted">รหัส: <?= htmlspecialchars($cp['student_id'] ?: '-') ?></small>
                                    </td>
                                    <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($cp['subject']) ?></span></td>
                                    <td>
                                        <div class="p-2 bg-light rounded text-dark border mb-1"><?= nl2br(htmlspecialchars($cp['message'])) ?></div>
                                        <small class="text-muted"><i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($cp['created_at'])) ?> น.</small>
                                    </td>
                                    <td>
                                        <!-- กล่องแสดงประวัติการพิมพ์โต้ตอบทั้งหมด -->
                                        <div class="p-2 bg-white border rounded mb-2 shadow-sm" style="max-height: 160px; overflow-y: auto;">
                                            <?php
                                            $c_id = $cp['id'];
                                            $replies = $conn->query("SELECT * FROM complaint_replies WHERE complaint_id = $c_id ORDER BY created_at ASC");
                                            if ($replies && $replies->num_rows > 0) {
                                                while ($rp = $replies->fetch_assoc()) {
                                                    $is_admin = ($rp['sender_type'] === 'admin');
                                                    $bg_class = $is_admin ? 'bg-success bg-opacity-10 border-success text-success' : 'bg-primary bg-opacity-10 border-primary text-primary';
                                                    $sender_label = $is_admin ? '🛡️ แอดมิน' : '🎓 นักศึกษา';
                                                    echo '<div class="p-1.5 mb-1 border rounded small ' . $bg_class . '"><strong>' . $sender_label . ':</strong> ' . htmlspecialchars($rp['message']) . '<br><span class="text-muted" style="font-size:10px;">' . $rp['created_at'] . '</span></div>';
                                                }
                                            } else {
                                                echo '<span class="text-muted small">ยังไม่มีการตอบกลับ</span>';
                                            }
                                            ?>
                                        </div>

                                        <!-- ฟอร์มพิมพ์ข้อความตอบกลับต่อเนื่อง -->
                                        <form method="POST">
                                            <input type="hidden" name="complaint_id" value="<?= $cp['id'] ?>">
                                            <input type="hidden" name="student_user_id" value="<?= $cp['user_id'] ?>">
                                            <div class="input-group input-group-sm">
                                                <input type="text" name="admin_reply" class="form-control" placeholder="พิมพ์ข้อความตอบกลับ..." required>
                                                <button type="submit" name="reply_complaint" class="btn btn-dark"><i class="bi bi-send-fill"></i> ส่ง</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted">ไม่พบประวัติรายการแจ้งปัญหา</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Modal สำหรับสร้างแมตช์การแข่งขันใหม่ -->
<div class="modal fade" id="createMatchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-trophy"></i> สร้างตารางการแข่งขันใหม่</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">ชนิดกีฬา</label>
                        <select name="sport_name" id="sport_select" class="form-select" required>
                            <option value="">-- เลือกประเภทกีฬา --</option>
                            <option value="ฟุตบอล">⚽ ฟุตบอล</option>
                            <option value="บาสเกตบอล">🏀 บาสเกตบอล</option>
                            <option value="วอลเลย์บอล">🏐 วอลเลย์บอล</option>
                            <option value="แบดมินตัน">🏸 แบดมินตัน</option>
                            <option value="เปตอง">⚪ เปตอง</option>
                            <option value="E-Sports">🎮 E-Sports</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">ทีมแข่งขันที่ 1 (ฝั่ง A)</label>
                        <select name="team1_id" id="team1_select" class="form-select" required disabled>
                            <option value="">-- กรุณาเลือกประเภทกีฬาก่อน --</option>
                            <?php 
                            if ($all_teams_list) {
                                $all_teams_list->data_seek(0);
                                while ($t1 = $all_teams_list->fetch_assoc()) {
                                    $sport = htmlspecialchars($t1['sport_name'] ?? '');
                                    echo '<option value="' . $t1['id'] . '" data-sport="' . $sport . '">' . htmlspecialchars($t1['team_name']) . ' (' . htmlspecialchars($t1['team_color']) . ') - ' . $sport . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">ทีมแข่งขันที่ 2 (ฝั่ง B)</label>
                        <select name="team2_id" id="team2_select" class="form-select" required disabled>
                            <option value="">-- กรุณาเลือกประเภทกีฬาก่อน --</option>
                            <?php 
                            if ($all_teams_list) {
                                $all_teams_list->data_seek(0);
                                while ($t2 = $all_teams_list->fetch_assoc()) {
                                    $sport = htmlspecialchars($t2['sport_name'] ?? '');
                                    echo '<option value="' . $t2['id'] . '" data-sport="' . $sport . '">' . htmlspecialchars($t2['team_name']) . ' (' . htmlspecialchars($t2['team_color']) . ') - ' . $sport . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">เวลาการแข่งขัน</label>
                        <input type="text" name="match_time" class="form-control" placeholder="เช่น 10:00 - 11:30 น. (รอบรองฯ)" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">สนาม / สถานที่แข่งขัน</label>
                        <input type="text" name="location" class="form-control" placeholder="เช่น สนาม 1 (คอร์ท A) อาคารศูนย์กีฬา" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">สถานะแมตช์เริ่มต้น</label>
                        <input type="text" class="form-control bg-light text-dark fw-bold" value="⏳ รอดำเนินการ" readonly>
                        <input type="hidden" name="status" value="upcoming">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="create_match" class="btn btn-success fw-bold"><i class="bi bi-plus-lg"></i> สร้างตารางแข่ง</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const sportSelect = document.getElementById('sport_select');
    const team1Select = document.getElementById('team1_select');
    const team2Select = document.getElementById('team2_select');

    function filterTeamsBySport() {
        const selectedSport = sportSelect.value;
        if(selectedSport === "") {
            team1Select.disabled = true;
            team2Select.disabled = true;
            team1Select.options[0].text = "-- กรุณาเลือกประเภทกีฬาก่อน --";
            team2Select.options[0].text = "-- กรุณาเลือกประเภทกีฬาก่อน --";
            team1Select.value = "";
            team2Select.value = "";
            return;
        }

        team1Select.disabled = false;
        team2Select.disabled = false;
        team1Select.options[0].text = "-- เลือกทีมที่ 1 --";
        team2Select.options[0].text = "-- เลือกทีมที่ 2 --";

        Array.from(team1Select.options).forEach(option => {
            if (option.value === "") return;
            if (option.getAttribute('data-sport') === selectedSport) {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
            }
        });
        team1Select.value = ""; 

        Array.from(team2Select.options).forEach(option => {
            if (option.value === "") return;
            if (option.getAttribute('data-sport') === selectedSport) {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
            }
        });
        team2Select.value = ""; 
    }

    sportSelect.addEventListener('change', filterTeamsBySport);
});
</script>
</body>
</html>