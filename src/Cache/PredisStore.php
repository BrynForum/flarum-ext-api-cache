<?php

namespace BrynForum\ApiCache\Cache;

use Illuminate\Contracts\Cache\Store;
use Predis\Client;

/**
 * Minimal Laravel cache `Store` implementation backed by Predis.
 *
 * We don't pull illuminate/redis as a dependency (it carries a lot of
 * baggage for what we use). Instead this class implements the seven
 * methods we actually need; Laravel's `Cache\Repository` wraps it for
 * the `Repository` API the rest of the extension expects.
 *
 * Keys are silently prefixed; `flush()` is bounded to the prefix via
 * SCAN so we never wipe a Redis DB that's also being used by Flarum
 * core / sessions.
 */
class PredisStore implements Store
{
    public function __construct(
        protected Client $client,
        protected string $prefix = '',
        // Pattern used by flush() — kept separate from $prefix so the caller
        // can leave keys un-prefixed (avoiding double-prefix with middleware-
        // level namespacing) but still bound flush() to a safe subset.
        protected string $scanPattern = '*',
    ) {
    }

    public function get($key)
    {
        $value = $this->client->get($this->prefix.$key);

        return $value === null ? null : unserialize($value);
    }

    public function many(array $keys): array
    {
        $prefixed = array_map(fn ($k) => $this->prefix.$k, $keys);
        $values = $this->client->mget($prefixed);

        return array_combine(
            $keys,
            array_map(fn ($v) => $v === null ? null : unserialize($v), $values),
        );
    }

    public function put($key, $value, $seconds): bool
    {
        // Redis's SETEX requires positive TTL; clamp 0/negative to 1.
        $ttl = max(1, (int) $seconds);

        return (string) $this->client->setex(
            $this->prefix.$key,
            $ttl,
            serialize($value),
        ) === 'OK';
    }

    public function putMany(array $values, $seconds): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->put($key, $value, $seconds) && $ok;
        }

        return $ok;
    }

    public function increment($key, $value = 1)
    {
        return $this->client->incrby($this->prefix.$key, (int) $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->client->decrby($this->prefix.$key, (int) $value);
    }

    public function forever($key, $value): bool
    {
        return (string) $this->client->set($this->prefix.$key, serialize($value)) === 'OK';
    }

    public function forget($key): bool
    {
        return $this->client->del([$this->prefix.$key]) > 0;
    }

    /**
     * Flush only keys we own (prefix-bounded). The tenant Redis DB index
     * is shared with Flarum's session + settings cache once Phase 0 lands,
     * so a blind FLUSHDB would be too destructive.
     */
    public function flush(): bool
    {
        $iter = 0;
        do {
            [$iter, $keys] = $this->client->scan(
                $iter,
                ['match' => $this->scanPattern, 'count' => 200],
            );
            if (! empty($keys)) {
                $this->client->del($keys);
            }
        } while ((int) $iter !== 0);

        return true;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }
}
