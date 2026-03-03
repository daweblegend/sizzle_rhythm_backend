<?php

class NotificationHandler 
{
    private const ONESIGNAL_API_URL = 'https://onesignal.com/api/v1/notifications';

    /**
     * Get OneSignal credentials from environment variables
     * 
     * @return array Associative array with appId and apiKey
     */
    private static function getCredentials() {
        // Get credentials from environment variables
        $appId = $_ENV['ONESIGNAL_APP_ID'] ?? null;
        
        // Check both possible key names
        $apiKey = $_ENV['ONESIGNAL_API_KEY'] ?? $_ENV['ONESIGNAL_REST_API_KEY'] ?? null;
        
        // If any credential is missing, log it but don't expose in production
        if (empty($appId) || empty($apiKey)) {
            error_log('⚠️ Some OneSignal credentials missing from .env file');
        }
        
        return [
            'appId' => $appId,
            'apiKey' => $apiKey
        ];
    }

     /**
     * Send gift claim notification to unregistered recipient (email/SMS)
     * @param string|null $email
     * @param string|null $phone
     * @param string|null $name
     * @param string $giftUuid
     */
    public static function sendGiftClaimNotification($email, $phone, $name, $giftUuid) {
        $claimUrl = $_ENV['APP_URL'] . "/v1/gift/claim?uuid=$giftUuid";
        $subject = "You've received a gift on Waves!";
        $body = "<h2>Hi " . htmlspecialchars($name ?: 'there') . ",</h2>"
            . "<p>You have received a gift! <br>"
            . "<a href='" . htmlspecialchars($claimUrl) . "' style='color:#C12928;font-weight:bold;'>Click here to claim your gift</a></p>"
            . "<p>If you don't have an account, you can sign up to claim your gift.</p>";
        // Always send email using MailHandler
        if ($email && class_exists('MailHandler')) {
            MailHandler::sendEmail($email, $subject, $body);
        }
        // Send SMS (if SMS handler available)
        if ($phone) {
            // Implement SMS sending here if you have an SMS handler
            // Example: SmsHandler::sendSms($phone, strip_tags($body));
        }
        // Optionally log notification
        error_log("Gift claim notification sent to $email / $phone for gift $giftUuid");
    }

    /**
     * Send push notification via OneSignal
     * 
     * @param array $options Notification options
     * @return array Response array with success status
     */
    public static function sendPushNotification($options) {
        try {
            $credentials = self::getCredentials();
            $apiKey = $credentials['apiKey'];
            
            if (empty($credentials['appId']) || empty($apiKey)) {
                throw new Exception('OneSignal credentials not properly configured in .env file');
            }
            
            // Build notification payload
            $notification = [
                'app_id' => $credentials['appId'],
                'contents' => $options['contents'] ?? ['en' => 'New notification'],
            ];
            
            // Add optional fields
            if (isset($options['headings'])) {
                $notification['headings'] = $options['headings'];
            }
            
            if (isset($options['data'])) {
                $notification['data'] = $options['data'];
            }
            
            if (isset($options['includePlayerIds'])) {
                $notification['include_player_ids'] = $options['includePlayerIds'];
            }
            
            if (isset($options['includeExternalUserIds'])) {
                $notification['include_external_ids'] = $options['includeExternalUserIds'];
            }
            
            if (isset($options['includedSegments'])) {
                $notification['included_segments'] = $options['includedSegments'];
            }
            
            if (isset($options['filters'])) {
                $notification['filters'] = $options['filters'];
            }
            
            if (isset($options['sendAfter'])) {
                $notification['send_after'] = $options['sendAfter'];
            }
            
            if (isset($options['url'])) {
                $notification['url'] = $options['url'];
            }
            
            if (isset($options['iosAttachments'])) {
                $notification['ios_attachments'] = $options['iosAttachments'];
            }
            
            if (isset($options['bigPicture'])) {
                $notification['big_picture'] = $options['bigPicture'];
            }
            
            // Make HTTP request to OneSignal API
            $response = self::makeOneSignalRequest($notification, $apiKey);
            
            // Check for successful response (200-299)
            if ($response['httpCode'] >= 200 && $response['httpCode'] < 300) {
                try {
                    // Attempt to log successful notification, but continue even if logging fails
                    self::logNotification($notification, 'sent', $response['data']);
                } catch (Exception $e) {
                    error_log('Error logging notification: ' . $e->getMessage());
                }
                
                return [
                    'success' => true,
                    'message' => 'Notification sent successfully',
                    'data' => $response['data']
                ];
            } else {
                // Handle connection issues
                if ($response['httpCode'] === 0) {
                    $errorMessage = isset($response['curlError']) ? 
                                   'Connection error: ' . $response['curlError'] : 
                                   'Failed to connect to OneSignal API';
                    
                    error_log('OneSignal connection error: ' . ($response['curlError'] ?? 'Unknown error'));
                    
                    return [
                        'success' => false,
                        'message' => $errorMessage,
                        'error' => $response['curlError'] ?? 'Connection failed'
                    ];
                }
                
                // Try to log failed notification
                try {
                    self::logNotification($notification, 'failed', $response);
                } catch (Exception $e) {
                    error_log('Error logging failed notification: ' . $e->getMessage());
                }
                
                $errorDetail = isset($response['data']['errors']) ? 
                              json_encode($response['data']['errors']) : 
                              'HTTP ' . $response['httpCode'];
                
                throw new Exception('OneSignal API error: ' . $errorDetail);
            }
            
        } catch (Exception $e) {
            error_log('OneSignal error: ' . $e->getMessage());
            
            // Try to log the failure
            try {
                if (isset($notification)) {
                    self::logNotification($notification, 'failed', ['error' => $e->getMessage()]);
                }
            } catch (Exception $logEx) {
                // Just log the error but don't fail the whole operation
                error_log('Failed to log notification error: ' . $logEx->getMessage());
            }
            
            return [
                'success' => false,
                'message' => 'Failed to send notification',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Make HTTP request to OneSignal API
     * 
     * @param array $notification Notification payload
     * @param string $apiKey OneSignal API key
     * @return array Response data
     */
    private static function makeOneSignalRequest($notification, $apiKey) {
        try {
            $curl = curl_init();
            
            $headers = [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Basic ' . $apiKey
            ];
            
            // Encode the notification data
            $jsonData = json_encode($notification);
            if ($jsonData === false) {
                throw new Exception('Failed to encode notification data: ' . json_last_error_msg());
            }
            
            curl_setopt_array($curl, [
                CURLOPT_URL => self::ONESIGNAL_API_URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 10, // Reduced timeout to 10 seconds
                CURLOPT_CONNECTTIMEOUT => 5, // Connection timeout of 5 seconds
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $jsonData,
                CURLOPT_SSL_VERIFYPEER => true, // Verify SSL certificates
                CURLOPT_SSL_VERIFYHOST => 2,    // Verify the host name
                CURLOPT_VERBOSE => false        // Enable for debugging
            ]);
            
            // Execute the request
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            $errno = curl_errno($curl);
            
            curl_close($curl);
            
            if ($error) {
                error_log("OneSignal cURL error ($errno): $error");
                return [
                    'httpCode' => 0,
                    'data' => ['error' => "Connection error: $error"],
                    'rawResponse' => null,
                    'curlError' => $error,
                    'curlErrno' => $errno
                ];
            }
            
            // For successful responses
            if ($httpCode >= 200 && $httpCode < 300) {
                $decodedResponse = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid JSON response from OneSignal API: ' . json_last_error_msg());
                }
                
                return [
                    'httpCode' => $httpCode,
                    'data' => $decodedResponse,
                    'rawResponse' => $response
                ];
            } 
            // For error responses
            else {
                $decodedResponse = json_decode($response, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // If response is not valid JSON
                    return [
                        'httpCode' => $httpCode,
                        'data' => ['error' => "HTTP error $httpCode"],
                        'rawResponse' => $response
                    ];
                }
                
                return [
                    'httpCode' => $httpCode,
                    'data' => $decodedResponse,
                    'rawResponse' => $response
                ];
            }
        } catch (Exception $e) {
            error_log('OneSignal request exception: ' . $e->getMessage());
            return [
                'httpCode' => 0,
                'data' => ['error' => $e->getMessage()],
                'rawResponse' => null,
                'exception' => $e->getMessage()
            ];
        }
    }

    /**
     * Send general success notification
     * 
     * @param string $userId User ID
     * @param string $title Notification title
     * @param string $message Notification message
     * @param array $data Additional data
     * @return array Response array
     */
    public static function sendSuccessNotification($userId, $title, $message, $data = []) {
        $options = [
            'headings' => ['en' => $title],
            'contents' => ['en' => $message],
            'includeExternalUserIds' => [$userId],
            'data' => array_merge([
                'type' => 'success',
                'timestamp' => date('Y-m-d H:i:s')
            ], $data)
        ];
        
        return self::sendPushNotification($options);
    }

    /**
     * Send error notification
     * 
     * @param string $userId User ID
     * @param string $title Notification title
     * @param string $message Error message
     * @param array $data Additional data
     * @return array Response array
     */
    public static function sendErrorNotification($userId, $title, $message, $data = []) {
        $options = [
            'headings' => ['en' => $title],
            'contents' => ['en' => $message],
            'includeExternalUserIds' => [$userId],
            'data' => array_merge([
                'type' => 'error',
                'timestamp' => date('Y-m-d H:i:s')
            ], $data)
        ];
        
        return self::sendPushNotification($options);
    }
    
    /**
     * Send push notification to multiple external user IDs
     * 
     * @param array $externalIds Array of external user IDs
     * @param string $message Notification message
     * @param string $title Notification title
     * @param array $data Additional data to include
     * @return array Response array
     */
    public static function sendPushNotificationToExternalIds($externalIds, $message, $title = 'Notification', $data = []) {
        if (empty($externalIds) || !is_array($externalIds)) {
            return [
                'success' => false,
                'message' => 'External IDs must be a non-empty array'
            ];
        }
        
        $options = [
            'headings' => ['en' => $title],
            'contents' => ['en' => $message],
            'includeExternalUserIds' => $externalIds,
            'data' => array_merge([
                'type' => 'general',
                'timestamp' => date('Y-m-d H:i:s')
            ], $data)
        ];
        
        return self::sendPushNotification($options);
    }

    /**
     * Log notification (simply logs to error_log in this implementation)
     * 
     * @param array $notificationData Notification data
     * @param string $status Notification status (sent, failed, pending)
     * @param array $response OneSignal response
     * @return void
     */
    public static function logNotification($notificationData, $status = 'sent', $response = []) {
        try {
            // Extract notification details
            $title = $notificationData['headings']['en'] ?? 'No Title';
            $message = $notificationData['contents']['en'] ?? 'No Message';
            
            // Determine notification type
            $type = 'individual';
            
            if (isset($notificationData['included_segments'])) {
                $type = 'broadcast';
            } elseif (isset($notificationData['include_external_ids'])) {
                if (count($notificationData['include_external_ids']) > 1) {
                    $type = 'multiple';
                } else {
                    $type = 'individual';
                }
            } elseif (isset($notificationData['filters'])) {
                $type = 'filtered';
            }
            
            // Log basic notification info
            // error_log(sprintf(
            //     'OneSignal notification [%s] - Title: "%s", Status: %s, Type: %s, Recipients: %s',
            //     date('Y-m-d H:i:s'),
            //     $title,
            //     $status,
            //     $type,
            //     $type === 'individual' ? 
            //         (isset($notificationData['include_external_ids'][0]) ? $notificationData['include_external_ids'][0] : 'Unknown') : 
            //         ($type === 'multiple' ? count($notificationData['include_external_ids']) . ' users' : $type)
            // ));
            
        } catch (Exception $e) {
            error_log('Error logging notification: ' . $e->getMessage());
        }
    }
}
