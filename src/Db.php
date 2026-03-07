<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
  public static function pdo(array $config): PDO
  {
    $db = $config['db'];
    $dsn = sprintf(
      'mysql:host=%s;dbname=%s;charset=%s',
      $db['host'],
      $db['name'],
      $db['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("SET time_zone = '-04:00'");

    return $pdo;
  }
}