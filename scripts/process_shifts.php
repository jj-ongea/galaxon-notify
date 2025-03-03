<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ParimIntegration\ShiftManager;
use ParimIntegration\Logger;

$logger = Logger::getLogger('process-script');
$shiftManager = new ShiftManager();

$logger->info('Starting to process shifts with clock-ins');

try {
    $shifts = $shiftManager->getUnprocessedShiftsWithClockIn();
    
    $logger->info('Found unprocessed shifts', ['count' => count($shifts)]);
    
    foreach ($shifts as $shift) {
        $logger->info('Processing shift', ['uuid' => $shift['shift_uuid']]);
        try {
            $shiftManager->sendClockInEmail($shift);
        } catch (\Exception $e) {
            $logger->error('Failed to process shift email', [
                'uuid' => $shift['shift_uuid'],
                'error' => $e->getMessage()
            ]);
            continue;
        }
        
        $shiftManager->markShiftAsProcessed($shift['shift_uuid']);
    }
    
    $logger->info('Successfully processed all shifts', ['count' => count($shifts)]);
} catch (Exception $e) {
    $logger->error('Error processing shifts', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    exit(1);
} 