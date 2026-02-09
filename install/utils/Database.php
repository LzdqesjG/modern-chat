<?php
/**
 * 数据库工具类 - 安装系统
 * 处理数据库连接、配置、导入等
 */

class InstallDatabase {
    private $host;
    private $port;
    private $database;
    private $username;
    private $password;
    private $pdo;

    /**
     * 构造函数
     */
    public function __construct() {
        $this->host = '';
        $this->port = 3306;
        $this->database = '';
        $this->username = '';
        $this->password = '';
    }

    /**
     * 设置数据库配置
     */
    public function setConfig($host, $port, $database, $username, $password) {
        $this->host = $host;
        $this->port = $port;
        $this->database = $database;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * 测试数据库连接
     */
    public function testConnection() {
        try {
            $dsn = "mysql:host={$this->host};port={$this->port};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            return true;
        } catch (PDOException $e) {
            throw new Exception('数据库连接失败: ' . $e->getMessage());
        }
    }

    /**
     * 修改数据库用户密码
     */
    public function changeUserPassword($username, $host, $newPassword) {
        try {
            // 尝试使用 ALTER USER (MySQL 5.7.6+)
            $this->pdo->exec("ALTER USER '{$username}'@'{$host}' IDENTIFIED BY '{$newPassword}'");
            $this->pdo->exec("FLUSH PRIVILEGES");
            return true;
        } catch (PDOException $e) {
            // 如果 ALTER USER 失败，尝试旧版语法 (MySQL 5.7.5 及以下)
            try {
                $this->pdo->exec("SET PASSWORD FOR '{$username}'@'{$host}' = PASSWORD('{$newPassword}')");
                $this->pdo->exec("FLUSH PRIVILEGES");
                return true;
            } catch (PDOException $e2) {
                throw new Exception('修改密码失败: ' . $e->getMessage());
            }
        }
    }

    /**
     * 检查数据库是否存在
     */
    public function databaseExists() {
        try {
            $stmt = $this->pdo->query("SHOW DATABASES LIKE '{$this->database}'");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new Exception('检查数据库失败: ' . $e->getMessage());
        }
    }

    /**
     * 创建数据库
     */
    public function createDatabase() {
        try {
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            return true;
        } catch (PDOException $e) {
            throw new Exception('创建数据库失败: ' . $e->getMessage());
        }
    }

    /**
     * 检查数据库中是否有表
     */
    public function hasTables() {
        try {
            $stmt = $this->pdo->query("SHOW TABLES FROM `{$this->database}`");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new Exception('检查数据表失败: ' . $e->getMessage());
        }
    }

    /**
     * 导入SQL文件
     */
    public function importSql($sqlFile) {
        if (!file_exists($sqlFile)) {
            throw new Exception('SQL文件不存在: ' . $sqlFile);
        }

        // 选择数据库
        $this->pdo->exec("USE `{$this->database}`");

        // 读取SQL文件
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new Exception('读取SQL文件失败');
        }

        // 移除注释和空行
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        $sql = trim($sql);

        // 分割SQL语句
        $statements = preg_split('/;\s*\n/', $sql);
        $statements = array_filter($statements, function($stmt) {
            return !empty(trim($stmt));
        });

        // 执行每条SQL语句
        foreach ($statements as $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (PDOException $e) {
                throw new Exception('执行SQL语句失败: ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * 清空数据库（删除所有表）
     */
    public function clearDatabase() {
        try {
            // 获取所有表
            $stmt = $this->pdo->query("SHOW TABLES FROM `{$this->database}`");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // 删除每个表
            foreach ($tables as $table) {
                $this->pdo->exec("DROP TABLE IF EXISTS `{$this->database}`.`{$table}`");
            }

            return true;
        } catch (PDOException $e) {
            throw new Exception('清空数据库失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新config.php配置文件
     */
    public function updateConfigFile($configFile) {
        if (!file_exists($configFile)) {
            throw new Exception('配置文件不存在: ' . $configFile);
        }

        $configContent = file_get_contents($configFile);
        if ($configContent === false) {
            throw new Exception('读取配置文件失败');
        }

        // 替换数据库配置
        $replacements = [
            '/define\(\'DB_HOST\',\s*[\'"].*?[\'"]\)/' => "define('DB_HOST', '{$this->host}')",
            '/define\(\'DB_PORT\',\s*[\'"].*?[\'"]\)/' => "define('DB_PORT', '{$this->port}')",
            '/define\(\'DB_USER\',\s*[\'"].*?[\'"]\)/' => "define('DB_USER', '{$this->username}')",
            '/define\(\'DB_PASS\',\s*[\'"].*?[\'"]\)/' => "define('DB_PASS', '{$this->password}')",
            '/define\(\'DB_NAME\',\s*[\'"].*?[\'"]\)/' => "define('DB_NAME', '{$this->database}')",
        ];

        foreach ($replacements as $pattern => $replacement) {
            $configContent = preg_replace($pattern, $replacement, $configContent);
        }

        // 写回文件
        if (file_put_contents($configFile, $configContent) === false) {
            // 尝试使用 chmod 修改权限后再写入
            @chmod($configFile, 0777);
            if (file_put_contents($configFile, $configContent) === false) {
                throw new Exception('写入配置文件失败，请检查文件权限');
            }
        }

        return true;
    }

    /**
     * 获取数据库版本
     */
    public function getDatabaseVersion() {
        try {
            $stmt = $this->pdo->query("SELECT VERSION()");
            $version = $stmt->fetchColumn();
            return $version;
        } catch (PDOException) {
            return '未知';
        }
    }

    /**
     * 检查表是否存在
     */
    public function tableExists($tableName) {
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE '{$tableName}'");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * 创建或更新管理员用户
     */
    public function createOrUpdateAdmin($username, $email, $password) {
        // 确保 users 表存在
        if (!$this->tableExists('users')) {
            throw new Exception('用户表不存在，无法创建管理员');
        }

        try {
            // 哈希密码
            // 注意：这里假设安装环境支持password_hash，PHP 5.5+
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // 检查用户是否存在
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // 更新现有用户
                $stmt = $this->pdo->prepare("UPDATE users SET password = ?, email = ?, is_admin = 1 WHERE id = ?");
                $stmt->execute([$hashedPassword, $email, $user['id']]);
            } else {
                // 创建新用户
                $stmt = $this->pdo->prepare("INSERT INTO users (username, email, password, is_admin, avatar, status) VALUES (?, ?, ?, 1, 'default_avatar.png', 'offline')");
                $stmt->execute([$username, $email, $hashedPassword]);
            }
            
            return true;
        } catch (PDOException $e) {
            throw new Exception('创建管理员失败: ' . $e->getMessage());
        }
    }
}
