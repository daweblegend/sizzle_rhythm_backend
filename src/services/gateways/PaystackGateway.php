<?php
/**
 * Paystack Payment Gateway Implementation
 * 
 * @author Sizzle & Rhythm Team
 * @version 1.0
 */

require_once 'BasePaymentGateway.php';

class PaystackGateway extends BasePaymentGateway {
    
    private $apiUrl;
    
    public function __construct(array $config) {
        parent::__construct($config);
        
        $this->apiUrl = 'https://api.paystack.co';
    }
    
    /**
     * Validate required credentials
     */
    protected function validateCredentials() {
        $required = ['public_key', 'secret_key'];
        
        foreach ($required as $field) {
            if (empty($this->credentials[$field])) {
                throw new Exception("Paystack gateway: Missing required credential '{$field}'");
            }
        }
    }
    
    /**
     * Initialize payment transaction
     */
    public function initializePayment(array $data) {
        try {
            $amount = $this->formatAmount($data['amount'], $data['currency'] ?? 'NGN');
            
            $payload = [
                'reference' => $data['reference'],
                'amount' => $amount,
                'currency' => strtoupper($data['currency'] ?? 'NGN'),
                'email' => $data['customer_email'],
                'callback_url' => $data['callback_url'] ?? '',
                'metadata' => array_merge($data['metadata'] ?? [], [
                    'customer_name' => $data['customer_name'] ?? '',
                    'description' => $data['description'] ?? 'Payment for order ' . $data['reference']
                ])
            ];
            
            $endpoint = $this->apiUrl . '/transaction/initialize';
            
            $response = $this->makeRequest($endpoint, $payload, 'POST', [
                'Authorization: Bearer ' . $this->credentials['secret_key']
            ]);
            
            if ($response['status'] === 'success' && isset($response['data']['status']) && $response['data']['status']) {
                $responseData = $response['data']['data'];
                
                return [
                    'status' => 'success',
                    'message' => 'Payment initialized successfully',
                    'gateway_reference' => $responseData['reference'] ?? null,
                    'payment_url' => $responseData['authorization_url'] ?? null,
                    'access_code' => $responseData['access_code'] ?? null,
                    'data' => $responseData
                ];
            }
            
            $message = $response['data']['message'] ?? 'Payment initialization failed';
            
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
            $endpoint = $this->apiUrl . '/transaction/verify/' . $reference;
            
            $response = $this->makeRequest($endpoint, [], 'GET', [
                'Authorization: Bearer ' . $this->credentials['secret_key']
            ]);
            
            if ($response['status'] === 'success' && isset($response['data']['status']) && $response['data']['status']) {
                $transactionData = $response['data']['data'];
                
                $status = strtolower($transactionData['status'] ?? 'pending');
                $amount = $this->unformatAmount($transactionData['amount'] ?? 0, $transactionData['currency'] ?? 'NGN');
                
                $responseStatus = 'pending';
                if ($status === 'success') {
                    $responseStatus = 'success';
                } elseif (in_array($status, ['failed', 'abandoned', 'cancelled'])) {
                    $responseStatus = 'failed';
                }
                
                return [
                    'status' => $responseStatus,
                    'message' => $transactionData['gateway_response'] ?? 'Payment verification completed',
                    'reference' => $reference,
                    'gateway_reference' => $transactionData['reference'] ?? null,
                    'amount' => $amount,
                    'currency' => $transactionData['currency'] ?? 'NGN',
                    'payment_method' => $this->getPaymentMethod($transactionData),
                    'transaction_date' => $transactionData['paid_at'] ?? $transactionData['created_at'] ?? null,
                    'fees' => $this->unformatAmount($transactionData['fees'] ?? 0, $transactionData['currency'] ?? 'NGN'),
                    'data' => $transactionData
                ];
            }
            
            return [
                'status' => 'error',
                'message' => $response['data']['message'] ?? 'Verification failed',
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
     * Handle webhook from Paystack
     */
    public function handleWebhook(array $payload) {
        try {
            $this->log('info', 'Webhook received', $payload);
            
            // Verify webhook signature if webhook secret is configured
            if (!empty($this->credentials['webhook_secret'])) {
                $signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
                if (!$this->verifyWebhookSignature($payload, $signature)) {
                    return [
                        'status' => 'error',
                        'message' => 'Invalid webhook signature'
                    ];
                }
            }
            
            $eventType = $payload['event'] ?? '';
            $transactionData = $payload['data'] ?? [];
            
            if ($eventType === 'charge.success') {
                return [
                    'status' => 'success',
                    'message' => 'Webhook processed successfully',
                    'event_type' => $eventType,
                    'reference' => $transactionData['reference'] ?? null,
                    'transaction_status' => 'completed'
                ];
            } elseif (in_array($eventType, ['charge.failed', 'charge.declined'])) {
                return [
                    'status' => 'success',
                    'message' => 'Webhook processed successfully',
                    'event_type' => $eventType,
                    'reference' => $transactionData['reference'] ?? null,
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
     * Extract payment method from transaction data
     */
    private function getPaymentMethod(array $transactionData) {
        $authorization = $transactionData['authorization'] ?? [];
        $channel = $transactionData['channel'] ?? '';
        
        if (!empty($authorization['channel'])) {
            return $authorization['channel'];
        }
        
        if (!empty($channel)) {
            return $channel;
        }
        
        return 'card';
    }
    
    /**
     * Get supported payment methods
     */
    public function getSupportedMethods() {
        return [
            'card' => 'Credit/Debit Card',
            'bank' => 'Bank Transfer',
            'ussd' => 'USSD',
            'qr' => 'QR Code',
            'mobile_money' => 'Mobile Money',
            'bank_transfer' => 'Direct Bank Transfer'
        ];
    }
    
    /**
     * Get supported currencies
     */
    public function getSupportedCurrencies() {
        return ['NGN', 'USD', 'ZAR', 'GHS'];
    }
    
    /**
     * Create a customer
     */
    public function createCustomer(array $customerData) {
        try {
            $payload = [
                'email' => $customerData['email'],
                'first_name' => $customerData['first_name'] ?? '',
                'last_name' => $customerData['last_name'] ?? '',
                'phone' => $customerData['phone'] ?? ''
            ];
            
            $endpoint = $this->apiUrl . '/customer';
            
            $response = $this->makeRequest($endpoint, $payload, 'POST', [
                'Authorization: Bearer ' . $this->credentials['secret_key']
            ]);
            
            if ($response['status'] === 'success' && isset($response['data']['status']) && $response['data']['status']) {
                return [
                    'status' => 'success',
                    'customer_code' => $response['data']['data']['customer_code'],
                    'data' => $response['data']['data']
                ];
            }
            
            return [
                'status' => 'error',
                'message' => $response['data']['message'] ?? 'Customer creation failed'
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
