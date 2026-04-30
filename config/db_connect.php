<?php
// MySQL Connection
define('MYSQL_HOST', 'localhost');
define('MYSQL_USER', 'root');
define('MYSQL_PASS', '1234567');
define('MYSQL_DB', 'qms');

$mysql_conn = mysqli_connect(MYSQL_HOST, MYSQL_USER, MYSQL_PASS, MYSQL_DB);
if (!$mysql_conn) {
    die("MySQL Connection failed: " . mysqli_connect_error());
}

// MSSQL Connection
define('MSSQL_DB_HOST', '192.168.0.10,1434');
define('MSSQL_DB_NAME', 'lims');
define('MSSQL_DB_USER', 'php_user');
define('MSSQL_DB_PASS', 'PhpUser2025!');

$mssql_conn = sqlsrv_connect(MSSQL_DB_HOST, [
    'Database' => MSSQL_DB_NAME,
    'Uid' => MSSQL_DB_USER,
    'PWD' => MSSQL_DB_PASS,
    'ReturnDatesAsStrings' => true,
    'CharacterSet' => 'UTF-8' // Ensure UTF-8 encoding for patient names
]);

if ($mssql_conn === false) {
    $errors = sqlsrv_errors();
    $error_message = "MSSQL Connection failed: ";
    foreach ($errors as $error) {
        $error_message .= "SQLSTATE: {$error['SQLSTATE']}, Code: {$error['code']}, Message: {$error['message']}; ";
    }
    die($error_message);
}
?>