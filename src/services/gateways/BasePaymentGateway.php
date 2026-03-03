<?php
/**
 * Base Payment Gateway Class
 * Provides common functionality for all payment gateways
 * 
 * @author Sizzle & Rhythm Team
 * @version 1.0
 */

require_once 'PaymentGatewayInterface.php';

abstract class BasePaymentGateway implements PaymentGatewayInterface {
    
    protected $config;
    protected $credentials;
    protected $settings;
    protected $environment;
    protected $isLive;
    
    public function __construct(array $config) {
        $this->config = $config;
        $this->credentials = $config['credentials'];
        $this->settings = $config['settings'] ?? [];
        $this->environment = $config['environment'] ?? 'sandbox';
        $this->isLive = $this->environment === 'live';
        
        $this->validateCredentials();
    }
    
    /**
     * Validate required credentials
     * 
     * @throws Exception
     */
    abstract protected function validateCredentials();
    
    /**
     * Make HTTP request to gateway API
     * 
     * @param string $endpoint
     * @param array $data
     * @param string $method
     * @param array $headers
     * @return array
     */
    protected function makeRequest($endpoint, $data = [], $method = 'POST', $headers = []) {
        $ch = curl_init();
        
        $defaultHeaders = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        $headers = array_merge($defaultHeaders, $headers);
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45, // Increased timeout
            CURLOPT_CONNECTTIMEOUT => 30, // Added connection timeout
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->isLive,
            CURLOPT_SSL_VERIFYHOST => $this->isLive ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'SizzleRhythm-PaymentGateway/1.0'
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'GET' && !empty($data)) {
            $url = $endpoint . '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        
        curl_close($ch);
        
        // Log request details for debugging
        $this->log('debug', 'API Request', [
            'endpoint' => $endpoint,
            'method' => $method,
            'http_code' => $httpCode,
            'total_time' => $totalTime,
            'has_error' => !empty($error)
        ]);
        
        if ($error) {
            $this->log('error', 'cURL Error', [
                'error' => $error,
                'endpoint' => $endpoint
            ]);
            
            return [
                'status' => 'error',
                'message' => 'Network Error: ' . $error,
                'data' => null
            ];
        }
        
        $decodedResponse = json_decode($response, true);
        
        // Handle JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('error', 'JSON Decode Error', [
                'error' => json_last_error_msg(),
                'raw_response' => substr($response, 0, 500)
            ]);
            
            return [
                'status' => 'error',
                'message' => 'Invalid JSON response: ' . json_last_error_msg(),
                'data' => null,
                'raw_response' => $response
            ];
        }
        
        return [
            'status' => $httpCode >= 200 && $httpCode < 300 ? 'success' : 'error',
            'http_code' => $httpCode,
            'data' => $decodedResponse,
            'raw_response' => $response
        ];
    }
    
    /**
     * Generate transaction reference
     * 
     * @param string $prefix
     * @return string
     */
    protected function generateReference($prefix = '') {
        $prefix = $prefix ?: strtoupper($this->config['slug']) . '_';
        return $prefix . strtoupper(uniqid()) . '_' . time();
    }
    
    /**
     * Format amount based on currency
     * 
     * @param float $amount
     * @param string $currency
     * @return int|float
     */
    protected function formatAmount($amount, $currency = 'NGN') {
        // Most African gateways expect amount in smallest currency unit (kobo, pesewas, etc.)
        $zeroDecimalCurrencies = ['NGN', 'GHS', 'KES', 'UGX', 'ZAR'];
        
        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            return (int) ($amount * 100); // Convert to kobo/pesewas
        }
        
        return (float) $amount;
    }
    
    /**
     * Unformat amount from gateway response
     * 
     * @param int|float $amount
     * @param string $currency
     * @return float
     */
    protected function unformatAmount($amount, $currency = 'NGN') {
        $zeroDecimalCurrencies = ['NGN', 'GHS', 'KES', 'UGX', 'ZAR'];
        
        if (in_array(strtoupper($currency), $zeroDecimalCurrencies)) {
            return (float) ($amount / 100); // Convert from kobo/pesewas
        }
        
        return (float) $amount;
    }
    
    /**
     * Log gateway activity (you can implement logging here)
     * 
     * @param string $level
     * @param string $message
     * @param array $context
     */
    protected function log($level, $message, $context = []) {
        // Implement logging logic here
        error_log("Gateway [{$this->config['slug']}] {$level}: {$message} " . json_encode($context));
    }
    
    /**
     * Get gateway configuration
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getConfig($key, $default = null) {
        return $this->config[$key] ?? $default;
    }
    
    /**
     * Get credential value
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getCredential($key, $default = null) {
        return $this->credentials[$key] ?? $default;
    }
    
    /**
     * Get setting value
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getSetting($key, $default = null) {
        return $this->settings[$key] ?? $default;
    }
}
