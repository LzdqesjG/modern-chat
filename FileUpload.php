<?php
require_once 'config.php';
require_once 'db.php';

class FileUpload {
    private $conn;
    private $uploadDir;
    private $maxFileSize;
    private $allowedTypes;
    
    public function __construct($db) {
        $this->conn = $db;
        $this->uploadDir = UPLOAD_DIR;
        $this->maxFileSize = MAX_FILE_SIZE;
        $this->allowedTypes = ALLOWED_FILE_TYPES;
        
        // 确保上传目录存在
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }
    
    // 上传文件
    public function upload($file, $user_id) {
        try {
            // 检查文件是否有错误
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error_msg = $this->getErrorMessage($file['error']);
                error_log("File Upload Error: " . $error_msg);
                return ['success' => false, 'message' => $error_msg];
            }
            
            // 检查文件大小
            if ($file['size'] > $this->maxFileSize) {
                $max_size_mb = round($this->maxFileSize / (1024 * 1024));
                error_log("File too large: " . $file['size'] . " bytes, max allowed: " . $this->maxFileSize . " bytes");
                return ['success' => false, 'message' => '文件大小不能超过' . $max_size_mb . 'MB'];
            }
            
            // 禁止上传的网页格式文件扩展名
            $forbidden_extensions = ['html', 'htm', 'php', 'asp', 'aspx', 'jsp', 'js', 'css', 'xml', 'svg', 'xhtml', 'shtml', 'phtml', 'pl', 'py', 'cgi', 'php3', 'php4', 'php5', 'php7', 'php8', 'jspf', 'jspx', 'wss', 'do', 'action', 'cfm', 'cfml', 'cfc', 'lua', 'rb', 'go', 'sh', 'bat', 'cmd', 'exe', 'dll', 'com', 'pif', 'scr', 'jsx', 'tsx', 'ts', 'jsonp', 'vbs', 'vbe', 'wsf', 'wsc', 'htaccess', 'htpasswd', 'ini', 'conf', 'config', 'inc', 'module', 'theme', 'tpl', 'twig', 'blade', 'mustache', 'ejs', 'hbs', 'pug', 'jade', 'haml', 'slim', 'liquid', 'jinja2', 'nunjucks', 'handlebars', 'marko', 'riot', 'vue', 'svelte', 'angular', 'react', 'ember', 'backbone', 'marionette', 'knockout', 'meteor', 'polymer', 'aurelia', 'vuex', 'redux', 'mobx', 'flux', 'relay', 'apollo', 'graphql', 'rest', 'api', 'swagger', 'openapi', 'raml', 'oas', 'soap', 'wsdl', 'wadl', 'json-schema', 'xml-schema', 'xsd', 'dtd', 'rdf', 'owl', 'turtle', 'n3', 'ntriples', 'jsonld', 'microdata', 'rdfa', 'schema', 'structured-data', 'meta', 'link', 'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'param', 'source', 'code', 'pre', 'textarea', 'input', 'select', 'option', 'form', 'button', 'submit', 'reset', 'image', 'checkbox', 'radio', 'file', 'hidden', 'password', 'tel', 'email', 'url', 'search', 'number', 'range', 'color', 'date', 'time', 'datetime', 'datetime-local', 'month', 'week'];
            
            // 获取文件扩展名，防止::DATA流绕过
            $original_name = basename($file['name']);
            
            // 移除Windows ::DATA流
            $original_name = preg_replace('/::DATA$/i', '', $original_name);
            
            // 获取真实扩展名
            $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            
            // 检查文件扩展名是否在禁止列表中
            if (in_array($extension, $forbidden_extensions)) {
                error_log("Forbidden file extension: " . $extension);
                return ['success' => false, 'message' => '禁止上传网页格式文件'];
            }
            
            // 跳过文件类型检查，因为服务器没有安装fileinfo扩展
            // 使用文件扩展名作为MIME类型的替代
            $mime_type = $file['type']; // 使用浏览器提供的MIME类型
            
            // 如果浏览器没有提供MIME类型，根据扩展名猜测
            if (empty($mime_type)) {
                $mime_type = 'application/octet-stream';
            }
            
            // 计算文件的SHA256哈希值
            $file_sha256 = hash_file('sha256', $file['tmp_name']);
            
            // 检查是否已存在相同SHA256的文件
            $stmt = $this->conn->prepare(
                "SELECT * FROM files WHERE file_sha256 = ?"
            );
            $stmt->execute([$file_sha256]);
            $existing_file = $stmt->fetch();
            
            if ($existing_file) {
                // 文件已存在，返回秒传信息
                return [
                    'success' => true,
                    'file_path' => $existing_file['file_path'],
                    'file_name' => $original_name,
                    'file_size' => $existing_file['file_size'],
                    'mime_type' => $existing_file['mime_type'],
                    'stored_name' => $existing_file['stored_name'],
                    'is_duplicate' => true,
                    'message' => '文件重复，已秒传！'
                ];
            }
            
            // 确保上传目录存在
            if (!is_dir($this->uploadDir)) {
                if (!mkdir($this->uploadDir, 0755, true)) {
                    error_log("Failed to create upload directory: " . $this->uploadDir);
                    return ['success' => false, 'message' => '上传目录创建失败'];
                }
            }
            
            // 检查上传目录是否可写
            if (!is_writable($this->uploadDir)) {
                error_log("Upload directory not writable: " . $this->uploadDir);
                return ['success' => false, 'message' => '上传目录不可写'];
            }
            
            // 生成唯一文件名
            $original_name = basename($file['name']);
            $extension = pathinfo($original_name, PATHINFO_EXTENSION);
            $stored_name = uniqid() . '_' . time() . '.' . $extension;
            $file_path = $this->uploadDir . $stored_name;
            
            // 移动文件到上传目录
            if (!move_uploaded_file($file['tmp_name'], $file_path)) {
                error_log("Failed to move file from " . $file['tmp_name'] . " to " . $file_path);
                return ['success' => false, 'message' => '文件上传失败: 无法移动文件'];
            }
            
            // 保存文件信息到数据库（包含SHA256）
            $stmt = $this->conn->prepare(
                "INSERT INTO files (user_id, original_name, stored_name, file_path, file_size, mime_type, file_sha256) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$user_id, $original_name, $stored_name, $file_path, $file['size'], $mime_type, $file_sha256]);
            
            return [
                'success' => true,
                'file_path' => $file_path,
                'file_name' => $original_name,
                'file_size' => $file['size'],
                'mime_type' => $mime_type,
                'stored_name' => $stored_name,
                'is_duplicate' => false
            ];
        } catch(PDOException $e) {
            error_log("File Upload Database Error: " . $e->getMessage());
            return ['success' => false, 'message' => '文件上传失败: 数据库错误'];
        } catch(Exception $e) {
            error_log("File Upload Exception: " . $e->getMessage());
            return ['success' => false, 'message' => '文件上传失败: ' . $e->getMessage()];
        }
    }
    
    // 获取文件信息
    public function getFile($file_id) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM files WHERE id = ?"
            );
            $stmt->execute([$file_id]);
            return $stmt->fetch();
        } catch(PDOException $e) {
            error_log("Get File Error: " . $e->getMessage());
            return null;
        }
    }
    
    // 删除文件
    public function delete($file_id, $user_id) {
        try {
            // 获取文件信息
            $file = $this->getFile($file_id);
            if (!$file || $file['user_id'] != $user_id) {
                return ['success' => false, 'message' => '文件不存在或无权限'];
            }
            
            // 删除物理文件
            if (file_exists($file['file_path'])) {
                unlink($file['file_path']);
            }
            
            // 删除数据库记录
            $stmt = $this->conn->prepare(
                "DELETE FROM files WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$file_id, $user_id]);
            
            return ['success' => true, 'message' => '文件已删除'];
        } catch(PDOException $e) {
            error_log("Delete File Error: " . $e->getMessage());
            return ['success' => false, 'message' => '文件删除失败'];
        }
    }
    
    // 获取文件错误信息
    public function getErrorMessage($errorCode) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => '文件超过了php.ini中upload_max_filesize限制',
            UPLOAD_ERR_FORM_SIZE => '文件超过了HTML表单中MAX_FILE_SIZE限制',
            UPLOAD_ERR_PARTIAL => '文件只上传了一部分',
            UPLOAD_ERR_NO_FILE => '没有文件被上传',
            UPLOAD_ERR_NO_TMP_DIR => '缺少临时文件夹',
            UPLOAD_ERR_CANT_WRITE => '文件写入失败',
            UPLOAD_ERR_EXTENSION => '文件上传被PHP扩展停止'
        ];
        
        return isset($errorMessages[$errorCode]) ? $errorMessages[$errorCode] : '未知错误';
    }
    
    // 格式化文件大小
    public static function formatFileSize($bytes) {
        if ($bytes == 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}