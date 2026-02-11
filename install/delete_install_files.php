<?php
/**
 * 删除安装文件
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 定义根目录
$rootDir = dirname(__DIR__);

// 需要删除的文件和目录列表
$filesToDelete = [
    $rootDir . '/install.php',
    $rootDir . '/db.sql',
    $rootDir . '/lock', // 删除部署锁文件
    $rootDir . '/.lock', // 删除旧的部署锁文件
    $rootDir . '/install/delete_install_files.php', // 删除自己
    $rootDir . '/help/index.php',
    $rootDir . '/Readme.md',
    $rootDir . '/.gitignore',
    $rootDir . '/.env',
    $rootDir . '/LICENSE',
    $rootDir . '/install/register.php',
    $rootDir . '/install/register_process.php',
    $rootDir . '/install/utils/mysql_error.php',
    $rootDir . '/test.php'
];

// 需要删除的目录（必须为空才能删除）
// 我们先尝试删除目录中的所有文件，然后删除目录
$dirsToDelete = [
    $rootDir . '/install/utils',
    $rootDir . '/install',
    $rootDir . '/help'
];

// 递归删除目录函数
function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }

    if (!is_dir($dir)) {
        return unlink($dir);
    }

    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }

        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }

    return rmdir($dir);
}

// 执行删除
foreach ($filesToDelete as $file) {
    if (file_exists($file)) {
        @unlink($file);
    }
}

foreach ($dirsToDelete as $dir) {
    if (file_exists($dir)) {
        deleteDirectory($dir);
    }
}

// 返回JSON响应
header('Content-Type: application/json');
echo json_encode(['success' => true, 'message' => '安装文件已清除']);
