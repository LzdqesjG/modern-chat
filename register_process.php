<?php
require_once 'security_check.php';
if (file_exists(__DIR__ . '/lock')) {
    header('Content-Type: text/html; charset=utf-8');
    die('请先进行部署后再使用');
}
require_once 'config.php';
require_once 'db.php';
require_once 'User.php';

// 检查是否是POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.php');
    exit;
}

// 获取用户IP地址
// 使用config.php中定义的getUserIP()函数
$user_ip = getUserIP();

try {
    // 检查是否启用了IP注册限制
    $restrict_registration = getConfig('Restrict_registration', false);
    $restrict_registration_ip = getConfig('Restrict_registration_ip', 3);

    if ($restrict_registration) {
        // 检查数据库连接是否成功
        if (!$conn) {
            throw new Exception("Database connection failed");
        }
        
        // 检查该IP地址已经注册的用户数�?        
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM ip_registrations WHERE ip_address = ?");
        $stmt->execute([$user_ip]);
        $result = $stmt->fetch();
        
        if ($result['count'] >= $restrict_registration_ip) {
            // 超过限制，拒绝注册            
header("Location: register.php?error=" . urlencode("该IP地址已超过注册限制，最多只能注册{$restrict_registration_ip}个账号"));
            exit;
        }
        
        // 检查该IP地址是否已经有用户登录过
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT ir.user_id) as count FROM ip_registrations ir
                            JOIN users u ON ir.user_id = u.id
                            WHERE ir.ip_address = ? AND u.last_active > u.created_at");
        $stmt->execute([$user_ip]);
        $login_result = $stmt->fetch();
        
        if ($login_result['count'] > 0) {
            // 该IP地址已经有用户登录过，拒绝注册            
header("Location: register.php?error=" . urlencode("该IP地址已经有用户登录过，禁止继续注册"));
            exit;
        }
    }

    // 获取表单数据
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $sms_code = trim($_POST['sms_code']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // 验证表单数据
    $errors = [];

    // 验证手机号和短信验证�?    
if (empty($phone) || !preg_match('/^1[3-9]\d{9}$/', $phone)) {
        $errors[] = '请输入有效的手机号';
    }

    if (empty($sms_code)) {
        $errors[] = '请输入短信验证码';
    } else {
        // 验证短信验证码
        if (!isset($_SESSION['sms_code']) || !isset($_SESSION['sms_phone']) || !isset($_SESSION['sms_expire'])) {
            $errors[] = '短信验证码错误，请检查是否过期'; // Session过期或未发送
        } 
        elseif ($_SESSION['sms_phone'] !== $phone) {
            $errors[] = '手机号与接收验证码的手机号不一致';
        } elseif (time() > $_SESSION['sms_expire']) {
            $errors[] = '短信验证码已过期，请重新获取';
        } elseif ($_SESSION['sms_code'] !== $sms_code) {
            $errors[] = '短信验证码错误，请检查是否输入错误';
        } else {
            // 验证通过
            // 强制使用接收验证码的手机号作为注册手机号，实现自动关联
            $phone = $_SESSION['sms_phone'];
            
            // 验证通过，可以选择清除Session防止重复使用
            unset($_SESSION['sms_code']);
            // 这里不清除sms_phone，以防后续还需要用到（虽然上面已经赋值给了$phone）
            unset($_SESSION['sms_expire']);
            // 暂时保留，防止用户提交失败后需要重新获取
        }
    }

    // 获取用户名最大长度配置
    $user_name_max = getUserNameMaxLength();

    if (strlen($username) < 3 || strlen($username) > $user_name_max) {
        $errors[] = "用户名长度必须在3-{$user_name_max}个字符之间";
    }

    // 修复：禁止包含HTML标签或特殊字符
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

    // 极验4.0验证码验证
    // 如果已经通过短信发送时的极验验证（5分钟内），则跳过此次验证
    if (isset($_SESSION['geetest_verified_time']) && (time() - $_SESSION['geetest_verified_time'] < 300)) {
        // 已通过验证，跳过
        error_log("Geetest validation skipped due to recent successful verification.");
    } else {
        $lot_number = isset($_POST['geetest_challenge']) ? $_POST['geetest_challenge'] : '';
        $captcha_output = isset($_POST['geetest_validate']) ? $_POST['geetest_validate'] : '';
        $pass_token = isset($_POST['geetest_seccode']) ? $_POST['geetest_seccode'] : '';
        $gen_time = isset($_POST['gen_time']) ? $_POST['gen_time'] : '';
        $captcha_id = isset($_POST['captcha_id']) ? $_POST['captcha_id'] : '';

        if (empty($lot_number) || empty($captcha_output) || empty($pass_token) || empty($gen_time) || empty($captcha_id)) {
            $errors[] = '请完成验证码验证';
        } else {
            // 调用极验服务器端API验证
            $captchaId = '55574dfff9c40f2efeb5a26d6d188245';
            $captchaKey = 'e69583b3ddcc2b114388b5e1dc213cfd';
            
            // 生成签名
            $sign_token = hash_hmac('sha256', $lot_number, $captchaKey);
            
            $apiUrl = 'http://gcaptcha4.geetest.com/validate?captcha_id=' . urlencode($captchaId);
            $params = [
                'lot_number' => $lot_number,
                'captcha_output' => $captcha_output,
                'pass_token' => $pass_token,
                'gen_time' => $gen_time,
                'sign_token' => $sign_token
            ];
            
            // 使用curl发送验证请�?            
$ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 设置超时时间�?0�?            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            // 调试信息，记录到日志
            error_log("Geetest 4.0 validation - URL: $apiUrl");
            error_log("Geetest 4.0 validation - Params: " . json_encode($params));
            error_log("Geetest 4.0 validation - HTTP Code: $http_code");
            error_log("Geetest 4.0 validation - Response: $response");
            
            // 检查响�?            
if ($http_code === 200) {
                $result = json_decode($response, true);
                error_log("Geetest 4.0 validation - Decoded Result: " . json_encode($result));
                
                if ($result && $result['status'] === 'success' && $result['result'] === 'success') {
                    // 验证成功
                } else {
                    $errors[] = '验证码验证失败，请重新验证';
                    $reason = isset($result['reason']) ? $result['reason'] : 'unknown';
                    error_log("Geetest 4.0 validation failed - Result: " . json_encode($result) . ", Reason: $reason");
                }
            } else {
                // API请求失败，暂时跳过验证（可能是网络问题）
                error_log("Geetest 4.0 API request failed - HTTP Code: $http_code, Response: $response");
            }
        }
    }

    // 如果有错误，重定向回注册页面
    if (!empty($errors)) {
        $error_message = implode('<br>', $errors);
        header("Location: register.php?error=" . urlencode($error_message));
        exit;
    }

    // 检查是否启用邮箱验�?    
$email_verify = getConfig('email_verify', false);

    if ($email_verify) {
        // 判断邮箱是否为Gmail
        $is_gmail = preg_match('/@gmail\.com$/i', $email);
        
        if (!$is_gmail) {
            // 非Gmail邮箱，使用API验证
            $api_url = getConfig('email_verify_api', 'https://api.nbhao.org/v1/email/verify');
            $request_method = strtoupper(getConfig('email_verify_api_Request', 'POST'));
            $verify_param = getConfig('email_verify_api_Verify_parameters', 'result');
            
            // 验证请求方法，只允许GET或POST
            if (!in_array($request_method, ['GET', 'POST'])) {
                // 请求方法无效，跳过邮箱验�?                
$email_verify = false;
            } else {
                // 准备请求数据
                $request_data = [
                    'email' => $email
                ];
                
                // 初始化cURL
                $ch = curl_init();
                
                // 设置cURL选项
                curl_setopt($ch, CURLOPT_URL, $api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 禁用SSL验证，根据实际情况调�?                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // 禁用SSL主机验证，根据实际情况调�?                
                if ($request_method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($request_data));
                } else {
                    // GET请求，将参数添加到URL
                    $api_url .= '?' . http_build_query($request_data);
                    curl_setopt($ch, CURLOPT_URL, $api_url);
                }
                
                // 设置请求头
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json'
                ]);
                
                // 执行请求并获取响应
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                // cURL 资源会在不再被引用时自动关闭，无需显式调用 curl_close()
                
                if ($http_code === 200) {
                    // 解析响应
                    $response_data = json_decode($response, true);
                    
                    if ($response_data) {
                        // 提取验证结果
                        $result_value = null;
                        
                        // 处理嵌套参数，如data.[0].result
                        $param_path = explode('.', $verify_param);
                        $temp_data = $response_data;
                        $param_valid = true;
                        
                        foreach ($param_path as $param_part) {
                            // 处理数组索引，如[0]
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
                                // 普通键
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
                        
                        // 检查验证结果
                        $lower_result = $result_value !== null ? strtolower((string)$result_value) : '';
                        if ($lower_result !== 'true' && $lower_result !== 'ok') {
                            header("Location: register.php?error=" . urlencode("邮箱验证失败，请仔细填写"));
                            exit;
                        }
                    } else {
                        // 无法解析响应
                        error_log('邮箱验证API响应解析失败: ' . $response);
                        header("Location: register.php?error=" . urlencode("邮箱验证失败，请仔细填写"));
                        exit;
                    }
                } else {
                    // API请求失败
                    error_log('邮箱验证API请求失败，HTTP状态码: ' . $http_code);
                    header("Location: register.php?error=" . urlencode("邮箱验证失败，请仔细填写"));
                    exit;
                }
            }
        }
    }

    // 创建User实例
    $user = new User($conn);

    // 尝试注册用户，传入IP地址
    $result = $user->register($username, $email, $password, $phone, $user_ip);

    if ($result['success']) {
        // 为新用户生成加密密钥
        $user->generateEncryptionKeys($result['user_id']);
        
        // 注册成功，将用户添加到所有全员群聊
        require_once 'Group.php';
        $group = new Group($conn);
        $group->addUserToAllUserGroups($result['user_id']);
        
        // 自动添加Admin管理员为好友并自动通过
        require_once 'Friend.php';
        $friend = new Friend($conn);
        
        // 获取Admin用户的ID
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = 'Admin' OR username = 'admin' LIMIT 1");
        $stmt->execute();
        $admin_user = $stmt->fetch();
        
        if ($admin_user) {
            $admin_id = $admin_user['id'];
            $new_user_id = $result['user_id'];
            
            // 检查是否已经是好友
            if (!$friend->isFriend($new_user_id, $admin_id)) {
                // 直接创建好友关系，跳过请求步骤
                try {
                    // 创建正向关系
                    $stmt = $conn->prepare("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'accepted')");
                    $stmt->execute([$new_user_id, $admin_id]);
                    
                    // 创建反向关系
                    $stmt = $conn->prepare("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'accepted')");
                    $stmt->execute([$admin_id, $new_user_id]);
                } catch (PDOException $e) {
                    error_log("自动添加Admin好友失败: " . $e->getMessage());
                }
            }
        }
        
        // 注册成功，重定向到登录页面
        header("Location: login.php?success=" . urlencode('注册成功，请登录'));
        exit;
    } else {
        // 注册失败，重定向回注册页面
        header("Location: register.php?error=" . urlencode($result['message']));
        exit;
    }

} catch (Throwable $e) {
    // 捕获所有异常和错误
    $errorMessage = "System Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
    error_log($errorMessage);
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // 如果是开发环境，可以显示详细错误，生产环境只显示通用错误
    // 
header("Location: register.php?error=" . urlencode("系统发生严重错误，请联系管理员查看日志"));
    // 为了调试方便，暂时显示详细错误（注意：生产环境应改为上面的通用提示�?    
header("Location: register.php?error=" . urlencode("系统错误: " . $e->getMessage()));
    exit;
}