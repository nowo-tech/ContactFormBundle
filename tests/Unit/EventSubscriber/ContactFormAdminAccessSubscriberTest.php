<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\EventSubscriber;

use Nowo\ContactFormBundle\EventSubscriber\ContactFormAdminAccessSubscriber;
use Nowo\ContactFormBundle\Security\ContactFormAccessCheckerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
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
        $checker->method('canAccess')->willReturn(true);

        $subscriber = new ContactFormAdminAccessSubscriber($checker);
        $subscriber->onKernelController($this->controllerEvent('nowo_contact_form_fields_index'));
    }

    private function controllerEvent(string $route): ControllerEvent
    {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $request->attributes->set('_route', $route);

        return new ControllerEvent($kernel, static fn (): string => 'ok', $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
