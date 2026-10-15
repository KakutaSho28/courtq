<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Service 層が業務ルール違反を伝えるための例外。
 * Handler が `{ "message": ... }` と $status で JSON 応答に変換する。
 */
class ServiceException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function forbidden(string $message = 'この操作は許可されていません'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message): self
    {
        return new self($message, 404);
    }
}
