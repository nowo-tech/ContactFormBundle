<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Nowo\ContactFormBundle\Service\ContactFormSubmissionRateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(ContactFormSubmissionRateLimiter::class)]
final class ContactFormSubmissionRateLimiterTest extends TestCase
{
    public function testConsumeIsNoOpWhenLimitOrIntervalIsZero(): void
    {
        $cache   = new ArrayAdapter();
        $limiter = new ContactFormSubmissionRateLimiter($cache, 0, 60, new MockClock());

        $limiter->consume(Request::create('/'), 'contact');

        self::assertSame([], $cache->getValues());
    }

    public function testConsumeIsNoOpWhenCachePoolIsNull(): void
    {
        $this->expectNotToPerformAssertions();

        $limiter = new ContactFormSubmissionRateLimiter(null, 5, 60, new MockClock());

        $limiter->consume(Request::create('/'), 'contact');
    }

    public function testConsumeTracksSubmissionsAndThrowsWhenLimitExceeded(): void
    {
        $cache   = new ArrayAdapter();
        $limiter = new ContactFormSubmissionRateLimiter($cache, 2, 60, new MockClock());
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        $limiter->consume($request, 'support');
        $limiter->consume($request, 'support');

        $this->expectException(TooManyRequestsHttpException::class);
        $this->expectExceptionMessage('Too many submissions. Limit is 2 per 60 seconds.');

        $limiter->consume($request, 'support');
    }

    public function testConsumeResetsCounterAfterIntervalExpires(): void
    {
        $this->expectNotToPerformAssertions();

        $clock   = new MockClock();
        $cache   = new ArrayAdapter();
        $limiter = new ContactFormSubmissionRateLimiter($cache, 1, 1, $clock);
        $request = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.11']);

        $limiter->consume($request, 'contact');

        $clock->sleep(2);

        $limiter->consume($request, 'contact');
    }

    public function testConsumeUsesUnknownIpWhenClientIpMissing(): void
    {
        $this->expectNotToPerformAssertions();

        $cache   = new ArrayAdapter();
        $limiter = new ContactFormSubmissionRateLimiter($cache, 5, 60, new MockClock());
        $request = Request::create('/');

        $limiter->consume($request, 'contact');
    }

    /**
     * L4: with a symfony/rate-limiter factory the counting is the limiter's (atomic with a lock store).
     */
    public function testUsesTheRateLimiterFactoryWhenConfigured(): void
    {
        $clock   = new MockClock('2026-10-27 12:00:00');
        $factory = new RateLimiterFactory(['id' => 'contact', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'], new InMemoryStorage());
        $limiter = new ContactFormSubmissionRateLimiter(new ArrayAdapter(), 0, 0, $clock, $factory);
        $request = Request::create('/en/contact/support', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']);

        $limiter->consume($request, 'support');
        $limiter->consume($request, 'support');

        $this->expectException(TooManyRequestsHttpException::class);
        $limiter->consume($request, 'support');
    }
}
