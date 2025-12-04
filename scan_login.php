<?php
require_once 'db.php';

// 获取用户IP地址
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

// 生成唯一的qid
function generateQid() {
    return uniqid('scan_', true) . rand(1000, 9999);
}

// 主处理逻辑
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 生成二维码和qid
    $qid = generateQid();
    $ip_address = getUserIP();
    $expire_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    
    // 插入数据库
    $sql = "INSERT INTO scan_login (qid, expire_at, ip_address, status) VALUES (?, ?, ?, 'pending')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $qid, $expire_at, $ip_address);
    
    if ($stmt->execute()) {
        // 生成二维码内容（临时链接）
        $domain = $_SERVER['HTTP_HOST'];
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $qr_content = "$protocol://$domain/chat/scan_login.php?qid=$qid";
        
        // 返回qid和二维码内容
        $response = [
            'success' => true,
            'qid' => $qid,
            'qr_content' => $qr_content,
            'expire_at' => $expire_at
        ];
        echo json_encode($response);
    } else {
        echo json_encode(['success' => false, 'message' => '生成登录二维码失败']);
    }
    $stmt->close();
    $conn->close();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 处理手机端登录请求
    $qid = isset($_POST['qid']) ? $_POST['qid'] : '';
    $username = isset($_POST['username']) ? $_POST['username'] : '';
    $source = isset($_POST['source']) ? $_POST['source'] : '';
    
    // 验证参数
    if (empty($qid) || empty($username) || empty($source)) {
        echo json_encode(['success' => false, 'message' => '参数错误']);
        exit;
    }
    
    // 验证来源是否为mobilechat.php
    if ($source !== 'mobilechat.php') {
        echo json_encode(['success' => false, 'message' => '非法请求来源']);
        exit;
    }
    
    // 检查二维码是否存在且未过期
    $sql = "SELECT * FROM scan_login WHERE qid = ? AND status = 'pending' AND expire_at > NOW()";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $qid);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '二维码已过期或无效']);
        $stmt->close();
        $conn->close();
        exit;
    }
    
    $scan_record = $result->fetch_assoc();
    
    // 获取用户ID
    $sql = "SELECT id FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user_result = $stmt->get_result();
    
    if ($user_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '用户不存在']);
        $stmt->close();
        $conn->close();
        exit;
    }
    
    $user = $user_result->fetch_assoc();
    $user_id = $user['id'];
    
    // 更新扫码登录状态
    $sql = "UPDATE scan_login SET status = 'success', user_id = ?, login_source = ? WHERE qid = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $user_id, $source, $qid);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '登录成功']);
    } else {
        echo json_encode(['success' => false, 'message' => '登录失败']);
    }
    
    $stmt->close();
    $conn->close();
} elseif (isset($_GET['check_status'])) {
    // 检查登录状态
    $qid = isset($_GET['qid']) ? $_GET['qid'] : '';
    
    if (empty($qid)) {
        echo json_encode(['success' => false, 'message' => '参数错误']);
        exit;
    }
    
    // 检查登录状态
    $sql = "SELECT * FROM scan_login WHERE qid = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $qid);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['status' => 'expired', 'message' => '二维码已过期']);
        $stmt->close();
        $conn->close();
        exit;
    }
    
    $scan_record = $result->fetch_assoc();
    
    // 检查是否过期
    if (strtotime($scan_record['expire_at']) < time()) {
        // 更新为过期状态
        $sql = "UPDATE scan_login SET status = 'expired' WHERE qid = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $qid);
        $stmt->execute();
        echo json_encode(['status' => 'expired', 'message' => '二维码已过期']);
    } elseif ($scan_record['status'] === 'success') {
        // 登录成功
        echo json_encode(['status' => 'success', 'user_id' => $scan_record['user_id'], 'message' => '登录成功']);
    } else {
        // 等待扫描
        echo json_encode(['status' => 'pending', 'message' => '等待扫描']);
    }
    
    $stmt->close();
    $conn->close();
} else {
    // 直接访问时返回错误
    echo json_encode(['success' => false, 'message' => '非法访问']);
}
?>
