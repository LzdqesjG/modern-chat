<?php
/**
 * 通用工具类 - 安装系统
 * 处理安装锁的创建和检查
 */

class InstallCommon {
    /**
     * 检查安装锁是否存在
     * @return bool
     */
    public static function checkInstallLock() {
        return file_exists(dirname(__DIR__, 2) . '/installed.lock');
    }

    /**
     * 创建安装锁
     * @return bool
     */
    public static function createInstallLock() {
        $lockFile = dirname(__DIR__, 2) . '/installed.lock';
        return file_put_contents($lockFile, date('Y-m-d H:i:s')) !== false;
    }

    /**
     * 删除安装锁（用于重新安装）
     * @return bool
     */
    public static function removeInstallLock() {
        $lockFile = dirname(__DIR__, 2) . '/installed.lock';
        if (file_exists($lockFile)) {
            return unlink($lockFile);
        }
        return true;
    }

    /**
     * 返回JSON响应
     * @param bool $success
     * @param string $message
     * @param array $data
     */
    public static function jsonResponse($success, $message, $data = []) {
        // 清除缓冲区中的任何之前的输出
        if (ob_get_length()) {
            ob_clean();
        }
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 检查文件是否可写
     * @param string $file
     * @return bool
     */
    public static function isWritable($file) {
        return is_writable($file);
    }
}
