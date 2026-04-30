// Suggested UPDATE for get_notifications.php:
<?php
header('Content-Type: application/json');
include 'config/db_connect.php';

$dept_ids = [];
if (isset($_GET['dept_ids']) && !empty($_GET['dept_ids'])) {
    $dept_ids = array_map('intval', explode(',', $_GET['dept_ids']));
}

if (empty($dept_ids)) {
    echo json_encode([]);
    exit;
}

// FIX: Remove time limit (INTERVAL 30 SECOND) to ensure the call is always visible until cleared/completed.
$query = "SELECT pn.token_number, pn.pat_name 
          FROM popup_notifications pn 
          WHERE pn.dept_id IN (" . implode(',', $dept_ids) . ") 
          ORDER BY pn.created_at DESC
          LIMIT 10"; // Fetch top 10 for cycling, without time limit.

$result = mysqli_query($mysql_conn, $query);

if ($result) {
    $notifications = mysqli_fetch_all($result, MYSQLI_ASSOC);
} else {
    error_log("DB Error in get_notifications.php: " . mysqli_error($mysql_conn), 0);
    $notifications = []; 
}

echo json_encode($notifications);
?>