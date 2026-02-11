<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>帮助中心 - Modern Chat</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            /* 优化字体设置，使用系统默认字体栈，视觉更舒适 */
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Microsoft YaHei", "PingFang SC", "Hiragino Sans GB", sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #333;
            line-height: 1.6;
        }

        .help-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            max-width: 800px;
            width: 100%;
            overflow: hidden;
            animation: slideIn 0.5s ease;
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

        .help-header {
            background: white;
            padding: 40px 40px 20px 40px;
            border-bottom: 1px solid #f0f0f0;
        }

        .help-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: #333;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .help-icon {
            color: #12b7f5;
            font-size: 28px;
        }

        .help-body {
            padding: 40px;
        }

        .help-item {
            margin-bottom: 35px;
        }
        
        .help-item:last-child {
            margin-bottom: 0;
        }

        .help-title {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 15px;
            padding-left: 15px;
            border-left: 4px solid #12b7f5;
            line-height: 1.4;
        }

        .help-content {
            padding-left: 20px;
            color: #555;
            font-size: 15px;
        }
        
        .help-content ul, .help-content ol {
            padding-left: 20px;
            margin: 10px 0;
        }
        
        .help-content li {
            margin-bottom: 8px;
        }

        code {
            background: #f6f8fa;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
            color: #d63384;
            font-size: 0.9em;
            border: 1px solid #eee;
        }
        
        a {
            color: #12b7f5;
            text-decoration: none;
            transition: color 0.2s;
        }
        
        a:hover {
            color: #009cd6;
            text-decoration: underline;
        }

        .footer {
            padding: 20px 40px 40px 40px;
            text-align: center;
            border-top: 1px solid #f0f0f0;
            background: #fafafa;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 30px;
            background: linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(18, 183, 245, 0.3);
            border: none;
            cursor: pointer;
            font-size: 15px;
        }

        .btn-back:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(18, 183, 245, 0.4);
        }
        
        .btn-back:active {
            transform: translateY(0);
        }
    </style>
</head>
<body>
    <div class="help-container">
        <div class="help-header">
            <h1><span class="help-icon">ℹ</span> 常见问题帮助</h1>
        </div>
        <div class="help-body">
            <div class="help-item">
                <div class="help-title">1. 数据库root密码如何获取？</div>
                <div class="help-content">
                    <p>数据库root密码是您在安装MySQL或MariaDB数据库时设置的最高权限账户密码。</p>
                    <p style="margin-top: 10px; font-weight: 500;">如果您使用的是宝塔面板（BT Panel）：</p>
                    <ul>
                        <li>进入宝塔面板后台</li>
                        <li>点击左侧菜单的“数据库”</li>
                        <li>点击“root密码”按钮即可查看或修改</li>
                    </ul>
                    <p style="margin-top: 10px; font-weight: 500;">如果您使用的是本地集成环境（如phpStudy、XAMPP）：</p>
                    <ul>
                        <li>默认密码通常为 <code>root</code> 或 <code>123456</code></li>
                        <li>或者留空（即没有密码）</li>
                    </ul>
                </div>
            </div>
            
            <div class="help-item">
                <div class="help-title">2. 如何获取阿里云短信 AccessKey？</div>
                <div class="help-content">
                    <p>您可以按照以下步骤获取阿里云短信服务的 AccessKey ID 和 Secret：</p>
                    <ol>
                        <li>登录 <a href="https://www.aliyun.com/" target="_blank">阿里云控制台</a>。</li>
                        <li>将鼠标悬停在右上角的头像上，选择“AccessKey管理”。</li>
                        <li>点击“创建AccessKey”按钮。</li>
                        <li>在弹出的对话框中，您将看到 AccessKey ID 和 AccessKey Secret。<strong>请务必立即保存 Secret，因为它只显示一次。</strong></li>
                        <li>确保您的账号已开通“短信服务”并拥有相应的权限（AliyunDysmsFullAccess）。</li>
                    </ol>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <a href="javascript:window.close();" class="btn-back">关闭页面</a>
        </div>
    </div>
</body>
</html>
