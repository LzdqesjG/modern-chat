<?php
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

/**
 * Redis管理器类，用于管理Redis连接和在线人数存储
 * 支持降级方案，当Redis不可用时回退到数据库存储
 */

if (!class_exists('Redis')) {
    class Redis {
        public function connect($host, $port, $timeout = 0) { return false; }
        public function zAdd($key, $score, $value) { return false; }
        public function zRem($key, $member) { return false; }
        public function zRemRangeByScore($key, $min, $max) { return false; }
        public function zCard($key) { return 0; }
        public function zRange($key, $start, $end) { return []; }
    }
}

class RedisManager {
    /** @var Redis|null $redis Redis实例 */
    private $redis;
    private $is_available = false;
    private $conn;
    
    /**
     * 构造函数，初始化Redis连接
     * @param PDO $conn 数据库连接，用于降级方案
     */
    public function __construct($conn) {
        if (!$conn) {
            throw new Exception("数据库连接失败");
        }
        $this->conn = $conn;
        
        try {
            // 检查是否安装了Redis扩展
            if (extension_loaded('redis')) {
                /** @var Redis $redis */
                $this->redis = new Redis();
                // 连接Redis服务器
                $this->redis->connect('127.0.0.1', 6379);
                $this->is_available = true;
            }
        } catch (Exception $e) {
            // Redis连接失败，使用数据库作为降级方案
            $this->is_available = false;
        }
    }
    
    /**
     * 获取Redis是否可用
     * @return bool Redis是否可用
     */
    public function isAvailable() {
        return $this->is_available;
    }
    
    /**
     * 添加在线用户
     * @param int $user_id 用户ID
     */
    public function addOnlineUser($user_id) {
        if ($this->is_available) {
            try {
                // 使用Redis有序集合存储在线用户，分数为当前时间戳
                $this->redis->zAdd('online_users', time(), $user_id);
            } catch (Exception $e) {
                // Redis操作失败，使用数据库作为降级方案
                $this->updateUserStatusInDB($user_id, 'online');
            }
        } else {
            // Redis不可用，使用数据库作为降级方案
            $this->updateUserStatusInDB($user_id, 'online');
        }
    }
    
    /**
     * 移除在线用户
     * @param int $user_id 用户ID
     */
    public function removeOnlineUser($user_id) {
        if ($this->is_available) {
            try {
                // 从Redis有序集合中移除在线用户
                $this->redis->zRem('online_users', $user_id);
            } catch (Exception $e) {
                // Redis操作失败，使用数据库作为降级方案
                $this->updateUserStatusInDB($user_id, 'offline');
            }
        } else {
            // Redis不可用，使用数据库作为降级方案
            $this->updateUserStatusInDB($user_id, 'offline');
        }
    }
    
    /**
     * 更新用户状态（数据库降级方案）
     * @param int $user_id 用户ID
     * @param string $status 用户状态
     */
    private function updateUserStatusInDB($user_id, $status) {
        try {
            $stmt = $this->conn->prepare("UPDATE users SET status = ?, last_active = NOW() WHERE id = ?");
            $stmt->execute([$status, $user_id]);
        } catch (PDOException $e) {
            // 数据库操作失败，忽略错误
        }
    }
    
    /**
     * 获取在线用户数量
     * @return int 在线用户数量
     */
    public function getOnlineUserCount() {
        if ($this->is_available) {
            try {
                // 移除超过30分钟没有活动的用户
                $this->redis->zRemRangeByScore('online_users', 0, time() - 1800);
                // 返回在线用户数量
                return $this->redis->zCard('online_users');
            } catch (Exception $e) {
                // Redis操作失败，使用数据库作为降级方案
                return $this->getOnlineUserCountFromDB();
            }
        } else {
            // Redis不可用，使用数据库作为降级方案
            return $this->getOnlineUserCountFromDB();
        }
    }
    
    /**
     * 从数据库获取在线用户数量（降级方案）
     * @return int 在线用户数量
     */
    private function getOnlineUserCountFromDB() {
        try {
            $stmt = $this->conn->prepare("SELECT COUNT(*) as online_count FROM users WHERE status = 'online' AND last_active > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
            $stmt->execute();
            $result = $stmt->fetch();
            return (int)$result['online_count'];
        } catch (PDOException $e) {
            // 数据库操作失败，返回0
            return 0;
        }
    }
    
    /**
     * 获取所有在线用户ID
     * @return array 在线用户ID数组
     */
    public function getOnlineUsers() {
        if ($this->is_available) {
            try {
                // 移除超过30分钟没有活动的用户
                $this->redis->zRemRangeByScore('online_users', 0, time() - 1800);
                // 返回在线用户ID数组
                return $this->redis->zRange('online_users', 0, -1);
            } catch (Exception $e) {
                // Redis操作失败，使用数据库作为降级方案
                return $this->getOnlineUsersFromDB();
            }
        } else {
            // Redis不可用，使用数据库作为降级方案
            return $this->getOnlineUsersFromDB();
        }
    }
    
    /**
     * 从数据库获取在线用户ID数组（降级方案）
     * @return array 在线用户ID数组
     */
    private function getOnlineUsersFromDB() {
        try {
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE status = 'online' AND last_active > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
            $stmt->execute();
            $users = $stmt->fetchAll();
            $user_ids = [];
            foreach ($users as $user) {
                $user_ids[] = $user['id'];
            }
            return $user_ids;
        } catch (PDOException $e) {
            // 数据库操作失败，返回空数组
            return [];
        }
    }
    
    /**
     * 更新用户活动时间
     * @param int $user_id 用户ID
     */
    public function updateUserActivity($user_id) {
        if ($this->is_available) {
            try {
                // 更新Redis中的用户活动时间
                $this->redis->zAdd('online_users', time(), $user_id);
            } catch (Exception $e) {
                // Redis操作失败，使用数据库作为降级方案
                $this->updateUserStatusInDB($user_id, 'online');
            }
        } else {
            // Redis不可用，使用数据库作为降级方案
            $this->updateUserStatusInDB($user_id, 'online');
        }
    }
}
