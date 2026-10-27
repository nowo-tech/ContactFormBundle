<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\EventSubscriber;

use Nowo\ContactFormBundle\EventSubscriber\ContactFormAdminLocaleSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

#[CoversClass(ContactFormAdminLocaleSubscriber::class)]
final class ContactFormAdminLocaleSubscriberTest extends TestCase
{
    public function testSubscribesAfterTheRouter(): void
    {
        self::assertSame([KernelEvents::REQUEST => ['onKernelRequest', -5]], ContactFormAdminLocaleSubscriber::getSubscribedEvents());
    }

    public function testAppliesTheSessionLocaleOnAdminRoutes(): void
    {
        foreach (['nowo_contact_form_admin_index', 'nowo_contact_form_fields_edit', 'nowo_contact_form_submissions_show'] as $route) {
            $switcher = new LocaleSwitcher('en', []);
            $request  = $this->request($route, 'es');

            $this->subscriber($switcher)->onKernelRequest($this->event($request));

            self::assertSame('es', $switcher->getLocale(), $route);
            self::assertSame('es', $request->getLocale(), $route);
        }
    }

    public function testCustomPathPrefixIsRecognisedByRouteName(): void
    {
        $switcher = new LocaleSwitcher('en', []);
        $request  = $this->request('nowo_contact_form_admin_index', 'es', '/backoffice/contact/');

        $this->subscriber($switcher)->onKernelRequest($this->event($request));

        self::assertSame('es', $request->getLocale());
    }

    public function testIgnoresSubRequestsOtherRoutesAndRouteLocales(): void
    {
        $switcher = new LocaleSwitcher('en', []);
        $sub      = $this->request('nowo_contact_form_admin_index', 'es');
        $this->subscriber($switcher)->onKernelRequest($this->event($sub, HttpKernelInterface::SUB_REQUEST));
        self::assertSame('en', $sub->getLocale());

        $public = $this->request('nowo_contact_form_public_show', 'es');
        $this->subscriber($switcher)->onKernelRequest($this->event($public));
        self::assertSame('en', $public->getLocale());

        $noRoute = $this->request(null, 'es');
        $this->subscriber($switcher)->onKernelRequest($this->event($noRoute));
        self::assertSame('en', $noRoute->getLocale());

        $withLocale = $this->request('nowo_contact_form_admin_index', 'es');
        $withLocale->attributes->set('_locale', 'en');
        $this->subscriber($switcher)->onKernelRequest($this->event($withLocale));
        self::assertSame('en', $withLocale->getLocale());

        self::assertSame('en', $switcher->getLocale());
    }

    public function testIgnoresMissingSessionAndUnknownLocales(): void
    {
        $switcher  = new LocaleSwitcher('en', []);
        $noSession = Request::create('/admin/contact-forms/');
        $noSession->attributes->set('_route', 'nowo_contact_form_admin_index');
        $this->subscriber($switcher)->onKernelRequest($this->event($noSession));
        self::assertSame('en', $noSession->getLocale());

        $unknown = $this->request('nowo_contact_form_admin_index', 'fr');
        $this->subscriber($switcher)->onKernelRequest($this->event($unknown));
        self::assertSame('en', $unknown->getLocale());

        self::assertSame('en', $switcher->getLocale());
    }

    public function testFallsBackToEnglishWithoutEnabledLocales(): void
    {
        $switcher = new LocaleSwitcher('es', []);
        $request  = $this->request('nowo_contact_form_admin_index', 'en');

        (new ContactFormAdminLocaleSubscriber($switcher, new ParameterBag(['kernel.enabled_locales' => []])))->onKernelRequest($this->event($request));

        self::assertSame('en', $switcher->getLocale());
    }

    private function subscriber(LocaleSwitcher $switcher): ContactFormAdminLocaleSubscriber
    {
        return new ContactFormAdminLocaleSubscriber($switcher, new ParameterBag(['kernel.enabled_locales' => ['en', 'es']]));
    }

    private function request(?string $route, string $sessionLocale, string $path = '/admin/contact-forms/'): Request
    {
        $request = Request::create($path);
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }
        $session = new Session(new MockArraySessionStorage());
        $session->set('_locale', $sessionLocale);
        $request->setSession($session);

        return $request;
    }

    private function event(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }
}
