<?php
// parent/attendance.php - CORRECTED (parent_portal first)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

if (!isset($_SESSION['parent_id'])) {
    header('Location: ' . BASE_URL . 'parent/login.php');
    exit;
}

$db = getDB();
$parent_id = (int)$_SESSION['parent_id'];
$school_id = (int)$_SESSION['school_id'];
$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
$selected_term = $_GET['term'] ?? '';
$selected_session = $_GET['session'] ?? '';

// ========== FIX: Check parent_portal FIRST ==========
$children = [];

$parentStmt = $db->prepare("SELECT student_id FROM parent_portal WHERE id = ? AND school_id = ? AND is_active = 1");
$parentStmt->execute([$parent_id, $school_id]);
$parent = $parentStmt->fetch();

if ($parent) {
    $student_id = (int)$parent['student_id'];
    $stmt = $db->prepare("SELECT s.id, s.first_name, s.last_name, s.photo_path, c.name AS class_name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.id = ? AND s.school_id = ?");
    $stmt->execute([$student_id, $school_id]);
    $children = $stmt->fetchAll();
}

// Fallback to parent_students if needed
if (empty($children) && $db->query("SHOW TABLES LIKE 'parent_students'")->rowCount() > 0) {
    $stmt = $db->prepare("SELECT s.id, s.first_name, s.last_name, s.photo_path, c.name AS class_name FROM parent_students ps JOIN students s ON ps.student_id = s.id JOIN classes c ON s.class_id = c.id WHERE ps.parent_id = ? AND s.school_id = ? ORDER BY s.first_name");
    $stmt->execute([$parent_id, $school_id]);
    $children = $stmt->fetchAll();
}

if (empty($children)) die("No child found.");

$activeChildId = $child_id ?: $children[0]['id'];
$activeChild = null;
foreach ($children as $child) {
    if ($child['id'] === $activeChildId) { $activeChild = $child; break; }
}
if (!$activeChild) $activeChild = $children[0];

// Get current term/session
$schoolStmt = $db->prepare("SELECT current_term, current_session FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$schoolInfo = $schoolStmt->fetch();
$current_term = $schoolInfo['current_term'] ?? 'Term 1';
$current_session = $schoolInfo['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

if (empty($selected_term) || empty($selected_session)) {
    $selected_term = $current_term;
    $selected_session = $current_session;
}

// Get attendance summary
$attStmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM attendance_log WHERE student_id = ? AND school_id = ? AND term_name = ? AND session_year = ? GROUP BY status");
$attStmt->execute([$activeChild['id'], $school_id, $selected_term, $selected_session]);
$attendance = ['Present' => 0, 'Absent' => 0, 'Late' => 0, 'Excused' => 0];
while ($row = $attStmt->fetch()) {
    if (isset($attendance[$row['status']])) $attendance[$row['status']] = (int)$row['cnt'];
}

// Recent records
$recentStmt = $db->prepare("SELECT attendance_date, status, time_marked FROM attendance_log WHERE student_id = ? AND school_id = ? AND term_name = ? AND session_year = ? ORDER BY attendance_date DESC LIMIT 15");
$recentStmt->execute([$activeChild['id'], $school_id, $selected_term, $selected_session]);
$recentRecords = $recentStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance | Parent Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f1f5f9; min-height: 100vh; }
        .container { max-width: 450px; margin: 0 auto; padding: 0 16px 30px; }
        
        .topbar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 16px 10px;
        }
        .topbar .back {
            background: white;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0f172a;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .topbar h3 { font-weight: 800; color: #0f172a; font-size: 1.1rem; }
        
        .child-card {
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            border-radius: 20px;
            padding: 16px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
        }
        .child-card img { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.5); }
        .child-card .name { font-weight: 700; font-size: 0.95rem; }
        .child-card .class { font-size: 0.75rem; opacity: 0.8; }
        
        .stats-row {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }
        .stat-box {
            background: white;
            border-radius: 14px;
            padding: 14px 8px;
            flex: 1;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .stat-box .num { font-size: 1.2rem; font-weight: 800; }
        .stat-box .lbl { font-size: 0.65rem; color: #64748b; font-weight: 600; text-transform: uppercase; }
        .c-green { color: #16a34a; }
        .c-red { color: #dc2626; }
        .c-yellow { color: #f59e0b; }
        .c-blue { color: #2563eb; }
        
        .section-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #94a3b8;
            font-weight: 700;
            margin: 20px 0 10px;
        }
        
        .record-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .record-item {
            background: white;
            border-radius: 12px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .record-item .date { font-weight: 600; color: #0f172a; font-size: 0.85rem; }
        .record-item .time { font-size: 0.7rem; color: #94a3b8; }
        
        .badge { padding: 5px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; }
        .badge-present { background: #dcfce7; color: #166534; }
        .badge-absent { background: #fee2e2; color: #991b1b; }
        .badge-late { background: #fef3c7; color: #92400e; }
        .badge-excused { background: #dbeafe; color: #1e40af; }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <a href="index.php" class="back"><i class="fas fa-arrow-left"></i></a>
        <h3>📅 Attendance</h3>
    </div>

    <?php if (count($children) > 1): ?>
    <select class="form-select mb-2" onchange="window.location='?child_id='+this.value" style="border-radius:12px;border-color:#e2e8f0;font-weight:600;font-size:0.85rem;">
        <?php foreach ($children as $s): ?>
            <option value="<?php echo $s['id']; ?>" <?php echo $activeChild['id'] === $s['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <div class="child-card">
        <?php $photo = ($activeChild['photo_path'] && $activeChild['photo_path'] !== 'default_student.png') ? BASE_URL . 'uploads/student_photos/' . $activeChild['photo_path'] : BASE_URL . 'assets/images/default_avatar.png'; ?>
        <img src="<?php echo $photo; ?>" alt="Child">
        <div>
            <div class="name"><?php echo htmlspecialchars($activeChild['first_name'] . ' ' . $activeChild['last_name']); ?></div>
            <div class="class"><?php echo htmlspecialchars($activeChild['class_name']); ?> • <?php echo $selected_term; ?></div>
        </div>
    </div>

    <div class="stats-row">
        <div class="stat-box"><div class="num c-green"><?php echo $attendance['Present']; ?></div><div class="lbl">Present</div></div>
        <div class="stat-box"><div class="num c-red"><?php echo $attendance['Absent']; ?></div><div class="lbl">Absent</div></div>
        <div class="stat-box"><div class="num c-yellow"><?php echo $attendance['Late']; ?></div><div class="lbl">Late</div></div>
        <div class="stat-box"><div class="num c-blue"><?php echo $attendance['Excused']; ?></div><div class="lbl">Excused</div></div>
    </div>

    <div class="section-title">Recent Records</div>
    <div class="record-list">
        <?php if (empty($recentRecords)): ?>
            <p style="text-align:center;color:#94a3b8;padding:20px;">No attendance records yet.</p>
        <?php else: ?>
            <?php foreach ($recentRecords as $rec): ?>
            <div class="record-item">
                <div>
                    <div class="date"><?php echo date('M d, Y', strtotime($rec['attendance_date'])); ?></div>
                    <div class="time"><?php echo $rec['time_marked'] ? date('h:i A', strtotime($rec['time_marked'])) : ''; ?></div>
                </div>
                <span class="badge badge-<?php echo strtolower($rec['status']); ?>"><?php echo $rec['status']; ?></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>