<?php

declare(strict_types=1);

namespace App;

use Predis\ClientInterface;

final class DedupService
{
    public function __construct(
        private ClientInterface $redis,
        private int $ttlSeconds = 86400,
    ) {
    }

    public static function key(string $clickId, string $event, string $normalizedAmount): string
    {
        return 'postback:dedup:' . hash('sha256', implode("\0", [$clickId, $event, $normalizedAmount]));
    }

    /**
     * Atomic "first one wins": SET key 1 EX <ttl> NX.
     * Returns true if the key was created (event is new), false if it already existed (duplicate).
     */
    public function claim(string $key): bool
    {
        return $this->redis->set($key, '1', 'EX', $this->ttlSeconds, 'NX') !== null;
    }

    public function release(string $key): void
    {
        $this->redis->del([$key]);
    }
}
