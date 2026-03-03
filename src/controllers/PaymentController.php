<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';
require_once APP_ROOT . '/src/services/PaymentGatewayManager.php';

/**
 * Initialize Payment
 * Creates a new payment transaction using the specified gateway
 */
function initializePayment() {
    global $conn;
    
    // Verify JWT token using UtilHandler
    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) {
        return; // Error response already sent by verifyJWTToken
    }
    
    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }
    
    try {
        $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $requiredFields = ['amount', 'currency', 'customer_email', 'gateway'];
        foreach ($requiredFields as $field) {
            if (empty($input[$field])) {
                ResponseHandler::error("Field '{$field}' is required", null, 400);
                return;
            }
        }
        
        // Initialize payment gateway manager
        $gatewayManager = new PaymentGatewayManager();
        
        $paymentData = [
            'amount' => (float) $input['amount'],
            'currency' => strtoupper($input['currency']),
            'customer_email' => $input['customer_email'],
            'customer_name' => $input['customer_name'] ?? '',
            'description' => $input['description'] ?? '',
            'callback_url' => $input['callback_url'] ?? '',
            'redirect_url' => $input['redirect_url'] ?? '',
            'metadata' => $input['metadata'] ?? []
        ];
        
        $environment = $input['environment'] ?? 'sandbox';
        $gateway = strtolower($input['gateway']);
        
        $result = $gatewayManager->initializePayment($gateway, $paymentData, $userId, $environment);
        
        if ($result['status'] === 'success') {
            ResponseHandler::success('Payment initialized successfully', $result);
        } else {
            ResponseHandler::error($result['message'] ?? 'Payment initialization failed', $result, 400);
        }
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to initialize payment', ['error' => $e->getMessage()], 500);
    }
}

/**
 * Verify Payment
 * Verifies a payment transaction using the gateway
 */
function verifyPayment() {
    global $conn;
    
    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }
    
    try {
        $reference = $_GET['reference'] ?? $_POST['reference'] ?? null;
        
        if (empty($reference)) {
            ResponseHandler::error('Payment reference is required', null, 400);
            return;
        }
        
        $reference = UtilHandler::sanitizeInput($conn, $reference);
        
        $gatewayManager = new PaymentGatewayManager();
        $result = $gatewayManager->verifyPayment($reference);
        
        if ($result['status'] === 'success') {
            ResponseHandler::success('Payment verified successfully', $result);
        } else {
            ResponseHandler::error($result['message'] ?? 'Payment verification failed', $result, 400);
        }
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to verify payment', ['error' => $e->getMessage()], 500);
    }
}

/**
 * Handle Payment Webhook
 * Processes webhook notifications from payment gateways
 */
function handleWebhook() {
    global $conn;
    
    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }
    
    try {
        $gateway = $_GET['gateway'] ?? null;
        
        if (empty($gateway)) {
            ResponseHandler::error('Gateway parameter is required', null, 400);
            return;
        }
        
        $payload = json_decode(file_get_contents('php://input'), true);
        
        if (empty($payload)) {
            ResponseHandler::error('Invalid webhook payload', null, 400);
            return;
        }
        
        // Log webhook for debugging
        error_log("Webhook received for gateway: {$gateway} - " . json_encode($payload));
        
        $gatewayManager = new PaymentGatewayManager();
        $gatewayInstance = $gatewayManager->getGatewayInstance($gateway);
        
        if (!$gatewayInstance) {
            ResponseHandler::error('Gateway not found or not configured', null, 404);
            return;
        }
        
        $result = $gatewayInstance->handleWebhook($payload);
        
        // Log webhook to database
        $query = "INSERT INTO webhook_logs (gateway_id, event_type, payload, ip_address, status) 
                 SELECT pg.id, ?, ?, ?, ? FROM payment_gateways pg WHERE pg.slug = ?";
        
        $stmt = mysqli_prepare($conn, $query);
        $eventType = $payload['event'] ?? $payload['eventType'] ?? 'unknown';
        $payloadJson = json_encode($payload);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $status = $result['status'] === 'success' ? 'processed' : 'failed';
        
        mysqli_stmt_bind_param($stmt, "sssss", $eventType, $payloadJson, $ipAddress, $status, $gateway);
        mysqli_stmt_execute($stmt);
        
        if ($result['status'] === 'success') {
            ResponseHandler::success('Webhook processed successfully', $result);
        } else {
            ResponseHandler::error($result['message'] ?? 'Webhook processing failed', $result, 400);
        }
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to process webhook', ['error' => $e->getMessage()], 500);
    }
}

/**
 * Get Payment History
 * Retrieves payment transaction history for a user
 */
function getPaymentHistory() {
    global $conn;
    
    // Verify JWT token using UtilHandler
    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) {
        return; // Error response already sent by verifyJWTToken
    }
    
    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }
    
    try {
        $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);
        $limit = (int) ($_GET['limit'] ?? 20);
        $offset = (int) ($_GET['offset'] ?? 0);
        $status = $_GET['status'] ?? null;
        
        $whereClause = "WHERE pt.user_id = ?";
        $params = [$userId];
        $types = "i";
        
        if ($status) {
            $whereClause .= " AND pt.status = ?";
            $params[] = $status;
            $types .= "s";
        }
        
        $query = "SELECT pt.*, pg.name as gateway_name, pg.display_name 
                 FROM payment_transactions pt 
                 JOIN payment_gateways pg ON pt.gateway_id = pg.id 
                 {$whereClause} 
                 ORDER BY pt.created_at DESC 
                 LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $transactions = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $row['metadata'] = json_decode($row['metadata'] ?? '{}', true);
            $row['gateway_response'] = json_decode($row['gateway_response'] ?? '{}', true);
            $transactions[] = $row;
        }
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM payment_transactions pt {$whereClause}";
        $countStmt = mysqli_prepare($conn, $countQuery);
        $countTypes = substr($types, 0, -2); // Remove limit and offset types
        $countParams = array_slice($params, 0, -2); // Remove limit and offset params
        
        if (!empty($countParams)) {
            mysqli_stmt_bind_param($countStmt, $countTypes, ...$countParams);
        }
        
        mysqli_stmt_execute($countStmt);
        $countResult = mysqli_stmt_get_result($countStmt);
        $total = mysqli_fetch_assoc($countResult)['total'];
        
        ResponseHandler::success('Payment history retrieved successfully', [
            'transactions' => $transactions,
            'pagination' => [
                'total' => (int) $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total
            ]
        ]);
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to retrieve payment history', ['error' => $e->getMessage()], 500);
    }
}

/**
 * Get Available Payment Gateways
 * Returns list of available and configured payment gateways
 */
function getPaymentGateways() {
    global $conn;
    
    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }
    
    try {
        $gatewayManager = new PaymentGatewayManager();
        $gateways = $gatewayManager->getAvailableGateways();
        
        // Add configuration status for each gateway
        $userId = null;
        $tokenData = UtilHandler::verifyJWTToken(false); // Don't return error if no token
        if ($tokenData) {
            $userId = $tokenData['userId'];
        }
        
        foreach ($gateways as &$gateway) {
            $gateway['configuration_fields'] = json_decode($gateway['configuration_fields'] ?? '{}', true);
            $gateway['supported_currencies'] = json_decode($gateway['supported_currencies'] ?? '[]', true);
            $gateway['supported_countries'] = json_decode($gateway['supported_countries'] ?? '[]', true);
            
            // Check if gateway is configured
            $config = $gatewayManager->getGatewayConfig($gateway['slug'], $userId);
            $gateway['is_configured'] = !empty($config);
        }
        
        ResponseHandler::success('Payment gateways retrieved successfully', [
            'gateways' => $gateways
        ]);
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to retrieve payment gateways', ['error' => $e->getMessage()], 500);
    }
}

/**
 * Handle Payment Callback/Redirect
 * Handles user redirect after payment completion
 */
function handlePaymentCallback() {
    global $conn;
    
    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }
    
    try {
        $reference = $_GET['reference'] ?? $_POST['reference'] ?? null;
        $status = $_GET['status'] ?? $_POST['status'] ?? null;
        
        if (empty($reference)) {
            ResponseHandler::error('Payment reference is required', null, 400);
            return;
        }
        
        // Verify the payment
        $gatewayManager = new PaymentGatewayManager();
        $result = $gatewayManager->verifyPayment($reference);
        
        // For web redirects, you might want to redirect to a frontend page
        // For API calls, return JSON response
        if (isset($_GET['redirect']) && $_GET['redirect'] === 'web') {
            $frontendUrl = "https://yourfrontend.com/payment-status";
            $statusParam = $result['status'] === 'success' ? 'success' : 'failed';
            header("Location: {$frontendUrl}?status={$statusParam}&reference={$reference}");
            exit;
        } else {
            ResponseHandler::success('Payment callback processed', $result);
        }
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to process payment callback', ['error' => $e->getMessage()], 500);
    }
}


// Determine which function to call based on the action
$action = $_REQUEST['action'] ?? 'getPaymentGateways';

switch ($action) {
    case 'initializePayment':
        initializePayment();
        break;
    case 'verifyPayment':
        verifyPayment();
        break;
    case 'handleWebhook':
        handleWebhook();
        break;
    case 'getPaymentHistory':
        getPaymentHistory();
        break;
    case 'getPaymentGateways':
        getPaymentGateways();
        break;
    case 'handlePaymentCallback':
        handlePaymentCallback();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
