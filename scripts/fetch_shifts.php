<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ParimIntegration\ShiftManager;
use ParimIntegration\Logger;

$logger = Logger::getLogger('fetch-script');
$shiftManager = new ShiftManager();

// Get today's date at midnight as start date
$startDate = date('Y-m-d 00:00:00');
$startTimestamp = strtotime($startDate);

// Set end date to 23:59:00 of the same day
$endDate = date('Y-m-d 23:59:00');
$endTimestamp = strtotime($endDate);

$logger->info('Starting shift fetch', [
    'startDate' => $startDate,
    'endDate' => $endDate
]);

try {
    $shiftManager->processNewShifts($startTimestamp, $endTimestamp);
    $logger->info('Successfully processed shifts');
} catch (Exception $e) {
    $logger->error('Error processing shifts', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    exit(1);
} 