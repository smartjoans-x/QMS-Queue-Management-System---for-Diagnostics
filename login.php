<?php
session_start();
include 'config/db_connect.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// one-time CSRF token for this browser session
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/* Simple attempt throttle. Session-based, so it slows down casual
   guessing from one browser — it is not a substitute for a real
   IP-based lockout if this system is ever exposed to the internet. */
$MAX_TRIES   = 5;
$LOCK_SECONDS = 60;

$error       = '';
$locked_for  = 0;
$posted_user = '';

if (!empty($_SESSION['login_block_until']) && $_SESSION['login_block_until'] > time()) {
    $locked_for = $_SESSION['login_block_until'] - time();
}

if ($_POST && isset($_POST['login'])) {

    $posted_user = trim($_POST['username'] ?? '');
    $password    = $_POST['password'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Your session expired. Please try signing in again.';

    } elseif ($locked_for > 0) {
        $error = 'Too many attempts. Wait ' . $locked_for . ' seconds and try again.';

    } elseif ($posted_user === '' || $password === '') {
        $error = 'Enter both a username and a password.';

    } else {
        $stmt = mysqli_prepare($mysql_conn,
            "SELECT id, username, password, role, department_id FROM users WHERE username = ?");
        mysqli_stmt_bind_param($stmt, 's', $posted_user);
        mysqli_stmt_execute($stmt);
        $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if ($user && password_verify($password, $user['password'])) {

            // new session id on privilege change — blocks session fixation
            session_regenerate_id(true);

            $_SESSION['user_id']       = $user['id'];
            $_SESSION['username']      = $user['username'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['department_id'] = $user['department_id'];

            unset($_SESSION['login_fails'], $_SESSION['login_block_until'], $_SESSION['csrf']);

            header('Location: dashboard.php');
            exit;

        } else {
            $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;

            if ($_SESSION['login_fails'] >= $MAX_TRIES) {
                $_SESSION['login_block_until'] = time() + $LOCK_SECONDS;
                $_SESSION['login_fails']       = 0;
                $locked_for = $LOCK_SECONDS;
                $error = 'Too many attempts. Wait ' . $LOCK_SECONDS . ' seconds and try again.';
            } else {
                $left  = $MAX_TRIES - $_SESSION['login_fails'];
                $error = 'Username or password is incorrect. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.';
            }
        }
    }
}

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#00325C">
<meta name="robots" content="noindex, nofollow">
<title>Sign in | SL Diagnostics</title>
<style>
:root{
  --ink:#0A1A2A; --ink-2:#41586C; --ink-3:#7B90A3;
  --line:#DCE5EC; --line-soft:#EBF1F6;
  --paper:#EEF3F7; --surface:#FFFFFF;
  --navy:#004B87; --navy-deep:#00325C; --navy-tint:#E5EEF6;
  --signal:#9A6600; --signal-tint:#FBF0D9; --signal-bar:#E0A32E;
  --alert:#B3392F; --alert-tint:#FBEAE8;
  --mono:ui-monospace,"SFMono-Regular","SF Mono",Menlo,Consolas,"Liberation Mono",monospace;
  --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
}
*{box-sizing:border-box; margin:0; padding:0}
html{-webkit-text-size-adjust:100%}
body{
  font-family:var(--sans); color:var(--ink); background:var(--paper);
  font-size:15px; line-height:1.5; -webkit-font-smoothing:antialiased;
  min-height:100vh; min-height:100dvh;
}
:focus-visible{outline:2px solid var(--navy); outline-offset:2px; border-radius:6px}
.ico{width:16px;height:16px;flex:none;stroke:currentColor;fill:none;stroke-width:1.8;
     stroke-linecap:round;stroke-linejoin:round}

.stage{display:grid; grid-template-columns:1.05fr 1fr; min-height:100vh; min-height:100dvh}

/* ---------- left: brand panel ---------- */
.aside{
  position:relative; overflow:hidden; padding:46px 52px;
  background:var(--navy-deep); color:#fff;
  display:flex; flex-direction:column; justify-content:space-between;
}
.aside::after{ /* soft light from the top-right, keeps the flat navy from going dead */
  content:""; position:absolute; width:520px; height:520px; right:-190px; top:-200px;
  background:radial-gradient(circle, rgba(255,255,255,.09), rgba(255,255,255,0) 68%);
  pointer-events:none;
}
.mark{display:flex; align-items:baseline; gap:9px; font-size:1.15rem; font-weight:800; letter-spacing:-.02em}
.mark b{color:#8FC0E6}
.mark span{font-size:.6rem; font-weight:700; letter-spacing:.18em; text-transform:uppercase;
  color:rgba(255,255,255,.55); padding-left:11px; border-left:1px solid rgba(255,255,255,.2)}

.pitch{position:relative; z-index:1; max-width:420px}
.pitch h2{font-size:1.9rem; font-weight:700; letter-spacing:-.03em; line-height:1.2}
.pitch p{margin-top:12px; color:rgba(255,255,255,.72); font-size:.94rem}

/* a still frame of the call board, so the door shows the room */
.preview{
  position:relative; z-index:1; align-self:flex-start; margin-top:26px;
  background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.14);
  border-radius:14px; padding:16px 20px 14px; min-width:206px;
}
.preview .cap{font-size:.62rem; font-weight:800; letter-spacing:.16em; text-transform:uppercase;
  color:rgba(255,255,255,.55); display:flex; align-items:center; gap:8px}
.preview .dot{width:7px; height:7px; border-radius:50%; background:var(--signal-bar);
  box-shadow:0 0 0 0 rgba(224,163,46,.55); animation:pulse 2.4s infinite}
@keyframes pulse{70%{box-shadow:0 0 0 8px rgba(224,163,46,0)}100%{box-shadow:0 0 0 0 rgba(224,163,46,0)}}
.preview .tok{font-family:var(--mono); font-size:2.6rem; font-weight:700; line-height:1.1;
  margin:8px 0 2px; letter-spacing:-.03em}
.preview .meta{font-size:.76rem; font-weight:600; color:rgba(255,255,255,.6)}

.aside .foot{position:relative; z-index:1; font-size:.76rem; color:rgba(255,255,255,.45)}

/* ---------- right: form ---------- */
.side{display:flex; align-items:center; justify-content:center; padding:40px 28px; background:var(--surface)}
.form{width:100%; max-width:370px}
.form .eyebrow{font-size:.68rem; font-weight:800; letter-spacing:.14em; text-transform:uppercase;
  color:var(--ink-3); margin-bottom:8px}
.form h1{font-size:1.55rem; font-weight:700; letter-spacing:-.02em}
.form .sub{margin-top:6px; color:var(--ink-3); font-size:.87rem}

.alert{display:flex; align-items:flex-start; gap:10px; margin:22px 0 0; padding:12px 14px;
  border-radius:10px; background:var(--alert-tint); color:#8E2C24; border:1px solid #F1CFCB;
  font-size:.84rem; font-weight:600}
.alert .ico{margin-top:2px}

.field{margin-top:18px}
.field label{display:block; margin-bottom:7px; font-size:.7rem; font-weight:800;
  letter-spacing:.1em; text-transform:uppercase; color:var(--ink-3)}
.control{position:relative}
.control > .ico{position:absolute; left:14px; top:50%; margin-top:-8px; color:var(--ink-3)}
.control input{
  width:100%; height:50px; padding:0 14px 0 42px; border:1px solid var(--line);
  border-radius:11px; font:inherit; font-size:.95rem; background:var(--paper); color:var(--ink);
  transition:border-color .15s, background .15s, box-shadow .15s;
}
.control input:focus{background:var(--surface); border-color:var(--navy); outline:none;
  box-shadow:0 0 0 3px rgba(0,75,135,.12)}
.control input:disabled{opacity:.55; cursor:not-allowed}
#password{padding-right:46px}
.peek{position:absolute; right:6px; top:50%; margin-top:-17px; width:34px; height:34px;
  display:grid; place-items:center; border:0; background:transparent; color:var(--ink-3);
  border-radius:8px; cursor:pointer}
.peek:hover{color:var(--navy); background:var(--navy-tint)}

.caps{display:none; align-items:center; gap:7px; margin-top:8px; font-size:.76rem;
  font-weight:700; color:var(--signal)}
.caps.on{display:flex}

.submit{
  width:100%; height:50px; margin-top:24px; border:0; border-radius:11px; cursor:pointer;
  background:var(--navy); color:#fff; font-family:inherit; font-size:.95rem; font-weight:700;
  display:inline-flex; align-items:center; justify-content:center; gap:9px;
  box-shadow:0 8px 18px -10px rgba(0,75,135,.95); transition:.15s;
}
.submit:hover{background:var(--navy-deep)}
.submit:disabled{background:#9DB2C4; box-shadow:none; cursor:not-allowed}

.legal{margin-top:26px; padding-top:18px; border-top:1px solid var(--line-soft);
  font-size:.75rem; color:var(--ink-3); text-align:center}

/* ---------- responsive ---------- */
@media (max-width:900px){
  .stage{grid-template-columns:1fr; min-height:0}
  .aside{padding:22px 22px 24px; flex-direction:row; align-items:center; gap:16px}
  .aside::after{width:340px; height:340px; right:-150px; top:-160px}
  .pitch, .preview, .aside .foot{display:none}
  .side{padding:34px 22px 48px; min-height:calc(100dvh - 84px)}
}
@media (max-width:420px){
  .form h1{font-size:1.35rem}
}
@media (prefers-reduced-motion:reduce){*{animation:none !important; transition:none !important}}
</style>
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="i-user" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol>
  <symbol id="i-lock" viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></symbol>
  <symbol id="i-eye" viewBox="0 0 24 24"><path d="M1.8 12S5.5 5 12 5s10.2 7 10.2 7-3.7 7-10.2 7S1.8 12 1.8 12z"/><circle cx="12" cy="12" r="3"/></symbol>
  <symbol id="i-eye-off" viewBox="0 0 24 24"><path d="M10.6 6.2A7.6 7.6 0 0 1 12 6c6.5 0 10.2 6 10.2 6a17 17 0 0 1-3.3 3.9M6.5 7.6A17 17 0 0 0 1.8 12S5.5 18 12 18a9.6 9.6 0 0 0 3.4-.6"/><path d="M3 3l18 18"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></symbol>
  <symbol id="i-caps" viewBox="0 0 24 24"><path d="m12 4 7 7h-4v4H9v-4H5z"/><rect x="9" y="18" width="6" height="2.5" rx="1"/></symbol>
  <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M4 12h15"/><path d="m13 6 6 6-6 6"/></symbol>
</svg>

<div class="stage">

  <aside class="aside">
    <p class="mark">SL <b>Diagnostics</b> <span>Token desk</span></p>

    <div class="pitch">
      <h2>The queue, in one place.</h2>
      <p>Issue tokens, call patients to the right department, and see today's turnaround as it happens.</p>

      <div class="preview" aria-hidden="true">
        <p class="cap"><span class="dot"></span> Now calling</p>
        <p class="tok">042</p>
        <p class="meta">CT &middot; 6 waiting</p>
      </div>
    </div>

    <p class="foot">&copy; <?= date('Y') ?> SL Diagnostics &middot; Queue management system</p>
  </aside>

  <main class="side">
    <div class="form">
      <p class="eyebrow">Staff access</p>
      <h1>Sign in</h1>
      <p class="sub">Use the username your administrator set up for you.</p>

      <?php if ($error): ?>
        <div class="alert" role="alert">
          <svg class="ico"><use href="#i-alert"/></svg>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" id="loginForm">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

        <div class="field">
          <label for="username">Username</label>
          <div class="control">
            <svg class="ico"><use href="#i-user"/></svg>
            <input type="text" id="username" name="username" required
                   autocomplete="username" autocapitalize="none" spellcheck="false"
                   placeholder="Your username" value="<?= e($posted_user) ?>"
                   <?= $locked_for > 0 ? 'disabled' : '' ?>>
          </div>
        </div>

        <div class="field">
          <label for="password">Password</label>
          <div class="control">
            <svg class="ico"><use href="#i-lock"/></svg>
            <input type="password" id="password" name="password" required
                   autocomplete="current-password" placeholder="Your password"
                   <?= $locked_for > 0 ? 'disabled' : '' ?>>
            <button type="button" class="peek" id="peek" aria-label="Show password" aria-pressed="false">
              <svg class="ico" id="peekIcon"><use href="#i-eye"/></svg>
            </button>
          </div>
          <p class="caps" id="capsHint">
            <svg class="ico" style="width:14px;height:14px"><use href="#i-caps"/></svg>
            Caps Lock is on
          </p>
        </div>

        <button type="submit" name="login" value="1" class="submit" id="submitBtn"
                <?= $locked_for > 0 ? 'disabled' : '' ?>>
          <span id="submitLabel"><?= $locked_for > 0 ? 'Locked for ' . $locked_for . 's' : 'Sign in' ?></span>
          <svg class="ico"><use href="#i-arrow"/></svg>
        </button>
      </form>

      <p class="legal">Forgot your password? Contact your system administrator.</p>
    </div>
  </main>

</div>

<script>
(function () {
  'use strict';

  var pwd  = document.getElementById('password');
  var peek = document.getElementById('peek');
  var icon = document.getElementById('peekIcon');
  var caps = document.getElementById('capsHint');
  var form = document.getElementById('loginForm');
  var btn  = document.getElementById('submitBtn');
  var label = document.getElementById('submitLabel');
  var user = document.getElementById('username');

  /* show / hide password */
  peek.addEventListener('click', function () {
    var showing = pwd.type === 'text';
    pwd.type = showing ? 'password' : 'text';
    peek.setAttribute('aria-pressed', showing ? 'false' : 'true');
    peek.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    icon.innerHTML = '<use href="#i-' + (showing ? 'eye' : 'eye-off') + '"/>';
    pwd.focus();
  });

  /* caps lock warning */
  function capsCheck(ev) {
    if (typeof ev.getModifierState !== 'function') return;
    caps.classList.toggle('on', ev.getModifierState('CapsLock'));
  }
  pwd.addEventListener('keydown', capsCheck);
  pwd.addEventListener('keyup', capsCheck);
  pwd.addEventListener('blur', function () { caps.classList.remove('on'); });

  /* one submit only */
  form.addEventListener('submit', function () {
    setTimeout(function () {
      btn.disabled = true;
      label.textContent = 'Signing in…';
    }, 0);
  });

  /* lockout countdown, re-enables the form on its own */
  var lockLeft = <?= (int)$locked_for ?>;
  if (lockLeft > 0) {
    var tick = setInterval(function () {
      lockLeft--;
      if (lockLeft <= 0) {
        clearInterval(tick);
        btn.disabled = false;
        user.disabled = false;
        pwd.disabled = false;
        label.textContent = 'Sign in';
        user.focus();
      } else {
        label.textContent = 'Locked for ' + lockLeft + 's';
      }
    }, 1000);
  } else {
    (user.value ? pwd : user).focus();
  }
})();
</script>

</body>
</html>