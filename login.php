<?php
// 连接数据库
require_once 'db.php';
?>
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
            flex-direction: column;
            gap: 15px;
        }

        .helper-links-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .helper-links-bottom {
            display: flex;
            justify-content: center;
            gap: 20px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
            font-size: 13px;
            color: #999;
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

        .helper-links-bottom a {
            font-weight: 400;
            color: #999;
        }

        .helper-links-bottom a:hover {
            color: #12b7f5;
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
        
        <?php
        if (isset($_GET['error'])) {
            echo '<div class="error-message">' . htmlspecialchars($_GET['error']) . '</div>';
        }
        if (isset($_GET['success'])) {
            echo '<div class="success-message">' . htmlspecialchars($_GET['success']) . '</div>';
        }
        
        // 忘记密码申请状态提示
        $password_request_message = '';
        
        // 检查是否有邮箱参数，用于显示忘记密码申请状态
        if (isset($_GET['email'])) {
            $email = urldecode($_GET['email']);
            
            // 获取用户的忘记密码申请状态
            try {
                // 先通过邮箱获取用户名
                $stmt = $conn->prepare("SELECT username FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $username = $user['username'];
                    
                    // 查询最新的忘记密码申请
                    $stmt = $conn->prepare("SELECT status FROM forget_password_requests WHERE username = ? ORDER BY created_at DESC LIMIT 1");
                    $stmt->execute([$username]);
                    $request = $stmt->fetch();
                    
                    if ($request) {
                        switch ($request['status']) {
                            case 'approved':
                                $password_request_message = '您的修改密码申请已通过，请使用新密码登录';
                                $message_type = 'success';
                                break;
                            case 'rejected':
                                $password_request_message = '您的修改密码申请无法通过';
                                $message_type = 'error';
                                break;
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Check password request status error: " . $e->getMessage());
            }
        }
        
        // 显示忘记密码申请状态提示
        if (!empty($password_request_message)) {
            $message_class = $message_type === 'error' ? 'error-message' : 'success-message';
            echo '<div class="' . $message_class . '">' . $password_request_message . '</div>';
        }
        ?>
        
        <?php
        // 检测设备类型
        function isMobileDevice() {
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            $mobileAgents = array('Android', 'iPhone', 'iPad', 'iPod', 'BlackBerry', 'Windows Phone', 'Mobile', 'Opera Mini', 'Fennec', 'IEMobile');
            foreach ($mobileAgents as $agent) {
                if (stripos($userAgent, $agent) !== false) {
                    return true;
                }
            }
            return false;
        }
        
        $is_mobile = isMobileDevice();
        ?>
        
        <!-- 登录选项 -->
        <div class="login-options">
            <div class="login-option active" data-method="password">密码登录</div>
            <?php if (!$is_mobile) { ?>
            <div class="login-option" data-method="scan">扫码登录</div>
            <?php } ?>
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
                
                <button type="submit" class="btn">登录</button>
            </form>
            
            <div class="helper-links">
                <div class="helper-links-top">
                    <div class="forget-password">
                        忘记密码？ <a href="forgetpassword.php">点击这里</a>
                    </div>
                    
                    <div class="register-link">
                        还没有账户？ <a href="register.php">立即注册</a>
                    </div>
                </div>

                <div class="helper-links-bottom">
                    <a href="Agreement/terms_of_service.md" target="_blank">用户协议</a>
                    <a href="Agreement/privacy_policy.md" target="_blank">隐私协议</a>
                </div>
            </div>
        </div>
        
        <!-- 扫码登录（仅在PC端显示） -->
        <?php if (!$is_mobile) { ?>
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
        <?php } ?>
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
    </script>
</body>
</html>