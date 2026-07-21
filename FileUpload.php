<?php
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}
require_once 'config.php';
require_once 'db.php';

class FileUpload {
    private $conn;
    private $uploadDir;
    private $maxFileSize;
    private $allowedTypes;
    
    public function __construct($db) {
        $this->conn = $db;
        // 修改为新的上传目录：files.modern-chat.top/uploads/
        $this->uploadDir = __DIR__ . '/files.modern-chat.top/uploads/';
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
            // 预先获取 vkey（用于构建代理 URL，避免前端 fetch 跨域）
            $vkey = null;
            if (function_exists('get_vkey_by_user_id')) {
                $vkey = get_vkey_by_user_id($user_id, $this->conn);
            }

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
            
            // 获取原始文件名（不进行basename处理，保留完整路径用于安全检查）
            $original_filename = $file['name'];
            
            // 检查文件名中是否包含NTFS ADS流标记（防止ADS流绕过）
            if (strpos($original_filename, ':') !== false) {
                // 检查是否包含ADS流标记
                $ads_patterns = [
                    '/::DATA$/i',          // 标准ADS流标记
                    '/:\$DATA$/i',         // 变体形式
                    '/::[A-Za-z0-9_]+$/i', // 任意ADS流名称
                    '/:\$[A-Za-z0-9_]+$/i',// 变体形式
                ];
                
                foreach ($ads_patterns as $pattern) {
                    if (preg_match($pattern, $original_filename)) {
                        error_log("NTFS ADS stream detected in filename: " . $original_filename);
                        return ['success' => false, 'message' => '禁止上传包含数据流的文件'];
                    }
                }
            }
            
            // 使用basename获取文件名（去除路径）
            $original_name = basename($file['name']);
            
            // 移除Windows ::DATA流（双重防护）
            $original_name = preg_replace('/::DATA$/i', '', $original_name);
            $original_name = preg_replace('/:\$DATA$/i', '', $original_name);
            
            // 再次检查处理后的文件名是否仍然包含冒号（可能表示隐藏的ADS流）
            if (strpos($original_name, ':') !== false) {
                error_log("Suspicious filename with colon detected: " . $original_name);
                return ['success' => false, 'message' => '文件名包含非法字符'];
            }
            
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
                "SELECT * FROM file_uploads WHERE file_sha256 = ? ORDER BY created_at DESC LIMIT 1"
            );
            $stmt->execute([$file_sha256]);
            $existing_file = $stmt->fetch();
            
            if ($existing_file) {
                // 文件已存在，返回秒传信息
                // file_path 使用 {upload_id}/{stored_name} 相对格式，供 generate_file_url() / 前端 FileHelper 使用
                $relative_path = $existing_file['upload_id'] . '/' . $existing_file['stored_name'];
                if ($vkey && function_exists('generate_proxy_url_with_id')) {
                    $file_url = generate_proxy_url_with_id($existing_file['upload_id'], $existing_file['stored_name'], $vkey);
                } else {
                    $file_url = 'https://files.modern-chat.top/list.php?id=' . urlencode($existing_file['upload_id']) . '&file=' . urlencode($existing_file['stored_name']);
                }
                
                return [
                    'success' => true,
                    'file_path' => $relative_path,
                    'file_name' => $original_name,
                    'file_size' => $existing_file['file_size'],
                    'mime_type' => $existing_file['mime_type'],
                    'stored_name' => $existing_file['stored_name'],
                    'upload_id' => $existing_file['upload_id'],
                    'file_url' => $file_url,
                    'is_duplicate' => true,
                    'message' => '文件重复，已秒传！'
                ];
            }
            
            // 生成唯一的 upload_id
            $upload_id = uniqid('', true) . '_' . time();
            $upload_dir = $this->uploadDir . $upload_id . '/';
            
            // 创建上传子目录
            if (!is_dir($upload_dir)) {
                if (!mkdir($upload_dir, 0755, true)) {
                    error_log("Failed to create upload directory: " . $upload_dir);
                    return ['success' => false, 'message' => '上传目录创建失败'];
                }
            }
            
            // 检查上传目录是否可写
            if (!is_writable($upload_dir)) {
                error_log("Upload directory not writable: " . $upload_dir);
                return ['success' => false, 'message' => '上传目录不可写'];
            }
            
            // 生成唯一文件名
            $stored_name = uniqid() . '_' . time() . '.' . $extension;
            $file_path = $upload_dir . $stored_name;
            
            // 移动文件到上传目录
            if (!move_uploaded_file($file['tmp_name'], $file_path)) {
                error_log("Failed to move file from " . $file['tmp_name'] . " to " . $file_path);
                return ['success' => false, 'message' => '文件上传失败: 无法移动文件'];
            }
            
            // 保存文件信息到数据库（包含SHA256）— file_uploads 表存完整物理路径（如秒传/去重用）
            $stmt = $this->conn->prepare(
                "INSERT INTO file_uploads (user_id, upload_id, original_name, stored_name, file_path, file_size, mime_type, file_sha256) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$user_id, $upload_id, $original_name, $stored_name, $file_path, $file['size'], $mime_type, $file_sha256]);
            
            // 构建文件访问 URL（优先走同域代理）
            if ($vkey && function_exists('generate_proxy_url_with_id')) {
                $file_url = generate_proxy_url_with_id($upload_id, $stored_name, $vkey);
            } else {
                $file_url = 'https://files.modern-chat.top/list.php?id=' . urlencode($upload_id) . '&file=' . urlencode($stored_name);
            }
            
            // file_path 返回 {upload_id}/{stored_name} 相对格式
            // —— generate_file_url() 和前端 FileHelper.getFileUrl() 都直接按 / 拆分即可
            $relative_path = $upload_id . '/' . $stored_name;
            return [
                'success' => true,
                'file_path' => $relative_path,
                'file_name' => $original_name,
                'file_size' => $file['size'],
                'mime_type' => $mime_type,
                'stored_name' => $stored_name,
                'upload_id' => $upload_id,
                'file_url' => $file_url,
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