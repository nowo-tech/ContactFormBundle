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
}
