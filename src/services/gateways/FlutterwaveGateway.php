<?php
/**
 * Flutterwave Payment Gateway Implementation
 * 
 * @author Sizzle & Rhythm Team
 * @version 1.0
 */

require_once 'BasePaymentGateway.php';

class FlutterwaveGateway extends BasePaymentGateway {
    
    private $apiUrl;
    
    public function __construct(array $config) {
        parent::__construct($config);
        
        $this->apiUrl = $this->isLive 
            ? 'https://api.flutterwave.com/v3' 
            : 'https://api.flutterwave.com/v3';
    }
    
    /**
     * Validate required credentials
     */
    protected function validateCredentials() {
        $required = ['public_key', 'secret_key', 'encryption_key'];
        
        foreach ($required as $field) {
            if (empty($this->credentials[$field])) {
                throw new Exception("Flutterwave gateway: Missing required credential '{$field}'");
            }
        }
    }
    
    /**
     * Initialize payment transaction
     */
    public function initializePayment(array $data) {
        try {
            $amount = $data['amount']; // Flutterwave uses actual amount, not kobo
            
            $payload = [
                'tx_ref' => $data['reference'],
                'amount' => $amount,
                'currency' => strtoupper($data['currency'] ?? 'NGN'),
                'redirect_url' => $data['redirect_url'] ?? '',
                'customer' => [
                    'email' => $data['customer_email'],
                    'name' => $data['customer_name'] ?? ''
                ],
                'customizations' => [
                    'title' => 'Sizzle & Rhythm Payment',
                    'description' => $data['description'] ?? 'Payment for order ' . $data['reference'],
                    'logo' => ''
                ],
                'meta' => $data['metadata'] ?? []
            ];
            
            if (!empty($data['callback_url'])) {
                $payload['customizations']['callback_url'] = $data['callback_url'];
            }
            
            $endpoint = $this->apiUrl . '/payments';
            
            $response = $this->makeRequest($endpoint, $payload, 'POST', [
                'Authorization: Bearer ' . $this->credentials['secret_key']
            ]);
            
            if ($response['status'] === 'success' && isset($response['data']['status']) && $response['data']['status'] === 'success') {
                $responseData = $response['data']['data'];
                
                return [
                    'status' => 'success',
                    'message' => 'Payment initialized successfully',
                    'gateway_reference' => $responseData['tx_ref'] ?? null,
                    'payment_url' => $responseData['link'] ?? null,
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
            $endpoint = $this->apiUrl . '/transactions/verify_by_reference';
            
            $response = $this->makeRequest($endpoint, ['tx_ref' => $reference], 'GET', [
                'Authorization: Bearer ' . $this->credentials['secret_key']
            ]);
            
            if ($response['status'] === 'success' && isset($response['data']['status']) && $response['data']['status'] === 'success') {
                $transactionData = $response['data']['data'];
                
                $status = strtolower($transactionData['status'] ?? 'pending');
                $amount = (float) ($transactionData['amount'] ?? 0);
                
                $responseStatus = 'pending';
                if ($status === 'successful') {
                    $responseStatus = 'success';
                } elseif (in_array($status, ['failed', 'cancelled'])) {
                    $responseStatus = 'failed';
                }
                
                return [
                    'status' => $responseStatus,
                    'message' => $transactionData['processor_response'] ?? 'Payment verification completed',
                    'reference' => $reference,
                    'gateway_reference' => $transactionData['tx_ref'] ?? null,
                    'flw_ref' => $transactionData['flw_ref'] ?? null,
                    'amount' => $amount,
                    'currency' => $transactionData['currency'] ?? 'NGN',
                    'payment_method' => $transactionData['payment_type'] ?? null,
                    'transaction_date' => $transactionData['created_at'] ?? null,
                    'app_fee' => (float) ($transactionData['app_fee'] ?? 0),
                    'merchant_fee' => (float) ($transactionData['merchant_fee'] ?? 0),
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
     * Handle webhook from Flutterwave
     */
    public function handleWebhook(array $payload) {
        try {
            $this->log('info', 'Webhook received', $payload);
            
            // Verify webhook signature if webhook secret is configured
            if (!empty($this->credentials['webhook_secret'])) {
                $signature = $_SERVER['HTTP_VERIF_HASH'] ?? '';
                if (!$this->verifyWebhookSignature($payload, $signature)) {
                    return [
                        'status' => 'error',
                        'message' => 'Invalid webhook signature'
                    ];
                }
            }
            
            $eventType = $payload['event'] ?? '';
            $transactionData = $payload['data'] ?? [];
            
            if ($eventType === 'charge.completed') {
                $status = strtolower($transactionData['status'] ?? '');
                
                return [
                    'status' => 'success',
                    'message' => 'Webhook processed successfully',
                    'event_type' => $eventType,
                    'reference' => $transactionData['tx_ref'] ?? null,
                    'transaction_status' => $status === 'successful' ? 'completed' : 'failed'
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
        
        return hash_equals($secret, $signature);
    }
    
    /**
     * Get supported payment methods
     */
    public function getSupportedMethods() {
        return [
            'card' => 'Credit/Debit Card',
            'banktransfer' => 'Bank Transfer',
            'ussd' => 'USSD',
            'mpesa' => 'M-Pesa',
            'mobilemoneyghana' => 'MTN Mobile Money',
            'mobilemoneyuganda' => 'Mobile Money Uganda',
            'mobilemoneyrwanda' => 'Mobile Money Rwanda',
            'mobilemoneyzambia' => 'Mobile Money Zambia',
            'qr' => 'QR Code',
            'barter' => 'Barter'
        ];
    }
    
    /**
     * Get supported currencies
     */
    public function getSupportedCurrencies() {
        return ['NGN', 'USD', 'EUR', 'GBP', 'KES', 'UGX', 'ZAR', 'GHS', 'XOF', 'XAF'];
    }
    
    /**
     * Create a virtual account
     */
    public function createVirtualAccount(array $data) {
        try {
            $payload = [
                'email' => $data['email'],
                'is_permanent' => $data['is_permanent'] ?? true,
                'bvn' => $data['bvn'] ?? '',
                'tx_ref' => $data['tx_ref'] ?? $this->generateReference('VA_'),
                'phonenumber' => $data['phone'] ?? '',
                'firstname' => $data['first_name'] ?? '',
                'lastname' => $data['last_name'] ?? '',
                'narration' => $data['narration'] ?? 'Virtual Account'
            ];
            
            $endpoint = $this->apiUrl . '/virtual-account-numbers';
            
            $response = $this->makeRequest($endpoint, $payload, 'POST', [
                'Authorization: Bearer ' . $this->credentials['secret_key']
            ]);
            
            if ($response['status'] === 'success' && isset($response['data']['status']) && $response['data']['status'] === 'success') {
                return [
                    'status' => 'success',
                    'data' => $response['data']['data']
                ];
            }
            
            return [
                'status' => 'error',
                'message' => $response['data']['message'] ?? 'Virtual account creation failed'
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
