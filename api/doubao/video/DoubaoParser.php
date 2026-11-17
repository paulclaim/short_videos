<?php
/**
*@Author: JH-Ahua
*@CreateTime: 2026/4/24 17:17
*@email: admin@bugpk.com
*@blog: www.jiuhunwl.cn
*@Api: api.bugpk.com
*@tip: 豆包ai视频无水印解析（非对话生成的视频）-实现类
*/
class DoubaoParser
{
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';
    private $cookie = 'i18next=zh-CN; ttwid=1%7CFGYNcsGXqieXVBP4QNemEXTZLZoArf0NEPXt1WhSmm8%7C1772615343%7Cc6580d4acaa52df7479b610305cc558176ba8dfcdd55bf8d9ce6fe86d3948009';
    private $apiUrl = 'https://www.doubao.com/creativity/share/get_video_share_info?version_code=20800&language=zh-CN&device_platform=web&aid=497858&real_aid=497858&pkg_type=release_version&device_id=&pc_version=3.8.3&region=&sys_region=&samantha_web=1&use-olympus-account=1&web_tab_id=4d5d17a6-6729-4c1e-9f55-09f093100f0a';
    // 项目作者维护的公开新版接口；原生分享接口目前可能只返回水印转码。
    private $fallbackApiUrl = 'https://api.bugpk.com/api/dbvideos';

    public function parse($url)
    {
        if (empty($url)) {
            return $this->output(400, '请输入豆包视频链接');
        }

        $realUrl = $this->getRealUrl($url);
        
        $params = $this->extractParams($realUrl);
        
        if (empty($params['share_id']) || empty($params['video_id'])) {
            return $this->output(400, '无法从链接中提取必要参数');
        }

        $result = $this->requestApi($params);
        
        if (is_array($result) && ($result['code'] ?? null) === 0) {
            $data = $this->normalizeVideoData($result['data'] ?? [], $params);
            if ($data !== null) {
                return $this->output(200, '解析成功', $data);
            }
        }

        // 不可删除 lr/logo_type 后继续使用：水印已烧录在该 CDN 资源中。
        $fallback = $this->requestFallbackApi($this->buildReferer($params));
        if (is_array($fallback) && ($fallback['code'] ?? null) === 200) {
            $data = $this->normalizeVideoData($fallback['data'] ?? [], $params);
            if ($data !== null) {
                return $this->output(200, '解析成功', $data);
            }
        }

        return $this->output(502, '豆包暂未返回可用的无水印视频，请稍后重试');
    }

    private function normalizeVideoData($data, $params)
    {
        if (!is_array($data)) {
            return null;
        }

        $candidates = [
            $data['url'] ?? null,
            $data['video_url'] ?? null,
            $data['play_url'] ?? null,
            $data['video_play_url'] ?? null,
            $data['video']['url'] ?? null,
            $data['video']['play_addr'] ?? null,
            $data['play_info']['main'] ?? null,
            $data['play_info']['backup'] ?? null,
        ];
        $urls = [];
        foreach ($candidates as $candidate) {
            if ($this->isUsableVideoUrl($candidate)) {
                $urls[] = $candidate;
            }
        }
        $urls = array_values(array_unique($urls));
        if ($urls === []) {
            return null;
        }

        $playInfo = is_array($data['play_info'] ?? null) ? $data['play_info'] : [];
        $title = $data['title'] ?? $data['desc'] ?? $data['prompt'] ?? '';
        $normalized = [
            'type' => 'video',
            'title' => $title,
            'desc' => $data['desc'] ?? $title,
            'author' => $data['author'] ?? [
                'name' => $data['user_info']['nickname'] ?? '',
                'id' => (string)($data['user_info']['user_id'] ?? ''),
            ],
            'cover' => $data['cover'] ?? $playInfo['poster_url'] ?? '',
            'url' => $urls[0],
            'quality' => $data['quality'] ?? $playInfo['definition'] ?? '',
            'duration' => $data['duration'] ?? null,
            'extra' => array_merge(is_array($data['extra'] ?? null) ? $data['extra'] : [], [
                'share_id' => $params['share_id'],
                'vid' => $params['video_id'],
            ]),
        ];
        // 保留原生调用方的播放结构，但不暴露已识别的水印备份地址。
        if ($playInfo !== []) {
            $playInfo['main'] = $urls[0];
            $playInfo['backup'] = $urls[1] ?? '';
            $normalized['play_info'] = $playInfo;
        }
        return $normalized;
    }

    private function isUsableVideoUrl($url)
    {
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return false;
        }
        $decoded = strtolower(rawurldecode(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return strpos($decoded, 'video_gen_watermark') === false
            && strpos($decoded, 'watermark_dyn') === false;
    }

    protected function getRealUrl($url)
    {
        $params = $this->extractParams($url);
        if (!empty($params['share_id']) && !empty($params['video_id'])) {
            return $url;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        
        curl_exec($ch);
        $realUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        
        return $realUrl ?: $url;
    }

    private function extractParams($url)
    {
        $parsed = parse_url($url);
        $params = [];
        
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
        }
        
        return $params;
    }

    protected function requestApi($params)
    {
        $postData = [
            'share_id' => $params['share_id'] ?? '',
            'vid' => $params['video_id'] ?? '',
            'creation_id' => ''
        ];

        $headers = [
            'accept: application/json, text/plain, */*',
            'accept-language: zh-CN,zh;q=0.9',
            'agw-js-conv: str',
            'cache-control: no-cache',
            'content-type: application/json',
            'origin: https://www.doubao.com',
            'pragma: no-cache',
            'priority: u=1, i',
            'referer: ' . $this->buildReferer($params),
            'sec-ch-ua: "Not:A-Brand";v="99", "Google Chrome";v="145", "Chromium";v="145"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-origin',
            'user-agent: ' . $this->userAgent,
            'x-tt-logid;'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return null;
        }
        
        return json_decode($response, true);
    }

    protected function requestFallbackApi($url)
    {
        $ch = curl_init($this->fallbackApiUrl . '?' . http_build_query(['url' => $url]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false || $status !== 200) {
            return null;
        }
        return json_decode($response, true);
    }

    private function buildReferer($params)
    {
        return 'https://www.doubao.com/video-sharing?share_id=' . 
               ($params['share_id'] ?? '') . 
               '&source_type=mobile&video_id=' . ($params['video_id'] ?? '') . 
               '&share_scene=video_viewer';
    }

    private function output($code, $msg, $data = [])
    {
        return json_encode([
            'code' => $code,
            'msg' => $msg,
            'data' => $data
        ], 480);
    }
}
