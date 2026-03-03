<?php
/**
 * Payment Gateway Manager
 * Manages multiple payment gateways and handles gateway operations
 * 
 * @author Sizzle & Rhythm Team
 * @version 1.0
 */

require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';

class PaymentGatewayManager {
    
    private $conn;
    private $availableGateways = [];
    private $gatewayInstances = [];
    
    public function __construct() {
        global $conn;
        $this->conn = $conn;
        $this->loadAvailableGateways();
    }
    
    /**
     * Load available payment gateways from database
     */
    private function loadAvailableGateways() {
        $query = "SELECT * FROM payment_gateways WHERE is_active = 1 ORDER BY is_default DESC, name ASC";
        $result = mysqli_query($this->conn, $query);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $this->availableGateways[$row['slug']] = $row;
        }
    }
    
    /**
     * Get all available payment gateways
     * 
     * @return array
     */
    public function getAvailableGateways() {
        return array_values($this->availableGateways);
    }
    
    /**
     * Get specific gateway configuration
     * 
     * @param string $gatewaySlug
     * @param int|null $userId
     * @param string $environment
     * @return array|null
     */
    public function getGatewayConfig($gatewaySlug, $userId = null, $environment = 'sandbox') {
        if (!isset($this->availableGateways[$gatewaySlug])) {
            return null;
        }
        
        $gatewayId = $this->availableGateways[$gatewaySlug]['id'];
        
        $query = "SELECT pgc.*, pg.name, pg.slug FROM payment_gateway_configs pgc 
                 JOIN payment_gateways pg ON pgc.gateway_id = pg.id 
                 WHERE pgc.gateway_id = ? AND pgc.environment = ? AND pgc.is_active = 1";
        
        $params = [$gatewayId, $environment];
        $types = "is";
        
        if ($userId) {
            $query .= " AND (pgc.user_id = ? OR pgc.user_id IS NULL) ORDER BY pgc.user_id DESC LIMIT 1";
            $params[] = $userId;
            $types .= "i";
        } else {
            $query .= " AND pgc.user_id IS NULL LIMIT 1";
        }
        
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($config = mysqli_fetch_assoc($result)) {
            $config['credentials'] = json_decode($config['credentials'], true);
            $config['settings'] = json_decode($config['settings'] ?? '{}', true);
            return $config;
        }
        
        return null;
    }
    
    /**
     * Get gateway instance
     * 
     * @param string $gatewaySlug
     * @param int|null $userId
     * @param string $environment
     * @return object|null
     */
    public function getGatewayInstance($gatewaySlug, $userId = null, $environment = 'sandbox') {
        $cacheKey = $gatewaySlug . '_' . ($userId ?? 'system') . '_' . $environment;
        
        if (isset($this->gatewayInstances[$cacheKey])) {
            return $this->gatewayInstances[$cacheKey];
        }
        
        $config = $this->getGatewayConfig($gatewaySlug, $userId, $environment);
        if (!$config) {
            return null;
        }
        
        $gatewayClass = ucfirst($gatewaySlug) . 'Gateway';
        $gatewayFile = __DIR__ . '/gateways/' . $gatewayClass . '.php';
        
        if (!file_exists($gatewayFile)) {
            return null;
        }
        
        require_once $gatewayFile;
        
        if (!class_exists($gatewayClass)) {
            return null;
        }
        
        $instance = new $gatewayClass($config);
        $this->gatewayInstances[$cacheKey] = $instance;
        
        return $instance;
    }
    
    /**
     * Initialize payment with specified gateway
     * 
     * @param string $gatewaySlug
     * @param array $paymentData
     * @param int|null $userId
     * @param string $environment
     * @return array
     */
    public function initializePayment($gatewaySlug, $paymentData, $userId = null, $environment = 'sandbox') {
        $gateway = $this->getGatewayInstance($gatewaySlug, $userId, $environment);
        
        if (!$gateway) {
            return [
                'status' => 'error',
                'message' => 'Payment gateway not available or not configured'
            ];
        }
        
        // Create transaction record
        $transactionData = $this->createTransaction($paymentData, $gatewaySlug, $userId, $environment);
        
        if (!$transactionData) {
            return [
                'status' => 'error',
                'message' => 'Failed to create transaction record'
            ];
        }
        
        // Initialize payment with gateway
        $paymentResult = $gateway->initializePayment(array_merge($paymentData, [
            'reference' => $transactionData['reference'],
            'transaction_id' => $transactionData['id']
        ]));
        
        // Update transaction with gateway response
        $this->updateTransaction($transactionData['id'], [
            'gateway_reference' => $paymentResult['gateway_reference'] ?? null,
            'gateway_response' => json_encode($paymentResult),
            'status' => $paymentResult['status'] === 'success' ? 'processing' : 'failed'
        ]);
        
        return $paymentResult;
    }
    
    /**
     * Create payment transaction record
     * 
     * @param array $paymentData
     * @param string $gatewaySlug
     * @param int|null $userId
     * @param string $environment
     * @return array|null
     */
    private function createTransaction($paymentData, $gatewaySlug, $userId, $environment) {
        $config = $this->getGatewayConfig($gatewaySlug, $userId, $environment);
        if (!$config) return null;
        
        $reference = $this->generateReference();
        $amount = (float) $paymentData['amount'];
        $currency = $paymentData['currency'] ?? 'NGN';
        
        // Calculate fees (you can implement fee calculation logic here)
        $feeAmount = 0.00;
        $netAmount = $amount - $feeAmount;
        
        $query = "INSERT INTO payment_transactions 
                 (reference, user_id, gateway_id, gateway_config_id, amount, currency, 
                  fee_amount, net_amount, callback_url, redirect_url, metadata) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = mysqli_prepare($this->conn, $query);
        
        $metadata = json_encode($paymentData['metadata'] ?? []);
        $callbackUrl = $paymentData['callback_url'] ?? null;
        $redirectUrl = $paymentData['redirect_url'] ?? null;
        
        mysqli_stmt_bind_param($stmt, "siiidsddsss", 
            $reference, $userId, $config['gateway_id'], $config['id'], 
            $amount, $currency, $feeAmount, $netAmount, 
            $callbackUrl, $redirectUrl, $metadata
        );
        
        if (mysqli_stmt_execute($stmt)) {
            $transactionId = mysqli_insert_id($this->conn);
            return [
                'id' => $transactionId,
                'reference' => $reference,
                'amount' => $amount,
                'currency' => $currency
            ];
        }
        
        return null;
    }
    
    /**
     * Update transaction record
     * 
     * @param int $transactionId
     * @param array $data
     * @return bool
     */
    private function updateTransaction($transactionId, $data) {
        $setParts = [];
        $params = [];
        $types = "";
        
        foreach ($data as $field => $value) {
            $setParts[] = "{$field} = ?";
            $params[] = $value;
            $types .= "s";
        }
        
        if (empty($setParts)) return false;
        
        $query = "UPDATE payment_transactions SET " . implode(', ', $setParts) . " WHERE id = ?";
        $params[] = $transactionId;
        $types .= "i";
        
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        
        return mysqli_stmt_execute($stmt);
    }
    
    /**
     * Verify payment with gateway
     * 
     * @param string $reference
     * @return array
     */
    public function verifyPayment($reference) {
        // Get transaction record
        $query = "SELECT pt.*, pg.slug as gateway_slug, pgc.credentials, pgc.environment 
                 FROM payment_transactions pt 
                 JOIN payment_gateways pg ON pt.gateway_id = pg.id 
                 JOIN payment_gateway_configs pgc ON pt.gateway_config_id = pgc.id 
                 WHERE pt.reference = ?";
        
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, "s", $reference);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $transaction = mysqli_fetch_assoc($result);
        
        if (!$transaction) {
            return [
                'status' => 'error',
                'message' => 'Transaction not found'
            ];
        }
        
        $gateway = $this->getGatewayInstance(
            $transaction['gateway_slug'], 
            $transaction['user_id'], 
            $transaction['environment']
        );
        
        if (!$gateway) {
            return [
                'status' => 'error',
                'message' => 'Gateway not available for verification'
            ];
        }
        
        // Use gateway_reference for verification if available, otherwise fall back to reference
        $verificationReference = $transaction['gateway_reference'] ?: $reference;
        
        $verificationResult = $gateway->verifyPayment($verificationReference);
        
        // Ensure the response includes the original reference for consistency
        $verificationResult['reference'] = $reference;
        
        // Update transaction status
        $updateData = [
            'status' => $verificationResult['status'] === 'success' ? 'completed' : 'failed',
            'gateway_response' => json_encode($verificationResult)
        ];
        
        if ($verificationResult['status'] === 'success') {
            $updateData['processed_at'] = date('Y-m-d H:i:s');
        } else {
            $updateData['failed_at'] = date('Y-m-d H:i:s');
            $updateData['failure_reason'] = $verificationResult['message'] ?? 'Verification failed';
        }
        
        $this->updateTransaction($transaction['id'], $updateData);
        
        return $verificationResult;
    }
    
    /**
     * Generate unique transaction reference
     * 
     * @return string
     */
    private function generateReference() {
        return 'SR_' . strtoupper(uniqid()) . '_' . time();
    }
    
    /**
     * Save gateway configuration
     * 
     * @param string $gatewaySlug
     * @param array $credentials
     * @param int|null $userId
     * @param string $environment
     * @param array $settings
     * @return bool
     */
    public function saveGatewayConfig($gatewaySlug, $credentials, $userId = null, $environment = 'sandbox', $settings = []) {
        if (!isset($this->availableGateways[$gatewaySlug])) {
            return false;
        }
        
        $gatewayId = $this->availableGateways[$gatewaySlug]['id'];
        
        // Encrypt credentials (in production, use proper encryption)
        $encryptedCredentials = json_encode($credentials);
        $settingsJson = json_encode($settings);
        
        // Check if config exists
        $query = "SELECT id FROM payment_gateway_configs 
                 WHERE gateway_id = ? AND environment = ? AND user_id " . ($userId ? "= ?" : "IS NULL");
        
        $stmt = mysqli_prepare($this->conn, $query);
        if ($userId) {
            mysqli_stmt_bind_param($stmt, "isi", $gatewayId, $environment, $userId);
        } else {
            mysqli_stmt_bind_param($stmt, "is", $gatewayId, $environment);
        }
        
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $existing = mysqli_fetch_assoc($result);
        
        if ($existing) {
            // Update existing configuration
            $query = "UPDATE payment_gateway_configs SET credentials = ?, settings = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = mysqli_prepare($this->conn, $query);
            mysqli_stmt_bind_param($stmt, "ssi", $encryptedCredentials, $settingsJson, $existing['id']);
        } else {
            // Insert new configuration
            $query = "INSERT INTO payment_gateway_configs (gateway_id, user_id, environment, credentials, settings) VALUES (?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($this->conn, $query);
            mysqli_stmt_bind_param($stmt, "iisss", $gatewayId, $userId, $environment, $encryptedCredentials, $settingsJson);
        }
        
        return mysqli_stmt_execute($stmt);
    }
}
