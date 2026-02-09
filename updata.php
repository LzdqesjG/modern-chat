<?php
require_once 'config.php';
require_once 'db.php';
require_once 'User.php';

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// 检查用户是否为管理员
if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    // 非管理员，跳转到聊天页面
    header('Location: chat.php');
    exit;
}

// 处理AJAX更新请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'true') {
    // 设置无缓冲输出
    ob_end_clean();
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    
    // 处理撤销更新请求
    if (isset($_POST['action']) && $_POST['action'] === 'rollback') {
        echo "data: {\"status\": \"start\", \"message\": \"开始撤销更新...\"}\n\n";
        flush();
        
        // 检查old目录是否存在
        if (is_dir('old')) {
            try {
                $rollbackCount = 0;
                $rollbackFailed = 0;
                
                // 使用递归迭代器遍历old目录
                $dirIterator = new RecursiveDirectoryIterator('old', RecursiveDirectoryIterator::SKIP_DOTS);
                $iterator = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);
                
                // 先统计文件数量
                $filesToRestore = [];
                foreach ($iterator as $item) {
                    if ($item->isFile()) {
                        // 手动计算相对路径，避免兼容性问题
                        // $item->getPathname() 返回 like 'old\dir\file.php'
                        // 我们需要 'dir\file.php'
                        $pathName = $item->getPathname();
                        // 统一分隔符
                        $pathName = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $pathName);
                        $prefix = 'old' . DIRECTORY_SEPARATOR;
                        
                        $subPath = '';
                        if (strpos($pathName, $prefix) === 0) {
                            $subPath = substr($pathName, strlen($prefix));
                        } else {
                            // Fallback
                            $subPath = $item->getFilename();
                        }
                        
                        $filesToRestore[] = [
                            'path' => $item->getPathname(),
                            'subPath' => $subPath
                        ];
                    }
                }
                
                $totalFiles = count($filesToRestore);
                $currentFile = 0;
                
                foreach ($filesToRestore as $fileInfo) {
                    $currentFile++;
                    $progress = $totalFiles > 0 ? round(($currentFile / $totalFiles) * 100) : 100;
                    $oldFilePath = $fileInfo['path'];
                    $newFilePath = $fileInfo['subPath'];
                    
                    // 发送进度更新
                    echo "data: {\"status\": \"progress\", \"progress\": {$progress}, \"message\": \"恢复文件: {$newFilePath}\"}\n\n";
                    flush();
                    usleep(50000);
                    
                    // 确保目标目录存在
                    $destDir = dirname($newFilePath);
                    if ($destDir !== '.' && !is_dir($destDir)) {
                        @mkdir($destDir, 0777, true);
                    }
                    
                    // 恢复旧文件
                    if (copy($oldFilePath, $newFilePath)) {
                        $rollbackCount++;
                    } else {
                        $rollbackFailed++;
                    }
                }
                
                if ($rollbackCount > 0) {
                    $successMsg = "成功撤销更新，恢复了 {$rollbackCount} 个文件";
                    if ($rollbackFailed > 0) {
                        $successMsg .= "，失败 {$rollbackFailed} 个文件";
                    }
                    echo "data: {\"status\": \"complete\", \"success\": true, \"message\": \"{$successMsg}\"}\n\n";
                } else {
                    echo "data: {\"status\": \"complete\", \"success\": false, \"message\": \"撤销更新失败，没有可恢复的文件\"}\n\n";
                }
            } catch (Exception $e) {
                echo "data: {\"status\": \"complete\", \"success\": false, \"message\": \"撤销更新出错: " . $e->getMessage() . "\"}\n\n";
            }
        } else {
            echo "data: {\"status\": \"complete\", \"success\": false, \"message\": \"撤销更新失败，没有找到备份文件\"}\n\n";
        }
    }
    // 处理更新请求
    elseif (isset($_POST['action']) && $_POST['action'] === 'update') {
        echo "data: {\"status\": \"start\", \"message\": \"开始更新系统...\"}\n\n";
        flush();
        
        $updateSuccess = true;
        
        // 创建old目录（如果不存在）
        echo "data: {\"status\": \"progress\", \"progress\": 5, \"message\": \"准备更新环境...\"}\n\n";
        flush();
        usleep(50000);
        
        if (!is_dir('old')) {
            if (!mkdir('old', 0777, true)) {
                echo "data: {\"status\": \"complete\", \"success\": false, \"message\": \"无法创建备份目录\"}\n\n";
                flush();
                exit;
            }
        }
        
        // 获取更新信息
        $updateUrl = 'https://updata.hyacine.com.cn/updata.json';
        $updateJson = file_get_contents($updateUrl);
        
        if ($updateJson === false) {
            echo "data: {\"status\": \"complete\", \"success\": false, \"message\": \"无法连接到更新服务器\"}\n\n";
            flush();
            exit;
        }
        
        $updateInfo = json_decode($updateJson, true);
        
        if ($updateInfo === null || !isset($updateInfo['updatafiles'])) {
            echo "data: {\"status\": \"complete\", \"success\": false, \"message\": \"更新信息格式错误\"}\n\n";
            flush();
            exit;
        }
        
        $totalFiles = count($updateInfo['updatafiles']);
        
        // 检查服务器硬盘空间
        echo "data: {\"status\": \"progress\", \"progress\": 10, \"message\": \"检查服务器空间...\"}\n\n";
        flush();
        usleep(50000);
        
        $requiredSpace = 0;
        
        // 获取待更新文件的实际大小
        foreach ($updateInfo['updatafiles'] as $file) {
            $fileUrl = 'https://updata.hyacine.com.cn/' . $file;
            $fileSize = 0;
            
            // 使用curl获取远程文件大小
            $ch = curl_init($fileUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true); // 只获取头部信息
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // 跟随重定向
            
            $headers = curl_exec($ch);
            if ($headers !== false) {
                $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
                if ($contentLength > 0) {
                    $fileSize = $contentLength;
                } else {
                    // 如果无法获取实际大小，使用估算值
                    $fileSize = 100 * 1024; // 100KB
                }
            } else {
                // 如果curl请求失败，使用估算值
                $fileSize = 100 * 1024; // 100KB
            }
            
            // cURL资源会自动关闭，不需要显式调用curl_close()
            $requiredSpace += $fileSize;
        }
        
        // 增加备份所需的空间
        $requiredSpace *= 2;
        
        // 获取服务器剩余空间（单位：字节）
        $freeSpace = disk_free_space('.');
        
        if ($freeSpace < $requiredSpace) {
            $requiredMB = round($requiredSpace / (1024 * 1024), 2);
            $freeMB = round($freeSpace / (1024 * 1024), 2);
            echo "data: {\"status\": \"complete\", \"success\": false, \"message\": \"服务器剩余空间不足，需要 {$requiredMB}MB，但只有 {$freeMB}MB 可用\"}\n\n";
            flush();
            exit;
        }
        
        // 备份当前文件
        echo "data: {\"status\": \"progress\", \"progress\": 20, \"message\": \"备份当前文件...\"}\n\n";
        flush();
        usleep(50000);
        
        $backupSuccess = true;
        $currentFile = 0;
        foreach ($updateInfo['updatafiles'] as $file) {
            $currentFile++;
            $progress = 20 + round(($currentFile / $totalFiles) * 30);
            
            // 发送进度更新
            echo "data: {\"status\": \"progress\", \"progress\": {$progress}, \"message\": \"备份文件: {$file}\"}\n\n";
            flush();
            usleep(50000);
            
            // 只备份存在的文件
            if (file_exists($file)) {
                // 确保old目录中对应的子目录存在
                $destPath = 'old/' . $file;
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    if (!mkdir($destDir, 0777, true)) {
                        $backupSuccess = false;
                        echo "data: {\"status\": \"complete\", \"success\": false, \"message\": \"无法创建备份目录: {$destDir}\"}\n\n";
                        flush();
                        exit;
                    }
                }
                
                // 备份到old目录
                if (!copy($file, $destPath)) {
                    $backupSuccess = false;
                    echo "data: {\"status\": \"complete\", \"success\": false, \"message\": \"无法备份文件: {$file}\"}\n\n";
                    flush();
                    exit;
                }
            }
        }
        
        // 下载并替换文件
        echo "data: {\"status\": \"progress\", \"progress\": 50, \"message\": \"开始下载更新文件...\"}\n\n";
        flush();
        usleep(50000);
        
        $successCount = 0;
        $failedCount = 0;
        $currentFile = 0;
        
        foreach ($updateInfo['updatafiles'] as $file) {
            $currentFile++;
            $progress = 50 + round(($currentFile / $totalFiles) * 50);
            
            // 发送进度更新
            echo "data: {\"status\": \"progress\", \"progress\": {$progress}, \"message\": \"更新文件: {$file}\"}\n\n";
            flush();
            usleep(50000);
            
            $fileUrl = 'https://updata.hyacine.com.cn/' . $file;
            $fileContent = file_get_contents($fileUrl);
            
            if ($fileContent !== false) {
                // 确保目标目录存在
                $destDir = dirname($file);
                if ($destDir !== '.' && !is_dir($destDir)) {
                    if (!mkdir($destDir, 0777, true)) {
                        $failedCount++;
                        continue; // 无法创建目录，跳过此文件
                    }
                }
                
                // 保存文件
                $result = file_put_contents($file, $fileContent);
                if ($result !== false) {
                    $successCount++;
                } else {
                    $failedCount++;
                }
            } else {
                $failedCount++;
            }
        }
        
        if ($successCount > 0) {
            $successMsg = "成功更新 {$successCount} 个文件";
            if ($failedCount > 0) {
                $successMsg .= "，失败 {$failedCount} 个文件";
            }
            echo "data: {\"status\": \"complete\", \"success\": true, \"message\": \"{$successMsg}\"}\n\n";
        } else {
            echo "data: {\"status\": \"complete\", \"success\": false, \"message\": \"更新失败，无法下载文件\"}\n\n";
        }
        flush();
    }
    exit;
}

// 获取当前版本信息
$currentVersion = '未知版本';
// 优先从version.json获取版本信息
if (file_exists('version.json')) {
    $versionJson = file_get_contents('version.json');
    if ($versionJson !== false) {
        $versionData = json_decode($versionJson, true);
        if ($versionData && isset($versionData['Version'])) {
            $currentVersion = $versionData['Version'];
        }
    }
}
// 如果version.json不存在或解析失败，尝试从version.txt获取
elseif (file_exists('version.txt')) {
    $currentVersion = trim(file_get_contents('version.txt'));
}

// 获取最新版本信息
$isLatestVersion = false;
$updateUrl = 'https://updata.hyacine.com.cn/updata.json';
$updateJson = file_get_contents($updateUrl);

$error = '';
$updateInfo = null;

if ($updateJson === false) {
    $error = '无法连接到更新服务器';
} else {
    $updateInfo = json_decode($updateJson, true);
    
    if ($updateInfo === null) {
        $error = '更新信息格式错误';
    } else {
        // 检查是否为最新版本
        if (isset($updateInfo['version']) && $updateInfo['version'] === $currentVersion) {
            $isLatestVersion = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统更新 - Modern Chat</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
            overflow: hidden;
        }
        
        .header {
            background: #fff;
            color: #333;
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid #e5e5e5;
        }
        
        .header h1 {
            font-size: 18px;
            font-weight: 600;
        }
        
        .content {
            padding: 30px;
        }
        
        /* 最新版本样式 */
        .latest-version {
            text-align: center;
            padding: 30px 0;
        }
        
        .latest-version-icon {
            font-size: 64px;
            margin-bottom: 15px;
        }
        
        .latest-version-text {
            font-size: 16px;
            color: #333;
            font-weight: 500;
        }
        
        .version-info {
            display: flex;
            justify-content: space-around;
            margin-bottom: 25px;
            padding: 20px;
            background: #fafafa;
            border-radius: 8px;
        }
        
        .version-item {
            text-align: center;
        }
        
        .version-label {
            font-size: 14px;
            color: #999;
            margin-bottom: 8px;
        }
        
        .version-value {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }
        
        .version-value.current {
            color: #07c160;
        }
        
        .version-value.latest {
            color: #1989fa;
        }
        
        .update-message {
            background: #ebf5ff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        
        .update-message h3 {
            color: #1989fa;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .update-message p {
            color: #333;
            font-size: 13px;
            line-height: 1.5;
        }
        
        .update-files {
            margin-bottom: 25px;
        }
        
        .update-files h3 {
            color: #333;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 12px;
        }
        
        .files-list {
            background: #fafafa;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .file-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .file-item:last-child {
            border-bottom: none;
        }
        
        .file-name {
            font-size: 13px;
            color: #333;
        }
        
        .file-status {
            font-size: 12px;
            color: #999;
        }
        
        .file-status.downloaded {
            color: #07c160;
        }
        
        .file-status.updating {
            color: #1989fa;
        }
        
        .actions {
            text-align: center;
            margin-top: 30px;
        }
        
        .btn {
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
            margin: 0 8px;
        }
        
        .btn-primary {
            background: #07c160;
            color: white;
        }
        
        .btn-primary:hover {
            background: #06ad56;
        }
        
        .btn-primary:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .btn-secondary {
            background: #f5f5f5;
            color: #333;
        }
        
        .btn-secondary:hover {
            background: #eeeeee;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #f6ffed;
            color: #52c41a;
            border: 1px solid #b7eb8f;
        }
        
        .alert-error {
            background: #fff2f0;
            color: #ff4d4f;
            border: 1px solid #ffccc7;
        }
        
        .alert-info {
            background: #ebf5ff;
            color: #1989fa;
            border: 1px solid #91d5ff;
        }
        
        /* 进度条样式 */
        .progress-container {
            margin: 20px 0;
            display: none;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #f0f0f0;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #07c160, #1989fa);
            border-radius: 4px;
            width: 0%;
            transition: width 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            animation: progress-shine 1.5s infinite;
        }
        
        @keyframes progress-shine {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        
        .progress-text {
            text-align: center;
            font-size: 13px;
            color: #666;
            margin-bottom: 10px;
        }
        
        /* 加载动画样式 */
        .loading-container {
            display: none;
            text-align: center;
            padding: 30px 0;
        }
        
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f0f0f0;
            border-top: 4px solid #1989fa;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-text {
            font-size: 14px;
            color: #666;
        }
        
        /* 更新状态容器 */
        .update-status {
            margin: 20px 0;
            padding: 15px;
            background: #fafafa;
            border-radius: 8px;
            display: none;
        }
        
        .status-item {
            margin-bottom: 8px;
            font-size: 13px;
            color: #666;
        }
        
        .status-item:last-child {
            margin-bottom: 0;
        }
        
        /* 动画效果 */
        .fade-in {
            animation: fadeIn 0.3s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .container {
                margin: 10px;
            }
            
            .content {
                padding: 20px;
            }
            
            .version-info {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
        <h1>系统更新</h1>
        <p>Modern Chat 更新系统</p>
        <!-- 版本标记，防止浏览器缓存旧版本 -->
        <meta name="version" content="<?php echo time(); ?>">
    </div>
        
        <div class="content">
            <!-- 消息提示区域 -->
            <div id="message-area"></div>
            
            <!-- 进度条区域 -->
            <div class="progress-container" id="progress-container">
                <div class="progress-text" id="progress-text">准备更新...</div>
                <div class="progress-bar">
                    <div class="progress-fill" id="progress-fill"></div>
                </div>
            </div>
            
            <!-- 加载动画区域 -->
            <div class="loading-container" id="loading-container">
                <div class="loading-spinner"></div>
                <div class="loading-text" id="loading-text">正在更新系统...</div>
            </div>
            
            <!-- 更新状态区域 -->
            <div class="update-status" id="update-status"></div>
            
            <?php if ($updateInfo): ?>
                <?php if ($isLatestVersion): ?>
                    <!-- 已是最新版本 -->
                    <div class="latest-version">
                        <div class="latest-version-icon">✅</div>
                        <div class="latest-version-text">已是最新版本无需更新</div>
                    </div>
                <?php else: ?>
                    <!-- 有新版本 -->
                    <div class="version-info">
                        <div class="version-item">
                            <div class="version-label">当前版本</div>
                            <div class="version-value current"><?php echo $currentVersion; ?></div>
                        </div>
                        <div class="version-item">
                            <div class="version-label">最新版本</div>
                            <div class="version-value latest"><?php echo $updateInfo['version']; ?></div>
                        </div>
                    </div>
                    
                    <div class="update-message">
                        <h3>更新内容</h3>
                        <p><?php echo $updateInfo['updatamessage']; ?></p>
                    </div>
                    
                    <div class="update-files">
                        <h3>更新文件</h3>
                        <div class="files-list">
                            <?php foreach ($updateInfo['updatafiles'] as $file): ?>
                                <div class="file-item">
                                    <span class="file-name"><?php echo $file; ?></span>
                                    <span class="file-status" data-file="<?php echo $file; ?>">待更新</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- 操作按钮区域 -->
                <div class="actions">
                    <?php if (!$isLatestVersion): ?>
                        <button type="button" class="btn btn-primary" id="update-btn" onclick="startUpdate()">立即更新</button>
                    <?php endif; ?>
                    
                    <!-- 撤销更新按钮 -->
                    <?php if (is_dir('old') && count(array_diff(scandir('old'), array('.', '..'))) > 0): ?>
                        <button type="button" class="btn btn-secondary" id="rollback-btn" onclick="confirmRollback()">撤销更新</button>
                    <?php endif; ?>
                    
                    <!-- 返回按钮 -->
                    <a href="chat.php" class="btn btn-secondary">返回聊天</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // 显示消息
        function showMessage(message, type = 'info') {
            const messageArea = document.getElementById('message-area');
            const messageDiv = document.createElement('div');
            messageDiv.className = `alert alert-${type} fade-in`;
            messageDiv.textContent = message;
            messageArea.appendChild(messageDiv);
            
            // 5秒后自动移除消息
            setTimeout(() => {
                if (messageDiv.parentNode) {
                    messageDiv.remove();
                }
            }, 5000);
        }
        
        // 显示进度条
        function showProgress() {
            document.getElementById('progress-container').style.display = 'block';
        }
        
        // 更新进度
        function updateProgress(percent, message) {
            document.getElementById('progress-fill').style.width = percent + '%';
            document.getElementById('progress-text').textContent = message;
        }
        
        // 显示加载动画
        function showLoading(message = '正在更新系统...') {
            document.getElementById('loading-container').style.display = 'block';
            document.getElementById('loading-text').textContent = message;
        }
        
        // 隐藏加载动画
        function hideLoading() {
            document.getElementById('loading-container').style.display = 'none';
        }
        
        // 禁用按钮
        function disableButtons() {
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach(btn => {
                btn.disabled = true;
            });
        }
        
        // 启用按钮
        function enableButtons() {
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach(btn => {
                btn.disabled = false;
            });
        }
        
        // 添加更新状态日志
        function addStatusLog(message) {
            const statusContainer = document.getElementById('update-status');
            statusContainer.style.display = 'block';
            
            const statusItem = document.createElement('div');
            statusItem.className = 'status-item fade-in';
            statusItem.textContent = `${new Date().toLocaleTimeString()}: ${message}`;
            statusContainer.appendChild(statusItem);
            
            // 滚动到底部
            statusContainer.scrollTop = statusContainer.scrollHeight;
        }
        
        // 更新文件状态
        function updateFileStatus(file, status) {
            const statusElements = document.querySelectorAll(`[data-file="${file}"]`);
            statusElements.forEach(element => {
                element.textContent = status;
                if (status === '已更新') {
                    element.className = 'file-status downloaded';
                } else if (status === '正在更新') {
                    element.className = 'file-status updating';
                } else {
                    element.className = 'file-status';
                }
            });
        }
        
        // 开始更新
        function startUpdate() {
            // 显示进度条和加载动画
            showProgress();
            showLoading('正在准备更新...');
            disableButtons();
            
            // 清空之前的状态
            document.getElementById('update-status').innerHTML = '';
            document.getElementById('message-area').innerHTML = '';
            
            // 使用XMLHttpRequest进行长轮询
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 3 || xhr.readyState === 4) {
                    const responseText = xhr.responseText;
                    const lines = responseText.split('\n\n');
                    
                    for (const line of lines) {
                        if (line.startsWith('data:')) {
                            const dataStr = line.replace('data: ', '');
                            try {
                                const data = JSON.parse(dataStr);
                                
                                switch (data.status) {
                                    case 'start':
                                        updateProgress(0, data.message);
                                        addStatusLog(data.message);
                                        break;
                                    case 'progress':
                                        updateProgress(data.progress, data.message);
                                        addStatusLog(data.message);
                                        
                                        // 更新文件状态
                                        if (data.message.includes('更新文件: ')) {
                                            const file = data.message.replace('更新文件: ', '');
                                            updateFileStatus(file, '正在更新');
                                        }
                                        break;
                                    case 'complete':
                                        hideLoading();
                                        enableButtons();
                                        
                                        if (data.success) {
                                            showMessage(data.message, 'success');
                                            updateProgress(100, '更新完成');
                                            
                                            // 更新所有文件状态为已更新
                                            const fileElements = document.querySelectorAll('[data-file]');
                                            fileElements.forEach(element => {
                                                updateFileStatus(element.dataset.file, '已更新');
                                            });
                                        } else {
                                            showMessage(data.message, 'error');
                                        }
                                        return;
                                }
                            } catch (error) {
                                console.error('解析更新数据失败:', error);
                            }
                        }
                    }
                }
                
                if (xhr.readyState === 4) {
                    if (xhr.status !== 200) {
                        hideLoading();
                        enableButtons();
                        showMessage('更新请求失败: ' + xhr.statusText, 'error');
                    }
                }
            };
            
            xhr.onerror = function() {
                hideLoading();
                enableButtons();
                showMessage('更新连接中断', 'error');
            };
            
            // 发送请求
            xhr.send('ajax=true&action=update');
        }
        
        // 确认撤销更新
        function confirmRollback() {
            if (confirm('确定要撤销更新吗？这将恢复到更新前的版本。')) {
                rollbackUpdate();
            }
        }
        
        // 撤销更新
        function rollbackUpdate() {
            // 显示进度条和加载动画
            showProgress();
            showLoading('正在撤销更新...');
            disableButtons();
            
            // 清空之前的状态
            document.getElementById('update-status').innerHTML = '';
            document.getElementById('message-area').innerHTML = '';
            
            // 使用XMLHttpRequest进行长轮询
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 3 || xhr.readyState === 4) {
                    const responseText = xhr.responseText;
                    const lines = responseText.split('\n\n');
                    
                    for (const line of lines) {
                        if (line.startsWith('data:')) {
                            const dataStr = line.replace('data: ', '');
                            try {
                                const data = JSON.parse(dataStr);
                                
                                switch (data.status) {
                                    case 'start':
                                        updateProgress(0, data.message);
                                        addStatusLog(data.message);
                                        break;
                                    case 'progress':
                                        updateProgress(data.progress, data.message);
                                        addStatusLog(data.message);
                                        break;
                                    case 'complete':
                                        hideLoading();
                                        enableButtons();
                                        
                                        if (data.success) {
                                            showMessage(data.message, 'success');
                                            updateProgress(100, '撤销完成');
                                        } else {
                                            showMessage(data.message, 'error');
                                        }
                                        return;
                                }
                            } catch (error) {
                                console.error('解析撤销数据失败:', error);
                            }
                        }
                    }
                }
                
                if (xhr.readyState === 4) {
                    if (xhr.status !== 200) {
                        hideLoading();
                        enableButtons();
                        showMessage('撤销请求失败: ' + xhr.statusText, 'error');
                    }
                }
            };
            
            xhr.onerror = function() {
                hideLoading();
                enableButtons();
                showMessage('撤销连接中断', 'error');
            };
            
            // 发送请求
            xhr.send('ajax=true&action=rollback');
        }
        

        
        // 页面加载完成后，显示初始错误信息
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($error)): ?>
                showMessage('<?php echo addslashes($error); ?>', 'error');
            <?php endif; ?>
        });
    </script>
</body>
</html>