<?php
if (isset($_GET['action']) && $_GET['action'] === 'get_agreement') {
    $type = $_GET['type'] ?? '';
    $file = '';
    
    // 定义协议文件路径
    $baseDir = __DIR__ . '/Agreement/';
    // 如果目录不存在，尝试使用用户提供的绝对路径作为备选
    $absDir = '/Agreement/';
    
    if ($type === 'tos') {
        $filename = 'terms_of_service.md';
    } elseif ($type === 'privacy') {
        $filename = 'privacy_policy.md';
    }
    
    if (isset($filename)) {
        if (file_exists($baseDir . $filename)) {
            $file = $baseDir . $filename;
        } elseif (file_exists($absDir . $filename)) {
            $file = $absDir . $filename;
        }
    }

    if ($file && file_exists($file)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo file_get_contents($file);
    } else {
        header('HTTP/1.1 404 Not Found');
        echo "协议文件不存在。\n尝试路径:\n" . $baseDir . ($filename ?? '') . "\n" . $absDir . ($filename ?? '');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modern Chat - 安装向导</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Microsoft YaHei', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .install-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.15);
            max-width: 900px;
            width: 100%;
            overflow: hidden;
            animation: slideIn 0.5s ease;
            border: 2px solid transparent;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .install-header {
            background: transparent;
            padding: 40px 40px 20px 40px;
            text-align: center;
            color: #333;
        }

        .install-header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            font-weight: 600;
            color: #333;
        }

        .install-header p {
            font-size: 1.1em;
            color: #666;
            opacity: 1;
        }

        .install-body {
            padding: 40px;
        }

        .step-nav {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            position: relative;
        }

        .step-nav::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 50px;
            right: 50px;
            height: 3px;
            background: #e0e0e0;
            z-index: 0;
        }

        .step-item {
            position: relative;
            z-index: 1;
            text-align: center;
            flex: 1;
        }

        .step-number {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #e0e0e0;
            color: #999;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: bold;
            font-size: 18px;
            transition: all 0.3s;
        }

        .step-item.active .step-number {
            background: linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%);
            color: white;
            box-shadow: 0 4px 10px rgba(18, 183, 245, 0.4);
        }

        .step-item.completed .step-number {
            background: #52c41a;
            color: white;
        }

        .step-label {
            font-size: 14px;
            color: #666;
        }

        .step-item.active .step-label {
            color: #12b7f5;
            font-weight: 600;
        }

        .step-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .step-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .welcome-content {
            text-align: center;
            padding: 20px 0;
        }

        .welcome-content h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 24px;
        }

        .welcome-content p {
            color: #666;
            line-height: 1.8;
            margin-bottom: 15px;
        }

        .version-info {
            background: #f0f9ff;
            border-left: 4px solid #12b7f5;
            padding: 15px 20px;
            margin: 20px 0;
            text-align: left;
            border-radius: 4px;
        }

        .version-info p {
            margin: 5px 0;
            color: #555;
        }

        .check-list {
            list-style: none;
            margin: 20px 0;
        }

        .check-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            transition: background 0.2s;
        }

        .check-item:hover {
            background: #f9f9f9;
        }

        .check-item:last-child {
            border-bottom: none;
        }

        .check-icon {
            width: 24px;
            height: 24px;
            margin-right: 15px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .check-item.success .check-icon {
            background: #52c41a;
            color: white;
        }

        .check-item.error .check-icon {
            background: #ff4d4f;
            color: white;
        }

        .check-item.warning .check-icon {
            background: #faad14;
            color: white;
        }

        .check-info {
            flex: 1;
        }

        .check-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .check-detail {
            font-size: 13px;
            color: #999;
        }

        .check-message {
            font-size: 13px;
            margin-top: 5px;
        }

        .check-item.success .check-message {
            color: #52c41a;
        }

        .check-item.error .check-message {
            color: #ff4d4f;
        }

        .check-item.warning .check-message {
            color: #faad14;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #12b7f5;
            box-shadow: 0 0 0 3px rgba(18, 183, 245, 0.1);
        }

        .form-group .hint {
            font-size: 13px;
            color: #999;
            margin-top: 5px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(18, 183, 245, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(18, 183, 245, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #666;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-group {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .btn-group .btn {
            min-width: 120px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
        }

        .alert.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: #f6ffed;
            border: 1px solid #b7eb8f;
            color: #52c41a;
        }

        .alert-error {
            background: #fff2f0;
            border: 1px solid #ffccc7;
            color: #ff4d4f;
        }

        .alert-warning {
            background: #fffbe6;
            border: 1px solid #ffe58f;
            color: #faad14;
        }

        .alert-info {
            background: #e6f7ff;
            border: 1px solid #91d5ff;
            color: #1890ff;
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .complete-content {
            text-align: center;
            padding: 20px 0;
        }

        .complete-icon {
            width: 80px;
            height: 80px;
            background: #52c41a;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 40px;
            animation: scaleIn 0.5s ease;
        }

        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }

        .complete-content h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 24px;
        }

        .complete-content p {
            color: #666;
            line-height: 1.8;
            margin-bottom: 15px;
        }

        .complete-info {
            background: #f0f9ff;
            border-left: 4px solid #12b7f5;
            padding: 20px;
            margin: 20px 0;
            text-align: left;
            border-radius: 4px;
        }

        .complete-info h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .complete-info ul {
            list-style: none;
        }

        .complete-info li {
            padding: 8px 0;
            color: #555;
            border-bottom: 1px dashed #ddd;
        }

        .complete-info li:last-child {
            border-bottom: none;
        }

        .complete-info strong {
            color: #12b7f5;
        }

        @media (max-width: 768px) {
            .install-container {
                border-radius: 0;
            }

            .install-header {
                padding: 30px 20px;
            }

            .install-header h1 {
                font-size: 2em;
            }

            .install-body {
                padding: 20px;
            }

            .step-nav {
                margin-bottom: 30px;
            }

            .step-number {
                width: 36px;
                height: 36px;
                font-size: 15px;
            }

            .step-label {
                font-size: 12px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .btn-group {
                flex-direction: column-reverse;
                gap: 10px;
            }

            .btn-group .btn {
                width: 100%;
            }
        }

        /* 开关样式 */
        .toggle-switch {
            position: relative;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
            user-select: none;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: relative;
            display: inline-block;
            width: 46px;
            height: 24px;
            background-color: #e0e0e0;
            border-radius: 24px;
            transition: .4s;
            margin-right: 12px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            border-radius: 50%;
            transition: .4s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        input:checked + .slider {
            background: linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%);
        }

        input:focus + .slider {
            box-shadow: 0 0 1px #12b7f5;
        }

        input:checked + .slider:before {
            transform: translateX(22px);
        }
        
        .toggle-label {
            font-size: 15px;
            color: #333;
            font-weight: 600;
        }

        /* 进度条样式 */
        .progress-container {
            margin-top: 20px;
            background: #f0f0f0;
            border-radius: 10px;
            height: 24px;
            overflow: hidden;
            position: relative;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%);
            width: 0%;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: 600;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        
        .progress-bar.animated {
            background-size: 40px 40px, 100% 100%;
            background-image: 
                linear-gradient(45deg, rgba(255, 255, 255, .15) 25%, transparent 25%, transparent 50%, rgba(255, 255, 255, .15) 50%, rgba(255, 255, 255, .15) 75%, transparent 75%, transparent),
                linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%);
            animation: progress-stripes 1s linear infinite;
        }
        
        @keyframes progress-stripes {
            from { background-position: 40px 0; }
            to { background-position: 0 0; }
        }

        .progress-text {
            text-align: center;
            margin-top: 8px;
            font-size: 13px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="install-header">
            <h1>🚀 Modern Chat</h1>
            <p>现代化聊天系统安装向导</p>
        </div>

        <div class="install-body">
            <!-- 步骤导航 -->
            <div class="step-nav">
                <div class="step-item active" data-step="1">
                    <div class="step-number">1</div>
                    <div class="step-label">欢迎</div>
                </div>
                <div class="step-item" data-step="2">
                    <div class="step-number">2</div>
                    <div class="step-label">环境检测</div>
                </div>
                <div class="step-item" data-step="3">
                    <div class="step-number">3</div>
                    <div class="step-label">数据库</div>
                </div>
                <div class="step-item" data-step="4">
                    <div class="step-number">4</div>
                    <div class="step-label">短信配置</div>
                </div>
                <div class="step-item" data-step="5">
                    <div class="step-number">5</div>
                    <div class="step-label">完成</div>
                </div>
            </div>

            <!-- 消息提示 -->
            <div id="alert-box" class="alert"></div>

            <!-- 步骤1: 欢迎页 -->
            <div class="step-content active" id="step-1">
                <div class="welcome-content">
                    <h2>欢迎使用 Modern Chat 安装向导</h2>
                    <p>Modern Chat 是一个基于 PHP + MySQL 的现代化聊天系统，具有简洁的界面和丰富的功能。</p>
                    <p>本向导将帮助您完成以下配置：</p>
                    <ul style="text-align: left; margin: 20px 0; padding-left: 30px; color: #666; line-height: 2;">
                        <li>检查服务器环境是否符合要求</li>
                        <li>配置数据库连接信息</li>
                        <li>自动导入数据库表结构</li>
                        <li>完成系统初始化</li>
                    </ul>
                    <div style="margin: 20px 0; text-align: left;">
                        <label class="custom-checkbox" style="display: flex; align-items: center; cursor: pointer; color: #666; font-size: 14px;">
                            <input type="checkbox" id="agree-terms" style="margin-right: 8px;">
                            <span>我已阅读并同意 <a href="javascript:void(0)" onclick="showAgreement('tos')" style="color: #12b7f5; text-decoration: none;">《用户协议》</a> 和 <a href="javascript:void(0)" onclick="showAgreement('privacy')" style="color: #12b7f5; text-decoration: none;">《隐私协议》</a></span>
                        </label>
                    </div>
                    <div class="version-info" id="version-info">
                        <p>正在加载版本信息...</p>
                    </div>
                    <p style="font-size: 13px; color: #999;">点击"下一步"开始安装流程</p>
                </div>
            </div>

            <!-- 步骤2: 环境检测 -->
            <div class="step-content" id="step-2">
                <div style="text-align: center; margin-bottom: 20px;">
                    <h2 style="color: #333; font-size: 24px;">环境检测</h2>
                    <p style="color: #666;">正在检测您的服务器环境是否符合运行要求</p>
                </div>
                <ul class="check-list" id="env-check-list">
                    <li class="check-item">
                        <div class="check-icon">...</div>
                        <div class="check-info">
                            <div class="check-name">正在检测...</div>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- 步骤3: 数据库配置 -->
            <div class="step-content" id="step-3">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h2 style="color: #333; font-size: 24px;">数据库配置</h2>
                    <p style="color: #666;">请填写您的MySQL数据库连接信息</p>
                </div>
                <form id="db-config-form">
                    <div class="form-group">
                        <label for="db-host">数据库服务器地址</label>
                        <input type="text" id="db-host" name="host" value="localhost" placeholder="例如: localhost">
                        <div class="hint">如果数据库和网站在同一台服务器上，通常填写 localhost 或 127.0.0.1</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="toggle-switch">
                            <input type="checkbox" id="compat-mode">
                            <span class="slider"></span>
                            <span class="toggle-label">兼容模式 (自定义数据库用户)</span>
                        </label>
                    </div>

                    <!-- 兼容模式字段 -->
                    <div id="compat-fields" style="display: none;">
                        <div class="form-group">
                            <label for="db-name">数据库名称</label>
                            <input type="text" id="db-name" name="database" value="chat" placeholder="例如: chat">
                        </div>
                        <div class="form-group">
                            <label for="db-user">数据库用户名</label>
                            <input type="text" id="db-user" name="username" value="root" placeholder="例如: root">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="db-pass" style="display: flex; align-items: center; gap: 8px;">
                                数据库密码 
                                <a href="help/index.php" target="_blank" style="text-decoration: none; color: #12b7f5; font-size: 18px;" title="点击查看帮助">ℹ</a>
                            </label>
                            <input type="password" id="db-pass" name="password" placeholder="请输入密码">
                        </div>
                        <div class="form-group">
                            <label for="db-port">端口</label>
                            <input type="number" id="db-port" name="port" value="3306" placeholder="例如: 3306">
                        </div>
                    </div>
                </form>
                
                <!-- 进度条 -->
                <div id="install-progress-wrapper" style="display: none;">
                    <div class="progress-container">
                        <div id="install-progress-bar" class="progress-bar animated" style="width: 0%">0%</div>
                    </div>
                    <div id="install-progress-text" class="progress-text">准备开始安装...</div>
                </div>
            </div>

            <!-- 步骤4: 短信配置 -->
            <div class="step-content" id="step-4">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h2 style="color: #333; font-size: 24px;">阿里云短信配置</h2>
                    <p style="color: #666;">配置短信服务以开启手机号注册验证功能</p>
                </div>
                
                <div class="alert alert-info show">
                    如需跳过此步骤，点击下方的“跳过”按钮。跳过将无法使用手机号注册功能。
                </div>

                <form id="sms-config-form">
                    <div class="form-group">
                        <label for="access-key-id" style="display: flex; align-items: center; gap: 8px;">
                            AccessKey ID
                            <a href="help/index.php" target="_blank" style="text-decoration: none; color: #12b7f5; font-size: 18px;" title="如何获取？">ℹ</a>
                        </label>
                        <input type="text" id="access-key-id" name="access_key_id" placeholder="请输入阿里云 AccessKey ID">
                    </div>
                    
                    <div class="form-group">
                        <label for="access-key-secret" style="display: flex; align-items: center; gap: 8px;">
                            AccessKey Secret
                            <a href="help/index.php" target="_blank" style="text-decoration: none; color: #12b7f5; font-size: 18px;" title="如何获取？">ℹ</a>
                        </label>
                        <input type="password" id="access-key-secret" name="access_key_secret" placeholder="请输入阿里云 AccessKey Secret">
                    </div>

                    <div class="form-group">
                        <label for="test-phone">测试手机号</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="tel" id="test-phone" name="test_phone" placeholder="用于接收测试短信的手机号">
                            <button type="button" class="btn btn-secondary" id="send-test-sms-btn" style="white-space: nowrap;">
                                发送测试短信
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="verify-code">验证码</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" id="verify-code" name="verify_code" placeholder="请输入收到的6位验证码">
                            <button type="button" class="btn btn-primary" id="verify-sms-btn" style="white-space: nowrap;" disabled>
                                验证并保存
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- 步骤5: 完成安装 -->
            <div class="step-content" id="step-5">
                <div class="complete-content">
                    <div class="complete-icon">✓</div>
                    <h2>🎉 安装完成！</h2>
                    <p>恭喜您！Modern Chat 已成功安装到您的服务器。</p>
                    <div class="complete-info">
                        <h3>后续操作</h3>
                        <ul>
                            <li><strong>删除安装锁</strong>：如需重新安装，请删除根目录下的 <code>installed.lock</code> 文件</li>
                            <li><strong>配置管理员</strong>：首次注册的用户将自动成为超级管理员</li>
                            <li><strong>访问系统</strong>：点击下方按钮进入聊天系统</li>
                            <li><strong>安全提示</strong>：建议安装完成后修改数据库密码</li>
                        </ul>
                    </div>
                    <p style="font-size: 13px; color: #999;">感谢您使用 Modern Chat！如有问题请访问项目主页获取支持</p>
                </div>
            </div>

            <!-- 按钮组 -->
            <div class="btn-group">
                <button type="button" class="btn btn-secondary" id="prev-btn" style="display: none;">
                    ← 上一步
                </button>
                <button type="button" class="btn btn-primary" id="next-btn">
                    下一步 →
                </button>
            </div>
        </div>
    </div>

    <!-- 协议模态框 -->
    <div id="agreement-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; opacity: 0; transition: opacity 0.3s;">
        <div style="background: white; width: 80%; max-width: 800px; height: 80%; border-radius: 12px; display: flex; flex-direction: column; box-shadow: 0 10px 25px rgba(0,0,0,0.2); transform: scale(0.9); transition: transform 0.3s;">
            <div style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                <h3 id="agreement-title" style="margin: 0; font-size: 18px; color: #333;">协议条款</h3>
                <button onclick="closeAgreement()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #999; padding: 0 10px;">&times;</button>
            </div>
            <div style="flex: 1; overflow-y: auto; padding: 30px; background: #f9f9f9;">
                <div id="agreement-content" style="white-space: pre-wrap; font-family: inherit; color: #444; line-height: 1.8; font-size: 15px;"></div>
            </div>
            <div style="padding: 20px; border-top: 1px solid #eee; text-align: right; background: white; border-radius: 0 0 12px 12px;">
                <button onclick="closeAgreement()" class="btn btn-primary">我已阅读并关闭</button>
            </div>
        </div>
    </div>

    <script>
        // 协议相关函数
        function showAgreement(type) {
            const modal = document.getElementById('agreement-modal');
            const title = document.getElementById('agreement-title');
            const content = document.getElementById('agreement-content');
            const modalContent = modal.querySelector('div');
            
            title.textContent = type === 'tos' ? '用户协议' : '隐私协议';
            content.innerHTML = '<div class="loading" style="border-color: rgba(0,0,0,0.1); border-top-color: #12b7f5;"></div> 正在加载协议内容...';
            content.style.textAlign = 'center';
            content.style.paddingTop = '50px';
            
            modal.style.display = 'flex';
            // 强制重绘
            modal.offsetHeight;
            modal.style.opacity = '1';
            modalContent.style.transform = 'scale(1)';
            
            fetch('install.php?action=get_agreement&type=' + type)
                .then(res => {
                    if (!res.ok) throw new Error('文件未找到');
                    return res.text();
                })
                .then(text => {
                    content.style.textAlign = 'left';
                    content.style.paddingTop = '0';
                    // 简单的 Markdown 处理 (将 # 转换为标题样式，其他保持文本)
                    // 这里为了保持格式，我们直接显示文本，但做一些简单的样式美化
                    content.textContent = text;
                })
                .catch(err => {
                    content.innerHTML = `<div style="color: #ff4d4f; text-align: center;">加载失败: ${err.message}</div>`;
                });
        }

        function closeAgreement() {
            const modal = document.getElementById('agreement-modal');
            const modalContent = modal.querySelector('div');
            
            modal.style.opacity = '0';
            modalContent.style.transform = 'scale(0.9)';
            
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }

        // 点击模态框背景关闭
        document.getElementById('agreement-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAgreement();
            }
        });

        // 当前步骤
        let currentStep = 1;
        const totalSteps = 5;

        // 环境检测结果
        let envCheckPassed = false;
        let dbConfig = {};
        let smsVerified = false;
        
        // 进度条控制
        let progressTimer = null;
        const progressWrapper = document.getElementById('install-progress-wrapper');
        const progressBar = document.getElementById('install-progress-bar');
        const progressText = document.getElementById('install-progress-text');
        
        function updateProgress(percent, text) {
            progressBar.style.width = `${percent}%`;
            progressBar.textContent = `${Math.round(percent)}%`;
            if (text) progressText.textContent = text;
        }
        
        function startProgressSimulation(start, end, duration) {
            if (progressTimer) clearInterval(progressTimer);
            let current = start;
            const step = (end - start) / (duration / 100);
            
            progressTimer = setInterval(() => {
                current += step;
                if (current >= end) {
                    current = end;
                    clearInterval(progressTimer);
                }
                updateProgress(current);
            }, 100);
        }

        // 获取DOM元素
        const alertBox = document.getElementById('alert-box');
        const prevBtn = document.getElementById('prev-btn');
        const nextBtn = document.getElementById('next-btn');

        // 显示消息
        function showAlert(type, message) {
            alertBox.className = `alert alert-${type} show`;
            alertBox.textContent = message;

            if (type === 'success' || type === 'error') {
                setTimeout(() => {
                    alertBox.classList.remove('show');
                }, 3000);
            }
        }

        // 隐藏消息
        function hideAlert() {
            alertBox.classList.remove('show');
        }

        // 更新步骤导航
        function updateStepNav(step) {
            document.querySelectorAll('.step-item').forEach((item, index) => {
                const stepNum = index + 1;
                item.classList.remove('active', 'completed');

                if (stepNum < step) {
                    item.classList.add('completed');
                } else if (stepNum === step) {
                    item.classList.add('active');
                }
            });
        }

        // 显示指定步骤
        function showStep(step) {
            document.querySelectorAll('.step-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(`step-${step}`).classList.add('active');
            updateStepNav(step);
            currentStep = step;

            // 更新按钮状态
            prevBtn.style.display = step > 1 ? 'inline-flex' : 'none';
            
            if (step === totalSteps) {
                nextBtn.textContent = '进入系统 →';
                nextBtn.onclick = () => {
                    window.location.href = 'login.php';
                };
            } else if (step === 3) {
                nextBtn.textContent = '下一步 →';
            } else if (step === 4) {
                nextBtn.textContent = '跳过 →';
                nextBtn.onclick = handleSkipSms;
                nextBtn.disabled = false; // 确保跳过按钮可用
            } else {
                nextBtn.textContent = '下一步 →';
                nextBtn.onclick = handleNext;
            }
        }

        // 处理下一步
        function handleNext() {
            hideAlert();

            switch (currentStep) {
                case 1:
                    // 检查是否同意用户协议
                    const agreeTerms = document.getElementById('agree-terms');
                    if (!agreeTerms.checked) {
                        showAlert('error', '请先阅读并同意用户协议');
                        return;
                    }
                    showStep(2);
                    checkEnvironment();
                    break;
                case 2:
                    if (!envCheckPassed) {
                        showAlert('error', '请先解决环境检测中的错误再继续');
                        return;
                    }
                    showStep(3);
                    break;
                case 3:
                    saveDatabaseConfig();
                    break;
                case 4:
                    // 检查是否验证通过
                    if (smsVerified) {
                        showStep(5);
                        completeInstall();
                    } else {
                        showAlert('error', '请先验证短信配置或点击跳过');
                    }
                    break;
            }
        }

        // 处理上一步
        prevBtn.onclick = () => {
            hideAlert();
            showStep(currentStep - 1);
        };

        // 获取版本信息
        function getVersionInfo() {
            fetch('install/install_api.php?action=get_version')
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const info = data.data;
                        document.getElementById('version-info').innerHTML = `
                            <p><strong>当前版本：</strong>${info.version}</p>
                            <p><strong>发布日期：</strong>${info.release_date}</p>
                            <p><strong>PHP版本：</strong>${info.php_version}</p>
                        `;
                    }
                })
                .catch(err => {
                    console.error('获取版本信息失败:', err);
                });
        }

        // 环境检测
        function checkEnvironment() {
            showAlert('info', '正在进行环境检测...');
            nextBtn.disabled = true;

            fetch('install/install_api.php?action=check_environment')
                .then(res => res.json())
                .then(data => {
                    hideAlert();

                    if (data.success) {
                        const checks = data.data.checks;
                        const systemInfo = data.data.system_info;
                        envCheckPassed = data.data.all_passed;

                        // 显示检测结果
                        const checkList = document.getElementById('env-check-list');
                        checkList.innerHTML = '';

                        Object.entries(checks).forEach(([key, check]) => {
                            const statusClass = check.status ? 'success' : 'warning';
                            const icon = check.status ? '✓' : '!';

                            const li = document.createElement('li');
                            li.className = `check-item ${statusClass}`;
                            li.innerHTML = `
                                <div class="check-icon">${icon}</div>
                                <div class="check-info">
                                    <div class="check-name">${check.name}</div>
                                    <div class="check-detail">当前: ${check.current} | 要求: ${check.required}</div>
                                    <div class="check-message">${check.message}</div>
                                </div>
                            `;
                            checkList.appendChild(li);
                        });

                        // 显示系统信息
                        const sysInfo = document.createElement('li');
                        sysInfo.className = 'check-item success';
                        sysInfo.innerHTML = `
                            <div class="check-icon">ℹ</div>
                            <div class="check-info">
                                <div class="check-name">系统信息</div>
                                <div class="check-detail">PHP ${systemInfo.php_version} | ${systemInfo.server_software} | ${systemInfo.os}</div>
                            </div>
                        `;
                        checkList.appendChild(sysInfo);

                        if (!envCheckPassed) {
                            showAlert('error', '存在必须的环境要求未满足，请先解决以上问题');
                            nextBtn.disabled = false;
                        } else {
                            showAlert('success', '环境检测通过，可以进行下一步');
                            nextBtn.disabled = false;
                        }
                    } else {
                        showAlert('error', data.message);
                        nextBtn.disabled = false;
                    }
                })
                .catch(err => {
                    showAlert('error', '环境检测失败: ' + err.message);
                    nextBtn.disabled = false;
                });
        }

        // 保存数据库配置
        function saveDatabaseConfig() {
            const host = document.getElementById('db-host').value.trim();
            const port = document.getElementById('db-port').value.trim();
            const database = document.getElementById('db-name').value.trim();
            const username = document.getElementById('db-user').value.trim();
            const password = document.getElementById('db-pass').value;

            // 验证必填字段
            if (!host || !database || !username) {
                showAlert('error', '请填写完整的数据库配置信息');
                return;
            }

            dbConfig = { host, port, database, username, password };
            nextBtn.disabled = true;
            nextBtn.innerHTML = '<span class="loading"></span> 安装中...';
            
            // 显示进度条
            progressWrapper.style.display = 'block';
            updateProgress(0, '正在连接数据库...');

            // 先测试连接
            testDatabase();
        }

        // 测试数据库连接
        function testDatabase() {
            const formData = new URLSearchParams(dbConfig);
            
            updateProgress(10, '正在连接数据库...');

            fetch('install/install_api.php?action=test_db', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    updateProgress(30, '数据库连接成功，准备导入数据...');
                    // showAlert('success', '数据库连接成功，正在导入数据...'); // 隐藏原来的提示，用进度条代替
                    importDatabase();
                } else {
                    progressWrapper.style.display = 'none';
                    showAlert('error', data.message);
                    nextBtn.disabled = false;
                    nextBtn.textContent = '开始安装 →';
                }
            })
            .catch(err => {
                progressWrapper.style.display = 'none';
                showAlert('error', '数据库连接失败: ' + err.message);
                nextBtn.disabled = false;
                nextBtn.textContent = '开始安装 →';
            });
        }

        // 导入数据库
        function importDatabase() {
            const formData = new URLSearchParams(dbConfig);
            formData.append('overwrite', 'false');
            
            updateProgress(35, '正在导入数据表结构和初始数据...');
            // 模拟进度从35%走到90%，持续5秒
            startProgressSimulation(35, 90, 5000);

            fetch('install/install_api.php?action=import_db', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (progressTimer) clearInterval(progressTimer);
                
                if (data.success) {
                    // 保存管理员信息
                    if (data.data && data.data.admin_created) {
                        window.adminInfo = {
                            email: data.data.admin_email,
                            password: data.data.admin_password
                        };
                    } else if (data.data && data.data.admin_creation_error) {
                         // 捕获管理员创建失败的错误
                         window.adminCreationError = data.data.admin_creation_error;
                    }
                    
                    updateProgress(95, '数据库导入成功，准备配置短信...');
                    showStep(4);
                } else {
                    // 检查是否是数据冲突
                    if (data.data && data.data.conflict) {
                        if (confirm(data.data.message)) {
                            // 用户确认覆盖
                            updateProgress(35, '正在清空旧数据并重新导入...');
                            startProgressSimulation(35, 90, 5000);
                            
                            const formData2 = new URLSearchParams(dbConfig);
                            formData2.append('overwrite', 'true');

                            return fetch('install/install_api.php?action=import_db', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                },
                                body: formData2
                            }).then(res => res.json());
                        } else {
                            progressWrapper.style.display = 'none';
                            showAlert('warning', '已取消导入，请修改数据库名称后重试');
                            nextBtn.disabled = false;
                            nextBtn.textContent = '开始安装 →';
                            return { success: false };
                        }
                    } else {
                        progressWrapper.style.display = 'none';
                        showAlert('error', data.message);
                        nextBtn.disabled = false;
                        nextBtn.textContent = '开始安装 →';
                    }
                }
            })
            .then(data => {
                if (data && data.success) {
                    if (progressTimer) clearInterval(progressTimer);
                    
                     // 确保在第二次成功回调中也保存管理员信息
                    if (data.data && data.data.admin_created) {
                        window.adminInfo = {
                            email: data.data.admin_email,
                            password: data.data.admin_password
                        };
                    } else if (data.data && data.data.admin_creation_error) {
                        window.adminCreationError = data.data.admin_creation_error;
                    }
                    
                    updateProgress(95, '数据库导入成功，准备配置短信...');
                    showStep(4);
                } else if (data && !data.success && !data.data) {
                     // 这里的逻辑有点绕，主要是处理第二次fetch的结果
                     // 如果第二次fetch失败（比如覆盖导入也失败），已经在上面或者下面的catch里处理了？
                     // 不，第二次fetch返回json后，会进入这个then
                     if (data.message) { // 只有出错时会有message
                         progressWrapper.style.display = 'none';
                         showAlert('error', data.message);
                         nextBtn.disabled = false;
                         nextBtn.textContent = '开始安装 →';
                     }
                }
            })
            .catch(err => {
                if (progressTimer) clearInterval(progressTimer);
                progressWrapper.style.display = 'none';
                showAlert('error', '数据库导入失败: ' + err.message);
                nextBtn.disabled = false;
                nextBtn.textContent = '开始安装 →';
            });
        }

        // 短信配置相关
        const sendTestSmsBtn = document.getElementById('send-test-sms-btn');
        const verifySmsBtn = document.getElementById('verify-sms-btn');
        
        sendTestSmsBtn.onclick = function() {
            const accessKeyId = document.getElementById('access-key-id').value.trim();
            const accessKeySecret = document.getElementById('access-key-secret').value.trim();
            const testPhone = document.getElementById('test-phone').value.trim();
            
            if (!accessKeyId || !accessKeySecret || !testPhone) {
                showAlert('error', '请填写AccessKey ID、Secret和测试手机号');
                return;
            }
            
            sendTestSmsBtn.disabled = true;
            sendTestSmsBtn.textContent = '发送中...';
            
            const formData = new FormData();
            formData.append('access_key_id', accessKeyId);
            formData.append('access_key_secret', accessKeySecret);
            formData.append('test_phone', testPhone);
            
            fetch('install/install_api.php?action=send_test_sms', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', '测试短信已发送，请查收验证码');
                    verifySmsBtn.disabled = false;
                    // 倒计时
                    let countdown = 60;
                    const timer = setInterval(() => {
                        sendTestSmsBtn.textContent = `${countdown}秒后重试`;
                        countdown--;
                        if (countdown < 0) {
                            clearInterval(timer);
                            sendTestSmsBtn.disabled = false;
                            sendTestSmsBtn.textContent = '发送测试短信';
                        }
                    }, 1000);
                } else {
                    showAlert('error', data.message);
                    sendTestSmsBtn.disabled = false;
                    sendTestSmsBtn.textContent = '发送测试短信';
                }
            })
            .catch(err => {
                showAlert('error', '请求失败: ' + err.message);
                sendTestSmsBtn.disabled = false;
                sendTestSmsBtn.textContent = '发送测试短信';
            });
        };
        
        verifySmsBtn.onclick = function() {
            const verifyCode = document.getElementById('verify-code').value.trim();
            if (!verifyCode) {
                showAlert('error', '请输入验证码');
                return;
            }
            
            verifySmsBtn.disabled = true;
            verifySmsBtn.textContent = '验证中...';
            
            const formData = new FormData();
            formData.append('verify_code', verifyCode);
            
            fetch('install/install_api.php?action=verify_test_sms', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', '验证成功！');
                    smsVerified = true;
                    verifySmsBtn.textContent = '已验证';
                    nextBtn.textContent = '完成安装 →';
                    nextBtn.onclick = function() {
                        showStep(5);
                        completeInstall();
                    };
                } else {
                    showAlert('error', data.message);
                    verifySmsBtn.disabled = false;
                    verifySmsBtn.textContent = '验证并保存';
                }
            })
            .catch(err => {
                showAlert('error', '请求失败: ' + err.message);
                verifySmsBtn.disabled = false;
                verifySmsBtn.textContent = '验证并保存';
            });
        };
        
        function handleSkipSms() {
            if (confirm('确定要跳过短信配置吗？跳过将无法使用手机号注册功能，且会覆盖现有的注册文件。')) {
                nextBtn.disabled = true;
                nextBtn.textContent = '正在配置...';
                
                fetch('install/install_api.php?action=skip_sms_config', {
                    method: 'POST'
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showStep(5);
                        completeInstall();
                    } else {
                        showAlert('error', data.message);
                        nextBtn.disabled = false;
                        nextBtn.textContent = '跳过 →';
                    }
                })
                .catch(err => {
                    showAlert('error', '请求失败: ' + err.message);
                    nextBtn.disabled = false;
                    nextBtn.textContent = '跳过 →';
                });
            }
        }

        // 完成安装
        function completeInstall() {
            fetch('install/install_api.php?action=complete_install', {
                method: 'POST'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    updateProgress(100, '安装完成！');
                    setTimeout(() => {
                        showAlert('success', '安装完成！');
                        showStep(4);
                        
                        // 构建管理员信息HTML
                        let adminInfoHtml = '';
                        // 强制检查 window.adminInfo 是否存在
                        if (window.adminInfo && window.adminInfo.email && window.adminInfo.password) {
                            adminInfoHtml = `
                            <div style="background: #f6ffed; border: 1px solid #b7eb8f; padding: 20px; border-radius: 10px; margin: 25px 0; text-align: left;">
                                <h3 style="color: #52c41a; margin-bottom: 15px; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 20px;">🛡️</span> 为了您的服务器安全已自动创建Admin用户
                                </h3>
                                <div style="background: rgba(255,255,255,0.6); padding: 15px; border-radius: 6px;">
                                    <p style="color: #666; margin-bottom: 8px; font-family: monospace; font-size: 14px;">
                                        账号：<strong style="color: #333;">${window.adminInfo.email}</strong>
                                    </p>
                                    <p style="color: #666; margin-bottom: 0; font-family: monospace; font-size: 14px;">
                                        密码：<strong style="color: #ff4d4f; font-size: 18px; letter-spacing: 1px;">${window.adminInfo.password}</strong>
                                    </p>
                                </div>
                                <p style="color: #888; font-size: 13px; margin-top: 15px; display: flex; align-items: center; gap: 5px;">
                                    <span>💡</span> 您可以使用此账号直接登录无需注册，请妥善保存密码！
                                </p>
                            </div>`;
                        } else {
                            // 如果因为某种原因没有获取到密码，显示默认提示
                            const errorMsg = window.adminCreationError ? 
                                `<br><span style="color: #ff4d4f; font-size: 13px;">具体错误：${window.adminCreationError}</span>` : 
                                '但由于网络或状态原因未能获取到随机密码。';
                            
                             adminInfoHtml = `
                            <div style="background: #fffbe6; border: 1px solid #ffe58f; padding: 20px; border-radius: 10px; margin: 25px 0; text-align: left;">
                                <h3 style="color: #faad14; margin-bottom: 15px; font-size: 16px;">
                                    ⚠️ 管理员账号提示
                                </h3>
                                <p style="color: #666; font-size: 14px;">
                                    系统尝试为您创建了管理员账号：<strong>admin@admin.com.cn</strong>
                                </p>
                                <p style="color: #666; font-size: 14px; margin-top: 5px;">
                                    ${errorMsg}
                                </p>
                                <p style="color: #666; font-size: 14px; margin-top: 5px;">
                                    请检查数据库 <code>users</code> 表，或使用注册功能注册新账号（第一个注册的用户通常会自动获得管理员权限）。
                                </p>
                            </div>`;
                        }

                        // 显示安装完成提示
                        document.body.innerHTML = `
                        <div style="text-align: center; background: white; padding: 40px; border-radius: 20px; box-shadow: 0 6px 24px rgba(0, 0, 0, 0.15); max-width: 600px; width: 100%; margin: 20px;">
                            <div style="font-size: 60px; color: #52c41a; margin-bottom: 20px;">✓</div>
                            <h1 style="color: #333; margin-bottom: 10px;">安装完成</h1>
                            <p style="color: #666; font-size: 16px;">此页面已被清除，系统已准备就绪。</p>
                            ${adminInfoHtml}
                            <a href="login.php" style="display: inline-block; margin-top: 10px; padding: 12px 30px; background: linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 600; box-shadow: 0 4px 15px rgba(18, 183, 245, 0.3); transition: all 0.3s;">进入系统</a>
                        </div>`;
                        
                        // 发送请求删除安装文件
                        fetch('install/delete_install_files.php');
                    }, 500);
                } else {
                    progressWrapper.style.display = 'none';
                    showAlert('error', data.message);
                    nextBtn.disabled = false;
                    nextBtn.textContent = '开始安装 →';
                }
            })
            .catch(err => {
                progressWrapper.style.display = 'none';
                showAlert('error', '安装失败: ' + err.message);
                nextBtn.disabled = false;
                nextBtn.textContent = '开始安装 →';
            });
        }

        // 初始化
        window.onload = function() {
            getVersionInfo();
            nextBtn.onclick = handleNext;

            // 兼容模式切换
            const compatMode = document.getElementById('compat-mode');
            const compatFields = document.getElementById('compat-fields');
            if (compatMode && compatFields) {
                compatMode.addEventListener('change', function() {
                    compatFields.style.display = this.checked ? 'block' : 'none';
                });
            }
        };
    </script>
</body>
</html>
