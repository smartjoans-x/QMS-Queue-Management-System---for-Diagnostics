<?php
session_start();
include 'config/db_connect.php'; 

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$current_date = date('Y-m-d');
$lab_departments = [
    'Biochemistry', 'Cytology', 'Genetic', 'Haematology', 'Histopathology',
    'MICROBIOLOGY', 'Molecular Biology', 'Out source',
    'Pathology', 'Sample Collection', 'Serology'
];

// --- 1. Fetch Assigned Departments for the User ---
$assigned_dept_ids = [];
$is_admin = ($_SESSION['role'] !== 'department_user');

if (!$is_admin) {
    $dept_res = mysqli_query($mysql_conn, "
        SELECT ud.dept_id FROM user_departments ud WHERE ud.user_id = $user_id");

    while ($row = mysqli_fetch_assoc($dept_res)) {
        $assigned_dept_ids[] = (int)$row['dept_id'];
    }

    if (empty($assigned_dept_ids)) {
        die("<div style='padding:100px; text-align:center; font-family:sans-serif;'><h2>Access Denied!</h2><p>No Departments assigned to your profile.</p><a href='logout.php'>Logout</a></div>");
    }
    $dept_ids_csv = implode(',', $assigned_dept_ids);
}

// --- 2. Total Unique Patients (SIDs) Today ---
if ($is_admin) {
    $total_tokens_query = "SELECT COUNT(DISTINCT sid_no) as total FROM tokens WHERE created_date = CURDATE()";
} else {
    $total_tokens_query = "SELECT COUNT(DISTINCT sid_no) as total FROM tokens WHERE created_date = CURDATE() AND dept_id IN ($dept_ids_csv)";
}
$total_res = mysqli_query($mysql_conn, $total_tokens_query);
$total_tokens = mysqli_fetch_assoc($total_res)['total'] ?? 0;

// --- 3. Department-wise Stats with Merge Logic ---
$current_stats = [];
$stats_query = "SELECT d.dept_name, d.id,
    COUNT(CASE WHEN t.status = 'pending' THEN 1 END) as pending,
    COUNT(CASE WHEN t.status = 'completed' THEN 1 END) as completed_count,
    MAX(CASE WHEN t.status = 'called' THEN t.token_number END) as current_token,
    MAX(CASE WHEN t.status = 'completed' THEN t.token_number END) as last_completed,
    SUM(CASE WHEN t.status = 'completed' AND t.completed_date IS NOT NULL AND t.accepted_date IS NOT NULL 
             THEN TIMESTAMPDIFF(MINUTE, t.accepted_date, t.completed_date) 
             ELSE 0 END) as total_time_minutes
    FROM departments d 
    LEFT JOIN tokens t ON d.id = t.dept_id AND t.created_date = '$current_date'
    WHERE 1=1";

if (!$is_admin) {
    $stats_query .= " AND d.id IN ($dept_ids_csv)";
}

$stats_query .= " GROUP BY d.id, d.dept_name";
$result = mysqli_query($mysql_conn, $stats_query);

while ($row = mysqli_fetch_assoc($result)) {
    $raw_name = trim($row['dept_name']);
    $display_name = $raw_name;

    // --- MERGE LOGIC START ---
    if (in_array($raw_name, $lab_departments)) {
        $display_name = 'LAB';
    } elseif (strcasecmp($raw_name, 'MRI') == 0 || strcasecmp($raw_name, 'M.R.I') == 0) {
        $display_name = 'MRI';
    } elseif (strcasecmp($raw_name, 'CT') == 0 || strcasecmp($raw_name, 'C.T') == 0) {
        $display_name = 'CT';
    }
    // --- MERGE LOGIC END ---
    
    if (!isset($current_stats[$display_name])) {
        $current_stats[$display_name] = [
            'pending' => 0, 'completed_count' => 0, 'current_token' => null, 
            'last_completed' => null, 'total_time_minutes' => 0
        ];
    }
    
    $current_stats[$display_name]['pending'] += $row['pending'];
    $current_stats[$display_name]['completed_count'] += $row['completed_count'];
    
    // பிற்கால டோக்கன் எண்களை முன்னுரிமைப்படுத்துதல்
    if ($row['current_token']) $current_stats[$display_name]['current_token'] = $row['current_token'];
    if ($row['last_completed']) $current_stats[$display_name]['last_completed'] = $row['last_completed'];
    
    $current_stats[$display_name]['total_time_minutes'] += $row['total_time_minutes'];
}

ksort($current_stats); // அகரவரிசைப்படி அடுக்குகிறோம்
$display_username = htmlspecialchars($_SESSION['username'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | SL Diagnostics</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb; --success: #10b981; --danger: #ef4444;
            --warning: #f59e0b; --info: #0ea5e9; --bg: #f8fafc; --white: #ffffff;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg); margin: 0; padding-top: 80px; color: #1e293b; }
        
        .navbar { background: #fff; border-bottom: 3px solid var(--primary); padding: 10px 30px; position: fixed; top: 0; width: 100%; box-sizing: border-box; z-index: 1000; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .nav-brand { font-weight: 900; font-size: 1.5rem; color: #000; text-decoration: none; text-transform: uppercase; }
        
        .nav-links { display: flex; gap: 10px; align-items: center; }
        .nav-link { text-decoration: none; font-weight: 700; font-size: 0.85rem; padding: 10px 18px; border-radius: 8px; color: #475569; transition: 0.2s; display: flex; align-items: center; gap: 8px; }
        .nav-link:hover { background: #f1f5f9; color: var(--primary); }
        
        /* Green Color for Generate Button */
        .btn-generate { background: var(--success) !important; color: white !important; box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2); }
        .btn-generate:hover { background: #059669 !important; }

        .btn-logout { background: #fee2e2; color: #dc2626; }

        .container { padding: 0 30px 40px; max-width: 1800px; margin: 0 auto; }

        .header-row { display: grid; grid-template-columns: 1fr 300px; gap: 20px; margin-bottom: 30px; align-items: stretch; }
        .welcome-card { background: var(--white); padding: 30px; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .welcome-card h1 { margin: 0; font-size: 1.8rem; font-weight: 800; }
        
        .stat-box { background: linear-gradient(135deg, var(--primary), #1e40af); color: white; padding: 25px; border-radius: 20px; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; }
        .stat-box h2 { margin: 0; font-size: 2.5rem; font-weight: 800; }
        .stat-box p { margin: 5px 0 0; font-size: 0.85rem; font-weight: 600; opacity: 0.9; text-transform: uppercase; }

        .dashboard-grid { display: grid; grid-template-columns: 350px 1fr; gap: 25px; }
        .card { background: var(--white); border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; overflow: hidden; height: fit-content; }
        .card-header { padding: 20px 25px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 800; font-size: 1rem; display: flex; align-items: center; gap: 10px; color: #475569; }

        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: #f8fafc; text-align: left; padding: 15px 20px; font-size: 0.75rem; text-transform: uppercase; color: #64748b; font-weight: 700; border-bottom: 1px solid #e2e8f0; }
        .data-table td { padding: 18px 20px; font-size: 0.9rem; border-bottom: 1px solid #f1f5f9; }
        .data-table tr:hover { background: #fcfcfc; }

        .badge-pending { color: var(--danger); font-weight: 800; }
        .badge-token { background: #eff6ff; color: var(--primary); padding: 4px 10px; border-radius: 6px; font-weight: 800; font-size: 1rem; }
        .badge-success { color: var(--success); font-weight: 800; }
        
        @media (max-width: 1200px) { .dashboard-grid, .header-row { grid-template-columns: 1fr; } .stat-box { order: -1; } }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="dashboard.php" class="nav-brand">SL <span style="color:var(--primary)">Diagnostics</span></a>
    <div class="nav-links">
        <?php if($_SESSION['role'] === 'admin1'): ?>
            <a href="user_management.php" class="nav-link" style="background-color: #8b5cf6; color: white; border-radius: 8px; font-weight: 800; box-shadow: 0 4px 6px -1px rgba(139, 92, 246, 0.3);">
                <i class="bi bi-people-fill"></i> USERS
            </a>
        <?php endif; ?>

        <a href="generate_token.php" class="nav-link btn-generate"><i class="bi bi-plus-circle"></i> GENERATE TOKEN</a>
        
        <a href="view_tokens.php" class="nav-link"><i class="bi bi-printer"></i> PRINT</a>
        <a href="reports.php" class="nav-link"><i class="bi bi-file-earmark-bar-graph"></i> REPORTS</a>

        <?php if($_SESSION['role'] === 'department_user'): ?>
            <a href="department_screen.php" class="nav-link" style="background-color: #f59e0b; color: white; border-radius: 8px; font-weight: 800; box-shadow: 0 4px 6px -1px rgba(245, 158, 11, 0.3);">
                <i class="bi bi-display"></i> DEPT SCREEN
            </a>
        <?php endif; ?>

        <a href="logout.php" class="nav-link btn-logout"><i class="bi bi-power"></i></a>
    </div>
</nav>

<div class="container">
    <div class="header-row">
        <div class="welcome-card">
            <div>
                <h1>Welcome back, <?= $display_username ?>!</h1>
                <p id="live-clock" style="margin: 10px 0 0; color: #64748b; font-weight: 600;"></p>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="view_queue.php" class="nav-link" style="background: var(--bg); border: 1px solid #e2e8f0;"><i class="bi bi-eye"></i> View Live Queue</a>
            </div>
        </div>
        <div class="stat-box">
            <p>Total Patients Served Today</p>
            <h2><?= $total_tokens ?></h2>
        </div>
    </div>

    <div class="dashboard-grid">
        <div class="card">
            <div class="card-header"><i class="bi bi-hourglass-split"></i> Waiting Summary</div>
            <table class="data-table">
                <thead><tr><th>Dept</th><th style="text-align: right;">Waiting</th></tr></thead>
                <tbody>
                    <?php foreach ($current_stats as $name => $stat): ?>
                    <tr>
                        <td style="font-weight: 600;"><?= $name ?></td>
                        <td style="text-align: right;" class="badge-pending"><?= $stat['pending'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-grid-3x3-gap"></i> Activity Overview</div>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Current Call</th>
                            <th>Pending</th>
                            <th>Completed</th>
                            <th>Last Served</th>
                            <th>Avg. Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($current_stats as $name => $stat): ?>
                        <tr>
                            <td style="font-weight: 700;"><?= $name ?></td>
                            <td><span class="badge-token"><?= $stat['current_token'] ?: '--' ?></span></td>
                            <td class="badge-pending"><?= $stat['pending'] ?></td>
                            <td class="badge-success"><?= $stat['completed_count'] ?></td>
                            <td style="color: #64748b; font-weight: 600;"><?= $stat['last_completed'] ?: '--' ?></td>
                            <td style="font-weight: 700; color: var(--info);">
                                <?= $stat['total_time_minutes'] ?> <small>min</small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    function updateClock() {
        const now = new Date();
        document.getElementById('live-clock').innerHTML = '<i class="bi bi-clock"></i> ' + 
            now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' | ' +
            now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }
    setInterval(updateClock, 1000);
    updateClock();
</script>

</body>
</html>