<?php

class CloudflarePro_CloudflareClient
{
    const BASE_URL = 'https://api.cloudflare.com/client/v4';

    private $apiLog;

    public function __construct(Modules_CloudflarePro_ApiLogRepository $apiLog = null)
    {
        $this->apiLog = $apiLog ?: new Modules_CloudflarePro_ApiLogRepository();
    }

    public function verifyToken($token)
    {
        return $this->request($this->assertToken($token), 'GET', '/user/tokens/verify');
    }

    private function request($token, $method, $path)
    {
        $started = microtime(true);
        $request = [
            'path' => $path,
            'headers' => [
                'Authorization' => 'Bearer [redacted]',
                'Accept' => 'application/json',
            ],
        ];

        if (!function_exists('curl_init')) {
            throw new pm_Exception('cURL is not available on this server.');
        }

        $curl = curl_init(self::BASE_URL . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if (false === $body) {
            $message = 'Cloudflare validation failed: ' . $error;
            $this->apiLog->add($path, $method, $status, false, $request, null, $durationMs, $message);
            throw new pm_Exception($message);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $message = 'Cloudflare returned an invalid validation response.';
            $this->apiLog->add($path, $method, $status, false, $request, $body, $durationMs, $message);
            throw new pm_Exception($message);
        }

        if ($status >= 200 && $status < 300 && !empty($data['success'])) {
            $this->apiLog->add($path, $method, $status, true, $request, $data, $durationMs);
            return isset($data['result']) ? $data['result'] : $data;
        }

        $message = $this->errorMessage($data, $status);
        $this->apiLog->add($path, $method, $status, false, $request, $data, $durationMs, $message);
        throw new pm_Exception($message);
    }

    public function assertToken($token)
    {
        $token = trim((string) $token);
        if (strlen($token) < 20 || strlen($token) > 2048 || preg_match('/\s/', $token)) {
            throw new pm_Exception('Invalid Cloudflare API token format.');
        }

        return $token;
    }

    private function errorMessage(array $data, $status)
    {
        $messages = array();
        if (!empty($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $error) {
                if (!empty($error['message'])) {
                    $messages[] = $error['message'];
                }
            }
        }

        if (!empty($messages)) {
            return 'Cloudflare API error ' . (int) $status . ': ' . implode('; ', $messages);
        }

        if ($status === 403 || $status === 401) {
            return 'Cloudflare token validation failed: token is invalid or unauthorized.';
        }

        return 'Cloudflare API error ' . (int) $status . ': Unknown error.';
    }
}
