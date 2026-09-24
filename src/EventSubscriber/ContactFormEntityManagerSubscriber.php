<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\EventSubscriber;

use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function is_string;
use function str_starts_with;

/**
 * Keeps bundle routes working when the EntityManager was closed by a failed flush and no kernel
 * reset runs between requests (FrankenPHP worker mode without services_resetter).
 */
final readonly class ContactFormEntityManagerSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIX = 'nowo_contact_form_';

    public function __construct(
        private ContactFormEntityManagerResolver $entityManagerResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Right after RouterListener (32) so `_route` is known, before the firewall (8).
            KernelEvents::REQUEST   => ['onKernelRequest', 31],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isBundleRoute($event->getRequest())) {
            return;
        }

        $this->entityManagerResolver->get();
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$this->isBundleRoute($event->getRequest())) {
            return;
        }

        $this->entityManagerResolver->resetClosed();
    }

    private function isBundleRoute(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        return is_string($route) && str_starts_with($route, self::ROUTE_PREFIX);
    }
}
