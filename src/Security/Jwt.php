<?php
declare(strict_types=1);

namespace App\Security;

final class Jwt
{
  public static function encode(array $payload, string $secret): string
  {
    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $h = self::b64url(json_encode($header, JSON_UNESCAPED_UNICODE));
    $p = self::b64url(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $s = self::sign("{$h}.{$p}", $secret);
    return "{$h}.{$p}.{$s}";
  }

  public static function decodeAndVerify(string $token, string $secret): array
  {
    $parts = explode('.', $token);
    if (count($parts) !== 3) throw new \RuntimeException('Invalid token');

    [$h, $p, $sig] = $parts;
    $expected = self::sign("{$h}.{$p}", $secret);

    if (!hash_equals($expected, $sig)) throw new \RuntimeException('Invalid signature');

    $payloadJson = self::b64urlDecode($p);
    $payload = json_decode($payloadJson, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
      throw new \RuntimeException('Invalid payload');
    }
    return $payload;
  }

  private static function sign(string $data, string $secret): string
  {
    $raw = hash_hmac('sha256', $data, $secret, true);
    return self::b64url($raw);
  }

  private static function b64url(string $data): string
  {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  private static function b64urlDecode(string $data): string
  {
    $r = strlen($data) % 4;
    if ($r) $data .= str_repeat('=', 4 - $r);
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    if ($decoded === false) throw new \RuntimeException('Invalid base64');
    return $decoded;
  }
}