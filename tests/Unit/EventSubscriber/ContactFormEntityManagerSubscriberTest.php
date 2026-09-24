<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormEntityManagerSubscriber;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[CoversClass(ContactFormEntityManagerSubscriber::class)]
final class ContactFormEntityManagerSubscriberTest extends TestCase
{
    public function testSubscribesAfterRouterAndOnException(): void
    {
        self::assertSame([
            KernelEvents::REQUEST   => ['onKernelRequest', 31],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ], ContactFormEntityManagerSubscriber::getSubscribedEvents());
    }

    public function testClosedManagerIsResetOnMainRequestToBundleRoute(): void
    {
        $subscriber = new ContactFormEntityManagerSubscriber($this->createResolver(expectedResets: 1));

        $subscriber->onKernelRequest($this->requestEvent('nowo_contact_form_public_show', HttpKernelInterface::MAIN_REQUEST));
    }

    public function testSubRequestsAndOtherRoutesAreIgnored(): void
    {
        $subscriber = new ContactFormEntityManagerSubscriber($this->createResolver(expectedResets: 0));

        $subscriber->onKernelRequest($this->requestEvent('nowo_contact_form_public_show', HttpKernelInterface::SUB_REQUEST));
        $subscriber->onKernelRequest($this->requestEvent('app_home', HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelRequest($this->requestEvent(null, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelException($this->exceptionEvent('app_home'));
    }

    public function testClosedManagerIsResetWhenBundleRouteThrows(): void
    {
        $subscriber = new ContactFormEntityManagerSubscriber($this->createResolver(expectedResets: 1));

        $subscriber->onKernelException($this->exceptionEvent('nowo_contact_form_admin_edit'));
    }

    private function createResolver(int $expectedResets): ContactFormEntityManagerResolver
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($closed);
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('getManager')->willReturn($closed);
        $registry->expects(self::exactly($expectedResets))
            ->method('resetManager')
            ->willReturn($this->createMock(EntityManagerInterface::class));

        return new ContactFormEntityManagerResolver($registry);
    }

    private function requestEvent(?string $route, int $type): RequestEvent
    {
        return new RequestEvent($this->createMock(HttpKernelInterface::class), $this->request($route), $type);
    }

    private function exceptionEvent(string $route): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            $this->request($route),
            HttpKernelInterface::MAIN_REQUEST,
            new RuntimeException('failed'),
        );
    }

    private function request(?string $route): Request
    {
        $request = Request::create('/');
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        return $request;
    }
}
