# Modern Chat Docker 部署指南

## 功能特性

- ✅ 使用 Docker Compose 一键部署
- ✅ 自动导入数据库结构 (db.sql)
- ✅ 支持通过环境变量修改数据库密码
- ✅ 容器化 PHP 8.2 + Nginx + MySQL 8.0 + phpMyAdmin
- ✅ 支持公网访问
- ✅ 可配置的端口映射
- ✅ 持久化数据存储

## 部署步骤

### 1. 准备工作

确保你的系统已经安装了以下软件：
- Docker
- Docker Compose

### 2. 配置环境变量

**方式一：使用默认配置**

默认配置：
- 数据库密码：`rootpassword`
- HTTP端口：80
- HTTPS端口：443
- phpMyAdmin端口：888

可以直接跳过此步骤，使用默认配置。

**方式二：自定义配置**

1. 复制 `.env.example` 文件为 `.env`：
   ```bash
   cp .env.example .env
   ```

2. 编辑 `.env` 文件，修改配置：
   ```
   # MySQL数据库密码
   DB_PASSWORD=your_secure_password
   
   # 端口配置
   HTTP_PORT=80         # HTTP端口映射
   HTTPS_PORT=443       # HTTPS端口映射
   PHPMYADMIN_PORT=888  # phpMyAdmin端口映射
   ```

### 3. 启动服务

在项目根目录下执行：

```bash
docker-compose up -d
```

这个命令会：
- 构建 PHP-FPM 应用镜像
- 启动 MySQL 数据库容器
- 自动导入 `db.sql` 到数据库
- 启动 Nginx 服务
- 启动 phpMyAdmin 服务

### 4. 访问应用

服务启动后，可以通过以下地址访问：

| 服务 | 访问地址 | 说明 |
|------|----------|------|
| 现代聊天应用 | `http://localhost:${HTTP_PORT}` | 默认：http://localhost:80 |
| phpMyAdmin | `http://localhost:${PHPMYADMIN_PORT}` | 默认：http://localhost:888 |

## 服务管理

### 查看服务状态

```bash
docker-compose ps
```

### 停止服务

```bash
docker-compose down
```

### 重启服务

```bash
docker-compose restart
```

### 查看日志

```bash
# 查看所有服务日志
docker-compose logs -f

# 查看指定服务日志
docker-compose logs -f app      # PHP-FPM
docker-compose logs -f nginx   # Nginx
docker-compose logs -f db       # MySQL
docker-compose logs -f phpmyadmin  # phpMyAdmin
```

## 项目结构

```
.
├── Dockerfile              # PHP-FPM Docker 构建文件
├── docker-compose.yml      # Docker Compose 配置文件
├── .env.example            # 环境变量示例文件
├── nginx/                  # Nginx 配置目录
│   └── nginx.conf          # Nginx 主配置文件
├── db.sql                  # 数据库初始化脚本
├── config.php              # 应用配置文件（已支持环境变量）
└── ...
```

## 关键配置说明

### 数据库连接

应用会从环境变量读取数据库配置，如果没有设置则使用默认值：
- `DB_HOST`: 数据库主机名 (默认: localhost)
- `DB_NAME`: 数据库名 (默认: chat)
- `DB_USER`: 数据库用户名 (默认: root)
- `DB_PASS`: 数据库密码 (默认: cf211396ab9363ad)

### 端口映射

| 服务 | 容器端口 | 主机端口配置 | 默认主机端口 |
|------|----------|--------------|--------------|
| Nginx HTTP | 80 | `HTTP_PORT` | 80 |
| Nginx HTTPS | 443 | `HTTPS_PORT` | 443 |
| phpMyAdmin | 80 | `PHPMYADMIN_PORT` | 888 |

### 公网访问配置

要允许公网访问，需要：

1. 确保服务器的防火墙已开放对应的端口（HTTP_PORT, HTTPS_PORT, PHPMYADMIN_PORT）
2. 如果使用云服务器，需要在安全组中开放对应的端口
3. 可以配置域名解析，将域名指向服务器IP

### HTTPS 配置

当前配置为HTTP，要启用HTTPS，需要：

1. 准备SSL证书文件（cert.pem 和 key.pem）
2. 修改 `nginx/nginx.conf`，添加HTTPS配置：
   ```nginx
   server {
       listen 443 ssl;
       server_name your_domain.com;
       
       ssl_certificate /etc/nginx/ssl/cert.pem;
       ssl_certificate_key /etc/nginx/ssl/key.pem;
       
       # 其他配置与HTTP服务器相同
       root /var/www/html;
       index index.php index.html index.htm;
       
       # ... 其他配置 ...
   }
   ```
3. 在 `docker-compose.yml` 中添加SSL证书挂载：
   ```yaml
   volumes:
       # ... 其他挂载 ...
       - ./nginx/ssl:/etc/nginx/ssl
   ```

## 常见问题

### Q: 如何修改默认端口？
A: 编辑 `.env` 文件中的端口配置，例如：
   ```
   HTTP_PORT=8080
   HTTPS_PORT=8443
   PHPMYADMIN_PORT=9999
   ```

### Q: 如何更新数据库结构？
A: 修改 `db.sql` 文件后，重新启动服务即可自动导入：
   ```bash
   docker-compose down
   docker-compose up -d
   ```

### Q: 如何使用phpMyAdmin？
A: 访问 `http://localhost:${PHPMYADMIN_PORT}`，使用以下信息登录：
   - 服务器：`db`
   - 用户名：`root`
   - 密码：你在 `.env` 文件中设置的 `DB_PASSWORD`

### Q: 服务启动失败怎么办？
A: 查看日志以获取详细错误信息：
   ```bash
   docker-compose logs -f
   ```

### Q: 如何配置域名访问？
A: 修改 `nginx/nginx.conf` 文件中的 `server_name` 配置，例如：
   ```nginx
   server {
       listen 80;
       server_name your_domain.com;
       # ... 其他配置 ...
   }
   ```

## 技术栈

- PHP 8.2-FPM
- Nginx 1.20+
- MySQL 8.0
- phpMyAdmin 5.2+
- Docker 20+
- Docker Compose 3.8+

## 许可证

MIT
