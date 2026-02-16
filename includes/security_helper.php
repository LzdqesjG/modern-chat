<?php
if (!defined('SECURITY_HELPER_LOADED')) {
    define('SECURITY_HELPER_LOADED', true);

    function setSecurityHeaders($enable_csp = true) {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            if ($enable_csp) {
                header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: https://static.geetest.com https://static2.geetest.com https://static3.geetest.com; style-src 'self' 'unsafe-inline' https://static.geetest.com https://static2.geetest.com; img-src 'self' data: blob: https://static.geetest.com https://static2.geetest.com; font-src 'self' data:; connect-src 'self' https://gcaptcha4.geetest.com https://api.geetest.com https://static.geetest.com; frame-src 'self' blob: https://player.bilibili.com https://www.youtube.com; worker-src 'self' blob:; media-src 'self' blob:; object-src 'none';");
            }
            header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        }
    }

    function generateCSRFToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    function validateCSRFToken($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    function regenerateCSRFToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    function sanitizeOutput($string) {
        if (is_string($string)) {
            return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
        }
        return $string;
    }

    function secureSessionStart() {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', 1);
            session_start();
            
            if (!isset($_SESSION['initiated'])) {
                session_regenerate_id(true);
                $_SESSION['initiated'] = true;
            }
        }
    }

    function rateLimitCheck($identifier, $max_attempts = 10, $time_window = 60) {
        $cache_file = sys_get_temp_dir() . '/rate_limit_' . md5($identifier) . '.json';
        $current_time = time();
        $attempts = [];
        
        if (file_exists($cache_file)) {
            $attempts = json_decode(file_get_contents($cache_file), true) ?: [];
            $attempts = array_filter($attempts, function($time) use ($current_time, $time_window) {
                return ($current_time - $time) < $time_window;
            });
        }
        
        if (count($attempts) >= $max_attempts) {
            return ['allowed' => false, 'retry_after' => $time_window - ($current_time - min($attempts))];
        }
        
        $attempts[] = $current_time;
        file_put_contents($cache_file, json_encode($attempts));
        
        return ['allowed' => true, 'remaining' => $max_attempts - count($attempts)];
    }

    function logSecurityEvent($event_type, $details = []) {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $event_type,
            'ip' => function_exists('getUserIP') ? getUserIP() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'details' => $details
        ];
        error_log("SECURITY EVENT: " . json_encode($log_entry));
    }
}
