<?php
require_once 'config.php';
require_once 'db.php';
require_once 'User.php';

// 检查是否是POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.php');
    exit;
}

// 获取用户IP地址
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

$user_ip = getUserIP();

// 检查是否启用了IP注册限制
$restrict_registration = getConfig('Restrict_registration', false);
$restrict_registration_ip = getConfig('Restrict_registration_ip', 3);

if ($restrict_registration) {
    // 检查该IP地址已经注册的用户数量
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
$password = $_POST['password'];
$confirm_password = $_POST['confirm_password'];

// 验证表单数据
$errors = [];

// 获取用户名最大长度配置
$user_name_max = getUserNameMaxLength();

if (strlen($username) < 3 || strlen($username) > $user_name_max) {
    $errors[] = "用户名长度必须在3-{$user_name_max}个字符之间";
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = '请输入有效的邮箱地址';
}

if (strlen($password) < 6) {
    $errors[] = '密码长度必须至少为6个字符';
}

if ($password !== $confirm_password) {
    $errors[] = '两次输入的密码不一致';
}

// 如果有错误，重定向回注册页面
if (!empty($errors)) {
    $error_message = implode('<br>', $errors);
    header("Location: register.php?error=" . urlencode($error_message));
    exit;
}

// 检查是否启用邮箱验证
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
            // 请求方法无效，跳过邮箱验证
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
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // 禁用SSL验证，根据实际情况调整
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // 禁用SSL主机验证，根据实际情况调整
            
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
            
            // 关闭cURL
            curl_close($ch);
            
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
                    if (strtolower($result_value) !== 'true' && strtolower($result_value) !== 'ok') {
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
$result = $user->register($username, $email, $password, $user_ip);

if ($result['success']) {
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