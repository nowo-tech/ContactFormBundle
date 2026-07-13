<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Event\ContactSubmissionCreatedEvent;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Service\ContactSubmissionProcessor;
use Nowo\ContactFormBundle\Service\IpAnonymizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

use function in_array;

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
            static fn (string $name) => match ($name) {
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
        );

        $submission = $processor->process(
            $contactForm,
            $symfonyForm,
            Request::create('/'),
            'es',
            null,
        );

        self::assertTrue($submission->isAnonymous());
        self::assertCount(0, $submission->getValues());
    }
}
