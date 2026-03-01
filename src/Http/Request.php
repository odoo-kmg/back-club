<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
  private string $method;
  private string $path;
  private array $headers;
  private ?array $jsonBody;
  private array $query;

  private function __construct(string $method, string $path, array $headers, ?array $jsonBody, array $query)
  {
    $this->method = strtoupper($method);
    $this->path = $path;
    $this->headers = $headers;
    $this->jsonBody = $jsonBody;
    $this->query = $query;
  }

  public static function fromGlobals(array $config): self
  {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $basePath = rtrim((string)($config['app']['base_path'] ?? ''), '/');

    if ($basePath !== '' && substr($uriPath, 0, strlen($basePath)) === $basePath) {
      $uriPath = substr($uriPath, strlen($basePath));
      if ($uriPath === '') $uriPath = '/';
    }

    $headers = self::headersLower();
    $jsonBody = null;

    if (in_array(strtoupper($method), ['POST', 'PATCH', 'PUT'], true)) {
      $raw = file_get_contents('php://input');
      if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
          $jsonBody = $decoded;
        }
      }
    }

    $query = is_array($_GET ?? null) ? $_GET : [];

    return new self($method, $uriPath, $headers, $jsonBody, $query);
  }

  public function method(): string { return $this->method; }
  public function path(): string { return $this->path; }

  public function header(string $name): ?string
  {
    $key = strtolower($name);
    return $this->headers[$key] ?? null;
  }

  public function json(): ?array { return $this->jsonBody; }

  public function query(string $key, $default = null)
  {
    if (!array_key_exists($key, $this->query)) return $default;
    return $this->query[$key];
  }

  private static function headersLower(): array
  {
    $out = [];

    if (function_exists('getallheaders')) {
      $h = getallheaders();
      if (is_array($h)) {
        foreach ($h as $k => $v) {
          $out[strtolower($k)] = (string)$v;
        }
      }
    }

    foreach ($_SERVER as $k => $v) {
      if (strpos($k, 'HTTP_') === 0) {
        $name = strtolower(str_replace('_', '-', substr($k, 5)));
        $out[$name] = (string)$v;
      }
    }

    if (isset($_SERVER['CONTENT_TYPE'])) $out['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
    if (isset($_SERVER['AUTHORIZATION'])) $out['authorization'] = (string)$_SERVER['AUTHORIZATION'];

    return $out;
  }
}
