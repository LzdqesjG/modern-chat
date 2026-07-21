<?php
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}
// 设置错误日志
ini_set('error_log', 'error.log');

// 生产环境禁用错误显示
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

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

    // ═══════════════════════════════════════════════════
    // 辅助函数：生成 list.php 文件访问签名 token
    // 用于远程上传后构建文件访问 URL（无需调用 api.php）
    // ═══════════════════════════════════════════════════
    if (!function_exists('generate_list_token')) {
        function generate_list_token(string $upload_id, string $stored_name, string $vkey): string {
            $ts   = time();
            // 使用 FILE_TOKEN_SECRET 签名（与 list.php 保持一致）
            $hmac = hash_hmac('sha256', "{$upload_id}|{$stored_name}|{$vkey}|{$ts}", FILE_TOKEN_SECRET);
            return base64_encode("{$ts}:{$hmac}");
        }
    }

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
    require_once 'Friend.php';
    $friend = new Friend($conn);

    // 修复：未加好友不能发送消息（针对好友聊天）
    if ($chat_type === 'friend') {
        // 检查是否为好友关系
        if (!$friend->isFriend($user_id, $friend_id)) {
            echo json_encode(['success' => false, 'message' => '你们还不是好友，无法发送消息 ❌']);
            exit;
        }
    }

    // 添加调试信息
    error_log("Send Message Request: user_id=$user_id, chat_type=$chat_type, selected_id=$selected_id");
    error_log("Message Text: '$message_text'");

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
    
    // 确保必要的表存在
    function ensureProhibitedWordTables($conn) {
        try {
            // 创建warnings表
            $conn->exec("CREATE TABLE IF NOT EXISTS warnings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                prohibited_word VARCHAR(100) NOT NULL,
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )");
            
            // 创建bans表
            $conn->exec("CREATE TABLE IF NOT EXISTS bans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                ban_reason TEXT NOT NULL,
                ban_type ENUM('temporary', 'permanent') DEFAULT 'temporary',
                ban_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ban_end TIMESTAMP NULL,
                warnings_count INT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )");
            
            // 检查并添加users表中可能不存在的字段
            $requiredColumns = [
                'warning_count' => "ADD COLUMN warning_count INT DEFAULT 0",
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
        } catch (PDOException $e) {
            error_log("Ensure prohibited word tables error: " . $e->getMessage());
        }
    }
    
    // 检查用户是否被封禁
    function checkUserBanStatus($user_id, $conn) {
        try {
            // 检查用户是否有活跃的封禁记录
            $stmt = $conn->prepare("SELECT * FROM bans WHERE user_id = ? AND (ban_end IS NULL OR ban_end > NOW())");
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
            // 插入警告记录
            $stmt = $conn->prepare("INSERT INTO warnings (user_id, prohibited_word, message) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $triggered_word, $message_text]);
            
            // 更新用户警告次数
            $stmt = $conn->prepare("UPDATE users SET warning_count = warning_count + 1 WHERE id = ?");
            $stmt->execute([$user_id]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Record warning error: " . $e->getMessage());
            return false;
        }
    }
    
    // 获取用户的总警告次数
    function getTotalWarnings($user_id, $conn) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM warnings WHERE user_id = ?");
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
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM bans WHERE user_id = ?");
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
            // 计算封禁结束时间
            $ban_end = $is_permanent ? null : date('Y-m-d H:i:s', time() + ($ban_duration_hours * 3600));
            $ban_type = $is_permanent ? 'permanent' : 'temporary';
            $ban_reason = '违反违禁词规则，累计警告次数：' . $warnings_count;

            // 插入封禁记录
            $stmt = $conn->prepare("INSERT INTO bans (user_id, ban_reason, ban_type, ban_end, warnings_count) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $ban_reason, $ban_type, $ban_end, $warnings_count]);

            // 更新用户封禁状态
            $stmt = $conn->prepare("UPDATE users SET
                is_banned_for_prohibited_words = TRUE,
                ban_end_for_prohibited_words = ?
                WHERE id = ?");
            $stmt->execute([$ban_end, $user_id]);

            return true;
        } catch (PDOException $e) {
            error_log("Ban user error: " . $e->getMessage());
            return false;
        }
    }
    
    // 调用API检测违禁词
    function checkProhibitedWordsAPI($text) {
        try {
            $url = 'https://uapis.cn/api/v1/text/profanitycheck';
            $data = [
                'text' => $text
            ];
            
            $options = [
                'http' => [
                    'header' => "Content-type: application/json\r\n",
                    'method' => 'POST',
                    'content' => json_encode($data),
                    'timeout' => 5 // 5秒超时，避免页面卡死
                ]
            ];
            
            $context = stream_context_create($options);
            $result = file_get_contents($url, false, $context);
            
            if ($result) {
                $response = json_decode($result, true);
                return $response;
            }
            return ['status' => 'error', 'message' => 'API请求失败'];
        } catch (Exception $e) {
            error_log("API check error: " . $e->getMessage());
            return ['status' => 'error', 'message' => 'API请求异常'];
        }
    }
    
    // 调用API检测NSFW图片
    function checkImageNSFW($image_url) {
        try {
            $data = http_build_query([
                'url' => $image_url
            ]);
            
            $options = [
                'http' => [
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'content' => $data,
                    'timeout' => 30 // 30秒超时，避免页面卡死
                ]
            ];
            
            $context = stream_context_create($options);
            $result = file_get_contents('https://uapis.cn/api/v1/image/nsfw', false, $context);
            
            if ($result) {
                $response = json_decode($result, true);
                return $response;
            }
            return null;
        } catch (Exception $e) {
            error_log("NSFW API check error: " . $e->getMessage());
            return null;
        }
    }
    
    // 记录图片审核
    function recordImageForReview($conn, $user_id, $file_path, $original_name, $file_size, $mime_type) {
        try {
            // 确保图片审核表存在
            $conn->exec("CREATE TABLE IF NOT EXISTS image_reviews (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                file_size INT NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )");
            
            // 插入审核记录
            $stmt = $conn->prepare("INSERT INTO image_reviews (user_id, file_path, original_name, file_size, mime_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $file_path, $original_name, $file_size, $mime_type]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Record image for review error: " . $e->getMessage());
            return false;
        }
    }

    // 处理文件上传
    $file_result = null;

    // 检查是否有文件上传（旧流程：直接传文件）
    // 或新流程：文件已通过 uploads.php 上传（含分片上传），传 upload_id + stored_name
    $file_url_new = $_POST['file_url'] ?? '';
    $upload_id_new = $_POST['upload_id'] ?? '';
    $stored_name_new = $_POST['stored_name'] ?? '';
    $file_name_new = $_POST['file_name'] ?? '';
    $file_size_new = $_POST['file_size'] ?? 0;
    $file_type_new = $_POST['file_type'] ?? '';
    
    if (!empty($upload_id_new) && !empty($file_name_new)) {
        // 新流程：文件已通过 uploads.php 上传（Single 或 split 模式）
        // 构建带签名的 list.php 访问 URL（安全访问）
        $vkey_for_token = get_vkey_by_user_id($user_id, $conn);
        
        if (!empty($stored_name_new) && $vkey_for_token) {
            // 有 stored_name → 生成签名 URL（分片上传场景）
            $file_access_url = 'https://files.modern-chat.top/list.php'
                . '?id='   . urlencode($upload_id_new)
                . '&file=' . urlencode($stored_name_new)
                . '&vkey=' . urlencode($vkey_for_token)
                . '&token=' . urlencode(generate_list_token($upload_id_new, $stored_name_new, $vkey_for_token));
            $db_file_path = $upload_id_new . '/' . $stored_name_new;
        } elseif (!empty($file_url_new)) {
            // 只有 file_url → 直接使用（兼容旧格式）
            $file_access_url = $file_url_new;
            $db_file_path = $file_url_new;
        } else {
            echo json_encode(['success' => false, 'message' => '文件信息不完整']);
            exit;
        }

        $file_result = [
            'success'       => true,
            'file_path'     => $db_file_path,
            'file_url'      => $file_access_url,
            'file_name'     => $file_name_new,
            'file_size'     => (int)$file_size_new,
            'mime_type'     => $file_type_new ?: 'application/octet-stream',
            'upload_id'     => $upload_id_new,
            'original_name' => $file_name_new,
            'stored_name'   => $stored_name_new,
            'is_duplicate'  => false,
        ];
        error_log("Pre-uploaded file: id={$upload_id_new} stored={$stored_name_new} name={$file_name_new} size={$file_size_new}");
        
        // 如果是图片，进行 NSFW 检测（使用文件URL）
        $mime_type = $file_result['mime_type'];
        if (strpos($mime_type, 'image/') === 0) {
            $image_url = $file_access_url;
            $nsfw_result = checkImageNSFW($image_url);
            error_log("NSFW Check Result (pre-uploaded): " . print_r($nsfw_result, true));
            
            if ($nsfw_result) {
                $is_nsfw = isset($nsfw_result['is_nsfw']) ? $nsfw_result['is_nsfw'] : false;
                $suggestion = isset($nsfw_result['suggestion']) ? $nsfw_result['suggestion'] : '';
                $risk_level = isset($nsfw_result['risk_level']) ? $nsfw_result['risk_level'] : '';
                
                if ($is_nsfw || $suggestion === 'block' || $risk_level === 'high') {
                    recordWarning($user_id, 'NSFW图片检测失败', $conn, '上传了违规图片');
                    $total_warnings = getTotalWarnings($user_id, $conn);
                    $max_warnings = 500;
                    $remaining = $max_warnings - $total_warnings;
                    
                    if ($total_warnings >= 500) {
                        banUser($user_id, 0, $conn, true, $total_warnings);
                        echo json_encode(['success' => false, 'message' => '发送违规图片次数已达上限，您的账号已被永久封禁，如有疑问请联系管理员！']);
                        exit;
                    } elseif ($total_warnings >= 400) {
                        banUser($user_id, 24, $conn, false, $total_warnings);
                        echo json_encode(['success' => false, 'message' => '由于您上传违规图片被系统检测到了，为了良好的网络环境，您已被限制发言24小时']);
                        exit;
                    } else {
                        echo json_encode(['success' => false, 'message' => '经过分析，您上传的图片违规，已记录，距离禁言还有' . $remaining . '次']);
                        exit;
                    }
                } elseif ($suggestion === 'review' || $risk_level === 'medium') {
                    echo json_encode(['success' => false, 'message' => '您上传的图片需要人工审核，请等待审核结果']);
                    exit;
                }
            }
        }
    } elseif (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        // ═══════════════════════════════════════════════════
        // 远程代理上传：将文件转发到 files.modern-chat.top/uploads.php
        // 不调用 api.php（外部API），不使用本地 FileUpload 存储
        // ═══════════════════════════════════════════════════
        error_log("[Proxy Upload] Starting remote upload for user={$user_id}");

        // 1. 获取用户 vkey（用于远程服务器身份验证）
        $vkey = null;
        if (function_exists('get_vkey_by_user_id')) {
            $vkey = get_vkey_by_user_id($user_id, $conn);
        }
        if (!$vkey) {
            echo json_encode(['success' => false, 'message' => '上传失败：无法获取访问密钥，请刷新页面重试']);
            exit;
        }

        // 2. 生成上传令牌（服务端签名，无需调用 api.php）
        if (!function_exists('generate_upload_token')) {
            require_once 'config.php';
        }
        $upload_session_id = uniqid('', true) . '_' . time();
        $auth_params = generate_upload_token($user_id, $vkey, $upload_session_id);

        // 3. 通过 cURL 转发文件到远程上传服务器
        $upload_file = $_FILES['file'];
        $remote_upload_url = 'https://files.modern-chat.top/uploads.php';

        $curl_file = new CURLFile($upload_file['tmp_name'], $upload_file['type'], $upload_file['name']);
        $post_fields = array_merge($auth_params, [
            'method'     => 'Single',
            'uploadfile' => $curl_file,
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $remote_upload_url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_HTTPHEADER     => [
                'X-Allow-Upload: ' . hash_hmac('sha256', 'internal_upload_bypass', UPLOAD_TOKEN_SECRET),
                'Origin: https://' . ($_SERVER['HTTP_HOST'] ?? 'chat.hyacine.com.cn'),
            ],
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        error_log("[Proxy Upload] Remote response (HTTP {$http_code}): " . substr($response ?? '', 0, 500));

        // 4. 解析远程服务器响应
        if ($response === false || !empty($curl_error)) {
            error_log("[Proxy Upload] cURL error: {$curl_error}");
            echo json_encode(['success' => false, 'message' => '文件上传失败：无法连接到文件服务器']);
            exit;
        }

        $remote_data = json_decode($response, true);
        if (!$remote_data) {
            error_log("[Proxy Upload] Invalid JSON response: " . substr($response, 0, 200));
            echo json_encode(['success' => false, 'message' => '文件上传失败：服务器返回异常']);
            exit;
        }

        if (($remote_data['success'] ?? false) !== true) {
            $err_msg = $remote_data['error'] ?? $remote_data['message'] ?? '未知错误';
            error_log("[Proxy Upload] Remote server error: {$err_msg}");
            echo json_encode(['success' => false, 'message' => '文件上传失败：' . $err_msg]);
            exit;
        }

        // 5. 构建统一的 file_result 格式
        $stored_name    = $remote_data['stored_name'] ?? '';
        $remote_id      = $remote_data['upload_id'] ?? $upload_session_id;
        $original_name  = $remote_data['file_name'] ?? $upload_file['name'];
        $remote_size    = (int)($remote_data['file_size'] ?? $upload_file['size']);
        $remote_mime    = $remote_data['mime_type'] ?: $upload_file['type'];
        $is_duplicate   = $remote_data['is_duplicate'] ?? false;

        // 文件访问 URL（通过 list.php 访问）
        $file_access_url = 'https://files.modern-chat.top/list.php'
            . '?id='    . urlencode($remote_id)
            . '&file='  . urlencode($stored_name)
            . '&vkey='  . urlencode($vkey)
            . '&token=' . urlencode(generate_list_token($remote_id, $stored_name, $vkey));

        $file_result = [
            'success'       => true,
            'file_path'     => $remote_id . '/' . $stored_name,  // 相对路径，用于数据库存储
            'file_url'      => $file_access_url,
            'file_name'     => $original_name,
            'file_size'     => $remote_size,
            'mime_type'     => $remote_mime,
            'upload_id'     => $remote_id,
            'original_name' => $original_name,
            'stored_name'   => $stored_name,
            'is_duplicate'  => $is_duplicate,
        ];

        error_log("[Proxy Upload] Success: id={$remote_id} file={$stored_name} url={$file_access_url}");

        // 如果是图片文件，进行NSFW检测（使用远程 URL）
        if ($file_result && $file_result['success']) {
            $mime_type = $file_result['mime_type'];
            if (strpos($mime_type, 'image/') === 0) {
                // 使用远程文件 URL 进行 NSFW 检测
                $image_url = $file_result['file_url'];
                
                // 调用NSFW检测API
                $nsfw_result = checkImageNSFW($image_url);
                error_log("NSFW Check Result: " . print_r($nsfw_result, true));
                
                // 处理NSFW检测结果
                if ($nsfw_result) {
                    $is_nsfw = isset($nsfw_result['is_nsfw']) ? $nsfw_result['is_nsfw'] : false;
                    $suggestion = isset($nsfw_result['suggestion']) ? $nsfw_result['suggestion'] : '';
                    $risk_level = isset($nsfw_result['risk_level']) ? $nsfw_result['risk_level'] : '';
                    
                    // 检查是否需要删除图片（远程上传的文件通过 API 清理标记处理）
                    if ($is_nsfw || $suggestion === 'block' || $risk_level === 'high') {
                        // 远程服务器上的文件，记录违规但不本地删除（由远程服务定期清理或人工处理）
                        error_log("[NSFW] Remote file flagged: {$file_result['file_url']}");
                        
                        // 记录违规
                        recordWarning($user_id, 'NSFW图片检测失败', $conn, '上传了违规图片');
                        
                        // 获取用户的总警告次数
                        $total_warnings = getTotalWarnings($user_id, $conn);
                        
                        // 系统设置的违禁词允许次数
                        $max_warnings = 500; // 永久封禁阈值
                        $remaining = $max_warnings - $total_warnings;
                        
                        // 检查是否需要封禁
                        if ($total_warnings >= 500) {
                            // 永久封禁用户
                            banUser($user_id, 0, $conn, true, $total_warnings);
                            echo json_encode(['success' => false, 'message' => '发送违规图片次数已达上限，您的账号已被永久封禁，如有疑问请联系管理员！']);
                            exit;
                        } elseif ($total_warnings >= 400) {
                            // 临时封禁
                            banUser($user_id, 24, $conn, false, $total_warnings);
                            echo json_encode(['success' => false, 'message' => '由于您上传违规图片被系统检测到了，为了良好的网络环境，您已被限制发言24小时']);
                            exit;
                        } else {
                            // 显示警告信息
                            echo json_encode(['success' => false, 'message' => '经过分析，您上传的图片违规，已记录，距离禁言还有' . $remaining . '次']);
                            exit;
                        }
                    } elseif ($suggestion === 'review' || $risk_level === 'medium') {
                        // 远程文件记录审核信息（不移动本地路径，记录 URL 供管理员查看）
                        recordImageForReview($conn, $user_id, $file_result['file_url'], $file_result['original_name'], $file_result['file_size'], $mime_type);

                        // 显示审核中信息
                        echo json_encode(['success' => false, 'message' => '您上传的图片需要人工审核，请等待审核结果']);
                        exit;
                    }
                    // 如果is_nsfw为false且suggestion为pass且risk_level为low，则保留图片，继续执行
                }
            }
        }
    }
    
    // 确保必要的表存在
    ensureProhibitedWordTables($conn);
    
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
                $file_result['mime_type'],
                $file_result['upload_id'] ?? null,
                $file_result['file_url'] ?? null
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
            

            
            // 调用API检测违禁词
            $api_response = checkProhibitedWordsAPI($message_text);
            
            // 处理API响应
            if ($api_response['status'] === 'forbidden') {
                // 记录警告
                recordWarning($user_id, 'API检测到违禁词', $conn, $message_text);
                
                // 获取用户的总警告次数
                $total_warnings = getTotalWarnings($user_id, $conn);
                
                // 系统设置的违禁词允许次数
                $max_warnings = 500; // 永久封禁阈值
                $remaining = $max_warnings - $total_warnings;
                
                // 检查是否需要封禁
                if ($total_warnings >= 500) {
                    // 永久封禁用户
                    banUser($user_id, 0, $conn, true, $total_warnings);
                    echo json_encode(['success' => false, 'message' => '发送违禁词次数已达上限，您的账号已被永久封禁，如有疑问请联系管理员！']);
                    exit;
                } elseif ($total_warnings >= 400) {
                    // 临时封禁
                    banUser($user_id, 24, $conn, false, $total_warnings);
                    echo json_encode(['success' => false, 'message' => '由于您的不当言论被系统检测到了，为了良好的网络环境，您已被限制发言24小时']);
                    exit;
                } else {
                    // 显示警告信息
                    echo json_encode(['success' => false, 'message' => '经过分析，您的消息违规，已记录，距离禁言还有' . $remaining . '次']);
                    exit;
                }
            } elseif ($api_response['status'] !== 'ok') {
                // API请求失败，记录错误但允许发送消息
                error_log("API check failed: " . print_r($api_response, true));
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
        
        // 修复：检查用户是否为群成员
        if (!$group->isUserInGroup($selected_id, $user_id)) {
            echo json_encode(['success' => false, 'message' => '您未加入该群聊，无法发送消息 ❌']);
            exit;
        }
        
        if ($file_result && $file_result['success']) {
            // 发送文件消息，添加file_type字段，使用mime_type作为file_type
            $file_info = [
                'file_path' => $file_result['file_path'],
                'file_name' => $file_result['file_name'],
                'file_size' => $file_result['file_size'],
                'file_type' => $file_result['mime_type'], // 添加file_type字段
                'upload_id' => $file_result['upload_id'] ?? null,
                'file_url' => $file_result['file_url'] ?? null
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
            

            
            // 调用API检测违禁词
            $api_response = checkProhibitedWordsAPI($message_text);
            
            // 处理API响应
            if ($api_response['status'] === 'forbidden') {
                // 记录警告
                recordWarning($user_id, 'API检测到违禁词', $conn, $message_text);
                
                // 获取用户的总警告次数
                $total_warnings = getTotalWarnings($user_id, $conn);
                
                // 系统设置的违禁词允许次数
                $max_warnings = 500; // 永久封禁阈值
                $remaining = $max_warnings - $total_warnings;
                
                // 检查是否需要封禁
                if ($total_warnings >= 500) {
                    // 永久封禁用户
                    banUser($user_id, 0, $conn, true, $total_warnings);
                    echo json_encode(['success' => false, 'message' => '发送违禁词次数已达上限，您的账号已被永久封禁，如有疑问请联系管理员！']);
                    exit;
                } elseif ($total_warnings >= 400) {
                    // 临时封禁
                    banUser($user_id, 24, $conn, false, $total_warnings);
                    echo json_encode(['success' => false, 'message' => '由于您的不当言论被系统检测到了，为了良好的网络环境，您已被限制发言24小时']);
                    exit;
                } else {
                    // 显示警告信息
                    echo json_encode(['success' => false, 'message' => '经过分析，您的消息违规，已记录，距离禁言还有' . $remaining . '次']);
                    exit;
                }
            } elseif ($api_response['status'] !== 'ok') {
                // API请求失败，记录错误但允许发送消息
                error_log("API check failed: " . print_r($api_response, true));
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
        preg_match_all('/@([\x{4e00}-\x{9fa5}\w]+)/u', $message_text, $matches);
        
        if (!empty($matches[1])) {
            $mentioned_names = $matches[1];
            
            // 检查是否提及了全体成员
            if (in_array('全体成员', $mentioned_names) || in_array('所有人', $mentioned_names)) {
                // 获取群聊所有成员
                $group = new Group($conn);
                $group_members = $group->getGroupMembers($chat_id);
                
                foreach ($group_members as $member) {
                    // 不包括发送者自己
                    if (isset($member['id']) && $member['id'] != $user_id) {
                        $mentioned_users[] = $member['id'];
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

        // 如果是文件消息，补充完整可访问的 file_url
        if (!empty($file_result) && $file_result['success'] && !empty($sent_message['file_path'])) {
            $sent_message['file_url'] = $file_result['file_url'];
            // 确保 original_name 也在返回中（有些消息表可能不存这个字段）
            if (empty($sent_message['original_name']) && !empty($file_result['original_name'])) {
                $sent_message['original_name'] = $file_result['original_name'];
            }
            error_log("Enhanced file message with file_url: " . $file_result['file_url']);
        }

        echo json_encode([
            'success' => true,
            'message_id' => $result['message_id'],
            'message' => $sent_message,
            'is_duplicate' => $file_result['is_duplicate'] ?? false
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['message']]);
    }
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    error_log("服务器内部错误: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    echo json_encode(['success' => false, 'message' => '服务器内部错误，请稍后重试']);
}