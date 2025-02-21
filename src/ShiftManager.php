<?php

namespace ParimIntegration;

class ShiftManager
{
    private $db;
    private $api;
    private $logger;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->api = new ParimAPI();
        $this->logger = Logger::getLogger('shifts');
    }

    public function saveShift(array $shiftData): void
    {
        if (!isset($shiftData['shift_uuid'])) {
            $this->logger->error('Invalid shift data: missing uuid', [
                'shift_data' => $shiftData
            ]);
            throw new \InvalidArgumentException('Shift data must contain a uuid');
        }

        $this->logger->info('Saving shift', [
            'uuid' => $shiftData['shift_uuid'],
            'has_clock_in' => isset($shiftData['actual_clock_in']),
            'clock_in_time' => $shiftData['actual_clock_in'] ?? 'none'
        ]);

        try {
            $stmt = $this->db->getPdo()->prepare(
                "INSERT INTO shifts (shift_uuid, actual_clock_in, raw_data) 
                 VALUES (:uuid, :clock_in, :raw_data)
                 ON DUPLICATE KEY UPDATE 
                 actual_clock_in = :clock_in,
                 raw_data = :raw_data"
            );

            $rawData = json_encode($shiftData);
            if ($rawData === false) {
                throw new \RuntimeException('Failed to encode shift data as JSON: ' . json_last_error_msg());
            }

            $params = [
                'uuid' => $shiftData['shift_uuid'],
                'clock_in' => $shiftData['actual_clock_in'] ?? null,
                'raw_data' => $rawData
            ];

            $stmt->execute($params);

            $this->logger->debug('Shift saved successfully', [
                'uuid' => $shiftData['shift_uuid'],
                'affected_rows' => $stmt->rowCount()
            ]);
        } catch (\PDOException $e) {
            $this->logger->error('Database error while saving shift', [
                'uuid' => $shiftData['shift_uuid'],
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            throw new \RuntimeException('Failed to save shift: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getUnprocessedShiftsWithClockIn(): array
    {
        $stmt = $this->db->getPdo()->prepare(
            "SELECT * FROM shifts 
             WHERE processed = FALSE 
             AND actual_clock_in IS NOT NULL"
        );
        
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function markShiftAsProcessed(string $shiftUuid): void
    {
        $stmt = $this->db->getPdo()->prepare(
            "UPDATE shifts 
             SET processed = TRUE 
             WHERE shift_uuid = ?"
        );
        
        $stmt->execute([$shiftUuid]);
    }

    public function processNewShifts(string $startDate, string $endDate): void
    {
        $this->logger->info('Starting to process new shifts', [
            'startDate' => $startDate,
            'endDate' => $endDate
        ]);

        $shifts = $this->api->fetchShifts($startDate, $endDate);
        
        $this->logger->info('Retrieved shifts from API', [
            'count' => count($shifts)
        ]);
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($shifts as $index => $shift) {
            try {
                $this->saveShift($shift);
                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $this->logger->error('Failed to save shift', [
                    'index' => $index,
                    'error' => $e->getMessage(),
                    'shift' => $shift
                ]);
            }
        }
        
        $this->logger->info('Finished processing shifts', [
            'total' => count($shifts),
            'success' => $successCount,
            'errors' => $errorCount
        ]);
    }
} 