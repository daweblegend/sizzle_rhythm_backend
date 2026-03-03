<?php
require_once __DIR__ . '/../../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';
require_once APP_ROOT . '/Utils/UtilHandler.php';
require_once APP_ROOT . '/Utils/MailHandler.php';
require_once APP_ROOT . '/Utils/GoogleOAuthHandler.php';
require_once APP_ROOT . '/Middleware/JWTHandler.php';
require_once APP_ROOT . '/vendor/autoload.php';  // Include Composer's autoloader

use Middleware\JWTHandler;
use Ramsey\Uuid\Uuid;

// Function to handle user registration
function register() {
    global $conn;
    
    // Include OTPHandler
    require_once APP_ROOT . '/Utils/OTPHandler.php';
    
    // Get the request body content
    $requestBody = json_decode(file_get_contents('php://input'), true);
    
    // Check if the required fields are present
    if (empty($requestBody['email']) || empty($requestBody['name']) || empty($requestBody['password'])) {
        ResponseHandler::error('Failed! Name, email, and password are required!');
        return;
    }

    $name = $requestBody['name'];
    $email = $requestBody['email'];
    $password = $requestBody['password'];
    $phone = isset($requestBody['phone']) ? $requestBody['phone'] : null;
    $emailVerification = true; // You can make this configurable from global config

    // Check if email already exists in the database
    $sql = "SELECT email FROM users WHERE email = '$email'";
    $result = mysqli_query($conn, $sql);
    
    if (!$result || mysqli_num_rows($result) > 0) {
        ResponseHandler::error('Email has already been used. Please enter new credentials or login to your account.');
        return;
    }

    // Check if phone is provided and already exists
    if ($phone) {
        $phoneSql = "SELECT phone FROM users WHERE phone = '$phone'";
        $phoneResult = mysqli_query($conn, $phoneSql);
        if ($phoneResult && mysqli_num_rows($phoneResult) > 0) {
            ResponseHandler::error('Phone number has already been used. Please enter a different phone number.');
            return;
        }
    }

    // Check if the password has 6 or more characters
    if (strlen($password) < 6) {
        ResponseHandler::error('Your password must contain at least 6 characters.');
        return;
    }
    
    // Hash the password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Generate UUID for the user
    $uuid = Uuid::uuid4()->toString();

    // Escape inputs for database insertion
    $name = mysqli_real_escape_string($conn, $name);
    $email = mysqli_real_escape_string($conn, $email);
    $phone = $phone ? mysqli_real_escape_string($conn, $phone) : null;
    
    // Save to database users - matching your new schema
    $sql = "INSERT INTO users (`uuid`, `name`, `email`, `password`, `phone`, `email_verified`, `phone_verified`, `is_active`, `created_at`, `updated_at`) 
            VALUES ('$uuid', '$name', '$email', '$hashedPassword', " . ($phone ? "'$phone'" : "NULL") . ", 0, 0, 1, NOW(), NOW())";

    if (mysqli_query($conn, $sql)) {
        $userId = mysqli_insert_id($conn);
        
        
        // If email verification is enabled, create and send OTP
        $emailStatus = false;
        $smsStatus = false;
        
        if ($emailVerification) {
            // Create OTP record in otps table using UUID
            $otpData = OTPHandler::createOTP($uuid, 'verification', $email, $phone, 10);
            
            if ($otpData) {
                // Send OTP via Email
                $emailStatus = OTPHandler::sendOTPViaEmail($email, $otpData['otp_code']);
                
                // Send OTP via SMS if phone is provided
                if ($phone) {
                    // $smsStatus = OTPHandler::sendOTPViaSMS($phone, $otpData['otp_code']);
                }
            }
        }
        
        $message = $emailVerification
            ? "Your registration was successful! Kindly verify your email to proceed."
            : "Your registration was successful! You can now log in.";

        $responseData = [
            'userId' => $userId,
            'uuid' => $uuid,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'email_verified' => false,
            'phone_verified' => false,
            'requiresVerification' => $emailVerification,
            'emailStatus' => $emailStatus,
            'smsStatus' => $smsStatus,
            'otpExpiry' => $emailVerification ? 10 : null, // minutes
            // 'claimed_gifts' => $claimedGifts // Info about auto-claimed gifts
        ];

        ResponseHandler::success($message, $responseData);
    } else {
        ResponseHandler::error('An error occurred! Kindly try again or contact support if the problem persists: ' . mysqli_error($conn));
    }
}

// Function to send OTP
function sendOTP() {
    global $conn;
    
    // Include OTPHandler
    require_once APP_ROOT . '/Utils/OTPHandler.php';
    
    // Get request body
    $requestBody = json_decode(file_get_contents('php://input'), true);
    
    // Check if email or phone is provided
    if (empty($requestBody['email']) && empty($requestBody['phone'])) {
        ResponseHandler::error('Email or phone number is required!');
        return;
    }
    
    $email = isset($requestBody['email']) ? mysqli_real_escape_string($conn, $requestBody['email']) : null;
    $phone = isset($requestBody['phone']) ? mysqli_real_escape_string($conn, $requestBody['phone']) : null;
    
    // Build query based on provided identifier
    $whereClause = [];
    if ($email) {
        $whereClause[] = "email='$email'";
    }
    if ($phone) {
        $whereClause[] = "phone='$phone'";
    }
    
    $sql = "SELECT id, uuid, email, name, phone FROM users WHERE " . implode(' OR ', $whereClause);
    $result = mysqli_query($conn, $sql);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        $userId = $user["id"];
        $userUuid = $user["uuid"];
        $userName = $user["name"];
        $userEmail = $user["email"];
        $userPhone = $user["phone"];
        
        // First, delete any existing verification OTPs for this user
        $cleanupSql = "DELETE FROM otps WHERE user_id='$userUuid' AND otp_type='verification' AND verified=0";
        mysqli_query($conn, $cleanupSql);
        
        // Create OTP record in otps table using UUID
        $otpData = OTPHandler::createOTP($userUuid, 'verification', $userEmail, $userPhone, 10);
        
        if ($otpData) {
            $smsStatus = false;
            $emailStatus = false;

            // Send OTP via Email if email exists
            if ($userEmail) {
                $emailStatus = OTPHandler::sendOTPViaEmail($userEmail, $otpData['otp_code']);
            }
            
            // Send OTP via SMS if phone exists
            if ($userPhone) {
                // $smsStatus = OTPHandler::sendOTPViaSMS($userPhone, $otpData['otp_code']);
            }
            
            ResponseHandler::success("Sent! Kindly check your phone/email for verification code.", [
                "userId" => $userId,
                "uuid" => $userUuid,
                "name" => $userName,
                "phone" => $userPhone,
                "email" => $userEmail,
                "smsStatus" => $smsStatus,
                "emailStatus" => $emailStatus,
                "otpExpiry" => 10 // minutes
            ]);
        } else {
            ResponseHandler::error("An error occurred! Kindly try again or contact support if the problem persists.");
        }
    } else {
        ResponseHandler::error("User not found. Please check your credentials or create a new account.");
    }
}

// Function to verify OTP and activate account
function verifyAccount() {
    global $conn;
    
    // Include OTPHandler
    require_once APP_ROOT . '/Utils/OTPHandler.php';
    
    // Get the request body content
    $requestBody = json_decode(file_get_contents('php://input'), true);
    
    // Check if required fields are present - accept both uuid and userId for backward compatibility
    if (empty($requestBody['uuid']) && empty($requestBody['userId'])) {
        ResponseHandler::error('User UUID is required');
        return;
    }
    
    if (empty($requestBody['otpCode'])) {
        ResponseHandler::error('OTP code is required');
        return;
    }
    
    // Use uuid if provided, otherwise fall back to userId
    $userUuid = !empty($requestBody['uuid']) ? mysqli_real_escape_string($conn, $requestBody['uuid']) : mysqli_real_escape_string($conn, $requestBody['userId']);
    $otpCode = mysqli_real_escape_string($conn, $requestBody['otpCode']);
    
    // Get user by UUID
    $query = "SELECT * FROM users WHERE uuid = '$userUuid'";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        
        if ($user['email_verified'] == 1) {
            ResponseHandler::success('Your account is already verified.', [
                'userId' => $user['id'],
                'uuid' => $user['uuid'],
                'email_verified' => true,
                'phone_verified' => (bool)$user['phone_verified'],
                'phone' => $user['phone'],
                'email' => $user['email']
            ]);
            return;
        }
        
        // Verify the OTP using our OTPHandler with UUID
        if (OTPHandler::verifyOTP($userUuid, $otpCode, 'verification')) {
            // Update user email_verified status
            $updateQuery = "UPDATE users SET email_verified = 1, updated_at = NOW() WHERE uuid = '$userUuid'";
            
            if (mysqli_query($conn, $updateQuery)) {
                // Clean up old verification OTPs
                $cleanupSql = "DELETE FROM otps WHERE user_id = '$userUuid' AND otp_type = 'verification'";
                mysqli_query($conn, $cleanupSql);
                
                // Send welcome email
                try {
                    MailHandler::sendWelcomeEmail($user['email'], $user['name']);
                } catch (Exception $e) {
                    error_log('Failed to send welcome email: ' . $e->getMessage());
                    // Don't fail the verification if email fails
                }

                // Auto-claim pending gifts for this user
                $claimedGifts = autoclaimPendingGifts($user['id'], $user['email'], $user['phone']);
                
                // Return success response
                ResponseHandler::success('Your account has been verified successfully!', [
                    'userId' => $user['id'],
                    'uuid' => $user['uuid'],
                    'email_verified' => true,
                    'phone_verified' => (bool)$user['phone_verified'],
                    'phone' => $user['phone'],
                    'email' => $user['email'],
                    'name' => $user['name'],
                    'claimed_gifts' => $claimedGifts // Info about auto-claimed gifts
                ]);
            } else {
                ResponseHandler::error('Failed to update user verification status');
            }
        } else {
            ResponseHandler::error('Invalid or expired verification code. Please try again or request a new code.');
        }
    } else {
        ResponseHandler::error('User not found');
    }
}

// Function to handle login
function login() {
    global $conn;
    
    // Get the request body content
    $requestBody = json_decode(file_get_contents('php://input'), true);
    
    // Check if required fields are present
    // Support both new 'userid' field and legacy 'email'/'phone' fields for backward compatibility
    if (empty($requestBody['password'])) {
        ResponseHandler::error('Password is required');
        return;
    }
    
    $userid = null;
    if (!empty($requestBody['userid'])) {
        // New format: userid can be email or phone
        $userid = mysqli_real_escape_string($conn, $requestBody['userid']);
    } elseif (!empty($requestBody['email'])) {
        // Legacy format: email field
        $userid = mysqli_real_escape_string($conn, $requestBody['email']);
    } elseif (!empty($requestBody['phone'])) {
        // Legacy format: phone field
        $userid = mysqli_real_escape_string($conn, $requestBody['phone']);
    }
    
    if (!$userid) {
        ResponseHandler::error('Email or phone is required');
        return;
    }
    
    $password = $requestBody['password'];
    
    // Build query to find user by email or phone
    // The userid can be either email or phone, so we check both columns
    $query = "SELECT * FROM users WHERE email = '$userid' OR phone = '$userid'";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        $userId = $user['id'];
        $hashedPassword = $user['password'];
        
        // Check if user registered with OAuth (no password)
        if (!$hashedPassword && $user['oauth_provider']) {
            ResponseHandler::error('This account was created with ' . ucfirst($user['oauth_provider']) . '. Please use ' . ucfirst($user['oauth_provider']) . ' sign-in.');
            return;
        }
        
        // Check if account is active
        if (!$user['is_active']) {
            ResponseHandler::error('Your account has been deactivated. Please contact support.');
            return;
        }
        
        // Verify password
        if (password_verify($password, $hashedPassword)) {
            // Generate JWT token
            $jwtHandler = new JWTHandler();
            $token = $jwtHandler->generateToken($userId);
            
            // Return success response with user data
            ResponseHandler::success('Login successful', [
                'token' => $token,
                'tokenType' => 'Bearer',
                'expiresIn' => 2592000, // 30 days
                'user' => [
                    'id' => $userId,
                    'uuid' => $user['uuid'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'name' => $user['first_name'] . ' ' . $user['last_name'],
                    'email' => $user['email'],
                    'phone' => $user['phone'],
                    'email_verified' => (bool)$user['email_verified'],
                    'phone_verified' => (bool)$user['phone_verified'],
                    'is_active' => (bool)$user['is_active'],
                    'oauth_provider' => $user['oauth_provider']
                ]
            ]);
        } else {
            ResponseHandler::error('Invalid credentials. Please check your email/phone and password.');
        }
    } else {
        ResponseHandler::error('Invalid credentials. Please check your email/phone and password.');
    }
}

// Function to handle logout
function logout() {
    // For JWT, logout is typically handled client-side by removing the token
    // But we can blacklist tokens if needed (requires database storage)
    
    ResponseHandler::success('Logout successful', [
        'message' => 'You have been successfully logged out. Please remove the token from your client storage.'
    ]);
}

// Function to refresh JWT token
function refreshToken() {
    $tokenData = UtilHandler::verifyJWTToken();
    if (!$tokenData) {
        return; // Error response already sent by verifyJWTToken
    }
    
    $jwtHandler = new JWTHandler();
    $newToken = $jwtHandler->generateToken($tokenData['userId']);
    
    ResponseHandler::success('Token refreshed successfully', [
        'token' => $newToken,
        'tokenType' => 'Bearer',
        'expiresIn' => 2592000,
        'message' => 'Token has been refreshed successfully.'
    ]);
}

// ============================================
// ========== GOOGLE OAUTH FUNCTIONS =========
// ============================================

// ========================
// FORGOT PASSWORD FLOW
// ========================

function forgotPasswordRequestOTP() {
    global $conn;
    require_once APP_ROOT . '/Utils/OTPHandler.php';
    $requestBody = json_decode(file_get_contents('php://input'), true);
    if (empty($requestBody['email']) && empty($requestBody['phone'])) {
        ResponseHandler::error('Email or phone is required!');
        return;
    }
    $email = isset($requestBody['email']) ? mysqli_real_escape_string($conn, $requestBody['email']) : null;
    $phone = isset($requestBody['phone']) ? mysqli_real_escape_string($conn, $requestBody['phone']) : null;
    $whereClause = [];
    if ($email) $whereClause[] = "email='$email'";
    if ($phone) $whereClause[] = "phone='$phone'";
    $sql = "SELECT id, uuid, email, name, phone FROM users WHERE " . implode(' OR ', $whereClause);
    $result = mysqli_query($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);
        $userUuid = $user['uuid'];
        $userEmail = $user['email'];
        $userPhone = $user['phone'];
        // Clean up old password_reset OTPs
        $cleanupSql = "DELETE FROM otps WHERE user_id='$userUuid' AND otp_type='password_reset' AND verified=0";
        mysqli_query($conn, $cleanupSql);
        // Create OTP
        $otpData = OTPHandler::createOTP($userUuid, 'password_reset', $userEmail, $userPhone, 10);
        if ($otpData) {
            $emailStatus = false;
            if ($userEmail) $emailStatus = OTPHandler::sendOTPViaEmail($userEmail, $otpData['otp_code']);
            ResponseHandler::success('OTP sent for password reset.', [
                'uuid' => $userUuid,
                'emailStatus' => $emailStatus,
                'otpExpiry' => 10
            ]);
        } else {
            ResponseHandler::error('Failed to create OTP.');
        }
    } else {
        ResponseHandler::error('User not found.');
    }
}

function forgotPasswordValidateOTP() {
    global $conn;
    require_once APP_ROOT . '/Utils/OTPHandler.php';
    $requestBody = json_decode(file_get_contents('php://input'), true);
    if (empty($requestBody['uuid']) && empty($requestBody['userId'])) {
        ResponseHandler::error('User UUID is required');
        return;
    }
    if (empty($requestBody['otpCode'])) {
        ResponseHandler::error('OTP code is required');
        return;
    }
    $userUuid = !empty($requestBody['uuid']) ? mysqli_real_escape_string($conn, $requestBody['uuid']) : mysqli_real_escape_string($conn, $requestBody['userId']);
    $otpCode = mysqli_real_escape_string($conn, $requestBody['otpCode']);
    if (OTPHandler::verifyOTP($userUuid, $otpCode, 'password_reset')) {
        ResponseHandler::success('OTP verified. You may now reset your password.', [
            'uuid' => $userUuid,
            'otpCode' => $otpCode
        ]);
    } else {
        ResponseHandler::error('Invalid or expired OTP.');
    }
}

function forgotPasswordUpdatePassword() {
    global $conn;
    require_once APP_ROOT . '/Utils/OTPHandler.php';
    $requestBody = json_decode(file_get_contents('php://input'), true);
    if (empty($requestBody['uuid']) && empty($requestBody['userId'])) {
        ResponseHandler::error('User UUID is required');
        return;
    }
    if (empty($requestBody['otpCode']) || empty($requestBody['newPassword']) || empty($requestBody['confirmPassword'])) {
        ResponseHandler::error('OTP, new password, and confirm password are required');
        return;
    }
    $userUuid = !empty($requestBody['uuid']) ? mysqli_real_escape_string($conn, $requestBody['uuid']) : mysqli_real_escape_string($conn, $requestBody['userId']);
    $otpCode = mysqli_real_escape_string($conn, $requestBody['otpCode']);
    $newPassword = $requestBody['newPassword'];
    $confirmPassword = $requestBody['confirmPassword'];
    if ($newPassword !== $confirmPassword) {
        ResponseHandler::error('Passwords do not match.');
        return;
    }
    if (strlen($newPassword) < 6) {
        ResponseHandler::error('Password must be at least 6 characters.');
        return;
    }
    // Validate OTP
    if (!OTPHandler::verifyOTP($userUuid, $otpCode, 'password_reset')) {
        ResponseHandler::error('Invalid or expired OTP.');
        return;
    }
    // Update password
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateSql = "UPDATE users SET password='$hashedPassword', updated_at=NOW() WHERE uuid='$userUuid'";
    if (mysqli_query($conn, $updateSql)) {
        // Mark OTP as used
        $cleanupSql = "UPDATE otps SET verified=1, verified_at=" . time() . " WHERE user_id='$userUuid' AND otp_code='$otpCode' AND otp_type='password_reset'";
        mysqli_query($conn, $cleanupSql);
        ResponseHandler::success('Password updated successfully. You may now log in.');
    } else {
        ResponseHandler::error('Failed to update password.');
    }
}
/**
 * Initiate Google OAuth flow
 * Returns the Google authorization URL
 */
function googleAuth() {
    try {
        $googleOAuth = new GoogleOAuthHandler();
        $authUrl = $googleOAuth->getAuthUrl();
        
        ResponseHandler::success('Google OAuth URL generated', [
            'authUrl' => $authUrl,
            'message' => 'Redirect user to this URL to authorize with Google'
        ]);
    } catch (Exception $e) {
        ResponseHandler::error('Failed to generate Google OAuth URL: ' . $e->getMessage());
    }
}

/**
 * Handle Google OAuth callback
 * Process the authorization code and create/login user
 */
function googleCallback() {
    global $conn;
    
    // Get authorization code from query parameter
    if (!isset($_GET['code'])) {
        ResponseHandler::error('Authorization code not provided');
        return;
    }
    
    $code = $_GET['code'];
    
    try {
        $googleOAuth = new GoogleOAuthHandler();
        $userData = $googleOAuth->getUserFromCode($code);
        
        if (!$userData) {
            ResponseHandler::error('Failed to get user information from Google');
            return;
        }
        
        // Process OAuth login/registration
        $result = processOAuthUser($userData);
        
        if ($result['success']) {
            ResponseHandler::success($result['message'], $result['data']);
        } else {
            ResponseHandler::error($result['message']);
        }
    } catch (Exception $e) {
        ResponseHandler::error('Google OAuth error: ' . $e->getMessage());
    }
}

/**
 * Handle Google Sign-In with ID Token (for mobile/frontend direct auth)
 * This is useful for mobile apps or SPAs that use Google Sign-In SDK
 */
function googleSignIn() {
    global $conn;
    
    // Get the request body content
    $requestBody = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($requestBody['idToken'])) {
        ResponseHandler::error('Google ID token is required');
        return;
    }
    
    $idToken = $requestBody['idToken'];
    
    try {
        $googleOAuth = new GoogleOAuthHandler();
        $userData = $googleOAuth->verifyIdToken($idToken);
        
        if (!$userData) {
            ResponseHandler::error('Invalid Google ID token');
            return;
        }
        
        // Process OAuth login/registration
        $result = processOAuthUser($userData);
        
        if ($result['success']) {
            ResponseHandler::success($result['message'], $result['data']);
        } else {
            ResponseHandler::error($result['message']);
        }
    } catch (Exception $e) {
        ResponseHandler::error('Google Sign-In error: ' . $e->getMessage());
    }
}

/**
 * Helper function to process OAuth user (create or login)
 * @param array $oauthData - OAuth user data from provider
 * @return array - Result with success status, message and data
 */
function processOAuthUser($oauthData) {
    global $conn;
    
    $provider = mysqli_real_escape_string($conn, $oauthData['provider']);
    $providerId = mysqli_real_escape_string($conn, $oauthData['provider_id']);
    $email = mysqli_real_escape_string($conn, $oauthData['email']);
    $name = mysqli_real_escape_string($conn, $oauthData['name']);
    $accessToken = isset($oauthData['access_token']) ? mysqli_real_escape_string($conn, $oauthData['access_token']) : null;
    $refreshToken = isset($oauthData['refresh_token']) ? mysqli_real_escape_string($conn, $oauthData['refresh_token']) : null;
    
    // Check if user exists with this OAuth provider
    $query = "SELECT * FROM users WHERE oauth_provider = '$provider' AND oauth_provider_id = '$providerId'";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        // User exists - perform login
        $user = mysqli_fetch_assoc($result);
        
        // Update OAuth tokens if provided
        if ($accessToken) {
            $updateQuery = "UPDATE users SET 
                oauth_access_token = '$accessToken',
                oauth_refresh_token = " . ($refreshToken ? "'$refreshToken'" : "NULL") . ",
                updated_at = NOW()
                WHERE id = " . $user['id'];
            mysqli_query($conn, $updateQuery);
        }
        
        // Generate JWT token
        $jwtHandler = new JWTHandler();
        $token = $jwtHandler->generateToken($user['id']);
        
        return [
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'uuid' => $user['uuid'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'phone' => $user['phone'],
                    'email_verified' => (bool)$user['email_verified'],
                    'phone_verified' => (bool)$user['phone_verified'],
                    'is_active' => (bool)$user['is_active'],
                    'oauth_provider' => $user['oauth_provider']
                ]
            ]
        ];
    } else {
        // Check if user exists with same email (link accounts)
        $emailQuery = "SELECT * FROM users WHERE email = '$email'";
        $emailResult = mysqli_query($conn, $emailQuery);
        
        if ($emailResult && mysqli_num_rows($emailResult) > 0) {
            // User exists with same email - link OAuth account
            $user = mysqli_fetch_assoc($emailResult);
            
            $updateQuery = "UPDATE users SET 
                oauth_provider = '$provider',
                oauth_provider_id = '$providerId',
                oauth_access_token = " . ($accessToken ? "'$accessToken'" : "NULL") . ",
                oauth_refresh_token = " . ($refreshToken ? "'$refreshToken'" : "NULL") . ",
                email_verified = 1,
                updated_at = NOW()
                WHERE id = " . $user['id'];
            
            if (mysqli_query($conn, $updateQuery)) {
                // Generate JWT token
                $jwtHandler = new JWTHandler();
                $token = $jwtHandler->generateToken($user['id']);
                
                return [
                    'success' => true,
                    'message' => 'Account linked successfully',
                    'data' => [
                        'token' => $token,
                        'user' => [
                            'id' => $user['id'],
                            'uuid' => $user['uuid'],
                            'name' => $user['name'],
                            'email' => $user['email'],
                            'phone' => $user['phone'],
                            'email_verified' => true,
                            'phone_verified' => (bool)$user['phone_verified'],
                            'is_active' => (bool)$user['is_active'],
                            'oauth_provider' => $provider
                        ]
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to link OAuth account: ' . mysqli_error($conn)
                ];
            }
        } else {
            // Create new user with OAuth
            $uuid = Uuid::uuid4()->toString();
            
            $insertQuery = "INSERT INTO users (
                uuid, name, email, oauth_provider, oauth_provider_id, 
                oauth_access_token, oauth_refresh_token, 
                email_verified, is_active, created_at, updated_at
            ) VALUES (
                '$uuid', '$name', '$email', '$provider', '$providerId',
                " . ($accessToken ? "'$accessToken'" : "NULL") . ",
                " . ($refreshToken ? "'$refreshToken'" : "NULL") . ",
                1, 1, NOW(), NOW()
            )";
            
            if (mysqli_query($conn, $insertQuery)) {
                $userId = mysqli_insert_id($conn);
                
                // Send welcome email for new OAuth user
                try {
                    MailHandler::sendWelcomeEmail($email, $name);
                } catch (Exception $e) {
                    error_log('Failed to send welcome email: ' . $e->getMessage());
                    // Don't fail the registration if email fails
                }
                
                // Generate JWT token
                $jwtHandler = new JWTHandler();
                $token = $jwtHandler->generateToken($userId);
                
                return [
                    'success' => true,
                    'message' => 'Registration successful',
                    'data' => [
                        'token' => $token,
                        'user' => [
                            'id' => $userId,
                            'uuid' => $uuid,
                            'name' => $name,
                            'email' => $email,
                            'phone' => null,
                            'email_verified' => true,
                            'phone_verified' => false,
                            'is_active' => true,
                            'oauth_provider' => $provider
                        ]
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to create user account: ' . mysqli_error($conn)
                ];
            }
        }
    }
}

/**
 * Auto-claim pending gifts for newly registered user
 * Checks for gifts sent to the email or phone and associates them with the new user
 * Sends notifications to both sender and recipient
 * 
 * @param int $userId The newly created user ID
 * @param string $email User's email
 * @param string|null $phone User's phone (optional)
 * @return array Information about claimed gifts
 */
function autoclaimPendingGifts($userId, $email, $phone = null) {
    global $conn;
    
    $email = mysqli_real_escape_string($conn, $email);
    $phone = $phone ? mysqli_real_escape_string($conn, $phone) : null;
    
    // Get recipient details
    $recipientQuery = "SELECT id, uuid, name, email FROM users WHERE id = $userId";
    $recipientResult = mysqli_query($conn, $recipientQuery);
    
    if (!$recipientResult || mysqli_num_rows($recipientResult) === 0) {
        error_log("AUTO-CLAIM ERROR: Failed to get recipient details for user $userId");
        return ['count' => 0, 'gifts' => [], 'total_money_claimed' => 0];
    }
    
    $recipient = mysqli_fetch_assoc($recipientResult);
    $recipientName = $recipient['name'];
    $recipientEmail = $recipient['email'];
    
    // Find all pending gifts for this email or phone
    $whereClause = "recipient_email = '$email'";
    if ($phone) {
        $whereClause .= " OR recipient_phone = '$phone'";
    }
    
    // Only auto-claim gifts with status 'pending' (payment completed, awaiting claim)
    $query = "SELECT g.id, g.uuid, g.gift_type, g.amount, g.sender_id, g.message,
                     u.name as sender_name, u.email as sender_email
              FROM gifts g
              LEFT JOIN users u ON g.sender_id = u.id
              WHERE ($whereClause) AND g.status = 'pending' 
              ORDER BY g.created_at ASC";
    
    $result = mysqli_query($conn, $query);
    
    if (!$result) {
        error_log("AUTO-CLAIM ERROR: Failed to query pending gifts: " . mysqli_error($conn));
        return ['count' => 0, 'gifts' => [], 'total_money_claimed' => 0];
    }
    
    $claimedGifts = [];
    $totalAmount = 0;
    
    while ($gift = mysqli_fetch_assoc($result)) {
        $giftId = $gift['id'];
        $giftUuid = $gift['uuid'];
        $giftType = $gift['gift_type'];
        $amount = floatval($gift['amount']);
        $senderId = $gift['sender_id'];
        $senderName = $gift['sender_name'] ?? 'Anonymous';
        $senderEmail = $gift['sender_email'] ?? null;
        $message = $gift['message'] ?? '';
        
        // Update gift status to 'delivered' and set delivered_at timestamp
        $updateQuery = "UPDATE gifts 
                       SET status = 'delivered', 
                           delivered_at = NOW(), 
                           updated_at = NOW() 
                       WHERE id = $giftId";
        
        if (mysqli_query($conn, $updateQuery)) {
            $claimedGifts[] = [
                'uuid' => $giftUuid,
                'type' => $giftType,
                'amount' => $amount,
                'message' => $message,
                'sender_name' => $senderName
            ];
            
            if ($giftType === 'money') {
                $totalAmount += $amount;
            }
            
            error_log("AUTO-CLAIM SUCCESS: User $userId claimed gift $giftUuid ($giftType, ₦$amount)");
            
            // Send notification to sender if email is available
            if ($senderEmail) {
                try {
                    MailHandler::sendGiftClaimedSenderEmail(
                        $senderEmail, 
                        $senderName, 
                        $recipientName, 
                        [
                            'type' => $giftType,
                            'amount' => $amount,
                            'message' => $message,
                            'uuid' => $giftUuid
                        ]
                    );
                    error_log("AUTO-CLAIM: Sent notification to sender $senderEmail for gift $giftUuid");
                } catch (Exception $e) {
                    error_log("AUTO-CLAIM ERROR: Failed to send sender notification for gift $giftUuid: " . $e->getMessage());
                    // Don't fail the claim if email fails
                }
            }
        } else {
            error_log("AUTO-CLAIM ERROR: Failed to update gift $giftUuid: " . mysqli_error($conn));
        }
    }
    
    // For money gifts, credit user's wallet
    if ($totalAmount > 0) {
        $updateBalance = "UPDATE users SET balance = balance + $totalAmount WHERE id = $userId";
        if (mysqli_query($conn, $updateBalance)) {
            error_log("AUTO-CLAIM SUCCESS: Credited ₦$totalAmount to user $userId wallet");
        } else {
            error_log("AUTO-CLAIM ERROR: Failed to credit wallet for user $userId: " . mysqli_error($conn));
        }
    }
    
    // Send notification to recipient if they claimed any gifts
    if (count($claimedGifts) > 0) {
        try {
            MailHandler::sendGiftClaimedRecipientEmail(
                $recipientEmail, 
                $recipientName, 
                $claimedGifts, 
                $totalAmount
            );
            error_log("AUTO-CLAIM: Sent recipient notification to $recipientEmail for " . count($claimedGifts) . " gifts");
        } catch (Exception $e) {
            error_log("AUTO-CLAIM ERROR: Failed to send recipient notification: " . $e->getMessage());
            // Don't fail the claim if email fails
        }
    }
    
    return [
        'count' => count($claimedGifts),
        'gifts' => $claimedGifts,
        'total_money_claimed' => $totalAmount
    ];
}


$action = $_REQUEST['action'] ?? 'register';

switch ($action) {
    case 'register':
        register();
        break;
    case 'verifyAccount':
        verifyAccount();
        break;
    case 'login':
        login();
        break;
    case 'sendOTP':
        sendOTP();
        break;
    case 'logout':
        logout();
        break;
    case 'refreshToken':
        refreshToken();
        break;
    // Google OAuth routes
    case 'googleAuth':
        googleAuth();
        break;
    case 'googleCallback':
        googleCallback();
        break;
    case 'googleSignIn':
        googleSignIn();
        break;
    case 'forgotPasswordRequestOTP':
        forgotPasswordRequestOTP();
        break;
    case 'forgotPasswordValidateOTP':
        forgotPasswordValidateOTP();
        break;
    case 'forgotPasswordUpdatePassword':
        forgotPasswordUpdatePassword();
        break;
    default:
        ResponseHandler::error('Invalid action', null, 400);
        break;
}