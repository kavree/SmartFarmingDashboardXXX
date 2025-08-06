<?php
// Security headers
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'; img-src 'self' data: https:;");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// Start secure session
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');
session_start();

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('CSRF token validation failed');
    }
}

// Input validation and sanitization functions
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

function validateNumber($value) {
    return filter_var($value, FILTER_VALIDATE_INT, [
        "options" => [
            "min_range" => 0,
            "max_range" => 999999
        ]
    ]);
}

// Secure API URL configuration
define('SPREADSHEET_ID', '1Qp4sOxtHleHZPaUoyWK6bcM--LX-rm2omzTPGAIj_TA');
define('API_KEY', 'AIzaSyBFSCGY_HxpxlvwuWoaCsmm2eiFsM75NSg');
define('GOOGLE_SCRIPT_URL', 'https://script.google.com/macros/s/AKfycbwmhQi-TA4geA9rtMubHFQIzoxyVoWOII5APPQJp4rWP50iLypsHufNw1bPyShynjAQ/exec');

// Process form submissions with security measures
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['value'])) {
        $value = validateNumber($_POST['value']);
        if ($value === false) {
            die('Invalid input value');
        }

        $data = ['value' => $value];

        $options = [
            'http' => [
                'header'  => "Content-Type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
                'timeout' => 30,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ];

        $context = stream_context_create($options);
        $result = @file_get_contents(GOOGLE_SCRIPT_URL, false, $context);
        
        if ($result === false) {
            error_log("Failed to update Google Sheet: " . error_get_last()['message']);
            die('Failed to update data');
        }

        $message = sanitizeInput($value);
    } elseif (isset($_POST['download_spray_data'])) {
        // Set timezone to Thailand
        date_default_timezone_set('Asia/Bangkok');
        
        // Get current date and time
        $date = date('Y-m-d H:i:s');
        
        // Secure API calls with error handling
        $sprayCountUrlSheet1 = "https://sheets.googleapis.com/v4/spreadsheets/" . SPREADSHEET_ID . "/values/Sheet1!G2?key=" . API_KEY;
        $sprayCountUrlSheet2 = "https://sheets.googleapis.com/v4/spreadsheets/" . SPREADSHEET_ID . "/values/Sheet2!G2?key=" . API_KEY;
        
        $sprayCountSheet1 = '0';
        $sprayCountSheet2 = '0';
        
        // Get Sheet1 (Zone A) spray count with error handling
        $sprayCountResponse1 = @file_get_contents($sprayCountUrlSheet1, false, stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]));
        
        if ($sprayCountResponse1) {
            $sprayCountData1 = json_decode($sprayCountResponse1, true);
            $sprayCountSheet1 = isset($sprayCountData1['values'][0][0]) ? 
                validateNumber($sprayCountData1['values'][0][0]) : '0';
        }
        
        // Get Sheet2 (Zone B) spray count with error handling
        $sprayCountResponse2 = @file_get_contents($sprayCountUrlSheet2, false, stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]));
        
        if ($sprayCountResponse2) {
            $sprayCountData2 = json_decode($sprayCountResponse2, true);
            $sprayCountSheet2 = isset($sprayCountData2['values'][0][0]) ? 
                validateNumber($sprayCountData2['values'][0][0]) : '0';
        }
        
        // Calculate total spray count
        $totalSprayCount = intval($sprayCountSheet1) + intval($sprayCountSheet2);
        
        // Create file content with sanitized data
        $content = "รายงานการฉีดพ่น\n";
        $content .= "วันที่: " . $date . "\n";
        $content .= "----------------------------------------\n";
        $content .= "โซน A: ฉีดพ่น " . $sprayCountSheet1 . " ครั้ง\n";
        $content .= "โซน B: ฉีดพ่น " . $sprayCountSheet2 . " ครั้ง\n";
        $content .= "----------------------------------------\n";
        $content .= "รวมทั้งหมด: " . $totalSprayCount . " ครั้ง\n";
        $content .= "หมายเหตุ: รายงานนี้ถูกสร้างเมื่อ " . $date . "\n";
        
        // Set secure headers for file download
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="spray_report_' . date('Y-m-d_H-i-s') . '.txt"');
        header('X-Content-Type-Options: nosniff');
        
        // Output file content
        echo $content;
        exit;
    } elseif (isset($_POST['water_level'])) {
        // --- New: Handle water level dropdown submission ---
        $allowed_levels = [25, 50, 75, 100];
        $water_level = intval($_POST['water_level']);
        if (!in_array($water_level, $allowed_levels, true)) {
            die('Invalid water level');
        }
        // Prepare data for Google Apps Script
        $data = [
            'sheet' => 'Sheet1',
            'cell' => 'K2',
            'value' => $water_level . '%'
        ];
        $script_url = 'https://script.google.com/macros/s/AKfycby8Yb2vIIapjpNjVn8RvObgaDjzTZzD5VFhrU6cXbq648FVA1uN-yywft3LliFZVjWi/exec';
        $options = [
            'http' => [
                'header'  => "Content-Type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
                'timeout' => 30,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ];
        $context = stream_context_create($options);
        $result = @file_get_contents($script_url, false, $context);
        if ($result === false) {
            error_log("Failed to update water level: " . error_get_last()['message']);
            die('Failed to update water level');
        }
        $water_message = sanitizeInput($water_level);
    } elseif (isset($_POST['water_level_b'])) {
        // --- New: Handle water level dropdown submission for Zone B ---
        $allowed_levels = [25, 50, 75, 100];
        $water_level_b = intval($_POST['water_level_b']);
        if (!in_array($water_level_b, $allowed_levels, true)) {
            die('Invalid water level (Zone B)');
        }
        // Prepare data for Google Apps Script (Zone B)
        $data = [
            'sheet' => 'Sheet2',
            'cell' => 'K2',
            'value' => $water_level_b . '%'
        ];
        $script_url_b = 'https://script.google.com/macros/s/AKfycbwSRvlxcadu_zkLYveliSNCqe2UJLqQt7a92NIiwl3xE__FSosgpHVUrRG4dDMcV_av/exec';
        $options = [
            'http' => [
                'header'  => "Content-Type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
                'timeout' => 30,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ];
        $context = stream_context_create($options);
        $result = @file_get_contents($script_url_b, false, $context);
        if ($result === false) {
            error_log("Failed to update water level (Zone B): " . error_get_last()['message']);
            die('Failed to update water level (Zone B)');
        }
        $water_message_b = sanitizeInput($water_level_b);
    }
}

// Fetch threshold value with error handling
$thresholdUrl = "https://sheets.googleapis.com/v4/spreadsheets/" . SPREADSHEET_ID . "/values/Sheet1!H2?key=" . API_KEY;
$thresholdResponse = @file_get_contents($thresholdUrl, false, stream_context_create([
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true
    ]
]));

$thresholdValue = '0';
if ($thresholdResponse) {
    $thresholdData = json_decode($thresholdResponse, true);
    $thresholdValue = isset($thresholdData['values'][0][0]) ? 
        validateNumber($thresholdData['values'][0][0]) : '0';
}

// Fetch data for report charts
function fetchSheetReportData($sheetName) {
    $range = "{$sheetName}!A:G";
    $url = "https://sheets.googleapis.com/v4/spreadsheets/" . SPREADSHEET_ID . "/values/" . urlencode($range) . "?key=" . API_KEY;
    
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);

    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        error_log("Failed to fetch report data for {$sheetName}");
        return [];
    }
    
    $data = json_decode($response, true);
    return isset($data['values']) ? array_filter($data['values']) : []; // Filter empty rows
}

$reportDataSheet1 = fetchSheetReportData('Sheet1');
$reportDataSheet2 = fetchSheetReportData('Sheet2');

// Secure function for fetching Google Sheet data
function fetchGoogleSheetData() {
    $csvUrl = "https://docs.google.com/spreadsheets/d/" . SPREADSHEET_ID . "/export?format=csv";
    
    $csvData = @file_get_contents($csvUrl, false, stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]));
    
    if ($csvData === false) {
        error_log("Failed to fetch Google Sheet data");
        return false;
    }
    
    $rows = array_map('str_getcsv', explode("\n", $csvData));
    $rows = array_filter($rows);
    
    if (count($rows) > 0) {
        $headers = array_map('sanitizeInput', array_shift($rows));
        $data = array_map(function($row) {
            return array_map('sanitizeInput', $row);
        }, $rows);
    } else {
        $headers = [];
        $data = [];
    }
    
    return [
        'headers' => $headers,
        'data' => $data
    ];
}

// Fetch data with error handling
$sheetData = fetchGoogleSheetData();
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบติดตามแมลงศัตรูพืช | Smart Farming</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🌾</text></svg>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            /* Modern Color Palette */
            --primary-color: #2ecc71; /* Fresh Green */
            --secondary-color: #34495e; /* Dark Blue */
            --accent-color: #3498db; /* Bright Blue */
            --accent-light: #7fdbff; /* Light Blue */
            --warning-color: #e74c3c; /* Vibrant Red */
            --success-color: #2ecc71; /* Success Green */
            --info-color: #3498db; /* Info Blue */
            --light-color: #ecf0f1; /* Light Grey */
            --dark-color: #2c3e50; /* Dark Blue */
            --text-color: #ffffff; /* White */
            --text-muted: #bdc3c7; /* Light Grey */
            --bg-color: #1a1a2e; /* Deep Navy */
            --card-bg: rgba(44, 62, 80, 0.7);
            --border-color: rgba(255, 255, 255, 0.1);
            --shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            --shadow-hover: 0 15px 30px rgba(0, 0, 0, 0.3);
            --border-radius: 12px;
            --border-radius-sm: 8px;
            --spacing: 1.5rem;
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            --gradient-primary: linear-gradient(135deg, #2ecc71 0%, #3498db 100%);
            --gradient-secondary: linear-gradient(135deg, #34495e 0%, #2c3e50 100%);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Noto Sans Thai', sans-serif;
            background: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
            padding: 0;
            margin: 0;
            min-height: 100vh;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 80%, rgba(0, 188, 212, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(76, 175, 80, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(255, 87, 34, 0.05) 0%, transparent 50%),
                linear-gradient(135deg, #23234f 0%, #1a1a3a 50%, #23234f 100%);
            pointer-events: none;
            z-index: -1;
        }
        
        .header {
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.9), rgba(0, 188, 212, 0.9));
            backdrop-filter: blur(10px);
            padding: 2.5rem var(--spacing);
            text-align: center;
            color: white;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .header::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path fill="rgba(255,255,255,0.08)" d="M0,0 L100,0 L100,100 L0,100 Z" /></svg>');
            opacity: 0.1;
        }
        
        .header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            position: relative;
            text-shadow: 0 2px 8px rgba(0,0,0,0.15);
            letter-spacing: 0.5px;
        }
        
        .header h1 i {
            font-size: 2.5rem;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
        }
        
        .dashboard {
            max-width: 1400px;
            margin: var(--spacing) auto;
            padding: 0 var(--spacing);
            width: 100%;
        }
        
        .summary-bar {
            background: rgba(44, 44, 74, 0.8);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: var(--spacing);
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .summary-bar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-primary);
        }
        
        .summary-bar:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }
        
        .summary-item {
            text-align: center;
            padding: 1.5rem;
            position: relative;
            background: var(--card-bg);
            border-radius: var(--border-radius-sm);
            transition: var(--transition);
            border: 1px solid var(--border-color);
        }
        
        .summary-item:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(46, 125, 50, 0.15);
        }
        
        .summary-item strong {
            font-size: 3rem;
            color: var(--accent-color);
            display: block;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 0 2px 4px rgba(0, 188, 212, 0.2);
        }
        
        .summary-label {
            font-size: 1rem;
            color: var(--text-muted);
            font-weight: 500;
        }
        
        .container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: var(--spacing);
            margin-bottom: var(--spacing);
        }
        
        .card {
            background: rgba(44, 44, 74, 0.8);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 280px;
        }
        
        .card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 6px;
            height: 100%;
            background: var(--gradient-primary);
            transition: var(--transition);
            box-shadow: 0 0 10px rgba(0, 188, 212, 0.5);
        }
        
        .card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            border-color: rgba(0, 188, 212, 0.3);
        }
        
        .card:hover::before {
            width: 8px;
            background: var(--accent-color);
        }
        
        .card.critical::before {
            background: var(--warning-color);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--secondary-color);
            position: relative;
        }
        
        .card-header i.fa-bug, .card-header i.fa-worm, .card-header i.fa-beetle, .card-header i.fa-tint {
            font-size: 1.8rem;
            color: var(--accent-color);
            margin-right: 1rem;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(44, 44, 74, 0.9), rgba(58, 58, 90, 0.9));
            backdrop-filter: blur(10px);
            border-radius: 50%;
            transition: var(--transition);
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
            border: 1px solid rgba(0, 188, 212, 0.3);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .card:hover .card-header i.fa-bug, 
        .card:hover .card-header i.fa-worm,
        .card:hover .card-header i.fa-beetle,
        .card:hover .card-header i.fa-tint {
            transform: rotate(15deg) scale(1.1);
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 6px 20px rgba(0, 188, 212, 0.3);
        }
        
        .card h3 {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--text-color);
            flex-grow: 1;
        }
        
        .status-container {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        
        .card-status {
            background: var(--card-bg);
            color: var(--accent-color);
            font-size: 0.8rem;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            letter-spacing: 0.5px;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(0, 188, 212, 0.2);
            border: 1px solid var(--border-color);
        }
        
        .zone-badge {
            background: var(--gradient-primary);
            color: white;
            font-size: 0.85rem;
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.2);
            transition: var(--transition);
        }
        
        .card:hover .zone-badge {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(46, 125, 50, 0.3);
        }
        
        .value-container {
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
            margin: 2rem 0;
            flex-grow: 1;
        }
        
        .value {
            font-size: 4.5rem;
            font-weight: 300;
            color: var(--accent-color);
            text-align: center;
            background: linear-gradient(135deg, rgba(44, 44, 74, 0.9), rgba(58, 58, 90, 0.9));
            backdrop-filter: blur(10px);
            width: 200px;
            height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin: 0 auto;
            box-shadow: 
                inset 0 4px 12px rgba(0, 0, 0, 0.3),
                0 0 20px rgba(0, 188, 212, 0.2);
            border: 8px solid rgba(0, 188, 212, 0.3);
            position: relative;
            transition: var(--transition);
        }
        
        .card:hover .value {
            border-color: var(--accent-color);
            transform: scale(1.05);
            box-shadow: inset 0 6px 16px rgba(0, 0, 0, 0.3);
        }
        
        .value-icon {
            font-size: 2rem;
            position: absolute;
            right: -12px;
            top: -12px;
            background: var(--card-bg);
            width: 56px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            border: 4px solid var(--accent-color);
            box-shadow: 0 4px 16px rgba(0, 188, 212, 0.3);
            transition: var(--transition);
        }
        
        .card:hover .value-icon {
            transform: scale(1.15) rotate(20deg);
            box-shadow: 0 6px 20px rgba(0, 188, 212, 0.4);
        }
        
        .critical .value {
            background: #2C2C4A;
            color: var(--warning-color);
            border-color: var(--warning-color);
        }
        
        .critical .value-icon {
            border-color: var(--warning-color);
        }
        
        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 1.5rem;
            border-top: 2px solid var(--border-color);
            font-size: 0.95rem;
            color: var(--text-muted);
            margin-top: auto;
        }
        
        .trend {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--accent-color);
            font-weight: 500;
        }
        
        .trend-up {
            color: var(--warning-color);
        }
        
        .trend-down {
            color: var(--success-color);
        }
        
        .warning {
            border-color: var(--warning-color);
            color: var(--warning-color);
        }
        
        .warning:hover {
            background: var(--warning-color);
            color: white;
        }
        
        .refresh-time {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: var(--spacing);
            border: 1px solid var(--border-color);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .refresh-time::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: var(--gradient-primary);
        }
        
        .refresh-time:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }
        
        .refresh-btn {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.8rem;
            transition: var(--transition);
            font-weight: 600;
            box-shadow: 0 4px 16px rgba(0, 188, 212, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .refresh-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                120deg,
                transparent,
                rgba(255, 255, 255, 0.3),
                transparent
            );
            transition: 0.6s;
        }
        
        .refresh-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0, 188, 212, 0.4);
        }
        
        .refresh-btn:hover::before {
            left: 100%;
        }
        
        .refresh-btn i {
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }
        
        .refresh-btn:hover i {
            transform: rotate(15deg);
        }
        
        .refresh-btn.download-btn {
            background: linear-gradient(135deg, #00BCD4, #0097A7);
        }
        
        .refresh-btn.download-btn:hover {
            background: linear-gradient(135deg, #0097A7, #00BCD4);
        }
        
        .button-group {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .interval-selector {
            padding: 0.8rem 1.2rem;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 1rem;
            transition: var(--transition);
            cursor: pointer;
            font-weight: 500;
        }
        
        .interval-selector:hover {
            border-color: var(--accent-color);
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.2);
        }
        
        .interval-selector:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.2);
        }
        
        .empty-value {
            color: var(--text-muted);
            font-style: italic;
            font-weight: 300;
        }
        
        .footer {
            text-align: center;
            padding: 2rem var(--spacing);
            color: var(--text-muted);
            font-size: 1rem;
            background: var(--card-bg);
            border-top: 1px solid var(--border-color);
            margin-top: var(--spacing);
            box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.2);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .card {
            animation: fadeIn 0.6s ease-out forwards;
        }
        
        .card:nth-child(1) { animation-delay: 0.1s; }
        .card:nth-child(2) { animation-delay: 0.2s; }
        .card:nth-child(3) { animation-delay: 0.3s; }
        .card:nth-child(4) { animation-delay: 0.4s; }
        .card:nth-child(5) { animation-delay: 0.5s; }
        .card:nth-child(6) { animation-delay: 0.6s; }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Floating Particles */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }
        
        .particle {
            position: absolute;
            width: 2px;
            height: 2px;
            background: rgba(0, 188, 212, 0.6);
            border-radius: 50%;
            animation: float 6s infinite linear;
        }
        
        @keyframes float {
            0% {
                transform: translateY(100vh) translateX(0);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100px) translateX(100px);
                opacity: 0;
            }
        }
        
        #sheet1-water-value,
        #sheet2-water-value {
            font-size: 2.5rem;
            line-height: 1;
            padding-bottom: 0.2rem;
        }
        
        /* Data Update Form Styling */
        .data-update-card {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            margin-bottom: var(--spacing);
            overflow: hidden;
        }
        
        .data-update-header {
            background: var(--gradient-primary);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .data-update-header i {
            font-size: 1.8rem;
        }
        
        .data-update-header h3 {
            font-size: 1.4rem;
            font-weight: 600;
            margin: 0;
        }
        
        .data-update-content {
            padding: 2rem;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            align-items: start;
        }
        
        .form-section {
            padding: 1.5rem;
            background: var(--card-bg);
            border-radius: var(--border-radius-sm);
            border: 1px solid var(--border-color);
        }
        
        .form-section h4 {
            color: var(--accent-color);
            margin-bottom: 1rem;
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-group {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .form-group label {
            font-weight: 600;
            color: var(--text-color);
            white-space: nowrap;
            min-width: 120px;
        }
        
        .form-group input,
        .form-group select {
            padding: 0.8rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background: var(--card-bg);
            color: var(--text-color);
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.2);
        }
        
        .form-group button {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: var(--border-radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-group button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.2);
        }
        
        .threshold-display {
            text-align: center;
            padding: 2rem;
            background: var(--card-bg);
            border-radius: var(--border-radius-sm);
            border: 2px solid var(--accent-color);
        }
        
        .threshold-display h4 {
            color: var(--accent-color);
            margin-bottom: 1rem;
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .threshold-value {
            font-size: 5rem;
            font-weight: 300;
            color: var(--accent-color);
            margin-bottom: 1rem;
            text-shadow: 0 2px 4px rgba(0, 188, 212, 0.2);
        }
        
        /* Success Message Styling */
        .success-message {
            background: #1a1a3a;
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius-sm);
            border-left: 4px solid var(--success-color);
            margin-top: 1rem;
            opacity: 1;
            transition: opacity 0.5s ease-out;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .success-message p {
            color: var(--success-color);
            margin: 0;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        /* Bug/Worm Section Styling */
        .bugworm-card {
            border: none;
            box-shadow: var(--shadow);
            border-radius: var(--border-radius);
            overflow: visible;
            background: var(--card-bg);
            margin-bottom: 2rem;
        }
        
        .bugworm-header {
            background: var(--gradient-primary);
            color: white;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            box-shadow: 0 4px 16px rgba(46, 125, 50, 0.15);
            display: flex;
            align-items: center;
            gap: 1.5rem;
            padding: 2rem;
            border-bottom: 4px solid var(--accent-color);
        }
        
        .bugworm-icon-glow {
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            width: 60px;
            height: 60px;
            box-shadow: 0 0 20px 6px var(--accent-color), 0 4px 12px rgba(46, 125, 50, 0.15);
            font-size: 2.2rem;
            color: white;
        }
        
        .bugworm-header h3 {
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: 0.5px;
            text-shadow: 0 2px 8px rgba(46, 125, 50, 0.2);
        }
        
        .glass-bg {
            background: var(--card-bg);
            backdrop-filter: blur(8px);
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            padding: 2rem;
            margin-bottom: 0.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: center;
        }
        
        .bugworm-select {
            border: 2px solid var(--accent-color);
            border-radius: var(--border-radius-sm);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 1.1rem;
            padding: 1rem 1.5rem 1rem 2.5rem;
            box-shadow: 0 4px 12px rgba(0, 188, 212, 0.2);
            transition: var(--transition);
            background-image: url('data:image/svg+xml;utf8,<svg fill="%2300BCD4" height="20" viewBox="0 0 24 24" width="20" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/></svg>');
            background-repeat: no-repeat;
            background-position: 12px center;
            font-weight: 500;
        }
        
        .bugworm-select:focus {
            border-color: var(--accent-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.2);
        }
        
        .bugworm-btn {
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: var(--border-radius-sm);
            font-weight: 700;
            box-shadow: 0 4px 16px rgba(0, 188, 212, 0.3);
            transition: var(--transition);
            padding: 1rem 2.5rem;
            font-size: 1.1rem;
            position: relative;
            overflow: hidden;
        }
        
        .bugworm-btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 8px 24px rgba(0, 188, 212, 0.4);
        }
        
        .bugworm-result {
            background: var(--card-bg);
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            margin: 0 2rem 2rem 2rem;
            min-height: 80px;
            padding: 2.5rem;
            font-size: 1.1rem;
            border: 2px solid var(--accent-color);
            animation: fadeIn 0.7s;
        }
        
        .bugworm-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: var(--card-bg);
            border-radius: var(--border-radius-sm);
            box-shadow: 0 4px 16px rgba(0,0,0,0.3);
            overflow: hidden;
            margin-bottom: 1.5rem;
            border-left: 6px solid var(--accent-color);
        }
        
        .bugworm-table th {
            background: var(--gradient-primary);
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            padding: 1.2rem 1.5rem;
            border-bottom: 2px solid var(--primary-color);
        }
        
        .bugworm-table td {
            padding: 1rem 1.5rem;
            font-size: 1.05rem;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
        }
        
        .bugworm-table tr:last-child td {
            border-bottom: none;
        }
        
        .bugworm-table tbody tr:hover {
            background: var(--secondary-color);
            transition: var(--transition);
        }
        
        .bugworm-table tfoot td {
            background: var(--card-bg);
            font-weight: bold;
            color: var(--accent-color);
            border-top: 2px solid var(--accent-color);
        }
        
        .bugworm-no-data {
            background: #2a1a1a;
            color: var(--warning-color);
            border-radius: var(--border-radius-sm);
            padding: 1.5rem 2rem;
            font-size: 1.1rem;
            text-align: center;
            box-shadow: 0 4px 12px rgba(255,87,34,0.2);
            margin: 1.5rem 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
        }
        
        .bugworm-no-data i {
            font-size: 1.4rem;
            color: var(--warning-color);
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .container {
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            }
        }

        @media (max-width: 992px) {
            .summary-item strong {
                font-size: 2.5rem;
            }
            .value {
                width: 180px;
                height: 180px;
                font-size: 4rem;
            }
            #sheet1-water-value,
            #sheet2-water-value {
                font-size: 2.2rem;
            }
            .form-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
        }
        
        @media (max-width: 768px) {
            .summary-bar {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .summary-item {
                padding: 1rem;
            }
            
            .summary-item strong {
                font-size: 2.2rem;
            }
            
            .container {
                grid-template-columns: 1fr;
            }
            
            .card {
                margin-bottom: 1rem;
            }
            
            .header h1 {
                font-size: 1.8rem;
            }
            
            .form-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .form-group input,
            .form-group select,
            .form-group button {
                width: 100%;
                margin: 0.5rem 0;
            }
            
            .refresh-time {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .button-group {
                flex-wrap: wrap;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .value { 
                width: 150px; 
                height: 150px; 
                font-size: 3.5rem; 
            }
            .card-header { 
                flex-direction: column; 
                align-items: flex-start; 
                gap: 0.8rem; 
            }
            .card-header i.fa-bug, 
            .card-header i.fa-worm, 
            .card-header i.fa-beetle, 
            .card-header i.fa-tint { 
                margin-bottom: 0.5rem;
            }
            .status-container { 
                align-self: flex-start; 
                margin-left: 0; 
            }
            #sheet1-water-value, 
            #sheet2-water-value { 
                font-size: 2rem; 
            }
            .summary-item strong { 
                font-size: 2rem; 
            }
            .header h1 { 
                font-size: 1.5rem; 
            }
            .threshold-value {
                font-size: 3.5rem;
            }
        }

        /* AI Assistant Modal Styling */
        .ai-assistant-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 70px;
            height: 70px;
            background: var(--gradient-primary);
            border: none;
            border-radius: 50%;
            color: white;
            font-size: 2rem;
            cursor: pointer;
            box-shadow: 0 8px 25px rgba(0, 188, 212, 0.4);
            transition: var(--transition);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse-ai 2s infinite;
        }

        @keyframes pulse-ai {
            0%, 100% { transform: scale(1); box-shadow: 0 8px 25px rgba(0, 188, 212, 0.4); }
            50% { transform: scale(1.1); box-shadow: 0 12px 35px rgba(0, 188, 212, 0.6); }
        }

        .ai-assistant-btn:hover {
            transform: scale(1.15);
            box-shadow: 0 15px 40px rgba(0, 188, 212, 0.6);
        }

        .ai-modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
        }

        .ai-modal-content {
            background: var(--card-bg);
            margin: 5% auto;
            padding: 0;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            border: 1px solid var(--border-color);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .ai-modal-header {
            background: var(--gradient-primary);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .ai-modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .ai-close {
            color: white;
            font-size: 1.8rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
        }

        .ai-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        .ai-modal-body {
            padding: 2rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        .ai-chat-container {
            margin-bottom: 1.5rem;
        }

        .ai-message {
            margin-bottom: 1rem;
            padding: 1rem;
            border-radius: var(--border-radius-sm);
            animation: messageSlideIn 0.3s ease-out;
        }

        @keyframes messageSlideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .ai-message.user {
            background: var(--accent-color);
            color: white;
            margin-left: 2rem;
            border-bottom-right-radius: 5px;
        }

        .ai-message.assistant {
            background: var(--card-bg);
            color: var(--text-color);
            margin-right: 2rem;
            border: 1px solid var(--border-color);
            border-bottom-left-radius: 5px;
        }

        .ai-input-container {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .ai-input {
            flex: 1;
            padding: 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 1rem;
            transition: var(--transition);
        }

        .ai-input:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(0, 188, 212, 0.2);
        }

        .ai-send-btn {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 1rem 1.5rem;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .ai-send-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(46, 125, 50, 0.2);
        }

        .ai-send-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .ai-quick-questions {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        .ai-quick-questions h4 {
            color: var(--accent-color);
            margin-bottom: 1rem;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .ai-quick-btn {
            background: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            padding: 0.8rem 1.2rem;
            border-radius: var(--border-radius-sm);
            cursor: pointer;
            font-size: 0.9rem;
            margin: 0.3rem;
            transition: var(--transition);
            display: inline-block;
        }

        .ai-quick-btn:hover {
            background: var(--accent-color);
            color: white;
            border-color: var(--accent-color);
            transform: translateY(-2px);
        }

        .ai-loading {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            font-style: italic;
        }

        .ai-loading-dots {
            display: flex;
            gap: 0.2rem;
        }

        .ai-loading-dots span {
            width: 6px;
            height: 6px;
            background: var(--accent-color);
            border-radius: 50%;
            animation: loadingDots 1.4s infinite ease-in-out;
        }

        .ai-loading-dots span:nth-child(1) { animation-delay: -0.32s; }
        .ai-loading-dots span:nth-child(2) { animation-delay: -0.16s; }

        @keyframes loadingDots {
            0%, 80%, 100% { transform: scale(0); }
            40% { transform: scale(1); }
        }

        /* Responsive Design for AI Modal */
        @media (max-width: 768px) {
            .ai-modal-content {
                width: 95%;
                margin: 10% auto;
                max-height: 85vh;
            }
            
            .ai-modal-header {
                padding: 1rem 1.5rem;
            }
            
            .ai-modal-body {
                padding: 1.5rem;
            }
            
            .ai-input-container {
                flex-direction: column;
            }
            
            .ai-assistant-btn {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
                bottom: 20px;
                right: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="particles" id="particles"></div>
    <div class="header">
        <h1><i class="fas fa-leaf"></i> ระบบติดตามแมลงศัตรูพืช | Smart Farming Dashboard</h1>
    </div>

    <div class="dashboard">
        <div class="summary-bar">
            <div class="summary-item">
                <strong id="total-pests">0</strong>
                <span class="summary-label">แมลงที่ตรวจพบ (เรียลไทม์)</span>
            </div>
            <div class="summary-item">
                <strong id="total-zones">2</strong>
                <span class="summary-label">จำนวนโซน</span>
            </div>
            <div class="summary-item">
                <strong id="active-alerts">0</strong>
                <span class="summary-label">แจ้งเตือน</span>
            </div>
        </div>

        <div class="data-update-card">
            <div class="data-update-header">
                <i class="fas fa-edit"></i>
                <h3>อัปเดตข้อมูลระบบ</h3>
            </div>
            <div class="data-update-content">
                <div class="form-grid">
                    <div class="form-section">
                        <h4><i class="fas fa-pencil-alt"></i> ป้อนค่าแมลงที่ต้องการอัปเดต</h4>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="form-group">
                                <label for="value">ค่าใหม่ :</label>
                                <input type="number" name="value" id="value" required>
                                <button type="submit">
                                    <i class="fas fa-save"></i> อัปเดต
                                </button>
                            </div>
                            <?php if (!empty($message)): ?>
                                <div id="success-message" class="success-message">
                                    <i class="fas fa-check-circle"></i>
                                    <p>อัปเดตค่าแมลง <?php echo htmlspecialchars($_POST['value']); ?> ตัวสำเร็จ</p>
                                </div>
                                <script>
                                    setTimeout(function() {
                                        const messageDiv = document.getElementById('success-message');
                                        if (messageDiv) {
                                            messageDiv.style.opacity = '0';
                                            setTimeout(function() {
                                                messageDiv.style.display = 'none';
                                            }, 500);
                                        }
                                    }, 3000);
                                </script>
                            <?php endif; ?>
                        </form>
                        
                        <h4 style="margin-top: 2rem;"><i class="fas fa-tint"></i> ระดับน้ำ (โซน A)</h4>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="form-group">
                                <label for="water_level">ระดับน้ำ :</label>
                                <select name="water_level" id="water_level" required>
                                    <option value="">เลือก</option>
                                    <option value="25">25%</option>
                                    <option value="50">50%</option>
                                    <option value="75">75%</option>
                                    <option value="100">100%</option>
                                </select>
                                <button type="submit">
                                    <i class="fas fa-save"></i> อัปเดต
                                </button>
                            </div>
                            <?php if (!empty($water_message)): ?>
                                <div id="water-success-message" class="success-message">
                                    <i class="fas fa-check-circle"></i>
                                    <p>อัปเดตระดับน้ำ <?php echo htmlspecialchars($_POST['water_level']); ?>% (โซน A) สำเร็จ</p>
                                </div>
                                <script>
                                    setTimeout(function() {
                                        const messageDiv = document.getElementById('water-success-message');
                                        if (messageDiv) {
                                            messageDiv.style.opacity = '0';
                                            setTimeout(function() {
                                                messageDiv.style.display = 'none';
                                            }, 500);
                                        }
                                    }, 3000);
                                </script>
                            <?php endif; ?>
                        </form>
                        
                        <h4 style="margin-top: 2rem;"><i class="fas fa-tint"></i> ระดับน้ำ (โซน B)</h4>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <div class="form-group">
                                <label for="water_level_b">ระดับน้ำ :</label>
                                <select name="water_level_b" id="water_level_b" required>
                                    <option value="">เลือก</option>
                                    <option value="25">25%</option>
                                    <option value="50">50%</option>
                                    <option value="75">75%</option>
                                    <option value="100">100%</option>
                                </select>
                                <button type="submit">
                                    <i class="fas fa-save"></i> อัปเดต
                                </button>
                            </div>
                            <?php if (!empty($water_message_b)): ?>
                                <div id="water-success-message-b" class="success-message">
                                    <i class="fas fa-check-circle"></i>
                                    <p>อัปเดตระดับน้ำ <?php echo htmlspecialchars($_POST['water_level_b']); ?>% (โซน B) สำเร็จ</p>
                                </div>
                                <script>
                                    setTimeout(function() {
                                        const messageDiv = document.getElementById('water-success-message-b');
                                        if (messageDiv) {
                                            messageDiv.style.opacity = '0';
                                            setTimeout(function() {
                                                messageDiv.style.display = 'none';
                                            }, 500);
                                        }
                                    }, 3000);
                                </script>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="threshold-display">
                        <h4><i class="fas fa-bug"></i> จำนวนของแมลงที่ต้องการฉีดพ่น</h4>
                        <div class="threshold-value" id="threshold-display-value">
                            <?php echo $thresholdValue; ?>
                        </div>
                        <p style="color: var(--text-muted); font-size: 0.9rem; margin: 0;">
                            ค่าที่กำหนดไว้สำหรับการแจ้งเตือน
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="container">
            <!-- Zone A -->
            <div class="card" id="sheet1-e2-card">
                <div class="card-header">
                    <i class="fas fa-bug"></i>
                    <h3>ตั๊กแตนที่ตรวจพบ</h3>
                    <div class="status-container">
                        <span class="zone-badge">โซน A</span>
                        <span class="card-status">ออนไลน์</span>
                    </div>
                </div>
                <div class="value-container">
                    <div class="value">
                        <span id="sheet1-e2-value" class="empty-value">0</span>
                        <span class="value-icon">🦗</span>
                    </div>
                </div>
                <div class="card-footer">
                    <span>เวลาที่ตรวจพบล่าสุด: <span id="sheet1-I2-value">-</span></span>
                    <span class="trend trend-down"><i class="fas fa-arrow-down"></i> -3 (24 ชม.)</span>
                </div>
            </div>

            <div class="card" id="sheet1-f2-card">
                <div class="card-header">
                    <i class="fas fa-worm"></i>
                    <h3>หนอนที่ตรวจพบ</h3>
                    <div class="status-container">
                        <span class="zone-badge">โซน A</span>
                        <span class="card-status">ออนไลน์</span>
                    </div>
                </div>
                <div class="value-container">
                    <div class="value">
                        <span id="sheet1-f2-value" class="empty-value">0</span>
                        <span class="value-icon">🐛</span>
                    </div>
                </div>
                <div class="card-footer">
                    <span>เวลาที่ตรวจพบล่าสุด: <span id="sheet1-J2-value">-</span></span>
                    <span class="trend"><i class="fas fa-equals"></i> 0 (24 ชม.)</span>
                </div>
            </div>
        </div>
        
        <div class="container">
            <!-- Zone B -->
            <div class="card" id="sheet2-e2-card">
                <div class="card-header">
                    <i class="fas fa-bug"></i>
                    <h3>ตั๊กแตนที่ตรวจพบ</h3>
                    <div class="status-container">
                        <span class="zone-badge">โซน B</span>
                        <span class="card-status">ออนไลน์</span>
                    </div>
                </div>
                <div class="value-container">
                    <div class="value">
                        <span id="sheet2-e2-value" class="empty-value">0</span>
                        <span class="value-icon">🦗</span>
                    </div>
                </div>
                <div class="card-footer">
                    <span>เวลาที่ตรวจพบล่าสุด: <span id="sheet2-I2-value">-</span></span>
                    <span class="trend"><i class="fas fa-equals"></i> 0 (24 ชม.)</span>
                </div>
            </div>

            <div class="card" id="sheet2-f2-card">
                <div class="card-header">
                    <i class="fas fa-worm"></i>
                    <h3>หนอนที่ตรวจพบ</h3>
                    <div class="status-container">
                        <span class="zone-badge">โซน B</span>
                        <span class="card-status">ออนไลน์</span>
                    </div>
                </div>
                <div class="value-container">
                    <div class="value">
                        <span id="sheet2-f2-value" class="empty-value">0</span>
                        <span class="value-icon">🐛</span>
                    </div>
                </div>
                <div class="card-footer">
                    <span>เวลาที่ตรวจพบล่าสุด: <span id="sheet2-J2-value">-</span></span>
                    <span class="trend trend-down"><i class="fas fa-arrow-down"></i> -1 (24 ชม.)</span>
                </div>
            </div>
        </div>
        
        <div class="container">
            <!-- Water Cards -->
            <div class="card" id="sheet1-water-card">
                <div class="card-header">
                    <i class="fas fa-tint"></i>
                    <h3>ปริมาณน้ำ</h3>
                    <div class="status-container">
                        <span class="zone-badge">โซน A</span>
                        <span class="card-status">ออนไลน์</span>
                    </div>
                </div>
                <div class="value-container">
                    <div class="value">
                        <span id="sheet1-water-value" class="empty-value">0</span>
                        <span class="value-icon">💧</span>
                    </div>
                </div>
                <div class="card-footer">
                    <!--<span>อัพเดทล่าสุด: <span id="sheet1-water-time">-</span></span>-->
                </div>
            </div>

            <div class="card" id="sheet2-water-card">
                <div class="card-header">
                    <i class="fas fa-tint"></i>
                    <h3>ปริมาณน้ำ</h3>
                    <div class="status-container">
                        <span class="zone-badge">โซน B</span>
                        <span class="card-status">ออนไลน์</span>
                    </div>
                </div>
                <div class="value-container">
                    <div class="value">
                        <span id="sheet2-water-value" class="empty-value">0</span>
                        <span class="value-icon">💧</span>
                    </div>
                </div>
                <div class="card-footer">
                    <!--<span>อัพเดทล่าสุด: <span id="sheet2-water-time">-</span></span>-->
                </div>
            </div>
        </div>
        
        <div class="refresh-time">
            <span>อัพเดทข้อมูลอัตโนมัติ: <span id="last-update-time">-</span></span>
            <div class="button-group">
                <select id="update-interval" class="interval-selector">
                    <option value="3000">ทุก 3 วินาที</option>
                    <option value="5000">ทุก 5 วินาที</option>
                    <option value="10000" selected>ทุก 10 วินาที</option>
                    <option value="30000">ทุก 30 วินาที</option>
                    <option value="60000">ทุก 1 นาที</option>
                </select>
                <button id="manual-refresh" class="refresh-btn">
                    <i class="fas fa-sync-alt"></i> อัพเดทข้อมูล
                </button>
                <form method="POST" style="display: inline-block;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="download_spray_data" value="1">
                    <button type="submit" class="refresh-btn download-btn">
                        <i class="fas fa-download"></i> บันทึกข้อมูลการฉีดพ่น
                    </button>
                </form>
            </div>
        </div>



        <!-- Bug/Worm Daily Detail Section (Modern UI) -->
        <div class="card bugworm-card">
          <div class="bugworm-header">
            <span class="bugworm-icon-glow">
              <i class="fas fa-search"></i>
            </span>
            <h3>ดูข้อมูลตั๊กแตนและหนอนแยกตามวัน</h3>
          </div>
          <div class="bugworm-controls glass-bg">
            <select id="bugworm-zone-selector" class="bugworm-select">
              <option value="Sheet1">โซน A</option>
              <option value="Sheet2">โซน B</option>
            </select>
            <select id="bugworm-date-dropdown" class="bugworm-select">
              <option value="">เลือกวันที่</option>
            </select>
            <button id="bugworm-search-btn" class="bugworm-btn">
              <i class="fas fa-search"></i> ดูข้อมูล
            </button>
          </div>
          <div id="bugworm-detail-result" class="bugworm-result"></div>
        </div>

        <!-- Insect Count Chart Card (NEW) -->
        <div class="card bg-white rounded-lg shadow p-6 mt-6">
          <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-bold">จำนวนแมลงที่พบในแต่ละช่วงเวลา</h2>
            <select id="insect-chart-mode" class="border rounded px-2 py-1">
              <option value="day">รายวัน</option>
              <option value="month">รายเดือน</option>
              <option value="year">รายปี</option>
            </select>
          </div>
          <div class="w-full h-96">
            <canvas id="insect-count-chart"></canvas>
          </div>
          <div id="insect-chart-legend" class="mt-4 flex flex-wrap gap-4"></div>
        </div>
    </div>

    <div class="footer">
        © <span id="current-year"></span> ระบบติดตามแมลงศัตรูพืช | Smart Farming Dashboard
    </div>

    <!-- AI Assistant Button -->
    <button class="ai-assistant-btn" id="ai-assistant-btn" title="AI Assistant - ปรึกษาเกี่ยวกับระบบและวิธีการป้องกันแมลง">
        <i class="fas fa-robot"></i>
    </button>

    <!-- AI Assistant Modal -->
    <div class="ai-modal" id="ai-modal">
        <div class="ai-modal-content">
            <div class="ai-modal-header">
                <h3>
                    <i class="fas fa-robot"></i>
                    AI Assistant - ผู้ช่วยอัจฉริยะ
                </h3>
                <span class="ai-close" id="ai-close">&times;</span>
            </div>
            <div class="ai-modal-body">
                <div class="ai-chat-container" id="ai-chat-container">
                    <div class="ai-message assistant">
                        <strong>สวัสดีครับ! 👋</strong><br>
                        ผมเป็น AI Assistant ที่จะช่วยตอบคำถามเกี่ยวกับระบบติดตามแมลงศัตรูพืช และให้คำแนะนำเกี่ยวกับวิธีการป้องกันและจัดการแมลงศัตรูพืชครับ
                        <br><br>
                        คุณสามารถถามเกี่ยวกับ:
                        <ul style="margin-top: 0.5rem; padding-left: 1.5rem;">
                            <li>วิธีการใช้งานระบบ</li>
                            <li>การป้องกันแมลงศัตรูพืช</li>
                            <li>วิธีการจัดการเมื่อพบแมลง</li>
                            <li>คำแนะนำการเกษตร</li>
                        </ul>
                    </div>
                </div>
                
                <div class="ai-quick-questions">
                    <h4><i class="fas fa-lightbulb"></i> คำถามที่พบบ่อย</h4>
                    <button class="ai-quick-btn" data-question="วิธีการใช้งานระบบติดตามแมลง">วิธีการใช้งานระบบ</button>
                    <button class="ai-quick-btn" data-question="วิธีป้องกันตั๊กแตน">ป้องกันตั๊กแตน</button>
                    <button class="ai-quick-btn" data-question="วิธีป้องกันหนอน">ป้องกันหนอน</button>
                    <button class="ai-quick-btn" data-question="เมื่อพบแมลงเกินเกณฑ์ควรทำอย่างไร">จัดการเมื่อพบแมลงเกินเกณฑ์</button>
                    <button class="ai-quick-btn" data-question="วิธีการฉีดพ่นที่ปลอดภัย">การฉีดพ่นที่ปลอดภัย</button>
                    <button class="ai-quick-btn" data-question="วิธีจัดการน้ำในระบบ">จัดการน้ำในระบบ</button>
                </div>

                <div class="ai-input-container">
                    <input type="text" class="ai-input" id="ai-input" placeholder="พิมพ์คำถามของคุณที่นี่..." maxlength="500">
                    <button class="ai-send-btn" id="ai-send-btn">
                        <i class="fas fa-paper-plane"></i>
                        ส่ง
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Simple AI Assistant Test
        document.addEventListener('DOMContentLoaded', function() {
            console.log('AI Assistant loading...');
            
            const aiBtn = document.getElementById('ai-assistant-btn');
            const aiModal = document.getElementById('ai-modal');
            const aiClose = document.getElementById('ai-close');
            
            if (aiBtn && aiModal && aiClose) {
                console.log('AI elements found, adding event listeners...');
                
                // Open modal
                aiBtn.addEventListener('click', function() {
                    console.log('AI button clicked!');
                    aiModal.style.display = 'block';
                });
                
                // Close modal
                aiClose.addEventListener('click', function() {
                    aiModal.style.display = 'none';
                });
                
                // Close modal when clicking outside
                aiModal.addEventListener('click', function(e) {
                    if (e.target === aiModal) {
                        aiModal.style.display = 'none';
                    }
                });
                
                console.log('AI Assistant ready!');
            } else {
                console.error('AI elements not found!');
            }
        });
    </script>
</body>
</html>
