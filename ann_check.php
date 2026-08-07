<?php
/* =====================================================================
   SL Diagnostics - announcement diagnostics
   File: ann_check.php   (put it in the SAME folder as announcements.php)

   Open it in a browser while logged in. It tells you exactly why the
   wall is or is not showing something. Delete the file once sorted.
   ===================================================================== */

@ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

$__db_file = __DIR__ . '/config/db_connect.php';
$db_file_found = is_file($__db_file);
if ($db_file_found) { require_once $__db_file; }

if (!isset($_SESSION['user_id'], $_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* which variable holds the connection? */
$candidates = ['mysql_conn', 'conn', 'con', 'link', 'db', 'mysqli', 'connection'];
$found_var = null;
$db = null;
foreach ($candidates as $name) {
    if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof mysqli) {
        $found_var = $name;
        $db = $GLOBALS[$name];
        break;
    }
}
if ($db) { @$db->set_charset('utf8mb4'); }

$table_exists = false;
$rows = [];
$php_today = date('Y-m-d');
$sql_today = '(unknown)';
$sql_error = '';

if ($db) {
    try {
        $r = $db->query("SHOW TABLES LIKE 'announcements'");
        $table_exists = ($r && $r->num_rows > 0);
        if ($r) { $r->free(); }

        $r2 = $db->query("SELECT CURDATE() AS d");
        if ($r2) { $sql_today = $r2->fetch_assoc()['d']; $r2->free(); }

        if ($table_exists) {
            $r3 = $db->query("SELECT * FROM announcements ORDER BY sort_order ASC, id DESC");
            if ($r3) { while ($row = $r3->fetch_assoc()) { $rows[] = $row; } $r3->free(); }
        }
    } catch (Throwable $ex) {
        $sql_error = $ex->getMessage();
    }
}

function ann_state(array $r, $today) {
    if ((int)$r['is_active'] !== 1)                                return ['off',  'Hidden - "Show on the wall" is unticked'];
    if (!empty($r['start_date']) && $r['start_date'] > $today)     return ['wait', 'Starts on ' . $r['start_date']];
    if (!empty($r['end_date'])   && $r['end_date']   < $today)     return ['off',  'Ended on ' . $r['end_date']];
    if ($r['type'] === 'text' && (int)$r['show_in_ticker'] === 1)  return ['tick', 'Live - scrolling in the BOTTOM STRIP, not the band'];
    return ['live', 'Live - announcement band'];
}

$live_band = 0; $live_tick = 0;
foreach ($rows as $r) {
    $s = ann_state($r, $php_today)[0];
    if ($s === 'live') $live_band++;
    if ($s === 'tick') $live_tick++;
}

$upload_dir = __DIR__ . '/uploads/announcements';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Announcement check | SL Diagnostics</title>
<style>
:root{
  --ink:#0A1A2A; --ink-2:#41586C; --ink-3:#7B90A3;
  --line:#DCE5EC; --line-soft:#EBF1F6; --paper:#EEF3F7; --surface:#fff;
  --navy:#004B87; --navy-deep:#00325C; --navy-tint:#E5EEF6;
  --ok:#0E7C63; --ok-tint:#E1F1ED; --bad:#B3392F; --bad-tint:#FBEAE8;
  --warn:#9A6600; --warn-tint:#FBF0D9;
  --mono:ui-monospace,"SFMono-Regular",Menlo,Consolas,monospace;
}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);
     font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:28px 18px 60px}
.wrap{max-width:1000px;margin:0 auto}
h1{font-size:1.5rem;margin:0 0 4px;letter-spacing:-.02em}
p.sub{margin:0 0 22px;color:var(--ink-2);font-size:.9rem}
h2{font-size:.95rem;margin:0 0 10px;letter-spacing:-.01em}
.card{background:var(--surface);border:1px solid var(--line);border-radius:14px;
      padding:18px 20px;margin-bottom:18px;box-shadow:0 8px 24px -18px rgba(10,26,42,.4)}
.line{display:flex;gap:12px;align-items:flex-start;padding:9px 0;border-top:1px solid var(--line-soft);font-size:.88rem}
.line:first-of-type{border-top:0}
.line b{flex:none;width:210px;font-weight:700}
.line span{color:var(--ink-2);word-break:break-word}
.pill{display:inline-block;padding:3px 10px;border-radius:99px;font-size:.72rem;font-weight:800;letter-spacing:.02em}
.ok{background:var(--ok-tint);color:#0A5A48}
.bad{background:var(--bad-tint);color:var(--bad)}
.warn{background:var(--warn-tint);color:var(--warn)}
.info{background:var(--navy-tint);color:var(--navy-deep)}
table{width:100%;border-collapse:collapse;font-size:.86rem}
th{text-align:left;padding:10px 12px;font-size:.68rem;letter-spacing:.1em;text-transform:uppercase;
   color:var(--ink-3);border-bottom:1px solid var(--line)}
td{padding:11px 12px;border-bottom:1px solid var(--line-soft);vertical-align:top}
tr:last-child td{border-bottom:0}
pre{background:#0B1D2E;color:#D7E4EE;padding:14px 16px;border-radius:10px;overflow:auto;
    font-family:var(--mono);font-size:.8rem;max-height:280px;margin:0}
a.btn{display:inline-block;margin-top:12px;padding:10px 15px;border-radius:10px;background:var(--navy);
      color:#fff;text-decoration:none;font-size:.82rem;font-weight:700}
code{font-family:var(--mono);font-size:.85em;background:var(--paper);padding:2px 6px;border-radius:5px}
</style>
</head>
<body>
<div class="wrap">

<h1>Announcement check</h1>
<p class="sub">Run this from the same folder as announcements.php. Delete the file when you are done.</p>

<div class="card">
  <h2>1. Wiring</h2>

  <div class="line">
    <b>config/db_connect.php</b>
    <span><?= $db_file_found ? '<span class="pill ok">found</span>' : '<span class="pill bad">missing</span> announcements.php cannot reach the database from this folder' ?></span>
  </div>

  <div class="line">
    <b>Connection variable</b>
    <span>
      <?php if ($found_var === 'mysql_conn'): ?>
        <span class="pill ok">$mysql_conn</span> correct
      <?php elseif ($found_var): ?>
        <span class="pill bad">$<?= e($found_var) ?></span>
        announcements.php looks for <code>$mysql_conn</code>. Open announcements.php, find <code>function ann_db()</code>
        and change <code>$mysql_conn</code> to <code>$<?= e($found_var) ?></code> (3 places in that function).
      <?php else: ?>
        <span class="pill bad">not found</span>
        No mysqli connection in the global scope. If db_connect.php uses PDO, announcements.php needs the mysqli handle instead.
      <?php endif; ?>
    </span>
  </div>

  <div class="line">
    <b>announcements table</b>
    <span><?= $table_exists ? '<span class="pill ok">exists</span> ' . count($rows) . ' row(s)'
                            : '<span class="pill bad">missing</span> open announcements.php once as an admin to create it' ?></span>
  </div>

  <div class="line">
    <b>Date on PHP vs MySQL</b>
    <span>
      <?= e($php_today) ?> / <?= e($sql_today) ?>
      <?= ($php_today !== $sql_today) ? ' <span class="pill warn">mismatch</span> start and end dates will behave oddly' : '' ?>
    </span>
  </div>

  <div class="line">
    <b>uploads/announcements</b>
    <span>
      <?php if (!is_dir($upload_dir)): ?>
        <span class="pill warn">not created yet</span> it is made on the first image upload
      <?php elseif (!is_writable($upload_dir)): ?>
        <span class="pill bad">not writable</span> image uploads will fail
      <?php else: ?>
        <span class="pill ok">writable</span>
      <?php endif; ?>
    </span>
  </div>

  <?php if ($sql_error !== ''): ?>
  <div class="line"><b>SQL error</b><span class="pill bad"><?= e($sql_error) ?></span></div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>2. What each announcement is doing</h2>
  <p class="sub" style="margin-bottom:12px">
    Band: <b><?= (int)$live_band ?></b> live &nbsp;&middot;&nbsp; Bottom strip: <b><?= (int)$live_tick ?></b> live.
    Zero in the band means the wall is right to show nothing.
  </p>

  <?php if (empty($rows)): ?>
    <p style="color:var(--ink-3)">No announcements saved yet.</p>
  <?php else: ?>
  <table>
    <thead><tr><th>#</th><th>Heading</th><th>Type</th><th>Status right now</th><th>Image on disk</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r):
        [$state, $why] = ann_state($r, $php_today);
        $cls = ($state === 'live') ? 'ok' : (($state === 'tick') ? 'info' : (($state === 'wait') ? 'warn' : 'bad')); ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= $r['title'] !== null && $r['title'] !== '' ? e($r['title']) : '<i>(no heading)</i>' ?></td>
          <td><?= e($r['type']) ?></td>
          <td><span class="pill <?= $cls ?>"><?= e($why) ?></span></td>
          <td>
            <?php if ($r['type'] === 'image'):
              $p = $upload_dir . '/' . basename((string)$r['image_path']);
              echo (!empty($r['image_path']) && is_file($p))
                   ? '<span class="pill ok">yes</span>'
                   : '<span class="pill bad">file missing</span>';
            else: echo '&mdash;'; endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>3. The exact feed the wall reads</h2>
  <p class="sub" style="margin-bottom:12px">
    This runs the same request the TV makes. It must be pure JSON - any HTML, warning or blank line above it breaks the wall silently.
  </p>
  <pre id="out">Loading announcements.php?feed=1 ...</pre>
  <a class="btn" href="announcements.php?feed=1" target="_blank" rel="noopener">Open the feed in a new tab</a>
</div>

</div>

<script>
fetch('announcements.php?feed=1&t=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' })
  .then(function (r) {
    return r.text().then(function (t) {
      return { status: r.status, type: r.headers.get('content-type') || '(none)', body: t };
    });
  })
  .then(function (d) {
    var note = 'HTTP ' + d.status + '   content-type: ' + d.type + '\n\n' + d.body;
    if (d.status === 404) {
      note = 'HTTP 404 - announcements.php is not in this folder.\n' +
             'The wall page and announcements.php must sit side by side, or change ANN_FEED in display.php to the correct path.\n\n' + note;
    } else {
      try {
        var j = JSON.parse(d.body);
        note += '\n\n--- parsed ---\nband items: ' + (j.panel || []).length +
                '\nticker lines: ' + (j.ticker || []).length +
                '\nversion: ' + j.version;
        if (!(j.panel || []).length) {
          note += '\n\nband items = 0, so the wall correctly draws no announcement strip.';
        }
      } catch (err) {
        note += '\n\n--- NOT VALID JSON ---\n' + err.message +
                '\nSomething is printing before the JSON (a warning, a stray space, or a BOM in config/db_connect.php).';
      }
    }
    document.getElementById('out').textContent = note;
  })
  .catch(function (err) {
    document.getElementById('out').textContent = 'Request failed: ' + err.message;
  });
</script>

</body>
</html>