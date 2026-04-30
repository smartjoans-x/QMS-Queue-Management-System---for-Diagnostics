<?php
    if (!empty($_SERVER['HTTPS']) && ('on' == $_SERVER['HTTPS'])) {
        $uri = 'https://';
    } else {
        $uri = 'http://';
    }
    $uri .= $_SERVER['HTTP_HOST'];
    
    // Changed from /dashboard/ to /qms2/
    header('Location: '.$uri.'/qms2/login.php');
    exit;
?>