<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

use function hash;
use function sprintf;

/**
 * Rate limiter for public contact form submissions (per IP and form slug).
 */
final readonly class ContactFormSubmissionRateLimiter
{
    private const CACHE_KEY_PREFIX = 'nowo_contact_form_submit_';

    public function __construct(
        private ?CacheItemPoolInterface $cachePool,
        private int $limit,
        private int $intervalSeconds,
        private ClockInterface $clock,
    ) {
    }

    public function consume(Request $request, string $formSlug): void
    {
        if ($this->limit <= 0 || $this->intervalSeconds <= 0 || !$this->cachePool instanceof CacheItemPoolInterface) {
            return;
        }

        $key  = self::CACHE_KEY_PREFIX . hash('sha256', $formSlug . '|' . ($request->getClientIp() ?? 'unknown'));
        $item = $this->cachePool->getItem($key);
        $now  = $this->clock->now()->getTimestamp();
        $data = $item->isHit() ? $item->get() : null;

        if ($data === null || !isset($data['s'], $data['c']) || ($now - (int) $data['s']) >= $this->intervalSeconds) {
            $data = ['s' => $now, 'c' => 1];
        } else {
            $data['c'] = (int) $data['c'] + 1;
        }

        if ($data['c'] > $this->limit) {
            throw new TooManyRequestsHttpException($this->intervalSeconds, sprintf('Too many submissions. Limit is %d per %d seconds.', $this->limit, $this->intervalSeconds));
        }

        $item->set($data);
        $item->expiresAfter($this->intervalSeconds + 10);
        $this->cachePool->save($item);
    }
}
