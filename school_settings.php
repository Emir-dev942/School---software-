<?php
// school_owner/school_settings.php - Complete School Control Center (v3 fixed hidden submit marker)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id = (int)$_SESSION['user_id'];

$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch();
if (!$school) {
    $_SESSION['error'] = "School not found.";
    redirect('school_owner/dashboard.php');
}

// ---------- HANDLE SETTINGS UPDATE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $school_name   = sanitize($_POST['school_name'] ?? '');
    $slogan        = sanitize($_POST['slogan'] ?? '');
    $phone         = sanitize($_POST['phone'] ?? '');
    $email         = sanitize($_POST['email'] ?? '');
    $address       = sanitize($_POST['address'] ?? '');
    $website       = sanitize($_POST['website'] ?? '');
    $primary_color = sanitize($_POST['primary_color'] ?? '#7c3aed');
    $secondary_color = sanitize($_POST['secondary_color'] ?? '#4f46e5');
    $current_term  = sanitize($_POST['current_term'] ?? 'Term 1');
    $current_session = sanitize($_POST['current_session'] ?? '2025/2026');
    $next_term_date = sanitize($_POST['next_term_date'] ?? '');

    $enable_parent_portal = isset($_POST['enable_parent_portal']) ? 1 : 0;
    $enable_sms           = isset($_POST['enable_sms']) ? 1 : 0;
    $enable_exams         = isset($_POST['enable_exams']) ? 1 : 0;
    $enable_question_bank = isset($_POST['enable_question_bank']) ? 1 : 0;
    $enable_attendance    = isset($_POST['enable_attendance']) ? 1 : 0;
    $enable_fees          = isset($_POST['enable_fees']) ? 1 : 0;

    $principal_permissions = json_encode([
        'add_students'      => isset($_POST['principal_add_students']) ? 1 : 0,
        'edit_students'     => isset($_POST['principal_edit_students']) ? 1 : 0,
        'delete_students'   => isset($_POST['principal_delete_students']) ? 1 : 0,
        'add_teachers'      => isset($_POST['principal_add_teachers']) ? 1 : 0,
        'edit_teachers'     => isset($_POST['principal_edit_teachers']) ? 1 : 0,
        'manage_fees'       => isset($_POST['principal_manage_fees']) ? 1 : 0,
        'override_fee_lock' => isset($_POST['principal_override_fee_lock']) ? 1 : 0,
        'record_payments'   => isset($_POST['principal_record_payments']) ? 1 : 0,
        'reverse_payments'  => isset($_POST['principal_reverse_payments']) ? 1 : 0,
        'set_fee_structures'=> isset($_POST['principal_set_fee_structures']) ? 1 : 0,
        'generate_invoices' => isset($_POST['principal_generate_invoices']) ? 1 : 0,
    ]);

    if (empty($school_name)) {
        $_SESSION['error'] = "School name is required.";
        redirect('school_owner/school_settings.php');
    }

    $stmt = $db->prepare("UPDATE schools SET 
        school_name = ?, slogan = ?, phone = ?, email = ?, address = ?, website = ?,
        primary_color = ?, secondary_color = ?,
        current_term = ?, current_session = ?, next_term_date = ?,
        enable_parent_portal = ?, enable_sms = ?, enable_exams = ?,
        enable_question_bank = ?, enable_attendance = ?, enable_fees = ?,
        principal_permissions = ?
        WHERE id = ?");
    $stmt->execute([
        $school_name, $slogan, $phone, $email, $address, $website,
        $primary_color, $secondary_color,
        $current_term, $current_session, $next_term_date,
        $enable_parent_portal, $enable_sms, $enable_exams,
        $enable_question_bank, $enable_attendance, $enable_fees,
        $principal_permissions,
        $school_id
    ]);

    logActivity('UPDATE_SETTINGS', "Updated school settings. Term: $current_term, Session: $current_session", $school_id, $user_id);
    $_SESSION['success'] = "Settings updated successfully!";
    redirect('school_owner/school_settings.php');
}

// ---------- HANDLE LOGO UPLOAD ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = $_FILES['logo']['type'];
    $file_size = $_FILES['logo']['size'];
    $file_tmp = $_FILES['logo']['tmp_name'];

    if (!in_array($file_type, $allowed)) {
        $_SESSION['error'] = "Only JPG, PNG, GIF, and WEBP images are allowed.";
        redirect('school_owner/school_settings.php');
    }
    if ($file_size > 2 * 1024 * 1024) {
        $_SESSION['error'] = "Logo must be less than 2MB.";
        redirect('school_owner/school_settings.php');
    }

    $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
    $filename = 'school_' . $school_id . '_' . time() . '.' . $ext;
    $upload_dir = __DIR__ . '/../uploads/school_logos/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    $upload_path = $upload_dir . $filename;

    if (move_uploaded_file($file_tmp, $upload_path)) {
        if ($school['logo_path'] && $school['logo_path'] !== 'default_logo.png') {
            $old_path = $upload_dir . $school['logo_path'];
            if (file_exists($old_path)) unlink($old_path);
        }
        $stmt = $db->prepare("UPDATE schools SET logo_path = ? WHERE id = ?");
        $stmt->execute([$filename, $school_id]);
        logActivity('UPDATE_LOGO', "Updated school logo.", $school_id, $user_id);
        $_SESSION['success'] = "Logo uploaded successfully!";
    } else {
        $_SESSION['error'] = "Failed to upload logo. Please check folder permissions.";
    }
    redirect('school_owner/school_settings.php');
}

// ---------- HANDLE DEACTIVATE SCHOOL ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_school_confirm'])) {
    $stmt = $db->prepare("UPDATE schools SET status = 'inactive' WHERE id = ?");
    if ($stmt->execute([$school_id])) {
        logActivity('DEACTIVATE_SCHOOL', "Deactivated school.", $school_id, $user_id);
        session_destroy();
        header('Location: ' . BASE_URL . 'login.php?msg=school_deactivated');
        exit;
    } else {
        $_SESSION['error'] = "Failed to deactivate school.";
        redirect('school_owner/school_settings.php');
    }
}

// Parse principal permissions
$principal_perms = json_decode($school['principal_permissions'] ?? '{}', true);
$default_perms = [
    'add_students' => 0, 'edit_students' => 0, 'delete_students' => 0,
    'add_teachers' => 0, 'edit_teachers' => 0, 'manage_fees' => 0,
    'override_fee_lock' => 0, 'record_payments' => 1, 'reverse_payments' => 0,
    'set_fee_structures' => 0, 'generate_invoices' => 1,
];
$principal_perms = array_merge($default_perms, $principal_perms);

// ---------- CHECK IF CURRENT TERM HAS UNARCHIVED DATA ----------
$dataCheck = $db->prepare("SELECT 
    (SELECT COUNT(*) FROM exam_scores WHERE school_id = ? AND term_name = ? AND session_year = ?) AS scores,
    (SELECT COUNT(*) FROM attendance_log WHERE school_id = ? AND term_name = ? AND session_year = ?) AS attendance,
    (SELECT COUNT(*) FROM invoices WHERE school_id = ? AND term_name = ? AND session_year = ?) AS invoices");
$dataCheck->execute([$school_id, $school['current_term'], $school['current_session'], $school_id, $school['current_term'], $school['current_session'], $school_id, $school['current_term'], $school['current_session']]);
$dataCounts = $dataCheck->fetch();

include_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:12px 18px;border-radius:10px;margin-bottom:16px;">✅ ' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 18px;border-radius:10px;margin-bottom:16px;">❌ ' . $_SESSION['error'] . '</div>';
    unset($_SESSION['error']);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">⚙️ School Settings</h1>
        <p style="color: #64748b; margin: 0;">Control school identity, features, and academic settings.</p>
    </div>
    <a href="dashboard.php" class="btn btn-outline-secondary" style="border-radius: 10px; padding: 8px 18px;">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<!-- Academic Info Banner -->
<div style="background:#ede9fe;border:1px solid #c4b5fd;border-radius:12px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:14px;">
    <i class="fas fa-calendar-alt" style="color:#7c3aed;font-size:24px;"></i>
    <div>
        <strong style="color:#5b21b6;">Current Term: <?php echo $school['current_term']; ?> - <?php echo $school['current_session']; ?></strong>
        <?php if ($dataCounts['scores'] > 0 || $dataCounts['attendance'] > 0 || $dataCounts['invoices'] > 0): ?>
            <div style="font-size:13px;color:#6d28d9;margin-top:2px;">
                This term has: <?php echo $dataCounts['scores']; ?> scores, <?php echo $dataCounts['attendance']; ?> attendance records, <?php echo $dataCounts['invoices']; ?> invoices
            </div>
        <?php else: ?>
            <div style="font-size:13px;color:#6d28d9;margin-top:2px;">No data recorded for this term yet.</div>
        <?php endif; ?>
    </div>
</div>

<ul class="nav nav-tabs" style="border-bottom: 2px solid #f1f5f9; margin-bottom: 28px;">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#identity" style="font-weight:600;">🏫 Identity</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#branding" style="font-weight:600;">🎨 Branding</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#academic" style="font-weight:600;">📅 Academic</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#features" style="font-weight:600;">🔘 Features</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#permissions" style="font-weight:600;">👔 Principal</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#danger" style="font-weight:600;color:#dc2626;">⚠️ Danger</button></li>
</ul>

<form method="POST" action="" enctype="multipart/form-data" id="settingsForm">
    <?php echo csrfField(); ?>
    <!-- Always-present marker so JS .submit() works -->
    <input type="hidden" name="update_settings" value="1">
    <div class="tab-content">

        <!-- IDENTITY -->
        <div class="tab-pane fade show active" id="identity">
            <div style="background:#fff;border-radius:16px;padding:28px;border:1px solid #f1f5f9;margin-bottom:24px;">
                <h5 style="font-weight:600;color:#0f172a;margin-bottom:20px;">🏫 School Identity</h5>
                <div class="row">
                    <div class="col-md-6"><div class="mb-3"><label class="form-label fw-semibold">School Name *</label><input type="text" name="school_name" class="form-control" value="<?php echo htmlspecialchars($school['school_name']); ?>" required></div></div>
                    <div class="col-md-6"><div class="mb-3"><label class="form-label fw-semibold">Slogan</label><input type="text" name="slogan" class="form-control" value="<?php echo htmlspecialchars($school['slogan'] ?? ''); ?>"></div></div>
                </div>
                <div class="row">
                    <div class="col-md-4"><div class="mb-3"><label class="form-label fw-semibold">Phone</label><input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($school['phone'] ?? ''); ?>"></div></div>
                    <div class="col-md-4"><div class="mb-3"><label class="form-label fw-semibold">Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($school['email'] ?? ''); ?>"></div></div>
                    <div class="col-md-4"><div class="mb-3"><label class="form-label fw-semibold">Website</label><input type="text" name="website" class="form-control" value="<?php echo htmlspecialchars($school['website'] ?? ''); ?>"></div></div>
                </div>
                <div class="mb-3"><label class="form-label fw-semibold">Address</label><textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($school['address'] ?? ''); ?></textarea></div>
            </div>
            <div style="background:#fff;border-radius:16px;padding:28px;border:1px solid #f1f5f9;">
                <h5 style="font-weight:600;color:#0f172a;margin-bottom:16px;">🖼️ School Logo</h5>
                <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
                    <img src="<?php echo $school['logo_path'] && $school['logo_path'] !== 'default_logo.png' ? BASE_URL . 'uploads/school_logos/' . $school['logo_path'] : BASE_URL . 'assets/images/default_logo.png'; ?>" style="width:120px;height:120px;object-fit:contain;border:2px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
                    <div style="flex:1;"><input type="file" name="logo" class="form-control" accept="image/*"><small class="text-muted">Max 2MB. JPG, PNG, GIF, WEBP</small></div>
                </div>
            </div>
        </div>

        <!-- BRANDING -->
        <div class="tab-pane fade" id="branding">
            <div style="background:#fff;border-radius:16px;padding:28px;border:1px solid #f1f5f9;">
                <h5 style="font-weight:600;color:#0f172a;margin-bottom:20px;">🎨 Branding Colors</h5>
                <div class="row">
                    <div class="col-md-6"><div class="mb-3"><label class="form-label fw-semibold">Primary Color</label><div style="display:flex;gap:12px;align-items:center;"><input type="color" name="primary_color" value="<?php echo $school['primary_color'] ?? '#7c3aed'; ?>" style="width:50px;height:50px;border:2px solid #e2e8f0;border-radius:10px;"><input type="text" class="form-control" value="<?php echo $school['primary_color'] ?? '#7c3aed'; ?>" readonly style="font-family:monospace;"></div></div></div>
                    <div class="col-md-6"><div class="mb-3"><label class="form-label fw-semibold">Secondary Color</label><div style="display:flex;gap:12px;align-items:center;"><input type="color" name="secondary_color" value="<?php echo $school['secondary_color'] ?? '#4f46e5'; ?>" style="width:50px;height:50px;border:2px solid #e2e8f0;border-radius:10px;"><input type="text" class="form-control" value="<?php echo $school['secondary_color'] ?? '#4f46e5'; ?>" readonly style="font-family:monospace;"></div></div></div>
                </div>
            </div>
        </div>

        <!-- ACADEMIC -->
        <div class="tab-pane fade" id="academic">
            <div style="background:#fff;border-radius:16px;padding:28px;border:1px solid #f1f5f9;">
                <h5 style="font-weight:600;color:#0f172a;margin-bottom:20px;">📅 Academic Settings</h5>
                
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
                    <strong style="color:#92400e;"><i class="fas fa-exclamation-triangle"></i> Important:</strong>
                    <span style="color:#78350f;font-size:14px;">Changing the term here does NOT move or archive any data. Use <a href="archive_term.php" style="color:#7c3aed;font-weight:600;">Archive Term Data</a> to clear old term data first.</span>
                </div>
                
                <div class="row">
                    <div class="col-md-6"><div class="mb-3">
                        <label class="form-label fw-semibold">Current Term *</label>
                        <select name="current_term" id="termSelect" class="form-control" required>
                            <option value="Term 1" <?php echo ($school['current_term'] === 'Term 1') ? 'selected' : ''; ?>>Term 1</option>
                            <option value="Term 2" <?php echo ($school['current_term'] === 'Term 2') ? 'selected' : ''; ?>>Term 2</option>
                            <option value="Term 3" <?php echo ($school['current_term'] === 'Term 3') ? 'selected' : ''; ?>>Term 3</option>
                        </select>
                        <input type="hidden" id="originalTerm" value="<?php echo htmlspecialchars($school['current_term']); ?>">
                    </div></div>
                    <div class="col-md-6"><div class="mb-3">
                        <label class="form-label fw-semibold">Current Session *</label>
                        <select name="current_session" id="sessionSelect" class="form-control" required>
                            <?php
                            $current_year = date('Y');
                            for ($i = -1; $i <= 2; $i++) {
                                $year = $current_year + $i;
                                $session = $year . '/' . ($year + 1);
                                $selected = ($school['current_session'] === $session) ? 'selected' : '';
                                echo "<option value=\"$session\" $selected>$session</option>";
                            }
                            ?>
                        </select>
                        <input type="hidden" id="originalSession" value="<?php echo htmlspecialchars($school['current_session']); ?>">
                    </div></div>
                </div>
                <div class="mb-3"><label class="form-label fw-semibold">Next Term Begins</label><input type="date" name="next_term_date" class="form-control" value="<?php echo htmlspecialchars($school['next_term_date'] ?? ''); ?>"></div>
            </div>
        </div>

        <!-- FEATURES -->
        <div class="tab-pane fade" id="features">
            <div style="background:#fff;border-radius:16px;padding:28px;border:1px solid #f1f5f9;">
                <h5 style="font-weight:600;color:#0f172a;margin-bottom:20px;">🔘 System Features</h5>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div style="background:#f8fafc;border-radius:12px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><div><span style="font-weight:500;">👪 Parent Portal</span><div style="font-size:12px;color:#94a3b8;">Parents login and view results</div></div><div class="form-check form-switch" style="margin:0;"><input class="form-check-input" type="checkbox" name="enable_parent_portal" <?php echo ($school['enable_parent_portal'] ?? 1) ? 'checked' : ''; ?>></div></div>
                    <div style="background:#f8fafc;border-radius:12px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><div><span style="font-weight:500;">📱 SMS Alerts</span><div style="font-size:12px;color:#94a3b8;">Send SMS to parents</div></div><div class="form-check form-switch" style="margin:0;"><input class="form-check-input" type="checkbox" name="enable_sms" <?php echo ($school['enable_sms'] ?? 1) ? 'checked' : ''; ?>></div></div>
                    <div style="background:#f8fafc;border-radius:12px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><div><span style="font-weight:500;">📝 Exam Generator</span><div style="font-size:12px;color:#94a3b8;">Generate exam papers</div></div><div class="form-check form-switch" style="margin:0;"><input class="form-check-input" type="checkbox" name="enable_exams" <?php echo ($school['enable_exams'] ?? 1) ? 'checked' : ''; ?>></div></div>
                    <div style="background:#f8fafc;border-radius:12px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><div><span style="font-weight:500;">📚 Question Bank</span><div style="font-size:12px;color:#94a3b8;">Question bank access</div></div><div class="form-check form-switch" style="margin:0;"><input class="form-check-input" type="checkbox" name="enable_question_bank" <?php echo ($school['enable_question_bank'] ?? 1) ? 'checked' : ''; ?>></div></div>
                    <div style="background:#f8fafc;border-radius:12px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><div><span style="font-weight:500;">✅ Attendance</span><div style="font-size:12px;color:#94a3b8;">Mark attendance</div></div><div class="form-check form-switch" style="margin:0;"><input class="form-check-input" type="checkbox" name="enable_attendance" <?php echo ($school['enable_attendance'] ?? 1) ? 'checked' : ''; ?>></div></div>
                    <div style="background:#f8fafc;border-radius:12px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><div><span style="font-weight:500;">💰 Fee Management</span><div style="font-size:12px;color:#94a3b8;">Track fees and invoices</div></div><div class="form-check form-switch" style="margin:0;"><input class="form-check-input" type="checkbox" name="enable_fees" <?php echo ($school['enable_fees'] ?? 1) ? 'checked' : ''; ?>></div></div>
                </div>
            </div>
        </div>

        <!-- PRINCIPAL PERMISSIONS -->
        <div class="tab-pane fade" id="permissions">
            <div style="background:#fff;border-radius:16px;padding:28px;border:1px solid #f1f5f9;">
                <h5 style="font-weight:600;color:#0f172a;margin-bottom:8px;">👔 Principal Permissions</h5>
                <p style="color:#64748b;margin-bottom:20px;">All permissions are <strong style="color:#dc2626;">OFF</strong> by default.</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div style="background:#f8fafc;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><span style="font-size:14px;">Add Students</span><input type="checkbox" name="principal_add_students" <?php echo ($principal_perms['add_students'] ?? 0) ? 'checked' : ''; ?> style="width:20px;height:20px;"></div>
                    <div style="background:#f8fafc;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><span style="font-size:14px;">Edit Students</span><input type="checkbox" name="principal_edit_students" <?php echo ($principal_perms['edit_students'] ?? 0) ? 'checked' : ''; ?> style="width:20px;height:20px;"></div>
                    <div style="background:#f8fafc;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><span style="font-size:14px;">Delete Students</span><input type="checkbox" name="principal_delete_students" <?php echo ($principal_perms['delete_students'] ?? 0) ? 'checked' : ''; ?> style="width:20px;height:20px;"></div>
                    <div style="background:#f8fafc;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><span style="font-size:14px;">Add Teachers</span><input type="checkbox" name="principal_add_teachers" <?php echo ($principal_perms['add_teachers'] ?? 0) ? 'checked' : ''; ?> style="width:20px;height:20px;"></div>
                    <div style="background:#f8fafc;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><span style="font-size:14px;">Edit Teachers</span><input type="checkbox" name="principal_edit_teachers" <?php echo ($principal_perms['edit_teachers'] ?? 0) ? 'checked' : ''; ?> style="width:20px;height:20px;"></div>
                    <div style="background:#f8fafc;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><span style="font-size:14px;">Manage Fees</span><input type="checkbox" name="principal_manage_fees" <?php echo ($principal_perms['manage_fees'] ?? 0) ? 'checked' : ''; ?> style="width:20px;height:20px;"></div>
                    <div style="background:#f8fafc;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><span style="font-size:14px;">Record Payments</span><input type="checkbox" name="principal_record_payments" <?php echo ($principal_perms['record_payments'] ?? 1) ? 'checked' : ''; ?> style="width:20px;height:20px;"></div>
                    <div style="background:#f8fafc;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><span style="font-size:14px;">Reverse Payments</span><input type="checkbox" name="principal_reverse_payments" <?php echo ($principal_perms['reverse_payments'] ?? 0) ? 'checked' : ''; ?> style="width:20px;height:20px;"></div>
                    <div style="background:#f8fafc;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><span style="font-size:14px;">Set Fee Structures</span><input type="checkbox" name="principal_set_fee_structures" <?php echo ($principal_perms['set_fee_structures'] ?? 0) ? 'checked' : ''; ?> style="width:20px;height:20px;"></div>
                    <div style="background:#f8fafc;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;"><span style="font-size:14px;">Generate Invoices</span><input type="checkbox" name="principal_generate_invoices" <?php echo ($principal_perms['generate_invoices'] ?? 1) ? 'checked' : ''; ?> style="width:20px;height:20px;"></div>
                    <div style="background:#f8fafc;border-radius:10px;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;border:1px solid #e2e8f0;grid-column:span 2;"><span style="font-size:14px;">Override Fee Lock</span><input type="checkbox" name="principal_override_fee_lock" <?php echo ($principal_perms['override_fee_lock'] ?? 0) ? 'checked' : ''; ?> style="width:20px;height:20px;"></div>
                </div>
            </div>
        </div>

        <!-- DANGER -->
        <div class="tab-pane fade" id="danger">
            <div style="background:#fff;border-radius:16px;padding:28px;border:2px solid #fecaca;">
                <h5 style="font-weight:700;color:#dc2626;margin-bottom:8px;">⚠️ Danger Zone</h5>
                <p style="color:#64748b;margin-bottom:24px;">These actions are <strong>irreversible</strong>.</p>
                
                <div style="background:#fef2f2;border-radius:12px;padding:20px;border:1px solid #fecaca;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                        <div>
                            <h6 style="font-weight:600;color:#0f172a;margin-bottom:4px;">📦 Archive Term Data</h6>
                            <p style="font-size:14px;color:#475569;margin:0;">Permanently archive and optionally clear term data.</p>
                        </div>
                        <a href="archive_term.php" class="btn btn-warning" style="border-radius:10px;padding:10px 24px;font-weight:600;">
                            <i class="fas fa-archive"></i> Go to Archive Term
                        </a>
                    </div>
                </div>
                
                <div style="background:#fef2f2;border-radius:12px;padding:20px;border:1px solid #fecaca;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                        <div>
                            <h6 style="font-weight:600;color:#dc2626;margin-bottom:4px;">🚫 Deactivate School</h6>
                            <p style="font-size:14px;color:#475569;margin:0;">Instantly lock everyone out of the school.</p>
                        </div>
                        <button type="button" class="btn btn-danger" style="border-radius:10px;padding:10px 24px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#deactivateModal">
                            <i class="fas fa-power-off"></i> Deactivate
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div style="position:sticky;bottom:0;background:white;padding:16px 0;border-top:1px solid #e2e8f0;margin-top:24px;text-align:right;">
        <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);border:none;border-radius:12px;padding:12px 40px;font-weight:600;font-size:16px;">
            <i class="fas fa-save"></i> Save All Settings
        </button>
    </div>
</form>

<!-- TERM CHANGE WARNING MODAL -->
<div class="modal fade" id="termChangeModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-header" style="background:#fef2f2;border-radius:16px 16px 0 0;border:none;">
                <h5 class="modal-title fw-bold" style="color:#dc2626;">⚠️ Warning: Changing Active Term</h5>
            </div>
            <div class="modal-body" style="padding:24px;">
                <p style="color:#0f172a;font-size:15px;">You are about to change the active term from:</p>
                <div style="background:#fef2f2;border-radius:10px;padding:14px;margin-bottom:16px;">
                    <div><strong>From:</strong> <span id="fromTerm" style="color:#dc2626;"></span></div>
                    <div><strong>To:</strong> <span id="toTerm" style="color:#16a34a;font-weight:600;"></span></div>
                </div>
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px;margin-bottom:16px;">
                    <strong style="color:#92400e;">⚠️ Before you continue:</strong>
                    <ul style="color:#78350f;padding-left:20px;margin:8px 0 0;">
                        <li>Make sure you've <a href="archive_term.php" style="color:#7c3aed;font-weight:700;">archived the old term data</a> if needed</li>
                        <li>Teachers won't be able to enter scores for old term after this</li>
                        <li>Parents will see the new term's (empty) data</li>
                        <li>Old data is still visible in each student's profile</li>
                    </ul>
                </div>
                <p style="font-size:14px;color:#64748b;">Are you sure you want to switch the active term?</p>
            </div>
            <div class="modal-footer" style="border-top:1px solid #f1f5f9;padding:16px 24px;">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal" style="border-radius:10px;padding:10px 20px;">Cancel</button>
                <a href="archive_term.php" class="btn btn-outline-warning" style="border-radius:10px;padding:10px 20px;">Archive First</a>
                <button type="button" id="confirmTermChange" class="btn btn-danger" style="border-radius:10px;padding:10px 24px;font-weight:600;">Yes, Change Term</button>
            </div>
        </div>
    </div>
</div>

<!-- DEACTIVATE MODAL -->
<div class="modal fade" id="deactivateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px;border:none;">
            <div class="modal-header" style="border-bottom:1px solid #f1f5f9;padding:20px 28px;">
                <h5 class="modal-title fw-bold" style="color:#dc2626;">🚫 Confirm Deactivate School</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="modal-body" style="padding:28px;">
                    <p>You are about to <strong>deactivate <?php echo htmlspecialchars($school['school_name']); ?></strong>.</p>
                    <ul style="color:#64748b;padding-left:20px;">
                        <li>All users will be <strong>locked out immediately</strong>.</li>
                        <li>All data remains safe.</li>
                        <li>Contact Super Admin to reactivate.</li>
                    </ul>
                    <input type="hidden" name="deactivate_school_confirm" value="1">
                </div>
                <div class="modal-footer" style="border-top:1px solid #f1f5f9;padding:16px 28px;">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="background:#dc2626;font-weight:600;">Yes, Deactivate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const originalTerm = document.getElementById('originalTerm').value;
    const originalSession = document.getElementById('originalSession').value;
    const termSelect = document.getElementById('termSelect');
    const sessionSelect = document.getElementById('sessionSelect');
    const settingsForm = document.getElementById('settingsForm');
    let confirmedChange = false;

    // Only guard on actual submit — not on dropdown change
    settingsForm.addEventListener('submit', function(e) {
        const newTerm = termSelect.value;
        const newSession = sessionSelect.value;

        if ((newTerm !== originalTerm || newSession !== originalSession) && !confirmedChange) {
            e.preventDefault();
            document.getElementById('fromTerm').textContent = originalTerm + ' - ' + originalSession;
            document.getElementById('toTerm').textContent = newTerm + ' - ' + newSession;
            new bootstrap.Modal(document.getElementById('termChangeModal')).show();
        }
    });

    // Confirm button — sets the flag and submits programmatically
    document.getElementById('confirmTermChange').addEventListener('click', function() {
        confirmedChange = true;
        const modalEl = document.getElementById('termChangeModal');
        const modalInstance = bootstrap.Modal.getInstance(modalEl);
        if (modalInstance) modalInstance.hide();
        settingsForm.submit();
    });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>