<?php
declare(strict_types=1);

final class TGGateway
{
    public function __construct(private string $baseUrl, private string $apiKey, private int $timeout = 10)
    {
        if (!str_starts_with($baseUrl, 'https://')) throw new InvalidArgumentException('baseUrl must use HTTPS');
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['X-Api-Key: ' . $this->apiKey, 'Accept: application/json'];
        $opts = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'timeout' => $this->timeout, 'ignore_errors' => true]];
        if ($body !== null) { $headers[] = 'Content-Type: application/json'; $opts['http']['header'] = implode("\r\n", $headers); $opts['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR); }
        $response = file_get_contents($url, false, stream_context_create($opts));
        $status = 0; foreach ($http_response_header ?? [] as $line) if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) $status = (int)$m[1];
        $data = json_decode((string)$response, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) throw new RuntimeException('TG Gateways request failed (HTTP ' . $status . ')');
        return $data;
    }
    public function createOrder(float $amount, array $options = []): array { return $this->request('POST', '/api/create-order.php', [], ['amount' => $amount] + $options); }
    public function verifyOrder(string $orderId): array { return $this->request('GET', '/api/verify-order.php', ['order_id' => $orderId]); }
}
