<?php
// school_owner/manage_subjects.php - Subject Management (PDO + CSRF + Archive/Restore)
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
    $status_filter = $_GET['status'] ?? 'active';

    $sql = "SELECT id, name, code, level, status, created_at 
            FROM subjects 
            WHERE school_id = ? AND status = ?";
    $params = [$school_id, $status_filter];

    if (!empty($search)) {
        $sql .= " AND (name LIKE ? OR code LIKE ? OR level LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }
    $sql .= " ORDER BY name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $subjects = $stmt->fetchAll();
    header('Content-Type: application/json');
    echo json_encode($subjects);
    exit;
}

// ---------- ARCHIVE ----------
if (isset($_GET['archive']) && is_numeric($_GET['archive'])) {
    $subject_id = (int)$_GET['archive'];
    $stmt = $db->prepare("UPDATE subjects SET status = 'archived' WHERE id = ? AND school_id = ?");
    if ($stmt->execute([$subject_id, $school_id])) {
        logActivity('ARCHIVE_SUBJECT', "Archived subject ID: $subject_id", $school_id, $user_id);
        $_SESSION['success'] = "Subject archived successfully.";
    } else {
        $_SESSION['error'] = "Failed to archive subject.";
    }
    header("Location: manage_subjects.php");
    exit;
}

// ---------- REACTIVATE ----------
if (isset($_GET['reactivate']) && is_numeric($_GET['reactivate'])) {
    $subject_id = (int)$_GET['reactivate'];
    $stmt = $db->prepare("UPDATE subjects SET status = 'active' WHERE id = ? AND school_id = ?");
    if ($stmt->execute([$subject_id, $school_id])) {
        logActivity('REACTIVATE_SUBJECT', "Reactivated subject ID: $subject_id", $school_id, $user_id);
        $_SESSION['success'] = "Subject reactivated successfully.";
    } else {
        $_SESSION['error'] = "Failed to reactivate subject.";
    }
    header("Location: manage_subjects.php");
    exit;
}

// ---------- ADD / EDIT ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $level = trim($_POST['level'] ?? 'All');

    $valid_levels = ['Nursery', 'Primary', 'Junior Secondary', 'Senior Secondary', 'All'];
    if (!in_array($level, $valid_levels)) {
        $_SESSION['error'] = "Invalid level selected.";
        header("Location: manage_subjects.php");
        exit;
    }
    if (empty($name)) {
        $_SESSION['error'] = "Subject name is required.";
        header("Location: manage_subjects.php");
        exit;
    }

    if ($edit_id > 0) {
        $stmt = $db->prepare("UPDATE subjects SET name=?, code=?, level=? WHERE id=? AND school_id=?");
        $stmt->execute([$name, $code, $level, $edit_id, $school_id]);
        $_SESSION['success'] = "Subject updated successfully.";
    } else {
        $check = $db->prepare("SELECT id FROM subjects WHERE name=? AND school_id=? AND status='active'");
        $check->execute([$name, $school_id]);
        if ($check->fetch()) {
            $_SESSION['error'] = "A subject with this name already exists.";
            header("Location: manage_subjects.php");
            exit;
        }
        $stmt = $db->prepare("INSERT INTO subjects (school_id, name, code, level, status, created_at) VALUES (?,?,?,?, 'active', NOW())");
        $stmt->execute([$school_id, $name, $code, $level]);
        $_SESSION['success'] = "Subject added successfully.";
    }
    header("Location: manage_subjects.php");
    exit;
}

// ---------- FETCH STATS ----------
$stmt = $db->prepare("SELECT COUNT(*) FROM subjects WHERE school_id=? AND status='active'");
$stmt->execute([$school_id]);
$total_active = (int)$stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM subjects WHERE school_id=? AND status='archived'");
$stmt->execute([$school_id]);
$total_archived = (int)$stmt->fetchColumn();

include_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success">✅ ' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger">❌ ' . $_SESSION['error'] . '</div>';
    unset($_SESSION['error']);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">📚 Manage Subjects</h1>
        <p>Create, edit, and organize subjects for your school.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#subjectModal">Add Subject</button>
</div>

<div class="row mb-3">
    <div class="col-md-3">
        <select id="statusFilter" class="form-control">
            <option value="active">Active Subjects</option>
            <option value="archived">Archived Subjects</option>
        </select>
    </div>
    <div class="col-md-6">
        <input type="text" id="liveSearch" class="form-control" placeholder="Search by name, code, or level...">
    </div>
    <div class="col-md-3 text-end">
        <span id="resultCount" class="text-muted"></span>
    </div>
</div>

<table class="table" style="background:white; border-radius:16px; overflow:hidden;">
    <thead>
        <tr>
            <th>Subject Name</th>
            <th>Code</th>
            <th>Level</th>
            <th>Status</th>
            <th style="text-align:right;">Actions</th>
        </tr>
    </thead>
    <tbody id="subjectTableBody"></tbody>
</table>
<div id="emptyMessage" style="display:none; padding:40px; text-align:center; color:#94a3b8;">No subjects found.</div>

<!-- Subject Modal -->
<div class="modal fade" id="subjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add New Subject</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="modal-body">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">
                    <div class="mb-3">
                        <label>Subject Name *</label>
                        <input type="text" name="name" id="subject_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Subject Code</label>
                        <input type="text" name="code" id="subject_code" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label>Level *</label>
                        <select name="level" id="subject_level" class="form-control" required>
                            <option value="All">All Levels</option>
                            <option value="Nursery">Nursery</option>
                            <option value="Primary">Primary</option>
                            <option value="Junior Secondary">Junior Secondary</option>
                            <option value="Senior Secondary">Senior Secondary</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Subject</button>
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
    const tableBody = document.getElementById('subjectTableBody');
    const emptyMsg = document.getElementById('emptyMessage');
    const resultCount = document.getElementById('resultCount');
    let debounceTimer;

    function loadSubjects() {
        const search = searchInput.value.trim();
        const status = statusFilter.value;
        const url = '?ajax=search&search=' + encodeURIComponent(search) + '&status=' + status;
        fetch(url)
            .then(res => res.json())
            .then(data => {
                tableBody.innerHTML = '';
                if (data.length === 0) {
                    emptyMsg.style.display = 'block';
                    resultCount.textContent = '0 subjects found';
                    return;
                }
                emptyMsg.style.display = 'none';
                resultCount.textContent = data.length + ' subjects found';
                data.forEach(subj => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${subj.name}</td>
                        <td>${subj.code || '-'}</td>
                        <td>${subj.level}</td>
                        <td><span class="badge bg-${subj.status === 'active' ? 'success' : 'danger'}">${subj.status}</span></td>
                        <td style="text-align:right;">
                            ${subj.status === 'active' 
                                ? `<a href="?archive=${subj.id}" class="btn btn-sm btn-outline-danger" onclick="return confirm('Archive this subject?')"><i class="fas fa-archive"></i></a>`
                                : `<a href="?reactivate=${subj.id}" class="btn btn-sm btn-outline-success" onclick="return confirm('Reactivate this subject?')"><i class="fas fa-undo"></i> Reactivate</a>`
                            }
                            <button class="btn btn-sm btn-outline-primary" onclick="editSubject(${subj.id}, '${subj.name}', '${subj.code || ''}', '${subj.level}')"><i class="fas fa-edit"></i></button>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });
            });
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(loadSubjects, 300);
    });
    statusFilter.addEventListener('change', loadSubjects);
    loadSubjects();

    window.editSubject = function(id, name, code, level) {
        document.getElementById('edit_id').value = id;
        document.getElementById('subject_name').value = name;
        document.getElementById('subject_code').value = code;
        document.getElementById('subject_level').value = level;
        document.getElementById('modalTitle').textContent = '✏️ Edit Subject';
        new bootstrap.Modal(document.getElementById('subjectModal')).show();
    };
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>