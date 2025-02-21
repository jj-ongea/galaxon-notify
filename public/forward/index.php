<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use ParimIntegration\Database;
use ParimIntegration\Logger;
use ParimIntegration\ShiftManager;
use ParimIntegration\Config;

$logger = Logger::getLogger('forward-page');
$db = Database::getInstance();

$token = $_GET['token'] ?? '';
$action = $_POST['action'] ?? '';
$email = $_POST['email'] ?? '';
$confirm = $_POST['confirm'] ?? false;

$shift = null;
$error = null;
$success = null;
$pending = null;

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

if ($action === 'forward' && $shift && $shift['is_valid'] && !$confirm) {
    $pending = true;
} else if ($action === 'forward' && $shift && $shift['is_valid'] && $confirm) {
    try {
        $shiftManager = new ShiftManager();
        $shiftManager->forwardClockInEmail($shift, $email);
        $success = "Email has been forwarded successfully to " . htmlspecialchars($email);
    } catch (\Exception $e) {
        $logger->error('Failed to forward email', [
            'error' => $e->getMessage(),
            'shift_uuid' => $shift['shift_uuid'],
            'email' => $email
        ]);
        $error = "Failed to forward email. Please try again later.";
    }
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
                <h2>Success!</h2>
                <?= htmlspecialchars($success) ?>
                <p class="success-details">The notification has been forwarded to the specified email address.</p>
                <div class="check-mark">✓</div>
            </div>
        <?php elseif ($pending): ?>
            <div class="pending">
                <h2>Forwarding to: <?= htmlspecialchars($email) ?></h2>
                <p>This action will complete in <span id="countdown">30</span> seconds</p>
                <form method="post" class="undo-form">
                    <button type="button" class="btn-undo" onclick="window.location.href='?token=<?= htmlspecialchars($token) ?>'">Cancel</button>
                </form>
                <form method="post" id="confirmForm" style="display: none;">
                    <input type="hidden" name="action" value="forward">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                    <input type="hidden" name="controller" value="<?= htmlspecialchars($_POST['controller'] ?? '') ?>">
                    <input type="hidden" name="confirm" value="1">
                </form>
            </div>
        <?php elseif ($shiftData): ?>
            <h1>Forward Clock-in Notification</h1>
            
            <div class="shift-details">
                <h2>Shift Details</h2>
                <p><strong>Employee:</strong> <?= htmlspecialchars($shiftData['user_name']) ?></p>
                <p><strong>Venue:</strong> <?= htmlspecialchars($shiftData['venue_name']) ?></p>
                <p><strong>Clock-in:</strong> <?= date('jS F Y h:ia', $shiftData['actual_clock_in']) ?></p>
                <p><strong>Shift Time:</strong> <?= (new DateTime($shiftData['time_from']))->format('jS F Y h:ia') . ' - ' . (new DateTime($shiftData['time_to']))->format('jS F Y h:ia') ?></p>
            </div>

            <form method="post" class="forward-form" id="forwardForm">
                <input type="hidden" name="action" value="forward">
                <div class="form-group">
                    <label for="email">Forward to Email:</label>
                    <input type="email" id="email" name="email" required disabled
                           value="<?= htmlspecialchars(Config::getInstance()->get('DEFAULT_FORWARD_EMAIL')) ?>">
                </div>
                <div class="form-group">
                    <label for="controller">Controller:</label>
                    <select id="controller" name="controller" required class="form-control">
                        <option value="">Select Controller</option>
                        <option value="Cornelius">Cornelius</option>
                        <option value="Muna">Muna</option>
                        <option value="Jude">Jude</option>
                        <option value="John">John</option>
                        <option value="Asif">Asif</option>
                        <option value="Rasheel">Rasheel</option>
                    </select>
                </div>
                <button type="submit" class="btn-forward">Forward Notification</button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Only start countdown if we're in pending state
        <?php if ($pending): ?>
        let timeLeft = 15;
        const countdownElement = document.getElementById('countdown');
        const confirmForm = document.getElementById('confirmForm');
        
        const countdown = setInterval(() => {
            timeLeft--;
            countdownElement.textContent = timeLeft;
            
            if (timeLeft <= 0) {
                clearInterval(countdown);
                confirmForm.submit();
            }
        }, 1000);
        <?php endif; ?>
    </script>
</body>
</html> 