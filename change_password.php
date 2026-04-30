<?php
session_start();
include 'config/db_connect.php';

// 1. Redirect if user is not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Admins can manage passwords via user_management.php, 
// so this page is primarily for normal users, but we allow admins too for consistency.

$message = '';
$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $old_password = $_POST['old_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Input Validation
    if ($new_password !== $confirm_password) {
        $message = "Error: New password and confirmation password do not match.";
    } elseif (strlen($new_password) < 6) { // Basic password strength check
        $message = "Error: New password must be at least 6 characters long.";
    } else {
        // 2. Fetch current hashed password from database
        $check_query = "SELECT password FROM users WHERE id = ?";
        $check_stmt = mysqli_prepare($mysql_conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, 'i', $user_id);
        mysqli_stmt_execute($check_stmt);
        $result = mysqli_stmt_get_result($check_stmt);
        $user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($check_stmt);

        if ($user) {
            $current_hashed_password = $user['password'];

            // 3. Verify old password
            if (password_verify($old_password, $current_hashed_password)) {
                // 4. Hash and update new password
                $new_hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                
                $update_query = "UPDATE users SET password = ? WHERE id = ?";
                $update_stmt = mysqli_prepare($mysql_conn, $update_query);
                mysqli_stmt_bind_param($update_stmt, 'si', $new_hashed_password, $user_id);

                if (mysqli_stmt_execute($update_stmt)) {
                    $message = "Success: Your password has been changed successfully. 🎉";
                } else {
                    $message = "Error: Failed to update password. Please try again. " . mysqli_error($mysql_conn);
                    error_log("Password change error for user " . $user_id . ": " . mysqli_error($mysql_conn));
                }
                mysqli_stmt_close($update_stmt);

            } else {
                $message = "Error: The old password you entered is incorrect.";
            }
        } else {
            $message = "Fatal Error: User not found.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - QMS</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary-color: #007bff;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --light-bg: #f4f7f6;
            --white: #ffffff;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --border-radius: 8px;
        }
        body { font-family: 'Poppins', sans-serif; background-color: var(--light-bg); color: #343a40; margin: 0; padding-top: 60px; }
        .navbar { background-color: var(--primary-color); box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); padding: 0 25px; width: 100%; height: 60px; position: fixed; top: 0; left: 0; z-index: 1000; display: flex; justify-content: space-between; align-items: center; }
        .navbar h1 { color: var(--white); font-size: 1.5rem; margin: 0; }
        .navbar div a { color: var(--white); text-decoration: none; padding: 8px 12px; border-radius: 4px; margin-left: 10px; background-color: #0056b3; display: inline-flex; align-items: center; gap: 5px; }
        .navbar div a:hover { background-color: #003e80; }

        .container { padding: 25px; max-width: 600px; margin: 50px auto; }
        .card { background-color: var(--white); padding: 30px; border-radius: var(--border-radius); box-shadow: var(--shadow); }
        .card h2 { color: var(--primary-color); border-bottom: 2px solid #e9ecef; padding-bottom: 10px; margin-bottom: 20px; font-size: 1.8rem; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #555; }
        .form-group input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 1rem;
        }

        button[type="submit"] {
            background-color: var(--success-color);
            color: var(--white);
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s;
        }
        button[type="submit"]:hover { background-color: #1e7e34; }
        
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 8px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <nav class="navbar" style="
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 20px;
    background-color: #ffffff;
    min-height: 70px;
    border-bottom: 3px solid #2563eb;
    box-sizing: border-box;
    width: 100%;
    font-family: Arial, sans-serif;
">
    <a href="dashboard.php" style="
        font-size: 20px;
        font-weight: 900;
        color: #000000;
        text-decoration: none;
        text-transform: uppercase;
        flex-shrink: 0;
    ">
        SL <span style="color: #2563eb;">DIAGNOSTICS</span>
    </a>

    <div style="display: block;">
        <ul style="
            list-style: none;
            display: flex;
            margin: 0;
            padding: 0;
            gap: 10px;
            align-items: center;
        ">
 


    <div style="display: flex; align-items: center;">
        <a href="dashboard.php" style="
            padding: 12px 24px;
            background-color: #2563eb;
            color: #ffffff;
            text-decoration: none;
            font-weight: 800;
            font-size: 15px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.2);
            transition: background-color 0.2s;
        " onmouseover="this.style.backgroundColor='#1e40af'" 
           onmouseout="this.style.backgroundColor='#2563eb'">
            <i class="bi bi-house-door-fill"></i> DASHBOARD
        </a>
    </div>
           
              

            <li style="display: inline-block; margin-left: 5px;">
                <a href="logout.php" style="
                    padding: 8px 15px;
                    background-color: #dc2626;
                    color: #ffffff;
                    text-decoration: none;
                    font-weight: 800;
                    font-size: 13px;
                    border-radius: 4px;
                    display: inline-flex;
                    align-items: center;
                ">
                    LOGOUT
                </a>
            </li>
        </ul>
    </div>
</nav>
    
    <div class="container">
        <div class="card">
            <h2><i class="bi bi-shield-lock"></i> Change Password</h2>
            
            <?php if ($message) { 
                $alert_class = strpos($message, 'Success') !== false ? 'alert-success' : 'alert-error';
                $icon_class = strpos($message, 'Success') !== false ? 'bi-check-circle-fill' : 'bi-x-octagon-fill';
                ?>
                <div class="alert <?php echo $alert_class; ?>">
                    <i class="bi <?php echo $icon_class; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php } ?>

            <form method="POST">
                <div class="form-group">
                    <label for="old_password">Enter Old Password</label>
                    <input type="password" name="old_password" id="old_password" required autocomplete="current-password">
                </div>
                <div class="form-group">
                    <label for="new_password">Enter New Password</label>
                    <input type="password" name="new_password" id="new_password" required minlength="6" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" name="confirm_password" id="confirm_password" required minlength="6" autocomplete="new-password">
                </div>
                <button type="submit" name="change_password">
                    <i class="bi bi-pencil-square"></i> Change Password
                </button>
            </form>
        </div>
    </div>
</body>
</html>