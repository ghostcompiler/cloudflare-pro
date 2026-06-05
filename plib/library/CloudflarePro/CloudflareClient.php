<?php

class CloudflarePro_CloudflareClient
{
    const BASE_URL = 'https://api.cloudflare.com/client/v4';

    private $apiLog;
    private $logApiRequests = true;

    public function __construct(Modules_CloudflarePro_ApiLogRepository $apiLog = null)
    {
        $this->apiLog = $apiLog ?: new Modules_CloudflarePro_ApiLogRepository();
        $this->logApiRequests = $this->loggingEnabled();
    }

    public function verifyToken($token)
    {
        return $this->request($this->assertToken($token), 'GET', '/user/tokens/verify');
    }

    public function listZones($token)
    {
        $zones = [];
        $page = 1;

        do {
            $response = $this->request($this->assertToken($token), 'GET', '/zones', [
                'page' => $page,
                'per_page' => 50,
            ], null, true);
            foreach (isset($response['result']) && is_array($response['result']) ? $response['result'] : [] as $zone) {
                $zones[] = $zone;
            }

            $info = isset($response['result_info']) && is_array($response['result_info']) ? $response['result_info'] : [];
            $totalPages = isset($info['total_pages']) ? (int) $info['total_pages'] : $page;
            $page++;
        } while ($page <= $totalPages && $page <= 100);

        return $zones;
    }

    public function listDnsRecords($token, $zoneId)
    {
        $records = [];
        $page = 1;

        do {
            $response = $this->request($this->assertToken($token), 'GET', '/zones/' . rawurlencode($zoneId) . '/dns_records', [
                'page' => $page,
                'per_page' => 100,
            ], null, true);
            foreach (isset($response['result']) && is_array($response['result']) ? $response['result'] : [] as $record) {
                $records[] = $record;
            }

            $info = isset($response['result_info']) && is_array($response['result_info']) ? $response['result_info'] : [];
            $totalPages = isset($info['total_pages']) ? (int) $info['total_pages'] : $page;
            $page++;
        } while ($page <= $totalPages && $page <= 100);

        return $records;
    }

    public function createDnsRecord($token, $zoneId, array $record)
    {
        return $this->request($this->assertToken($token), 'POST', '/zones/' . rawurlencode($zoneId) . '/dns_records', [], $record);
    }

    public function updateDnsRecord($token, $zoneId, $recordId, array $record)
    {
        return $this->request($this->assertToken($token), 'PATCH', '/zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($recordId), [], $record);
    }

    public function deleteDnsRecord($token, $zoneId, $recordId)
    {
        try {
            return $this->request($this->assertToken($token), 'DELETE', '/zones/' . rawurlencode($zoneId) . '/dns_records/' . rawurlencode($recordId));
        } catch (pm_Exception $e) {
            if (false !== stripos($e->getMessage(), 'not found') || false !== stripos($e->getMessage(), '404')) {
                return ['id' => (string) $recordId, 'missing' => true];
            }

            throw $e;
        }
    }

    public function withoutLogging($callback)
    {
        $previous = $this->logApiRequests;
        $this->logApiRequests = false;

        try {
            return call_user_func($callback);
        } finally {
            $this->logApiRequests = $previous;
        }
    }

    private function request($token, $method, $path, array $query = [], $body = null, $returnEnvelope = false)
    {
        $started = microtime(true);
        $url = self::BASE_URL . $path;
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $request = [
            'path' => $path,
            'query' => $query,
            'body' => $body,
            'headers' => [
                'Authorization' => 'Bearer [redacted]',
                'Accept' => 'application/json',
            ],
        ];

        if (!function_exists('curl_init')) {
            throw new pm_Exception('cURL is not available on this server.');
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if (null !== $body) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if (false === $body) {
            $message = 'Cloudflare validation failed: ' . $error;
            $this->logRequest($path, $method, $status, false, $request, null, $durationMs, $message);
            throw new pm_Exception($message);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $message = 'Cloudflare returned an invalid validation response.';
            $this->logRequest($path, $method, $status, false, $request, $body, $durationMs, $message);
            throw new pm_Exception($message);
        }

        if ($status >= 200 && $status < 300 && !empty($data['success'])) {
            $this->logRequest($path, $method, $status, true, $request, $data, $durationMs);
            return $returnEnvelope ? $data : (isset($data['result']) ? $data['result'] : $data);
        }

        $message = $this->errorMessage($data, $status);
        $this->logRequest($path, $method, $status, false, $request, $data, $durationMs, $message);
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

    private function logRequest($route, $method, $statusCode, $ok, array $request = [], $response = null, $durationMs = null, $error = null)
    {
        if (!$this->logApiRequests) {
            return;
        }

        $this->apiLog->add($route, $method, $statusCode, $ok, $request, $response, $durationMs, $error);
    }

    private function loggingEnabled()
    {
        try {
            $settings = new Modules_CloudflarePro_SettingsRepository($this->apiLog->owner());
            $values = $settings->all();

            return !empty($values['log_api_requests']);
        } catch (Throwable $e) {
            return true;
        }
    }
}
