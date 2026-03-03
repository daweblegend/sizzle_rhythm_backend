<?php
/**
 * Simple Environment Variables Loader
 * Loads variables from .env file into $_ENV superglobal
 */

class EnvLoader {
    
    /**
     * Load environment variables from .env file
     * 
     * @param string $filePath Path to .env file
     * @return bool Success status
     */
    public static function load($filePath = null) {
        if ($filePath === null) {
            $filePath = __DIR__ . '/../.env';
        }
        
        if (!file_exists($filePath)) {
            return false;
        }
        
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse key=value pairs
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if (strlen($value) > 1 && 
                    (($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
                    ($value[0] === "'" && $value[strlen($value) - 1] === "'"))) {
                    $value = substr($value, 1, -1);
                }
                
                // Set in $_ENV and putenv
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
        
        return true;
    }
    
    /**
     * Get environment variable value
     * 
     * @param string $key Variable name
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public static function get($key, $default = null) {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}

// Auto-load .env file when this file is included
EnvLoader::load();
?>
