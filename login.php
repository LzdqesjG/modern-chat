<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - Modern Chat</title>
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
        }
        
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.15);
            padding: 40px;
            width: 100%;
            max-width: 450px;
            animation: messageSlide 0.6s ease-out;
            border: 2px solid transparent;
        }
        
        @keyframes messageSlide {
            from {
                opacity: 0;
                transform: translateY(20px);
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
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #fafafa;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #12b7f5;
            background: white;
            box-shadow: 0 0 0 3px rgba(18, 183, 245, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
            box-shadow: 0 5px 18px rgba(18, 183, 245, 0.5);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(18, 183, 245, 0.6);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        /* 注册链接样式 - 与helper-links保持一致 */
        .register-link {
            color: #666;
            font-size: 14px;
        }
        
        .register-link a {
            color: #12b7f5;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .register-link a:hover {
            color: #00a2e8;
            text-decoration: underline;
        }
        
        .error-message {
            background: rgba(255, 77, 79, 0.1);
            color: #ff4d4f;
            padding: 14px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 2px solid rgba(255, 77, 79, 0.2);
        }
        
        .success-message {
            background: rgba(158, 234, 106, 0.1);
            color: #52c41a;
            padding: 14px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 2px solid rgba(158, 234, 106, 0.2);
        }
        
        /* 登录选项切换 */
        .login-options {
            display: flex;
            margin-bottom: 30px;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid #e0e0e0;
        }
        
        .login-option {
            flex: 1;
            padding: 14px;
            text-align: center;
            cursor: pointer;
            background: #fafafa;
            transition: all 0.3s ease;
            font-weight: 600;
            color: #666;
        }
        
        .login-option.active {
            background: linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%);
            color: white;
            border-color: #12b7f5;
        }
        
        /* 登录方式容器 */
        .login-method {
            display: none;
        }
        
        .login-method.active {
            display: block;
        }
        
        /* 二维码样式 */
        .qr-container {
            text-align: center;
            margin: 20px 0;
        }
        
        #qr-code {
            display: inline-block;
            padding: 10px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.15);
        }
        
        .qr-info {
            margin-top: 15px;
            color: #666;
            font-size: 14px;
        }
        
        .countdown {
            font-weight: 600;
            color: #12b7f5;
        }
        
        /* 状态提示 */
        .status-message {
            margin: 15px 0;
            padding: 14px;
            border-radius: 12px;
            font-size: 14px;
            text-align: center;
        }
        
        .status-pending {
            background: rgba(18, 183, 245, 0.1);
            color: #12b7f5;
            border: 2px solid rgba(18, 183, 245, 0.2);
        }
        
        .status-scanning {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
            border: 2px solid rgba(255, 193, 7, 0.2);
        }
        
        .status-success {
            background: rgba(158, 234, 106, 0.1);
            color: #52c41a;
            border: 2px solid rgba(158, 234, 106, 0.2);
        }
        
        .status-error {
            background: rgba(255, 77, 79, 0.1);
            color: #ff4d4f;
            border: 2px solid rgba(255, 77, 79, 0.2);
        }
        
        .helper-links {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            color: #666;
        }

        .helper-links a {
            color: #12b7f5;
            text-decoration: none;
            transition: color 0.3s ease;
            font-weight: 600;
        }

        .helper-links a:hover {
            color: #00a2e8;
            text-decoration: underline;
        }

        /* 协议复选框样式 */
        .agreement-checkbox {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .agreement-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 0 10px 0 0;
            cursor: pointer;
            accent-color: #12b7f5;
        }

        .agreement-checkbox label {
            font-size: 13px;
            color: #666;
            cursor: pointer;
            flex: 1;
            margin: 0;
            display: flex;
            align-items: center;
        }

        .agreement-checkbox a {
            color: #12b7f5;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .agreement-checkbox a:hover {
            text-decoration: underline;
        }

        /* 协议预览弹窗样式 */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #e0e0e0;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 20px;
            color: #333;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: #999;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: #f5f5f5;
            color: #333;
        }

        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
            line-height: 1.8;
            color: #555;
            font-size: 14px;
        }

        .modal-body h1, .modal-body h2, .modal-body h3 {
            color: #333;
            margin-top: 20px;
            margin-bottom: 10px;
        }

        .modal-body h1 { font-size: 20px; }
        .modal-body h2 { font-size: 18px; }
        .modal-body h3 { font-size: 16px; }

        .modal-body ul {
            margin: 10px 0;
            padding-left: 20px;
        }

        .modal-body li {
            margin: 5px 0;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .modal-btn {
            padding: 10px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .modal-btn-primary {
            background: #12b7f5;
            color: white;
        }

        .modal-btn-primary:hover {
            background: #00a2e8;
        }

        .modal-btn-secondary {
            background: #f5f5f5;
            color: #666;
        }

        .modal-btn-secondary:hover {
            background: #e0e0e0;
        }

        .modal-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .modal-btn-primary:disabled {
            background: #ccc;
        }

        /* 阅读进度提示 */
        .read-progress {
            position: sticky;
            top: 0;
            background: white;
            padding: 12px 24px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 13px;
            color: #666;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 10;
        }

        .read-progress-info {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 180px;
        }

        .read-progress-bar {
            flex: 1;
            height: 4px;
            background: #e0e0e0;
            border-radius: 2px;
            overflow: hidden;
        }

        .read-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #12b7f5, #00a2e8);
            width: 0;
            transition: width 0.3s ease;
        }

        .read-progress-text {
            min-width: 80px;
            text-align: right;
            font-size: 12px;
            color: #999;
        }

        .read-progress .check-icon {
            display: none;
            color: #52c41a;
            font-size: 14px;
        }

        .read-progress.completed .check-icon {
            display: block;
        }

        .read-progress.completed .read-progress-text {
            color: #52c41a;
            font-weight: 600;
        }

        /* 验证码容器样式 */
        #captcha {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 10px 0;
        }
        
        @media (max-width: 500px) {
            .container {
                margin: 20px;
                padding: 30px 20px;
            }

            h1 {
                font-size: 24px;
            }

            .helper-links {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
    <!-- 极验验证码JS库 -->
    <script src="https://static.geetest.com/v4/gt4.js"></script>
</head>
<body>
    <div class="container">
        <h1>登录</h1>
        
                
                
        <!-- 登录选项 -->
        <div class="login-options">
            <div class="login-option active" data-method="password">密码登录</div>
                        <div class="login-option" data-method="scan">扫码登录</div>
                    </div>
        
        <!-- 密码登录 -->
        <div class="login-method active" id="password-login">
            <form action="login_process.php" method="POST" onsubmit="return handleLoginSubmit(this);">
                <div class="form-group">
                    <label for="email">邮箱</label>
                    <input type="email" id="email" name="email" required>
                </div>
                
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <!-- 极验验证码容器 -->
                <div class="form-group">
                    <div id="captcha"></div>
                </div>
                
                <!-- 极验验证结果隐藏字段 -->
                <input type="hidden" name="geetest_challenge" id="geetest_challenge">
                <input type="hidden" name="geetest_validate" id="geetest_validate">
                <input type="hidden" name="geetest_seccode" id="geetest_seccode">
                
                <!-- 浏览器指纹隐藏字段 -->
                <input type="hidden" name="browser_fingerprint" id="browser_fingerprint">

                <!-- 协议同意复选框 -->
                <div class="agreement-checkbox">
                    <input type="checkbox" id="agree_terms" name="agree_terms">
                    <label for="agree_terms">
                        我已阅读并同意 <a href="javascript:void(0)" onclick="showModal('terms')">《用户协议》</a> 和 <a href="javascript:void(0)" onclick="showModal('privacy')">《隐私协议》</a>
                    </label>
                </div>

                <button type="submit" class="btn" id="loginBtn">登录</button>
            </form>
            
            <div class="helper-links">
                <div class="forget-password">
                    忘记密码？ <a href="forgetpassword.php">点击这里</a>
                </div>
                
                <div class="register-link">
                    还没有账户？ <a href="register.php">立即注册</a>
                </div>
            </div>
        </div>
        
        <!-- 扫码登录（仅在PC端显示） -->
                <div class="login-method" id="scan-login">
            <div class="qr-container">
                <div id="qr-code"></div>
                <div class="qr-info">
                    <p>使用手机APP扫描二维码登录</p>
                    <p>有效期 <span class="countdown" id="countdown">5:00</span></p>
                </div>
                <div class="status-message status-pending" id="status-message">
                    等待手机确认...
                </div>
            </div>
        </div>
            </div>

    <!-- 协议预览弹窗 -->
    <div class="modal-overlay" id="agreementModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">协议标题</h2>
                <button class="modal-close" onclick="closeModal()">×</button>
            </div>
            <div class="read-progress" id="readProgress">
                <div class="read-progress-info">
                    <span class="check-icon">✓</span>
                    <span>阅读进度</span>
                    <span id="timeRemaining" style="font-size: 12px; color: #999;">还需阅读 10 秒</span>
                </div>
                <div class="read-progress-bar">
                    <div class="read-progress-fill" id="progressFill"></div>
                </div>
                <span class="read-progress-text" id="progressText">0%</span>
            </div>
            <div class="modal-body" id="modalBody">
                协议内容加载中...
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-secondary" onclick="closeModal()">关闭</button>
                <button class="modal-btn modal-btn-primary" id="agreeBtn" disabled onclick="agreeAndClose()">请先完整阅读协议</button>
            </div>
        </div>
    </div>
    
    <script src="./js/qrcode.min.js"></script>
    <script>
        // 浏览器指纹生成功能
        function generateBrowserFingerprint() {
            // 收集浏览器信息
            const fingerprintData = {
                userAgent: navigator.userAgent,
                screenResolution: screen.width + 'x' + screen.height,
                colorDepth: screen.colorDepth,
                timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                language: navigator.language,
                platform: navigator.platform,
                cookieEnabled: navigator.cookieEnabled,
                localStorageEnabled: typeof(Storage) !== 'undefined' && typeof(Storage.prototype.getItem) === 'function',
                sessionStorageEnabled: typeof(Storage) !== 'undefined' && typeof(Storage.prototype.getItem) === 'function',
                plugins: Array.from(navigator.plugins).map(plugin => plugin.name + ' ' + plugin.version).join(','),
                hardwareConcurrency: navigator.hardwareConcurrency || 0,
                deviceMemory: navigator.deviceMemory || 0
            };
            
            // 将数据转换为字符串
            const fingerprintString = JSON.stringify(fingerprintData);
            
            // 使用SHA-256生成哈希值
            return crypto.subtle.digest('SHA-256', new TextEncoder().encode(fingerprintString))
                .then(hashBuffer => {
                    // 将ArrayBuffer转换为十六进制字符串
                    const hashArray = Array.from(new Uint8Array(hashBuffer));
                    const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
                    return hashHex;
                });
        }
        
        // 登录选项切换
        document.querySelectorAll('.login-option').forEach(option => {
            option.addEventListener('click', () => {
                const method = option.dataset.method;
                
                // 更新选项状态
                document.querySelectorAll('.login-option').forEach(opt => opt.classList.remove('active'));
                option.classList.add('active');
                
                // 更新登录方式显示
                document.querySelectorAll('.login-method').forEach(methodEl => methodEl.classList.remove('active'));
                document.getElementById(method + '-login').classList.add('active');
                
                // 如果从扫码登录切换到其他登录方式，停止监测和倒计时
                if (method !== 'scan') {
                    clearInterval(checkInterval);
                    clearInterval(countdownInterval);
                }
                
                // 如果切换到扫码登录，初始化二维码
                if (method === 'scan') {
                    initScanLogin();
                }
            });
        });
        
        // 扫码登录初始化
        let checkInterval;
        let countdownInterval;
        let currentQid;
        
        async function initScanLogin() {
            // 清除之前的定时器
            if (checkInterval) clearInterval(checkInterval);
            if (countdownInterval) clearInterval(countdownInterval);
            
            try {
                // 生成浏览器指纹
                const fingerprint = await generateBrowserFingerprint();
                
                // 获取二维码，传递浏览器指纹
                const response = await fetch('scan_login.php', {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    credentials: 'include',
                    // 将浏览器指纹作为URL参数传递
                    cache: 'no-cache'
                });
                
                const data = await response.json();
                
                if (data.success) {
                    currentQid = data.qid;
                    
                    // 生成二维码（使用qrcode.js库，无需外部API）
                    const qrCode = document.getElementById('qr-code');
                    qrCode.innerHTML = '<canvas id="qr-canvas"></canvas>';
                    const canvas = document.getElementById('qr-canvas');
                    
                    // 将浏览器指纹添加到二维码内容中
                    const qrContentWithFingerprint = data.qr_content + '&browser_fingerprint=' + encodeURIComponent(fingerprint);
                    
                    QRCode.toCanvas(canvas, qrContentWithFingerprint, {
                        width: 200,
                        margin: 1,
                        color: {
                            dark: '#000000',
                            light: '#ffffff'
                        }
                    }, function(error) {
                        if (error) {
                            console.error('生成二维码失败:', error);
                            qrCode.innerHTML = '<p style="color: #ff4757;">生成二维码失败，请重试</p>';
                        }
                    });
                    
                    // 初始化倒计时
                    startCountdown();
                    
                    // 开始检查登录状态
                    checkLoginStatus();
                } else {
                    document.getElementById('status-message').textContent = data.message || '生成二维码失败，请重试';
                    document.getElementById('status-message').className = 'status-message status-error';
                }
            } catch (error) {
                console.error('获取二维码失败:', error);
                document.getElementById('status-message').textContent = '生成二维码失败，请重试';
                document.getElementById('status-message').className = 'status-message status-error';
            }
        }
        
        // 倒计时
        function startCountdown() {
            let seconds = 300; // 5分钟
            const countdownEl = document.getElementById('countdown');
            
            countdownInterval = setInterval(() => {
                seconds--;
                const minutes = Math.floor(seconds / 60);
                const secs = seconds % 60;
                countdownEl.textContent = `${minutes}:${secs.toString().padStart(2, '0')}`;
                
                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                    clearInterval(checkInterval);
                    // 显示二维码失效提示
                    const qrCode = document.getElementById('qr-code');
                    const statusMsg = document.getElementById('status-message');
                    
                    // 隐藏原有的状态信息
                    statusMsg.textContent = '';
                    statusMsg.className = 'status-message';
                    
                    // 在二维码中间显示失效提示
                    qrCode.innerHTML = `
                        <div style="position: relative; width: 200px; height: 200px; background: #f5f5f5; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-direction: column;">
                            <div style="font-size: 48px; margin-bottom: 10px;">⏰</div>
                            <p style="text-align: center; font-size: 14px; color: #666; margin-bottom: 15px;">二维码已失效</p>
                            <button onclick="initScanLogin()" style="padding: 8px 16px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; transition: background-color 0.2s;">
                                点击刷新二维码
                            </button>
                        </div>
                    `;
                }
            }, 1000);
        }
        
        // 检查登录状态
        function checkLoginStatus() {
            checkInterval = setInterval(() => {
                fetch(`scan_login.php?check_status=true&qid=${currentQid}`)
                    .then(response => response.json())
                    .then(data => {
                        const statusMsg = document.getElementById('status-message');
                        
                        // 调试信息
                        console.log('登录状态检查结果:', data);
                        
                        if (data.status === 'success') {
                            // 清除定时器，避免重复请求
                            clearInterval(checkInterval);
                            // 登录成功，跳转到login_process.php，使用token而不是直接使用user_id
                            console.log('登录成功，跳转到:', `login_process.php?scan_login=true&token=${data.token}`);
                            window.location.href = `login_process.php?scan_login=true&token=${data.token}`;
                        } else if (data.status === 'expired') {
                            // 二维码过期，刷新
                            clearInterval(checkInterval);
                            statusMsg.textContent = '二维码已过期，正在刷新...';
                            statusMsg.className = 'status-message status-pending';
                            setTimeout(initScanLogin, 1000);
                        } else if (data.status === 'scanned') {
                            // 已扫描，等待手机确认
                            statusMsg.textContent = '手机已扫描，等待确认登录...';
                            statusMsg.className = 'status-message status-scanned';
                        } else if (data.status === 'rejected') {
                            // 手机端拒绝登录
                            clearInterval(checkInterval);
                            clearInterval(countdownInterval);
                            
                            // 隐藏原有的状态信息
                            statusMsg.textContent = '';
                            statusMsg.className = 'status-message';
                            
                            // 在二维码中间显示失效提示
                            const qrCode = document.getElementById('qr-code');
                            qrCode.innerHTML = `
                                <div style="position: relative; width: 200px; height: 200px; background: #f5f5f5; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-direction: column;">
                                    <div style="font-size: 48px; margin-bottom: 10px;">❌</div>
                                    <p style="text-align: center; font-size: 14px; color: #666; margin-bottom: 15px;">手机端拒绝了登录请求</p>
                                    <button onclick="initScanLogin()" style="padding: 8px 16px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; transition: background-color 0.2s;">
                                        点击刷新二维码
                                    </button>
                                </div>
                            `;
                        } else if (data.status === 'error') {
                            // 错误状态
                            statusMsg.textContent = '检查登录状态失败: ' + (data.message || '未知错误');
                            statusMsg.className = 'status-message status-error';
                        } else {
                            // 等待扫描
                            statusMsg.textContent = '等待扫描...';
                            statusMsg.className = 'status-message status-pending';
                        }
                    })
                    .catch(error => {
                        console.error('检查登录状态失败:', error);
                        const statusMsg = document.getElementById('status-message');
                        statusMsg.textContent = '网络错误，检查登录状态失败';
                        statusMsg.className = 'status-message status-error';
                    });
            }, 1000); // 每1秒检查一次，提高响应速度
        }
        
        // 极验验证码初始化
        let geetestCaptcha = null;
        
        // 初始化极验验证码
        initGeetest4({
            captchaId: '55574dfff9c40f2efeb5a26d6d188245'
        }, function (captcha) {
            // captcha为验证码实例
            geetestCaptcha = captcha;
            captcha.appendTo("#captcha");// 调用appendTo将验证码插入到页的某一个元素中
        });
        
        // 表单提交处理，生成浏览器指纹
        async function handleLoginSubmit(form) {
            // 检查是否同意协议
            const agreeCheckbox = document.getElementById('agree_terms');
            if (!agreeCheckbox.checked) {
                alert('请勾选同意用户协议和隐私协议');
                return false;
            }

            // 检查极验验证码是否通过
            if (!geetestCaptcha || !geetestCaptcha.getValidate()) {
                alert('请完成验证码验证');
                return false;
            }
            
            // 获取验证码验证结果
            const validate = geetestCaptcha.getValidate();
            if (validate) {
                // 极验4.0返回的参数
                document.getElementById('geetest_challenge').value = validate.lot_number;
                document.getElementById('geetest_validate').value = validate.captcha_output;
                document.getElementById('geetest_seccode').value = validate.pass_token;
                
                // 添加新的隐藏字段用于极验4.0二次校验
                const genTimeInput = document.createElement('input');
                genTimeInput.type = 'hidden';
                genTimeInput.name = 'gen_time';
                genTimeInput.value = validate.gen_time;
                form.appendChild(genTimeInput);
                
                const captchaIdInput = document.createElement('input');
                captchaIdInput.type = 'hidden';
                captchaIdInput.name = 'captcha_id';
                captchaIdInput.value = '55574dfff9c40f2efeb5a26d6d188245';
                form.appendChild(captchaIdInput);
            }
            
            // 生成浏览器指纹
            const fingerprintInput = document.getElementById('browser_fingerprint');
            if (!fingerprintInput.value) {
                const fingerprint = await generateBrowserFingerprint();
                fingerprintInput.value = fingerprint;
            }
            return true;
        }
        
        // 页面加载完成后，如果扫码登录是默认选项，初始化二维码
        document.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('scan-login').classList.contains('active')) {
                initScanLogin();
            }
        });

        // 协议预览功能
        const agreements = {
            terms: {
                title: '用户协议',
                url: 'Agreement/terms_of_service.md'
            },
            privacy: {
                title: '隐私协议',
                url: 'Agreement/privacy_policy.md'
            }
        };

        const MIN_READ_TIME = 10; // 最小阅读时间（秒）

        let currentAgreement = null;
        let readStatus = {
            terms: { scrolledToBottom: false, readTime: 0, completed: false },
            privacy: { scrolledToBottom: false, readTime: 0, completed: false }
        };
        let readTimer = null;
        let readStartTime = null;

        // 显示协议弹窗
        async function showModal(type) {
            currentAgreement = type;
            const modal = document.getElementById('agreementModal');
            const titleEl = document.getElementById('modalTitle');
            const bodyEl = document.getElementById('modalBody');
            const agreeBtn = document.getElementById('agreeBtn');
            const progressFill = document.getElementById('progressFill');
            const progressText = document.getElementById('progressText');
            const timeRemaining = document.getElementById('timeRemaining');
            const readProgress = document.getElementById('readProgress');

            titleEl.textContent = agreements[type].title;
            bodyEl.innerHTML = '<div style="text-align: center; padding: 40px;">加载中...</div>';

            // 重置进度
            progressFill.style.width = '0%';
            progressText.textContent = '0%';
            readProgress.classList.remove('completed');
            timeRemaining.textContent = '还需阅读 ' + MIN_READ_TIME + ' 秒';

            // 清除之前的计时器
            if (readTimer) {
                clearInterval(readTimer);
                readTimer = null;
            }

            // 根据阅读状态设置按钮
            if (readStatus[type].completed) {
                agreeBtn.disabled = false;
                agreeBtn.textContent = '已阅读并同意';
                readProgress.classList.add('completed');
                progressFill.style.width = '100%';
                progressText.textContent = '100%';
                timeRemaining.textContent = '已完成阅读';
            } else {
                agreeBtn.disabled = true;
                agreeBtn.textContent = '请先完整阅读协议';
            }

            modal.classList.add('active');

            try {
                const response = await fetch(agreements[type].url);
                if (response.ok) {
                    const content = await response.text();
                    bodyEl.innerHTML = renderMarkdown(content);

                    // 添加滚动监听
                    setTimeout(() => {
                        setupScrollListener(type);
                    }, 100);
                } else {
                    bodyEl.innerHTML = '<div style="text-align: center; padding: 40px; color: #ff4d4f;">加载失败，请稍后重试</div>';
                }
            } catch (error) {
                console.error('加载协议失败:', error);
                bodyEl.innerHTML = '<div style="text-align: center; padding: 40px; color: #ff4d4f;">加载失败，请稍后重试</div>';
            }
        }

        // 设置滚动监听
        function setupScrollListener(type) {
            const bodyEl = document.getElementById('modalBody');
            const progressFill = document.getElementById('progressFill');
            const progressText = document.getElementById('progressText');
            const agreeBtn = document.getElementById('agreeBtn');
            const readProgress = document.getElementById('readProgress');
            const timeRemaining = document.getElementById('timeRemaining');

            // 如果该协议尚未完成，开始计时
            if (!readStatus[type].completed) {
                readStartTime = Date.now();
                readTimer = setInterval(() => {
                    const elapsed = Math.floor((Date.now() - readStartTime) / 1000);
                    const remaining = Math.max(0, MIN_READ_TIME - elapsed);

                    if (remaining > 0) {
                        timeRemaining.textContent = '还需阅读 ' + remaining + ' 秒';
                    } else {
                        timeRemaining.textContent = '阅读时间已达要求';
                        checkCanAgree(type);
                    }
                }, 1000);
            }

            bodyEl.onscroll = function() {
                const scrollTop = bodyEl.scrollTop;
                const scrollHeight = bodyEl.scrollHeight;
                const clientHeight = bodyEl.clientHeight;

                // 计算滚动百分比
                const scrollPercent = Math.min(100, Math.round((scrollTop / (scrollHeight - clientHeight)) * 100));

                progressFill.style.width = scrollPercent + '%';
                progressText.textContent = scrollPercent + '%';

                // 判断是否滚动到底部（允许5px误差）
                if (scrollTop + clientHeight >= scrollHeight - 5) {
                    readStatus[type].scrolledToBottom = true;
                    checkCanAgree(type);
                }
            };
        }

        // 检查是否可以同意
        function checkCanAgree(type) {
            const agreeBtn = document.getElementById('agreeBtn');
            const readProgress = document.getElementById('readProgress');
            const timeRemaining = document.getElementById('timeRemaining');

            const elapsed = Math.floor((Date.now() - readStartTime) / 1000);
            const timeMet = elapsed >= MIN_READ_TIME;
            const scrolled = readStatus[type].scrolledToBottom;

            if (scrolled && timeMet) {
                readStatus[type].completed = true;
                readStatus[type].readTime = elapsed;

                agreeBtn.disabled = false;
                agreeBtn.textContent = '已阅读并同意';
                readProgress.classList.add('completed');
                timeRemaining.textContent = '已完成阅读';

                // 清除计时器
                if (readTimer) {
                    clearInterval(readTimer);
                    readTimer = null;
                }
            }
        }

        // 关闭弹窗
        function closeModal() {
            const bodyEl = document.getElementById('modalBody');
            if (bodyEl.onscroll) {
                bodyEl.onscroll = null;
            }
            if (readTimer) {
                clearInterval(readTimer);
                readTimer = null;
            }
            document.getElementById('agreementModal').classList.remove('active');
            currentAgreement = null;
        }

        // 同意并关闭
        function agreeAndClose() {
            closeModal();
        }

        // 检查登录是否允许
        function canLogin() {
            // 两个协议都必须完成阅读
            return readStatus.terms.completed && readStatus.privacy.completed;
        }

        // 简单的 Markdown 渲染
        function renderMarkdown(text) {
            return text
                // 标题
                .replace(/^### (.+)$/gm, '<h3>$1</h3>')
                .replace(/^## (.+)$/gm, '<h2>$1</h2>')
                .replace(/^# (.+)$/gm, '<h1>$1</h1>')
                // 分隔线
                .replace(/^---$/gm, '<hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">')
                // 粗体
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                // 列表
                .replace(/^- (.+)$/gm, '<li>$1</li>')
                .replace(/(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>')
                // 段落
                .replace(/^([^<\n].+)$/gm, '<p>$1</p>')
                // 清理空段落
                .replace(/<p><\/p>/g, '')
                .replace(/<p>(<h[1-6]>)/g, '$1')
                .replace(/(<\/h[1-6]>)<\/p>/g, '$1');
        }

        // 点击遮罩层关闭弹窗
        document.getElementById('agreementModal').addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-overlay')) {
                closeModal();
            }
        });

        // ESC键关闭弹窗
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>
<script defer src="https://static.cloudflareinsights.com/beacon.min.js/vcd15cbe7772f49c399c6a5babf22c1241717689176015" integrity="sha512-ZpsOmlRQV6y907TI0dKBHq9Md29nnaEIPlkf84rnaERnq6zvWvPUqr2ft8M1aS28oN72PdrCzSjY4U6VaAw1EQ==" data-cf-beacon='{"version":"2024.11.0","token":"43749428072542b6826b2e3a6ee82a6f","r":1,"server_timing":{"name":{"cfCacheStatus":true,"cfEdge":true,"cfExtPri":true,"cfL4":true,"cfOrigin":true,"cfSpeedBrain":true},"location_startswith":null}}' crossorigin="anonymous"></script>
</body>
</html>