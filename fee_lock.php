<?php
// school_owner/fee_lock.php - Fee Lock Management (PDO + CSRF, toggle now POST)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

$message = '';
$error = '';

// ---------- Load principal permissions if Principal ----------
$principal_perms = [];
if ($user_role === 'Principal') {
    $default_perms = [
        'add_students' => 0,
        'edit_students' => 0,
        'delete_students' => 0,
        'add_teachers' => 0,
        'edit_teachers' => 0,
        'manage_fees' => 0,
        'override_fee_lock' => 0,
        'record_payments' => 1,
        'reverse_payments' => 0,
        'set_fee_structures' => 0,
        'generate_invoices' => 1,
    ];
    $permStmt = $db->prepare("SELECT principal_permissions FROM schools WHERE id = ?");
    $permStmt->execute([$school_id]);
    $permJson = $permStmt->fetchColumn();
    if ($permJson) {
        $principal_perms = array_merge($default_perms, json_decode($permJson, true) ?: []);
    } else {
        $principal_perms = $default_perms;
    }
}

// Permission: Owner always can override; Principal only if toggle enabled
$can_override = ($user_role === 'Owner') 
    || ($user_role === 'Principal' && !empty($principal_perms['override_fee_lock']));

// ---------- HANDLE TOGGLE OVERRIDE (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_override'])) {
    if (!$can_override) {
        $_SESSION['error'] = "You do not have permission to override fee locks.";
    } else {
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $action = $_POST['toggle_action'] ?? '';

        $stmt = $db->prepare("SELECT student_id, fee_lock_override FROM invoices WHERE id = ? AND school_id = ?");
        $stmt->execute([$invoice_id, $school_id]);
        $inv = $stmt->fetch();

        if (!$inv) {
            $_SESSION['error'] = "Invoice not found or does not belong to your school.";
        } else {
            $new_override = ($action === 'unlock') ? 1 : 0;
            $stmt = $db->prepare("UPDATE invoices SET fee_lock_override = ? WHERE id = ? AND school_id = ?");
            if ($stmt->execute([$new_override, $invoice_id, $school_id])) {
                logActivity('FEE_LOCK_OVERRIDE', ($new_override ? "Unlocked" : "Locked") . " fee lock for invoice #$invoice_id", $school_id, $user_id);
                $_SESSION['success'] = "✅ Fee lock " . ($new_override ? "unlocked" : "locked") . " successfully.";
            } else {
                $_SESSION['error'] = "Failed to update fee lock status.";
            }
        }
    }
    header("Location: fee_lock.php");
    exit;
}

// ---------- HANDLE BULK UNLOCK (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_unlock'])) {
    if (!$can_override) {
        $_SESSION['error'] = "You do not have permission to unlock fees.";
    } else {
        $term = sanitize($_POST['term'] ?? 'Term 1');
        $session = sanitize($_POST['session'] ?? date('Y') . '/' . (date('Y') + 1));
        $class_id = (int)($_POST['class_id'] ?? 0);

        $sql = "UPDATE invoices 
                SET fee_lock_override = 1 
                WHERE school_id = ? 
                AND balance > 0 
                AND term_name = ? 
                AND session_year = ?";
        $params = [$school_id, $term, $session];

        if ($class_id > 0) {
            $sql .= " AND student_id IN (SELECT id FROM students WHERE class_id = ? AND school_id = ?)";
            array_push($params, $class_id, $school_id);
        }

        $stmt = $db->prepare($sql);
        if ($stmt->execute($params)) {
            $affected = $stmt->rowCount();
            logActivity('BULK_UNLOCK', "Bulk unlocked $affected invoices for term: $term, session: $session", $school_id, $user_id);
            $_SESSION['success'] = "✅ Bulk unlocked $affected invoices.";
        } else {
            $_SESSION['error'] = "Failed to bulk unlock.";
        }
    }
    header("Location: fee_lock.php");
    exit;
}

// ---------- FETCH LOCKED STUDENTS ----------
$stmt = $db->prepare("SELECT i.id AS invoice_id, i.balance, i.fee_lock_override, i.term_name, i.session_year,
                             s.first_name, s.last_name, s.student_id, c.name AS class_name
                      FROM invoices i
                      JOIN students s ON i.student_id = s.id
                      JOIN classes c ON s.class_id = c.id
                      WHERE i.school_id = ?
                      AND i.balance > 0
                      ORDER BY i.balance DESC, s.first_name");
$stmt->execute([$school_id]);
$locked_students = $stmt->fetchAll();
$locked_count = count($locked_students);

$stmt = $db->prepare("SELECT SUM(balance) FROM invoices WHERE school_id = ? AND balance > 0");
$stmt->execute([$school_id]);
$total_balance = $stmt->fetchColumn();

$classes = $db->prepare("SELECT id, name FROM classes WHERE school_id = ? AND status='active' ORDER BY name");
$classes->execute([$school_id]);
$classes = $classes->fetchAll();

include_once __DIR__ . '/../includes/header.php';

if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:16px 20px;border-radius:12px;margin-bottom:24px;">' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    echo '<div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:16px 20px;border-radius:12px;margin-bottom:24px;">❌ ' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">🔒 Fee Lock Management</h1>
        <p style="color: #64748b; margin: 0;">View students with outstanding balances and override the fee lock to allow report card downloads.</p>
    </div>
    <a href="dashboard.php" class="btn btn-outline-secondary" style="border-radius: 10px; padding: 8px 18px;">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</div>

<!-- Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div style="background: #ffffff; border-radius: 12px; padding: 16px 20px; border: 1px solid #e2e8f0;">
        <div style="font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;">Students Owing</div>
        <div style="font-size: 24px; font-weight: 700; color: #dc2626;"><?php echo $locked_count; ?></div>
    </div>
    <div style="background: #ffffff; border-radius: 12px; padding: 16px 20px; border: 1px solid #f59e0b;">
        <div style="font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;">Total Outstanding</div>
        <div style="font-size: 24px; font-weight: 700; color: #f59e0b;"><?php echo formatCurrency($total_balance); ?></div>
    </div>
</div>

<?php if ($can_override): ?>
<!-- Bulk Unlock Form -->
<div style="background: #ffffff; border-radius: 16px; padding: 24px; border: 1px solid #f1f5f9; margin-bottom: 24px;">
    <h5 style="font-weight: 600; color: #0f172a; margin-bottom: 16px;">📦 Bulk Unlock</h5>
    <form method="POST" action="">
        <?php echo csrfField(); ?>
        <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
            <div>
                <label class="form-label fw-semibold" style="font-size: 13px; color: #334155;">Term</label>
                <select name="term" class="form-control" style="border-radius: 8px; padding: 8px 14px; border-color: #e2e8f0;">
                    <option value="Term 1">Term 1</option>
                    <option value="Term 2">Term 2</option>
                    <option value="Term 3">Term 3</option>
                </select>
            </div>
            <div>
                <label class="form-label fw-semibold" style="font-size: 13px; color: #334155;">Session</label>
                <select name="session" class="form-control" style="border-radius: 8px; padding: 8px 14px; border-color: #e2e8f0;">
                    <?php
                    $current_year = date('Y');
                    for ($i = -1; $i <= 2; $i++) {
                        $year = $current_year + $i;
                        $session = $year . '/' . ($year + 1);
                        echo "<option value=\"$session\">$session</option>";
                    }
                    ?>
                </select>
            </div>
            <div>
                <label class="form-label fw-semibold" style="font-size: 13px; color: #334155;">Class</label>
                <select name="class_id" class="form-control" style="border-radius: 8px; padding: 8px 14px; border-color: #e2e8f0;">
                    <option value="0">All Classes</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" name="bulk_unlock" value="1" class="btn btn-warning" style="border-radius: 8px; padding: 8px 20px; font-weight: 600; background: #f59e0b; border: none; color: white;" onclick="return confirm('Unlock all students for this term/session?')">
                    <i class="fas fa-unlock"></i> Bulk Unlock
                </button>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Students Table -->
<div style="background: #ffffff; border-radius: 16px; overflow: hidden; border: 1px solid #f1f5f9; box-shadow: 0 4px 16px rgba(0,0,0,0.02);">
    <div style="overflow-x: auto;">
        <table class="table" style="margin-bottom: 0; min-width: 700px;">
            <thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <tr>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Term / Session</th>
                    <th>Balance</th>
                    <th>Override Status</th>
                    <?php if ($can_override): ?>
                        <th style="text-align: center;">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($locked_count === 0): ?>
                    <tr>
                        <td colspan="<?php echo $can_override ? '6' : '5'; ?>" style="padding: 40px; text-align: center; color: #94a3b8;">
                            No students have outstanding balances.
                        </td>
                    </tr>
                <?php else: foreach ($locked_students as $row): ?>
                    <?php 
                        $is_unlocked = $row['fee_lock_override'] == 1;
                        $status_label = $is_unlocked ? 'Unlocked' : 'Locked';
                        $status_bg = $is_unlocked ? '#dcfce7' : '#fee2e2';
                        $status_color = $is_unlocked ? '#166534' : '#991b1b';
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['first_name'].' '.$row['last_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['class_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['term_name'].' / '.$row['session_year']); ?></td>
                        <td><?php echo formatCurrency($row['balance']); ?></td>
                        <td><span class="badge" style="background:<?php echo $status_bg; ?>; color:<?php echo $status_color; ?>;"><?php echo $status_label; ?></span></td>
                        <?php if ($can_override): ?>
                            <td style="text-align:center;">
                                <form method="POST" style="display:inline;">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="toggle_override" value="1">
                                    <input type="hidden" name="invoice_id" value="<?php echo (int)$row['invoice_id']; ?>">
                                    <input type="hidden" name="toggle_action" value="<?php echo $is_unlocked ? 'lock' : 'unlock'; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $is_unlocked ? 'btn-outline-danger' : 'btn-outline-success'; ?>" onclick="return confirm('Toggle fee lock for this student?')">
                                        <?php echo $is_unlocked ? 'Lock' : 'Unlock'; ?>
                                    </button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>