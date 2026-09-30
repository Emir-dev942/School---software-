<?php
// parent/login.php - Parent Portal Login
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

if (isset($_SESSION['parent_id']) && isset($_SESSION['school_id'])) {
    header('Location: ' . BASE_URL . 'parent/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid CSRF token. Please try again.';
    } else {
        $phone = sanitize($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'];

        if (isLoginBlocked($phone, $ip)) {
            $error = 'Too many failed attempts. Please try again in 10 minutes.';
        } elseif (empty($phone) || empty($password)) {
            $error = 'Please enter phone number and password.';
        } else {
            $db = getDB();

            $stmt = $db->prepare("SELECT id, school_id, student_id, parent_phone, parent_email, password, is_active 
                                  FROM parent_portal 
                                  WHERE parent_phone = ? AND is_active = 1");
            $stmt->execute([$phone]);
            $parent = $stmt->fetch();

            if (!$parent && filter_var($phone, FILTER_VALIDATE_EMAIL)) {
                $stmt = $db->prepare("SELECT id, school_id, student_id, parent_phone, parent_email, password, is_active 
                                      FROM parent_portal 
                                      WHERE parent_email = ? AND is_active = 1");
                $stmt->execute([$phone]);
                $parent = $stmt->fetch();
            }

            if ($parent && password_verify($password, $parent['password'])) {
                session_unset();
                session_destroy();
                session_start();
                session_regenerate_id(true);

                $_SESSION['parent_id'] = (int)$parent['id'];
                $_SESSION['school_id'] = (int)$parent['school_id'];
                $_SESSION['parent_phone'] = $parent['parent_phone'];
                $_SESSION['parent_email'] = $parent['parent_email'];
                $_SESSION['parent_logged_in'] = true;
                $_SESSION['last_activity'] = time();

                clearFailedLogins($phone, $ip);

                header('Location: ' . BASE_URL . 'parent/index.php');
                exit;
            } else {
                recordFailedLogin($phone, $ip);
                $error = 'Invalid phone number or password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Portal - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { 
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            margin: 0;
            padding: 20px;
        }
        .login-card { 
            background: white; 
            border-radius: 20px; 
            padding: 40px 30px; 
            width: 100%; 
            max-width: 420px; 
            box-shadow: 0 20px 60px rgba(0,0,0,0.2); 
        }
        .login-card h3 { 
            text-align: center; 
            margin-bottom: 8px; 
            color: #0f172a;
            font-weight: 800;
        }
        .login-card .subtitle {
            text-align: center;
            color: #64748b;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .form-label {
            font-weight: 600;
            color: #334155;
            font-size: 14px;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
        }
        .form-control:focus {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }
        .login-card .btn-primary { 
            width: 100%; 
            padding: 12px; 
            background: #7c3aed; 
            border: none; 
            border-radius: 10px;
            font-weight: 600;
            transition: 0.3s;
        }
        .login-card .btn-primary:hover { 
            background: #6d28d9; 
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        }
        .error-msg { 
            color: #dc3545; 
            text-align: center; 
            margin-bottom: 15px; 
            background: #fef2f2;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #fecaca;
            font-size: 14px;
        }
        .success-msg { 
            color: #166534; 
            text-align: center; 
            margin-bottom: 15px; 
            background: #f0fdf4;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #bbf7d0;
            font-size: 14px;
        }
        .help-links {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }
        .help-links a {
            color: #7c3aed;
            text-decoration: none;
            font-weight: 600;
        }
        .help-links a:hover {
            text-decoration: underline;
        }
        .help-links .divider {
            color: #cbd5e1;
            margin: 0 8px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h3>👪 Parent Portal</h3>
        <p class="subtitle">Sign in to view your child's progress</p>
        
        <?php if ($error): ?>
            <div class="error-msg">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <?php echo csrfField(); ?>
            <div class="mb-3">
                <label for="phone" class="form-label">Phone Number or Email</label>
                <input type="text" class="form-control" id="phone" name="phone" placeholder="Enter phone or email" required autofocus>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" placeholder="Enter password" required>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>
        
        <div class="help-links">
            <a href="request_reset.php">Forgot password?</a>
            <span class="divider">|</span>
            <a href="reset_with_code.php">Have a reset code?</a>
        </div>
    </div>
</body>
</html>