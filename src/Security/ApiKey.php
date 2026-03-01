<?php
declare(strict_types=1);

namespace App\Security;

use App\Http\Request;
use App\Http\HttpException;

final class ApiKey
{
  /**
   * Validates X-API-Key for machine-to-machine endpoints (e.g. Botmaker).
   * Do NOT use this for browser UI, since it would be exposed.
   */
  public static function requireKey(array $config, Request $req, string $keyName = 'default'): void
  {
    $hdr = $req->header('x-api-key');
    if (!$hdr) {
      throw new HttpException(401, 'UNAUTHORIZED', 'Falta X-API-Key');
    }

    $cfg = $config['api_keys'] ?? [];
    $expected = (string)($cfg[$keyName] ?? '');
    if ($expected === '' || !hash_equals($expected, trim($hdr))) {
      throw new HttpException(401, 'UNAUTHORIZED', 'X-API-Key inválida');
    }
  }
}
