<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\EventSubscriber;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

use function in_array;
use function is_array;
use function is_string;
use function str_starts_with;

/**
 * Applies the session locale to admin routes when no route locale is set. Admin requests are recognised by their route
 * name (the router listener runs first), so a custom web_ui.path_prefix works too.
 */
final readonly class ContactFormAdminLocaleSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIXES = [
        'nowo_contact_form_admin_',
        'nowo_contact_form_fields_',
        'nowo_contact_form_submissions_',
    ];

    public function __construct(
        private LocaleSwitcher $localeSwitcher,
        private ParameterBagInterface $parameterBag,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -5],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->isAdminRoute($request->attributes->get('_route'))) {
            return;
        }

        if ($request->attributes->has('_locale')) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $locale = $request->getSession()->get('_locale');

        if (!is_string($locale) || !in_array($locale, $this->getEnabledLocales(), true)) {
            return;
        }

        $this->localeSwitcher->setLocale($locale);
        $request->setLocale($locale);
    }

    private function isAdminRoute(mixed $route): bool
    {
        if (!is_string($route)) {
            return false;
        }

        foreach (self::ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function getEnabledLocales(): array
    {
        $locales = $this->parameterBag->get('kernel.enabled_locales');

        if (!is_array($locales) || $locales === []) {
            return ['en'];
        }

        return array_values($locales);
    }
}
