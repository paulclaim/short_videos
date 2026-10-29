<?php
/**
 * 短视频解析主页入口
 */
if (file_exists(__DIR__ . '/index.html')) {
    readfile(__DIR__ . '/index.html');
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'code' => 200,
    'msg' => '短视频去水印解析服务运行中',
    'api' => '/api/sv.php?url='
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
