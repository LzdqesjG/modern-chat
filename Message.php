<?php
require_once 'db.php';

class Message {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // 发送文本消息
    public function sendTextMessage($sender_id, $receiver_id, $content) {
        try {
            // 检查消息内容是否包含HTML标签
            if (preg_match('/<\s*[a-zA-Z][a-zA-Z0-9-_:.]*(\s+[^>]*|$)/i', $content)) {
                // 包含HTML标签，替换为"此消息无法被显示"
                $filtered_content = "此消息无法被显示";
            } else {
                // 不包含HTML标签，直接使用
                $filtered_content = $content;
            }
            
            $stmt = $this->conn->prepare(
                "INSERT INTO messages (sender_id, receiver_id, content, type, status) 
                 VALUES (?, ?, ?, 'text', 'sent')"
            );
            $stmt->execute([$sender_id, $receiver_id, $filtered_content]);
            
            $message_id = $this->conn->lastInsertId();
            $this->updateSession($sender_id, $receiver_id, $message_id);
            $this->updateUnreadMessageCount($receiver_id, $sender_id, $message_id);
            
            return ['success' => true, 'message_id' => $message_id];
        } catch(PDOException $e) {
            error_log("Send Text Message Error: " . $e->getMessage());
            return ['success' => false, 'message' => '消息发送失败'];
        }
    }
    
    // 发送文件消息（$audio_duration 为语音消息时长，秒，可选）
    public function sendFileMessage($sender_id, $receiver_id, $file_path, $file_name, $file_size, $file_type = null, $audio_duration = 0) {
        try {
            // 确保必要的表和列存在
            $this->ensureTablesExist();
            
            $audio_duration = (int) $audio_duration;
            if ($audio_duration < 0) $audio_duration = 0;
            
            $stmt = $this->conn->prepare(
                "INSERT INTO messages (sender_id, receiver_id, file_path, file_name, file_size, file_type, audio_duration, type, status) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'file', 'sent')"
            );
            $stmt->execute([$sender_id, $receiver_id, $file_path, $file_name, $file_size, $file_type, $audio_duration]);
            
            $message_id = $this->conn->lastInsertId();
            $this->updateSession($sender_id, $receiver_id, $message_id);
            $this->updateUnreadMessageCount($receiver_id, $sender_id, $message_id);
            
            return ['success' => true, 'message_id' => $message_id, 'audio_duration' => $audio_duration];
        } catch(PDOException $e) {
            error_log("Send File Message Error: " . $e->getMessage());
            return ['success' => false, 'message' => '文件发送失败'];
        }
    }
    
    /**
     * 更新未读消息计数
     * @param int $user_id 用户ID
     * @param int $friend_id 好友ID
     * @param int $message_id 消息ID
     */
    private function updateUnreadMessageCount($user_id, $friend_id, $message_id) {
        try {
            // 确保unread_messages表存在
            $this->ensureTablesExist();
            
            // 更新未读消息计数
            $stmt = $this->conn->prepare("INSERT INTO unread_messages (user_id, chat_type, chat_id, count, last_message_id) 
                                         VALUES (?, 'friend', ?, 1, ?) 
                                         ON DUPLICATE KEY UPDATE count = count + 1, last_message_id = ?");
            $stmt->execute([$user_id, $friend_id, $message_id, $message_id]);
        } catch (PDOException $e) {
            error_log("Update unread message count error: " . $e->getMessage());
        }
    }
    
    /**
     * 确保必要的表存在
     */
    private function ensureTablesExist() {
        try {
            // 创建unread_messages表来存储未读消息计数
            $sql = "CREATE TABLE IF NOT EXISTS unread_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                chat_type ENUM('friend', 'group') NOT NULL,
                chat_id INT NOT NULL,
                count INT DEFAULT 0,
                last_message_id INT DEFAULT 0,
                UNIQUE KEY unique_chat (user_id, chat_type, chat_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
            $this->conn->exec($sql);
            
            // 确保messages表有file_type列
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM messages LIKE 'file_type'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $this->conn->exec("ALTER TABLE messages ADD COLUMN file_type VARCHAR(50) NULL");
            }
            // 确保messages表有audio_duration列（语音时长，秒）
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM messages LIKE 'audio_duration'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $this->conn->exec("ALTER TABLE messages ADD COLUMN audio_duration INT UNSIGNED DEFAULT 0");
            }
            
            // 确保group_messages表有file_type列
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM group_messages LIKE 'file_type'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $this->conn->exec("ALTER TABLE group_messages ADD COLUMN file_type VARCHAR(50) NULL");
            }
            
            // 确保sessions表有is_pinned列
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM sessions LIKE 'is_pinned'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $this->conn->exec("ALTER TABLE sessions ADD COLUMN is_pinned BOOLEAN DEFAULT FALSE");
            }
            
            // 确保sessions表有pinned_at列
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM sessions LIKE 'pinned_at'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $this->conn->exec("ALTER TABLE sessions ADD COLUMN pinned_at TIMESTAMP NULL");
            }
            
            // 确保messages表有is_deleted列
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM messages LIKE 'is_deleted'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $this->conn->exec("ALTER TABLE messages ADD COLUMN is_deleted TINYINT(1) DEFAULT 0");
            }
            
            // 确保group_messages表有is_deleted列
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM group_messages LIKE 'is_deleted'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $this->conn->exec("ALTER TABLE group_messages ADD COLUMN is_deleted TINYINT(1) DEFAULT 0");
            }
        } catch (PDOException $e) {
            error_log("Ensure tables exist error: " . $e->getMessage());
        }
    }
    
    // 获取聊天记录
    public function getChatHistory($user1_id, $user2_id, $limit = 50, $offset = 0) {
        try {
            // 确保必要的表和列存在
            $this->ensureTablesExist();
            
            $stmt = $this->conn->prepare(
                "SELECT m.*, u.username as sender_username, u.avatar 
                 FROM messages m 
                 JOIN users u ON m.sender_id = u.id
                 WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
                 AND (m.is_deleted = 0 OR m.sender_id != ?)
                 ORDER BY m.created_at DESC 
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute([$user1_id, $user2_id, $user2_id, $user1_id, $user1_id, $limit, $offset]);
            
            $messages = $stmt->fetchAll();
            
            return array_reverse($messages); // 按时间正序返回
        } catch(PDOException $e) {
            error_log("Get Chat History Error: " . $e->getMessage());
            return [];
        }
    }
    
    // 更新消息状态为已读
    public function markAsRead($message_ids) {
        try {
            if (empty($message_ids)) {
                return true;
            }
            
            $placeholders = implode(',', array_fill(0, count($message_ids), '?'));
            $stmt = $this->conn->prepare(
                "UPDATE messages SET status = 'read' WHERE id IN ($placeholders)"
            );
            $stmt->execute($message_ids);
            
            return true;
        } catch(PDOException $e) {
            error_log("Mark As Read Error: " . $e->getMessage());
            return false;
        }
    }
    
    // 获取未读消息数量
    public function getUnreadCount($user_id) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) as unread_count FROM messages 
                 WHERE receiver_id = ? AND status != 'read'"
            );
            $stmt->execute([$user_id]);
            $result = $stmt->fetch();
            return $result['unread_count'];
        } catch(PDOException $e) {
            error_log("Get Unread Count Error: " . $e->getMessage());
            return 0;
        }
    }
    
    // 更新会话信息
    private function updateSession($user_id, $friend_id, $message_id) {
        try {
            // 检查会话是否存在
            $stmt = $this->conn->prepare(
                "SELECT id FROM sessions WHERE user_id = ? AND friend_id = ?"
            );
            $stmt->execute([$user_id, $friend_id]);
            $session = $stmt->fetch();
            
            if ($session) {
                // 更新现有会话
                $stmt = $this->conn->prepare(
                    "UPDATE sessions SET last_message_id = ?, unread_count = unread_count + 1, updated_at = NOW() 
                     WHERE id = ?"
                );
                $stmt->execute([$message_id, $session['id']]);
            } else {
                // 创建新会话
                $stmt = $this->conn->prepare(
                    "INSERT INTO sessions (user_id, friend_id, last_message_id, unread_count) 
                     VALUES (?, ?, ?, 1)"
                );
                $stmt->execute([$user_id, $friend_id, $message_id]);
            }
            
            return true;
        } catch(PDOException $e) {
            error_log("Update Session Error: " . $e->getMessage());
            return false;
        }
    }
    
    // 获取用户的会话列表
    public function getSessions($user_id) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT 
                    s.id as session_id,
                    u.id as friend_id,
                    u.username,
                    u.avatar,
                    u.status,
                    m.content,
                    m.file_name,
                    m.type,
                    m.created_at as message_time,
                    s.unread_count,
                    s.is_pinned,
                    s.pinned_at,
                    s.updated_at
                 FROM sessions s
                 JOIN users u ON s.friend_id = u.id
                 LEFT JOIN messages m ON s.last_message_id = m.id
                 WHERE s.user_id = ?
                 ORDER BY s.is_pinned DESC, s.pinned_at DESC, s.updated_at DESC"
            );
            $stmt->execute([$user_id]);
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            error_log("Get Sessions Error: " . $e->getMessage());
            return [];
        }
    }
    
    // 置顶会话
    public function pinSession($session_id, $user_id) {
        try {
            $stmt = $this->conn->prepare(
                "UPDATE sessions SET is_pinned = TRUE, pinned_at = NOW() WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$session_id, $user_id]);
            return true;
        } catch(PDOException $e) {
            error_log("Pin Session Error: " . $e->getMessage());
            return false;
        }
    }
    
    // 取消置顶
    public function unpinSession($session_id, $user_id) {
        try {
            $stmt = $this->conn->prepare(
                "UPDATE sessions SET is_pinned = FALSE, pinned_at = NULL WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$session_id, $user_id]);
            return true;
        } catch(PDOException $e) {
            error_log("Unpin Session Error: " . $e->getMessage());
            return false;
        }
    }
    
    // 清除会话未读计数
    public function clearUnreadCount($session_id) {
        try {
            $stmt = $this->conn->prepare(
                "UPDATE sessions SET unread_count = 0 WHERE id = ?"
            );
            $stmt->execute([$session_id]);
            return true;
        } catch(PDOException $e) {
            error_log("Clear Unread Count Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取所有好友消息（管理员功能）
     * @return array 所有好友消息列表
     */
    public function getAllFriendMessages() {
        try {
            $stmt = $this->conn->prepare(
                "SELECT m.*, 
                        u1.username as sender_username, 
                        u2.username as receiver_username
                 FROM messages m
                 JOIN users u1 ON m.sender_id = u1.id
                 JOIN users u2 ON m.receiver_id = u2.id
                 ORDER BY m.created_at DESC
                 LIMIT 1000" // 限制1000条消息
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            error_log("Get All Friend Messages Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 删除文件辅助函数
     * @param string $file_path 文件路径
     * @return bool 是否成功删除
     */
    private function deleteFile($file_path) {
        if (!empty($file_path) && file_exists($file_path)) {
            try {
                return unlink($file_path);
            } catch (Exception $e) {
                error_log("Delete File Error: " . $e->getMessage());
                return false;
            }
        }
        return true;
    }
    
    /**
     * 撤回好友消息
     * @param int $message_id 消息ID
     * @param int $user_id 当前用户ID
     * @return array 结果
     */
    public function recallMessage($message_id, $user_id) {
        try {
            // 1. 验证消息是否存在且是当前用户发送的
            $stmt = $this->conn->prepare("SELECT * FROM messages WHERE id = ? AND sender_id = ?");
            $stmt->execute([$message_id, $user_id]);
            $message = $stmt->fetch();
            
            if (!$message) {
                return ['success' => false, 'message' => '消息不存在或您无权撤回'];
            }
            
            // 2. 验证消息是否在2分钟内
            $created_at = new DateTime($message['created_at']);
            $now = new DateTime();
            $diff = $created_at->diff($now);
            $minutes = $diff->days * 24 * 60 + $diff->h * 60 + $diff->i;
            
            if ($minutes > 2) {
                return ['success' => false, 'message' => '消息已超过2分钟，无法撤回'];
            }
            
            // 3. 保存文件路径用于后续删除
            $file_path = $message['file_path'];
            
            // 4. 删除消息
            $stmt = $this->conn->prepare("DELETE FROM messages WHERE id = ?");
            $stmt->execute([$message_id]);
            
            // 5. 删除对应的文件
            $this->deleteFile($file_path);
            
            return ['success' => true, 'message' => '消息已成功撤回'];
        } catch(PDOException $e) {
            error_log("Recall Message Error: " . $e->getMessage());
            return ['success' => false, 'message' => '撤回消息失败'];
        }
    }
}