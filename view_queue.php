<?php
// display_queue.php - Responsive Header Fix
include 'config/db_connect.php'; 

$current_date = date('Y-m-d');

if (isset($_GET['ajax'])) {
    $active_tokens_by_dept = [];
    $department_names = []; 

    $allowed_search_depts = ['2D Echo', 'CT', 'LAB', 'MRI', 'OPG', 'Xray', 'UltraSound', 'TMT', 'Mamography'];
    $dept_placeholders = implode(',', array_fill(0, count($allowed_search_depts), '?'));
    
    $query = "
        SELECT t.token_number, t.sid_no, t.pat_name, d.dept_name, t.status
        FROM tokens t
        JOIN departments d ON t.dept_id = d.id
        WHERE t.created_date = ? 
        AND d.dept_name IN ($dept_placeholders) 
        AND t.status IN ('pending', 'called')
        ORDER BY t.id ASC"; 

    $stmt = mysqli_prepare($mysql_conn, $query);
    $types = 's' . str_repeat('s', count($allowed_search_depts));
    $params = array_merge([$current_date], $allowed_search_depts);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $real_dept = $row['dept_name'];
        $target_col = $real_dept;

        if ($real_dept == 'LAB') {
            $target_col = 'SAMPLE';
        } elseif ($real_dept == 'OPG' || $real_dept == 'Xray') {
            $target_col = 'OPG / X RAY';
        }

        if (!isset($active_tokens_by_dept[$target_col])) {
            $active_tokens_by_dept[$target_col] = [];
            $department_names[] = $target_col;
        }

        if (count($active_tokens_by_dept[$target_col]) < 8) {
            $active_tokens_by_dept[$target_col][] = [
                'token' => htmlspecialchars($row['token_number']),
                'sid' => htmlspecialchars($row['sid_no']),
                'name' => htmlspecialchars($row['pat_name']),
                'status' => $row['status']
            ];
        }
    }

    $final_order = ['2D Echo', 'CT', 'SAMPLE', 'MRI', 'OPG / X RAY', 'UltraSound', 'TMT', 'Mamography'];
    $ordered_dept_names = array_intersect($final_order, $department_names);

    $max_rows = 0;
    foreach ($active_tokens_by_dept as $tokens) { $max_rows = max($max_rows, count($tokens)); }

    header('Content-Type: application/json');
    echo json_encode([
        'depts' => array_values($ordered_dept_names), 
        'tokens' => $active_tokens_by_dept, 
        'max' => $max_rows
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Live Queue - SL Diagnostics</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --primary: #2563eb; --danger: #dc3545; --white: #ffffff; }
        body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background-color: #f1f5f9; overflow: hidden; }
        
        .navbar {
            background: var(--white); padding: 10px 25px;
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 4px solid var(--primary);
        }

        .queue-table-container { width: 100%; height: calc(100vh - 70px); padding: 10px; box-sizing: border-box; }
        
        .queue-table {
            width: 100%; border-collapse: separate; border-spacing: 10px;
            table-layout: fixed; background: transparent;
        }

        /* Fixed Header for Long Names */
        .queue-table th {
            background: var(--primary); color: white;
            padding: 10px 5px; 
            font-size: clamp(1rem, 2.5vw, 1.6rem); /* பான்ட் தானாக சுருங்கும் */
            text-transform: uppercase; border-radius: 8px;
            height: 90px; /* உயரத்தை சற்று அதிகரித்துள்ளேன் */
            vertical-align: middle;
            word-wrap: break-word; /* வார்த்தை அடுத்த வரிசைக்கு செல்லும் */
            overflow-wrap: break-word;
            hyphens: auto;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .queue-table td {
            background: var(--white); border: 1px solid #e2e8f0;
            height: 120px; text-align: center; vertical-align: middle; border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .token-num { font-size: 3.2rem; font-weight: 900; color: #1e293b; line-height: 1; display: block; }
        .token-sid { font-size: 0.85rem; color: #64748b; font-weight: bold; display: block; margin-bottom: 2px; }
        .token-name { font-size: 1rem; color: #334155; font-weight: 700; text-transform: uppercase; margin-top: 4px; display: block; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; padding: 0 8px; }

        .status-called { background-color: #fff7ed !important; border: 4px solid #f97316 !important; }
        .status-called .token-num { color: var(--danger); animation: blink 0.8s infinite alternate; }
        
        @keyframes blink { from { opacity: 1; transform: scale(1); } to { opacity: 0.5; transform: scale(0.98); } }

        .no-data { text-align: center; margin-top: 150px; color: #64748b; font-size: 2rem; font-weight: bold; }
    </style>
</head>
<body>

<div class="navbar">
    <h1 style="margin:0; font-size: 1.8rem; color: #1e293b;">
        <i class="bi bi-activity" style="color: var(--primary);"></i> SL DIAGNOSTICS - LIVE QUEUE
    </h1>
    <div id="live-clock" style="font-size: 1.6rem; font-weight: 800; color: var(--primary);"></div>
</div>

<div class="queue-table-container">
    <div id="display-area">
        <div class="no-data">Initializing System...</div>
    </div>
</div>

<script>
    function updateClock() {
        const now = new Date();
        $('#live-clock').text(now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit', second:'2-digit'}));
    }

    function updateDisplay() {
        $.ajax({
            url: window.location.pathname + '?ajax=1',
            type: 'GET',
            success: function(res) {
                if (!res.depts || res.depts.length === 0) {
                    $('#display-area').html('<div class="no-data"><i class="bi bi-info-circle"></i> No Active Patient Tokens</div>');
                    return;
                }

                let html = '<table class="queue-table"><thead><tr>';
                res.depts.forEach(d => { html += `<th>${d}</th>`; });
                html += '</tr></thead><tbody>';

                for (let i = 0; i < res.max; i++) {
                    html += '<tr>';
                    res.depts.forEach(d => {
                        let row = (res.tokens[d] && res.tokens[d][i]) ? res.tokens[d][i] : null;
                        if (row) {
                            html += `<td class="status-${row.status}">
                                <span class="token-sid">SID: ${row.sid}</span>
                                <span class="token-num">${row.token}</span>
                                <span class="token-name">${row.name}</span>
                            </td>`;
                        } else {
                            html += '<td style="background:transparent; border:none;"></td>';
                        }
                    });
                    html += '</tr>';
                }
                html += '</tbody></table>';
                $('#display-area').html(html);
            }
        });
    }

    $(document).ready(function() {
        updateDisplay();
        setInterval(updateDisplay, 5000);
        setInterval(updateClock, 1000);
        updateClock();
    });
</script>

</body>
</html>