<?php
/**
 * parent/index.php — Parent Dashboard v3
 * Fixes: N+1 queries, always-current-term, better alerts, download links.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/parent_auth.php';
require_once __DIR__ . '/../includes/parent_helpers.php';

$parent = requireParent();
$parent_id = (int)$parent['id'];
$school_id = (int)$parent['school_id'];
$db = getDB();

// ---------------------------------------------
// School + term info
// ---------------------------------------------
$schoolStmt = $db->prepare("SELECT current_term, current_session, school_name FROM schools WHERE id = ?");
$schoolStmt->execute([$school_id]);
$school = $schoolStmt->fetch();

// ALWAYS use current term — ignore any URL parameters
$current_term    = $school['current_term']    ?? 'Term 1';
$current_session = $school['current_session'] ?? date('Y') . '/' . (date('Y') + 1);

// ---------------------------------------------
// Children
// ---------------------------------------------
$students = getChildrenForParent($parent_id, $school_id);

if (empty($students)) {
    parentAbort(404, 'No active children found linked to your account.');
}

// ---------------------------------------------
// Batch load invoices + attendance for ALL children in 2 queries
// ---------------------------------------------
$studentIds = array_column($students, 'id');
$placeholders = implode(',', array_fill(0, count($studentIds), '?'));

// Invoices for current term
$invStmt = $db->prepare("
    SELECT student_id, total_amount, paid_amount, (total_amount - paid_amount) AS balance, status
    FROM invoices
    WHERE student_id IN ($placeholders)
      AND school_id = ?
      AND term_name = ?
      AND session_year = ?
");
$invStmt->execute(array_merge($studentIds, [$school_id, $current_term, $current_session]));
$invoicesByStudent = [];
while ($row = $invStmt->fetch()) {
    $invoicesByStudent[(int)$row['student_id']] = $row;
}

// All invoices for these children (for total owing across all terms)
$allInvStmt = $db->prepare("
    SELECT student_id, SUM(total_amount - paid_amount) AS total_owing
    FROM invoices
    WHERE student_id IN ($placeholders) AND school_id = ? AND (total_amount - paid_amount) > 0
    GROUP BY student_id
");
$allInvStmt->execute(array_merge($studentIds, [$school_id]));
$totalOwingByStudent = [];
while ($row = $allInvStmt->fetch()) {
    $totalOwingByStudent[(int)$row['student_id']] = (float)$row['total_owing'];
}

// Attendance summary for current term
$attStmt = $db->prepare("
    SELECT student_id, status, COUNT(*) AS cnt
    FROM attendance_log
    WHERE student_id IN ($placeholders)
      AND school_id = ?
      AND term_name = ?
      AND session_year = ?
    GROUP BY student_id, status
");
$attStmt->execute(array_merge($studentIds, [$school_id, $current_term, $current_session]));
$attendanceByStudent = [];
while ($row = $attStmt->fetch()) {
    $sid = (int)$row['student_id'];
    if (!isset($attendanceByStudent[$sid])) {
        $attendanceByStudent[$sid] = ['Present' => 0, 'Absent' => 0, 'Late' => 0, 'Excused' => 0];
    }
    if (isset($attendanceByStudent[$sid][$row['status']])) {
        $attendanceByStudent[$sid][$row['status']] = (int)$row['cnt'];
    }
}

// Merge into students
foreach ($students as &$s) {
    $sid = (int)$s['id'];
    $inv = $invoicesByStudent[$sid] ?? null;
    $s['balance_current'] = $inv ? (float)$inv['balance'] : null;
    $s['balance_total']   = $totalOwingByStudent[$sid] ?? 0;
    $s['attendance']      = $attendanceByStudent[$sid] ?? ['Present' => 0, 'Absent' => 0];
}
unset($s);

// ---------------------------------------------
// Active child
// ---------------------------------------------
$activeChildId = isset($_GET['child_id']) ? (int)$_GET['child_id'] : (int)$students[0]['id'];
$activeChild = null;
foreach ($students as $s) {
    if ((int)$s['id'] === $activeChildId) { $activeChild = $s; break; }
}
if (!$activeChild) $activeChild = $students[0];

// ---------------------------------------------
// Greeting
// ---------------------------------------------
$hour = (int)date('G');
if ($hour < 12) $greeting = 'Good morning';
elseif ($hour < 17) $greeting = 'Good afternoon';
else $greeting = 'Good evening';

$parentFullName = $activeChild['parent_name'] ?? '';
if (empty($parentFullName) && !empty($parent['parent_email'])) {
    $parentFullName = ucfirst(explode('@', $parent['parent_email'])[0]);
}
if (empty($parentFullName)) $parentFullName = 'Parent';

// ---------------------------------------------
// Alerts (both fees AND announcement — stack them)
// ---------------------------------------------
$alerts = [];

// Alert 1: Fees
$totalOwing = 0;
foreach ($students as $s) $totalOwing += (float)$s['balance_total'];

if ($totalOwing > 0) {
    $alerts[] = [
        'type'    => 'danger',
        'icon'    => 'fa-exclamation-triangle',
        'color'   => '#dc2626',
        'title'   => 'Outstanding Fees',
        'message' => 'You have an outstanding balance of ' . formatCurrency($totalOwing) . ' across your children.',
        'link'    => 'fees.php?child_id=' . $activeChild['id'],
        'link_text' => 'View Fees',
    ];
}

// Alert 2: Latest announcement (last 7 days)
$annStmt = $db->prepare("
    SELECT id, title FROM announcements
    WHERE school_id = ? AND is_published = 1
      AND (expires_at IS NULL OR expires_at >= CURDATE())
      AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY created_at DESC LIMIT 1
");
$annStmt->execute([$school_id]);
$recentAnn = $annStmt->fetch();

if ($recentAnn) {
    $alerts[] = [
        'type'    => 'info',
        'icon'    => 'fa-bullhorn',
        'color'   => '#2563eb',
        'title'   => 'New Announcement',
        'message' => $recentAnn['title'],
        'link'    => 'announcements.php',
        'link_text' => 'Read',
    ];
}

// Recent receipts (last 3)
$recStmt = $db->prepare("
    SELECT ph.id, ph.receipt_number, ph.amount_paid, ph.payment_date, s.first_name
    FROM payment_history ph
    JOIN invoices i ON ph.invoice_id = i.id
    JOIN students s ON i.student_id = s.id
    WHERE s.id = ? AND i.school_id = ?
    ORDER BY ph.payment_date DESC
    LIMIT 3
");
$recStmt->execute([$activeChild['id'], $school_id]);
$recentReceipts = $recStmt->fetchAll();

// Latest announcements for widget
$childLevels = ['All'];
foreach ($students as $c) {
    if (!empty($c['class_level'])) $childLevels[] = $c['class_level'];
}
$childLevels = array_unique($childLevels);
$ph = implode(',', array_fill(0, count($childLevels), '?'));
$annStmt = $db->prepare("
    SELECT id, title, message, created_at FROM announcements
    WHERE school_id = ? AND is_published = 1
      AND class_level IN ($ph)
      AND (expires_at IS NULL OR expires_at >= CURDATE())
    ORDER BY created_at DESC LIMIT 2
");
$annStmt->execute(array_merge([$school_id], $childLevels));
$latestAnnouncements = $annStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['school_name'] ?? 'Parent Portal'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f1f5f9; min-height: 100vh; }
        .container { max-width: 450px; margin: 0 auto; padding: 0 16px 30px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; padding: 20px 0 4px; }
        .topbar .school-name { font-weight: 700; font-size: 0.9rem; color: #64748b; }
        .topbar .logout { background: white; color: #dc2626; padding: 8px 14px; border-radius: 10px; font-size: 0.8rem; font-weight: 600; text-decoration: none; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .greeting { padding: 8px 0 16px; }
        .greeting .hello { font-size: 1.5rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
        .greeting .sub { font-size: 0.85rem; color: #64748b; margin-top: 4px; }
        .alert-banner { border-radius: 16px; padding: 16px 18px; display: flex; align-items: center; gap: 14px; margin-bottom: 12px; color: white; }
        .alert-banner .banner-icon { width: 44px; height: 44px; border-radius: 12px; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0; }
        .alert-banner .banner-body { flex: 1; min-width: 0; }
        .alert-banner .banner-title { font-weight: 800; font-size: 0.9rem; margin-bottom: 2px; }
        .alert-banner .banner-msg { font-size: 0.78rem; opacity: 0.92; line-height: 1.4; }
        .alert-banner .banner-link { background: rgba(255,255,255,0.25); color: white; padding: 6px 14px; border-radius: 8px; font-size: 0.72rem; font-weight: 700; text-decoration: none; white-space: nowrap; }
        .chips-scroll { display: flex; gap: 10px; overflow-x: auto; padding: 8px 0 4px; margin: 0 -16px; padding-left: 16px; padding-right: 16px; scrollbar-width: none; position: relative; }
        .chips-scroll::-webkit-scrollbar { display: none; }
        .chip { display: flex; align-items: center; gap: 10px; background: white; border: 2px solid #e2e8f0; border-radius: 50px; padding: 6px 16px 6px 6px; text-decoration: none; color: #0f172a; transition: 0.15s; flex-shrink: 0; }
        .chip:hover { border-color: #c4b5fd; }
        .chip.active { background: linear-gradient(135deg, #7c3aed, #4f46e5); border-color: #7c3aed; color: white; }
        .chip.active .chip-name, .chip.active .chip-class { color: white; }
        .chip img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid #f1f5f9; }
        .chip.active img { border-color: rgba(255,255,255,0.4); }
        .chip-text { min-width: 0; }
        .chip-name { font-weight: 700; font-size: 0.82rem; white-space: nowrap; }
        .chip-class { font-size: 0.68rem; color: #94a3b8; white-space: nowrap; }
        .child-card { background: linear-gradient(135deg, #7c3aed, #4f46e5); border-radius: 20px; padding: 20px; margin-top: 8px; display: flex; align-items: center; gap: 16px; color: white; }
        .child-card img { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 3px solid rgba(255,255,255,0.5); flex-shrink: 0; }
        .child-card .name { font-size: 1.1rem; font-weight: 800; }
        .child-card .class { font-size: 0.8rem; opacity: 0.8; }
        .stats-row { display: flex; gap: 10px; margin-top: 12px; }
        .stat-box { background: white; border-radius: 14px; padding: 14px 10px; flex: 1; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        .stat-box .num { font-size: 1.15rem; font-weight: 800; }
        .stat-box .lbl { font-size: 0.68rem; color: #64748b; font-weight: 600; margin-top: 2px; }
        .num-green { color: #16a34a; }
        .num-red { color: #dc2626; }
        .num-blue { color: #2563eb; }
        .menu-list { margin-top: 20px; display: flex; flex-direction: column; gap: 8px; }
        .menu-item { background: white; border-radius: 14px; padding: 16px; display: flex; align-items: center; gap: 14px; text-decoration: none; box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: transform 0.15s; }
        .menu-item:active { transform: scale(0.98); }
        .menu-item .icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .icon-green { background: #dcfce7; color: #16a34a; }
        .icon-blue { background: #dbeafe; color: #2563eb; }
        .icon-purple { background: #ede9fe; color: #7c3aed; }
        .icon-orange { background: #ffedd5; color: #ea580c; }
        .icon-pink { background: #fce7f3; color: #db2777; }
        .icon-cyan { background: #cffafe; color: #0891b2; }
        .menu-item .label { font-weight: 700; color: #0f172a; font-size: 0.9rem; }
        .menu-item .sub-label { font-size: 0.72rem; color: #94a3b8; margin-top: 2px; font-weight: 500; }
        .menu-item .arrow { margin-left: auto; color: #cbd5e1; font-size: 0.8rem; }
        .section-title { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; font-weight: 700; margin: 20px 0 10px; }
        .ann-mini { background: white; border-radius: 14px; padding: 16px; margin-bottom: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border-left: 4px solid #7c3aed; }
        .ann-mini .t { font-weight: 700; color: #0f172a; font-size: 0.9rem; margin-bottom: 4px; }
        .ann-mini .d { font-size: 0.72rem; color: #94a3b8; margin-bottom: 8px; }
        .ann-mini .b { color: #475569; font-size: 0.82rem; line-height: 1.5; }
        .view-all-link { display: block; text-align: center; padding: 10px; font-size: 0.8rem; color: #7c3aed; text-decoration: none; font-weight: 600; }
        .receipt-mini { background: white; border-radius: 12px; padding: 12px 14px; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 6px rgba(0,0,0,0.04); text-decoration: none; color: inherit; }
        .receipt-mini .r-name { font-weight: 600; font-size: 0.82rem; }
        .receipt-mini .r-date { font-size: 0.7rem; color: #94a3b8; }
        .receipt-mini .r-amt { font-weight: 700; font-size: 0.9rem; color: #16a34a; }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <div class="school-name">🏫 <?php echo htmlspecialchars($school['school_name'] ?? ''); ?></div>
        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="greeting">
        <div class="hello"><?php echo htmlspecialchars($greeting); ?>, <?php echo htmlspecialchars($parentFullName); ?> 👋</div>
        <div class="sub">Here's what's happening with your children today.</div>
    </div>

    <?php foreach ($alerts as $alert): ?>
        <div class="alert-banner" style="background: linear-gradient(135deg, <?php echo $alert['color']; ?>, <?php echo $alert['color']; ?>dd);">
            <div class="banner-icon"><i class="fas <?php echo $alert['icon']; ?>"></i></div>
            <div class="banner-body">
                <div class="banner-title"><?php echo htmlspecialchars($alert['title']); ?></div>
                <div class="banner-msg"><?php echo htmlspecialchars($alert['message']); ?></div>
            </div>
            <?php if (!empty($alert['link'])): ?>
                <a href="<?php echo $alert['link']; ?>" class="banner-link"><?php echo htmlspecialchars($alert['link_text']); ?></a>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($activeChild): ?>

        <?php if (count($students) > 1): ?>
            <div class="chips-scroll">
                <?php foreach ($students as $s):
                    $chipPhoto = ($s['photo_path'] && $s['photo_path'] !== 'default_student.png')
                        ? BASE_URL . 'uploads/student_photos/' . $s['photo_path']
                        : BASE_URL . 'assets/images/default_avatar.png';
                    $isActive = ((int)$s['id'] === (int)$activeChild['id']);
                ?>
                    <a href="?child_id=<?php echo (int)$s['id']; ?>" class="chip <?php echo $isActive ? 'active' : ''; ?>">
                        <img src="<?php echo $chipPhoto; ?>" alt="">
                        <div class="chip-text">
                            <div class="chip-name"><?php echo htmlspecialchars($s['first_name']); ?></div>
                            <div class="chip-class"><?php echo htmlspecialchars($s['class_name']); ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="child-card">
            <?php 
                $photo = ($activeChild['photo_path'] && $activeChild['photo_path'] !== 'default_student.png') 
                    ? BASE_URL . 'uploads/student_photos/' . $activeChild['photo_path'] 
                    : BASE_URL . 'assets/images/default_avatar.png';
            ?>
            <img src="<?php echo $photo; ?>" alt="Child">
            <div>
                <div class="name"><?php echo htmlspecialchars($activeChild['first_name'] . ' ' . $activeChild['last_name']); ?></div>
                <div class="class"><?php echo htmlspecialchars($activeChild['class_name']); ?></div>
            </div>
        </div>

        <div class="stats-row">
            <div class="stat-box">
                <div class="num num-green"><?php echo $activeChild['attendance']['Present']; ?></div>
                <div class="lbl">Present<br>(<?php echo htmlspecialchars($current_term); ?>)</div>
            </div>
            <div class="stat-box">
                <div class="num num-red"><?php echo $activeChild['attendance']['Absent']; ?></div>
                <div class="lbl">Absent<br>(<?php echo htmlspecialchars($current_term); ?>)</div>
            </div>
            <div class="stat-box">
                <?php if ($activeChild['balance_current'] === null): ?>
                    <div class="num num-blue">—</div>
                    <div class="lbl">Fees<br>(not billed)</div>
                <?php elseif ($activeChild['balance_current'] <= 0): ?>
                    <div class="num num-green">✓</div>
                    <div class="lbl">Fees<br>Paid</div>
                <?php else: ?>
                    <div class="num num-red"><?php echo formatCurrency($activeChild['balance_current']); ?></div>
                    <div class="lbl">Owing<br>(this term)</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($recentReceipts)): ?>
            <div class="section-title">🧾 Recent Receipts</div>
            <?php foreach ($recentReceipts as $r): ?>
                <a href="download_receipt.php?payment_id=<?php echo (int)$r['id']; ?>&child_id=<?php echo (int)$activeChild['id']; ?>" class="receipt-mini">
                    <div>
                        <div class="r-name"><?php echo htmlspecialchars($r['first_name']); ?></div>
                        <div class="r-date"><?php echo parentDate($r['payment_date']); ?> · <?php echo htmlspecialchars($r['receipt_number']); ?></div>
                    </div>
                    <div class="r-amt"><?php echo formatCurrency((float)$r['amount_paid']); ?></div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($latestAnnouncements)): ?>
            <div class="section-title">📢 Latest Announcements</div>
            <?php foreach ($latestAnnouncements as $ann): ?>
                <div class="ann-mini">
                    <div class="t"><?php echo htmlspecialchars($ann['title']); ?></div>
                    <div class="d"><?php echo parentDate($ann['created_at']); ?></div>
                    <div class="b"><?php echo htmlspecialchars(substr($ann['message'], 0, 180)) . (strlen($ann['message']) > 180 ? '...' : ''); ?></div>
                </div>
            <?php endforeach; ?>
            <a href="announcements.php" class="view-all-link">View All Announcements →</a>
        <?php endif; ?>

        <div class="menu-list">
            <a href="attendance.php?child_id=<?php echo (int)$activeChild['id']; ?>" class="menu-item">
                <div class="icon icon-green"><i class="fas fa-calendar-check"></i></div>
                <div>
                    <div class="label">Attendance</div>
                    <div class="sub-label"><?php echo $activeChild['attendance']['Present']; ?> present · <?php echo $activeChild['attendance']['Absent']; ?> absent this term</div>
                </div>
                <i class="fas fa-chevron-right arrow"></i>
            </a>
            <a href="scores.php?child_id=<?php echo (int)$activeChild['id']; ?>" class="menu-item">
                <div class="icon icon-blue"><i class="fas fa-chart-line"></i></div>
                <div>
                    <div class="label">Results</div>
                    <div class="sub-label">Current term scores</div>
                </div>
                <i class="fas fa-chevron-right arrow"></i>
            </a>
            <a href="report_card.php?child_id=<?php echo (int)$activeChild['id']; ?>" class="menu-item">
                <div class="icon icon-purple"><i class="fas fa-file-alt"></i></div>
                <div>
                    <div class="label">Report Card</div>
                    <div class="sub-label"><?php echo htmlspecialchars($current_term); ?></div>
                </div>
                <i class="fas fa-chevron-right arrow"></i>
            </a>
            <a href="fees.php?child_id=<?php echo (int)$activeChild['id']; ?>" class="menu-item">
                <div class="icon icon-orange"><i class="fas fa-wallet"></i></div>
                <div>
                    <div class="label">Fees & Payments</div>
                    <div class="sub-label"><?php echo $activeChild['balance_total'] > 0 ? 'Owing: ' . formatCurrency($activeChild['balance_total']) : 'All clear'; ?></div>
                </div>
                <i class="fas fa-chevron-right arrow"></i>
            </a>
            <a href="history.php?child_id=<?php echo (int)$activeChild['id']; ?>" class="menu-item">
                <div class="icon icon-cyan"><i class="fas fa-clock-rotate-left"></i></div>
                <div>
                    <div class="label">History</div>
                    <div class="sub-label">Past report cards & receipts</div>
                </div>
                <i class="fas fa-chevron-right arrow"></i>
            </a>
            <a href="announcements.php" class="menu-item">
                <div class="icon icon-pink"><i class="fas fa-bullhorn"></i></div>
                <div>
                    <div class="label">Announcements</div>
                    <div class="sub-label">School news & notices</div>
                </div>
                <i class="fas fa-chevron-right arrow"></i>
            </a>
        </div>

    <?php endif; ?>
</div>
</body>
</html>