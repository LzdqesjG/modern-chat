<?php
/**
 * 删除安装文件
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 定义根目录
$rootDir = dirname(__DIR__);

// 需要删除的文件和目录列表（仅删除真正的安装文件，保留聊天室正常运行所需文件）
$filesToDelete = [
    $rootDir . '/install.php',
    $rootDir . '/db.sql',
    $rootDir . '/lock',
    $rootDir . '/.lock',
    $rootDir . '/add_all_user_group_field.sql',
    $rootDir . '/create_ban_table.sql',
    $rootDir . '/create_group_invitation_tables.sql',
    $rootDir . '/create_ip_registration_table.sql',
    $rootDir . '/install/delete_install_files.php',
    $rootDir . '/install/register.php',
    $rootDir . '/install/register_process.php',
    $rootDir . '/install/install_api.php',
    $rootDir . '/install/utils/Common.php',
    $rootDir . '/install/utils/Database.php',
    $rootDir . '/install/utils/Environment.php',
    $rootDir . '/install/utils/mysql_errors.php',
    $rootDir . '/install/README.md',
    $rootDir . '/install/TESTING.md'
];

// 需要删除的目录
$dirsToDelete = [
    $rootDir . '/install/utils',
    $rootDir . '/install'
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
$deletedFiles = [];
foreach ($filesToDelete as $file) {
    if (file_exists($file)) {
        if (@unlink($file)) {
            $deletedFiles[] = $file;
        }
    }
}

$deletedDirs = [];
foreach ($dirsToDelete as $dir) {
    if (file_exists($dir)) {
        if (deleteDirectory($dir)) {
            $deletedDirs[] = $dir;
        }
    }
}

// 返回JSON响应
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => '安装文件已清除',
    'deleted_files' => $deletedFiles,
    'deleted_dirs' => $deletedDirs
]);
