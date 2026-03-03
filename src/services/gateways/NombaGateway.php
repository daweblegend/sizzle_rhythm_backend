<?php
/**
 * Nomba Payment Gateway Implementation
 * 
 * @author Sizzle & Rhythm Team
 * @version 1.0
 */

require_once 'BasePaymentGateway.php';

class NombaGateway extends BasePaymentGateway {
    
    private $apiUrl;
    private $accessToken;
    private $tokenExpiry;
    
    public function __construct(array $config) {
        parent::__construct($config);
        
        $this->apiUrl = $this->isLive 
            ? 'https://api.nomba.com' 
            : 'https://sandbox.nomba.com'; // Use same URL for both
    }
    
    /**
     * Validate required credentials
     */
    protected function validateCredentials() {
        $required = ['client_id', 'client_secret'];
        
        foreach ($required as $field) {
            if (empty($this->credentials[$field])) {
                throw new Exception("Nomba gateway: Missing required credential '{$field}'");
            }
        }
    }
    
    /**
     * Get access token for API calls
     */
    private function getAccessToken() {
        // Add a small buffer check to prevent race conditions
        if ($this->accessToken && $this->tokenExpiry > (time() + 60)) {
            return $this->accessToken;
        }
        
        $endpoint = $this->apiUrl . '/v1/auth/token/issue';
        
        // Add retry logic for authentication
        $maxRetries = 2;
        $retryCount = 0;
        
        while ($retryCount <= $maxRetries) {
            try {
                $response = $this->makeRequest($endpoint, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->credentials['client_id'],
                    'client_secret' => $this->credentials['client_secret']
                ], 'POST', [
                    'accountId: ' . ($this->credentials['account_id'] ?? '')
                ]);
                
                if ($response['status'] === 'success' && isset($response['data']['code'])) {
                    $responseData = $response['data'];
                    
                    // Check for successful response
                    if ($responseData['code'] === '00' && isset($responseData['data']['access_token'])) {
                        $tokenData = $responseData['data'];
                        $this->accessToken = $tokenData['access_token'];
                        
                        // Set expiry time with more conservative buffer
                        if (isset($tokenData['expiresAt'])) {
                            $this->tokenExpiry = strtotime($tokenData['expiresAt']) - 600; // 10 minute buffer
                        } else {
                            $this->tokenExpiry = time() + 3000; // 50 minutes default
                        }
                        
                        $this->log('info', 'Nomba token obtained successfully', [
                            'expires_at' => date('Y-m-d H:i:s', $this->tokenExpiry)
                        ]);
                        
                        return $this->accessToken;
                    } else {
                        $error = $responseData['description'] ?? 'Authentication failed';
                        $this->log('error', 'Nomba authentication failed', [
                            'error' => $error, 
                            'response' => $responseData,
                            'attempt' => $retryCount + 1
                        ]);
                        
                        if ($retryCount < $maxRetries) {
                            $retryCount++;
                            sleep(1); // Wait 1 second before retry
                            continue;
                        }
                        
                        throw new Exception('Nomba authentication failed: ' . $error);
                    }
                }
                
                $this->log('error', 'Failed to get access token', [
                    'response' => $response,
                    'attempt' => $retryCount + 1
                ]);
                
                if ($retryCount < $maxRetries) {
                    $retryCount++;
                    sleep(1); // Wait 1 second before retry
                    continue;
                }
                
                throw new Exception('Failed to authenticate with Nomba API');
                
            } catch (Exception $e) {
                $this->log('error', 'Authentication attempt failed', [
                    'error' => $e->getMessage(),
                    'attempt' => $retryCount + 1
                ]);
                
                if ($retryCount < $maxRetries) {
                    $retryCount++;
                    sleep(1); // Wait 1 second before retry
                    continue;
                }
                
                throw $e;
            }
        }
    }
    
    /**
     * Initialize payment transaction
     */
    public function initializePayment(array $data) {
        $maxRetries = 2;
        $retryCount = 0;
        
        while ($retryCount <= $maxRetries) {
            try {
                $token = $this->getAccessToken();
                
                // Don't format amount for Nomba - use actual amount
                $amount = number_format((float)$data['amount'], 2, '.', '');
                
                // Prepare order data according to Nomba API
                $orderData = [
                    'amount' => $amount,
                    'currency' => strtoupper($data['currency'] ?? 'NGN'),
                    'orderReference' => $data['reference'],
                    'customerEmail' => $data['customer_email'],
                    'accountId' => $this->credentials['account_id'] ?? ''
                ];
                
                // Add optional fields if provided
                if (!empty($data['callback_url'])) {
                    $orderData['callbackUrl'] = $data['callback_url'];
                }
                
                if (!empty($data['metadata'])) {
                    $orderData['orderMetaData'] = $data['metadata'];
                }
                
                $payload = [
                    'order' => $orderData,
                    'tokenizeCard' => false // Default to false
                ];
                
                $endpoint = $this->apiUrl . '/v1/checkout/order';
                
                $response = $this->makeRequest($endpoint, $payload, 'POST', [
                    'Authorization: Bearer ' . $token,
                    'accountId: ' . ($this->credentials['account_id'] ?? '')
                ]);
                
                if ($response['status'] === 'success' && isset($response['data']['code'])) {
                    $responseData = $response['data'];
                    
                    if ($responseData['code'] === '00' && isset($responseData['data'])) {
                        $checkoutData = $responseData['data'];
                        
                        $this->log('info', 'Payment initialized successfully', [
                            'reference' => $data['reference'],
                            'gateway_reference' => $checkoutData['orderReference'] ?? null
                        ]);
                        
                        return [
                            'status' => 'success',
                            'message' => 'Payment initialized successfully',
                            'gateway_reference' => $checkoutData['orderReference'] ?? null,
                            'checkout_url' => $checkoutData['checkoutUrl'] ?? null,
                            'reference' => $data['reference'],
                            'amount' => $amount,
                            'currency' => $orderData['currency'],
                            'data' => $checkoutData
                        ];
                    } else {
                        $error = $responseData['description'] ?? 'Payment initialization failed';
                        $this->log('error', 'Payment initialization failed', [
                            'error' => $error, 
                            'response' => $responseData,
                            'attempt' => $retryCount + 1
                        ]);
                        
                        // Don't retry on invalid data errors
                        if (stripos($error, 'invalid') !== false || stripos($error, 'duplicate') !== false) {
                            return [
                                'status' => 'error',
                                'message' => $error,
                                'reference' => $data['reference'],
                                'data' => $responseData
                            ];
                        }
                        
                        if ($retryCount < $maxRetries) {
                            $retryCount++;
                            sleep(1);
                            continue;
                        }
                        
                        return [
                            'status' => 'error',
                            'message' => $error,
                            'reference' => $data['reference'],
                            'data' => $responseData
                        ];
                    }
                }
                
                $this->log('error', 'Payment initialization failed - invalid response', [
                    'response' => $response,
                    'attempt' => $retryCount + 1
                ]);
                
                if ($retryCount < $maxRetries) {
                    $retryCount++;
                    sleep(1);
                    continue;
                }
                
                return [
                    'status' => 'error',
                    'message' => $response['data']['description'] ?? 'Payment initialization failed',
                    'reference' => $data['reference'],
                    'data' => $response
                ];
                
            } catch (Exception $e) {
                $this->log('error', 'Payment initialization exception', [
                    'error' => $e->getMessage(),
                    'reference' => $data['reference'],
                    'attempt' => $retryCount + 1
                ]);
                
                // Don't retry on authentication errors, but do retry on network errors
                if (stripos($e->getMessage(), 'authenticate') !== false || stripos($e->getMessage(), 'token') !== false) {
                    // Clear token on auth errors to force refresh
                    $this->accessToken = null;
                    $this->tokenExpiry = 0;
                }
                
                if ($retryCount < $maxRetries && (stripos($e->getMessage(), 'network') !== false || stripos($e->getMessage(), 'timeout') !== false || stripos($e->getMessage(), 'authenticate') !== false)) {
                    $retryCount++;
                    sleep(1);
                    continue;
                }
                
                return [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'reference' => $data['reference'],
                    'data' => null
                ];
            }
        }
    }

    /**
     * Verify payment transaction
     */
    public function verifyPayment(string $reference) {
        try {
            $token = $this->getAccessToken();
            
            $endpoint = $this->apiUrl . '/v1/checkout/transaction?idType=ORDER_REFERENCE&id=' . urlencode($reference);
            
            $response = $this->makeRequest($endpoint, [], 'GET', [
                'Authorization: Bearer ' . $token,
                'accountId: ' . ($this->credentials['account_id'] ?? '')
            ]);
            
            if ($response['status'] === 'success' && isset($response['data']['code'])) {
                $responseData = $response['data'];
                
                if ($responseData['code'] === '00' && isset($responseData['data'])) {
                    $data = $responseData['data'];
                    $order = $data['order'] ?? [];
                    $transactionDetails = $data['transactionDetails'] ?? [];
                    
                    // Check if payment was successful
                    $isSuccessful = ($data['success'] ?? false) === true;
                    $status = $isSuccessful ? 'success' : 'pending';
                    
                    // Get amount (don't need to unformat since Nomba uses actual amounts)
                    $amount = (float) ($order['amount'] ?? 0);
                    
                    return [
                        'status' => $status,
                        'message' => $isSuccessful ? 'Payment successful' : 'Payment pending',
                        'reference' => $reference,
                        'gateway_reference' => $order['orderReference'] ?? null,
                        'amount' => $amount,
                        'currency' => $order['currency'] ?? 'NGN',
                        'payment_method' => 'card',
                        'transaction_date' => $order['createdAt'] ?? null,
                        'data' => $data
                    ];
                } else {
                    $error = $responseData['description'] ?? 'Verification failed';
                    return [
                        'status' => 'error',
                        'message' => $error,
                        'reference' => $reference,
                        'data' => $responseData
                    ];
                }
            }
            
            return [
                'status' => 'error',
                'message' => $response['data']['description'] ?? 'Verification failed',
                'reference' => $reference,
                'data' => $response
            ];
            
        } catch (Exception $e) {
            $this->log('error', 'Payment verification failed', ['error' => $e->getMessage(), 'reference' => $reference]);
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'reference' => $reference,
                'data' => null
            ];
        }
    }
    
    /**
     * Handle webhook from Nomba
     */
    public function handleWebhook(array $payload) {
        try {
            $this->log('info', 'Webhook received', $payload);
            
            // Verify webhook signature if webhook secret is configured
            if (!empty($this->credentials['webhook_secret'])) {
                $signature = $_SERVER['HTTP_X_NOMBA_SIGNATURE'] ?? '';
                if (!$this->verifyWebhookSignature($payload, $signature)) {
                    return [
                        'status' => 'error',
                        'message' => 'Invalid webhook signature'
                    ];
                }
            }
            
            $eventType = $payload['event'] ?? '';
            $orderData = $payload['data'] ?? [];
            
            if ($eventType === 'payment.completed' || $eventType === 'order.successful') {
                return [
                    'status' => 'success',
                    'message' => 'Webhook processed successfully',
                    'event_type' => $eventType,
                    'reference' => $orderData['orderReference'] ?? null,
                    'transaction_status' => 'completed'
                ];
            } elseif ($eventType === 'payment.failed' || $eventType === 'order.failed') {
                return [
                    'status' => 'success',
                    'message' => 'Webhook processed successfully',
                    'event_type' => $eventType,
                    'reference' => $orderData['orderReference'] ?? null,
                    'transaction_status' => 'failed'
                ];
            }
            
            return [
                'status' => 'success',
                'message' => 'Webhook received but no action taken',
                'event_type' => $eventType
            ];
            
        } catch (Exception $e) {
            $this->log('error', 'Webhook processing failed', ['error' => $e->getMessage(), 'payload' => $payload]);
            
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Verify webhook signature
     */
    private function verifyWebhookSignature(array $payload, string $signature) {
        $secret = $this->credentials['webhook_secret'];
        $computedSignature = hash_hmac('sha256', json_encode($payload), $secret);
        
        return hash_equals($computedSignature, $signature);
    }
    
    /**
     * Get supported payment methods
     */
    public function getSupportedMethods() {
        return [
            'card' => 'Credit/Debit Card',
            'bank_transfer' => 'Bank Transfer',
            'ussd' => 'USSD',
            'qr' => 'QR Code'
        ];
    }
    
    /**
     * Get supported currencies
     */
    public function getSupportedCurrencies() {
        return ['NGN', 'USD'];
    }
    
    /**
     * Clear cached token (useful for testing)
     */
    public function clearToken() {
        $this->accessToken = null;
        $this->tokenExpiry = 0;
        $this->log('debug', 'Token cache cleared');
    }
}
