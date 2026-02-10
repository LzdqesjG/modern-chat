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
    private $errorMap;

    /**
     * 构造函数
     */
    public function __construct() {
        $this->host = '';
        $this->port = 3306;
        $this->database = '';
        $this->username = '';
        $this->password = '';
        
        // 加载错误对照表
        $errorFile = __DIR__ . '/mysql_errors.php';
        if (file_exists($errorFile)) {
            $this->errorMap = require $errorFile;
        } else {
            $this->errorMap = [];
        }
    }

    /**
     * 获取友好的错误提示
     */
    private function getFriendlyError($e) {
        $msg = $e->getMessage();
        $code = $e->getCode();
        
        // 尝试从getMessage中提取错误码（PDO有时会将MySQL错误码包含在消息中，如SQLSTATE[HY000] [2002] ...）
        if (preg_match('/\[(\d+)\]/', $msg, $matches)) {
            $realCode = $matches[1];
            if (isset($this->errorMap[$realCode])) {
                return $this->errorMap[$realCode];
            }
        }
        
        // 尝试直接使用getCode（注意PDO的getCode有时返回SQLSTATE字符串）
        if (is_numeric($code) && isset($this->errorMap[$code])) {
            return $this->errorMap[$code];
        }
        
        // 针对特定字符串的匹配（保留原有的特殊逻辑）
        if (strpos($msg, 'php_network_getaddresses') !== false || strpos($msg, 'getaddrinfo') !== false) {
             return '无法解析数据库服务器地址，请检查主机名是否正确（不要包含http://等前缀）';
        }
        if (strpos($msg, 'Connection refused') !== false) {
             return '数据库连接被拒绝，请检查端口是否正确或防火墙设置';
        }
        if (strpos($msg, 'Access denied') !== false) {
             // 可能是密码错误(1045)或无权访问(1044/1227)，如果上面没匹配到代码，这里做通用提示
             return '访问被拒绝。请检查用户名、密码或数据库权限。(' . $msg . ')';
        }
        
        // 默认返回原始错误
        return $msg;
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
            throw new Exception($this->getFriendlyError($e));
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
                throw new Exception($this->getFriendlyError($e2));
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
            // 处理权限不足错误 (1227 Access denied)
            // SQLSTATE[42000]: Syntax error or access violation: 1227 Access denied
            if ($e->getCode() == '42000' || strpos($e->getMessage(), '1227') !== false) {
                try {
                    // 尝试直接切换到该数据库
                    $this->pdo->exec("USE `{$this->database}`");
                    return true;
                } catch (PDOException $e2) {
                    // 如果是 "Unknown database" (1049)，则确实不存在
                    if ($e2->getCode() == '1049' || strpos($e2->getMessage(), 'Unknown database') !== false) {
                        return false;
                    }
                    // 其他错误，仍然视为存在（忽略错误继续执行）
                    return true;
                }
            }
            throw new Exception($this->getFriendlyError($e));
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
            throw new Exception($this->getFriendlyError($e));
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
            throw new Exception($this->getFriendlyError($e));
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
                throw new Exception($this->getFriendlyError($e));
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
            throw new Exception($this->getFriendlyError($e));
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
            throw new Exception($this->getFriendlyError($e));
        }
    }
}
