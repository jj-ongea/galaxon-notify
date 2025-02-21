<?php

namespace ParimIntegration;

use Exception;
use Monolog\Logger;

class ParimAPI
{
    private $config;
    private $db;
    private $baseUrl;
    private $logger;

    public function __construct()
    {
        $this->config = Config::getInstance();
        $this->db = Database::getInstance();
        $this->baseUrl = "https://" . $this->config->get('PARIM_TEAMNAME') . ".parim.co";
        $this->logger = Logger::getLogger('api');
    }

    private function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function getAuthHeaders(string $nonce, int $timestamp): array
    {
        $signatureData = $timestamp . ':' . $nonce;
        $signature = sha1($this->config->get('PARIM_PRIVATE_API_KEY') . ':' . $signatureData);

        return [
            'x-auth-parim-key: ' . $this->config->get('PARIM_PUBLIC_API_KEY'),
            'x-auth-parim-timestamp: ' . $timestamp,
            'x-auth-parim-nonce: ' . $nonce,
            'x-auth-parim-signature: ' . $signature,
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . $this->config->get('PARIM_BASIC_AUTH')
        ];
    }

    private function makeRequest(string $endpoint, array $params = []): array
    {
        $timestamp = time();
        $nonce = $this->generateNonce();
        
        $this->logger->info('Making API request', [
            'endpoint' => $endpoint,
            'params' => $params
        ]);
        
        $url = $this->baseUrl . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->getAuthHeaders($nonce, $timestamp),
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Log the API request
        $this->logApiCall($endpoint, $params, $response, $statusCode);

        if ($error) {
            $this->logger->error('API request failed', ['error' => $error]);
            throw new Exception("API request failed: " . $error);
        }

        if ($statusCode !== 200) {
            $this->logger->error('API request failed', [
                'statusCode' => $statusCode,
                'response' => $response
            ]);
            throw new Exception("API request failed with status code: " . $statusCode);
        }

        $this->logger->info('API request successful', ['statusCode' => $statusCode]);
        return json_decode($response, true);
    }

    private function logApiCall(string $endpoint, array $requestData, $responseData, int $statusCode): void
    {
        $stmt = $this->db->getPdo()->prepare(
            "INSERT INTO api_logs (endpoint, request_data, response_data, status_code) 
             VALUES (?, ?, ?, ?)"
        );
        
        $stmt->execute([
            $endpoint,
            json_encode($requestData),
            $responseData,
            $statusCode
        ]);
    }

    public function fetchShifts(string $startDate, string $endDate): array
    {
        $params = [
            'start[after]' => $startDate,
            'end[before]' => $endDate
        ];

        return $this->makeRequest('/testApi/data_exports', $params);
    }
} 