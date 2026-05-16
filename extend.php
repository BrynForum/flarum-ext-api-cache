<?php

/*
 * BrynForum API Cache — extension wiring.
 *
 * Registers:
 *   - Database migrations (rules table)
 *   - The PSR-15 cache middleware on the API stack
 *   - Admin-only CRUD routes for managing rules + flushing cache
 *   - Frontend assets (admin pane only — no forum-side UI)
 */

use BrynForum\ApiCache\Api\Controller\CreateRuleController;
use BrynForum\ApiCache\Api\Controller\DeleteRuleController;
use BrynForum\ApiCache\Api\Controller\FlushCacheController;
use BrynForum\ApiCache\Api\Controller\ListRulesController;
use BrynForum\ApiCache\Api\Controller\UpdateRuleController;
use BrynForum\ApiCache\Listener\InvalidateOnRuleChange;
use BrynForum\ApiCache\Middleware\ApiCacheMiddleware;
use BrynForum\ApiCache\Rule;
use Flarum\Extend;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    (new Extend\Routes('api'))
        ->get('/api-cache-rules', 'brynforum.api-cache.rules.index', ListRulesController::class)
        ->post('/api-cache-rules', 'brynforum.api-cache.rules.create', CreateRuleController::class)
        ->patch('/api-cache-rules/{id}', 'brynforum.api-cache.rules.update', UpdateRuleController::class)
        ->delete('/api-cache-rules/{id}', 'brynforum.api-cache.rules.delete', DeleteRuleController::class)
        ->post('/api-cache-rules/flush', 'brynforum.api-cache.rules.flush', FlushCacheController::class),

    (new Extend\Middleware('api'))
        ->add(ApiCacheMiddleware::class),

    (new Extend\Event())
        ->subscribe(InvalidateOnRuleChange::class),

    (new Extend\Model(Rule::class)),
];
