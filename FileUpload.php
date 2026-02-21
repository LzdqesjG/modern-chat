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
        // 使用绝对路径，确保文件能正确保存
        $this->uploadDir = __DIR__ . '/' . UPLOAD_DIR;
        $this->maxFileSize = MAX_FILE_SIZE;
        $this->allowedTypes = ALLOWED_FILE_TYPES;
        
        // 确保上传目录存在
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
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
            
            // 安全获取MIME类型 - 不信任浏览器提供的类型
            $mime_type = $this->getSecureMimeType($file['tmp_name'], $extension);
            
            // 验证MIME类型是否在允许列表中
            if (!in_array($mime_type, $this->allowedTypes)) {
                error_log("MIME type not allowed: " . $mime_type);
                return ['success' => false, 'message' => '不允许上传此类型的文件'];
            }
            
            // 额外安全检查：检测文件内容是否包含恶意代码
            $security_check = $this->scanFileContent($file['tmp_name'], $mime_type);
            if (!$security_check['safe']) {
                error_log("File content security check failed: " . $security_check['reason']);
                return ['success' => false, 'message' => '文件内容安全检查未通过'];
            }
            
            // 确保上传目录存在
            if (!is_dir($this->uploadDir)) {
                if (!mkdir($this->uploadDir, 0777, true)) {
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
            
            // 保存文件信息到数据库
            $stmt = $this->conn->prepare(
                "INSERT INTO files (user_id, original_name, stored_name, file_path, file_size, mime_type) 
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$user_id, $original_name, $stored_name, $file_path, $file['size'], $mime_type]);
            
            // 返回相对路径，方便客户端访问
            $relative_path = 'uploads/' . $stored_name;
            
            return [
                'success' => true,
                'file_path' => $relative_path,
                'file_name' => $original_name,
                'file_size' => $file['size'],
                'mime_type' => $mime_type,
                'stored_name' => $stored_name
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
    
    /**
     * 安全获取MIME类型 - 优先使用fileinfo扩展，否则根据扩展名映射
     * @param string $file_path 文件路径
     * @param string $extension 文件扩展名
     * @return string MIME类型
     */
    private function getSecureMimeType($file_path, $extension) {
        // 尝试使用fileinfo扩展
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime_type = finfo_file($finfo, $file_path);
                finfo_close($finfo);
                if ($mime_type) {
                    return $mime_type;
                }
            }
        }
        
        // 尝试使用mime_content_type函数
        if (function_exists('mime_content_type')) {
            $mime_type = mime_content_type($file_path);
            if ($mime_type) {
                return $mime_type;
            }
        }
        
        // 根据扩展名映射MIME类型
        $mime_map = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav'
        ];
        
        return $mime_map[$extension] ?? 'application/octet-stream';
    }
    
    /**
     * 扫描文件内容检测恶意代码
     * @param string $file_path 文件路径
     * @param string $mime_type MIME类型
     * @return array ['safe' => bool, 'reason' => string]
     */
    private function scanFileContent($file_path, $mime_type) {
        // 只扫描文本类文件和图片文件
        $text_types = ['text/plain', 'text/csv', 'application/json', 'application/xml'];
        $image_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        
        // 对于图片文件，检查是否包含恶意代码
        if (in_array($mime_type, $image_types)) {
            $content = file_get_contents($file_path);
            if ($content === false) {
                return ['safe' => false, 'reason' => 'Cannot read file content'];
            }
            
            // 检查是否包含PHP代码
            $dangerous_patterns = [
                '/<\?php/i',
                '/<\?=/i',
                '/<script/i',
                '/javascript:/i',
                '/on\w+\s*=/i',
                '/eval\s*\(/i',
                '/base64_decode/i',
                '/gzinflate/i',
                '/str_rot13/i'
            ];
            
            foreach ($dangerous_patterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    return ['safe' => false, 'reason' => 'Malicious code pattern detected'];
                }
            }
            
            // 验证图片是否有效
            $image_info = @getimagesize($file_path);
            if ($image_info === false) {
                return ['safe' => false, 'reason' => 'Invalid image file'];
            }
        }
        
        // 对于文本文件，检查是否包含危险内容
        if (in_array($mime_type, $text_types)) {
            $content = file_get_contents($file_path);
            if ($content === false) {
                return ['safe' => false, 'reason' => 'Cannot read file content'];
            }
            
            // 检查是否包含可执行代码
            $dangerous_patterns = [
                '/<\?php/i',
                '/<\?=/i',
                '/<script/i',
                '/javascript:/i',
                '/on\w+\s*=/i'
            ];
            
            foreach ($dangerous_patterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    return ['safe' => false, 'reason' => 'Potentially dangerous content detected'];
                }
            }
        }
        
        return ['safe' => true, 'reason' => ''];
    }
}