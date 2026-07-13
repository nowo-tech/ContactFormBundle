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

/**
 * Applies the session locale to admin routes when no route locale is set.
 */
final class ContactFormAdminLocaleSubscriber implements EventSubscriberInterface
{
    private const ADMIN_PATH_PREFIX = '/admin/contact-forms';

    public function __construct(
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly ParameterBagInterface $parameterBag,
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

        if (!str_starts_with($request->getPathInfo(), self::ADMIN_PATH_PREFIX)) {
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
