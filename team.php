<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

// 1. จัดการสร้างทีมใหม่ (เช็กสีทีมซ้ำ และชื่อทีมซ้ำ)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_team'])) {
    $team_name  = trim($_POST['team_name']);
    $team_color = trim($_POST['team_color']);
    $logo_url   = trim($_POST['logo_url']); // ลิงก์ URL รูปโลโก้

    if (!empty($team_name) && !empty($team_color)) {
        
        // 🔍 ตรวจสอบว่ามีสีประจำทีมนี้ถูกใช้งานไปแล้วหรือไม่
        $check_color = $conn->prepare("SELECT id, team_name FROM teams WHERE team_color = ?");
        $check_color->bind_param("s", $team_color);
        $check_color->execute();
        $existing_color = $check_color->get_result()->fetch_assoc();

        if ($existing_color) {
            $message = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> สีทีม <strong>' . htmlspecialchars($team_color) . '</strong> มีผู้ใช้งานแล้วโดยทีม "' . htmlspecialchars($existing_color['team_name']) . '" กรุณาเลือกสีอื่น</div>';
        } else {
            // 🔍 ตรวจสอบว่าชื่อทีมซ้ำหรือไม่
            $check_name = $conn->prepare("SELECT id FROM teams WHERE team_name = ?");
            $check_name->bind_param("s", $team_name);
            $check_name->execute();

            if ($check_name->get_result()->num_rows > 0) {
                $message = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i> ชื่อทีม "' . htmlspecialchars($team_name) . '" นี้ถูกใช้งานแล้ว กรุณาใช้ชื่ออื่น</div>';
            } else {
                // บันทึกสร้างทีมใหม่
                $stmt = $conn->prepare("INSERT INTO teams (team_name, team_color, logo_url, leader_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssi", $team_name, $team_color, $logo_url, $user_id);
                
                if ($stmt->execute()) {
                    $new_team_id = $stmt->insert_id;
                    // บันทึกหัวหน้าทีมเข้าเป็นสมาชิกร่วมทีมทันที (status = accepted)
                    $stmt_m = $conn->prepare("INSERT INTO team_members (team_id, user_id, status) VALUES (?, ?, 'accepted')");
                    $stmt_m->bind_param("ii", $new_team_id, $user_id);
                    $stmt_m->execute();

                    $message = '<div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i> สร้างทีมสำเร็จเรียบร้อยแล้ว!</div>';
                }
            }
        }
    } else {
        $message = '<div class="alert alert-warning">กรุณากรอกข้อมูลชื่อทีมและเลือกสีทีมให้ครบถ้วน</div>';
    }
}

// 2. จัดการส่งคำชวนเพื่อนเข้าทีม
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invite_student'])) {
    $invited_user_id = intval($_POST['invited_user_id']);
    $my_team_id      = intval($_POST['team_id']);

    if ($invited_user_id > 0 && $my_team_id > 0) {
        $check = $conn->prepare("SELECT id FROM team_members WHERE team_id = ? AND user_id = ?");
        $check->bind_param("ii", $my_team_id, $invited_user_id);
        $check->execute();

        if ($check->get_result()->num_rows === 0) {
            $stmt_inv = $conn->prepare("INSERT INTO team_members (team_id, user_id, status) VALUES (?, ?, 'pending')");
            $stmt_inv->bind_param("ii", $my_team_id, $invited_user_id);
            $stmt_inv->execute();
            $message = '<div class="alert alert-success"><i class="bi bi-check-circle-fill me-1"></i> ส่งคำเชิญเข้าร่วมทีมเรียบร้อยแล้ว!</div>';
        } else {
            $message = '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i> นักศึกษาคนนี้อยู่ในทีมหรือถูกเชิญไปแล้ว</div>';
        }
    }
}

// 3. จัดการตอบรับ/ปฏิเสธ คำเชิญ
if (isset($_GET['action']) && isset($_GET['member_id'])) {
    $member_id = intval($_GET['member_id']);
    $action    = $_GET['action'] === 'accept' ? 'accepted' : 'rejected';

    $stmt_act = $conn->prepare("UPDATE team_members SET status = ? WHERE id = ? AND user_id = ?");
    $stmt_act->bind_param("sii", $action, $member_id, $user_id);
    $stmt_act->execute();
    
    $msg_alert = ($action === 'accepted') ? 'ตอบรับคำเชิญและเข้าร่วมทีมเรียบร้อยแล้ว!' : 'ปฏิเสธคำเชิญเรียบร้อยแล้ว';
    echo "<script>alert('$msg_alert'); window.location='team.php';</script>";
    exit();
}

// ดึงข้อมูลทีมที่ผู้ใช้เป็นสมาชิกอยู่
$my_team = null;
$team_stmt = $conn->prepare("
    SELECT t.*, u.fullname AS leader_name 
    FROM teams t
    JOIN team_members tm ON t.id = tm.team_id
    JOIN users u ON t.leader_id = u.id
    WHERE tm.user_id = ? AND tm.status = 'accepted' LIMIT 1
");
$team_stmt->bind_param("i", $user_id);
$team_stmt->execute();
$my_team = $team_stmt->get_result()->fetch_assoc();

// ถ้ามีทีมแล้ว ดึงรายชื่อสมาชิกในทีม
$team_members_list = null;
if ($my_team) {
    $m_stmt = $conn->prepare("
        SELECT tm.id, tm.status, u.fullname, u.student_id 
        FROM team_members tm
        JOIN users u ON tm.user_id = u.id
        WHERE tm.team_id = ?
    ");
    $m_stmt->bind_param("i", $my_team['id']);
    $m_stmt->execute();
    $team_members_list = $m_stmt->get_result();
}

// ดึงรายการสีทีมที่ถูกใช้ไปแล้ว (ใช้ปิดตัวเลือกสีที่ถูกเลือกไปแล้วใน Dropdown)
$used_colors = [];
$color_res = $conn->query("SELECT team_color FROM teams");
if ($color_res) {
    while ($c = $color_res->fetch_assoc()) {
        $used_colors[] = $c['team_color'];
    }
}

// ดึงรายชื่อนักศึกษาคนอื่นที่ยังไม่มีทีม (สำหรับค้นหาชวนเข้าทีม)
$other_students = $conn->query("
    SELECT id, fullname, student_id 
    FROM users 
    WHERE role = 'student' AND id != $user_id
    ORDER BY fullname ASC
");

// ดึงรายการคำเชิญเข้าทีมที่รอยืนยัน (Pending Invites)
$pending_invites = $conn->query("
    SELECT tm.id AS member_id, t.team_name, t.team_color, u.fullname AS leader_name
    FROM team_members tm
    JOIN teams t ON tm.team_id = t.id
    JOIN users u ON t.leader_id = u.id
    WHERE tm.user_id = $user_id AND tm.status = 'pending'
");
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการทีมกีฬา - ระบบกิจกรรม</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<div class="container my-4" style="max-width: 700px;">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold text-primary"><i class="bi bi-shield-fill"></i> ระบบทีมกีฬาและเพื่อนร่วมทีม</h4>
        <a href="student_dashboard.php" class="btn btn-outline-secondary btn-sm">ย้อนกลับ</a>
    </div>

    <?= $message ?>

    <!-- ส่วนที่ 1: รายการคำเชิญเข้าทีมที่รอตอบรับ -->
    <?php if ($pending_invites && $pending_invites->num_rows > 0): ?>
        <div class="card border-warning mb-4 shadow-sm">
            <div class="card-header bg-warning bg-opacity-25 fw-bold">
                <i class="bi bi-envelope-open-fill text-warning me-1"></i> คำเชิญเข้าร่วมทีมที่คุณได้รับ
            </div>
            <div class="card-body">
                <?php while ($inv = $pending_invites->fetch_assoc()): ?>
                    <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2">
                        <div>
                            <strong><?= htmlspecialchars($inv['team_name']) ?></strong> (<?= htmlspecialchars($inv['team_color']) ?>)
                            <br><small class="text-muted">หัวหน้าทีม: <?= htmlspecialchars($inv['leader_name']) ?></small>
                        </div>
                        <div>
                            <a href="team.php?action=accept&member_id=<?= $inv['member_id'] ?>" class="btn btn-success btn-sm fw-bold">เข้าร่วม</a>
                            <a href="team.php?action=reject&member_id=<?= $inv['member_id'] ?>" class="btn btn-outline-danger btn-sm">ปฏิเสธ</a>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- ส่วนที่ 2: แสดงข้อมูลทีมถ้ามีทีมอยู่แล้ว -->
    <?php if ($my_team): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body text-center p-4">
                <?php if (!empty($my_team['logo_url'])): ?>
                    <img src="<?= htmlspecialchars($my_team['logo_url']) ?>" class="rounded-circle mb-3 border shadow-sm" style="width: 100px; height: 100px; object-fit: cover;">
                <?php else: ?>
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 80px; height: 80px; font-size: 2rem;">
                        <i class="bi bi-trophy"></i>
                    </div>
                <?php endif; ?>

                <h3 class="fw-bold mb-1"><?= htmlspecialchars($my_team['team_name']) ?></h3>
                <span class="badge bg-info text-dark mb-3"><?= htmlspecialchars($my_team['team_color']) ?></span>
                <p class="small text-muted mb-0">หัวหน้าทีม: <?= htmlspecialchars($my_team['leader_name']) ?></p>
            </div>
        </div>

        <!-- รายชื่อสมาชิกในทีม -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-people-fill me-1"></i> สมาชิกภายในทีม</span>
                <?php if ($my_team['leader_id'] == $user_id): ?>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#inviteModal">
                        <i class="bi bi-person-plus-fill"></i> ชวนเพื่อนเข้าทีม
                    </button>
                <?php endif; ?>
            </div>
            <ul class="list-group list-group-flush">
                <?php if ($team_members_list): ?>
                    <?php while ($m = $team_members_list->fetch_assoc()): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= htmlspecialchars($m['fullname']) ?></strong>
                                <small class="text-muted d-block">รหัส: <?= htmlspecialchars($m['student_id'] ?: '-') ?></small>
                            </div>
                            <?php if ($m['status'] === 'accepted'): ?>
                                <span class="badge bg-success">เข้าร่วมแล้ว</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">รอตอบรับ</span>
                            <?php endif; ?>
                        </li>
                    <?php endwhile; ?>
                <?php endif; ?>
            </ul>
        </div>

    <!-- ส่วนที่ 3: แบบฟอร์มสร้างทีมใหม่ (ถ้ายังไม่มีทีม) -->
    <?php else: ?>
        <div class="card shadow-sm border-0 p-4">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-plus-circle-fill text-primary me-1"></i> คุณยังไม่มีทีม สร้างทีมใหม่</h5>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label fw-bold">ชื่อทีมกีฬา</label>
                    <input type="text" name="team_name" class="form-control" placeholder="เช่น สิงห์นครสวรรค์ FC" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">เลือกสีประจำทีม (เลือกได้เฉพาะสีที่ยังไม่มีทีมสร้าง)</label>
                    <select name="team_color" class="form-select" required>
                        <option value="">-- เลือกสีประจำทีม --</option>
                        <?php 
                            $all_colors = [
                                "🔴 ทีมสีแดง" => "🔴 ทีมสีแดง",
                                "🔵 ทีมสีน้ำเงิน" => "🔵 ทีมสีน้ำเงิน",
                                "🟡 ทีมสีเหลือง" => "🟡 ทีมสีเหลือง",
                                "🟢 ทีมสีเขียว" => "🟢 ทีมสีเขียว",
                                "🟠 ทีมสีแสด/ส้ม" => "🟠 ทีมสีแสด/ส้ม",
                                "🟣 ทีมสีม่วง" => "🟣 ทีมสีม่วง"
                            ];

                            foreach ($all_colors as $key => $val) {
                                $is_used = in_array($key, $used_colors);
                                $disabled = $is_used ? 'disabled' : '';
                                $label_text = $is_used ? $val . ' (มีผู้เลือกแล้ว)' : $val;
                                echo "<option value=\"$key\" $disabled>$label_text</option>";
                            }
                        ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">ลิงก์รูปโลโก้ทีม (URL รูปภาพ) - Optional</label>
                    <input type="url" name="logo_url" class="form-control" placeholder="https://example.com/logo.png">
                </div>

                <button type="submit" name="create_team" class="btn btn-primary w-100 fw-bold rounded-pill mt-2">
                    <i class="bi bi-check-circle-fill me-1"></i> ยืนยันสร้างทีม
                </button>
            </form>
        </div>
    <?php endif; ?>

</div>

<!-- Modal ชวนเพื่อนเข้าทีม -->
<?php if ($my_team && $my_team['leader_id'] == $user_id): ?>
<div class="modal fade" id="inviteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus"></i> ชวนนักศึกษาคนอื่นเข้าทีม</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="team_id" value="<?= $my_team['id'] ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">เลือกรายชื่อเพื่อนนักศึกษา</label>
                        <select name="invited_user_id" class="form-select" required>
                            <option value="">-- เลือกเพื่อนที่ต้องการชวน --</option>
                            <?php if ($other_students): ?>
                                <?php while ($st = $other_students->fetch_assoc()): ?>
                                    <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['fullname']) ?> (รหัส: <?= htmlspecialchars($st['student_id'] ?: '-') ?>)</option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" name="invite_student" class="btn btn-primary"><i class="bi bi-send-fill"></i> ส่งคำเชิญ</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>