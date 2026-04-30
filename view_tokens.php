<?php
// view_tokens.php - Updated for Full Screen, Search, Color Coding, and Print Filtering
include 'config/db_connect.php'; // Ensure this file correctly establishes $mysql_conn

// Variables
$current_date = date('Y-m-d');
$tokens_by_sid = [];
$message = '';
$search_sid = '';
// Set the specific date string as requested for the display and print header
$display_date = "November 14, 2025"; 

// PHP variable to store all token data for JavaScript printing (without HTML escaping yet)
$js_tokens_data = []; 

if ($_POST && isset($_POST['search_sid'])) {
    $search_sid = trim($_POST['search_sid']);
}

// --- Fetch all assigned tokens for the current date from MySQL ---
$query = "
    SELECT 
        t.sid_no, 
        t.pat_name, 
        t.token_number, 
        t.status,       /* Fetch status for color coding */
        d.dept_name
    FROM 
        tokens t
    JOIN 
        departments d ON t.dept_id = d.id
    WHERE 
        t.created_date = ?
";

$params = [$current_date];
$types = 's';

if (!empty($search_sid)) {
    $query .= " AND t.sid_no = ?";
    $params[] = $search_sid;
    $types .= 's';
}

$query .= " ORDER BY t.sid_no, d.dept_name";

$stmt = mysqli_prepare($mysql_conn, $query);

if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $sid_no = htmlspecialchars($row['sid_no']);
        $pat_name = htmlspecialchars($row['pat_name']);
        $dept_name = htmlspecialchars($row['dept_name']);
        $token_number = htmlspecialchars($row['token_number']);
        $token_status = $row['status'];

        // Grouping data by SID number for PHP Display
        if (!isset($tokens_by_sid[$sid_no])) {
            $tokens_by_sid[$sid_no] = [
                'pat_name' => $pat_name,
                'tokens' => []
            ];
        }
        $tokens_by_sid[$sid_no]['tokens'][] = [
            'dept' => $dept_name,
            'token' => $token_number,
            'status' => $token_status // Store status for coloring
        ];
        
        // Storing all data (un-escaped) for JavaScript printing/filtering
        if ($row['status'] !== 'completed') { // Only add non-completed tokens to the print data
             $js_tokens_data[] = [
                'sid_no' => $row['sid_no'],
                'pat_name' => $row['pat_name'],
                'dept' => $row['dept_name'],
                'token' => $row['token_number'],
                'status' => $row['status']
             ];
        }
    }
    mysqli_stmt_close($stmt);

    if (empty($tokens_by_sid)) {
        $message = "No tokens have been assigned for today, " . $display_date;
        if (!empty($search_sid)) {
            $message = "No tokens found for SID: **$search_sid** on " . $display_date;
        }
    }
} else {
    $message = "Error preparing token fetch query: " . mysqli_error($mysql_conn);
    error_log($message);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Today's Assigned Tokens - QMS Display</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* CSS for Full Screen Display */
        :root {
            --primary-color: #007bff;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
            --light-bg: #f4f7f6;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        body { 
            font-family: 'Poppins', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 0; 
            padding: 0; 
            background-color: var(--light-bg); 
            color: #333; 
        }
        .navbar { 
            background-color: var(--primary-color); 
            color: white; 
            padding: 15px 50px; 
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); 
        }
        .navbar h1 { 
            margin: 0; 
            font-size: 1.8em; 
        }
        .container { 
            width: 95%; /* Full width */
            margin: 30px auto; 
        }
        .card { 
            background-color: white; 
            padding: 30px; 
            border-radius: 12px; 
            box-shadow: var(--shadow); 
            margin-bottom: 20px; 
        }
        .card-header-content {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
        }
        .card-header-content h2 { 
            color: var(--primary-color); 
            padding-bottom: 10px; 
            margin: 0;
            border-bottom: none;
        }
        .instruction-text {
            color: var(--danger-color);
            font-weight: 600;
            font-size: 1.1em;
            margin-top: 0;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef; /* Added back the separator */
            margin-bottom: 20px;
        }
        .alert { 
            padding: 15px; 
            margin-bottom: 20px; 
            border-radius: 8px; 
            font-weight: bold; 
            background-color: #ffe5cc; 
            color: #856404; 
            border: 1px solid #ffe0b3; 
        }

        /* Search Form & Print Button */
        .search-form-container { 
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .search-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .search-form label {
            font-weight: 600;
        }
        .search-form input[type="text"] {
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            width: 250px;
        }
        .search-form button, .search-form a, .print-btn {
            padding: 10px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            color: white;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: background-color 0.3s;
        }
        .search-form button { background-color: var(--primary-color); }
        .search-form button:hover { background-color: #0056b3; }
        .search-form a { background-color: var(--info-color); }
        .search-form a:hover { background-color: #117a8b; }
        .print-btn { 
            background-color: var(--success-color); /* Use a distinct color for printing */
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .print-btn:hover {
            background-color: #218838;
        }

        /* Token Display Styles */
        .token-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .sid-group { 
            border: 1px solid #ccc; 
            border-radius: 8px; 
            padding: 15px; 
            background-color: #f9f9f9; 
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            page-break-inside: avoid; /* Added for print layout consideration */
        }
        .sid-group h3 { 
            margin-top: 0; 
            color: #555; 
            font-size: 1.2em; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            border-bottom: 1px dashed #ddd; 
            padding-bottom: 10px; 
            margin-bottom: 10px; 
        }
        .patient-info {
            font-weight: 600;
            color: var(--primary-color);
        }
        .token-list { 
            margin-top: 10px;
        }
        .token-item { 
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dotted #eee;
        }
        .token-item:last-child { border-bottom: none; }

        .token-status { 
            font-weight: bold; 
            font-size: 1.3em;
            padding: 3px 8px;
            border-radius: 4px;
            min-width: 100px;
            text-align: center;
        }
        /* Color Logic */
        .status-completed { 
            color: var(--success-color); 
            border: 1px solid var(--success-color);
            background-color: #e6ffe6;
        }
        .status-pending, .status-called { 
            color: var(--danger-color); 
            border: 1px solid var(--danger-color);
            background-color: #ffeded;
        }
        
        /* --- PRINT STYLES: Minimal as JS will generate a new window --- */
        @media print {
            .navbar, .search-form-container, .alert {
                display: none;
            }
            .container {
                width: 100%;
                margin: 0;
            }
            .card {
                padding: 0;
                box-shadow: none;
            }
            /* Ensure the main grid tokens print well */
            .token-grid {
                display: block;
            }
            .sid-group {
                border: 1px solid #000;
                margin-bottom: 15px;
                page-break-inside: avoid;
            }
        }
    </style>
    <script>
        // Inject PHP data structure containing ALL active tokens for filtering/printing
        const ALL_ACTIVE_TOKENS = <?php echo json_encode($js_tokens_data); ?>;

        function printPendingTokens() {
            // Filter tokens: only include 'pending' or 'called' status
            const tokensToPrint = ALL_ACTIVE_TOKENS.filter(token => 
                token.status === 'pending' || token.status === 'called'
            );

            if (tokensToPrint.length === 0) {
                alert("No pending tokens found to print. Only uncompleted tokens are printed.");
                return;
            }

            // Group by SID for clean display on the printout
            const groupedTokens = tokensToPrint.reduce((acc, current) => {
                if (!acc[current.sid_no]) {
                    acc[current.sid_no] = {
                        pat_name: current.pat_name,
                        tokens: []
                    };
                }
                acc[current.sid_no].tokens.push(current);
                return acc;
            }, {});

            let printContent = `
                <div style="text-align: center; margin-bottom: 15px;">
                    <h1 style="margin: 0; font-size: 1.8em; color: #000;">SL QMS</h1>
                    <p style="font-size: 1.1em; margin: 5px 0 0;">Date: <?php echo $display_date; ?></p>
                </div>
                <div style="border-top: 2px solid #000; padding-top: 10px; margin-bottom: 15px; text-align: center;">
                    <h2 style="margin: 0; font-size: 1.5em; color: #000; padding-bottom: 5px;">Department wise Tokens</h2>
                    <p style="font-size: 1em; font-weight: 600; color: #dc3545; margin-top: 5px;">
                        Please wait for your token number to be called.
                    </p>
                </div>
                <div style="margin-top: 20px;">
            `;

            for (const sid in groupedTokens) {
                const data = groupedTokens[sid];
                printContent += `
                    <div style="border: 1px solid #ccc; padding: 10px; margin-bottom: 15px; border-radius: 4px; page-break-inside: avoid;">
                        <h3 style="margin: 0; font-size: 1.1em; color: #007bff; border-bottom: 1px dashed #ddd; padding-bottom: 5px;">
                            SID: ${sid} (${data.pat_name})
                        </h3>
                        <div style="margin-top: 10px;">
                            ${data.tokens.map(item => `
                                <div style="display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dotted #eee;">
                                    <span style="font-weight: 500;">${item.dept}</span>
                                    <span style="font-weight: bold; font-size: 1.2em; color: #dc3545;">${item.token}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }

            printContent += `</div>`;

            // Open a new window and write the content
            const printWindow = window.open('', '', 'height=600,width=800');
            printWindow.document.write('<html><head><title>Pending Tokens</title>');
            // Inject print-specific CSS for better layout
            printWindow.document.write('<style>');
            printWindow.document.write(`
                body { font-family: 'Arial', sans-serif; color: #000; margin: 20px; }
                h1, h2, h3 { color: #000 !important; }
                @page { margin: 15mm; }
            `);
            printWindow.document.write('</style></head><body>');
            printWindow.document.write(printContent);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            
            // Wait for content to load before printing
            printWindow.onload = function() {
                printWindow.focus();
                printWindow.print();
                printWindow.close();
            };
        }
    </script>
</head>
<body>
     <nav class="navbar" style="
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 25px;
    background-color: #ffffff;
    min-height: 70px;
    border-bottom: 3px solid #2563eb;
    box-sizing: border-box;
    width: 100%;
    font-family: 'Segoe UI', Arial, sans-serif;
">
    <a href="dashboard.php" style="
        font-size: 20px;
        font-weight: 900;
        color: #000000;
        text-decoration: none;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    ">
      
		<h1><i class="bi bi-print"></i> View Or Print Token</h1>
    </a>

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
</nav>
    
    <div class="container">
        
        <div class="card">
            <div class="card-header-content">
                <h2><i class="bi bi-list-check"></i> Department wise Tokens</h2>
            </div>
            <p class="instruction-text">
                <i class="bi bi-bell-fill"></i> Please wait for your token number to be called.
            </p>
            
            <div class="search-form-container"> 
                <form method="POST" class="search-form">
                    <label for="search_sid"><i class="bi bi-person-circle"></i> Search by SID No:</label>
                    <input type="text" name="search_sid" id="search_sid" value="<?php echo htmlspecialchars($search_sid); ?>" placeholder="Enter SID number">
                    <button type="submit"><i class="bi bi-search"></i> Search</button>
                    <a href="view_tokens.php"><i class="bi bi-arrow-counterclockwise"></i> Reset Filter</a>
                </form>
                <button type="button" class="print-btn" onclick="printPendingTokens()"><i class="bi bi-printer-fill"></i> Print Pending Tokens</button>
            </div>
            
            <?php if ($message) { ?>
                <div class="alert">
                    <?php echo $message; ?>
                </div>
            <?php } ?>

            <?php if (!empty($tokens_by_sid)) { ?>
                <div class="token-grid">
                    <?php foreach ($tokens_by_sid as $sid => $data) { 
                    ?>
                        <div class="sid-group">
                            <h3>
                                <span class="patient-info">SID: **<?php echo $sid; ?>** Patient :<?php echo $data['pat_name']; ?></span>
                            </h3>
                            <div class="token-list">
                                <?php foreach ($data['tokens'] as $token_item) { 
                                    
                                    $status_class = '';
                                    
                                    if ($token_item['status'] === 'completed') {
                                        $status_class = 'status-completed';
                                    } elseif ($token_item['status'] === 'pending' || $token_item['status'] === 'called') {
                                        $status_class = 'status-pending'; 
                                    }
                                ?>
                                    <div class="token-item">
                                        <span><?php echo $token_item['dept']; ?></span>
                                        <div class="token-status <?php echo $status_class; ?>">
                                            <?php echo $token_item['token']; ?>
                                        </div>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>
        </div>
    </div>
</body>
</html>