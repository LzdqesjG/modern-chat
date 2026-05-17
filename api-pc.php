<?php
/**
 * PC端扫码登录API
 * 
 * 专门为PC端提供扫码登录功能，包括生成二维码和查询登录状态。
 * 基于现有的扫码登录系统，提供更简洁的PC端接口。
 */

// 开启错误日志，关闭页面错误显示
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'api-pc_error.log');
error_reporting(E_ALL);

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');


// 处理 OPTIONS 预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 设置Session配置以支持跨域
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', 1);

// 启动 Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 获取请求数据 (兼容 JSON 和 Form Data，APP 端可能发 JSON 但 Content-Type 不准确)
function get_request_data() {
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    $input = file_get_contents('php://input');
    $data = [];
    
    if (!empty($input)) {
        $decoded = json_decode($input, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    if (strpos($content_type, 'application/json') !== false && !empty($data)) {
        return $data;
    }
    if (!empty($_POST)) {
        return array_merge($data, $_POST);
    }
    if (!empty($_GET)) {
        return array_merge($data, $_GET);
    }
    return $data;
}

// 检测是否为状态检测请求（无参数或特定参数）
$request_data = get_request_data();
$resource = $request_data['resource'] ?? $_GET['resource'] ?? '';
$action = $request_data['action'] ?? $_GET['action'] ?? '';

// 调试日志
error_log("[API-PC] 请求: method=" . $_SERVER['REQUEST_METHOD'] . ", resource=$resource, action=$action, data=" . json_encode($request_data) . ", url=" . $_SERVER['REQUEST_URI']);

// APP 版本信息接口（无需登录，PC端检查更新用）
if ($resource === 'version' && ($action === 'app' || $action === '')) {
    $base_dir_for_version = __DIR__;
    $version_file = $base_dir_for_version . '/version-app-pc.json';
    if (is_file($version_file)) {
        $version_data = json_decode(file_get_contents($version_file), true);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=60');
        echo json_encode([
            'version' => $version_data['version'] ?? 'V0.0.1',
            'update_message' => $version_data['update_message'] ?? '',
            'downloadUrl' => $version_data['downloadUrl'] ?? '',
            'update_must' => $version_data['update_must'] ?? false
        ], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'version' => 'V0.0.1',
            'update_message' => '暂无新版本',
            'downloadUrl' => '',
            'update_must' => false
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 检查服务器连接接口（无需登录）
if ($resource === 'check_api') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'code' => 200,
        'message' => '服务器连接正常'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 如果没有 resource 参数，返回错误信息
if (empty($resource)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => '参数不存在',
        'code' => 404
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 智能检测基础路径
// 支持两种部署方式：
// 1. api.php 在 /api/ 子目录中，其他文件在父目录
// 2. api.php 和其他文件都在根目录（扁平化部署）
$base_dir = __DIR__;
$api_subdir = basename(__DIR__) === 'api';

if ($api_subdir) {
    // 方式1：api.php 在 api/ 目录，其他文件在父目录
    $base_dir = dirname(__DIR__);
}

// 定义需要加载的核心文件
$required_files = [
    'config.php',
    'db.php',
    'User.php',
    'Friend.php',
    'Message.php',
    'Group.php',
    'FileUpload.php',
    'RSAUtil.php'
];

// 检查文件是否存在
$missing_files = [];
foreach ($required_files as $file) {
    $file_path = $base_dir . '/' . $file;
    if (!file_exists($file_path)) {
        $missing_files[] = $file;
    }
}

if (!empty($missing_files)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器初始化失败: 以下文件不存在: ' . implode(', ', $missing_files),
        'base_dir' => $base_dir,
        'api_dir' => __DIR__,
        'api_subdir' => $api_subdir,
        'tip' => '请确保以下文件存在于 ' . $base_dir . ' 目录: ' . implode(', ', $required_files)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 加载核心文件
try {
    require_once $base_dir . '/config.php';
    require_once $base_dir . '/db.php';
    require_once $base_dir . '/User.php';
    require_once $base_dir . '/Friend.php';
    require_once $base_dir . '/Message.php';
    require_once $base_dir . '/Group.php';
    require_once $base_dir . '/FileUpload.php';
    require_once $base_dir . '/RSAUtil.php';
} catch (Throwable $e) {
    error_log("API 文件加载失败: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器初始化失败: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 检查数据库连接
if ($conn === null) {
    error_log("API 数据库连接为空");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '数据库连接失败，请检查数据库配置'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 初始化核心服务类
try {
    $user = new User($conn);
    $friend = new Friend($conn);
    $message = new Message($conn);
    $group = new Group($conn);
    $fileUpload = new FileUpload($conn);
    $rsaUtil = new RSAUtil();
} catch (Throwable $e) {
    error_log("API 服务初始化失败: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器初始化失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取所有会话的最新消息和未读计数
if ($resource === 'unread' && $action === 'list') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $user_id = check_auth();
        
        $friend_sessions = array();
        // 必须先按会话聚合出「最新一条消息」的 id，再 JOIN 取 content/type；
        // 否则在 GROUP BY 下直接选 m.content 会为每组返回任意一行，导致列表预览错乱。
        $friend_sql = "
            SELECT 
                agg.id,
                agg.last_time,
                m.content AS last_message,
                m.type AS message_type,
                u.username AS sender_name,
                COALESCE(um.count, 0) AS unread_count
            FROM (
                SELECT 
                    CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END AS id,
                    MAX(m.created_at) AS last_time,
                    SUBSTRING_INDEX(GROUP_CONCAT(m.id ORDER BY m.created_at DESC, m.id DESC SEPARATOR ','), ',', 1) AS last_msg_id
                FROM messages m
                WHERE (m.sender_id = ? OR m.receiver_id = ?)
                GROUP BY CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END
            ) agg
            INNER JOIN messages m ON m.id = CAST(agg.last_msg_id AS UNSIGNED)
            LEFT JOIN users u ON m.sender_id = u.id
            LEFT JOIN unread_messages um ON um.user_id = ? AND um.chat_type = 'friend' AND um.chat_id = agg.id
            ORDER BY agg.last_time DESC
        ";
        $friend_stmt = $conn->prepare($friend_sql);
        $friend_stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
        while ($row = $friend_stmt->fetch(PDO::FETCH_ASSOC)) {
            $friend_sessions[] = $row;
        }
        
        $group_sessions = array();
        $group_sql = "
            SELECT 
                agg.id,
                agg.last_time,
                gm.content AS last_message,
                g.name AS group_name,
                u.username AS sender_name,
                COALESCE(NULLIF(TRIM(gm.file_type), ''), 'text') AS message_type,
                COALESCE(um.count, 0) AS unread_count
            FROM (
                SELECT 
                    gm.group_id AS id,
                    MAX(gm.created_at) AS last_time,
                    SUBSTRING_INDEX(GROUP_CONCAT(gm.id ORDER BY gm.created_at DESC, gm.id DESC SEPARATOR ','), ',', 1) AS last_msg_id
                FROM group_messages gm
                WHERE gm.group_id IN (
                    SELECT group_id FROM group_members WHERE user_id = ?
                )
                GROUP BY gm.group_id
            ) agg
            INNER JOIN group_messages gm ON gm.id = CAST(agg.last_msg_id AS UNSIGNED)
            LEFT JOIN users u ON gm.sender_id = u.id
            LEFT JOIN `groups` g ON gm.group_id = g.id
            LEFT JOIN unread_messages um ON um.user_id = ? AND um.chat_type = 'group' AND um.chat_id = agg.id
            ORDER BY agg.last_time DESC
        ";
        $group_stmt = $conn->prepare($group_sql);
        $group_stmt->execute([$user_id, $user_id]);
        while ($row = $group_stmt->fetch(PDO::FETCH_ASSOC)) {
            $group_sessions[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'friends' => $friend_sessions,
                'groups' => $group_sessions
            ]
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log("API unread/list 错误: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ==========================================
// 辅助函数
// ==========================================

/**
 * 返回成功响应
 */
function response_success($data = [], $message = '操作成功') {
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 返回错误响应
 */
function response_error($message = '操作失败', $code = 400, $extraData = []) {
    http_response_code($code);
    $response = [
        'success' => false,
        'message' => $message,
        'code' => $code
    ];
    if (!empty($extraData) && is_array($extraData)) {
        $response = array_merge($response, $extraData);
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 验证用户是否登录
 */
function check_auth() {
    if (!isset($_SESSION['user_id'])) {
        response_error('未登录或会话已过期', 401);
    }
    return $_SESSION['user_id'];
}

/**
 * 格式化时长（秒转换为可读格式）
 * @param int $seconds 秒数
 * @return string 格式化后的时长
 */
function formatDuration($seconds) {
    if ($seconds <= 0) {
        return '已过期';
    }
    
    $days = floor($seconds / 86400);
    $hours = floor(($seconds % 86400) / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    $parts = [];
    if ($days > 0) {
        $parts[] = $days . '天';
    }
    if ($hours > 0) {
        $parts[] = $hours . '时';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . '分';
    }
    if ($secs > 0 || empty($parts)) {
        $parts[] = $secs . '秒';
    }
    
    return implode('', $parts);
}

// ==========================================
// 请求处理逻辑
// ==========================================

try {
    // 使用已定义的变量
    $data = $request_data;
    
    // 全局参数
    $id = $data['id'] ?? null;
    
    // 需要登录的资源列表
    $requiresAuth = [
        'user', 'friends', 'messages', 'groups', 'upload', 'avatar', 
        'sessions', 'unread', 'user_check', 'ip_check'
    ];
    
    // 需要检查用户和IP状态的资源（登录后）
    $requiresCheck = [
        'user', 'friends', 'messages', 'groups', 'upload', 'avatar', 
        'sessions', 'unread'
    ];
    
    // 如果需要登录且不是登录/注册相关请求，检查用户状态
    if (in_array($resource, $requiresCheck) && isset($_SESSION['user_id'])) {
        $currentUserId = $_SESSION['user_id'];
        
        // 检查用户封禁状态
        $banInfo = $user->isBanned($currentUserId);
        if ($banInfo) {
            $banEnd = strtotime($banInfo['expires_at']);
            $now = time();
            $remaining = $banEnd > $now ? $banEnd - $now : 0;
            
            $stmt = $conn->prepare("SELECT ban_start FROM bans WHERE user_id = ? AND status = 'active'");
            $stmt->execute([$currentUserId]);
            $banStart = $stmt->fetchColumn();
            
            $randomCode = mt_rand(8526, 9999);
            
            response_error($banInfo['reason'], $randomCode, [
                'ban_time' => $banStart ?? date('Y-m-d H:i:s'),
                'ban_end_time' => $banInfo['expires_at'],
                'Remaining' => formatDuration($remaining)
            ]);
        }
        
        // 检查IP封禁状态
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($ipAddress)) {
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT b.user_id) as banned_count
                FROM bans b
                JOIN users u ON b.user_id = u.id
                JOIN ip_registrations ipr ON u.id = ipr.user_id
                WHERE b.status = 'active' AND ipr.ip_address = ?
            ");
            $stmt->execute([$ipAddress]);
            $result = $stmt->fetch();
            $bannedCount = $result['banned_count'] ?? 0;
            
            if ($bannedCount >= 5) {
                $stmt = $conn->prepare("SELECT * FROM ip_bans WHERE ip_address = ? AND status = 'active'");
                $stmt->execute([$ipAddress]);
                $ipBan = $stmt->fetch();
                
                if ($ipBan) {
                    $banEnd = strtotime($ipBan['ban_end']);
                    $now = time();
                    $remaining = $banEnd > $now ? $banEnd - $now : 0;
                    
                    $randomCode = mt_rand(3526, 8526);
                    
                    response_error('当前IP下有多个账号已被封禁！', $randomCode, [
                        'ip_ban_time' => $ipBan['ban_start'],
                        'ip_ban_end_time' => $ipBan['ban_end'],
                        'remaining' => formatDuration($remaining)
                    ]);
                }
            }
            
            // 优先通过 access_key 验证用户身份
            $noAccessKeyCheck = ['user_check', 'ip_check', 'version', 'auth'];
            if (!in_array($resource, $noAccessKeyCheck)) {
                $accessKey = $data['access_key'] ?? $_GET['access_key'] ?? '';
                
                if (!empty($accessKey)) {
                    $stmt = $conn->prepare("SELECT user_id FROM pc_keys WHERE access_key = ? AND is_active = TRUE");
                    $stmt->execute([$accessKey]);
                    $keyInfo = $stmt->fetch();
                    
                    if (!$keyInfo) {
                        echo json_encode([
                            'success' => false,
                            'message' => '无法执行操作，请重试！'
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    // 通过 access_key 找到用户后，设置 session
                    $_SESSION['user_id'] = $keyInfo['user_id'];
                    $currentUserId = $keyInfo['user_id'];
                }
            }
        }
    }

    // 路由分发
    switch ($resource) {
        // ------------------------------------------
        // 认证模块 (Auth)
        // ------------------------------------------
        case 'auth':
            switch ($action) {
                case 'login':
                    $email = trim($data['email'] ?? '');
                    $password = '';
                    
                    // 处理 RSA 加密密码
                    if (isset($data['encrypted_password']) && !empty($data['encrypted_password'])) {
                        // 使用 RSA 解密密码（使用全局初始化的rsaUtil对象）
                        $decryptedPassword = $rsaUtil->decrypt($data['encrypted_password']);
                        if ($decryptedPassword !== false) {
                            $password = $decryptedPassword;
                        } else {
                            response_error('密码解密失败，请重试');
                        }
                    } elseif (isset($data['password']) && !empty($data['password'])) {
                        // 兼容未加密的情况
                        $password = $data['password'];
                    }
                    
                    if (empty($email) || empty($password)) {
                        response_error('邮箱和密码不能为空');
                    }
                    
                    $result = $user->login($email, $password);
                    if ($result['success']) {
                        session_regenerate_id(true); // 防止会话固定攻击
                        $_SESSION['user_id'] = $result['user']['id'];
                        $_SESSION['username'] = $result['user']['username'];
                        $_SESSION['email'] = $result['user']['email'];
                        
                        // 移除敏感信息
                        unset($result['user']['password']);
                        unset($result['user']['security_question']);
                        unset($result['user']['security_answer']);
                        unset($result['user']['reset_token']);
                        unset($result['user']['reset_token_expires']);
                        
                        // 更新状态为在线
                        $user->updateStatus($result['user']['id'], 'online');
                        
                        // 生成 access_key 并保存到数据库
                        $accessKey = bin2hex(random_bytes(32));
                        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '未知';
                        $deviceName = substr($_SERVER['HTTP_USER_AGENT'] ?? '未知设备', 0, 100);
                        
                        // 检查是否已存在该IP的记录
                        $stmt = $conn->prepare("SELECT id FROM pc_keys WHERE user_id = ? AND ip_address = ?");
                        $stmt->execute([$result['user']['id'], $ipAddress]);
                        
                        if ($stmt->fetch()) {
                            // 如果存在，更新
                            $stmt = $conn->prepare("UPDATE pc_keys SET access_key = ?, device_name = ?, created_at = NOW(), is_active = TRUE WHERE user_id = ? AND ip_address = ?");
                            $stmt->execute([$accessKey, $deviceName, $result['user']['id'], $ipAddress]);
                        } else {
                            // 不存在，插入
                            $stmt = $conn->prepare("INSERT INTO pc_keys (user_id, access_key, device_name, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
                            $stmt->execute([$result['user']['id'], $accessKey, $deviceName, $ipAddress]);
                        }
                        
                        $result['user']['access_key'] = $accessKey;
                        
                        // 同时在顶层返回 access_key，方便前端获取
                        $responseData = $result['user'];
                        $responseData['access_key'] = $accessKey;
                        
                        response_success($responseData, '登录成功');
                    } else {
                        response_error($result['message']);
                    }
                    break;
                    
                case 'register':
                    $username = trim($data['username'] ?? '');
                    $email = trim($data['email'] ?? '');
                    $password = $data['password'] ?? '';
                    $phone = trim($data['phone'] ?? '');
                    $sms_code = trim($data['sms_code'] ?? '');
                    $sms_required = getConfig('sms_verify_required', false);
                    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
                    
                    if (empty($username) || empty($email) || empty($password)) {
                        $hint = [];
                        if (empty($username)) $hint[] = '用户名';
                        if (empty($email)) $hint[] = '邮箱';
                        if (empty($password)) $hint[] = '密码';
                        response_error('请填写完整：' . implode('、', $hint) . '（若已填写仍报错，请检查网络或联系管理员）');
                    }
                    
                    if ($sms_required || !empty($sms_code)) {
                        if (empty($phone)) {
                            response_error('请输入手机号');
                        }
                        if (empty($sms_code)) {
                            response_error('请输入短信验证码');
                        }
                        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
                            response_error('请输入有效的手机号');
                        }
                        if (!isset($_SESSION['sms_code']) || !isset($_SESSION['sms_phone']) || !isset($_SESSION['sms_expire'])) {
                            response_error('短信验证码已过期，请重新获取');
                        }
                        if ($_SESSION['sms_phone'] !== $phone) {
                            response_error('手机号与接收验证码的手机号不一致');
                        }
                        if (time() > $_SESSION['sms_expire']) {
                            response_error('短信验证码已过期，请重新获取');
                        }
                        if ($_SESSION['sms_code'] !== $sms_code) {
                            response_error('短信验证码错误');
                        }
                        unset($_SESSION['sms_code']);
                        unset($_SESSION['sms_expire']);
                    }
                    
                    $result = $user->register($username, $email, $password, $phone, $ip_address);
                    if ($result['success']) {
                        $user->generateEncryptionKeys($result['user_id']);
                        require_once $base_dir . '/Group.php';
                        $group = new Group($conn);
                        $group->addUserToAllUserGroups($result['user_id']);
                        response_success(['user_id' => $result['user_id']], '注册成功');
                    } else {
                        response_error($result['message']);
                    }
                    break;
                    
                case 'logout':
                    if (isset($_SESSION['user_id'])) {
                        $user->updateStatus($_SESSION['user_id'], 'offline');
                    }
                    session_unset();
                    session_destroy();
                    response_success([], '退出成功');
                    break;
                    
                case 'check_status':
                    if (isset($_SESSION['user_id'])) {
                        response_success(['is_logged_in' => true, 'user_id' => $_SESSION['user_id']]);
                    } else {
                        response_success(['is_logged_in' => false]);
                    }
                    break;
                    
                case 'get_public_key':
                    // 获取 RSA 公钥，用于前端加密（使用全局初始化的rsaUtil对象）
                    $publicKey = $rsaUtil->getPublicKeyForJS();
                    response_success(['public_key' => $publicKey]);
                    break;
                    
                default:
                    response_error('参数不存在', 404);
            }
            break;

        // ------------------------------------------
        // 用户封禁检查接口
        // ------------------------------------------
        case 'user_check':
            $current_user_id = check_auth();
            
            try {
                $banInfo = $user->isBanned($current_user_id);
                
                if ($banInfo) {
                    $banEnd = strtotime($banInfo['expires_at']);
                    $now = time();
                    $remaining = $banEnd > $now ? $banEnd - $now : 0;
                    
                    $stmt = $conn->prepare("SELECT ban_start FROM bans WHERE user_id = ? AND status = 'active'");
                    $stmt->execute([$current_user_id]);
                    $banStart = $stmt->fetchColumn();
                    
                    $randomCode = mt_rand(8526, 9999);
                    
                    response_error($banInfo['reason'], $randomCode, [
                        'ban_time' => $banStart ?? date('Y-m-d H:i:s'),
                        'ban_end_time' => $banInfo['expires_at'],
                        'Remaining' => formatDuration($remaining)
                    ]);
                } else {
                    response_success(['status' => 'normal', 'message' => '账号状态正常']);
                }
            } catch (Exception $e) {
                error_log("[API-PC] user_check 错误: " . $e->getMessage());
                response_success(['status' => 'normal', 'message' => '账号状态正常']);
            }
            break;
            
        // ------------------------------------------
        // IP封禁检查接口
        // ------------------------------------------
        case 'ip_check':
            $current_user_id = check_auth();
            
            try {
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
                
                if (empty($ip_address)) {
                    response_success(['status' => 'normal', 'message' => 'IP状态正常']);
                    break;
                }
                
                $stmt = $conn->prepare("
                    SELECT COUNT(DISTINCT b.user_id) as banned_count
                    FROM bans b
                    JOIN users u ON b.user_id = u.id
                    JOIN ip_registrations ipr ON u.id = ipr.user_id
                    WHERE b.status = 'active' AND ipr.ip_address = ?
                ");
                $stmt->execute([$ip_address]);
                $result = $stmt->fetch();
                $bannedCount = $result['banned_count'] ?? 0;
                
                if ($bannedCount >= 5) {
                    $stmt = $conn->prepare("SELECT * FROM ip_bans WHERE ip_address = ? AND status = 'active'");
                    $stmt->execute([$ip_address]);
                    $ipBan = $stmt->fetch();
                    
                    if ($ipBan) {
                        $banEnd = strtotime($ipBan['ban_end']);
                        $now = time();
                        $remaining = $banEnd > $now ? $banEnd - $now : 0;
                        
                        $randomCode = mt_rand(3526, 8526);
                        
                        response_error('当前IP下有多个账号已被封禁！', $randomCode, [
                            'ip_ban_time' => $ipBan['ban_start'],
                            'ip_ban_end_time' => $ipBan['ban_end'],
                            'Remaining' => formatDuration($remaining)
                        ]);
                    } else {
                        $banStartTime = date('Y-m-d H:i:s');
                        $banEndTime = date('Y-m-d H:i:s', strtotime('+24 hours'));
                        $remaining = 24 * 3600;
                        
                        $randomCode = mt_rand(3526, 8526);
                        
                        response_error('当前IP下有多个账号已被封禁！', $randomCode, [
                            'ip_ban_time' => $banStartTime,
                            'ip_ban_end_time' => $banEndTime,
                            'Remaining' => formatDuration($remaining)
                        ]);
                    }
                } else {
                    response_success(['status' => 'normal', 'message' => 'IP状态正常']);
                }
            } catch (Exception $e) {
                error_log("[API-PC] ip_check 错误: " . $e->getMessage());
                response_success(['status' => 'normal', 'message' => 'IP状态正常']);
            }
            break;
            
        // ------------------------------------------
        // 用户模块 (User)
        // ------------------------------------------
        case 'user':
            $current_user_id = check_auth();
            
            switch ($action) {
                case 'get_info':
                    // 默认获取当前用户，也可获取指定用户
                    $target_user_id = $data['user_id'] ?? $current_user_id;
                    $user_info = $user->getUserById($target_user_id);
                    
                    if ($user_info) {
                        // 处理手机号显示格式：前3位+****+后4位
                        if (isset($user_info['phone']) && !empty($user_info['phone'])) {
                            $phone = $user_info['phone'];
                            if (strlen($phone) >= 11) {
                                $user_info['phone'] = substr($phone, 0, 3) . '****' . substr($phone, -4);
                            }
                        }
                        
                        // 移除敏感信息
                        unset($user_info['password']);
                        unset($user_info['security_question']);
                        unset($user_info['security_answer']);
                        unset($user_info['reset_token']);
                        unset($user_info['reset_token_expires']);
                        response_success($user_info);
                    } else {
                        response_error('用户不存在', 404);
                    }
                    break;
                    
                case 'update_info':
                    // 更新当前用户信息
                    // 允许更新的字段应在 User::updateUser 中控制
                    $update_data = $data;
                    unset($update_data['resource'], $update_data['action'], $update_data['id']); // 移除控制参数
                    
                    // 验证密码
                    if (isset($update_data['password'])) {
                        $password = $update_data['password'];
                        unset($update_data['password']);
                        
                        // 验证密码是否正确
                        $current_user = $user->getUserById($current_user_id);
                        if (!password_verify($password, $current_user['password'])) {
                            response_error('密码错误');
                        }
                    }
                    
                    // 验证短信验证码
                    if (isset($update_data['sms_code']) && isset($update_data['phone'])) {
                        $sms_code = $update_data['sms_code'];
                        $phone = $update_data['phone'];
                        unset($update_data['sms_code']);
                        
                        // 这里应该验证短信验证码的有效性
                        // 实际项目中，应该从缓存或数据库中获取之前发送的验证码进行比对
                        // 这里简化处理，假设验证码正确
                        // if (!$sms->verifyCode($phone, $sms_code)) {
                        //     response_error('短信验证码错误');
                        // }
                    }
                    
                    if ($user->updateUser($current_user_id, $update_data)) {
                        // 更新 Session 中的信息（如果修改了）
                        $updated_user = $user->getUserById($current_user_id);
                        $_SESSION['username'] = $updated_user['username'];
                        $_SESSION['email'] = $updated_user['email'];
                        
                        response_success([], '个人信息更新成功');
                    } else {
                        response_error('更新失败或没有数据变更');
                    }
                    break;
                
                case 'search':
                    try {
                        $keyword = trim($data['q'] ?? '');
                        if (empty($keyword)) {
                            response_error('搜索关键词不能为空');
                        }
                        error_log("[API-PC] 用户搜索: keyword=$keyword, user_id=$current_user_id");
                        $users = $user->searchUsers($keyword, $current_user_id);
                        error_log("[API-PC] 用户搜索结果: " . count($users) . " 条");
                        response_success($users);
                    } catch (Exception $e) {
                        error_log("[API-PC] 用户搜索失败: " . $e->getMessage());
                        response_error('搜索失败: ' . $e->getMessage(), 500);
                    }
                    break;
                    
                case 'update_password':
                    $old_password = $data['old_password'] ?? '';
                    $new_password = $data['new_password'] ?? '';
                    
                    if (empty($old_password) || empty($new_password)) {
                        response_error('原密码和新密码不能为空');
                    }
                    
                    $current_user = $user->getUserById($current_user_id);
                    if (!$current_user) {
                        response_error('用户不存在', 404);
                    }
                    
                    if (!password_verify($old_password, $current_user['password'])) {
                        response_error('原密码不正确');
                    }
                    
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT, ['cost' => 12]);
                    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashed_password, $current_user_id]);
                    
                    response_success([], '密码修改成功');
                    break;
                    
                case 'delete_account':
                    $password = $data['password'] ?? '';
                    
                    if (empty($password)) {
                        response_error('请输入密码确认注销');
                    }
                    
                    $current_user = $user->getUserById($current_user_id);
                    if (!$current_user) {
                        response_error('用户不存在', 404);
                    }
                    
                    if (!password_verify($password, $current_user['password'])) {
                        response_error('密码不正确');
                    }
                    
                    $result = $user->deleteUser($current_user_id);
                    if ($result) {
                        session_unset();
                        session_destroy();
                        response_success([], '账号已注销');
                    } else {
                        response_error('注销账号失败');
                    }
                    break;
                    
                default:
                    response_error('参数不存在', 404);
            }
            break;

        // ------------------------------------------
        // 好友模块 (Friends)
        // ------------------------------------------
        case 'friends':
            $current_user_id = check_auth();
            
            switch ($action) {
                case 'list':
                    $friends = $friend->getFriends($current_user_id);
                    response_success($friends);
                    break;
                    
                case 'send_request':
                    $friend_id = $data['friend_id'] ?? 0;
                    if (empty($friend_id)) response_error('好友ID不能为空');
                    
                    $result = $friend->sendFriendRequest($current_user_id, $friend_id);
                    if ($result['success']) {
                        response_success([], $result['message']);
                    } else {
                        response_error($result['message']);
                    }
                    break;
                    
                case 'delete':
                    $friend_id = $data['friend_id'] ?? 0;
                    if (empty($friend_id)) response_error('好友ID不能为空');
                    
                    $result = $friend->deleteFriend($current_user_id, $friend_id);
                    if ($result['success']) {
                        response_success([], $result['message']);
                    } else {
                        response_error($result['message']);
                    }
                    break;
                
                // 好友请求管理
                case 'get_requests':
                    $requests = $friend->getPendingRequests($current_user_id);
                    response_success($requests);
                    break;
                    
                case 'accept_request':
                    $request_id = $data['request_id'] ?? 0;
                    if (empty($request_id)) response_error('请求ID不能为空');
                    
                    $result = $friend->acceptFriendRequest($current_user_id, $request_id);
                    if ($result['success']) {
                        response_success([], $result['message']);
                    } else {
                        response_error($result['message']);
                    }
                    break;
                    
                case 'reject_request':
                    $request_id = $data['request_id'] ?? 0;
                    if (empty($request_id)) response_error('请求ID不能为空');
                    
                    $result = $friend->rejectFriendRequest($current_user_id, $request_id);
                    if ($result['success']) {
                        response_success([], $result['message']);
                    } else {
                        response_error($result['message']);
                    }
                    break;

                default:
                    response_error('参数不存在', 404);
            }
            break;

        // ------------------------------------------
        // 消息模块 (Messages)
        // ------------------------------------------
        case 'messages':
            $current_user_id = check_auth();
            
            switch ($action) {
                case 'history':
                    $friend_id = $data['friend_id'] ?? 0;
                    if (empty($friend_id)) response_error('好友ID不能为空');
                    
                    $messages = $message->getChatHistory($current_user_id, $friend_id);
                    
                    // 处理消息数据，确保字段名与前端一致
                    $processed_messages = [];
                    foreach ($messages as $msg) {
                        $processed_msg = $msg;
                        // 确保sender_name字段存在
                        $processed_msg['sender_name'] = $msg['sender_username'] ?? '未知';
                        // 确保time字段存在
                        $processed_msg['time'] = $msg['created_at'] ?? '';
                        // 确保created_at字段存在
                        $processed_msg['created_at'] = $msg['created_at'] ?? '';
                        // 确保avatar字段存在
                        $processed_msg['avatar'] = $msg['avatar'] ?? null;
                        $processed_messages[] = $processed_msg;
                    }
                    
                    response_success($processed_messages);
                    break;
                    
                case 'send':
                    $receiver_id = $data['receiver_id'] ?? 0;
                    $content = trim($data['content'] ?? '');
                    
                    if (empty($receiver_id)) response_error('接收者ID不能为空');
                    if (empty($content)) response_error('消息内容不能为空');
                    
                    $receiver_id = preg_replace('/^(friend|group)_/', '', $receiver_id);
                    
                    if (!is_numeric($receiver_id)) response_error('无效的接收者ID');
                    
                    $result = $message->sendTextMessage($current_user_id, $receiver_id, $content);
                    if ($result['success']) {
                        response_success(['message_id' => $result['message_id']], '消息发送成功');
                    } else {
                        response_error('消息发送失败');
                    }
                    break;
                    
                case 'send_file':
                    $receiver_id = $data['receiver_id'] ?? 0;
                    $file_path = $data['file_path'] ?? '';
                    $file_name = $data['file_name'] ?? '';
                    $file_size = $data['file_size'] ?? 0;
                    $file_type = $data['file_type'] ?? '';
                    $audio_duration = (int)($data['audio_duration'] ?? 0);
                    
                    if (empty($receiver_id)) response_error('接收者ID不能为空');
                    if (empty($file_path)) response_error('文件路径不能为空');
                    
                    $receiver_id = preg_replace('/^(friend|group)_/', '', $receiver_id);
                    
                    if (!is_numeric($receiver_id)) response_error('无效的接收者ID');
                    
                    $result = $message->sendFileMessage($current_user_id, $receiver_id, $file_path, $file_name, $file_size, $file_type, $audio_duration);
                    if ($result['success']) {
                        response_success(['message_id' => $result['message_id'], 'audio_duration' => $result['audio_duration'] ?? $audio_duration], '文件发送成功');
                    } else {
                        response_error('文件发送失败');
                    }
                    break;
                    
                case 'recall':
                    // 撤回私聊消息
                    $message_id = $data['message_id'] ?? 0;
                    if (empty($message_id)) response_error('消息ID不能为空');
                    
                    $result = $message->recallMessage($message_id, $current_user_id);
                    if ($result['success']) {
                        response_success([], $result['message']);
                    } else {
                        response_error($result['message']);
                    }
                    break;
                    
                case 'delete':
                    // 删除私聊消息（仅删除自己的消息记录）
                    $message_id = $data['message_id'] ?? 0;
                    if (empty($message_id)) response_error('消息ID不能为空');
                    
                    // 验证消息是否属于当前用户
                    $stmt = $conn->prepare("SELECT id FROM messages WHERE id = ? AND sender_id = ?");
                    $stmt->execute([$message_id, $current_user_id]);
                    if (!$stmt->fetch()) {
                        response_error('无权删除此消息');
                    }
                    
                    // 软删除：标记为已删除
                    $stmt = $conn->prepare("UPDATE messages SET is_deleted = 1 WHERE id = ?");
                    $stmt->execute([$message_id]);
                    
                    response_success([], '消息已删除');
                    break;
                    
                case 'mark_read':
                    // 标记消息为已读
                    $friend_id = $data['friend_id'] ?? 0;
                    if (empty($friend_id)) response_error('好友ID不能为空');
                    
                    // 获取该好友发送给当前用户的所有未读消息
                    $stmt = $conn->prepare("SELECT id FROM messages WHERE sender_id = ? AND receiver_id = ? AND status != 'read'");
                    $stmt->execute([$friend_id, $current_user_id]);
                    $unread_messages = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (!empty($unread_messages)) {
                        $message->markAsRead($unread_messages);
                    }
                    
                    // 清除未读计数（无论是否有未读消息都执行）
                    $stmt = $conn->prepare("UPDATE unread_messages SET count = 0 WHERE user_id = ? AND chat_type = 'friend' AND chat_id = ?");
                    $stmt->execute([$current_user_id, $friend_id]);
                    
                    response_success([], '已标记为已读');
                    break;
                    
                case 'get_unread':
                    // 获取未读消息数量
                    $unread_count = $message->getUnreadCount($current_user_id);
                    response_success(['unread_count' => $unread_count]);
                    break;
                    
                case 'poll':
                    // 轮询获取新消息（支持私聊和群聊）
                    $last_time = $data['last_time'] ?? null;
                    $chat_type = $data['chat_type'] ?? 'friend'; // friend 或 group
                    $chat_id = $data['chat_id'] ?? 0;
                    
                    if (empty($last_time)) {
                        response_error('last_time参数不能为空');
                    }
                    
                    $new_messages = [];
                    
                    if ($chat_type === 'friend' && !empty($chat_id)) {
                        // 获取私聊新消息
                        $stmt = $conn->prepare("SELECT m.*, u.username as sender_name, u.avatar as sender_avatar 
                                               FROM messages m 
                                               JOIN users u ON m.sender_id = u.id 
                                               WHERE m.receiver_id = ? AND m.sender_id = ? 
                                               AND m.created_at > ? AND m.is_deleted = 0
                                               ORDER BY m.created_at ASC");
                        $stmt->execute([$current_user_id, $chat_id, $last_time]);
                        $new_messages = $stmt->fetchAll();
                        
                        // 也获取自己发送的消息（用于多设备同步）
                        $stmt = $conn->prepare("SELECT m.*, u.username as sender_name, u.avatar as sender_avatar 
                                               FROM messages m 
                                               JOIN users u ON m.sender_id = u.id 
                                               WHERE m.sender_id = ? AND m.receiver_id = ? 
                                               AND m.created_at > ? AND m.is_deleted = 0
                                               ORDER BY m.created_at ASC");
                        $stmt->execute([$current_user_id, $chat_id, $last_time]);
                        $sent_messages = $stmt->fetchAll();
                        
                        // 合并消息并按时间排序
                        $new_messages = array_merge($new_messages, $sent_messages);
                        usort($new_messages, function($a, $b) {
                            return strtotime($a['created_at']) - strtotime($b['created_at']);
                        });
                    } elseif ($chat_type === 'group' && !empty($chat_id)) {
                        // 获取群聊新消息
                        $stmt = $conn->prepare("SELECT gm.*, u.username as sender_name, u.avatar as sender_avatar 
                                               FROM group_messages gm 
                                               JOIN users u ON gm.sender_id = u.id 
                                               WHERE gm.group_id = ? 
                                               AND gm.created_at > ? AND gm.is_deleted = 0
                                               ORDER BY gm.created_at ASC");
                        $stmt->execute([$chat_id, $last_time]);
                        $new_messages = $stmt->fetchAll();
                    }
                    
                    // 处理消息数据，确保字段名与前端一致
                    $processed_messages = [];
                    foreach ($new_messages as $msg) {
                        $processed_msg = $msg;
                        // 确保sender_name字段存在
                        $processed_msg['sender_name'] = $msg['sender_name'] ?? '未知';
                        // 确保time字段存在
                        $processed_msg['time'] = $msg['created_at'] ?? '';
                        // 确保created_at字段存在
                        $processed_msg['created_at'] = $msg['created_at'] ?? '';
                        // 确保avatar字段存在
                        $processed_msg['avatar'] = $msg['sender_avatar'] ?? null;
                        // 处理消息类型
                        if (!empty($msg['message_type']) && $msg['message_type'] === 'file') {
                            $processed_msg['file_info'] = json_decode($msg['file_info'] ?? '{}', true);
                        }
                        // 移除敏感字段
                        if (isset($processed_msg['receiver_id'])) {
                            unset($processed_msg['receiver_id']);
                        }
                        if (isset($processed_msg['sender_avatar'])) {
                            unset($processed_msg['sender_avatar']);
                        }
                        $processed_messages[] = $processed_msg;
                    }
                    $new_messages = $processed_messages;
                    
                    response_success([
                        'messages' => $new_messages,
                        'count' => count($new_messages)
                    ]);
                    break;
                    
                case 'recall':
                    $message_id = $data['message_id'] ?? 0;
                    $chat_type = $data['chat_type'] ?? 'friend';
                    $chat_id = $data['chat_id'] ?? 0;
                    
                    if (empty($message_id)) {
                        response_error('消息ID不能为空');
                    }
                    if (empty($chat_type)) {
                        response_error('聊天类型不能为空');
                    }
                    if (empty($chat_id)) {
                        response_error('聊天ID不能为空');
                    }
                    
                    try {
                        if ($chat_type === 'friend') {
                            $stmt = $conn->prepare("SELECT sender_id, created_at FROM messages WHERE id = ? AND (sender_id = ? OR receiver_id = ?) AND is_deleted = 0");
                            $stmt->execute([$message_id, $current_user_id, $current_user_id]);
                            $msg = $stmt->fetch();
                            
                            if (!$msg) {
                                response_error('消息不存在或已被撤回');
                            }
                            
                            if ($msg['sender_id'] != $current_user_id) {
                                response_error('只能撤回自己发送的消息');
                            }
                            
                            $created_at = new DateTime($msg['created_at']);
                            $now = new DateTime();
                            $diff = $now->diff($created_at);
                            $minutes = $diff->i + ($diff->h * 60) + ($diff->days * 1440);
                            
                            if ($minutes > 5) {
                                response_error('消息已超过5分钟，无法撤回');
                            }
                            
                            $stmt = $conn->prepare("UPDATE messages SET is_deleted = 1, content = '[消息已撤回]', message_type = 'text' WHERE id = ?");
                            $stmt->execute([$message_id]);
                        } else {
                            $stmt = $conn->prepare("SELECT sender_id, created_at FROM group_messages WHERE id = ? AND group_id = ? AND is_deleted = 0");
                            $stmt->execute([$message_id, $chat_id]);
                            $msg = $stmt->fetch();
                            
                            if (!$msg) {
                                response_error('消息不存在或已被撤回');
                            }
                            
                            if ($msg['sender_id'] != $current_user_id) {
                                response_error('只能撤回自己发送的消息');
                            }
                            
                            $created_at = new DateTime($msg['created_at']);
                            $now = new DateTime();
                            $diff = $now->diff($created_at);
                            $minutes = $diff->i + ($diff->h * 60) + ($diff->days * 1440);
                            
                            if ($minutes > 5) {
                                response_error('消息已超过5分钟，无法撤回');
                            }
                            
                            $stmt = $conn->prepare("UPDATE group_messages SET is_deleted = 1, content = '[消息已撤回]', message_type = 'text' WHERE id = ?");
                            $stmt->execute([$message_id]);
                        }
                        
                        response_success([], '撤回成功');
                    } catch (Exception $e) {
                        error_log("[API-PC] 撤回消息失败: " . $e->getMessage());
                        response_error('撤回失败: ' . $e->getMessage(), 500);
                    }
                    break;
                    
                default:
                    response_error('参数不存在', 404);
            }
            break;

        // ------------------------------------------
        // 群组模块 (Groups)
        // ------------------------------------------
        case 'groups':
            $current_user_id = check_auth();
            
            switch ($action) {
                case 'list':
                    $groups = $group->getUserGroups($current_user_id);
                    response_success($groups);
                    break;
                    
                case 'info':
                    $group_id = $data['group_id'] ?? 0;
                    if (empty($group_id)) response_error('群聊ID不能为空');
                    
                    $group_info = $group->getGroupInfo($group_id);
                    if ($group_info) {
                        response_success($group_info);
                    } else {
                        response_error('群聊不存在', 404);
                    }
                    break;
                    
                case 'create':
                    $name = trim($data['name'] ?? '');
                    $member_ids = $data['member_ids'] ?? [];
                    
                    if (empty($name)) response_error('群聊名称不能为空');
                    
                    $group_id = $group->createGroup($current_user_id, $name, $member_ids);
                    if ($group_id) {
                        response_success(['group_id' => $group_id], '群聊创建成功');
                    } else {
                        response_error('群聊创建失败');
                    }
                    break;
                
                // 群成员管理
                case 'members':
                    $group_id = $data['group_id'] ?? 0;
                    if (empty($group_id)) response_error('群聊ID不能为空');
                    
                    $members = $group->getGroupMembers($group_id);
                    response_success($members);
                    break;
                    
                case 'add_members':
                    $group_id = $data['group_id'] ?? 0;
                    $member_ids = $data['member_ids'] ?? [];
                    
                    if (empty($group_id) || empty($member_ids)) response_error('参数不完整');
                    
                    if ($group->addGroupMembers($group_id, $member_ids)) {
                        response_success([], '成员添加成功');
                    } else {
                        response_error('成员添加失败');
                    }
                    break;

                // 群消息
                case 'messages':
                    $group_id = $data['group_id'] ?? 0;
                    if (empty($group_id)) response_error('群聊ID不能为空');
                    
                    $messages = $group->getGroupMessages($group_id, $current_user_id);
                    // 处理消息数据，确保字段名与前端一致
                    $processed_messages = [];
                    foreach ($messages as $msg) {
                        $processed_msg = $msg;
                        // 确保sender_name字段存在（兼容前端期望）
                        $processed_msg['sender_name'] = $msg['sender_username'] ?? ($msg['sender_name'] ?? '未知');
                        // 确保avatar字段存在
                        $processed_msg['avatar'] = $msg['avatar'] ?? null;
                        $processed_messages[] = $processed_msg;
                    }
                    response_success($processed_messages);
                    break;
                    
                case 'mark_read':
                    // 标记群消息为已读
                    $group_id = $data['group_id'] ?? 0;
                    if (empty($group_id)) response_error('群聊ID不能为空');
                    
                    // 清除群聊未读计数
                    $stmt = $conn->prepare("UPDATE unread_messages SET count = 0 WHERE user_id = ? AND chat_type = 'group' AND chat_id = ?");
                    $stmt->execute([$current_user_id, $group_id]);
                    
                    response_success([], '已标记为已读');
                    break;
                    
                case 'send_message':
                    $group_id = $data['group_id'] ?? 0;
                    $content = trim($data['content'] ?? '');
                    
                    if (empty($group_id)) response_error('群聊ID不能为空');
                    if (empty($content)) response_error('消息内容不能为空');
                    
                    $result = $group->sendGroupMessage($group_id, $current_user_id, $content);
                    if ($result['success']) {
                        response_success(['message_id' => $result['message_id']], '消息发送成功');
                    } else {
                        response_error($result['message'] ?? '消息发送失败');
                    }
                    break;
                    
                case 'send_file':
                    $group_id = $data['group_id'] ?? 0;
                    $file_path = $data['file_path'] ?? '';
                    $file_name = $data['file_name'] ?? '';
                    $file_size = $data['file_size'] ?? 0;
                    $file_type = $data['file_type'] ?? '';
                    $audio_duration = (int)($data['audio_duration'] ?? 0);
                    
                    if (empty($group_id)) response_error('群聊ID不能为空');
                    if (empty($file_path)) response_error('文件路径不能为空');
                    
                    $file_info = [
                        'file_path' => $file_path,
                        'file_name' => $file_name,
                        'file_size' => $file_size,
                        'file_type' => $file_type,
                        'audio_duration' => $audio_duration
                    ];
                    
                    $result = $group->sendGroupMessage($group_id, $current_user_id, '', $file_info);
                    if ($result['success']) {
                        response_success(['message_id' => $result['message_id'], 'audio_duration' => $result['audio_duration'] ?? $audio_duration], '文件发送成功');
                    } else {
                        response_error($result['message'] ?? '文件发送失败');
                    }
                    break;
                    
                case 'recall':
                    // 撤回群聊消息
                    $message_id = $data['message_id'] ?? 0;
                    if (empty($message_id)) response_error('消息ID不能为空');
                    
                    $result = $group->recallGroupMessage($message_id, $current_user_id);
                    if ($result['success']) {
                        response_success([], $result['message']);
                    } else {
                        response_error($result['message']);
                    }
                    break;
                    
                case 'delete_message':
                    // 删除群聊消息（仅删除自己的消息记录）
                    $message_id = $data['message_id'] ?? 0;
                    if (empty($message_id)) response_error('消息ID不能为空');
                    
                    // 验证消息是否属于当前用户
                    $stmt = $conn->prepare("SELECT id FROM group_messages WHERE id = ? AND sender_id = ?");
                    $stmt->execute([$message_id, $current_user_id]);
                    if (!$stmt->fetch()) {
                        response_error('无权删除此消息');
                    }
                    
                    // 软删除：标记为已删除
                    $stmt = $conn->prepare("UPDATE group_messages SET is_deleted = 1 WHERE id = ?");
                    $stmt->execute([$message_id]);
                    
                    response_success([], '消息已删除');
                    break;
                    
                case 'pin':
                    // 置顶群聊
                    $group_id = $data['group_id'] ?? 0;
                    if (empty($group_id)) response_error('群聊ID不能为空');
                    
                    $group->pinGroup($group_id, $current_user_id);
                    response_success([], '群聊已置顶');
                    break;
                    
                case 'unpin':
                    // 取消置顶群聊
                    $group_id = $data['group_id'] ?? 0;
                    if (empty($group_id)) response_error('群聊ID不能为空');
                    
                    $group->unpinGroup($group_id, $current_user_id);
                    response_success([], '已取消置顶');
                    break;
                    
                case 'leave':
                    // 退出群聊
                    $group_id = $data['group_id'] ?? 0;
                    if (empty($group_id)) response_error('群聊ID不能为空');
                    
                    // 检查是否是群主
                    $group_info = $group->getGroupInfo($group_id);
                    if ($group_info && $group_info['owner_id'] == $current_user_id) {
                        response_error('群主不能退出群聊，请先转让群主或解散群聊');
                    }
                    
                    // 删除群成员记录
                    $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
                    $stmt->execute([$group_id, $current_user_id]);
                    
                    response_success([], '已退出群聊');
                    break;
                    
                case 'remove_member':
                    // 踢出群成员（仅群主和管理员可用）
                    $group_id = $data['group_id'] ?? 0;
                    $user_id = $data['user_id'] ?? 0;
                    
                    if (empty($group_id) || empty($user_id)) response_error('参数不完整');
                    
                    // 检查权限
                    $group_info = $group->getGroupInfo($group_id);
                    if (!$group_info) response_error('群聊不存在');
                    
                    // 检查当前用户是否是群主或管理员
                    $is_owner = $group_info['owner_id'] == $current_user_id;
                    $stmt = $conn->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
                    $stmt->execute([$group_id, $current_user_id]);
                    $member_info = $stmt->fetch();
                    $is_admin = $member_info && $member_info['role'] == 'admin';
                    
                    if (!$is_owner && !$is_admin) {
                        response_error('没有权限踢出成员');
                    }
                    
                    // 不能踢出群主
                    if ($user_id == $group_info['owner_id']) {
                        response_error('不能踢出群主');
                    }
                    
                    // 删除群成员
                    $stmt = $conn->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
                    $stmt->execute([$group_id, $user_id]);
                    
                    response_success([], '已将该成员移出群聊');
                    break;
                    
                case 'set_admin':
                    // 设置/取消管理员（仅群主可用）
                    $group_id = $data['group_id'] ?? 0;
                    $user_id = $data['user_id'] ?? 0;
                    $is_admin = $data['is_admin'] ?? true;
                    
                    if (empty($group_id) || empty($user_id)) response_error('参数不完整');
                    
                    // 检查是否是群主
                    $group_info = $group->getGroupInfo($group_id);
                    if (!$group_info || $group_info['owner_id'] != $current_user_id) {
                        response_error('只有群主可以设置管理员');
                    }
                    
                    if ($group->setAdmin($group_id, $user_id, $is_admin)) {
                        response_success([], $is_admin ? '已设置为管理员' : '已取消管理员');
                    } else {
                        response_error('操作失败，管理员数量已达上限');
                    }
                    break;
                    
                case 'transfer':
                    // 转让群主
                    $group_id = $data['group_id'] ?? 0;
                    $new_owner_id = $data['new_owner_id'] ?? 0;
                    
                    if (empty($group_id) || empty($new_owner_id)) response_error('参数不完整');
                    
                    if ($group->transferOwnership($group_id, $current_user_id, $new_owner_id)) {
                        response_success([], '群主已转让');
                    } else {
                        response_error('转让失败，请确认新群主是群成员');
                    }
                    break;

                case 'search':
                    try {
                        $keyword = trim($data['q'] ?? '');
                        if ($keyword === '') {
                            response_error('搜索关键词不能为空');
                        }
                        error_log("[API-PC] 群聊搜索: keyword=$keyword, user_id=$current_user_id");
                        $like = '%' . $keyword . '%';
                        $stmt = $conn->prepare(
                            "SELECT g.id, g.name, g.owner_id
                             FROM `groups` g
                             WHERE g.name LIKE ?
                             ORDER BY g.name ASC
                             LIMIT 50"
                        );
                        $stmt->execute([$like]);
                        $result = $stmt->fetchAll();
                        error_log("[API-PC] 群聊搜索结果: " . count($result) . " 条");
                        response_success($result);
                    } catch (Exception $e) {
                        error_log("[API-PC] 群聊搜索失败: " . $e->getMessage());
                        response_error('搜索失败: ' . $e->getMessage(), 500);
                    }
                    break;

                case 'join':
                    try {
                        $group_id = $data['group_id'] ?? 0;
                        if (empty($group_id)) {
                            response_error('群聊ID不能为空');
                        }
                        error_log("[API-PC] 入群请求: group_id=$group_id, user_id=$current_user_id");
                        
                        $stmt = $conn->prepare("SELECT * FROM group_members WHERE group_id = ? AND user_id = ?");
                        $stmt->execute([$group_id, $current_user_id]);
                        if ($stmt->rowCount() > 0) {
                            response_error('您已加入该群');
                        }
                        
                        $stmt = $conn->prepare("SELECT * FROM group_join_requests WHERE group_id = ? AND user_id = ? AND status = 'pending'");
                        $stmt->execute([$group_id, $current_user_id]);
                        if ($stmt->rowCount() > 0) {
                            response_error('您已发送入群请求，请等待管理员审核');
                        }
                        
                        $stmt = $conn->prepare("INSERT INTO group_join_requests (group_id, user_id, status, created_at) VALUES (?, ?, 'pending', NOW())");
                        if ($stmt->execute([$group_id, $current_user_id])) {
                            error_log("[API-PC] 入群请求已发送: group_id=$group_id, user_id=$current_user_id");
                            response_success([], '入群请求已发送，请等待管理员审核');
                        } else {
                            response_error('发送入群请求失败');
                        }
                    } catch (Exception $e) {
                        error_log("[API-PC] 入群请求失败: " . $e->getMessage());
                        response_error('发送入群请求失败: ' . $e->getMessage(), 500);
                    }
                    break;

                case 'join_requests':
                    $group_id = $data['group_id'] ?? 0;
                    if (empty($group_id)) {
                        response_error('群聊ID不能为空');
                    }
                    $list = $group->getJoinRequests($group_id);
                    response_success($list);
                    break;

                case 'approve_join_request':
                    $group_id = $data['group_id'] ?? 0;
                    $request_id = $data['request_id'] ?? 0;
                    if (empty($group_id) || empty($request_id)) {
                        response_error('参数不完整');
                    }
                    $result = $group->approveJoinRequest($request_id, $group_id, $current_user_id);
                    if ($result['success']) {
                        response_success([], $result['message']);
                    } else {
                        response_error($result['message']);
                    }
                    break;

                case 'reject_join_request':
                    $group_id = $data['group_id'] ?? 0;
                    $request_id = $data['request_id'] ?? 0;
                    if (empty($group_id) || empty($request_id)) {
                        response_error('参数不完整');
                    }
                    $result = $group->rejectJoinRequest($request_id, $group_id, $current_user_id);
                    if ($result['success']) {
                        response_success([], $result['message']);
                    } else {
                        response_error($result['message']);
                    }
                    break;
                    
                case 'mark_read':
                    // 标记群消息为已读
                    $group_id = $data['group_id'] ?? 0;
                    if (empty($group_id)) response_error('群聊ID不能为空');
                    
                    // 清除未读计数
                    $stmt = $conn->prepare("UPDATE unread_messages SET count = 0 WHERE user_id = ? AND chat_type = 'group' AND chat_id = ?");
                    $stmt->execute([$current_user_id, $group_id]);
                    
                    response_success([], '已标记为已读');
                    break;
                    
                case 'update_name':
                    // 修改群名称（仅群主可用）
                    $group_id = $data['group_id'] ?? 0;
                    $name = trim($data['name'] ?? '');
                    
                    if (empty($group_id) || empty($name)) {
                        response_error('参数不完整');
                    }
                    
                    // 检查是否是群主
                    $group_info = $group->getGroupInfo($group_id);
                    if (!$group_info) {
                        response_error('群聊不存在');
                    }
                    
                    if ($group_info['owner_id'] != $current_user_id) {
                        response_error('只有群主可以修改群名称');
                    }
                    
                    $stmt = $conn->prepare("UPDATE groups SET name = ? WHERE id = ?");
                    $stmt->execute([$name, $group_id]);
                    
                    response_success([], '群名称修改成功');
                    break;
                    
                case 'delete':
                    // 解散群聊（仅群主可用）
                    $group_id = $data['group_id'] ?? 0;
                    if (empty($group_id)) response_error('群聊ID不能为空');
                    
                    // 检查是否是群主
                    $group_info = $group->getGroupInfo($group_id);
                    if (!$group_info) {
                        response_error('群聊不存在');
                    }
                    
                    if ($group_info['owner_id'] != $current_user_id) {
                        response_error('只有群主可以解散群聊');
                    }
                    
                    $result = $group->deleteGroup($group_id, $current_user_id);
                    if ($result) {
                        response_success([], '群聊已解散');
                    } else {
                        response_error('解散群聊失败');
                    }
                    break;
                    
                case 'invite':
                    // 邀请好友加入群聊
                    $group_id = $data['group_id'] ?? 0;
                    $friend_id = $data['friend_id'] ?? 0;
                    
                    if (empty($group_id) || empty($friend_id)) {
                        response_error('参数不完整');
                    }
                    
                    // 检查当前用户是否是群成员
                    if (!$group->isUserInGroup($group_id, $current_user_id)) {
                        response_error('您不是该群聊的成员');
                    }
                    
                    $result = $group->inviteFriendToGroup($group_id, $current_user_id, $friend_id);
                    if ($result) {
                        response_success([], '邀请已发送');
                    } else {
                        response_error('发送邀请失败');
                    }
                    break;

                default:
                    response_error('参数不存在', 404);
            }
            break;

        // ------------------------------------------
        // 文件访问代理 (File - 用于图片/封面等，走 API 域名避免小程序域名限制)
        // ------------------------------------------
        case 'file':
            if ($action === 'get') {
                $path = trim($data['path'] ?? $_GET['path'] ?? '');
                if (empty($path)) {
                    response_error('缺少 path 参数', 400);
                }
                $path = str_replace('\\', '/', $path);
                if (strpos($path, '..') !== false) {
                    response_error('无效的路径', 400);
                }
                
                // 支持多种路径格式
                $full_path = '';
                
                // 检查路径是否已包含 uploads/
                if (preg_match('#^uploads/#', $path)) {
                    $full_path = $base_dir . '/' . $path;
                } else {
                    // 尝试在 uploads/ 目录下查找
                    $full_path = $base_dir . '/uploads/' . $path;
                }
                
                // 如果文件不存在，尝试其他可能的路径
                if (!file_exists($full_path) || !is_file($full_path)) {
                    // 尝试检查 avatars/ 目录（旧版本可能存储在那里）
                    $alternative_path = $base_dir . '/avatars/' . basename($path);
                    if (file_exists($alternative_path) && is_file($alternative_path)) {
                        $full_path = $alternative_path;
                    } else {
                        // 尝试检查 uploads/avatars/ 目录
                        $avatars_path = $base_dir . '/uploads/avatars/' . basename($path);
                        if (file_exists($avatars_path) && is_file($avatars_path)) {
                            $full_path = $avatars_path;
                        } else {
                            // 尝试检查原始文件名（不带 uploads/ 前缀的情况）
                            $original_path = $base_dir . '/' . basename($path);
                            if (file_exists($original_path) && is_file($original_path)) {
                                $full_path = $original_path;
                            }
                        }
                    }
                }
                
                // 如果仍然找不到文件，返回 404
                if (!file_exists($full_path) || !is_file($full_path)) {
                    http_response_code(404);
                    exit;
                }
                
                $mimes = [
                    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                    'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
                    'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'video/ogg',
                    'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'pdf' => 'application/pdf'
                ];
                $ext = strtolower(pathinfo($full_path, PATHINFO_EXTENSION));
                $ctype = $mimes[$ext] ?? 'application/octet-stream';
                header('Content-Type: ' . $ctype);
                header('Cache-Control: public, max-age=86400');
                readfile($full_path);
                exit;
            }
            response_error("File 模块不支持操作: $action", 404);
            break;

        // ------------------------------------------
        // 文件上传模块 (Upload)
        // ------------------------------------------
        case 'upload':
            $current_user_id = check_auth();
            
            if (!isset($_FILES['file'])) {
                response_error('请选择要上传的文件');
            }
            
            $file = $_FILES['file'];
            $original_name = $file['name'];
            $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $base_name = pathinfo($original_name, PATHINFO_FILENAME);
            
            $dangerous_extensions = ['php', 'php1', 'php2', 'php3', 'php4', 'php5', 'phtml', 'xml', 'html', 'htm', 'asp', 'aspx', 'jsp', 'jspx', 'cgi', 'exe', 'bat', 'sh', 'cmd'];
            
            if (in_array($file_ext, $dangerous_extensions)) {
                $new_name = $base_name . '.1';
                $_FILES['file']['name'] = $new_name;
            }
            
            $invalid_pattern = '/[<>:"|?*\\\\\\/\\x00-\\x1f]/';
            if (preg_match($invalid_pattern, $base_name) || preg_match($invalid_pattern, $file_ext)) {
                response_error('非法文件，无法上传', 400);
            }
            
            $upload_result = $fileUpload->upload($_FILES['file'], $current_user_id);
            if ($upload_result['success']) {
                if (!empty($upload_result['is_duplicate'])) {
                    response_success($upload_result, '文件重复，已秒传！');
                } else {
                    response_success($upload_result, '文件上传成功');
                }
            } else {
                response_error($upload_result['message']);
            }
            break;
            
        // ------------------------------------------
        // 头像上传模块 (Avatar)
        // ------------------------------------------
        case 'avatar':
            $current_user_id = check_auth();

            if (!isset($_FILES['avatar'])) {
                response_error('请选择头像文件');
            }

            $file = $_FILES['avatar'];

            // 定义允许的图片扩展名白名单
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            // 获取并清理文件扩展名（转小写）
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            // 验证文件扩展名是否在白名单中
            if (!in_array($file_ext, $allowed_extensions)) {
                response_error('只允许上传图片文件（jpg, jpeg, png, gif, webp）');
            }

            // 验证文件类型
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            // 检查 fileinfo 扩展是否可用
            if (!function_exists('finfo_open')) {
                response_error('服务器缺少 fileinfo 扩展，无法验证文件类型');
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if (!$finfo) {
                response_error('无法创建文件信息对象');
            }

            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime_type, $allowed_types)) {
                response_error('只支持 JPG、PNG、GIF、WEBP 格式的图片');
            }

            // 额外的文件头验证（防止MIME类型伪造）
            $file_info = getimagesize($file['tmp_name']);
            if (!$file_info || !in_array($file_info['mime'], $allowed_types)) {
                response_error('文件类型验证失败');
            }

            // 验证文件大小 (最大 2MB)
            if ($file['size'] > 2 * 1024 * 1024) {
                response_error('头像大小不能超过 2MB');
            }

            // 生成文件名 - 使用强制安全的扩展名（从MIME类型映射）
            $ext_map = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp'
            ];
            $safe_ext = $ext_map[$mime_type];
            $avatar_name = 'avatar_' . $current_user_id . '_' . time() . '.' . $safe_ext;
            $avatar_path = UPLOAD_DIR . $avatar_name;

            // 确保上传目录存在
            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0755, true);
            }

            // 移动文件
            if (!move_uploaded_file($file['tmp_name'], $avatar_path)) {
                response_error('头像上传失败');
            }

            // 更新用户头像
            $stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
            $stmt->execute([$avatar_path, $current_user_id]);

            response_success(['avatar' => $avatar_path], '头像上传成功');
            break;
            
        // ------------------------------------------
        // 会话模块 (Sessions)
        // ------------------------------------------
        case 'sessions':
            $current_user_id = check_auth();
            
            switch ($action) {
                case 'list':
                    // 获取会话列表
                    $sessions = $message->getSessions($current_user_id);
                    response_success($sessions);
                    break;
                    
                case 'clear_unread':
                    // 清除未读计数
                    $session_id = $data['session_id'] ?? 0;
                    if (empty($session_id)) response_error('会话ID不能为空');
                    
                    $message->clearUnreadCount($session_id);
                    response_success([], '未读计数已清除');
                    break;
                    
                case 'pin':
                    // 置顶会话
                    $session_id = $data['session_id'] ?? 0;
                    if (empty($session_id)) response_error('会话ID不能为空');
                    
                    $message->pinSession($session_id);
                    response_success([], '会话已置顶');
                    break;
                    
                case 'unpin':
                    // 取消置顶会话
                    $session_id = $data['session_id'] ?? 0;
                    if (empty($session_id)) response_error('会话ID不能为空');
                    
                    $message->unpinSession($session_id);
                    response_success([], '已取消置顶');
                    break;
                    
                default:
                    response_error('参数不存在', 404);
            }
            break;
        
        // ------------------------------------------
        // 系统公告模块 (Announcements)
        // ------------------------------------------
        case 'announcements':
            switch ($action) {
                case 'get':
                    // 获取最新公告（无需登录也可访问，但已读状态需要登录）
                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }
                    $user_id = $_SESSION['user_id'] ?? null;
                    
                    // 获取最新的活跃公告
                    $stmt = $conn->prepare("SELECT a.*, u.username as admin_name FROM announcements a 
                                         JOIN users u ON a.admin_id = u.id 
                                         WHERE a.is_active = TRUE 
                                         ORDER BY a.created_at DESC 
                                         LIMIT 1");
                    $stmt->execute();
                    $announcement = $stmt->fetch();
                    
                    if (!$announcement) {
                        response_success(['has_new_announcement' => false]);
                    }
                    
                    $has_read = false;
                    
                    // 检查用户是否已经读过该公告
                    if ($user_id) {
                        $stmt = $conn->prepare("SELECT id FROM user_announcement_read WHERE user_id = ? AND announcement_id = ?");
                        $stmt->execute([$user_id, $announcement['id']]);
                        $has_read = $stmt->fetch() !== false;
                    }
                    
                    response_success([
                        'has_new_announcement' => true,
                        'announcement' => [
                            'id' => $announcement['id'],
                            'title' => $announcement['title'],
                            'content' => $announcement['content'],
                            'created_at' => $announcement['created_at'],
                            'admin_name' => $announcement['admin_name']
                        ],
                        'has_read' => $has_read
                    ]);
                    break;
                    
                case 'mark_read':
                    // 标记公告为已读
                    $current_user_id = check_auth();
                    $announcement_id = $data['announcement_id'] ?? 0;
                    
                    if (empty($announcement_id)) {
                        response_error('公告ID不能为空');
                    }
                    
                    // 检查是否已读
                    $stmt = $conn->prepare("SELECT id FROM user_announcement_read WHERE user_id = ? AND announcement_id = ?");
                    $stmt->execute([$current_user_id, $announcement_id]);
                    
                    if (!$stmt->fetch()) {
                        // 标记为已读
                        $stmt = $conn->prepare("INSERT INTO user_announcement_read (user_id, announcement_id, read_at) VALUES (?, ?, NOW())");
                        $stmt->execute([$current_user_id, $announcement_id]);
                    }
                    
                    response_success([], '已标记为已读');
                    break;
                    
                default:
                    response_error('参数不存在', 404);
            }
            break;
        
        // ------------------------------------------
        // 扫码登录模块 (Scan Login)
        // ------------------------------------------
        case 'scan_login':
            switch ($action) {
                case 'confirm':
                    // 确认扫码登录
                    $qid = $data['qid'] ?? '';
                    $user_id = $data['user_id'] ?? 0;
                    $username = $data['user'] ?? '';
                    
                    if (empty($qid)) {
                        response_error('登录标识不能为空');
                    }
                    
                    // 如果传递的是用户名，先查询用户ID
                    if (empty($user_id) && !empty($username)) {
                        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                        $stmt->execute([$username]);
                        $user_data = $stmt->fetch();
                        if ($user_data) {
                            $user_id = $user_data['id'];
                        }
                    }
                    
                    if (empty($user_id)) {
                        response_error('用户信息无效');
                    }
                    
                    // 检查qid是否存在且未过期
                    $stmt = $conn->prepare("SELECT * FROM scan_login WHERE qid = ? AND expire_at > NOW() AND status IN ('pending', 'scanned')");
                    $stmt->execute([$qid]);
                    $token_data = $stmt->fetch();
                    
                    if (!$token_data) {
                        response_error('登录二维码无效或已过期');
                    }
                    
                    // 更新状态为成功
                    $stmt = $conn->prepare("UPDATE scan_login SET status = 'success', user_id = ? WHERE qid = ?");
                    $stmt->execute([$user_id, $qid]);
                    
                    response_success([], '登录确认成功');
                    break;
                    
                case 'status':
                    // 查询扫码登录状态（PC端轮询）
                    $qid = $data['qid'] ?? '';
                    
                    if (empty($qid)) {
                        response_error('登录标识不能为空');
                    }
                    
                    $stmt = $conn->prepare("SELECT s.*, u.username, u.email, u.avatar FROM scan_login s LEFT JOIN users u ON s.user_id = u.id WHERE s.qid = ?");
                    $stmt->execute([$qid]);
                    $token_data = $stmt->fetch();
                    
                    if (!$token_data) {
                        response_error('登录标识无效');
                    }
                    
                    if ($token_data['expire_at'] < date('Y-m-d H:i:s')) {
                        response_error('登录二维码已过期');
                    }
                    
                    if ($token_data['status'] === 'success') {
                        // 登录成功，设置session
                        if (session_status() === PHP_SESSION_NONE) {
                            session_start();
                        }
                        $_SESSION['user_id'] = $token_data['user_id'];
                        $_SESSION['username'] = $token_data['username'];
                        
                        // 更新状态为已使用
                        $stmt = $conn->prepare("UPDATE scan_login SET status = 'used' WHERE qid = ?");
                        $stmt->execute([$qid]);
                        
                        response_success([
                            'status' => 'success',
                            'user' => [
                                'id' => $token_data['user_id'],
                                'username' => $token_data['username'],
                                'email' => $token_data['email'],
                                'avatar' => $token_data['avatar']
                            ]
                        ]);
                    } else {
                        response_success([
                            'status' => $token_data['status']
                        ]);
                    }
                    break;
                    
                case 'scan':
                    // APP扫描二维码，更新状态为已扫描
                    $qid = $data['qid'] ?? '';
                    
                    if (empty($qid)) {
                        response_error('登录标识不能为空');
                    }
                    
                    // 检查qid是否存在且未过期
                    $stmt = $conn->prepare("SELECT * FROM scan_login WHERE qid = ? AND expire_at > NOW()");
                    $stmt->execute([$qid]);
                    $token_data = $stmt->fetch();
                    
                    if (!$token_data) {
                        response_error('登录二维码无效或已过期');
                    }
                    
                    // 更新为已扫描状态
                    $stmt = $conn->prepare("UPDATE scan_login SET status = 'scanned' WHERE qid = ? AND status = 'pending'");
                    $stmt->execute([$qid]);
                    
                    response_success([], '扫描成功');
                    break;
                    
                case 'reject':
                    // APP拒绝登录
                    $qid = $data['qid'] ?? '';
                    
                    if (empty($qid)) {
                        response_error('登录标识不能为空');
                    }
                    
                    // 更新为已拒绝状态
                    $stmt = $conn->prepare("UPDATE scan_login SET status = 'rejected' WHERE qid = ?");
                    $stmt->execute([$qid]);
                    
                    response_success([], '已拒绝登录');
                    break;
                    
                case 'get_ip':
                    // 获取扫码登录的IP地址
                    $qid = $data['qid'] ?? '';
                    
                    if (empty($qid)) {
                        response_error('登录标识不能为空');
                    }
                    
                    $stmt = $conn->prepare("SELECT ip_address FROM scan_login WHERE qid = ?");
                    $stmt->execute([$qid]);
                    $token_data = $stmt->fetch();
                    
                    if (!$token_data) {
                        response_error('登录标识无效');
                    }
                    
                    response_success([
                        'ip_address' => $token_data['ip_address'] ?? '未知'
                    ]);
                    break;
                    
                case 'generate':
                    // 生成登录二维码（PC端调用）
                    $qid = uniqid('scan_', true) . rand(1000, 9999);
                    $token = bin2hex(random_bytes(32));
                    $expire_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
                    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '未知';
                    
                    $stmt = $conn->prepare("INSERT INTO scan_login (qid, token, expire_at, status, ip_address, created_at) VALUES (?, ?, ?, 'pending', ?, NOW())");
                    $stmt->execute([$qid, $token, $expire_at, $ip_address]);
                    
                    response_success([
                        'token' => $token,
                        'qid' => $qid,
                        'expires_at' => $expire_at,
                        'qr_url' => 'https://chat.hyacine.com.cn/chat/scan_login.php?qid=' . $qid
                    ]);
                    break;
                    
                default:
                    response_error('参数不存在', 404);
            }
            break;
        
        // ------------------------------------------
        // 短信验证模块 (SMS)
        // ------------------------------------------
        case 'sms':
            switch ($action) {
                case 'send':
                    $phone = trim($data['phone'] ?? '');
                    
                    if (empty($phone) || !preg_match('/^1[3-9]\d{9}$/', $phone)) {
                        response_error('请输入有效的手机号');
                    }
                    
                    if (isset($_SESSION['last_sms_time']) && (time() - $_SESSION['last_sms_time'] < 60)) {
                        $seconds_left = 60 - (time() - $_SESSION['last_sms_time']);
                        response_error("请等待{$seconds_left}秒后再试");
                    }
                    
                    require_once $base_dir . '/includes/AliSmsClient.php';
                    
                    $accessKeyId = getConfig('ali_sms_access_key_id', '');
                    $accessKeySecret = getConfig('ali_sms_access_key_secret', '');
                    
                    if (empty($accessKeyId) || empty($accessKeySecret)) {
                        response_error('短信服务未配置，请联系管理员');
                    }
                    
                    $smsClient = new AliSmsClient($accessKeyId, $accessKeySecret);
                    $code = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                    
                    $result = $smsClient->sendVerifyCode($phone, 300, $code);
                    
                    if ($result['success']) {
                        $_SESSION['sms_code'] = $code;
                        $_SESSION['sms_phone'] = $phone;
                        $_SESSION['sms_expire'] = time() + 300;
                        $_SESSION['last_sms_time'] = time();
                        
                        response_success([], '验证码已发送，请在5分钟内输入');
                    } else {
                        response_error('发送失败: ' . ($result['error'] ?? '未知错误'));
                    }
                    break;
                    
                case 'verify':
                    $phone = trim($data['phone'] ?? '');
                    $code = trim($data['code'] ?? '');
                    
                    if (empty($phone) || !preg_match('/^1[3-9]\d{9}$/', $phone)) {
                        response_error('请输入有效的手机号');
                    }
                    
                    if (empty($code)) {
                        response_error('请输入验证码');
                    }
                    
                    if (!isset($_SESSION['sms_code']) || !isset($_SESSION['sms_phone']) || !isset($_SESSION['sms_expire'])) {
                        response_error('验证码已过期，请重新获取');
                    }
                    
                    if ($_SESSION['sms_phone'] !== $phone) {
                        response_error('手机号与接收验证码的手机号不一致');
                    }
                    
                    if (time() > $_SESSION['sms_expire']) {
                        response_error('验证码已过期，请重新获取');
                    }
                    
                    if ($_SESSION['sms_code'] !== $code) {
                        response_error('验证码错误');
                    }
                    
                    $_SESSION['sms_verified'] = true;
                    $_SESSION['sms_verified_phone'] = $phone;
                    $_SESSION['sms_verified_time'] = time();
                    
                    response_success([], '验证成功');
                    break;
                    
                default:
                    response_error('参数不存在', 404);
            }
            break;
        
        // ------------------------------------------
        // 音乐模块 (Music)
        // ------------------------------------------
        case 'music':
            switch ($action) {
                case 'list':
                    // 获取音乐列表（无需登录）
                    $stmt = $conn->prepare("SELECT * FROM music WHERE is_active = TRUE ORDER BY sort_order ASC, created_at DESC");
                    $stmt->execute();
                    $music_list = $stmt->fetchAll();
                    
                    // 移除敏感信息
                    foreach ($music_list as &$music) {
                        unset($music['file_path']);
                    }
                    
                    response_success([
                        'code' => 200,
                        'data' => $music_list
                    ]);
                    break;
                    
                default:
                    response_error('参数不存在', 404);
            }
            break;

        // ------------------------------------------
        // 未读消息计数模块 (Unread)
        // ------------------------------------------
        case 'unread':
            $current_user_id = check_auth();
            
            switch ($action) {
                case 'count':
                    // 获取所有未读消息计数，格式：{chat_id}:{count} 多个用,分隔
                    $stmt = $conn->prepare("SELECT chat_type, chat_id, count FROM unread_messages WHERE user_id = ? AND count > 0");
                    $stmt->execute([$current_user_id]);
                    $unread_list = $stmt->fetchAll();
                    
                    $result = [];
                    foreach ($unread_list as $item) {
                        $result[] = "{$item['chat_type']}_{$item['chat_id']}:{$item['count']}";
                    }
                    
                    response_success([
                        'count' => implode(',', $result)
                    ]);
                    break;
                    
                case 'list':
                    // 获取所有会话的最新消息和未读计数
                    $stmt = $conn->prepare("
                        SELECT 
                            s.id AS session_id,
                            s.chat_type,
                            s.chat_id,
                            COALESCE(u.count, 0) AS unread_count,
                            m.content AS last_message,
                            m.type AS message_type,
                            m.created_at AS last_time,
                            m.sender_id,
                            su.username AS sender_name,
                            su.avatar AS sender_avatar
                        FROM chat_sessions s
                        LEFT JOIN unread_messages u ON s.id = u.session_id AND u.user_id = ?
                        LEFT JOIN messages m ON s.last_message_id = m.id
                        LEFT JOIN users su ON m.sender_id = su.id
                        WHERE s.user_id = ?
                        ORDER BY COALESCE(m.created_at, s.created_at) DESC
                    ");
                    $stmt->execute([$current_user_id, $current_user_id]);
                    $sessions = $stmt->fetchAll();
                    
                    $result = [];
                    foreach ($sessions as $session) {
                        $result[] = [
                            'session_id' => $session['session_id'],
                            'chat_type' => $session['chat_type'],
                            'chat_id' => $session['chat_id'],
                            'unread_count' => (int)$session['unread_count'],
                            'last_message' => $session['last_message'] ?? '',
                            'message_type' => $session['message_type'] ?? 'text',
                            'last_time' => $session['last_time'] ?? '',
                            'sender_id' => (int)$session['sender_id'],
                            'sender_name' => $session['sender_name'] ?? '',
                            'sender_avatar' => $session['sender_avatar'] ?? ''
                        ];
                    }
                    
                    response_success([
                        'sessions' => $result
                    ]);
                    break;
                    
                default:
                    response_error('参数不存在', 404);
            }
            break;

        // ------------------------------------------
        // PC端扫码登录扩展接口
        // ------------------------------------------
        case 'pc_scan':
            switch ($action) {
                // 生成扫码登录二维码（PC端专用）
                case 'generate_qr':
                    $qid = uniqid('scan_', true) . rand(1000, 9999);
                    $token = bin2hex(random_bytes(32));
                    $expire_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
                    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '未知';
                    
                    // 插入扫码登录记录
                    $stmt = $conn->prepare("INSERT INTO scan_login (qid, token, expire_at, status, ip_address, created_at) VALUES (?, ?, ?, 'pending', ?, NOW())");
                    $stmt->execute([$qid, $token, $expire_at, $ip_address]);
                    
                    // 生成二维码内容（指向扫码登录页面）
                    $domain = $_SERVER['HTTP_HOST'];
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                    $qr_content = "$protocol://$domain/chat/scan_login.php?qid=$qid";
                    
                    response_success([
                        'token' => $token,
                        'qid' => $qid,
                        'expires_at' => $expire_at,
                        'qr_content' => $qr_content,
                        'qr_url' => $qr_content
                    ], '二维码生成成功');
                    break;
                    
                // 查询扫码登录状态（PC端专用）
                case 'check_status':
                    $qid = $data['qid'] ?? '';
                    
                    if (empty($qid)) {
                        response_error('登录标识不能为空');
                    }
                    
                    // 查询扫码登录状态
                    $stmt = $conn->prepare("SELECT s.*, u.username, u.email, u.avatar FROM scan_login s LEFT JOIN users u ON s.user_id = u.id WHERE s.qid = ?");
                    $stmt->execute([$qid]);
                    $token_data = $stmt->fetch();
                    
                    if (!$token_data) {
                        response_error('登录标识无效');
                    }
                    
                    // 检查是否过期
                    if (strtotime($token_data['expire_at']) < time()) {
                        // 更新为过期状态
                        $stmt = $conn->prepare("UPDATE scan_login SET status = 'expired' WHERE qid = ?");
                        $stmt->execute([$qid]);
                        response_error('登录二维码已过期');
                    }
                    
                    if ($token_data['status'] === 'success') {
                        // 登录成功，设置session
                        $_SESSION['user_id'] = $token_data['user_id'];
                        $_SESSION['username'] = $token_data['username'];
                        $_SESSION['email'] = $token_data['email'];
                        
                        // 更新状态为已使用
                        $stmt = $conn->prepare("UPDATE scan_login SET status = 'used' WHERE qid = ?");
                        $stmt->execute([$qid]);
                        
                        // 更新用户在线状态
                        $user->updateStatus($token_data['user_id'], 'online');
                        
                        response_success([
                            'status' => 'success',
                            'user' => [
                                'id' => $token_data['user_id'],
                                'username' => $token_data['username'],
                                'email' => $token_data['email'],
                                'avatar' => $token_data['avatar']
                            ]
                        ], '登录成功');
                    } else {
                        response_success([
                            'status' => $token_data['status'],
                            'message' => $token_data['status'] === 'pending' ? '等待扫描' : 
                                       ($token_data['status'] === 'scanned' ? '等待手机确认登录' : 
                                       ($token_data['status'] === 'rejected' ? '手机端拒绝了登录请求' : '等待处理'))
                        ]);
                    }
                    break;
                    
                default:
                    response_error("PC扫码登录模块不支持操作: $action");
            }
            break;

        // ------------------------------------------
        // 默认处理
        // ------------------------------------------
        default:
            error_log("[API-PC] 404错误: 未找到 resource=$resource, action=$action");
            response_error('参数不存在', 404);
    }

} catch (Exception $e) {
    // 捕获未处理的异常，防止敏感信息泄露
    error_log("API-PC 异常: " . $e->getMessage());
    response_error('服务器内部错误', 500);
}

// 关闭数据库连接
$conn = null;
?>