<?php
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
        // 添加agreed_to_terms字段，记录用户是否同意协议
        $conn->exec("ALTER TABLE users ADD COLUMN agreed_to_terms BOOLEAN DEFAULT FALSE AFTER is_deleted");
        error_log("Added agreed_to_terms column to users table");
        
        // 将管理员用户设置为已同意协议
        $conn->exec("UPDATE users SET agreed_to_terms = TRUE WHERE is_admin = TRUE");
        error_log("Set admin users as agreed to terms");
    }
} catch (PDOException $e) {
    error_log("Field setup error: " . $e->getMessage());
}

require_once 'User.php';

// 创建User实例
$user = new User($conn);

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
                    // 用户被封禁，重定向到登录页面并显示封禁信息
                    $ban_message = "您的账号已被封禁，原因：{$ban_info['reason']}，预计解封时间：{$ban_info['expires_at']}，如有疑问请联系管理员";
                    header("Location: login.php?error=" . urlencode($ban_message));
                    exit;
                }
                
                // 登录成功，将用户信息存储在会话中
            $_SESSION['user_id'] = $user_info['id'];
            $_SESSION['username'] = $user_info['username'];
            $_SESSION['email'] = $user_info['email'];
            $_SESSION['avatar'] = $user_info['avatar'];
            $_SESSION['is_admin'] = isset($user_info['is_admin']) && $user_info['is_admin'];
            $_SESSION['last_activity'] = time();
            
            // 自动添加Admin管理员为好友并自动通过（如果还不是好友）
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
                    // 直接创建好友关系，跳过请求步骤
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
                
                // 登录成功后删除数据库记录，避免重复使用
                $sql = "DELETE FROM scan_login WHERE token = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$token]);
                
                // 登录成功后清除已处理的忘记密码申请
                try {
                    $username = $user_info['username'];
                    // 清除已通过的申请
                    $stmt = $conn->prepare("DELETE FROM forget_password_requests WHERE username = ? AND status = 'approved'");
                    $stmt->execute([$username]);
                    // 清除已拒绝的申请
                    $stmt = $conn->prepare("DELETE FROM forget_password_requests WHERE username = ? AND status = 'rejected'");
                    $stmt->execute([$username]);
                } catch (PDOException $e) {
                    error_log("Clear password requests error: " . $e->getMessage());
                }
                
                // 检查用户是否有反馈已被标记为"received"
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
            // token无效或已过期，重定向到登录页面
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
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 获取表单数据
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    // 验证表单数据
    $errors = [];
    
    if (empty($email)) {
        $errors[] = '请输入邮箱地址';
    }
    
    if (empty($password)) {
        $errors[] = '请输入密码';
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
        // 检查用户是否被封禁
        $ban_info = $user->isBanned($result['user']['id']);
        if ($ban_info) {
            // 用户被封禁，重定向到登录页面并显示封禁信息
            $ban_message = "您的账号已被封禁，原因：{$ban_info['reason']}，预计解封时间：{$ban_info['expires_at']}，如有疑问请联系管理员";
            header("Location: login.php?error=" . urlencode($ban_message));
            exit;
        }
        
        // 登录成功，将用户信息存储在会话中
            $_SESSION['user_id'] = $result['user']['id'];
            $_SESSION['username'] = $result['user']['username'];
            $_SESSION['email'] = $result['user']['email'];
            $_SESSION['avatar'] = $result['user']['avatar'];
            $_SESSION['is_admin'] = isset($result['user']['is_admin']) && $result['user']['is_admin'];
            $_SESSION['last_activity'] = time();
            
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
                    // 直接创建好友关系，跳过请求步骤
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
        
        // 登录成功后清除已处理的忘记密码申请
        try {
            $username = $result['user']['username'];
            // 清除已通过的申请
            $stmt = $conn->prepare("DELETE FROM forget_password_requests WHERE username = ? AND status = 'approved'");
            $stmt->execute([$username]);
            // 清除已拒绝的申请
            $stmt = $conn->prepare("DELETE FROM forget_password_requests WHERE username = ? AND status = 'rejected'");
            $stmt->execute([$username]);
        } catch (PDOException $e) {
            error_log("Clear password requests error: " . $e->getMessage());
        }
        
        // 检查用户是否有反馈已被标记为"received"
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
        // 登录失败，重定向回登录页面，并传递邮箱参数以便显示忘记密码申请状态
        $email = urlencode($email);
        header("Location: login.php?error=" . urlencode($result['message']) . "&email=" . $email);
        exit;
    }
} else {
    // 非法请求，重定向到登录页面
    header('Location: login.php');
    exit;
}