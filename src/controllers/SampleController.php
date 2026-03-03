<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';

// This is just a sample controller to demonstrate structure
// You can expand this with actual functionality as needed
function sampleFunction() {
    global $conn;
    
    // Verify JWT token using UtilHandler
    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) {
        return; // Error response already sent by verifyJWTToken
    }
    
    // Ensure database connection is available
    if (!$conn) {
        ResponseHandler::error('Database connection not available', null, 500);
    }
    
    try {
        $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);
        
        // Get user profile
        $query = "SELECT * FROM users WHERE _id = '$userId'";
        $result = mysqli_query($conn, $query);
        $user = mysqli_fetch_assoc($result);
        
        if (!$user) {
            ResponseHandler::error('User not found', null, 404);
        }
        
        
        ResponseHandler::success('Profile retrieved successfully', $responseData);
        
    } catch (Exception $e) {
        ResponseHandler::error('Failed to retrieve profile', ['error' => $e->getMessage()]);
    }
}


// Determine which function to call based on the action
$action = $_REQUEST['action'] ?? 'sampleFunction';

switch ($action) {
    case 'sampleFunction':
    case 'sampleFunction':
        sampleFunction();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
