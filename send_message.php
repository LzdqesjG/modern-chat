<?php
// 启用错误报告以便调试
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 设置错误日志
ini_set('error_log', 'error.log');

// 配置大文件上传支持
ini_set('upload_max_filesize', '200M'); // 允许最大上传文件大小
ini_set('post_max_size', '200M'); // 允许最大POST数据大小
ini_set('max_execution_time', 300); // 脚本最大执行时间（秒）
ini_set('max_input_time', 300); // 输入数据最大处理时间（秒）
ini_set('memory_limit', '256M'); // 脚本最大内存使用

// 开始会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    require_once 'config.php';
    require_once 'db.php';
    require_once 'User.php';
    require_once 'Message.php';
require_once 'FileUpload.php';
require_once 'Group.php';

    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '用户未登录']);
        exit;
    }

    // 检查是否是POST请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '无效的请求方法']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $chat_type = isset($_POST['chat_type']) ? $_POST['chat_type'] : 'friend'; // 'friend' 或 'group'
    $selected_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $friend_id = isset($_POST['friend_id']) ? intval($_POST['friend_id']) : 0;
    $message_text = isset($_POST['message']) ? trim($_POST['message']) : '';

    // 验证数据
    if (($chat_type === 'friend' && !$friend_id) || ($chat_type === 'group' && !$selected_id)) {
        echo json_encode(['success' => false, 'message' => '请选择聊天对象']);
        exit;
    }

    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }

    // 创建实例
    $message = new Message($conn);
    $fileUpload = new FileUpload($conn);
    $group = new Group($conn);

    // 添加调试信息
    error_log("Send Message Request: user_id=$user_id, chat_type=$chat_type, selected_id=$selected_id");
    error_log("Message Text: '$message_text'");

    // 处理文件上传
    $file_result = null;

    // 检查是否有文件上传
    if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        error_log("File Info: " . print_r($_FILES['file'], true));
        
        // 调用文件上传方法
        $file_result = $fileUpload->upload($_FILES['file'], $user_id);
        error_log("File Upload Result: " . print_r($file_result, true));
    }

    // 严格检查消息是否包含HTML内容、脚本或其他危险字符
    function containsHtmlContent($text) {
        // 检测各种HTML标签模式
        $htmlTagRegex = '/<\s*[a-zA-Z][a-zA-Z0-9-_:.]*|\/>|<!--|-->|<!DOCTYPE/i';
        // 检测HTML实体
        $htmlEntityRegex = '/&[a-zA-Z0-9#]+;/i';
        // 检测脚本相关内容
        $scriptRegex = '/<script|javascript:|vbscript:/i';
        // 检测常见的XSS攻击向量
        $xssRegex = '/on[a-zA-Z]+\s*=|expression\(|eval\(|alert\(|confirm\(|prompt\(/i';
        // 检测表单相关标签
        $formRegex = '/<form|<input|<button|<select|<textarea/i';
        // 检测媒体相关标签
        $mediaRegex = '/<img|<audio|<video|<source/i';
        // 检测链接相关标签
        $linkRegex = '/<a\s+href|rel=|target=/i';
        
        return preg_match($htmlTagRegex, $text) || 
               preg_match($htmlEntityRegex, $text) || 
               preg_match($scriptRegex, $text) || 
               preg_match($xssRegex, $text) || 
               preg_match($formRegex, $text) || 
               preg_match($mediaRegex, $text) || 
               preg_match($linkRegex, $text);
    }
    
    // 净化消息内容，移除所有HTML和危险字符
    function sanitizeMessage($text) {
        // 移除所有HTML标签
        $text = strip_tags($text);
        // 移除HTML实体
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        // 再次移除可能生成的HTML标签
        $text = strip_tags($text);
        // 移除危险字符序列
        $text = preg_replace('/&[a-zA-Z0-9#]+;/i', '', $text);
        // 移除脚本内容
        $text = preg_replace('/<script.*?<\/script>/is', '', $text);
        // 移除事件处理程序
        $text = preg_replace('/on[a-zA-Z]+\s*=/i', '', $text);
        // 清理前后空格
        return trim($text);
    }
    
    // 加载违禁词配置
    $prohibited_words_config = [];
    $prohibited_words_file = 'config/Prohibited_word.json';
    $prohibited_words_txt_file = 'config/Prohibited_word.txt';
    
    // 确保JSON配置文件存在并包含必要的配置项
    if (file_exists($prohibited_words_file)) {
        $prohibited_words_config = json_decode(file_get_contents($prohibited_words_file), true);
    } else {
        // 创建默认配置
        $prohibited_words_config = [
            'max_warnings_per_day' => 10,
            'ban_time' => 24,
            'max_ban_time' => 30,
            'permanent_ban_days' => 365
        ];
        file_put_contents($prohibited_words_file, json_encode($prohibited_words_config, JSON_PRETTY_PRINT));
    }
    
    // 违禁词检测函数 - 优化：去除空格和特殊字符后再判断
    function checkProhibitedWords($text, $prohibited_words) {
        if (empty($prohibited_words)) {
            return false;
        }
        
        // 预处理文本：去除空格和特殊字符
        $processed_text = preg_replace('/[\s\p{P}\p{S}]/u', '', $text);
        $processed_text = strtolower($processed_text);
        
        foreach ($prohibited_words as $word) {
            // 同时处理违禁词：去除空格和特殊字符，转为小写
            $processed_word = preg_replace('/[\s\p{P}\p{S}]/u', '', $word);
            $processed_word = strtolower($processed_word);
            
            if (stripos($processed_text, $processed_word) !== false) {
                return $word;
            }
        }
        return false;
    }
    
    // 检查用户是否被封禁
    function checkUserBanStatus($user_id, $conn) {
        try {
            // 先检查 prohibited_word_bans 表是否存在
            $stmt = $conn->prepare("SHOW TABLES LIKE 'prohibited_word_bans'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                // 创建 prohibited_word_bans 表
                $conn->exec("CREATE TABLE IF NOT EXISTS prohibited_word_bans (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    ban_reason TEXT NOT NULL,
                    ban_type ENUM('temporary', 'permanent') DEFAULT 'temporary',
                    ban_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    ban_end TIMESTAMP NULL,
                    warnings_count INT NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )");
            }
            
            // 检查用户是否有活跃的封禁记录
            $stmt = $conn->prepare("SELECT * FROM prohibited_word_bans WHERE user_id = ? AND (ban_end IS NULL OR ban_end > NOW())");
            $stmt->execute([$user_id]);
            $ban = $stmt->fetch();
            
            if ($ban) {
                $remaining_time = $ban['ban_end'] ? ceil((strtotime($ban['ban_end']) - time()) / 3600) : null;
                if ($remaining_time) {
                    return [
                        'banned' => true,
                        'message' => "由于您的不当言论被系统检测到了，为了良好的网络环境，您已被限制发言:{$remaining_time}小时",
                        'ban_end' => $ban['ban_end']
                    ];
                } else {
                    return [
                        'banned' => true,
                        'message' => "由于您的不当言论被系统检测到了，为了良好的网络环境，您已被永久限制发言",
                        'ban_end' => null
                    ];
                }
            }
            return ['banned' => false];
        } catch (PDOException $e) {
            error_log("Check user ban status error: " . $e->getMessage());
            return ['banned' => false];
        }
    }
    
    // 记录警告次数
    function recordWarning($user_id, $triggered_word, $conn, $message_text) {
        try {
            // 检查 prohibited_word_warnings 表是否存在
            $stmt = $conn->prepare("SHOW TABLES LIKE 'prohibited_word_warnings'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                // 创建 prohibited_word_warnings 表
                $conn->exec("CREATE TABLE IF NOT EXISTS prohibited_word_warnings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    prohibited_word VARCHAR(100) NOT NULL,
                    message TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )");
            }
            
            // 插入警告记录
            $stmt = $conn->prepare("INSERT INTO prohibited_word_warnings (user_id, prohibited_word, message) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $triggered_word, $message_text]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Record warning error: " . $e->getMessage());
            return false;
        }
    }
    
    // 获取用户当天的警告次数
    function getTodayWarnings($user_id, $conn) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM prohibited_word_warnings WHERE user_id = ? AND DATE(created_at) = CURDATE()");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch();
            return $result['count'];
        } catch (PDOException $e) {
            error_log("Get today warnings error: " . $e->getMessage());
            return 0;
        }
    }
    
    // 获取用户的总警告次数
    function getTotalWarnings($user_id, $conn) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM prohibited_word_warnings WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch();
            return $result['count'];
        } catch (PDOException $e) {
            error_log("Get total warnings error: " . $e->getMessage());
            return 0;
        }
    }
    
    // 检查用户的封禁历史
    function getBanHistory($user_id, $conn) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM prohibited_word_bans WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch();
            return $result['count'];
        } catch (PDOException $e) {
            error_log("Get ban history error: " . $e->getMessage());
            return 0;
        }
    }
    
    // 封禁用户
    function banUser($user_id, $ban_duration_hours, $conn, $is_permanent = false, $warnings_count = 0) {
        try {
            // 检查 prohibited_word_bans 表是否存在
            $stmt = $conn->prepare("SHOW TABLES LIKE 'prohibited_word_bans'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                // 创建 prohibited_word_bans 表
                $conn->exec("CREATE TABLE IF NOT EXISTS prohibited_word_bans (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    ban_reason TEXT NOT NULL,
                    ban_type ENUM('temporary', 'permanent') DEFAULT 'temporary',
                    ban_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    ban_end TIMESTAMP NULL,
                    warnings_count INT NOT NULL,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )");
            }

            // 检查并添加users表中可能不存在的字段
            $requiredColumns = [
                'warning_count_today' => "ADD COLUMN warning_count_today INT DEFAULT 0",
                'last_warning_date' => "ADD COLUMN last_warning_date DATE DEFAULT NULL",
                'is_banned_for_prohibited_words' => "ADD COLUMN is_banned_for_prohibited_words BOOLEAN DEFAULT FALSE",
                'ban_end_for_prohibited_words' => "ADD COLUMN ban_end_for_prohibited_words TIMESTAMP NULL"
            ];

            foreach ($requiredColumns as $column => $alterSql) {
                $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE ?");
                $stmt->execute([$column]);
                if (!$stmt->fetch()) {
                    $conn->exec("ALTER TABLE users $alterSql");
                }
            }

            // 计算封禁结束时间
            $ban_end = $is_permanent ? null : date('Y-m-d H:i:s', time() + ($ban_duration_hours * 3600));
            $ban_type = $is_permanent ? 'permanent' : 'temporary';
            $ban_reason = '违反违禁词规则，累计警告次数：' . $warnings_count;

            // 插入封禁记录
            $stmt = $conn->prepare("INSERT INTO prohibited_word_bans (user_id, ban_reason, ban_type, ban_end, warnings_count) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $ban_reason, $ban_type, $ban_end, $warnings_count]);

            // 更新用户封禁状态
            $stmt = $conn->prepare("UPDATE users SET
                is_banned_for_prohibited_words = TRUE,
                ban_end_for_prohibited_words = ?,
                warning_count_today = 0,
                last_warning_date = NULL
                WHERE id = ?");
            $stmt->execute([$ban_end, $user_id]);

            return true;
        } catch (PDOException $e) {
            error_log("Ban user error: " . $e->getMessage());
            return false;
        }
    }
    
    // 检查用户是否被封禁
    $ban_status = checkUserBanStatus($user_id, $conn);
    if ($ban_status['banned']) {
        echo json_encode(['success' => false, 'message' => $ban_status['message']]);
        exit;
    }
    
    // 发送消息
    if ($chat_type === 'friend') {
        // 好友消息
        if ($file_result && $file_result['success']) {
            // 发送文件消息，添加file_type参数，使用mime_type作为file_type
            $result = $message->sendFileMessage(
                $user_id,
                $friend_id,
                $file_result['file_path'],
                $file_result['file_name'],
                $file_result['file_size'],
                $file_result['mime_type']
            );
            error_log("Send File Message Result: " . print_r($result, true));
        } else if ($message_text) {
            // 检查消息是否包含HTML内容
            if (containsHtmlContent($message_text)) {
                echo json_encode(['success' => false, 'message' => '禁止发送HTML代码、脚本或特殊字符 ❌']);
                exit;
            }
            
            // 额外安全措施：净化消息内容，确保绝对安全
            $message_text = sanitizeMessage($message_text);
            
            // 如果净化后消息为空，不发送
            if (empty($message_text)) {
                echo json_encode(['success' => false, 'message' => '消息内容不能为空 ❌']);
                exit;
            }
            
            // 加载违禁词列表（从txt文件）
            $prohibited_words = [];
            if (file_exists($prohibited_words_txt_file)) {
                $prohibited_words = file($prohibited_words_txt_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                // 去重
                $prohibited_words = array_unique($prohibited_words);
            }
            $triggered_word = checkProhibitedWords($message_text, $prohibited_words);
            if ($triggered_word) {
                // 记录警告次数
                recordWarning($user_id, $triggered_word, $conn, $message_text);
                
                // 获取当天警告次数
                $today_warnings = getTodayWarnings($user_id, $conn);
                
                // 获取配置参数
                $max_warnings_per_day = $prohibited_words_config['max_warnings_per_day'] ?? 10;
                $ban_time = $prohibited_words_config['ban_time'] ?? 24;
                $max_ban_time = $prohibited_words_config['max_ban_time'] ?? 30;
                $permanent_ban_days = $prohibited_words_config['permanent_ban_days'] ?? 365;
                
                // 检查是否需要封禁
                if ($today_warnings >= $max_warnings_per_day) {
                    // 计算封禁时长
                    $ban_count = getBanHistory($user_id, $conn);
                    $ban_duration_hours = $ban_time * pow(2, $ban_count);
                    
                    // 检查是否超过最大封禁时长或达到永久封禁条件
                    $is_permanent = false;
                    if ($ban_count >= $permanent_ban_days) {
                        // 永久封禁
                        banUser($user_id, 0, $conn, true, $today_warnings);
                        echo json_encode(['success' => false, 'message' => "您已被永久封禁，原因：多次违反违禁词规则"]);
                    } else if ($ban_duration_hours > $max_ban_time * 24) {
                        // 最大封禁时长
                        $ban_duration_hours = $max_ban_time * 24;
                        banUser($user_id, $ban_duration_hours, $conn, false, $today_warnings);
                        echo json_encode(['success' => false, 'message' => "您已被封禁 {$ban_duration_hours} 小时，原因：多次违反违禁词规则"]);
                    } else {
                        // 正常封禁
                        banUser($user_id, $ban_duration_hours, $conn, false, $today_warnings);
                        echo json_encode(['success' => false, 'message' => "您已被封禁 {$ban_duration_hours} 小时，原因：多次违反违禁词规则"]);
                    }
                    exit;
                }
                
                echo json_encode(['success' => false, 'message' => "你的消息违反了管理员设置的违禁词语：{$triggered_word}，警告一次"]);
                exit;
            }
            
            // 发送文本消息
            $result = $message->sendTextMessage($user_id, $friend_id, $message_text);
            error_log("Send Text Message Result: " . print_r($result, true));
        } else {
            echo json_encode(['success' => false, 'message' => '请输入消息内容或选择文件']);
            exit;
        }
    } else {
        // 群聊消息
        
        // 检查群聊是否被封禁
        $stmt = $conn->prepare("SELECT reason, ban_end FROM group_bans WHERE group_id = ? AND status = 'active'");
        $stmt->execute([$selected_id]);
        $ban_info = $stmt->fetch();
        
        if ($ban_info) {
            // 检查封禁是否已过期
            if ($ban_info['ban_end'] && strtotime($ban_info['ban_end']) < time()) {
                // 更新封禁状态为过期
                $stmt = $conn->prepare("UPDATE group_bans SET status = 'expired' WHERE group_id = ? AND status = 'active'");
                $stmt->execute([$selected_id]);
                
                // 插入过期日志
                $stmt = $conn->prepare("INSERT INTO group_ban_logs (ban_id, action, action_by) VALUES ((SELECT id FROM group_bans WHERE group_id = ? ORDER BY id DESC LIMIT 1), 'expire', NULL)");
                $stmt->execute([$selected_id]);
            } else {
                // 群聊被封禁，返回错误信息
                echo json_encode(['success' => false, 'message' => '群聊被封禁，您暂时无法查看群聊成员和使用群聊功能']);
                exit;
            }
        }
        
        if ($file_result && $file_result['success']) {
            // 发送文件消息，添加file_type字段，使用mime_type作为file_type
            $file_info = [
                'file_path' => $file_result['file_path'],
                'file_name' => $file_result['file_name'],
                'file_size' => $file_result['file_size'],
                'file_type' => $file_result['mime_type'] // 添加file_type字段
            ];
            $result = $group->sendGroupMessage($selected_id, $user_id, '', $file_info);
            error_log("Send Group File Message Result: " . print_r($result, true));
        } else if ($message_text) {
            // 检查消息是否包含HTML内容
            if (containsHtmlContent($message_text)) {
                echo json_encode(['success' => false, 'message' => '禁止发送HTML代码、脚本或特殊字符 ❌']);
                exit;
            }
            
            // 额外安全措施：净化消息内容，确保绝对安全
            $message_text = sanitizeMessage($message_text);
            
            // 如果净化后消息为空，不发送
            if (empty($message_text)) {
                echo json_encode(['success' => false, 'message' => '消息内容不能为空 ❌']);
                exit;
            }
            
            // 加载违禁词列表（从txt文件）
            $prohibited_words = [];
            if (file_exists($prohibited_words_txt_file)) {
                $prohibited_words = file($prohibited_words_txt_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                // 去重
                $prohibited_words = array_unique($prohibited_words);
            }
            $triggered_word = checkProhibitedWords($message_text, $prohibited_words);
            if ($triggered_word) {
                // 记录警告次数
                recordWarning($user_id, $triggered_word, $conn, $message_text);
                
                // 获取当天警告次数
                $today_warnings = getTodayWarnings($user_id, $conn);
                
                // 获取配置参数
                $max_warnings_per_day = $prohibited_words_config['max_warnings_per_day'] ?? 10;
                $ban_time = $prohibited_words_config['ban_time'] ?? 24;
                $max_ban_time = $prohibited_words_config['max_ban_time'] ?? 30;
                $permanent_ban_days = $prohibited_words_config['permanent_ban_days'] ?? 365;
                
                // 检查是否需要封禁
                if ($today_warnings >= $max_warnings_per_day) {
                    // 计算封禁时长
                    $ban_count = getBanHistory($user_id, $conn);
                    $ban_duration_hours = $ban_time * pow(2, $ban_count);
                    
                    // 检查是否超过最大封禁时长或达到永久封禁条件
                    $is_permanent = false;
                    if ($ban_count >= $permanent_ban_days) {
                        // 永久封禁
                        banUser($user_id, 0, $conn, true, $today_warnings);
                        echo json_encode(['success' => false, 'message' => "您已被永久封禁，原因：多次违反违禁词规则"]);
                    } else if ($ban_duration_hours > $max_ban_time * 24) {
                        // 最大封禁时长
                        $ban_duration_hours = $max_ban_time * 24;
                        banUser($user_id, $ban_duration_hours, $conn, false, $today_warnings);
                        echo json_encode(['success' => false, 'message' => "您已被封禁 {$ban_duration_hours} 小时，原因：多次违反违禁词规则"]);
                    } else {
                        // 正常封禁
                        banUser($user_id, $ban_duration_hours, $conn, false, $today_warnings);
                        echo json_encode(['success' => false, 'message' => "您已被封禁 {$ban_duration_hours} 小时，原因：多次违反违禁词规则"]);
                    }
                    exit;
                }
                
                echo json_encode(['success' => false, 'message' => "你的消息违反了管理员设置的违禁词语：{$triggered_word}，警告一次"]);
                exit;
            }
            
            // 发送文本消息
            $result = $group->sendGroupMessage($selected_id, $user_id, $message_text);
            error_log("Send Group Text Message Result: " . print_r($result, true));
        } else {
            echo json_encode(['success' => false, 'message' => '请输入消息内容或选择文件']);
            exit;
        }
    }

    // 处理@提及功能
    function processMentions($message_id, $message_text, $chat_type, $chat_id, $user_id, $conn) {
        // 只有群聊消息需要处理@提及
        if ($chat_type !== 'group') {
            return;
        }
        
        // 解析消息中的@提及
        $mentioned_users = [];
        
        // 匹配@用户名格式，包括@全体成员和@具体用户名
        preg_match_all('/@([\u4e00-\u9fa5\w]+)/u', $message_text, $matches);
        
        if (!empty($matches[1])) {
            $mentioned_names = $matches[1];
            
            // 检查是否提及了全体成员
            if (in_array('全体成员', $mentioned_names) || in_array('所有人', $mentioned_names)) {
                // 获取群聊所有成员
                $group = new Group($conn);
                $group_members = $group->getGroupMembers($chat_id);
                
                foreach ($group_members as $member) {
                    // 不包括发送者自己
                    if ($member['user_id'] != $user_id) {
                        $mentioned_users[] = $member['user_id'];
                    }
                }
            } else {
                // 获取具体提及的用户
                foreach ($mentioned_names as $name) {
                    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                    $stmt->execute([$name]);
                    $user = $stmt->fetch();
                    if ($user && $user['id'] != $user_id) {
                        $mentioned_users[] = $user['id'];
                    }
                }
            }
        }
        
        // 去重
        $mentioned_users = array_unique($mentioned_users);
        
        // 为被提及的用户添加@提醒标记
        foreach ($mentioned_users as $mentioned_user_id) {
            // 检查chat_settings表是否存在
            $stmt = $conn->prepare("SHOW TABLES LIKE 'chat_settings'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                // 创建chat_settings表
                $conn->exec("CREATE TABLE IF NOT EXISTS chat_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    chat_type ENUM('friend', 'group') NOT NULL,
                    chat_id INT NOT NULL,
                    is_muted BOOLEAN DEFAULT FALSE,
                    has_mention BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_chat (user_id, chat_type, chat_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )");
            }
            
            // 更新或插入chat_settings记录，标记有@提及
            $stmt = $conn->prepare("INSERT INTO chat_settings (user_id, chat_type, chat_id, has_mention) 
                                   VALUES (?, ?, ?, TRUE) 
                                   ON DUPLICATE KEY UPDATE has_mention = TRUE, updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$mentioned_user_id, 'group', $chat_id]);
        }
    }
    
    if ($result['success']) {
        // 处理@提及
        processMentions($result['message_id'], $message_text, $chat_type, $chat_type === 'friend' ? $friend_id : $selected_id, $user_id, $conn);
        
            // 获取完整的消息信息
        if ($chat_type === 'friend') {
            // 获取好友消息
            $stmt = $conn->prepare("SELECT *, m.id as message_id FROM messages m WHERE m.id = ?");
            $stmt->execute([$result['message_id']]);
        } else {
            // 获取群聊消息
            $stmt = $conn->prepare("SELECT gm.*, u.username as sender_username, u.avatar, gm.id as message_id FROM group_messages gm JOIN users u ON gm.sender_id = u.id WHERE gm.id = ?");
            $stmt->execute([$result['message_id']]);
        }
        $sent_message = $stmt->fetch();
        
        error_log("Sent Message: " . print_r($sent_message, true));
        
        echo json_encode([
            'success' => true,
            'message_id' => $result['message_id'],
            'message' => $sent_message
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message']]);
    }
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}