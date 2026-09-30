<?php
// school_owner/manage_teachers.php - Staff Management with Subject/Class Assignment (v2)
// FIX: duplicate email check on add/edit
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
    $sql = "SELECT id, full_name, email, phone, role, photo_path, status, last_login, created_at 
            FROM users 
            WHERE school_id = ? 
            AND role IN ('Teacher', 'Principal', 'Accountant')";
    $params = [$school_id];
    if (!empty($search)) {
        $sql .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR role LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $teachers = $stmt->fetchAll();
    header('Content-Type: application/json');
    echo json_encode($teachers);
    exit;
}

// ---------- AJAX GET ASSIGNMENTS ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_assignments' && isset($_GET['teacher_id'])) {
    $tid = (int)$_GET['teacher_id'];

    $verifyStmt = $db->prepare("SELECT school_id FROM users WHERE id = ?");
    $verifyStmt->execute([$tid]);
    $teacherSchoolId = (int)$verifyStmt->fetchColumn();

    if ($teacherSchoolId !== $school_id) {
        header('Content-Type: application/json');
        echo json_encode(['subject_ids' => [], 'class_ids' => []]);
        exit;
    }

    $assignments = ['subject_ids' => [], 'class_ids' => []];
    $stmt = $db->prepare("SELECT subject_id, class_id FROM teacher_subject_assignments WHERE teacher_id = ? AND school_id = ?");
    $stmt->execute([$tid, $school_id]);
    while ($row = $stmt->fetch()) {
        $assignments['subject_ids'][] = (int)$row['subject_id'];
        $assignments['class_ids'][] = (int)$row['class_id'];
    }
    $assignments['subject_ids'] = array_values(array_unique($assignments['subject_ids']));
    $assignments['class_ids'] = array_values(array_unique($assignments['class_ids']));
    header('Content-Type: application/json');
    echo json_encode($assignments);
    exit;
}

// ---------- ARCHIVE ----------
if (isset($_GET['archive']) && is_numeric($_GET['archive'])) {
    $teacher_id = (int)$_GET['archive'];

    $verifyStmt = $db->prepare("SELECT school_id FROM users WHERE id = ?");
    $verifyStmt->execute([$teacher_id]);
    $teacherSchoolId = (int)$verifyStmt->fetchColumn();

    if ($teacherSchoolId !== $school_id) {
        $_SESSION['error'] = "You cannot modify staff from another school.";
        header("Location: manage_teachers.php");
        exit;
    }

    $stmt = $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ? AND school_id = ? AND role != 'Owner'");
    if ($stmt->execute([$teacher_id, $school_id])) {
        logActivity('ARCHIVE_TEACHER', "Deactivated staff ID: $teacher_id", $school_id, $user_id);
        $_SESSION['success'] = "Staff deactivated successfully.";
    } else {
        $_SESSION['error'] = "Failed to deactivate staff.";
    }
    header("Location: manage_teachers.php");
    exit;
}

// ---------- REACTIVATE ----------
if (isset($_GET['reactivate']) && is_numeric($_GET['reactivate'])) {
    $teacher_id = (int)$_GET['reactivate'];

    $verifyStmt = $db->prepare("SELECT school_id FROM users WHERE id = ?");
    $verifyStmt->execute([$teacher_id]);
    $teacherSchoolId = (int)$verifyStmt->fetchColumn();

    if ($teacherSchoolId !== $school_id) {
        $_SESSION['error'] = "You cannot modify staff from another school.";
        header("Location: manage_teachers.php");
        exit;
    }

    $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE id = ? AND school_id = ? AND role != 'Owner'");
    if ($stmt->execute([$teacher_id, $school_id])) {
        logActivity('REACTIVATE_TEACHER', "Reactivated staff ID: $teacher_id", $school_id, $user_id);
        $_SESSION['success'] = "Staff reactivated successfully.";
    } else {
        $_SESSION['error'] = "Failed to reactivate staff.";
    }
    header("Location: manage_teachers.php");
    exit;
}

// ---------- ADD / EDIT ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = trim($_POST['role'] ?? 'Teacher');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $subject_ids = isset($_POST['subject_ids']) && is_array($_POST['subject_ids']) ? $_POST['subject_ids'] : [];
    $class_ids = isset($_POST['class_ids']) && is_array($_POST['class_ids']) ? $_POST['class_ids'] : [];

    $subject_ids = array_map('intval', $subject_ids);
    $class_ids = array_map('intval', $class_ids);

    if (empty($full_name) || empty($email)) {
        $_SESSION['error'] = "Full name and email are required.";
        header("Location: manage_teachers.php");
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please enter a valid email address.";
        header("Location: manage_teachers.php");
        exit;
    }

    if (!empty($password) && (strlen($password) < 6 || $password !== $confirm_password)) {
        $_SESSION['error'] = "Password must be at least 6 characters and match confirmation.";
        header("Location: manage_teachers.php");
        exit;
    }

    try {
        $db->beginTransaction();

        if ($edit_id > 0) {
            // EDITING EXISTING TEACHER
            $checkStmt = $db->prepare("SELECT school_id FROM users WHERE id = ?");
            $checkStmt->execute([$edit_id]);
            $actual_school_id = (int)$checkStmt->fetchColumn();

            if ($actual_school_id === 0) {
                throw new Exception("Staff not found.");
            }
            if ($actual_school_id !== $school_id) {
                throw new Exception("You cannot edit staff from another school.");
            }

            // ---- FIX: Duplicate email check (excluding self) ----
            $dupeStmt = $db->prepare("SELECT id, full_name FROM users WHERE email = ? AND school_id = ? AND id != ?");
            $dupeStmt->execute([$email, $school_id, $edit_id]);
            $dupe = $dupeStmt->fetch();
            if ($dupe) {
                throw new Exception("A staff member with email '" . htmlspecialchars($email) . "' already exists for this school (Name: " . htmlspecialchars($dupe['full_name']) . ").");
            }

            $sql = "UPDATE users SET full_name=?, email=?, phone=?, role=?";
            $params = [$full_name, $email, $phone, $role];
            if (!empty($password)) {
                $sql .= ", password=?";
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= " WHERE id=? AND school_id=?";
            $params[] = $edit_id;
            $params[] = $school_id;
            $db->prepare($sql)->execute($params);
            $teacher_id = $edit_id;
        } else {
            // ADDING NEW TEACHER
            // ---- FIX: Duplicate email check ----
            $dupeStmt = $db->prepare("SELECT id, full_name FROM users WHERE email = ? AND school_id = ?");
            $dupeStmt->execute([$email, $school_id]);
            $dupe = $dupeStmt->fetch();
            if ($dupe) {
                throw new Exception("A staff member with email '" . htmlspecialchars($email) . "' already exists for this school (Name: " . htmlspecialchars($dupe['full_name']) . ").");
            }

            $hashed = password_hash(!empty($password) ? $password : 'password123', PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO users (school_id, full_name, email, phone, password, role, status, created_at) VALUES (?,?,?,?,?,?, 'active', NOW())")
               ->execute([$school_id, $full_name, $email, $phone, $hashed, $role]);
            $teacher_id = (int)$db->lastInsertId();
        }

        // Delete old assignments
        $db->prepare("DELETE FROM teacher_subject_assignments WHERE teacher_id=? AND school_id=?")
           ->execute([$teacher_id, $school_id]);

        // Insert new assignments (cross product of subjects × classes)
        if (!empty($subject_ids) && !empty($class_ids)) {
            $insertAssignStmt = $db->prepare("INSERT INTO teacher_subject_assignments (teacher_id, school_id, subject_id, class_id, assignment_type, created_at) VALUES (?,?,?,?, 'subject_teacher', NOW())");
            foreach ($subject_ids as $sid) {
                foreach ($class_ids as $cid) {
                    $insertAssignStmt->execute([$teacher_id, $school_id, $sid, $cid]);
                }
            }
        }

        $db->commit();

        if (!empty($password)) {
            if ($edit_id > 0) {
                $_SESSION['success'] = "Staff saved. Password changed to: <strong>" . htmlspecialchars($password) . "</strong>";
            } else {
                $_SESSION['success'] = "Staff created. Login password: <strong>" . htmlspecialchars($password) . "</strong>";
            }
        } else {
            $_SESSION['success'] = "Staff saved successfully with assignments.";
        }

        logActivity('SAVE_TEACHER', "Saved staff ID: $teacher_id" . (!empty($password) ? " (password changed)" : ""), $school_id, $user_id);

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header("Location: manage_teachers.php");
    exit;
}

// ---------- FETCH SUBJECTS & CLASSES ----------
$subjects = $db->prepare("SELECT id, name FROM subjects WHERE school_id = ? AND status='active' ORDER BY name");
$subjects->execute([$school_id]);
$subjects = $subjects->fetchAll();

$classes = $db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND status='active' ORDER BY order_number, name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

include_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success" style="border-left: 4px solid #22c55e; font-weight: 500; background: #f0fdf4; padding:16px; margin-bottom:20px;"><i class="fas fa-check-circle"></i> ' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger" style="border-left: 4px solid #dc2626; font-weight: 500; background: #fef2f2; padding:16px; margin-bottom:20px;"><i class="fas fa-exclamation-circle"></i> ' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">👨‍🏫 Manage Staff</h1>
        <p>Teachers, Principals, and Accountants with subject/class assignment.</p>
    </div>
    <div style="display:flex; gap:10px;">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#teacherModal">Add Staff</button>
        <a href="bulk_import_teachers.php" class="btn btn-outline-primary">
            <i class="fas fa-file-import"></i> Bulk Import
        </a>
    </div>
</div>

<div class="row mb-3">
    <div class="col-md-6">
        <input type="text" id="liveSearch" class="form-control" placeholder="Search by name, email, phone...">
    </div>
    <div class="col-md-3 text-end">
        <span id="resultCount" class="text-muted"></span>
    </div>
</div>

<table class="table" style="background:white; border-radius:16px; overflow:hidden;">
    <thead>
        <tr>
            <th>Name</th>
            <th>Role</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Status</th>
            <th style="text-align:right;">Actions</th>
        </tr>
    </thead>
    <tbody id="teacherTableBody"></tbody>
</table>
<div id="emptyMessage" style="display:none; padding:40px; text-align:center; color:#94a3b8;">No staff found.</div>

<!-- Teacher Modal -->
<div class="modal fade" id="teacherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add New Staff</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="modal-body">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">
                    <div class="row">
                        <div class="col-md-6">
                            <label>Full Name *</label>
                            <input type="text" name="full_name" id="full_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label>Role *</label>
                            <select name="role" id="role" class="form-control" required>
                                <option value="Teacher">Teacher</option>
                                <option value="Principal">Principal</option>
                                <option value="Accountant">Accountant</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <label>Email *</label>
                            <input type="email" name="email" id="email" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label>Phone</label>
                            <input type="text" name="phone" id="phone" class="form-control">
                        </div>
                    </div>
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <label>Password (min 6) <span class="text-muted">- Leave blank to keep current password</span></label>
                            <input type="password" name="password" id="password" class="form-control" placeholder="Enter new password">
                        </div>
                        <div class="col-md-6">
                            <label>Confirm Password</label>
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Re-enter new password">
                        </div>
                    </div>
                    <hr>
                    <h6>Assign Subjects & Classes</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <label>Subjects</label>
                            <select name="subject_ids[]" id="subject_ids" class="form-control" multiple size="5">
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Hold Ctrl to select multiple</small>
                        </div>
                        <div class="col-md-6">
                            <label>Classes</label>
                            <select name="class_ids[]" id="class_ids" class="form-control" multiple size="5">
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Hold Ctrl to select multiple</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Staff</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('liveSearch');
    const tableBody = document.getElementById('teacherTableBody');
    const emptyMsg = document.getElementById('emptyMessage');
    const resultCount = document.getElementById('resultCount');
    let debounceTimer;

    function loadTeachers() {
        const search = searchInput.value.trim();
        const url = '?ajax=search&search=' + encodeURIComponent(search);
        fetch(url)
            .then(res => res.json())
            .then(data => {
                tableBody.innerHTML = '';
                if (data.length === 0) {
                    emptyMsg.style.display = 'block';
                    resultCount.textContent = '0 staff found';
                    return;
                }
                emptyMsg.style.display = 'none';
                resultCount.textContent = data.length + ' staff found';
                data.forEach(teacher => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${teacher.full_name}</td>
                        <td><span class="badge bg-primary">${teacher.role}</span></td>
                        <td>${teacher.email}</td>
                        <td>${teacher.phone || '—'}</td>
                        <td><span class="badge bg-${teacher.status === 'active' ? 'success' : 'danger'}">${teacher.status}</span></td>
                        <td style="text-align:right;">
                            ${teacher.status === 'active' 
                                ? `<a href="?archive=${teacher.id}" class="btn btn-sm btn-outline-danger" onclick="return confirm('Deactivate this staff?')"><i class="fas fa-user-slash"></i></a>`
                                : `<a href="?reactivate=${teacher.id}" class="btn btn-sm btn-outline-success" onclick="return confirm('Reactivate this staff?')"><i class="fas fa-undo"></i></a>`
                            }
                            <button class="btn btn-sm btn-outline-primary" onclick="editTeacher(${teacher.id}, '${(teacher.full_name||'').replace(/'/g, "\\'")}', '${(teacher.email||'').replace(/'/g, "\\'")}', '${(teacher.phone||'').replace(/'/g, "\\'")}', '${teacher.role}')"><i class="fas fa-edit"></i></button>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });
            })
            .catch(error => {
                console.error('Error:', error);
                tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading data.</td></tr>';
            });
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(loadTeachers, 300);
    });
    loadTeachers();

    window.editTeacher = function(id, name, email, phone, role) {
        document.getElementById('edit_id').value = id;
        document.getElementById('full_name').value = name;
        document.getElementById('email').value = email;
        document.getElementById('phone').value = phone;
        document.getElementById('role').value = role;
        document.getElementById('password').value = '';
        document.getElementById('confirm_password').value = '';
        document.getElementById('modalTitle').textContent = '✏️ Edit Staff';

        Array.from(document.getElementById('subject_ids').options).forEach(opt => opt.selected = false);
        Array.from(document.getElementById('class_ids').options).forEach(opt => opt.selected = false);

        fetch('?ajax=get_assignments&teacher_id=' + id)
            .then(res => res.json())
            .then(data => {
                if (data.subject_ids) {
                    data.subject_ids.forEach(sid => {
                        const opt = document.getElementById('subject_ids').querySelector(`option[value="${sid}"]`);
                        if (opt) opt.selected = true;
                    });
                }
                if (data.class_ids) {
                    data.class_ids.forEach(cid => {
                        const opt = document.getElementById('class_ids').querySelector(`option[value="${cid}"]`);
                        if (opt) opt.selected = true;
                    });
                }
            })
            .catch(error => console.error('Error fetching assignments:', error));

        new bootstrap.Modal(document.getElementById('teacherModal')).show();
    };

    document.getElementById('teacherModal').addEventListener('show.bs.modal', function() {
        if (document.getElementById('edit_id').value === '0') {
            document.getElementById('modalTitle').textContent = '➕ Add New Staff';
            document.getElementById('full_name').value = '';
            document.getElementById('email').value = '';
            document.getElementById('phone').value = '';
            document.getElementById('role').value = 'Teacher';
            document.getElementById('password').value = '';
            document.getElementById('confirm_password').value = '';
            Array.from(document.getElementById('subject_ids').options).forEach(opt => opt.selected = false);
            Array.from(document.getElementById('class_ids').options).forEach(opt => opt.selected = false);
        }
    });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>