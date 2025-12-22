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
            // 获取已过期的封禁，只处理有结束时间的封禁
            $stmt = $this->conn->prepare("SELECT id FROM bans WHERE status = 'active' AND ban_end IS NOT NULL AND ban_end <= NOW()");
            $stmt->execute();
            $expired_bans = $stmt->fetchAll();
            
            if (!empty($expired_bans)) {
                // 将已过期的封禁记录状态更新为'expired'，而不是删除
                $ban_ids = array_column($expired_bans, 'id');
                $placeholders = rtrim(str_repeat('?,', count($ban_ids)), ',');
                $stmt = $this->conn->prepare("UPDATE bans SET status = 'expired' WHERE id IN ($placeholders)");
                $stmt->execute($ban_ids);
                
                // 记录封禁日志
                $stmt = $this->conn->prepare("INSERT INTO ban_logs (ban_id, action) VALUES (?, 'expire')");
                foreach ($ban_ids as $ban_id) {
                    $stmt->execute([$ban_id]);
                }
            }
        } catch(PDOException $e) {
            error_log("Update Expired Bans Error: " . $e->getMessage());
        }
    }
    
    /**
     * 封禁用户
     * @param int $user_id 用户ID
     * @param int $banned_by 封禁者ID
     * @param string $reason 封禁理由
     * @param int $ban_duration 封禁时长（秒），0表示永久封禁
     * @return bool 是否封禁成功
     */
    public function banUser($user_id, $banned_by, $reason, $ban_duration) {
        try {
            // 计算封禁结束时间，0表示永久封禁
            $ban_end = $ban_duration > 0 ? date('Y-m-d H:i:s', time() + $ban_duration) : null;
            
            // 开始事务
            $this->conn->beginTransaction();
            
            // 处理可能存在的旧封禁记录，删除或更新状态
            // 先检查是否存在旧的封禁记录
            $stmt = $this->conn->prepare("SELECT id FROM bans WHERE user_id = ? AND status IN ('lifted', 'expired')");
            $stmt->execute([$user_id]);
            $old_bans = $stmt->fetchAll();
            
            // 如果有旧的封禁记录，删除它们
            if ($old_bans) {
                $ban_ids = array_column($old_bans, 'id');
                $placeholders = rtrim(str_repeat('?,', count($ban_ids)), ',');
                $stmt = $this->conn->prepare("DELETE FROM bans WHERE id IN ($placeholders)");
                $stmt->execute($ban_ids);
            }
            
            // 检查用户是否已经被封禁
            $existing_ban = $this->isBanned($user_id);
            if ($existing_ban) {
                $this->conn->rollBack();
                return false;
            }
            
            // 插入封禁记录，存储秒数以便后续计算，并显式设置status为active
            $stmt = $this->conn->prepare("INSERT INTO bans (user_id, banned_by, reason, ban_duration, ban_end, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$user_id, $banned_by, $reason, $ban_duration, $ban_end]);
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
     * 用户同意协议
     * @param int $user_id 用户ID
     * @return bool 是否更新成功
     */
    public function agreeToTerms($user_id) {
        // 直接调用updateTermsAgreement方法，设置为同意
        return $this->updateTermsAgreement($user_id, true);
    }
    
    /**
     * 生成RSA密钥对
     * @return array 包含public_key和private_key的数组
     */
    private function generateRSAKeys() {
        $config = array(
            "digest_alg" => "sha512",
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        );
        
        // 创建密钥对
        $res = openssl_pkey_new($config);
        
        if ($res === false) {
            error_log("OpenSSL: Failed to generate new private key");
            return false;
        }
        
        // 提取私钥
        $success = openssl_pkey_export($res, $private_key);
        
        if (!$success) {
            error_log("OpenSSL: Failed to export private key");
            openssl_free_key($res);
            return false;
        }
        
        // 提取公钥
        $public_key = openssl_pkey_get_details($res);
        
        if ($public_key === false) {
            error_log("OpenSSL: Failed to get public key details");
            openssl_free_key($res);
            return false;
        }
        
        $public_key_pem = $public_key["key"];
        
        // 释放资源
        openssl_free_key($res);
        
        return array(
            "public_key" => $public_key_pem,
            "private_key" => $private_key
        );
    }
    
    /**
     * 为用户生成并存储加密密钥
     * @param int $user_id 用户ID
     * @return bool 是否成功
     */
    public function generateEncryptionKeys($user_id) {
        try {
            // 检查用户是否已经有密钥
            $stmt = $this->conn->prepare("SELECT id FROM encryption_keys WHERE user_id = ?");
            $stmt->execute([$user_id]);
            if ($stmt->fetch()) {
                // 用户已经有密钥，不需要重新生成
                return true;
            }
            
            // 生成密钥对
            $keys = $this->generateRSAKeys();
            
            // 存储密钥
            $stmt = $this->conn->prepare(
                "INSERT INTO encryption_keys (user_id, public_key, private_key) 
                 VALUES (?, ?, ?)"
            );
            $stmt->execute([$user_id, $keys["public_key"], $keys["private_key"]]);
            
            return true;
        } catch(PDOException $e) {
            error_log("Generate Encryption Keys Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取用户的公钥
     * @param int $user_id 用户ID
     * @return string|null 公钥，如果不存在则返回null
     */
    public function getPublicKey($user_id) {
        try {
            $stmt = $this->conn->prepare("SELECT public_key FROM encryption_keys WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch();
            return $result ? $result["public_key"] : null;
        } catch(PDOException $e) {
            error_log("Get Public Key Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 获取用户的私钥
     * @param int $user_id 用户ID
     * @return string|null 私钥，如果不存在则返回null
     */
    public function getPrivateKey($user_id) {
        try {
            $stmt = $this->conn->prepare("SELECT private_key FROM encryption_keys WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch();
            return $result ? $result["private_key"] : null;
        } catch(PDOException $e) {
            error_log("Get Private Key Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 加密消息
     * @param string $message 要加密的消息
     * @param string $public_key 接收者的公钥
     * @return string|null 加密后的消息，如果加密失败则返回null
     */
    public function encryptMessage($message, $public_key) {
        try {
            $encrypted = "";
            openssl_public_encrypt($message, $encrypted, $public_key);
            return base64_encode($encrypted);
        } catch(Exception $e) {
            error_log("Encrypt Message Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 解密消息
     * @param string $encrypted_message 加密后的消息
     * @param string $private_key 接收者的私钥
     * @return string|null 解密后的消息，如果解密失败则返回null
     */
    public function decryptMessage($encrypted_message, $private_key) {
        try {
            $decrypted = "";
            openssl_private_decrypt(base64_decode($encrypted_message), $decrypted, $private_key);
            return $decrypted;
        } catch(Exception $e) {
            error_log("Decrypt Message Error: " . $e->getMessage());
            return null;
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
