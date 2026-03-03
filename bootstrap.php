<?php
// Bootstrap file - include this in all your endpoint files

// Define APP_ROOT if not already defined
if (!defined('APP_ROOT')) {
    define('APP_ROOT', __DIR__);
}

// Load composer dependencies
require_once APP_ROOT . '/vendor/autoload.php';

// Load environment variables from .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Auto-include common files
require_once APP_ROOT . '/Config/database.php';
require_once APP_ROOT . '/Utils/ResponseHandler.php';

// Optional: Include other commonly used utilities
// require_once APP_ROOT . '/Middleware/JWTHandler.php';
// require_once APP_ROOT . '/Utils/MailHandler.php';

// Note: CORS headers are now handled in Config/global.php
