<?php
declare(strict_types=1);

namespace App;

final class Config
{
  public static function load(string $configDir): array
  {
    $file = rtrim($configDir, '/') . '/app.php';
    if (!file_exists($file)) throw new \RuntimeException("Config not found: {$file}");
    $cfg = require $file;
    if (!is_array($cfg)) throw new \RuntimeException("Invalid config: {$file}");
    return $cfg;
  }
}