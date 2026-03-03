<?php

/**
 * PusherUtil Class
 * Handles real-time notifications using Pusher
 */
class PusherUtil 
{
    private static $instance = null;
    private $pusher = null;
    
    /**
     * Get Pusher credentials from environment variables or fallback to defaults
     * 
     * @return array Associative array with appId, appKey, appSecret, and cluster
     */
    private static function getCredentials() {
        // Get credentials from environment variables
        $appId = $_ENV['PUSHER_APP_ID'] ?? null;
        $appKey = $_ENV['PUSHER_APP_KEY'] ?? null;
        $appSecret = $_ENV['PUSHER_APP_SECRET'] ?? null;
        $cluster = $_ENV['PUSHER_APP_CLUSTER'] ?? 'mt1';
        
        // If any credential is missing, log it but don't expose in production
        if (empty($appId) || empty($appKey) || empty($appSecret)) {
            error_log('⚠️ Some Pusher credentials missing from .env file');
        }
        
        return [
            'appId' => $appId,
            'appKey' => $appKey,
            'appSecret' => $appSecret,
            'cluster' => $cluster
        ];
    }
    
    /**
     * Constructor - Initialize Pusher connection using environment variables
     */
    private function __construct() {
        try {
            // Load required Pusher library if not already loaded
            if (!class_exists('\\Pusher\\Pusher')) {
                require_once __DIR__ . '/../vendor/autoload.php';
            }
            
            // Get credentials from .env
            $credentials = self::getCredentials();
            
            // Configure Pusher options
            $options = [
                'cluster' => $credentials['cluster'],
                'useTLS' => true
            ];
            
            // Initialize Pusher with credentials from .env
            $this->pusher = new \Pusher\Pusher(
                $credentials['appKey'],
                $credentials['appSecret'],
                $credentials['appId'],
                $options
            );
            
        } catch (\Exception $e) {
            error_log('❌ Error initializing Pusher: ' . $e->getMessage());
        }
    }
    
    /**
     * Get singleton instance
     * 
     * @return PusherUtil
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Check if Pusher is properly initialized
     * 
     * @return bool Whether Pusher is initialized
     */
    public function isInitialized() {
        return $this->pusher !== null;
    }
    
    /**
     * Get the configured app key (for client-side use)
     * 
     * @return string The Pusher app key from .env
     */
    public static function getAppKey() {
        $credentials = self::getCredentials();
        return $credentials['appKey'];
    }
    
    /**
     * Get the configured cluster (for client-side use)
     * 
     * @return string The Pusher cluster from .env
     */
    public static function getCluster() {
        $credentials = self::getCredentials();
        return $credentials['cluster'];
    }
    
    /**
     * Send notification to a specific user
     * 
     * @param int $userId User ID to send notification to
     * @param string $event Event name
     * @param array $data Data to send
     * @return bool|array Success status or error message
     */
    public function sendToUser($userId, $event, $data) {
        if (!$this->pusher) {
            return ['error' => 'Pusher not initialized'];
        }
        
        try {
            // Create channel name for the specific user
            $channel = "private-user-" . $userId;
            
            // Trigger event on the channel
            $result = $this->pusher->trigger($channel, $event, $data);
            
            return $result;
        } catch (\Exception $e) {
            error_log('❌ Error sending Pusher notification: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Send notification to multiple users
     * 
     * @param array $userIds Array of user IDs
     * @param string $event Event name
     * @param array $data Data to send
     * @return array Results for each user
     */
    public function sendToUsers($userIds, $event, $data) {
        $results = [];
        
        foreach ($userIds as $userId) {
            $results[$userId] = $this->sendToUser($userId, $event, $data);
        }
        
        return $results;
    }
    
    /**
     * Send notification to all users (broadcast)
     * 
     * @param string $event Event name
     * @param array $data Data to send
     * @return bool|array Success status or error message
     */
    public function broadcast($event, $data) {
        if (!$this->pusher) {
            return ['error' => 'Pusher not initialized'];
        }
        
        try {
            // Broadcast to the public channel
            $channel = "public-broadcast";
            $result = $this->pusher->trigger($channel, $event, $data);
            
            return $result;
        } catch (\Exception $e) {
            error_log('❌ Error broadcasting Pusher notification: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Send notification to a specific role/group
     * 
     * @param string $role Role/group name (e.g., 'drivers', 'admins')
     * @param string $event Event name
     * @param array $data Data to send
     * @return bool|array Success status or error message
     */
    public function sendToRole($role, $event, $data) {
        if (!$this->pusher) {
            return ['error' => 'Pusher not initialized'];
        }
        
        try {
            // Create channel name for the specific role
            $channel = "role-" . $role;
            
            // Trigger event on the channel
            $result = $this->pusher->trigger($channel, $event, $data);
            
            return $result;
        } catch (\Exception $e) {
            error_log('❌ Error sending Pusher notification to role: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Send notification to a specific location/region
     * 
     * @param string $location Location/region name
     * @param string $event Event name
     * @param array $data Data to send
     * @return bool|array Success status or error message
     */
    public function sendToLocation($location, $event, $data) {
        if (!$this->pusher) {
            return ['error' => 'Pusher not initialized'];
        }
        
        try {
            // Create channel name for the specific location
            $channel = "location-" . $location;
            
            // Trigger event on the channel
            $result = $this->pusher->trigger($channel, $event, $data);
            
            return $result;
        } catch (\Exception $e) {
            error_log('❌ Error sending Pusher notification to location: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Generate authentication signature for private channels
     * 
     * @param string $socketId Socket ID from Pusher
     * @param string $channel Channel name
     * @return string Authentication signature
     */
    public function authenticate($socketId, $channel) {
        if (!$this->pusher) {
            return ['error' => 'Pusher not initialized'];
        }
        
        try {
            return $this->pusher->socket_auth($channel, $socketId);
        } catch (\Exception $e) {
            error_log('❌ Error authenticating Pusher channel: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}
