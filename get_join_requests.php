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

    // 检查是否是GET请求
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        echo json_encode(['success' => false, 'message' => '无效的请求方法']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $group_id = isset($_GET['group_id']) ? intval($_GET['group_id']) : 0;

    // 验证数据
    if (!$group_id) {
        echo json_encode(['success' => false, 'message' => '请选择群聊']);
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
        echo json_encode(['success' => false, 'message' => '您没有权限查看入群申请']);
        exit;
    }

    // 获取入群申请列表
    $requests = $group->getJoinRequests($group_id);

    echo json_encode(['success' => true, 'requests' => $requests]);
} catch (Exception $e) {
    // 捕获所有异常并返回错误信息
    $error_msg = "服务器内部错误: " . $e->getMessage();
    error_log($error_msg);
    echo json_encode(['success' => false, 'message' => $error_msg]);
}
?>