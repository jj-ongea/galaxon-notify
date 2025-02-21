<?php

namespace ParimIntegration;

class ShiftManager
{
    private $db;
    private $api;
    private $logger;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->api = new ParimAPI();
        $this->logger = Logger::getLogger('shifts');
        $this->config = Config::getInstance();
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
                'clock_in' => isset($shiftData['actual_clock_in']) ? 
                    date('Y-m-d H:i:s', $shiftData['actual_clock_in']) : null,
                'raw_data' => $rawData
            ];

            $this->logger->debug('Executing SQL', [
                'params' => $params,
                'actual_clock_in_exists' => isset($shiftData['actual_clock_in']),
                'actual_clock_in_value' => $shiftData['actual_clock_in'] ?? 'null',
                'actual_clock_in_formatted' => isset($shiftData['actual_clock_in']) ? 
                    date('Y-m-d H:i:s', $shiftData['actual_clock_in']) : 'null'
            ]);

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
            'count' => count($shifts),
            'first_shift' => !empty($shifts) ? [
                'shift_uuid' => $shifts[0]['shift_uuid'] ?? null,
                'actual_clock_in' => $shifts[0]['actual_clock_in'] ?? null,
                'raw' => $shifts[0]
            ] : null
        ]);
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($shifts as $index => $shift) {
            try {
                $this->logger->debug('Processing shift', [
                    'shift_uuid' => $shift['shift_uuid'] ?? null,
                    'actual_clock_in' => $shift['actual_clock_in'] ?? null,
                    'raw' => $shift
                ]);
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

    public function sendClockInEmail(array $shiftData): void
    {
        $rawData = json_decode($shiftData['raw_data'], true);
        
        $forwardToken = bin2hex(random_bytes(16)); // Generate unique token
        
        // Store token in database for verification
        $stmt = $this->db->getPdo()->prepare(
            "UPDATE shifts 
             SET forward_token = ?, forward_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
             WHERE shift_uuid = ?"
        );
        $stmt->execute([$forwardToken, $shiftData['shift_uuid']]);
        
        $emailData = [
            'replyTo' => [
                'email' => 'iclark@galaxon.co.uk',
                'name' => 'Ian'
            ],
            'params' => [
                'employee_name' => $rawData['user_name'],
                'venue_name' => $rawData['venue_name'],
                'clock_in' => (new \DateTime())->setTimestamp($rawData['actual_clock_in'])->format('jS F Y h:ia'),
                'shift' => (new \DateTime($rawData['time_from']))->format('jS F Y h:ia') . ' - ' . (new \DateTime($rawData['time_to']))->format('h:ia'),
                'status' => (function() use ($rawData) {
                    $clockInTime = $rawData['actual_clock_in'];
                    $startTime = strtotime($rawData['time_from']);
                    
                    $diffMinutes = round(($clockInTime - $startTime) / 60);
                    
                    if ($diffMinutes < 0) {
                        return abs($diffMinutes) . ' minutes early';
                    } else if ($diffMinutes > 0) {
                        return $diffMinutes . ' minutes late'; 
                    }
                    return 'on time';
                })(),
                'link' => 'https://galaxon.co.uk/notify/public/forward/?token=' . $forwardToken
            ],
            'to' => [
                [
                    //'email' => 'info@galaxon.co.uk',
                    'email' => 'jj@ongea.co',
                    'name' => 'Control Room'
                ]
            ],
            'subject' => $rawData['user_name'] . ' has clocked in at ' . $rawData['venue_name'] . ' for shift starting at ' . (new \DateTime($rawData['time_from']))->format('h:ia'),
            'templateId' => 511
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . $this->config->get('BREVO_API_KEY'),
                'content-type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($emailData)
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $statusCode !== 201) {
            $this->logger->error('Failed to send clock-in email', [
                'error' => $error,
                'statusCode' => $statusCode,
                'response' => $response,
                'shift_uuid' => $shiftData['shift_uuid']
            ]);
            throw new \RuntimeException('Failed to send clock-in email');
        }

        $this->logger->info('Clock-in email sent successfully', [
            'shift_uuid' => $shiftData['shift_uuid']
        ]);
    }

    public function forwardClockInEmail(array $shiftData, string $forwardEmail): void
    {
        if (empty($forwardEmail)) {
            $this->logger->error('Forward email is empty');
            throw new \InvalidArgumentException('Forward email address is required');
        }

        $rawData = json_decode($shiftData['raw_data'], true);
        
        $this->logger->info('Starting to forward email', [
            'shift_uuid' => $shiftData['shift_uuid'],
            'forward_email' => $forwardEmail,
            'controller' => $_POST['controller'] ?? 'Unknown'
        ]);
        
        // Determine time of day greeting
        $hour = (int)date('H');
        $timeOfDay = match(true) {
            $hour >= 0 && $hour < 12 => 'Good Morning',
            $hour >= 12 && $hour < 17 => 'Good Afternoon',
            default => 'Good Evening'
        };
        
        $emailData = [
            'replyTo' => [
                'email' => 'info@galaxon.co.uk',
                'name' => 'Galaxon'
            ],
            'params' => [
                'employee_name' => $rawData['user_name'],
                'venue_name' => $rawData['venue_name'],
                'clock_time' => 
                    (new \DateTime())->setTimestamp($rawData['actual_clock_in'])->format('g:ia'),
                'clock_date' => (new \DateTime())->setTimestamp($rawData['actual_clock_in'])->format('jS F Y'),
                'shift' => (new \DateTime($rawData['time_from']))->format('jS F Y h:ia') . ' - ' . (new \DateTime($rawData['time_to']))->format('h:ia'),
                'daytime' => $timeOfDay,
                'controller' => $_POST['controller'] ?? 'Unknown'
            ],
            'to' => [
                [
                    'email' => $forwardEmail,
                    'name' => 'Recipient'
                ]
            ],
            'subject' => 'Book on confirmation for ' . (new \DateTime($rawData['time_from']))->format('H:i') . ' at ' . $rawData['venue_name'],
            'templateId' => 512
        ];

        $this->logger->debug('Email data prepared', [
            'emailData' => $emailData
        ]);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . $this->config->get('BREVO_API_KEY'),
                'content-type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($emailData)
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $statusCode !== 201) {
            $this->logger->error('Failed to forward email', [
                'error' => $error,
                'statusCode' => $statusCode,
                'response' => $response,
                'shift_uuid' => $shiftData['shift_uuid']
            ]);
            throw new \RuntimeException('Failed to forward email: ' . ($error ?: $response));
        }

        $this->logger->info('Email forwarded successfully', [
            'shift_uuid' => $shiftData['shift_uuid'],
            'forward_email' => $forwardEmail
        ]);

        // Update the database to record the forward
        $stmt = $this->db->getPdo()->prepare(
            "UPDATE shifts 
             SET forward_email = ?, 
                 forwarded_at = NOW() 
             WHERE shift_uuid = ?"
        );
        $stmt->execute([$forwardEmail, $shiftData['shift_uuid']]);
    }
} 