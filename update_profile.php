<?php
require_once 'security_check.php';
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';
check_api_access();
require_once 'db.php';

// 检查是否登�?
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action === 'bind_phone') {
    $phone = $_POST['phone'] ?? '';
    $sms_code = $_POST['sms_code'] ?? '';
    
    // 验证手机号格�?    
if (empty($phone) || !preg_match('/^1[3-9]\d{9}$/', $phone)) {
        echo json_encode(['success' => false, 'message' => '请输入有效的手机号']);
        exit;
    }
    
    // 验证短信验证码是否为空
if (empty($sms_code)) {
        echo json_encode(['success' => false, 'message' => '请输入验证码']);
        exit;
    }
    
    if (!isset($_SESSION['sms_code']) || !isset($_SESSION['sms_phone']) || !isset($_SESSION['sms_expire'])) {
        echo json_encode(['success' => false, 'message' => '验证码已失效，请重新获取']);
        exit;
    }
    
    if ($_SESSION['sms_phone'] !== $phone) {
        echo json_encode(['success' => false, 'message' => '手机号不一致']);
        exit;
    }
    
    if (time() > $_SESSION['sms_expire']) {
        echo json_encode(['success' => false, 'message' => '验证码已过期']);
        exit;
    }
    
    if ($_SESSION['sms_code'] != $sms_code) {
        echo json_encode(['success' => false, 'message' => '验证码错误']);
        exit;
    }
    
    try {
        // 检查手机号是否已被其他用户使用
        $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
        $stmt->execute([$phone, $user_id]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => '该手机号已被绑定']);
            exit;
        }
        
        // 更新用户手机�?        
$stmt = $conn->prepare("UPDATE users SET phone = ? WHERE id = ?");
        $stmt->execute([$phone, $user_id]);
        
        // 清除短信session
        unset($_SESSION['sms_code']);
        unset($_SESSION['sms_phone']);
        unset($_SESSION['sms_expire']);
        
        echo json_encode(['success' => true, 'message' => '绑定成功']);
        
    } catch (PDOException $e) {
        error_log("Bind Phone Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '系统错误，请稍后重试']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => '无效的操作']);
