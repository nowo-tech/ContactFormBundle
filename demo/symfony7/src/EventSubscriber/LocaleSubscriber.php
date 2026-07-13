<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function in_array;
use function is_string;

/**
 * Applies ?_locale= query switches and persists the choice in session for routes without {_locale}.
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    private const LOCALES = ['en', 'es'];

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->attributes->has('_locale')) {
            return;
        }

        $requestedLocale = $request->query->get('_locale');

        if (is_string($requestedLocale) && in_array($requestedLocale, self::LOCALES, true)) {
            $request->setLocale($requestedLocale);

            if ($request->hasSession()) {
                $request->getSession()->set('_locale', $requestedLocale);
            }

            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $sessionLocale = $request->getSession()->get('_locale');

        if (is_string($sessionLocale) && in_array($sessionLocale, self::LOCALES, true)) {
            $request->setLocale($sessionLocale);
        }
    }
}
