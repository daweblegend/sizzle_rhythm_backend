<?php

class ResponseHandler 
{
    /**
     * Send a success response
     * 
     * @param string $message Success message
     * @param mixed $data Optional data to include
     * @param int $code HTTP status code (default: 200)
     */
    public static function success($message = 'Success', $data = null, $code = 200) 
    {
        http_response_code($code);
        header('Content-Type: application/json');
        
        $response = [
            'code' => $code,
            'status' => 'success',
            'message' => $message
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response);
        exit;
    }
    
    /**
     * Send an error response
     * 
     * @param string $message Error message
     * @param mixed $data Optional error data to include
     * @param int $code HTTP status code (default: 400)
     */
    public static function error($message = 'An error occurred', $data = null, $code = 400) 
    {
        http_response_code($code);
        header('Content-Type: application/json');
        
        $response = [
            'code' => $code,
            'status' => 'error',
            'message' => $message
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response);
        exit;
    }
    
    /**
     * Send a validation error response
     * 
     * @param string $message Validation error message
     * @param array $errors Array of validation errors
     */
    public static function validationError($message = 'Validation failed', $errors = []) 
    {
        self::error($message, ['validation_errors' => $errors], 422);
    }
    
    /**
     * Send an unauthorized response
     * 
     * @param string $message Unauthorized message
     */
    public static function unauthorized($message = 'Unauthorized access') 
    {
        self::error($message, null, 401);
    }
    
    /**
     * Send a forbidden response
     * 
     * @param string $message Forbidden message
     */
    public static function forbidden($message = 'Access forbidden') 
    {
        self::error($message, null, 403);
    }
    
    /**
     * Send a not found response
     * 
     * @param string $message Not found message
     */
    public static function notFound($message = 'Resource not found') 
    {
        self::error($message, null, 404);
    }
    
    /**
     * Send a server error response
     * 
     * @param string $message Server error message
     */
    public static function serverError($message = 'Internal server error') 
    {
        self::error($message, null, 500);
    }
    
    // Debug method removed
}
