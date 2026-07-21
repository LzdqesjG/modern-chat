<?php
require_once 'security_check.php';
require_once 'config.php';
check_api_access();
/**
 * 音乐代理脚本
 * 用于解决跨域(CORS)问题和处理重定向链接
 * 
 * 使用方法: proxy_music.php?url=ENCODED_URL
 */

// 允许跨域访问
// CORS配置：从配置文件中读取允许的源
$allowed_origins = getConfig('cors_allowed_origins', ['http://localhost', 'http://127.0.0.1']);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// 验证Origin是否在允许列表中
if (!empty($origin)) {
    if (is_array($allowed_origins)) {
        if (in_array($origin, $allowed_origins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
    } elseif ($allowed_origins === '*') {
        header('Access-Control-Allow-Origin: *');
    }
}

header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Content-Type');

$url = isset($_GET['url']) ? $_GET['url'] : '';

if (empty($url)) {
    http_response_code(400);
    exit('缺少URL参数');
}

// 解码 URL（处理 URL 编码）
$url = urldecode($url);

// 简单的 URL 验证
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('无效的URL');
}

// 解析 URL
$parsed_url = parse_url($url);
if (!$parsed_url) {
    http_response_code(400);
    exit('无效的URL格式');
}

// 1. 限制允许的协议（只允许 http 和 https）
$allowed_schemes = ['http', 'https'];
$scheme = isset($parsed_url['scheme']) ? strtolower($parsed_url['scheme']) : '';
if (!in_array($scheme, $allowed_schemes)) {
    http_response_code(400);
    exit('无效的URL协议。只允许HTTP和HTTPS');
}

// 2. 获取主机名
$host = isset($parsed_url['host']) ? strtolower($parsed_url['host']) : '';
if (empty($host)) {
    http_response_code(400);
    exit('无效的URL：缺少主机名');
}

// 3. 禁止访问内网地址和私有 IP
function is_private_ip($ip)
{
    // 检查是否是私有 IP 地址
    $private_ranges = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '0.0.0.0/8',
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32'
    ];

    foreach ($private_ranges as $range) {
        if (ip_in_range($ip, $range)) {
            return true;
        }
    }
    return false;
}

function ip_in_range($ip, $range)
{
    list($range_ip, $netmask) = explode('/', $range);
    $range_decimal = ip2long($range_ip);
    $ip_decimal = ip2long($ip);
    $wildcard_decimal = pow(2, (32 - $netmask)) - 1;
    $netmask_decimal = ~$wildcard_decimal;
    return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
}

// 解析域名获取 IP
$ip = gethostbyname($host);
if ($ip === $host) {
    // 如果 gethostbyname 返回原主机名，说明解析失败
    // 尝试直接作为 IP 地址处理
    $ip = $host;
}

// 验证 IP 格式
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    http_response_code(403);
    exit('访问被拒绝：不允许访问私有或保留IP地址');
}

// 4. 限制允许的域名白名单
$allowed_hosts = [
    // vkeys.cn 及其子域名
    'vkeys.cn',
    '*.vkeys.cn',
    // QQ音乐
    'ws.stream.qqmusic.qq.com',
    '*.qqmusic.qq.com',
    // 其他音乐厂商
    '*.music.163.com', // 网易云音乐
    '*.kugou.com', // 酷狗音乐
    '*.kuwo.cn', // 酷我音乐
    '*.tencentmusic.com', // 腾讯音乐
    '*.qq.com', // QQ音乐相关
];

$is_allowed = false;
foreach ($allowed_hosts as $allowed_host) {
    if (strpos($allowed_host, '*.') === 0) {
        // 通配符匹配
        $domain = substr($allowed_host, 2);
        if (substr($host, -strlen($domain)) === $domain) {
            $is_allowed = true;
            break;
        }
    } elseif ($host === $allowed_host) {
        $is_allowed = true;
        break;
    }
}

if (!$is_allowed) {
    http_response_code(403);
    exit('访问被拒绝：主机不在白名单中');
}

// 5. 禁止访问本地主机名
$forbidden_hosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
if (in_array($host, $forbidden_hosts)) {
    http_response_code(403);
    exit('访问被拒绝：不允许访问本地主机');
}

// 初始化 CURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);

// SSRF 防护：禁用跟随重定向，防止重定向到内网地址
// 如果需要支持重定向，应该在每次重定向时重新验证目标地址
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // 直接输出内容
curl_setopt($ch, CURLOPT_HEADER, false); // 不输出头部信息到内容
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // 启用 SSL 验证
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// 限制 curl 只使用 HTTP/HTTPS 协议（防止 file://, gopher:// 等）
curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

// 设置超时时间，防止长时间占用资源
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

// 限制下载大小（例如 50MB），防止 DoS 攻击
curl_setopt($ch, CURLOPT_MAXFILESIZE, 50 * 1024 * 1024);

// 伪装 User-Agent，避免部分服务器拒绝请求
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

// 转发 Range 头，支持拖动进度�?
$headers = [];
if (isset($_SERVER['HTTP_RANGE'])) {
    $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    curl_setopt($ch, CURLOPT_RANGE, str_replace('bytes=', '', $_SERVER['HTTP_RANGE']));
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// 处理头部回调，转�?Content-Type �?Content-Length
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) {
    $len = strlen($header);
    $header = trim($header);
    if (empty($header)) return $len;

    // 转发关键头部
    if (
        stripos($header, 'Content-Type:') === 0 ||
        stripos($header, 'Content-Length:') === 0 ||
        stripos($header, 'Content-Range:') === 0 ||
        stripos($header, 'Accept-Ranges:') === 0 ||
        stripos($header, 'HTTP/') === 0
    ) { // 转发状态码，如 HTTP/1.1 206 Partial Content
        header($header);
    }

    return $len;
});

// 执行请求
curl_exec($ch);

// 错误处理
if (curl_errno($ch)) {
    http_response_code(500);
    echo 'curl错误：' . curl_error($ch);
}

curl_close($ch);
