<?php

namespace BrynForum\ApiCache\Listener;

use BrynForum\ApiCache\Cache\CacheFactory;
use BrynForum\ApiCache\Middleware\ApiCacheMiddleware;
use BrynForum\ApiCache\Rule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Events\Saved;
use Illuminate\Database\Eloquent\Events\Deleted;

/**
 * Whenever a rule is created, updated, or deleted, drop the rules-list
 * cache so the next request re-reads from DB. We don't try to invalidate
 * the response-bodies cache here — those age out via TTL. The only thing
 * that needs to refresh fast is the active rule set itself.
 */
class InvalidateOnRuleChange
{
    public function __construct(
        protected Cache $defaultCache,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Saved::class, [$this, 'onSaved']);
        $events->listen(Deleted::class, [$this, 'onDeleted']);
    }

    public function onSaved(Saved $event): void
    {
        if ($event->model instanceof Rule) {
            $this->forget();
        }
    }

    public function onDeleted(Deleted $event): void
    {
        if ($event->model instanceof Rule) {
            $this->forget();
        }
    }

    /**
     * Always forget against the active backend (Redis when available, file
     * otherwise). If we forgot against the default file cache while the
     * middleware was reading from Redis, the rules-list cache would never
     * actually invalidate.
     */
    protected function forget(): void
    {
        CacheFactory::build($this->defaultCache)
            ->forget(ApiCacheMiddleware::RULES_CACHE_KEY);
    }
}
