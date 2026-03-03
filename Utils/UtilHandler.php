<?php

// Import UUID library
use Ramsey\Uuid\Uuid;

class UtilHandler 
{
    /**
     * Generate a UUID v4 using Ramsey UUID library
     * 
     * @return string
     */
    public static function generateUUID() {
        return Uuid::uuid4()->toString();
    }

    /**
     * Generate a 6-digit OTP
     * 
     * @return string
     */
    public static function generateOTP() {
        return sprintf('%06d', mt_rand(0, 999999));
    }

    /**
     * Normalize phone number to international format (Nigerian format)
     * 
     * @param string $phone
     * @return string
     */
    public static function normalizePhoneNumber($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // If it starts with 0, replace with +234 (Nigeria)
        if (substr($phone, 0, 1) === '0') {
            $phone = '+234' . substr($phone, 1);
        }
        // If it doesn't start with +, assume it's missing country code
        elseif (substr($phone, 0, 1) !== '+') {
            $phone = '+234' . $phone;
        }
        
        return $phone;
    }

    /**
     * Generate referral code based on name and user ID
     * 
     * @param string $name
     * @param int $userId
     * @return string
     */
    public static function generateReferralCode($name, $userId) {
        $namePrefix = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 3));
        $randomSuffix = str_pad($userId, 4, '0', STR_PAD_LEFT);
        return $namePrefix . $randomSuffix;
    }

    /**
     * Sanitize input for database queries
     * 
     * @param mysqli $conn
     * @param string $input
     * @return string
     */
    public static function sanitizeInput($conn, $input) {
        return mysqli_real_escape_string($conn, trim($input));
    }

    /**
     * Validate email format
     * 
     * @param string $email
     * @return bool
     */
    public static function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Generate a random string of specified length
     * 
     * @param int $length
     * @param string $characters
     * @return string
     */
    public static function generateRandomString($length = 10, $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ') {
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }

    /**
     * Validate phone number format
     * 
     * @param string $phone
     * @return bool
     */
    public static function isValidPhoneNumber($phone) {
        // Remove all non-numeric characters for validation
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        
        // Nigerian phone numbers should be 11 digits (without country code)
        // or 13 digits (with +234)
        $length = strlen($cleanPhone);
        return ($length >= 10 && $length <= 15);
    }

    /**
     * Validate password strength
     * 
     * @param string $password
     * @param int $minLength
     * @return array
     */
    public static function validatePassword($password, $minLength = 6) {
        $errors = [];
        
        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long";
        }
        
        // if (!preg_match('/[A-Z]/', $password)) {
        //     $errors[] = "Password must contain at least one uppercase letter";
        // }
        
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'strength' => empty($errors) ? 'strong' : 'weak'
        ];
    }

    /**
     * Format currency amount
     * 
     * @param float $amount
     * @param string $currency
     * @return string
     */
    public static function formatCurrency($amount, $currency = 'NGN') {
        return $currency . ' ' . number_format($amount, 2);
    }

    /**
     * Generate transaction reference
     * 
     * @param string $prefix
     * @return string
     */
    public static function generateTransactionRef($prefix = 'TXN') {
        return $prefix . '_' . date('YmdHis') . '_' . self::generateRandomString(6, '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    /**
     * Mask sensitive data (email, phone, etc.)
     * 
     * @param string $value
     * @param string $type
     * @return string
     */
    public static function maskSensitiveData($value, $type = 'email') {
        switch ($type) {
            case 'email':
                $parts = explode('@', $value);
                if (count($parts) === 2) {
                    $name = $parts[0];
                    $domain = $parts[1];
                    $maskedName = substr($name, 0, 2) . str_repeat('*', strlen($name) - 2);
                    return $maskedName . '@' . $domain;
                }
                return $value;
                
            case 'phone':
                $length = strlen($value);
                if ($length > 4) {
                    return substr($value, 0, 4) . str_repeat('*', $length - 8) . substr($value, -4);
                }
                return $value;
                
            default:
                return $value;
        }
    }

    /**
     * Calculate age from date of birth
     * 
     * @param string $dateOfBirth (Y-m-d format)
     * @return int
     */
    public static function calculateAge($dateOfBirth) {
        return date_diff(date_create($dateOfBirth), date_create('today'))->y;
    }

    /**
     * Convert string to slug (URL-friendly)
     * 
     * @param string $string
     * @return string
     */
    public static function slugify($string) {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
    }

    /**
     * Middleware function to verify JWT token
     * 
     * @return array|false Returns user data if token is valid, false otherwise
     */
    public static function verifyJWTToken() {
        require_once APP_ROOT . '/Middleware/JWTHandler.php';
        
        $token = \Middleware\JWTHandler::getTokenFromHeader();
        
        if (!$token) {
            \ResponseHandler::error('Authorization token required', null, 401);
            return false;
        }
        
        $jwtHandler = new \Middleware\JWTHandler();
        $decoded = $jwtHandler->validateToken($token);
        
        if (!$decoded) {
            \ResponseHandler::error('Invalid or expired token', null, 401);
            return false;
        }
        
        return [
            'userId' => $decoded->userId,
            'iat' => $decoded->iat,
            'exp' => $decoded->exp
        ];
    }

    /**
     * Format money into detailed money object with Nigerian Naira formatting
     * 
     * @param float|string $amount Amount in Naira (e.g., 50.00, 9004.00)
     * @param string $currency Currency code (default: NGN)
     * @return array Detailed money object
     */
    public static function formatMoney($amount, $currency = 'NGN') {
        // Convert to float to handle string inputs
        $amount = (float)$amount;
        
        // Calculate amount in cents (kobo for NGN)
        $amountInCents = (int)($amount * 100);
        
        // Currency symbols
        $currencySymbols = [
            'NGN' => '₦',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£'
        ];
        
        $symbol = $currencySymbols[$currency] ?? $currency;
        
        // Format amount with thousands separator and 2 decimal places
        $formattedAmount = $symbol . number_format($amount, 2);
        
        return [
            'amountInCents' => $amountInCents,
            'currency' => $currency,
            'amount' => $amount,
            'formattedAmount' => $formattedAmount
        ];
    }
    
    /**
     * Extract ID from URL path
     * 
     * This function extracts an ID from the last segment of a URL path
     * For example: /api/users/123 would return '123'
     * It also handles cases where query parameters are present
     * 
     * @return string|null The extracted ID or null if not found
     */
    public static function extractIdFromUrl() {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return null;
        }
        
        $requestUri = $_SERVER['REQUEST_URI'];
        $parts = explode('/', $requestUri);
        $id = end($parts);
        
        // Clean up the ID in case it has query parameters
        if (strpos($id, '?') !== false) {
            $id = strstr($id, '?', true);
        }
        
        // If ID is empty or not a valid format, return null
        if (empty($id) || $id === '') {
            return null;
        }
        
        return $id;
    }
    
    /**
     * Get settings from the database
     * If alias is provided, returns a specific setting
     * If alias is not provided, returns all settings as an associative array with aliases as keys
     * 
     * @param string|null $alias Optional specific setting alias to retrieve
     * @param bool $refresh Whether to refresh the cache
     * @return array|string|null
     */
    public static function getSettings($alias = null, $refresh = false) {
        global $conn;
        static $settingsCache = null;
        
        // If cache is empty or refresh is requested, fetch all settings
        if ($settingsCache === null || $refresh) {
            $settingsCache = [];
            
            if (!$conn) {
                // Try to establish connection if not available
                require_once __DIR__ . '/../Config/database.php';
            }
            
            if ($conn) {
                $query = "SELECT * FROM settings";
                $result = mysqli_query($conn, $query);
                
                if ($result && mysqli_num_rows($result) > 0) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $settingsCache[$row['alias']] = $row;
                    }
                }
            }
        }
        
        // If a specific alias is requested
        if ($alias !== null) {
            return isset($settingsCache[$alias]) ? $settingsCache[$alias] : null;
        }
        
        // Return all settings
        return $settingsCache;
    }

    /**
     * Sort Nigerian phone numbers into their correct networks
     * @param array $phoneNumbers Array of phone numbers
     * @return array Associative array: network => [numbers]
     */
    public static function sortNumbersByNetwork($phoneNumbers) {
        // Nigerian network prefixes
        $prefixes = [
            'MTN' => [
                '0703','0704','0706','07025','07026','0707',
                '0803','0806',
                '0810','0813','0814','0816',
                '0903','0906',
                '0913','0916'
            ],
            'Airtel' => [
                '0701','0708',
                '0802','0808',
                '0812',
                '0901','0902','0904','0907',
                '0911','0912'
            ],
            'Glo' => [
                '0705',
                '0805','0807',
                '0811','0815',
                '0905',
                '0915'
            ],
            '9mobile' => [
                '0809',
                '0817','0818',
                '0908','0909'
            ],
            // Optional: Legacy / defunct networks (if you want a truly full mapping)
            'Starcomms' => ['07028','07029','0819'],
            'Multi-Links' => ['07027','0709'],
            'ZoomMobile' => ['0707'], // sometimes grouped separately
            'Mtel' => ['0804'],
            'Smile' => ['07020']
        ];

        $result = [
            'MTN' => [],
            'Airtel' => [],
            'Glo' => [],
            '9mobile' => [],
            'Unknown' => []
        ];
        foreach ($phoneNumbers as $number) {
            // Normalize number (remove spaces, +234, leading zeros)
            $num = preg_replace('/\D/', '', $number);
            if (strpos($num, '234') === 0) {
                $num = '0' . substr($num, 3);
            }
            if (strlen($num) === 11) {
                $prefix = substr($num, 0, 4);
                $found = false;
                foreach ($prefixes as $network => $networkPrefixes) {
                    if (in_array($prefix, $networkPrefixes)) {
                        $result[$network][] = $num;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $result['Unknown'][] = $num;
                }
            } else {
                $result['Unknown'][] = $number;
            }
        }
        return $result;
    }
}
