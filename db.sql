-- 创建数据库
CREATE DATABASE IF NOT EXISTS chat DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE chat;

-- 用户表
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(255) DEFAULT 'default_avatar.png',
    status ENUM('online', 'offline', 'away') DEFAULT 'offline',
    is_admin BOOLEAN DEFAULT FALSE,
    is_deleted BOOLEAN DEFAULT FALSE,
    has_security_question BOOLEAN DEFAULT FALSE,
    security_question VARCHAR(255) DEFAULT NULL,
    security_answer VARCHAR(255) DEFAULT NULL,
    last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 好友表
CREATE TABLE IF NOT EXISTS friends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    friend_id INT NOT NULL,
    status ENUM('pending', 'accepted') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_friendship (user_id, friend_id)
);

-- 消息表
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    content TEXT,
    file_path VARCHAR(255) NULL,
    file_name VARCHAR(255) NULL,
    file_size INT NULL,
    file_type VARCHAR(50) NULL,
    type ENUM('text', 'file') DEFAULT 'text',
    status ENUM('sent', 'delivered', 'read') DEFAULT 'sent',
    is_encrypted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 文件表（用于存储文件元数据）
CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    upload_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 会话表（用于存储聊天会话信息）
CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    friend_id INT NOT NULL,
    last_message_id INT NULL,
    unread_count INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (last_message_id) REFERENCES messages(id) ON DELETE SET NULL,
    UNIQUE KEY unique_session (user_id, friend_id)
);

-- 扫码登录表
CREATE TABLE IF NOT EXISTS scan_login (
    id INT AUTO_INCREMENT PRIMARY KEY,
    qid VARCHAR(50) NOT NULL UNIQUE,
    token VARCHAR(100) NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expire_at TIMESTAMP NOT NULL,
    token_expire_at TIMESTAMP NULL,
    status ENUM('pending', 'success', 'expired', 'used') DEFAULT 'pending',
    user_id INT NULL,
    ip_address VARCHAR(50) NOT NULL,
    login_source VARCHAR(50) NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 群聊表
CREATE TABLE IF NOT EXISTS `groups` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    creator_id INT NOT NULL,
    owner_id INT NOT NULL,
    all_user_group INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 群成员表
CREATE TABLE IF NOT EXISTS group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('admin', 'member') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_group_member (group_id, user_id)
);

-- 更新全员群聊名称为世界大厅
UPDATE `groups` SET name = REPLACE(name, '全员群聊', '世界大厅') WHERE all_user_group > 0;

-- 群聊消息表
CREATE TABLE IF NOT EXISTS group_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    sender_id INT NOT NULL,
    content TEXT,
    file_path VARCHAR(255) NULL,
    file_name VARCHAR(255) NULL,
    file_size INT NULL,
    file_type VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 忘记密码申请表
CREATE TABLE IF NOT EXISTS forget_password_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) NOT NULL,
    new_password VARCHAR(255) NOT NULL, -- 加密后的新密码
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_at TIMESTAMP NULL,
    admin_id INT DEFAULT NULL,
    FOREIGN KEY (admin_id) REFERENCES users(id)
);

-- 反馈表
CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'received', 'fixed') DEFAULT 'pending',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建索引以提高查询性能
CREATE INDEX idx_groups_all_user_group ON `groups`(all_user_group);

-- 创建封禁表
CREATE TABLE IF NOT EXISTS bans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    banned_by INT NOT NULL,
    reason TEXT NOT NULL,
    ban_duration INT NOT NULL, -- 封禁时长（秒）
    ban_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ban_end TIMESTAMP NULL,
    status ENUM('active', 'expired', 'lifted') DEFAULT 'active',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (banned_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_active_ban (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建封禁日志表
CREATE TABLE IF NOT EXISTS ban_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ban_id INT NOT NULL,
    action ENUM('ban', 'lift', 'expire') NOT NULL,
    action_by INT NULL,
    action_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ban_id) REFERENCES bans(id) ON DELETE CASCADE,
    FOREIGN KEY (action_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建群聊邀请表
CREATE TABLE IF NOT EXISTS group_invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    inviter_id INT NOT NULL,
    invitee_id INT NOT NULL,
    status ENUM('pending', 'accepted', 'rejected', 'expired') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (inviter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (invitee_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_invitation (group_id, inviter_id, invitee_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建未读消息计数表
CREATE TABLE IF NOT EXISTS unread_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chat_type ENUM('friend', 'group') NOT NULL,
    chat_id INT NOT NULL,
    count INT DEFAULT 0,
    last_message_id INT DEFAULT 0,
    UNIQUE KEY unique_chat (user_id, chat_type, chat_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建违禁词封禁表
CREATE TABLE IF NOT EXISTS prohibited_word_bans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ban_reason TEXT NOT NULL,
    ban_type ENUM('temporary', 'permanent') DEFAULT 'temporary',
    ban_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ban_end TIMESTAMP NULL,
    warnings_count INT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建违禁词警告表
CREATE TABLE IF NOT EXISTS prohibited_word_warnings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    prohibited_word VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建聊天设置表
CREATE TABLE IF NOT EXISTS chat_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chat_type ENUM('friend', 'group') NOT NULL,
    chat_id INT NOT NULL,
    is_muted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_chat (user_id, chat_type, chat_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建入群申请表
CREATE TABLE IF NOT EXISTS group_join_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_join_request (group_id, user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建IP注册记录表
CREATE TABLE IF NOT EXISTS ip_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 修改groups表，添加is_muted字段
ALTER TABLE `groups` ADD COLUMN is_muted TINYINT(1) DEFAULT 0 AFTER all_user_group;

-- 创建群聊封禁表
CREATE TABLE IF NOT EXISTS group_bans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    banned_by INT NOT NULL,
    reason TEXT NOT NULL,
    ban_duration INT NOT NULL, -- 封禁时长（秒），0表示永久
    ban_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ban_end TIMESTAMP NULL,
    status ENUM('active', 'expired', 'lifted') DEFAULT 'active',
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (banned_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_active_group_ban (group_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建群聊封禁日志表
CREATE TABLE IF NOT EXISTS group_ban_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ban_id INT NOT NULL,
    action ENUM('ban', 'lift', 'expire') NOT NULL,
    action_by INT NULL,
    action_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ban_id) REFERENCES group_bans(id) ON DELETE CASCADE,
    FOREIGN KEY (action_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建@提醒表
CREATE TABLE IF NOT EXISTS mentions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    message_type ENUM('friend', 'group') NOT NULL,
    mentioned_user_id INT NOT NULL,
    sender_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建索引以提高查询性能
CREATE INDEX idx_group_bans_group_id ON group_bans(group_id);
CREATE INDEX idx_group_bans_status ON group_bans(status);
CREATE INDEX idx_group_bans_ban_end ON group_bans(ban_end);
CREATE INDEX idx_group_ban_logs_ban_id ON group_ban_logs(ban_id);
CREATE INDEX idx_group_ban_logs_action ON group_ban_logs(action);

-- 创建加密密钥表
CREATE TABLE IF NOT EXISTS encryption_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    public_key TEXT NOT NULL,
    private_key TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建IP登录尝试表
CREATE TABLE IF NOT EXISTS ip_login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_successful BOOLEAN DEFAULT FALSE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建IP封禁表
CREATE TABLE IF NOT EXISTS ip_bans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    ban_reason VARCHAR(255) NOT NULL DEFAULT '多次登录失败',
    ban_duration INT NOT NULL, -- 封禁时长（秒）
    ban_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ban_end TIMESTAMP NULL,
    status ENUM('active', 'expired') DEFAULT 'active',
    last_ban_id INT DEFAULT NULL,
    UNIQUE KEY unique_active_ban (ip_address, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建浏览器指纹表
CREATE TABLE IF NOT EXISTS browser_fingerprints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fingerprint VARCHAR(64) NOT NULL, -- 浏览器指纹哈希值
    ip_address VARCHAR(45) NOT NULL, -- 关联的IP地址
    user_agent TEXT NOT NULL, -- 用户代理信息
    screen_resolution VARCHAR(20) DEFAULT NULL, -- 屏幕分辨率
    time_zone VARCHAR(100) DEFAULT NULL, -- 时区信息
    language VARCHAR(50) DEFAULT NULL, -- 浏览器语言
    plugins_count INT DEFAULT NULL, -- 插件数量
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fingerprint (fingerprint),
    INDEX idx_ip_address (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建浏览器封禁表
CREATE TABLE IF NOT EXISTS browser_bans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fingerprint VARCHAR(64) NOT NULL, -- 浏览器指纹哈希值
    ban_reason VARCHAR(255) NOT NULL DEFAULT '多次登录失败',
    ban_duration INT NOT NULL, -- 封禁时长（秒）
    ban_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ban_end TIMESTAMP NULL,
    status ENUM('active', 'expired') DEFAULT 'active',
    last_ban_id INT DEFAULT NULL,
    UNIQUE KEY unique_active_browser_ban (fingerprint, status),
    INDEX idx_fingerprint_status (fingerprint, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建索引以提高查询性能
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_messages_sender_receiver ON messages(sender_id, receiver_id);
CREATE INDEX idx_messages_created_at ON messages(created_at);
CREATE INDEX idx_friends_user_id_status ON friends(user_id, status);
CREATE INDEX idx_sessions_user_id_updated_at ON sessions(user_id, updated_at);
CREATE INDEX idx_scan_login_qid ON scan_login(qid);
CREATE INDEX idx_scan_login_token ON scan_login(token);
CREATE INDEX idx_scan_login_status ON scan_login(status);
CREATE INDEX idx_scan_login_expire_at ON scan_login(expire_at);
CREATE INDEX idx_scan_login_token_expire_at ON scan_login(token_expire_at);
CREATE INDEX idx_forget_password_username ON forget_password_requests(username);
CREATE INDEX idx_forget_password_status ON forget_password_requests(status);
CREATE INDEX idx_forget_password_created_at ON forget_password_requests(created_at);
CREATE INDEX idx_groups_creator_id ON `groups`(creator_id);
CREATE INDEX idx_groups_owner_id ON `groups`(owner_id);
CREATE INDEX idx_group_members_group_id ON group_members(group_id);
CREATE INDEX idx_group_members_user_id ON group_members(user_id);
CREATE INDEX idx_group_messages_group_id ON group_messages(group_id);
CREATE INDEX idx_group_messages_sender_id ON group_messages(sender_id);
CREATE INDEX idx_group_messages_created_at ON group_messages(created_at);
CREATE INDEX idx_feedback_user_id ON feedback(user_id);
CREATE INDEX idx_feedback_status ON feedback(status);
CREATE INDEX idx_feedback_created_at ON feedback(created_at);
CREATE INDEX idx_bans_user_id ON bans(user_id);
CREATE INDEX idx_bans_status ON bans(status);
CREATE INDEX idx_bans_ban_end ON bans(ban_end);
CREATE INDEX idx_ban_logs_ban_id ON ban_logs(ban_id);
CREATE INDEX idx_ban_logs_action ON ban_logs(action);
CREATE INDEX idx_ip_registrations_ip_address ON ip_registrations(ip_address);
CREATE INDEX idx_ip_registrations_user_id ON ip_registrations(user_id);
CREATE INDEX idx_ip_registrations_registered_at ON ip_registrations(registered_at);
CREATE INDEX idx_encryption_keys_user_id ON encryption_keys(user_id);
CREATE INDEX idx_ip_login_attempts_ip_address ON ip_login_attempts(ip_address);
CREATE INDEX idx_ip_login_attempts_attempt_time ON ip_login_attempts(attempt_time);
CREATE INDEX idx_ip_bans_ip_address ON ip_bans(ip_address);
CREATE INDEX idx_ip_bans_ban_end ON ip_bans(ban_end);
CREATE INDEX idx_ip_bans_status ON ip_bans(status);

-- 创建聊天设置表
CREATE TABLE IF NOT EXISTS chat_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chat_type ENUM('friend', 'group') NOT NULL,
    chat_id INT NOT NULL,
    is_muted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_chat_setting (user_id, chat_type, chat_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建聊天设置表索引
CREATE INDEX idx_chat_settings_user_id ON chat_settings(user_id);
CREATE INDEX idx_chat_settings_chat_type ON chat_settings(chat_type);
CREATE INDEX idx_chat_settings_chat_id ON chat_settings(chat_id);

-- 公告表
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    admin_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 用户公告已读表
CREATE TABLE IF NOT EXISTS user_announcement_read (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    announcement_id INT NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_announcement (user_id, announcement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建公告表索引
CREATE INDEX idx_announcements_is_active ON announcements(is_active);
CREATE INDEX idx_announcements_created_at ON announcements(created_at);
CREATE INDEX idx_user_announcement_read_user_id ON user_announcement_read(user_id);
CREATE INDEX idx_user_announcement_read_announcement_id ON user_announcement_read(announcement_id);
