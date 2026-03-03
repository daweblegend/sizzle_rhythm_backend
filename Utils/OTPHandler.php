<?php
require_once __DIR__ . '/../Config/database.php';
require_once __DIR__ . '/../Utils/ResponseHandler.php';
require_once __DIR__ . '/../Utils/MailHandler.php';

class OTPHandler {
    /**
     * Generate a new OTP code
     *
     * @param int $length Length of the OTP code
     * @return string Generated OTP code
     */
    public static function generateOTP($length = 4) {
        return str_pad(rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }
    
    /**
     * Create a new OTP record
     *
     * @param string $userId User UUID
     * @param string $otpType Type of OTP (verification, password_reset, etc)
     * @param string|null $email Email to send OTP to
     * @param string|null $phone Phone to send OTP to
     * @param int $expiryMinutes Expiry time in minutes (default 10)
     * @return array|bool OTP data if successful, false otherwise
     */
    public static function createOTP($userId, $otpType, $email = null, $phone = null, $expiryMinutes = 10) {
        global $conn;
        
        try {
            // Generate OTP
            $otpCode = self::generateOTP(4);
            $currentTime = time();
            $expiryTime = $currentTime + ($expiryMinutes * 60);
            
            // Escape inputs
            $userId = mysqli_real_escape_string($conn, $userId);
            $otpType = mysqli_real_escape_string($conn, $otpType);
            $email = $email ? mysqli_real_escape_string($conn, $email) : null;
            $phone = $phone ? mysqli_real_escape_string($conn, $phone) : null;
            
            // Insert into database (id is auto-increment, no uuid field)
            $sql = "INSERT INTO otps (user_id, otp_code, otp_type, email, phone, created_at, expires_at) 
                    VALUES ('$userId', '$otpCode', '$otpType', " . 
                    ($email ? "'$email'" : "NULL") . ", " . 
                    ($phone ? "'$phone'" : "NULL") . ", 
                    $currentTime, $expiryTime)";
            
            if (mysqli_query($conn, $sql)) {
                $otpId = mysqli_insert_id($conn);
                return [
                    'id' => $otpId,
                    'user_id' => $userId,
                    'otp_code' => $otpCode,
                    'otp_type' => $otpType,
                    'email' => $email,
                    'phone' => $phone,
                    'created_at' => $currentTime,
                    'expires_at' => $expiryTime
                ];
            } else {
                error_log("Failed to create OTP: " . mysqli_error($conn));
                return false;
            }
        } catch (Exception $e) {
            error_log("Exception in createOTP: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verify an OTP code
     *
     * @param string $userId User UUID
     * @param string $otpCode OTP code to verify
     * @param string $otpType Type of OTP (verification, password_reset, etc)
     * @return bool True if OTP is valid, false otherwise
     */
    public static function verifyOTP($userId, $otpCode, $otpType) {
        global $conn;
        
        try {
            // Escape inputs
            $userId = mysqli_real_escape_string($conn, $userId);
            $otpCode = mysqli_real_escape_string($conn, $otpCode);
            $otpType = mysqli_real_escape_string($conn, $otpType);
            $currentTime = time();
            
            // Check if OTP is valid
            $sql = "SELECT * FROM otps 
                    WHERE user_id = '$userId' 
                    AND otp_code = '$otpCode' 
                    AND otp_type = '$otpType' 
                    AND verified = 0 
                    AND expires_at > $currentTime 
                    ORDER BY created_at DESC 
                    LIMIT 1";
            
            $result = mysqli_query($conn, $sql);
            
            if ($result && mysqli_num_rows($result) > 0) {
                $otp = mysqli_fetch_assoc($result);
                
                // Mark OTP as verified
                $otpId = $otp['id'];
                $updateSql = "UPDATE otps SET verified = 1, verified_at = $currentTime WHERE id = '$otpId'";
                mysqli_query($conn, $updateSql);
                
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Exception in verifyOTP: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send OTP via email
     *
     * @param string $email Email address
     * @param string $otpCode OTP code
     * @return bool True if email sent successfully, false otherwise
     */
    public static function sendOTPViaEmail($email, $otpCode) {
        try {
            MailHandler::sendOTPViaEmail($email, $otpCode);
            return true;
        } catch (Exception $e) {
            error_log("Failed to send OTP via email: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send OTP via SMS
     *
     * @param string $phone Phone number
     * @param string $otpCode OTP code
     * @return bool True if SMS sent successfully, false otherwise
     */
    public static function sendOTPViaSMS($phone, $otpCode) {
        try {
            // Implement SMS sending logic here using UtilHandler::sendSMS or similar
            // For now, this is a placeholder
            error_log("SMS OTP sending not implemented yet");
            return false;
        } catch (Exception $e) {
            error_log("Failed to send OTP via SMS: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the latest OTP for a user
     * 
     * @param string $userId User UUID
     * @param string $otpType Type of OTP
     * @return array|null OTP data if found, null otherwise
     */
    public static function getLatestOTP($userId, $otpType) {
        global $conn;
        
        try {
            // Escape inputs
            $userId = mysqli_real_escape_string($conn, $userId);
            $otpType = mysqli_real_escape_string($conn, $otpType);
            
            $sql = "SELECT * FROM otps 
                    WHERE user_id = '$userId' 
                    AND otp_type = '$otpType' 
                    ORDER BY created_at DESC 
                    LIMIT 1";
            
            $result = mysqli_query($conn, $sql);
            
            if ($result && mysqli_num_rows($result) > 0) {
                return mysqli_fetch_assoc($result);
            }
            
            return null;
        } catch (Exception $e) {
            error_log("Exception in getLatestOTP: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete expired OTPs (can be run periodically via a cron job)
     * 
     * @return bool True if successful, false otherwise
     */
    public static function cleanupExpiredOTPs() {
        global $conn;
        
        try {
            $currentTime = time();
            $sql = "DELETE FROM otps WHERE expires_at < $currentTime";
            
            return mysqli_query($conn, $sql) ? true : false;
        } catch (Exception $e) {
            error_log("Exception in cleanupExpiredOTPs: " . $e->getMessage());
            return false;
        }
    }
}
