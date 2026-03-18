<?php
declare(strict_types=1);

namespace App\Integrations;

final class BotmakerTemplateClient
{
  private array $cfg;

  public function __construct(array $cfg)
  {
    $this->cfg = $cfg;
  }

  /** @return array{ok:bool,statusCode:int,responseBody:string,errorMessage:?string} */
  public function sendTemplate(array $payload): array
  {
    $endpoint = trim((string)($this->cfg['endpoint'] ?? ''));
    if ($endpoint === '') {
      return ['ok' => false, 'statusCode' => 0, 'responseBody' => '', 'errorMessage' => 'BOTMAKER_ENDPOINT_EMPTY'];
    }

    $timeout = (int)($this->cfg['timeout_seconds'] ?? 10);
    if ($timeout <= 0) $timeout = 10;

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      return ['ok' => false, 'statusCode' => 0, 'responseBody' => '', 'errorMessage' => 'PAYLOAD_JSON_ERROR'];
    }

    $headers = [
      'Content-Type: application/json',
      'Accept: application/json',
    ];

    $apiKey = trim((string)($this->cfg['api_key'] ?? ''));
    if ($apiKey !== '') {
      $headers[] = 'X-API-Key: ' . $apiKey;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POSTFIELDS => $json,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
    ]);

    $body = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
      return ['ok' => false, 'statusCode' => $statusCode, 'responseBody' => '', 'errorMessage' => $curlError !== '' ? $curlError : 'CURL_EXEC_ERROR'];
    }

    $ok = $statusCode >= 200 && $statusCode < 300;
    return [
      'ok' => $ok,
      'statusCode' => $statusCode,
      'responseBody' => (string)$body,
      'errorMessage' => $ok ? null : ('HTTP_' . $statusCode),
    ];
  }
}
