<?php
session_start();

// 1. Authorization Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin1') {
    header('Location: login.php');
    exit;
}

// 2. Data Retrieval
if (!isset($_SESSION['report_data']) || empty($_SESSION['report_data']['rows'])) {
    header('Location: dashboard.php');
    exit;
}

$data = $_SESSION['report_data']['rows'];
$headers = $_SESSION['report_data']['headers'];
$format = $_GET['format'] ?? 'csv'; // Default to CSV

if ($format === 'pdf') {
    // --- PDF UNSUPPORTED FALLBACK ---
    // You would need a library like FPDF or TCPDF here to generate a real PDF.
    // For now, we redirect with a message or simply fall back to CSV if possible.
    // Since the request was explicit about asking PDF, we'll inform the user.
    echo "<script>alert('PDF generation requires installing additional server libraries and is not supported in this script. Please download the CSV for use in Excel.'); window.location.href='dashboard.php';</script>";
    exit;
} 
// Handle CSV/Excel Download
else {
    $filename = 'Department_Activity_Report_' . date('Ymd_His') . '.csv';

    // 3. HTTP Headers for CSV Download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    // Prevent caching
    header('Pragma: no-cache');
    header('Expires: 0');

    // 4. CSV Output Generation
    $output = fopen('php://output', 'w');

    // Write Headers
    fputcsv($output, $headers);
    
    // Write the data rows
    foreach ($data as $row) {
        fputcsv($output, $row);
    }

    fclose($output);

    // Exit to prevent any other output that might corrupt the CSV file
    exit;
}
?>