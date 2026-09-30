<?php
// school_owner/activity_log.php - School Activity History (PDO + safe output)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal']);

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

$message = '';
$error = '';

// ---------- HANDLE AJAX LIVE SEARCH ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json');

    $search = trim($_GET['search'] ?? '');
    $filter_action = sanitize($_GET['action'] ?? '');
    $filter_user = sanitize($_GET['user'] ?? '');
    $filter_date_from = sanitize($_GET['date_from'] ?? '');
    $filter_date_to = sanitize($_GET['date_to'] ?? '');

    $sql = "SELECT * FROM activity_log WHERE school_id = ?";
    $params = [$school_id];

    if (!empty($search)) {
        $sql .= " AND (action LIKE ? OR details LIKE ? OR user_role LIKE ? OR ip_address LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if (!empty($filter_action)) {
        $sql .= " AND action = ?";
        $params[] = $filter_action;
    }
    if ($filter_user !== '') {
        $sql .= " AND user_id = ?";
        $params[] = (int)$filter_user;
    }
    if (!empty($filter_date_from)) {
        $sql .= " AND DATE(created_at) >= ?";
        $params[] = $filter_date_from;
    }
    if (!empty($filter_date_to)) {
        $sql .= " AND DATE(created_at) <= ?";
        $params[] = $filter_date_to;
    }
    $sql .= " ORDER BY created_at DESC LIMIT 200";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        // XSS-safe: escape values before sending to the browser
        foreach ($logs as &$l) {
            $l['action']    = htmlspecialchars($l['action'] ?? '', ENT_QUOTES);
            $l['details']   = htmlspecialchars($l['details'] ?? '', ENT_QUOTES);
            $l['user_role'] = htmlspecialchars($l['user_role'] ?? 'System', ENT_QUOTES);
            $l['ip_address'] = htmlspecialchars($l['ip_address'] ?? '', ENT_QUOTES);
            $l['user_agent'] = htmlspecialchars($l['user_agent'] ?? '', ENT_QUOTES);
        }
        unset($l);

        echo json_encode($logs);
    } catch (Exception $e) {
        error_log("activity_log AJAX error: " . $e->getMessage());
        echo json_encode([]);
    }
    exit;
}

// ---------- HANDLE EXPORT CSV ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filter_action = sanitize($_GET['action'] ?? '');
    $filter_user = sanitize($_GET['user'] ?? '');
    $filter_date_from = sanitize($_GET['date_from'] ?? '');
    $filter_date_to = sanitize($_GET['date_to'] ?? '');

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="activity_log_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'User', 'Role', 'Action', 'Details', 'IP Address', 'User Agent']);

    $sql = "SELECT * FROM activity_log WHERE school_id = ?";
    $params = [$school_id];
    if (!empty($filter_action)) { $sql .= " AND action = ?"; $params[] = $filter_action; }
    if ($filter_user !== '') { $sql .= " AND user_id = ?"; $params[] = (int)$filter_user; }
    if (!empty($filter_date_from)) { $sql .= " AND DATE(created_at) >= ?"; $params[] = $filter_date_from; }
    if (!empty($filter_date_to)) { $sql .= " AND DATE(created_at) <= ?"; $params[] = $filter_date_to; }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['created_at'],
            $row['user_id'] ? getUserName((int)$row['user_id']) : 'System',
            $row['user_role'],
            $row['action'],
            $row['details'],
            $row['ip_address'],
            $row['user_agent']
        ]);
    }
    fclose($output);
    exit;
}

// ---------- FETCH FILTER OPTIONS ----------
$stmt = $db->prepare("SELECT DISTINCT action FROM activity_log WHERE school_id = ? ORDER BY action");
$stmt->execute([$school_id]);
$actions = $stmt->fetchAll();

$stmt = $db->prepare("SELECT id, full_name FROM users WHERE school_id = ? AND status = 'active' ORDER BY full_name");
$stmt->execute([$school_id]);
$users = $stmt->fetchAll();

// Stats (using bound params)
$stmt = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE school_id = ?");
$stmt->execute([$school_id]);
$total_actions = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE school_id = ? AND DATE(created_at) = CURDATE()");
$stmt->execute([$school_id]);
$today_actions = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE school_id = ? AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)");
$stmt->execute([$school_id]);
$this_week = (int)$stmt->fetchColumn();

// Log this view once per session
if (!isset($_SESSION['activity_log_viewed'])) {
    logActivity('VIEW_ACTIVITY_LOG', 'Viewed school activity log.', $school_id, $user_id);
    $_SESSION['activity_log_viewed'] = true;
}

include_once __DIR__ . '/../includes/header.php';

if ($message) echo '<div class="alert alert-success">' . htmlspecialchars($message) . '</div>';
if ($error) echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">📊 Activity Log</h1>
        <p style="color: #64748b; margin: 0;">Complete history of all actions performed in your school.</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="?export=csv&action=&user=&date_from=&date_to=" class="btn btn-outline-success" style="border-radius: 10px; padding: 10px 20px; border-color: #86efac; color: #16a34a;">
            <i class="fas fa-file-export"></i> Export CSV
        </a>
        <a href="dashboard.php" class="btn btn-outline-secondary" style="border-radius: 10px; padding: 8px 18px;">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<!-- Stats Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div style="background: #ffffff; border-radius: 12px; padding: 14px 18px; border: 1px solid #e2e8f0;">
        <div style="font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;">Total Activities</div>
        <div style="font-size: 22px; font-weight: 700; color: #0f172a;"><?php echo number_format($total_actions); ?></div>
    </div>
    <div style="background: #ffffff; border-radius: 12px; padding: 14px 18px; border: 1px solid #22c55e;">
        <div style="font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;">Today</div>
        <div style="font-size: 22px; font-weight: 700; color: #22c55e;"><?php echo number_format($today_actions); ?></div>
    </div>
    <div style="background: #ffffff; border-radius: 12px; padding: 14px 18px; border: 1px solid #7c3aed;">
        <div style="font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;">This Week</div>
        <div style="font-size: 22px; font-weight: 700; color: #7c3aed;"><?php echo number_format($this_week); ?></div>
    </div>
</div>

<!-- Filters & Search -->
<div style="background: #ffffff; border-radius: 16px; padding: 20px; border: 1px solid #f1f5f9; margin-bottom: 24px;">
    <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex: 1; min-width: 180px;">
            <label class="form-label fw-semibold" style="font-size: 12px; color: #334155;">Search</label>
            <div class="input-group" style="border-radius: 8px;">
                <span class="input-group-text" style="background: white; border-right: none;"><i class="fas fa-search" style="color: #94a3b8;"></i></span>
                <input type="text" id="liveSearch" class="form-control" placeholder="Keyword, user, IP..." style="border-left: none; border-radius: 0 8px 8px 0;">
            </div>
        </div>
        <div style="min-width: 130px;">
            <label class="form-label fw-semibold" style="font-size: 12px; color: #334155;">Action</label>
            <select id="filterAction" class="form-control" style="border-radius: 8px; padding: 8px 12px; border-color: #e2e8f0;">
                <option value="">All Actions</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?php echo htmlspecialchars($a['action']); ?>"><?php echo htmlspecialchars($a['action']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="min-width: 130px;">
            <label class="form-label fw-semibold" style="font-size: 12px; color: #334155;">User</label>
            <select id="filterUser" class="form-control" style="border-radius: 8px; padding: 8px 12px; border-color: #e2e8f0;">
                <option value="">All Users</option>
                <option value="0">System</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="min-width: 130px;">
            <label class="form-label fw-semibold" style="font-size: 12px; color: #334155;">Date From</label>
            <input type="date" id="filterDateFrom" class="form-control" style="border-radius: 8px; padding: 8px 12px; border-color: #e2e8f0;">
        </div>
        <div style="min-width: 130px;">
            <label class="form-label fw-semibold" style="font-size: 12px; color: #334155;">Date To</label>
            <input type="date" id="filterDateTo" class="form-control" style="border-radius: 8px; padding: 8px 12px; border-color: #e2e8f0;">
        </div>
        <div>
            <button id="searchBtn" class="btn btn-primary" style="border-radius: 8px; padding: 8px 20px; background: #7c3aed; border: none; font-weight: 600;">
                <i class="fas fa-search"></i> Search
            </button>
            <button id="clearBtn" class="btn btn-outline-secondary" style="border-radius: 8px; padding: 8px 18px; border-color: #e2e8f0;">
                <i class="fas fa-times"></i> Clear
            </button>
        </div>
    </div>
</div>

<!-- Activity Log Table -->
<div style="background: #ffffff; border-radius: 16px; overflow: hidden; border: 1px solid #f1f5f9; box-shadow: 0 4px 16px rgba(0,0,0,0.02);">
    <div style="overflow-x: auto;">
        <table class="table" style="margin-bottom: 0; min-width: 700px;">
            <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <tr>
                    <th>Date/Time</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Action</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody id="logTableBody"></tbody>
        </table>
    </div>
    <div id="emptyMessage" style="display: none; padding: 30px; text-align: center; color: #94a3b8; font-style: italic;">No activity logs found.</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('liveSearch');
    const filterAction = document.getElementById('filterAction');
    const filterUser = document.getElementById('filterUser');
    const filterDateFrom = document.getElementById('filterDateFrom');
    const filterDateTo = document.getElementById('filterDateTo');
    const searchBtn = document.getElementById('searchBtn');
    const clearBtn = document.getElementById('clearBtn');
    const tableBody = document.getElementById('logTableBody');
    const emptyMsg = document.getElementById('emptyMessage');

    function formatDate(dateString) {
        const d = new Date(dateString);
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString();
    }

    function loadLogs() {
        const search = searchInput.value.trim();
        const action = filterAction.value;
        const user = filterUser.value;
        const date_from = filterDateFrom.value;
        const date_to = filterDateTo.value;
        const url = '?ajax=search&search=' + encodeURIComponent(search) +
                    '&action=' + encodeURIComponent(action) +
                    '&user=' + encodeURIComponent(user) +
                    '&date_from=' + encodeURIComponent(date_from) +
                    '&date_to=' + encodeURIComponent(date_to);
        fetch(url)
            .then(res => res.json())
            .then(data => {
                tableBody.innerHTML = '';
                if (data.length === 0) {
                    emptyMsg.style.display = 'block';
                    return;
                }
                emptyMsg.style.display = 'none';
                data.forEach(log => {
                    const row = document.createElement('tr');
                    row.style.borderBottom = '1px solid #f1f5f9';
                    // Values are already escaped server-side; use textContent to be doubly safe
                    const td1 = document.createElement('td'); td1.textContent = formatDate(log.created_at);
                    const td2 = document.createElement('td'); td2.textContent = log.user_id ? (log.user_role || 'User') : 'System';
                    const td3 = document.createElement('td');
                    const badge1 = document.createElement('span');
                    badge1.className = 'badge';
                    badge1.style.background = '#eef2ff';
                    badge1.style.color = '#4f46e5';
                    badge1.textContent = log.user_role || 'System';
                    td3.appendChild(badge1);
                    const td4 = document.createElement('td');
                    const badge2 = document.createElement('span');
                    badge2.className = 'badge';
                    badge2.style.background = '#f1f5f9';
                    badge2.style.color = '#0f172a';
                    badge2.textContent = log.action;
                    td4.appendChild(badge2);
                    const td5 = document.createElement('td'); td5.textContent = log.details || '';
                    row.appendChild(td1); row.appendChild(td2); row.appendChild(td3); row.appendChild(td4); row.appendChild(td5);
                    tableBody.appendChild(row);
                });
            })
            .catch(error => console.error('Error:', error));
    }

    searchBtn.addEventListener('click', loadLogs);
    searchInput.addEventListener('keypress', function(e) { if (e.key === 'Enter') loadLogs(); });
    filterAction.addEventListener('change', loadLogs);
    filterUser.addEventListener('change', loadLogs);
    filterDateFrom.addEventListener('change', loadLogs);
    filterDateTo.addEventListener('change', loadLogs);
    clearBtn.addEventListener('click', function() {
        searchInput.value = '';
        filterAction.value = '';
        filterUser.value = '';
        filterDateFrom.value = '';
        filterDateTo.value = '';
        loadLogs();
    });
    loadLogs();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>