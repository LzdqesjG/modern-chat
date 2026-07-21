<?php
// 禁用错误显示，只记录到日志
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// 设置错误日志
ini_set('error_log', 'error.log');

// 开始会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    require_once 'config.php';
    check_api_access();
    require_once 'db.php';
    require_once 'Group.php';

    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '用户未登录']);
        exit;
    }

    // 检查是否是POST请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '无效的请求方法']);
        exit;
    }

    // 获取请求数据
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        echo json_encode(['success' => false, 'message' => '无效的请求数据']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $request_id = isset($data['request_id']) ? intval($data['request_id']) : 0;
    $group_id = isset($data['group_id']) ? intval($data['group_id']) : 0;

    // 验证数据
    if (!$request_id || !$group_id) {
        echo json_encode(['success' => false, 'message' => '缺少必要参数']);
        exit;
    }

    // 检查数据库连接
    if (!$conn) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }

    // 创建Group实例
    $group = new Group($conn);

    // 检查用户是否是管理员或群主
    $is_admin = false;
    $group_info = $group->getGroupInfo($group_id);
    if ($group_info) {
        if ($group_info['owner_id'] == $user_id) {
            $is_admin = true;
        } else {
            $member = $group->getMemberRole($group_id, $user_id);
            if ($member && $member['is_admin']) {
                $is_admin = true;
            }
        }
    }

    if (!$is_admin) {
        echo json_encode(['success' => false, 'message' => '您没有权限批准入群申请']);
        exit;
    }

    // 批准入群申请
    $result = $group->approveJoinRequest($request_id, $group_id);

    if ($result) {
        echo json_encode(['success' => true, 'message' => '入群申请已批准']);
    } else {
        echo json_encode(['success' => false, 'message' => '操作失败，请稍后重试']);
    }
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>