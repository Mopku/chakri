<?php
session_start();
require_once 'db.php';

// ตรวจสอบสิทธิ์เฉพาะแอดมิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$message = '';

// 1. แอดมินลบทีมที่นักศึกษาสร้างขึ้น
if (isset($_GET['delete_team_by_admin'])) {
    $del_team_id = intval($_GET['delete_team_by_admin']);
    $stmt_del_t = $conn->prepare("DELETE FROM teams WHERE id = ?");
    $stmt_del_t->bind_param("i", $del_team_id);
    if ($stmt_del_t->execute()) {
        $message = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-1"></i> ลบทีมกีฬาเรียบร้อยแล้ว <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

// 2. แอดมินสร้างแมตช์การแข่งขันใหม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_match'])) {
    $sport_name = trim($_POST['sport_name']);
    $team1_id   = intval($_POST['team1_id']);
    $team2_id   = intval($_POST['team2_id']);
    $match_time = trim($_POST['match_time']);
    $location   = trim($_POST['location']);
    $status     = trim($_POST['status']);

    if (!empty($sport_name) && $team1_id > 0 && $team2_id > 0 && $team1_id !== $team2_id) {
        $stmt_m = $conn->prepare("INSERT INTO matches (sport_name, team1_id, team2_id, match_time, location, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_m->bind_param("siisss", $sport_name, $team1_id, $team2_id, $match_time, $location, $status);
        if ($stmt_m->execute()) {
            $message = '<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-1"></i> สร้างตารางแข่งขันเรียบร้อยแล้ว! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        } else {
            $message = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการบันทึกข้อมูล</div>';
        }
    } else {
        $message = '<div class="alert alert-warning">กรุณาเลือกทีมที่แข่งขันให้ถูกต้องและไม่ซ้ำกัน</div>';
    }
}

// 3. แอดมินลบรายการแข่งขัน
if (isset($_GET['delete_match'])) {
    $del_id = intval($_GET['delete_match']);
    $stmt_del = $conn->prepare("DELETE FROM matches WHERE id = ?");
    $stmt_del->bind_param("i", $del_id);
    $stmt_del->execute();
    header("Location: admin_dashboard.php");
    exit();
}

// ดึงรายชื่อทีมทั้งหมดสำหรับดร็อปดาวน์เลือกแมตช์แข่ง
$all_teams_dropdown = $conn->query("SELECT id, team_name, team_color FROM teams ORDER BY team_name ASC");
$all_teams_dropdown2 = $conn->query("SELECT id, team_name, team_color FROM teams ORDER BY team_name ASC");

// ดึงรายการแมตช์การแข่งขันทั้งหมด
$matches_list = $conn->query("
    SELECT m.*, 
           t1.team_name AS team1_name, t1.team_color AS team1_color,
           t2.team_name AS team2_name, t2.team_color AS team2_color
    FROM matches m
    JOIN teams t1 ON m.team1_id = t1.id
    JOIN teams t2 ON m.team2_id = t2.id
    ORDER BY m.id DESC
");

// ระบบค้นหาชื่อทีมกีฬา
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
$team_query .= " ORDER BY t.created_at DESC";
$teams_list = $conn->query($team_query);

// ดึงรายการแจ้งปัญหา / ข้อร้องเรียน
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

    <!-- ⚽ ส่วนที่ 1: จัดการตารางแข่งขัน & ปุ่มลิงก์ไปหน้าชมตารางแข่ง -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-success text-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title mb-0 fw-bold"><i class="bi bi-calendar-event-fill me-2"></i> จัดการตารางการแข่งขันกีฬา</h5>
            <div class="d-flex gap-2">
                <button class="btn btn-light btn-sm fw-bold text-success" data-bs-toggle="modal" data-bs-target="#createMatchModal">
                    <i class="bi bi-plus-circle-fill"></i> สร้างการแข่งขันใหม่
                </button>
                <!-- เปลี่ยนปุ่มเอา QR ออก แล้วเปลี่ยนเป็นปุ่มดูตารางการแข่งขัน -->
                <a href="schedule_landing.php" class="btn btn-warning btn-sm fw-bold text-dark" target="_blank">
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
                            <th style="width: 35%; text-align: center;">คู่แข่งขัน (ทีม A VS ทีม B)</th>
                            <th style="width: 20%;">เวลาการแข่งขัน</th>
                            <th style="width: 15%;">สนาม / สถานที่</th>
                            <th style="width: 10%; text-align: center;">สถานะ</th>
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
                                        <?php if ($mc['status'] === 'live'): ?>
                                            <span class="badge bg-danger"><i class="bi bi-record-fill"></i>กำลังแข่งขัน</span>
                                        <?php elseif ($mc['status'] === 'finished'): ?>
                                            <span class="badge bg-success">แข่งเสร็จแล้ว</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">รอดำเนินการ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="admin_dashboard.php?delete_match=<?= $mc['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('ยืนยันลบตารางการแข่งขันนี้?');">
                                            <i class="bi bi-trash-fill"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted">ยังไม่มีการจัดตารางแข่งขัน (กดปุ่ม "สร้างการแข่งขันใหม่" ด้านบน)</td></tr>
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
                            <th style="width: 22%;">ชื่อทีม</th>
                            <th style="width: 18%;">สีประจำทีม</th>
                            <th style="width: 22%;">หัวหน้าทีม</th>
                            <th style="width: 10%; text-align: center;">จำนวนสมาชิก</th>
                            <th style="width: 10%;">วันที่สร้าง</th>
                            <th style="width: 10%; text-align: center;">จัดการ</th>
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
                            <tr><td colspan="7" class="text-center py-4 text-muted">ไม่พบทีมกีฬา</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ส่วนที่ 3: รายการแจ้งปัญหา / ข้อร้องเรียน -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-danger text-white fw-bold d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0 fs-6"><i class="bi bi-chat-square-text-fill me-2"></i> รายการแจ้งปัญหา / ข้อร้องเรียนจากนักศึกษา</h5>
            <span class="badge bg-light text-danger fw-bold"><?= $complaints ? $complaints->num_rows : 0 ?> รายการ</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>นักศึกษา</th><th>หัวข้อ</th><th>รายละเอียด</th><th>วันที่แจ้ง</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($complaints && $complaints->num_rows > 0): ?>
                            <?php while ($cp = $complaints->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($cp['fullname']) ?></strong><br><small class="text-muted">รหัส: <?= htmlspecialchars($cp['student_id'] ?: '-') ?></small></td>
                                    <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($cp['subject']) ?></span></td>
                                    <td><div class="p-2 bg-light rounded text-dark border"><?= nl2br(htmlspecialchars($cp['message'])) ?></div></td>
                                    <td><small class="text-muted"><?= date('d/m/Y H:i', strtotime($cp['created_at'])) ?> น.</small></td>
                                </tr>
                            <?php endwhile; ?>
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
                        <select name="sport_name" class="form-select" required>
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
                        <select name="team1_id" class="form-select" required>
                            <option value="">-- เลือกทีมที่ 1 --</option>
                            <?php if ($all_teams_dropdown): ?>
                                <?php while ($t1 = $all_teams_dropdown->fetch_assoc()): ?>
                                    <option value="<?= $t1['id'] ?>"><?= htmlspecialchars($t1['team_name']) ?> (<?= htmlspecialchars($t1['team_color']) ?>)</option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">ทีมแข่งขันที่ 2 (ฝั่ง B)</label>
                        <select name="team2_id" class="form-select" required>
                            <option value="">-- เลือกทีมที่ 2 --</option>
                            <?php if ($all_teams_dropdown2): ?>
                                <?php while ($t2 = $all_teams_dropdown2->fetch_assoc()): ?>
                                    <option value="<?= $t2['id'] ?>"><?= htmlspecialchars($t2['team_name']) ?> (<?= htmlspecialchars($t2['team_color']) ?>)</option>
                                <?php endwhile; ?>
                            <?php endif; ?>
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
                        <label class="form-label fw-bold">สถานะแมตช์</label>
                        <select name="status" class="form-select" required>
                            <option value="live">🔴 กำลังแข่งขัน (LIVE)</option>
                            <option value="upcoming" selected>⏳ รอดำเนินการ</option>
                            <option value="finished">✅ แข่งเสร็จแล้ว</option>
                        </select>
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
</body>
</html>