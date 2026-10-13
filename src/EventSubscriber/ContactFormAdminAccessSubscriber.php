<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\EventSubscriber;

use Nowo\ContactFormBundle\Security\ContactFormAccessCheckerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Guards admin CRUD routes: 404 when the web UI is disabled, otherwise enforces ContactFormAccessCheckerInterface.
 *
 * A null access checker means security.allow_unauthenticated is true (dev/demo): no access check is applied.
 */
final readonly class ContactFormAdminAccessSubscriber implements EventSubscriberInterface
{
    private const ROUTE_PREFIXES = [
        'nowo_contact_form_admin_',
        'nowo_contact_form_fields_',
        'nowo_contact_form_submissions_',
    ];

    public function __construct(
        private ?ContactFormAccessCheckerInterface $accessChecker,
        private bool $webUiEnabled = true,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onKernelController', 0],
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if ($route === null || !$this->isAdminRoute((string) $route)) {
            return;
        }

        if (!$this->webUiEnabled) {
            throw new NotFoundHttpException('Contact Form admin web UI is disabled.');
        }

        if ($this->accessChecker instanceof ContactFormAccessCheckerInterface && !$this->accessChecker->canAccess()) {
            throw new AccessDeniedException('Contact Form admin requires an authorized user.');
        }
    }

    private function isAdminRoute(string $route): bool
    {
        foreach (self::ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
