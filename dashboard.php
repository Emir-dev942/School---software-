<?php
// principal/dashboard.php - Principal Dashboard (PDO version with correct links)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Principal']);

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$principal_name = $_SESSION['user_name'] ?? 'Principal';

// Fetch school name
$stmt = $db->prepare("SELECT school_name FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school_name = $stmt->fetchColumn() ?: 'Your School';

// Basic stats
$student_count = (int)$db->query("SELECT COUNT(*) FROM students WHERE school_id = $school_id AND status = 'Active'")->fetchColumn();
$teacher_count = (int)$db->query("SELECT COUNT(*) FROM users WHERE school_id = $school_id AND role = 'Teacher' AND status = 'active'")->fetchColumn();
$class_count = (int)$db->query("SELECT COUNT(*) FROM classes WHERE school_id = $school_id AND status = 'active'")->fetchColumn();

include_once __DIR__ . '/../includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">👋 Welcome, <?php echo htmlspecialchars($principal_name); ?>!</h1>
        <p style="color: #64748b; margin: 0;"><?php echo htmlspecialchars($school_name); ?> - Principal Dashboard</p>
    </div>
    <a href="<?php echo BASE_URL; ?>logout.php" class="btn btn-outline-danger" style="border-radius: 10px; padding: 8px 16px;">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</div>

<!-- Stats Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 30px;">
    <div style="background: #ffffff; border-radius: 12px; padding: 16px 20px; border: 1px solid #e2e8f0;">
        <div style="font-size: 12px; color: #94a3b8; text-transform: uppercase;">Students</div>
        <div style="font-size: 22px; font-weight: 700; color: #0f172a;"><?php echo number_format($student_count); ?></div>
    </div>
    <div style="background: #ffffff; border-radius: 12px; padding: 16px 20px; border: 1px solid #e2e8f0;">
        <div style="font-size: 12px; color: #94a3b8; text-transform: uppercase;">Teachers</div>
        <div style="font-size: 22px; font-weight: 700; color: #0f172a;"><?php echo number_format($teacher_count); ?></div>
    </div>
    <div style="background: #ffffff; border-radius: 12px; padding: 16px 20px; border: 1px solid #e2e8f0;">
        <div style="font-size: 12px; color: #94a3b8; text-transform: uppercase;">Classes</div>
        <div style="font-size: 22px; font-weight: 700; color: #0f172a;"><?php echo number_format($class_count); ?></div>
    </div>
</div>

<!-- Quick Links (pointing to school_owner files that Principal can access) -->
<h5 style="font-weight: 600; color: #0f172a; margin-bottom: 16px;">📌 Quick Links</h5>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
    <a href="<?php echo BASE_URL; ?>school_owner/activity_log.php" style="background: #fff; padding: 20px; border-radius: 16px; text-align: center; text-decoration: none; color: #0f172a; border: 1px solid #eef2ff;">
        <div style="font-size: 28px; margin-bottom: 8px;">📊</div>
        <div style="font-weight: 600; font-size: 14px;">Activity History</div>
    </a>
    <a href="<?php echo BASE_URL; ?>school_owner/generate_report_cards.php" style="background: #fff; padding: 20px; border-radius: 16px; text-align: center; text-decoration: none; color: #0f172a; border: 1px solid #eef2ff;">
        <div style="font-size: 28px; margin-bottom: 8px;">📄</div>
        <div style="font-weight: 600; font-size: 14px;">Generate Report Cards</div>
    </a>
    <a href="<?php echo BASE_URL; ?>school_owner/fees.php" style="background: #fff; padding: 20px; border-radius: 16px; text-align: center; text-decoration: none; color: #0f172a; border: 1px solid #eef2ff;">
        <div style="font-size: 28px; margin-bottom: 8px;">💰</div>
        <div style="font-weight: 600; font-size: 14px;">Fee Overview</div>
    </a>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>