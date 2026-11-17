<?php

require_once __DIR__ . '/../api/doubao/video/DoubaoParser.php';

class StubDoubaoParser extends DoubaoParser
{
    public $nativeResult = null;
    public $fallbackResult = null;
    public $fallbackCalls = 0;
    public $fallbackUrl = '';

    protected function getRealUrl($url)
    {
        return $url;
    }

    protected function requestApi($params)
    {
        return $this->nativeResult;
    }

    protected function requestFallbackApi($url)
    {
        $this->fallbackCalls++;
        $this->fallbackUrl = $url;
        return $this->fallbackResult;
    }
}

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$shareUrl = 'https://www.doubao.com/video-sharing?share_id=123&video_id=example';
$cleanUrl = 'https://media.example.com/original.mp4?lr=unwatermarked';
$markedUrl = 'https://media.example.com/shared.mp4?lr=video_gen_watermark_dyn';
$fallback = ['code' => 200, 'data' => [
    'url' => $cleanUrl, 'title' => '原片', 'author' => ['name' => '作者'], 'duration' => 10.08,
]];

$parser = new StubDoubaoParser();
$parser->nativeResult = ['code' => 0, 'data' => ['play_info' => [
    'main' => $markedUrl, 'backup' => $markedUrl, 'definition' => '720p',
]]];
$parser->fallbackResult = $fallback;
$result = json_decode($parser->parse($shareUrl), true);
check($result['code'] === 200, '水印分享视频必须回退到可用原片');
check($result['data']['url'] === $cleanUrl, '下载直链必须指向回退返回的原片');
check(strpos(json_encode($result), 'video_gen_watermark') === false, '成功结果不能带回已知水印地址');
check($parser->fallbackCalls === 1, '水印资源必须触发一次回退');
check($result['data']['duration'] === 10.08, '保留新版接口元数据');
parse_str(parse_url($parser->fallbackUrl, PHP_URL_QUERY), $forwarded);
check($forwarded['share_id'] === '123' && $forwarded['video_id'] === 'example', '回退必须解析同一分享视频');

$parser = new StubDoubaoParser();
$parser->nativeResult = ['code' => 0, 'data' => ['prompt' => '测试标题', 'play_info' => [
    'main' => $cleanUrl, 'backup' => $markedUrl, 'definition' => '720p',
]]];
$result = json_decode($parser->parse($shareUrl), true);
check($result['code'] === 200 && $result['data']['url'] === $cleanUrl, '原生 play_info.main 必须映射到 data.url');
check($result['data']['title'] === '测试标题', '原生 prompt 必须映射到标题');
check($result['data']['play_info']['backup'] === '', '移除水印备份地址');
check($parser->fallbackCalls === 0, '原生直链可用时无需回退');

foreach ([$markedUrl, 'https://media.example.com/shared.mp4?lr=video%5Fgen%5Fwatermark', 'javascript:alert(1)', '', null, []] as $invalidUrl) {
    $parser = new StubDoubaoParser();
    $parser->nativeResult = ['code' => 0, 'data' => ['play_info' => ['main' => $markedUrl]]];
    $parser->fallbackResult = ['code' => 200, 'data' => ['url' => $invalidUrl]];
    $result = json_decode($parser->parse($shareUrl), true);
    check($result['code'] === 502 && $result['data'] === [], '水印或无效的回退结果不能报成功');
}

foreach ([null, ['code' => 500], ['code' => 0, 'data' => []], ['code' => 0, 'data' => 'invalid']] as $nativeResult) {
    $parser = new StubDoubaoParser();
    $parser->nativeResult = $nativeResult;
    $parser->fallbackResult = $fallback;
    $result = json_decode($parser->parse($shareUrl), true);
    check($result['code'] === 200 && $result['data']['url'] === $cleanUrl, '原生接口异常或直链缺失时使用回退');
}

$parser = new StubDoubaoParser();
$result = json_decode($parser->parse($shareUrl), true);
check($result['code'] === 502 && $result['data'] === [], '两条路径均失败时返回明确错误');
$result = json_decode($parser->parse('https://www.doubao.com/video-sharing'), true);
check($result['code'] === 400, '缺少视频参数时仍返回输入错误');

echo "Doubao parser regression checks passed.\n";
