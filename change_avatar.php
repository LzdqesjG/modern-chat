<?php
// 关闭错误显示，防止破坏JSON格式
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 设置JSON头
header('Content-Type: application/json; charset=utf-8');

// 启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
check_api_access();
require_once 'db.php';

$response = ['success' => false, 'message' => '未知错误'];

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    // 检查是否有文件上传
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = '请选择要上传的头像文件';
        if (isset($_FILES['avatar']['error'])) {
            switch ($_FILES['avatar']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                    $error_msg = '文件大小超过了php.ini中upload_max_filesize选项限制的值';
                    break;
                case UPLOAD_ERR_FORM_SIZE:
                    $error_msg = '文件大小超过了HTML表单中MAX_FILE_SIZE选项指定的值';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $error_msg = '文件只有部分被上传';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $error_msg = '没有文件被上传';
                    break;
            }
        }
        throw new Exception($error_msg);
    }

    // 获取用户ID
    $user_id = $_SESSION['user_id'];

    // 允许的文件类型
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    // 允许的文件扩展名
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

    // 获取文件信息
    $file = $_FILES['avatar'];
    $file_type = $file['type'];
    $file_size = $file['size'];
    $file_tmp = $file['tmp_name'];

    // 获取文件扩展名
    $file_info = pathinfo($file['name']);
    $file_extension = strtolower($file_info['extension'] ?? '');

    // 验证文件扩展名是否在白名单中
    if (!in_array($file_extension, $allowed_extensions)) {
        throw new Exception('只允许上传JPG、PNG或GIF格式的图片');
    }

    // 检查文件类型
    if (!in_array($file_type, $allowed_types)) {
         // 尝试通过扩展名判断（有些浏览器可能不发送正确的MIME类型）
         if (!in_array($file_extension, $allowed_extensions)) {
             throw new Exception('只允许上传JPG、PNG或GIF格式的图片');
         }
    }

    // 检查文件大小（限制为5MB）
    $max_size = 5 * 1024 * 1024;
    if ($file_size > $max_size) {
        throw new Exception('图片大小不能超过5MB');
    }

    // 定义上传目录
    $upload_dir = 'uploads/avatars/';

    // 确保目录存在
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('无法创建上传目录');
        }
    }

    // 处理图片，调整为32*32像素
    $image_info = getimagesize($file_tmp);
    if ($image_info === false) {
        throw new Exception('无效的图片文件');
    }
    list($original_width, $original_height) = $image_info;

    // 生成文件名 - 使用强制安全的扩展名（从MIME类型映射）
    $ext_map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif'
    ];
    $safe_ext = $ext_map[$image_info['mime']];
    $new_filename = $user_id . '_' . time() . '.' . $safe_ext;
    $file_path = $upload_dir . $new_filename;

    // 创建新的图片资源
    $new_width = 32;
    $new_height = 32;

    // 根据文件类型创建图片资源
    $source_image = null;
    switch ($image_info['mime']) { // 使用getimagesize返回的mime类型更可靠
        case 'image/jpeg':
            $source_image = imagecreatefromjpeg($file_tmp);
            break;
        case 'image/png':
            $source_image = imagecreatefrompng($file_tmp);
            break;
        case 'image/gif':
            $source_image = imagecreatefromgif($file_tmp);
            break;
        default:
            throw new Exception('不支持的图片类型');
    }

    if (!$source_image) {
        throw new Exception('无法处理图片文件');
    }

    // 创建目标图片资源
    $destination_image = imagecreatetruecolor($new_width, $new_height);

    // 保留PNG和GIF的透明度
    if ($image_info['mime'] == 'image/png' || $image_info['mime'] == 'image/gif') {
        imagealphablending($destination_image, false);
        imagesavealpha($destination_image, true);
        $transparent = imagecolorallocatealpha($destination_image, 255, 255, 255, 127);
        imagefilledrectangle($destination_image, 0, 0, $new_width, $new_height, $transparent);
    }

    // 调整图片大小
    imagecopyresampled($destination_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $original_width, $original_height);

    // 保存调整后的图片
    $save_result = false;
    switch ($image_info['mime']) {
        case 'image/jpeg':
            $save_result = imagejpeg($destination_image, $file_path, 90);
            break;
        case 'image/png':
            $save_result = imagepng($destination_image, $file_path, 9);
            break;
        case 'image/gif':
            $save_result = imagegif($destination_image, $file_path);
            break;
    }

    // 释放图片资源
    imagedestroy($source_image);
    imagedestroy($destination_image);

    if (!$save_result) {
        throw new Exception('保存图片失败');
    }

    // 检查数据库连接
    if (!isset($conn)) {
        throw new Exception('数据库连接失败');
    }

    // 更新用户头像
    $avatar_url = $file_path;
    $sql = "UPDATE users SET avatar = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt->execute([$avatar_url, $user_id])) {
        // 删除旧头像（如果存在）
        try {
            $sql = "SELECT avatar FROM users WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$user_id]);
            $old_avatar = $stmt->fetchColumn();
            
            if ($old_avatar && $old_avatar !== $avatar_url && file_exists($old_avatar)) {
                // 检查是否是默认头像或外部链接，避免误删
                if (strpos($old_avatar, 'uploads/') !== false) {
                    @unlink($old_avatar);
                }
            }
        } catch (Exception $e) {
            // 忽略删除旧头像的错误
            error_log("Delete old avatar failed: " . $e->getMessage());
        }
        
        $response['success'] = true;
        $response['message'] = '头像修改成功';
        $response['avatar_url'] = $avatar_url;
    } else {
        // 删除已上传的图片
        if (file_exists($file_path)) {
            unlink($file_path);
        }
        throw new Exception('数据库更新失败');
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>