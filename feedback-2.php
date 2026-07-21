<?php

/**
 * 反馈处理页面
 * 处理反馈的提交和管理
 */
require_once 'config.php';
require_once 'db.php';
require_once 'Feedback-1.php';
check_api_access();

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
/** @var PDO $conn */
$feedback = new Feedback($conn);

// 检查feedback表是否存在
/** @var PDO $conn */
$stmt = $conn->prepare("SHOW TABLES LIKE 'feedback'");
$stmt->execute();
$table_exists = $stmt->fetch();
error_log('Feedback table exists: ' . ($table_exists ? 'Yes' : 'No'));

// 如果表不存在，创建表
if (!$table_exists) {
    $create_table_sql = "
    CREATE TABLE feedback (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        content TEXT NOT NULL,
        image_path VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending', 'received', 'fixed') DEFAULT 'pending',
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $conn->exec($create_table_sql);
    error_log('Created feedback table');
}

// 检查是否有反馈数据
/** @var PDO $conn */
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM feedback");
$stmt->execute();
$count_result = $stmt->fetch(PDO::FETCH_ASSOC);
error_log('Feedback count: ' . $count_result['count']);

// 检查所有反馈数据
/** @var PDO $conn */
$stmt = $conn->prepare("SELECT * FROM feedback");
$stmt->execute();
$all_feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
error_log('All feedbacks: ' . print_r($all_feedbacks, true));

// 处理反馈提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'submit_feedback') {
        $content = $_POST['content'];
        $image_path = null;

        // 处理图片上传
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['image'];

            // 定义允许的图片扩展名白名单
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            // 获取并清理文件扩展名（转小写）
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            // 验证文件扩展名是否在白名单中
            if (!in_array($file_ext, $allowed_extensions)) {
                echo json_encode(['success' => false, 'message' => '只允许上传图片文件（jpg, jpeg, png, gif, webp）']);
                exit;
            }

            // 验证文件类型（MIME类型检查）
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $file_info = getimagesize($file['tmp_name']);

            if (!$file_info || !in_array($file_info['mime'], $allowed_types)) {
                echo json_encode(['success' => false, 'message' => '文件类型验证失败']);
                exit;
            }

            // 额外的文件头验证（防止MIME类型伪造）
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime_type, $allowed_types)) {
                echo json_encode(['success' => false, 'message' => '文件头验证失败']);
                exit;
            }

            // 验证文件大小（限制为5MB）
            $max_size = 5 * 1024 * 1024;
            if ($file['size'] > $max_size) {
                echo json_encode(['success' => false, 'message' => '文件大小不能超过5MB']);
                exit;
            }

            // 生成唯一文件名 - 使用强制安全的扩展名（从MIME类型映射）
            $ext_map = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp'
            ];
            $safe_ext = $ext_map[$mime_type];
            $new_filename = 'feedback_' . $user_id . '_' . time() . '.' . $safe_ext;
            $upload_dir = 'uploads/feedback/';

            // 创建目录如果不存在
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            // 移动文件
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_filename)) {
                $image_path = $upload_dir . $new_filename;
            } else {
                echo json_encode(['success' => false, 'message' => '文件上传失败']);
                exit;
            }
        }

        $result = $feedback->submitFeedback($user_id, $content, $image_path);
        echo json_encode($result);
        exit;
    } elseif ($_POST['action'] === 'mark_received' && isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
        $feedback_id = intval($_POST['feedback_id']);
        $result = $feedback->updateFeedbackStatus($feedback_id, 'received');
        echo json_encode($result);
        exit;
    } elseif ($_POST['action'] === 'delete_feedback' && isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
        $feedback_id = intval($_POST['feedback_id']);
        $result = $feedback->deleteFeedback($feedback_id);
        echo json_encode($result);
        exit;
    }
}

// 检查是否是管理员
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'];

// 如果是管理员，显示反馈管理页面
if ($is_admin) {
    $feedbacks = $feedback->getAllFeedback();
    // 添加调试信息
    error_log('Number of feedbacks found: ' . count($feedbacks));
    error_log('Feedbacks data: ' . print_r($feedbacks, true));
    include 'admin_feedback.php';
    exit;
}

// 如果是普通用户，重定向到聊天页面
header('Location: chat.php');
exit;
