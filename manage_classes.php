<?php
// school_owner/manage_classes.php - Complete Class Management (PDO + CSRF) - v2 fixed redirects
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
    $sql = "SELECT id, name, level, order_number, status, created_at 
            FROM classes 
            WHERE school_id = ?";
    $params = [$school_id];

    if (!empty($search)) {
        $sql .= " AND (name LIKE ? OR level LIKE ? OR status LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }
    $sql .= " ORDER BY order_number ASC, name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $classes = $stmt->fetchAll();
    header('Content-Type: application/json');
    echo json_encode($classes);
    exit;
}

// ---------- HANDLE ARCHIVE ----------
if (isset($_GET['archive']) && is_numeric($_GET['archive'])) {
    $class_id = (int)$_GET['archive'];
    $stmt = $db->prepare("UPDATE classes SET status = 'archived' WHERE id = ? AND school_id = ?");
    if ($stmt->execute([$class_id, $school_id])) {
        logActivity('ARCHIVE_CLASS', "Archived class ID: $class_id", $school_id, $user_id);
        $_SESSION['success'] = "Class archived successfully.";
    } else {
        $_SESSION['error'] = "Failed to archive class.";
    }
    header("Location: manage_classes.php");
    exit;
}

// ---------- HANDLE REACTIVATE ----------
if (isset($_GET['reactivate']) && is_numeric($_GET['reactivate'])) {
    $class_id = (int)$_GET['reactivate'];
    $stmt = $db->prepare("UPDATE classes SET status = 'active' WHERE id = ? AND school_id = ?");
    if ($stmt->execute([$class_id, $school_id])) {
        logActivity('REACTIVATE_CLASS', "Reactivated class ID: $class_id", $school_id, $user_id);
        $_SESSION['success'] = "Class reactivated successfully.";
    } else {
        $_SESSION['error'] = "Failed to reactivate class.";
    }
    header("Location: manage_classes.php");
    exit;
}

// ---------- HANDLE ADD / EDIT ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $level = trim($_POST['level'] ?? 'Primary');
    $order_number = (int)($_POST['order_number'] ?? 0);

    $valid_levels = ['KG', 'Nursery', 'Primary', 'Junior Secondary', 'Senior Secondary'];
    if (!in_array($level, $valid_levels)) {
        $_SESSION['error'] = "Invalid level selected.";
        header("Location: manage_classes.php");
        exit;
    }
    if (empty($name)) {
        $_SESSION['error'] = "Class name is required.";
        header("Location: manage_classes.php");
        exit;
    }

    if ($edit_id > 0) {
        $stmt = $db->prepare("UPDATE classes SET name = ?, level = ?, order_number = ? WHERE id = ? AND school_id = ?");
        if ($stmt->execute([$name, $level, $order_number, $edit_id, $school_id])) {
            logActivity('EDIT_CLASS', "Updated class: $name (ID: $edit_id)", $school_id, $user_id);
            $_SESSION['success'] = "Class updated successfully.";
        } else {
            $_SESSION['error'] = "Failed to update class.";
        }
    } else {
        // Check duplicate
        $stmt = $db->prepare("SELECT id FROM classes WHERE name = ? AND school_id = ? AND status = 'active'");
        $stmt->execute([$name, $school_id]);
        if ($stmt->fetch()) {
            $_SESSION['error'] = "A class with this name already exists.";
            header("Location: manage_classes.php");
            exit;
        }
        $stmt = $db->prepare("INSERT INTO classes (school_id, name, level, order_number, status, created_at) VALUES (?, ?, ?, ?, 'active', NOW())");
        if ($stmt->execute([$school_id, $name, $level, $order_number])) {
            $new_id = (int)$db->lastInsertId();
            logActivity('ADD_CLASS', "Added class: $name (ID: $new_id)", $school_id, $user_id);
            $_SESSION['success'] = "Class added successfully.";
        } else {
            $_SESSION['error'] = "Failed to add class.";
        }
    }
    header("Location: manage_classes.php");
    exit;
}

// ---------- HANDLE CSV EXPORT ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="classes_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Class Name', 'Level', 'Order', 'Status', 'Created At']);
    $stmt = $db->prepare("SELECT name, level, order_number, status, created_at FROM classes WHERE school_id = ? ORDER BY order_number ASC");
    $stmt->execute([$school_id]);
    while ($row = $stmt->fetch()) {
        fputcsv($output, [$row['name'], $row['level'], $row['order_number'], $row['status'], $row['created_at']]);
    }
    fclose($output);
    exit;
}

// ---------- COUNT STATS ----------
$stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND status='active'");
$stmt->execute([$school_id]);
$total_active = (int)$stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND status='archived'");
$stmt->execute([$school_id]);
$total_archived = (int)$stmt->fetchColumn();

include_once __DIR__ . '/../includes/header.php';

// Flash messages
if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:12px 18px;border-radius:10px;margin-bottom:16px;">✅ ' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 18px;border-radius:10px;margin-bottom:16px;">❌ ' . $_SESSION['error'] . '</div>';
    unset($_SESSION['error']);
}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">🏫 Manage Classes</h1>
        <p style="color: #64748b; margin: 0;">Create, edit, and organize classes for your school.</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#classModal" style="background: linear-gradient(135deg, #7c3aed, #6d28d9); border: none; border-radius: 10px; padding: 10px 20px; font-weight: 600;">
            <i class="fas fa-plus-circle"></i> Add Class
        </button>
        <a href="?export=csv" class="btn btn-outline-success" style="border-radius: 10px; padding: 10px 20px; border-color: #86efac; color: #16a34a;">
            <i class="fas fa-file-export"></i> Export CSV
        </a>
    </div>
</div>

<!-- Stats Row -->
<div style="display: flex; gap: 20px; margin-bottom: 20px;">
    <div style="background: #fff; padding: 12px 24px; border-radius: 12px; border: 1px solid #f1f5f9;">
        <span style="font-size: 24px; font-weight: 700; color: #0f172a;"><?php echo $total_active; ?></span>
        <span style="font-size: 14px; color: #64748b; margin-left: 6px;">Active Classes</span>
    </div>
    <div style="background: #fff; padding: 12px 24px; border-radius: 12px; border: 1px solid #f1f5f9;">
        <span style="font-size: 24px; font-weight: 700; color: #94a3b8;"><?php echo $total_archived; ?></span>
        <span style="font-size: 14px; color: #64748b; margin-left: 6px;">Archived</span>
    </div>
</div>

<!-- Live Search -->
<div class="mb-4" style="background: #fff; border-radius: 16px; padding: 16px 20px; border: 1px solid #f1f5f9;">
    <div class="input-group" style="max-width: 500px;">
        <span class="input-group-text" style="background: white; border-right: none; border-radius: 12px 0 0 12px; color: #94a3b8;"><i class="fas fa-search"></i></span>
        <input type="text" id="liveSearch" class="form-control" placeholder="Search by class name or level..." style="border-left: none; border-radius: 0 12px 12px 0; padding: 12px 16px; border-color: #e2e8f0;">
    </div>
    <div id="searchStatus" style="font-size: 13px; color: #94a3b8; margin-top: 8px;"><span id="resultCount">Loading...</span></div>
</div>

<!-- Classes Table -->
<div style="background: #ffffff; border-radius: 16px; overflow: hidden; border: 1px solid rgba(0,0,0,0.02); box-shadow: 0 4px 16px rgba(0,0,0,0.02);">
    <div style="overflow-x: auto;">
        <table class="table" style="margin-bottom: 0; min-width: 600px;">
            <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <tr>
                    <th>Class Name</th>
                    <th>Level</th>
                    <th>Order</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody id="classTableBody"></tbody>
        </table>
    </div>
    <div id="emptyMessage" style="display: none; padding: 40px; text-align: center; color: #94a3b8;"><i class="fas fa-school" style="font-size: 48px; display: block; margin-bottom: 12px; opacity: 0.3;"></i> No classes found.</div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="classModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,0.15);">
            <div class="modal-header" style="border-bottom: 1px solid #f1f5f9; padding: 20px 28px;">
                <h5 class="modal-title fw-bold" id="modalTitle">Add New Class</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <?php echo csrfField(); ?>
                <div class="modal-body" style="padding: 28px;">
                    <input type="hidden" name="edit_id" id="edit_id" value="0">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Class Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="class_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Level <span class="text-danger">*</span></label>
                        <select name="level" id="class_level" class="form-control" required>
                            <option value="KG">KG</option>
                            <option value="Nursery">Nursery</option>
                            <option value="Primary" selected>Primary</option>
                            <option value="Junior Secondary">Junior Secondary</option>
                            <option value="Senior Secondary">Senior Secondary</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Order Number</label>
                        <input type="number" name="order_number" id="order_number" class="form-control" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg,#7c3aed,#6d28d9); border:none;">Save Class</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Archive Confirmation Modal -->
<div class="modal fade" id="archiveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content" style="border-radius: 16px; border: none;">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" style="color: #dc2626;">⚠️ Confirm Archive</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to archive this class?</p>
                <input type="hidden" id="archive_id" value="">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmArchiveBtn" class="btn btn-warning">Archive</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('liveSearch');
    const tableBody = document.getElementById('classTableBody');
    const emptyMsg = document.getElementById('emptyMessage');
    const resultCount = document.getElementById('resultCount');
    let debounceTimer;

    function loadClasses(searchTerm = '') {
        const url = '?ajax=search&search=' + encodeURIComponent(searchTerm);
        fetch(url)
            .then(res => res.json())
            .then(data => {
                tableBody.innerHTML = '';
                if (data.length === 0) {
                    emptyMsg.style.display = 'block';
                    resultCount.textContent = '0 classes found';
                    return;
                }
                emptyMsg.style.display = 'none';
                resultCount.textContent = data.length + ' classes found';
                data.forEach(cls => {
                    const row = document.createElement('tr');
                    row.style.borderBottom = '1px solid #f1f5f9';
                    const statusText = cls.status === 'active' ? 'Active' : 'Archived';
                    const statusBadge = `<span class="badge" style="background:${cls.status === 'active' ? '#dcfce7' : '#fef3c7'};color:${cls.status === 'active' ? '#166534' : '#92400e'};">${statusText}</span>`;
                    row.innerHTML = `
                        <td>${cls.name}</td>
                        <td>${cls.level}</td>
                        <td>${cls.order_number}</td>
                        <td>${statusBadge}</td>
                        <td style="text-align:right;">
                            <button class="btn btn-sm btn-outline-primary" onclick="editClass(${cls.id}, '${cls.name}', '${cls.level}', ${cls.order_number})"><i class="fas fa-edit"></i></button>
                            ${cls.status === 'active' 
                                ? `<button class="btn btn-sm btn-outline-warning" onclick="confirmArchive(${cls.id})"><i class="fas fa-archive"></i></button>`
                                : `<a href="?reactivate=${cls.id}" class="btn btn-sm btn-outline-success" onclick="return confirm('Reactivate this class?')"><i class="fas fa-undo"></i></a>`
                            }
                        </td>
                    `;
                    tableBody.appendChild(row);
                });
            })
            .catch(error => console.error('Error:', error));
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => loadClasses(this.value.trim()), 300);
    });
    loadClasses();

    window.editClass = function(id, name, level, order) {
        document.getElementById('edit_id').value = id;
        document.getElementById('class_name').value = name;
        document.getElementById('class_level').value = level;
        document.getElementById('order_number').value = order;
        document.getElementById('modalTitle').textContent = '✏️ Edit Class';
        new bootstrap.Modal(document.getElementById('classModal')).show();
    };

    window.confirmArchive = function(id) {
        document.getElementById('archive_id').value = id;
        document.getElementById('confirmArchiveBtn').href = '?archive=' + id;
        new bootstrap.Modal(document.getElementById('archiveModal')).show();
    };

    document.getElementById('classModal').addEventListener('show.bs.modal', function() {
        if (document.getElementById('edit_id').value === '0') {
            document.getElementById('modalTitle').textContent = '➕ Add New Class';
            document.getElementById('class_name').value = '';
            document.getElementById('class_level').value = 'Primary';
            document.getElementById('order_number').value = '0';
        }
    });

    document.getElementById('classModal').addEventListener('hidden.bs.modal', function() {
        loadClasses(searchInput.value.trim());
    });
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>