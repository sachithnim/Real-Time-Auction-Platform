<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * A domain error that is safe to show to the client.
 * Thrown from services; rendered as { "error": { code, message, details } }.
 */
class ApiException extends Exception
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }

    public function render(): JsonResponse
    {
        return ApiExceptionRenderer::response($this->errorCode, $this->getMessage(), $this->details);
    }
}
