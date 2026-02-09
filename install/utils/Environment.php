<?php
/**
 * 环境检测工具类 - 安装系统
 * 检测PHP版本、扩展等环境要求
 */

class InstallEnvironment {
    /**
     * 检查环境要求
     * @return array
     */
    public static function checkRequirements() {
        $checks = [];

        // 检查PHP版本
        $phpVersion = PHP_VERSION;
        $minVersion = '5.5.0'; // 修改为5.5.0
        $checks['php_version'] = [
            'name' => 'PHP版本',
            'current' => $phpVersion,
            'required' => '>= ' . $minVersion,
            'status' => version_compare($phpVersion, $minVersion, '>='),
            'message' => version_compare($phpVersion, $minVersion, '>=') 
                ? 'PHP版本符合要求' 
                : 'PHP版本过低，建议升级到 ' . $minVersion . ' 或更高版本'
        ];

        // 检查PDO扩展
        $checks['pdo'] = [
            'name' => 'PDO扩展',
            'current' => extension_loaded('pdo') ? '已安装' : '未安装',
            'required' => '必须',
            'status' => extension_loaded('pdo'),
            'message' => extension_loaded('pdo') 
                ? 'PDO扩展已加载' 
                : 'PDO扩展未安装，请安装PDO扩展'
        ];

        // 检查PDO MySQL扩展
        $checks['pdo_mysql'] = [
            'name' => 'PDO MySQL扩展',
            'current' => extension_loaded('pdo_mysql') ? '已安装' : '未安装',
            'required' => '必须',
            'status' => extension_loaded('pdo_mysql'),
            'message' => extension_loaded('pdo_mysql') 
                ? 'PDO MySQL扩展已加载' 
                : 'PDO MySQL扩展未安装，请安装pdo_mysql扩展'
        ];

        // 检查PDO扩展
        $checks['mbstring'] = [
            'name' => 'Mbstring扩展',
            'current' => extension_loaded('mbstring') ? '已安装' : '未安装',
            'required' => '必须',
            'status' => extension_loaded('mbstring'),
            'message' => extension_loaded('mbstring') 
                ? 'Mbstring扩展已加载' 
                : 'Mbstring扩展未安装，请安装mbstring扩展'
        ];

        // 检查GD库（图片处理）
        $checks['gd'] = [
            'name' => 'GD库',
            'current' => extension_loaded('gd') ? '已安装' : '未安装',
            'required' => '推荐',
            'status' => extension_loaded('gd'),
            'message' => extension_loaded('gd') 
                ? 'GD库已加载' 
                : 'GD库未安装（可选，用于图片处理）'
        ];

        // 检查文件权限
        $rootPath = dirname(__DIR__, 2);
        $writableDirs = [
            'avatars' => $rootPath . '/avatars',
            'uploads' => $rootPath . '/uploads',
            'config' => $rootPath . '/config'
        ];

        foreach ($writableDirs as $key => $dir) {
            $isWritable = is_writable($dir) || (!file_exists($dir) && is_writable(dirname($dir)));
            $checks['writable_' . $key] = [
                'name' => ucfirst($key) . '目录权限',
                'current' => $isWritable ? '可写' : '不可写',
                'required' => '必须',
                'status' => $isWritable,
                'message' => $isWritable 
                    ? ucfirst($key) . '目录可写' 
                    : ucfirst($key) . '目录不可写，请设置写权限'
            ];
        }

        return $checks;
    }

    /**
     * 检查所有环境要求是否满足
     * @param array $checks
     * @return bool
     */
    public static function allPassed($checks) {
        foreach ($checks as $check) {
            if ($check['required'] === '必须' && !$check['status']) {
                return false;
            }
        }
        return true;
    }

    /**
     * 获取系统信息
     * @return array
     */
    public static function getSystemInfo() {
        return [
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'os' => PHP_OS,
            'server_time' => date('Y-m-d H:i:s'),
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)
        ];
    }
}
