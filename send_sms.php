<?php
require_once 'security_check.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/includes/AliSmsClient.php';

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// 获取POST数据
$phone = $_POST['phone'] ?? '';
$lot_number = $_POST['geetest_challenge'] ?? '';
$captcha_output = $_POST['geetest_validate'] ?? '';
$pass_token = $_POST['geetest_seccode'] ?? '';
$gen_time = $_POST['gen_time'] ?? '';
$captcha_id = $_POST['captcha_id'] ?? '';

// 验证手机号
if (empty($phone) || !preg_match('/^1[3-9]\d{9}$/', $phone)) {
    echo json_encode(['success' => false, 'message' => '请输入有效的手机号']);
    exit;
}

// 极验4.0验证
if (empty($lot_number) || empty($captcha_output) || empty($pass_token) || empty($gen_time) || empty($captcha_id)) {
    echo json_encode(['success' => false, 'message' => '请先完成验证码验证']);
    exit;
}

// 检查发送间隔(60秒)
if (isset($_SESSION['last_sms_time']) && (time() - $_SESSION['last_sms_time'] < 60)) {
    $seconds_left = 60 - (time() - $_SESSION['last_sms_time']);
    echo json_encode(['success' => false, 'message' => "请等待{$seconds_left} 秒后再试"]);
    exit;
}

// 验证极验签名
$captchaId = '55574dfff9c40f2efeb5a26d6d188245';
$captchaKey = 'e69583b3ddcc2b114388b5e1dc213cfd';
$apiUrl = 'http://gcaptcha4.geetest.com/validate?captcha_id=' . urlencode($captchaId);

$sign_token = hash_hmac('sha256', $lot_number, $captchaKey);

$params = [
    'lot_number' => $lot_number,
    'captcha_output' => $captcha_output,
    'pass_token' => $pass_token,
    'gen_time' => $gen_time,
    'sign_token' => $sign_token
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
curl_setopt($ch, CURLOPT_TIMEOUT, 5);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$geetest_success = false;
if ($http_code === 200) {
    $result = json_decode($response, true);
    if ($result && $result['status'] === 'success' && $result['result'] === 'success') {
        $geetest_success = true;
    }
}

if (!$geetest_success) {
    echo json_encode(['success' => false, 'message' => '验证码验证失败，请刷新页面重试']);
    exit;
}

// 发送短信验证码，请配置这里
$accessKeyId = '0';
$accessKeySecret = '0';

$smsClient = new AliSmsClient($accessKeyId, $accessKeySecret);

// 生成6位随机验证码
$code = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

// 调用API发送验证码
$result = $smsClient->sendVerifyCode($phone, 300, $code);

if ($result['success']) {
    // 保存到Session
    $_SESSION['sms_code'] = $code;
    $_SESSION['sms_phone'] = $phone;
    $_SESSION['sms_expire'] = time() + 300; // 5分钟有效
    $_SESSION['last_sms_time'] = time(); // 记录发送时间用于倒计时
    $_SESSION['geetest_verified_time'] = time(); // 记录极验验证通过时间，用于注册时跳过验证
    
    // 记录日志：用户IP和生成的验证码
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $forwarded_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $real_ip = !empty($forwarded_ip) ? $forwarded_ip : $user_ip;
    
    // 使用固定宽度格式对齐日志
    $timestamp = date('Y-m-d H:i:s');
    $log_message = sprintf(
        "[%s] IP: %s\n%s手机号: %-11s | 验证码: %-6s | 状态: %s\n%s\n",
        $timestamp,
        $real_ip,
        str_repeat(' ', 22), // 对齐第二行（22个空格对应时间戳长度）
        $phone,
        $code,
        '发送成功',
        str_repeat('-', 80) // 分隔线
    );
    
    // 写入日志文件
    $log_dir = __DIR__ . '/config';
    $log_file = $log_dir . '/system.log';
    
    // 确保日志目录存在
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
    
    echo json_encode(['success' => true, 'message' => '验证码已发送，请在5分钟内输入']);
    exit;
} else {
    // 记录失败日志
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $forwarded_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $real_ip = !empty($forwarded_ip) ? $forwarded_ip : $user_ip;
    $error_msg = $result['error'] ?? '未知错误';
    
    $timestamp = date('Y-m-d H:i:s');
    $log_message = sprintf(
        "[%s] IP: %s\n%s手机号: %-11s | 验证码: %-6s | 状态: %s | 错误: %s\n%s\n",
        $timestamp,
        $real_ip,
        str_repeat(' ', 22),
        $phone,
        $code,
        '发送失败',
        $error_msg,
        str_repeat('-', 80)
    );
    
    // 写入日志文件
    $log_dir = __DIR__ . '/config';
    $log_file = $log_dir . '/system.log';
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
    
    echo json_encode([
        'success' => false, 
        'message' => '发送失败 ' . ($result['error'] ?? '未知错误')
    ]);
    exit;
}