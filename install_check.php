<?php
/**
 * 检查系统是否已安装
 * 如果存在 lock 文件，说明未安装，显示提示页面
 */
if (file_exists(__DIR__ . '/lock')) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>系统未部署 - Modern Chat</title>
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

            .container {
                background: white;
                border-radius: 20px;
                box-shadow: 0 6px 24px rgba(0, 0, 0, 0.15);
                max-width: 500px;
                width: 100%;
                padding: 40px;
                text-align: center;
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

            .icon-wrapper {
                width: 80px;
                height: 80px;
                background: #fff7e6;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 25px;
            }

            .icon {
                font-size: 40px;
                color: #faad14;
            }

            h1 {
                font-size: 24px;
                color: #333;
                margin-bottom: 15px;
                font-weight: 600;
            }

            p {
                font-size: 15px;
                color: #666;
                line-height: 1.6;
                margin-bottom: 30px;
            }

            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 12px 35px;
                background: linear-gradient(135deg, #12b7f5 0%, #00a2e8 100%);
                color: white;
                font-size: 16px;
                font-weight: 600;
                border-radius: 8px;
                text-decoration: none;
                transition: all 0.3s;
                box-shadow: 0 4px 15px rgba(18, 183, 245, 0.3);
                border: none;
                cursor: pointer;
            }

            .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(18, 183, 245, 0.4);
            }

            .btn:active {
                transform: translateY(0);
            }

            .footer-tip {
                margin-top: 25px;
                font-size: 13px;
                color: #999;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="icon-wrapper">
                <div class="icon">⚠️</div>
            </div>
            <h1>请先进行部署</h1>
            <p>检测到系统尚未完成初始化配置。<br>为了您的使用体验和数据安全，请先完成系统部署。</p>
            <a href="install.php" class="btn">
                进入部署页面 →
            </a>
            <div class="footer-tip">Modern Chat 现代化聊天系统</div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
