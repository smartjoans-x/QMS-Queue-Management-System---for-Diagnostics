<?php
/**
 * Advanced Activity Tracker for SL Diagnostics
 * Logs IP for visitors and Username for logged-in users
 */

function log_activity($mysql_conn, $user_id = null, $username = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $device_name = gethostbyaddr($ip_address);
    
    // Check if it's a dashboard login (with username) or just a page visit
    if ($user_id !== null && $username !== null) {
        $u_id = $user_id;
        $u_name = $username; // Dashboard-ல் லாகின் ஆன யூசர் பெயர்
        $status = "User Logged In";
    } else {
        $u_id = NULL; 
        $u_name = "Guest / Page Visitor"; // Login page-ல் லாகின் பண்ணாத விசிட்டர்
        $status = "IP Access Only";
    }

    $query = "INSERT INTO login_logs (user_id, username, ip_address, device_name) VALUES (?, ?, ?, ?)";
    $stmt = mysqli_prepare($mysql_conn, $query);
    
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'isss', $u_id, $u_name, $ip_address, $device_name);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
?>