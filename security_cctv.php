<?php
// school_owner/security_cctv.php - Security events monitor (PDO version)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id   = (int)$_SESSION['user_id'];

// Patterns that make an activity_log row "security related"
$SECURITY_ACTIONS = [
    'LOGIN', 'LOGOUT', 'FAILED', 'LOCK', 'PERMISSION',
    'RESET_PASSWORD', 'REQUEST_PERMISSION', 'APPROVE_PERMISSION',
    'DENY_PERMISSION', 'ARCHIVE', 'DEACTIVATE', 'RESTORE'
];

// ---------- AJAX SEARCH ----------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json');

    $search     = trim($_GET['search'] ?? '');
    $event_type = trim($_GET['event_type'] ?? '');
    $date_from  = trim($_GET['date_from'] ?? '');
    $date_to    = trim($_GET['date_to'] ?? '');

    $sql = "SELECT al.id, al.action, al.details, al.ip_address, al.user_agent, 
                   al.created_at, al.user_role,
                   u.full_name AS user_full_name
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.school_id = ?";
    $params = [$school_id];

    // Only security-related actions
    $placeholders = implode(' OR ', array_fill(0, count($SECURITY_ACTIONS), 'al.action LIKE ?'));
    $sql .= " AND ($placeholders)";
    foreach ($SECURITY_ACTIONS as $a) {
        $params[] = '%' . $a . '%';
    }

    if ($search !== '') {
        $sql .= " AND (al.action LIKE ? OR al.details LIKE ? OR al.ip_address LIKE ? 
                       OR u.full_name LIKE ? OR al.user_role LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }

    if ($event_type !== '') {
        $sql .= " AND al.action = ?";
        $params[] = $event_type;
    }

    if ($date_from !== '' && strtotime($date_from) !== false) {
        $sql .= " AND DATE(al.created_at) >= ?";
        $params[] = $date_from;
    }
    if ($date_to !== '' && strtotime($date_to) !== false) {
        $sql .= " AND DATE(al.created_at) <= ?";
        $params[] = $date_to;
    }

    $sql .= " ORDER BY al.created_at DESC LIMIT 200";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Escape output fields
        foreach ($rows as &$r) {
            $r['action'] = htmlspecialchars($r['action'] ?? '', ENT_QUOTES);
            $r['details'] = htmlspecialchars($r['details'] ?? '', ENT_QUOTES);
            $r['ip_address'] = htmlspecialchars($r['ip_address'] ?? '', ENT_QUOTES);
            $r['user_role'] = htmlspecialchars($r['user_role'] ?? 'System', ENT_QUOTES);
            $r['user_full_name'] = htmlspecialchars($r['user_full_name'] ?? 'System', ENT_QUOTES);
        }

        echo json_encode($rows);
    } catch (Exception $e) {
        error_log("Security CCTV AJAX error: " . $e->getMessage());
        echo json_encode(['error' => 'Could not load events.']);
    }
    exit;
}

// ---------- EXPORT CSV ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $sql = "SELECT al.action, al.details, al.ip_address, al.user_role, al.created_at,
                   u.full_name AS user_full_name
            FROM activity_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.school_id = ?";
    $params = [$school_id];
    $placeholders = implode(' OR ', array_fill(0, count($SECURITY_ACTIONS), 'al.action LIKE ?'));
    $sql .= " AND ($placeholders)";
    foreach ($SECURITY_ACTIONS as $a) {
        $params[] = '%' . $a . '%';
    }
    $sql .= " ORDER BY al.created_at DESC LIMIT 5000";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="security_cctv_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date/Time', 'Event Type', 'User', 'Role', 'Details', 'IP Address']);
    foreach ($logs as $row) {
        fputcsv($out, [
            $row['created_at'],
            $row['action'],
            $row['user_full_name'] ?? 'System',
            $row['user_role'] ?? 'System',
            $row['details'] ?? '',
            $row['ip_address'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// ---------- EVENT TYPES FOR FILTER ----------
$event_types = [];
try {
    $sql = "SELECT DISTINCT action FROM activity_log WHERE school_id = ?";
    $params = [$school_id];
    $placeholders = implode(' OR ', array_fill(0, count($SECURITY_ACTIONS), 'action LIKE ?'));
    $sql .= " AND ($placeholders) ORDER BY action";
    foreach ($SECURITY_ACTIONS as $a) {
        $params[] = '%' . $a . '%';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $event_types = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $event_types = [];
}

// ---------- STATS ----------
$stats = ['total' => 0, 'today' => 0, 'failed' => 0];
try {
    // Total security events
    $sql = "SELECT COUNT(*) FROM activity_log WHERE school_id = ?";
    $params = [$school_id];
    $placeholders = implode(' OR ', array_fill(0, count($SECURITY_ACTIONS), 'action LIKE ?'));
    $sql .= " AND ($placeholders)";
    foreach ($SECURITY_ACTIONS as $a) $params[] = '%' . $a . '%';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $stats['total'] = (int)$stmt->fetchColumn();

    // Today's events
    $sql = "SELECT COUNT(*) FROM activity_log WHERE school_id = ? AND DATE(created_at) = CURDATE()";
    $params = [$school_id];
    $sql .= " AND ($placeholders)";
    foreach ($SECURITY_ACTIONS as $a) $params[] = '%' . $a . '%';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $stats['today'] = (int)$stmt->fetchColumn();

    // Failed logins
    $stmt = $db->prepare("SELECT COUNT(*) FROM activity_log WHERE school_id = ? AND action LIKE '%FAILED%'");
    $stmt->execute([$school_id]);
    $stats['failed'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    // silently ignore
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight: 700; color: #0f172a;">🔒 Security CCTV</h1>
        <p style="color: #64748b;">Monitor all security events in your school.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="?export=csv" class="btn btn-outline-success"><i class="fas fa-file-export"></i> Export CSV</a>
        <a href="dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<!-- Stats Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div style="background:#fff; border-radius:12px; padding:14px 18px; border:1px solid #e2e8f0;">
        <div style="font-size:12px; color:#94a3b8;">Total Events</div>
        <div style="font-size:22px; font-weight:700;"><?php echo $stats['total']; ?></div>
    </div>
    <div style="background:#fff; border-radius:12px; padding:14px 18px; border:1px solid #22c55e;">
        <div style="font-size:12px; color:#94a3b8;">Today</div>
        <div style="font-size:22px; font-weight:700; color:#22c55e;"><?php echo $stats['today']; ?></div>
    </div>
    <div style="background:#fff; border-radius:12px; padding:14px 18px; border:1px solid #dc2626;">
        <div style="font-size:12px; color:#94a3b8;">Failed Logins</div>
        <div style="font-size:22px; font-weight:700; color:#dc2626;"><?php echo $stats['failed']; ?></div>
    </div>
</div>

<!-- Filters -->
<div style="background:#fff; border-radius:16px; padding:16px 20px; border:1px solid #f1f5f9; margin-bottom:24px;">
    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:1; min-width:180px;">
            <label class="form-label fw-semibold" style="font-size:12px;">Search</label>
            <input type="text" id="liveSearch" class="form-control" placeholder="Keyword, IP..." style="border-radius:8px;">
        </div>
        <div style="min-width:160px;">
            <label class="form-label fw-semibold" style="font-size:12px;">Event Type</label>
            <select id="filterEvent" class="form-control" style="border-radius:8px;">
                <option value="">All</option>
                <?php foreach ($event_types as $type): ?>
                    <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="min-width:130px;">
            <label class="form-label fw-semibold" style="font-size:12px;">Date From</label>
            <input type="date" id="filterDateFrom" class="form-control" style="border-radius:8px;">
        </div>
        <div style="min-width:130px;">
            <label class="form-label fw-semibold" style="font-size:12px;">Date To</label>
            <input type="date" id="filterDateTo" class="form-control" style="border-radius:8px;">
        </div>
        <button id="clearBtn" class="btn btn-outline-secondary" style="border-radius:8px; padding:8px 18px;">
            <i class="fas fa-times"></i> Clear
        </button>
    </div>
</div>

<!-- Table -->
<div style="background:#fff; border-radius:16px; overflow:hidden; border:1px solid #f1f5f9;">
    <div style="overflow-x:auto;">
        <table class="table" style="margin-bottom:0;">
            <thead style="background:#f8fafc;">
                <tr>
                    <th style="padding:12px 16px;">Date/Time</th>
                    <th style="padding:12px 16px;">Event Type</th>
                    <th style="padding:12px 16px;">User</th>
                    <th style="padding:12px 16px;">Details</th>
                    <th style="padding:12px 16px;">IP</th>
                </tr>
            </thead>
            <tbody id="logTableBody"></tbody>
        </table>
    </div>
    <div id="emptyMessage" style="display:none; padding:30px; text-align:center; color:#94a3b8;">No security events found.</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('liveSearch');
    const filterEvent = document.getElementById('filterEvent');
    const filterDateFrom = document.getElementById('filterDateFrom');
    const filterDateTo = document.getElementById('filterDateTo');
    const clearBtn = document.getElementById('clearBtn');
    const tableBody = document.getElementById('logTableBody');
    const emptyMsg = document.getElementById('emptyMessage');
    let timer;

    function loadLogs() {
        const params = new URLSearchParams({
            ajax: 'search',
            search: searchInput.value.trim(),
            event_type: filterEvent.value,
            date_from: filterDateFrom.value,
            date_to: filterDateTo.value
        });
        fetch('?' + params.toString())
            .then(res => res.json())
            .then(data => {
                tableBody.innerHTML = '';
                if (data.error) {
                    emptyMsg.textContent = '⚠️ ' + data.error;
                    emptyMsg.style.display = 'block';
                    return;
                }
                if (!Array.isArray(data) || data.length === 0) {
                    emptyMsg.textContent = 'No security events found.';
                    emptyMsg.style.display = 'block';
                    return;
                }
                emptyMsg.style.display = 'none';
                data.forEach(log => {
                    const row = document.createElement('tr');
                    row.style.borderBottom = '1px solid #f1f5f9';
                    const isDanger = (log.action || '').match(/FAILED|DENIED|LOCK|DEACTIVATE|ARCHIVE/);

                    const dateCell = document.createElement('td');
                    dateCell.style.padding = '12px 16px';
                    dateCell.style.fontSize = '13px';
                    dateCell.textContent = new Date(log.created_at).toLocaleString();
                    row.appendChild(dateCell);

                    const typeCell = document.createElement('td');
                    typeCell.style.padding = '12px 16px';
                    const badge = document.createElement('span');
                    badge.className = 'badge';
                    badge.style.background = isDanger ? '#fee2e2' : '#eef2ff';
                    badge.style.color = isDanger ? '#991b1b' : '#4f46e5';
                    badge.textContent = log.action;
                    typeCell.appendChild(badge);
                    row.appendChild(typeCell);

                    const userCell = document.createElement('td');
                    userCell.style.padding = '12px 16px';
                    userCell.textContent = log.user_full_name || log.user_role || 'System';
                    row.appendChild(userCell);

                    const detailsCell = document.createElement('td');
                    detailsCell.style.padding = '12px 16px';
                    detailsCell.style.maxWidth = '300px';
                    detailsCell.style.wordWrap = 'break-word';
                    detailsCell.textContent = log.details || '';
                    row.appendChild(detailsCell);

                    const ipCell = document.createElement('td');
                    ipCell.style.padding = '12px 16px';
                    ipCell.style.fontFamily = 'monospace';
                    ipCell.textContent = log.ip_address || '';
                    row.appendChild(ipCell);

                    tableBody.appendChild(row);
                });
            })
            .catch(error => {
                console.error('Error:', error);
                emptyMsg.textContent = '⚠️ Error loading data. Please refresh.';
                emptyMsg.style.display = 'block';
            });
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(timer);
        timer = setTimeout(loadLogs, 300);
    });
    filterEvent.addEventListener('change', loadLogs);
    filterDateFrom.addEventListener('change', loadLogs);
    filterDateTo.addEventListener('change', loadLogs);
    clearBtn.addEventListener('click', function() {
        searchInput.value = '';
        filterEvent.value = '';
        filterDateFrom.value = '';
        filterDateTo.value = '';
        loadLogs();
    });
    loadLogs();
});
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>