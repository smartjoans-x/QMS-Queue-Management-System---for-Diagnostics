<?php
/* =================================================================
   SL Diagnostics - waiting room display wall
   Save as .php and run fullscreen on the TV browser.

   Announcements (staff messages, festival wishes, posters) come from
   announcements.php?feed=1 and are polled in the background. The
   announcement band now runs on a fixed 60s-visible / 60s-hidden
   cycle with a smooth slide+fade animation, instead of just staying
   on screen the whole time. When there are no live announcements at
   all, the band stays collapsed and the queue / video keep the full
   screen.
   ================================================================= */

// Permanent ticker lines. Announcements marked "bottom strip" join these.
$messages = [
    'Welcome to SL Diagnostics - your health is our priority.',
    'Kindly maintain silence in the waiting area.',
];

// Videos played in a loop, in this order.
$videos = [
    'videos/video1.mp4',
    'videos/video2.mp4',
];

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#04121F">
<meta name="robots" content="noindex, nofollow">
<title>Waiting room display | SL Diagnostics</title>
<style>
/* -----------------------------------------------------------------
   Tuning - change these numbers to re-balance the wall.
----------------------------------------------------------------- */
:root{
  --side-width:   35%;    /* left column vs queue */
  --monitor-part: 60%;    /* monitor vs video, inside the left column */
  --bar-height:   clamp(96px, 12.5vh, 200px);
  --notice-height:clamp(150px, 21vh, 320px);   /* announcement band */
  --zoom:         0.55;   /* how far the embedded pages are zoomed out */

  --wall:#04121F; --panel:#0B1D2E; --edge:#173247;
  --navy:#00325C; --navy-lit:#0A4675;
  --signal:#E0A32E; --ink-0:#FFFFFF; --ink-1:#9FB6C8;
  --warn:#FF9F94;
  --mono:ui-monospace,"SFMono-Regular","SF Mono",Menlo,Consolas,"Liberation Mono",monospace;
  --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
}

*{box-sizing:border-box; margin:0; padding:0}
[hidden]{display:none !important}
html,body{width:100%; height:100%; overflow:hidden; background:var(--wall)}
body{font-family:var(--sans); color:var(--ink-0)}
body.hide-pointer{cursor:none}

.wall{display:flex; flex-direction:column; width:100%; height:100%}

/* ---------- upper two thirds ---------- */
.top{flex:1; display:flex; min-height:0}

.side{
  width:var(--side-width); display:flex; flex-direction:column;
  background:var(--panel); border-right:3px solid var(--edge);
}
.pane{position:relative; overflow:hidden; background:#000}
.pane-monitor{height:var(--monitor-part); border-bottom:3px solid var(--edge)}
.pane-video{flex:1}

.queue{flex:1; position:relative; overflow:hidden; background:#000}

/* The embedded pages are rendered at a larger logical size and scaled
   down, so they fit more rows. Everything this page draws itself
   stays at native resolution and therefore stays sharp. */
.zoom{position:absolute; inset:0; overflow:hidden}
.zoom iframe{
  position:absolute; top:0; left:0; border:0; display:block;
  width:calc(100% / var(--zoom));
  height:calc(100% / var(--zoom));
  transform:scale(var(--zoom));
  transform-origin:top left;
}

video{width:100%; height:100%; object-fit:cover; display:block; background:#000}

/* ---------- announcement band ---------------------------------
   Collapsed by default (height:0). JS toggles the "notice-visible"
   class on a 60s-on / 60s-off cycle; the height/opacity/transform
   transition below is what gives the slide-down-fade-in and
   slide-up-fade-out animation. Because height animates (not just
   `hidden`), the queue/video panes smoothly reclaim the space too.
------------------------------------------------------------------ */
.notice{
  flex:none; height:0; position:relative; overflow:hidden;
  background:linear-gradient(180deg,#08202F 0%, #04121F 100%);
  border-top:3px solid transparent;
  opacity:0;
  transform:translateY(18px);
  transition: height .6s ease, opacity .5s ease, transform .5s ease, border-color .5s ease;
}
.notice.notice-visible{
  height:var(--notice-height);
  opacity:1;
  transform:translateY(0);
  border-top-color:var(--edge);
}
.notice .slides{position:absolute; inset:0}
.slide{
  position:absolute; inset:0; opacity:0; transition:opacity .7s ease;
  display:flex; flex-direction:column; justify-content:center; gap:.28em;
  padding:clamp(12px,2vh,26px) clamp(26px,3.4vw,72px);
}
.slide.on{opacity:1}
.slide::before{
  content:""; position:absolute; left:0; top:0; bottom:0; width:7px; background:var(--signal);
}
.slide .kicker{
  font-size:clamp(.6rem,.95vw,1.05rem); font-weight:800; letter-spacing:.24em;
  text-transform:uppercase; color:var(--ink-1);
}
.slide h2{
  font-size:clamp(1.35rem,2.9vw,3.4rem); font-weight:800; letter-spacing:-.02em; line-height:1.1;
}
.slide p{
  font-size:clamp(.95rem,1.75vw,2.1rem); font-weight:600; color:#D7E4EE; line-height:1.28;
  display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}
.slide.tone-wish::before{background:linear-gradient(180deg,#F2C55C,#C9862A)}
.slide.tone-wish h2{color:var(--signal)}
.slide.tone-alert::before{background:#D2483B}
.slide.tone-alert{background:linear-gradient(90deg, rgba(210,72,59,.28) 0%, rgba(210,72,59,0) 62%)}
.slide.tone-alert h2{color:var(--warn)}

.slide.is-image{flex-direction:row; align-items:center; gap:clamp(18px,2.4vw,48px)}
.slide.is-image img{height:100%; width:auto; max-width:48%; object-fit:contain; border-radius:8px}
.slide.is-image .cap{display:flex; flex-direction:column; gap:.28em; min-width:0}
.slide.is-image.bare{justify-content:center}
.slide.is-image.bare img{max-width:100%; margin:0 auto}
.slide.img-failed img{display:none}

.notice .dots{
  position:absolute; right:clamp(14px,1.6vw,30px); bottom:clamp(10px,1.4vh,20px);
  display:flex; gap:7px;
}
.notice .dots i{
  width:9px; height:9px; border-radius:50%; background:rgba(255,255,255,.22);
  transition:background .3s;
}
.notice .dots i.on{background:var(--signal)}

/* ---------- bottom bar ---------- */
.bar{
  height:var(--bar-height); flex:none; display:flex; align-items:center; gap:clamp(20px,2.5vw,54px);
  padding:0 clamp(18px,2.2vw,46px);
  background:linear-gradient(90deg, #04121F 0%, var(--navy) 60%, var(--navy-lit) 100%);
  border-top:4px solid var(--signal);
}

.clock{display:flex; flex-direction:column; flex:none; line-height:1}
.clock .time{
  font-family:var(--mono); font-variant-numeric:tabular-nums;
  font-size:clamp(2.1rem, 5vw, 6.4rem); font-weight:700; letter-spacing:-.03em;
}
.clock .time .ampm{font-size:.42em; font-weight:600; color:var(--ink-1); margin-left:.22em; letter-spacing:0}
.clock .date{
  margin-top:.35em; font-size:clamp(.8rem, 1.5vw, 2rem); font-weight:600;
  color:var(--ink-1); letter-spacing:.02em;
}

.ticker{
  flex:1; min-width:0; overflow:hidden; position:relative;
  background:rgba(0,0,0,.32); border-radius:14px;
  padding:clamp(8px,1vh,18px) 0; border:1px solid rgba(255,255,255,.07);
}
.track{
  display:flex; width:max-content; white-space:nowrap; will-change:transform;
  animation:slide var(--dur, 34s) linear infinite;
}
.track span{
  font-size:clamp(1.1rem, 2.3vw, 3rem); font-weight:700; color:var(--signal);
  padding-right:clamp(30px, 4vw, 90px); letter-spacing:.01em;
}
.track span::after{content:"\2022"; margin-left:clamp(30px,4vw,90px); opacity:.42; font-size:.7em; vertical-align:middle}
@keyframes slide{from{transform:translateX(0)}to{transform:translateX(-50%)}}

.mark{flex:none; text-align:right; line-height:1.25}
.mark b{display:block; font-size:clamp(.85rem,1.5vw,1.9rem); font-weight:800; letter-spacing:-.02em}
.mark span{
  display:block; font-size:clamp(.5rem,.75vw,.95rem); font-weight:700;
  letter-spacing:.2em; text-transform:uppercase; color:var(--ink-1); margin-top:.35em;
}

/* portrait or very narrow screens: stack instead of squeezing */
@media (max-aspect-ratio:1/1){
  .top{flex-direction:column}
  .side{width:100%; height:42%; flex-direction:row; border-right:0; border-bottom:3px solid var(--edge)}
  .pane-monitor{height:100%; width:var(--monitor-part); border-bottom:0; border-right:3px solid var(--edge)}
  .pane-video{height:100%}
  .slide.is-image{flex-direction:column; align-items:flex-start}
  .slide.is-image img{max-width:100%; height:auto; max-height:56%}
  .bar{gap:16px}
  .mark{display:none}
}
@media (prefers-reduced-motion:reduce){
  /* the ticker is content, not decoration - slow it down, don't stop it */
  .track{animation-duration:calc(var(--dur, 34s) * 1.6)}
  .slide{transition:none}
  .notice{transition-duration:.01ms, .01ms, .01ms, .01ms}
}
</style>
</head>
<body>

<div class="wall">

  <div class="top">
    <aside class="side">
      <div class="pane pane-monitor">
        <div class="zoom">
          <iframe id="monitorFrame" src="monitor2.php" title="Announcements" allow="autoplay"></iframe>
        </div>
      </div>
      <div class="pane pane-video">
        <video id="localVideo" muted autoplay playsinline preload="auto"></video>
      </div>
    </aside>

    <main class="queue">
      <div class="zoom">
        <iframe id="queueFrame" src="view_queue.php" title="Live queue"></iframe>
      </div>
    </main>
  </div>

  <!-- collapsed by default; JS drives the 60s show / 60s hide cycle via
       the "notice-visible" class -->
  <section class="notice" id="noticeBand" aria-live="polite">
    <div class="slides" id="noticeSlides"></div>
    <div class="dots" id="noticeDots" aria-hidden="true"></div>
  </section>

  <footer class="bar">
    <div class="clock">
      <span class="time" id="clockTime">--:--:--</span>
      <span class="date" id="clockDate">&nbsp;</span>
    </div>

    <div class="ticker">
      <div class="track" id="track">
        <?php
        // printed twice - the animation slides exactly one copy for a seamless loop
        for ($copy = 0; $copy < 2; $copy++) {
            foreach ($messages as $m) {
                echo '<span>' . e($m) . '</span>';
            }
        }
        ?>
      </div>
    </div>

    <div class="mark">
      <b>SL Diagnostics</b>
      <span>Queue display</span>
    </div>
  </footer>

</div>

<script>
(function () {
  'use strict';

  /* ---------------------------------------------------------------
     Settings
  --------------------------------------------------------------- */
  var PLAYLIST         = <?= json_encode(array_values($videos), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var BASE_MESSAGES    = <?= json_encode(array_values($messages), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var TICKER_SPEED     = 110;   // pixels per second
  var RELOAD_MINUTES   = 0;     // set to e.g. 120 to refresh the whole wall twice a day; 0 = never
  var AUDIO_BUTTON_ID  = 'audioEnableButtonID';

  var ANN_FEED         = 'announcements.php?feed=1';
  var ANN_POLL_SECONDS = 45;    // how often the wall asks for new announcements
  var ANN_SLIDE_SECONDS = 12;   // if multiple announcements, how long each one stays on screen
                                 // while the band itself is in its visible phase
  var ANN_SHOW_SECONDS  = 60;   // how long the band stays visible per cycle
  var ANN_HIDE_SECONDS  = 60;   // how long the band stays hidden before it reappears

  /* ---------------------------------------------------------------
     Clock - retimes itself each tick so it never drifts off the second
  --------------------------------------------------------------- */
  var timeEl = document.getElementById('clockTime');
  var dateEl = document.getElementById('clockDate');

  function two(n) { return n < 10 ? '0' + n : '' + n; }

  function tick() {
    var now = new Date();
    var h = now.getHours();
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;

    timeEl.innerHTML = h + ':' + two(now.getMinutes()) + ':' + two(now.getSeconds()) +
                       '<span class="ampm">' + ampm + '</span>';
    dateEl.textContent = now.toLocaleDateString('en-GB', {
      weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
    });

    setTimeout(tick, 1000 - (Date.now() % 1000));
  }
  tick();

  /* ---------------------------------------------------------------
     Ticker - duration from real width, so long and short messages
     scroll at the same readable pace
  --------------------------------------------------------------- */
  var track = document.getElementById('track');

  // If the bar is empty, PHP did not render the messages - most often the
  // file was saved with an .html extension.
  if (!track.children.length) {
    console.warn('Ticker is empty: the PHP message block did not run. Is this file saved as .php?');
    BASE_MESSAGES = ['Welcome to SL Diagnostics'];
  }

  function sizeTicker() {
    var half = track.scrollWidth / 2;
    if (!half) return;
    track.style.setProperty('--dur', Math.max(12, Math.round(half / TICKER_SPEED)) + 's');
  }

  function buildTicker(extra) {
    var list = BASE_MESSAGES.concat(extra || []);
    if (!list.length) list = ['Welcome to SL Diagnostics'];

    track.innerHTML = '';
    for (var copy = 0; copy < 2; copy++) {
      list.forEach(function (msg) {
        var span = document.createElement('span');
        span.textContent = msg;
        track.appendChild(span);
      });
    }
    // restart the animation so the new strip starts from the left edge
    track.style.animation = 'none';
    void track.offsetWidth;
    track.style.animation = '';
    sizeTicker();
  }

  sizeTicker();
  window.addEventListener('resize', sizeTicker);
  window.addEventListener('load', sizeTicker);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(sizeTicker);

  /* ---------------------------------------------------------------
     Announcement band
     Content is built entirely from the feed. Independent of content,
     the band runs on a repeating 60s-visible / 60s-hidden cycle with
     a slide + fade animation (see the .notice / .notice-visible CSS).
     No live items at all -> cycle stops and the band stays collapsed,
     giving the queue and video the full height back.
  --------------------------------------------------------------- */
  var band      = document.getElementById('noticeBand');
  var slidesBox = document.getElementById('noticeSlides');
  var dotsBox   = document.getElementById('noticeDots');
  var rotTimer  = null;      // rotates between multiple slides while visible
  var cycleTimer = null;     // drives the show/hide cycle
  var slideAt   = 0;
  var lastItems = [];
  var bandVisible = false;

  var KICKER = { info: 'Announcement', wish: 'Greetings', alert: 'Important' };

  function showSlide(n) {
    var slides = slidesBox.children, dots = dotsBox.children, i;
    if (!slides.length) return;
    slideAt = ((n % slides.length) + slides.length) % slides.length;
    for (i = 0; i < slides.length; i++) {
      if (i === slideAt) { slides[i].classList.add('on'); }
      else { slides[i].classList.remove('on'); }
    }
    for (i = 0; i < dots.length; i++) {
      if (i === slideAt) { dots[i].classList.add('on'); }
      else { dots[i].classList.remove('on'); }
    }
  }

  function startRotate() {
    clearInterval(rotTimer);
    if (slidesBox.children.length < 2) return;
    rotTimer = setInterval(function () { showSlide(slideAt + 1); }, ANN_SLIDE_SECONDS * 1000);
  }

  // toggles ONLY the visible/hidden animation state; does not touch content
  function setBandVisible(v) {
    bandVisible = v;
    if (v) {
      band.classList.add('notice-visible');
      showSlide(slideAt);
      startRotate();
    } else {
      band.classList.remove('notice-visible');
      clearInterval(rotTimer);
    }
  }

  // schedules the next flip of the show/hide cycle
  function scheduleNextFlip() {
    clearTimeout(cycleTimer);
    if (!lastItems.length) return;
    var wait = (bandVisible ? ANN_SHOW_SECONDS : ANN_HIDE_SECONDS) * 1000;
    cycleTimer = setTimeout(function () {
      if (!lastItems.length) { setBandVisible(false); return; }
      setBandVisible(!bandVisible);
      scheduleNextFlip();
    }, wait);
  }

  function makeText(parent, tag, cls, value) {
    if (!value) return;
    var el = document.createElement(tag);
    if (cls) el.className = cls;
    el.textContent = value;
    parent.appendChild(el);
  }

  function buildNotice(items) {
    items = items || [];
    lastItems = items;

    slidesBox.innerHTML = '';
    dotsBox.innerHTML = '';
    slideAt = 0;

    if (!items.length) {
      clearInterval(rotTimer);
      clearTimeout(cycleTimer);
      setBandVisible(false);
      return;
    }

    items.forEach(function (item, i) {
      var slide = document.createElement('article');
      var tone = item.tone || 'info';
      slide.className = 'slide tone-' + tone + ' ' + (item.type === 'image' ? 'is-image' : 'is-text');

      if (item.type === 'image' && item.image) {
        var img = document.createElement('img');
        img.src = item.image;
        img.alt = item.title || 'Announcement';
        img.onerror = function () { slide.classList.add('img-failed'); };
        slide.appendChild(img);

        if (item.title || item.body) {
          var cap = document.createElement('div');
          cap.className = 'cap';
          makeText(cap, 'h2', '', item.title);
          makeText(cap, 'p', '', item.body);
          slide.appendChild(cap);
        } else {
          slide.classList.add('bare');
        }
      } else {
        makeText(slide, 'span', 'kicker', KICKER[tone] || KICKER.info);
        makeText(slide, 'h2', '', item.title);
        makeText(slide, 'p', '', item.body);
      }

      slidesBox.appendChild(slide);

      if (items.length > 1) {
        var dot = document.createElement('i');
        dotsBox.appendChild(dot);
      }
    });

    // fresh content -> start the 60s show / 60s hide cycle from "visible"
    clearTimeout(cycleTimer);
    setBandVisible(true);
    scheduleNextFlip();
  }

  var annVersion = null;

  function pullAnnouncements() {
    if (!window.fetch) return;
    fetch(ANN_FEED + '&t=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
      .then(function (res) { return res.ok ? res.json() : null; })
      .then(function (data) {
        if (!data || data.version === annVersion) return;   // nothing changed
        annVersion = data.version;
        buildNotice(data.panel || []);
        buildTicker(data.ticker || []);
      })
      .catch(function () { /* feed offline - keep showing what we have */ });
  }

  pullAnnouncements();
  setInterval(pullAnnouncements, ANN_POLL_SECONDS * 1000);

  /* ---------------------------------------------------------------
     Video playlist - skips a file that is missing or broken instead
     of stopping the wall for the rest of the day
  --------------------------------------------------------------- */
  var video = document.getElementById('localVideo');
  var index = 0;
  var strikes = 0;

  function play(i) {
    if (!PLAYLIST.length) return;
    index = ((i % PLAYLIST.length) + PLAYLIST.length) % PLAYLIST.length;
    video.src = PLAYLIST[index];
    video.load();
    var p = video.play();
    if (p && p.catch) p.catch(function () { /* blocked until a gesture; retried below */ });
  }

  video.addEventListener('ended', function () { strikes = 0; play(index + 1); });

  video.addEventListener('error', function () {
    strikes++;
    if (strikes >= PLAYLIST.length) {          // every file failed - wait, then start over
      strikes = 0;
      setTimeout(function () { play(0); }, 30000);
      return;
    }
    play(index + 1);
  });

  // if playback stalls for a full minute, nudge it
  var lastTime = 0, stalled = 0;
  setInterval(function () {
    if (video.paused || video.ended) return;
    if (video.currentTime === lastTime) {
      if (++stalled >= 6) { stalled = 0; play(index + 1); }
    } else {
      stalled = 0;
      lastTime = video.currentTime;
    }
  }, 10000);

  play(0);

  /* ---------------------------------------------------------------
     Announcement audio in the monitor frame
     A scripted click is not a user gesture, so browsers may still
     block sound. Keep retrying, and take the first real click on the
     wall as the gesture that unlocks it.
  --------------------------------------------------------------- */
  function pokeAudio() {
    try {
      var frame = document.getElementById('monitorFrame');
      var doc = frame.contentDocument || frame.contentWindow.document;
      var btn = doc.getElementById(AUDIO_BUTTON_ID);
      if (btn) { btn.click(); return true; }
    } catch (err) { /* frame not ready yet */ }
    return false;
  }

  var pokes = 0;
  var pokeTimer = setInterval(function () {
    if (pokeAudio() || ++pokes > 15) clearInterval(pokeTimer);
  }, 2000);

  document.getElementById('monitorFrame').addEventListener('load', function () {
    setTimeout(pokeAudio, 1500);
  });

  document.addEventListener('click', function once() {
    pokeAudio();
    video.play().catch(function () {});
    document.removeEventListener('click', once);
  });

  /* ---------------------------------------------------------------
     Kiosk behaviour
  --------------------------------------------------------------- */

  // keep the screen awake (needs https:// or localhost)
  var lock = null;
  function keepAwake() {
    if (!('wakeLock' in navigator)) return;
    navigator.wakeLock.request('screen').then(function (l) {
      lock = l;
      l.addEventListener('release', function () { lock = null; });
    }).catch(function () {});
  }
  keepAwake();
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && !lock) keepAwake();
    if (document.visibilityState === 'visible') pullAnnouncements();
  });

  // hide the mouse pointer when nobody is touching it
  var pointerTimer;
  function pointerSeen() {
    document.body.classList.remove('hide-pointer');
    clearTimeout(pointerTimer);
    pointerTimer = setTimeout(function () {
      document.body.classList.add('hide-pointer');
    }, 3000);
  }
  document.addEventListener('mousemove', pointerSeen);
  pointerSeen();

  // F or double click toggles fullscreen, for setup day
  function toggleFullscreen() {
    if (document.fullscreenElement) { document.exitFullscreen(); }
    else { document.documentElement.requestFullscreen().catch(function () {}); }
  }
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'f' || ev.key === 'F') toggleFullscreen();
    if (ev.key === 'a' || ev.key === 'A') pullAnnouncements();   // force a check
  });
  document.addEventListener('dblclick', toggleFullscreen);

  // optional scheduled refresh
  if (RELOAD_MINUTES > 0) {
    setTimeout(function () { location.reload(); }, RELOAD_MINUTES * 60000);
  }
})();
</script>

</body>
</html>