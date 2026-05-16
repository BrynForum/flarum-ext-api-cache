<?php

use Flarum\Database\Migration;

return Migration::addColumns(
    'brynforum_api_cache_rules',
    [
        // When false, the rule's HIT path serves cached responses to everyone,
        // but only requests from unauthenticated visitors will WRITE into the
        // cache. Prevents an admin's request (whose response may include
        // auth-gated fields like `email` via Flarum's UserSerializer) from
        // poisoning a shared cache that guests subsequently read.
        // Default true to preserve existing behaviour on upgrade.
        'seed_from_authenticated' => ['boolean', 'default' => true],
    ]
);
