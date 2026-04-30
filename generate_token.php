<?php
session_start();
include 'config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$lab_departments = [
    'Biochemistry', 'Cytology', 'Genetic', 'Haematology', 'Histopathology',
    'Mamography', 'MICROBIOLOGY', 'Molecular Biology', 'Out source',
    'Pathology', 'Sample Collection', 'Serology'
];
$patient = null;
$patient_depts = [];
$has_lab_dept = false;
$message = '';
$current_date = date('Y-m-d');
$selected_date = isset($_POST['sid_date']) ? $_POST['sid_date'] : $current_date;

$token_for_modal = '';
$patient_name_for_modal = '';
$sid_no_for_modal = '';

if ($_POST && isset($_POST['sid_no']) && isset($_POST['sid_date']) && !isset($_POST['assign'])) {
    $sid_no = trim($_POST['sid_no']);
    $sid_date = $_POST['sid_date'];

    $query = "SELECT Sid_No, Pat_Name, Pat_Age, Pat_Sex, Ref_Name, Dept_Name 
              FROM slims_Live.dbo.vw_TAT 
              WHERE Sid_No = ? AND Branch_Name = 'SL Diagnostics' AND Sid_Date = ?";
    $stmt = sqlsrv_prepare($mssql_conn, $query, [$sid_no, $sid_date]);
    
    if ($stmt && sqlsrv_execute($stmt)) {
        $patient = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($patient) {
            $dept_query = "SELECT DISTINCT Dept_Name FROM slims_Live.dbo.vw_TAT WHERE Sid_No = ? AND Branch_Name = 'SL Diagnostics' AND Sid_Date = ?";
            $dept_stmt = sqlsrv_prepare($mssql_conn, $dept_query, [$sid_no, $sid_date]);
            if (sqlsrv_execute($dept_stmt)) {
                while ($row = sqlsrv_fetch_array($dept_stmt, SQLSRV_FETCH_ASSOC)) {
                    $dept_name = trim($row['Dept_Name'] ?? '');
                    if (!empty($dept_name)) {
                        $patient_depts[] = $dept_name;
                        if (in_array($dept_name, $lab_departments)) $has_lab_dept = true;
                    }
                }
            }
        } else {
            $message = "Error: No patient found!";
        }
    }
}

if ($_POST && isset($_POST['assign'])) {
    $sid_no = $_POST['sid_no'];
    $pat_name = $_POST['pat_name'];
    $dept_ids = $_POST['dept_ids'] ?? [];

    $check_query = "SELECT token_number FROM tokens WHERE sid_no = ? AND created_date = ? LIMIT 1";
    $check_stmt = mysqli_prepare($mysql_conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, 'ss', $sid_no, $current_date);
    mysqli_stmt_execute($check_stmt);
    $res = mysqli_stmt_get_result($check_stmt);
    $existing = mysqli_fetch_assoc($res);

    if ($existing) {
        $token_for_modal = $existing['token_number'];
        $message = "Success: Token already exists - " . $token_for_modal;
    } else {
        $count_res = mysqli_query($mysql_conn, "SELECT COUNT(DISTINCT sid_no) as total FROM tokens WHERE created_date = '$current_date'");
        $token_val = mysqli_fetch_assoc($count_res)['total'] + 1;
        $token_for_modal = sprintf("%03d", $token_val);

        foreach ($dept_ids as $dept_id) {
            $ins = "INSERT INTO tokens (sid_no, pat_name, pat_age, pat_sex, ref_name, dept_id, token_number, created_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $ins_stmt = mysqli_prepare($mysql_conn, $ins);
            mysqli_stmt_bind_param($ins_stmt, 'ssississ', $sid_no, $pat_name, $_POST['pat_age'], $_POST['pat_sex'], $_POST['ref_name'], $dept_id, $token_for_modal, $current_date);
            mysqli_stmt_execute($ins_stmt);
        }
        $message = "Success: Token Generated!";
    }
    $patient_name_for_modal = $pat_name;
    $sid_no_for_modal = $sid_no;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QMS - Token Generation</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --primary: #2563eb; --bg: #f8fafc; --text: #1e293b; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); margin: 0; padding-bottom: 50px; }
        
        .navbar { background: #fff; padding: 1rem 5%; border-bottom: 2px solid var(--primary); display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .logo { font-size: 1.5rem; font-weight: 800; color: #000; text-decoration: none; }
        .logo span { color: var(--primary); }

        .container { width: 90%; max-width: 1000px; margin: 2rem auto; }
        
        .card { background: #fff; border-radius: 12px; padding: 2rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); margin-bottom: 1.5rem; border: 1px solid #e2e8f0; }
        .card-title { font-size: 1.25rem; font-weight: 700; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 10px; color: var(--primary); }
        
        .grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; }
        .input-group { margin-bottom: 1rem; }
        .input-group label { display: block; font-size: 0.875rem; font-weight: 600; margin-bottom: 0.5rem; }
        .input-group input { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; box-sizing: border-box; }

        .btn { padding: 0.75rem 1.5rem; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-success { background: #10b981; color: #fff; width: 100%; justify-content: center; font-size: 1.1rem; }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }

        .dept-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin: 1.5rem 0; }
        .dept-card { background: #f1f5f9; padding: 1rem; border-radius: 8px; border: 2px solid transparent; cursor: pointer; text-align: center; font-weight: 600; transition: 0.2s; position: relative; }
        .dept-card input { position: absolute; opacity: 0; }
        .dept-card.selected { border-color: var(--primary); background: #eff6ff; color: var(--primary); }

        /* Modal Styles */
        #modalOverlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: none; justify-content: center; align-items: center; z-index: 1000; backdrop-filter: blur(4px); }
        .modal { background: #fff; padding: 2rem; border-radius: 16px; width: 90%; max-width: 400px; text-align: center; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
        .token-circle { width: 120px; height: 120px; background: #eff6ff; border: 4px solid var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 1.5rem auto; font-size: 3rem; font-weight: 800; color: var(--primary); }
        
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; font-weight: 600; text-align: center; }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="#" class="logo">SL <span>DIAGNOSTICS</span></a>
    <a href="dashboard.php" class="btn btn-primary"><i class="bi bi-speedometer2"></i> Dashboard</a>
</nav>

<div class="container">
    <?php if ($message): ?>
        <div class="alert <?php echo strpos($message, 'Success') !== false ? 'alert-success' : 'alert-error'; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title"><i class="bi bi-search"></i> Fetch Patient</div>
        <form method="POST">
            <div class="grid-3">
                <div class="input-group">
                    <label>SID Number</label>
                    <input type="text" name="sid_no" required placeholder="Ex: 0240001" value="<?php echo $_POST['sid_no'] ?? ''; ?>">
                </div>
                <div class="input-group">
                    <label>Visit Date</label>
                    <input type="date" name="sid_date" value="<?php echo $selected_date; ?>">
                </div>
                <div style="display: flex; align-items: flex-end; padding-bottom: 1rem;">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-repeat"></i> Fetch Details</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($patient): ?>
        <form method="POST" id="assignForm">
            <input type="hidden" name="sid_no" value="<?php echo $patient['Sid_No']; ?>">
            <input type="hidden" name="pat_name" value="<?php echo $patient['Pat_Name']; ?>">
            <input type="hidden" name="pat_age" value="<?php echo $patient['Pat_Age']; ?>">
            <input type="hidden" name="pat_sex" value="<?php echo $patient['Pat_Sex']; ?>">
            <input type="hidden" name="ref_name" value="<?php echo $patient['Ref_Name'] ?? 'N/A'; ?>">

            <div class="card">
                <div class="card-title"><i class="bi bi-person-check"></i> Patient Details</div>
                <div class="grid-3" style="background: #f8fafc; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                    <div><strong>Name:</strong> <?php echo $patient['Pat_Name']; ?></div>
                    <div><strong>SID:</strong> <?php echo $patient['Sid_No']; ?></div>
                    <div><strong>Age/Sex:</strong> <?php echo $patient['Pat_Age']; ?> / <?php echo $patient['Pat_Sex']; ?></div>
                </div>

                <div class="card-title"><i class="bi bi-tags"></i> Select Departments</div>
                <div class="dept-grid">
                    <?php 
                    if ($has_lab_dept) {
                        $l_res = mysqli_query($mysql_conn, "SELECT id FROM departments WHERE dept_name = 'LAB'");
                        $l_id = mysqli_fetch_assoc($l_res)['id'] ?? null;
                        if ($l_id) echo "<label class='dept-card selected'><input type='checkbox' name='dept_ids[]' value='$l_id' checked> <i class='bi bi-flask'></i> LAB</label>";
                    }
                    $d_list = "'" . implode("','", $lab_departments) . "'";
                    $others = mysqli_query($mysql_conn, "SELECT * FROM departments WHERE dept_name NOT IN ($d_list)");
                    while ($d = mysqli_fetch_assoc($others)) {
                        $sel = in_array($d['dept_name'], $patient_depts) ? 'selected' : '';
                        $chk = in_array($d['dept_name'], $patient_depts) ? 'checked' : '';
                        echo "<label class='dept-card $sel'><input type='checkbox' name='dept_ids[]' value='{$d['id']}' $chk> {$d['dept_name']}</label>";
                    }
                    ?>
                </div>
                <button type="submit" name="assign" class="btn btn-success"><i class="bi bi-plus-circle"></i> Generate Token</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<div id="modalOverlay">
    <div class="modal">
        <i class="bi bi-check-circle-fill" style="font-size: 3rem; color: #10b981;"></i>
        <h2 style="margin: 10px 0;">Token Generated!</h2>
        <div style="background: #f1f5f9; padding: 10px; border-radius: 8px; text-align: left; font-size: 0.9rem;">
            <div><strong>Patient:</strong> <span id="m_name"></span></div>
            <div><strong>SID:</strong> <span id="m_sid"></span></div>
        </div>
        <div class="token-circle" id="m_token"></div>
        <div style="display: flex; gap: 10px; margin-top: 1.5rem;">
            <button onclick="printAction()" class="btn btn-primary" style="flex: 1; justify-content: center;"><i class="bi bi-printer"></i> Print</button>
            <button onclick="closeModal()" class="btn" style="flex: 1; background: #e2e8f0; justify-content: center;">Close</button>
        </div>
    </div>
</div>

<script>
    // Dept Selection UI Toggle
    document.querySelectorAll('.dept-card input').forEach(inp => {
        inp.addEventListener('change', function() {
            this.parentElement.classList.toggle('selected', this.checked);
        });
    });

    const MODAL_DATA = {
        token: "<?php echo $token_for_modal; ?>",
        name: "<?php echo $patient_name_for_modal; ?>",
        sid: "<?php echo $sid_no_for_modal; ?>"
    };

    if(MODAL_DATA.token !== "") {
        document.getElementById('m_token').innerText = MODAL_DATA.token;
        document.getElementById('m_name').innerText = MODAL_DATA.name;
        document.getElementById('m_sid').innerText = MODAL_DATA.sid;
        document.getElementById('modalOverlay').style.display = 'flex';
    }

    function closeModal() { document.getElementById('modalOverlay').style.display = 'none'; }

    function printAction() {
        const p = window.open('', '', 'width=400,height=600');
        p.document.write(`<html><body style="text-align:center;font-family:sans-serif;padding:20px;">
            <h3>SL DIAGNOSTICS</h3>
            <p style="margin:0;">SID: ${MODAL_DATA.sid}</p>
            <p style="margin:5px 0;">Patient: ${MODAL_DATA.name}</p>
            <hr>
            <h1 style="font-size:70px;margin:20px 0;">${MODAL_DATA.token}</h1>
            <p>Date: ${new Date().toLocaleDateString()}</p>
            <p>Please wait for your turn.</p>
        </body></html>`);
        p.document.close();
        p.print();
        p.close();
    }
</script>

</body>
</html>