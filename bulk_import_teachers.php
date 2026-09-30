<?php
// school_owner/bulk_import_teachers.php - SMART IMPORT for staff
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id = (int)$_SESSION['user_id'];

$errors = [];
$success = '';
$imported_count = 0;
$failed_rows = [];
$imported_users = [];

// Sample CSV
if (isset($_GET['sample'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="teacher_import_template.csv"');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Full Name', 'Email', 'Phone', 'Role']);
    fputcsv($output, ['John Doe', 'john@school.com', '08012345678', 'Teacher']);
    fputcsv($output, ['Jane Smith', 'jane@school.com', '08087654321', 'Principal']);
    fputcsv($output, ['Sam Ade', 'sam@school.com', '08011223344', 'Accountant']);
    fclose($output);
    exit;
}

// CSV Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['csv_file']['tmp_name'];
    $fileName = $_FILES['csv_file']['name'];
    $fileType = pathinfo($fileName, PATHINFO_EXTENSION);

    if (strtolower($fileType) !== 'csv') {
        $errors[] = 'Please upload a valid CSV file.';
    } else {
        $rows = array_map('str_getcsv', file($file));
        
        if (count($rows) < 2) {
            $errors[] = 'The CSV file is empty.';
        } else {
            $headers = array_shift($rows);
            if (isset($headers[0])) {
                $headers[0] = preg_replace('/[\x{FEFF}]/u', '', $headers[0]);
            }
            
            $requiredHeaders = ['Full Name', 'Email', 'Phone', 'Role'];
            $headerValid = true;
            foreach ($requiredHeaders as $i => $header) {
                if (!isset($headers[$i]) || trim(strtolower($headers[$i])) !== strtolower($header)) {
                    $headerValid = false;
                    break;
                }
            }
            
            if (!$headerValid) {
                $errors[] = 'CSV headers do not match. Download the template.';
            } else {
                $db->beginTransaction();
                try {
                    $rowNumber = 1;
                    foreach ($rows as $row) {
                        $rowNumber++;
                        if (count($row) < 4 || empty(trim($row[0]))) continue;
                        
                        $full_name = trim($row[0] ?? '');
                        $email     = trim($row[1] ?? '');
                        $phone     = trim($row[2] ?? '');
                        $role      = trim($row[3] ?? 'Teacher');
                        
                        if (empty($full_name) || empty($email)) {
                            $failed_rows[] = "Row $rowNumber: Missing Name or Email.";
                            continue;
                        }
                        
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $failed_rows[] = "Row $rowNumber: Invalid email '$email'.";
                            continue;
                        }
                        
                        // Smart role matching (case insensitive + variations)
                        $role_lower = strtolower(trim($role));
                        if (strpos($role_lower, 'principal') !== false) {
                            $role = 'Principal';
                        } elseif (strpos($role_lower, 'account') !== false) {
                            $role = 'Accountant';
                        } elseif (strpos($role_lower, 'teacher') !== false) {
                            $role = 'Teacher';
                        } else {
                            $role = 'Teacher';
                        }
                        
                        $check = $db->prepare("SELECT id FROM users WHERE email = ? AND school_id = ?");
                        $check->execute([$email, $school_id]);
                        if ($check->fetch()) {
                            $failed_rows[] = "Row $rowNumber: Email '$email' already exists.";
                            continue;
                        }
                        
                        $random_password = 'Staff@' . rand(1000, 9999);
                        $hashed = password_hash($random_password, PASSWORD_DEFAULT);
                        
                        $stmt = $db->prepare("INSERT INTO users (school_id, full_name, email, phone, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())");
                        $stmt->execute([$school_id, $full_name, $email, $phone, $hashed, $role]);
                        
                        $imported_count++;
                        $imported_users[] = ['name' => $full_name, 'email' => $email, 'password' => $random_password, 'role' => $role];
                    }
                    
                    if ($imported_count > 0) {
                        $db->commit();
                        logActivity('BULK_IMPORT_TEACHERS', "Imported $imported_count staff", $school_id, $user_id);
                        $success = "✅ Imported <strong>$imported_count</strong> staff.";
                        if (count($failed_rows) > 0) $success .= " ⚠️ " . count($failed_rows) . " skipped.";
                    } else {
                        $db->rollBack();
                        $errors[] = 'No staff imported.';
                    }
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $errors[] = 'Error: ' . $e->getMessage();
                }
            }
        }
    }
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight:700;color:#0f172a;">📤 Bulk Import Staff</h1>
        <p style="color:#64748b;margin:0;">Upload CSV to add multiple staff members at once.</p>
    </div>
    <a href="manage_teachers.php" class="btn btn-outline-secondary" style="border-radius:10px;"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:16px;border-radius:12px;margin-bottom:24px;"><?php echo $success; ?></div>
    <?php if (!empty($imported_users)): ?>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:16px;margin-bottom:24px;">
            <p style="font-weight:600;color:#92400e;margin-bottom:8px;">🔑 Staff Passwords:</p>
            <div style="max-height:250px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead style="background:#fef3c7;">
                        <tr><th style="padding:8px;text-align:left;">Name</th><th style="padding:8px;text-align:left;">Email</th><th style="padding:8px;text-align:left;">Role</th><th style="padding:8px;text-align:left;">Password</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($imported_users as $u): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:6px 8px;"><?php echo htmlspecialchars($u['name']); ?></td>
                                <td style="padding:6px 8px;"><?php echo htmlspecialchars($u['email']); ?></td>
                                <td style="padding:6px 8px;"><?php echo $u['role']; ?></td>
                                <td style="padding:6px 8px;font-family:monospace;background:#f1f5f9;"><?php echo $u['password']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:16px;border-radius:12px;margin-bottom:24px;"><?php echo implode('<br>', $errors); ?></div>
<?php endif; ?>

<?php if (!empty($failed_rows)): ?>
    <div class="alert alert-warning" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:16px;border-radius:12px;margin-bottom:24px;max-height:200px;overflow-y:auto;">
        <strong>⚠️ Skipped:</strong><br><?php echo implode('<br>', array_slice($failed_rows, 0, 20)); ?>
    </div>
<?php endif; ?>

<div style="background:#fff;border-radius:20px;padding:36px;box-shadow:0 4px 24px rgba(0,0,0,0.04);">
    <div style="background:#f8fafc;border-radius:12px;padding:20px;margin-bottom:24px;border-left:4px solid #7c3aed;">
        <h5 style="font-weight:600;color:#0f172a;margin-bottom:8px;">📋 Instructions</h5>
        <ul style="color:#475569;padding-left:20px;margin:0;">
            <li>Required: <strong>Full Name, Email, Phone, Role</strong></li>
            <li>Valid roles: <strong>Teacher, Principal, Accountant</strong> (case-insensitive)</li>
            <li>Random passwords generated and shown after import</li>
        </ul>
    </div>
    <div style="margin-bottom:24px;">
        <a href="?sample=1" class="btn btn-outline-primary" style="border-radius:10px;padding:10px 24px;border-color:#c4b5fd;color:#5b21b6;">
            <i class="fas fa-download"></i> Download Sample Template
        </a>
    </div>
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrfField(); ?>
        <div style="border:2px dashed #e2e8f0;border-radius:16px;padding:40px;text-align:center;background:#fafbfc;">
            <div style="font-size:48px;margin-bottom:12px;">📄</div>
            <p style="font-weight:600;color:#0f172a;margin-bottom:16px;">Choose CSV file</p>
            <input type="file" name="csv_file" accept=".csv" required style="margin-bottom:16px;">
        </div>
        <div style="margin-top:24px;text-align:right;">
            <button type="submit" class="btn btn-primary" style="border-radius:12px;padding:12px 40px;font-weight:600;">
                <i class="fas fa-upload"></i> Upload & Import
            </button>
        </div>
    </form>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>