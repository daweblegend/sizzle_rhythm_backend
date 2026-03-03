<?php
namespace Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JWTHandler
{
    private $secret;
    private $expire;

    public function __construct()
    {
        // Try to find .env file from multiple possible locations
        $envPaths = [
            __DIR__ . '/../.env',
            __DIR__ . '/../../.env',
            dirname(__DIR__) . '/.env',
            getcwd() . '/.env'
        ];
        
        $envLoaded = false;
        foreach ($envPaths as $envPath) {
            if (file_exists($envPath)) {
                $dotenv = \Dotenv\Dotenv::createImmutable(dirname($envPath));
                $dotenv->load();
                $envLoaded = true;
                break;
            }
        }
        
        $this->secret = $_ENV['JWT_SECRET'] ?? 'default_secret_key_change_in_production';
        $this->expire = $_ENV['JWT_EXPIRE'] ?? 2592000; // 30 days default
    }

    public function generateToken($userId)
    {
        $issuedAt = time();
        $expirationTime = $issuedAt + $this->expire;
        $payload = [
            'userId' => $userId,
            'iat' => $issuedAt,
            'exp' => $expirationTime
        ];
        return JWT::encode($payload, $this->secret, 'HS256');
    }

    public function validateToken($token)
    {
        try {
            return JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Extract user ID from token
     * 
     * @param string $token
     * @return string|false
     */
    public function getUserIdFromToken($token)
    {
        $decoded = $this->validateToken($token);
        return $decoded ? $decoded->userId : false;
    }

    /**
     * Check if token is expired
     * 
     * @param string $token
     * @return bool
     */
    public function isTokenExpired($token)
    {
        $decoded = $this->validateToken($token);
        if (!$decoded) {
            return true;
        }
        
        return $decoded->exp < time();
    }

    /**
     * Refresh token (generate new token with same user ID)
     * 
     * @param string $token
     * @return string|false
     */
    public function refreshToken($token)
    {
        $userId = $this->getUserIdFromToken($token);
        if (!$userId) {
            return false;
        }
        
        return $this->generateToken($userId);
    }

    /**
     * Get token from Authorization header
     * 
     * @return string|false
     */
    public static function getTokenFromHeader()
    {
        $headers = getallheaders();
        
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            if (strpos($auth, 'Bearer ') === 0) {
                return substr($auth, 7);
            }
        }
        
        return false;
    }
}