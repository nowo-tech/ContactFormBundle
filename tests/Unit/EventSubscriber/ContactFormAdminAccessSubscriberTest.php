<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\EventSubscriber;

use Nowo\ContactFormBundle\Controller\ContactFormAdminController;
use Nowo\ContactFormBundle\Controller\ContactFormFieldAdminController;
use Nowo\ContactFormBundle\Controller\ContactSubmissionAdminController;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormAdminAccessSubscriber;
use Nowo\ContactFormBundle\Security\ContactFormAccessCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(ContactFormAdminAccessSubscriber::class)]
final class ContactFormAdminAccessSubscriberTest extends TestCase
{
    public function testIgnoresNonAdminRoutes(): void
    {
        $checker = $this->createMock(ContactFormAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccess');

        $subscriber = new ContactFormAdminAccessSubscriber($checker);
        $event      = $this->controllerEvent('nowo_contact_form_public_show');

        $subscriber->onKernelController($event);
    }

    public function testDeniesWhenCheckerFails(): void
    {
        $checker = $this->createMock(ContactFormAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturn(false);

        $subscriber = new ContactFormAdminAccessSubscriber($checker);

        $this->expectException(AccessDeniedException::class);
        $subscriber->onKernelController($this->controllerEvent('nowo_contact_form_admin_index'));
    }

    public function testAllowsWhenCheckerPasses(): void
    {
        $checker = $this->createMock(ContactFormAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccess')->willReturn(true);

        $subscriber = new ContactFormAdminAccessSubscriber($checker);
        $subscriber->onKernelController($this->controllerEvent('nowo_contact_form_fields_index'));
    }

    public function testReturnsNotFoundOnAdminRoutesWhenWebUiDisabled(): void
    {
        $checker = $this->createMock(ContactFormAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturn(true);

        $subscriber = new ContactFormAdminAccessSubscriber($checker, webUiEnabled: false);

        $this->expectException(NotFoundHttpException::class);
        $subscriber->onKernelController($this->controllerEvent('nowo_contact_form_submissions_index'));
    }

    public function testReturnsNotFoundWhenWebUiDisabledEvenWithoutChecker(): void
    {
        $subscriber = new ContactFormAdminAccessSubscriber(null, webUiEnabled: false);

        $this->expectException(NotFoundHttpException::class);
        $subscriber->onKernelController($this->controllerEvent('nowo_contact_form_admin_delete'));
    }

    public function testIgnoresPublicRoutesWhenWebUiDisabled(): void
    {
        $subscriber = new ContactFormAdminAccessSubscriber(null, webUiEnabled: false);
        $subscriber->onKernelController($this->controllerEvent('nowo_contact_form_public_show'));

        $this->addToAssertionCount(1);
    }

    public function testNullCheckerSkipsAccessCheckWhenWebUiEnabled(): void
    {
        $subscriber = new ContactFormAdminAccessSubscriber(null);
        $subscriber->onKernelController($this->controllerEvent('nowo_contact_form_admin_index'));

        $this->addToAssertionCount(1);
    }

    /**
     * L2: an admin controller is guarded whatever its route name (host route name_prefix, fragment sub-request).
     */
    public function testGuardsAdminControllersUnderAnyRouteName(): void
    {
        foreach ([ContactFormAdminController::class, ContactFormFieldAdminController::class, ContactSubmissionAdminController::class] as $class) {
            $checker = $this->createMock(ContactFormAccessCheckerInterface::class);
            $checker->expects(self::once())->method('canAccess')->willReturn(false);

            $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            $request    = new Request();
            $request->attributes->set('_route', 'host_prefixed_contact_switch');
            $event = new ControllerEvent($this->createStub(HttpKernelInterface::class), [$controller, 'index'], $request, HttpKernelInterface::SUB_REQUEST);

            try {
                (new ContactFormAdminAccessSubscriber($checker))->onKernelController($event);
                self::fail($class . ' must be guarded');
            } catch (AccessDeniedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInvokableNonAdminControllersAreIgnored(): void
    {
        $checker = $this->createMock(ContactFormAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canAccess');

        $request = new Request();
        $event   = new ControllerEvent($this->createStub(HttpKernelInterface::class), new class {
            public function __invoke(): string
            {
                return 'ok';
            }
        }, $request, HttpKernelInterface::MAIN_REQUEST);

        (new ContactFormAdminAccessSubscriber($checker))->onKernelController($event);
    }

    private function controllerEvent(string $route): ControllerEvent
    {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $request->attributes->set('_route', $route);

        return new ControllerEvent($kernel, static fn (): string => 'ok', $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
