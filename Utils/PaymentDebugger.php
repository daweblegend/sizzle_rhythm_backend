<?php

/**
 * Payment Debugger Utility
 * Helps diagnose intermittent payment gateway issues
 */
class PaymentDebugger {
    
    private $logFile;
    
    public function __construct($logFile = null) {
        $this->logFile = $logFile ?: __DIR__ . '/../logs/payment_debug.log';
        $this->ensureLogDirectory();
    }
    
    /**
     * Test payment initialization multiple times to identify patterns
     */
    public function testPaymentReliability($gatewayManager, $testData, $iterations = 10) {
        $results = [
            'total_tests' => $iterations,
            'successful' => 0,
            'failed' => 0,
            'failures' => [],
            'timing' => [],
            'patterns' => []
        ];
        
        $this->log("Starting reliability test with {$iterations} iterations");
        
        for ($i = 1; $i <= $iterations; $i++) {
            $this->log("Test iteration {$i}/{$iterations}");
            
            // Modify reference for each test
            $testData['reference'] = 'TEST_REL_' . $i . '_' . time();
            
            $startTime = microtime(true);
            
            try {
                $result = $gatewayManager->initializePayment($testData);
                $endTime = microtime(true);
                $duration = $endTime - $startTime;
                
                $results['timing'][] = $duration;
                
                if ($result['status'] === 'success') {
                    $results['successful']++;
                    $this->log("✓ Test {$i} passed in {$duration}s", $result);
                } else {
                    $results['failed']++;
                    $results['failures'][] = [
                        'iteration' => $i,
                        'duration' => $duration,
                        'error' => $result['message'],
                        'data' => $result
                    ];
                    $this->log("✗ Test {$i} failed in {$duration}s: " . $result['message'], $result);
                }
                
                // Small delay between tests
                usleep(500000); // 0.5 seconds
                
            } catch (Exception $e) {
                $endTime = microtime(true);
                $duration = $endTime - $startTime;
                
                $results['failed']++;
                $results['failures'][] = [
                    'iteration' => $i,
                    'duration' => $duration,
                    'error' => $e->getMessage(),
                    'exception' => true
                ];
                
                $this->log("✗ Test {$i} exception in {$duration}s: " . $e->getMessage());
            }
        }
        
        // Calculate statistics
        $results['success_rate'] = ($results['successful'] / $results['total_tests']) * 100;
        $results['average_duration'] = array_sum($results['timing']) / count($results['timing']);
        $results['min_duration'] = min($results['timing']);
        $results['max_duration'] = max($results['timing']);
        
        // Analyze patterns
        $results['patterns'] = $this->analyzeFailurePatterns($results['failures']);
        
        $this->log("Test completed", $results);
        
        return $results;
    }
    
    /**
     * Test authentication reliability specifically
     */
    public function testAuthenticationReliability($gateway, $iterations = 20) {
        $results = [
            'total_tests' => $iterations,
            'successful' => 0,
            'failed' => 0,
            'failures' => [],
            'timing' => []
        ];
        
        $this->log("Starting authentication reliability test with {$iterations} iterations");
        
        // Clear any existing tokens to force fresh authentication
        if (method_exists($gateway, 'clearToken')) {
            $gateway->clearToken();
        }
        
        for ($i = 1; $i <= $iterations; $i++) {
            $startTime = microtime(true);
            
            try {
                // Use reflection to access private getAccessToken method
                $reflection = new ReflectionClass($gateway);
                $method = $reflection->getMethod('getAccessToken');
                $method->setAccessible(true);
                
                $token = $method->invoke($gateway);
                $endTime = microtime(true);
                $duration = $endTime - $startTime;
                
                $results['timing'][] = $duration;
                
                if ($token) {
                    $results['successful']++;
                    $this->log("✓ Auth test {$i} passed in {$duration}s");
                } else {
                    $results['failed']++;
                    $results['failures'][] = [
                        'iteration' => $i,
                        'duration' => $duration,
                        'error' => 'No token returned'
                    ];
                    $this->log("✗ Auth test {$i} failed - no token");
                }
                
                usleep(100000); // 0.1 seconds
                
            } catch (Exception $e) {
                $endTime = microtime(true);
                $duration = $endTime - $startTime;
                
                $results['failed']++;
                $results['failures'][] = [
                    'iteration' => $i,
                    'duration' => $duration,
                    'error' => $e->getMessage()
                ];
                
                $this->log("✗ Auth test {$i} exception: " . $e->getMessage());
            }
        }
        
        $results['success_rate'] = ($results['successful'] / $results['total_tests']) * 100;
        $results['average_duration'] = array_sum($results['timing']) / count($results['timing']);
        
        $this->log("Authentication test completed", $results);
        
        return $results;
    }
    
    /**
     * Analyze failure patterns to identify potential issues
     */
    private function analyzeFailurePatterns($failures) {
        $patterns = [
            'timeout_count' => 0,
            'auth_count' => 0,
            'network_count' => 0,
            'duplicate_count' => 0,
            'other_count' => 0,
            'failure_times' => []
        ];
        
        foreach ($failures as $failure) {
            $error = strtolower($failure['error']);
            $patterns['failure_times'][] = $failure['duration'];
            
            if (strpos($error, 'timeout') !== false || strpos($error, 'time') !== false) {
                $patterns['timeout_count']++;
            } elseif (strpos($error, 'auth') !== false || strpos($error, 'token') !== false) {
                $patterns['auth_count']++;
            } elseif (strpos($error, 'network') !== false || strpos($error, 'curl') !== false) {
                $patterns['network_count']++;
            } elseif (strpos($error, 'duplicate') !== false) {
                $patterns['duplicate_count']++;
            } else {
                $patterns['other_count']++;
            }
        }
        
        return $patterns;
    }
    
    /**
     * Generate a comprehensive report
     */
    public function generateReport($testResults) {
        $report = "=== Payment Gateway Reliability Report ===\n\n";
        
        $report .= "Test Summary:\n";
        $report .= "- Total Tests: {$testResults['total_tests']}\n";
        $report .= "- Successful: {$testResults['successful']}\n";
        $report .= "- Failed: {$testResults['failed']}\n";
        $report .= "- Success Rate: " . number_format($testResults['success_rate'], 2) . "%\n\n";
        
        $report .= "Performance:\n";
        $report .= "- Average Duration: " . number_format($testResults['average_duration'], 3) . "s\n";
        $report .= "- Min Duration: " . number_format($testResults['min_duration'], 3) . "s\n";
        $report .= "- Max Duration: " . number_format($testResults['max_duration'], 3) . "s\n\n";
        
        if (!empty($testResults['patterns'])) {
            $patterns = $testResults['patterns'];
            $report .= "Failure Analysis:\n";
            $report .= "- Timeout Errors: {$patterns['timeout_count']}\n";
            $report .= "- Authentication Errors: {$patterns['auth_count']}\n";
            $report .= "- Network Errors: {$patterns['network_count']}\n";
            $report .= "- Duplicate Errors: {$patterns['duplicate_count']}\n";
            $report .= "- Other Errors: {$patterns['other_count']}\n\n";
        }
        
        if (!empty($testResults['failures'])) {
            $report .= "Recent Failures:\n";
            foreach (array_slice($testResults['failures'], -5) as $failure) {
                $report .= "- Iteration {$failure['iteration']}: {$failure['error']} ({$failure['duration']}s)\n";
            }
        }
        
        $report .= "\n=== End Report ===\n";
        
        return $report;
    }
    
    /**
     * Log debug information
     */
    private function log($message, $data = null) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}";
        
        if ($data) {
            $logEntry .= "\nData: " . json_encode($data, JSON_PRETTY_PRINT);
        }
        
        $logEntry .= "\n" . str_repeat('-', 80) . "\n";
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // Also output to console if running from CLI
        if (php_sapi_name() === 'cli') {
            echo $logEntry;
        }
    }
    
    /**
     * Ensure log directory exists
     */
    private function ensureLogDirectory() {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
}
