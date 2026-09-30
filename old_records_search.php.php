<?php
// school_owner/old_records_search.php - Search Teacher Records
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'All';
$role_filter = $_GET['role'] ?? 'All';

$results = [];

if (!empty($search) || $status_filter !== 'All' || $role_filter !== 'All') {
    $sql = "SELECT u.id, u.full_name, u.email, u.phone, u.role, u.status, u.photo_path, u.created_at, u.hire_date, u.last_login
            FROM users u
            WHERE u.school_id = ?
              AND u.role IN ('Teacher', 'Principal', 'Accountant')";

    $params = [$school_id];

    if (!empty($search)) {
        $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    if ($status_filter !== 'All') {
        $sql .= " AND u.status = ?";
        $params[] = $status_filter;
    }

    if ($role_filter !== 'All') {
        $sql .= " AND u.role = ?";
        $params[] = $role_filter;
    }

    $sql .= " ORDER BY u.full_name LIMIT 200";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
}

include_once __DIR__ . '/../includes/header.php';
?>

<style>
    .filter-card { background:#fff; border-radius:16px; padding:20px; border:1px solid #f1f5f9; margin-bottom:20px; }
    .staff-card { background:#fff; border-radius:14px; padding:20px; border:1px solid #f1f5f9; display:flex; align-items:center; gap:16px; margin-bottom:10px; transition: 0.15s; }
    .staff-card:hover { border-color:#c4b5fd; box-shadow: 0 4px 12px rgba(124,58,237,0.06); }
    .staff-card img { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 3px solid #e2e8f0; }
    .staff-info { flex: 1; min-width: 0; }
    .staff-name { font-weight: 700; color: #0f172a; font-size: 1rem; }
    .staff-meta { font-size: 0.8rem; color: #64748b; margin-top: 2px; }
    .staff-tags { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
    .staff-tag { font-size: 0.7rem; padding: 3px 10px; border-radius: 20px; font-weight: 600; }
    .tag-teacher { background: #dbeafe; color: #1e40af; }
    .tag-principal { background: #ede9fe; color: #6d28d9; }
    .tag-accountant { background: #dcfce7; color: #166534; }
    .tag-active { background: #dcfce7; color: #166534; }
    .tag-inactive { background: #f1f5f9; color: #64748b; }
    .staff-actions { display: flex; gap: 8px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700; color:#0f172a;">👥 Teacher Records</h1>
        <p class="text-muted" style="margin:0;">Search and view all staff records — teachers, principals, accountants.</p>
    </div>
    <a href="advanced.php" class="btn btn-outline-secondary" style="border-radius:10px;">
        <i class="fas fa-arrow-left"></i> Back
    </a>
</div>

<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:14px 20px;margin-bottom:20px;">
    <i class="fas fa-info-circle" style="color:#1e40af;"></i>
    <strong style="color:#1e40af;">Tip:</strong>
    <span style="color:#1e3a8a;">Search by name, email, or phone. View full employment history, class assignments, and status.</span>
</div>

<!-- Filters -->
<div class="filter-card">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label fw-semibold">Search</label>
            <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, email, or phone" autofocus>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" class="form-control">
                <option value="All" <?php echo $status_filter === 'All' ? 'selected' : ''; ?>>All</option>
                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label fw-semibold">Role</label>
            <select name="role" class="form-control">
                <option value="All" <?php echo $role_filter === 'All' ? 'selected' : ''; ?>>All Roles</option>
                <option value="Teacher" <?php echo $role_filter === 'Teacher' ? 'selected' : ''; ?>>Teacher</option>
                <option value="Principal" <?php echo $role_filter === 'Principal' ? 'selected' : ''; ?>>Principal</option>
                <option value="Accountant" <?php echo $role_filter === 'Accountant' ? 'selected' : ''; ?>>Accountant</option>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100" style="border-radius:10px;">
                <i class="fas fa-search"></i> Search
            </button>
            <a href="old_records_search.php" class="btn btn-outline-secondary" style="border-radius:10px;">
                <i class="fas fa-times"></i>
            </a>
        </div>
    </form>
</div>

<!-- Results -->
<?php if (!empty($search) || $status_filter !== 'All' || $role_filter !== 'All'): ?>
    <h5 style="font-weight:700; color:#0f172a; margin-bottom:16px;">
        Results (<?php echo count($results); ?>)
    </h5>

    <?php if (empty($results)): ?>
        <div style="background:#fff; border-radius:14px; padding:40px; text-align:center; color:#94a3b8;">
            <i class="fas fa-user-slash" style="font-size:2.5rem; margin-bottom:12px; display:block;"></i>
            <strong>No staff records found.</strong>
            <div style="font-size:0.85rem; margin-top:6px;">Try a different search.</div>
        </div>
    <?php else: ?>
        <?php foreach ($results as $staff):
            $photo = ($staff['photo_path'] && $staff['photo_path'] !== 'default_avatar.png')
                ? BASE_URL . 'uploads/teacher_photos/' . $staff['photo_path']
                : BASE_URL . 'assets/images/default_avatar.png';

            $roleTagClass = 'tag-' . strtolower($staff['role']);
            $statusTagClass = $staff['status'] === 'active' ? 'tag-active' : 'tag-inactive';

            // Get assignment count
            $assignStmt = $db->prepare("
                SELECT COUNT(*) AS assignment_count,
                       GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS class_names
                FROM teacher_subject_assignments tsa
                JOIN classes c ON tsa.class_id = c.id
                WHERE tsa.teacher_id = ? AND tsa.school_id = ?
            ");
            $assignStmt->execute([$staff['id'], $school_id]);
            $assign = $assignStmt->fetch();
            $assignmentCount = (int)($assign['assignment_count'] ?? 0);
            $classNames = $assign['class_names'] ?? '';

            $hireDate = !empty($staff['hire_date']) ? $staff['hire_date'] : $staff['created_at'];
        ?>
            <div class="staff-card">
                <img src="<?php echo $photo; ?>" alt="Photo">
                <div class="staff-info">
                    <div class="staff-name"><?php echo htmlspecialchars($staff['full_name']); ?></div>
                    <div class="staff-meta">
                        <?php echo htmlspecialchars($staff['email']); ?>
                        <?php if (!empty($staff['phone'])): ?>
                            • <?php echo htmlspecialchars($staff['phone']); ?>
                        <?php endif; ?>
                    </div>
                    <div class="staff-tags">
                        <span class="staff-tag <?php echo $roleTagClass; ?>">
                            <?php echo htmlspecialchars($staff['role']); ?>
                        </span>
                        <span class="staff-tag <?php echo $statusTagClass; ?>">
                            <?php echo ucfirst($staff['status']); ?>
                        </span>
                        <?php if ($assignmentCount > 0): ?>
                            <span class="staff-tag" style="background:#f1f5f9; color:#475569;">
                                <?php echo $assignmentCount; ?> assignment<?php echo $assignmentCount !== 1 ? 's' : ''; ?>
                            </span>
                        <?php else: ?>
                            <span class="staff-tag" style="background:#fef3c7; color:#92400e;">
                                No assignments
                            </span>
                        <?php endif; ?>
                        <span class="staff-tag" style="background:#f1f5f9; color:#475569;">
                            Hired: <?php echo date('M Y', strtotime($hireDate)); ?>
                        </span>
                    </div>
                    <?php if (!empty($classNames)): ?>
                        <div class="staff-meta" style="margin-top:6px;">
                            <strong>Classes:</strong> <?php echo htmlspecialchars($classNames); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="staff-actions">
                    <a href="manage_teachers.php?search=<?php echo urlencode($staff['email']); ?>" class="btn btn-sm btn-primary" style="border-radius:8px;">
                        <i class="fas fa-user"></i> View
                    </a>
                    <a href="manage_teachers.php?edit=<?php echo $staff['id']; ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php else: ?>
    <div style="background:#fff; border-radius:14px; padding:60px 20px; text-align:center; color:#94a3b8;">
        <i class="fas fa-search" style="font-size:3rem; margin-bottom:16px; display:block; opacity:0.5;"></i>
        <strong style="font-size:1rem; color:#475569;">Search for a staff member</strong>
        <p style="margin-top:8px; font-size:0.85rem;">Enter a name, email, phone, or choose filters above to see results.</p>
    </div>
<?php endif; ?>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>