<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Config/global.php';

use Google\Client as Google_Client;
use Google\Service\Oauth2 as Google_Service_Oauth2;

class GoogleOAuthHandler {
    private $client;
    
    public function __construct() {
        // Load .env file if not already loaded
        $this->loadEnv();
        
        $this->client = new Google_Client();
        
        // Get credentials from environment
        $clientId = $this->getEnv('GOOGLE_CLIENT_ID');
        $clientSecret = $this->getEnv('GOOGLE_CLIENT_SECRET');
        $redirectUri = $this->getEnv('GOOGLE_REDIRECT_URI');
        
        // Validate required credentials
        if (empty($clientId) || empty($clientSecret) || empty($redirectUri)) {
            throw new Exception('Google OAuth credentials not properly configured in .env file');
        }
        
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        $this->client->setRedirectUri($redirectUri);
        $this->client->addScope("email");
        $this->client->addScope("profile");
    }
    
    /**
     * Load environment variables from .env file
     */
    private function loadEnv() {
        $envFile = __DIR__ . '/../.env';
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Skip comments
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                
                // Parse key=value
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    
                    // Set as environment variable if not already set
                    if (!getenv($key)) {
                        putenv("$key=$value");
                        $_ENV[$key] = $value;
                        $_SERVER[$key] = $value;
                    }
                }
            }
        }
    }
    
    /**
     * Get environment variable with fallback
     */
    private function getEnv($key, $default = null) {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }
        return $value;
    }
    
    /**
     * Get the Google OAuth authorization URL
     * @return string
     */
    public function getAuthUrl() {
        return $this->client->createAuthUrl();
    }
    
    /**
     * Exchange authorization code for access token and get user info
     * @param string $code - Authorization code from Google
     * @return array|false - User information or false on failure
     */
    public function getUserFromCode($code) {
        try {
            // Exchange authorization code for access token
            $token = $this->client->fetchAccessTokenWithAuthCode($code);
            
            // Check if there was an error
            if (isset($token['error'])) {
                error_log("Google OAuth Error: " . $token['error']);
                return false;
            }
            
            // Set the access token
            $this->client->setAccessToken($token);
            
            // Get user info
            $oauth2 = new Google_Service_Oauth2($this->client);
            $userInfo = $oauth2->userinfo->get();
            
            return [
                'provider' => 'google',
                'provider_id' => $userInfo->id,
                'email' => $userInfo->email,
                'name' => $userInfo->name,
                'first_name' => $userInfo->givenName ?? '',
                'last_name' => $userInfo->familyName ?? '',
                'profile_picture' => $userInfo->picture ?? '',
                'email_verified' => $userInfo->verifiedEmail ?? false,
                'access_token' => $token['access_token'] ?? null,
                'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_in' => $token['expires_in'] ?? null,
            ];
        } catch (Exception $e) {
            error_log("Google OAuth Exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verify a Google ID token (for mobile/frontend direct auth)
     * @param string $idToken - Google ID token
     * @return array|false - User information or false on failure
     */
    public function verifyIdToken($idToken) {
        try {
            $payload = $this->client->verifyIdToken($idToken);
            
            if ($payload) {
                return [
                    'provider' => 'google',
                    'provider_id' => $payload['sub'],
                    'email' => $payload['email'] ?? '',
                    'name' => $payload['name'] ?? '',
                    'first_name' => $payload['given_name'] ?? '',
                    'last_name' => $payload['family_name'] ?? '',
                    'profile_picture' => $payload['picture'] ?? '',
                    'email_verified' => $payload['email_verified'] ?? false,
                ];
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Google ID Token Verification Exception: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Revoke the access token
     * @param string $accessToken
     * @return bool
     */
    public function revokeToken($accessToken) {
        try {
            $this->client->revokeToken($accessToken);
            return true;
        } catch (Exception $e) {
            error_log("Google Token Revocation Exception: " . $e->getMessage());
            return false;
        }
    }
}
