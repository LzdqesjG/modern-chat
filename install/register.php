<?php
// 启用会话，必须在任何输出之前调用
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 读取配置（不设置CSP，让极验验证码可以加载）
$phone_sms_enabled = false;
$config_path = __DIR__ . '/config/config.json';
if (file_exists($config_path)) {
    $config_content = file_get_contents($config_path);
    $config = json_decode($config_content, true);
    $phone_sms_enabled = isset($config['phone_sms']) ? $config['phone_sms'] : false;
    if ($phone_sms_enabled === 'true' || $phone_sms_enabled === true) {
        $phone_sms_enabled = true;
    } else {
        $phone_sms_enabled = false;
    }
}

if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
} else {
    $error_message = '';
}

if (isset($_GET['success'])) {
    $success_message = htmlspecialchars($_GET['success']);
} else {
    $success_message = '';
}
?>
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
            margin-bottom: 20px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            font-size: 13px;
            color: #666;
            text-align: center;
            line-height: 1.6;
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
            line-height: 2.0;
            color: #555;
            font-size: 14px;
            max-height: 70vh;
        }

        .modal-body p {
            margin-bottom: 16px;
            text-align: justify;
        }

        .modal-body h1, .modal-body h2, .modal-body h3 {
            color: #333;
            margin-top: 30px;
            margin-bottom: 16px;
            padding-bottom: 8px;
        }

        .modal-body h2 {
            border-bottom: 2px solid #f0f0f0;
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
    <!-- 极验验证码JS库 -->
    <script src="https://static.geetest.com/v4/gt4.js"></script>
</head>
<body>
    <div class="container">
        <h1>创建账户</h1>
        
        <?php if ($error_message): ?>
        <div class="error-message"><?php echo $error_message; ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
        <div class="success-message"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <form action="register_process.php" method="POST" onsubmit="return handleRegisterSubmit(this);">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required minlength="3" maxlength="50">
            </div>
            
            <div class="form-group">
                <label for="email">邮箱</label>
                <input type="email" id="email" name="email" required>
            </div>
            
            <?php if ($phone_sms_enabled): ?>
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
            <?php endif; ?>
            
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
            
            <div class="agreement-notice" id="agreementNotice">
                注册即表示同意 <a href="#" onclick="showAgreement('terms'); return false;">《服务条款》</a> 和 <a href="#" onclick="showAgreement('privacy'); return false;">《隐私政策》</a>
                <span id="agreementStatus">（请先阅读协议）</span>
            </div>
            
            <button type="submit" class="btn" id="registerBtn" disabled>创建账户</button>
        </form>
        
        <div class="login-link">
            已有账户? <a href="login.php">立即登录</a>
        </div>
    </div>
    
    <!-- 服务条款弹窗 -->
    <div class="modal-overlay" id="termsModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>服务条款</h2>
                <button class="modal-close" onclick="closeModal('terms')">&times;</button>
            </div>
            <div class="read-progress" id="termsReadProgress">
                <span>阅读进度:</span>
                <div class="read-progress-bar">
                    <div class="read-progress-fill" id="termsProgressFill"></div>
                </div>
                <span class="read-progress-text" id="termsProgressText">0%</span>
                <span class="check-icon">✓</span>
                <div class="read-timer" id="termsTimer" style="display: none;">
                    <span>请等待</span>
                    <span class="timer-text" id="termsTimerText">0</span>
                    <span>秒</span>
                </div>
            </div>
            <div class="modal-body" id="termsBody" onscroll="updateReadProgress(this, 'terms')">
                加载中...
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-secondary" onclick="closeModal('terms')">关闭</button>
                <button class="modal-btn modal-btn-primary" id="termsAgreeBtn" onclick="agreeToAgreement('terms')" disabled>同意</button>
            </div>
        </div>
    </div>
    
    <!-- 隐私政策弹窗 -->
    <div class="modal-overlay" id="privacyModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>隐私政策</h2>
                <button class="modal-close" onclick="closeModal('privacy')">&times;</button>
            </div>
            <div class="read-progress" id="privacyReadProgress">
                <span>阅读进度:</span>
                <div class="read-progress-bar">
                    <div class="read-progress-fill" id="privacyProgressFill"></div>
                </div>
                <span class="read-progress-text" id="privacyProgressText">0%</span>
                <span class="check-icon">✓</span>
                <div class="read-timer" id="privacyTimer" style="display: none;">
                    <span>请等待</span>
                    <span class="timer-text" id="privacyTimerText">0</span>
                    <span>秒</span>
                </div>
            </div>
            <div class="modal-body" id="privacyBody" onscroll="updateReadProgress(this, 'privacy')">
                加载中...
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-secondary" onclick="closeModal('privacy')">关闭</button>
                <button class="modal-btn modal-btn-primary" id="privacyAgreeBtn" onclick="agreeToAgreement('privacy')" disabled>同意</button>
            </div>
        </div>
    </div>
    
    <script>
        const phoneSmsEnabled = <?php echo $phone_sms_enabled ? 'true' : 'false'; ?>;
        
        let termsAgreed = false;
        let privacyAgreed = false;
        
        let termsReadStartTime = null;
        let privacyReadStartTime = null;
        let termsTimerInterval = null;
        let privacyTimerInterval = null;
        
        // 简单的 Markdown 渲染
        function renderMarkdown(text) {
            return text
                // 处理特殊的分隔标记
                .replace(/--- #/g, '<hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;"><h2>')
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

        function showAgreement(type) {
            const modal = document.getElementById(type + 'Modal');
            modal.classList.add('active');
            
            // 重置滚动位置
            const body = document.getElementById(type + 'Body');
            body.scrollTop = 0;
            
            // 加载并渲染协议内容
            const fileName = type === 'terms' ? 'terms_of_service.md' : 'privacy_policy.md';
            const filePath = `../Agreement/${fileName}`;
            
            fetch(filePath)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Failed to load agreement');
                    }
                    return response.text();
                })
                .then(content => {
                    body.innerHTML = renderMarkdown(content);
                    // 更新进度
                    updateReadProgress(body, type);
                })
                .catch(error => {
                    console.error('Error loading agreement:', error);
                    body.innerHTML = '<div style="text-align: center; padding: 40px; color: #ff4d4f;">加载失败，请稍后重试</div>';
                });
        }
        
        function closeModal(type) {
            const modal = document.getElementById(type + 'Modal');
            modal.classList.remove('active');
            
            // 停止计时器
            if (type === 'terms' && termsTimerInterval) {
                clearInterval(termsTimerInterval);
                termsTimerInterval = null;
            } else if (type === 'privacy' && privacyTimerInterval) {
                clearInterval(privacyTimerInterval);
                privacyTimerInterval = null;
            }
        }
        
        function updateReadProgress(element, type) {
            const progressFill = document.getElementById(type + 'ProgressFill');
            const progressText = document.getElementById(type + 'ProgressText');
            const readProgress = document.getElementById(type + 'ReadProgress');
            const timerDiv = document.getElementById(type + 'Timer');
            const timerText = document.getElementById(type + 'TimerText');
            const agreeBtn = document.getElementById(type + 'AgreeBtn');
            
            const scrollTop = element.scrollTop;
            const scrollHeight = element.scrollHeight - element.clientHeight;
            const progress = scrollHeight > 0 ? Math.round((scrollTop / scrollHeight) * 100) : 100;
            
            progressFill.style.width = progress + '%';
            progressText.textContent = progress + '%';
            
            // 开始计时器（当滚动超过50%时）
            if (progress >= 50) {
                timerDiv.style.display = 'flex';
                
                if (type === 'terms' && !termsReadStartTime) {
                    termsReadStartTime = Date.now();
                    let remainingSeconds = 5;
                    timerText.textContent = remainingSeconds;
                    timerText.classList.add('counting');
                    
                    termsTimerInterval = setInterval(() => {
                        remainingSeconds--;
                        if (remainingSeconds > 0) {
                            timerText.textContent = remainingSeconds;
                        } else {
                            clearInterval(termsTimerInterval);
                            timerText.classList.remove('counting');
                            timerText.classList.add('completed');
                            timerText.textContent = '完成';
                            agreeBtn.disabled = false;
                        }
                    }, 1000);
                } else if (type === 'privacy' && !privacyReadStartTime) {
                    privacyReadStartTime = Date.now();
                    let remainingSeconds = 5;
                    timerText.textContent = remainingSeconds;
                    timerText.classList.add('counting');
                    
                    privacyTimerInterval = setInterval(() => {
                        remainingSeconds--;
                        if (remainingSeconds > 0) {
                            timerText.textContent = remainingSeconds;
                        } else {
                            clearInterval(privacyTimerInterval);
                            timerText.classList.remove('counting');
                            timerText.classList.add('completed');
                            timerText.textContent = '完成';
                            agreeBtn.disabled = false;
                        }
                    }, 1000);
                }
            }
            
            // 完成时更新样式
            if (progress >= 100) {
                readProgress.classList.add('completed');
            }
        }
        
        function agreeToAgreement(type) {
            if (type === 'terms') {
                termsAgreed = true;
                document.getElementById('termsAgreeBtn').textContent = '已同意';
            } else if (type === 'privacy') {
                privacyAgreed = true;
                document.getElementById('privacyAgreeBtn').textContent = '已同意';
            }
            
            updateAgreementStatus();
            closeModal(type);
        }
        
        function updateAgreementStatus() {
            const statusElement = document.getElementById('agreementStatus');
            const noticeElement = document.getElementById('agreementNotice');
            const registerBtn = document.getElementById('registerBtn');
            
            if (termsAgreed && privacyAgreed) {
                statusElement.textContent = '（已同意）';
                noticeElement.classList.add('completed');
                registerBtn.disabled = false;
                registerBtn.textContent = '创建账户';
            } else {
                let remaining = [];
                if (!termsAgreed) remaining.push('服务条款');
                if (!privacyAgreed) remaining.push('隐私政策');
                statusElement.textContent = `（需阅读：${remaining.join('、')}）`;
                noticeElement.classList.remove('completed');
                registerBtn.disabled = true;
                registerBtn.textContent = '请先阅读协议';
            }
        }
        
        // 极验验证码实例
        let geetestCaptcha = null;
        
        // 初始化极验验证码
        initGeetest4({
            captchaId: '55574dfff9c40f2efeb5a26d6d188245'
        }, function (captcha) {
            geetestCaptcha = captcha;
            captcha.appendTo("#captcha");
        });
        
        function handleRegisterSubmit(form) {
            if (!termsAgreed || !privacyAgreed) {
                alert('请先阅读并同意服务条款和隐私政策');
                return false;
            }
            
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                alert('两次输入的密码不一致');
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
            
            return true;
        }
        
        // 发送短信验证码
        const phoneInput = document.getElementById('phone');
        const sendSmsBtn = document.getElementById('send_sms_btn');
        
        if (phoneSmsEnabled && phoneInput && sendSmsBtn) {
            phoneInput.addEventListener('input', function() {
                const phone = this.value;
                if (/^1[3-9]\d{9}$/.test(phone)) {
                    sendSmsBtn.disabled = false;
                    sendSmsBtn.style.background = 'linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%)';
                    sendSmsBtn.style.cursor = 'pointer';
                } else {
                    sendSmsBtn.disabled = true;
                    sendSmsBtn.style.background = '#ccc';
                    sendSmsBtn.style.cursor = 'not-allowed';
                }
            });
            
            sendSmsBtn.addEventListener('click', function() {
                const phone = phoneInput.value;
                
                if (!/^1[3-9]\d{9}$/.test(phone)) {
                    alert('请输入正确的手机号');
                    return;
                }
                
                // 发送验证码请求
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'send_sms.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status === 200) {
                            try {
                                const data = JSON.parse(xhr.responseText);
                                if (data.success) {
                                    alert('验证码已发送，请注意查收');
                                    
                                    // 倒计时
                                    let countdown = 60;
                                    sendSmsBtn.disabled = true;
                                    sendSmsBtn.style.background = '#ccc';
                                    sendSmsBtn.style.cursor = 'not-allowed';
                                    
                                    const timer = setInterval(() => {
                                        countdown--;
                                        sendSmsBtn.textContent = countdown + '秒后重发';
                                        
                                        if (countdown <= 0) {
                                            clearInterval(timer);
                                            sendSmsBtn.textContent = '获取验证码';
                                            if (/^1[3-9]\d{9}$/.test(phoneInput.value)) {
                                                sendSmsBtn.disabled = false;
                                                sendSmsBtn.style.background = 'linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%)';
                                                sendSmsBtn.style.cursor = 'pointer';
                                            }
                                        }
                                    }, 1000);
                                } else {
                                    alert('发送失败: ' + data.message);
                                }
                            } catch (e) {
                                alert('发送失败，请稍后重试');
                            }
                        } else {
                            alert('发送失败，请稍后重试');
                        }
                    }
                };
                xhr.send('phone=' + encodeURIComponent(phone));
            });
        }
        
        // 监听滚动事件以更新进度
        document.getElementById('termsBody').addEventListener('scroll', function() {
            updateReadProgress(this, 'terms');
        });
        
        document.getElementById('privacyBody').addEventListener('scroll', function() {
            updateReadProgress(this, 'privacy');
        });
    </script>
</body>
</html>
