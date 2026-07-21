<?php
/**
 * upload_token.php — 内部文件上传认证接口
 *
 * 为前端分片上传提供认证参数（user, vkey, time, token, id）。
 * 使用 Session 认证，不调用 api.php。
 *
 * 请求方式：POST
 * 返回 JSON：{ success: true, data: { user, vkey, time, token, id, upload_url } }
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// 检查登录状态
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '用户未登录']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '无效的请求方法']);
    exit;
}

require_once 'config.php';
check_api_access();

$user_id = (int) $_SESSION['user_id'];

// 建立数据库连接
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    error_log('[upload_token.php] DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '服务暂不可用']);
    exit;
}

// 获取用户 vkey
$vkey = get_vkey_by_user_id($user_id, $pdo);
if (!$vkey) {
    echo json_encode(['success' => false, 'message' => '无法获取访问密钥']);
    exit;
}

// 生成上传会话 ID 和认证参数
$upload_session_id = uniqid('', true) . '_' . time();
$auth_params = generate_upload_token($user_id, $vkey, $upload_session_id);

echo json_encode([
    'success' => true,
    'data' => [
        'user'       => $auth_params['user'],
        'vkey'       => $auth_params['vkey'],
        'time'       => $auth_params['time'],
        'token'      => $auth_params['token'],
        'id'         => $auth_params['id'],
        'upload_url' => 'https://files.modern-chat.top/uploads.php',
    ],
]);
