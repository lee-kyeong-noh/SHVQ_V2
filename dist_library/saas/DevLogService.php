<?php
declare(strict_types=1);

final class DevLogService
{
    public static function tryLog(string $category, string $title, string $content, int $fileCount = 1): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        $url = shvEnv('DEVLOG_API_URL', 'http://211.116.112.67/SHVQ/dist_process/DevLog.php');
        $post = [
            'todo' => 'insert',
            'system_type' => 'V2',
            'category' => $category,
            'title' => $title,
            'content' => $content,
            'status' => '1',
            'dev_time' => date('Y-m-d H:i:s'),
            'file_count' => (string)max(1, $fileCount),
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $errno === 0 && $status >= 200 && $status < 500;
    }
}
