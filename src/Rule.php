<?php

namespace BrynForum\ApiCache;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $name
 * @property string $path_pattern
 * @property string|null $query_filter
 * @property int $ttl_seconds
 * @property string $scope          'public' | 'guest'
 * @property bool $enabled
 * @property int $priority
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Rule extends AbstractModel
{
    protected $table = 'brynforum_api_cache_rules';

    // Flarum's AbstractModel disables $timestamps by default. We want
    // Eloquent to auto-fill created_at/updated_at on save AND to cast them
    // to DateTime on retrieval (RuleSerializer::formatDate requires a
    // DateTime-typed value).
    public $timestamps = true;

    protected $fillable = [
        'name',
        'path_pattern',
        'query_filter',
        'ttl_seconds',
        'scope',
        'enabled',
        'priority',
        'seed_from_authenticated',
    ];

    protected $casts = [
        'enabled' => 'bool',
        'ttl_seconds' => 'int',
        'priority' => 'int',
        'seed_from_authenticated' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Validate that a string compiles as a PCRE pattern (with delimiters).
     *
     * Used by controllers before persisting; surfaces a clean validation
     * error instead of letting an invalid regex blow up at request time.
     */
    public static function isValidRegex(?string $pattern): bool
    {
        if ($pattern === null || $pattern === '') {
            return true; // null/empty is allowed for query_filter
        }

        // Pattern must be self-delimited (e.g. #^/api/users$#) — same form
        // we feed to preg_match() in the middleware.
        $delim = $pattern[0] ?? '';
        if (! in_array($delim, ['/', '#', '~', '@', '!', '|'], true)) {
            return false;
        }

        // Suppress warnings; preg_match returns false on a bad pattern.
        return @preg_match($pattern, '') !== false;
    }

    /**
     * URLs that are reliably per-user in Flarum core. If a `scope=public`
     * rule's path pattern matches any of these, caching the response would
     * leak one user's data to another. We refuse to save such rules and
     * tell the operator to use scope=guest instead.
     *
     * Detection uses the operator's regex (which we already validated) and
     * tests it against each example URL — semantically correct even when
     * the operator's pattern uses character classes / alternations that
     * substring checks wouldn't catch.
     */
    private const IDENTITY_SPECIFIC_URLS = [
        '/api/users/123',           // single-user profile
        '/api/users/me',            // current-user shortcut
        '/api/users/me/avatar',     // current-user uploads
        '/api/notifications',       // per-user notification feed
        '/api/notifications/123',
        '/api/preferences',         // per-user prefs
        '/api/access_tokens',       // auth tokens
        '/api/access_tokens/abc',
    ];

    /**
     * If `$pattern` matches a known identity-specific URL, return that URL.
     * Returns null when the pattern is safe for scope=public.
     */
    public static function dangerousMatch(string $pattern): ?string
    {
        if ($pattern === '' || ! self::isValidRegex($pattern)) {
            return null;
        }

        foreach (self::IDENTITY_SPECIFIC_URLS as $url) {
            if (@preg_match($pattern, $url) === 1) {
                return $url;
            }
        }

        return null;
    }
}
