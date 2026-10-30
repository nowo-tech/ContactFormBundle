<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\DependencyInjection;

use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Notification\MailerContactSubmissionNotifier;
use Nowo\ContactFormBundle\Notification\NullContactSubmissionNotifier;
use Nowo\ContactFormBundle\Security\AllowAllContactFormAccessChecker;
use Nowo\ContactFormBundle\Security\ConfigurableContactFormAccessChecker;
use Nowo\ContactFormBundle\Security\ContactFormAccessCheckerInterface;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionRateLimiter;
use Nowo\ContactFormBundle\Service\SecurityClientResolver;
use Nowo\ContactFormBundle\Ui\ContactFormPages;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionProperty;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

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

    public function testAccessCheckerReceivesAuthorizationCheckerWithSecurityBundle(): void
    {
        $container = $this->boot(new WiringTestKernel())->getContainer();

        $checker = $container->get(ContactFormAccessCheckerInterface::class);
        self::assertInstanceOf(ConfigurableContactFormAccessChecker::class, $checker);
        self::assertInstanceOf(AuthorizationCheckerInterface::class, $this->property($checker, 'authorizationChecker'));
        self::assertSame(['ROLE_ADMIN'], $this->property($checker, 'accessRoles'));
    }

    public function testAccessCheckerKeepsRolesWithSecurityBundleEvenIfAllowUnauthenticated(): void
    {
        $container = $this->boot(new WiringTestKernel(
            contactFormConfig: ['security' => ['allow_unauthenticated' => true]],
        ))->getContainer();

        $checker = $container->get(ContactFormAccessCheckerInterface::class);
        self::assertInstanceOf(ConfigurableContactFormAccessChecker::class, $checker);
        self::assertInstanceOf(AuthorizationCheckerInterface::class, $this->property($checker, 'authorizationChecker'));
    }

    public function testAccessCheckerWithoutSecurityBundleAllowUnauthenticated(): void
    {
        $container = $this->boot(new WiringTestKernel(
            contactFormConfig: ['security' => ['allow_unauthenticated' => true]],
            withSecurity: false,
        ))->getContainer();

        self::assertInstanceOf(AllowAllContactFormAccessChecker::class, $container->get(ContactFormAccessCheckerInterface::class));
    }

    public function testAccessCheckerWithoutSecurityBundleIsFailClosed(): void
    {
        $container = $this->boot(new WiringTestKernel(
            contactFormConfig: ['web_ui' => ['enabled' => false]],
            withSecurity: false,
        ))->getContainer();

        $checker = $container->get(ContactFormAccessCheckerInterface::class);
        self::assertInstanceOf(ConfigurableContactFormAccessChecker::class, $checker);
        self::assertNull($this->property($checker, 'authorizationChecker'));
        self::assertFalse($checker->canAccess());
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

    /**
     * The container injects the TwigBundle `twig` service into ContactFormPages, so a configured 1.x admin layout (no
     * UiKit canonical blocks) is wrapped in the deprecated bridge. Up to 1.6.1 the argument used
     * `#[Autowire('@?twig')]`, which is not an optional reference (the id became "?twig") and compiled to null: the
     * bridge was never taken and the 1.x layout was extended directly.
     */
    #[IgnoreDeprecations]
    public function testLegacyAdminLayoutIsWrappedInTheBridge(): void
    {
        $this->expectUserDeprecationMessageMatches('/legacy_admin\.html\.twig/');
        $container = $this->boot(new WiringTestKernel(contactFormConfig: [
            'security' => ['allow_unauthenticated' => true],
            'web_ui'   => ['layout_template' => '@ContactFormWiring/legacy_admin.html.twig'],
        ]))->getContainer();

        $pages = $container->get(ContactFormPages::class);
        self::assertInstanceOf(ContactFormPages::class, $pages);
        self::assertSame(ContactFormPages::LEGACY_ADMIN_BRIDGE, $pages->layout());
        $context = $pages->context([]);
        self::assertSame('@ContactFormWiring/legacy_admin.html.twig', $context['nowo_contact_form_legacy_layout']);
        self::assertSame(['nowo_ui_content'], $context['nowo_contact_form_legacy_blocks']);
    }

    public function testCanonicalAdminLayoutIsUsedAsIs(): void
    {
        $container = $this->boot(new WiringTestKernel(contactFormConfig: [
            'security' => ['allow_unauthenticated' => true],
            'web_ui'   => ['layout_template' => '@ContactFormWiring/canonical_admin.html.twig'],
        ]))->getContainer();

        $pages = $container->get(ContactFormPages::class);
        self::assertInstanceOf(ContactFormPages::class, $pages);
        self::assertSame('@ContactFormWiring/canonical_admin.html.twig', $pages->layout());
        self::assertNull($pages->context([])['nowo_contact_form_legacy_layout']);
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
