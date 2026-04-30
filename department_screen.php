<?php
session_start();
include 'config/db_connect.php'; 

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'department_user') {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$waiting_tokens = [];
$accepted_tokens = [];
$completed_tokens = []; 
$search = '';

// --- 1. Fetch assigned department IDs for the current user ---
$assigned_dept_ids = [];
$dept_names_list = [];
$dept_res = mysqli_query($mysql_conn, "
    SELECT ud.dept_id, d.dept_name 
    FROM user_departments ud 
    JOIN departments d ON ud.dept_id = d.id 
    WHERE ud.user_id = $user_id");

while ($row = mysqli_fetch_assoc($dept_res)) {
    $assigned_dept_ids[] = (int)$row['dept_id'];
    $dept_names_list[] = htmlspecialchars($row['dept_name']);
}

if (empty($assigned_dept_ids)) {
    die("<div style='padding:100px; text-align:center; font-family:sans-serif;'><h2>Access Denied!</h2><p>No Departments assigned to your profile. Contact Admin.</p><a href='logout.php'>Logout</a></div>");
}

$dept_ids_csv = implode(',', $assigned_dept_ids);

// --- NEW LOGIC: 60 விநாடிகளுக்கு மேல் 'CALLING' ஸ்டேட்டஸில் இருக்கும் அறிவிப்புகளைத் தானாக நீக்க ---
mysqli_query($mysql_conn, "DELETE FROM popup_notifications WHERE created_at < (NOW() - INTERVAL 60 SECOND)");

// --- 2. Fetch Waiting Tokens ---
$query = "SELECT * FROM tokens WHERE dept_id IN ($dept_ids_csv) AND status = 'pending' AND created_date = CURDATE() ORDER BY id ASC";
$waiting_tokens = mysqli_fetch_all(mysqli_query($mysql_conn, $query), MYSQLI_ASSOC);

// --- 3. Fetch Accepted Tokens ---
$query = "SELECT *, accepted_date AS accepted_timestamp 
          FROM tokens 
          WHERE dept_id IN ($dept_ids_csv) AND status = 'called' AND created_date = CURDATE() 
          ORDER BY accepted_date ASC";
$accepted_tokens = mysqli_fetch_all(mysqli_query($mysql_conn, $query), MYSQLI_ASSOC);

// --- 4. Fetch Completed Tokens ---
$query = "SELECT token_number, pat_name, pat_age, pat_sex, accepted_date, completed_date 
          FROM tokens 
          WHERE dept_id IN ($dept_ids_csv) AND status = 'completed' AND created_date = CURDATE() 
          ORDER BY completed_date DESC";
$completed_tokens = mysqli_fetch_all(mysqli_query($mysql_conn, $query), MYSQLI_ASSOC);

// Handle Search
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $search = mysqli_real_escape_string($mysql_conn, $_POST['search_term']);
    $query = "SELECT * FROM tokens WHERE dept_id IN ($dept_ids_csv) AND (pat_name LIKE '%$search%' OR sid_no LIKE '%$search%') AND status = 'pending' AND created_date = CURDATE() ORDER BY id ASC";
    $waiting_tokens = mysqli_fetch_all(mysqli_query($mysql_conn, $query), MYSQLI_ASSOC);
}

// --- Action Handlers ---
if (isset($_POST['call_token'])) {
    $token_id = (int)$_POST['token_id'];
    $token_q = mysqli_query($mysql_conn, "SELECT token_number, pat_name, dept_id FROM tokens WHERE id = $token_id");
    $token = mysqli_fetch_assoc($token_q);
    if ($token) {
        mysqli_query($mysql_conn, "DELETE FROM popup_notifications WHERE token_id = $token_id");
        $stmt = mysqli_prepare($mysql_conn, "INSERT INTO popup_notifications (token_id, dept_id, token_number, pat_name) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'iiss', $token_id, $token['dept_id'], $token['token_number'], $token['pat_name']);
        mysqli_stmt_execute($stmt);
    }
    header('Location: department_screen.php'); exit;
}

if (isset($_POST['stop_call'])) {
    $token_id = (int)$_POST['token_id'];
    mysqli_query($mysql_conn, "DELETE FROM popup_notifications WHERE token_id = $token_id");
    header('Location: department_screen.php'); exit;
}

if (isset($_POST['accept_token'])) {
    $token_id = (int)$_POST['token_id'];
    mysqli_query($mysql_conn, "UPDATE tokens SET status = 'called', accepted_date = NOW() WHERE id = $token_id");
    mysqli_query($mysql_conn, "DELETE FROM popup_notifications WHERE token_id = $token_id");
    header('Location: department_screen.php'); exit;
}

if (isset($_POST['complete_token'])) {
    $token_id = (int)$_POST['token_id'];
    mysqli_query($mysql_conn, "UPDATE tokens SET status = 'completed', completed_date = NOW() WHERE id = $token_id");
    header('Location: department_screen.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dept Panel | SL Diagnostics</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb; --success: #10b981; --warning: #f59e0b;
            --info: #0ea5e9; --danger: #ef4444; --bg: #f8fafc; --card: #ffffff;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg); margin: 0; padding-top: 75px; color: #1e293b; }
        .navbar { background: #fff; border-bottom: 2px solid var(--primary); padding: 10px 30px; position: fixed; top: 0; width: 100%; box-sizing: border-box; z-index: 1000; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        .nav-brand { font-weight: 800; font-size: 1.4rem; color: #000; text-decoration: none; }
        .nav-links a { text-decoration: none; font-weight: 700; font-size: 0.85rem; padding: 8px 15px; border-radius: 8px; transition: 0.2s; }
        .btn-dash { color: var(--primary); background: #eff6ff; margin-right: 10px; }
        .btn-logout { background: #fee2e2; color: #dc2626; }
        .container { padding: 20px; max-width: 1800px; margin: 0 auto; }
        .search-section { background: var(--card); padding: 15px 25px; border-radius: 16px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; }
        .dept-badge { background: #f1f5f9; color: #475569; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; border: 1px solid #e2e8f0; }
        .dashboard-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 25px; }
        .column-card { background: var(--card); border-radius: 20px; padding: 20px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .column-card h3 { margin: 0 0 20px 0; font-size: 1.1rem; font-weight: 800; display: flex; align-items: center; gap: 10px; padding-bottom: 15px; border-bottom: 2px solid #f1f5f9; }
        .token-table { width: 100%; border-collapse: separate; border-spacing: 0 10px; }
        .token-row td { padding: 15px 10px; border-top: 1px solid #edf2f7; border-bottom: 1px solid #edf2f7; }
        .token-row td:first-child { border-left: 1px solid #edf2f7; border-top-left-radius: 12px; border-bottom-left-radius: 12px; font-weight: 800; color: var(--primary); }
        .token-row td:last-child { border-right: 1px solid #edf2f7; border-top-right-radius: 12px; border-bottom-right-radius: 12px; }
        .btn-act { border: none; padding: 8px 14px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
        .btn-call { background: var(--info); color: white; }
        .btn-stop { background: var(--danger); color: white; }
        .btn-accept { background: var(--success); color: white; }
        .btn-done { background: var(--warning); color: #000; width: 100%; justify-content: center; }
        .timer-badge { background: #fee2e2; color: #ef4444; padding: 4px 10px; border-radius: 6px; font-weight: 800; font-size: 0.85rem; }
        @media (max-width: 1600px) { .dashboard-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="dashboard.php" class="nav-brand">SL <span style="color:var(--primary)">DIAGNOSTICS</span></a>
    <div class="nav-links">
        <a href="dashboard.php" class="btn-dash"><i class="bi bi-house"></i> DASHBOARD</a>
        <a href="logout.php" class="btn-logout"><i class="bi bi-power"></i> LOGOUT</a>
    </div>
</nav>

<div class="container">
    <div class="search-section">
        <div class="dept-badges">
            <span style="font-weight: 800; font-size: 0.9rem; color: #64748b; margin-right: 10px;">MY DEPARTMENTS:</span>
            <?php foreach($dept_names_list as $dn) echo "<span class='dept-badge'>$dn</span>"; ?>
        </div>
        <form method="POST" style="display:flex; gap:10px;">
            <input type="text" name="search_term" value="<?= htmlspecialchars($search) ?>" placeholder="Patient Name / SID" style="padding:10px; border:1px solid #cbd5e1; border-radius:10px; width:220px;">
            <button type="submit" name="search" class="btn-act" style="background:var(--primary); color:white;">SEARCH</button>
            <?php if($search): ?><a href="department_screen.php" style="padding:10px; color:#64748b;"><i class="bi bi-x-circle"></i></a><?php endif; ?>
        </form>
    </div>

    <div class="dashboard-grid">
        <div class="column-card">
            <h3 style="color:var(--info)"><i class="bi bi-people"></i> Waiting List (<?= count($waiting_tokens) ?>)</h3>
            <table class="token-table">
                <thead><tr><th>Token</th><th>Patient Details</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php 
                    $called_res = mysqli_query($mysql_conn, "SELECT token_id FROM popup_notifications");
                    $called_ids = []; while($r = mysqli_fetch_assoc($called_res)) $called_ids[] = $r['token_id'];
                    foreach ($waiting_tokens as $t): 
                        $is_calling = in_array($t['id'], $called_ids);
                    ?>
                    <tr class="token-row">
                        <td><?= $t['token_number'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($t['pat_name']) ?></strong><br>
                            <small style="color:#64748b"><?= $t['pat_age'] ?>/<?= $t['pat_sex'] ?></small>
                        </td>
                        <td>
                            <form method="POST" style="display:flex; gap:5px;">
                                <input type="hidden" name="token_id" value="<?= $t['id'] ?>">
                                <?php if(!$is_calling): ?>
                                    <button type="submit" name="call_token" class="btn-act btn-call" title="Call"><i class="bi bi-megaphone"></i></button>
                                <?php else: ?>
                                    <button type="submit" name="stop_call" class="btn-act btn-stop" title="Stop"><i class="bi bi-stop-circle"></i></button>
                                <?php endif; ?>
                                <button type="submit" name="accept_token" class="btn-act btn-accept">ACCEPT</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="column-card">
            <h3 style="color:var(--success)"><i class="bi bi-play-circle"></i> In Progress (<?= count($accepted_tokens) ?>)</h3>
            <table class="token-table">
                <thead><tr><th>Token</th><th>Patient Details</th><th>Timer / Action</th></tr></thead>
                <tbody>
                    <?php foreach ($accepted_tokens as $t): ?>
                    <tr class="token-row">
                        <td><?= $t['token_number'] ?></td>
                        <td><strong><?= htmlspecialchars($t['pat_name']) ?></strong></td>
                        <td>
                            <div style="display:flex; flex-direction:column; gap:8px;">
                                <span id="countdown-<?= $t['id'] ?>" class="timer-badge">00:00</span>
                                <form method="POST">
                                    <input type="hidden" name="token_id" value="<?= $t['id'] ?>">
                                    <button type="submit" name="complete_token" class="btn-act btn-done">FINISH</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <span class="countdown-initializer" data-token-id="<?= $t['id'] ?>" data-time="<?= $t['accepted_timestamp'] ?>"></span>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="column-card">
            <h3 style="color:var(--primary)"><i class="bi bi-check2-all"></i> Recently Done</h3>
            <table class="token-table">
                <thead><tr><th>Token</th><th>Patient</th><th>Duration</th></tr></thead>
                <tbody>
                    <?php foreach ($completed_tokens as $t): 
                        $diff = 'N/A';
                        if($t['accepted_date'] && $t['completed_date']){
                            $d1 = new DateTime($t['accepted_date']);
                            $d2 = new DateTime($t['completed_date']);
                            $diff = $d1->diff($d2)->format('%H:%I:%S');
                        }
                    ?>
                    <tr class="token-row" style="opacity: 0.7;">
                        <td><?= $t['token_number'] ?></td>
                        <td><strong><?= htmlspecialchars($t['pat_name']) ?></strong></td>
                        <td style="font-weight:700; color:var(--success)"><?= $diff ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // Countdown Timer Logic
    const countdowns = {};
    function formatTime(seconds) {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }
    function updateCountdown(tokenId, startTime) {
        const element = document.getElementById(`countdown-${tokenId}`);
        if (!element) return;
        const now = new Date().getTime();
        let elapsed = Math.floor((now - startTime) / 1000);
        element.textContent = formatTime(elapsed < 0 ? 0 : elapsed);
        countdowns[tokenId] = setTimeout(() => updateCountdown(tokenId, startTime), 1000);
    }
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.countdown-initializer').forEach(el => {
            const id = el.dataset.tokenId;
            const time = new Date(el.dataset.time.replace(' ', 'T')).getTime();
            updateCountdown(id, time);
        });
    });

    // 15 விநாடிகளுக்கு ஒருமுறை பக்கம் தானாக Refresh ஆகும்.
    // இது PHP-யில் உள்ள 'Interval 60 Second' லாஜிக்கோடு இணைந்து, தானாக CALL ஆக்ஷனை நீக்கும்.
    setTimeout(function() {
        window.location.href = window.location.pathname;
    }, 15000);
</script>
</body>
</html>