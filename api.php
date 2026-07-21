<?php
/**
 * Modern Chat API
 * 
 * 统一API入口，处理所有客户端请求。
 * 采用 RESTful 风格设计（尽管使用 POST 参数模拟资源和动作）。
 * 支持跨域请求（CORS），并配置了安全的 HTTP 头。
 * 实现了基本的身份验证机制（基于 Session Cookie）。
 * 提供了错误处理机制，返回 JSON 格式错误信息。
 * 支持 JSONP 跨域请求（可选）。
 * 
 * 使用说明:
 * 1. 所有请求均应发送至 api.php
 * 2. 请求方法推荐使用 POST
 * 3. 必须包含 'resource' 和 'action' 参数
 * 4. 数据可以通过 JSON Body 或 POST 表单提交
 * 5. 身份验证基于 Session Cookie
 */

// 开启错误日志，关闭页面错误显示
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'api_error.log');
error_reporting(E_ALL);

// 设置响应头
header('Content-Type: application/json; charset=utf-8');



// 处理 OPTIONS 预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 启动 Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 获取请求数据 (兼容 JSON、Form Data 和 GET 参数)
function get_request_data() {
    $data = [];
    
    // 1. 尝试解析 JSON 数据（优先）
    $input = @file_get_contents('php://input');
    if ($input !== false && !empty($input)) {
        $decoded = json_decode($input, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    
    // 2. 如果 JSON 解析失败或无法获取，降级使用其他方法获取 POST 数据
    if (empty($data)) {
        // 尝试获取原始 POST 数据（某些环境下可能需要特殊处理）
        if (!empty($_POST)) {
            $data = $_POST;
        } else {
            // 尝试其他方式获取 POST 数据
            $raw_post = '';
            if (isset($HTTP_RAW_POST_DATA)) {
                $raw_post = $HTTP_RAW_POST_DATA;
            } elseif (function_exists('http_get_request_body')) {
                $raw_post = http_get_request_body();
            }
            
            if (!empty($raw_post)) {
                $decoded = json_decode($raw_post, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
    }
    
    // 3. 合并 GET 参数（优先级最低）
    if (!empty($_GET)) {
        foreach ($_GET as $key => $value) {
            if (!isset($data[$key])) {
                $data[$key] = $value;
            }
        }
    }
    
    return $data;
}

// 检测是否为状态检测请求（无参数或特定参数）
$request_data = get_request_data();
$resource = $request_data['resource'] ?? $_GET['resource'] ?? '';
$action = $request_data['action'] ?? $_GET['action'] ?? '';

// 检查请求参数
$has_other_params = false;
foreach ($request_data as $key => $value) {
    if ($key !== 'resource' && $key !== 'action') {
        $has_other_params = true;
        break;
    }
}

// APP 版本信息接口（无需登录，手机端检查更新用）
if ($resource === 'version' && ($action === 'app' || $action === '')) {
    $base_dir_for_version = __DIR__;
    $version_file = $base_dir_for_version . '/version-app.json';
    if (is_file($version_file)) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=60');
        echo file_get_contents($version_file);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'version' => '0.7.4',
            'versionCode' => 74,
            'versionName' => '0.7.4',
            'minVersionCode' => 0,
            'note' => '暂无新版本',
            'downloadUrl' => ''
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ═══════════════════════════════════════════════════════
// 辅助函数：为文件消息补充 file_url（走 file 子域名）
// ═══════════════════════════════════════════════════════
function enrich_file_url(&$msg, $conn) {
    // 只处理有 file_path 的消息
    if (empty($msg['file_path'])) return;
    
    // 如果已有完整的 https:// file URL，直接使用
    if (!empty($msg['file_url']) && strpos($msg['file_url'], 'https://') === 0) return;
    
    // 预先获取 vkey（后续构建代理 URL 需要）
    $vkey = null;
    try {
        if (function_exists('get_vkey_by_user_id')) {
            $uid = $_SESSION['user_id'] ?? 0;
            if ($uid > 0) $vkey = get_vkey_by_user_id($uid, $conn);
        }
    } catch (Exception $e) {}
    
    // 如果有 upload_id，通过代理 URL 访问（避免前端 fetch 跨域）
    if (!empty($msg['upload_id'])) {
        $stored_name = $msg['stored_name'] ?? basename($msg['file_path']);
        if ($vkey && function_exists('generate_proxy_url_with_id')) {
            $msg['file_url'] = generate_proxy_url_with_id($msg['upload_id'], $stored_name, $vkey);
        } else {
            $msg['file_url'] = 'https://files.modern-chat.top/list.php?id=' . urlencode($msg['upload_id']) 
                             . '&file=' . urlencode($stored_name);
        }
        return;
    }
    
    // 尝试从 file_uploads 表查找
    try {
        $stmt = $conn->prepare("SELECT upload_id, stored_name FROM file_uploads WHERE file_path = ? LIMIT 1");
        $stmt->execute([$msg['file_path']]);
        $row = $stmt->fetch();
        if ($row) {
            $msg['upload_id'] = $row['upload_id'];
            if ($vkey && function_exists('generate_proxy_url_with_id')) {
                $msg['file_url'] = generate_proxy_url_with_id($row['upload_id'], $row['stored_name'], $vkey);
            } else {
                $msg['file_url'] = 'https://files.modern-chat.top/list.php?id=' . urlencode($row['upload_id']) 
                                 . '&file=' . urlencode($row['stored_name']);
            }
            return;
        }
    } catch (Exception $e) {}
    
    // 回退：通过 generate_proxy_url 构建（走 vkey+token 链路）
    if ($vkey && function_exists('generate_proxy_url')) {
        // 将绝对路径转为相对路径
        $relative_path = $msg['file_path'];
        $possible_bases = [
            realpath(__DIR__ . '/..') . '/',
            realpath(__DIR__) . '/',
            '/www/wwwroot/',
            '/www/files.modern-chat.top/',
        ];
        foreach ($possible_bases as $base) {
            if (strpos($relative_path, $base) === 0) {
                $relative_path = substr($relative_path, strlen($base));
                break;
            }
        }
        $msg['file_url'] = generate_proxy_url(ltrim($relative_path, '/'), $vkey);
    }
}

// 如果没有 resource 参数，返回 API 状态信息
if (empty($resource)) {
    echo json_encode([
        'success' => true,
        'message' => 'Modern Chat API 已正常运行',
        'version' => '2.2',
        'timestamp' => date('Y-m-d H:i:s'),
        'endpoints' => [
            'auth' => ['login', 'register', 'logout', 'check_status', 'get_public_key'],
            'user' => ['get_info', 'update_info', 'search', 'update_password', 'delete_account'],
            'friends' => ['list', 'send_request', 'delete', 'get_requests', 'accept_request', 'reject_request'],
            'messages' => ['history', 'send', 'send_file', 'recall', 'mark_read', 'get_unread', 'delete'],
            'groups' => ['list', 'info', 'create', 'members', 'add_members', 'messages', 'send_message', 'send_file', 'recall', 'leave', 'remove_member', 'set_admin', 'transfer', 'mark_read', 'update_name', 'delete', 'invite', 'delete_message', 'pin', 'unpin'],
            'sessions' => ['list', 'clear_unread', 'pin', 'unpin'],
            'upload' => ['file'],
            'file' => ['get'],
            'avatar' => ['upload'],
            'announcements' => ['get', 'mark_read'],
            'scan_login' => ['confirm', 'status', 'generate', 'scan', 'reject', 'get_ip'],
            'sms' => ['send', 'verify'],
            'music' => ['list'],
            'vkey' => ['generate', 'get']
        ],
        'usage' => 'POST/GET with resource and action parameters'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 如果选择了板块但是没有指定操作
if (!empty($resource) && empty($action)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '未指定的操作',
        'resource' => $resource
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 允许不需要额外参数的接口列表
$allow_no_params = [
    'friends/list',
    'friends/get_requests',
    'groups/list',
    'auth/logout',
    'auth/check_status',
    'sessions/list',
    'announcements/get'
];

$request_key = $resource . '/' . $action;

// 如果都指定了但是没有传递参数，且不在允许列表中
if (!empty($resource) && !empty($action) && !$has_other_params && !in_array($request_key, $allow_no_params)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => '未传递参数',
        'resource' => $resource,
        'action' => $action
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
function response_error($message = '操作失败', $code = 400) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);
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

// ==========================================
// 请求处理逻辑
// ==========================================

try {
    // 获取业务数据
    // 支持两种格式：
    // 1. PC客户端格式：{resource, action, data: {...}}
    // 2. 手机端APP格式：{resource, action, ...data}
    if (isset($request_data['data']) && is_array($request_data['data'])) {
        // PC客户端格式：数据在 data 字段中
        $data = $request_data['data'];
    } else {
        // 手机端APP格式：数据直接在根级别，排除 resource 和 action
        $data = $request_data;
        unset($data['resource'], $data['action']);
    }
    
    // 全局参数
    $id = $data['id'] ?? null;

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
                        
                        // 确保用户有 vkey（用于 list.php 文件访问认证）
                        $vkey = $result['user']['vkey'] ?? null;
                        if (empty($vkey)) {
                            $vkey = bin2hex(random_bytes(32));
                            $stmt = $conn->prepare("UPDATE users SET vkey = ? WHERE id = ?");
                            $stmt->execute([$vkey, $result['user']['id']]);
                        }
                        $result['user']['vkey'] = $vkey;
                        
                        // 移除敏感信息（vkey 现在需要返回给客户端，已从列表中移除）
                        $sensitive_fields = [
                            'password',
                            'security_question',
                            'security_answer',
                            'reset_token',
                            'reset_token_expires',
                            'key',           // 认证令牌
                            'token',         // 认证令牌
                            'api_key',       // API密钥
                            'access_token',  // 访问令牌
                            'refresh_token', // 刷新令牌
                            'secret',        // 密钥
                            'salt',          // 盐值
                        ];
                        
                        foreach ($sensitive_fields as $field) {
                            if (isset($result['user'][$field])) {
                                unset($result['user'][$field]);
                            }
                        }
                        
                        // 更新状态为在线
                        $user->updateStatus($result['user']['id'], 'online');
                        
                        response_success($result['user'], '登录成功');
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
                    response_error("Auth 模块不支持操作: $action");
            }
            break;

        // ------------------------------------------
        // 用户模块 (User)
        // ------------------------------------------
        case 'user':
            $current_user_id = check_auth();
            
            switch ($action) {
                case 'get_info':
                    // 只能获取当前登录用户自己的信息，禁止通过 user_id 参数查询他人信息
                    $target_user_id = $current_user_id;
                    $user_info = $user->getUserById($target_user_id);
                    
                    if ($user_info) {
                        // 处理头像URL
                        if (!empty($user_info['avatar']) && strpos($user_info['avatar'], 'http') !== 0) {
                            $user_info['avatar'] = generate_file_url($user_info['avatar'], $_SESSION['vkey'] ?? '');
                        }
                        
                        // 移除敏感信息（扩展敏感字段列表）
                        $sensitive_fields = [
                            'password',
                            'security_question',
                            'security_answer',
                            'reset_token',
                            'reset_token_expires',
                            'vkey',          // 认证密钥
                            'key',           // 认证令牌
                            'token',         // 认证令牌
                            'api_key',       // API密钥
                            'access_token',  // 访问令牌
                            'refresh_token', // 刷新令牌
                            'secret',        // 密钥
                            'salt',          // 盐值
                        ];
                        
                        foreach ($sensitive_fields as $field) {
                            if (isset($user_info[$field])) {
                                unset($user_info[$field]);
                            }
                        }
                        
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
                    $keyword = trim($data['q'] ?? '');
                    if (empty($keyword)) {
                        response_error('搜索关键词不能为空');
                    }
                    $users = $user->searchUsers($keyword, $current_user_id);
                    foreach ($users as &$user_item) {
                        if (!empty($user_item['avatar']) && strpos($user_item['avatar'], 'http') !== 0) {
                            $user_item['avatar'] = generate_file_url($user_item['avatar'], $_SESSION['vkey'] ?? '');
                        }
                    }
                    response_success($users);
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
                    response_error("User 模块不支持操作: $action");
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
                    foreach ($friends as &$friend_item) {
                        if (!empty($friend_item['avatar']) && strpos($friend_item['avatar'], 'http') !== 0) {
                            $friend_item['avatar'] = generate_file_url($friend_item['avatar'], $_SESSION['vkey'] ?? '');
                        }
                    }
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
                    response_error("Friends 模块不支持操作: $action");
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
                    // 为文件消息补充 file_url
                    if (is_array($messages)) {
                        foreach ($messages as &$m) { enrich_file_url($m, $conn); }
                    }
                    response_success($messages);
                    break;
                    
                case 'send':
                    $receiver_id = $data['receiver_id'] ?? 0;
                    $content = trim($data['content'] ?? '');
                    
                    if (empty($receiver_id)) response_error('接收者ID不能为空');
                    if (empty($content)) response_error('消息内容不能为空');
                    
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
                    $upload_id = $data['upload_id'] ?? null;
                    $file_url  = $data['file_url']  ?? null;
                    $audio_duration = (int)($data['audio_duration'] ?? 0);
                    
                    if (empty($receiver_id)) response_error('接收者ID不能为空');
                    
                    // 新上传流程：通过 uploads.php 上传的文件
                    if (!empty($upload_id) && !empty($file_url)) {
                        if (empty($file_name)) response_error('文件名不能为空');
                        $result = $message->sendFileMessage($current_user_id, $receiver_id, $file_url, $file_name, (int)$file_size, $file_type, $audio_duration, $upload_id, $file_url);
                    } elseif (!empty($file_path)) {
                        // 旧版兼容：直接传 file_path
                        $result = $message->sendFileMessage($current_user_id, $receiver_id, $file_path, $file_name, $file_size, $file_type, $audio_duration);
                    } else {
                        response_error('文件信息不能为空');
                    }
                    
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
                        
                        // 清除未读计数
                        $stmt = $conn->prepare("UPDATE unread_messages SET count = 0 WHERE user_id = ? AND chat_type = 'friend' AND chat_id = ?");
                        $stmt->execute([$current_user_id, $friend_id]);
                    }
                    
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
                        // 为文件消息补充 file_url
                        enrich_file_url($processed_msg, $conn);
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
                    
                default:
                    response_error("Messages 模块不支持操作: $action");
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
                    foreach ($members as &$member) {
                        if (!empty($member['avatar']) && strpos($member['avatar'], 'http') !== 0) {
                            $member['avatar'] = generate_file_url($member['avatar'], $_SESSION['vkey'] ?? '');
                        }
                    }
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
                    // 为文件消息补充 file_url
                    if (is_array($messages)) {
                        foreach ($messages as &$m) { enrich_file_url($m, $conn); }
                    }
                    response_success($messages);
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
                    response_error("Groups 模块不支持操作: $action");
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
                $path = ltrim($path, '/');

                $blocked_extensions = ['php', 'php3', 'php4', 'php5', 'phtml', 'pht', 'inc', 'json', 'xml', 'ini', 'conf', 'env', 'htaccess', 'sql', 'log', 'htpasswd', 'git'];
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, $blocked_extensions)) {
                    response_error('禁止访问该类型文件', 403);
                }

                $allowed_prefixes = ['uploads/', 'avatars/', 'new_music/', 'download/'];
                $is_allowed = false;
                foreach ($allowed_prefixes as $prefix) {
                    if (strpos($path, $prefix) === 0) {
                        $is_allowed = true;
                        break;
                    }
                }
                if (!$is_allowed) {
                    $path = 'uploads/' . $path;
                }

                $full = $base_dir . '/' . $path;
                if (!file_exists($full) || !is_file($full)) {
                    http_response_code(404);
                    exit;
                }

                $real_path = realpath($full);
                $allowed_base = realpath($base_dir);
                if (!$real_path || strpos($real_path, $allowed_base) !== 0) {
                    response_error('禁止访问该文件', 403);
                }

                // 获取当前用户的 vkey
                $user_id = $_SESSION['user_id'] ?? 0;
                if ($user_id <= 0) {
                    response_error('未登录', 401);
                }
                $vkey = get_vkey_by_user_id($user_id, $conn);
                if (!$vkey) {
                    response_error('请先生成访问密钥 (vkey)', 403);
                }

                // 生成签名 URL 并 302 重定向
                $url = generate_file_url($path, $vkey);
                header('Location: ' . $url, true, 302);
                exit;
            }
            
            // file/token: 获取签名 token（返回 JSON，供前端 FileHelper 使用）
            if ($action === 'token') {
                $path = trim($data['path'] ?? $_GET['path'] ?? '');
                if (empty($path)) {
                    response_error('缺少 path 参数', 400);
                }
                $path = str_replace('\\', '/', $path);
                if (strpos($path, '..') !== false) {
                    response_error('无效的路径', 400);
                }
                $path = ltrim($path, '/');
                
                $blocked_extensions = ['php', 'php3', 'php4', 'php5', 'phtml', 'pht', 'inc', 'json', 'xml', 'ini', 'conf', 'env', 'htaccess', 'sql', 'log', 'htpasswd', 'git'];
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, $blocked_extensions)) {
                    response_error('禁止访问该类型文件', 403);
                }

                $allowed_prefixes = ['uploads/', 'avatars/', 'new_music/', 'download/'];
                $is_allowed = false;
                foreach ($allowed_prefixes as $prefix) {
                    if (strpos($path, $prefix) === 0) {
                        $is_allowed = true;
                        break;
                    }
                }
                if (!$is_allowed) {
                    $path = 'uploads/' . $path;
                }
                
                $user_id = $_SESSION['user_id'] ?? 0;
                if ($user_id <= 0) {
                    response_error('未登录', 401);
                }
                $vkey = get_vkey_by_user_id($user_id, $conn);
                if (!$vkey) {
                    response_error('请先生成访问密钥 (vkey)', 403);
                }
                
                $url = generate_proxy_url($path, $vkey);
                response_success([
                    'url' => $url,
                    'expires_in' => FILE_TOKEN_TTL
                ]);
            }
            response_error("File 模块不支持操作: $action", 404);
            break;

        // ------------------------------------------
        // 文件上传模块 (Upload)
        //   - upload/token: 获取上传令牌（供前端上传到 uploads.php）
        //   - upload/file:  旧版直接上传（保留兼容）
        // ------------------------------------------
        case 'upload':
            $current_user_id = check_auth();
            $action = $data['action'] ?? 'file';
            
            // upload/token: 生成上传令牌
            if ($action === 'token') {
                $vkey = get_vkey_by_user_id($current_user_id, $conn);
                if (!$vkey) {
                    response_error('请先生成访问密钥 (vkey)', 403);
                }
                $upload_id = uniqid('', true) . '_' . time();
                $auth = generate_upload_token($current_user_id, $vkey, $upload_id);
                response_success($auth, '上传令牌已生成');
            }
            
            // upload/file: 旧版直接上传（保留兼容）
            if (!isset($_FILES['file'])) {
                response_error('请选择要上传的文件');
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
                    
                    $message->pinSession($session_id, $current_user_id);
                    response_success([], '会话已置顶');
                    break;
                    
                case 'unpin':
                    // 取消置顶
                    $session_id = $data['session_id'] ?? 0;
                    if (empty($session_id)) response_error('会话ID不能为空');
                    
                    $message->unpinSession($session_id, $current_user_id);
                    response_success([], '已取消置顶');
                    break;
                    
                default:
                    response_error("Sessions 模块不支持操作: $action");
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
                    response_error("Announcements 模块不支持操作: $action");
            }
            break;
        
        // ------------------------------------------
        // vkey 密钥管理模块 (VKey)
        // ------------------------------------------
        case 'vkey':
            // 必须登录才能操作 vkey，且只能操作自己的 vkey
            $current_user_id = check_auth();
            
            switch ($action) {
                case 'generate':
                    // 强制只能为当前登录用户生成 vkey，禁止传入 user_id 指定他人
                    $user_id = $current_user_id;
                    
                    $stmt = $conn->prepare("SELECT vkey FROM users WHERE id = ? AND is_deleted = FALSE");
                    $stmt->execute([$user_id]);
                    $existing = $stmt->fetch();
                    
                    if ($existing && !empty($existing['vkey'])) {
                        response_success(['vkey' => $existing['vkey']], '密钥已存在');
                    }
                    
                    $vkey = bin2hex(random_bytes(32));
                    $stmt = $conn->prepare("UPDATE users SET vkey = ? WHERE id = ?");
                    $stmt->execute([$vkey, $user_id]);
                    
                    response_success(['vkey' => $vkey], '密钥生成成功');
                    break;
                    
                case 'get':
                    // 强制只能获取当前登录用户的 vkey
                    $user_id = $current_user_id;
                    
                    $stmt = $conn->prepare("SELECT vkey FROM users WHERE id = ? AND is_deleted = FALSE");
                    $stmt->execute([$user_id]);
                    $result = $stmt->fetch();
                    
                    if ($result && !empty($result['vkey'])) {
                        response_success(['vkey' => $result['vkey']]);
                    } else {
                        response_error('未找到密钥');
                    }
                    break;
                    
                default:
                    response_error("VKey 模块不支持操作: $action");
            }
            break;

        // ------------------------------------------
        // 扫码登录模块 (Scan Login)
        // ------------------------------------------
        case 'scan_login':
            switch ($action) {
                case 'confirm':
                    // confirm 必须由已登录的 APP 端调用，绑定当前登录用户身份
                    $current_user_id = check_auth();
                    
                    $qid = $data['qid'] ?? '';
                    
                    if (empty($qid)) {
                        response_error('登录标识不能为空');
                    }
                    
                    // 使用当前登录用户的身份，不再接受外部传入的 vkey
                    $user_id = $current_user_id;
                    
                    $stmt = $conn->prepare("SELECT * FROM scan_login WHERE qid = ? AND expire_at > NOW() AND status IN ('pending', 'scanned')");
                    $stmt->execute([$qid]);
                    $token_data = $stmt->fetch();
                    
                    if (!$token_data) {
                        response_error('登录二维码无效或已过期');
                    }
                    
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
                    response_error("Scan Login 模块不支持操作: $action");
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
                    response_error("SMS 模块不支持操作: $action");
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
                    response_error("Music 模块不支持操作: $action");
            }
            break;

        // ------------------------------------------
        // 默认处理
        // ------------------------------------------
        default:
            response_error('无效的 API 资源 (Resource)', 404);
    }

} catch (Exception $e) {
    // 捕获未处理的异常，防止敏感信息泄露
    error_log("API 异常: " . $e->getMessage());
    response_error('服务器内部错误', 500);
}

// 关闭数据库连接
$conn = null;
