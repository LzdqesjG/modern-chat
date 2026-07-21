<?php
require_once 'config.php';
require_once 'db.php';
check_api_access();

header('Content-Type: application/json');

if ($conn === null) {
    echo json_encode(['success' => false, 'message' => '数据库连接失败']);
    exit;
}

function getCurrentUserId() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['user_id'])) {
        return intval($_SESSION['user_id']);
    }
    
    return 0;
}

function generateVkey() {
    return bin2hex(random_bytes(32));
}

function getVkeyByUserId($conn, $user_id) {
    try {
        $stmt = $conn->prepare("SELECT vkey FROM users WHERE id = ? AND is_deleted = FALSE");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        return $result ? $result['vkey'] : null;
    } catch (PDOException $e) {
        error_log("Get vkey error: " . $e->getMessage());
        return null;
    }
}

function getUserIdByVkey($conn, $vkey) {
    try {
        $stmt = $conn->prepare("SELECT id FROM users WHERE vkey = ? AND is_deleted = FALSE");
        $stmt->execute([$vkey]);
        $result = $stmt->fetch();
        return $result ? $result['id'] : null;
    } catch (PDOException $e) {
        error_log("Get user by vkey error: " . $e->getMessage());
        return null;
    }
}

function updateVkey($conn, $user_id, $vkey = null) {
    if ($vkey === null) {
        $vkey = generateVkey();
    }
    
    try {
        $stmt = $conn->prepare("UPDATE users SET vkey = ? WHERE id = ?");
        $stmt->execute([$vkey, $user_id]);
        return $vkey;
    } catch (PDOException $e) {
        error_log("Update vkey error: " . $e->getMessage());
        return null;
    }
}

function createVkeyIfNotExists($conn, $user_id) {
    $existing_vkey = getVkeyByUserId($conn, $user_id);
    
    if ($existing_vkey) {
        return $existing_vkey;
    }
    
    return updateVkey($conn, $user_id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    switch ($action) {
        case 'generate':
            $user_id = getCurrentUserId();
            
            if ($user_id <= 0) {
                echo json_encode(['success' => false, 'message' => '请先登录']);
                exit;
            }
            
            $vkey = createVkeyIfNotExists($conn, $user_id);
            
            if ($vkey) {
                echo json_encode(['success' => true, 'vkey' => $vkey]);
            } else {
                echo json_encode(['success' => false, 'message' => '生成密钥失败']);
            }
            break;
            
        case 'refresh':
            $user_id = getCurrentUserId();
            
            if ($user_id <= 0) {
                echo json_encode(['success' => false, 'message' => '请先登录']);
                exit;
            }
            
            $vkey = updateVkey($conn, $user_id);
            
            if ($vkey) {
                echo json_encode(['success' => true, 'vkey' => $vkey]);
            } else {
                echo json_encode(['success' => false, 'message' => '刷新密钥失败']);
            }
            break;
            
        case 'validate':
            $vkey = isset($_POST['vkey']) ? $_POST['vkey'] : '';
            
            if (empty($vkey)) {
                echo json_encode(['success' => false, 'message' => '密钥错误，请重新尝试！']);
                exit;
            }
            
            $user_id = getUserIdByVkey($conn, $vkey);
            
            if ($user_id) {
                echo json_encode(['success' => true, 'user_id' => $user_id]);
            } else {
                echo json_encode(['success' => false, 'message' => '密钥错误，请重新尝试！']);
            }
            break;
            
        case 'get':
            $user_id = getCurrentUserId();
            
            if ($user_id <= 0) {
                echo json_encode(['success' => false, 'message' => '请先登录']);
                exit;
            }
            
            $vkey = getVkeyByUserId($conn, $user_id);
            
            if ($vkey) {
                echo json_encode(['success' => true, 'vkey' => $vkey]);
            } else {
                echo json_encode(['success' => false, 'message' => '未找到密钥']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '无效的操作']);
            break;
    }
} else {
    echo json_encode(['success' => false, 'message' => '非法请求']);
}
?>