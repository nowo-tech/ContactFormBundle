<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\EventSubscriber;

use Nowo\ContactFormBundle\Controller\ContactFormAdminController;
use Nowo\ContactFormBundle\Controller\ContactFormFieldAdminController;
use Nowo\ContactFormBundle\Controller\ContactSubmissionAdminController;
use Nowo\ContactFormBundle\Security\ContactFormAccessCheckerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function is_array;
use function is_string;
use function str_starts_with;

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

    /** Admin controllers: guarded whatever their route name (route name_prefix, fragments, sub-requests). */
    private const ADMIN_CONTROLLERS = [
        ContactFormAdminController::class,
        ContactFormFieldAdminController::class,
        ContactSubmissionAdminController::class,
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
        if (!$this->isAdminController($event->getController()) && !$this->isAdminRoute(is_string($route) ? $route : '')) {
            return;
        }

        if (!$this->webUiEnabled) {
            throw new NotFoundHttpException('Contact Form admin web UI is disabled.');
        }

        if ($this->accessChecker instanceof ContactFormAccessCheckerInterface && !$this->accessChecker->canAccess()) {
            throw new AccessDeniedException('Contact Form admin requires an authorized user.');
        }
    }

    private function isAdminController(callable $controller): bool
    {
        $object = is_array($controller) ? $controller[0] : $controller;

        foreach (self::ADMIN_CONTROLLERS as $class) {
            if ($object instanceof $class) {
                return true;
            }
        }

        return false;
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
