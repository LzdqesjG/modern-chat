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
        
        // 初始化极验验证码
        initGeetest4({
            captchaId: '55574dfff9c40f2efeb5a26d6d188245'
        }, function (captcha) {
            // captcha为验证码实例
            geetestCaptcha = captcha;
            captcha.appendTo("#captcha");// 调用appendTo将验证码插入到页的某一个元素中
        });
        
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

        // 协议预览功能 - 重构版
        const AgreementManager = {
            agreements: {
                terms: { title: '用户协议', url: 'Agreement/terms_of_service.md' },
                privacy: { title: '隐私协议', url: 'Agreement/privacy_policy.md' }
            },
            readStatus: { terms: false, privacy: false },
            currentType: null,
            animationId: null,
            duration: 20000,

            getElements() {
                return {
                    modal: document.getElementById('agreementModal'),
                    title: document.getElementById('modalTitle'),
                    body: document.getElementById('modalBody'),
                    agreeBtn: document.getElementById('agreeBtn'),
                    progressFill: document.getElementById('progressFill'),
                    progressText: document.getElementById('progressText'),
                    timerText: document.getElementById('timerText'),
                    readProgress: document.getElementById('readProgress'),
                    agreementStatus: document.getElementById('agreementStatus'),
                    registerBtn: document.getElementById('registerBtn')
                };
            },

            async show(type) {
                this.currentType = type;
                const el = this.getElements();
                const agreement = this.agreements[type];

                el.title.textContent = agreement.title;
                el.body.innerHTML = '<div style="text-align: center; padding: 40px;">加载中...</div>';
                this.resetProgress(el);

                if (this.readStatus[type]) {
                    this.setCompleted(el);
                } else {
                    el.agreeBtn.disabled = true;
                    el.agreeBtn.textContent = '请先完整阅读协议';
                }

                el.modal.classList.add('active');
                await this.loadContent(type, el);
            },

            resetProgress(el) {
                el.progressFill.style.width = '0%';
                el.progressText.textContent = '0%';
                el.timerText.textContent = '滚动中...';
                el.timerText.className = 'timer-text counting';
                el.readProgress.classList.remove('completed');
            },

            setCompleted(el) {
                el.progressFill.style.width = '100%';
                el.progressText.textContent = '100%';
                el.timerText.textContent = '完成';
                el.timerText.className = 'timer-text completed';
                el.readProgress.classList.add('completed');
                el.agreeBtn.disabled = false;
                el.agreeBtn.textContent = '已阅读并同意';
            },

            async loadContent(type, el, retries = 3) {
                for (let i = 0; i <= retries; i++) {
                    try {
                        el.body.innerHTML = '<div style="text-align: center; padding: 40px;">加载中...</div>';
                        const response = await fetch(this.agreements[type].url);
                        if (response.ok) {
                            const content = await response.text();
                            el.body.innerHTML = this.renderMarkdown(content);
                            
                            if (!this.readStatus[type]) {
                                this.startScroll(el.body, el, type);
                            } else {
                                this.setCompleted(el);
                            }
                            return;
                        }
                    } catch (e) {
                        if (i < retries) await new Promise(r => setTimeout(r, 1500));
                    }
                }
                el.body.innerHTML = '<div style="text-align: center; padding: 40px;"><p style="color: #ff4d4f;">加载失败</p><button onclick="AgreementManager.retry()" style="margin-top: 15px; padding: 8px 20px; background: #12b7f5; color: white; border: none; border-radius: 4px; cursor: pointer;">重试</button></div>';
            },

            async retry() {
                const el = this.getElements();
                await this.loadContent(this.currentType, el);
            },

            startScroll(container, el, type) {
                const maxScroll = container.scrollHeight - container.clientHeight;
                if (maxScroll <= 0) {
                    this.complete(type, el);
                    return;
                }

                const startTime = performance.now();
                const animate = (now) => {
                    const progress = Math.min((now - startTime) / this.duration, 1);
                    const eased = progress < 0.5 ? 4 * progress ** 3 : 1 - (-2 * progress + 2) ** 3 / 2;
                    
                    container.scrollTop = maxScroll * eased;
                    const percent = Math.round(eased * 100);
                    el.progressFill.style.width = percent + '%';
                    el.progressText.textContent = percent + '%';
                    
                    const remaining = Math.ceil((this.duration - (now - startTime)) / 1000);
                    if (remaining > 0) el.timerText.textContent = remaining + '秒';

                    if (progress < 1) {
                        this.animationId = requestAnimationFrame(animate);
                    } else {
                        this.complete(type, el);
                    }
                };
                this.animationId = requestAnimationFrame(animate);
            },

            complete(type, el) {
                this.readStatus[type] = true;
                this.setCompleted(el);
                this.updateMainStatus(el);
            },

            updateMainStatus(el) {
                const bothRead = this.readStatus.terms && this.readStatus.privacy;
                if (bothRead) {
                    el.agreementStatus.textContent = '（已同意）';
                    el.agreementStatus.parentElement.classList.add('completed');
                    el.registerBtn.disabled = false;
                    el.registerBtn.textContent = '注册';
                } else {
                    const remaining = [];
                    if (!this.readStatus.terms) remaining.push('用户协议');
                    if (!this.readStatus.privacy) remaining.push('隐私协议');
                    el.agreementStatus.textContent = `（还需阅读：${remaining.join('、')}）`;
                    el.agreementStatus.parentElement.classList.remove('completed');
                    el.registerBtn.disabled = true;
                    el.registerBtn.textContent = '请先同意协议';
                }
            },

            close() {
                if (this.animationId) {
                    cancelAnimationFrame(this.animationId);
                    this.animationId = null;
                }
                this.getElements().modal.classList.remove('active');
                this.currentType = null;
            },

            agree() {
                this.close();
            },

            renderMarkdown(text) {
                return text
                    .replace(/^###### (.+)$/gm, '<h6 style="font-size: 14px; color: #666; margin: 15px 0 8px; font-weight: 600;">$1</h6>')
                    .replace(/^##### (.+)$/gm, '<h5 style="font-size: 15px; color: #555; margin: 18px 0 10px; font-weight: 600;">$1</h5>')
                    .replace(/^#### (.+)$/gm, '<h4 style="font-size: 16px; color: #444; margin: 20px 0 12px; font-weight: 600;">$1</h4>')
                    .replace(/^### (.+)$/gm, '<h3 style="font-size: 18px; color: #333; margin: 22px 0 14px; font-weight: 600; border-bottom: 2px solid #e0e0e0; padding-bottom: 8px;">$1</h3>')
                    .replace(/^## (.+)$/gm, '<h2 style="font-size: 20px; color: #333; margin: 25px 0 16px; font-weight: 600; border-bottom: 2px solid #12b7f5; padding-bottom: 10px;">$1</h2>')
                    .replace(/^# (.+)$/gm, '<h1 style="font-size: 24px; color: #333; margin: 30px 0 20px; font-weight: 600; text-align: center;">$1</h1>')
                    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                    .replace(/\*(.+?)\*/g, '<em>$1</em>')
                    .replace(/~~(.+?)~~/g, '<del>$1</del>')
                    .replace(/`(.+?)`/g, '<code style="background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-family: Consolas, monospace;">$1</code>')
                    .replace(/```(\w+)?\n([\s\S]*?)```/g, '<pre style="background: #f8f9fa; padding: 16px; border-radius: 8px; overflow-x: auto; font-family: Consolas, monospace; font-size: 13px;"><code>$2</code></pre>')
                    .replace(/^(?:---|\*\*\*|___)$/gm, '<hr style="margin: 25px 0; border: none; border-top: 2px solid #e0e0e0;">')
                    .replace(/^> (.+)$/gm, '<blockquote style="border-left: 4px solid #12b7f5; padding: 12px 16px; margin: 15px 0; background: #f0f9ff; color: #555;">$1</blockquote>')
                    .replace(/^- (.+)$/gm, '<li style="margin: 6px 0;">$1</li>')
                    .replace(/(<li.*<\/li>\n?)+/g, '<ul style="margin: 15px 0; padding-left: 28px;">$&</ul>')
                    .replace(/^(\d+)\. (.+)$/gm, '<li style="margin: 6px 0;">$2</li>')
                    .replace(/\[(.+?)\]\((.+?)\)/g, '<a href="$2" target="_blank" style="color: #12b7f5;">$1</a>')
                    .replace(/!\[(.+?)\]\((.+?)\)/g, '<img src="$2" alt="$1" style="max-width: 100%; border-radius: 8px; margin: 15px 0;">')
                    .replace(/^([^<\n][\s\S]*?)$/gm, (m) => m.trim() && !m.match(/^<(h|ul|ol|li|bl|pr|ta)/) ? '<p style="margin: 12px 0; line-height: 1.8;">' + m + '</p>' : m);
            }
        };

        // 全局函数兼容
        function showModal(type) { AgreementManager.show(type); }
        function closeModal() { AgreementManager.close(); }
        function agreeAndClose() { AgreementManager.agree(); }

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