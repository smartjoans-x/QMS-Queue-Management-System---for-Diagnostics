<?php
if (isset($_POST['message']) && isset($_POST['file'])) {
    $message = $_POST['message'];
    $file = $_POST['file'];
    error_log($message, 3, $file);
}
?>