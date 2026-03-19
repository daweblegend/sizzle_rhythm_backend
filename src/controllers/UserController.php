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
    $query = "SELECT id, uuid, first_name, last_name, username, email, phone, role, balance, avatar, email_verified, phone_verified, is_active, created_at, updated_at FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    if (!$user) {
        ResponseHandler::error('User not found', null, 404);
        return;
    }

    // formatted balance
    $user['formatted_balance'] = number_format($user['balance'], 2);

    // If user is a vendor, append their store profile
    if ($user['role'] === 'vendor') {
        $vQuery = "SELECT * FROM vendors WHERE user_id = ? LIMIT 1";
        $vStmt = mysqli_prepare($conn, $vQuery);
        mysqli_stmt_bind_param($vStmt, "i", $userId);
        mysqli_stmt_execute($vStmt);
        $vResult = mysqli_stmt_get_result($vStmt);
        $vendor = mysqli_fetch_assoc($vResult);

        if ($vendor) {
            // Decode JSON fields
            foreach (['cuisine_type', 'tags', 'opening_hours', 'currency'] as $field) {
                if (isset($vendor[$field]) && is_string($vendor[$field])) {
                    $vendor[$field] = json_decode($vendor[$field], true);
                }
            }
            // Cast numeric / boolean fields
            $vendor['minimum_order']      = (float)($vendor['minimum_order'] ?? 0);
            $vendor['delivery_fee']       = (float)($vendor['delivery_fee'] ?? 0);
            $vendor['delivery_radius_km'] = $vendor['delivery_radius_km'] !== null ? (float)$vendor['delivery_radius_km'] : null;
            $vendor['average_rating']     = (float)$vendor['average_rating'];
            $vendor['total_orders']       = (int)$vendor['total_orders'];
            $vendor['total_reviews']      = (int)$vendor['total_reviews'];
            $vendor['is_open']            = (bool)$vendor['is_open'];
            $vendor['delivery_available'] = (bool)$vendor['delivery_available'];
            $vendor['pickup_available']   = (bool)$vendor['pickup_available'];
            $vendor['is_verified']        = (bool)$vendor['is_verified'];
            $vendor['is_active']          = (bool)$vendor['is_active'];

            $user['vendor_profile'] = $vendor;
        } else {
            $user['vendor_profile'] = null;
        }
    }

    // If user is a team_member, append their team info and the vendor's store profile
    if ($user['role'] === 'team_member') {
        $tmQuery = "
            SELECT tm.id AS team_member_id, tm.uuid AS team_member_uuid, tm.team_role, tm.permissions,
                   tm.status AS team_status, tm.invited_at, tm.joined_at,
                   v.*, v.id AS vendor_id
            FROM vendor_team_members tm
            JOIN vendors v ON v.id = tm.vendor_id
            WHERE tm.user_id = ? AND tm.status IN ('invited', 'active')
            LIMIT 1
        ";
        $tmStmt = mysqli_prepare($conn, $tmQuery);
        mysqli_stmt_bind_param($tmStmt, "i", $userId);
        mysqli_stmt_execute($tmStmt);
        $tmRow = mysqli_fetch_assoc(mysqli_stmt_get_result($tmStmt));

        if ($tmRow) {
            // Extract team-specific fields
            $user['team_info'] = [
                'team_member_id'   => (int)$tmRow['team_member_id'],
                'team_member_uuid' => $tmRow['team_member_uuid'],
                'team_role'        => $tmRow['team_role'],
                'permissions'      => json_decode($tmRow['permissions'], true) ?? [],
                'team_status'      => $tmRow['team_status'],
                'invited_at'       => $tmRow['invited_at'],
                'joined_at'        => $tmRow['joined_at'],
            ];

            // Build vendor store profile from the same row
            $vendorFields = [
                'vendor_id', 'uuid', 'user_id', 'business_name', 'slug', 'description', 'logo', 'banner_image',
                'contact_email', 'contact_phone', 'address', 'city', 'state', 'country', 'currency', 'latitude', 'longitude',
                'cuisine_type', 'tags', 'preparation_time', 'minimum_order', 'is_open', 'opening_hours',
                'delivery_available', 'pickup_available', 'delivery_fee', 'delivery_radius_km',
                'estimated_delivery_time', 'total_orders', 'total_reviews', 'average_rating',
                'is_verified', 'verified_at', 'is_active', 'created_at', 'updated_at'
            ];
            $vendor = [];
            foreach ($vendorFields as $f) {
                $key = ($f === 'vendor_id') ? 'id' : $f;
                $vendor[$key] = $tmRow[$f] ?? null;
            }

            // Decode JSON fields
            foreach (['cuisine_type', 'tags', 'opening_hours', 'currency'] as $field) {
                if (isset($vendor[$field]) && is_string($vendor[$field])) {
                    $vendor[$field] = json_decode($vendor[$field], true);
                }
            }
            // Cast numeric / boolean fields
            $vendor['minimum_order']      = (float)($vendor['minimum_order'] ?? 0);
            $vendor['delivery_fee']       = (float)($vendor['delivery_fee'] ?? 0);
            $vendor['delivery_radius_km'] = $vendor['delivery_radius_km'] !== null ? (float)$vendor['delivery_radius_km'] : null;
            $vendor['average_rating']     = (float)($vendor['average_rating'] ?? 0);
            $vendor['total_orders']       = (int)($vendor['total_orders'] ?? 0);
            $vendor['total_reviews']      = (int)($vendor['total_reviews'] ?? 0);
            $vendor['is_open']            = (bool)($vendor['is_open'] ?? false);
            $vendor['delivery_available'] = (bool)($vendor['delivery_available'] ?? false);
            $vendor['pickup_available']   = (bool)($vendor['pickup_available'] ?? false);
            $vendor['is_verified']        = (bool)($vendor['is_verified'] ?? false);
            $vendor['is_active']          = (bool)($vendor['is_active'] ?? false);

            $user['vendor_profile'] = $vendor;
        } else {
            $user['team_info']      = null;
            $user['vendor_profile'] = null;
        }
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
    if (!empty($requestBody['first_name'])) $fields[] = "first_name='" . mysqli_real_escape_string($conn, $requestBody['first_name']) . "'";
    if (!empty($requestBody['last_name'])) $fields[] = "last_name='" . mysqli_real_escape_string($conn, $requestBody['last_name']) . "'";
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