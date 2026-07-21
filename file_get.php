<?php
require_once 'config.php';
check_api_access();

/**
 * file_get.php — 统一文件获取入口
 *
 * 所有文件访问都必须通过此脚本，提供：
 *   - Vkey 用户认证
 *   - Token 签名验证（防重放 / 防伪造）
 *   - 速率限制（每个 vkey 每 5 秒最多 100 次）
 *   - 路径遍历防护 + 目录白名单
 *   - MIME 类型映射
 *   - HTTP Range 请求支持（音视频流播放）
 *
 * GET 参数：
 *   file  — 文件相对路径（如 uploads/images/photo.png）
 *   vkey  — 用户 vkey 认证密钥
 *   token — HMAC 签名令牌（时效 5 分钟）
 *
 * 签名生成方式（服务端）：
 *   $ts      = time();
 *   $payload = "{$file}|{$vkey}|{$ts}";
 *   $hmac    = hash_hmac('sha256', $payload, FILE_TOKEN_SECRET);
 *   $token   = base64_encode("{$ts}:{$hmac}");
 *
 * 速率限制：每 vkey 每 5 秒窗口内最多 100 次请求。
 */

require_once __DIR__ . '/config.php';

// 常量定义在 config.php 中：
//   FILE_TOKEN_SECRET / FILE_TOKEN_TTL / FILE_RATE_LIMIT_MAX / FILE_RATE_LIMIT_WINDOW

// ═══════════════════════════════════════════════════════
// 数据库连接
// ═══════════════════════════════════════════════════════
$db_host    = DB_HOST;
$db_name    = DB_NAME;
$db_user    = DB_USER;
$db_pass    = DB_PASS;

try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('[file_get] DB connect error: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '服务暂不可用', 'code' => 503]);
    exit;
}

// ═══════════════════════════════════════════════════════
// 辅助函数
// ═══════════════════════════════════════════════════════

/**
 * 输出 JSON 错误并终止
 */
function file_error(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg, 'code' => $code], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 确保 file_access_limits 表存在
 */
function ensure_rate_limit_table(PDO $pdo): void {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS file_access_limits (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                vkey          VARCHAR(64)    NOT NULL COMMENT '用户认证密钥',
                request_count INT            DEFAULT 1 COMMENT '窗口内请求计数',
                window_start  DECIMAL(16,6)  NOT NULL COMMENT '窗口起始 microtime',
                INDEX idx_vkey_window (vkey, window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文件访问速率限制表'
        ");
    } catch (PDOException $e) {
        error_log('[file_get] Create table error: ' . $e->getMessage());
    }
}

/**
 * 验证 Token（HMAC-SHA256，±1 秒容差）
 */
function validate_token(string $file, string $vkey, string $token): bool {
    $decoded = base64_decode($token, true);
    if ($decoded === false) return false;

    $parts = explode(':', $decoded, 2);
    if (count($parts) !== 2) return false;

    [$ts_str, $hmac] = $parts;
    $ts = (int) $ts_str;

    $now = time();
    if (abs($now - $ts) > FILE_TOKEN_TTL) return false;

    // 允许 ±1 秒时钟偏差
    for ($offset = -1; $offset <= 1; $offset++) {
        $check_ts = $ts + $offset;
        $expected  = hash_hmac('sha256', "{$file}|{$vkey}|{$check_ts}", FILE_TOKEN_SECRET);
        if (hash_equals($expected, $hmac)) {
            return true;
        }
    }

    return false;
}

/**
 * 速率限制检查
 *
 * @return bool  true = 放行，false = 超限
 */
function check_rate_limit(string $vkey, PDO $pdo): bool {
    try {
        $now          = microtime(true);
        $window_start = $now - FILE_RATE_LIMIT_WINDOW;

        // 清理过期窗口
        $pdo->prepare("DELETE FROM file_access_limits WHERE window_start < ?")
            ->execute([$window_start]);

        // 查当前窗口记录
        $stmt = $pdo->prepare(
            "SELECT id, request_count FROM file_access_limits
             WHERE vkey = ? AND window_start >= ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$vkey, $window_start]);
        $record = $stmt->fetch();

        if ($record) {
            $new_count = $record['request_count'] + 1;
            if ($new_count > FILE_RATE_LIMIT_MAX) {
                return false;
            }
            $pdo->prepare("UPDATE file_access_limits SET request_count = ? WHERE id = ?")
                ->execute([$new_count, $record['id']]);
        } else {
            $pdo->prepare(
                "INSERT INTO file_access_limits (vkey, request_count, window_start) VALUES (?, 1, ?)"
            )->execute([$vkey, $now]);
        }

        return true;
    } catch (PDOException $e) {
        error_log('[file_get] Rate limit error: ' . $e->getMessage());
        return true; // 出错时放行
    }
}

/**
 * 解析并验证文件路径
 *
 * @return string|null 标准化后的绝对路径；不合法/不存在返回 null
 */
function resolve_file_path(string $file): ?string {
    $base_dir = realpath(__DIR__) . '/';
    $file     = str_replace('\\', '/', $file);

    // 路径遍历防护
    if (strpos($file, '..') !== false)  return null;
    if (strpos($file, "\0") !== false)  return null;

    $file = ltrim($file, '/');

    $blocked_extensions = ['php', 'php3', 'php4', 'php5', 'phtml', 'pht', 'inc', 'json', 'xml', 'ini', 'conf', 'env', 'htaccess', 'sql', 'log', 'htpasswd', 'git'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($ext, $blocked_extensions)) return null;

    $allowed_dirs = ['uploads/', 'avatars/', 'new_music/', 'download/'];

    $allowed = false;
    foreach ($allowed_dirs as $dir) {
        if (strpos($file, $dir) === 0) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        $file = 'uploads/' . $file;
    }

    $full = $base_dir . $file;
    if (file_exists($full) && is_file($full)) {
        $real = realpath($full);
        $allowed_base = realpath(__DIR__);
        if ($real && strpos($real, $allowed_base) === 0) return $real;
    }

    if (strpos($file, 'uploads/') !== 0) {
        $full = $base_dir . 'uploads/' . $file;
        if (file_exists($full) && is_file($full)) {
            $real = realpath($full);
            $allowed_base = realpath(__DIR__ . '/uploads');
            if ($real && strpos($real, $allowed_base) === 0) return $real;
        }
    }

    $avatar = $base_dir . 'avatars/' . basename($file);
    if (file_exists($avatar) && is_file($avatar)) {
        $real = realpath($avatar);
        $allowed_base = realpath(__DIR__ . '/avatars');
        if ($real && strpos($real, $allowed_base) === 0) return $real;
    }

    $uploads_avatar = $base_dir . 'uploads/avatars/' . basename($file);
    if (file_exists($uploads_avatar) && is_file($uploads_avatar)) {
        $real = realpath($uploads_avatar);
        $allowed_base = realpath(__DIR__ . '/uploads/avatars');
        if ($real && strpos($real, $allowed_base) === 0) return $real;
    }

    return null;
}

/**
 * 返回 MIME 类型
 */
function get_mime(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return [
        // 图片
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'bmp' => 'image/bmp', 'ico'  => 'image/x-icon',
        // 视频
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'video/ogg',
        'avi' => 'video/x-msvideo', 'mov' => 'video/quicktime',
        // 音频
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'flac' => 'audio/flac',
        'm4a' => 'audio/mp4', 'aac'  => 'audio/aac',
        // 文档
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // 压缩
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z'  => 'application/x-7z-compressed',
        // 文本
        'txt' => 'text/plain', 'csv' => 'text/csv',
        'json' => 'application/json', 'xml' => 'application/xml',
    ][$ext] ?? 'application/octet-stream';
}

/**
 * 输出文件内容（支持 Range）
 */
function serve_file(string $full_path): void {
    $mime     = get_mime($full_path);
    $filesize = filesize($full_path);
    $filename = basename($full_path);

    // 清空输出缓冲
    while (ob_get_level()) {
        ob_end_clean();
    }
    @ini_set('zlib.output_compression', 'Off');

    // --- Range 请求处理 ---
    $range = $_SERVER['HTTP_RANGE'] ?? null;

    if ($range) {
        [$param, $val] = explode('=', $range, 2);
        if (strtolower(trim($param)) === 'bytes') {
            $parts = explode('-', explode(',', $val)[0]);
            $start = (int) $parts[0];
            $end   = (isset($parts[1]) && $parts[1] !== '')
                     ? (int) $parts[1]
                     : $filesize - 1;

            if ($start <= $end && $start < $filesize) {
                $length = $end - $start + 1;

                http_response_code(206);
                header("Content-Type: {$mime}");
                header("Content-Range: bytes {$start}-{$end}/{$filesize}");
                header("Content-Length: {$length}");
                header('Accept-Ranges: bytes');
                header('Cache-Control: public, max-age=86400');

                $fp = fopen($full_path, 'rb');
                fseek($fp, $start);
                $buf = 8192;
                while (!feof($fp) && ($pos = ftell($fp)) <= $end) {
                    if ($pos + $buf > $end) $buf = $end - $pos + 1;
                    echo fread($fp, $buf);
                    flush();
                }
                fclose($fp);
                exit;
            }
        }
        http_response_code(416);
        header("Content-Range: bytes */{$filesize}");
        exit;
    }

    // --- 普通完整请求 ---
    header("Content-Type: {$mime}");
    header("Content-Length: {$filesize}");
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=86400');
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');

    readfile($full_path);
    exit;
}

// ═══════════════════════════════════════════════════════
// 主流程
// ═══════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    file_error('仅支持 GET 请求', 405);
}

$file  = $_GET['file']  ?? '';
$vkey  = $_GET['vkey']  ?? '';
$token = $_GET['token'] ?? '';

if ($file  === '') file_error('缺少参数: file（文件名称）', 400);
if ($vkey  === '') file_error('缺少参数: vkey（用户认证密钥）', 400);
if ($token === '') file_error('缺少参数: token（访问令牌）', 400);

// 1. 确保限流表存在
ensure_rate_limit_table($pdo);

// 2. 验证 vkey → 获取 user_id
$stmt = $pdo->prepare("SELECT id, username, vkey FROM users WHERE vkey = ? AND is_deleted = FALSE");
$stmt->execute([$vkey]);
$user = $stmt->fetch();

if (!$user) {
    file_error('无效的 vkey，请重新获取认证密钥', 403);
}

// 3. 验证 token
if (!validate_token($file, $vkey, $token)) {
    file_error('无效或已过期的 token，请重新获取访问令牌', 403);
}

// 4. 速率限制
if (!check_rate_limit($vkey, $pdo)) {
    header('Retry-After: ' . FILE_RATE_LIMIT_WINDOW);
    file_error('请求过于频繁，请稍后再试（限制：每 ' . FILE_RATE_LIMIT_WINDOW . ' 秒最多 ' . FILE_RATE_LIMIT_MAX . ' 次）', 429);
}

// 5. 解析文件路径
$full_path = resolve_file_path($file);
if ($full_path === null) {
    file_error('文件不存在或路径不合法', 404);
}

// 6. 输出文件
serve_file($full_path);
