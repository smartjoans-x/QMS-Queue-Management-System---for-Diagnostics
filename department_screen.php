<?php
session_start();
include 'config/db_connect.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'department_user') {
    header('Location: login.php');
    exit;
}

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$user_id = (int)$_SESSION['user_id'];
$search  = trim($_GET['q'] ?? ($_POST['q'] ?? ''));

// --- 1. Departments assigned to this user ---
$assigned_dept_ids = [];
$dept_names_list   = [];

$dept_res = mysqli_query($mysql_conn, "
    SELECT ud.dept_id, d.dept_name
    FROM user_departments ud
    JOIN departments d ON ud.dept_id = d.id
    WHERE ud.user_id = $user_id
    ORDER BY d.dept_name");

while ($row = mysqli_fetch_assoc($dept_res)) {
    $assigned_dept_ids[] = (int)$row['dept_id'];
    $dept_names_list[]   = $row['dept_name'];
}

if (empty($assigned_dept_ids)) {
    die("<div style='padding:100px; text-align:center; font-family:system-ui,sans-serif;'>
         <h2>No departments assigned</h2>
         <p>Ask an administrator to link your login to a department, then sign in again.</p>
         <a href='logout.php'>Sign out</a></div>");
}
$dept_ids_csv = implode(',', $assigned_dept_ids);

// --- 2. Actions (redirect after post, keeping the search) ---
$back = 'department_screen.php' . ($search !== '' ? '?q=' . urlencode($search) : '');

if (isset($_POST['call_token'])) {
    $token_id = (int)$_POST['token_id'];
    $token_q  = mysqli_query($mysql_conn,
        "SELECT token_number, pat_name, dept_id FROM tokens WHERE id = $token_id AND dept_id IN ($dept_ids_csv)");
    $token = mysqli_fetch_assoc($token_q);
    if ($token) {
        mysqli_query($mysql_conn, "DELETE FROM popup_notifications WHERE token_id = $token_id");
        $stmt = mysqli_prepare($mysql_conn,
            "INSERT INTO popup_notifications (token_id, dept_id, token_number, pat_name) VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'iiss', $token_id, $token['dept_id'], $token['token_number'], $token['pat_name']);
        mysqli_stmt_execute($stmt);
    }
    header('Location: ' . $back); exit;
}

if (isset($_POST['stop_call'])) {
    $token_id = (int)$_POST['token_id'];
    mysqli_query($mysql_conn, "DELETE FROM popup_notifications WHERE token_id = $token_id");
    header('Location: ' . $back); exit;
}

if (isset($_POST['accept_token'])) {
    $token_id = (int)$_POST['token_id'];
    mysqli_query($mysql_conn,
        "UPDATE tokens SET status = 'called', accepted_date = NOW() WHERE id = $token_id AND dept_id IN ($dept_ids_csv)");
    mysqli_query($mysql_conn, "DELETE FROM popup_notifications WHERE token_id = $token_id");
    header('Location: ' . $back); exit;
}

if (isset($_POST['complete_token'])) {
    $token_id = (int)$_POST['token_id'];
    mysqli_query($mysql_conn,
        "UPDATE tokens SET status = 'completed', completed_date = NOW() WHERE id = $token_id AND dept_id IN ($dept_ids_csv)");
    header('Location: ' . $back); exit;
}

// --- 3. Drop announcements older than 60 seconds ---
mysqli_query($mysql_conn, "DELETE FROM popup_notifications WHERE created_at < (NOW() - INTERVAL 60 SECOND)");

// --- 4. Waiting ---
if ($search !== '') {
    $like = '%' . $search . '%';
    $w_stmt = mysqli_prepare($mysql_conn,
        "SELECT * FROM tokens
         WHERE dept_id IN ($dept_ids_csv) AND status = 'pending' AND created_date = CURDATE()
           AND (pat_name LIKE ? OR sid_no LIKE ?)
         ORDER BY id ASC");
    mysqli_stmt_bind_param($w_stmt, 'ss', $like, $like);
    mysqli_stmt_execute($w_stmt);
    $waiting_tokens = mysqli_fetch_all(mysqli_stmt_get_result($w_stmt), MYSQLI_ASSOC);
} else {
    $waiting_tokens = mysqli_fetch_all(mysqli_query($mysql_conn,
        "SELECT * FROM tokens
         WHERE dept_id IN ($dept_ids_csv) AND status = 'pending' AND created_date = CURDATE()
         ORDER BY id ASC"), MYSQLI_ASSOC);
}

// --- 5. In progress (elapsed measured on the server, not the browser clock) ---
$accepted_tokens = mysqli_fetch_all(mysqli_query($mysql_conn,
    "SELECT *, TIMESTAMPDIFF(SECOND, accepted_date, NOW()) AS elapsed_seconds
     FROM tokens
     WHERE dept_id IN ($dept_ids_csv) AND status = 'called' AND created_date = CURDATE()
     ORDER BY accepted_date ASC"), MYSQLI_ASSOC);

// --- 6. Recently completed (latest 30) ---
$completed_tokens = mysqli_fetch_all(mysqli_query($mysql_conn,
    "SELECT token_number, pat_name, pat_age, pat_sex, sid_no, accepted_date, completed_date,
            TIMESTAMPDIFF(SECOND, accepted_date, completed_date) AS duration_seconds
     FROM tokens
     WHERE dept_id IN ($dept_ids_csv) AND status = 'completed' AND created_date = CURDATE()
     ORDER BY completed_date DESC
     LIMIT 30"), MYSQLI_ASSOC);

$done_total = (int)(mysqli_fetch_assoc(mysqli_query($mysql_conn,
    "SELECT COUNT(*) AS c FROM tokens
     WHERE dept_id IN ($dept_ids_csv) AND status = 'completed' AND created_date = CURDATE()"))['c'] ?? 0);

// --- 7. Which tokens are being announced right now ---
$called_ids = [];
$called_res = mysqli_query($mysql_conn, "SELECT token_id FROM popup_notifications WHERE dept_id IN ($dept_ids_csv)");
while ($r = mysqli_fetch_assoc($called_res)) $called_ids[] = (int)$r['token_id'];

if (!function_exists('hms')) {
    function hms($seconds) {
        $seconds = max(0, (int)$seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        if ($m >= 60) {
            return intdiv($m, 60) . 'h ' . str_pad($m % 60, 2, '0', STR_PAD_LEFT) . 'm';
        }
        return $m . 'm ' . str_pad($s, 2, '0', STR_PAD_LEFT) . 's';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#00325C">
<meta name="robots" content="noindex, nofollow">
<title>Department panel | SL Diagnostics</title>
<style>
:root{
  --ink:#0A1A2A; --ink-2:#41586C; --ink-3:#7B90A3;
  --line:#DCE5EC; --line-soft:#EBF1F6;
  --paper:#EEF3F7; --surface:#FFFFFF;
  --navy:#004B87; --navy-deep:#00325C; --navy-tint:#E5EEF6;
  --signal:#9A6600; --signal-tint:#FBF0D9; --signal-bar:#E0A32E;
  --clear:#0E7C63; --clear-tint:#E1F1ED;
  --alert:#B3392F; --alert-tint:#FBEAE8;
  --r:14px; --r-sm:10px;
  --mono:ui-monospace,"SFMono-Regular","SF Mono",Menlo,Consolas,"Liberation Mono",monospace;
  --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --shadow:0 1px 2px rgba(10,26,42,.05), 0 8px 24px -14px rgba(10,26,42,.22);
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0; background:var(--paper); color:var(--ink); font-family:var(--sans);
  font-size:15px; line-height:1.5; padding-top:60px; -webkit-font-smoothing:antialiased}
h1,h2{margin:0; font-weight:700; letter-spacing:-.02em}
.num{font-family:var(--mono); font-variant-numeric:tabular-nums; letter-spacing:-.02em}
:focus-visible{outline:2px solid var(--navy); outline-offset:2px; border-radius:6px}
.ico{width:16px;height:16px;flex:none;stroke:currentColor;fill:none;stroke-width:1.8;
     stroke-linecap:round;stroke-linejoin:round}

/* top bar */
.topbar{position:fixed; inset:0 0 auto 0; height:60px; z-index:60; display:flex;
  align-items:center; gap:12px; padding:0 20px; background:rgba(255,255,255,.88);
  backdrop-filter:saturate(160%) blur(10px); border-bottom:1px solid var(--line)}
.brand{display:flex; align-items:baseline; gap:8px; text-decoration:none; color:inherit;
  font-weight:800; font-size:1.05rem; letter-spacing:-.02em; white-space:nowrap}
.brand b{color:var(--navy)}
.brand span{font-size:.62rem; font-weight:700; letter-spacing:.16em; text-transform:uppercase;
  color:var(--ink-3); padding-left:10px; border-left:1px solid var(--line)}
.topbar .right{margin-left:auto; display:flex; gap:8px; align-items:center}

.btn{display:inline-flex; align-items:center; justify-content:center; gap:8px;
  text-decoration:none; padding:10px 15px; border-radius:10px; font-size:.82rem;
  font-weight:700; font-family:inherit; border:1px solid transparent; cursor:pointer;
  transition:.15s; color:var(--ink)}
.btn-solid{background:var(--navy); color:#fff}
.btn-solid:hover{background:var(--navy-deep)}
.btn-ghost{background:var(--surface); border-color:var(--line)}
.btn-ghost:hover{border-color:var(--navy); color:var(--navy-deep)}
.btn-out{background:var(--alert-tint); color:var(--alert)}
.btn-out:hover{background:#F6DCD9}

/* shell */
.wrap{max-width:1800px; margin:0 auto; padding:22px 20px 50px}

/* control strip */
.strip{display:flex; flex-wrap:wrap; align-items:center; gap:14px; background:var(--surface);
  border:1px solid var(--line); border-radius:var(--r); box-shadow:var(--shadow);
  padding:14px 18px; margin-bottom:22px}
.strip .lbl{font-size:.66rem; font-weight:800; letter-spacing:.14em; text-transform:uppercase;
  color:var(--ink-3)}
.badges{display:flex; flex-wrap:wrap; gap:6px; align-items:center}
.badge{background:var(--navy-tint); color:var(--navy-deep); padding:5px 11px; border-radius:99px;
  font-size:.74rem; font-weight:700}
.strip .spacer{margin-left:auto}
.searchform{display:flex; gap:8px; align-items:center}
.searchbox{position:relative}
.searchbox .ico{position:absolute; left:11px; top:50%; margin-top:-8px; color:var(--ink-3)}
.searchbox input{width:230px; padding:10px 12px 10px 33px; border:1px solid var(--line);
  border-radius:10px; font:inherit; font-size:.85rem; background:var(--paper); color:var(--ink)}
.searchbox input:focus{background:var(--surface); border-color:var(--navy); outline:none}
.clearlink{color:var(--ink-3); text-decoration:none; font-size:.78rem; font-weight:700}
.clearlink:hover{color:var(--alert)}
.refresh{display:inline-flex; align-items:center; gap:7px; padding:9px 13px; border-radius:99px;
  background:var(--paper); border:1px solid var(--line); color:var(--ink-2); font-size:.75rem;
  font-weight:700; cursor:pointer; font-family:inherit; white-space:nowrap}
.refresh:hover{border-color:var(--navy); color:var(--navy-deep)}
.refresh .ico{width:14px; height:14px}
.refresh.paused{color:var(--ink-3)}

/* columns */
.board{display:grid; grid-template-columns:repeat(auto-fit,minmax(360px,1fr)); gap:20px; align-items:start}
.col{background:var(--surface); border:1px solid var(--line); border-radius:var(--r);
  box-shadow:var(--shadow); overflow:hidden}
.col-head{display:flex; align-items:center; gap:10px; padding:15px 18px;
  border-bottom:1px solid var(--line-soft)}
.col-head h2{font-size:.95rem}
.col-head .n{margin-left:auto; font-family:var(--mono); font-weight:700; font-size:.95rem;
  padding:2px 10px; border-radius:8px}
.col.wait .col-head{box-shadow:inset 0 3px 0 var(--signal-bar)}
.col.wait .col-head .ico{color:var(--signal)}
.col.wait .n{background:var(--signal-tint); color:var(--signal)}
.col.prog .col-head{box-shadow:inset 0 3px 0 var(--navy)}
.col.prog .col-head .ico{color:var(--navy)}
.col.prog .n{background:var(--navy-tint); color:var(--navy-deep)}
.col.done .col-head{box-shadow:inset 0 3px 0 var(--clear)}
.col.done .col-head .ico{color:var(--clear)}
.col.done .n{background:var(--clear-tint); color:var(--clear)}

/* queue rows */
.queue{list-style:none; margin:0; padding:8px}
.qrow{display:flex; align-items:center; gap:14px; padding:12px; border-radius:var(--r-sm);
  border:1px solid transparent}
.qrow + .qrow{border-top:1px solid var(--line-soft)}
.qrow:hover{background:#FAFCFD}
.qrow.announcing{background:var(--signal-tint); border-color:#EFD9A6; border-top-color:#EFD9A6}
.tok{font-family:var(--mono); font-variant-numeric:tabular-nums; font-size:1.45rem;
  font-weight:700; color:var(--navy-deep); background:var(--navy-tint); border-radius:10px;
  padding:8px 12px; min-width:64px; text-align:center; flex:none; letter-spacing:-.02em}
.qrow.announcing .tok{background:#fff; color:var(--signal)}
.who{min-width:0; flex:1}
.who strong{display:block; font-size:.92rem; font-weight:700; letter-spacing:-.01em;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap}
.who small{display:block; margin-top:2px; color:var(--ink-3); font-size:.76rem; font-weight:600}
.acts{display:flex; align-items:center; gap:7px; flex:none}
.act{display:inline-flex; align-items:center; justify-content:center; gap:6px; border:none;
  cursor:pointer; font-family:inherit; font-weight:700; font-size:.75rem; letter-spacing:.04em;
  padding:9px 13px; border-radius:9px; transition:.15s; text-transform:uppercase}
.act-call{background:var(--navy); color:#fff; width:38px; padding:9px}
.act-call:hover{background:var(--navy-deep)}
.act-stop{background:var(--alert); color:#fff; width:38px; padding:9px}
.act-stop:hover{background:#9C3128}
.act-accept{background:var(--clear-tint); color:var(--clear); box-shadow:inset 0 0 0 1px #BFE3D9}
.act-accept:hover{background:var(--clear); color:#fff; box-shadow:none}
.act-finish{background:var(--navy); color:#fff}
.act-finish:hover{background:var(--navy-deep)}
.dot{width:7px; height:7px; border-radius:50%; background:var(--signal-bar); flex:none;
  animation:pulse 1.6s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(224,163,46,.6)}70%{box-shadow:0 0 0 7px rgba(224,163,46,0)}100%{box-shadow:0 0 0 0 rgba(224,163,46,0)}}

/* timers */
.timer{font-family:var(--mono); font-variant-numeric:tabular-nums; font-weight:700;
  font-size:.82rem; padding:6px 10px; border-radius:8px; white-space:nowrap; flex:none}
.t-ok{background:var(--clear-tint); color:var(--clear)}
.t-warn{background:var(--signal-tint); color:var(--signal)}
.t-late{background:var(--alert-tint); color:var(--alert)}
.dur{font-family:var(--mono); font-weight:700; font-size:.8rem; color:var(--clear); flex:none}
.qrow.finished{opacity:.78}

.empty{padding:34px 18px; text-align:center; color:var(--ink-3); font-size:.85rem}
.empty strong{display:block; color:var(--ink-2); font-size:.92rem; margin-bottom:4px; font-weight:700}
.more{padding:10px 18px 14px; text-align:center; color:var(--ink-3); font-size:.75rem; font-weight:600}

@media (max-width:820px){
  body{padding-top:56px}
  .topbar{height:56px; padding:0 14px}
  .brand span{display:none}
  .wrap{padding:18px 14px 40px}
  .strip{gap:10px}
  .strip .spacer{display:none}
  .searchform{width:100%}
  .searchbox{flex:1}
  .searchbox input{width:100%}
  .board{grid-template-columns:1fr}
  .qrow{flex-wrap:wrap}
  .who{flex-basis:calc(100% - 90px)}
  .acts{width:100%; justify-content:flex-end}
}
@media (prefers-reduced-motion:reduce){*{animation:none !important; transition:none !important}}
</style>
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="i-gauge" viewBox="0 0 24 24"><path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM13.4 10.6 19 5"/><path d="M4.1 18a9 9 0 1 1 15.8 0"/></symbol>
  <symbol id="i-power" viewBox="0 0 24 24"><path d="M18.36 6.64a9 9 0 1 1-12.73 0M12 2v10"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></symbol>
  <symbol id="i-wait" viewBox="0 0 24 24"><path d="M6 2h12M6 22h12"/><path d="M6 2c0 5 6 5 6 10s-6 5-6 10M18 2c0 5-6 5-6 10s6 5 6 10"/></symbol>
  <symbol id="i-play" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M10 8.5 16 12l-6 3.5z"/></symbol>
  <symbol id="i-done" viewBox="0 0 24 24"><path d="m2 13 4 4L14 9"/><path d="m11 15 2 2 9-10"/></symbol>
  <symbol id="i-mega" viewBox="0 0 24 24"><path d="M3 11v2a1 1 0 0 0 1 1h3l6 4V6L7 10H4a1 1 0 0 0-1 1z"/><path d="M17 9a4 4 0 0 1 0 6"/></symbol>
  <symbol id="i-stop" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><rect x="9" y="9" width="6" height="6" rx="1.2"/></symbol>
</svg>

<header class="topbar">
  <a href="dashboard.php" class="brand">SL <b>Diagnostics</b> <span>Department panel</span></a>
  <div class="right">
    <a href="dashboard.php" class="btn btn-ghost"><svg class="ico"><use href="#i-gauge"/></svg> Dashboard</a>
    <a href="logout.php" class="btn btn-out"><svg class="ico"><use href="#i-power"/></svg> Sign out</a>
  </div>
</header>

<main class="wrap">

  <section class="strip">
    <span class="lbl">My departments</span>
    <div class="badges">
      <?php foreach ($dept_names_list as $dn): ?>
        <span class="badge"><?= e($dn) ?></span>
      <?php endforeach; ?>
    </div>

    <span class="spacer"></span>

    <button type="button" class="refresh" id="refreshBtn">
      <svg class="ico"><use href="#i-refresh"/></svg>
      <span id="refreshLabel">Refreshing in 15s</span>
    </button>

    <form method="GET" class="searchform">
      <label class="searchbox">
        <svg class="ico"><use href="#i-search"/></svg>
        <input type="search" name="q" id="searchInput" value="<?= e($search) ?>"
               placeholder="Patient name or SID" autocomplete="off" aria-label="Search waiting list">
      </label>
      <button type="submit" class="btn btn-solid">Search</button>
      <?php if ($search !== ''): ?>
        <a href="department_screen.php" class="clearlink">Clear</a>
      <?php endif; ?>
    </form>
  </section>

  <div class="board">

    <!-- Waiting -->
    <section class="col wait">
      <div class="col-head">
        <svg class="ico"><use href="#i-wait"/></svg>
        <h2>Waiting</h2>
        <span class="n"><?= count($waiting_tokens) ?></span>
      </div>

      <?php if (empty($waiting_tokens)): ?>
        <p class="empty">
          <strong><?= $search !== '' ? 'No match in the waiting list' : 'Queue is clear' ?></strong>
          <?= $search !== '' ? 'Try a different name or SID.' : 'New tokens will appear here as they are issued.' ?>
        </p>
      <?php else: ?>
        <ul class="queue">
          <?php foreach ($waiting_tokens as $t):
            $is_calling = in_array((int)$t['id'], $called_ids, true); ?>
            <li class="qrow<?= $is_calling ? ' announcing' : '' ?>">
              <span class="tok"><?= e($t['token_number']) ?></span>
              <div class="who">
                <strong><?= e($t['pat_name']) ?></strong>
                <small>
                  <?php if ($is_calling): ?><span class="dot" style="display:inline-block;margin-right:5px"></span>Announcing &middot; <?php endif; ?>
                  SID <?= e($t['sid_no']) ?> &middot; <?= e($t['pat_age']) ?>/<?= e($t['pat_sex']) ?>
                </small>
              </div>
              <form method="POST" class="acts">
                <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="q" value="<?= e($search) ?>">
                <?php if (!$is_calling): ?>
                  <button type="submit" name="call_token" value="1" class="act act-call" title="Announce this token" aria-label="Announce token <?= e($t['token_number']) ?>">
                    <svg class="ico"><use href="#i-mega"/></svg>
                  </button>
                <?php else: ?>
                  <button type="submit" name="stop_call" value="1" class="act act-stop" title="Stop announcing" aria-label="Stop announcing token <?= e($t['token_number']) ?>">
                    <svg class="ico"><use href="#i-stop"/></svg>
                  </button>
                <?php endif; ?>
                <button type="submit" name="accept_token" value="1" class="act act-accept">Accept</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <!-- In progress -->
    <section class="col prog">
      <div class="col-head">
        <svg class="ico"><use href="#i-play"/></svg>
        <h2>In progress</h2>
        <span class="n"><?= count($accepted_tokens) ?></span>
      </div>

      <?php if (empty($accepted_tokens)): ?>
        <p class="empty">
          <strong>Nobody in the room</strong>
          Accept a waiting token to start the clock.
        </p>
      <?php else: ?>
        <ul class="queue">
          <?php foreach ($accepted_tokens as $t): $el = (int)$t['elapsed_seconds']; ?>
            <li class="qrow">
              <span class="tok"><?= e($t['token_number']) ?></span>
              <div class="who">
                <strong><?= e($t['pat_name']) ?></strong>
                <small>SID <?= e($t['sid_no']) ?></small>
              </div>
              <span class="timer <?= $el < 600 ? 't-ok' : ($el < 1200 ? 't-warn' : 't-late') ?>"
                    data-elapsed="<?= $el ?>"><?= hms($el) ?></span>
              <form method="POST" class="acts">
                <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
                <input type="hidden" name="q" value="<?= e($search) ?>">
                <button type="submit" name="complete_token" value="1" class="act act-finish">Finish</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <!-- Done -->
    <section class="col done">
      <div class="col-head">
        <svg class="ico"><use href="#i-done"/></svg>
        <h2>Completed today</h2>
        <span class="n"><?= $done_total ?></span>
      </div>

      <?php if (empty($completed_tokens)): ?>
        <p class="empty">
          <strong>Nothing finished yet</strong>
          Completed tokens land here with their room time.
        </p>
      <?php else: ?>
        <ul class="queue">
          <?php foreach ($completed_tokens as $t): ?>
            <li class="qrow finished">
              <span class="tok"><?= e($t['token_number']) ?></span>
              <div class="who">
                <strong><?= e($t['pat_name']) ?></strong>
                <small>SID <?= e($t['sid_no']) ?></small>
              </div>
              <span class="dur">
                <?= $t['duration_seconds'] !== null ? hms($t['duration_seconds']) : '&mdash;' ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($done_total > count($completed_tokens)): ?>
          <p class="more">Showing the latest <?= count($completed_tokens) ?> of <?= $done_total ?>.</p>
        <?php endif; ?>
      <?php endif; ?>
    </section>

  </div>
</main>

<script>
(function () {
  'use strict';

  /* ---- elapsed timers (seeded from the server clock) ---- */
  function fmt(sec) {
    var m = Math.floor(sec / 60), s = sec % 60;
    if (m >= 60) {
      return Math.floor(m / 60) + 'h ' + String(m % 60).padStart(2, '0') + 'm';
    }
    return m + 'm ' + String(s).padStart(2, '0') + 's';
  }

  var timers = Array.prototype.slice.call(document.querySelectorAll('.timer'));
  timers.forEach(function (el) { el.dataset.base = el.dataset.elapsed; });
  var started = Date.now();

  function tickTimers() {
    var drift = Math.floor((Date.now() - started) / 1000);
    timers.forEach(function (el) {
      var sec = parseInt(el.dataset.base, 10) + drift;
      if (sec < 0) sec = 0;
      el.textContent = fmt(sec);
      el.classList.remove('t-ok', 't-warn', 't-late');
      el.classList.add(sec < 600 ? 't-ok' : (sec < 1200 ? 't-warn' : 't-late'));
    });
  }
  if (timers.length) setInterval(tickTimers, 1000);

  /* ---- auto refresh, pausable ---- */
  var SECONDS = 15;
  var left = SECONDS, running = true;
  var btn = document.getElementById('refreshBtn');
  var label = document.getElementById('refreshLabel');
  var input = document.getElementById('searchInput');

  function paint() {
    label.textContent = running ? 'Refreshing in ' + left + 's' : 'Auto refresh paused';
    btn.classList.toggle('paused', !running);
  }
  function pause() { running = false; paint(); }
  function resume() { running = true; left = SECONDS; paint(); }

  btn.addEventListener('click', function () {
    if (running) { pause(); } else { location.reload(); }
  });

  /* never reload out from under someone who is typing */
  if (input) {
    input.addEventListener('focus', pause);
    input.addEventListener('blur', function () { if (input.value === '') resume(); });
  }

  setInterval(function () {
    if (!running) return;
    left--;
    if (left <= 0) { location.reload(); return; }
    paint();
  }, 1000);
  paint();

  /* ---- stop double taps on action buttons ---- */
  Array.prototype.forEach.call(document.querySelectorAll('.acts'), function (form) {
    form.addEventListener('submit', function () {
      pause();
      setTimeout(function () {
        Array.prototype.forEach.call(form.querySelectorAll('button'), function (b) {
          b.disabled = true;
          b.style.opacity = '.55';
        });
      }, 0);
    });
  });
})();
</script>

</body>
</html>