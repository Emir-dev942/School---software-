<?php
// school_owner/upload_question_images.php - Upload Question Images (PDO + CSRF)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner']);
requireCsrf();

$db = getDB();
$school_id = (int)$_SESSION['school_id'];
$user_id = (int)$_SESSION['user_id'];

$message = '';
$error = '';

// ---------- HANDLE IMAGE UPLOADS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['images'])) {
    $upload_dir = __DIR__ . '/../uploads/question_images/';

    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true)) {
            $error = "Failed to create upload directory. Please check permissions.";
        }
    }

    if (empty($error)) {
        $uploaded = 0;
        $failed = 0;
        $file_names = [];
        $files = $_FILES['images'];

        $file_count = count($files['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $failed++;
                continue;
            }

            $name = basename($files['name'][$i]);
            $tmp = $files['tmp_name'][$i];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];

            if (!in_array($ext, $allowed)) {
                $failed++;
                continue;
            }

            if ($files['size'][$i] > 5 * 1024 * 1024) {
                $failed++;
                continue;
            }

            $safe_name = 'qimg_' . time() . '_' . rand(1000, 9999) . '.' . $ext;

            if (move_uploaded_file($tmp, $upload_dir . $safe_name)) {
                $uploaded++;
                $file_names[] = $safe_name;
            } else {
                $failed++;
            }
        }

        if ($uploaded > 0) {
            logActivity('UPLOAD_QUESTION_IMAGES', "Uploaded $uploaded question images. Files: " . implode(', ', $file_names), $school_id, $user_id);
            $message = "✅ Successfully uploaded <strong>$uploaded</strong> images.";
            if ($failed > 0) {
                $message .= " ⚠️ Failed: <strong>$failed</strong> images.";
            }
            $message .= "<br><small style='color:#64748b;'>Files: " . implode(', ', $file_names) . "</small>";
        } else {
            $error = "No images were uploaded. Failed: $failed";
        }
    }
}

// ---------- GET EXISTING IMAGES ----------
$image_dir = __DIR__ . '/../uploads/question_images/';
$existing_images = [];
if (is_dir($image_dir)) {
    $files = scandir($image_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'])) {
                $existing_images[] = $file;
            }
        }
    }
    rsort($existing_images);
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title" style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">🖼️ Upload Question Images</h1>
        <p style="color: #64748b; margin: 0;">Upload images for questions. Use the filenames in your CSV import.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="import_qa_bulk.php" class="btn btn-outline-primary" style="border-radius: 10px; padding: 8px 18px;">
            <i class="fas fa-file-import"></i> Bulk Import
        </a>
        <a href="import_questions.php" class="btn btn-outline-secondary" style="border-radius: 10px; padding: 8px 18px;">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:16px 20px;border-radius:12px;margin-bottom:24px;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:16px 20px;border-radius:12px;margin-bottom:24px;">
        ❌ <?php echo $error; ?>
    </div>
<?php endif; ?>

<!-- Upload Form -->
<div style="background: #ffffff; border-radius: 16px; padding: 28px; border: 1px solid #f1f5f9; margin-bottom: 24px;">
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrfField(); ?>
        <div class="mb-3">
            <label class="form-label fw-semibold" style="font-size: 13px; color: #334155;">Select Images <span class="text-danger">*</span></label>
            <input type="file" name="images[]" class="form-control" accept="image/*" multiple required style="border-radius: 10px; padding: 10px 14px; border-color: #e2e8f0;">
            <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;">
                Supported: JPG, PNG, GIF, WEBP, SVG, BMP. Max size: 5MB each.
                <br>You can select multiple files at once (Ctrl+Click or Shift+Click).
            </div>
        </div>
        <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #7c3aed, #6d28d9); border: none; border-radius: 10px; padding: 12px 40px; font-weight: 600;">
            <i class="fas fa-upload"></i> Upload Images
        </button>
    </form>
</div>

<!-- Existing Images Gallery -->
<?php if (!empty($existing_images)): ?>
<div style="background: #ffffff; border-radius: 16px; padding: 24px; border: 1px solid #f1f5f9;">
    <h5 style="font-weight: 600; color: #0f172a; margin-bottom: 16px;">📁 Existing Images (<?php echo count($existing_images); ?>)</h5>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px;">
        <?php foreach ($existing_images as $img): ?>
            <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; text-align: center; background: #f8fafc;">
                <img src="<?php echo BASE_URL; ?>uploads/question_images/<?php echo $img; ?>" alt="<?php echo $img; ?>" style="max-width: 100%; max-height: 100px; object-fit: contain;">
                <div style="font-size: 11px; color: #94a3b8; margin-top: 4px; word-break: break-all;"><?php echo $img; ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div style="font-size: 12px; color: #94a3b8; margin-top: 12px; text-align: center;">
        These filenames can be used in the <code>question_image</code> column of your CSV.
    </div>
</div>
<?php else: ?>
<div style="background: #f8fafc; border-radius: 16px; padding: 24px; border: 1px solid #e2e8f0; text-align: center; color: #94a3b8;">
    <i class="fas fa-images" style="font-size: 48px; display: block; margin-bottom: 12px; opacity: 0.3;"></i>
    No images uploaded yet. Use the form above to upload images.
</div>
<?php endif; ?>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>