<?php
$artPath = 'C:/Users/wgdag/.gemini/antigravity/brain/f1f89816-943b-482c-88e9-745503a7c0ec/user_manual_and_guide.md';
$mdContent = file_get_contents($artPath);

file_put_contents(__DIR__ . '/../downloads/NIS_PPMS_User_Manual_and_Operational_Guide.md', $mdContent);

// Simple HTML wrapper for web/print view
$html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>NIS-PPMS Official Master User Manual & Operational Guide v2.4</title>
    <style>
        body { font-family: "Segoe UI", Arial, sans-serif; line-height: 1.6; color: #1e293b; max-width: 950px; margin: 0 auto; padding: 2rem; background: #fff; }
        h1 { color: #1a5632; border-bottom: 3px solid #1a5632; padding-bottom: 0.5rem; }
        h2 { color: #0284c7; margin-top: 1.5rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.3rem; }
        h3 { color: #334155; }
        pre, code { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.2rem 0.4rem; font-family: monospace; font-size: 0.85rem; }
        pre { padding: 1rem; overflow-x: auto; background: #0f172a; color: #38bdf8; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.85rem; }
        th, td { border: 1px solid #cbd5e1; padding: 0.65rem 0.85rem; text-align: left; }
        th { background: #f1f5f9; color: #1e293b; font-weight: 700; }
        tr:nth-child(even) { background: #f8fafc; }
        .alert { background: #fef2f2; border-left: 4px solid #ef4444; padding: 1rem; margin: 1rem 0; border-radius: 6px; color: #991b1b; }
        @media print {
            body { padding: 0; margin: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 1.5rem; text-align: right;">
        <button onclick="window.print()" style="padding: 0.6rem 1.25rem; background: #1a5632; color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">🖨️ Print / Download PDF</button>
    </div>
    ' . nl2br(htmlspecialchars($mdContent)) . '
</body>
</html>';

file_put_contents(__DIR__ . '/../downloads/NIS_PPMS_User_Manual_and_Operational_Guide.html', $html);
echo "DOWNLOAD MANUALS UPDATED SUCCESSFULLY FROM ARTIFACT PATH!\n";
