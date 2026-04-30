<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SL Diagnostics - Bottom Clock & Scroll View</title>
    <style>
        body, html {
            margin: 0; padding: 0; width: 100%; height: 100%;
            overflow: hidden; background-color: #000; font-family: Arial, sans-serif;
        }

        /* 55% Zoom Out for clarity */
        .master-container {
            display: flex; flex-wrap: wrap;
            width: 181.81%; height: 181.81%;
            transform: scale(0.55); transform-origin: top left;
        }

        /* Top Section: Sidebar + Queue */
        .top-section { display: flex; width: 100%; height: 88%; }

        /* Left Side (35%) */
        .left-sidebar {
            width: 35%; height: 100%; display: flex; flex-direction: column;
            border-right: 3px solid #444; box-sizing: border-box; background-color: #111;
        }
        .monitor-wrapper { height: 60%; width: 100%; border-bottom: 3px solid #444; }
        .video-wrapper { height: 40%; width: 100%; }

        /* Right Side (65%) */
        .queue-wrapper { width: 65%; height: 100%; }

        /* --- Bottom Bar Area (Red Marked Place) --- */
        .bottom-bar {
            width: 100%; height: 12%; 
            background: linear-gradient(90deg, #000, #1e3a8a);
            display: flex; align-items: center; justify-content: space-between;
            border-top: 4px solid #2563eb; box-sizing: border-box; padding: 0 40px;
        }

        /* Bottom Clock */
        #bottom-clock-panel { display: flex; flex-direction: column; color: #fff; min-width: 400px; }
        #clock-time { font-size: 5.5rem; font-weight: 900; line-height: 1; }
        #clock-date { font-size: 2rem; font-weight: 600; color: #94a3b8; }

        /* Running Scroll Text Area */
        .scroll-container {
            flex-grow: 1; margin-left: 50px; overflow: hidden;
            background: rgba(0,0,0,0.3); border-radius: 15px; padding: 10px;
        }
        .marquee-text {
            font-size: 3rem; font-weight: 700; color: #facc15; /* Yellow color for visibility */
            white-space: nowrap; display: inline-block;
            animation: marquee 20s linear infinite;
        }

        @keyframes marquee {
            0% { transform: translateX(100%); }
            100% { transform: translateX(-100%); }
        }

        iframe { width: 100%; height: 100%; border: none; display: block; }
    </style>
</head>
<body>

    <div class="master-container">
        <div class="top-section">
            <div class="left-sidebar">
                <div class="monitor-wrapper">
                    <iframe id="monitorFrame" src="monitor2.php" allow="autoplay"></iframe>
                </div>
                <div class="video-wrapper">
                    <iframe id="youtubeFrame" src="https://www.youtube.com/embed/VuenSWcqPzM?autoplay=1&mute=1&loop=1&playlist=VuenSWcqPzM&controls=0" allow="autoplay; encrypted-media"></iframe>
                </div>
            </div>
            <div class="queue-wrapper">
                <iframe id="queueFrame" src="view_queue.php"></iframe>
            </div>
        </div>

        <div class="bottom-bar">
            <div id="bottom-clock-panel">
                <span id="clock-time">00:00:00 AM</span>
                <span id="clock-date">Loading...</span>
            </div>

            <div class="scroll-container">
                <div class="marquee-text">
                    <?php 
                        
                        echo "Welcome to SL DIAGNOSTICS - Your Health is Our Priority | Please Maintain Silence."; 
                    ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function runClock() {
            const now = new Date();
            let h = now.getHours(), m = now.getMinutes(), s = now.getSeconds();
            let ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            m = m < 10 ? '0' + m : m; s = s < 10 ? '0' + s : s;
            document.getElementById('clock-time').textContent = `${h}:${m}:${s} ${ampm}`;
            const options = { day: 'numeric', month: 'long', year: 'numeric' };
            document.getElementById('clock-date').textContent = now.toLocaleDateString('en-GB', options);
        }
        setInterval(runClock, 1000);
        runClock();

        const MONITOR_BUTTON_ID = 'audioEnableButtonID'; 
        function autoTriggerAudio() {
            try {
                let iframe = document.getElementById('monitorFrame');
                let iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                let btn = iframeDoc.getElementById(MONITOR_BUTTON_ID);
                if (btn) btn.click();
            } catch (e) { console.error('Audio click error'); }
        }
        window.onload = function() { setTimeout(autoTriggerAudio, 3000); };
    </script>
</body>
</html>