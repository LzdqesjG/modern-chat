<?php
// 检查用户是否登录
require_once 'config.php';
require_once 'db.php';
require_once 'User.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// 创建User实例
$user = new User($conn);

// 获取用户信息
$current_user = $user->getUserById($user_id);

// 处理表单提交
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    
    // 获取用户名最大长度配置
    $user_name_max = getUserNameMaxLength();
    
    // 验证表单数据
    if (strlen($username) < 3 || strlen($username) > $user_name_max) {
        $errors[] = "用户名长度必须在3-{$user_name_max}个字符之间";
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = '请输入有效的邮箱地址';
    }
    
    // 检查用户名是否已被使用
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt->execute([$username, $user_id]);
    if ($stmt->rowCount() > 0) {
        $errors[] = '用户名已被使用';
    }
    
    // 检查邮箱是否已被使用
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $user_id]);
    if ($stmt->rowCount() > 0) {
        $errors[] = '邮箱已被使用';
    }
    
    if (empty($errors)) {
        // 处理头像更新
        $avatar = $current_user['avatar'];
        
        // 检查是否有裁剪后的头像
        if (isset($_POST['cropped_avatar']) && !empty($_POST['cropped_avatar'])) {
            $cropped_avatar = $_POST['cropped_avatar'];
            
            // 保存裁剪后的头像到服务器
            $avatar_data = base64_decode(preg_replace('#^data:image/[^;]+;base64,#', '', $cropped_avatar));
            $avatar_filename = 'avatars/' . uniqid() . '.png';
            $avatar_path = __DIR__ . '/' . $avatar_filename;
            
            // 确保avatars目录存在
            if (!is_dir(__DIR__ . '/avatars')) {
                mkdir(__DIR__ . '/avatars', 0777, true);
            }
            
            // 保存头像文件
            file_put_contents($avatar_path, $avatar_data);
            $avatar = $avatar_filename;
        }
        // 检查是否有头像URL
        elseif (isset($_POST['avatar_url']) && !empty($_POST['avatar_url'])) {
            $avatar_url = trim($_POST['avatar_url']);
            $avatar = $avatar_url;
        }
        
        // 更新用户信息
        $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, avatar = ? WHERE id = ?");
        $stmt->execute([$username, $email, $avatar, $user_id]);
        
        // 更新会话中的用户信息
        $_SESSION['username'] = $username;
        $_SESSION['email'] = $email;
        
        $success = '资料更新成功';
        
        // 重新获取用户信息
        $current_user = $user->getUserById($user_id);
    }
    
    // 处理密码修改
    if (isset($_POST['current_password']) || isset($_POST['new_password']) || isset($_POST['confirm_password'])) {
        // 检查是否所有密码字段都已填写
        if (empty($_POST['current_password'])) {
            $errors[] = '请输入原密码';
        }
        if (empty($_POST['new_password'])) {
            $errors[] = '请输入新密码';
        }
        if (empty($_POST['confirm_password'])) {
            $errors[] = '请再次输入新密码';
        }
        
        if (empty($errors)) {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            // 验证原密码是否正确
            if (!password_verify($current_password, $current_user['password'])) {
                $errors[] = '原密码错误';
            }
            
            // 验证新密码长度
            if (strlen($new_password) < 6) {
                $errors[] = '新密码长度必须至少6个字符';
            }
            
            // 验证新密码和确认密码是否匹配
            if ($new_password !== $confirm_password) {
                $errors[] = '两次输入的新密码不匹配';
            }
            
            if (empty($errors)) {
                // 更新密码
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT, ['cost' => 12]);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
                
                // 密码更新成功后，退出登录并跳转到登录页面
                session_destroy();
                header('Location: login.php?success=' . urlencode('密码修改成功，请重新登录'));
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑资料 - Modern Chat</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            padding: 40px;
            width: 100%;
            max-width: 500px;
            animation: slideUp 0.6s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 28px;
            font-weight: 600;
        }
        
        .profile-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 40px;
            margin: 0 auto 15px;
        }
        
        .profile-name {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .profile-email {
            font-size: 14px;
            color: #666;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #fafafa;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 15px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn-secondary {
            background: #9e9e9e;
        }
        
        .btn-secondary:hover {
            background: #757575;
            box-shadow: 0 8px 20px rgba(158, 158, 158, 0.3);
        }
        
        .error-message {
            background: #fee;
            color: #d32f2f;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #fcc;
        }
        
        .success-message {
            background: #e8f5e8;
            color: #388e3c;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #c8e6c9;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
        }
        
        @media (max-width: 500px) {
            .container {
                margin: 20px;
                padding: 30px 20px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>编辑资料</h1>
        
        <div class="profile-header">
            <div class="profile-avatar"><?php echo substr($current_user['username'], 0, 2); ?></div>
            <div class="profile-name"><?php echo $current_user['username']; ?></div>
            <div class="profile-email"><?php echo $current_user['email']; ?></div>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="error-message">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo $error; ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($current_user['username']); ?>" required minlength="3" maxlength="<?php echo getUserNameMaxLength(); ?>">
            </div>
            
            <div class="form-group">
                <label for="email">邮箱</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($current_user['email']); ?>" required>
            </div>
            
            <!-- 头像上传 -->
            <div class="form-group">
                <label>头像</label>
                <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 15px;">
                    <!-- 当前头像 -->
                    <div style="position: relative;">
                        <img id="current-avatar" src="<?php echo !empty($current_user['avatar']) && $current_user['avatar'] !== 'default_avatar.png' ? $current_user['avatar'] : 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTAwIiBoZWlnaHQ9IjEwMCIgdmlld0JveD0iMCAwIDEwMCAxMDAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxyZWN0IHdpZHRoPSIxMDAiIGhlaWdodD0iMTAwIiBmaWxsPSIjNjY3ZWVhIi8+CjxyZWN0IHg9IjI1IiB5PSIyNSIgd2lkdGg9IjUwIiBoZWlnaHQ9IjUwIiBmaWxsPSIjNzY0YmEyIi8+Cjwvc3ZnPgo='; ?>" alt="当前头像" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid #667eea;">
                    </div>
                    
                    <!-- 头像上传选项 -->
                    <div style="flex: 1;">
                        <div style="margin-bottom: 10px;">
                            <label for="avatar-file" style="display: inline-block; padding: 10px 20px; background: #667eea; color: white; border-radius: 8px; cursor: pointer; transition: all 0.2s ease;">选择图片</label>
                            <input type="file" id="avatar-file" name="avatar" accept="image/*" style="display: none;">
                        </div>
                        <div>
                            <label for="avatar-url" style="display: block; margin-bottom: 5px; font-size: 14px; color: #555;">或输入图片链接</label>
                            <input type="url" id="avatar-url" name="avatar_url" placeholder="https://example.com/avatar.jpg" style="width: 100%; padding: 8px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;">
                        </div>
                    </div>
                </div>
                
                <!-- 图片裁剪区域 -->
                <div id="crop-container" style="display: none; margin-top: 20px; border: 2px dashed #e0e0e0; border-radius: 8px; padding: 20px;">
                    <h3 style="margin-bottom: 15px; font-size: 16px; color: #333;">裁剪头像（32x32）</h3>
                    <div style="display: flex; gap: 20px;">
                        <div>
                            <canvas id="crop-canvas" width="200" height="200" style="border: 1px solid #e0e0e0;"></canvas>
                            <div style="margin-top: 10px;">
                                <button type="button" id="crop-btn" style="padding: 8px 16px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; margin-right: 10px;">裁剪</button>
                                <button type="button" id="cancel-crop-btn" style="padding: 8px 16px; background: #9e9e9e; color: white; border: none; border-radius: 6px; cursor: pointer;">取消</button>
                            </div>
                        </div>
                        <div>
                            <h4 style="margin-bottom: 10px; font-size: 14px; color: #555;">预览（32x32）</h4>
                            <img id="preview-avatar" src="" alt="预览" style="width: 32px; height: 32px; border: 1px solid #e0e0e0; border-radius: 50%; object-fit: cover;">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 密码修改 -->
            <div class="form-group" style="margin-top: 30px; border-top: 2px solid #f0f0f0; padding-top: 30px;">
                <h3 style="margin-bottom: 20px; font-size: 18px; color: #333;">修改密码</h3>
                
                <div class="form-group">
                    <label for="current_password">原密码</label>
                    <input type="password" id="current_password" name="current_password" placeholder="请输入原密码">
                </div>
                
                <div class="form-group">
                    <label for="new_password">新密码</label>
                    <input type="password" id="new_password" name="new_password" placeholder="请输入新密码" minlength="6">
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">重复新密码</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="请再次输入新密码" minlength="6">
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn">保存更改</button>
                <a href="mobilechat.php" class="btn btn-secondary" style="text-decoration: none; display: flex; align-items: center; justify-content: center;">取消</a>
            </div>
        </form>
        
        <script>
            // 图片裁剪相关变量
            let currentImage = null;
            let cropCanvas = null;
            let ctx = null;
            let isDragging = false;
            let startX = 0;
            let startY = 0;
            let imgX = 0;
            let imgY = 0;
            let scale = 1;
            
            // 初始化
            document.addEventListener('DOMContentLoaded', function() {
                cropCanvas = document.getElementById('crop-canvas');
                ctx = cropCanvas.getContext('2d');
                
                // 监听文件选择
                document.getElementById('avatar-file').addEventListener('change', handleFileSelect);
                
                // 监听裁剪按钮点击
                document.getElementById('crop-btn').addEventListener('click', cropImage);
                
                // 监听取消裁剪按钮点击
                document.getElementById('cancel-crop-btn').addEventListener('click', cancelCrop);
                
                // 监听鼠标事件进行裁剪
                cropCanvas.addEventListener('mousedown', startDrag);
                cropCanvas.addEventListener('mousemove', drag);
                cropCanvas.addEventListener('mouseup', stopDrag);
                cropCanvas.addEventListener('mouseleave', stopDrag);
            });
            
            // 处理文件选择
            function handleFileSelect(e) {
                const file = e.target.files[0];
                if (!file) return;
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        // 检查图片大小
                        if (img.width < 32 || img.height < 32) {
                            alert('图片大小必须满足32x32');
                            return;
                        }
                        
                        // 显示裁剪区域
                        document.getElementById('crop-container').style.display = 'block';
                        currentImage = img;
                        
                        // 初始化裁剪
                        initCrop(img);
                    };
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
            
            // 初始化裁剪
            function initCrop(img) {
                // 计算缩放比例，确保图片能覆盖画布
                const scaleX = cropCanvas.width / img.width;
                const scaleY = cropCanvas.height / img.height;
                scale = Math.max(scaleX, scaleY) * 1.5;
                
                // 居中显示图片
                imgX = (cropCanvas.width - img.width * scale) / 2;
                imgY = (cropCanvas.height - img.height * scale) / 2;
                
                drawImage();
            }
            
            // 绘制图片
            function drawImage() {
                ctx.clearRect(0, 0, cropCanvas.width, cropCanvas.height);
                
                // 绘制图片
                ctx.drawImage(currentImage, imgX, imgY, currentImage.width * scale, currentImage.height * scale);
                
                // 绘制裁剪框
                const cropSize = 150; // 放大的裁剪框大小
                const cropX = (cropCanvas.width - cropSize) / 2;
                const cropY = (cropCanvas.height - cropSize) / 2;
                
                ctx.strokeStyle = '#667eea';
                ctx.lineWidth = 2;
                ctx.setLineDash([10, 5]);
                ctx.strokeRect(cropX, cropY, cropSize, cropSize);
                ctx.setLineDash([]);
                
                // 绘制裁剪提示
                ctx.fillStyle = 'rgba(0, 0, 0, 0.5)';
                ctx.fillRect(0, 0, cropCanvas.width, cropY);
                ctx.fillRect(0, cropY, cropX, cropSize);
                ctx.fillRect(cropX + cropSize, cropY, cropCanvas.width - (cropX + cropSize), cropSize);
                ctx.fillRect(0, cropY + cropSize, cropCanvas.width, cropCanvas.height - (cropY + cropSize));
            }
            
            // 开始拖拽
            function startDrag(e) {
                if (!currentImage) return;
                isDragging = true;
                startX = e.offsetX - imgX;
                startY = e.offsetY - imgY;
                cropCanvas.style.cursor = 'grabbing';
            }
            
            // 拖拽中
            function drag(e) {
                if (!isDragging || !currentImage) return;
                imgX = e.offsetX - startX;
                imgY = e.offsetY - startY;
                drawImage();
            }
            
            // 停止拖拽
            function stopDrag() {
                isDragging = false;
                cropCanvas.style.cursor = 'grab';
            }
            
            // 裁剪图片
            function cropImage() {
                if (!currentImage) return;
                
                // 创建32x32的canvas
                const finalCanvas = document.createElement('canvas');
                finalCanvas.width = 32;
                finalCanvas.height = 32;
                const finalCtx = finalCanvas.getContext('2d');
                
                // 计算裁剪区域
                const cropSize = 150;
                const cropX = (cropCanvas.width - cropSize) / 2;
                const cropY = (cropCanvas.height - cropSize) / 2;
                
                // 从裁剪画布上裁剪32x32的区域
                finalCtx.drawImage(
                    cropCanvas,
                    cropX, cropY, cropSize, cropSize,
                    0, 0, 32, 32
                );
                
                // 更新预览
                const preview = document.getElementById('preview-avatar');
                preview.src = finalCanvas.toDataURL('image/png');
                
                // 更新当前头像
                const currentAvatar = document.getElementById('current-avatar');
                currentAvatar.src = preview.src;
                
                // 创建隐藏的input用于提交裁剪后的图片
                let hiddenInput = document.getElementById('cropped-avatar');
                if (!hiddenInput) {
                    hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.id = 'cropped-avatar';
                    hiddenInput.name = 'cropped_avatar';
                    document.querySelector('form').appendChild(hiddenInput);
                }
                hiddenInput.value = preview.src;
            }
            
            // 取消裁剪
            function cancelCrop() {
                document.getElementById('crop-container').style.display = 'none';
                currentImage = null;
                document.getElementById('avatar-file').value = '';
            }
        </script>
    </div>
</body>
</html>