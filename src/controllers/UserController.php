<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';


// ========================
// USER ACCOUNT FUNCTIONS
// ========================

function getUserProfile() {
    global $conn;
    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) return;
    $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);
    $query = "SELECT id, uuid, name, email, phone, photo, balance, email_verified, phone_verified, is_active, created_at, updated_at FROM users WHERE id = '$userId'";
    $result = mysqli_query($conn, $query);
    $user = mysqli_fetch_assoc($result);

    // formatted balance
    $user['formatted_balance'] = number_format($user['balance'], 2);
    
    if (!$user) {
        ResponseHandler::error('User not found', null, 404);
        return;
    }
    ResponseHandler::success('Profile retrieved successfully', $user);
}

function updateUserProfile() {
    global $conn;
    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) return;
    $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);
    $requestBody = json_decode(file_get_contents('php://input'), true);
    $fields = [];
    if (!empty($requestBody['name'])) $fields[] = "name='" . mysqli_real_escape_string($conn, $requestBody['name']) . "'";
    if (!empty($requestBody['phone'])) $fields[] = "phone='" . mysqli_real_escape_string($conn, $requestBody['phone']) . "'";
    if (empty($fields)) {
        ResponseHandler::error('No fields to update');
        return;
    }
    $updateSql = "UPDATE users SET " . implode(', ', $fields) . ", updated_at=NOW() WHERE id='$userId'";
    if (mysqli_query($conn, $updateSql)) {
        ResponseHandler::success('Profile updated successfully');
    } else {
        ResponseHandler::error('Failed to update profile');
    }
}

function changeUserPassword() {
    global $conn;
    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) return;
    $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);
    $requestBody = json_decode(file_get_contents('php://input'), true);
    if (empty($requestBody['oldPassword']) || empty($requestBody['newPassword']) || empty($requestBody['confirmPassword'])) {
        ResponseHandler::error('Old, new, and confirm password are required');
        return;
    }
    $oldPassword = $requestBody['oldPassword'];
    $newPassword = $requestBody['newPassword'];
    $confirmPassword = $requestBody['confirmPassword'];
    if ($newPassword !== $confirmPassword) {
        ResponseHandler::error('New and confirm password do not match');
        return;
    }
    if (strlen($newPassword) < 6) {
        ResponseHandler::error('New password must be at least 6 characters');
        return;
    }
    $query = "SELECT password FROM users WHERE id='$userId'";
    $result = mysqli_query($conn, $query);
    $user = mysqli_fetch_assoc($result);
    if (!$user || !password_verify($oldPassword, $user['password'])) {
        ResponseHandler::error('Old password is incorrect');
        return;
    }
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateSql = "UPDATE users SET password='$hashedPassword', updated_at=NOW() WHERE id='$userId'";
    if (mysqli_query($conn, $updateSql)) {
        ResponseHandler::success('Password changed successfully');
    } else {
        ResponseHandler::error('Failed to change password');
    }
}

$action = $_REQUEST['action'] ?? 'getUserProfile';

switch ($action) {
    case 'getUserProfile':
        getUserProfile();
        break;
    case 'updateUserProfile':
        updateUserProfile();
        break;
    case 'changeUserPassword':
        changeUserPassword();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}