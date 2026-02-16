<?php
require_once 'db.php';

class Friend {
    private $conn;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // 发送好友请求
    public function sendFriendRequest($user_id, $friend_id) {
        try {
            // 检查是否已经是好友或有未处理的请求
            $stmt = $this->conn->prepare(
                "SELECT * FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)"
            );
            $stmt->execute([$user_id, $friend_id, $friend_id, $user_id]);
            if ($stmt->rowCount() > 0) {
                return ['success' => false, 'message' => '已经是好友或有未处理的请求'];
            }
            
            // 发送好友请求
            $stmt = $this->conn->prepare(
                "INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'pending')"
            );
            $stmt->execute([$user_id, $friend_id]);
            
            return ['success' => true, 'message' => '好友请求已发送'];
        } catch(PDOException $e) {
            error_log("Send Friend Request Error: " . $e->getMessage());
            return ['success' => false, 'message' => '发送失败，请稍后重试'];
        }
    }
    
    // 接受好友请求
    public function acceptFriendRequest($user_id, $request_id) {
        try {
            // 先获取好友请求信息
            $stmt = $this->conn->prepare(
                "SELECT * FROM friends WHERE id = ? AND friend_id = ? AND status = 'pending'"
            );
            $stmt->execute([$request_id, $user_id]);
            $request = $stmt->fetch();
            
            if (!$request) {
                return ['success' => false, 'message' => '好友请求不存在或已处理'];
            }
            
            $friend_id = $request['user_id'];
            
            // 更新请求状态为已接受
            $stmt = $this->conn->prepare(
                "UPDATE friends SET status = 'accepted' WHERE id = ? AND status = 'pending'"
            );
            $stmt->execute([$request_id]);
            
            // 创建反向好友关系
            $stmt = $this->conn->prepare(
                "INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'accepted')"
            );
            $stmt->execute([$user_id, $friend_id]);
            
            return ['success' => true, 'message' => '好友请求已接受'];
        } catch(PDOException $e) {
            error_log("Accept Friend Request Error: " . $e->getMessage());
            return ['success' => false, 'message' => '操作失败，请稍后重试'];
        }
    }
    
    // 拒绝好友请求
    public function rejectFriendRequest($user_id, $request_id) {
        try {
            // 删除好友请求
            $stmt = $this->conn->prepare(
                "DELETE FROM friends WHERE id = ? AND friend_id = ? AND status = 'pending'"
            );
            $stmt->execute([$request_id, $user_id]);
            
            if ($stmt->rowCount() == 0) {
                return ['success' => false, 'message' => '好友请求不存在或已处理'];
            }
            
            return ['success' => true, 'message' => '好友请求已拒绝'];
        } catch(PDOException $e) {
            error_log("Reject Friend Request Error: " . $e->getMessage());
            return ['success' => false, 'message' => '操作失败，请稍后重试'];
        }
    }
    
    // 获取好友列表
    public function getFriends($user_id) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT u.id, u.username, u.email, u.avatar, u.status, f.created_at 
                 FROM friends f 
                 JOIN users u ON f.friend_id = u.id 
                 WHERE f.user_id = ? AND f.status = 'accepted'"
            );
            $stmt->execute([$user_id]);
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            error_log("Get Friends Error: " . $e->getMessage());
            return [];
        }
    }
    
    // 获取待处理的好友请求
    public function getPendingRequests($user_id) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT f.id as request_id, u.id, u.username, u.email, u.avatar, f.created_at 
                 FROM friends f 
                 JOIN users u ON f.user_id = u.id 
                 WHERE f.friend_id = ? AND f.status = 'pending'"
            );
            $stmt->execute([$user_id]);
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            error_log("Get Pending Requests Error: " . $e->getMessage());
            return [];
        }
    }
    
    // 检查是否是好友
    public function isFriend($user_id, $friend_id) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM friends WHERE user_id = ? AND friend_id = ? AND status = 'accepted'"
            );
            $stmt->execute([$user_id, $friend_id]);
            return $stmt->rowCount() > 0;
        } catch(PDOException $e) {
            error_log("Is Friend Error: " . $e->getMessage());
            return false;
        }
    }
    
    // 删除好友
    public function deleteFriend($user_id, $friend_id) {
        try {
            // 删除正向关系
            $stmt = $this->conn->prepare(
                "DELETE FROM friends WHERE user_id = ? AND friend_id = ? AND status = 'accepted'"
            );
            $stmt->execute([$user_id, $friend_id]);
            
            // 删除反向关系
            $stmt = $this->conn->prepare(
                "DELETE FROM friends WHERE user_id = ? AND friend_id = ? AND status = 'accepted'"
            );
            $stmt->execute([$friend_id, $user_id]);
            
            return ['success' => true, 'message' => '好友已删除'];
        } catch(PDOException $e) {
            error_log("Delete Friend Error: " . $e->getMessage());
            return ['success' => false, 'message' => '操作失败，请稍后重试'];
        }
    }
}