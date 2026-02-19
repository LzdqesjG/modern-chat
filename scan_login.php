<?php
require_once 'config.php';
require_once 'db.php';

// 确保scan_login表结构正确
try {
    // 检查scan_login表是否有browser_fingerprint字段
    $stmt = $conn->prepare("SHOW COLUMNS FROM scan_login LIKE 'browser_fingerprint'");
    $stmt->execute();
    $browser_fingerprint_column_exists = $stmt->fetch();
    
    if (!$browser_fingerprint_column_exists) {
        // 添加browser_fingerprint字段
        $conn->exec("ALTER TABLE scan_login ADD COLUMN browser_fingerprint VARCHAR(100) NULL AFTER ip_address");
        error_log("Added browser_fingerprint column to scan_login table");
    }
    
    // 更新status字段的枚举值，添加scanned和rejected状态
    $conn->exec("ALTER TABLE scan_login MODIFY COLUMN status ENUM('pending', 'scanned', 'success', 'expired', 'used', 'rejected') DEFAULT 'pending'");
    error_log("Updated scan_login status enum values");
} catch (PDOException $e) {
    error_log("Field setup error: " . $e->getMessage());
}

// 生成唯一的qid
function generateQid() {
    return uniqid('scan_', true) . rand(1000, 9999);
}

// 更新过期的IP封禁
function updateExpiredIpBans($conn) {
    try {
        $stmt = $conn->prepare("UPDATE ip_bans SET status = 'expired' WHERE status = 'active' AND ban_end <= NOW()");
        $stmt->execute();
        return true;
    } catch (PDOException $e) {
        error_log("Update Expired IP Bans Error: " . $e->getMessage());
        return false;
    }
}

// 检查IP是否被封禁
function isIpBanned($conn, $ip_address) {
    // 先更新过期的封禁
    updateExpiredIpBans($conn);
    
    try {
        $stmt = $conn->prepare("SELECT * FROM ip_bans WHERE ip_address = ? AND status = 'active'");
        $stmt->execute([$ip_address]);
        $ban = $stmt->fetch();
        return $ban;
    } catch (PDOException $e) {
        error_log("Check IP Ban Error: " . $e->getMessage());
        return false;
    }
}

// 更新过期的浏览器封禁
function updateExpiredBrowserBans($conn) {
    try {
        $stmt = $conn->prepare("UPDATE browser_bans SET status = 'expired' WHERE status = 'active' AND ban_end <= NOW()");
        $stmt->execute();
        return true;
    } catch (PDOException $e) {
        error_log("Update Expired Browser Bans Error: " . $e->getMessage());
        return false;
    }
}

// 检查浏览器指纹是否被封禁
function isBrowserBanned($conn, $fingerprint) {
    // 先更新过期的封禁
    updateExpiredBrowserBans($conn);
    
    try {
        $stmt = $conn->prepare("SELECT * FROM browser_bans WHERE fingerprint = ? AND status = 'active'");
        $stmt->execute([$fingerprint]);
        $ban = $stmt->fetch();
        return $ban;
    } catch (PDOException $e) {
        error_log("Check Browser Ban Error: " . $e->getMessage());
        return false;
    }
}

// 主处理逻辑
if (isset($_GET['check_status'])) {
    // 检查登录状态
    $qid = isset($_GET['qid']) ? $_GET['qid'] : '';
    
    if (empty($qid)) {
        echo json_encode(['success' => false, 'message' => '参数错误']);
        exit;
    }
    
    try {
        // 优化：添加qid索引（如果不存在）
        $stmt = $conn->prepare("SHOW INDEX FROM scan_login WHERE Key_name = 'idx_qid'");
        $stmt->execute();
        $index_exists = $stmt->fetch();
        
        if (!$index_exists) {
            $conn->exec("CREATE INDEX idx_qid ON scan_login(qid)");
            error_log("Created index idx_qid on scan_login table");
        }
        
        // 优化：使用索引查询，提高速度
        $sql = "SELECT * FROM scan_login WHERE qid = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$qid]);
        $scan_record = $stmt->fetch();
        
        if (!$scan_record) {
            echo json_encode(['status' => 'expired', 'message' => '二维码已过期']);
            exit;
        }
        
        if (strtotime($scan_record['expire_at']) < time()) {
            $sql = "UPDATE scan_login SET status = 'expired' WHERE qid = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$qid]);
            echo json_encode(['status' => 'expired', 'message' => '二维码已过期']);
        } elseif ($scan_record['status'] === 'success') {
            $user_id = $scan_record['user_id'];
            $token = bin2hex(random_bytes(32));
            $token_expire_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            
            $sql = "UPDATE scan_login SET status = 'success', token = ?, token_expire_at = ? WHERE qid = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$token, $token_expire_at, $qid]);
            
            echo json_encode(['status' => 'success', 'token' => $token, 'message' => '登录成功']);
        } elseif ($scan_record['status'] === 'scanned') {
            echo json_encode(['status' => 'scanned', 'message' => '等待手机确认登录']);
        } elseif ($scan_record['status'] === 'rejected') {
            echo json_encode(['status' => 'rejected', 'message' => '手机端拒绝了登录请求，请重试']);
        } else {
            echo json_encode(['status' => 'pending', 'message' => '等待扫描']);
        }
    } catch(PDOException $e) {
        error_log('Check login status error: ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '检查登录状态失败']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qid = isset($_POST['qid']) ? $_POST['qid'] : '';
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $user = isset($_POST['user']) ? $_POST['user'] : '';
    $source = isset($_POST['source']) ? $_POST['source'] : '';
    
    if (empty($qid) || empty($source)) {
        echo json_encode(['success' => false, 'message' => '参数错误: 缺少必要参数']);
        exit;
    }
    
    if (!in_array($source, ['mobilechat.php', 'Newchatmobile.php'])) {
        echo json_encode(['success' => false, 'message' => '非法请求来源: ' . $source]);
        exit;
    }
    
    try {
        // 检查二维码是否存在且未过期
        $sql = "SELECT * FROM scan_login WHERE qid = ? AND expire_at > NOW()";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$qid]);
        $scan_record = $stmt->fetch();
        
        if (!$scan_record) {
            echo json_encode(['success' => false, 'message' => '二维码已过期或无效']);
            exit;
        }
        
        // 处理扫描动作（更新状态为scanned）
        if ($action === 'scan') {
            $sql = "UPDATE scan_login SET status = 'scanned' WHERE qid = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$qid]);
            echo json_encode(['success' => true, 'message' => '扫描状态更新成功']);
        }
        // 处理拒绝登录动作（更新状态为rejected）
        elseif ($action === 'reject') {
            $sql = "UPDATE scan_login SET status = 'rejected' WHERE qid = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$qid]);
            echo json_encode(['success' => true, 'message' => '已拒绝登录请求']);
        }
        // 处理登录动作（更新状态为success）
        else {
            if (empty($user)) {
                echo json_encode(['success' => false, 'message' => '参数错误: 缺少用户名']);
                exit;
            }
            
            $username = $user;
            
            // 获取用户ID
            $sql = "SELECT id FROM users WHERE username = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$username]);
            $user_data = $stmt->fetch();
            
            if (!$user_data) {
                echo json_encode(['success' => false, 'message' => '用户不存在: ' . $username]);
                exit;
            }
            
            $user_id = $user_data['id'];
            
            $sql = "UPDATE scan_login SET status = 'success', user_id = ?, login_source = ? WHERE qid = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user_id, $source, $qid]);
            
            echo json_encode(['success' => true, 'message' => '登录成功', 'debug' => 'qid: ' . $qid . ', user: ' . $user . ', user_id: ' . $user_id]);
        }
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['qid'])) {
    $qid = generateQid();
    $ip_address = getUserIP();
    $browser_fingerprint = isset($_GET['browser_fingerprint']) ? $_GET['browser_fingerprint'] : '';
    $expire_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    
    try {
        $ip_ban_info = isIpBanned($conn, $ip_address);
        if ($ip_ban_info) {
            echo json_encode(['success' => false, 'message' => '当前IP地址已被封禁，无法生成登录二维码']);
            exit;
        }
        
        if (!empty($browser_fingerprint)) {
            $browser_ban_info = isBrowserBanned($conn, $browser_fingerprint);
            if ($browser_ban_info) {
                echo json_encode(['success' => false, 'message' => '当前浏览器已被封禁，无法生成登录二维码']);
                exit;
            }
        }
        
        $sql = "INSERT INTO scan_login (qid, expire_at, ip_address, status, browser_fingerprint) VALUES (?, ?, ?, 'pending', ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$qid, $expire_at, $ip_address, $browser_fingerprint]);
        
        $domain = $_SERVER['HTTP_HOST'];
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $qr_content = "$protocol://$domain/chat/scan_login.php?qid=$qid";
        
        $response = [
            'success' => true,
            'qid' => $qid,
            'qr_content' => $qr_content,
            'expire_at' => $expire_at
        ];
        echo json_encode($response);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => '生成登录二维码失败']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['qid'])) {
    $qid = $_GET['qid'];
    $browser_fingerprint = isset($_GET['browser_fingerprint']) ? $_GET['browser_fingerprint'] : '';
    
    try {
        if (!empty($browser_fingerprint)) {
            $browser_ban_info = isBrowserBanned($conn, $browser_fingerprint);
            if ($browser_ban_info) {
                echo "<h1>登录失败</h1><p>当前浏览器已被封禁，无法登录</p>";
                exit;
            }
        }
        
        $domain = $_SERVER['HTTP_HOST'];
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        header("Location: $protocol://$domain/chat/mobilechat.php?action=scan_login&qid=$qid");
        exit;
    } catch(PDOException $e) {
        echo "<h1>错误</h1><p>处理登录请求失败</p>";
        exit;
    }
} else {
    echo json_encode(['success' => false, 'message' => '非法访问']);
}
?>