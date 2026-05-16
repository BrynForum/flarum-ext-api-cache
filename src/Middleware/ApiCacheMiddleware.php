<?php

namespace BrynForum\ApiCache\Middleware;

use BrynForum\ApiCache\Cache\CacheFactory;
use BrynForum\ApiCache\Rule;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ApiCacheMiddleware implements MiddlewareInterface
{
    /**
     * Cache key prefix. All keys we own start with this so a single
     * forgetByPattern (or full flush via rules.flush) is straightforward.
     */
    public const KEY_PREFIX = 'brynforum.api-cache.';

    /**
     * How long the compiled rule list itself is cached. Short — we want
     * admin rule edits to take effect quickly. Invalidated explicitly by
     * InvalidateOnRuleChange when rules are mutated.
     */
    public const RULES_CACHE_TTL = 60;

    public const RULES_CACHE_KEY = self::KEY_PREFIX.'rules';

    public function __construct(
        Cache $defaultCache,
    ) {
        // CacheFactory picks Redis if available + reachable, else falls
        // back to Flarum's default file-backed cache. Result is memoised
        // statically across the request, so the env probe + ping happens
        // only once.
        $this->cache = CacheFactory::build($defaultCache);
    }

    protected Cache $cache;

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        // Only GET is cacheable — the rest pass through untouched.
        if (strtoupper($request->getMethod()) !== 'GET') {
            return $handler->handle($request);
        }

        $rules = $this->loadRules();
        if (empty($rules)) {
            return $handler->handle($request);
        }

        // Flarum's BasePathRouter middleware runs ahead of us and strips the
        // `/api` prefix from the URI — by the time we see the request, the
        // path is `/discussions` not `/api/discussions`. Re-add the prefix
        // so admin-facing rule patterns use the URL the user actually sees.
        $path = '/api'.$request->getUri()->getPath();
        $query = $request->getUri()->getQuery();

        $rule = $this->matchRule($rules, $path, $query);
        if ($rule === null) {
            return $handler->handle($request);
        }

        // For scope=guest, we only serve / store cache for unauthenticated
        // requests. Authenticated users bypass the cache entirely so their
        // identity-specific responses never land in a shared bucket.
        if ($rule['scope'] === 'guest' && $this->isAuthenticated($request)) {
            return $handler->handle($request);
        }

        $key = $this->cacheKey($path, $query, $rule['scope']);

        $cached = $this->cache->get($key);
        if (is_array($cached) && isset($cached['body'], $cached['headers'], $cached['status'])) {
            return $this->responseFromCache($cached, $hit = true);
        }

        $response = $handler->handle($request);

        // Decide whether to store the fresh response, with three safety nets
        // beyond the rule's own scope gating:
        //   1. Only 200 is cacheable — errors / redirects pass through.
        //   2. Honour Cache-Control: private | no-store from the handler.
        //      Standard HTTP semantics: if the response is marked per-user
        //      we never write it to a shared bucket even at scope=public.
        //      Catches identity-sensitive endpoints the rule author forgot.
        //   3. Per-rule seed_from_authenticated: when false, only responses
        //      generated for unauthenticated visitors seed the cache. Stops
        //      an admin's response (which may include auth-gated fields
        //      via Flarum's serializers, e.g. `email` on /api/users) from
        //      poisoning a shared bucket that guests subsequently read.
        $cacheState = 'MISS';
        if ($response->getStatusCode() !== 200) {
            $cacheState = 'SKIP-status';
        } else {
            $cacheControl = strtolower($response->getHeaderLine('Cache-Control'));
            if (str_contains($cacheControl, 'private')
                || str_contains($cacheControl, 'no-store')) {
                $cacheState = 'SKIP-private';
            } elseif (! ($rule['seed_from_authenticated'] ?? true)
                && $this->isAuthenticated($request)) {
                $cacheState = 'SKIP-authseed';
            } else {
                $this->store($key, $response, $rule['ttl_seconds']);
            }
        }

        // Debug headers — HIT/MISS/SKIP-* + backend are visible during testing.
        return $response
            ->withHeader('X-BrynForum-Cache', $cacheState)
            ->withHeader('X-BrynForum-Cache-Backend', CacheFactory::backendName());
    }

    /**
     * Load enabled rules from DB (cached briefly to keep request-path cost low).
     *
     * Returns an array of associative arrays, ordered by priority DESC then id ASC.
     */
    protected function loadRules(): array
    {
        $cached = $this->cache->get(self::RULES_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $rules = Rule::query()
            ->where('enabled', true)
            ->orderBy('priority', 'desc')
            ->orderBy('id', 'asc')
            ->get([
                'id',
                'path_pattern',
                'query_filter',
                'ttl_seconds',
                'scope',
                'seed_from_authenticated',
            ])
            ->map(fn (Rule $r) => [
                'id' => $r->id,
                'path_pattern' => $r->path_pattern,
                'query_filter' => $r->query_filter,
                'ttl_seconds' => $r->ttl_seconds,
                'scope' => $r->scope,
                'seed_from_authenticated' => (bool) $r->seed_from_authenticated,
            ])
            ->all();

        $this->cache->put(self::RULES_CACHE_KEY, $rules, self::RULES_CACHE_TTL);

        return $rules;
    }

    /**
     * First-match-wins. Uses @preg_match to swallow warnings on a bad
     * regex (validated at write time, but defence-in-depth: a corrupt
     * pattern shouldn't crash the request).
     */
    protected function matchRule(array $rules, string $path, string $query): ?array
    {
        foreach ($rules as $rule) {
            if (@preg_match($rule['path_pattern'], $path) !== 1) {
                continue;
            }
            if (! empty($rule['query_filter'])
                && @preg_match($rule['query_filter'], $query) !== 1) {
                continue;
            }

            return $rule;
        }

        return null;
    }

    /**
     * Stable cache key from method+path+sorted-query+scope. Sorting the
     * querystring means /api/foo?a=1&b=2 and /api/foo?b=2&a=1 hit the same
     * key — same response, sensible to share.
     *
     * SHA-1 keeps the key under filesystem name limits when the cache
     * driver is `file` (alpine ext4 supports 255, but readable + safe wins).
     */
    protected function cacheKey(string $path, string $query, string $scope): string
    {
        $params = [];
        if ($query !== '') {
            parse_str($query, $params);
            ksort($params);
        }
        $normalised = http_build_query($params);

        return self::KEY_PREFIX.'response.'.sha1("GET|$path|$normalised|$scope");
    }

    /**
     * Pick out the user from the request. Flarum's `RequestUtil::getActor`
     * returns a Guest instance when the request is unauthenticated.
     */
    protected function isAuthenticated(ServerRequestInterface $request): bool
    {
        try {
            $actor = RequestUtil::getActor($request);

            return $actor !== null && $actor->exists;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Serialise a PSR-7 response to a cache-stable shape. Headers are
     * preserved EXCEPT Set-Cookie — caching that across users would leak
     * sessions. Body is read fully into memory; rewindable streams are a
     * mess to persist and our cached bodies are JSON, so this is fine.
     */
    protected function store(string $key, ResponseInterface $response, int $ttl): void
    {
        $body = (string) $response->getBody();
        // Rewind so the response we return after caching is still readable.
        $response->getBody()->rewind();

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            if (strcasecmp($name, 'Set-Cookie') === 0) {
                continue;
            }
            $headers[$name] = $values;
        }

        $this->cache->put($key, [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => $body,
        ], $ttl);
    }

    /**
     * Rehydrate a cached payload into a PSR-7 response.
     */
    protected function responseFromCache(array $cached, bool $hit): ResponseInterface
    {
        $response = new \Laminas\Diactoros\Response(
            'php://memory',
            $cached['status'],
            $cached['headers']
        );
        $response->getBody()->write($cached['body']);
        $response->getBody()->rewind();

        return $response
            ->withHeader('X-BrynForum-Cache', $hit ? 'HIT' : 'MISS')
            ->withHeader('X-BrynForum-Cache-Backend', CacheFactory::backendName());
    }
}
