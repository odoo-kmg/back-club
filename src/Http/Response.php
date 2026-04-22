<?php
declare(strict_types=1);

namespace App\Http;

use App\Security\Headers;

final class Response
{
  public function json(int $status, array $payload): void
  {
    Headers::applyNoCacheHeaders();

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  }
}