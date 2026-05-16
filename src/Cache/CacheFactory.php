<?php

namespace BrynForum\ApiCache\Cache;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Predis\Client;

/**
 * Decides which cache backend the api-cache extension uses.
 *
 * If the runtime env names a Redis host AND predis is available AND we
 * can ping the host successfully, we return a Redis-backed Repository.
 * Otherwise we hand back the Flarum default cache (file driver, normally),
 * so the extension still works on tenants without Redis configured.
 *
 * The detection result is cached on the static so we only ping once per
 * request; cheaper than checking every middleware invocation.
 */
class CacheFactory
{
    private static ?CacheRepository $instance = null;

    private static ?string $backendName = null;

    public static function reset(): void
    {
        self::$instance = null;
        self::$backendName = null;
    }

    /**
     * Returns the cache repository the extension should use. Falls back to
     * `$default` (Flarum's container-bound cache) if Redis isn't available.
     */
    public static function build(CacheRepository $default): CacheRepository
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $redis = self::tryRedis();
        if ($redis !== null) {
            self::$instance = $redis;
            self::$backendName = 'redis';
        } else {
            self::$instance = $default;
            self::$backendName = 'file';
        }

        return self::$instance;
    }

    public static function backendName(): string
    {
        return self::$backendName ?? 'unknown';
    }

    private static function tryRedis(): ?CacheRepository
    {
        $host = getenv('REDIS_HOST');
        if (! $host) {
            return null;
        }
        if (! class_exists(Client::class)) {
            return null;
        }

        try {
            $client = new Client([
                'scheme' => 'tcp',
                'host' => $host,
                'port' => (int) (getenv('REDIS_PORT') ?: 6379),
                'password' => getenv('REDIS_PASSWORD') ?: null,
                'database' => (int) (getenv('REDIS_DB') ?: 0),
                'timeout' => 0.5,
                'read_write_timeout' => 1.0,
            ]);
            // Ping verifies both reachability and password.
            $client->ping();
        } catch (\Throwable $e) {
            error_log('[brynforum/api-cache] Redis unreachable, falling back to file cache: '.$e->getMessage());

            return null;
        }

        // Empty store-level prefix: the middleware already namespaces every
        // key with `brynforum.api-cache.`, so adding it again at the store
        // would double-prefix. flush() still scans only our keys via the
        // explicit scan pattern.
        $store = new PredisStore($client, '', 'brynforum.api-cache.*');

        return new Repository($store);
    }
}
