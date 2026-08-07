<?php
/* =====================================================================
   SL Diagnostics - Announcements
   File: announcements.php   (same folder as display.php / dashboard.php)
   ---------------------------------------------------------------------
   announcements.php?feed=1  -> JSON feed read by the waiting room wall
                                (no login, read-only, active items only)
   announcements.php         -> staff screen to add / edit / hide / delete

   The `announcements` table is created automatically on the first
   admin visit. Images go to uploads/announcements/ (auto-created).
   ===================================================================== */

@ini_set('display_errors', '0');

$__db_file = __DIR__ . '/config/db_connect.php';
if (is_file($__db_file)) { require_once $__db_file; }

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

define('ANN_DIR',       __DIR__ . '/uploads/announcements');
define('ANN_URL',       'uploads/announcements');
define('ANN_MAX_BYTES', 6 * 1024 * 1024);          // 6 MB per image

/* ---------------------------------------------------------------------
   Database helpers
--------------------------------------------------------------------- */
function ann_db() {
    global $mysql_conn;
    if (isset($mysql_conn) && $mysql_conn instanceof mysqli) {
        @$mysql_conn->set_charset('utf8mb4');
        return $mysql_conn;
    }
    return null;
}

function ann_ensure_table(mysqli $db) {
    try {
        $db->query("
        CREATE TABLE IF NOT EXISTS `announcements` (
          `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `type`           ENUM('text','image') NOT NULL DEFAULT 'text',
          `tone`           ENUM('info','wish','alert') NOT NULL DEFAULT 'info',
          `title`          VARCHAR(160) DEFAULT NULL,
          `body`           TEXT DEFAULT NULL,
          `image_path`     VARCHAR(255) DEFAULT NULL,
          `show_in_ticker` TINYINT(1) NOT NULL DEFAULT 0,
          `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
          `start_date`     DATE DEFAULT NULL,
          `end_date`       DATE DEFAULT NULL,
          `sort_order`     INT NOT NULL DEFAULT 0,
          `created_by`     VARCHAR(100) DEFAULT NULL,
          `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `live_idx` (`is_active`,`start_date`,`end_date`,`sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        return '';
    } catch (Throwable $ex) {
        return 'The `announcements` table could not be created automatically. '
             . 'Ask your DBA to run the CREATE TABLE from the top of this file.';
    }
}

/* What the wall receives. `version` changes whenever any live item
   changes, so the wall knows when to redraw without a full reload. */
function ann_payload(?mysqli $db) {
    $panel = [];
    $ticker = [];
    $stamp = '';

    if ($db) {
      try {
        $sql = "SELECT id, type, tone, title, body, image_path, show_in_ticker, updated_at
                FROM announcements
                WHERE is_active = 1
                  AND (start_date IS NULL OR start_date <= CURDATE())
                  AND (end_date   IS NULL OR end_date   >= CURDATE())
                ORDER BY sort_order ASC, id DESC";
        $res = @$db->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $stamp .= $r['id'] . ':' . $r['updated_at'] . '|';
                $title = trim((string)$r['title']);
                $body  = trim((string)$r['body']);

                if ($r['type'] === 'text' && (int)$r['show_in_ticker'] === 1) {
                    $line = ($title !== '' && $body !== '')
                          ? $title . ' - ' . $body
                          : ($title !== '' ? $title : $body);
                    if ($line !== '') { $ticker[] = $line; }
                    continue;
                }

                $panel[] = [
                    'id'    => (int)$r['id'],
                    'type'  => $r['type'],
                    'tone'  => $r['tone'],
                    'title' => $title,
                    'body'  => $body,
                    'image' => $r['image_path'] ? ANN_URL . '/' . $r['image_path'] : '',
                ];
            }
            $res->free();
        }
      } catch (Throwable $ex) {
        return ['version' => 'empty', 'panel' => [], 'ticker' => []];
      }
    }

    return [
        'version' => ($stamp === '' ? 'empty' : md5($stamp)),
        'panel'   => $panel,
        'ticker'  => $ticker,
    ];
}

/* ---------------------------------------------------------------------
   1) Public feed - must stay above the login check
--------------------------------------------------------------------- */
if (isset($_GET['feed'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex');
    echo json_encode(ann_payload(ann_db()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------------------------------------------------------------------
   2) Admin screen
--------------------------------------------------------------------- */
session_start();

if (!isset($_SESSION['user_id'], $_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

$role     = $_SESSION['role'] ?? '';
$username = (string)$_SESSION['username'];

if ($role === 'department_user') {
    http_response_code(403);
    exit("<div style='padding:100px;text-align:center;font-family:system-ui,sans-serif'>
          <h2>Announcements are managed by the front desk</h2>
          <p>Your login does not have access to this screen.</p>
          <a href='dashboard.php'>Back to dashboard</a></div>");
}

$db = ann_db();
if (!$db) {
    exit("<div style='padding:100px;text-align:center;font-family:system-ui,sans-serif'>
          <h2>Database connection not available</h2>
          <p>config/db_connect.php did not provide \$mysql_conn.</p></div>");
}
$table_error = ann_ensure_table($db);

if (empty($_SESSION['ann_csrf'])) {
    $_SESSION['ann_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['ann_csrf'];

function ann_flash($kind, $text) {
    $_SESSION['ann_flash'] = ['kind' => $kind, 'text' => $text];
}

function ann_valid_date($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/* Saves an uploaded image, returns the stored filename or null. */
function ann_store_image(array $file, &$error) {
    $error = '';
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'The image did not upload. Try a smaller file.';
        return null;
    }
    if ($file['size'] > ANN_MAX_BYTES) {
        $error = 'Image is larger than 6 MB. Compress it and upload again.';
        return null;
    }

    $info = @getimagesize($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!$info || !isset($allowed[$info['mime']])) {
        $error = 'Only JPG, PNG, WEBP or GIF images are accepted.';
        return null;
    }

    if (!is_dir(ANN_DIR) && !@mkdir(ANN_DIR, 0775, true) && !is_dir(ANN_DIR)) {
        $error = 'Could not create uploads/announcements/. Check folder permissions.';
        return null;
    }

    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$info['mime']];
    if (!@move_uploaded_file($file['tmp_name'], ANN_DIR . '/' . $name)) {
        $error = 'Could not write the image to uploads/announcements/.';
        return null;
    }
    return $name;
}

function ann_delete_image($name) {
    $name = basename((string)$name);
    if ($name !== '' && is_file(ANN_DIR . '/' . $name)) { @unlink(ANN_DIR . '/' . $name); }
}

/* ---------------------- POST actions ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        ann_flash('bad', 'Your session expired. Please try again.');
        header('Location: announcements.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    /* ---- add or update ---- */
    if ($action === 'save') {
        $id     = (int)($_POST['id'] ?? 0);
        $type   = (($_POST['type'] ?? 'text') === 'image') ? 'image' : 'text';
        $tone   = in_array($_POST['tone'] ?? '', ['info', 'wish', 'alert'], true) ? $_POST['tone'] : 'info';
        $title  = trim((string)($_POST['title'] ?? ''));
        $body   = trim((string)($_POST['body'] ?? ''));
        $ticker = ($type === 'text' && isset($_POST['show_in_ticker'])) ? 1 : 0;
        $active = isset($_POST['is_active']) ? 1 : 0;
        $start  = ann_valid_date($_POST['start_date'] ?? '');
        $end    = ann_valid_date($_POST['end_date'] ?? '');
        $order  = (int)($_POST['sort_order'] ?? 0);

        if (function_exists('mb_substr')) {
            $title = mb_substr($title, 0, 160);
            $who   = mb_substr($username, 0, 100);
        } else {
            $title = substr($title, 0, 160);
            $who   = substr($username, 0, 100);
        }

        $old_image = null;
        if ($id > 0) {
            $q = $db->prepare("SELECT image_path FROM announcements WHERE id = ?");
            $q->bind_param('i', $id);
            $q->execute();
            $old_image = $q->get_result()->fetch_assoc()['image_path'] ?? null;
            $q->close();
        }

        $upload_error = '';
        $new_image = ann_store_image($_FILES['image'] ?? [], $upload_error);
        $image = $new_image ?: $old_image;

        $problem = '';
        if ($upload_error !== '') {
            $problem = $upload_error;
        } elseif ($type === 'image' && !$image) {
            $problem = 'Pick an image file for an image announcement.';
        } elseif ($type === 'text' && $title === '' && $body === '') {
            $problem = 'Add a heading or a message.';
        } elseif ($start && $end && $start > $end) {
            $problem = 'The end date is before the start date.';
        }

        if ($problem !== '') {
            if ($new_image) { ann_delete_image($new_image); }
            ann_flash('bad', $problem);
            header('Location: announcements.php' . ($id ? '?edit=' . $id : ''));
            exit;
        }

        if ($type === 'text') { $image = $new_image ? $image : $old_image; }

        if ($id > 0) {
            $sql = "UPDATE announcements
                    SET type=?, tone=?, title=?, body=?, image_path=?, show_in_ticker=?,
                        is_active=?, start_date=?, end_date=?, sort_order=?
                    WHERE id=?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('sssssiissii', $type, $tone, $title, $body, $image,
                              $ticker, $active, $start, $end, $order, $id);
            $stmt->execute();
            $stmt->close();
            if ($new_image && $old_image && $new_image !== $old_image) { ann_delete_image($old_image); }
            ann_flash('ok', 'Announcement updated. The wall picks it up within a minute.');
        } else {
            $sql = "INSERT INTO announcements
                    (type, tone, title, body, image_path, show_in_ticker, is_active,
                     start_date, end_date, sort_order, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('sssssiissis', $type, $tone, $title, $body, $image,
                              $ticker, $active, $start, $end, $order, $who);
            $stmt->execute();
            $stmt->close();
            ann_flash('ok', 'Announcement published. The wall picks it up within a minute.');
        }

        header('Location: announcements.php');
        exit;
    }

    /* ---- show / hide ---- */
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("UPDATE announcements SET is_active = 1 - is_active WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        ann_flash('ok', 'Visibility changed.');
        header('Location: announcements.php');
        exit;
    }

    /* ---- delete ---- */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $q = $db->prepare("SELECT image_path FROM announcements WHERE id = ?");
        $q->bind_param('i', $id);
        $q->execute();
        $img = $q->get_result()->fetch_assoc()['image_path'] ?? null;
        $q->close();

        $stmt = $db->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        if ($img) { ann_delete_image($img); }

        ann_flash('ok', 'Announcement removed.');
        header('Location: announcements.php');
        exit;
    }
}

/* ---------------------- page data ---------------------- */
$flash = $_SESSION['ann_flash'] ?? null;
unset($_SESSION['ann_flash']);

$edit = null;
if (!empty($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $q = $db->prepare("SELECT * FROM announcements WHERE id = ?");
    $q->bind_param('i', $eid);
    $q->execute();
    $edit = $q->get_result()->fetch_assoc() ?: null;
    $q->close();
}

$rows = [];
try {
    $res = $db->query("SELECT * FROM announcements ORDER BY is_active DESC, sort_order ASC, id DESC");
    if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } $res->free(); }
} catch (Throwable $ex) { $rows = []; }

$live = ann_payload($db);
$live_count = count($live['panel']) + count($live['ticker']);

$tone_label = ['info' => 'Notice', 'wish' => 'Greeting', 'alert' => 'Important'];

function ann_is_live(array $r) {
    if ((int)$r['is_active'] !== 1) return false;
    $today = date('Y-m-d');
    if (!empty($r['start_date']) && $r['start_date'] > $today) return false;
    if (!empty($r['end_date'])   && $r['end_date']   < $today) return false;
    return true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#00325C">
<meta name="robots" content="noindex, nofollow">
<title>Announcements | SL Diagnostics</title>
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
body{
  margin:0; background:var(--paper); color:var(--ink);
  font-family:var(--sans); font-size:15px; line-height:1.5;
  padding-top:60px; -webkit-font-smoothing:antialiased;
}
h1,h2,h3{margin:0; font-weight:700; letter-spacing:-.02em}
a{color:inherit}
.num{font-family:var(--mono); font-variant-numeric:tabular-nums}
.eyebrow{margin:0 0 6px; font-size:.68rem; font-weight:700; letter-spacing:.14em;
         text-transform:uppercase; color:var(--ink-3)}
:focus-visible{outline:2px solid var(--navy); outline-offset:2px; border-radius:6px}

.topbar{
  position:fixed; inset:0 0 auto 0; height:60px; z-index:60;
  display:flex; align-items:center; gap:16px; padding:0 20px;
  background:rgba(255,255,255,.9); backdrop-filter:saturate(160%) blur(10px);
  border-bottom:1px solid var(--line);
}
.brand{display:flex; align-items:baseline; gap:8px; text-decoration:none;
       font-weight:800; font-size:1.05rem; letter-spacing:-.02em; white-space:nowrap}
.brand b{color:var(--navy)}
.brand span{font-size:.62rem; font-weight:700; letter-spacing:.16em; text-transform:uppercase;
            color:var(--ink-3); padding-left:10px; border-left:1px solid var(--line)}
.nav{display:flex; align-items:center; gap:4px; margin-left:auto}
.nav a{display:inline-flex; align-items:center; gap:7px; text-decoration:none;
       padding:8px 12px; border-radius:9px; font-size:.8rem; font-weight:600; color:var(--ink-2)}
.nav a:hover{background:var(--navy-tint); color:var(--navy-deep)}
.nav a.is-out{color:var(--alert)}

.wrap{max-width:1240px; margin:0 auto; padding:26px 20px 60px}
.masthead{display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end;
          justify-content:space-between; margin-bottom:20px}
.masthead h1{font-size:1.55rem}
.masthead p.sub{margin:6px 0 0; color:var(--ink-2); font-size:.88rem}

.btn{display:inline-flex; align-items:center; gap:8px; text-decoration:none;
     padding:11px 16px; border-radius:10px; font-size:.83rem; font-weight:700;
     border:1px solid transparent; cursor:pointer; font-family:inherit}
.btn-solid{background:var(--navy); color:#fff}
.btn-solid:hover{background:var(--navy-deep)}
.btn-ghost{background:var(--surface); color:var(--ink); border-color:var(--line)}
.btn-ghost:hover{border-color:var(--navy); color:var(--navy-deep)}
.btn-sm{padding:7px 11px; font-size:.76rem; border-radius:8px}
.btn-danger{background:var(--surface); color:var(--alert); border-color:var(--line)}
.btn-danger:hover{background:var(--alert-tint); border-color:var(--alert)}

.flash{display:flex; gap:10px; align-items:flex-start; padding:13px 16px; border-radius:var(--r-sm);
       font-size:.87rem; font-weight:600; margin-bottom:18px; border:1px solid}
.flash.ok{background:var(--clear-tint); border-color:#BFE0D7; color:#0A5A48}
.flash.bad{background:var(--alert-tint); border-color:#F0C7C2; color:var(--alert)}

.grid{display:grid; grid-template-columns:400px 1fr; gap:22px; align-items:start}
.panel{background:var(--surface); border:1px solid var(--line); border-radius:var(--r);
       box-shadow:var(--shadow); overflow:hidden}
.panel-head{padding:16px 20px; border-bottom:1px solid var(--line-soft);
            display:flex; align-items:center; gap:10px}
.panel-head h2{font-size:.95rem}
.panel-head .spacer{margin-left:auto}
.tag{font-size:.68rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
     color:var(--ink-3); padding:3px 9px; background:var(--paper);
     border:1px solid var(--line); border-radius:99px}

form.editor{padding:18px 20px 22px}
.field{margin-bottom:16px}
.field label.lbl{display:block; font-size:.72rem; font-weight:700; letter-spacing:.1em;
                 text-transform:uppercase; color:var(--ink-3); margin-bottom:7px}
input[type=text], input[type=date], input[type=number], textarea, select, input[type=file]{
  width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:9px;
  font:inherit; font-size:.9rem; background:var(--paper); color:var(--ink);
}
textarea{min-height:96px; resize:vertical}
input:focus, textarea:focus, select:focus{background:var(--surface); border-color:var(--navy); outline:none}
.hint{margin:6px 0 0; font-size:.75rem; color:var(--ink-3); font-weight:600}

.seg{display:flex; gap:8px}
.seg label{flex:1; position:relative}
.seg input{position:absolute; opacity:0; width:0; height:0}
.seg span{display:block; text-align:center; padding:9px 6px; border:1px solid var(--line);
          border-radius:9px; font-size:.8rem; font-weight:700; color:var(--ink-2);
          background:var(--paper); cursor:pointer}
.seg input:checked + span{background:var(--navy-tint); border-color:var(--navy); color:var(--navy-deep)}
.seg input:focus-visible + span{outline:2px solid var(--navy); outline-offset:2px}

.check{display:flex; gap:10px; align-items:flex-start; padding:11px 12px;
       border:1px solid var(--line); border-radius:9px; background:var(--paper); margin-bottom:10px}
.check input{margin-top:3px; flex:none}
.check b{display:block; font-size:.85rem}
.check small{color:var(--ink-3); font-weight:600; font-size:.76rem}

.two{display:grid; grid-template-columns:1fr 1fr; gap:12px}
.form-actions{display:flex; gap:10px; margin-top:6px}
.thumb-now{display:flex; gap:10px; align-items:center; margin-bottom:8px}
.thumb-now img{width:74px; height:52px; object-fit:cover; border-radius:8px; border:1px solid var(--line)}

.scroll{overflow-x:auto}
table{width:100%; border-collapse:collapse; min-width:720px}
th{position:sticky; top:0; background:var(--surface); text-align:left; padding:12px 16px;
   font-size:.68rem; font-weight:700; letter-spacing:.1em; text-transform:uppercase;
   color:var(--ink-3); border-bottom:1px solid var(--line)}
td{padding:14px 16px; border-bottom:1px solid var(--line-soft); font-size:.88rem; vertical-align:top}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover td{background:#FAFCFD}
td.r, th.r{text-align:right; white-space:nowrap}

.item{display:flex; gap:12px; align-items:flex-start}
.item img{width:72px; height:50px; object-fit:cover; border-radius:8px; border:1px solid var(--line); flex:none}
.item .txt b{display:block; letter-spacing:-.01em}
.item .txt p{margin:3px 0 0; color:var(--ink-2); font-size:.82rem;
             display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden}

.pill{display:inline-block; padding:4px 10px; border-radius:99px; font-size:.72rem; font-weight:700}
.pill.live{background:var(--clear-tint); color:#0A5A48}
.pill.off{background:var(--line-soft); color:var(--ink-3)}
.pill.later{background:var(--signal-tint); color:var(--signal)}
.pill.tone-info{background:var(--navy-tint); color:var(--navy-deep)}
.pill.tone-wish{background:var(--signal-tint); color:var(--signal)}
.pill.tone-alert{background:var(--alert-tint); color:var(--alert)}
.where{font-size:.78rem; font-weight:700; color:var(--ink-2)}
.dates{font-size:.78rem; color:var(--ink-3); font-weight:600; white-space:nowrap}
.rowacts{display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap}
.rowacts form{display:inline}
.empty{padding:46px 20px; text-align:center; color:var(--ink-3); font-size:.88rem}

@media (max-width:1040px){ .grid{grid-template-columns:1fr} }
@media (max-width:820px){
  body{padding-top:56px}
  .topbar{height:56px; padding:0 14px; overflow-x:auto}
  .wrap{padding:20px 14px 44px}
}
@media (prefers-reduced-motion:reduce){ *{animation:none !important; transition:none !important} }
</style>
</head>
<body>

<header class="topbar">
  <a href="dashboard.php" class="brand">SL <b>Diagnostics</b> <span>Announcements</span></a>
  <nav class="nav">
    <a href="dashboard.php">Dashboard</a>
    <a href="view_queue.php">Live queue</a>
    <a href="monitor5.php" target="_blank" rel="noopener">Open wall</a>
    <a href="logout.php" class="is-out">Sign out</a>
  </nav>
</header>

<main class="wrap">

  <section class="masthead">
    <div>
      <p class="eyebrow">Waiting room wall</p>
      <h1><?= $edit ? 'Edit announcement' : 'Announcements' ?></h1>
      <p class="sub">
        <?= (int)$live_count ?> showing on the wall right now.
        Nothing active means the wall shows no announcement strip at all.
      </p>
    </div>
    <?php if ($edit): ?>
      <a class="btn btn-ghost" href="announcements.php">Cancel edit</a>
    <?php endif; ?>
  </section>

  <?php if (!empty($table_error)): ?>
    <div class="flash bad"><?= e($table_error) ?></div>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div class="flash <?= e($flash['kind']) ?>"><?= e($flash['text']) ?></div>
  <?php endif; ?>

  <div class="grid">

    <!-- ============ editor ============ -->
    <section class="panel">
      <div class="panel-head">
        <h2><?= $edit ? 'Edit #' . (int)$edit['id'] : 'New announcement' ?></h2>
        <span class="spacer"></span>
        <span class="tag">Text or image</span>
      </div>

      <form class="editor" method="post" enctype="multipart/form-data" action="announcements.php">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

        <div class="field">
          <label class="lbl">What are you putting up</label>
          <div class="seg">
            <label>
              <input type="radio" name="type" value="text" id="typeText"
                     <?= (!$edit || $edit['type'] === 'text') ? 'checked' : '' ?>>
              <span>Message</span>
            </label>
            <label>
              <input type="radio" name="type" value="image" id="typeImage"
                     <?= ($edit && $edit['type'] === 'image') ? 'checked' : '' ?>>
              <span>Image</span>
            </label>
          </div>
        </div>

        <div class="field">
          <label class="lbl" for="tone">Style</label>
          <select name="tone" id="tone">
            <option value="info"  <?= ($edit && $edit['tone'] === 'info')  ? 'selected' : '' ?>>Notice - plain white heading</option>
            <option value="wish"  <?= ($edit && $edit['tone'] === 'wish')  ? 'selected' : '' ?>>Greeting - gold heading, for wishes</option>
            <option value="alert" <?= ($edit && $edit['tone'] === 'alert') ? 'selected' : '' ?>>Important - red band</option>
          </select>
        </div>

        <div class="field">
          <label class="lbl" for="title">Heading</label>
          <input type="text" name="title" id="title" maxlength="160"
                 value="<?= $edit ? e($edit['title']) : '' ?>"
                 placeholder="Happy Diwali from all of us">
        </div>

        <div class="field">
          <label class="lbl" for="body">Message</label>
          <textarea name="body" id="body"
                    placeholder="Keep it to one or two lines - people read this from across the room."><?= $edit ? e($edit['body']) : '' ?></textarea>
          <p class="hint">Tamil text is fine. Long paragraphs get cut off on the wall.</p>
        </div>

        <div class="field" id="imageField">
          <label class="lbl" for="image">Image file</label>
          <?php if ($edit && !empty($edit['image_path'])): ?>
            <div class="thumb-now">
              <img src="<?= e(ANN_URL . '/' . $edit['image_path']) ?>" alt="">
              <span class="hint">Currently used. Choose a new file only if you want to replace it.</span>
            </div>
          <?php endif; ?>
          <input type="file" name="image" id="image" accept="image/jpeg,image/png,image/webp,image/gif">
          <p class="hint">JPG, PNG, WEBP or GIF up to 6 MB. Wide images fit the strip best.</p>
        </div>

        <div class="check" id="tickerRow">
          <input type="checkbox" name="show_in_ticker" id="show_in_ticker" value="1"
                 <?= ($edit && (int)$edit['show_in_ticker'] === 1) ? 'checked' : '' ?>>
          <label for="show_in_ticker">
            <b>Scroll it in the bottom strip instead</b>
            <small>Good for short one-liners. Messages only.</small>
          </label>
        </div>

        <div class="check">
          <input type="checkbox" name="is_active" id="is_active" value="1"
                 <?= (!$edit || (int)$edit['is_active'] === 1) ? 'checked' : '' ?>>
          <label for="is_active">
            <b>Show on the wall</b>
            <small>Untick to keep it saved but hidden.</small>
          </label>
        </div>

        <div class="field two">
          <div>
            <label class="lbl" for="start_date">Start date</label>
            <input type="date" name="start_date" id="start_date"
                   value="<?= $edit ? e($edit['start_date']) : '' ?>">
          </div>
          <div>
            <label class="lbl" for="end_date">End date</label>
            <input type="date" name="end_date" id="end_date"
                   value="<?= $edit ? e($edit['end_date']) : '' ?>">
          </div>
        </div>
        <p class="hint" style="margin:-8px 0 16px">Leave both blank to run until you hide it.</p>

        <div class="field">
          <label class="lbl" for="sort_order">Order</label>
          <input type="number" name="sort_order" id="sort_order" step="1"
                 value="<?= $edit ? (int)$edit['sort_order'] : 0 ?>">
          <p class="hint">Lower number shows first in the rotation.</p>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-solid"><?= $edit ? 'Save changes' : 'Publish' ?></button>
          <?php if ($edit): ?><a href="announcements.php" class="btn btn-ghost">Cancel</a><?php endif; ?>
        </div>
      </form>
    </section>

    <!-- ============ list ============ -->
    <section class="panel">
      <div class="panel-head">
        <h2>Everything saved</h2>
        <span class="spacer"></span>
        <span class="tag num"><?= count($rows) ?> total</span>
      </div>

      <?php if (empty($rows)): ?>
        <p class="empty">Nothing here yet. Publish your first announcement on the left and it appears on the wall within a minute.</p>
      <?php else: ?>
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th scope="col">Announcement</th>
              <th scope="col">Style</th>
              <th scope="col">Where</th>
              <th scope="col">Runs</th>
              <th scope="col">Status</th>
              <th scope="col" class="r">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r):
            $live_now = ann_is_live($r);
            $scheduled = ((int)$r['is_active'] === 1 && !$live_now); ?>
            <tr>
              <td>
                <div class="item">
                  <?php if ($r['type'] === 'image' && !empty($r['image_path'])): ?>
                    <img src="<?= e(ANN_URL . '/' . $r['image_path']) ?>" alt="">
                  <?php endif; ?>
                  <div class="txt">
                    <b><?= $r['title'] !== '' && $r['title'] !== null ? e($r['title']) : '(no heading)' ?></b>
                    <?php if (trim((string)$r['body']) !== ''): ?>
                      <p><?= e($r['body']) ?></p>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td><span class="pill tone-<?= e($r['tone']) ?>"><?= e($tone_label[$r['tone']] ?? 'Notice') ?></span></td>
              <td class="where"><?= ((int)$r['show_in_ticker'] === 1 && $r['type'] === 'text') ? 'Bottom strip' : 'Announcement band' ?></td>
              <td class="dates">
                <?php if (empty($r['start_date']) && empty($r['end_date'])): ?>
                  Until hidden
                <?php else: ?>
                  <?= $r['start_date'] ? e(date('d M', strtotime($r['start_date']))) : 'now' ?>
                  &rarr;
                  <?= $r['end_date'] ? e(date('d M', strtotime($r['end_date']))) : 'open' ?>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($live_now): ?><span class="pill live">On the wall</span>
                <?php elseif ($scheduled): ?><span class="pill later">Out of date range</span>
                <?php else: ?><span class="pill off">Hidden</span><?php endif; ?>
              </td>
              <td class="r">
                <div class="rowacts">
                  <a class="btn btn-ghost btn-sm" href="announcements.php?edit=<?= (int)$r['id'] ?>">Edit</a>
                  <form method="post" action="announcements.php">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">
                      <?= (int)$r['is_active'] === 1 ? 'Hide' : 'Show' ?>
                    </button>
                  </form>
                  <form method="post" action="announcements.php"
                        onsubmit="return confirm('Delete this announcement for good?');">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

  </div>
</main>

<script>
(function () {
  'use strict';
  var text = document.getElementById('typeText');
  var image = document.getElementById('typeImage');
  var imageField = document.getElementById('imageField');
  var tickerRow = document.getElementById('tickerRow');
  var tickerBox = document.getElementById('show_in_ticker');

  function sync() {
    var isImage = image.checked;
    imageField.style.display = isImage ? '' : 'none';
    tickerRow.style.display = isImage ? 'none' : '';
    if (isImage) { tickerBox.checked = false; }
  }
  text.addEventListener('change', sync);
  image.addEventListener('change', sync);
  sync();
})();
</script>

</body>
</html>