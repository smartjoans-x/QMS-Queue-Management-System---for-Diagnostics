<?php
session_start();
include 'config/db_connect.php';

// Admin check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$lab_departments = [
    'Biochemistry', 'Cytology', 'Genetic', 'Haematology', 'Histopathology',
    'Mamography', 'MICROBIOLOGY', 'Molecular Biology', 'Out source',
    'Pathology', 'Sample Collection', 'Serology'
];

$message      = '';
$message_type = 'error';
$current_date = date('Y-m-d');

// sticky form values
$in_sid   = '';
$in_name  = '';
$in_age   = '';
$in_sex   = 'Male';
$in_depts = [];

// slip values
$token_for_modal        = '';
$patient_name_for_modal = '';
$sid_no_for_modal       = '';
$age_sex_for_modal      = '';
$depts_for_modal        = [];

if ($_POST && isset($_POST['manual_assign'])) {
    $in_sid  = trim($_POST['sid_no'] ?? '');
    $in_name = trim($_POST['pat_name'] ?? '');
    $in_age  = trim($_POST['pat_age'] ?? '');
    $in_sex  = $_POST['pat_sex'] ?? 'Male';

    $in_depts = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['dept_ids'] ?? []))
    )));

    if ($in_sid === '' || $in_name === '') {
        $message = 'Enter both a SID and a patient name.';
    } elseif (empty($in_depts)) {
        $message = 'Pick at least one department before generating a token.';
    } else {

        // already issued today?
        $check_stmt = mysqli_prepare($mysql_conn,
            "SELECT token_number FROM tokens WHERE sid_no = ? AND created_date = ? LIMIT 1");
        mysqli_stmt_bind_param($check_stmt, 'ss', $in_sid, $current_date);
        mysqli_stmt_execute($check_stmt);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($check_stmt));

        $manual_ref = 'Manual';
        $ins_stmt = mysqli_prepare($mysql_conn,
            "INSERT INTO tokens (sid_no, pat_name, pat_age, pat_sex, ref_name, dept_id, token_number, created_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

        if ($existing) {
            $token_for_modal = $existing['token_number'];

            $have = [];
            $have_stmt = mysqli_prepare($mysql_conn,
                "SELECT dept_id FROM tokens WHERE sid_no = ? AND created_date = ?");
            mysqli_stmt_bind_param($have_stmt, 'ss', $in_sid, $current_date);
            mysqli_stmt_execute($have_stmt);
            $have_res = mysqli_stmt_get_result($have_stmt);
            while ($r = mysqli_fetch_assoc($have_res)) $have[] = (int)$r['dept_id'];

            $new_depts = array_values(array_diff($in_depts, $have));

            foreach ($new_depts as $dept_id) {
                mysqli_stmt_bind_param($ins_stmt, 'ssississ',
                    $in_sid, $in_name, $in_age, $in_sex, $manual_ref, $dept_id, $token_for_modal, $current_date);
                mysqli_stmt_execute($ins_stmt);
            }

            $message = count($new_depts) > 0
                ? 'Token ' . $token_for_modal . ' already exists for this SID — added ' . count($new_depts) . ' more department' . (count($new_depts) > 1 ? 's' : '') . '.'
                : 'Token ' . $token_for_modal . ' is already issued for this SID today.';
            $message_type = 'success';

        } else {
            $max_res   = mysqli_query($mysql_conn,
                "SELECT MAX(CAST(token_number AS UNSIGNED)) AS mx FROM tokens WHERE created_date = '$current_date'");
            $token_val = (int)(mysqli_fetch_assoc($max_res)['mx'] ?? 0) + 1;
            $token_for_modal = sprintf('%03d', $token_val);

            foreach ($in_depts as $dept_id) {
                mysqli_stmt_bind_param($ins_stmt, 'ssississ',
                    $in_sid, $in_name, $in_age, $in_sex, $manual_ref, $dept_id, $token_for_modal, $current_date);
                mysqli_stmt_execute($ins_stmt);
            }

            $message      = 'Manual token ' . $token_for_modal . ' generated.';
            $message_type = 'success';
        }

        $id_csv = implode(',', $in_depts);
        $nm_res = mysqli_query($mysql_conn,
            "SELECT dept_name FROM departments WHERE id IN ($id_csv) ORDER BY dept_name");
        if ($nm_res) {
            while ($n = mysqli_fetch_assoc($nm_res)) $depts_for_modal[] = $n['dept_name'];
        }

        $patient_name_for_modal = $in_name;
        $sid_no_for_modal       = $in_sid;
        $age_sex_for_modal      = trim($in_age . ' / ' . $in_sex, ' /');
    }
}

/* -----------------------------------------------------------------
   Department list — mirrors generate_token.php so both entry points
   write to the same dept ids. LAB replaces the individual lab
   sections; if there is no LAB row, the lab sections are shown.
----------------------------------------------------------------- */
$dept_cards = [];
$lab_res = mysqli_query($mysql_conn, "SELECT id FROM departments WHERE dept_name = 'LAB' LIMIT 1");
$lab_id  = $lab_res ? (mysqli_fetch_assoc($lab_res)['id'] ?? null) : null;

if ($lab_id) {
    $dept_cards[] = ['id' => (int)$lab_id, 'name' => 'LAB', 'lab' => true];

    $escaped = array_map(function ($n) use ($mysql_conn) {
        return mysqli_real_escape_string($mysql_conn, $n);
    }, $lab_departments);
    $d_list = "'" . implode("','", $escaped) . "'";

    $others = mysqli_query($mysql_conn,
        "SELECT id, dept_name FROM departments WHERE dept_name NOT IN ($d_list) AND dept_name <> 'LAB' ORDER BY dept_name");
} else {
    $others = mysqli_query($mysql_conn, "SELECT id, dept_name FROM departments ORDER BY dept_name");
}

if ($others) {
    while ($d = mysqli_fetch_assoc($others)) {
        $dept_cards[] = ['id' => (int)$d['id'], 'name' => $d['dept_name'], 'lab' => false];
    }
}

$slip = [
    'token' => $token_for_modal,
    'name'  => $patient_name_for_modal,
    'sid'   => $sid_no_for_modal,
    'meta'  => $age_sex_for_modal,
    'depts' => implode(' · ', $depts_for_modal),
    'date'  => date('d M Y'),
    'time'  => date('h:i A'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#00325C">
<meta name="robots" content="noindex, nofollow">
<title>Manual token | SL Diagnostics</title>
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
  text-decoration:none; padding:11px 16px; border-radius:10px; font-size:.83rem;
  font-weight:700; font-family:inherit; border:1px solid transparent; cursor:pointer;
  transition:.15s; color:var(--ink)}
.btn-solid{background:var(--navy); color:#fff; box-shadow:0 6px 16px -8px rgba(0,75,135,.8)}
.btn-solid:hover{background:var(--navy-deep)}
.btn-go{background:var(--clear); color:#fff; box-shadow:0 6px 16px -8px rgba(14,124,99,.9)}
.btn-go:hover{background:#0B6753}
.btn-ghost{background:var(--surface); border-color:var(--line)}
.btn-ghost:hover{border-color:var(--navy); color:var(--navy-deep)}
.btn-lg{padding:14px 22px; font-size:.9rem; width:100%}
.btn-xs{padding:6px 11px; font-size:.72rem; border-radius:8px; background:var(--surface);
  border-color:var(--line); color:var(--ink-2)}
.btn-xs:hover{border-color:var(--navy); color:var(--navy-deep)}

.wrap{max-width:1020px; margin:0 auto; padding:26px 20px 60px}
.step{display:flex; align-items:center; gap:10px; margin:0 0 10px; font-size:.68rem;
  font-weight:800; letter-spacing:.14em; text-transform:uppercase; color:var(--ink-3)}
.step i{font-style:normal; font-family:var(--mono); color:var(--navy);
  background:var(--navy-tint); border-radius:6px; padding:2px 7px; letter-spacing:0}
.card{background:var(--surface); border:1px solid var(--line); border-radius:var(--r);
  box-shadow:var(--shadow); padding:22px; margin-bottom:26px}
.card h2{font-size:1.05rem; margin-bottom:4px}
.card .hint{margin:0 0 18px; color:var(--ink-3); font-size:.83rem}

.alert{display:flex; align-items:flex-start; gap:10px; padding:13px 16px;
  border-radius:var(--r-sm); font-size:.86rem; font-weight:600; margin-bottom:20px;
  border:1px solid transparent}
.alert .ico{margin-top:2px}
.alert-success{background:var(--clear-tint); color:#0A5C49; border-color:#BFE3D9}
.alert-error{background:var(--alert-tint); color:#8E2C24; border-color:#F1CFCB}

.form-grid{display:grid; grid-template-columns:1.3fr 1.6fr .7fr .9fr; gap:14px}
.field label{display:block; margin-bottom:7px; font-size:.7rem; font-weight:800;
  letter-spacing:.1em; text-transform:uppercase; color:var(--ink-3)}
.field input, .field select{width:100%; padding:13px 14px; border:1px solid var(--line);
  border-radius:10px; font-family:inherit; font-size:.95rem; background:var(--paper);
  color:var(--ink); appearance:none}
.field select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%237B90A3' stroke-width='2' stroke-linecap='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
  background-repeat:no-repeat; background-position:right 12px center; background-size:16px; padding-right:36px}
.field input:focus, .field select:focus{background-color:var(--surface); border-color:var(--navy);
  outline:none; box-shadow:0 0 0 3px rgba(0,75,135,.12)}
#sidInput{font-family:var(--mono); font-weight:700; letter-spacing:.04em}

.picker-head{display:flex; align-items:center; gap:10px; margin:26px 0 14px; flex-wrap:wrap}
.picker-head h2{font-size:1.05rem}
.picker-head .spacer{margin-left:auto}
.count{font-size:.72rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase;
  color:var(--navy); background:var(--navy-tint); padding:5px 10px; border-radius:99px}
.dept-grid{display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:10px; margin-bottom:22px}
.dept{position:relative; display:flex; align-items:center; gap:11px; cursor:pointer;
  padding:13px 14px; border:1px solid var(--line); border-radius:var(--r-sm);
  background:var(--surface); font-size:.88rem; font-weight:600; transition:.15s}
.dept:hover{border-color:#B9CAD8}
.dept input{position:absolute; opacity:0; width:0; height:0}
.dept .tick{width:19px; height:19px; flex:none; border:1.5px solid #C3D0DB; border-radius:6px;
  display:grid; place-items:center; transition:.15s; background:#fff}
.dept .tick svg{width:12px; height:12px; stroke:#fff; stroke-width:3.2; fill:none;
  stroke-linecap:round; stroke-linejoin:round; opacity:0; transition:.15s}
.dept input:checked + .tick{background:var(--navy); border-color:var(--navy)}
.dept input:checked + .tick svg{opacity:1}
.dept input:focus-visible + .tick{outline:2px solid var(--navy); outline-offset:2px}
.dept.is-on{border-color:var(--navy); background:var(--navy-tint)}
.dept:has(input:checked){border-color:var(--navy); background:var(--navy-tint)}
.dept.lab.is-on, .dept.lab:has(input:checked){border-color:var(--signal-bar); background:var(--signal-tint)}
.dept .tag{margin-left:auto; font-size:.6rem; font-weight:800; letter-spacing:.1em;
  text-transform:uppercase; color:var(--signal)}
.empty{text-align:center; padding:30px 20px; color:var(--ink-3); font-size:.88rem}
.empty strong{display:block; color:var(--ink); font-size:1rem; margin-bottom:6px}

/* ticket modal */
.overlay{position:fixed; inset:0; z-index:100; display:none; padding:20px;
  align-items:center; justify-content:center; background:rgba(10,26,42,.68); backdrop-filter:blur(5px)}
.overlay.open{display:flex}
.ticket{width:100%; max-width:390px; background:var(--surface); border-radius:18px;
  overflow:hidden; box-shadow:0 30px 60px -20px rgba(0,0,0,.6); animation:rise .22s ease-out}
@keyframes rise{from{opacity:0; transform:translateY(10px) scale(.98)}}
.ticket-top{background:var(--navy-deep); color:#fff; padding:16px 22px; display:flex; align-items:center; gap:10px}
.ticket-top .ico{color:var(--signal-bar); width:18px; height:18px}
.ticket-top h2{font-size:.78rem; font-weight:800; letter-spacing:.14em; text-transform:uppercase}
.ticket-top .badge{margin-left:auto; font-size:.6rem; font-weight:800; letter-spacing:.12em;
  text-transform:uppercase; background:rgba(255,255,255,.14); padding:4px 9px; border-radius:99px}
.ticket-body{padding:24px 22px 22px; text-align:center}
.ticket-body .cap{font-size:.66rem; font-weight:800; letter-spacing:.16em;
  text-transform:uppercase; color:var(--ink-3); margin:0}
.ticket-num{font-family:var(--mono); font-size:4.4rem; font-weight:700; line-height:1;
  color:var(--navy-deep); margin:8px 0 4px; letter-spacing:-.03em}
.tear{border:0; border-top:2px dashed var(--line); margin:20px -22px}
.ticket-rows{text-align:left; display:grid; gap:9px; margin:0}
.ticket-rows div{display:flex; gap:14px; font-size:.85rem}
.ticket-rows dt{flex:none; width:82px; color:var(--ink-3); font-weight:700; font-size:.72rem;
  letter-spacing:.08em; text-transform:uppercase; padding-top:2px; margin:0}
.ticket-rows dd{margin:0; font-weight:600; word-break:break-word}
.ticket-actions{display:flex; gap:10px; padding:0 22px 22px}
.ticket-actions .btn{flex:1}

/* print slip */
.slip{display:none}
@media print{
  body{background:#fff; padding:0}
  body > *{display:none !important}
  .slip{display:block !important; text-align:center; max-width:74mm; margin:0 auto; color:#000}
  .slip .brandline{font-size:13pt; font-weight:800; letter-spacing:.06em}
  .slip .sub{font-size:7.5pt; letter-spacing:.22em; text-transform:uppercase; margin-top:2px}
  .slip .big{font-family:var(--mono); font-size:52pt; font-weight:700; line-height:1; margin:10mm 0 6mm}
  .slip .rule{border-top:1px dashed #000; margin:4mm 0}
  .slip p{margin:1.5mm 0; font-size:9pt}
  .slip .foot{font-size:8pt; margin-top:5mm}
  @page{margin:6mm}
}

@media (max-width:900px){
  .form-grid{grid-template-columns:1fr 1fr}
}
@media (max-width:820px){
  body{padding-top:56px}
  .topbar{height:56px; padding:0 14px}
  .brand span{display:none}
  .wrap{padding:20px 14px 44px}
  .ticket-num{font-size:3.6rem}
}
@media (max-width:520px){
  .form-grid{grid-template-columns:1fr}
}
@media (prefers-reduced-motion:reduce){*{animation:none !important; transition:none !important}}
</style>
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <symbol id="i-gauge" viewBox="0 0 24 24"><path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM13.4 10.6 19 5"/><path d="M4.1 18a9 9 0 1 1 15.8 0"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></symbol>
  <symbol id="i-pencil" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></symbol>
  <symbol id="i-ticket" viewBox="0 0 24 24"><path d="M3 9a3 3 0 0 0 0 6v3a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-3a3 3 0 0 1 0-6V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1z"/><path d="M13 6v2M13 11v2M13 16v2"/></symbol>
  <symbol id="i-print" viewBox="0 0 24 24"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></symbol>
  <symbol id="i-ok" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.2 2.4 2.4 4.6-4.8"/></symbol>
  <symbol id="i-tag" viewBox="0 0 24 24"><path d="M20.6 13.4 12 22l-9-9V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><path d="M7.5 7.5v.01"/></symbol>
  <symbol id="i-user" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol>
</svg>

<header class="topbar">
  <a href="dashboard.php" class="brand">SL <b>Diagnostics</b> <span>Token desk</span></a>
  <div class="right">
    <a href="generate_token.php" class="btn btn-ghost"><svg class="ico"><use href="#i-search"/></svg> SID lookup</a>
    <a href="dashboard.php" class="btn btn-solid"><svg class="ico"><use href="#i-gauge"/></svg> Dashboard</a>
  </div>
</header>

<main class="wrap">

  <?php if ($message): ?>
    <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'error' ?>" role="status">
      <svg class="ico"><use href="#i-<?= $message_type === 'success' ? 'ok' : 'alert' ?>"/></svg>
      <span><?= e($message) ?></span>
    </div>
  <?php endif; ?>

  <p class="step"><i>01</i> Walk-in entry</p>

  <form method="POST" id="manualForm">
    <section class="card">
      <h2>Patient details</h2>
      <p class="hint">For patients who aren't in SLIMS yet. Everything here is typed by hand, so double-check the SID before issuing.</p>

      <div class="form-grid">
        <div class="field">
          <label for="sidInput">SID number</label>
          <input type="text" id="sidInput" name="sid_no" required autocomplete="off"
                 placeholder="MAN-001" value="<?= e($in_sid) ?>">
        </div>
        <div class="field">
          <label for="nameInput">Patient name</label>
          <input type="text" id="nameInput" name="pat_name" required autocomplete="off"
                 placeholder="Full name" value="<?= e($in_name) ?>">
        </div>
        <div class="field">
          <label for="ageInput">Age</label>
          <input type="number" id="ageInput" name="pat_age" required min="0" max="130"
                 inputmode="numeric" placeholder="34" value="<?= e($in_age) ?>">
        </div>
        <div class="field">
          <label for="sexInput">Sex</label>
          <select id="sexInput" name="pat_sex" required>
            <?php foreach (['Male', 'Female', 'Others'] as $opt): ?>
              <option value="<?= $opt ?>" <?= $in_sex === $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="picker-head">
        <svg class="ico" style="color:var(--navy);width:18px;height:18px"><use href="#i-tag"/></svg>
        <h2>Departments</h2>
        <span class="spacer"></span>
        <span class="count" id="selCount">0 selected</span>
        <button type="button" class="btn btn-xs" data-all="1">Select all</button>
        <button type="button" class="btn btn-xs" data-all="0">Clear</button>
      </div>

      <div class="dept-grid">
        <?php if (empty($dept_cards)): ?>
          <p class="empty" style="grid-column:1/-1"><strong>No departments configured</strong>Add departments in the master list before issuing tokens.</p>
        <?php else: ?>
          <?php foreach ($dept_cards as $d): $on = in_array($d['id'], $in_depts, true); ?>
            <label class="dept<?= $d['lab'] ? ' lab' : '' ?><?= $on ? ' is-on' : '' ?>">
              <input type="checkbox" name="dept_ids[]" value="<?= $d['id'] ?>" <?= $on ? 'checked' : '' ?>>
              <span class="tick"><svg viewBox="0 0 24 24"><path d="m5 13 4 4L19 7"/></svg></span>
              <span class="label"><?= e($d['name']) ?></span>
              <?php if ($d['lab']): ?><span class="tag">Merged</span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <button type="submit" name="manual_assign" value="1" class="btn btn-go btn-lg" id="issueBtn">
        <svg class="ico"><use href="#i-ticket"/></svg> Generate manual token
      </button>
    </section>
  </form>

</main>

<!-- ticket modal -->
<div class="overlay" id="modalOverlay" role="dialog" aria-modal="true" aria-labelledby="ticketTitle">
  <div class="ticket">
    <div class="ticket-top">
      <svg class="ico"><use href="#i-ticket"/></svg>
      <h2 id="ticketTitle">Queue token</h2>
      <span class="badge">Manual</span>
    </div>
    <div class="ticket-body">
      <p class="cap">Token number</p>
      <p class="ticket-num" id="m_token">--</p>
      <hr class="tear">
      <dl class="ticket-rows">
        <div><dt>Patient</dt><dd id="m_name"></dd></div>
        <div><dt>SID</dt><dd id="m_sid" class="num"></dd></div>
        <div><dt>Age / Sex</dt><dd id="m_meta"></dd></div>
        <div id="m_dept_row"><dt>Depts</dt><dd id="m_depts"></dd></div>
      </dl>
    </div>
    <div class="ticket-actions">
      <button type="button" class="btn btn-solid" id="printBtn"><svg class="ico"><use href="#i-print"/></svg> Print slip</button>
      <button type="button" class="btn btn-ghost" id="closeBtn">Close</button>
    </div>
  </div>
</div>

<!-- printable slip -->
<div class="slip" id="printSlip">
  <p class="brandline">SL DIAGNOSTICS</p>
  <p class="sub">Queue token</p>
  <p class="big" id="s_token"></p>
  <hr class="rule">
  <p><strong id="s_name"></strong></p>
  <p>SID <span id="s_sid"></span> &nbsp;·&nbsp; <span id="s_meta"></span></p>
  <p id="s_depts"></p>
  <hr class="rule">
  <p><span id="s_date"></span> &nbsp;·&nbsp; <span id="s_time"></span></p>
  <p class="foot">Please wait for your number to be called.</p>
</div>

<script id="slipData" type="application/json"><?= json_encode($slip, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<script>
(function () {
  'use strict';

  var boxes = Array.prototype.slice.call(document.querySelectorAll('.dept input'));
  var count = document.getElementById('selCount');

  function sync() {
    var n = 0;
    boxes.forEach(function (b) {
      b.closest('.dept').classList.toggle('is-on', b.checked);
      if (b.checked) n++;
    });
    if (count) {
      count.textContent = n + ' selected';
      count.style.background = '';
      count.style.color = '';
    }
  }
  boxes.forEach(function (b) { b.addEventListener('change', sync); });
  Array.prototype.forEach.call(document.querySelectorAll('[data-all]'), function (btn) {
    btn.addEventListener('click', function () {
      var on = btn.getAttribute('data-all') === '1';
      boxes.forEach(function (b) { b.checked = on; });
      sync();
    });
  });
  sync();

  var form = document.getElementById('manualForm');
  if (form) {
    form.addEventListener('submit', function (ev) {
      var chosen = boxes.filter(function (b) { return b.checked; }).length;
      if (chosen === 0) {
        ev.preventDefault();
        if (count) {
          count.textContent = 'Pick a department';
          count.style.background = 'var(--alert-tint)';
          count.style.color = 'var(--alert)';
        }
        if (boxes[0]) boxes[0].focus();
        return;
      }
      var btn = document.getElementById('issueBtn');
      setTimeout(function () { btn.disabled = true; btn.style.opacity = '.6'; }, 0);
    });
  }

  /* ---- token slip ---- */
  var data = {};
  try { data = JSON.parse(document.getElementById('slipData').textContent); } catch (err) { data = {}; }

  var overlay = document.getElementById('modalOverlay');

  function fill(id, value) {
    var el = document.getElementById(id);
    if (el) el.textContent = value || '';
  }
  function open() {
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('printBtn').focus();
  }
  function close() {
    overlay.classList.remove('open');
    document.body.style.overflow = '';
    var sid = document.getElementById('sidInput');
    if (sid) { sid.focus(); sid.select(); }
  }

  if (data.token) {
    fill('m_token', data.token); fill('s_token', data.token);
    fill('m_name', data.name);   fill('s_name', data.name);
    fill('m_sid', data.sid);     fill('s_sid', data.sid);
    fill('m_meta', data.meta);   fill('s_meta', data.meta);
    fill('m_depts', data.depts); fill('s_depts', data.depts);
    fill('s_date', data.date);   fill('s_time', data.time);
    if (!data.depts) {
      var row = document.getElementById('m_dept_row');
      if (row) row.style.display = 'none';
    }
    open();
  } else {
    var sid = document.getElementById('sidInput');
    if (sid && !sid.value) sid.focus();
  }

  document.getElementById('closeBtn').addEventListener('click', close);
  overlay.addEventListener('click', function (ev) { if (ev.target === overlay) close(); });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && overlay.classList.contains('open')) close();
  });
  document.getElementById('printBtn').addEventListener('click', function () { window.print(); });
})();
</script>

</body>
</html>