<?php
declare(strict_types=1);

namespace App\Security;

final class Cors
{
  public static function handle(array $config): void
  {
    $cors = $config['cors'] ?? [];
    $allowed = $cors['allowed_origins'] ?? [];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

    if ($origin && is_array($allowed) && count($allowed) > 0) {
      if (in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Vary: Origin');
      }
    }

    header('Access-Control-Allow-Headers: ' . implode(', ', $cors['allowed_headers'] ?? ['Content-Type', 'Authorization']));
    header('Access-Control-Allow-Methods: ' . implode(', ', $cors['allowed_methods'] ?? ['GET', 'POST', 'PATCH', 'OPTIONS']));

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
      http_response_code(204);
      exit;
    }
  }
}