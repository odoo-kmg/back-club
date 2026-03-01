<?php
declare(strict_types=1);

namespace App\Security;

final class AuthContext
{
  public int $userId;
  public string $username;
  public string $fullName;
  /** @var string[] */
  public array $roles;
  /** @var string[] */
  public array $permissions;

  public function __construct(int $userId, string $username, string $fullName, array $roles, array $permissions)
  {
    $this->userId = $userId;
    $this->username = $username;
    $this->fullName = $fullName;
    $this->roles = $roles;
    $this->permissions = $permissions;
  }

  public function hasPermission(string $code): bool
  {
    return in_array($code, $this->permissions, true);
  }
}