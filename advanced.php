<?php
// school_owner/advanced.php - Advanced Settings Hub (with Communication section)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

// Counts for badges
$teacherRequestsCount = 0;
$passwordRequestsCount = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM permission_requests WHERE school_id = ? AND status = 'pending'");
    $s->execute([$school_id]);
    $teacherRequestsCount = (int)$s->fetchColumn();
} catch (Exception $e) {}

try {
    $s = $db->prepare("SELECT COUNT(*) FROM password_reset_requests WHERE school_id = ? AND status = 'pending'");
    $s->execute([$school_id]);
    $passwordRequestsCount = (int)$s->fetchColumn();
} catch (Exception $e) {}

include_once __DIR__ . '/../includes/header.php';
?>

<style>
    .tool-section { margin-bottom: 32px; }
    .tool-section h3 {
        font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px;
        color: #94a3b8; font-weight: 700; margin-bottom: 14px;
        display: flex; align-items: center; gap: 8px;
    }
    .tool-section h3 i { color: #7c3aed; font-size: 1rem; }

    .tool-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 14px;
    }
    .tool-card {
        background: white;
        border-radius: 14px;
        padding: 18px;
        border: 1px solid #f1f5f9;
        text-decoration: none;
        color: #0f172a;
        transition: all 0.15s;
        display: flex;
        align-items: flex-start;
        gap: 14px;
        position: relative;
    }
    .tool-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(124,58,237,0.08);
        border-color: #c4b5fd;
    }
    .tool-card .tool-icon {
        width: 44px; height: 44px;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.15rem;
        flex-shrink: 0;
    }
    .tool-card .tool-info { flex: 1; min-width: 0; }
    .tool-card .tool-title {
        font-weight: 700; font-size: 0.9rem; color: #0f172a;
        margin-bottom: 2px;
    }
    .tool-card .tool-desc {
        font-size: 0.75rem; color: #64748b; line-height: 1.4;
    }
    .tool-card .badge-count {
        position: absolute;
        top: 12px; right: 12px;
        background: #dc2626; color: white;
        font-size: 0.65rem; font-weight: 700;
        padding: 3px 8px; border-radius: 20px;
        min-width: 20px; text-align: center;
    }

    .back-btn {
        display: inline-flex; align-items: center; gap: 8px;
        background: white; color: #334155; padding: 10px 18px;
        border-radius: 10px; text-decoration: none; font-weight: 600;
        font-size: 0.85rem; border: 1px solid #e2e8f0;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700; color:#0f172a;">⚙️ Advanced Settings</h1>
        <p style="color:#64748b; margin: 0;">All the tools that aren't used every day — organized by category.</p>
    </div>
    <a href="dashboard.php" class="back-btn">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</div>

<!-- ============================================ -->
<!-- REPORTS & RECORDS -->
<!-- ============================================ -->
<div class="tool-section">
    <h3><i class="fas fa-chart-line"></i> Reports & Records</h3>
    <div class="tool-grid">
        <a href="student_records.php" class="tool-card">
            <div class="tool-icon" style="background:#dbeafe;color:#2563eb;"><i class="fas fa-history"></i></div>
            <div class="tool-info">
                <div class="tool-title">Student Records</div>
                <div class="tool-desc">View full history — including archived years</div>
            </div>
        </a>

        <a href="old_records_search.php" class="tool-card">
            <div class="tool-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-chalkboard-user"></i></div>
            <div class="tool-info">
                <div class="tool-title">Teacher Records</div>
                <div class="tool-desc">Search staff, view assignments and history</div>
            </div>
        </a>

        <a href="activity_log.php" class="tool-card">
            <div class="tool-icon" style="background:#f1f5f9;color:#475569;"><i class="fas fa-clock-rotate-left"></i></div>
            <div class="tool-info">
                <div class="tool-title">Activity Log</div>
                <div class="tool-desc">Every action taken in the system</div>
            </div>
        </a>
    </div>
</div>

<!-- ============================================ -->
<!-- COMMUNICATION -->
<!-- ============================================ -->
<div class="tool-section">
    <h3><i class="fas fa-bullhorn"></i> Communication</h3>
    <div class="tool-grid">
        <a href="announcements.php" class="tool-card">
            <div class="tool-icon" style="background:#fce7f3;color:#db2777;"><i class="fas fa-bullhorn"></i></div>
            <div class="tool-info">
                <div class="tool-title">Announcements</div>
                <div class="tool-desc">Send messages to parents</div>
            </div>
        </a>
    </div>
</div>

<!-- ============================================ -->
<!-- ACADEMICS -->
<!-- ============================================ -->
<div class="tool-section">
    <h3><i class="fas fa-graduation-cap"></i> Academics</h3>
    <div class="tool-grid">
        <a href="approve_scores.php" class="tool-card">
            <div class="tool-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-check-double"></i></div>
            <div class="tool-info">
                <div class="tool-title">Approve Scores</div>
                <div class="tool-desc">Review and approve teacher-submitted scores</div>
            </div>
        </a>

        <a href="promote_students.php" class="tool-card">
            <div class="tool-icon" style="background:#dcfce7;color:#16a34a;"><i class="fas fa-arrow-up"></i></div>
            <div class="tool-info">
                <div class="tool-title">Promote Students</div>
                <div class="tool-desc">End of session — move students to next class</div>
            </div>
        </a>

        <a href="archive_term.php" class="tool-card">
            <div class="tool-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-archive"></i></div>
            <div class="tool-info">
                <div class="tool-title">Archive Term</div>
                <div class="tool-desc">Save a snapshot and clear old term data</div>
            </div>
        </a>
    </div>
</div>

<!-- ============================================ -->
<!-- PEOPLE -->
<!-- ============================================ -->
<div class="tool-section">
    <h3><i class="fas fa-users"></i> People</h3>
    <div class="tool-grid">
        <a href="permission_requests.php" class="tool-card">
            <?php if ($teacherRequestsCount > 0): ?>
                <div class="badge-count"><?php echo $teacherRequestsCount; ?></div>
            <?php endif; ?>
            <div class="tool-icon" style="background:#dbeafe;color:#2563eb;"><i class="fas fa-user-check"></i></div>
            <div class="tool-info">
                <div class="tool-title">Teacher Requests</div>
                <div class="tool-desc">Approve access requests from teachers</div>
            </div>
        </a>

        <a href="password_requests.php" class="tool-card">
            <?php if ($passwordRequestsCount > 0): ?>
                <div class="badge-count"><?php echo $passwordRequestsCount; ?></div>
            <?php endif; ?>
            <div class="tool-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-key"></i></div>
            <div class="tool-info">
                <div class="tool-title">Password Requests</div>
                <div class="tool-desc">Help parents reset their login</div>
            </div>
        </a>

        <a href="bulk_import_students.php" class="tool-card">
            <div class="tool-icon" style="background:#dbeafe;color:#2563eb;"><i class="fas fa-user-plus"></i></div>
            <div class="tool-info">
                <div class="tool-title">Bulk Import Students</div>
                <div class="tool-desc">Add many students from a CSV file</div>
            </div>
        </a>

        <a href="bulk_import_teachers.php" class="tool-card">
            <div class="tool-icon" style="background:#dbeafe;color:#2563eb;"><i class="fas fa-chalkboard-user"></i></div>
            <div class="tool-info">
                <div class="tool-title">Bulk Import Teachers</div>
                <div class="tool-desc">Add many teachers from a CSV file</div>
            </div>
        </a>
    </div>
</div>

<!-- ============================================ -->
<!-- ACADEMIC SETUP -->
<!-- ============================================ -->
<div class="tool-section">
    <h3><i class="fas fa-book"></i> Academic Setup</h3>
    <div class="tool-grid">
        <a href="manage_classes.php" class="tool-card">
            <div class="tool-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-layer-group"></i></div>
            <div class="tool-info">
                <div class="tool-title">Manage Classes</div>
                <div class="tool-desc">Add, edit, or archive classes</div>
            </div>
        </a>

        <a href="manage_subjects.php" class="tool-card">
            <div class="tool-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-book"></i></div>
            <div class="tool-info">
                <div class="tool-title">Manage Subjects</div>
                <div class="tool-desc">Add, edit, or archive subjects</div>
            </div>
        </a>
    </div>
</div>

<!-- ============================================ -->
<!-- FINANCE -->
<!-- ============================================ -->
<div class="tool-section">
    <h3><i class="fas fa-coins"></i> Finance</h3>
    <div class="tool-grid">
        <a href="fee_lock.php" class="tool-card">
            <div class="tool-icon" style="background:#ffedd5;color:#ea580c;"><i class="fas fa-lock"></i></div>
            <div class="tool-info">
                <div class="tool-title">Fee Lock</div>
                <div class="tool-desc">Control who can download report cards</div>
            </div>
        </a>
    </div>
</div>

<!-- ============================================ -->
<!-- SYSTEM -->
<!-- ============================================ -->
<div class="tool-section">
    <h3><i class="fas fa-cog"></i> System</h3>
    <div class="tool-grid">
        <a href="school_settings.php" class="tool-card">
            <div class="tool-icon" style="background:#f1f5f9;color:#475569;"><i class="fas fa-sliders-h"></i></div>
            <div class="tool-info">
                <div class="tool-title">School Settings</div>
                <div class="tool-desc">Name, colors, term, permissions</div>
            </div>
        </a>

        <a href="security_cctv.php" class="tool-card">
            <div class="tool-icon" style="background:#f1f5f9;color:#475569;"><i class="fas fa-video"></i></div>
            <div class="tool-info">
                <div class="tool-title">Security Monitor</div>
                <div class="tool-desc">Login attempts and security events</div>
            </div>
        </a>
    </div>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>