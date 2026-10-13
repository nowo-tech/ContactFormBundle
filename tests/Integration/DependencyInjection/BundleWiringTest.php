<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\DependencyInjection;

use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Notification\MailerContactSubmissionNotifier;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionRateLimiter;
use Nowo\ContactFormBundle\Service\SecurityClientResolver;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionProperty;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function sys_get_temp_dir;

/**
 * Boots a real kernel: proves services of other bundles reach this bundle's services
 * (load() cannot see them; unit tests pre-registering them hid the bug).
 */
final class BundleWiringTest extends TestCase
{
    /** @var list<KernelInterface> */
    private array $kernels = [];

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo_contact_form_wiring');
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
    }

    public function testRateLimiterReceivesAppCachePoolByDefault(): void
    {
        $container = $this->boot(new WiringTestKernel())->getContainer();

        $limiter = $container->get(ContactFormSubmissionRateLimiter::class);
        self::assertInstanceOf(ContactFormSubmissionRateLimiter::class, $limiter);
        self::assertInstanceOf(CacheItemPoolInterface::class, $this->property($limiter, 'cachePool'));
        self::assertGreaterThan(0, $this->property($limiter, 'limit'));
    }

    public function testFrameworkClockIsNotReplaced(): void
    {
        $container = $this->boot(new WiringTestKernel())->getContainer();

        self::assertInstanceOf(Clock::class, $container->get('clock'));
    }

    public function testSecurityClientResolverReceivesTokenStorage(): void
    {
        $container = $this->boot(new WiringTestKernel())->getContainer();

        $resolver = $container->get(SecurityClientResolver::class);
        self::assertInstanceOf(SecurityClientResolver::class, $resolver);
        self::assertInstanceOf(TokenStorageInterface::class, $this->property($resolver, 'tokenStorage'));
    }

    public function testSecurityClientResolverWorksWithoutSecurityBundle(): void
    {
        $container = $this->boot(new WiringTestKernel(
            contactFormConfig: ['security' => ['allow_unauthenticated' => true]],
            withSecurity: false,
        ))->getContainer();

        $resolver = $container->get(SecurityClientResolver::class);
        self::assertInstanceOf(SecurityClientResolver::class, $resolver);
        self::assertNull($this->property($resolver, 'tokenStorage'));
    }

    public function testMailerNotifierRegisteredWhenMailerExists(): void
    {
        $container = $this->boot(new WiringTestKernel(
            frameworkConfig: ['mailer' => ['dsn' => 'null://null']],
            contactFormConfig: ['notifications' => ['enabled' => true, 'mailer' => ['enabled' => true]]],
        ))->getContainer();

        self::assertInstanceOf(MailerContactSubmissionNotifier::class, $container->get(ContactSubmissionNotifierInterface::class));
    }

    public function testNullNotifierWhenMailerMissing(): void
    {
        $container = $this->boot(new WiringTestKernel(
            frameworkConfig: ['mailer' => ['enabled' => false]],
            contactFormConfig: ['notifications' => ['enabled' => true, 'mailer' => ['enabled' => true]]],
        ))->getContainer();

        self::assertInstanceOf(NullContactSubmissionNotifier::class, $container->get(ContactSubmissionNotifierInterface::class));
    }

    public function testAdminRoutesReturnNotFoundWhenWebUiDisabled(): void
    {
        $kernel = $this->boot(new WiringTestKernel(
            contactFormConfig: ['web_ui' => ['enabled' => false]],
        ));

        foreach (['/admin/contact-forms/', '/admin/contact-forms/new', '/admin/contact-forms/1/edit'] as $path) {
            $response = $kernel->handle(Request::create($path));
            self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $path);
        }
    }

    public function testAdminRoutesDeniedForAnonymousWhenWebUiEnabled(): void
    {
        $kernel = $this->boot(new WiringTestKernel());

        $response = $kernel->handle(Request::create('/admin/contact-forms/'));

        self::assertContains($response->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], (string) $response->headers);
    }

    private function boot(KernelInterface $kernel): KernelInterface
    {
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }

    private function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }
}
