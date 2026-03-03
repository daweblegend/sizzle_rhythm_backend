<?php
define('APP_ROOT', dirname(__DIR__));
define('BASE_URL', APP_ROOT );

/**
 * Third-party API Keys
 */
// Add your Google Maps API key here
$apiKey = "AIzaSyAp4l3NVdnhI-q-2ls2_pa-wxxF2ILR9F4"; // Replace with your actual Google Maps API key

/**
 * CORS Headers - Set these early before any output
 */
function setCorsHeaders() {
    // Allow from any origin
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    } else {
        header("Access-Control-Allow-Origin: *");
    }
    
    // Set other CORS headers
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-API-Key');
    header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours
    
    // Set content type for API responses
    header('Content-Type: application/json');
}

/**
 * Handle OPTIONS preflight request
 */
function handleOptionsRequest() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        setCorsHeaders();
        http_response_code(200);
        exit(0);
    }
}

// Set CORS headers immediately
setCorsHeaders();

// Handle OPTIONS preflight requests
handleOptionsRequest();

/**
 * Helper functions for cleaner path management
 */

// Get absolute path from project root
function app_path($path = '') {
    return APP_ROOT . ($path ? '/' . ltrim($path, '/') : '');
}

// Get config file path
function config_path($file = '') {
    return app_path('Config/' . ltrim($file, '/'));
}

// Get utils file path
function utils_path($file = '') {
    return app_path('Utils/' . ltrim($file, '/'));
}

// Get src file path
function src_path($file = '') {
    return app_path('src/' . ltrim($file, '/'));
}

// Require a file from project root
function require_app($path) {
    require_once app_path($path);
}

// Require a config file
function require_config($file) {
    require_once config_path($file);
}

// Require a utils file
function require_utils($file) {
    require_once utils_path($file);
}