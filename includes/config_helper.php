<?php
if (!defined('CONFIG_HELPER_LOADED')) {
    define('CONFIG_HELPER_LOADED', true);

    if (!function_exists('str_starts_with')) {
        function str_starts_with($haystack, $needle) {
            return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
        }
    }

    if (!function_exists('str_ends_with')) {
        function str_ends_with($haystack, $needle) {
            return $needle !== '' && substr($haystack, -strlen($needle)) === (string)$needle;
        }
    }

    require_once __DIR__ . '/security_helper.php';
    setSecurityHeaders(false);

    $env_vars = [];
    $env_file = dirname(__DIR__) . '/.env';
    if (file_exists($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) continue;
            
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || 
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            
            $env_vars[$key] = $value;
            
            if (function_exists('putenv')) {
                putenv("$key=$value");
            }
        }
    }

    function getEnvVar($key, $default = '') {
        global $env_vars;
        
        if (isset($env_vars[$key])) {
            return $env_vars[$key];
        }
        
        $keys = [$key, strtolower($key), str_replace('_', '.', strtolower($key))];
        foreach ($keys as $k) {
            if (isset($_SERVER[$k])) {
                return $_SERVER[$k];
            }
        }
        
        if (function_exists('getenv')) {
            $value = getenv($key);
            if ($value !== false) {
                return $value;
            }
        }
        
        return $default;
    }

    function getConfig($key = null, $default = null) {
        $config_path = dirname(__DIR__) . '/config/config.json';
        static $config = null;
        
        if ($config === null) {
            if (!file_exists($config_path)) {
                $config = [];
            } else {
                $config_content = file_get_contents($config_path);
                $config = json_decode($config_content, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $config = [];
                }
            }
        }
        
        if ($key === null) {
            return $config;
        }
        
        return isset($config[$key]) ? $config[$key] : $default;
    }

    function getUserNameMaxLength() {
        return getConfig('user_name_max', 12);
    }
}
