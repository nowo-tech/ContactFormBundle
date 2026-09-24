<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Event\ContactSubmissionCreatedEvent;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Service\ContactSubmissionProcessor;
use Nowo\ContactFormBundle\Service\IpAnonymizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

use function in_array;
use function spl_object_id;

#[CoversClass(ContactSubmissionProcessor::class)]
final class ContactSubmissionProcessorTest extends TestCase
{
    public function testProcessPersistsSubmissionDispatchesEventAndNotifies(): void
    {
        $contactForm = (new ContactForm())
            ->setName('Support')
            ->setSlug('support')
            ->setRequireConsent(true)
            ->setNotificationEmail('form@example.com');

        $field = (new ContactFormField())
            ->setName('email')
            ->setForm($contactForm);

        $symfonyForm  = $this->createMock(FormInterface::class);
        $emailField   = $this->createMock(FormInterface::class);
        $consentField = $this->createMock(FormInterface::class);
        $emailField->method('getData')->willReturn('user@example.com');
        $consentField->method('getData')->willReturn(true);
        $symfonyForm->method('has')->willReturnCallback(
            static fn (string $name): bool => in_array($name, ['email', 'gdpr_consent'], true),
        );
        $symfonyForm->method('get')->willReturnCallback(
            static fn (string $name): MockObject => match ($name) {
                'email'        => $emailField,
                'gdpr_consent' => $consentField,
                default        => throw new InvalidArgumentException($name),
            },
        );

        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->with($contactForm)->willReturn([$field]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(ContactSubmission::class));
        $entityManager->expects(self::once())->method('flush');

        $ipAnonymizer = new IpAnonymizer('test-salt');

        $clientLabelResolver = new ClientLabelResolver(null, 'email');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(ContactSubmissionCreatedEvent::class));

        $notifier = $this->createMock(ContactSubmissionNotifierInterface::class);
        $notifier->expects(self::once())->method('notify');

        $processor = new ContactSubmissionProcessor(
            $entityManager,
            $fieldRepository,
            $ipAnonymizer,
            $clientLabelResolver,
            $eventDispatcher,
            $notifier,
            new ContactFormSubmissionValueNormalizer($this->createMock(ContactFormFileUploadHandlerInterface::class)),
            new MockClock(),
            'default@example.com',
        );

        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $client  = new class {
            public function getId(): int
            {
                return 42;
            }

            public function getEmail(): string
            {
                return 'client@example.com';
            }
        };

        $submission = $processor->process($contactForm, $symfonyForm, $request, 'en', $client);

        self::assertSame($contactForm, $submission->getForm());
        self::assertSame('en', $submission->getLocale());
        self::assertSame($ipAnonymizer->anonymize('127.0.0.1'), $submission->getIpHash());
        self::assertSame(42, $submission->getClientId());
        self::assertSame('client@example.com', $submission->getClientLabel());
        self::assertNotNull($submission->getConsentGivenAt());
        self::assertCount(1, $submission->getValues());
    }

    public function testProcessSkipsMissingFormFields(): void
    {
        $contactForm = (new ContactForm())->setRequireConsent(false);
        $field       = (new ContactFormField())->setName('notes')->setForm($contactForm);

        $symfonyForm = $this->createMock(FormInterface::class);
        $symfonyForm->method('has')->willReturn(false);

        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->willReturn([$field]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $processor = new ContactSubmissionProcessor(
            $entityManager,
            $fieldRepository,
            new IpAnonymizer('salt'),
            new ClientLabelResolver(null, 'email'),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(ContactSubmissionNotifierInterface::class),
            new ContactFormSubmissionValueNormalizer($this->createMock(ContactFormFileUploadHandlerInterface::class)),
            new MockClock(),
        );

        $submission = $processor->process(
            $contactForm,
            $symfonyForm,
            Request::create('/'),
            'es',
        );

        self::assertTrue($submission->isAnonymous());
        self::assertCount(0, $submission->getValues());
    }

    public function testConsecutiveSubmissionsAreDetachedFromTheManagerFromTheResolver(): void
    {
        $contactForm = (new ContactForm())->setRequireConsent(false);
        $field       = (new ContactFormField())->setName('email')->setForm($contactForm);

        $managed       = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->method('persist')->willReturnCallback(static function (ContactSubmission $submission) use (&$managed): void {
            $managed[spl_object_id($submission)] = $submission;
            foreach ($submission->getValues() as $value) {
                $managed[spl_object_id($value)] = $value;
            }
        });
        $entityManager->method('contains')->willReturnCallback(static function (object $entity) use (&$managed): bool {
            return isset($managed[spl_object_id($entity)]);
        });
        $entityManager->method('detach')->willReturnCallback(static function (object $entity) use (&$managed): void {
            unset($managed[spl_object_id($entity)]);
        });

        $injected = $this->createMock(EntityManagerInterface::class);
        $injected->expects(self::never())->method('persist');

        $processor = $this->createProcessor($injected, $field, $this->createResolver($entityManager));

        foreach (['first@example.com', 'second@example.com'] as $email) {
            $submission = $processor->process($contactForm, $this->createSymfonyForm($email), Request::create('/'), 'en');

            $value = $submission->getValues()->first();
            self::assertInstanceOf(ContactSubmissionValue::class, $value);
            self::assertSame($email, $value->getValue());
            self::assertSame([], $managed);
        }
    }

    public function testFailedFlushResetsClosedManagerAndRethrows(): void
    {
        $contactForm = (new ContactForm())->setRequireConsent(false);
        $field       = (new ContactFormField())->setName('email')->setForm($contactForm);

        $open          = true;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $entityManager->method('flush')->willReturnCallback(static function () use (&$open): never {
            $open = false;

            throw new RuntimeException('insert failed');
        });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('getManager')->with('default')->willReturn($entityManager);
        $registry->expects(self::once())->method('resetManager')->with('default')->willReturn($this->createMock(EntityManagerInterface::class));

        $processor = $this->createProcessor($entityManager, $field, new ContactFormEntityManagerResolver($registry));

        $this->expectException(RuntimeException::class);
        $processor->process($contactForm, $this->createSymfonyForm('x@example.com'), Request::create('/'), 'en');
    }

    public function testSubmissionIsDetachedWhenNotifierFails(): void
    {
        $contactForm = (new ContactForm())->setRequireConsent(false);
        $field       = (new ContactFormField())->setName('email')->setForm($contactForm);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->method('contains')->willReturn(true);
        $entityManager->expects(self::exactly(2))->method('detach');

        $notifier = $this->createMock(ContactSubmissionNotifierInterface::class);
        $notifier->method('notify')->willThrowException(new RuntimeException('smtp down'));

        $processor = $this->createProcessor($entityManager, $field, notifier: $notifier);

        $this->expectExceptionMessage('smtp down');
        $processor->process($contactForm, $this->createSymfonyForm('x@example.com'), Request::create('/'), 'en');
    }

    public function testNothingIsDetachedFromAClosedManager(): void
    {
        $contactForm = (new ContactForm())->setRequireConsent(false);
        $field       = (new ContactFormField())->setName('email')->setForm($contactForm);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(false);
        $entityManager->expects(self::never())->method('detach');

        $this->createProcessor($entityManager, $field)
            ->process($contactForm, $this->createSymfonyForm('x@example.com'), Request::create('/'), 'en');
    }

    private function createProcessor(
        EntityManagerInterface $entityManager,
        ContactFormField $field,
        ?ContactFormEntityManagerResolver $resolver = null,
        ?ContactSubmissionNotifierInterface $notifier = null,
    ): ContactSubmissionProcessor {
        $fieldRepository = $this->createMock(ContactFormFieldRepository::class);
        $fieldRepository->method('findByFormOrdered')->willReturn([$field]);

        return new ContactSubmissionProcessor(
            $entityManager,
            $fieldRepository,
            new IpAnonymizer('salt'),
            new ClientLabelResolver(null, 'email'),
            $this->createMock(EventDispatcherInterface::class),
            $notifier ?? $this->createMock(ContactSubmissionNotifierInterface::class),
            new ContactFormSubmissionValueNormalizer($this->createMock(ContactFormFileUploadHandlerInterface::class)),
            new MockClock(),
            null,
            $resolver,
        );
    }

    private function createResolver(EntityManagerInterface $entityManager): ContactFormEntityManagerResolver
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return new ContactFormEntityManagerResolver($registry);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function createSymfonyForm(string $email): FormInterface
    {
        $emailField = $this->createMock(FormInterface::class);
        $emailField->method('getData')->willReturn($email);

        $symfonyForm = $this->createMock(FormInterface::class);
        $symfonyForm->method('has')->willReturnCallback(static fn (string $name): bool => $name === 'email');
        $symfonyForm->method('get')->with('email')->willReturn($emailField);

        return $symfonyForm;
    }
}
