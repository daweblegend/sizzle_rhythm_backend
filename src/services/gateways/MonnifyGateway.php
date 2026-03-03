<?php
/**
 * Monnify Payment Gateway Implementation
 * 
 * @author Sizzle & Rhythm Team
 * @version 1.0
 */

require_once 'BasePaymentGateway.php';

class MonnifyGateway extends BasePaymentGateway {
    
    private $apiUrl;
    private $accessToken;
    private $tokenExpiry;
    
    public function __construct(array $config) {
        parent::__construct($config);
        
        $this->apiUrl = $this->isLive 
            ? 'https://api.monnify.com' 
            : 'https://sandbox.monnify.com';
    }
    
    /**
     * Validate required credentials
     */
    protected function validateCredentials() {
        $required = ['api_key', 'secret_key', 'contract_code'];
        
        foreach ($required as $field) {
            if (empty($this->credentials[$field])) {
                throw new Exception("Monnify gateway: Missing required credential '{$field}'");
            }
        }
    }
    
    /**
     * Get access token for API calls
     */
    private function getAccessToken() {
        if ($this->accessToken && $this->tokenExpiry > time()) {
            return $this->accessToken;
        }
        
        $endpoint = $this->apiUrl . '/api/v1/auth/login';
        
        $credentials = base64_encode($this->credentials['api_key'] . ':' . $this->credentials['secret_key']);
        
        $response = $this->makeRequest($endpoint, [], 'POST', [
            'Authorization: Basic ' . $credentials
        ]);
        
        if ($response['status'] === 'success' && isset($response['data']['responseBody']['accessToken'])) {
            $tokenData = $response['data']['responseBody'];
            $this->accessToken = $tokenData['accessToken'];
            $this->tokenExpiry = time() + ($tokenData['expiresIn'] ?? 3600) - 60; // 1 minute buffer
            
            return $this->accessToken;
        }
        
        $this->log('error', 'Failed to get access token', $response);
        throw new Exception('Failed to authenticate with Monnify API');
    }
    
    /**
     * Initialize payment transaction
     */
    public function initializePayment(array $data) {
        try {
            $token = $this->getAccessToken();
            
            $amount = $data['amount']; // Monnify uses actual amount
            
            $payload = [
                'amount' => $amount,
                'currencyCode' => strtoupper($data['currency'] ?? 'NGN'),
                'customerName' => $data['customer_name'] ?? '',
                'customerEmail' => $data['customer_email'],
                'paymentReference' => $data['reference'],
                'paymentDescription' => $data['description'] ?? 'Payment for order ' . $data['reference'],
                'redirectUrl' => $data['redirect_url'] ?? '',
                'contractCode' => $this->credentials['contract_code'],
                'metaData' => $data['metadata'] ?? []
            ];
            
            $endpoint = $this->apiUrl . '/api/v1/merchant/transactions/init-transaction';
            
            $response = $this->makeRequest($endpoint, $payload, 'POST', [
                'Authorization: Bearer ' . $token
            ]);
            
            if ($response['status'] === 'success' && isset($response['data']['requestSuccessful']) && $response['data']['requestSuccessful']) {
                $responseData = $response['data']['responseBody'];
                
                return [
                    'status' => 'success',
                    'message' => 'Payment initialized successfully',
                    'gateway_reference' => $responseData['transactionReference'] ?? null,
                    'payment_url' => $responseData['checkoutUrl'] ?? null,
                    'data' => $responseData
                ];
            }
            
            $message = $response['data']['responseMessage'] ?? 'Payment initialization failed';
            
            return [
                'status' => 'error',
                'message' => $message,
                'gateway_reference' => null,
                'data' => $response
            ];
            
        } catch (Exception $e) {
            $this->log('error', 'Payment initialization failed', ['error' => $e->getMessage(), 'data' => $data]);
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'gateway_reference' => null,
                'data' => null
            ];
        }
    }
    
    /**
     * Verify payment transaction
     */
    public function verifyPayment(string $reference) {
        try {
            $token = $this->getAccessToken();
            
            // Log the verification attempt
            $this->log('info', 'Monnify payment verification started', [
                'reference' => $reference,
                'endpoint_ref' => urlencode($reference)
            ]);
            
            $endpoint = $this->apiUrl . '/api/v2/transactions/' . urlencode($reference);
            
            $response = $this->makeRequest($endpoint, [], 'GET', [
                'Authorization: Bearer ' . $token
            ]);
            
            $this->log('debug', 'Monnify verification response', [
                'reference' => $reference,
                'response' => $response
            ]);
            
            if ($response['status'] === 'success' && isset($response['data']['requestSuccessful']) && $response['data']['requestSuccessful']) {
                $transactionData = $response['data']['responseBody'];
                
                $status = strtoupper($transactionData['paymentStatus'] ?? 'PENDING');
                $amount = (float) ($transactionData['amountPaid'] ?? 0);
                
                $responseStatus = 'pending';
                if ($status === 'PAID') {
                    $responseStatus = 'success';
                } elseif (in_array($status, ['FAILED', 'CANCELLED', 'EXPIRED'])) {
                    $responseStatus = 'failed';
                }
                
                $this->log('info', 'Monnify verification successful', [
                    'reference' => $reference,
                    'status' => $responseStatus,
                    'amount' => $amount,
                    'monnify_status' => $status
                ]);
                
                return [
                    'status' => $responseStatus,
                    'message' => $transactionData['paymentDescription'] ?? 'Payment verification completed',
                    'reference' => $reference,
                    'gateway_reference' => $transactionData['transactionReference'] ?? null,
                    'amount' => $amount,
                    'currency' => $transactionData['currencyCode'] ?? 'NGN',
                    'payment_method' => $transactionData['paymentMethod'] ?? null,
                    'transaction_date' => $transactionData['paidOn'] ?? $transactionData['createdOn'] ?? null,
                    'fee' => (float) ($transactionData['fee'] ?? 0),
                    'data' => $transactionData
                ];
            }
            
            $errorMessage = $response['data']['responseMessage'] ?? 'Verification failed';
            $this->log('error', 'Monnify verification failed', [
                'reference' => $reference,
                'error' => $errorMessage,
                'response' => $response
            ]);
            
            return [
                'status' => 'error',
                'message' => $errorMessage,
                'reference' => $reference,
                'data' => $response
            ];
            
        } catch (Exception $e) {
            $this->log('error', 'Payment verification exception', [
                'error' => $e->getMessage(), 
                'reference' => $reference
            ]);
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'reference' => $reference,
                'data' => null
            ];
        }
    }
    
    /**
     * Handle webhook from Monnify
     */
    public function handleWebhook(array $payload) {
        try {
            $this->log('info', 'Webhook received', $payload);
            
            // Verify webhook signature if webhook secret is configured
            if (!empty($this->credentials['webhook_secret'])) {
                $signature = $_SERVER['HTTP_MONNIFY_SIGNATURE'] ?? '';
                if (!$this->verifyWebhookSignature($payload, $signature)) {
                    return [
                        'status' => 'error',
                        'message' => 'Invalid webhook signature'
                    ];
                }
            }
            
            $eventType = $payload['eventType'] ?? '';
            $eventData = $payload['eventData'] ?? [];
            
            if ($eventType === 'SUCCESSFUL_TRANSACTION') {
                return [
                    'status' => 'success',
                    'message' => 'Webhook processed successfully',
                    'event_type' => $eventType,
                    'reference' => $eventData['paymentReference'] ?? null,
                    'transaction_status' => 'completed'
                ];
            } elseif ($eventType === 'FAILED_TRANSACTION') {
                return [
                    'status' => 'success',
                    'message' => 'Webhook processed successfully',
                    'event_type' => $eventType,
                    'reference' => $eventData['paymentReference'] ?? null,
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
        $computedSignature = hash_hmac('sha512', json_encode($payload), $secret);
        
        return hash_equals($computedSignature, $signature);
    }
    
    /**
     * Get supported payment methods
     */
    public function getSupportedMethods() {
        return [
            'CARD' => 'Credit/Debit Card',
            'ACCOUNT_TRANSFER' => 'Bank Transfer',
            'USSD' => 'USSD'
        ];
    }
    
    /**
     * Get supported currencies
     */
    public function getSupportedCurrencies() {
        return ['NGN'];
    }
    
    /**
     * Create a reserved account
     */
    public function createReservedAccount(array $data) {
        try {
            $token = $this->getAccessToken();
            
            $payload = [
                'accountReference' => $data['account_reference'] ?? $this->generateReference('RA_'),
                'accountName' => $data['account_name'],
                'currencyCode' => $data['currency'] ?? 'NGN',
                'contractCode' => $this->credentials['contract_code'],
                'customerEmail' => $data['customer_email'],
                'bvn' => $data['bvn'] ?? '',
                'customerName' => $data['customer_name'] ?? '',
                'getAllAvailableBanks' => $data['get_all_banks'] ?? false
            ];
            
            $endpoint = $this->apiUrl . '/api/v2/bank-transfer/reserved-accounts';
            
            $response = $this->makeRequest($endpoint, $payload, 'POST', [
                'Authorization: Bearer ' . $token
            ]);
            
            if ($response['status'] === 'success' && isset($response['data']['requestSuccessful']) && $response['data']['requestSuccessful']) {
                return [
                    'status' => 'success',
                    'data' => $response['data']['responseBody']
                ];
            }
            
            return [
                'status' => 'error',
                'message' => $response['data']['responseMessage'] ?? 'Reserved account creation failed'
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
