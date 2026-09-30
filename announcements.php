<?php
// parent/announcements.php - View announcements from the school
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

// Get children
$children = [];
$stmt = $db->prepare("
    SELECT s.id, s.first_name, s.last_name, s.photo_path, c.name AS class_name, c.level AS class_level
    FROM parent_students ps
    JOIN students s ON ps.student_id = s.id
    JOIN classes c ON s.class_id = c.id
    WHERE ps.parent_id = ? AND s.school_id = ? AND s.status = 'Active'
    ORDER BY s.first_name
");
$stmt->execute([$parent_id, $school_id]);
$children = $stmt->fetchAll();

// Fallback to primary student
if (empty($children)) {
    $parentStmt = $db->prepare("SELECT student_id FROM parent_portal WHERE id = ? AND school_id = ?");
    $parentStmt->execute([$parent_id, $school_id]);
    $parent = $parentStmt->fetch();
    if ($parent) {
        $stmt = $db->prepare("
            SELECT s.id, s.first_name, s.last_name, s.photo_path, c.name AS class_name, c.level AS class_level
            FROM students s
            JOIN classes c ON s.class_id = c.id
            WHERE s.id = ? AND s.school_id = ? AND s.status = 'Active'
        ");
        $stmt->execute([(int)$parent['student_id'], $school_id]);
        $children = $stmt->fetchAll();
    }
}

// Determine class levels this parent should see
$levels = ['All'];
foreach ($children as $c) {
    if (!empty($c['class_level'])) {
        $levels[] = $c['class_level'];
    }
}
$levels = array_unique($levels);

// Fetch announcements
$placeholders = implode(',', array_fill(0, count($levels), '?'));
$params = array_merge([$school_id], $levels);

$annStmt = $db->prepare("
    SELECT a.*, u.full_name AS author_name
    FROM announcements a
    LEFT JOIN users u ON a.created_by = u.id
    WHERE a.school_id = ?
      AND a.is_published = 1
      AND a.class_level IN ($placeholders)
      AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
    ORDER BY a.created_at DESC
    LIMIT 50
");
$annStmt->execute($params);
$announcements = $annStmt->fetchAll();

$schoolStmt = $db->prepare("SELECT school_name FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements | Parent Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f1f5f9; min-height: 100vh; }
        .container { max-width: 450px; margin: 0 auto; padding: 0 16px 30px; }
        .topbar { display: flex; align-items: center; gap: 12px; padding: 20px 16px 10px; }
        .topbar .back { background: white; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #0f172a; text-decoration: none; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .topbar h3 { font-weight: 800; color: #0f172a; font-size: 1.1rem; }

        .ann-card {
            background: white; border-radius: 16px; padding: 20px;
            margin-top: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border-left: 4px solid #7c3aed;
        }
        .ann-title { font-weight: 700; color: #0f172a; font-size: 1rem; margin-bottom: 4px; }
        .ann-meta { font-size: 0.72rem; color: #94a3b8; margin-bottom: 10px; }
        .ann-body { color: #475569; font-size: 0.85rem; line-height: 1.55; white-space: pre-wrap; }
        .ann-tag {
            display: inline-block; padding: 3px 10px; border-radius: 20px;
            font-size: 0.68rem; font-weight: 700; margin-top: 10px;
            background: #ede9fe; color: #6d28d9;
        }
        .empty-state {
            text-align: center; padding: 60px 20px; color: #94a3b8;
        }
        .empty-state i { font-size: 3rem; opacity: 0.5; margin-bottom: 12px; display: block; }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <a href="index.php" class="back"><i class="fas fa-arrow-left"></i></a>
        <h3>📢 Announcements</h3>
    </div>

    <?php if (empty($announcements)): ?>
        <div class="empty-state">
            <i class="fas fa-bullhorn"></i>
            <strong>No announcements right now</strong>
            <p style="margin-top: 6px; font-size: 0.85rem;">You'll see school updates here.</p>
        </div>
    <?php else: ?>
        <?php foreach ($announcements as $a): ?>
            <div class="ann-card">
                <div class="ann-title"><?php echo htmlspecialchars($a['title']); ?></div>
                <div class="ann-meta">
                    <?php echo date('M d, Y g:i A', strtotime($a['created_at'])); ?>
                    <?php if (!empty($a['author_name'])): ?>
                        • <?php echo htmlspecialchars($a['author_name']); ?>
                    <?php endif; ?>
                </div>
                <div class="ann-body"><?php echo nl2br(htmlspecialchars($a['message'])); ?></div>
                <span class="ann-tag"><?php echo htmlspecialchars($a['class_level']); ?></span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>