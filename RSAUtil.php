<?php
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}
/**
 * RSA 加密工具类
 * 用于生成密钥对、加密和解密数据
 */
class RSAUtil {
    private $privateKey;
    private $publicKey;
    private $keyPath;
    
    public function __construct() {
        $this->keyPath = __DIR__ . '/keys/';
        $this->ensureKeyDirectory();
        $this->loadOrGenerateKeys();
    }
    
    /**
     * 确保密钥目录存在
     */
    private function ensureKeyDirectory() {
        if (!is_dir($this->keyPath)) {
            mkdir($this->keyPath, 0755, true);
        }
    }
    
    /**
     * 加载或生成密钥对
     */
    private function loadOrGenerateKeys() {
        $privateKeyFile = $this->keyPath . 'private_key.pem';
        $publicKeyFile = $this->keyPath . 'public_key.pem';
        
        // 如果密钥文件存在，直接加载
        if (file_exists($privateKeyFile) && file_exists($publicKeyFile)) {
            $this->privateKey = file_get_contents($privateKeyFile);
            $this->publicKey = file_get_contents($publicKeyFile);
            return;
        }
        
        // 生成新的密钥对
        $this->generateKeyPair();
    }
    
    /**
     * 生成 RSA 密钥对
     */
    public function generateKeyPair() {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        
        // 生成密钥对
        $res = openssl_pkey_new($config);
        if (!$res) {
            throw new Exception('生成 RSA 密钥对失败: ' . openssl_error_string());
        }
        
        // 获取私钥
        openssl_pkey_export($res, $privateKey);
        
        // 获取公钥
        $keyDetails = openssl_pkey_get_details($res);
        $publicKey = $keyDetails['key'];
        
        // 保存密钥到文件
        $privateKeyFile = $this->keyPath . 'private_key.pem';
        $publicKeyFile = $this->keyPath . 'public_key.pem';
        
        file_put_contents($privateKeyFile, $privateKey);
        file_put_contents($publicKeyFile, $publicKey);
        
        // 设置文件权限
        chmod($privateKeyFile, 0600);
        chmod($publicKeyFile, 0644);
        
        $this->privateKey = $privateKey;
        $this->publicKey = $publicKey;
        
        return [
            'privateKey' => $privateKey,
            'publicKey' => $publicKey
        ];
    }
    
    /**
     * 获取公钥（用于前端加密）
     */
    public function getPublicKey() {
        return $this->publicKey;
    }
    
    /**
     * 获取公钥（去除头部和尾部，用于 jsencrypt）
     */
    public function getPublicKeyForJS() {
        $key = $this->publicKey;
        // 移除 PEM 头部和尾部
        $key = str_replace("-----BEGIN PUBLIC KEY-----", "", $key);
        $key = str_replace("-----END PUBLIC KEY-----", "", $key);
        $key = str_replace("\n", "", $key);
        $key = str_replace("\r", "", $key);
        return trim($key);
    }
    
    /**
     * 使用私钥解密数据
     */
    public function decrypt($encryptedData) {
        if (empty($encryptedData)) {
            return false;
        }
        
        // Base64 解码
        $encryptedData = base64_decode($encryptedData);
        if ($encryptedData === false) {
            return false;
        }
        
        // 使用私钥解密
        $decrypted = '';
        $result = openssl_private_decrypt($encryptedData, $decrypted, $this->privateKey);
        
        if (!$result) {
            error_log('RSA 解密失败: ' . openssl_error_string());
            return false;
        }
        
        return $decrypted;
    }
    
    /**
     * 使用公钥加密数据（用于测试）
     */
    public function encrypt($data) {
        $encrypted = '';
        $result = openssl_public_encrypt($data, $encrypted, $this->publicKey);
        
        if (!$result) {
            throw new Exception('RSA 加密失败: ' . openssl_error_string());
        }
        
        return base64_encode($encrypted);
    }
    
    /**
     * 重新生成密钥对
     */
    public function regenerateKeys() {
        // 备份旧密钥
        $privateKeyFile = $this->keyPath . 'private_key.pem';
        $publicKeyFile = $this->keyPath . 'public_key.pem';
        
        if (file_exists($privateKeyFile)) {
            rename($privateKeyFile, $privateKeyFile . '.bak.' . time());
        }
        if (file_exists($publicKeyFile)) {
            rename($publicKeyFile, $publicKeyFile . '.bak.' . time());
        }
        
        return $this->generateKeyPair();
    }
}
