<?php
/**
 * parent/history.php — Past report cards + receipts.
 * Replaces the need for a term switcher on parent pages.
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

$children = getChildrenForParent($parent_id, $school_id);
if (empty($children)) parentAbort(404, 'No active children.');

$activeChildId = (int)($_GET['child_id'] ?? $children[0]['id']);
$activeChild = null;
foreach ($children as $c) if ((int)$c['id'] === $activeChildId) { $activeChild = $c; break; }
if (!$activeChild) $activeChild = $children[0];

// All terms with scores for this child
$termsStmt = $db->prepare("
    SELECT DISTINCT term_name, session_year
    FROM exam_scores
    WHERE student_id = ? AND school_id = ?
    ORDER BY session_year DESC, term_name DESC
");
$termsStmt->execute([$activeChild['id'], $school_id]);
$reportTerms = $termsStmt->fetchAll();

// All payments for this child
$payStmt = $db->prepare("
    SELECT ph.id, ph.receipt_number, ph.amount_paid, ph.payment_date, ph.payment_method, ph.reversed,
           i.term_name, i.session_year
    FROM payment_history ph
    JOIN invoices i ON ph.invoice_id = i.id
    WHERE i.student_id = ? AND i.school_id = ?
    ORDER BY ph.payment_date DESC
");
$payStmt->execute([$activeChild['id'], $school_id]);
$allPayments = $payStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History — <?php echo htmlspecialchars($activeChild['first_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f1f5f9; min-height: 100vh; }
        .container { max-width: 450px; margin: 0 auto; padding: 0 16px 30px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; padding: 20px 0 4px; }
        .topbar a { color: #7c3aed; text-decoration: none; font-weight: 600; font-size: 0.85rem; }
        .page-title { font-size: 1.5rem; font-weight: 800; color: #0f172a; margin: 16px 0 4px; }
        .page-sub { color: #64748b; font-size: 0.85rem; margin-bottom: 16px; }
        .section-title { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; font-weight: 700; margin: 20px 0 10px; }
        .history-item { background: white; border-radius: 12px; padding: 14px 16px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 6px rgba(0,0,0,0.04); text-decoration: none; color: inherit; }
        .history-item:hover { background: #fafafa; }
        .history-item .info .title { font-weight: 700; color: #0f172a; font-size: 0.9rem; }
        .history-item .info .meta { font-size: 0.72rem; color: #94a3b8; margin-top: 2px; }
        .history-item .action { color: #7c3aed; font-weight: 700; font-size: 0.8rem; }
        .empty { text-align: center; padding: 40px 20px; color: #94a3b8; font-size: 0.85rem; }
        .chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px 6px 6px; background: white; border: 2px solid #e2e8f0; border-radius: 50px; text-decoration: none; color: #0f172a; font-size: 0.8rem; font-weight: 600; margin: 0 6px 6px 0; }
        .chip.active { background: linear-gradient(135deg, #7c3aed, #4f46e5); border-color: #7c3aed; color: white; }
        .chip img { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; }
        .chip.active img { border-color: rgba(255,255,255,0.4); }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <a href="index.php"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    <div class="page-title">📚 History</div>
    <div class="page-sub">Past report cards and payment records</div>

    <?php if (count($children) > 1): ?>
        <div style="margin: 12px 0;">
            <?php foreach ($children as $c):
                $photo = ($c['photo_path'] && $c['photo_path'] !== 'default_student.png')
                    ? BASE_URL . 'uploads/student_photos/' . $c['photo_path']
                    : BASE_URL . 'assets/images/default_avatar.png';
                $isActive = ((int)$c['id'] === (int)$activeChild['id']);
            ?>
                <a href="?child_id=<?php echo (int)$c['id']; ?>" class="chip <?php echo $isActive ? 'active' : ''; ?>">
                    <img src="<?php echo $photo; ?>" alt="">
                    <?php echo htmlspecialchars($c['first_name']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="section-title">📄 Report Cards</div>
    <?php if (empty($reportTerms)): ?>
        <div class="empty">No report cards yet.</div>
    <?php else: ?>
        <?php foreach ($reportTerms as $t): ?>
            <a href="download_report_card.php?child_id=<?php echo (int)$activeChild['id']; ?>&term=<?php echo urlencode($t['term_name']); ?>&session=<?php echo urlencode($t['session_year']); ?>" class="history-item">
                <div class="info">
                    <div class="title"><?php echo htmlspecialchars($t['term_name'] . ' · ' . $t['session_year']); ?></div>
                    <div class="meta">Tap to download PDF</div>
                </div>
                <div class="action"><i class="fas fa-download"></i></div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="section-title">🧾 Payment Receipts</div>
    <?php if (empty($allPayments)): ?>
        <div class="empty">No payment records yet.</div>
    <?php else: ?>
        <?php foreach ($allPayments as $p): ?>
            <a href="download_receipt.php?payment_id=<?php echo (int)$p['id']; ?>&child_id=<?php echo (int)$activeChild['id']; ?>" class="history-item" style="<?php echo $p['reversed'] ? 'background:#fef2f2;' : ''; ?>">
                <div class="info">
                    <div class="title"><?php echo formatCurrency((float)$p['amount_paid']); ?> <?php if ($p['reversed']): ?><span style="font-size:10px;color:#dc2626;font-weight:700;">REVERSED</span><?php endif; ?></div>
                    <div class="meta"><?php echo parentDate($p['payment_date']); ?> · <?php echo htmlspecialchars($p['receipt_number']); ?> · <?php echo htmlspecialchars($p['term_name'] . ' ' . $p['session_year']); ?></div>
                </div>
                <div class="action"><i class="fas fa-download"></i></div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>