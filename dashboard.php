<?php
session_start();
include 'config/db_connect.php';
include 'track_login.php';

if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {

    if (!isset($_SESSION['dashboard_logged_in'])) {
        log_activity($mysql_conn, $_SESSION['user_id'], $_SESSION['username']);
        $_SESSION['dashboard_logged_in'] = true;
    }

} else {
    header('Location: login.php');
    exit;
}

$user_id      = (int)$_SESSION['user_id'];
$role         = $_SESSION['role'] ?? '';
$current_date = date('Y-m-d');

$lab_departments = [
    'Biochemistry', 'Cytology', 'Genetic', 'Haematology', 'Histopathology',
    'MICROBIOLOGY', 'Molecular Biology', 'Out source',
    'Pathology', 'Sample Collection', 'Serology'
];

// --- 1. Fetch Assigned Departments for the User ---
$assigned_dept_ids = [];
$is_admin = ($role !== 'department_user');

if (!$is_admin) {
    $dept_res = mysqli_query($mysql_conn, "
        SELECT ud.dept_id FROM user_departments ud WHERE ud.user_id = $user_id");

    while ($row = mysqli_fetch_assoc($dept_res)) {
        $assigned_dept_ids[] = (int)$row['dept_id'];
    }

    if (empty($assigned_dept_ids)) {
        die("<div style='padding:100px; text-align:center; font-family:system-ui,sans-serif;'>
             <h2>No departments assigned</h2>
             <p>Ask an administrator to link your login to a department, then sign in again.</p>
             <a href='logout.php'>Sign out</a></div>");
    }
    $dept_ids_csv = implode(',', $assigned_dept_ids);
}

// --- 2. Total Unique Patients (SIDs) Today ---
if ($is_admin) {
    $total_tokens_query = "SELECT COUNT(DISTINCT sid_no) as total FROM tokens WHERE created_date = CURDATE()";
} else {
    $total_tokens_query = "SELECT COUNT(DISTINCT sid_no) as total FROM tokens WHERE created_date = CURDATE() AND dept_id IN ($dept_ids_csv)";
}
$total_res    = mysqli_query($mysql_conn, $total_tokens_query);
$total_tokens = mysqli_fetch_assoc($total_res)['total'] ?? 0;

// --- 3. Department-wise Stats with Merge Logic ---
$current_stats = [];
$stats_query = "SELECT d.dept_name, d.id,
    COUNT(CASE WHEN t.status = 'pending' THEN 1 END) as pending,
    COUNT(CASE WHEN t.status = 'completed' THEN 1 END) as completed_count,
    MAX(CASE WHEN t.status = 'called' THEN t.token_number END) as current_token,
    MAX(CASE WHEN t.status = 'completed' THEN t.token_number END) as last_completed,
    SUM(CASE WHEN t.status = 'completed' AND t.completed_date IS NOT NULL AND t.accepted_date IS NOT NULL
             THEN TIMESTAMPDIFF(MINUTE, t.accepted_date, t.completed_date)
             ELSE 0 END) as total_time_minutes
    FROM departments d
    LEFT JOIN tokens t ON d.id = t.dept_id AND t.created_date = '$current_date'
    WHERE 1=1";

if (!$is_admin) {
    $stats_query .= " AND d.id IN ($dept_ids_csv)";
}

$stats_query .= " GROUP BY d.id, d.dept_name";
$result = mysqli_query($mysql_conn, $stats_query);

while ($row = mysqli_fetch_assoc($result)) {
    $raw_name = trim($row['dept_name']);
    $display_name = $raw_name;

    // --- MERGE LOGIC START ---
    if (in_array($raw_name, $lab_departments)) {
        $display_name = 'LAB';
    } elseif (strcasecmp($raw_name, 'MRI') == 0 || strcasecmp($raw_name, 'M.R.I') == 0) {
        $display_name = 'MRI';
    } elseif (strcasecmp($raw_name, 'CT') == 0 || strcasecmp($raw_name, 'C.T') == 0) {
        $display_name = 'CT';
    }
    // --- MERGE LOGIC END ---

    if (!isset($current_stats[$display_name])) {
        $current_stats[$display_name] = [
            'pending' => 0, 'completed_count' => 0, 'current_token' => null,
            'last_completed' => null, 'total_time_minutes' => 0
        ];
    }

    $current_stats[$display_name]['pending']            += (int)$row['pending'];
    $current_stats[$display_name]['completed_count']    += (int)$row['completed_count'];

    // பிற்கால டோக்கன் எண்களை முன்னுரிமைப்படுத்துதல்
    if ($row['current_token'])  $current_stats[$display_name]['current_token']  = $row['current_token'];
    if ($row['last_completed']) $current_stats[$display_name]['last_completed'] = $row['last_completed'];

    $current_stats[$display_name]['total_time_minutes'] += (int)$row['total_time_minutes'];
}

ksort($current_stats); // அகரவரிசைப்படி அடுக்குகிறோம்

// --- 4. Roll-ups for the summary strip ---
$total_pending    = 0;
$total_completed  = 0;
$total_minutes    = 0;
$max_pending      = 0;
$active_calls     = 0;

foreach ($current_stats as $stat) {
    $total_pending   += $stat['pending'];
    $total_completed += $stat['completed_count'];
    $total_minutes   += $stat['total_time_minutes'];
    if ($stat['pending'] > $max_pending) $max_pending = $stat['pending'];
    if (!empty($stat['current_token']))  $active_calls++;
}
$overall_avg = $total_completed > 0 ? round($total_minutes / $total_completed) : null;

// Departments sorted by queue depth, for the waiting-load panel
$by_load = $current_stats;
uasort($by_load, function ($a, $b) { return $b['pending'] <=> $a['pending']; });

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$display_username = htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8');

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
<title>Dashboard | SL Diagnostics</title>
<style>
/* ============ tokens ============ */
:root{
  --ink:#0A1A2A; --ink-2:#41586C; --ink-3:#7B90A3;
  --line:#DCE5EC; --line-soft:#EBF1F6;
  --paper:#EEF3F7; --surface:#FFFFFF;
  --navy:#004B87; --navy-deep:#00325C; --navy-tint:#E5EEF6;
  --signal:#9A6600; --signal-tint:#FBF0D9; --signal-bar:#E0A32E;
  --clear:#0E7C63; --clear-tint:#E1F1ED;
  --alert:#B3392F;
  --r:14px; --r-sm:10px;
  --mono:ui-monospace,"SFMono-Regular","SF Mono",Menlo,Consolas,"Liberation Mono",monospace;
  --sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --shadow:0 1px 2px rgba(10,26,42,.05), 0 8px 24px -14px rgba(10,26,42,.22);
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{
  margin:0; background:var(--paper); color:var(--ink);
  font-family:var(--sans); font-size:15px; line-height:1.5;
  padding-top:60px; -webkit-font-smoothing:antialiased;
}
h1,h2,h3{margin:0; font-weight:700; letter-spacing:-.02em}
a{color:inherit}
.num{font-family:var(--mono); font-variant-numeric:tabular-nums; letter-spacing:-.02em}
.eyebrow{
  margin:0 0 6px; font-size:.68rem; font-weight:700; letter-spacing:.14em;
  text-transform:uppercase; color:var(--ink-3);
}
:focus-visible{outline:2px solid var(--navy); outline-offset:2px; border-radius:6px}

/* ============ top bar ============ */
.topbar{
  position:fixed; inset:0 0 auto 0; height:60px; z-index:60;
  display:flex; align-items:center; gap:16px; padding:0 20px;
  background:rgba(255,255,255,.88); backdrop-filter:saturate(160%) blur(10px);
  border-bottom:1px solid var(--line);
}
.brand{
  display:flex; align-items:baseline; gap:8px; text-decoration:none;
  font-weight:800; font-size:1.05rem; letter-spacing:-.02em; white-space:nowrap;
}
.brand b{color:var(--navy)}
.brand span{
  font-size:.62rem; font-weight:700; letter-spacing:.16em; text-transform:uppercase;
  color:var(--ink-3); padding-left:10px; margin-left:2px; border-left:1px solid var(--line);
}
.nav{display:flex; align-items:center; gap:4px; margin-left:auto}
.nav a{
  display:inline-flex; align-items:center; gap:7px; text-decoration:none;
  padding:8px 12px; border-radius:9px; font-size:.8rem; font-weight:600;
  color:var(--ink-2); white-space:nowrap; transition:background .15s,color .15s;
}
.nav a:hover{background:var(--navy-tint); color:var(--navy-deep)}
.nav a.is-admin{color:var(--navy-deep)}
.nav a.is-dept{background:var(--signal-tint); color:var(--signal)}
.nav a.is-out{color:var(--alert)}
.nav a.is-out:hover{background:#FBEAE8; color:var(--alert)}
.nav .sep{width:1px; height:22px; background:var(--line); margin:0 6px}
.ico{width:16px; height:16px; flex:none; stroke:currentColor; fill:none; stroke-width:1.8;
     stroke-linecap:round; stroke-linejoin:round}
.nav-toggle{
  display:none; margin-left:auto; width:38px; height:38px; align-items:center;
  justify-content:center; background:var(--surface); border:1px solid var(--line);
  border-radius:9px; color:var(--ink); cursor:pointer;
}

/* ============ shell ============ */
.wrap{max-width:1560px; margin:0 auto; padding:26px 20px 56px}

/* ============ masthead ============ */
.masthead{
  display:flex; flex-wrap:wrap; gap:18px; align-items:flex-end;
  justify-content:space-between; margin-bottom:20px;
}
.masthead h1{font-size:1.65rem}
.clock{
  margin:8px 0 0; display:flex; align-items:center; gap:8px;
  color:var(--ink-2); font-size:.85rem; font-weight:600;
}
.clock .ico{width:15px; height:15px; color:var(--ink-3)}
.actions{display:flex; gap:10px; flex-wrap:wrap}
.btn{
  display:inline-flex; align-items:center; gap:8px; text-decoration:none;
  padding:11px 16px; border-radius:10px; font-size:.83rem; font-weight:700;
  border:1px solid transparent; cursor:pointer; font-family:inherit; transition:.15s;
}
.btn-solid{background:var(--navy); color:#fff; box-shadow:0 6px 16px -8px rgba(0,75,135,.8)}
.btn-solid:hover{background:var(--navy-deep)}
.btn-ghost{background:var(--surface); color:var(--ink); border-color:var(--line)}
.btn-ghost:hover{border-color:var(--navy); color:var(--navy-deep)}

/* ============ ledger strip ============ */
.ledger{
  display:grid; grid-template-columns:repeat(4,1fr); background:var(--surface);
  border:1px solid var(--line); border-radius:var(--r); box-shadow:var(--shadow);
  overflow:hidden; margin-bottom:26px;
}
.ledger div{padding:18px 22px; border-left:1px solid var(--line-soft)}
.ledger div:first-child{border-left:0}
.ledger dt{
  margin:0 0 6px; font-size:.68rem; font-weight:700; letter-spacing:.12em;
  text-transform:uppercase; color:var(--ink-3);
}
.ledger dd{margin:0; font-size:1.9rem; font-weight:700; line-height:1.1}
.ledger dd small{font-size:.8rem; font-weight:600; color:var(--ink-3); margin-left:3px; letter-spacing:0}
.v-wait{color:var(--signal)} .v-done{color:var(--clear)} .v-navy{color:var(--navy-deep)}

/* ============ section heads ============ */
.head{display:flex; align-items:center; gap:12px; margin:0 0 12px}
.head h2{font-size:1rem}
.head .tag{
  font-size:.68rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
  color:var(--ink-3); padding:3px 9px; background:var(--surface);
  border:1px solid var(--line); border-radius:99px;
}
.head .spacer{margin-left:auto}

/* ============ call board (signature) ============ */
.board{margin-bottom:28px}
.rail{
  display:grid; grid-auto-flow:column; grid-auto-columns:minmax(180px,1fr);
  gap:12px; overflow-x:auto; padding-bottom:6px; scroll-snap-type:x proximity;
}
.rail::-webkit-scrollbar{height:8px}
.rail::-webkit-scrollbar-thumb{background:#CFDBE5; border-radius:99px}
.call{
  scroll-snap-align:start; position:relative; background:var(--surface);
  border:1px solid var(--line); border-radius:var(--r); padding:16px 18px 14px;
  box-shadow:var(--shadow); overflow:hidden;
}
.call::before{content:""; position:absolute; inset:0 auto 0 0; width:4px; background:var(--line)}
.call.live::before{background:var(--signal-bar)}
.call .dept{
  font-size:.72rem; font-weight:800; letter-spacing:.1em; text-transform:uppercase;
  color:var(--ink-2); display:flex; align-items:center; gap:7px;
}
.dot{width:7px; height:7px; border-radius:50%; background:#CBD6E0; flex:none}
.call.live .dot{background:var(--signal-bar); box-shadow:0 0 0 0 rgba(224,163,46,.55); animation:pulse 2s infinite}
@keyframes pulse{70%{box-shadow:0 0 0 8px rgba(224,163,46,0)}100%{box-shadow:0 0 0 0 rgba(224,163,46,0)}}
.call .token{font-size:2.15rem; font-weight:700; line-height:1.15; margin:8px 0 2px}
.call.live .token{color:var(--navy-deep)}
.call.idle .token{color:#C2CEDA}
.call .meta{font-size:.75rem; color:var(--ink-3); font-weight:600}
.call .meta b{color:var(--signal); font-weight:800}

/* ============ split ============ */
.split{display:grid; grid-template-columns:370px 1fr; gap:22px; align-items:start}
.panel{
  background:var(--surface); border:1px solid var(--line); border-radius:var(--r);
  box-shadow:var(--shadow); overflow:hidden;
}
.panel-head{
  padding:16px 20px; border-bottom:1px solid var(--line-soft);
  display:flex; align-items:center; gap:10px;
}
.panel-head h2{font-size:.95rem}
.panel-head .ico{color:var(--navy); width:17px; height:17px}
.panel-head .spacer{margin-left:auto}

/* waiting load list */
.load{list-style:none; margin:0; padding:6px 8px}
.load li{padding:11px 12px; border-radius:var(--r-sm)}
.load li:hover{background:var(--paper)}
.load .row{display:flex; align-items:baseline; gap:10px; margin-bottom:8px}
.load .name{font-weight:700; font-size:.85rem; letter-spacing:-.01em}
.load .qty{margin-left:auto; font-weight:800; font-size:.95rem; color:var(--signal)}
.load .qty.zero{color:var(--ink-3); font-weight:600}
.bar{height:6px; border-radius:99px; background:var(--line-soft); overflow:hidden}
.bar i{display:block; height:100%; border-radius:99px; background:var(--signal-bar)}
.bar i.empty{background:var(--line)}

/* activity table */
.scroll{overflow-x:auto}
table{width:100%; border-collapse:collapse; min-width:620px}
th{
  position:sticky; top:0; background:var(--surface); text-align:left; padding:12px 18px;
  font-size:.68rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
  color:var(--ink-3); border-bottom:1px solid var(--line);
}
th.r, td.r{text-align:right}
td{padding:14px 18px; border-bottom:1px solid var(--line-soft); font-size:.88rem}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover td{background:#FAFCFD}
.dept-cell{font-weight:700; letter-spacing:-.01em}
.chip{
  display:inline-block; padding:4px 10px; border-radius:8px; font-weight:700;
  font-size:.85rem; background:var(--navy-tint); color:var(--navy-deep);
}
.chip.off{background:var(--line-soft); color:var(--ink-3)}
.wait-n{font-weight:800; color:var(--signal)}
.wait-n.zero{color:var(--ink-3); font-weight:600}
.done-n{font-weight:800; color:var(--clear)}
.done-n.zero{color:var(--ink-3); font-weight:600}
.muted{color:var(--ink-3); font-weight:600}
.avg{font-weight:700}
.avg small{font-weight:600; color:var(--ink-3); margin-left:2px}
.empty{padding:44px 20px; text-align:center; color:var(--ink-3); font-size:.88rem}

/* search */
.search{position:relative}
.search .ico{position:absolute; left:10px; top:50%; margin-top:-8px; color:var(--ink-3); width:15px; height:15px}
.search input{
  width:190px; padding:8px 12px 8px 31px; border:1px solid var(--line); border-radius:9px;
  font:inherit; font-size:.82rem; background:var(--paper); color:var(--ink);
}
.search input:focus{background:var(--surface); border-color:var(--navy); outline:none}

/* refresh pill */
.refresh{
  display:inline-flex; align-items:center; gap:7px; padding:7px 12px; border-radius:99px;
  background:var(--surface); border:1px solid var(--line); color:var(--ink-2);
  font-size:.75rem; font-weight:700; cursor:pointer; font-family:inherit;
}
.refresh:hover{border-color:var(--navy); color:var(--navy-deep)}
.refresh .ico{width:14px; height:14px}
.refresh.paused{color:var(--ink-3)}

/* ============ responsive ============ */
@media (max-width:1180px){
  .split{grid-template-columns:1fr}
  .ledger{grid-template-columns:repeat(2,1fr)}
  .ledger div:nth-child(3){border-left:0}
  .ledger div:nth-child(n+3){border-top:1px solid var(--line-soft)}
}
@media (max-width:820px){
  body{padding-top:56px}
  .topbar{height:56px; padding:0 14px; flex-wrap:wrap; align-items:center}
  .nav-toggle{display:inline-flex}
  .nav{
    display:none; order:3; width:calc(100% + 28px); margin:0 -14px;
    flex-direction:column; align-items:stretch; gap:2px; padding:8px 10px 12px;
    background:var(--surface); border-top:1px solid var(--line);
  }
  .nav.open{display:flex}
  .nav a{padding:12px 14px; font-size:.88rem}
  .nav .sep{display:none}
  .wrap{padding:20px 14px 44px}
  .masthead h1{font-size:1.35rem}
  .actions{width:100%}
  .actions .btn{flex:1; justify-content:center}
  .rail{grid-auto-columns:minmax(158px,1fr)}
  .ledger dd{font-size:1.5rem}
  .ledger div{padding:14px 16px}
  .search input{width:130px}
}
@media (max-width:520px){
  .ledger{grid-template-columns:1fr}
  .ledger div{border-left:0; border-top:1px solid var(--line-soft)}
  .ledger div:first-child{border-top:0}
}
@media (prefers-reduced-motion:reduce){
  *{animation:none !important; transition:none !important; scroll-behavior:auto !important}
}
@media print{
  .topbar,.actions,.refresh,.search,.nav-toggle{display:none !important}
  body{padding-top:0; background:#fff}
  .panel,.ledger,.call{box-shadow:none}
  .split{grid-template-columns:1fr}
}
</style>
</head>
<body>

<!-- icon sprite -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="i-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
  <symbol id="i-log" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 15h6M9 11h3"/></symbol>
  <symbol id="i-plus" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></symbol>
  <symbol id="i-print" viewBox="0 0 24 24"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></symbol>
  <symbol id="i-chart" viewBox="0 0 24 24"><path d="M18 20V10M12 20V4M6 20v-6"/></symbol>
  <symbol id="i-screen" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></symbol>
  <symbol id="i-power" viewBox="0 0 24 24"><path d="M18.36 6.64a9 9 0 1 1-12.73 0M12 2v10"/></symbol>
  <symbol id="i-eye" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
  <symbol id="i-menu" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></symbol>
  <symbol id="i-wait" viewBox="0 0 24 24"><path d="M6 2h12M6 22h12"/><path d="M6 2c0 5 6 5 6 10s-6 5-6 10M18 2c0 5-6 5-6 10s6 5 6 10"/></symbol>
  <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></symbol>
  <symbol id="i-radio" viewBox="0 0 24 24"><circle cx="12" cy="12" r="2"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49M7.76 16.25a6 6 0 0 1 0-8.49M19.07 4.93a10 10 0 0 1 0 14.14M4.93 19.07a10 10 0 0 1 0-14.14"/></symbol>
</svg>

<header class="topbar">
  <a href="dashboard.php" class="brand">SL <b>Diagnostics</b> <span>Token desk</span></a>

  <button class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="mainNav">
    <svg class="ico" width="20" height="20"><use href="#i-menu"/></svg>
  </button>

  <nav class="nav" id="mainNav">
    <?php if ($role === 'admin1'): ?>
      <a href="user_management.php" class="is-admin"><svg class="ico"><use href="#i-users"/></svg> Users</a>
      <a href="view_logs.php" class="is-admin"><svg class="ico"><use href="#i-log"/></svg> Login logs</a>
      <span class="sep"></span>
    <?php endif; ?>

    <a href="generate_token.php"><svg class="ico"><use href="#i-plus"/></svg> New token</a>
    <a href="view_tokens.php"><svg class="ico"><use href="#i-print"/></svg> Print</a>
    <a href="reports.php"><svg class="ico"><use href="#i-chart"/></svg> Reports</a>
	
	<?php if ($role === 'admin1'): ?>
     <a href="announcements.php"><svg class="ico"><use href="#i-screen"/></svg> Announcements</a>
    <?php endif; ?>
    <?php if ($role === 'department_user'): ?>
      <a href="department_screen.php" class="is-dept"><svg class="ico"><use href="#i-screen"/></svg> Dept screen</a>
    <?php endif; ?>

    <span class="sep"></span>
    <a href="logout.php" class="is-out"><svg class="ico"><use href="#i-power"/></svg> Sign out</a>
  </nav>
</header>

<main class="wrap">

  <section class="masthead">
    <div>
      <p class="eyebrow">Live queue &middot; <?= e(date('l, d M Y')) ?></p>
      <h1><?= $greeting ?>, <?= $display_username ?></h1>
      <p class="clock"><svg class="ico"><use href="#i-clock"/></svg><span id="live-clock" class="num">--:--:--</span></p>
    </div>
    <div class="actions">
      <a href="view_queue.php" class="btn btn-ghost"><svg class="ico"><use href="#i-eye"/></svg> Live queue</a>
      <a href="generate_token.php" class="btn btn-solid"><svg class="ico"><use href="#i-plus"/></svg> New token</a>
    </div>
  </section>

  <dl class="ledger">
    <div>
      <dt>Patients today</dt>
      <dd class="num v-navy"><?= (int)$total_tokens ?></dd>
    </div>
    <div>
      <dt>Waiting now</dt>
      <dd class="num v-wait"><?= (int)$total_pending ?></dd>
    </div>
    <div>
      <dt>Completed today</dt>
      <dd class="num v-done"><?= (int)$total_completed ?></dd>
    </div>
    <div>
      <dt>Average turnaround</dt>
      <dd class="num"><?= $overall_avg !== null ? $overall_avg : '&mdash;' ?><?php if ($overall_avg !== null): ?><small>min</small><?php endif; ?></dd>
    </div>
  </dl>

  <section class="board">
    <div class="head">
      <svg class="ico" style="color:var(--navy);width:17px;height:17px"><use href="#i-radio"/></svg>
      <h2>Now calling</h2>
      <span class="tag"><?= (int)$active_calls ?> of <?= count($current_stats) ?> active</span>
      <span class="spacer"></span>
      <button type="button" class="refresh" id="refreshBtn" aria-live="polite">
        <svg class="ico"><use href="#i-refresh"/></svg>
        <span id="refreshLabel">Refreshing in 60s</span>
      </button>
    </div>

    <?php if (empty($current_stats)): ?>
      <div class="panel"><p class="empty">No departments to show yet. Generate a token to start today's queue.</p></div>
    <?php else: ?>
      <div class="rail">
        <?php foreach ($current_stats as $name => $stat): $live = !empty($stat['current_token']); ?>
          <article class="call <?= $live ? 'live' : 'idle' ?>">
            <p class="dept"><span class="dot"></span><?= e($name) ?></p>
            <p class="token num"><?= $live ? e($stat['current_token']) : '&mdash;' ?></p>
            <p class="meta">
              <?php if ($stat['pending'] > 0): ?>
                <b><?= (int)$stat['pending'] ?></b> waiting
              <?php else: ?>
                Queue clear
              <?php endif; ?>
            </p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <div class="split">

    <section class="panel">
      <div class="panel-head">
        <svg class="ico"><use href="#i-wait"/></svg>
        <h2>Waiting load</h2>
        <span class="spacer"></span>
        <span class="tag num"><?= (int)$total_pending ?> total</span>
      </div>
      <?php if (empty($by_load)): ?>
        <p class="empty">Nothing in the queue.</p>
      <?php else: ?>
        <ul class="load">
          <?php foreach ($by_load as $name => $stat):
            $pct = $max_pending > 0 ? round(($stat['pending'] / $max_pending) * 100) : 0; ?>
            <li>
              <div class="row">
                <span class="name"><?= e($name) ?></span>
                <span class="qty num <?= $stat['pending'] == 0 ? 'zero' : '' ?>"><?= (int)$stat['pending'] ?></span>
              </div>
              <div class="bar" role="img" aria-label="<?= (int)$stat['pending'] ?> waiting in <?= e($name) ?>">
                <i class="<?= $pct == 0 ? 'empty' : '' ?>" style="width:<?= $pct == 0 ? 100 : $pct ?>%"></i>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="panel">
      <div class="panel-head">
        <svg class="ico"><use href="#i-grid"/></svg>
        <h2>Today's activity</h2>
        <span class="spacer"></span>
        <label class="search">
          <svg class="ico"><use href="#i-search"/></svg>
          <input type="search" id="deptFilter" placeholder="Filter department" aria-label="Filter departments">
        </label>
      </div>
      <div class="scroll">
        <table id="activityTable">
          <thead>
            <tr>
              <th scope="col">Department</th>
              <th scope="col">Now calling</th>
              <th scope="col" class="r">Waiting</th>
              <th scope="col" class="r">Completed</th>
              <th scope="col">Last served</th>
              <th scope="col" class="r">Avg. time</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($current_stats as $name => $stat):
              $avg = $stat['completed_count'] > 0
                   ? round($stat['total_time_minutes'] / $stat['completed_count'])
                   : null; ?>
              <tr>
                <td class="dept-cell"><?= e($name) ?></td>
                <td>
                  <span class="chip num <?= empty($stat['current_token']) ? 'off' : '' ?>">
                    <?= !empty($stat['current_token']) ? e($stat['current_token']) : '&mdash;' ?>
                  </span>
                </td>
                <td class="r num wait-n <?= $stat['pending'] == 0 ? 'zero' : '' ?>"><?= (int)$stat['pending'] ?></td>
                <td class="r num done-n <?= $stat['completed_count'] == 0 ? 'zero' : '' ?>"><?= (int)$stat['completed_count'] ?></td>
                <td class="num muted"><?= !empty($stat['last_completed']) ? e($stat['last_completed']) : '&mdash;' ?></td>
                <td class="r num avg">
                  <?php if ($avg !== null): ?><?= $avg ?><small>min</small><?php else: ?><span class="muted">&mdash;</span><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (empty($current_stats)): ?>
          <p class="empty">No department activity recorded for today.</p>
        <?php endif; ?>
        <p class="empty" id="noMatch" hidden>No department matches that filter.</p>
      </div>
    </section>

  </div>
</main>

<script>
(function () {
  'use strict';

  /* live clock */
  var clock = document.getElementById('live-clock');
  function tick() {
    clock.textContent = new Date().toLocaleTimeString('en-GB', { hour12: false });
  }
  tick();
  setInterval(tick, 1000);

  /* mobile nav */
  var toggle = document.getElementById('navToggle');
  var nav = document.getElementById('mainNav');
  toggle.addEventListener('click', function () {
    var open = nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
  });

  /* department filter */
  var filter = document.getElementById('deptFilter');
  var rows = Array.prototype.slice.call(
    document.querySelectorAll('#activityTable tbody tr')
  );
  var noMatch = document.getElementById('noMatch');
  if (filter) {
    filter.addEventListener('input', function () {
      var q = filter.value.trim().toLowerCase();
      var shown = 0;
      rows.forEach(function (row) {
        var hit = row.cells[0].textContent.toLowerCase().indexOf(q) !== -1;
        row.hidden = !hit;
        if (hit) shown++;
      });
      noMatch.hidden = shown !== 0 || rows.length === 0;
      pause();
    });
  }

  /* auto refresh with pause */
  var left = 60, running = true;
  var btn = document.getElementById('refreshBtn');
  var label = document.getElementById('refreshLabel');

  function paint() {
    label.textContent = running ? 'Refreshing in ' + left + 's' : 'Auto refresh paused';
    btn.classList.toggle('paused', !running);
  }
  function pause() {
    if (!running) return;
    running = false;
    paint();
  }
  btn.addEventListener('click', function () {
    if (running) { pause(); } else { location.reload(); }
  });
  setInterval(function () {
    if (!running) return;
    left--;
    if (left <= 0) { location.reload(); return; }
    paint();
  }, 1000);
  paint();
})();
</script>

</body>
</html>