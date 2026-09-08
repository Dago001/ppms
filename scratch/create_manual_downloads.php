<?php
// scratch/create_manual_downloads.php
$mdPath = 'C:\\Users\\wgdag\\.gemini\\antigravity\\brain\\f1f89816-943b-482c-88e9-745503a7c0ec\\user_manual_and_guide.md';
$destDir = 'c:\\xampp\\htdocs\\nisposting\\downloads\\';

if (!file_exists($destDir)) {
    mkdir($destDir, 0777, true);
}

// 1. Copy Markdown file
$mdContent = file_get_contents($mdPath);
file_put_contents($destDir . 'NIS_PPMS_User_Manual_and_Operational_Guide.md', $mdContent);

// 2. Generate styled HTML document for PDF/Browser Download
$htmlHeader = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIS-PPMS Official Operational User Manual & Guide</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @page { size: A4; margin: 20mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", Arial, sans-serif; line-height: 1.6; color: #1e293b; background: #f8fafc; padding: 2rem; }
        .manual-container { max-width: 900px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 3rem; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; position: relative; }
        .header-box { text-align: center; border-bottom: 3px double #1a5632; padding-bottom: 1.5rem; margin-bottom: 2rem; }
        .header-box img { height: 85px; margin-bottom: 0.5rem; }
        .header-box h1 { color: #1a5632; font-size: 1.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .header-box h2 { color: #0f172a; font-size: 1.2rem; font-weight: 600; margin-top: 0.2rem; }
        .header-box p { color: #d97706; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; margin-top: 0.5rem; }
        
        .toolbar { display: flex; justify-content: space-between; align-items: center; background: #f1f5f9; padding: 0.75rem 1.25rem; border-radius: 8px; margin-bottom: 2rem; border: 1px solid #cbd5e1; }
        .toolbar-title { font-size: 0.85rem; font-weight: 600; color: #334155; }
        .toolbar-btns { display: flex; gap: 0.5rem; }
        .btn-download { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; background: #1a5632; color: #fff; text-decoration: none; border-radius: 6px; font-size: 0.8rem; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-download:hover { background: #145226; }
        .btn-secondary { background: #475569; }
        .btn-secondary:hover { background: #334155; }

        h2 { color: #1a5632; font-size: 1.3rem; margin-top: 2rem; margin-bottom: 0.8rem; border-left: 4px solid #1a5632; padding-left: 0.6rem; }
        h3 { color: #0f172a; font-size: 1.05rem; margin-top: 1.2rem; margin-bottom: 0.5rem; }
        p, li { font-size: 0.9rem; color: #334155; margin-bottom: 0.75rem; }
        ul, ol { margin-left: 1.5rem; margin-bottom: 1rem; }
        li { margin-bottom: 0.4rem; }
        
        table { width: 100%; border-collapse: collapse; margin: 1.2rem 0; font-size: 0.85rem; }
        th, td { border: 1px solid #cbd5e1; padding: 0.65rem 0.85rem; text-align: left; }
        th { background: #f8fafc; color: #1a5632; font-weight: 600; }
        tr:nth-child(even) { background: #f8fafc; }
        
        code { background: #f1f5f9; color: #0f172a; padding: 0.2rem 0.4rem; border-radius: 4px; font-family: monospace; font-size: 0.85rem; }
        pre { background: #0f172a; color: #f8fafc; padding: 1rem; border-radius: 8px; overflow-x: auto; font-size: 0.8rem; margin: 1rem 0; }
        
        .alert-box { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 0.85rem 1.1rem; border-radius: 6px; margin: 1.2rem 0; font-size: 0.85rem; color: #92400e; }
        .alert-box.danger { background: #fef2f2; border-color: #ef4444; color: #991b1b; }
        
        @media print {
            body { padding: 0; background: #fff; }
            .manual-container { box-shadow: none; border: none; padding: 0; }
            .toolbar { display: none !important; }
        }
    </style>
</head>
<body>
<div class="manual-container">
    <div class="toolbar">
        <div class="toolbar-title"><i class="fas fa-file-pdf"></i> NIS-PPMS Operational Manual (v2.4)</div>
        <div class="toolbar-btns">
            <button onclick="window.print();" class="btn-download"><i class="fas fa-print"></i> Print / Save as PDF</button>
            <a href="NIS_PPMS_User_Manual_and_Operational_Guide.md" download class="btn-download btn-secondary"><i class="fas fa-file-download"></i> Download Markdown (.md)</a>
        </div>
    </div>
    
    <div class="header-box">
        <img src="../assets/images/logo.png" alt="NIS Logo" onerror="this.src=\'../assets/images/logo2.png\';">
        <h1>Nigeria Immigration Service</h1>
        <h2>Personnel Posting Management System (NIS-PPMS)</h2>
        <p>Official Operational User Manual & Guide</p>
    </div>
';

// Parse basic markdown to HTML
$parsedHtml = $mdContent;

// Remove Frontmatter / Title blocks since header-box renders it
$parsedHtml = preg_replace('/^# Nigeria Immigration Service.*?\n---/s', '', $parsedHtml);

// Alerts
$parsedHtml = preg_replace('/> \[!IMPORTANT\]\s*\n>\s*(.*?)(?=\n\n|\n[^\>]|$)/s', '<div class="alert-box danger"><strong>IMPORTANT NOTICE:</strong> $1</div>', $parsedHtml);
$parsedHtml = preg_replace('/> \[!WARNING\]\s*\n>\s*(.*?)(?=\n\n|\n[^\>]|$)/s', '<div class="alert-box danger"><strong>WARNING:</strong> $1</div>', $parsedHtml);
$parsedHtml = preg_replace('/> \[!TIP\]\s*\n>\s*(.*?)(?=\n\n|\n[^\>]|$)/s', '<div class="alert-box"><strong>TIP:</strong> $1</div>', $parsedHtml);

// Headers
$parsedHtml = preg_replace('/^### (.*?)$/m', '<h3>$1</h3>', $parsedHtml);
$parsedHtml = preg_replace('/^## (.*?)$/m', '<h2>$1</h2>', $parsedHtml);

// Bold / Code
$parsedHtml = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $parsedHtml);
$parsedHtml = preg_replace('/`([^`]+)`/', '<code>$1</code>', $parsedHtml);

// Lists
$parsedHtml = preg_replace('/^\* (.*?)$/m', '<li>$1</li>', $parsedHtml);
$parsedHtml = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $parsedHtml);

// Tables
$parsedHtml = preg_replace_callback('/\|(.+)\|/m', function($matches) {
    $cols = array_map('trim', explode('|', trim($matches[1])));
    if (strpos($cols[0], '---') !== false) return '';
    $isHeader = false;
    static $inTable = false;
    $rowHtml = '<tr>';
    foreach ($cols as $col) {
        $rowHtml .= "<td>$col</td>";
    }
    $rowHtml .= '</tr>';
    return $rowHtml;
}, $parsedHtml);

$htmlFooter = '
</div>
</body>
</html>';

file_put_contents($destDir . 'NIS_PPMS_User_Manual_and_Operational_Guide.html', $htmlHeader . $parsedHtml . $htmlFooter);

echo "Manual downloads created successfully in " . $destDir . "\n";
