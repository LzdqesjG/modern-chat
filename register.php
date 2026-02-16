<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>注册 - Modern Chat</title>
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
        
        .login-link {
            text-align: center;
            color: #666;
            font-size: 14px;
        }
        
        .login-link a {
            color: #12b7f5;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .login-link a:hover {
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

        /* 协议同意提示样式 */
        .agreement-notice {
            text-align: center;
            margin-bottom: 20px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            font-size: 13px;
            color: #666;
        }

        .agreement-notice a {
            color: #12b7f5;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .agreement-notice a:hover {
            text-decoration: underline;
        }

        .agreement-notice #agreementStatus {
            color: #ff4d4f;
            font-weight: 600;
            margin-left: 5px;
        }

        .agreement-notice.completed #agreementStatus {
            color: #52c41a;
        }

        #registerBtn:disabled {
            background: #ccc;
            cursor: not-allowed;
            box-shadow: none;
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
            flex-wrap: wrap;
        }

        .read-progress-bar {
            flex: 1;
            height: 4px;
            background: #e0e0e0;
            border-radius: 2px;
            overflow: hidden;
            min-width: 100px;
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

        /* 阅读倒计时 */
        .read-timer {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #666;
            background: #f8f9fa;
            padding: 6px 12px;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
        }

        .read-timer .timer-text {
            font-weight: 600;
            min-width: 50px;
            text-align: center;
        }

        .read-timer .timer-text.counting {
            color: #ff4d4f;
            font-size: 16px;
        }

        .read-timer .timer-text.completed {
            color: #52c41a;
        }
        
        @media (max-width: 500px) {
            .container {
                margin: 20px;
                padding: 30px 20px;
            }
            
            h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>创建账户</h1>
        
        <?php
        if (isset($_GET['error'])) {
            echo '<div class="error-message">' . htmlspecialchars($_GET['error']) . '</div>';
        }
        if (isset($_GET['success'])) {
            echo '<div class="success-message">' . htmlspecialchars($_GET['success']) . '</div>';
        }
        ?>
        
        <form action="register_process.php" method="POST" onsubmit="return handleRegisterSubmit(this);">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required minlength="3" maxlength="50">
            </div>
            
            <div class="form-group">
                <label for="email">邮箱</label>
                <input type="email" id="email" name="email" required>
            </div>
            
            <div class="form-group">
                <label for="phone">手机号</label>
                <input type="tel" id="phone" name="phone" required pattern="^1[3-9]\d{9}$" placeholder="请输入11位手机号">
            </div>
            
            <div class="form-group">
                <label for="sms_code">短信验证码</label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" id="sms_code" name="sms_code" required maxlength="6" placeholder="请输入6位验证码" style="flex: 1;">
                    <button type="button" id="send_sms_btn" class="btn" style="width: auto; padding: 0 20px; margin-bottom: 0; background: #ccc; cursor: not-allowed;" disabled>获取验证码</button>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required minlength="6">
            </div>
            
            <div class="form-group">
                <label for="confirm_password">确认密码</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
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

            <!-- 协议同意提示 -->
            <div class="agreement-notice">
                请阅读 <a href="javascript:void(0)" onclick="showModal('terms')">《用户协议》</a> 和
                <a href="javascript:void(0)" onclick="showModal('privacy')">《隐私协议》</a>
                <span id="agreementStatus">（请阅读完整协议）</span>
            </div>

            <button type="submit" class="btn" id="registerBtn" disabled>请先同意协议</button>
        </form>
        
        <div class="login-link">
            已有账户？ <a href="login.php">立即登录</a>
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
                <span class="check-icon">✓</span>
                <span>阅读进度</span>
                <div class="read-progress-bar">
                    <div class="read-progress-fill" id="progressFill"></div>
                </div>
                <span class="read-progress-text" id="progressText">0%</span>
                <div class="read-timer">
                    <span>剩余阅读时间:</span>
                    <span class="timer-text counting" id="timerText">10秒</span>
                </div>
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

    <!-- 极验验证码JS库 -->
    <script src="https://static.geetest.com/v4/gt4.js"></script>
    
    <script>
        // 极验验证码初始化
        let geetestCaptcha = null;
        let smsCountdownTimer = null;
        const SMS_COOLDOWN_KEY = 'sms_cooldown_end_time';
        
        // 检查是否有未完成的倒计时
        function checkSmsCooldown() {
            const endTime = localStorage.getItem(SMS_COOLDOWN_KEY);
            if (endTime) {
                const now = Date.now();
                const remaining = Math.ceil((parseInt(endTime) - now) / 1000);
                
                if (remaining > 0) {
                    startSmsCountdown(remaining);
                } else {
                    localStorage.removeItem(SMS_COOLDOWN_KEY);
                    resetSmsButton();
                }
            }
        }
        
        // 启动倒计时
        function startSmsCountdown(seconds) {
            const btn = document.getElementById('send_sms_btn');
            
            // 如果是新启动的倒计时（即不是从localStorage恢复的），设置结束时间
            if (!localStorage.getItem(SMS_COOLDOWN_KEY)) {
                const endTime = Date.now() + (seconds * 1000);
                localStorage.setItem(SMS_COOLDOWN_KEY, endTime);
            }
            
            btn.disabled = true;
            btn.style.background = '#ccc';
            btn.style.cursor = 'not-allowed';
            
            clearInterval(smsCountdownTimer);
            
            function updateBtn() {
                btn.textContent = `${seconds}秒后重试`;
                if (seconds <= 0) {
                    clearInterval(smsCountdownTimer);
                    localStorage.removeItem(SMS_COOLDOWN_KEY);
                    resetSmsButton();
                }
                seconds--;
            }
            
            updateBtn(); // 立即执行一次
            smsCountdownTimer = setInterval(updateBtn, 1000);
        }
        
        // 重置短信按钮状态
        function resetSmsButton() {
            const btn = document.getElementById('send_sms_btn');
            // 只有当极验验证通过后才启用按钮
            if (geetestCaptcha && geetestCaptcha.getValidate()) {
                btn.disabled = false;
                btn.style.background = 'linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%)';
                btn.style.cursor = 'pointer';
            } else {
                btn.disabled = true;
                btn.style.background = '#ccc';
                btn.style.cursor = 'not-allowed';
            }
            btn.textContent = '获取验证码';
        }

        // 初始化极验验证码
        initGeetest4({
            captchaId: '55574dfff9c40f2efeb5a26d6d188245'
        }, function (captcha) {
            // captcha为验证码实例
            geetestCaptcha = captcha;
            captcha.appendTo("#captcha");
            
            // 监听验证成功事件
            captcha.onSuccess(function() {
                const btn = document.getElementById('send_sms_btn');
                // 如果没有在倒计时中，则启用按钮
                if (!localStorage.getItem(SMS_COOLDOWN_KEY)) {
                    btn.disabled = false;
                    btn.style.background = 'linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%)';
                    btn.style.cursor = 'pointer';
                }
            });
        });
        
        // 发送短信验证码
        document.getElementById('send_sms_btn').addEventListener('click', function() {
            if (this.disabled) return;
            
            const phone = document.getElementById('phone').value;
            if (!/^1[3-9]\d{9}$/.test(phone)) {
                alert('请输入有效的11位手机号');
                return;
            }
            
            const validate = geetestCaptcha.getValidate();
            if (!validate) {
                alert('请先完成验证码验证');
                return;
            }
            
            // 准备发送数据
            const formData = new FormData();
            formData.append('phone', phone);
            formData.append('geetest_challenge', validate.lot_number);
            formData.append('geetest_validate', validate.captcha_output);
            formData.append('geetest_seccode', validate.pass_token);
            formData.append('gen_time', validate.gen_time);
            formData.append('captcha_id', '55574dfff9c40f2efeb5a26d6d188245');
            
            // 禁用按钮防止重复点击
            this.disabled = true;
            this.textContent = '发送中...';
            
            fetch('send_sms.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('验证码已发送，请注意查收');
                    startSmsCountdown(60);
                    // 发送成功后锁定手机号输入框，防止修改
                    document.getElementById('phone').readOnly = true;
                    document.getElementById('phone').style.backgroundColor = '#f0f0f0';
                } else {
                    alert(data.message || '发送失败');
                    // 如果不是倒计时引起的失败，恢复按钮
                    if (!data.message.includes('秒后')) {
                         resetSmsButton();
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('发送请求失败，请检查网络');
                resetSmsButton();
            });
        });
        
        // 页面加载时检查倒计时
        checkSmsCooldown();
        
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
        
        // 表单提交处理
        async function handleRegisterSubmit(form) {
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

        let currentAgreement = null;
        let hasReadToBottom = {
            terms: false,
            privacy: false
        };
        let hasReadForTenSeconds = {
            terms: false,
            privacy: false
        };
        let countdownTimers = {
            terms: null,
            privacy: null
        };
        let countdownSeconds = {
            terms: 10,
            privacy: 10
        };
        const REQUIRED_READ_TIME = 10; // 必须倒计时10秒

        // 显示协议弹窗
        async function showModal(type) {
            currentAgreement = type;
            const modal = document.getElementById('agreementModal');
            const titleEl = document.getElementById('modalTitle');
            const bodyEl = document.getElementById('modalBody');
            const agreeBtn = document.getElementById('agreeBtn');
            const progressFill = document.getElementById('progressFill');
            const progressText = document.getElementById('progressText');
            const timerText = document.getElementById('timerText');
            const readProgress = document.getElementById('readProgress');

            titleEl.textContent = agreements[type].title;
            bodyEl.innerHTML = '<div style="text-align: center; padding: 40px;">加载中...</div>';

            // 重置进度
            progressFill.style.width = '0%';
            progressText.textContent = '0%';
            countdownSeconds[type] = REQUIRED_READ_TIME;
            timerText.textContent = countdownSeconds[type] + '秒';
            timerText.className = 'timer-text counting';
            readProgress.classList.remove('completed');

            // 清除旧的计时器
            if (countdownTimers[type]) {
                clearInterval(countdownTimers[type]);
                countdownTimers[type] = null;
            }

            // 检查是否已经阅读完成
            const bothRead = hasReadToBottom.terms && hasReadForTenSeconds.terms &&
                           hasReadToBottom.privacy && hasReadForTenSeconds.privacy;

            if (hasReadToBottom[type] && hasReadForTenSeconds[type]) {
                agreeBtn.disabled = false;
                agreeBtn.textContent = '已阅读并同意';
                timerText.textContent = '完成';
                timerText.className = 'timer-text completed';
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

                    // 如果还没有阅读完成，启动倒计时
                    if (!hasReadForTenSeconds[type]) {
                        countdownSeconds[type] = REQUIRED_READ_TIME;
                        countdownTimers[type] = setInterval(() => {
                            countdownSeconds[type]--;
                            timerText.textContent = countdownSeconds[type] + '秒';

                            if (countdownSeconds[type] <= 0) {
                                hasReadForTenSeconds[type] = true;
                                clearInterval(countdownTimers[type]);
                                countdownTimers[type] = null;
                                timerText.textContent = '完成';
                                timerText.className = 'timer-text completed';
                                checkAgreementStatus(type);
                            }
                        }, 1000);
                    } else {
                        timerText.textContent = '完成';
                        timerText.className = 'timer-text completed';
                    }

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

            bodyEl.onscroll = function() {
                const scrollTop = bodyEl.scrollTop;
                const scrollHeight = bodyEl.scrollHeight;
                const clientHeight = bodyEl.clientHeight;

                // 计算滚动百分比
                const scrollPercent = Math.min(100, Math.round((scrollTop / (scrollHeight - clientHeight)) * 100));

                progressFill.style.width = scrollPercent + '%';
                progressText.textContent = scrollPercent + '%';

                // 判断是否滚动到底部（允许5px误差）
                if (scrollTop + clientHeight >= scrollHeight - 5 && !hasReadToBottom[type]) {
                    hasReadToBottom[type] = true;
                    readProgress.classList.add('completed');
                    checkAgreementStatus(type);
                }
            };
        }

        // 检查协议状态
        function checkAgreementStatus(type) {
            const agreeBtn = document.getElementById('agreeBtn');

            // 检查当前协议是否阅读完成
            if (hasReadToBottom[type] && hasReadForTenSeconds[type]) {
                agreeBtn.disabled = false;
                agreeBtn.textContent = '已阅读并同意';
            }

            // 检查是否两个协议都阅读完成
            const bothRead = hasReadToBottom.terms && hasReadForTenSeconds.terms &&
                           hasReadToBottom.privacy && hasReadForTenSeconds.privacy;

            const agreementStatus = document.getElementById('agreementStatus');
            const registerBtn = document.getElementById('registerBtn');

            if (bothRead) {
                agreementStatus.textContent = '（已同意）';
                agreementStatus.parentElement.classList.add('completed');
                registerBtn.disabled = false;
                registerBtn.textContent = '注册';
            } else {
                let remaining = [];
                if (!hasReadToBottom.terms || !hasReadForTenSeconds.terms) {
                    remaining.push('用户协议');
                }
                if (!hasReadToBottom.privacy || !hasReadForTenSeconds.privacy) {
                    remaining.push('隐私协议');
                }
                agreementStatus.textContent = `（还需阅读：${remaining.join('、')}）`;
                agreementStatus.parentElement.classList.remove('completed');
                registerBtn.disabled = true;
                registerBtn.textContent = '请先同意协议';
            }
        }

        // 关闭弹窗
        function closeModal() {
            const bodyEl = document.getElementById('modalBody');

            // 停止倒计时
            if (currentAgreement && countdownTimers[currentAgreement]) {
                clearInterval(countdownTimers[currentAgreement]);
                countdownTimers[currentAgreement] = null;
            }

            if (bodyEl.onscroll) {
                bodyEl.onscroll = null;
            }
            document.getElementById('agreementModal').classList.remove('active');
            currentAgreement = null;
        }

        // 同意并关闭
        function agreeAndClose() {
            closeModal();
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
</body>
</html>