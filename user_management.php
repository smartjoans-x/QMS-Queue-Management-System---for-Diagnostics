<?php
session_start();
include 'config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin1') {
    header('Location: login.php');
    exit;
}

$message = '';
if (isset($_GET['message'])) { $message = htmlspecialchars($_GET['message']); }

function set_redirect_message($msg) {
    header('Location: user_management.php?message=' . urlencode($msg));
    exit;
}

// --- POST HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_BCRYPT);
    $role = $_POST['role'];
    $selected_depts = isset($_POST['dept_ids']) ? $_POST['dept_ids'] : [];

    $check_stmt = mysqli_prepare($mysql_conn, "SELECT id FROM users WHERE username = ?");
    mysqli_stmt_bind_param($check_stmt, 's', $username);
    mysqli_stmt_execute($check_stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($check_stmt))) {
        $message = "Error: Username already exists.";
    } else {
        $query = "INSERT INTO users (username, password, role) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($mysql_conn, $query);
        mysqli_stmt_bind_param($stmt, 'sss', $username, $password, $role);
        if (mysqli_stmt_execute($stmt)) {
            $new_user_id = mysqli_insert_id($mysql_conn);
            foreach ($selected_depts as $dept_id) {
                mysqli_query($mysql_conn, "INSERT INTO user_departments (user_id, dept_id) VALUES ($new_user_id, $dept_id)");
            }
            set_redirect_message("Success: User created.");
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_depts'])) {
    $user_id = (int)$_POST['user_id'];
    $selected_depts = isset($_POST['dept_ids']) ? $_POST['dept_ids'] : [];
    mysqli_query($mysql_conn, "DELETE FROM user_departments WHERE user_id = $user_id");
    foreach ($selected_depts as $dept_id) {
        mysqli_query($mysql_conn, "INSERT INTO user_departments (user_id, dept_id) VALUES ($user_id, $dept_id)");
    }
    set_redirect_message("Success: Departments updated.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $user_id = (int)$_POST['user_id'];
    if ($user_id !== (int)$_SESSION['user_id']) {
        mysqli_query($mysql_conn, "DELETE FROM users WHERE id = $user_id");
        set_redirect_message("Success: User deleted.");
    }
}

$depts_res = mysqli_query($mysql_conn, "SELECT id, dept_name FROM departments ORDER BY dept_name");
$all_departments = mysqli_fetch_all($depts_res, MYSQLI_ASSOC);
$users_res = mysqli_query($mysql_conn, "SELECT id, username, role FROM users ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Management | SL Diagnostics</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root { --primary: #2563eb; --bg: #f8fafc; --white: #ffffff; --border: #e2e8f0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); margin: 0; padding-top: 80px; color: #1e293b; }
        
        .navbar { background: var(--white); border-bottom: 2px solid var(--primary); padding: 12px 40px; position: fixed; top: 0; width: 100%; box-sizing: border-box; z-index: 1000; display: flex; justify-content: space-between; align-items: center; }
        .btn-dash { background: var(--primary); color: white; text-decoration: none; padding: 10px 20px; border-radius: 10px; font-weight: 700; }

        .container { width: 95%; max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: 350px 1fr; gap: 30px; }
        .card { background: var(--white); border-radius: 20px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid var(--border); }
        
        /* Table Styles */
        .user-table { width: 100%; border-collapse: collapse; }
        .user-table th { text-align: left; padding: 15px; color: #64748b; font-size: 0.8rem; text-transform: uppercase; border-bottom: 1px solid var(--border); }
        .user-table td { padding: 15px; border-bottom: 1px solid var(--border); }
        
        .badge { background: #f1f5f9; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .dept-count { background: #dbeafe; color: var(--primary); padding: 2px 8px; border-radius: 10px; font-weight: 800; margin-left: 5px; }

        /* Button Styles */
        .btn { border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-edit { background: #f1f5f9; color: var(--primary); }
        .btn-edit:hover { background: var(--primary); color: white; }
        .btn-del { color: #ef4444; background: transparent; }

        /* Modal / Popup Styles */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); }
        .modal-content { background: white; margin: 5% auto; padding: 25px; width: 500px; border-radius: 20px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 20px; }
        
        .dept-grid-popup { max-height: 400px; overflow-y: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 5px; }
        .dept-item { display: flex; align-items: center; gap: 10px; padding: 10px; border: 1px solid var(--border); border-radius: 10px; cursor: pointer; font-size: 0.9rem; }
        .dept-item:hover { background: #f8fafc; }
        .dept-item input { width: 18px; height: 18px; }

        .alert { background: #dcfce7; color: #166534; padding: 15px; border-radius: 12px; margin-bottom: 20px; font-weight: 700; border-left: 6px solid #22c55e; }
    </style>
</head>
<body>

<nav class="navbar">
    <h2 style="font-weight: 800;"><i class="bi bi-shield-check"></i> ADMIN CONSOLE</h2>
    <a href="dashboard.php" class="btn-dash">DASHBOARD</a>
</nav>

<div class="container">
    <div style="position: sticky; top: 100px; height: fit-content;">
        <div class="card">
            <h3 style="margin:0 0 20px 0;">Add User</h3>
            <?php if ($message) echo "<div class='alert'>$message</div>"; ?>
            <form method="POST">
                <input type="text" name="username" placeholder="Username" required style="width:100%; padding:12px; border:1px solid var(--border); border-radius:10px; margin-bottom:15px; box-sizing:border-box;">
                <input type="password" name="password" placeholder="Password" required style="width:100%; padding:12px; border:1px solid var(--border); border-radius:10px; margin-bottom:15px; box-sizing:border-box;">
                <select name="role" style="width:100%; padding:12px; border:1px solid var(--border); border-radius:10px; margin-bottom:15px;">
                    <option value="department_user">Department User</option>
                    <option value="admin">Admin</option>
                </select>
                <button type="submit" name="create_user" class="btn" style="background:var(--primary); color:white; width:100%; justify-content:center;">CREATE USER</button>
            </form>
        </div>
    </div>

    <div class="card">
        <table class="user-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Dept Access</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while($user = mysqli_fetch_assoc($users_res)): 
                    $uid = $user['id'];
                    $q = mysqli_query($mysql_conn, "SELECT d.dept_name FROM user_departments ud JOIN departments d ON ud.dept_id = d.id WHERE ud.user_id = $uid");
                    $depts = []; while($r = mysqli_fetch_assoc($q)) { $depts[] = $r['dept_name']; }
                    $assigned_ids_q = mysqli_query($mysql_conn, "SELECT dept_id FROM user_departments WHERE user_id = $uid");
                    $assigned_ids = []; while($r = mysqli_fetch_assoc($assigned_ids_q)) { $assigned_ids[] = $r['dept_id']; }
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                    <td><span class="badge"><?= strtoupper($user['role']) ?></span></td>
                    <td>
                        <span class="dept-count"><?= count($depts) ?> Depts</span>
                    </td>
                    <td style="display:flex; gap:10px;">
                        <button class="btn btn-edit" onclick="openEditModal(<?= $uid ?>, '<?= $user['username'] ?>', <?= htmlspecialchars(json_encode($assigned_ids)) ?>)">
                            <i class="bi bi-pencil-square"></i> EDIT
                        </button>
                        <?php if($uid != $_SESSION['user_id']): ?>
                        <form method="POST" onsubmit="return confirm('Delete?');">
                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                            <button type="submit" name="delete_user" class="btn btn-del"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalUserName" style="margin:0;">Edit Access</h3>
            <button onclick="closeModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="user_id" id="modalUserId">
            <div class="dept-grid-popup">
                <?php foreach($all_departments as $d): ?>
                    <label class="dept-item">
                        <input type="checkbox" name="dept_ids[]" value="<?= $d['id'] ?>" class="dept-checkbox" id="dept-<?= $d['id'] ?>">
                        <span><?= $d['dept_name'] ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:20px; display:flex; gap:10px;">
                <button type="button" onclick="closeModal()" class="btn" style="flex:1; background:#f1f5f9; justify-content:center;">CANCEL</button>
                <button type="submit" name="update_depts" class="btn" style="flex:1; background:var(--primary); color:white; justify-content:center;">SAVE CHANGES</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(userId, username, assignedIds) {
        document.getElementById('modalUserId').value = userId;
        document.getElementById('modalUserName').innerText = "Edit Access: " + username;
        
        // Uncheck all first
        document.querySelectorAll('.dept-checkbox').forEach(cb => cb.checked = false);
        
        // Check assigned ones
        assignedIds.forEach(id => {
            let cb = document.getElementById('dept-' + id);
            if(cb) cb.checked = true;
        });
        
        document.getElementById('editModal').style.display = 'block';
    }

    function closeModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        let modal = document.getElementById('editModal');
        if (event.target == modal) closeModal();
    }
</script>

</body>
</html>