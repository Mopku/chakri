<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

// ตรวจสอบว่าตาราง teams มีคอลัมน์ sport_name หรือยัง
$has_sport_col = false;
$chk_col = $conn->query("SHOW COLUMNS FROM teams LIKE 'sport_name'");
if ($chk_col && $chk_col->num_rows > 0) {
    $has_sport_col = true;
}

// 1. จัดการสร้างทีมใหม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_team'])) {
    $team_name  = trim($_POST['team_name']);
    $team_color = trim($_POST['team_color']);
    $sport_name = trim($_POST['sport_name'] ?? '');
    $logo_url   = '';

    // จัดการอัปโหลดไฟล์รูปภาพ (ใช้ getimagesize ปลอดภัย 100% และไม่ Error 500)
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['logo_file']['tmp_name'];
        $file_size = $_FILES['logo_file']['size'];

        // จำกัดขนาดไม่เกิน 1.5 MB เพื่อไม่ให้ฐานข้อมูลพัง
        if ($file_size <= 1.5 * 1024 * 1024) {
            $img_info = @getimagesize($file_tmp);

            if ($img_info !== false) {
                $mime_type = $img_info['mime'];
                $raw_bytes = file_get_contents($file_tmp);
                $logo_url  = 'data:' . $mime_type . ';base64,' . base64_encode($raw_bytes);
            } else {
                $message = '<div class="alert alert-warning">ไฟล์ที่อัปโหลดไม่ใช่รูปแบบรูปภาพที่ถูกต้อง กรุณาเลือกไฟล์ใหม่</div>';
            }
        } else {
            $message = '<div class="alert alert-warning">รูปภาพมีขนาดใหญ่เกินไป ฐานข้อมูลไม่สามารถรับได้ กรุณาย่อรูปให้มีขนาดเล็กกว่า 1.5MB ครับ</div>';
        }
    } elseif (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_INI_SIZE) {
        $message = '<div class="alert alert-danger">ไฟล์มีขนาดใหญ่เกินขีดจำกัดของเซิร์ฟเวอร์ กรุณาเลือกรูปภาพที่เล็กลง</div>';
    }

    if (!empty($team_name) && !empty($team_color) && empty($message)) {
        if ($has_sport_col && !empty($sport_name)) {
            $check_color = $conn->prepare("SELECT id, team_name FROM teams WHERE team_color = ? AND sport_name = ?");
            $check_color->bind_param("ss", $team_color, $sport_name);
        } else {
            $check_color = $conn->prepare("SELECT id, team_name FROM teams WHERE team_color = ?");
            $check_color->bind_param("s", $team_color);
        }

        $check_color->execute();
        $existing_team = $check_color->get_result()->fetch_assoc();

        if ($existing_team) {
            $message = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i> สีทีม <strong>' . htmlspecialchars($team_color) . '</strong> มีผู้ใช้งานแล้วโดยทีม "' . htmlspecialchars($existing_team['team_name']) . '" กรุณาเลือกสีอื่น</div>';
        } else {
            // ดักจับ Error ด้วย Try-Catch ป้องกันหน้าเว็บล่มเป็น HTTP 500
            try {
                if ($has_sport_col) {
                    $stmt = $conn->prepare("INSERT INTO teams (team_name, team_color, sport_name, logo_url, leader_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssi", $team_name, $team_color, $sport_name, $logo_url, $user_id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO teams (team_name, team_color, logo_url, leader_id) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("sssi", $team_name, $team_color, $logo_url, $user_id);
                }
                
                if ($stmt->execute()) {
                    $new_team_id = $stmt->insert_id;
                    $stmt_m = $conn->prepare("INSERT INTO team_members (team_id, user_id, status) VALUES (?, ?, 'accepted')");
                    $stmt_m->bind_param("ii", $new_team_id, $user_id);
                    $stmt_m->execute();

                    header("Location: student_dashboard.php");
                    exit();
                } else {
                    $message = '<div class="alert alert-danger">เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง</div>';
                }
            } catch (Exception $e) {
                $message = '<div class="alert alert-danger">ระบบปฏิเสธการบันทึก: ข้อความหรือไฟล์รูปภาพอาจมีขนาดใหญ่เกินกว่าที่ระบบรองรับได้ กรุณาลองเปลี่ยนเป็นรูปที่เล็กลง (แนะนำต่ำกว่า 1MB)</div>';
            }
        }
    } else if (empty($message)) {
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

// 4. สมาชิกกด "ออกจากทีม"
if (isset($_GET['leave_team_id'])) {
    $leave_team_id = intval($_GET['leave_team_id']);

    $check_match = $conn->prepare("SELECT id FROM matches WHERE team1_id = ? OR team2_id = ?");
    $check_match->bind_param("ii", $leave_team_id, $leave_team_id);
    $check_match->execute();

    if ($check_match->get_result()->num_rows > 0) {
        echo "<script>alert('ไม่สามารถออกจากทีมได้ เนื่องจากทีมนี้ถูกจัดตารางการแข่งขันเรียบร้อยแล้ว!'); window.location='team.php';</script>";
        exit();
    }

    $chk_leader = $conn->prepare("SELECT leader_id FROM teams WHERE id = ?");
    $chk_leader->bind_param("i", $leave_team_id);
    $chk_leader->execute();
    $team_info = $chk_leader->get_result()->fetch_assoc();

    if ($team_info) {
        $stmt_leave = $conn->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ?");
        $stmt_leave->bind_param("ii", $leave_team_id, $user_id);
        $stmt_leave->execute();

        if ($team_info['leader_id'] == $user_id) {
            $get_next = $conn->prepare("SELECT user_id FROM team_members WHERE team_id = ? AND status = 'accepted' ORDER BY id ASC LIMIT 1");
            $get_next->bind_param("i", $leave_team_id);
            $get_next->execute();
            $next_leader = $get_next->get_result()->fetch_assoc();

            if ($next_leader) {
                $up_leader = $conn->prepare("UPDATE teams SET leader_id = ? WHERE id = ?");
                $up_leader->bind_param("ii", $next_leader['user_id'], $leave_team_id);
                $up_leader->execute();
            } else {
                $del_team = $conn->prepare("DELETE FROM teams WHERE id = ?");
                $del_team->bind_param("i", $leave_team_id);
                $del_team->execute();
            }
        }

        echo "<script>alert('คุณได้ออกจากทีมเรียบร้อยแล้ว'); window.location='team.php';</script>";
        exit();
    }
}

// 5. เตะสมาชิกร่วมทีมออก / ยกเลิกคำเชิญ
if (isset($_GET['kick_user']) && isset($_GET['team_id'])) {
    $kick_user_id = intval($_GET['kick_user']);
    $team_id      = intval($_GET['team_id']);

    $check_leader = $conn->prepare("SELECT id FROM teams WHERE id = ? AND leader_id = ?");
    $check_leader->bind_param("ii", $team_id, $user_id);
    $check_leader->execute();

    if ($check_leader->get_result()->num_rows > 0 && $kick_user_id !== $user_id) {
        $stmt_kick = $conn->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ?");
        $stmt_kick->bind_param("ii", $team_id, $kick_user_id);
        $stmt_kick->execute();
        echo "<script>alert('ลบสมาชิก / ยกเลิกคำเชิญเรียบร้อยแล้ว'); window.location='team.php';</script>";
        exit();
    }
}

// 6. ยกเลิก / ลบทีม
if (isset($_GET['delete_team_id'])) {
    $del_team_id = intval($_GET['delete_team_id']);

    $check_leader = $conn->prepare("SELECT id FROM teams WHERE id = ? AND leader_id = ?");
    $check_leader->bind_param("ii", $del_team_id, $user_id);
    $check_leader->execute();

    if ($check_leader->get_result()->num_rows > 0) {
        $check_match = $conn->prepare("SELECT id FROM matches WHERE team1_id = ? OR team2_id = ?");
        $check_match->bind_param("ii", $del_team_id, $del_team_id);
        $check_match->execute();

        if ($check_match->get_result()->num_rows > 0) {
            echo "<script>alert('ไม่สามารถยกเลิกทีมได้ เนื่องจากทีมของคุณถูกแอดมินนำไปจัดตารางการแข่งขันเรียบร้อยแล้ว!'); window.location='team.php';</script>";
            exit();
        } else {
            $stmt_del_t = $conn->prepare("DELETE FROM teams WHERE id = ?");
            $stmt_del_t->bind_param("i", $del_team_id);
            $stmt_del_t->execute();
            echo "<script>alert('ยกเลิกและลบทีมเรียบร้อยแล้ว'); window.location='team.php';</script>";
            exit();
        }
    }
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

// ตรวจสอบสถานะการจัดแข่ง
$is_in_match = false;
if ($my_team) {
    $chk_m = $conn->prepare("SELECT id FROM matches WHERE team1_id = ? OR team2_id = ?");
    $chk_m->bind_param("ii", $my_team['id'], $my_team['id']);
    $chk_m->execute();
    if ($chk_m->get_result()->num_rows > 0) {
        $is_in_match = true;
    }
}

// ดึงรายชื่อสมาชิกในทีม
$team_members_list = null;
if ($my_team) {
    $m_stmt = $conn->prepare("
        SELECT tm.id AS member_record_id, tm.status, u.id AS member_user_id, u.fullname, u.student_id 
        FROM team_members tm
        JOIN users u ON tm.user_id = u.id
        WHERE tm.team_id = ?
        ORDER BY (u.id = ?) DESC, tm.status ASC
    ");
    $m_stmt->bind_param("ii", $my_team['id'], $my_team['leader_id']);
    $m_stmt->execute();
    $team_members_list = $m_stmt->get_result();
}

// ดึงรายชื่อนักศึกษาคนอื่นที่ยังไม่มีทีม
$other_students = $conn->query("
    SELECT id, fullname, student_id 
    FROM users 
    WHERE role = 'student' AND id != $user_id
    ORDER BY fullname ASC
");

// ดึงรายการคำเชิญเข้าทีมที่รอยืนยัน
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
                <?php if (isset($my_team['sport_name'])): ?>
                    <span class="badge bg-primary text-white me-1"><?= htmlspecialchars($my_team['sport_name']) ?></span>
                <?php endif; ?>
                <span class="badge bg-info text-dark mb-3"><?= htmlspecialchars($my_team['team_color']) ?></span>
                <p class="small text-muted mb-2">หัวหน้าทีม: <?= htmlspecialchars($my_team['leader_name']) ?></p>

                <div class="d-flex justify-content-center gap-2 flex-wrap mt-2">
                    <?php if (!$is_in_match): ?>
                        <a href="team.php?leave_team_id=<?= $my_team['id'] ?>" class="btn btn-warning btn-sm rounded-pill fw-bold text-dark" onclick="return confirm('คุณแน่ใจหรือไม่ว่าต้องการออกจากทีมนี้?');">
                            <i class="bi bi-box-arrow-right me-1"></i> ออกจากทีม
                        </a>
                    <?php endif; ?>

                    <?php if ($my_team['leader_id'] == $user_id): ?>
                        <?php if ($is_in_match): ?>
                            <div class="badge bg-success-subtle text-success border border-success p-2 d-inline-block">
                                <i class="bi bi-check-circle-fill me-1"></i> ทีมนี้ถูกจัดลงตารางการแข่งขันแล้ว
                            </div>
                        <?php else: ?>
                            <a href="team.php?delete_team_id=<?= $my_team['id'] ?>" class="btn btn-outline-danger btn-sm rounded-pill" onclick="return confirm('คุณต้องการยกเลิกและลบทีมนี้ใช่หรือไม่?');">
                                <i class="bi bi-trash-fill me-1"></i> ยกเลิก &amp; ลบทีมนี้
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
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
                                <?php if ($m['member_user_id'] == $my_team['leader_id']): ?>
                                    <span class="badge bg-warning text-dark ms-1">หัวหน้าทีม</span>
                                <?php endif; ?>
                                <?php if ($m['member_user_id'] == $user_id): ?>
                                    <span class="badge bg-info text-dark ms-1">คุณ</span>
                                <?php endif; ?>
                                <small class="text-muted d-block">รหัส: <?= htmlspecialchars($m['student_id'] ?: '-') ?></small>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($m['status'] === 'accepted'): ?>
                                    <span class="badge bg-success">เข้าร่วมแล้ว</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary opacity-75">รอตอบรับ</span>
                                <?php endif; ?>

                                <?php if ($my_team['leader_id'] == $user_id && $m['member_user_id'] != $user_id): ?>
                                    <a href="team.php?kick_user=<?= $m['member_user_id'] ?>&team_id=<?= $my_team['id'] ?>" class="btn btn-outline-danger btn-sm py-0 px-2" onclick="return confirm('คุณต้องการลบคุณ <?= htmlspecialchars($m['fullname']) ?> ออกจากทีมใช่หรือไม่?');">
                                        <i class="bi bi-person-x-fill"></i> เตะออก
                                    </a>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endwhile; ?>
                <?php endif; ?>
            </ul>
        </div>

    <!-- ส่วนที่ 3: แบบฟอร์มสร้างทีมใหม่ -->
    <?php else: ?>
        <div class="card shadow-sm border-0 p-4">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-plus-circle-fill text-primary me-1"></i> คุณยังไม่มีทีม สร้างทีมใหม่</h5>
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label fw-bold">ชื่อทีมกีฬา</label>
                    <input type="text" name="team_name" class="form-control" placeholder="เช่น สิงห์นครสวรรค์ FC" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">ชนิดกีฬาที่ต้องการลงแข่งขัน</label>
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
                    <label class="form-label fw-bold">เลือกสีประจำทีม</label>
                    <select name="team_color" class="form-select" required>
                        <option value="">-- เลือกสีประจำทีม --</option>
                        <option value="🔴 ทีมสีแดง">🔴 ทีมสีแดง</option>
                        <option value="🔵 ทีมสีน้ำเงิน">🔵 ทีมสีน้ำเงิน / สีฟ้า</option>
                        <option value="🟡 ทีมสีเหลือง">🟡 ทีมสีเหลือง</option>
                        <option value="🟢 ทีมสีเขียว">🟢 ทีมสีเขียว</option>
                        <option value="🟠 ทีมสีแสด/ส้ม">🟠 ทีมสีแสด/ส้ม</option>
                        <option value="🟣 ทีมสีม่วง">🟣 ทีมสีม่วง</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold"><i class="bi bi-image me-1"></i> เลือกรูปโลโก้ทีมจากเครื่อง / โทรศัพท์</label>
                    <input type="file" name="logo_file" class="form-control" accept="image/*">
                    <small class="text-muted d-block mt-1">* หากต้องการใส่โลโก้ แนะนำให้เลือกรูปขนาดเล็ก (ไม่บังคับ)</small>
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