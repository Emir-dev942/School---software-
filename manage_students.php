<?php
// school_owner/manage_students.php - v4 (multi-child parent edit fixed)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id = (int)$_SESSION['user_id'];

// ---------- AJAX SEARCH ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    $search = trim($_GET['search'] ?? '');
    $status_filter = $_GET['status'] ?? 'Active';

    $sql = "SELECT s.*, c.name as class_name 
            FROM students s 
            LEFT JOIN classes c ON s.class_id = c.id 
            WHERE s.school_id = ? AND s.status = ?";
    $params = [$school_id, $status_filter];

    if (!empty($search)) {
        $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.parent_phone LIKE ? OR s.student_id LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY s.id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
    header('Content-Type: application/json');
    echo json_encode($students);
    exit;
}

// ---------- ARCHIVE ----------
if (isset($_GET['archive']) && is_numeric($_GET['archive'])) {
    $student_id = (int)$_GET['archive'];
    $stmt = $db->prepare("UPDATE students SET status = 'Archived' WHERE id = ? AND school_id = ?");
    if ($stmt->execute([$student_id, $school_id])) {
        $_SESSION['success'] = "Student archived successfully.";
    } else {
        $_SESSION['error'] = "Failed to archive student.";
    }
    header("Location: manage_students.php");
    exit;
}

// ---------- RESTORE ----------
if (isset($_GET['restore']) && is_numeric($_GET['restore'])) {
    $student_id = (int)$_GET['restore'];
    $stmt = $db->prepare("UPDATE students SET status = 'Active' WHERE id = ? AND school_id = ?");
    if ($stmt->execute([$student_id, $school_id])) {
        $_SESSION['success'] = "Student restored successfully.";
    } else {
        $_SESSION['error'] = "Failed to restore student.";
    }
    header("Location: manage_students.php");
    exit;
}

// ---------- EXPORT CSV ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $status_filter = $_GET['status'] ?? 'Active';
    $search = trim($_GET['search'] ?? '');

    $sql = "SELECT s.student_id, s.first_name, s.last_name, c.name AS class_name, s.gender, s.parent_name, s.parent_phone, s.parent_email, s.status, s.admission_date 
            FROM students s 
            LEFT JOIN classes c ON s.class_id = c.id 
            WHERE s.school_id = ? AND s.status = ?";
    $params = [$school_id, $status_filter];

    if (!empty($search)) {
        $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.parent_phone LIKE ? OR s.student_id LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY s.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Student ID', 'First Name', 'Last Name', 'Class', 'Gender', 'Parent Name', 'Parent Phone', 'Parent Email', 'Status', 'Admission Date']);

    foreach ($students as $row) {
        fputcsv($output, [
            $row['student_id'],
            $row['first_name'],
            $row['last_name'],
            $row['class_name'] ?? 'N/A',
            $row['gender'] ?? 'Male',
            $row['parent_name'] ?? '',
            $row['parent_phone'] ?? '',
            $row['parent_email'] ?? '',
            $row['status'],
            $row['admission_date'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

// ---------- ADD STUDENT ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student']) && $_POST['add_student'] === '1') {
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name = sanitize($_POST['last_name'] ?? '');
    $class_id = (int)($_POST['class_id'] ?? 0);
    $gender = sanitize($_POST['gender'] ?? 'Male');
    $parent_name = sanitize($_POST['parent_name'] ?? '');
    $parent_phone = sanitize($_POST['parent_phone'] ?? '');
    $parent_email = sanitize($_POST['parent_email'] ?? '');
    $parent_password = $_POST['parent_password'] ?? '';

    if (empty($first_name) || empty($last_name) || $class_id <= 0) {
        $_SESSION['error'] = "First name, last name, and class are required.";
    } elseif (empty($parent_phone) && empty($parent_email)) {
        $_SESSION['error'] = "Parent phone or email is required.";
    } elseif (!empty($parent_password) && strlen($parent_password) < 6) {
        $_SESSION['error'] = "Parent password must be at least 6 characters.";
    } else {
        $parent_password_to_use = !empty($parent_password) ? $parent_password : 'password123';

        $photo_path = 'default_student.png';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $new_name = 'student_' . time() . '_' . uniqid() . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/student_photos/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $new_name)) {
                    $photo_path = $new_name;
                }
            }
        }

        try {
            $db->beginTransaction();

            $checkStudent = $db->prepare("SELECT id FROM students WHERE school_id = ? AND first_name = ? AND last_name = ? AND class_id = ? AND parent_phone = ? LIMIT 1");
            $checkStudent->execute([$school_id, $first_name, $last_name, $class_id, $parent_phone]);
            $existingStudent = $checkStudent->fetch();

            if ($existingStudent) {
                // ---- Student already exists: update and re-link parent ----
                $existing_id = (int)$existingStudent['id'];

                $db->prepare("UPDATE students SET gender=?, parent_name=?, parent_email=?, photo_path=?, status='Active' WHERE id=? AND school_id=?")
                   ->execute([$gender, $parent_name, $parent_email, $photo_path, $existing_id, $school_id]);

                // Find the parent account (multi-child aware)
                $parent_id = null;
                $linkStmt = $db->prepare("SELECT parent_id FROM parent_students WHERE student_id = ? AND school_id = ? LIMIT 1");
                $linkStmt->execute([$existing_id, $school_id]);
                $parent_id = $linkStmt->fetchColumn();

                if (!$parent_id && $parent_phone) {
                    $pStmt = $db->prepare("SELECT id FROM parent_portal WHERE school_id = ? AND parent_phone = ? LIMIT 1");
                    $pStmt->execute([$school_id, $parent_phone]);
                    $parent_id = $pStmt->fetchColumn();
                }
                if (!$parent_id) {
                    $pStmt = $db->prepare("SELECT id FROM parent_portal WHERE school_id = ? AND student_id = ? LIMIT 1");
                    $pStmt->execute([$school_id, $existing_id]);
                    $parent_id = $pStmt->fetchColumn();
                }

                $hashed = password_hash($parent_password_to_use, PASSWORD_DEFAULT);

                if ($parent_id) {
                    $db->prepare("UPDATE parent_portal SET parent_phone = ?, parent_email = COALESCE(NULLIF(?, ''), parent_email), password = ?, is_active = 1 WHERE id = ?")
                       ->execute([$parent_phone, $parent_email, $hashed, $parent_id]);
                } else {
                    $db->prepare("INSERT INTO parent_portal (school_id, student_id, parent_phone, parent_email, password, is_active, created_at) VALUES (?,?,?,?,?, 1, NOW())")
                       ->execute([$school_id, $existing_id, $parent_phone, $parent_email, $hashed]);
                    $parent_id = (int)$db->lastInsertId();

                    $db->prepare("INSERT INTO parent_students (parent_id, student_id, school_id) VALUES (?, ?, ?)")
                       ->execute([$parent_id, $existing_id, $school_id]);
                }

                $db->commit();
                $_SESSION['success'] = "✅ Student already existed. Updated successfully. Parent login: $parent_phone | Password: $parent_password_to_use";
            } else {
                // ---- New student ----
                $student_id_code = 'STU-' . strtoupper(substr(uniqid(), -6));

                $db->prepare("INSERT INTO students (school_id, class_id, student_id, first_name, last_name, gender, parent_name, parent_phone, parent_email, photo_path, status, admission_date, created_at) VALUES (?,?,?,?,?,?,?,?,?,?, 'Active', CURDATE(), NOW())")
                   ->execute([$school_id, $class_id, $student_id_code, $first_name, $last_name, $gender, $parent_name, $parent_phone, $parent_email, $photo_path]);
                $new_student_id = (int)$db->lastInsertId();

                // ---- Multi-child parent linking ----
                $checkParent = $db->prepare("SELECT id FROM parent_portal WHERE school_id = ? AND parent_phone = ? LIMIT 1");
                $checkParent->execute([$school_id, $parent_phone]);
                $existingParent = $checkParent->fetch();

                if ($existingParent) {
                    $parent_account_id = (int)$existingParent['id'];
                    $db->prepare("UPDATE parent_portal SET parent_email = COALESCE(NULLIF(?, ''), parent_email), is_active = 1 WHERE id = ?")
                       ->execute([$parent_email, $parent_account_id]);
                } else {
                    $hashed = password_hash($parent_password_to_use, PASSWORD_DEFAULT);
                    $db->prepare("INSERT INTO parent_portal (school_id, student_id, parent_phone, parent_email, password, is_active, created_at) VALUES (?,?,?,?,?, 1, NOW())")
                       ->execute([$school_id, $new_student_id, $parent_phone, $parent_email, $hashed]);
                    $parent_account_id = (int)$db->lastInsertId();
                }

                // Always link the child
                $linkCheck = $db->prepare("SELECT id FROM parent_students WHERE parent_id = ? AND student_id = ? LIMIT 1");
                $linkCheck->execute([$parent_account_id, $new_student_id]);
                if (!$linkCheck->fetch()) {
                    $db->prepare("INSERT INTO parent_students (parent_id, student_id, school_id) VALUES (?, ?, ?)")
                       ->execute([$parent_account_id, $new_student_id, $school_id]);
                }

                // ---- Auto-create invoice if fee structure exists ----
                $currentTermStmt = $db->prepare("SELECT current_term, current_session FROM schools WHERE id = ?");
                $currentTermStmt->execute([$school_id]);
                $schoolInfo = $currentTermStmt->fetch();
                $auto_term = $schoolInfo['current_term'] ?? 'Term 1';
                $auto_session = $schoolInfo['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

                $feeStmt = $db->prepare("SELECT term_fee, due_date FROM fee_structures WHERE school_id = ? AND class_id = ? AND term = ? AND session = ? LIMIT 1");
                $feeStmt->execute([$school_id, $class_id, $auto_term, $auto_session]);
                $fee = $feeStmt->fetch();

                $invoice_msg = '';
                if ($fee) {
                    $db->prepare("INSERT INTO invoices (school_id, student_id, term, session, term_name, session_year, total_amount, paid_amount, due_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 'pending', NOW())")
                       ->execute([$school_id, $new_student_id, $auto_term, $auto_session, $auto_term, $auto_session, $fee['term_fee'], $fee['due_date']]);
                    $invoice_msg = " Invoice of ₦" . number_format($fee['term_fee']) . " created.";
                }

                $db->commit();
                $_SESSION['success'] = "✅ Student added. Parent login: $parent_phone | Password: $parent_password_to_use$invoice_msg";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['error'] = "Failed to save student: " . $e->getMessage();
        }
    }
    header("Location: manage_students.php");
    exit;
}

// ---------- EDIT STUDENT ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_student']) && $_POST['edit_student'] === '1') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name = sanitize($_POST['last_name'] ?? '');
    $class_id = (int)($_POST['class_id'] ?? 0);
    $gender = sanitize($_POST['gender'] ?? 'Male');
    $parent_name = sanitize($_POST['parent_name'] ?? '');
    $parent_phone = sanitize($_POST['parent_phone'] ?? '');
    $parent_email = sanitize($_POST['parent_email'] ?? '');
    $parent_password = $_POST['parent_password'] ?? '';

    if ($student_id <= 0) {
        $_SESSION['error'] = "Invalid student ID.";
    } elseif (empty($first_name) || empty($last_name) || $class_id <= 0) {
        $_SESSION['error'] = "First name, last name, and class are required.";
    } elseif (!empty($parent_password) && strlen($parent_password) < 6) {
        $_SESSION['error'] = "Parent password must be at least 6 characters.";
    } else {
        $photo_path = $_POST['current_photo'] ?? 'default_student.png';
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $new_name = 'student_' . time() . '_' . uniqid() . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/student_photos/';
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $new_name)) {
                    $photo_path = $new_name;
                }
            }
        }

        try {
            $db->beginTransaction();

            // Get OLD phone before we update (so we can find the parent account)
            $oldStmt = $db->prepare("SELECT parent_phone FROM students WHERE id = ? AND school_id = ?");
            $oldStmt->execute([$student_id, $school_id]);
            $old_phone = $oldStmt->fetchColumn();

            // Update student row
            $stmt = $db->prepare("UPDATE students SET first_name=?, last_name=?, class_id=?, gender=?, parent_name=?, parent_phone=?, parent_email=?, photo_path=? WHERE id=? AND school_id=?");
            $stmt->execute([$first_name, $last_name, $class_id, $gender, $parent_name, $parent_phone, $parent_email, $photo_path, $student_id, $school_id]);

            // ---- Find the parent account (multi-child aware) ----
            $parent_id = null;
            $linkStmt = $db->prepare("SELECT parent_id FROM parent_students WHERE student_id = ? AND school_id = ? LIMIT 1");
            $linkStmt->execute([$student_id, $school_id]);
            $parent_id = $linkStmt->fetchColumn();

            if (!$parent_id && $old_phone) {
                $pStmt = $db->prepare("SELECT id FROM parent_portal WHERE school_id = ? AND parent_phone = ? LIMIT 1");
                $pStmt->execute([$school_id, $old_phone]);
                $parent_id = $pStmt->fetchColumn();
            }
            if (!$parent_id) {
                $pStmt = $db->prepare("SELECT id FROM parent_portal WHERE school_id = ? AND student_id = ? LIMIT 1");
                $pStmt->execute([$school_id, $student_id]);
                $parent_id = $pStmt->fetchColumn();
            }

            if ($parent_id) {
                // Update phone + email
                $db->prepare("UPDATE parent_portal SET parent_phone = ?, parent_email = COALESCE(NULLIF(?, ''), parent_email) WHERE id = ?")
                   ->execute([$parent_phone, $parent_email, $parent_id]);

                // Update password if provided
                if (!empty($parent_password) && strlen($parent_password) >= 6) {
                    $hashed = password_hash($parent_password, PASSWORD_DEFAULT);
                    $db->prepare("UPDATE parent_portal SET password = ?, is_active = 1 WHERE id = ?")
                       ->execute([$hashed, $parent_id]);
                }

                // If phone changed, sync it across ALL linked children
                if ($old_phone && $old_phone !== $parent_phone) {
                    $db->prepare("
                        UPDATE students 
                        SET parent_phone = ? 
                        WHERE id IN (SELECT student_id FROM parent_students WHERE parent_id = ?)
                          AND school_id = ?
                    ")->execute([$parent_phone, $parent_id, $school_id]);
                }
            } else {
                // No parent account — create one
                $pw = !empty($parent_password) && strlen($parent_password) >= 6 ? $parent_password : 'password123';
                $hashed = password_hash($pw, PASSWORD_DEFAULT);
                $db->prepare("INSERT INTO parent_portal (school_id, student_id, parent_phone, parent_email, password, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())")
                   ->execute([$school_id, $student_id, $parent_phone, $parent_email, $hashed]);
                $parent_id = (int)$db->lastInsertId();

                $db->prepare("INSERT INTO parent_students (parent_id, student_id, school_id) VALUES (?, ?, ?)")
                   ->execute([$parent_id, $student_id, $school_id]);
            }

            $db->commit();

            if (!empty($parent_password)) {
                $_SESSION['success'] = "✅ Student updated. Parent password changed to: " . htmlspecialchars($parent_password);
            } else {
                $_SESSION['success'] = "✅ Student updated successfully.";
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $_SESSION['error'] = "Failed to update student: " . $e->getMessage();
        }
    }
    header("Location: manage_students.php");
    exit;
}

// ---------- FETCH CLASSES ----------
$classes = $db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND status='active' ORDER BY name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

include_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success" style="border-left:4px solid #22c55e;background:#f0fdf4;padding:16px;margin-bottom:20px;"><i class="fas fa-check-circle"></i> ' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger" style="border-left:4px solid #dc2626;background:#fef2f2;padding:16px;margin-bottom:20px;"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">👨‍🎓 Manage Students</h1>
        <p>Add, edit, and manage students. Parent portal is created automatically.</p>
    </div>
    <div style="display:flex; gap:10px;">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#studentModal" id="addStudentBtn">Add Student</button>
        <a href="?export=csv&status=Active" class="btn btn-outline-success"><i class="fas fa-file-export"></i> Export CSV</a>
    </div>
</div>

<!-- Filter + Search -->
<div class="row mb-3">
    <div class="col-md-3">
        <select id="statusFilter" class="form-control">
            <option value="Active">Active Students</option>
            <option value="Archived">Archived Students</option>
        </select>
    </div>
    <div class="col-md-6">
        <input type="text" id="liveSearch" class="form-control" placeholder="Search by name, ID, or parent phone...">
    </div>
    <div class="col-md-3 text-end"><span id="resultCount" class="text-muted"></span></div>
</div>

<!-- Students Table -->
<div style="background:#fff; border-radius:16px; overflow:hidden;">
    <div style="overflow-x:auto;">
        <table class="table" style="margin-bottom:0;">
            <thead>
                <tr><th>Photo</th><th>Name</th><th>Class</th><th>Parent Phone</th><th>Status</th><th style="text-align:right;">Actions</th></tr>
            </thead>
            <tbody id="studentTableBody"></tbody>
        </table>
    </div>
    <div id="emptyMessage" style="display:none; padding:40px; text-align:center; color:#94a3b8;">No students found.</div>
</div>

<!-- Student Modal -->
<div class="modal fade" id="studentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="studentModalTitle">Add Student</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <div class="modal-body">
                    <input type="hidden" name="student_id" id="edit_student_id" value="0">
                    <input type="hidden" name="current_photo" id="current_photo" value="">
                    <input type="hidden" name="add_student" id="add_student_flag" value="1">
                    <input type="hidden" name="edit_student" id="edit_student_flag" value="0">

                    <div class="row">
                        <div class="col-md-6"><label>First Name *</label><input type="text" name="first_name" id="first_name" class="form-control" required></div>
                        <div class="col-md-6"><label>Last Name *</label><input type="text" name="last_name" id="last_name" class="form-control" required></div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <label>Class *</label>
                            <select name="class_id" id="class_id" class="form-control" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label>Gender</label>
                            <select name="gender" id="gender" class="form-control"><option value="Male">Male</option><option value="Female">Female</option></select>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6"><label>Parent Name</label><input type="text" name="parent_name" id="parent_name" class="form-control"></div>
                        <div class="col-md-6"><label>Parent Phone *</label><input type="text" name="parent_phone" id="parent_phone" class="form-control" required></div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6"><label>Parent Email</label><input type="email" name="parent_email" id="parent_email" class="form-control"></div>
                        <div class="col-md-6"><label>Parent Password <span class="text-muted">(blank = password123)</span></label><input type="password" name="parent_password" id="parent_password" class="form-control" placeholder="Leave blank"></div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-12">
                            <label>Student Photo</label>
                            <input type="file" name="photo" id="photo" class="form-control" accept="image/*">
                            <div id="photoPreview" style="margin-top:8px; display:none;"><img id="previewImage" src="" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Student</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('liveSearch');
    const statusFilter = document.getElementById('statusFilter');
    const tableBody = document.getElementById('studentTableBody');
    const emptyMsg = document.getElementById('emptyMessage');
    const resultCount = document.getElementById('resultCount');
    let debounceTimer;

    function getPhotoUrl(photoPath) {
        if (photoPath && photoPath !== 'default_student.png' && photoPath !== '') {
            return '<?php echo BASE_URL; ?>uploads/student_photos/' + photoPath;
        }
        return '<?php echo BASE_URL; ?>assets/images/default_avatar.png';
    }

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function loadStudents() {
        const search = searchInput.value.trim();
        const status = statusFilter.value;
        const url = '?ajax=search&search=' + encodeURIComponent(search) + '&status=' + status;
        fetch(url).then(res => res.json()).then(data => {
            tableBody.innerHTML = '';
            if (data.length === 0) {
                emptyMsg.style.display = 'block';
                resultCount.textContent = '0 students found';
                return;
            }
            emptyMsg.style.display = 'none';
            resultCount.textContent = data.length + ' students found';

            data.forEach(student => {
                const photoUrl = getPhotoUrl(student.photo_path);
                const row = document.createElement('tr');
                const studentJson = escHtml(JSON.stringify(student));

                row.innerHTML = `
                    <td><img src="${photoUrl}" width="40" height="40" class="rounded-circle viewable-photo" style="cursor:pointer;object-fit:cover;border:2px solid #e2e8f0;"></td>
                    <td><a href="view_student.php?id=${student.id}" style="text-decoration:none;color:inherit;"><strong>${escHtml(student.first_name)} ${escHtml(student.last_name)}</strong><br><small class="text-muted">${escHtml(student.student_id || '')}</small></a></td>
                    <td>${escHtml(student.class_name || 'N/A')}</td>
                    <td>${escHtml(student.parent_phone || 'N/A')}</td>
                    <td><span class="badge bg-${student.status === 'Active' ? 'success' : 'danger'}">${escHtml(student.status)}</span></td>
                    <td style="text-align:right;">
                        ${student.status === 'Active' 
                            ? `<a href="?archive=${student.id}" class="btn btn-sm btn-outline-danger" onclick="return confirm('Archive this student?')"><i class="fas fa-archive"></i></a>`
                            : `<a href="?restore=${student.id}" class="btn btn-sm btn-outline-success" onclick="return confirm('Restore this student?')"><i class="fas fa-undo"></i></a>`
                        }
                        <button class="btn btn-sm btn-outline-primary" data-student='${studentJson}' onclick="editStudentFromButton(this)"><i class="fas fa-edit"></i></button>
                    </td>
                `;
                tableBody.appendChild(row);
            });
        }).catch(err => {
            console.error(err);
            tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading data.</td></tr>';
        });
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(loadStudents, 300);
    });
    statusFilter.addEventListener('change', loadStudents);
    loadStudents();

    document.getElementById('photo').addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('previewImage').src = e.target.result;
                document.getElementById('photoPreview').style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    });

    window.editStudentFromButton = function(btn) {
        const student = JSON.parse(btn.dataset.student.replace(/&#39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>'));
        document.getElementById('edit_student_id').value = student.id;
        document.getElementById('first_name').value = student.first_name || '';
        document.getElementById('last_name').value = student.last_name || '';
        document.getElementById('class_id').value = student.class_id || '';
        document.getElementById('parent_phone').value = student.parent_phone || '';
        document.getElementById('parent_name').value = student.parent_name || '';
        document.getElementById('parent_email').value = student.parent_email || '';
        document.getElementById('gender').value = student.gender || 'Male';
        document.getElementById('current_photo').value = student.photo_path || '';
        document.getElementById('parent_password').value = '';
        document.getElementById('studentModalTitle').textContent = '✏️ Edit Student';
        document.getElementById('add_student_flag').value = '0';
        document.getElementById('edit_student_flag').value = '1';
        document.getElementById('photo').value = '';
        document.getElementById('photoPreview').style.display = 'none';
        new bootstrap.Modal(document.getElementById('studentModal')).show();
    };

    // Reset form when Add Student is clicked
    document.getElementById('addStudentBtn').addEventListener('click', function() {
        document.getElementById('edit_student_id').value = '0';
        document.getElementById('studentModalTitle').textContent = '➕ Add Student';
        document.getElementById('first_name').value = '';
        document.getElementById('last_name').value = '';
        document.getElementById('class_id').value = '';
        document.getElementById('parent_phone').value = '';
        document.getElementById('parent_name').value = '';
        document.getElementById('parent_email').value = '';
        document.getElementById('gender').value = 'Male';
        document.getElementById('current_photo').value = '';
        document.getElementById('photo').value = '';
        document.getElementById('parent_password').value = '';
        document.getElementById('photoPreview').style.display = 'none';
        document.getElementById('add_student_flag').value = '1';
        document.getElementById('edit_student_flag').value = '0';
    });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>