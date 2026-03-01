<?php
declare(strict_types=1);

namespace App\Http;

final class HttpException extends \RuntimeException
{
  public int $status;
  public string $errorCode;

  public function __construct(int $status, string $errorCode, string $message, ?\Throwable $previous = null)
  {
    // Keep Exception::$code numeric; use errorCode for API consumers.
    parent::__construct($message, 0, $previous);
    $this->status = $status;
    $this->errorCode = $errorCode;
  }
}