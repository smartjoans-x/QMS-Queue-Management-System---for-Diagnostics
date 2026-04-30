<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SL Diagnostics - Full Auto View</title>
    <style>
        body, html {
            margin: 0; padding: 0; width: 100%; height: 100%;
            overflow: hidden; background-color: #000; font-family: Arial, sans-serif;
        }

        .master-container {
            display: flex; width: 149.25%; height: 149.25%;
            transform: scale(0.67); transform-origin: top left;
        }

        .left-sidebar {
            width: 25%; height: 100%; display: flex; flex-direction: column;
            border-right: 2px solid #444; box-sizing: border-box; background-color: #111; position: relative;
        }

        .monitor-wrapper, .video-wrapper { height: 50%; width: 100%; }
        .monitor-wrapper { border-bottom: 1px solid #444; }
        .queue-wrapper { width: 75%; height: 100%; background-color: #000; }

        iframe { width: 100%; height: 100%; border: none; display: block; }

        #live-clock-panel {
            position: absolute; bottom: 20px; left: 20px;
            background: rgba(0, 0, 0, 0.85); color: #fff;
            padding: 15px 25px; border-radius: 12px; border-left: 6px solid #2563eb;
            z-index: 1000; pointer-events: none;
        }
        #clock-time { font-size: 2.8rem; font-weight: 900; display: block; line-height: 1; }
        #clock-date { font-size: 1.2rem; font-weight: 600; color: #94a3b8; margin-top: 5px; display: block; }
    </style>
</head>
<body>

    <div class="master-container">
        <div class="left-sidebar">
            <div class="monitor-wrapper">
                <iframe id="monitorFrame" src="monitor2.php" allow="autoplay" title="Calling Monitor"></iframe>
            </div>
            <div class="video-wrapper">
                <iframe id="youtubeFrame" 
                    src="https://www.youtube.com/embed/VuenSWcqPzM?autoplay=1&mute=1&loop=1&playlist=VuenSWcqPzM&controls=0" 
                    allow="autoplay; encrypted-media">
                </iframe>
            </div>
            <div id="live-clock-panel">
                <span id="clock-time">00:00:00 AM</span>
                <span id="clock-date">Loading...</span>
            </div>
        </div>
        <div class="queue-wrapper">
            <iframe id="queueFrame" src="view_queue.php" title="Queue List"></iframe>
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
                
                if (btn) {
                    btn.click();
                    console.log('Auto-clicked monitor audio button');
                }
            } catch (e) {
                console.error('Auto-click failed: Check if same domain', e);
            }
        }

        // சிஸ்டம் லோட் ஆன 3 செகண்ட் கழித்து ஆட்டோ கிளிக் ஆகும்
        window.onload = function() {
            setTimeout(autoTriggerAudio, 3000);
        };
    </script>
</body>
</html>