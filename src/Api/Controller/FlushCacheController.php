<?php

namespace BrynForum\ApiCache\Api\Controller;

use BrynForum\ApiCache\Cache\CacheFactory;
use BrynForum\ApiCache\Middleware\ApiCacheMiddleware;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Admin endpoint that drops cached responses.
 *
 * On the Redis backend the PredisStore.flush() implementation is bounded
 * to the `brynforum.api-cache.` prefix via SCAN, so calling Repository
 * ::flush() is safe — it nukes only our keys, not Flarum core / sessions.
 *
 * On the file backend, Repository::flush() would wipe everything Flarum's
 * file cache holds (settings, locale, etc.), which is too aggressive. So
 * we only clear the rule-list cache there and let response bodies age out
 * via TTL. Operators who really want an instant full flush can run
 * `php flarum cache:clear` on the container.
 */
class FlushCacheController implements RequestHandlerInterface
{
    public function __construct(
        protected Cache $defaultCache,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $cache = CacheFactory::build($this->defaultCache);
        $backend = CacheFactory::backendName();

        $cache->forget(ApiCacheMiddleware::RULES_CACHE_KEY);

        $note = 'Rule list cache cleared.';

        if ($backend === 'redis') {
            // Prefix-bounded flush — see PredisStore::flush().
            $cache->flush();
            $note .= ' Cached response bodies (prefix-bounded) flushed too.';
        } else {
            $note .= ' Cached responses age out via TTL — for an instant full flush'
                .' run `php flarum cache:clear` on the container.';
        }

        return new JsonResponse([
            'data' => [
                'type' => 'api-cache-flush',
                'attributes' => [
                    'flushedAt' => date('c'),
                    'backend' => $backend,
                    'note' => $note,
                ],
            ],
        ]);
    }
}
