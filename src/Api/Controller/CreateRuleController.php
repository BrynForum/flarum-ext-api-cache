<?php

namespace BrynForum\ApiCache\Api\Controller;

use BrynForum\ApiCache\Api\Serializer\RuleSerializer;
use BrynForum\ApiCache\Rule;
use Flarum\Api\Controller\AbstractCreateController;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class CreateRuleController extends AbstractCreateController
{
    public $serializer = RuleSerializer::class;

    protected function data(ServerRequestInterface $request, Document $document)
    {
        RequestUtil::getActor($request)->assertAdmin();

        $attributes = Arr::get($request->getParsedBody(), 'data.attributes', []);

        $rule = new Rule();
        $rule->name = (string) Arr::get($attributes, 'name', '');
        $rule->path_pattern = (string) Arr::get($attributes, 'pathPattern', '');
        $rule->query_filter = Arr::get($attributes, 'queryFilter') ?: null;
        $rule->ttl_seconds = (int) Arr::get($attributes, 'ttlSeconds', 300);
        $rule->scope = Arr::get($attributes, 'scope') === 'guest' ? 'guest' : 'public';
        $rule->enabled = (bool) Arr::get($attributes, 'enabled', true);
        $rule->priority = (int) Arr::get($attributes, 'priority', 0);
        $rule->seed_from_authenticated = (bool) Arr::get($attributes, 'seedFromAuthenticated', true);

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
        if ($rule->path_pattern === '') {
            $errors['pathPattern'] = 'Path pattern is required.';
        } elseif (! Rule::isValidRegex($rule->path_pattern)) {
            $errors['pathPattern'] = 'Path pattern must be a valid PCRE regex with delimiters (e.g. #^/api/users$#).';
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
