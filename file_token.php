<?php
/**
 * file_token.php — 内部文件访问令牌生成接口
 * =====================================================
 *
 * 仅供 Web 前端（chat.php 等）使用，通过 Session 认证。
 * 替代 api.php 的 file/token 端点，避免 Web 页面调用外部 API。
 *
 * 请求方式：POST JSON
 *   { "path": "uploads/xxx.jpg" }
 *
 * 响应：
 *   { "success": true, "data": { "url": "https://.../list.php?..." } }
 */

// 错误报告
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 仅允许 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '仅支持 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 启动 Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 检查登录状态
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/config.php';
check_api_access();
require_once __DIR__ . '/db.php';

$user_id = $_SESSION['user_id'];

// 获取请求参数
$input = @file_get_contents('php://input');
$data = json_decode($input, true);
$path = trim($data['path'] ?? '');

if ($path === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '缺少 path 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 安全检查：防止路径遍历
$path = str_replace(['\\', '..'], ['', ''], $path);
$path = ltrim($path, '/');

// 获取 vkey
$vkey = null;
if (function_exists('get_vkey_by_user_id')) {
    $vkey = get_vkey_by_user_id($user_id, $conn);
}
if (!$vkey) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '无法获取访问密钥，请刷新页面'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 检查是否有 upload_id（新格式路径）
$upload_id = null;
$stored_name = $path;

// 尝试从数据库查找文件记录
$stmt = $conn->prepare("SELECT upload_id, stored_name FROM file_uploads WHERE file_path LIKE ? OR upload_id = ? LIMIT 1");
$search_path = '%' . $path . '%';
$stmt->execute([$search_path, $path]);
$file_record = $stmt->fetch();

if ($file_record) {
    $upload_id = $file_record['upload_id'];
    $stored_name = $file_record['stored_name'];
}

// 生成签名 URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$server_host = $_SERVER['HTTP_HOST'] ?? '';

if ($upload_id) {
    // 有 upload_id → 使用 proxy_file.php 代理 + id 参数（避免跨域）
    $ts   = time();
    $hmac = hash_hmac('sha256', "{$upload_id}|{$stored_name}|{$vkey}|{$ts}", FILE_TOKEN_SECRET);
    $token = base64_encode("{$ts}:{$hmac}");

    $url = "{$scheme}://{$server_host}/proxy_file.php"
         . "?id="    . urlencode($upload_id)
         . "&file="  . urlencode($stored_name)
         . "&vkey="  . urlencode($vkey)
         . "&token=" . urlencode($token);
} else {
    // 无 upload_id → 使用标准代理 URL
    $ts   = time();
    $hmac = hash_hmac('sha256', "{$path}|{$vkey}|{$ts}", FILE_TOKEN_SECRET);
    $token = base64_encode("{$ts}:{$hmac}");

    $url = "{$scheme}://{$server_host}/proxy_file.php"
         . "?file="  . urlencode($path)
         . "&vkey="  . urlencode($vkey)
         . "&token=" . urlencode($token);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'data'    => ['url' => $url]
], JSON_UNESCAPED_UNICODE);
