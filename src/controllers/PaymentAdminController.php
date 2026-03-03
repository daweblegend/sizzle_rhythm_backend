<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';
require_once APP_ROOT . '/src/services/PaymentGatewayManager.php';

/**
 * Configure Payment Gateway
 * Saves payment gateway credentials and settings for a user or system-wide
 */
function configureGateway() {
    global $conn;
    
    // Verify JWT token and check admin role
    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) {
        return;
    }
    
    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }
    
    try {
        $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);
        
        // Check if user is admin (you can implement this based on your role system)
        $query = "SELECT role FROM users WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        
        if (!$user || $user['role'] !== 'admin') {
            ResponseHandler::error('Access denied. Admin privileges required.', null, 403);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $requiredFields = ['gateway', 'credentials', 'environment'];
        foreach ($requiredFields as $field) {
            if (!isset($input[$field])) {
                ResponseHandler::error("Field '{$field}' is required", null, 400);
                return;
            }
        }
        
        $gateway = strtolower($input['gateway']);
        $credentials = $input['credentials'];
        $environment = $input['environment'];
        $settings = $input['settings'] ?? [];
        $userIdForConfig = $input['user_id'] ?? null; // null for system-wide config
        
        $gatewayManager = new PaymentGatewayManager();
        $success = $gatewayManager->saveGatewayConfig(
            $gateway, 
            $credentials, 
            $userIdForConfig, 
            $environment, 
            $settings
        );
        
        if ($success) {
            ResponseHandler::success('Gateway configured successfully', [
                'gateway' => $gateway,
                'environment' => $environment,
                'user_id' => $userIdForConfig
            ]);
        } else {
            ResponseHandler::error('Failed to save gateway configuration', null, 500);
        }
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to configure gateway', ['error' => $e->getMessage()], 500);
    }
}

/**
 * Get Gateway Configuration
 * Retrieves payment gateway configuration
 */
function getGatewayConfig() {
    global $conn;
    
    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) {
        return;
    }
    
    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }
    
    try {
        $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);
        $gateway = $_GET['gateway'] ?? null;
        $environment = $_GET['environment'] ?? 'sandbox';
        
        if (empty($gateway)) {
            ResponseHandler::error('Gateway parameter is required', null, 400);
            return;
        }
        
        $gatewayManager = new PaymentGatewayManager();
        $config = $gatewayManager->getGatewayConfig($gateway, $userId, $environment);
        
        if ($config) {
            // Remove sensitive credential data for security
            unset($config['credentials']);
            ResponseHandler::success('Gateway configuration retrieved', $config);
        } else {
            ResponseHandler::error('Gateway configuration not found', null, 404);
        }
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to retrieve gateway configuration', ['error' => $e->getMessage()], 500);
    }
}

/**
 * Test Gateway Connection
 * Tests the gateway connection with provided credentials
 */
function testGatewayConnection() {
    global $conn;
    
    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) {
        return;
    }
    
    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $requiredFields = ['gateway', 'credentials', 'environment'];
        foreach ($requiredFields as $field) {
            if (!isset($input[$field])) {
                ResponseHandler::error("Field '{$field}' is required", null, 400);
                return;
            }
        }
        
        $gateway = strtolower($input['gateway']);
        $credentials = $input['credentials'];
        $environment = $input['environment'];
        
        // Create a temporary config for testing
        $tempConfig = [
            'gateway_id' => 1, // dummy
            'slug' => $gateway,
            'credentials' => $credentials,
            'environment' => $environment,
            'settings' => []
        ];
        
        $gatewayClass = ucfirst($gateway) . 'Gateway';
        $gatewayFile = __DIR__ . '/../services/gateways/' . $gatewayClass . '.php';
        
        if (!file_exists($gatewayFile)) {
            ResponseHandler::error('Gateway implementation not found', null, 404);
            return;
        }
        
        require_once $gatewayFile;
        
        if (!class_exists($gatewayClass)) {
            ResponseHandler::error('Gateway class not found', null, 404);
            return;
        }
        
        // Test the gateway connection
        try {
            $gatewayInstance = new $gatewayClass($tempConfig);
            
            ResponseHandler::success('Gateway connection test successful', [
                'gateway' => $gateway,
                'environment' => $environment,
                'supported_methods' => $gatewayInstance->getSupportedMethods(),
                'supported_currencies' => $gatewayInstance->getSupportedCurrencies()
            ]);
            
        } catch (Exception $e) {
            ResponseHandler::error('Gateway connection test failed: ' . $e->getMessage(), null, 400);
        }
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to test gateway connection', ['error' => $e->getMessage()], 500);
    }
}

/**
 * Get Payment Analytics
 * Retrieves payment transaction analytics
 */
function getPaymentAnalytics() {
    global $conn;
    
    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) {
        return;
    }
    
    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
        return;
    }
    
    try {
        $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);
        
        // Check admin access
        $query = "SELECT role FROM users WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        
        if (!$user || $user['role'] !== 'admin') {
            ResponseHandler::error('Access denied. Admin privileges required.', null, 403);
            return;
        }
        
        $period = $_GET['period'] ?? '30'; // days
        $dateFrom = date('Y-m-d H:i:s', strtotime("-{$period} days"));
        
        // Total transactions
        $query = "SELECT 
                    COUNT(*) as total_transactions,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_transactions,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_transactions,
                    SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_amount,
                    AVG(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as average_amount
                  FROM payment_transactions 
                  WHERE created_at >= ?";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "s", $dateFrom);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $summary = mysqli_fetch_assoc($result);
        
        // Gateway breakdown
        $query = "SELECT 
                    pg.name,
                    pg.display_name,
                    COUNT(*) as transaction_count,
                    SUM(CASE WHEN pt.status = 'completed' THEN pt.amount ELSE 0 END) as total_amount
                  FROM payment_transactions pt
                  JOIN payment_gateways pg ON pt.gateway_id = pg.id
                  WHERE pt.created_at >= ?
                  GROUP BY pg.id, pg.name, pg.display_name";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "s", $dateFrom);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $gatewayBreakdown = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $gatewayBreakdown[] = $row;
        }
        
        // Daily transactions for the period
        $query = "SELECT 
                    DATE(created_at) as transaction_date,
                    COUNT(*) as transaction_count,
                    SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as daily_amount
                  FROM payment_transactions 
                  WHERE created_at >= ?
                  GROUP BY DATE(created_at)
                  ORDER BY transaction_date";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "s", $dateFrom);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $dailyStats = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $dailyStats[] = $row;
        }
        
        ResponseHandler::success('Payment analytics retrieved successfully', [
            'period_days' => (int) $period,
            'summary' => [
                'total_transactions' => (int) $summary['total_transactions'],
                'successful_transactions' => (int) $summary['successful_transactions'],
                'failed_transactions' => (int) $summary['failed_transactions'],
                'success_rate' => $summary['total_transactions'] > 0 
                    ? round(($summary['successful_transactions'] / $summary['total_transactions']) * 100, 2)
                    : 0,
                'total_amount' => (float) $summary['total_amount'],
                'average_amount' => (float) $summary['average_amount']
            ],
            'gateway_breakdown' => $gatewayBreakdown,
            'daily_stats' => $dailyStats
        ]);
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to retrieve payment analytics', ['error' => $e->getMessage()], 500);
    }
}

// Determine which function to call based on the action
$action = $_REQUEST['action'] ?? 'getPaymentGateways';

switch ($action) {
    case 'configureGateway':
        configureGateway();
        break;
    case 'getGatewayConfig':
        getGatewayConfig();
        break;
    case 'testGatewayConnection':
        testGatewayConnection();
        break;
    case 'getPaymentAnalytics':
        getPaymentAnalytics();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
