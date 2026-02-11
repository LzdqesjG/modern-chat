<?php
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use GuzzleHttp\Client;

class AliSmsClient {
    private $accessKeyId;
    private $accessKeySecret;
    private $client;
    
    public function __construct($accessKeyId, $accessKeySecret) {
        if (!class_exists('GuzzleHttp\Client')) {
            throw new Exception("GuzzleHttp\Client 类不存在，请先执行 composer install 安装依赖");
        }
        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->client = new Client([
            'base_uri' => 'https://dypnsapi.aliyuncs.com',
            'timeout'  => 10.0,
        ]);
    }
    
    /**
     * 发送验证码
     * @param string $phoneNumber 手机号
     * @param int $expireTime 有效期（秒），默认300秒（5分钟）
     * @param string $verifyCode 6位数字验证码，如果不传则由阿里云生成
     * @return array 发送结果
     */
    public function sendVerifyCode($phoneNumber, $expireTime = 300, $verifyCode = '') {
        // 计算分钟数
        $minutes = ceil($expireTime / 60);
        
        // 准备请求参数
        $params = [
            'Action' => 'SendSmsVerifyCode',
            'PhoneNumber' => $phoneNumber,
            // 从图片中选择一个已通过审核的签名
            'SignName' => '云渚科技验证平台', // 可替换为图片中其他通过的签名
            'TemplateCode' => '100001', // 您的模板CODE
            'ExpireTime' => (string)$expireTime,
            'RegionId' => 'cn-hangzhou',
            'Format' => 'JSON',
            'Version' => '2017-05-25',
            'AccessKeyId' => $this->accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => uniqid(),
            // 模板参数必须与模板变量对应
            'TemplateParam' => json_encode([
                'code' => $verifyCode ?: '123456', // 如果提供则使用，否则用占位符
                'min' => (string)$minutes // 有效期分钟数
            ], JSON_UNESCAPED_UNICODE)
        ];
        
        // 如果提供了自定义验证码，需要添加到参数中
        if ($verifyCode !== '') {
            $params['Code'] = $verifyCode;
        }
        
        // 计算签名
        $params['Signature'] = $this->generateSignature($params);
        
        try {
            $response = $this->client->post('/', [
                'form_params' => $params,
                'http_errors' => false // 禁止自动抛出异常
            ]);
            
            $statusCode = $response->getStatusCode();
            $result = json_decode($response->getBody(), true);
            
            if ($statusCode == 200) {
                if (isset($result['Code']) && $result['Code'] === 'OK') {
                    return [
                        'success' => true,
                        'bizId' => $result['Model']['VerifyCode'] ?? '',
                        'requestId' => $result['RequestId'] ?? ''
                    ];
                } else {
                    return [
                        'success' => false,
                        'errorCode' => $result['Code'] ?? 'UNKNOWN',
                        'error' => $result['Message'] ?? '发送失败',
                        'raw' => $result
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'errorCode' => 'HTTP_'.$statusCode,
                    'error' => 'HTTP请求失败，状态码: '.$statusCode,
                    'raw' => $result
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => '请求异常: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 验证短信验证码
     */
    public function checkVerifyCode($phoneNumber, $verifyCode) {
        $params = [
            'Action' => 'CheckSmsVerifyCode',
            'PhoneNumber' => $phoneNumber,
            'VerifyCode' => $verifyCode,
            'RegionId' => 'cn-hangzhou',
            'Format' => 'JSON',
            'Version' => '2017-05-25',
            'AccessKeyId' => $this->accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => uniqid(),
        ];
        
        $params['Signature'] = $this->generateSignature($params);
        
        try {
            $response = $this->client->post('/', [
                'form_params' => $params,
                'http_errors' => false
            ]);
            
            $result = json_decode($response->getBody(), true);
            
            if ($response->getStatusCode() == 200 && 
                isset($result['Code']) && 
                $result['Code'] === 'OK') {
                return [
                    'success' => true,
                    'verifyResult' => $result['Model']['VerifyResult'] ?? '',
                    'requestId' => $result['RequestId'] ?? ''
                ];
            } else {
                return [
                    'success' => false,
                    'errorCode' => $result['Code'] ?? 'UNKNOWN',
                    'error' => $result['Message'] ?? '验证失败'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => '请求异常: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 生成阿里云API签名
     */
    private function generateSignature($parameters) {
        // 移除Signature参数（如果有）
        unset($parameters['Signature']);
        
        // 1. 将参数按字典序排序
        ksort($parameters);
        
        // 2. 构造规范化请求字符串
        $canonicalizedQueryString = '';
        foreach ($parameters as $key => $value) {
            $canonicalizedQueryString .= '&' . $this->percentEncode($key) . '=' . $this->percentEncode($value);
        }
        $canonicalizedQueryString = substr($canonicalizedQueryString, 1);
        
        // 3. 构造待签名字符串
        $stringToSign = 'POST&%2F&' . $this->percentEncode($canonicalizedQueryString);
        
        // 4. 计算签名
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->accessKeySecret . '&', true));
        
        return $signature;
    }
    
    /**
     * URL编码
     */
    private function percentEncode($str) {
        $res = urlencode($str);
        $res = str_replace(['+', '*'], ['%20', '%2A'], $res);
        $res = preg_replace('/%7E/', '~', $res);
        return $res;
    }
}
