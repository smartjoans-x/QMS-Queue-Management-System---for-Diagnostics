<?php
session_start();
include 'config/db_connect.php';

// லாகின் செக்
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// செஷன்ல இருந்து ரோல் மற்றும் டிபார்ட்மென்ட் ஐடியை எடுக்கிறோம்
$user_role = $_SESSION['role']; // 'admin', 'department_user', 'admin1'
$user_dept_id = $_SESSION['department_id']; // உன்னோட டேபிள்ல column name 'department_id'

// பில்டர் செட்டிங்ஸ்
$selected_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');

// --- பில்டர் லாஜிக் ---
if ($user_role === 'admin' || $user_role === 'admin1') {
    // அட்மின் என்றால் டிராப்டவுன்ல இருந்து செலக்ட் பண்றத எடுக்கும், இல்லையென்றால் 'all'
    $selected_dept = (isset($_GET['dept_filter']) && $_GET['dept_filter'] !== '') ? $_GET['dept_filter'] : 'all';
} else {
    // department_user என்றால் கண்டிப்பா அவன் டிபார்ட்மென்ட் மட்டும் தான்
    $selected_dept = $user_dept_id;
}

// அட்மினுக்காக எல்லா டிபார்ட்மென்ட் லிஸ்ட்டையும் எடுக்கிறோம்
$all_depts_res = mysqli_query($mysql_conn, "SELECT id, dept_name FROM departments ORDER BY dept_name ASC");

// --- SQL WHERE Clause தயார் செய்தல் ---
$where_clause = "WHERE created_date = '$selected_date'";

if ($selected_dept !== 'all') {
    $where_clause .= " AND dept_id = " . (int)$selected_dept;
}

// 1. Summary Stats (மொத்த டோக்கன், முடிந்தது, சராசரி நேரம்)
$summary_query = "SELECT 
    COUNT(*) as total_tokens,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
    AVG(TIMESTAMPDIFF(SECOND, accepted_date, completed_date)) as avg_time_overall
    FROM tokens $where_clause";
$summary_res = mysqli_query($mysql_conn, $summary_query);
$summary = mysqli_fetch_assoc($summary_res);

// 2. Department Wise Efficiency (டேபிள்ல காட்டுறதுக்கு)
$dept_summary_query = "SELECT 
    d.dept_name,
    COUNT(t.id) as total,
    AVG(TIMESTAMPDIFF(SECOND, t.accepted_date, t.completed_date)) as avg_seconds
    FROM tokens t
    JOIN departments d ON t.dept_id = d.id 
    $where_clause AND t.status = 'completed'
    GROUP BY t.dept_id";
$dept_summary_res = mysqli_query($mysql_conn, $dept_summary_query);

function formatTime($seconds) {
    if (!$seconds || $seconds < 0) return "0s";
    $m = floor($seconds / 60);
    $s = $seconds % 60;
    return ($m > 0 ? $m . "m " : "") . $s . "s";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Report - SL Diagnostics</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #2563eb; --bg: #f1f5f9; --card: #ffffff; --secondary: #64748b; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; color: #1e293b; }
        .top-bar { background: var(--card); padding: 15px 40px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .container { padding: 30px 40px; max-width: 1800px; margin: auto; }
        
        .filter-grid {
            background: var(--card); padding: 20px; border-radius: 12px;
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px; align-items: flex-end; margin-bottom: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        select, input { width: 100%; padding: 11px; border: 1px solid #cbd5e1; border-radius: 8px; margin-top: 5px; box-sizing: border-box; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--card); padding: 25px; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 15px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; background: #dbeafe; color: var(--primary); }
        
        .main-content { display: grid; grid-template-columns: 1fr 2.5fr; gap: 25px; }
        .content-card { background: var(--card); border-radius: 16px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; border-bottom: 2px solid #f1f5f9; color: var(--secondary); font-size: 0.8rem; text-transform: uppercase; }
        td { padding: 14px 12px; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
        .badge { background: #e0f2fe; color: #0369a1; padding: 5px 10px; border-radius: 6px; font-weight: 700; }
        .btn-submit { background: var(--primary); color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; }

        @media (max-width: 1100px) { .main-content { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    /* Modal (Popup) Styles */
    .modal {
        display: none; 
        position: fixed; 
        z-index: 1000; 
        left: 0; top: 0; width: 100%; height: 100%; 
        background-color: rgba(0,0,0,0.6);
        backdrop-filter: blur(5px);
    }
    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 25px;
        border-radius: 15px;
        width: 70%;
        max-width: 900px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        position: relative;
    }
    .close-btn {
        position: absolute; right: 20px; top: 15px;
        font-size: 28px; font-weight: bold; cursor: pointer; color: #666;
    }
    .btn-chart {
        background: #f59e0b; color: white; padding: 12px 25px; border: none; 
        border-radius: 8px; cursor: pointer; font-weight: bold; margin-left: 10px;
    }
</style>
<div class="top-bar">
    <h1 style="font-size: 1.4rem; color: var(--primary); margin:0;">SL Diagnostics <span style="color:#334155">QMS Analytics</span></h1>
    <a href="dashboard.php" style="text-decoration:none; color:var(--primary); font-weight:bold;"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
</div>

<div class="container">
    <form method="GET" class="filter-grid">
        <div>
            <label style="font-size: 0.8rem; font-weight: 600;">Select Date</label>
            <input type="date" name="report_date" value="<?php echo $selected_date; ?>">
        </div>

        <?php if ($user_role === 'admin' || $user_role === 'admin1'): ?>
        <div>
            <label style="font-size: 0.8rem; font-weight: 600;">Filter by Department</label>
            <select name="dept_filter">
                <option value="all">View All Departments</option>
                <?php 
                mysqli_data_seek($all_depts_res, 0);
                while($d = mysqli_fetch_assoc($all_depts_res)) {
                    $sel = ($selected_dept == $d['id']) ? 'selected' : '';
                    echo "<option value='{$d['id']}' $sel>{$d['dept_name']}</option>";
                } ?>
            </select>
        </div>
        <?php else: 
            $dept_query = mysqli_query($mysql_conn, "SELECT dept_name FROM departments WHERE id = " . (int)$user_dept_id);
            $dept_data = mysqli_fetch_assoc($dept_query);
        ?>
        <div>
            <label style="font-size: 0.8rem; font-weight: 600;">Your Department</label>
            <input type="text" value="<?php echo $dept_data['dept_name']; ?>" disabled style="background:#f8fafc;">
            <input type="hidden" name="dept_filter" value="<?php echo $user_dept_id; ?>">
        </div>
        <?php endif; ?>

        <button type="submit" class="btn-submit"><i class="bi bi-search"></i> Show Results</button>
		
		<button type="button" onclick="exportTableToCSV('report.csv')" class="btn-submit" style="background:#059669; margin-left:10px;">
    <i class="bi bi-file-earmark-excel"></i> Export Excel
</button>

<button type="button" class="btn-chart" onclick="openChartModal()">
    <i class="bi bi-bar-chart-line-fill"></i> View Performance Chart
</button>

<div id="chartModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeChartModal()">&times;</span>
        <h2 style="text-align:center; color: var(--primary); margin-bottom: 20px;">
            Department Performance (Avg Time in Minutes)
        </h2>
        <div style="width: 100%; height: 400px;">
            <canvas id="deptChart"></canvas>
        </div>
    </div>
</div>
    </form>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div><small style="color:var(--secondary)">Total Patients</small><div><strong><?php echo $summary['total_tokens'] ?? 0; ?></strong></div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#dcfce7; color:#10b981;"><i class="bi bi-check-circle-fill"></i></div>
            <div><small style="color:var(--secondary)">Completed</small><div><strong><?php echo $summary['completed_count'] ?? 0; ?></strong></div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7; color:#f59e0b;"><i class="bi bi-stopwatch-fill"></i></div>
            <div><small style="color:var(--secondary)">Avg. Processing Time</small><div><strong><?php echo formatTime($summary['avg_time_overall']); ?></strong></div></div>
        </div>
    </div>

    <div class="main-content">
        <div class="content-card">
            <h3 style="font-size: 1rem; margin-top:0; color:var(--primary);">Efficiency by Dept</h3>
            <table>
                <thead>
                    <tr><th>Dept</th><th>Avg Time</th></tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($dept_summary_res)) { ?>
                    <tr>
                        <td><strong><?php echo $row['dept_name']; ?></strong></td>
                        <td><span class="badge"><?php echo formatTime($row['avg_seconds']); ?></span></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="content-card">
            <h3 style="font-size: 1rem; margin-top:0; color:var(--primary);">Completed Patient Logs</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
    <tr>
        <th>SID</th>
        <th>Patient Name</th>
        <th>Department</th>
        <th>Completed At</th> <th>TAT (Duration)</th>
    </tr>
</thead>
                    <tbody>
    <?php
    $log_query = "SELECT t.*, d.dept_name 
        FROM tokens t 
        JOIN departments d ON t.dept_id = d.id 
        $where_clause AND t.status = 'completed'
        ORDER BY t.completed_date DESC";
    $log_res = mysqli_query($mysql_conn, $log_query);
    while($row = mysqli_fetch_assoc($log_res)) {
        $diff = strtotime($row['completed_date']) - strtotime($row['accepted_date']);
        
        
        $comp_time = date('h:i A', strtotime($row['completed_date']));
    ?>
    <tr>
        <td><strong><?php echo $row['sid_no']; ?></strong></td>
        <td><?php echo $row['pat_name']; ?></td>
        <td><small><?php echo $row['dept_name']; ?></small></td>
        <td><span style="color: var(--secondary); font-weight: 600;"><?php echo $comp_time; ?></span></td> <td><strong style="color:var(--primary);"><?php echo formatTime($diff); ?></strong></td>
    </tr>
    <?php } if(mysqli_num_rows($log_res) == 0) echo "<tr><td colspan='5' style='text-align:center;'>No records found.</td></tr>"; ?>
</tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<Script>

function exportTableToCSV(filename) {
    var csv = [];
    var rows = document.querySelectorAll("table tr");
    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll("td, th");
        for (var j = 0; j < cols.length; j++) row.push(cols[j].innerText);
        csv.push(row.join(","));
    }
    var csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
    var downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}

</Script>

<script>

<?php
    $labels = [];
    $data = [];
    mysqli_data_seek($dept_summary_res, 0); 
    while($row = mysqli_fetch_assoc($dept_summary_res)) {
        $labels[] = $row['dept_name'];
        
        $data[] = round($row['avg_seconds'] / 60, 2); 
    }
?>

const deptLabels = <?php echo json_encode($labels); ?>;
const deptData = <?php echo json_encode($data); ?>;

function openChartModal() {
    document.getElementById('chartModal').style.display = "block";
    
    const ctx = document.getElementById('deptChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: deptLabels,
            datasets: [{
                label: 'Avg Processing Time (Minutes)',
                data: deptData,
                backgroundColor: 'rgba(37, 99, 235, 0.7)',
                borderColor: 'rgba(37, 99, 235, 1)',
                borderWidth: 2,
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Minutes' }
                }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });
}

function closeChartModal() {
    document.getElementById('chartModal').style.display = "none";
}


window.onclick = function(event) {
    let modal = document.getElementById('chartModal');
    if (event.target == modal) {
        modal.style.display = "none";
    }
}
</script>
<script>
    
    setTimeout(function() {
        
        window.location.href = window.location.pathname;
    }, 5000);
</script>

</body>
</html>