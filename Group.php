<?php
require_once 'db.php';
require_once 'User.php';

class Group {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * 创建群聊
     * @param int $creator_id 创建者ID
     * @param string $name 群聊名称
     * @param array $member_ids 初始成员ID数组
     * @return int|false 群聊ID或false
     */
    public function createGroup($creator_id, $name, $member_ids = []) {
        // 验证成员数量（包括创建者）不超过2000
        $total_members = count($member_ids) + 1; // +1 是创建者
        if ($total_members > 2000) {
            return false;
        }
        
        try {
            $this->conn->beginTransaction();
            
            // 创建群聊
            $stmt = $this->conn->prepare("INSERT INTO groups (name, creator_id, owner_id) VALUES (?, ?, ?)");
            $stmt->execute([$name, $creator_id, $creator_id]);
            $group_id = $this->conn->lastInsertId();
            
            // 添加创建者为成员和群主
            $stmt = $this->conn->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)");
            $stmt->execute([$group_id, $creator_id, 'admin']);
            
            // 添加其他成员
            foreach ($member_ids as $member_id) {
                if ($member_id != $creator_id) {
                    $stmt = $this->conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
                    $stmt->execute([$group_id, $member_id]);
                }
            }
            
            $this->conn->commit();
            return $group_id;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Create group error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取群聊信息
     * @param int $group_id 群聊ID
     * @return array|false 群聊信息或false
     */
    public function getGroupInfo($group_id) {
        $stmt = $this->conn->prepare("SELECT * FROM groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $group_info = $stmt->fetch();
        
        if ($group_info) {
            // 计算成员数量
            if ($group_info['all_user_group'] == 1) {
                // 全员群聊，成员数量为所有用户的数量
                $stmt = $this->conn->prepare("SELECT COUNT(*) as total_users FROM users");
                $stmt->execute();
                $total_users = $stmt->fetch()['total_users'];
                $group_info['member_count'] = $total_users;
            } else {
                // 普通群聊，成员数量为group_members表中的记录数
                $stmt = $this->conn->prepare("SELECT COUNT(*) as member_count FROM group_members WHERE group_id = ?");
                $stmt->execute([$group_id]);
                $group_info['member_count'] = $stmt->fetch()['member_count'];
            }
        }
        
        return $group_info;
    }
    
    /**
     * 获取群聊成员列表
     * @param int $group_id 群聊ID
     * @return array 成员列表
     */
    public function getGroupMembers($group_id) {
        // 检查是否是全员群聊
        $stmt = $this->conn->prepare("SELECT all_user_group, owner_id FROM groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $group_info = $stmt->fetch();
        
        // 获取群聊的owner_id
        $owner_id = $group_info['owner_id'];
        
        // 兼容两种表结构
        $stmt = $this->conn->prepare("SHOW COLUMNS FROM group_members LIKE 'is_admin'");
        $stmt->execute();
        $is_admin_exists = $stmt->fetch();
        
        if ($group_info && $group_info['all_user_group'] == 1) {
            // 全员群聊，返回所有用户，并正确标记管理员
            if ($is_admin_exists) {
                // 表结构中有is_admin字段
                $stmt = $this->conn->prepare("SELECT u.*, 
                                                CASE 
                                                    WHEN u.id = ? THEN 1
                                                    WHEN gm.is_admin = 1 THEN 1
                                                    ELSE 0
                                                END as is_admin 
                                             FROM users u 
                                             LEFT JOIN group_members gm ON u.id = gm.user_id AND gm.group_id = ?");
            } else {
                // 表结构中有role字段
                $stmt = $this->conn->prepare("SELECT u.*, 
                                                CASE 
                                                    WHEN u.id = ? THEN 1
                                                    WHEN gm.role = 'admin' THEN 1
                                                    ELSE 0
                                                END as is_admin 
                                             FROM users u 
                                             LEFT JOIN group_members gm ON u.id = gm.user_id AND gm.group_id = ?");
            }
            $stmt->execute([$owner_id, $group_id]);
            return $stmt->fetchAll();
        } else {
            // 普通群聊，返回group_members表中的成员
            if ($is_admin_exists) {
                $stmt = $this->conn->prepare("SELECT u.*, gm.is_admin FROM users u 
                                             JOIN group_members gm ON u.id = gm.user_id 
                                             WHERE gm.group_id = ?");
            } else {
                $stmt = $this->conn->prepare("SELECT u.*, (gm.role = 'admin') as is_admin FROM users u 
                                             JOIN group_members gm ON u.id = gm.user_id 
                                             WHERE gm.group_id = ?");
            }
            $stmt->execute([$group_id]);
            return $stmt->fetchAll();
        }
    }
    
    /**
     * 添加群聊成员
     * @param int $group_id 群聊ID
     * @param array $user_ids 用户ID数组
     * @return bool 是否成功
     */
    public function addGroupMembers($group_id, $user_ids) {
        // 检查当前成员数量
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM group_members WHERE group_id = ?");
        $stmt->execute([$group_id]);
        $current_count = $stmt->fetch()['count'];
        
        // 验证总成员数不超过2000
        if ($current_count + count($user_ids) > 2000) {
            return false;
        }
        
        try {
            $this->conn->beginTransaction();
            
            $stmt = $this->conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
            foreach ($user_ids as $user_id) {
                $stmt->execute([$group_id, $user_id]);
            }
            
            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Add group members error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 设置管理员
     * @param int $group_id 群聊ID
     * @param int $user_id 用户ID
     * @param bool $is_admin 是否为管理员
     * @return bool 是否成功
     */
    public function setAdmin($group_id, $user_id, $is_admin = true) {
        // 兼容两种表结构
        $stmt = $this->conn->prepare("SHOW COLUMNS FROM group_members LIKE 'is_admin'");
        $stmt->execute();
        $is_admin_exists = $stmt->fetch();
        
        // 检查管理员数量
        if ($is_admin) {
            if ($is_admin_exists) {
                $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM group_members WHERE group_id = ? AND is_admin = 1");
                $stmt->execute([$group_id]);
            } else {
                $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM group_members WHERE group_id = ? AND role = 'admin'");
                $stmt->execute([$group_id]);
            }
            $admin_count = $stmt->fetch()['count'];
            
            // 管理员数量不能超过9个（不包括群主）
            if ($admin_count >= 9) {
                return false;
            }
        }
        
        if ($is_admin_exists) {
            $stmt = $this->conn->prepare("UPDATE group_members SET is_admin = ? WHERE group_id = ? AND user_id = ?");
            return $stmt->execute([$is_admin, $group_id, $user_id]);
        } else {
            $role = $is_admin ? 'admin' : 'member';
            $stmt = $this->conn->prepare("UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?");
            return $stmt->execute([$role, $group_id, $user_id]);
        }
    }
    
    /**
     * 转让群主
     * @param int $group_id 群聊ID
     * @param int $current_owner_id 当前群主ID
     * @param int $new_owner_id 新群主ID
     * @return bool 是否成功
     */
    public function transferOwnership($group_id, $current_owner_id, $new_owner_id) {
        // 验证当前用户是群主
        $stmt = $this->conn->prepare("SELECT owner_id FROM groups WHERE id = ? AND owner_id = ?");
        $stmt->execute([$group_id, $current_owner_id]);
        if (!$stmt->fetch()) {
            return false;
        }
        
        // 验证新群主是群成员
        $stmt = $this->conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$group_id, $new_owner_id]);
        if (!$stmt->fetch()) {
            return false;
        }
        
        try {
            $this->conn->beginTransaction();
            
            // 更新群主
            $stmt = $this->conn->prepare("UPDATE groups SET owner_id = ? WHERE id = ?");
            $stmt->execute([$new_owner_id, $group_id]);
            
            // 设置新群主为管理员
            $stmt = $this->conn->prepare("UPDATE group_members SET is_admin = 1 WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$group_id, $new_owner_id]);
            
            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Transfer ownership error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 发送群聊消息
     * @param int $group_id 群聊ID
     * @param int $sender_id 发送者ID
     * @param string $content 消息内容
     * @param array $file_info 文件信息（可选）
     * @return array 包含success和message_id的关联数组
     */
    public function sendGroupMessage($group_id, $sender_id, $content, $file_info = []) {
        // 确保必要的表和列存在
        $this->ensureTablesExist();
        
        // 验证发送者是否可以发送消息
        $is_member = false;
        
        // 检查是否是全员群聊
        $stmt = $this->conn->prepare("SELECT all_user_group FROM groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $group_info = $stmt->fetch();
        
        if ($group_info && $group_info['all_user_group'] == 1) {
            // 全员群聊，所有用户都可以发送消息
            $is_member = true;
        } else {
            // 普通群聊，检查用户是否是群成员
            $stmt = $this->conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$group_id, $sender_id]);
            $is_member = $stmt->fetch() !== false;
        }
        
        if (!$is_member) {
            return ['success' => false, 'message' => '发送者不是群成员'];
        }
        
        // 检查消息内容是否包含HTML标签
        if (preg_match('/<\s*[a-zA-Z][a-zA-Z0-9-_:.]*(\s+[^>]*|$)/i', $content)) {
            // 包含HTML标签，替换为"此消息无法被显示"
            $content = "此消息无法被显示";
        }
        
        $file_path = isset($file_info['file_path']) ? $file_info['file_path'] : null;
        $file_name = isset($file_info['file_name']) ? $file_info['file_name'] : null;
        $file_size = isset($file_info['file_size']) ? $file_info['file_size'] : null;
        $file_type = isset($file_info['file_type']) ? $file_info['file_type'] : null;
        
        try {
            if ($file_path) {
                // 发送文件消息，包含file_type字段
                $stmt = $this->conn->prepare("INSERT INTO group_messages (group_id, sender_id, content, file_path, file_name, file_size, file_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $result = $stmt->execute([$group_id, $sender_id, $content, $file_path, $file_name, $file_size, $file_type]);
            } else {
                // 发送文本消息，只使用数据库中实际存在的字段
                $stmt = $this->conn->prepare("INSERT INTO group_messages (group_id, sender_id, content) VALUES (?, ?, ?)");
                $result = $stmt->execute([$group_id, $sender_id, $content]);
            }
            
            if ($result) {
                $message_id = $this->conn->lastInsertId();
                
                // 处理@提醒
                if (!empty($content) && $content !== "此消息无法被显示") {
                    $mentioned_user_ids = [];
                    
                    // 检查是否@所有人
                    if (stripos($content, '@所有人') !== false || stripos($content, '@全体成员') !== false) {
                        // 获取所有用户（排除发送者自己）
                        $stmt = $this->conn->prepare("SELECT id FROM users WHERE id != ?");
                        $stmt->execute([$sender_id]);
                        $users = $stmt->fetchAll();
                        
                        foreach ($users as $user) {
                            $mentioned_user_ids[] = $user['id'];
                        }
                    } else {
                        // 查找@用户名格式
                        preg_match_all('/@([^\s]+)/', $content, $matches);
                        if (!empty($matches[1])) {
                            // 根据用户名获取用户ID
                            $usernames = $matches[1];
                            $placeholders = rtrim(str_repeat('?,', count($usernames)), ',');
                            $stmt = $this->conn->prepare("SELECT id FROM users WHERE username IN ($placeholders)");
                            $stmt->execute($usernames);
                            $users = $stmt->fetchAll();
                            
                            foreach ($users as $user) {
                                if ($user['id'] != $sender_id) {
                                    $mentioned_user_ids[] = $user['id'];
                                }
                            }
                        }
                    }
                    
                    // 去重
                    $mentioned_user_ids = array_unique($mentioned_user_ids);
                    
                    // 插入@提醒
                    if (!empty($mentioned_user_ids)) {
                        // 确保mentions表存在
                        $this->ensureTablesExist();
                        
                        $stmt = $this->conn->prepare("INSERT INTO mentions (message_id, message_type, mentioned_user_id, sender_id) VALUES (?, 'group', ?, ?)");
                        foreach ($mentioned_user_ids as $user_id) {
                            $stmt->execute([$message_id, $user_id, $sender_id]);
                        }
                    }
                }
                
                // 更新未读消息计数
                $this->updateUnreadMessageCount($group_id, $sender_id, $message_id);
                
                return ['success' => true, 'message_id' => $message_id];
            }
        } catch (PDOException $e) {
            error_log("Send group message error: " . $e->getMessage());
        }
        
        return ['success' => false, 'message' => '发送消息失败'];
    }
    
    /**
     * 确保必要的表存在
     */
    private function ensureTablesExist() {
        try {
            // 创建mentions表来存储@提醒
            $sql = "CREATE TABLE IF NOT EXISTS mentions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                message_type ENUM('friend', 'group') NOT NULL,
                mentioned_user_id INT NOT NULL,
                sender_id INT NOT NULL,
                is_read BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
            )";
            $this->conn->exec($sql);
            
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
            
            // 确保group_messages表有file_type列
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM group_messages LIKE 'file_type'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $this->conn->exec("ALTER TABLE group_messages ADD COLUMN file_type VARCHAR(50) NULL");
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
    
    /**
     * 更新未读消息计数
     * @param int $group_id 群聊ID
     * @param int $sender_id 发送者ID
     * @param int $message_id 消息ID
     */
    private function updateUnreadMessageCount($group_id, $sender_id, $message_id) {
        try {
            // 获取所有群成员（排除发送者自己）
            $stmt = $this->conn->prepare("SELECT user_id FROM group_members WHERE group_id = ? AND user_id != ?");
            $stmt->execute([$group_id, $sender_id]);
            $members = $stmt->fetchAll();
            
            foreach ($members as $member) {
                $user_id = $member['user_id'];
                
                // 更新未读消息计数
                $stmt = $this->conn->prepare("INSERT INTO unread_messages (user_id, chat_type, chat_id, count, last_message_id) 
                                             VALUES (?, 'group', ?, 1, ?) 
                                             ON DUPLICATE KEY UPDATE count = count + 1, last_message_id = ?");
                $stmt->execute([$user_id, $group_id, $message_id, $message_id]);
            }
        } catch (PDOException $e) {
            error_log("Update unread message count error: " . $e->getMessage());
        }
    }
    
    /**
     * 获取群聊消息
     * @param int $group_id 群聊ID
     * @param int $user_id 用户ID（验证权限）
     * @param int $last_message_id 最后一条消息ID（用于分页）
     * @param int $limit 消息数量限制
     * @return array 消息列表
     */
    public function getGroupMessages($group_id, $user_id, $last_message_id = 0, $limit = 50) {
        // 确保必要的表和列存在
        $this->ensureTablesExist();
        
        // 验证用户是否可以访问该群聊
        $is_member = false;
        
        // 检查是否是全员群聊
        $stmt = $this->conn->prepare("SELECT all_user_group FROM groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $group_info = $stmt->fetch();
        
        if ($group_info && $group_info['all_user_group'] == 1) {
            // 全员群聊，所有用户都可以访问
            $is_member = true;
        } else {
            // 普通群聊，检查用户是否是群成员
            $stmt = $this->conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$group_id, $user_id]);
            $is_member = $stmt->fetch() !== false;
        }
        
        if (!$is_member) {
            return [];
        }
        
        // 对于获取新消息，使用id直接比较，确保能获取到所有比last_message_id大的消息
        if ($last_message_id > 0) {
            // 使用id直接比较，确保能获取到所有比last_message_id大的消息
            $stmt = $this->conn->prepare("SELECT gm.id, gm.group_id, gm.sender_id, gm.content, gm.file_path, gm.file_name, gm.file_size, gm.created_at, 
                                         u.username as sender_username, u.avatar 
                                         FROM group_messages gm 
                                         JOIN users u ON gm.sender_id = u.id 
                                         WHERE gm.group_id = ? AND gm.id > ? 
                                         AND (gm.is_deleted = 0 OR gm.sender_id != ?)
                                         ORDER BY gm.created_at ASC 
                                         LIMIT ?");
            $stmt->execute([$group_id, $last_message_id, $user_id, $limit]);
            $messages = $stmt->fetchAll();
        } else {
            // 如果没有last_message_id，返回最新的消息
            $stmt = $this->conn->prepare("SELECT gm.id, gm.group_id, gm.sender_id, gm.content, gm.file_path, gm.file_name, gm.file_size, gm.created_at, 
                                         u.username as sender_username, u.avatar 
                                         FROM group_messages gm 
                                         JOIN users u ON gm.sender_id = u.id 
                                         WHERE gm.group_id = ? 
                                         AND (gm.is_deleted = 0 OR gm.sender_id != ?)
                                         ORDER BY gm.created_at ASC 
                                         LIMIT ?");
            $stmt->execute([$group_id, $user_id, $limit]);
            $messages = $stmt->fetchAll();
        }
        
        return $messages;
    }
    
    /**
     * 撤回群聊消息
     * @param int $message_id 消息ID
     * @param int $user_id 操作用户ID
     * @return array 结果
     */
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
    
    public function recallGroupMessage($message_id, $user_id) {
        try {
            // 获取消息信息
            $stmt = $this->conn->prepare("SELECT gm.*, g.owner_id FROM group_messages gm 
                                         JOIN groups g ON gm.group_id = g.id 
                                         WHERE gm.id = ?");
            $stmt->execute([$message_id]);
            $message = $stmt->fetch();
            
            if (!$message) {
                return ['success' => false, 'message' => '消息不存在'];
            }
            
            // 检查消息是否在2分钟内
            $message_time = strtotime($message['created_at']);
            $current_time = time();
            if (($current_time - $message_time) > 120) { // 120秒 = 2分钟
                return ['success' => false, 'message' => '消息已超过2分钟，无法撤回'];
            }
            
            // 检查操作权限：消息发送者、群主或管理员
            if ($user_id == $message['sender_id']) {
                // 发送者可以撤回自己的消息
                $can_remove = true;
            } else {
                // 兼容两种表结构
                $stmt = $this->conn->prepare("SHOW COLUMNS FROM group_members LIKE 'is_admin'");
                $stmt->execute();
                $is_admin_exists = $stmt->fetch();
                
                // 检查是否是管理员或群主
                if ($is_admin_exists) {
                    $stmt = $this->conn->prepare("SELECT is_admin FROM group_members WHERE group_id = ? AND user_id = ?");
                    $stmt->execute([$message['group_id'], $user_id]);
                    $member = $stmt->fetch();
                    $can_remove = $user_id == $message['owner_id'] || ($member && $member['is_admin'] == 1);
                } else {
                    $stmt = $this->conn->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
                    $stmt->execute([$message['group_id'], $user_id]);
                    $member = $stmt->fetch();
                    $can_remove = $user_id == $message['owner_id'] || ($member && $member['role'] == 'admin');
                }
            }
            
            if ($can_remove) {
                // 保存文件路径用于后续删除
                $file_path = $message['file_path'];
                
                $stmt = $this->conn->prepare("DELETE FROM group_messages WHERE id = ?");
                if ($stmt->execute([$message_id])) {
                    // 删除对应的文件
                    $this->deleteFile($file_path);
                    return ['success' => true, 'message' => '消息已成功撤回'];
                } else {
                    return ['success' => false, 'message' => '撤回消息失败'];
                }
            } else {
                return ['success' => false, 'message' => '您无权撤回此消息'];
            }
        } catch (PDOException $e) {
            error_log("Recall Group Message Error: " . $e->getMessage());
            return ['success' => false, 'message' => '撤回消息失败'];
        }
    }
    
    /**
     * 退出群聊
     * @param int $group_id 群聊ID
     * @param int $user_id 用户ID
     * @return bool 是否成功
     */
    public function leaveGroup($group_id, $user_id) {
        // 验证用户是群成员
        $stmt = $this->conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
        $stmt->execute([$group_id, $user_id]);
        if (!$stmt->fetch()) {
            return false;
        }
        
        // 群主不能直接退出，必须先转让群主
        $stmt = $this->conn->prepare("SELECT owner_id FROM groups WHERE id = ? AND owner_id = ?");
        $stmt->execute([$group_id, $user_id]);
        if ($stmt->fetch()) {
            return false;
        }
        
        // 检查是否是全员群聊，如果是则禁止退出
        $stmt = $this->conn->prepare("SELECT all_user_group FROM groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $group = $stmt->fetch();
        if ($group && $group['all_user_group'] > 0) {
            return false;
        }
        
        $stmt = $this->conn->prepare("DELETE FROM group_members WHERE group_id = ? AND user_id = ?");
        return $stmt->execute([$group_id, $user_id]);
    }
    

    
    /**
     * 获取用户加入的群聊列表
     * @param int $user_id 用户ID
     * @return array 群聊列表
     */
    public function getUserGroups($user_id) {
        // 获取所有群聊，包括全员群聊
        $stmt = $this->conn->prepare("SELECT g.* FROM groups g");
        $stmt->execute();
        $all_groups = $stmt->fetchAll();
        
        // 获取用户的群聊成员身份
        // 兼容两种表结构
        $stmt = $this->conn->prepare("SHOW COLUMNS FROM group_members LIKE 'is_admin'");
        $stmt->execute();
        $is_admin_exists = $stmt->fetch();
        
        if ($is_admin_exists) {
            $stmt = $this->conn->prepare("SELECT gm.group_id, gm.is_admin FROM group_members gm WHERE gm.user_id = ?");
        } else {
            $stmt = $this->conn->prepare("SELECT gm.group_id, (gm.role = 'admin') as is_admin FROM group_members gm WHERE gm.user_id = ?");
        }
        $stmt->execute([$user_id]);
        $user_groups = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        
        $groups = [];
        foreach ($all_groups as $group) {
            // 检查用户是否可以访问该群聊
            $is_member = false;
            
            if ($group['all_user_group'] == 1) {
                // 全员群聊，所有用户都可以访问
                $is_member = true;
            } else {
                // 普通群聊，检查用户是否是群成员
                $is_member = isset($user_groups[$group['id']]);
            }
            
            if ($is_member) {
                // 获取成员数量
                if ($group['all_user_group'] == 1) {
                    // 全员群聊，成员数量为所有用户的数量
                    $stmt = $this->conn->prepare("SELECT COUNT(*) as total_users FROM users");
                    $stmt->execute();
                    $total_users = $stmt->fetch()['total_users'];
                    $group['member_count'] = $total_users;
                } else {
                    // 普通群聊，成员数量为group_members表中的记录数
                    $stmt = $this->conn->prepare("SELECT COUNT(*) as member_count FROM group_members WHERE group_id = ?");
                    $stmt->execute([$group['id']]);
                    $group['member_count'] = $stmt->fetch()['member_count'];
                }
                
                // 获取用户在该群聊中的角色
                if ($group['all_user_group'] == 1) {
                    // 全员群聊，群主是管理员，其他用户是普通成员
                    $group['is_admin'] = $user_id == $group['owner_id'];
                } else {
                    // 普通群聊，使用group_members表中的角色
                    $group['is_admin'] = isset($user_groups[$group['id']]) ? $user_groups[$group['id']] : false;
                }
                
                // 获取该群聊的最新消息
                $stmt = $this->conn->prepare(
                    "SELECT gm.content, gm.created_at, gm.sender_id, u.username as sender_username 
                     FROM group_messages gm 
                     JOIN users u ON gm.sender_id = u.id 
                     WHERE gm.group_id = ? 
                     ORDER BY gm.created_at DESC 
                     LIMIT 1"
                );
                $stmt->execute([$group['id']]);
                $last_message = $stmt->fetch();
                
                if ($last_message) {
                    $group['last_message'] = $last_message['content'];
                    $group['last_message_time'] = $last_message['created_at'];
                    $group['sender_username'] = $last_message['sender_username'];
                    $group['is_me'] = $last_message['sender_id'] == $user_id;
                } else {
                    $group['last_message'] = '';
                    $group['last_message_time'] = '';
                    $group['sender_username'] = '';
                    $group['is_me'] = false;
                }
                
                $groups[] = $group;
            }
        }
        
        return $groups;
    }
    
    /**
     * 获取群聊成员角色
     * @param int $group_id 群聊ID
     * @param int $user_id 用户ID
     * @return array|false 角色信息或false
     */
    public function getMemberRole($group_id, $user_id) {
        // 检查是否是全员群聊
        $stmt = $this->conn->prepare("SELECT all_user_group, owner_id FROM groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $group_info = $stmt->fetch();
        
        if ($group_info && $group_info['all_user_group'] == 1) {
            // 全员群聊，群主是管理员，其他用户是普通成员
            $is_admin = $user_id == $group_info['owner_id'];
            return [
                'is_admin' => $is_admin,
                'owner_id' => $group_info['owner_id']
            ];
        } else {
            // 普通群聊，从group_members表获取角色
            // 兼容两种表结构
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM group_members LIKE 'is_admin'");
            $stmt->execute();
            $is_admin_exists = $stmt->fetch();
            
            if ($is_admin_exists) {
                $stmt = $this->conn->prepare("SELECT gm.is_admin, g.owner_id FROM group_members gm 
                                             JOIN groups g ON gm.group_id = g.id 
                                             WHERE gm.group_id = ? AND gm.user_id = ?");
            } else {
                $stmt = $this->conn->prepare("SELECT (gm.role = 'admin') as is_admin, g.owner_id FROM group_members gm 
                                             JOIN groups g ON gm.group_id = g.id 
                                             WHERE gm.group_id = ? AND gm.user_id = ?");
            }
            $stmt->execute([$group_id, $user_id]);
            return $stmt->fetch();
        }
    }
    

    
    /**
     * 检查用户是否是群成员
     * @param int $group_id 群聊ID
     * @param int $user_id 用户ID
     * @return bool 是否是群成员
     */
    public function isUserInGroup($group_id, $user_id) {
        // 检查是否是全员群聊
        $stmt = $this->conn->prepare("SELECT all_user_group, owner_id FROM groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $group_info = $stmt->fetch();
        
        if ($group_info && $group_info['all_user_group'] == 1) {
            // 全员群聊，所有用户都视为群成员
            return true;
        } else {
            // 普通群聊，检查用户是否在群成员表中
            $stmt = $this->conn->prepare("SELECT id FROM group_members WHERE group_id = ? AND user_id = ?");
            $stmt->execute([$group_id, $user_id]);
            return $stmt->fetch() !== false;
        }
    }
    
    /**
     * 获取用户的好友列表中不在群里的好友
     * @param int $user_id 用户ID
     * @param int $group_id 群聊ID
     * @return array 不在群里的好友列表
     */
    public function getUserFriendsNotInGroup($user_id, $group_id) {
        $stmt = $this->conn->prepare("SELECT u.id, u.username, u.avatar, u.status FROM users u
                                     JOIN friends f ON u.id = f.friend_id
                                     WHERE f.user_id = ? AND f.status = 'accepted'
                                     AND u.id NOT IN (SELECT user_id FROM group_members WHERE group_id = ?)
                                     ORDER BY u.username ASC");
        $stmt->execute([$user_id, $group_id]);
        return $stmt->fetchAll();
    }
    
    /**
     * 邀请好友加入群聊
     * @param int $group_id 群聊ID
     * @param int $inviter_id 邀请者ID
     * @param int $invitee_id 被邀请者ID
     * @return bool 是否成功
     */
    public function inviteFriendToGroup($group_id, $inviter_id, $invitee_id) {
        try {
            // 检查邀请者是否是群成员
            if (!$this->isUserInGroup($group_id, $inviter_id)) {
                return false;
            }
            
            // 检查被邀请者是否已经是群成员
            if ($this->isUserInGroup($group_id, $invitee_id)) {
                return false;
            }
            
            // 检查是否已经发送过邀请
            $stmt = $this->conn->prepare("SELECT id FROM group_invitations WHERE group_id = ? AND inviter_id = ? AND invitee_id = ? AND status = 'pending'");
            $stmt->execute([$group_id, $inviter_id, $invitee_id]);
            if ($stmt->fetch()) {
                return false;
            }
            
            // 发送邀请
            $stmt = $this->conn->prepare("INSERT INTO group_invitations (group_id, inviter_id, invitee_id) VALUES (?, ?, ?)");
            return $stmt->execute([$group_id, $inviter_id, $invitee_id]);
        } catch (PDOException $e) {
            error_log("Invite friend to group error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取群聊邀请列表
     * @param int $user_id 用户ID
     * @return array 群聊邀请列表
     */
    public function getGroupInvitations($user_id) {
        $stmt = $this->conn->prepare("SELECT gi.*, g.name as group_name, u.username as inviter_name, u.avatar as inviter_avatar FROM group_invitations gi
                                     JOIN groups g ON gi.group_id = g.id
                                     JOIN users u ON gi.inviter_id = u.id
                                     WHERE gi.invitee_id = ?
                                     ORDER BY gi.created_at DESC");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    }
    
    /**
     * 接受群聊邀请
     * @param int $invitation_id 邀请ID
     * @param int $user_id 用户ID
     * @return array 结果数组，包含success和message
     */
    public function acceptGroupInvitation($invitation_id, $user_id) {
        try {
            $this->conn->beginTransaction();
            
            // 获取邀请信息
            $stmt = $this->conn->prepare("SELECT * FROM group_invitations WHERE id = ? AND invitee_id = ? AND status = 'pending'");
            $stmt->execute([$invitation_id, $user_id]);
            $invitation = $stmt->fetch();
            
            if (!$invitation) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => '邀请不存在或已过期'
                ];
            }
            
            $group_id = $invitation['group_id'];
            $inviter_id = $invitation['inviter_id'];
            
            // 检查邀请者是否是管理员或群主
            $inviter_role = $this->getMemberRole($group_id, $inviter_id);
            if (!$inviter_role) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => '邀请者不是群成员'
                ];
            }
            
            $is_inviter_admin_or_owner = $inviter_role['is_admin'] || $inviter_role['owner_id'] == $inviter_id;
            
            if ($is_inviter_admin_or_owner) {
                // 邀请者是管理员或群主，直接添加被邀请者为群成员
                $stmt = $this->conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
                $stmt->execute([$group_id, $user_id]);
            } else {
                // 邀请者不是管理员或群主，发送入群申请
                $stmt = $this->conn->prepare("INSERT INTO group_join_requests (group_id, user_id) VALUES (?, ?)");
                $stmt->execute([$group_id, $user_id]);
            }
            
            // 更新邀请状态为已接受
            $stmt = $this->conn->prepare("UPDATE group_invitations SET status = 'accepted' WHERE id = ?");
            $stmt->execute([$invitation_id]);
            
            $this->conn->commit();
            return [
                'success' => true,
                'message' => $is_inviter_admin_or_owner ? '已成功加入群聊' : '入群申请已发送，等待管理员或群主批准'
            ];
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Accept group invitation error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '操作失败，请稍后重试'
            ];
        }
    }
    
    /**
     * 拒绝群聊邀请
     * @param int $invitation_id 邀请ID
     * @param int $user_id 用户ID
     * @return bool 是否成功
     */
    public function rejectGroupInvitation($invitation_id, $user_id) {
        try {
            $stmt = $this->conn->prepare("UPDATE group_invitations SET status = 'rejected' WHERE id = ? AND invitee_id = ? AND status = 'pending'");
            return $stmt->execute([$invitation_id, $user_id]);
        } catch (PDOException $e) {
            error_log("Reject group invitation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 发送入群申请
     * @param int $group_id 群聊ID
     * @param int $user_id 用户ID
     * @return bool 是否成功
     */
    public function sendJoinRequest($group_id, $user_id) {
        try {
            // 检查用户是否已经是群成员
            if ($this->isUserInGroup($group_id, $user_id)) {
                return false;
            }
            
            // 检查是否已经发送过入群申请
            $stmt = $this->conn->prepare("SELECT id FROM group_join_requests WHERE group_id = ? AND user_id = ? AND status = 'pending'");
            $stmt->execute([$group_id, $user_id]);
            if ($stmt->fetch()) {
                return false;
            }
            
            // 发送入群申请
            $stmt = $this->conn->prepare("INSERT INTO group_join_requests (group_id, user_id) VALUES (?, ?)");
            return $stmt->execute([$group_id, $user_id]);
        } catch (PDOException $e) {
            error_log("Send join request error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取入群申请列表
     * @param int $group_id 群聊ID
     * @return array 入群申请列表
     */
    public function getJoinRequests($group_id) {
        $stmt = $this->conn->prepare("SELECT gjr.*, u.username, u.avatar FROM group_join_requests gjr
                                     JOIN users u ON gjr.user_id = u.id
                                     WHERE gjr.group_id = ? AND gjr.status = 'pending'
                                     ORDER BY gjr.created_at DESC");
        $stmt->execute([$group_id]);
        return $stmt->fetchAll();
    }
    
    /**
     * 批准入群申请
     * @param int $request_id 申请ID
     * @param int $group_id 群聊ID
     * @return bool 是否成功
     */
    public function approveJoinRequest($request_id, $group_id) {
        try {
            $this->conn->beginTransaction();
            
            // 获取申请信息
            $stmt = $this->conn->prepare("SELECT * FROM group_join_requests WHERE id = ? AND group_id = ? AND status = 'pending'");
            $stmt->execute([$request_id, $group_id]);
            $request = $stmt->fetch();
            
            if (!$request) {
                $this->conn->rollBack();
                return false;
            }
            
            // 添加用户为群成员
            $stmt = $this->conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
            $stmt->execute([$group_id, $request['user_id']]);
            
            // 更新申请状态为已批准
            $stmt = $this->conn->prepare("UPDATE group_join_requests SET status = 'approved' WHERE id = ?");
            $stmt->execute([$request_id]);
            
            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Approve join request error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 拒绝入群申请
     * @param int $request_id 申请ID
     * @param int $group_id 群聊ID
     * @return bool 是否成功
     */
    public function rejectJoinRequest($request_id, $group_id) {
        try {
            $stmt = $this->conn->prepare("UPDATE group_join_requests SET status = 'rejected' WHERE id = ? AND group_id = ? AND status = 'pending'");
            return $stmt->execute([$request_id, $group_id]);
        } catch (PDOException $e) {
            error_log("Reject join request error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取所有群聊信息（管理员功能）
     * @return array 所有群聊列表
     */
    public function getAllGroups() {
        $stmt = $this->conn->prepare("SELECT g.*, 
                                            u1.username as creator_username, 
                                            u2.username as owner_username,
                                            (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) as member_count
                                     FROM groups g
                                     JOIN users u1 ON g.creator_id = u1.id
                                     JOIN users u2 ON g.owner_id = u2.id
                                     ORDER BY g.created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * 获取所有群聊消息（管理员功能）
     * @return array 所有群聊消息列表
     */
    public function getAllGroupMessages() {
        $stmt = $this->conn->prepare("SELECT gm.*, 
                                            u.username as sender_username,
                                            g.name as group_name
                                     FROM group_messages gm
                                     JOIN users u ON gm.sender_id = u.id
                                     JOIN groups g ON gm.group_id = g.id
                                     ORDER BY gm.created_at DESC
                                     LIMIT 1000"); // 限制1000条消息
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * 删除群聊
     * @param int $group_id 群聊ID
     * @param int|null $owner_id 群主ID（可选，为null时表示管理员直接删除）
     * @return bool 是否成功
     */
    public function deleteGroup($group_id, $owner_id = null) {
        // 如果提供了owner_id，验证该用户是否是群主
        if ($owner_id !== null) {
            $stmt = $this->conn->prepare("SELECT id FROM groups WHERE id = ? AND owner_id = ?");
            $stmt->execute([$group_id, $owner_id]);
            if (!$stmt->fetch()) {
                return false;
            }
        }
        
        // 检查是否是全员群聊，如果是则禁止删除
        $stmt = $this->conn->prepare("SELECT all_user_group FROM groups WHERE id = ?");
        $stmt->execute([$group_id]);
        $group = $stmt->fetch();
        if ($group && $group['all_user_group'] > 0) {
            return false;
        }
        
        try {
            $this->conn->beginTransaction();
            
            // 删除群聊消息
            $stmt = $this->conn->prepare("DELETE FROM group_messages WHERE group_id = ?");
            $stmt->execute([$group_id]);
            
            // 删除群聊成员
            $stmt = $this->conn->prepare("DELETE FROM group_members WHERE group_id = ?");
            $stmt->execute([$group_id]);
            
            // 删除群聊
            $stmt = $this->conn->prepare("DELETE FROM groups WHERE id = ?");
            $stmt->execute([$group_id]);
            
            $this->conn->commit();
            return true;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Delete group error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 创建全员群聊
     * @param int $creator_id 创建者ID
     * @param int $group_number 群聊编号
     * @return int|false 群聊ID或false
     */
    public function createAllUserGroup($creator_id, $group_number) {
        try {
            $this->conn->beginTransaction();
            
            // 创建群聊
            $group_name = "世界大厅-{$group_number}";
            $stmt = $this->conn->prepare("INSERT INTO groups (name, creator_id, owner_id, all_user_group) VALUES (?, ?, ?, ?)");
            $stmt->execute([$group_name, $creator_id, $creator_id, $group_number]);
            $group_id = $this->conn->lastInsertId();
            
            // 添加创建者为成员和群主
            $stmt = $this->conn->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)");
            $stmt->execute([$group_id, $creator_id, 'admin']);
            
            // 获取所有用户
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE id != ?");
            $stmt->execute([$creator_id]);
            $users = $stmt->fetchAll();
            
            // 添加所有用户为成员
            $stmt = $this->conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
            foreach ($users as $user) {
                $stmt->execute([$group_id, $user['id']]);
            }
            
            $this->conn->commit();
            return $group_id;
        } catch (PDOException $e) {
            $this->conn->rollBack();
            error_log("Create all user group error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取所有全员群聊
     * @return array 全员群聊列表
     */
    public function getAllUserGroups() {
        $stmt = $this->conn->prepare("SELECT * FROM groups WHERE all_user_group > 0 ORDER BY all_user_group ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * 将用户添加到所有全员群聊
     * @param int $user_id 用户ID
     * @return bool 是否成功
     */
    public function addUserToAllUserGroups($user_id) {
        try {
            // 获取所有全员群聊
            $all_user_groups = $this->getAllUserGroups();
            
            // 将用户添加到每个全员群聊
            foreach ($all_user_groups as $group) {
                // 检查用户是否已经在群里
                if (!$this->isUserInGroup($group['id'], $user_id)) {
                    $stmt = $this->conn->prepare("INSERT INTO group_members (group_id, user_id) VALUES (?, ?)");
                    $stmt->execute([$group['id'], $user_id]);
                }
            }
            
            return true;
        } catch (PDOException $e) {
            error_log("Add user to all user groups error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取当前全员群聊的数量
     * @return int 当前全员群聊的数量
     */
    public function getCurrentAllUserGroupNumber() {
        $stmt = $this->conn->prepare("SELECT MAX(all_user_group) as max_group FROM groups WHERE all_user_group > 0");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['max_group'] ? (int)$result['max_group'] : 0;
    }
    
    /**
     * 确保全员群聊存在并包含所有用户
     * @param int $creator_id 创建者ID
     * @return bool 是否成功
     */
    public function ensureAllUserGroups($creator_id) {
        try {
            // 获取所有用户数量
            $stmt = $this->conn->prepare("SELECT COUNT(*) as user_count FROM users");
            $stmt->execute();
            $user_count = $stmt->fetch()['user_count'];
            
            // 计算需要的全员群聊数量（每2000人一个群）
            $needed_groups = ceil($user_count / 2000);
            
            // 获取当前全员群聊数量
            $current_groups = $this->getCurrentAllUserGroupNumber();
            
            // 如果需要创建新的全员群聊
            for ($i = $current_groups + 1; $i <= $needed_groups; $i++) {
                $this->createAllUserGroup($creator_id, $i);
            }
            
            // 确保所有用户都在全员群聊中
            $stmt = $this->conn->prepare("SELECT id FROM users");
            $stmt->execute();
            $users = $stmt->fetchAll();
            
            foreach ($users as $user) {
                $this->addUserToAllUserGroups($user['id']);
            }
            
            return true;
        } catch (PDOException $e) {
            error_log("Ensure all user groups error: " . $e->getMessage());
            return false;
        }
    }
}
?>