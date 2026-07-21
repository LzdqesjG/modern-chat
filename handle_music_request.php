<?php
// 处理点歌请求的后端脚本
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
check_api_access();
require_once 'db.php';

header('Content-Type: application/json');

try {
    // 检查用户是否登录
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '用户未登录']);
        exit;
    }
    
    // 检查是否是POST请求
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => '无效的请求方法']);
        exit;
    }
    
    // 获取请求数据
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    $group_id = $data['group_id'] ?? 0;
    $message = $data['message'] ?? '';
    $page = $data['page'] ?? 1;
    $song_name = $data['song_name'] ?? '';
    $choice = $data['choice'] ?? '';
    
    // 检查是否是点歌群聊
    $stmt = $conn->prepare("SELECT Music_all_group FROM `groups` WHERE id = ?");
    $stmt->execute([$group_id]);
    $group = $stmt->fetch();
    
    if (!$group || $group['Music_all_group'] != 1) {
        echo json_encode(['success' => false, 'message' => '只有点歌群聊可以使用点歌功能']);
        exit;
    }
    
    // 检查时间限制
    $current_hour = date('H');
    if ($current_hour <  8|| $current_hour >= 22) {
        echo json_encode(['success' => false, 'message' => '点歌功能仅在中午12点到晚上8点开放']);
        exit;
    }
    
    // 处理不同的操作
    switch ($action) {
        case 'search_song':
            // 处理点歌搜索
            if (empty($message)) {
                echo json_encode(['success' => false, 'message' => '请输入歌曲名称']);
                exit;
            }
            
            // 提取歌曲名称（去掉"点歌："前缀）
            $song_name = preg_replace('/^点歌：/i', '', $message);
            if (empty($song_name)) {
                echo json_encode(['success' => false, 'message' => '请输入歌曲名称']);
                exit;
            }
            
            // 调用API搜索歌曲
            $api_url = "https://api.vkeys.cn/v2/music/tencent?word=" . urlencode($song_name) . "&page=" . $page;
            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            curl_close($ch);
            
            if (!$response) {
                echo json_encode(['success' => false, 'message' => 'API调用失败，请稍后重试']);
                exit;
            }
            
            $api_data = json_decode($response, true);
            if (!$api_data || !isset($api_data['data'])) {
                echo json_encode(['success' => false, 'message' => 'API返回数据格式错误']);
                exit;
            }
            
            // 处理返回的数据
            $songs = $api_data['data'];
            $results = [];
            $start_index = ($page - 1) * count($songs) + 1;
            
            foreach ($songs as $index => $song) {
                $results[] = [
                    'index' => $start_index + $index,
                    'song' => $song['song'] ?? '',
                    'singer' => $song['singer'] ?? ''
                ];
            }
            
            echo json_encode([
                'success' => true,
                'action' => 'search_song',
                'song_name' => $song_name,
                'page' => $page,
                'results' => $results
            ]);
            break;
            
        case 'next_page':
            // 处理翻页
            if (empty($song_name)) {
                echo json_encode(['success' => false, 'message' => '缺少歌曲名称']);
                exit;
            }
            
            $next_page = $page + 1;
            
            // 调用API获取下一页数据
            $api_url = "https://api.vkeys.cn/v2/music/tencent?word=" . urlencode($song_name) . "&page=" . $next_page;
            $ch = curl_init($api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            curl_close($ch);
            
            if (!$response) {
                echo json_encode(['success' => false, 'message' => 'API调用失败，请稍后重试']);
                exit;
            }
            
            $api_data = json_decode($response, true);
            if (!$api_data || !isset($api_data['data'])) {
                echo json_encode(['success' => false, 'message' => 'API返回数据格式错误']);
                exit;
            }
            
            // 处理返回的数据
            $songs = $api_data['data'];
            $results = [];
            $start_index = ($next_page - 1) * count($songs) + 1;
            
            foreach ($songs as $index => $song) {
                $results[] = [
                    'index' => $start_index + $index,
                    'song' => $song['song'] ?? '',
                    'singer' => $song['singer'] ?? ''
                ];
            }
            
            echo json_encode([
                'success' => true,
                'action' => 'next_page',
                'song_name' => $song_name,
                'page' => $next_page,
                'results' => $results
            ]);
            break;
            
        case 'select_song':
            // 处理歌曲选择
            if (empty($song_name) || empty($choice)) {
                echo json_encode(['success' => false, 'message' => '缺少歌曲名称或选择']);
                exit;
            }
            
            // 确保temp_song_config.json文件存在
            $config_file = 'config/temp_song_config.json';
            if (!file_exists($config_file)) {
                $default_config = [
                    '歌单' => [
                        'type' => 'qqmusic',
                        'data' => [
                            [
                                '请查收关心' => '1'
                            ],
                            [
                                '星之映画' => '1'
                            ]
                        ]
                    ]
                ];
                file_put_contents($config_file, json_encode($default_config, JSON_PRETTY_PRINT));
            }
            
            // 读取配置文件
            $config = json_decode(file_get_contents($config_file), true);
            
            // 添加歌曲到配置文件
            $new_song = [
                $song_name => $choice
            ];
            $config['歌单']['data'][] = $new_song;
            
            // 保存配置文件
            file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
            
            echo json_encode([
                'success' => true,
                'action' => 'select_song',
                'message' => "已添加歌曲: $song_name - 选择: $choice"
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => '无效的操作']);
            break;
            
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '服务器内部错误: ' . $e->getMessage()]);
}
?>