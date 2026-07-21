<?php
/**
 * music-api.php — 音乐 API 转发网关
 *
 * 参数：
 *   vkey       — 用户密钥（必填，到数据库验证有效性）
 *   Operation  — 操作类型：search / music / lrc
 *
 * 限流规则：
 *   admin 用户 → 无限制
 *   非admin用户 → 50 次/分钟（按 vkey+IP 双维度计数）
 *   超限后 → 禁止当前 vkey+IP 访问，递进封禁：
 *     首次 1 分钟，每次违规 +1 分钟，上限 60 分钟
 *     超过 60 分钟 → 永久封禁 IP（写入 ip_bans 表）
 *
 * Operation=search (GET):
 *   name   — 搜索内容（必填）
 *   source — 音乐源 wy/tx/kg/kw/mg（必填）
 *   page   — 页码 1-10（默认 1）
 *   pages  — 每页数量（默认 1，不能为负数）
 *
 * Operation=music (POST):
 *   请求体为 JSON，PHP 仅做转发
 *   自动注入 quality（默认 128k，可选 320k / flac）
 *   请求头添加 x-frontend-auth: lx_tk_a36b8c5e632d7037870e595e1d7d7f94
 *
 * Operation=lrc (GET):
 *   必填: source, songmid, name, singer, albumId
 *   可选: hash, interval, copyrightId, lrcUrl, mrcUrl, trcUrl 等
 *   构建请求发送到本地服务 http://127.0.0.1:9527/api/music/lyric
 */

require_once 'config.php';
check_api_access();
require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

// ── 本地音乐服务地址 ──
define('MUSIC_API_BASE', 'http://127.0.0.1:9527/api/music');
define('LYRIC_API_BASE', 'http://127.0.0.1:9527/api/music/lyric');
define('FRONTEND_AUTH_TOKEN', 'lx_tk_a36b8c5e632d7037870e595e1d7d7f94');

// ── 限流常量 ──
define('RATE_LIMIT_PER_MIN', 50);           // 非admin 每分钟最多 50 次
define('RATE_LIMIT_WINDOW', 60);            // 计数窗口 60 秒
define('BAN_MAX_MINUTES', 60);              // 封禁上限 60 分钟
define('PERMANENT_BAN_THRESHOLD', 60);      // 超过此分钟数 → 永久封禁

// 允许的音乐源
$ALLOWED_SOURCES = ['wy', 'tx', 'kg', 'kw', 'mg'];
// 允许的音质
$ALLOWED_QUALITY = ['128k', '320k', 'flac'];

// ─────────────────────────────────────────────
// 1. 数据库连接检查
// ─────────────────────────────────────────────
if ($conn === null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库连接失败']);
    exit;
}

// ─────────────────────────────────────────────
// 2. vkey 验证（同时获取 is_admin）
// ─────────────────────────────────────────────
$vkey = $_GET['vkey'] ?? $_POST['vkey'] ?? '';
$clientIp = _getClientIp();

if (empty($vkey)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '缺少 vkey 参数']);
    exit;
}

error_log("[music-api] request: Operation=" . ($_GET['Operation'] ?? '') . " method=" . $_SERVER['REQUEST_METHOD'] . " ip={$clientIp} params=" . json_encode($_GET, JSON_UNESCAPED_UNICODE));

try {
    $stmt = $conn->prepare("SELECT id, is_admin FROM users WHERE vkey = ? AND is_deleted = FALSE");
    $stmt->execute([$vkey]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log("[music-api] vkey validation DB error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库查询失败']);
    exit;
}

if (!$user) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'vkey 无效或用户不存在']);
    exit;
}

$userId   = intval($user['id']);
$isAdmin  = boolval($user['is_admin']);

// ─────────────────────────────────────────────
// 3. 限流 & 封禁检查（非 admin 用户才限制）
// ─────────────────────────────────────────────
if (!$isAdmin) {
    // ── 3a. 检查 IP 是否已永久封禁 ──
    if (_isIpPermanentlyBanned($conn, $clientIp)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'IP 已被永久禁止访问音乐接口']);
        exit;
    }

    // ── 3b. 检查 vkey+IP 是否处于临时封禁期 ──
    $banRemaining = _getBanRemaining($conn, $vkey, $clientIp);
    if ($banRemaining > 0) {
        http_response_code(429);
        header('Retry-After: ' . $banRemaining);
        echo json_encode([
            'success' => false,
            'message' => '请求频率超限，请 ' . $banRemaining . ' 秒后再试',
            'retry_after' => $banRemaining,
        ]);
        exit;
    }

    // ── 3c. QPS 限流计数 ──
    $redis = _getRedis();                               // 尝试连接 Redis

    $rateKey   = 'music_rate:' . md5($vkey . ':' . $clientIp);   // 计数 key
    $countKey  = 'music_ban_count:' . md5($vkey . ':' . $clientIp); // 违规次数 key

    // 获取当前窗口请求计数
    $currentCount = 0;
    if ($redis) {
        $currentCount = intval($redis->get($rateKey));
    } else {
        // Redis 不可用 → 降级用 DB 查询
        $currentCount = _getDbRequestCount($conn, $vkey, $clientIp);
    }

    if ($currentCount >= RATE_LIMIT_PER_MIN) {
        // ── 超限！触发递进封禁 ──
        $violationCount = 0;
        if ($redis) {
            $violationCount = intval($redis->get($countKey));
        } else {
            $violationCount = _getDbViolationCount($conn, $vkey, $clientIp);
        }

        $violationCount += 1;
        $banMinutes = min($violationCount, BAN_MAX_MINUTES);  // 首次1分钟，递进到60

        // 记录违规次数
        if ($redis) {
            $redis->setex($countKey, BAN_MAX_MINUTES * 60, $violationCount);
        } else {
            _setDbViolationCount($conn, $vkey, $clientIp, $violationCount);
        }

        // 检查是否达到永久封禁阈值
        if ($banMinutes >= PERMANENT_BAN_THRESHOLD) {
            _permanentlyBanIp($conn, $clientIp);
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'IP 已被永久禁止访问音乐接口']);
            exit;
        }

        // 写入临时封禁记录
        _setBan($conn, $vkey, $clientIp, $userId, $banMinutes);

        error_log("[music-api] RATE LIMITED: vkey={$vkey} ip={$clientIp} violations={$violationCount} ban={$banMinutes}min");

        http_response_code(429);
        header('Retry-After: ' . ($banMinutes * 60));
        echo json_encode([
            'success' => false,
            'message' => '请求频率超限，请 ' . $banMinutes . ' 分钟后再试（第 ' . $violationCount . ' 次违规）',
            'retry_after' => $banMinutes * 60,
            'violation_count' => $violationCount,
        ]);
        exit;
    }

    // ── 3d. 计数+1 ──
    if ($redis) {
        $redis->incr($rateKey);
        // 第一次计数时设置过期时间（窗口自动清零）
        if ($currentCount === 0) {
            $redis->expire($rateKey, RATE_LIMIT_WINDOW);
        }
    } else {
        _incrDbRequestCount($conn, $vkey, $clientIp);
    }
}

// ─────────────────────────────────────────────
// 4. 路由 — 根据 Operation 分发
// ─────────────────────────────────────────────
$operation = $_GET['Operation'] ?? $_POST['Operation'] ?? '';

switch ($operation) {

    // ═════════════════════════════════════════
    // search — 搜索歌曲 (GET)
    // ═════════════════════════════════════════
    case 'search':
        $name = $_GET['name'] ?? '';
        $source = $_GET['source'] ?? '';
        $page = $_GET['page'] ?? 1;
        $pages = $_GET['pages'] ?? 1;

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '缺少搜索内容 (name)']);
            exit;
        }

        if (!in_array($source, $ALLOWED_SOURCES, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '无效的音乐源 (source)，可选: wy/tx/kg/kw/mg']);
            exit;
        }

        $page = max(1, min(10, intval($page)));
        $pages = max(1, intval($pages));

        $apiUrl = MUSIC_API_BASE . '/search?' . http_build_query([
            'name'   => $name,
            'source' => $source,
            'type'   => 'song',
            'page'   => $page,
            'pages'  => $pages,
        ]);

        $response = _curlGet($apiUrl);
        _outputResponse($response, $apiUrl);
        break;

    // ═════════════════════════════════════════
    // music — 获取歌曲播放链接 (POST)
    // ═════════════════════════════════════════
    case 'music':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'music 操作需要 POST 请求']);
            exit;
        }

        $rawBody = file_get_contents('php://input');
        $bodyData = json_decode($rawBody, true);

        if (!is_array($bodyData)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '请求体不是有效的 JSON']);
            exit;
        }

        if (!isset($bodyData['quality']) || !in_array($bodyData['quality'], $ALLOWED_QUALITY, true)) {
            $bodyData['quality'] = '128k';
        }

        $postJson = json_encode($bodyData, JSON_UNESCAPED_UNICODE);

        $response = _curlPost(
            MUSIC_API_BASE . '/url',
            $postJson,
            [
                'Content-Type: application/json',
                'x-frontend-auth: ' . FRONTEND_AUTH_TOKEN,
            ]
        );
        _outputResponse($response, MUSIC_API_BASE . '/url');
        break;

    // ═════════════════════════════════════════
    // lrc — 获取歌词 (GET)
    // ═════════════════════════════════════════
    case 'lrc':
        $source   = $_GET['source'] ?? '';
        $songmid  = $_GET['songmid'] ?? '';
        $name     = $_GET['name'] ?? '';
        $singer   = $_GET['singer'] ?? '';
        $albumId  = $_GET['albumId'] ?? '';

        $missing = [];
        if (empty($source))   $missing[] = 'source';
        if (empty($songmid))  $missing[] = 'songmid';
        if (empty($name))     $missing[] = 'name';
        if (empty($singer))   $missing[] = 'singer';
        if (empty($albumId))  $missing[] = 'albumId';

        if (!empty($missing)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '缺少必填参数: ' . implode(', ', $missing)]);
            exit;
        }

        if (!in_array($source, $ALLOWED_SOURCES, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '无效的音乐源 (source)，可选: wy/tx/kg/kw/mg']);
            exit;
        }

        $queryParams = [
            'source'   => $source,
            'songmid'  => $songmid,
            'name'     => $name,
            'singer'   => $singer,
            'albumId'  => $albumId,
        ];

        $optionalKeys = ['hash', 'interval', 'copyrightId', 'lrcUrl', 'mrcUrl', 'trcUrl'];
        foreach ($optionalKeys as $key) {
            $val = $_GET[$key] ?? '';
            if ($val !== '') {
                $queryParams[$key] = $val;
            }
        }

        $apiUrl = LYRIC_API_BASE . '?' . http_build_query($queryParams);

        $response = _curlGet($apiUrl);
        _outputResponse($response, $apiUrl);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '无效的 Operation，可选: search/music/lrc']);
        break;
}


// ─────────────────────────────────────────────
// 限流 & 封禁 辅助函数
// ─────────────────────────────────────────────

/**
 * 获取客户端真实 IP（支持代理头）
 */
function _getClientIp(): string {
    $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        $val = $_SERVER[$h] ?? '';
        if (!empty($val)) {
            $ips = explode(',', $val);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * 尝试连接 Redis，不可用返回 null
 */
function _getRedis() {
    if (!extension_loaded('redis')) return null;
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 2);  // 2秒超时
        return $redis;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 检查 IP 是否已在 ip_bans 表中被永久封禁
 */
function _isIpPermanentlyBanned(PDO $conn, string $ip): bool {
    try {
        $stmt = $conn->prepare(
            "SELECT id FROM ip_bans WHERE ip_address = ? AND status = 'active' AND ban_duration = 0 LIMIT 1"
        );
        $stmt->execute([$ip]);
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        error_log("[music-api] ip_bans check error: " . $e->getMessage());
        return false; // 查询失败时不阻断请求
    }
}

/**
 * 永久封禁 IP（写入 ip_bans 表，ban_duration=0 表示永久）
 */
function _permanentlyBanIp(PDO $conn, string $ip): void {
    try {
        // 先关闭旧的活跃封禁记录
        $stmt = $conn->prepare(
            "UPDATE ip_bans SET status = 'expired' WHERE ip_address = ? AND status = 'active'"
        );
        $stmt->execute([$ip]);

        // 写入永久封禁
        $stmt = $conn->prepare(
            "INSERT INTO ip_bans (ip_address, ban_reason, ban_duration, ban_end, status)
             VALUES (?, '音乐API频率超限永久封禁', 0, NULL, 'active')"
        );
        $stmt->execute([$ip]);

        error_log("[music-api] PERMANENTLY BANNED IP: {$ip}");
    } catch (PDOException $e) {
        error_log("[music-api] permanent ban DB error: " . $e->getMessage());
    }
}

/**
 * 获取 vkey+IP 的临时封禁剩余秒数
 * 从 music_api_bans 表查询
 */
function _getBanRemaining(PDO $conn, string $vkey, string $ip): int {
    try {
        $stmt = $conn->prepare(
            "SELECT ban_end FROM music_api_bans WHERE vkey = ? AND ip_address = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$vkey, $ip]);
        $row = $stmt->fetch();

        if (!$row) return 0;

        $banEnd = strtotime($row['ban_end']);
        $now = time();

        if ($banEnd <= $now) {
            // 封禁已过期 → 自动标记为 expired
            $stmt2 = $conn->prepare(
                "UPDATE music_api_bans SET status = 'expired' WHERE vkey = ? AND ip_address = ? AND status = 'active'"
            );
            $stmt2->execute([$vkey, $ip]);
            return 0;
        }

        return $banEnd - $now;
    } catch (PDOException $e) {
        error_log("[music-api] ban remaining check error: " . $e->getMessage());
        return 0;
    }
}

/**
 * 设置 vkey+IP 临时封禁（写入 music_api_bans 表）
 */
function _setBan(PDO $conn, string $vkey, string $ip, int $userId, int $banMinutes): void {
    try {
        // 先关闭旧的活跃封禁
        $stmt = $conn->prepare(
            "UPDATE music_api_bans SET status = 'expired' WHERE vkey = ? AND ip_address = ? AND status = 'active'"
        );
        $stmt->execute([$vkey, $ip]);

        // 写入新封禁
        $banSeconds = $banMinutes * 60;
        $banEnd = date('Y-m-d H:i:s', time() + $banSeconds);

        $stmt = $conn->prepare(
            "INSERT INTO music_api_bans (user_id, vkey, ip_address, ban_minutes, ban_end, status)
             VALUES (?, ?, ?, ?, ?, 'active')"
        );
        $stmt->execute([$userId, $vkey, $ip, $banMinutes, $banEnd]);
    } catch (PDOException $e) {
        error_log("[music-api] set ban DB error: " . $e->getMessage());
    }
}

/**
 * DB 降级：获取请求计数（Redis 不可用时）
 */
function _getDbRequestCount(PDO $conn, string $vkey, string $ip): int {
    try {
        $stmt = $conn->prepare(
            "SELECT request_count FROM music_api_rate_log WHERE vkey = ? AND ip_address = ? AND window_start > ? LIMIT 1"
        );
        $stmt->execute([$vkey, $ip, date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW)]);
        $row = $stmt->fetch();
        return $row ? intval($row['request_count']) : 0;
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * DB 降级：+1 请求计数
 */
function _incrDbRequestCount(PDO $conn, string $vkey, string $ip): void {
    try {
        // 先检查当前窗口是否有记录
        $stmt = $conn->prepare(
            "SELECT id, request_count FROM music_api_rate_log WHERE vkey = ? AND ip_address = ? AND window_start > ? LIMIT 1"
        );
        $stmt->execute([$vkey, $ip, date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW)]);
        $row = $stmt->fetch();

        if ($row) {
            // 窗口内已有记录 → +1
            $stmt2 = $conn->prepare(
                "UPDATE music_api_rate_log SET request_count = request_count + 1 WHERE id = ?"
            );
            $stmt2->execute([intval($row['id'])]);
        } else {
            // 新窗口 → 创建记录
            $stmt2 = $conn->prepare(
                "INSERT INTO music_api_rate_log (vkey, ip_address, request_count, window_start)
                 VALUES (?, ?, 1, NOW())"
            );
            $stmt2->execute([$vkey, $ip]);
        }
    } catch (PDOException $e) {
        error_log("[music-api] DB rate count error: " . $e->getMessage());
    }
}

/**
 * DB 降级：获取违规次数
 */
function _getDbViolationCount(PDO $conn, string $vkey, string $ip): int {
    try {
        $stmt = $conn->prepare(
            "SELECT violation_count FROM music_api_violations WHERE vkey = ? AND ip_address = ? LIMIT 1"
        );
        $stmt->execute([$vkey, $ip]);
        $row = $stmt->fetch();
        return $row ? intval($row['violation_count']) : 0;
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * DB 降级：设置违规次数
 */
function _setDbViolationCount(PDO $conn, string $vkey, string $ip, int $count): void {
    try {
        $stmt = $conn->prepare(
            "INSERT INTO music_api_violations (vkey, ip_address, violation_count, last_violation)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE violation_count = ?, last_violation = NOW()"
        );
        $stmt->execute([$vkey, $ip, $count, $count]);
    } catch (PDOException $e) {
        error_log("[music-api] DB violation count error: " . $e->getMessage());
    }
}


// ─────────────────────────────────────────────
// HTTP 转发 辅助函数
// ─────────────────────────────────────────────

/**
 * cURL GET 请求
 */
function _curlGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['body' => $body, 'httpCode' => $httpCode, 'error' => $error];
}

/**
 * cURL POST 请求
 */
function _curlPost(string $url, string $postBody, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postBody,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['body' => $body, 'httpCode' => $httpCode, 'error' => $error];
}

/**
 * 输出本地服务的响应（透传）
 */
function _outputResponse(array $response, string $requestedUrl = ''): void {
    if ($requestedUrl) {
        header('X-Proxy-Target: ' . $requestedUrl);
    }

    if ($response['error']) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => '本地音乐服务连接失败: ' . $response['error'],
            'proxy_target' => $requestedUrl,
        ]);
        return;
    }

    http_response_code($response['httpCode'] ?: 200);

    $body = $response['body'];
    $decoded = json_decode($body, true);
    if ($decoded !== null) {
        echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
    } else {
        echo $body;
    }
}
