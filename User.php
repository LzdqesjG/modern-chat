<?php
require_once 'db.php';

class User {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // 用户注册
    public function register($username, $email, $password, $ip_address = '') {
        try {
            // 检查用户名和邮箱是否已存在
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->rowCount() > 0) {
                return ['success' => false, 'message' => '用户名或邮箱已存在'];
            }
            
            // 哈希密码
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
            
            // 插入新用户，不包含IP地址
            $stmt = $this->conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hashedPassword]);
            
            $user_id = $this->conn->lastInsertId();
            
            // 将IP地址插入到IP注册记录表
            if (!empty($ip_address)) {
                $stmt = $this->conn->prepare("INSERT INTO ip_registrations (user_id, ip_address) VALUES (?, ?)");
                $stmt->execute([$user_id, $ip_address]);
            }
            
            return ['success' => true, 'message' => '注册成功', 'user_id' => $user_id];
        } catch(PDOException $e) {
            error_log("Register Error: " . $e->getMessage());
            return ['success' => false, 'message' => '注册失败，请稍后重试'];
        }
    }
    
    // 用户登录
    public function login($email, $password) {
        try {
            // 查找用户
            $stmt = $this->conn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => '邮箱或密码错误'];
            }
            
            // 检查用户是否被注销
            // 检查is_deleted字段
            if (isset($user['is_deleted']) && $user['is_deleted']) {
                return ['success' => false, 'message' => '账户已被管理员删除，无法登录'];
            }
            // 检查avatar字段是否为deleted_user
            if ($user['avatar'] === 'deleted_user') {
                return ['success' => false, 'message' => '账户已被管理员删除，无法登录'];
            }
            
            // 验证密码
            if (!password_verify($password, $user['password'])) {
                return ['success' => false, 'message' => '邮箱或密码错误'];
            }
            
            // 登录时不立即更新状态为在线，等待页面加载后再更新
            // 这样可以确保只有页面真正加载后才显示在线状态
            
            return ['success' => true, 'user' => $user];
        } catch(PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
            return ['success' => false, 'message' => '登录失败，请稍后重试'];
        }
    }
    
    // 更新用户状态
    public function updateStatus($user_id, $status) {
        try {
            $stmt = $this->conn->prepare("UPDATE users SET status = ?, last_active = NOW() WHERE id = ?");
            $stmt->execute([$status, $user_id]);
            return true;
        } catch(PDOException $e) {
            error_log("Update Status Error: " . $e->getMessage());
            return false;
        }
    }
    
    // 获取用户信息
    public function getUserById($user_id) {
        try {
            $stmt = $this->conn->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            return $stmt->fetch();
        } catch(PDOException $e) {
            error_log("Get User By Id Error: " . $e->getMessage());
            return null;
        }
    }
    
    // 通过用户名搜索用户
    public function searchUsers($username, $current_user_id) {
        try {
            $stmt = $this->conn->prepare("SELECT id, username, email, avatar, status FROM users WHERE username LIKE ? AND id != ?");
            $stmt->execute(["%$username%", $current_user_id]);
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            error_log("Search Users Error: " . $e->getMessage());
            return [];
        }
    }
    
    // 检查用户是否在线
    public function isOnline($user_id) {
        try {
            $stmt = $this->conn->prepare("SELECT status FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            return $user ? $user['status'] == 'online' : false;
        } catch(PDOException $e) {
            error_log("Is Online Error: " . $e->getMessage());
            return false;
        }
    }
    
    // 获取用户状态
    public function getStatus($user_id) {
        try {
            $stmt = $this->conn->prepare("SELECT status FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            return $user ? $user['status'] : 'offline';
        } catch(PDOException $e) {
            error_log("Get Status Error: " . $e->getMessage());
            return 'offline';
        }
    }
    
    /**
     * 获取所有用户信息（管理员功能）
     * @return array 所有用户列表
     */
    public function getAllUsers() {
        try {
            $stmt = $this->conn->prepare("SELECT u.*, b.status as ban_status, b.ban_end, b.reason as ban_reason FROM users u LEFT JOIN bans b ON u.id = b.user_id AND b.status = 'active' ORDER BY u.created_at DESC");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            error_log("Get All Users Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 检查用户是否被封禁
     * @param int $user_id 用户ID
     * @return array|null 封禁信息，如果未被封禁则返回null
     */
    public function isBanned($user_id) {
        try {
            // 先检查并更新已过期的封禁
            $this->updateExpiredBans();
            
            // 检查用户是否被封禁
            $stmt = $this->conn->prepare("SELECT * FROM bans WHERE user_id = ? AND status = 'active'");
            $stmt->execute([$user_id]);
            $ban = $stmt->fetch();
            
            if ($ban) {
                return [
                    'status' => 'banned',
                    'reason' => $ban['reason'],
                    'expires_at' => $ban['ban_end']
                ];
            }
            
            return null;
        } catch(PDOException $e) {
            error_log("Check Ban Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 更新已过期的封禁
     */
    public function updateExpiredBans() {
        try {
            // 开始事务
            $this->conn->beginTransaction();
            
            // 获取已过期的封禁
            $stmt = $this->conn->prepare("SELECT id FROM bans WHERE status = 'active' AND ban_end <= NOW()");
            $stmt->execute();
            $expired_bans = $stmt->fetchAll();
            
            if (!empty($expired_bans)) {
                // 删除已过期的封禁记录
                $ban_ids = array_column($expired_bans, 'id');
                $placeholders = rtrim(str_repeat('?,', count($ban_ids)), ',');
                $stmt = $this->conn->prepare("DELETE FROM bans WHERE id IN ($placeholders)");
                $stmt->execute($ban_ids);
                
                // 记录封禁日志
                $stmt = $this->conn->prepare("INSERT INTO ban_logs (ban_id, action) VALUES (?, 'expire')");
                foreach ($ban_ids as $ban_id) {
                    $stmt->execute([$ban_id]);
                }
            }
            
            // 提交事务
            $this->conn->commit();
        } catch(PDOException $e) {
            // 回滚事务
            $this->conn->rollBack();
            error_log("Update Expired Bans Error: " . $e->getMessage());
        }
    }
    
    /**
     * 封禁用户
     * @param int $user_id 用户ID
     * @param int $banned_by 封禁者ID
     * @param string $reason 封禁理由
     * @param int $ban_duration 封禁时长（小时）
     * @return bool 是否封禁成功
     */
    public function banUser($user_id, $banned_by, $reason, $ban_duration) {
        try {
            // 检查用户是否已经被封禁
            $existing_ban = $this->isBanned($user_id);
            if ($existing_ban) {
                return false;
            }
            
            // 将小时转换为秒，计算封禁结束时间
            $ban_duration_seconds = $ban_duration * 3600; // 小时 * 3600秒/小时
            $ban_end = date('Y-m-d H:i:s', time() + $ban_duration_seconds);
            
            // 开始事务
            $this->conn->beginTransaction();
            
            // 插入封禁记录，存储秒数以便后续计算
            $stmt = $this->conn->prepare("INSERT INTO bans (user_id, banned_by, reason, ban_duration, ban_end) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $banned_by, $reason, $ban_duration_seconds, $ban_end]);
            $ban_id = $this->conn->lastInsertId();
            
            // 记录封禁日志
            $stmt = $this->conn->prepare("INSERT INTO ban_logs (ban_id, action, action_by) VALUES (?, 'ban', ?)");
            $stmt->execute([$ban_id, $banned_by]);
            
            // 提交事务
            $this->conn->commit();
            
            return true;
        } catch(PDOException $e) {
            // 回滚事务
            $this->conn->rollBack();
            error_log("Ban User Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 解除封禁
     * @param int $user_id 用户ID
     * @param int $lifted_by 解除封禁者ID
     * @return bool 是否解除成功
     */
    public function liftBan($user_id, $lifted_by) {
        try {
            // 检查用户是否被封禁
            $existing_ban = $this->isBanned($user_id);
            if (!$existing_ban) {
                return false;
            }
            
            // 开始事务
            $this->conn->beginTransaction();
            
            // 获取封禁ID
            $stmt = $this->conn->prepare("SELECT id FROM bans WHERE user_id = ? AND status = 'active'");
            $stmt->execute([$user_id]);
            $ban = $stmt->fetch();
            
            if (!$ban) {
                $this->conn->rollBack();
                return false;
            }
            
            // 更新封禁状态
            $stmt = $this->conn->prepare("UPDATE bans SET status = 'lifted' WHERE id = ?");
            $stmt->execute([$ban['id']]);
            
            // 记录封禁日志
            $stmt = $this->conn->prepare("INSERT INTO ban_logs (ban_id, action, action_by) VALUES (?, 'lift', ?)");
            $stmt->execute([$ban['id'], $lifted_by]);
            
            // 提交事务
            $this->conn->commit();
            
            return true;
        } catch(PDOException $e) {
            // 回滚事务
            $this->conn->rollBack();
            error_log("Lift Ban Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取用户的封禁记录
     * @param int $user_id 用户ID
     * @return array 封禁记录列表
     */
    public function getBanHistory($user_id) {
        try {
            $stmt = $this->conn->prepare("SELECT b.*, u.username as banned_by_username FROM bans b JOIN users u ON b.banned_by = u.id WHERE b.user_id = ? ORDER BY b.ban_start DESC");
            $stmt->execute([$user_id]);
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            error_log("Get Ban History Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 检查用户是否同意了协议
     * @param int $user_id 用户ID
     * @return bool 是否同意协议
     */
    public function hasAgreedToTerms($user_id) {
        try {
            $stmt = $this->conn->prepare("SELECT agreed_to_terms FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            return $user ? $user['agreed_to_terms'] : false;
        } catch(PDOException $e) {
            error_log("Check Terms Agreement Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新用户协议同意状态
     * @param int $user_id 用户ID
     * @param bool $agreed 是否同意协议
     * @return bool 是否更新成功
     */
    public function updateTermsAgreement($user_id, $agreed) {
        try {
            $stmt = $this->conn->prepare("UPDATE users SET agreed_to_terms = ? WHERE id = ?");
            $stmt->execute([$agreed, $user_id]);
            return true;
        } catch(PDOException $e) {
            error_log("Update Terms Agreement Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 注销用户账号
     * @param int $user_id 用户ID
     * @return bool 是否注销成功
     */
    public function deleteUser($user_id) {
        try {
            // 开始事务
            $this->conn->beginTransaction();
            
            // 更新用户为已删除状态
            $stmt = $this->conn->prepare("UPDATE users SET is_deleted = TRUE, avatar = 'deleted_user', status = 'offline' WHERE id = ?");
            $stmt->execute([$user_id]);
            
            // 提交事务
            $this->conn->commit();
            return true;
        } catch(PDOException $e) {
            // 回滚事务
            $this->conn->rollBack();
            error_log("Delete User Error: " . $e->getMessage());
            return false;
        }
    }
}
