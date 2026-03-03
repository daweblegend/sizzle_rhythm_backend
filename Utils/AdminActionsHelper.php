<?php

/**
 * AdminActionsHelper Class
 * 
 * Provides utility functions for logging and tracking admin actions
 * in the Waves backend system
 */
class AdminActionsHelper 
{
    /**
     * Log an administrative action
     * 
     * @param string $adminId The ID of the admin performing the action
     * @param string $action The type of action being performed
     * @param string $details Optional details about the action
     * @return bool True if logging was successful, false otherwise
     */
    public static function logAction($adminId, $action, $details = null) 
    {
        global $conn;
        
        if (!$conn) {
            return false;
        }
        
        try {
            // Sanitize inputs
            $adminId = mysqli_real_escape_string($conn, $adminId);
            $action = mysqli_real_escape_string($conn, $action);
            $details = $details ? mysqli_real_escape_string($conn, $details) : null;
            $timestamp = time();
            
            // Create log entry in database
            $query = "INSERT INTO admin_logs (admin_id, action, details, date) 
                      VALUES ('$adminId', '$action', " . ($details ? "'$details'" : "NULL") . ", '$timestamp')";
            
            $result = mysqli_query($conn, $query);
            
            return $result ? true : false;
            
        } catch (Exception $e) {
            // Log error but don't disrupt the main operation
            error_log('Admin log error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get recent admin activity logs
     * 
     * @param int $limit The number of logs to retrieve (default: 100)
     * @param int $offset Pagination offset (default: 0)
     * @param string $adminId Optional filter by specific admin
     * @return array Array of log entries
     */
    public static function getActivityLogs($limit = 100, $offset = 0, $adminId = null) 
    {
        global $conn;
        
        if (!$conn) {
            return [];
        }
        
        try {
            // Build query with optional admin filter
            $whereClause = $adminId ? "WHERE admin_id = '" . mysqli_real_escape_string($conn, $adminId) . "'" : "";
            
            $query = "SELECT al.*, u.name as admin_name, u.email as admin_email 
                      FROM admin_logs al
                      LEFT JOIN users u ON al.admin_id = u.id
                      $whereClause
                      ORDER BY al.date DESC 
                      LIMIT $offset, $limit";
            
            $result = mysqli_query($conn, $query);
            
            if (!$result) {
                return [];
            }
            
            $logs = [];
            while ($log = mysqli_fetch_assoc($result)) {
                // Format date
                $log['formatted_date'] = date('Y-m-d H:i:s', $log['date']);
                $logs[] = $log;
            }
            
            return $logs;
            
        } catch (Exception $e) {
            error_log('Error retrieving admin logs: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if user has admin privileges
     * 
     * @param int $userId User ID to check
     * @return bool True if user is an admin, false otherwise
     */
    public static function isAdmin($userId) 
    {
        global $conn;
        
        if (!$conn || !$userId) {
            return false;
        }
        
        try {
            $userId = mysqli_real_escape_string($conn, $userId);
            
            $query = "SELECT role FROM users WHERE id = '$userId'";
            $result = mysqli_query($conn, $query);
            
            if (!$result || mysqli_num_rows($result) === 0) {
                return false;
            }
            
            $userData = mysqli_fetch_assoc($result);
            return $userData['role'] === 'admin';
            
        } catch (Exception $e) {
            error_log('Error checking admin status: ' . $e->getMessage());
            return false;
        }
    }
}
