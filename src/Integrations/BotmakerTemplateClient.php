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

  /** @param array<string,mixed> $payload @return array{ok:bool,statusCode:int,responseBody:string,errorMessage:?string} */
  public function sendTemplate(array $payload): array
  {
    $timeout = (int)($this->cfg['timeout_seconds'] ?? 10);
    if ($timeout <= 0) $timeout = 10;

    $accessToken = trim((string)($this->cfg['access_token'] ?? ''));
    if ($accessToken === '') {
      return ['ok' => false, 'statusCode' => 0, 'responseBody' => '', 'errorMessage' => 'BOTMAKER_ACCESS_TOKEN_EMPTY'];
    }

    $chatChannelNumber = preg_replace('/\D+/', '', (string)($this->cfg['chat_channel_number'] ?? ''));
    if ($chatChannelNumber === '') {
      return ['ok' => false, 'statusCode' => 0, 'responseBody' => '', 'errorMessage' => 'BOTMAKER_CHANNEL_EMPTY'];
    }

    $platformContactId = preg_replace('/\D+/', '', (string)($payload['phoneE164'] ?? ''));
    $ruleNameOrId = trim((string)($payload['templateName'] ?? ''));
    $params = (array)($payload['namedVariables'] ?? []);
    $clientPayload = isset($this->cfg['client_payload']) ? (string)$this->cfg['client_payload'] : 'string';

    if ($platformContactId === '') {
      return ['ok' => false, 'statusCode' => 0, 'responseBody' => '', 'errorMessage' => 'BOTMAKER_DESTINATION_EMPTY'];
    }
    if ($ruleNameOrId === '') {
      return ['ok' => false, 'statusCode' => 0, 'responseBody' => '', 'errorMessage' => 'BOTMAKER_TEMPLATE_EMPTY'];
    }

    $requestBody = [
      'chatPlatform' => 'whatsapp',
      'chatChannelNumber' => $chatChannelNumber,
      'platformContactId' => $platformContactId,
      'ruleNameOrId' => $ruleNameOrId,
      'clientPayload' => $clientPayload,
      'params' => $params,
    ];

    $json = json_encode($requestBody, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
      return ['ok' => false, 'statusCode' => 0, 'responseBody' => '', 'errorMessage' => 'PAYLOAD_JSON_ERROR'];
    }

    $ch = curl_init('https://go.botmaker.com/api/v1.0/intent/v2');
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'access-token: ' . $accessToken,
      ],
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
