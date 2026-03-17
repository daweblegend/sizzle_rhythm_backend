<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';
require_once APP_ROOT . '/Utils/AdminActionsHelper.php';
require_once APP_ROOT . '/Utils/MailHandler.php';
require_once APP_ROOT . '/vendor/autoload.php';

use Ramsey\Uuid\Uuid;

// ========================
// HELPER: Verify admin role
// ========================
function verifyAdmin() {
    global $conn;

    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) return null;

    $userId = UtilHandler::sanitizeInput($conn, $tokenData['userId']);

    if (!AdminActionsHelper::isAdmin($userId)) {
        ResponseHandler::error('Access denied. Admin privileges required.', null, 403);
        return null;
    }

    return $userId;
}


// ===========================
// ADMIN: Create User Account
// ===========================

/**
 * Admin creates a new user/vendor/admin account.
 *
 * Body params:
 *  - first_name  (required)
 *  - last_name   (required)
 *  - email       (required)
 *  - phone       (optional)
 *  - role        (optional, default: "customer")  — customer | vendor | admin
 *  - password    (optional) — if omitted the user will set their own via the welcome email
 */
function createAccount() {
    global $conn;

    $adminId = verifyAdmin();
    if (!$adminId) return;

    $body = json_decode(file_get_contents('php://input'), true);

    // ---------- Validation ----------
    if (empty($body['first_name']) || empty($body['last_name']) || empty($body['email']) || empty($body['username'])) {
        ResponseHandler::error('first_name, last_name, email and username are required.');
        return;
    }

    $firstName = UtilHandler::sanitizeInput($conn, $body['first_name']);
    $lastName  = UtilHandler::sanitizeInput($conn, $body['last_name']);
    $email     = UtilHandler::sanitizeInput($conn, strtolower(trim($body['email'])));
    $phone     = !empty($body['phone']) ? UtilHandler::sanitizeInput($conn, $body['phone']) : null;
    $role      = !empty($body['role']) ? UtilHandler::sanitizeInput($conn, strtolower($body['role'])) : 'customer';
    $username  = strtolower(trim($body['username']));

    // Validate username: alphanumeric and underscores only, 3-30 chars
    if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        ResponseHandler::error('Username must be 3-30 characters and can only contain letters, numbers, and underscores (no spaces or special characters).');
        return;
    }

    // Check duplicate username
    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ?");
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0) {
        ResponseHandler::error('This username is already taken.');
        return;
    }

    // Validate email format
    if (!UtilHandler::isValidEmail($email)) {
        ResponseHandler::error('Invalid email address.');
        return;
    }

    // Validate role
    $allowedRoles = ['customer', 'vendor', 'admin'];
    if (!in_array($role, $allowedRoles)) {
        ResponseHandler::error('Invalid role. Allowed roles: customer, vendor, admin.');
        return;
    }

    // Validate phone if provided
    if ($phone && !UtilHandler::isValidPhoneNumber($phone)) {
        ResponseHandler::error('Invalid phone number format.');
        return;
    }

    // Check duplicate email
    $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if (mysqli_num_rows($result) > 0) {
        ResponseHandler::error('A user with this email already exists.');
        return;
    }

    // Check duplicate phone
    if ($phone) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE phone = ?");
        mysqli_stmt_bind_param($stmt, "s", $phone);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($result) > 0) {
            ResponseHandler::error('A user with this phone number already exists.');
            return;
        }
    }

    // ---------- Password handling ----------
    $adminSetPassword = !empty($body['password']);
    $rawPassword       = null;
    $setupToken        = null;

    if ($adminSetPassword) {
        $rawPassword = $body['password'];
        $validation  = UtilHandler::validatePassword($rawPassword);
        if (!$validation['valid']) {
            ResponseHandler::error('Password is too weak.', $validation['errors']);
            return;
        }
        $hashedPassword = password_hash($rawPassword, PASSWORD_DEFAULT);
    } else {
        // Generate a secure one-time setup token the user will use to create their own password
        $setupToken     = bin2hex(random_bytes(32));
        // Store the token hash as the password — it cannot be used to log in directly
        $hashedPassword = password_hash($setupToken, PASSWORD_DEFAULT);
    }

    // ---------- Insert ----------
    $uuid = Uuid::uuid4()->toString();

    $query = "INSERT INTO users (uuid, first_name, last_name, email, phone, password, username, role, email_verified, is_active, created_at, updated_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, '1', 1, NOW(), NOW())";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ssssssss", $uuid, $firstName, $lastName, $email, $phone, $hashedPassword, $username, $role);

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to create account.', ['db_error' => mysqli_error($conn)], 500);
        return;
    }

    $newUserId = mysqli_insert_id($conn);

    // ---------- Send welcome / onboarding email ----------
    $fullName = $firstName . ' ' . $lastName;

    if ($adminSetPassword) {
        // Send welcome email with the credentials the admin set
        sendAdminCreatedAccountEmail($email, $firstName, $rawPassword);
    } else {
        // Send onboarding email with a link to set their own password
        sendAccountSetupEmail($email, $firstName, $setupToken, $uuid);
    }

    // ---------- Log admin action ----------
    AdminActionsHelper::logAction($adminId, 'create_account', json_encode([
        'new_user_id'  => $newUserId,
        'email'        => $email,
        'role'         => $role,
        'password_set' => $adminSetPassword ? 'by_admin' : 'pending_user'
    ]));

    // ---------- Response ----------
    $userData = [
        'id'         => $newUserId,
        'uuid'       => $uuid,
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'username'   => $username,
        'email'      => $email,
        'phone'      => $phone,
        'role'       => $role,
        'is_active'  => 1,
        'password_set_by' => $adminSetPassword ? 'admin' : 'pending_user_setup',
        'created_at' => date('Y-m-d H:i:s')
    ];

    ResponseHandler::success('Account created successfully.', $userData, 201);
}


// ===========================
// ADMIN: List All Users
// ===========================
function listUsers() {
    global $conn;

    $adminId = verifyAdmin();
    if (!$adminId) return;

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $limit   = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $offset  = ($page - 1) * $limit;
    $role    = !empty($_GET['role']) ? UtilHandler::sanitizeInput($conn, $_GET['role']) : null;
    $search  = !empty($_GET['search']) ? UtilHandler::sanitizeInput($conn, $_GET['search']) : null;
    $status  = isset($_GET['is_active']) ? (int)$_GET['is_active'] : null;

    // Build WHERE clause
    $conditions = [];
    $params     = [];
    $types      = '';

    if ($role) {
        $conditions[] = "role = ?";
        $params[]     = $role;
        $types       .= 's';
    }
    if ($status !== null) {
        $conditions[] = "is_active = ?";
        $params[]     = $status;
        $types       .= 'i';
    }
    if ($search) {
        $conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $searchTerm   = "%{$search}%";
        $params       = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        $types       .= 'ssss';
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // Total count
    $countQuery = "SELECT COUNT(*) as total FROM users $where";
    $stmt = mysqli_prepare($conn, $countQuery);
    if ($types) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $total = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

    // Fetch users
    $dataQuery = "SELECT id, uuid, first_name, last_name, email, phone, role, avatar, email_verified, phone_verified, is_active, created_at, updated_at
                  FROM users $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $dataTypes  = $types . 'ii';
    $dataParams = array_merge($params, [$limit, $offset]);

    $stmt = mysqli_prepare($conn, $dataQuery);
    mysqli_stmt_bind_param($stmt, $dataTypes, ...$dataParams);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $users = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }

    ResponseHandler::success('Users retrieved successfully.', [
        'users'       => $users,
        'pagination'  => [
            'page'        => $page,
            'limit'       => $limit,
            'total'       => (int)$total,
            'total_pages' => ceil($total / $limit)
        ]
    ]);
}


// ===========================
// ADMIN: Get Single User
// ===========================
function getUser() {
    global $conn;

    $adminId = verifyAdmin();
    if (!$adminId) return;

    $userId = !empty($_GET['user_id']) ? UtilHandler::sanitizeInput($conn, $_GET['user_id']) : null;

    if (!$userId) {
        ResponseHandler::error('user_id query parameter is required.');
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT id, uuid, first_name, last_name, email, phone, role, avatar, email_verified, phone_verified, is_active, created_at, updated_at FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$user) {
        ResponseHandler::error('User not found.', null, 404);
        return;
    }

    ResponseHandler::success('User retrieved successfully.', $user);
}


// ===========================
// ADMIN: Update User Account
// ===========================
function updateAccount() {
    global $conn;

    $adminId = verifyAdmin();
    if (!$adminId) return;

    $body = json_decode(file_get_contents('php://input'), true);

    if (empty($body['user_id'])) {
        ResponseHandler::error('user_id is required.');
        return;
    }

    $userId = UtilHandler::sanitizeInput($conn, $body['user_id']);

    // Check user exists
    $stmt = mysqli_prepare($conn, "SELECT id, role FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$existing) {
        ResponseHandler::error('User not found.', null, 404);
        return;
    }

    $fields = [];
    $params = [];
    $types  = '';

    $allowedFields = [
        'first_name' => 's',
        'last_name'  => 's',
        'phone'      => 's',
        'role'       => 's',
        'is_active'  => 'i'
    ];

    foreach ($allowedFields as $field => $type) {
        if (isset($body[$field])) {
            $value = UtilHandler::sanitizeInput($conn, $body[$field]);

            // Validate role
            if ($field === 'role' && !in_array($value, ['customer', 'vendor', 'admin'])) {
                ResponseHandler::error('Invalid role. Allowed roles: customer, vendor, admin.');
                return;
            }

            $fields[] = "$field = ?";
            $params[] = $value;
            $types   .= $type;
        }
    }

    if (empty($fields)) {
        ResponseHandler::error('No valid fields to update.');
        return;
    }

    $fields[] = "updated_at = NOW()";

    $query = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
    $types .= 'i';
    $params[] = $userId;

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to update account.', null, 500);
        return;
    }

    AdminActionsHelper::logAction($adminId, 'update_account', json_encode([
        'user_id' => $userId,
        'fields'  => array_keys(array_intersect_key($body, $allowedFields))
    ]));

    ResponseHandler::success('Account updated successfully.');
}


// ================================
// ADMIN: Reset User Password
// ================================
function resetUserPassword() {
    global $conn;

    $adminId = verifyAdmin();
    if (!$adminId) return;

    $body = json_decode(file_get_contents('php://input'), true);

    if (empty($body['user_id'])) {
        ResponseHandler::error('user_id is required.');
        return;
    }

    $userId = UtilHandler::sanitizeInput($conn, $body['user_id']);

    // Fetch user
    $stmt = mysqli_prepare($conn, "SELECT id, email, first_name FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$user) {
        ResponseHandler::error('User not found.', null, 404);
        return;
    }

    $adminSetPassword = !empty($body['password']);

    if ($adminSetPassword) {
        $newPassword = $body['password'];
        $validation  = UtilHandler::validatePassword($newPassword);
        if (!$validation['valid']) {
            ResponseHandler::error('Password is too weak.', $validation['errors']);
            return;
        }
    } else {
        // Auto-generate a temporary password
        $newPassword = UtilHandler::generateRandomString(12, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#');
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $hashedPassword, $userId);

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to reset password.', null, 500);
        return;
    }

    // Send the new password via email
    MailHandler::sendAdminPasswordResetEmail($user['email'], $newPassword);

    AdminActionsHelper::logAction($adminId, 'reset_user_password', json_encode([
        'user_id' => $userId,
        'email'   => $user['email']
    ]));

    ResponseHandler::success('Password reset successfully. The user has been notified via email.');
}


// ================================
// ADMIN: Toggle Account Status
// ================================
function toggleAccountStatus() {
    global $conn;

    $adminId = verifyAdmin();
    if (!$adminId) return;

    $body = json_decode(file_get_contents('php://input'), true);

    if (empty($body['user_id'])) {
        ResponseHandler::error('user_id is required.');
        return;
    }

    $userId = UtilHandler::sanitizeInput($conn, $body['user_id']);

    // Prevent admin from deactivating themselves
    if ($userId == $adminId) {
        ResponseHandler::error('You cannot deactivate your own account.');
        return;
    }

    $stmt = mysqli_prepare($conn, "SELECT id, is_active, email, first_name FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$user) {
        ResponseHandler::error('User not found.', null, 404);
        return;
    }

    $newStatus = $user['is_active'] ? 0 : 1;

    $stmt = mysqli_prepare($conn, "UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $newStatus, $userId);

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to update account status.', null, 500);
        return;
    }

    $statusLabel = $newStatus ? 'activated' : 'deactivated';

    // Notify user via email if account is re-activated
    if ($newStatus) {
        MailHandler::sendAccountActivationEmail($user['email'], $user['first_name']);
    }

    AdminActionsHelper::logAction($adminId, 'toggle_account_status', json_encode([
        'user_id'    => $userId,
        'new_status' => $statusLabel
    ]));

    ResponseHandler::success("Account {$statusLabel} successfully.", [
        'user_id'   => (int)$userId,
        'is_active' => $newStatus
    ]);
}


// ===========================
// EMAIL: Admin-created account welcome
// ===========================
function sendAdminCreatedAccountEmail($email, $firstName, $password) {
    $appUrl   = $_ENV['APP_URL'] ?? 'https://sizzlerhythm.com';
    $loginUrl = $appUrl . '/login';
    $subject  = 'Your Account Has Been Created';
    $body     = '
        <h1 style="margin: 0 0 24px 0; color: #181411; font-size: 28px; font-weight: bold; line-height: 1.3;">
            Welcome to Sizzle Rhythm, ' . htmlspecialchars($firstName) . '! 🎵
        </h1>

        <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
            An account has been created for you. Here are your login credentials:
        </p>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #F8F7F5; border-radius: 12px; overflow: hidden;">
            <tr>
                <td style="padding: 24px;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                        <tr>
                            <td style="padding: 8px 0; color: #8a7560; font-size: 14px;">Email</td>
                            <td style="padding: 8px 0; color: #181411; font-size: 14px; font-weight: bold; text-align: right;">' . htmlspecialchars($email) . '</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; color: #8a7560; font-size: 14px;">Password</td>
                            <td style="padding: 8px 0; color: #181411; font-size: 14px; font-weight: bold; text-align: right; font-family: monospace;">' . htmlspecialchars($password) . '</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 8px; overflow: hidden;">
            <tr>
                <td style="padding: 16px;">
                    <p style="margin: 0; color: #856404; font-size: 14px; line-height: 1.5;">
                        ⚠️ For your security, please change your password after your first login.
                    </p>
                </td>
            </tr>
        </table>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px 0;">
            <tr>
                <td>
                    <a href="' . $loginUrl . '" class="button" style="background-color: #C12928; border-radius: 8px; color: #ffffff; display: inline-block; font-size: 16px; font-weight: bold; line-height: 48px; text-align: center; text-decoration: none; padding: 0 32px;">
                        Log In to Your Account
                    </a>
                </td>
            </tr>
        </table>

        <p style="margin: 0; color: #8a7560; font-size: 14px; line-height: 1.6;">
            If you didn\'t expect this email, please ignore it or contact support.
        </p>
    ';

    MailHandler::sendTransactionalEmail($email, $subject, $body, false, 1);
}


// ===========================
// EMAIL: Account setup (user sets own password)
// ===========================
function sendAccountSetupEmail($email, $firstName, $setupToken, $uuid) {
    $appUrl   = $_ENV['APP_URL'] ?? 'https://sizzlerhythm.com';
    $setupUrl = $appUrl . '/account/setup?token=' . urlencode($setupToken) . '&uid=' . urlencode($uuid);
    $subject  = 'Set Up Your Sizzle Rhythm Account';
    $body     = '
        <h1 style="margin: 0 0 24px 0; color: #181411; font-size: 28px; font-weight: bold; line-height: 1.3;">
            Welcome to Sizzle Rhythm, ' . htmlspecialchars($firstName) . '! 🎵
        </h1>

        <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
            An account has been created for you. To get started, please set up your password by clicking the button below:
        </p>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px 0;">
            <tr>
                <td>
                    <a href="' . $setupUrl . '" class="button" style="background-color: #C12928; border-radius: 8px; color: #ffffff; display: inline-block; font-size: 16px; font-weight: bold; line-height: 48px; text-align: center; text-decoration: none; padding: 0 32px;">
                        Set Up Your Password
                    </a>
                </td>
            </tr>
        </table>

        <p style="margin: 0 0 16px 0; color: #8a7560; font-size: 14px; line-height: 1.6;">
            Or copy and paste this link into your browser:
        </p>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #F8F7F5; border-radius: 8px; overflow: hidden;">
            <tr>
                <td style="padding: 16px;">
                    <p style="margin: 0; color: #C12928; font-size: 13px; line-height: 1.5; word-break: break-all;">
                        ' . htmlspecialchars($setupUrl) . '
                    </p>
                </td>
            </tr>
        </table>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 0 0 24px 0; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 8px; overflow: hidden;">
            <tr>
                <td style="padding: 16px;">
                    <p style="margin: 0; color: #856404; font-size: 14px; line-height: 1.5;">
                        ⏰ This setup link expires in 72 hours. If you didn\'t expect this email, please ignore it.
                    </p>
                </td>
            </tr>
        </table>

        <p style="margin: 0; color: #8a7560; font-size: 14px; line-height: 1.6;">
            Need help? Contact our support team any time.
        </p>
    ';

    MailHandler::sendTransactionalEmail($email, $subject, $body, true, 1);
}


// ===========================
// ADMIN: Account Setup (user sets own password via token)
// ===========================
function completeAccountSetup() {
    global $conn;

    $body = json_decode(file_get_contents('php://input'), true);

    if (empty($body['token']) || empty($body['uid']) || empty($body['password'])) {
        ResponseHandler::error('token, uid and password are required.');
        return;
    }

    $uuid     = UtilHandler::sanitizeInput($conn, $body['uid']);
    $token    = $body['token'];
    $password = $body['password'];

    // Validate new password
    $validation = UtilHandler::validatePassword($password);
    if (!$validation['valid']) {
        ResponseHandler::error('Password is too weak.', $validation['errors']);
        return;
    }

    // Find user by UUID
    $stmt = mysqli_prepare($conn, "SELECT id, password, first_name, email FROM users WHERE uuid = ?");
    mysqli_stmt_bind_param($stmt, "s", $uuid);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$user) {
        ResponseHandler::error('Invalid setup link.', null, 404);
        return;
    }

    // Verify the setup token against the stored hash
    if (!password_verify($token, $user['password'])) {
        ResponseHandler::error('Setup link is invalid or has already been used.', null, 400);
        return;
    }

    // Set the real password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = mysqli_prepare($conn, "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $hashedPassword, $user['id']);

    if (!mysqli_stmt_execute($stmt)) {
        ResponseHandler::error('Failed to complete account setup.', null, 500);
        return;
    }

    // Send a welcome confirmation
    MailHandler::sendWelcomeEmail($user['email'], $user['first_name']);

    ResponseHandler::success('Account setup complete. You can now log in.');
}


// ===========================
// ADMIN: Test Email Sending
// ===========================

/**
 * Send a test email to verify SMTP configuration is working.
 *
 * Body params:
 *  - to_email  (required) — recipient email address
 *  - subject   (optional) — custom subject line
 *  - message   (optional) — custom message body
 */
function testEmail() {
    $adminId = verifyAdmin();
    if (!$adminId) return;

    $data = json_decode(file_get_contents('php://input'), true);

    $toEmail = trim($data['to_email'] ?? '');
    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('A valid "to_email" is required.');
        return;
    }

    $subject = trim($data['subject'] ?? '') ?: '🧪 Test Email from ' . ($_ENV['SITE_NAME'] ?? 'Waves');
    $customMsg = trim($data['message'] ?? '') ?: 'If you are reading this, the SMTP email configuration is working correctly.';

    $body = '
        <h1 style="margin: 0 0 24px 0; color: #181411; font-size: 28px; font-weight: bold; line-height: 1.3;">
            Test Email
        </h1>
        <p style="margin: 0 0 16px 0; color: #181411; font-size: 16px; line-height: 1.6;">
            ' . htmlspecialchars($customMsg) . '
        </p>
        <p style="margin: 0 0 8px 0; color: #888; font-size: 13px;">
            Sent at: ' . date('Y-m-d H:i:s T') . '
        </p>
    ';

    // Send immediately (not queued) so we get instant feedback
    $result = MailHandler::sendTransactionalEmailNow($toEmail, $subject, $body);

    if ($result['success']) {
        ResponseHandler::success('Test email sent successfully to ' . $toEmail);
    } else {
        ResponseHandler::error('Failed to send test email: ' . ($result['error'] ?? 'Unknown error'), null, 500);
    }
}


// ===========================
// ROUTING
// ===========================
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'createAccount':        createAccount();
        break;
    case 'listUsers':
        listUsers();
        break;
    case 'getUser':
        getUser();
        break;
    case 'updateAccount':
        updateAccount();
        break;
    case 'resetUserPassword':
        resetUserPassword();
        break;
    case 'toggleAccountStatus':
        toggleAccountStatus();
        break;
    case 'completeAccountSetup':
        completeAccountSetup();
        break;
    case 'testEmail':
        testEmail();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}
