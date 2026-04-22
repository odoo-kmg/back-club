<?php
declare(strict_types=1);

namespace App\Security;

final class Headers
{
  public static function applySecurityHeaders(): void
  {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
  }

  public static function applyNoCacheHeaders(): void
  {
    header_remove('ETag');
    header_remove('Last-Modified');

    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0, s-maxage=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Surrogate-Control: no-store');
    header('Vary: Authorization, Accept-Encoding, Origin');
  }
}