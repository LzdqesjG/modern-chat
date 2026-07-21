<?php
// 包含数据库连接文件
require_once 'config.php';
check_api_access();
include 'db.php';

// 允许跨域请求 - 从配置文件读取
$allowed_origins = getConfig('cors_allowed_origins', ['http://localhost', 'http://127.0.0.1']);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Content-Type: application/json');

// 检查数据库连接
if (!$conn) {
    echo json_encode([
        'success' => false,
        'message' => '数据库连接失败'
    ]);
    exit;
}

// 检查是否有qid参数
if (isset($_GET['qid'])) {
    $qid = $_GET['qid'];

    try {
        // 准备查询语句
        $stmt = $conn->prepare("SELECT ip_address FROM scan_login WHERE qid = :qid AND status = 'pending' LIMIT 1");
        $stmt->bindParam(':qid', $qid);
        $stmt->execute();

        // 获取结果
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            // 返回成功结果
            echo json_encode([
                'success' => true,
                'ip_address' => $result['ip_address']
            ]);
        } else {
            // 没有找到记录
            echo json_encode([
                'success' => false,
                'message' => '未找到扫码记录'
            ]);
        }
    } catch (PDOException $e) {
        // 数据库错误
        echo json_encode([
            'success' => false,
            'message' => '数据库查询错误'
        ]);
    }
} else {
    // 缺少参数
    echo json_encode([
        'success' => false,
        'message' => '缺少必要参数'
    ]);
}
