<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Config;

$config = Config::load(__DIR__ . '/../config');
$token = $_GET['token'] ?? '';
$pass  = $_GET['p'] ?? '';

if ($token === '' || $token !== ($config['tools']['hash_token'] ?? '')) {
  http_response_code(403);
  echo "Forbidden\n";
  exit;
}
if ($pass === '') {
  http_response_code(400);
  echo "Missing p\n";
  exit;
}

$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
echo password_hash($pass, $algo), "\n";