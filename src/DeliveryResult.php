<?php

declare(strict_types=1);

namespace App;

final class DeliveryResult
{
    /**
     * @param array<int, array{no:int, http_status:?int, error:?string, duration_ms:int}> $attempts
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $attempts,
    ) {
    }

    public function lastHttpStatus(): ?int
    {
        return $this->attempts ? end($this->attempts)['http_status'] : null;
    }

    public function lastError(): ?string
    {
        return $this->attempts ? end($this->attempts)['error'] : null;
    }
}
