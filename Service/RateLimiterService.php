<?php

declare(strict_types=1);

namespace MyFlyingBox\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Simple session-based rate limiter for front-office AJAX endpoints.
 * Uses a sliding window algorithm stored in the session.
 */
final class RateLimiterService
{
    private const SESSION_KEY_PREFIX = 'mfb_rate_limit_';
    private const DEFAULT_MAX_REQUESTS = 30;
    private const DEFAULT_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Check if the current request is within rate limits.
     *
     * @return bool true if allowed, false if rate limit exceeded
     */
    public function isAllowed(
        string $endpoint,
        int $maxRequests = self::DEFAULT_MAX_REQUESTS,
        int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
    ): bool {
        $session = $this->requestStack->getSession();
        $key = self::SESSION_KEY_PREFIX . $endpoint;

        /** @var array<int> $timestamps */
        $timestamps = $session->get($key, []);
        $now = time();
        $windowStart = $now - $windowSeconds;

        // Remove expired timestamps
        $timestamps = array_values(array_filter(
            $timestamps,
            static fn (int $timestamp): bool => $timestamp > $windowStart,
        ));

        if (count($timestamps) >= $maxRequests) {
            $session->set($key, $timestamps);
            return false;
        }

        $timestamps[] = $now;
        $session->set($key, $timestamps);

        return true;
    }

    /**
     * Get the number of seconds until the next request is allowed.
     */
    public function getRetryAfterSeconds(
        string $endpoint,
        int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
    ): int {
        $session = $this->requestStack->getSession();
        $key = self::SESSION_KEY_PREFIX . $endpoint;

        /** @var array<int> $timestamps */
        $timestamps = $session->get($key, []);

        if ($timestamps === []) {
            return 0;
        }

        $oldestInWindow = min($timestamps);
        $retryAfter = $oldestInWindow + $windowSeconds - time();

        return max(0, $retryAfter);
    }
}
