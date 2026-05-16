<?php

namespace BrynForum\ApiCache\Api\Controller;

use BrynForum\ApiCache\Api\Serializer\RuleSerializer;
use BrynForum\ApiCache\Rule;
use Flarum\Api\Controller\AbstractShowController;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class UpdateRuleController extends AbstractShowController
{
    public $serializer = RuleSerializer::class;

    protected function data(ServerRequestInterface $request, Document $document)
    {
        RequestUtil::getActor($request)->assertAdmin();

        $id = Arr::get($request->getQueryParams(), 'id');
        $rule = Rule::query()->findOrFail($id);

        $attributes = Arr::get($request->getParsedBody(), 'data.attributes', []);

        if (Arr::has($attributes, 'name')) {
            $rule->name = (string) $attributes['name'];
        }
        if (Arr::has($attributes, 'pathPattern')) {
            $rule->path_pattern = (string) $attributes['pathPattern'];
        }
        if (Arr::has($attributes, 'queryFilter')) {
            $rule->query_filter = $attributes['queryFilter'] ?: null;
        }
        if (Arr::has($attributes, 'ttlSeconds')) {
            $rule->ttl_seconds = (int) $attributes['ttlSeconds'];
        }
        if (Arr::has($attributes, 'scope')) {
            $rule->scope = $attributes['scope'] === 'guest' ? 'guest' : 'public';
        }
        if (Arr::has($attributes, 'enabled')) {
            $rule->enabled = (bool) $attributes['enabled'];
        }
        if (Arr::has($attributes, 'priority')) {
            $rule->priority = (int) $attributes['priority'];
        }
        if (Arr::has($attributes, 'seedFromAuthenticated')) {
            $rule->seed_from_authenticated = (bool) $attributes['seedFromAuthenticated'];
        }

        $this->validate($rule);
        $rule->save();

        return $rule;
    }

    protected function validate(Rule $rule): void
    {
        $errors = [];

        if ($rule->name === '') {
            $errors['name'] = 'Name is required.';
        }
        if ($rule->path_pattern === '' || ! Rule::isValidRegex($rule->path_pattern)) {
            $errors['pathPattern'] = 'Path pattern must be a valid PCRE regex with delimiters.';
        }
        if ($rule->query_filter !== null && ! Rule::isValidRegex($rule->query_filter)) {
            $errors['queryFilter'] = 'Query filter must be a valid PCRE regex with delimiters.';
        }
        if ($rule->ttl_seconds < 1 || $rule->ttl_seconds > 86400 * 7) {
            $errors['ttlSeconds'] = 'TTL must be between 1 second and 7 days.';
        }
        if ($rule->scope === 'public'
            && ($dangerous = Rule::dangerousMatch($rule->path_pattern)) !== null) {
            $errors['scope'] = "Path pattern matches the identity-specific URL '$dangerous'. "
                .'Caching this at scope=public would leak one user\'s data to another. '
                .'Use scope=guest instead, or narrow the pattern to exclude per-user endpoints.';
        }

        if (! empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
