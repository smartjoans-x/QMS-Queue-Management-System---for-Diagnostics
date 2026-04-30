<?php
session_start();
include 'config/db_connect.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_POST && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $query = "SELECT id, username, password, role, department_id FROM users WHERE username = ?";
        $stmt = mysqli_prepare($mysql_conn, $query);
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['department_id'] = $user['department_id'];
            header('Location: dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | SL Diagnostics</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    
    <style>
        :root {
            --primary-color: #2563eb;
            --secondary-color: #3b82f6;
            --bg-gradient: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            --glass-bg: rgba(255, 255, 255, 0.9);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-gradient);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            overflow: hidden;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }

        .login-card {
            background: var(--glass-bg);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 24px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            padding: 40px;
            transition: transform 0.3s ease;
        }

        .brand-logo {
            width: 64px;
            height: 64px;
            background: var(--primary-color);
            color: white;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 20px;
            box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.3);
        }

        .brand-name {
            text-align: center;
            color: #1e293b;
            font-weight: 700;
            font-size: 1.5rem;
            margin-bottom: 8px;
        }

        .brand-subtitle {
            text-align: center;
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 32px;
        }

        .form-label {
            font-weight: 500;
            color: #475569;
            margin-bottom: 8px;
        }

        .input-group-custom {
            position: relative;
            margin-bottom: 20px;
        }

        .input-group-custom i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            z-index: 10;
        }

        .form-control {
            height: 50px;
            padding-left: 45px;
            border-radius: 12px;
            border: 1.5px solid #e2e8f0;
            background: #f8fafc;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            background: #fff;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .btn-login {
            height: 50px;
            background: var(--primary-color);
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-login:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .alert-custom {
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 0.875rem;
            margin-bottom: 20px;
            border: none;
            background: #fef2f2;
            color: #dc2626;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .footer-text {
            text-align: center;
            margin-top: 24px;
            font-size: 0.8rem;
            color: #94a3b8;
        }
    </style>
</head>
<body>

    <div class="login-container">
        <div class="login-card">
            <div class="brand-logo">
                <i class="bi bi-capsule-pill"></i>
            </div>
            <h1 class="brand-name">SL Diagnostics</h1>
            <p class="brand-subtitle">Please enter your details to sign in</p>

            <?php if ($error) { ?>
                <div class="alert-custom">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php } ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <div class="input-group-custom">
                        <i class="bi bi-person"></i>
                        <input type="text" class="form-control" name="username" id="username" placeholder="Enter username" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-group-custom">
                        <i class="bi bi-shield-lock"></i>
                        <input type="password" class="form-control" name="password" id="password" placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" name="login" class="btn btn-login w-100">
                    Sign In <i class="bi bi-arrow-right ms-2"></i>
                </button>
            </form>

            <div class="footer-text">
                &copy; <?php echo date('Y'); ?> SL Diagnostic Management System
            </div>
        </div>
    </div>

</body>
</html>