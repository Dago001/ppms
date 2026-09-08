<?php
// error.php - Custom Error Pages
$code = intval($_GET['code'] ?? 500);
$messages = [
    400 => 'Bad Request',
    401 => 'Unauthorized',
    403 => 'Access Denied',
    404 => 'Page Not Found',
    500 => 'Internal Server Error',
    503 => 'Service Unavailable'
];
$message = $messages[$code] ?? 'Error';

http_response_code($code);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $code; ?> - <?php echo $message; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh}
        .error-container{text-align:center;padding:2rem}
        .error-code{font-size:6rem;font-weight:700;color:#1a5632;line-height:1}
        .error-message{font-size:1.5rem;color:#1e293b;margin:1rem 0}
        .error-description{font-size:0.9rem;color:#64748b;margin-bottom:2rem}
        .back-btn{display:inline-flex;align-items:center;gap:.5rem;background:#1a5632;color:#fff;padding:.75rem 1.5rem;border-radius:8px;text-decoration:none;font-weight:500}
        .back-btn:hover{background:#1f6b3e}
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code"><?php echo $code; ?></div>
        <div class="error-message"><i class="fas fa-exclamation-triangle"></i> <?php echo $message; ?></div>
        <div class="error-description">
            <?php if ($code === 404): ?>
                The page you're looking for doesn't exist or has been moved.
            <?php elseif ($code === 403): ?>
                You don't have permission to access this resource.
            <?php elseif ($code === 500): ?>
                Something went wrong on our end. Please try again later.
            <?php else: ?>
                An error occurred while processing your request.
            <?php endif; ?>
        </div>
        <a href="dashboard.php" class="back-btn"><i class="fas fa-home"></i> Back to Dashboard</a>
    </div>
</body>
</html>