<?php
require_once __DIR__ . '/../Config/global.php';
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/vendor/autoload.php';

use Ramsey\Uuid\Uuid;

/**
 * Email Queue Handler
 * 
 * This class handles queueing and processing of emails for asynchronous sending
 */
class EmailQueueHandler {
    
    /**
     * Queue an email for asynchronous sending
     * 
     * @param string $email Recipient email address
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param bool $includeHero Whether to include hero image (default: false)
     * @param int $priority Email priority (1=highest, 10=lowest, default: 5)
     * @param string|null $scheduledAt When to send email (ISO format, null for immediate)
     * @return array Result with success status and queue ID
     */
    public static function queueEmail($email, $subject, $body, $includeHero = false, $priority = 5, $scheduledAt = null) {
        global $conn;
        
        try {
            // Generate UUID for the queue item
            $uuid = Uuid::uuid4()->toString();
            
            // Sanitize inputs
            $email = mysqli_real_escape_string($conn, $email);
            $subject = mysqli_real_escape_string($conn, $subject);
            $body = mysqli_real_escape_string($conn, $body);
            $includeHero = $includeHero ? 1 : 0;
            $priority = max(1, min(10, (int)$priority)); // Ensure priority is between 1-10
            
            // Handle scheduled timestamp
            $scheduledAtClause = 'NULL';
            if ($scheduledAt) {
                $scheduledTimestamp = date('Y-m-d H:i:s', strtotime($scheduledAt));
                $scheduledAtClause = "'" . mysqli_real_escape_string($conn, $scheduledTimestamp) . "'";
            }
            
            // Insert into email queue
            $sql = "INSERT INTO email_queue (
                        uuid, 
                        recipient_email, 
                        subject, 
                        body, 
                        include_hero, 
                        priority, 
                        scheduled_at,
                        status,
                        created_at
                    ) VALUES (
                        '$uuid', 
                        '$email', 
                        '$subject', 
                        '$body', 
                        $includeHero, 
                        $priority, 
                        $scheduledAtClause,
                        'pending',
                        NOW()
                    )";
            
            if (mysqli_query($conn, $sql)) {
                $queueId = mysqli_insert_id($conn);
                
                // Log successful queue
                error_log("📧 Email queued successfully - Queue ID: $queueId, UUID: $uuid, To: $email, Subject: $subject");
                
                return [
                    'success' => true,
                    'message' => 'Email queued successfully',
                    'queue_id' => $queueId,
                    'uuid' => $uuid
                ];
            } else {
                throw new Exception('Database error: ' . mysqli_error($conn));
            }
            
        } catch (Exception $e) {
            error_log('❌ Failed to queue email: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to queue email: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get pending emails from the queue
     * 
     * @param int $limit Maximum number of emails to fetch
     * @param bool $includeScheduled Whether to include scheduled emails that are due
     * @return array Array of pending email records
     */
    public static function getPendingEmails($limit = 10, $includeScheduled = true) {
        global $conn;
        
        try {
            $currentTime = date('Y-m-d H:i:s');
            $scheduledClause = $includeScheduled 
                ? "AND (scheduled_at IS NULL OR scheduled_at <= '$currentTime')"
                : "AND scheduled_at IS NULL";
            
            $sql = "SELECT * FROM email_queue 
                    WHERE status = 'pending' 
                    AND attempts < max_attempts 
                    $scheduledClause
                    ORDER BY priority ASC, created_at ASC 
                    LIMIT $limit";
            
            $result = mysqli_query($conn, $sql);
            
            if ($result) {
                $emails = [];
                while ($row = mysqli_fetch_assoc($result)) {
                    $emails[] = $row;
                }
                return $emails;
            } else {
                throw new Exception('Database error: ' . mysqli_error($conn));
            }
            
        } catch (Exception $e) {
            error_log('❌ Failed to fetch pending emails: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark email as processing
     * 
     * @param int $queueId Queue ID
     * @return bool Success status
     */
    public static function markAsProcessing($queueId) {
        global $conn;
        
        $sql = "UPDATE email_queue 
                SET status = 'processing', 
                    updated_at = NOW() 
                WHERE id = " . (int)$queueId;
        
        return mysqli_query($conn, $sql);
    }
    
    /**
     * Mark email as sent successfully
     * 
     * @param int $queueId Queue ID
     * @return bool Success status
     */
    public static function markAsSent($queueId) {
        global $conn;
        
        $sql = "UPDATE email_queue 
                SET status = 'sent', 
                    processed_at = NOW(), 
                    updated_at = NOW() 
                WHERE id = " . (int)$queueId;
        
        return mysqli_query($conn, $sql);
    }
    
    /**
     * Mark email as failed and increment attempts
     * 
     * @param int $queueId Queue ID
     * @param string $errorMessage Error message
     * @return bool Success status
     */
    public static function markAsFailed($queueId, $errorMessage = '') {
        global $conn;
        
        $errorMessage = mysqli_real_escape_string($conn, $errorMessage);
        
        $sql = "UPDATE email_queue 
                SET status = 'failed', 
                    attempts = attempts + 1,
                    error_message = '$errorMessage',
                    updated_at = NOW() 
                WHERE id = " . (int)$queueId;
        
        return mysqli_query($conn, $sql);
    }
    
    /**
     * Retry failed email (reset to pending if under max attempts)
     * 
     * @param int $queueId Queue ID
     * @return bool Success status
     */
    public static function retryEmail($queueId) {
        global $conn;
        
        $sql = "UPDATE email_queue 
                SET status = 'pending', 
                    error_message = NULL,
                    updated_at = NOW() 
                WHERE id = " . (int)$queueId . " 
                AND attempts < max_attempts";
        
        return mysqli_query($conn, $sql);
    }
    
    /**
     * Get queue statistics
     * 
     * @return array Queue statistics
     */
    public static function getQueueStats() {
        global $conn;
        
        $stats = [
            'pending' => 0,
            'processing' => 0,
            'sent' => 0,
            'failed' => 0,
            'total' => 0
        ];
        
        try {
            $sql = "SELECT status, COUNT(*) as count FROM email_queue GROUP BY status";
            $result = mysqli_query($conn, $sql);
            
            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $stats[$row['status']] = (int)$row['count'];
                    $stats['total'] += (int)$row['count'];
                }
            }
            
            return $stats;
            
        } catch (Exception $e) {
            error_log('❌ Failed to get queue stats: ' . $e->getMessage());
            return $stats;
        }
    }
    
    /**
     * Clean up old processed emails (older than specified days)
     * 
     * @param int $daysOld Number of days to keep processed emails
     * @return int Number of cleaned up records
     */
    public static function cleanupOldEmails($daysOld = 7) {
        global $conn;
        
        try {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-$daysOld days"));
            
            $sql = "DELETE FROM email_queue 
                    WHERE status IN ('sent', 'failed') 
                    AND (processed_at < '$cutoffDate' OR updated_at < '$cutoffDate')
                    AND attempts >= max_attempts";
            
            $result = mysqli_query($conn, $sql);
            
            if ($result) {
                $deletedCount = mysqli_affected_rows($conn);
                error_log("🧹 Cleaned up $deletedCount old email records");
                return $deletedCount;
            }
            
            return 0;
            
        } catch (Exception $e) {
            error_log('❌ Failed to cleanup old emails: ' . $e->getMessage());
            return 0;
        }
    }
}
