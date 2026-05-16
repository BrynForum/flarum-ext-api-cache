<?php

namespace BrynForum\ApiCache\Api\Serializer;

use BrynForum\ApiCache\Rule;
use Flarum\Api\Serializer\AbstractSerializer;

class RuleSerializer extends AbstractSerializer
{
    protected $type = 'api-cache-rules';

    /**
     * @param Rule $rule
     */
    protected function getDefaultAttributes($rule): array
    {
        return [
            'name' => $rule->name,
            'pathPattern' => $rule->path_pattern,
            'queryFilter' => $rule->query_filter,
            'ttlSeconds' => $rule->ttl_seconds,
            'scope' => $rule->scope,
            'enabled' => (bool) $rule->enabled,
            'priority' => $rule->priority,
            'seedFromAuthenticated' => (bool) $rule->seed_from_authenticated,
            'createdAt' => $this->formatDate($rule->created_at),
            'updatedAt' => $this->formatDate($rule->updated_at),
        ];
    }
}
