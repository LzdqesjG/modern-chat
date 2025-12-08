<?php
// 确保会话已启动
if (!isset($_SESSION)) {
    session_start();
}

require_once 'db.php';

try {
    // 检查users表是否有agreed_to_terms字段
    $stmt = $conn->prepare("SHOW COLUMNS FROM users LIKE 'agreed_to_terms'");
    $stmt->execute();
    $terms_column_exists = $stmt->fetch();
    
    if (!$terms_column_exists) {
        // 添加agreed_to_terms字段，记录用户是否同意协议
        $conn->exec("ALTER TABLE users ADD COLUMN agreed_to_terms BOOLEAN DEFAULT FALSE AFTER is_deleted");
        echo "已添加agreed_to_terms字段到users表<br>";
        
        // 将管理员用户设置为已同意协议
        $conn->exec("UPDATE users SET agreed_to_terms = TRUE WHERE is_admin = TRUE");
        echo "已将管理员用户设置为已同意协议<br>";
    } else {
        echo "agreed_to_terms字段已存在<br>";
    }
    
    // 显示users表结构
    echo "<h3>users表结构：</h3>";
    $stmt = $conn->prepare("DESCRIBE users");
    $stmt->execute();
    $columns = $stmt->fetchAll();
    
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
    echo "<tr><th>字段名</th><th>类型</th><th>空</th><th>默认值</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (PDOException $e) {
    echo "错误：" . $e->getMessage() . "<br>";
}
?>