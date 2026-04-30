<?php
session_start();
// Include the database connection configuration
include 'config/db_connect.php'; // Ensure this file correctly establishes $mysql_conn

$current_date = date('Y-m-d');
$lab_departments = [
    'Biochemistry', 'Cytology', 'Genetic', 'Haematology', 'Histopathology',
    'Mamography', 'MICROBIOLOGY', 'Molecular Biology', 'Out source',
    'Pathology', 'Sample Collection', 'Serology'
];

// --- NEW REQUIRED DEPARTMENTS ---
$fixed_display_departments = ['Xray', 'UltraSound', 'CT', 'ECG', 'EEG'];


// --- FUNCTION TO FETCH NOTIFICATIONS (New Inline Logic) ---
function fetch_notifications($mysql_conn) {
    // Select all columns, order by ID descending to get the newest call first
    $query = "SELECT t1.token_number, t1.pat_name, t1.dept_id, t2.dept_name
              FROM popup_notifications t1
              JOIN departments t2 ON t1.dept_id = t2.id
              ORDER BY t1.id DESC";
    $result = mysqli_query($mysql_conn, $query);
    
    $notifications = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            // Include department display name for the announcement script
            $display_dept_name = in_array(trim($row['dept_name']), $GLOBALS['lab_departments']) ? 'LAB' : trim($row['dept_name']);
            $notifications[] = [
                'token_number' => $row['token_number'],
                'pat_name' => $row['pat_name'],
                'dept_id' => $row['dept_id'],
                'display_dept_name' => $display_dept_name
            ];
        }
    }
    return $notifications;
}
// --- END FUNCTION TO FETCH NOTIFICATIONS ---


// --- Status Fetching Function (Department Status) ---
function fetch_department_stats($mysql_conn, $current_date, $lab_departments, $fixed_display_departments) {
    // 1. Get ALL departments and their display name mapping
    $all_dept_map = []; 
    $db_dept_name_map = [];
    $required_dept_ids = [];
    
    $result = mysqli_query($mysql_conn, "SELECT id, dept_name FROM departments ORDER BY dept_name ASC");
    if (!$result) return ['stats' => [], 'ids' => [], 'map' => []];

    while ($row = mysqli_fetch_assoc($result)) {
        $dept_name = trim($row['dept_name']);
        $display_name = in_array($dept_name, $lab_departments) ? 'LAB' : $dept_name;
        $all_dept_map[$row['id']] = $display_name;
        $db_dept_name_map[$row['id']] = $dept_name;
        
        // Track the IDs of the fixed departments
        if (in_array($dept_name, $fixed_display_departments)) {
            $required_dept_ids[] = (int)$row['id'];
        }
    }

    // 2. The IDs to query: All departments that generated tokens TODAY + the fixed display departments
    $active_db_dept_ids = $required_dept_ids; // Start with fixed departments
    $active_display_map = [];
    
    $active_query = "SELECT DISTINCT dept_id FROM tokens WHERE created_date = '$current_date'";
    $active_result = mysqli_query($mysql_conn, $active_query);

    if ($active_result) {
        while ($row = mysqli_fetch_assoc($active_result)) {
            $dept_id = (int)$row['dept_id'];
            if (isset($all_dept_map[$dept_id]) && !in_array($dept_id, $active_db_dept_ids)) {
                // Add token-active IDs only if they aren't already in the list (from fixed display)
                $active_db_dept_ids[] = $dept_id;
            }
        }
    }

    // Now, create the active_display_map and initialize stats for FIXED display departments
    $aggregated_stats = [];
    $all_active_display_names = [];
    
    foreach ($active_db_dept_ids as $dept_id) {
        if (isset($all_dept_map[$dept_id])) {
            $display_name = $all_dept_map[$dept_id];
            $db_name = $db_dept_name_map[$dept_id];

            // Only initialize the stats for the FIXED departments for the primary display
            if (in_array($db_name, $fixed_display_departments)) {
                 $aggregated_stats[$db_name] = [
                     'pending' => 0,
                     'current_token' => 'None',
                     'complete' => 0,
                     'last_completed' => 'None'
                 ];
            }
            // Populate maps for *all* active IDs, required for token announcement script
            $active_display_map[$dept_id] = $display_name;
            $all_active_display_names[] = $display_name;
        }
    }

    $unique_display_names = array_unique($all_active_display_names);
    $sql_dept_ids_string = !empty($active_db_dept_ids) ? implode(',', array_map('intval', $active_db_dept_ids)) : '0';

    // 3. Fetch token data for active departments
    if (empty($active_db_dept_ids)) {
        // Return initialized stats for fixed depts, even if no tokens are active
        return ['stats' => $aggregated_stats, 'ids' => $active_db_dept_ids, 'map' => $active_display_map];
    }

    $query = "SELECT t.dept_id, t.token_number, t.status
        FROM tokens t
        WHERE t.dept_id IN (" . $sql_dept_ids_string . ") AND t.created_date = '$current_date'
        ORDER BY t.id ASC"; 
    $result = mysqli_query($mysql_conn, $query);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $dept_id = $row['dept_id'];
            $db_name = $db_dept_name_map[$dept_id];
            $status = $row['status'];
            $token = $row['token_number'];

            // Only process stats for the departments that need to be displayed in the grid
            if (!in_array($db_name, $fixed_display_departments)) continue;

            if (!isset($aggregated_stats[$db_name])) continue; 

            // Aggregation logic
            if ($status === 'pending') {
                $aggregated_stats[$db_name]['pending']++;
            } elseif ($status === 'completed') {
                $aggregated_stats[$db_name]['complete']++;
                if ($token !== null) {
                    $aggregated_stats[$db_name]['last_completed'] = $token;
                }
                if ($aggregated_stats[$db_name]['current_token'] === $token) {
                     $aggregated_stats[$db_name]['current_token'] = 'None';
                }
            } elseif ($status === 'called' && $token !== null) {
                $aggregated_stats[$db_name]['current_token'] = $token;
            }
        }
    }
    
    // Sort the final stats by department name for stable display (optional, but good practice)
    uksort($aggregated_stats, 'strcasecmp');

    return ['stats' => $aggregated_stats, 'ids' => $active_db_dept_ids, 'map' => $active_display_map];
}
// --- END Status Fetching Function ---


// --- AJAX ENDPOINT RESPONSES ---
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'get_status_data') {
        $data = fetch_department_stats($mysql_conn, $current_date, $lab_departments, $fixed_display_departments);
        header('Content-Type: application/json');
        echo json_encode($data['stats']);
        exit;
    } elseif ($_GET['action'] === 'get_notifications') {
        // --- NEW AJAX ACTION FOR NOTIFICATIONS ---
        $notifications = fetch_notifications($mysql_conn);
        header('Content-Type: application/json');
        echo json_encode($notifications);
        exit;
    }
}
// --- END AJAX ENDPOINT RESPONSES ---


// --- Initial Load Data Fetching ---
$fetch_result = fetch_department_stats($mysql_conn, $current_date, $lab_departments, $fixed_display_departments);
$dept_stats = $fetch_result['stats'];
$all_db_dept_ids = $fetch_result['ids']; 

$initial_called_token = 'None'; 
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor - SL Diagnostic QMS Display</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        /* CSS Variables - Enhanced Styling */
        :root {
            --primary-color: #007bff;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
            --light-bg: #ffffff;
            --dark-bg: #343a40;
            --border-color: #e9ecef;
            --white: #ffffff;
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.3);
            --border-radius: 15px;
        }

        /* Base Styles */
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--dark-bg);
            color: var(--white);
            margin: 0;
            padding: 0;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Full Screen Overlay for Audio */
        #audio-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.85);
            z-index: 2000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        #audio-overlay button {
            background-color: var(--danger-color);
            color: var(--white);
            border: 3px solid var(--white);
            padding: 25px 40px;
            font-size: 2.5rem;
            font-weight: 700;
            border-radius: 15px;
            cursor: pointer;
            transition: background-color 0.3s;
            animation: pulse-overlay 1.5s infinite;
        }
        #audio-overlay button:hover {
            background-color: #ff3838;
            animation: none;
        }
        @keyframes pulse-overlay {
            0% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.7); }
            70% { box-shadow: 0 0 0 30px rgba(255, 255, 255, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0); }
        }

        /* Main Container */
        .monitor-container {
            display: flex;
            width: 95%;
            max-width: 1600px;
            height: 95vh;
            background-color: var(--light-bg);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        /* Side Container (Current Call) - Left Column */
        .side-container {
            width: 40%;
            padding: 40px;
            background: linear-gradient(145deg, var(--dark-bg), #1c232b);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            color: #ccc;
        }
        .side-container h3 {
            color: var(--info-color);
            font-size: 2.8rem;
            margin-bottom: 30px;
            text-align: center;
            font-weight: 700;
        }
        
        /* The Big Token Display */
        .call-box {
            background-color: var(--danger-color);
            color: var(--white);
            font-weight: 800;
            text-align: center;
            padding: 50px 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 0 30px rgba(220, 53, 69, 0.8);
            width: 90%;
            min-height: 400px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: all 0.5s ease-in-out;
        }
        .call-box .token-number {
            font-size: 8.5rem;
            margin-bottom: 5px;
            line-height: 1;
        }
        .call-box .pat-name {
            font-size: 2.2rem;
            opacity: 0.9;
            font-weight: 500;
            color: #ffe0e0;
        }
        .call-box.empty {
            background-color: #5a6268; 
            font-size: 3.5rem;
            opacity: 0.8;
            box-shadow: none;
        }
        
        /* Main Container (Department Status) - Right Column */
        .main-container {
            width: 60%;
            padding: 30px;
            background-color: var(--light-bg);
            color: #343a40;
            display: flex;
            flex-direction: column;
        }
        .main-container h3 {
            color: var(--primary-color);
            font-size: 2.2rem;
            margin-top: 0;
            margin-bottom: 25px;
            border-bottom: 3px solid var(--primary-color);
            padding-bottom: 10px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Department Cards Grid (Fixed to 2x2) */
        .dept-grid-wrapper {
            overflow: hidden; 
            flex-grow: 1;
            /* Ensure the wrapper doesn't take more space than needed for the grid */
            max-height: calc(100% - 70px); 
        }
        .dept-grid {
            display: grid;
            /* Fixed 2 columns, 2 rows for the 4 departments */
            grid-template-columns: 1fr 1fr; 
            grid-template-rows: 1fr 1fr; 
            gap: 20px;
            height: 100%;
        }
        
        /* Department Card styling */
        .dept-card {
            background-color: var(--white);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border-left: 6px solid var(--success-color);
            /* Cards are visible by default in HTML, no cycling needed */
            min-height: 180px; 
            transition: transform 0.3s, opacity 0.3s;
        }

        .dept-card h5 {
            font-size: 1.6rem;
            margin-top: 0;
            margin-bottom: 15px;
            color: var(--dark-bg);
            border-bottom: 1px dashed var(--border-color);
            padding-bottom: 8px;
            font-weight: 700;
        }
        .dept-card p {
            margin: 8px 0;
            font-size: 1.1rem;
            font-weight: 500;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        .dept-card p strong {
            font-size: 1.3rem;
            font-weight: 700;
        }
        
        /* Status Highlighting */
        .dept-card .pending { color: var(--danger-color); } 
        .dept-card .current { color: var(--primary-color); } 
        .dept-card .complete { color: var(--success-color); }

        /* Footer info */
        .side-container p {
            font-size: 0.9rem;
            opacity: 0.7;
            margin-bottom: 5px;
        }

        /* Pulse Animation Keyframes */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        .pulse-animation {
            animation: pulse 0.5s ease-in-out;
        }

        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .monitor-container {
                flex-direction: column;
                height: auto;
                min-height: 100vh;
            }
            .side-container, .main-container {
                width: 100%;
                padding: 20px;
            }
            .side-container {
                min-height: 50vh;
            }
            .call-box {
                min-height: 300px;
            }
            .call-box .token-number {
                font-size: 6rem;
            }
            .call-box .pat-name {
                font-size: 1.8rem;
            }
            .main-container h3 {
                font-size: 1.8rem;
            }
            .dept-grid {
                grid-template-columns: 1fr; /* Single column on smaller screens */
                grid-template-rows: auto;
            }
            .dept-card h5 {
                font-size: 1.4rem;
            }
        }
    </style>
</head>
<body>
    
    <div id="audio-overlay">
        <button onclick="enableAudio()" id="audio-enable-button">
            <i class="bi bi-volume-up-fill"></i> Tap to Enable Audio
        </button>
        <p style="margin-top: 20px; font-size: 1.2rem; color: #ccc;">(Required for voice announcements)</p>
    </div>
    
    <div class="monitor-container">
        <div class="side-container">
            <div>
                <h3><i class="bi bi-bell"></i> Now Serving</h3>
                <div class="call-box <?php echo $initial_called_token === 'None' ? 'empty' : ''; ?>" id="call-box" data-current-token="<?php echo $initial_called_token; ?>">
                    <?php if ($initial_called_token === 'None') { ?>
                        Waiting for Call
                    <?php } else { ?>
                        <div class="token-number"><?php echo htmlspecialchars($initial_called_token); ?></div>
                        <div class="pat-name">Patient Name Unavailable</div>
                    <?php } ?>
                </div>
            </div>
            
            <div style="text-align: center;">
                <?php if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') { ?>
                    <p style="color: var(--danger-color); font-weight: 600;">ADMIN NOTICE: Monitor is active.</p>
                <?php } ?>
                <p>Last Call Check: <span id="last-updated">--</span></p>
                <p>Status Data Refreshed: <span id="status-refreshed">--</span></p>
            </div>
        </div>
        
        <div class="main-container">
            <h3><i class="bi bi-list-columns-reverse"></i> Today's Active Department Status</h3>
            <div class="dept-grid-wrapper">
                <div class="dept-grid" id="dept-grid">
                    <?php
                    // Display hardcoded cards for the fixed departments
                    foreach ($fixed_display_departments as $dept_name) {
                        // Get initial stats or use defaults if not present
                        $stats = $dept_stats[$dept_name] ?? [
                            'pending' => 0,
                            'current_token' => 'None',
                            'complete' => 0,
                            'last_completed' => 'None'
                        ];
                        
                        $currentTokenDisplay = htmlspecialchars($stats['current_token']);
                        $lastCompletedDisplay = htmlspecialchars($stats['last_completed']);

                        // Use a consistent ID based on the department name for JS targeting
                        echo "<div class='dept-card' data-dept-name='" . htmlspecialchars($dept_name) . "'>";
                        echo "<h5>" . htmlspecialchars($dept_name) . "</h5>";
                        echo "<p>Waiting: <strong class='pending' id='pending-{$dept_name}'>" . ($stats['pending'] ?? 0) . "</strong></p>";
                        echo "<p>Current Token: <strong class='current' id='current-{$dept_name}'>" . $currentTokenDisplay . "</strong></p>";
                        echo "<p>Completed Today: <strong class='complete' id='complete-{$dept_name}'>" . ($stats['complete'] ?? 0) . "</strong></p>";
                        echo "<p>Last Completed: <strong id='last-completed-{$dept_name}'>" . $lastCompletedDisplay . "</strong></p>";
                        echo "</div>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Intervals remain the same
        const CALL_POLLING_INTERVAL = 3000;
        const STATUS_REFRESH_INTERVAL = 5000; // Refresh department status every 5 seconds
        const NOTIFICATION_DISPLAY_CYCLE = 5000;
        
        // Fixed department names for JS targeting
        const FIXED_DEPARTMENTS = <?php echo json_encode($fixed_display_departments); ?>;

        // Notification Cycling Variables
        let currentNotifications = [];
        let notificationIndex = 0;
        let notificationCycleInterval = null;
        let audioEnabled = false;
        let voicesLoaded = false;
        let preferredVoice = null;
        // The autoClickTimeout variable has been removed as auto-click is no longer reliable.

        const allDeptIds = <?php echo json_encode(array_map('intval', $all_db_dept_ids)); ?>;


        function formatTime(date = new Date()) {
            const timeString = date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            return timeString;
        }
        
        /**
         * Loads voices for the TTS engine, ensuring the browser is ready.
         */
        function loadVoices() {
            if (voicesLoaded || !('speechSynthesis' in window)) return;
            
            const setVoice = () => {
                const voices = speechSynthesis.getVoices();
                // Prioritize English or standard voices for clearer pronunciation of QMS terms
                preferredVoice = voices.find(
                    voice => voice.lang.includes('en') && (voice.name.includes('Google') || voice.name.includes('Microsoft'))
                ) || voices.find(voice => voice.lang.includes('en')) || voices[0];
                voicesLoaded = true;
                
                // If audio is already enabled (e.g., voices loaded asynchronously after initial click)
                if(audioEnabled) {
                     fetchNewNotifications();
                }
            };
            
            // This is crucial for cross-browser compatibility when loading voices
            if (speechSynthesis.onvoiceschanged !== undefined) {
                speechSynthesis.onvoiceschanged = setVoice;
            }
            setVoice(); 
        }
        
        /**
         * Enables audio playback after a user interaction (button click).
         */
        window.enableAudio = function() {
            if (audioEnabled) return;
            
            $('#audio-overlay').hide();
            audioEnabled = true;
            
            // 1. Initialise the audio context with a small dummy speak call (often necessary)
            const testSpeech = new SpeechSynthesisUtterance("Audio enabled.");
            testSpeech.volume = 0; // Keep it silent
            speechSynthesis.speak(testSpeech); 
            
            // 2. Load voices or proceed if they are already loaded
            if (!voicesLoaded) {
                 loadVoices();
            } else {
                 fetchNewNotifications();
            }

            // 3. Start the continuous polling for data
            startIntervals(); 
        }

        /**
         * Converts token and department info into spoken words and uses Web Speech API to announce it.
         */
        function announceToken(tokenNumber, departmentName) {
            // Check for both user permission and API availability
            if (!audioEnabled || !('speechSynthesis' in window) || !preferredVoice) {
                return;
            }
            
            const spokenToken = tokenNumber.split('').join(' ');

            const speech = new SpeechSynthesisUtterance();
            speech.voice = preferredVoice; // Use the loaded preferred voice
            
            speech.rate = 1.0; 
            speech.pitch = 1.0; 
            // Simplified and robust announcement phrase
            speech.text = `Token number ${spokenToken}, please proceed to ${departmentName} counter.`;
            
            speechSynthesis.cancel(); // Stop any announcement currently in progress
            speechSynthesis.speak(speech);
        }


        // --- Current Call Cycling Logic (Unchanged) ---
        function displayCurrentNotification() {
            const callBox = $('#call-box');
            
            if (currentNotifications.length === 0) {
                // No notifications available
                clearInterval(notificationCycleInterval);
                notificationCycleInterval = null;
                callBox.data('current-token', 'None');
                callBox.html('Waiting for Call');
                callBox.addClass('empty').css('background-color', '#5a6268');
                return;
            }

            // Get the notification to display
            const currentCall = currentNotifications[notificationIndex];
            const currentToken = currentCall.token_number;
            const patName = currentCall.pat_name || 'Patient';

            // Check if we need to show the pulse animation (only when the primary token changes)
            const tokenChanged = currentToken !== callBox.data('current-token');
            
            // Update the display
            callBox.data('current-token', currentToken);
            callBox.html(`
                <div class="token-number">${currentToken}</div>
                <div class="pat-name">${patName}</div>
            `);
            callBox.removeClass('empty').css('background-color', 'var(--danger-color)');

            if (tokenChanged && notificationIndex === 0) {
                 if (audioEnabled) {
                     // Announce only if audio is enabled by the user
                     announceToken(currentToken, currentCall.display_dept_name);
                 }
                callBox.addClass('pulse-animation');
                setTimeout(() => callBox.removeClass('pulse-animation'), 2000); 
            }

            // Move to the next notification for the next cycle
            notificationIndex = (notificationIndex + 1) % currentNotifications.length;
            
            // Re-establish interval if it was cleared and we have multiple calls
            if (!notificationCycleInterval && currentNotifications.length > 1) {
                notificationCycleInterval = setInterval(displayCurrentNotification, NOTIFICATION_DISPLAY_CYCLE);
            }
        }

        // --- Fetch New Notifications (Current Call) (Unchanged) ---
        function fetchNewNotifications() {
            const url = 'monitor.php?action=get_notifications';

            $.ajax({
                url: url,
                method: 'GET',
                dataType: 'json',
                success: function(data) {
                    $('#last-updated').text(formatTime());

                    const dataString = JSON.stringify(data);
                    const currentString = JSON.stringify(currentNotifications);

                    if (dataString !== currentString) {
                        currentNotifications = data;
                        notificationIndex = 0;
                        
                        if (notificationCycleInterval) {
                            clearInterval(notificationCycleInterval);
                            notificationCycleInterval = null;
                        }
                        displayCurrentNotification();
                    } else if (currentNotifications.length > 1 && !notificationCycleInterval) {
                        notificationCycleInterval = setInterval(displayCurrentNotification, NOTIFICATION_DISPLAY_CYCLE);
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX error fetching notifications:", status, error, xhr.responseText);
                    if (notificationCycleInterval) {
                        clearInterval(notificationCycleInterval);
                        notificationCycleInterval = null;
                    }
                    currentNotifications = [];
                    displayCurrentNotification();
                }
            });
        }
        
        // --- Status Refresh Logic (Unchanged) ---
        function refreshDepartmentStatus() {
            $.ajax({
                url: 'monitor.php?action=get_status_data', 
                method: 'GET',
                dataType: 'json',
                success: function(newStats) {
                    let statusTime = formatTime();
                    
                    // Iterate only over the fixed departments
                    FIXED_DEPARTMENTS.forEach(deptName => {
                        const stats = newStats[deptName] || {
                            pending: 0,
                            current_token: 'None',
                            complete: 0,
                            last_completed: 'None'
                        };
                        
                        // Update the specific DOM elements
                        $(`#pending-${deptName}`).text(stats.pending || 0);
                        $(`#current-${deptName}`).text(stats.current_token || 'None');
                        $(`#complete-${deptName}`).text(stats.complete || 0);
                        $(`#last-completed-${deptName}`).text(stats.last_completed || 'None'); 
                    });

                    $('#status-refreshed').text(statusTime);
                },
                error: function(xhr, status, error) {
                    console.error("AJAX error fetching status:", status, error);
                }
            });
        }
        
        /**
         * Starts all continuous polling intervals.
         */
        function startIntervals() {
            // Start continuous status refresh
            setInterval(refreshDepartmentStatus, STATUS_REFRESH_INTERVAL); 
            
            // Start continuous call polling (only starts the polling, announcements check audioEnabled internally)
            setInterval(fetchNewNotifications, CALL_POLLING_INTERVAL); 
        }

        $(document).ready(function() {
            // Pre-load voices (might still need a user click to be active)
            loadVoices();
            
            // Initialize refresh time display
            $('#status-refreshed').text(formatTime());
            $('#last-updated').text(formatTime());
            
            // Start the data refresh immediately upon page load (even if audio is disabled)
            refreshDepartmentStatus();
            fetchNewNotifications(); // Fetch notifications so the display is current.

            // Start polling only for status data, not call data, until audio is enabled.
            // The polling for CALL_POLLING_INTERVAL (notifications) is also left in startIntervals 
            // but is only called once here to initialize the display. The actual
            // recurring interval starts only after the button is clicked.

            setInterval(refreshDepartmentStatus, STATUS_REFRESH_INTERVAL);
        });
        
    </script>
</body>
</html>