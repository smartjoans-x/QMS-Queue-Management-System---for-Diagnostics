<?php
session_start();
include 'config/db_connect.php'; 

$current_date = date('Y-m-d');
$lab_departments = [
    'Biochemistry', 'Cytology', 'Genetic', 'Haematology', 'Histopathology',
    'Mamography', 'MICROBIOLOGY', 'Molecular Biology', 'Out source',
    'Pathology', 'Sample Collection', 'Serology'
];

// --- FUNCTION TO FETCH NOTIFICATIONS ---
function fetch_notifications($mysql_conn) {
    $query = "SELECT t1.token_number, t1.pat_name, t1.dept_id, t2.dept_name
              FROM popup_notifications t1
              JOIN departments t2 ON t1.dept_id = t2.id
              ORDER BY t1.id DESC";
    $result = mysqli_query($mysql_conn, $query);
    
    $notifications = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
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

// --- AJAX ENDPOINT ---
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'get_notifications') {
        $notifications = fetch_notifications($mysql_conn);
        header('Content-Type: application/json');
        echo json_encode($notifications);
        exit;
    }
}

$initial_called_token = 'None'; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcement Monitor - SL Diagnostic</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        :root {
            --danger-color: #dc3545;
            --dark-bg: #1a1a1a;
            --white: #ffffff;
            --info-color: #17a2b8;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--dark-bg);
            margin: 0;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        /* Overlay */
        #audio-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.9); z-index: 2000;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
        }
        #audio-overlay button {
            background-color: var(--danger-color); color: var(--white);
            border: 3px solid var(--white); padding: 25px 50px;
            font-size: 2.5rem; font-weight: 800; border-radius: 15px; cursor: pointer;
            animation: pulse-overlay 1.5s infinite;
        }
        @keyframes pulse-overlay {
            0% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.7); }
            70% { box-shadow: 0 0 0 30px rgba(255, 255, 255, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0); }
        }

        /* Full Screen Display Container */
        .announcement-container {
            width: 90%;
            text-align: center;
        }

        .header-title {
            color: var(--info-color);
            font-size: 4rem;
            font-weight: 800;
            margin-bottom: 40px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .call-box {
            background-color: var(--danger-color);
            color: var(--white);
            padding: 60px;
            border-radius: 30px;
            box-shadow: 0 0 60px rgba(220, 53, 69, 0.6);
            min-height: 500px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: all 0.5s ease;
        }

        .token-number {
            font-size: 15rem; /* Massive size for TV */
            font-weight: 800;
            line-height: 1;
            margin-bottom: 20px;
        }

        .pat-name {
            font-size: 4rem;
            font-weight: 600;
            opacity: 0.9;
        }

        .empty {
            background-color: #333;
            box-shadow: none;
            color: #666;
            font-size: 5rem;
        }

        .pulse-animation {
            animation: call-pulse 0.6s ease-in-out;
        }

        @keyframes call-pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .footer-time {
            position: absolute; bottom: 30px; right: 40px;
            color: #444; font-size: 1.5rem;
        }
    </style>
</head>
<body>

    <div id="audio-overlay">
        <button id="audioEnableButtonID" onclick="enableAudio()">
            <i class="bi bi-volume-up-fill"></i> CLICK TO START MONITOR
        </button>
    </div>

    <div class="announcement-container">
        <div class="header-title"><i class="bi bi-bell-fill"></i> Now Calling</div>
        
        <div class="call-box empty" id="call-box" data-current-token="None">
            Waiting for Calling...
        </div>
    </div>

    <div class="footer-time">Last Update: <span id="last-updated">--</span></div>

    <script>
        let currentNotifications = [];
        let notificationIndex = 0;
        let audioEnabled = false;
        let preferredVoice = null;
        let notificationCycleInterval = null;

        function loadVoices() {
            if (!('speechSynthesis' in window)) return;
            const setVoice = () => {
                const voices = speechSynthesis.getVoices();
                preferredVoice = voices.find(v => v.lang.includes('en') && (v.name.includes('Google') || v.name.includes('Microsoft'))) || voices[0];
            };
            if (speechSynthesis.onvoiceschanged !== undefined) speechSynthesis.onvoiceschanged = setVoice;
            setVoice();
        }

        window.enableAudio = function() {
            audioEnabled = true;
            $('#audio-overlay').hide();
            const startMsg = new SpeechSynthesisUtterance("System Ready");
            startMsg.volume = 0;
            speechSynthesis.speak(startMsg);
            loadVoices();
            startPolling();
        }

        function announceToken(token, dept) {
            if (!audioEnabled) return;
            const spokenToken = token.split('').join(' ');
            const speech = new SpeechSynthesisUtterance(`Token number ${spokenToken}, please proceed to ${dept} counter.`);
            speech.voice = preferredVoice;
            speech.rate = 0.9;
            speechSynthesis.cancel();
            speechSynthesis.speak(speech);
        }

        function displayNotification() {
            const callBox = $('#call-box');
            if (currentNotifications.length === 0) {
                callBox.addClass('empty').html("Waiting for Calling...");
                callBox.data('current-token', 'None');
                return;
            }

            const current = currentNotifications[notificationIndex];
            const tokenChanged = current.token_number !== callBox.data('current-token');

            callBox.removeClass('empty').html(`
                <div class="token-number">${current.token_number}</div>
                <div class="pat-name">${current.pat_name}</div>
            `);
            callBox.data('current-token', current.token_number);

            if (tokenChanged && notificationIndex === 0) {
                announceToken(current.token_number, current.display_dept_name);
                callBox.addClass('pulse-animation');
                setTimeout(() => callBox.removeClass('pulse-animation'), 1000);
            }

            notificationIndex = (notificationIndex + 1) % currentNotifications.length;
        }

        function fetchNotifications() {
            $.get('monitor.php?action=get_notifications', function(data) {
                $('#last-updated').text(new Date().toLocaleTimeString());
                if (JSON.stringify(data) !== JSON.stringify(currentNotifications)) {
                    currentNotifications = data;
                    notificationIndex = 0;
                    displayNotification();
                    if (notificationCycleInterval) clearInterval(notificationCycleInterval);
                    if (data.length > 1) {
                        notificationCycleInterval = setInterval(displayNotification, 5000);
                    }
                }
            });
        }

        function startPolling() {
            fetchNotifications();
            setInterval(fetchNotifications, 3000);
        }

        $(document).ready(loadVoices);
    </script>
</body>
</html>