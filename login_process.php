<?php
require_once 'security_check.php';
require_once 'config.php';
require_once 'db.php';

// 确保必要字段存在
try {
    // 检查users表是否有is_deleted字段
    $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'is_deleted'");
    $stmt->execute();
    $deleted_column_exists = $stmt->fetch();
    
    if (!$deleted_column_exists) {
        // 添加is_deleted字段
        $conn->exec("ALTER TABLE users ADD COLUMN is_deleted BOOLEAN DEFAULT FALSE AFTER is_admin");
        error_log("Added is_deleted column to users table");
    }
    
    // 检查users表是否有agreed_to_terms字段
    $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'agreed_to_terms'");
    $stmt->execute();
    $terms_column_exists = $stmt->fetch();
    
    if (!$terms_column_exists) {
        // 添加agreed_to_terms字段，记录用户是否同意协�?        
        $conn->exec("ALTER TABLE users ADD COLUMN agreed_to_terms BOOLEAN DEFAULT FALSE AFTER is_deleted");
        error_log("Added agreed_to_terms column to users table");
        
        // 将管理员用户设置为已同意协议
        $conn->exec("UPDATE users SET agreed_to_terms = TRUE WHERE is_admin = TRUE");
        error_log("Set admin users as agreed to terms");
    }
    
    // 确保IP相关表存�?    // 不要直接包含db.sql文件，这会导致SQL内容被输�?    
    // 已通过install_tables.php脚本或createGroupTables函数创建了所需�?
    } 
    catch (PDOException $e) {
    error_log("Field setup error: " . $e->getMessage());
}

// 使用config.php中定义的getUserIP()函数获取客户端IP地址
// 这里使用别名函数，保持与现有代码的兼容�?
    function getClientIP() {
    return getUserIP();
}

// 记录登录尝试
        function logLoginAttempt($conn, $ip_address, $is_successful = false) {
            try {
                // 将is_successful转换为整�?                
$is_successful_int = (int)$is_successful;
                $stmt = $conn->prepare("INSERT INTO ip_login_attempts (ip_address, is_successful) VALUES (?, ?)");
                $stmt->execute([$ip_address, $is_successful_int]);
                return true;
            } catch (PDOException $e) {
                error_log("Log Login Attempt Error: " . $e->getMessage());
                return false;
            }
        }

// 更新过期的IP封禁
function updateExpiredIpBans($conn) {
    try {
        $stmt = $conn->prepare("UPDATE ip_bans SET status = 'expired' WHERE status = 'active' AND ban_end <= NOW()");
        $stmt->execute();
        return true;
    } catch (PDOException $e) {
        error_log("Update Expired IP Bans Error: " . $e->getMessage());
        return false;
    }
}

// 检查IP是否被封�?
    function isIpBanned($conn, $ip_address) {
    // 先更新过期的封禁
    updateExpiredIpBans($conn);
    
    try {
        $stmt = $conn->prepare("SELECT * FROM ip_bans WHERE ip_address = ? AND status = 'active'");
        $stmt->execute([$ip_address]);
        $ban = $stmt->fetch();
        return $ban;
    } catch (PDOException $e) {
        error_log("Check IP Ban Error: " . $e->getMessage());
        return false;
    }
}

// 计算下一次封禁时�?
    function calculateBanDuration($conn, $ip_address) {
    try {
        // 获取该IP的上一次封禁记�?        
        $stmt = $conn->prepare("SELECT ban_duration, id FROM ip_bans WHERE ip_address = ? AND status = 'expired' ORDER BY ban_end DESC LIMIT 1");
        $stmt->execute([$ip_address]);
        $last_ban = $stmt->fetch();
        
        if ($last_ban) {
            // 上一次封禁时长的2倍，最长不超过30�?            
$next_duration = min($last_ban['ban_duration'] * 2, 30 * 24 * 60 * 60);
            return [$next_duration, $last_ban['id']];
        } else {
            // 第一次封禁，使用配置的默认时�?            
            return [DEFAULT_BAN_DURATION, null];
        }
    } catch (PDOException $e) {
        error_log("Calculate Ban Duration Error: " . $e->getMessage());
        return [DEFAULT_BAN_DURATION, null]; // 默认使用配置的封禁时�?    
        }
}

// 封禁IP
function banIpAddress($conn, $ip_address) {
    try {
        // 计算封禁时长
        list($ban_duration, $last_ban_id) = calculateBanDuration($conn, $ip_address);
        
        // 计算封禁结束时间
        $ban_end = date('Y-m-d H:i:s', time() + $ban_duration);
        
        // 封禁IP
        $stmt = $conn->prepare("INSERT INTO ip_bans (ip_address, ban_duration, ban_end, last_ban_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$ip_address, $ban_duration, $ban_end, $last_ban_id]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Ban IP Address Error: " . $e->getMessage());
        return false;
    }
}

// 更新过期的浏览器封禁
function updateExpiredBrowserBans($conn) {
    try {
        $stmt = $conn->prepare("UPDATE browser_bans SET status = 'expired' WHERE status = 'active' AND ban_end <= NOW()");
        $stmt->execute();
        return true;
    } catch (PDOException $e) {
        error_log("Update Expired Browser Bans Error: " . $e->getMessage());
        return false;
    }
}

// 检查浏览器是否被封�?
    function isBrowserBanned($conn, $fingerprint) {
    // 先更新过期的封禁
    updateExpiredBrowserBans($conn);
    
    try {
        $stmt = $conn->prepare("SELECT * FROM browser_bans WHERE fingerprint = ? AND status = 'active'");
        $stmt->execute([$fingerprint]);
        $ban = $stmt->fetch();
        return $ban;
    } catch (PDOException $e) {
        error_log("Check Browser Ban Error: " . $e->getMessage());
        return false;
    }
}

// 计算浏览器下一次封禁时�?
    function calculateBrowserBanDuration($conn, $fingerprint) {
    try {
        // 获取该浏览器指纹的上一次封禁记�?        
        $stmt = $conn->prepare("SELECT ban_duration, id FROM browser_bans WHERE fingerprint = ? AND status = 'expired' ORDER BY ban_end DESC LIMIT 1");
        $stmt->execute([$fingerprint]);
        $last_ban = $stmt->fetch();
        
        if ($last_ban) {
            // 上一次封禁时长的2倍，最长不超过30�?            
            $next_duration = min($last_ban['ban_duration'] * 2, 30 * 24 * 60 * 60);
            return [$next_duration, $last_ban['id']];
        } else {
            // 第一次封禁，使用配置的默认时�?            
            return [DEFAULT_BAN_DURATION, null];
        }
    } catch (PDOException $e) {
        error_log("Calculate Browser Ban Duration Error: " . $e->getMessage());
        return [DEFAULT_BAN_DURATION, null]; // 默认使用配置的封禁时�?    
        }
}

// 封禁浏览器指�?
function banBrowserFingerprint($conn, $fingerprint) {
    try {
        // 计算封禁时长
        list($ban_duration, $last_ban_id) = calculateBrowserBanDuration($conn, $fingerprint);
        
        // 计算封禁结束时间
        $ban_end = date('Y-m-d H:i:s', time() + $ban_duration);
        
        // 封禁浏览器指�?        
$stmt = $conn->prepare("INSERT INTO browser_bans (fingerprint, ban_duration, ban_end, last_ban_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$fingerprint, $ban_duration, $ban_end, $last_ban_id]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Ban Browser Fingerprint Error: " . $e->getMessage());
        return false;
    }
}

// 记录浏览器指纹信�?
function logBrowserFingerprint($conn, $fingerprint, $ip_address, $user_agent) {
    try {
        // 检查指纹是否已存在
        $stmt = $conn->prepare("SELECT id FROM browser_fingerprints WHERE fingerprint = ?");
        $stmt->execute([$fingerprint]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // 更新现有记录
            $stmt = $conn->prepare("UPDATE browser_fingerprints SET last_seen = NOW(), ip_address = ? WHERE fingerprint = ?");
            $stmt->execute([$ip_address, $fingerprint]);
        } else {
            // 插入新记�?            
$screen_resolution = isset($_POST['screen_resolution']) ? $_POST['screen_resolution'] : '';
            $time_zone = isset($_POST['time_zone']) ? $_POST['time_zone'] : '';
            $language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 50) : '';
            
            // 计算插件数量（简化处理）
            $plugins_count = 0;
            
            $stmt = $conn->prepare("INSERT INTO browser_fingerprints (fingerprint, ip_address, user_agent, screen_resolution, time_zone, language, plugins_count) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$fingerprint, $ip_address, $user_agent, $screen_resolution, $time_zone, $language, $plugins_count]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Log Browser Fingerprint Error: " . $e->getMessage());
        return false;
    }
}

// 检查IP的失败登录尝试次�?
function checkFailedLoginAttempts($conn, $ip_address) {
    try {
        // 检�?小时内的失败登录尝试次数
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM ip_login_attempts WHERE ip_address = ? AND is_successful = 0 AND attempt_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute([$ip_address]);
        $result = $stmt->fetch();
        
        return $result['count'];
    } catch (PDOException $e) {
        error_log("Check Failed Login Attempts Error: " . $e->getMessage());
        return 0;
    }
}

require_once 'User.php';

// 创建User实例
$user = new User($conn);

// 获取客户端IP地址
$client_ip = getClientIP();

// 获取浏览器指�?
$browser_fingerprint = isset($_POST['browser_fingerprint']) ? $_POST['browser_fingerprint'] : '';

// 获取用户代理
$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

// 记录浏览器指纹信息（如果有）
if (!empty($browser_fingerprint)) {
    logBrowserFingerprint($conn, $browser_fingerprint, $client_ip, $user_agent);
}

// 检查IP是否被封�?
$ban_info = isIpBanned($conn, $client_ip);
if ($ban_info) {
    // IP被封禁，计算剩余封禁时间
    $ban_end = new DateTime($ban_info['ban_end']);
    $now = new DateTime();
    $remaining = $now->diff($ban_end);
    
    $error_message = "您的IP地址已被封禁，剩余封禁时间：";
    if ($remaining->d > 0) {
        $error_message .= $remaining->d . "天";
    }
    if ($remaining->h > 0) {
        $error_message .= $remaining->h . "小时";
    }
    if ($remaining->i > 0) {
        $error_message .= $remaining->i . "分钟";
    }
    if ($remaining->s > 0) {
        $error_message .= $remaining->s . "秒";
    }
    
    header("Location: login.php?error=" . urlencode($error_message));
    exit;
}

// 检查浏览器指纹是否被封�?
if (!empty($browser_fingerprint)) {
    $browser_ban_info = isBrowserBanned($conn, $browser_fingerprint);
    if ($browser_ban_info) {
        // 浏览器被封禁，计算剩余封禁时�?        
$ban_end = new DateTime($browser_ban_info['ban_end']);
        $now = new DateTime();
        $remaining = $now->diff($ban_end);
        
        $error_message = "您的浏览器已被封禁，剩余封禁时间：";
        if ($remaining->d > 0) {
            $error_message .= $remaining->d . "天";
        }
        if ($remaining->h > 0) {
            $error_message .= $remaining->h . "小时";
        }
        if ($remaining->i > 0) {
            $error_message .= $remaining->i . "分钟";
        }
        if ($remaining->s > 0) {
            $error_message .= $remaining->s . "秒";
        }
        
        header("Location: login.php?error=" . urlencode($error_message));
        exit;
    }
}

// 处理扫码登录
if (isset($_GET['scan_login']) && isset($_GET['token'])) {
    // 扫码登录逻辑，使用token验证
    $token = $_GET['token'];
    
    try {
        // 验证token
        $sql = "SELECT * FROM scan_login WHERE token = ? AND token_expire_at > NOW() AND status = 'success'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$token]);
        $scan_record = $stmt->fetch();
        
        if ($scan_record) {
            // 获取用户信息
            $user_id = $scan_record['user_id'];
            $user_info = $user->getUserById($user_id);
            
            if ($user_info) {
                // 检查用户是否被封禁
                $ban_info = $user->isBanned($user_info['id']);
                if ($ban_info) {
                    // 用户被封禁，重定向到登录页面并显示封禁信�?                    
                    $ban_message = "您的账号已被封禁，原因：{$ban_info['reason']}，预计解封时间：{$ban_info['expires_at']}，如有疑问请联系管理员";
                    header("Location: login.php?error=" . urlencode($ban_message));
                    exit;
                }
                
                // 从扫码记录中获取浏览器指�?                
                $scan_browser_fingerprint = $scan_record['browser_fingerprint'];
                
                // 合并浏览器指纹（优先使用请求中的，否则使用扫码记录中的）
                $browser_fingerprint = isset($_POST['browser_fingerprint']) ? $_POST['browser_fingerprint'] : $scan_browser_fingerprint;
                
                // 检查浏览器指纹是否被封�?                
            if (!empty($browser_fingerprint)) {
                    $browser_ban_info = isBrowserBanned($conn, $browser_fingerprint);
                    if ($browser_ban_info) {
                        // 浏览器被封禁，计算剩余封禁时�?                        
                        $ban_end = new DateTime($browser_ban_info['ban_end']);
                        $now = new DateTime();
                        $remaining = $now->diff($ban_end);
                        
                        $error_message = "您的浏览器已被封禁，剩余封禁时间：";
                        if ($remaining->d > 0) {
                            $error_message .= $remaining->d . "天";
                        }
                        if ($remaining->h > 0) {
                            $error_message .= $remaining->h . "小时";
                        }
                        if ($remaining->i > 0) {
                            $error_message .= $remaining->i . "分钟";
                        }
                        if ($remaining->s > 0) {
                            $error_message .= $remaining->s . "秒";
                        }
                        
                        header("Location: login.php?error=" . urlencode($error_message));
                        exit;
                    }
                    
                    // 记录浏览器指纹信�?                    
                    logBrowserFingerprint($conn, $browser_fingerprint, $client_ip, $user_agent);
                }
                
                // 登录成功，将用户信息存储在会话中
            $_SESSION['user_id'] = $user_info['id'];
            $_SESSION['username'] = $user_info['username'];
            $_SESSION['email'] = $user_info['email'];
            $_SESSION['avatar'] = $user_info['avatar'];
            $_SESSION['is_admin'] = isset($user_info['is_admin']) && $user_info['is_admin'];
            $_SESSION['last_activity'] = time();
            
            // 自动添加Admin管理员为好友并自动通过（如果还不是好友�?            
            require_once 'Friend.php';
            $friend = new Friend($conn);
            
            // 获取Admin用户的ID
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = 'Admin' OR username = 'admin' LIMIT 1");
            $stmt->execute();
            $admin_user = $stmt->fetch();
            
            if ($admin_user) {
                $admin_id = $admin_user['id'];
                $current_user_id = $user_info['id'];
                
                // 检查是否已经是好友
                if (!$friend->isFriend($current_user_id, $admin_id)) {
                    // 直接创建好友关系，跳过请求步�?                    
                    try {
                        // 创建正向关系
                        $stmt = $conn->prepare("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'accepted')");
                        $stmt->execute([$current_user_id, $admin_id]);
                        
                        // 创建反向关系
                        $stmt = $conn->prepare("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'accepted')");
                        $stmt->execute([$admin_id, $current_user_id]);
                    } catch (PDOException $e) {
                        error_log("自动添加Admin好友失败: " . $e->getMessage());
                    }
                }
            }
                
                // 登录成功后删除数据库记录，避免重复使�?                
$sql = "DELETE FROM scan_login WHERE token = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$token]);
                
                // 登录成功后清除已处理的忘记密码申�?                
try {
                    $username = $user_info['username'];
                    // 清除已通过的申�?                    
 $stmt = $conn->prepare("DELETE FROM forget_password_requests WHERE username = ? AND status = 'approved'");
                    $stmt->execute([$username]);
                    // 清除已拒绝的申请
                    $stmt = $conn->prepare("DELETE FROM forget_password_requests WHERE username = ? AND status = 'rejected'");
                    $stmt->execute([$username]);
                } catch (PDOException $e) {
                    error_log("Clear password requests error: " . $e->getMessage());
                }
                
                // 检查用户是否有反馈已被标记�?received"
                try {
                    // 检查用户是否有反馈被标记为"received"
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM feedback WHERE user_id = ? AND status = 'received'");
                    $stmt->execute([$user_id]);
                    $result = $stmt->fetch();
                    
                    if ($result['count'] > 0) {
                        // 在会话中存储提示信息
                        $_SESSION['feedback_received'] = true;
                        
                        // 将这些反馈的状态更新为"fixed"，以避免重复提示
                        $stmt = $conn->prepare("UPDATE feedback SET status = 'fixed' WHERE user_id = ? AND status = 'received'");
                        $stmt->execute([$user_id]);
                    }
                } catch (PDOException $e) {
                    error_log("Check feedback status error: " . $e->getMessage());
                }
                
                // 重定向到聊天页面
                header('Location: chat.php');
                exit;
            } else {
                // 用户不存在，重定向到登录页面
                header("Location: login.php?error=" . urlencode('用户不存在'));
                exit;
            }
        } else {
            // token无效或已过期，重定向到登录页
            header("Location: login.php?error=" . urlencode('扫码登录失败，token无效或已过期'));
            exit;
        }
    } catch(PDOException $e) {
        // 数据库错误，重定向到登录页面
        header("Location: login.php?error=" . urlencode('扫码登录失败，请重试'));
        exit;
    }
} 
// 处理普通密码登录
else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 获取表单数据
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    // 获取极验4.0验证码验证结�?    
    $lot_number = isset($_POST['geetest_challenge']) ? $_POST['geetest_challenge'] : '';
    $captcha_output = isset($_POST['geetest_validate']) ? $_POST['geetest_validate'] : '';
    $pass_token = isset($_POST['geetest_seccode']) ? $_POST['geetest_seccode'] : '';
    $gen_time = isset($_POST['gen_time']) ? $_POST['gen_time'] : '';
    $captcha_id = isset($_POST['captcha_id']) ? $_POST['captcha_id'] : '';
    
    // 验证表单数据
    $errors = [];
    
    if (empty($email)) {
        $errors[] = '请输入邮箱地址';
    }
    
    if (empty($password)) {
        $errors[] = '请输入密码';
    }
    
    // 极验4.0验证码验�?    
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
        
        // curl_close is deprecated in PHP 8.0+, no need to explicitly close the handle
        
        // 检查响应
        if ($http_code === 200) {
            $result = json_decode($response, true);
            error_log("Geetest 4.0 validation - Decoded Result: " . json_encode($result));
            
            if ($result && $result['status'] === 'success' && $result['result'] === 'success') {
                // 验证成功
            } else {
                $errors[] = '验证码验证失败，请重试';
                $reason = isset($result['reason']) ? $result['reason'] : 'unknown';
                error_log("Geetest 4.0 validation failed - Result: " . json_encode($result) . ", Reason: $reason");
            }
        } else {
            // API请求失败，视为验证失败，防止绕过
            error_log("Geetest 4.0 API request failed - HTTP Code: $http_code, Response: $response");
            $errors[] = '验证服务暂时不可用，请稍后重试';
        }
    }
    
    // 如果有错误，重定向回登录页面
    if (!empty($errors)) {
        $error_message = implode('<br>', $errors);
        header("Location: login.php?error=" . urlencode($error_message));
        exit;
    }
    
    // 尝试登录用户
    $result = $user->login($email, $password);
    
    if ($result['success']) {
        // 登录成功，记录成功的登录尝试
        logLoginAttempt($conn, $client_ip, true);
        
        // 检查用户是否被封禁
        $ban_info = $user->isBanned($result['user']['id']);
        if ($ban_info) {
            // 用户被封禁，重定向到登录页面并显示封禁信�?            
            $ban_message = "您的账号已被封禁，原因：{$ban_info['reason']}，预计解封时间：{$ban_info['expires_at']}，如有疑问请联系管理员";
            header("Location: login.php?error=" . urlencode($ban_message));
            exit;
        }
        
        // 登录成功，将用户信息存储在会话中
        session_regenerate_id(true); // 防止会话固定攻击
        $_SESSION['user_id'] = $result['user']['id'];
            $_SESSION['username'] = $result['user']['username'];
            $_SESSION['email'] = $result['user']['email'];
            $_SESSION['avatar'] = $result['user']['avatar'];
            $_SESSION['is_admin'] = isset($result['user']['is_admin']) && $result['user']['is_admin'];
            $_SESSION['last_activity'] = time();
            
            // 确保用户有加密密钥（如果该方法存在）
            if (method_exists($user, 'generateEncryptionKeys')) {
                try {
                    $user->generateEncryptionKeys($result['user']['id']);
                } catch (Exception $e) {
                    error_log("Generate Encryption Keys Error: " . $e->getMessage());
                }
            }
            
            // 自动添加Admin管理员为好友并自动通过（如果还不是好友）
            require_once 'Friend.php';
            $friend = new Friend($conn);
            
            // 获取Admin用户的ID
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = 'Admin' OR username = 'admin' LIMIT 1");
            $stmt->execute();
            $admin_user = $stmt->fetch();
            
            if ($admin_user) {
                $admin_id = $admin_user['id'];
                $current_user_id = $result['user']['id'];
                
                // 检查是否已经是好友
                if (!$friend->isFriend($current_user_id, $admin_id)) {
                    // 直接创建好友关系，跳过请求步�?                    
try {
                        // 创建正向关系
                        $stmt = $conn->prepare("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'accepted')");
                        $stmt->execute([$current_user_id, $admin_id]);
                        
                        // 创建反向关系
                        $stmt = $conn->prepare("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'accepted')");
                        $stmt->execute([$admin_id, $current_user_id]);
                    } catch (PDOException $e) {
                        error_log("自动添加Admin好友失败: " . $e->getMessage());
                    }
                }
            }
        
        // 登录成功后清除已处理的忘记密码申�?        
try {
            $username = $result['user']['username'];
            // 清除已通过的申�?            
$stmt = $conn->prepare("DELETE FROM forget_password_requests WHERE username = ? AND status = 'approved'");
            $stmt->execute([$username]);
            // 清除已拒绝的申请
            $stmt = $conn->prepare("DELETE FROM forget_password_requests WHERE username = ? AND status = 'rejected'");
            $stmt->execute([$username]);
        } catch (PDOException $e) {
            error_log("Clear password requests error: " . $e->getMessage());
        }
        
        // 检查用户是否有反馈已被标记�?received"
        try {
            $user_id = $result['user']['id'];
            // 检查用户是否有反馈被标记为"received"
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM feedback WHERE user_id = ? AND status = 'received'");
            $stmt->execute([$user_id]);
            $feedback_result = $stmt->fetch();
            
            if ($feedback_result['count'] > 0) {
                // 在会话中存储提示信息
                $_SESSION['feedback_received'] = true;
                
                // 将这些反馈的状态更新为"fixed"，以避免重复提示
                $stmt = $conn->prepare("UPDATE feedback SET status = 'fixed' WHERE user_id = ? AND status = 'received'");
                $stmt->execute([$user_id]);
            }
        } catch (PDOException $e) {
            error_log("Check feedback status error: " . $e->getMessage()); 
        }
        
        // 重定向到聊天页面
        header('Location: chat.php');
        exit;
    } else {
        // 登录失败，记录失败的登录尝试
        logLoginAttempt($conn, $client_ip, false);
        
        // 增加延迟，防止暴力破解
        sleep(2);
        
        // 检查失败尝试次数        
$failed_attempts = checkFailedLoginAttempts($conn, $client_ip);
        if ($failed_attempts >= MAX_LOGIN_ATTEMPTS) {
            // 封禁IP
            banIpAddress($conn, $client_ip);
            
            // 封禁浏览器指纹（如果有）
            if (!empty($browser_fingerprint)) {
                banBrowserFingerprint($conn, $browser_fingerprint);
            }
            
            // 计算封禁时长
            list($ban_duration, $last_ban_id) = calculateBanDuration($conn, $client_ip);
            
            // 转换封禁时长为可读格�?            
$hours = ceil($ban_duration / 3600);
            $ban_message = "登录失败次数过多，您的IP地址和浏览器已被封禁 {$hours} 小时";
            
            // 重定向到登录页面并显示封禁信�?            
header("Location: login.php?error=" . urlencode($ban_message));
            exit;
        }
        
        // 登录失败，重定向回登录页面，并传递邮箱参数以便显示忘记密码申请状�?        
$email = urlencode($email);
        header("Location: login.php?error=" . urlencode($result['message']) . "&email=" . $email);
        exit;
    }
} else {
    // 非法请求，重定向到登录页�?    
header('Location: login.php');
    exit;
}