<?php
if (file_exists(__DIR__ . '/../installed.lock')) {
    header('Content-Type: text/html; charset=utf-8');
    die('系统已完成安装，请直接访问主站');
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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

function getUserIP() {
    $ip = '';
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
    
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $forwarded_ips = array_map('trim', $forwarded_ips);
        $forwarded_ips = array_filter($forwarded_ips, function($ip) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        });
        if (!empty($forwarded_ips)) {
            $ip = end($forwarded_ips);
        }
    }
    
    if (empty($ip)) {
        $ip = $remote_addr;
    }
    
    $ip = filter_var($ip, FILTER_VALIDATE_IP);
    return $ip ?: '0.0.0.0';
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/User.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.php');
    exit;
}

$user_ip = getUserIP();

try {
    $restrict_registration = getConfig('Restrict_registration', false);
    $restrict_registration_ip = getConfig('Restrict_registration_ip', 3);

    if ($restrict_registration) {
        if (!$conn) {
            throw new Exception("Database connection failed");
        }
        
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ip_registrations WHERE ip_address = ?");
        $stmt->execute([$user_ip]);
        $result = $stmt->fetch();
        
        if ($result['count'] >= $restrict_registration_ip) {
            header("Location: register.php?error=" . urlencode("该IP地址已超过注册限制，最多只能注册{$restrict_registration_ip}个账号"));
            exit;
        }
        
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT ir.user_id) as count FROM ip_registrations ir
                            JOIN users u ON ir.user_id = u.id
                            WHERE ir.ip_address = ? AND u.last_active > u.created_at");
        $stmt->execute([$user_ip]);
        $login_result = $stmt->fetch();
        
        if ($login_result['count'] > 0) {
            header("Location: register.php?error=" . urlencode("该IP地址已经有用户登录过，禁止继续注册"));
            exit;
        }
    }

    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $phone = '';

    $errors = [];

    $user_name_max = getUserNameMaxLength();

    if (strlen($username) < 3 || strlen($username) > $user_name_max) {
        $errors[] = "用户名长度必须在3-{$user_name_max}个字符之间";
    }

    if (preg_match('/[<>"\']/', $username)) {
        $errors[] = "用户名不能包含特殊字符（如 <, >, \", '）";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = '请输入有效的邮箱地址';
    }

    if (strlen($password) < 6) {
        $errors[] = '密码长度必须至少6个字符';
    }

    if ($password !== $confirm_password) {
        $errors[] = '两次输入的密码不一致';
    }

    $lot_number = isset($_POST['geetest_challenge']) ? $_POST['geetest_challenge'] : '';
    $captcha_output = isset($_POST['geetest_validate']) ? $_POST['geetest_validate'] : '';
    $pass_token = isset($_POST['geetest_seccode']) ? $_POST['geetest_seccode'] : '';
    $gen_time = isset($_POST['gen_time']) ? $_POST['gen_time'] : '';
    $captcha_id = isset($_POST['captcha_id']) ? $_POST['captcha_id'] : '';

    if (empty($lot_number) || empty($captcha_output) || empty($pass_token) || empty($gen_time) || empty($captcha_id)) {
        $errors[] = '请完成验证码验证';
    } else {
        $captchaId = '55574dfff9c40f2efeb5a26d6d188245';
        $captchaKey = 'e69583b3ddcc2b114388b5e1dc213cfd';
        
        $sign_token = hash_hmac('sha256', $lot_number, $captchaKey);
        
        $apiUrl = 'http://gcaptcha4.geetest.com/validate?captcha_id=' . urlencode($captchaId);
        $params = [
            'lot_number' => $lot_number,
            'captcha_output' => $captcha_output,
            'pass_token' => $pass_token,
            'gen_time' => $gen_time,
            'sign_token' => $sign_token
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        error_log("Geetest 4.0 validation - URL: $apiUrl");
        error_log("Geetest 4.0 validation - Params: " . json_encode($params));
        error_log("Geetest 4.0 validation - HTTP Code: $http_code");
        error_log("Geetest 4.0 validation - Response: $response");
        
        if ($http_code === 200) {
            $result = json_decode($response, true);
            error_log("Geetest 4.0 validation - Decoded Result: " . json_encode($result));
            
            if ($result && $result['status'] === 'success' && $result['result'] === 'success') {
            } else {
                $errors[] = '验证码验证失败，请重新验证';
                $reason = isset($result['reason']) ? $result['reason'] : 'unknown';
                error_log("Geetest 4.0 validation failed - Result: " . json_encode($result) . ", Reason: $reason");
            }
        } else {
            error_log("Geetest 4.0 API request failed - HTTP Code: $http_code, Response: $response");
        }
    }

    if (!empty($errors)) {
        $error_message = implode('<br>', $errors);
        header("Location: register.php?error=" . urlencode($error_message));
        exit;
    }

    $email_verify = getConfig('email_verify', false);

    if ($email_verify) {
        $is_gmail = preg_match('/@gmail\.com$/i', $email);
        
        if (!$is_gmail) {
            $api_url = getConfig('email_verify_api', 'https://api.nbhao.org/v1/email/verify') ?? 'https://api.nbhao.org/v1/email/verify';
            $request_method = strtoupper(getConfig('email_verify_api_Request', 'POST') ?? 'POST');
            $verify_param = getConfig('email_verify_api_Verify_parameters', 'result') ?? 'result';
            
            if (!in_array($request_method, ['GET', 'POST'])) {
                $email_verify = false;
            } else {
                $request_data = [
                    'email' => $email
                ];
                
                $ch = curl_init();
                
                curl_setopt($ch, CURLOPT_URL, $api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                
                if ($request_method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($request_data));
                } else {
                    $api_url .= '?' . http_build_query($request_data);
                    curl_setopt($ch, CURLOPT_URL, $api_url);
                }
                
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json'
                ]);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                if ($http_code === 200) {
                    $response_data = json_decode($response, true);
                    
                    if ($response_data) {
                        $result_value = null;
                        
                        $param_path = explode('.', $verify_param);
                        $temp_data = $response_data;
                        $param_valid = true;
                        
                        foreach ($param_path as $param_part) {
                            if (preg_match('/^(.*?)\[(\d+)\]$/', $param_part, $matches)) {
                                $key = $matches[1];
                                $index = (int)$matches[2];
                                
                                if (isset($temp_data[$key]) && is_array($temp_data[$key]) && isset($temp_data[$key][$index])) {
                                    $temp_data = $temp_data[$key][$index];
                                } else {
                                    $param_valid = false;
                                    break;
                                }
                            } else {
                                if (isset($temp_data[$param_part])) {
                                    $temp_data = $temp_data[$param_part];
                                } else {
                                    $param_valid = false;
                                    break;
                                }
                            }
                        }
                        
                        if ($param_valid) {
                            $result_value = $temp_data;
                        }
                        
                        $lower_result = $result_value !== null ? strtolower((string)$result_value) : '';
                        if ($lower_result !== 'true' && $lower_result !== 'ok') {
                            header("Location: register.php?error=" . urlencode("邮箱验证失败，请仔细填写"));
                            exit;
                        }
                    } else {
                        error_log('邮箱验证API响应解析失败: ' . $response);
                        header("Location: register.php?error=" . urlencode("邮箱验证失败，请仔细填写"));
                        exit;
                    }
                } else {
                    error_log('邮箱验证API请求失败，HTTP状态码: ' . $http_code);
                    header("Location: register.php?error=" . urlencode("邮箱验证失败，请仔细填写"));
                    exit;
                }
            }
        }
    }

    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    $user = new User($conn);

    $result = $user->register($username, $email, $password, $phone, $user_ip);

    if ($result['success']) {
        $user->generateEncryptionKeys($result['user_id']);
        
        require_once dirname(__DIR__) . '/Group.php';
        $group = new Group($conn);
        $group->addUserToAllUserGroups($result['user_id']);
        
        require_once dirname(__DIR__) . '/Friend.php';
        $friend = new Friend($conn);
        
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = 'Admin' OR username = 'admin' LIMIT 1");
        $stmt->execute();
        $admin_user = $stmt->fetch();
        
        if ($admin_user) {
            $admin_id = $admin_user['id'];
            $new_user_id = $result['user_id'];
            
            if (!$friend->isFriend($new_user_id, $admin_id)) {
                try {
                    $stmt = $conn->prepare("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'accepted')");
                    $stmt->execute([$new_user_id, $admin_id]);
                    
                    $stmt = $conn->prepare("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'accepted')");
                    $stmt->execute([$admin_id, $new_user_id]);
                } catch (PDOException $e) {
                    error_log("自动添加Admin好友失败: " . $e->getMessage());
                }
            }
        }
        
        header("Location: login.php?success=" . urlencode('注册成功，请登录'));
        exit;
    } else {
        header("Location: register.php?error=" . urlencode($result['message']));
        exit;
    }

} catch (Throwable $e) {
    $errorMessage = "System Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
    error_log($errorMessage);
    error_log("Stack trace: " . $e->getTraceAsString());
    
    $isDevEnv = file_exists(__DIR__ . '/../.env') || getenv('APP_ENV') === 'dev';
    
    if ($isDevEnv) {
        header("Location: register.php?error=" . urlencode("系统发生严重错误，请联系管理员查看日志"));
    } else {
        header("Location: register.php?error=" . urlencode("系统发生严重错误，请联系管理员"));
    }
    exit;
}
