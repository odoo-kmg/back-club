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
}