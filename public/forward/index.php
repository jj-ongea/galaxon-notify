<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use ParimIntegration\Database;
use ParimIntegration\Logger;

$logger = Logger::getLogger('forward-page');
$db = Database::getInstance();

$token = $_GET['token'] ?? '';
$action = $_POST['action'] ?? '';
$email = $_POST['email'] ?? '';

$shift = null;
$error = null;
$success = null;

if ($token) {
    $stmt = $db->getPdo()->prepare(
        "SELECT s.*, NOW() < s.forward_expires_at as is_valid 
         FROM shifts s 
         WHERE s.forward_token = ?"
    );
    $stmt->execute([$token]);
    $shift = $stmt->fetch();

    if (!$shift) {
        $error = "Invalid or expired link";
    } else if (!$shift['is_valid']) {
        $error = "This link has expired";
    }
}

if ($action === 'forward' && $shift && $shift['is_valid']) {
    // Process forwarding logic here
    $success = "Email forwarded successfully!";
}

$shiftData = $shift ? json_decode($shift['raw_data'], true) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forward Clock-in Notification</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <img src="https://galaxon.co.uk/Galaxon%20Email/galaxon-symbol-01.png" alt="Galaxon Logo" class="logo">
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php elseif ($success): ?>
            <div class="success">
                <?= htmlspecialchars($success) ?>
                <div class="timer">Redirecting in <span id="countdown">30</span> seconds...</div>
            </div>
        <?php elseif ($shiftData): ?>
            <h1>Forward Clock-in Notification</h1>
            
            <div class="shift-details">
                <h2>Shift Details</h2>
                <p><strong>Employee:</strong> <?= htmlspecialchars($shiftData['user_name']) ?></p>
                <p><strong>Venue:</strong> <?= htmlspecialchars($shiftData['venue_name']) ?></p>
                <p><strong>Clock-in:</strong> <?= date('jS F Y h:ia', $shiftData['actual_clock_in']) ?></p>
                <p><strong>Shift Time:</strong> <?= htmlspecialchars($shiftData['time_from'] . ' - ' . $shiftData['time_to']) ?></p>
            </div>

            <form method="post" class="forward-form" id="forwardForm">
                <input type="hidden" name="action" value="forward">
                <div class="form-group">
                    <label for="email">Forward to Email:</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <button type="submit" class="btn-forward">Forward Notification</button>
            </form>

            <div class="timer">This link will expire in <span id="countdown">30</span> seconds</div>
        <?php endif; ?>
    </div>

    <script>
        let timeLeft = 30;
        const countdownElement = document.getElementById('countdown');
        
        const countdown = setInterval(() => {
            timeLeft--;
            countdownElement.textContent = timeLeft;
            
            if (timeLeft <= 0) {
                clearInterval(countdown);
                window.location.href = '/';
            }
        }, 1000);
    </script>
</body>
</html> 