<?php
declare(strict_types=1);

namespace App\Http;

final class HttpException extends \RuntimeException
{
  public int $status;
  public string $code;

  public function __construct(int $status, string $code, string $message)
  {
    parent::__construct($message);
    $this->status = $status;
    $this->code = $code;
  }
}