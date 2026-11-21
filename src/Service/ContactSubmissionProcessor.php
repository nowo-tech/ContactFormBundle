<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Client\ClientReference;
use Nowo\ContactFormBundle\Client\ObjectClientReferenceResolver;
use Nowo\ContactFormBundle\Doctrine\ContactFormWrites;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Event\ContactSubmissionCreatedEvent;
use Nowo\ContactFormBundle\Exception\ContactFormNotFoundException;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotification;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function array_key_exists;
use function get_debug_type;
use function method_exists;

/**
 * Persists contact form submissions with GDPR metadata and optional client linkage.
 *
 * {@see self::process()} serves the public form (Symfony form + request); {@see self::record()} is the request-free port
 * (1.7). Both flush the bundle's entity manager only ({@see ContactFormEntityManagerResolver}: the bundle's private
 * entity manager, or a dedicated `doctrine.entity_manager`; never the application's) and dispatch the created event after
 * that flush. A contact form handed to {@see self::process()} that another manager loaded is re-loaded by id first.
 */
final readonly class ContactSubmissionProcessor implements ContactSubmissionRecorderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactFormFieldRepository $fieldRepository,
        private IpAnonymizer $ipAnonymizer,
        private ClientLabelResolver $clientLabelResolver,
        private EventDispatcherInterface $eventDispatcher,
        private ContactSubmissionNotifierInterface $notifier,
        private ContactFormSubmissionValueNormalizer $valueNormalizer,
        private ClockInterface $clock,
        private ?string $defaultNotificationRecipient = null,
        private ?ContactFormEntityManagerResolver $entityManagerResolver = null,
        private ?LoggerInterface $logger = null,
        private ?ContactFormRepository $formRepository = null,
        /*
         * @deprecated since 2.0, ignored; removed in 3.0 (1.13's host-flush reporter, removed with the host mode)
         */
        ?object $hostFlushReporter = null,
    ) {
        unset($hostFlushReporter);
    }

    /**
     * @param FormInterface<mixed> $symfonyForm
     */
    public function process(
        ContactForm $form,
        FormInterface $symfonyForm,
        Request $request,
        string $locale,
        ?object $client = null,
    ): ContactSubmission {
        // A form another manager loaded (the application's find(), a host query) is re-loaded in the bundle's manager:
        // the submission must refer to a row of the manager that flushes it, never persist the form as new.
        $form       = ContactFormWrites::adoptForm($this->entityManagerResolver?->get() ?? $this->entityManager, $form);
        $submission = new ContactSubmission();
        $submission->setForm($form);
        $submission->setLocale($locale);
        $submission->setIpHash($this->ipAnonymizer->anonymize($request->getClientIp()));

        if ($form->isRequireConsent() && $symfonyForm->has('gdpr_consent') && $symfonyForm->get('gdpr_consent')->getData() === true) {
            $submission->setConsentGivenAt($this->clock->now());
        }

        $this->applyClient($submission, $client);

        $fields = $this->fieldRepository->findByFormOrdered($form);

        foreach ($fields as $field) {
            $fieldName = $field->getName();

            if (!$symfonyForm->has($fieldName)) {
                continue;
            }

            $value = $symfonyForm->get($fieldName)->getData();

            $submission->addValue(
                (new ContactSubmissionValue())
                    ->setFieldName($fieldName)
                    ->setValue($this->valueNormalizer->normalize($field, $value, $form, $locale)),
            );
        }

        return $this->store($submission);
    }

    public function record(
        string $formSlug,
        array $values,
        string $locale,
        ?string $ipHash = null,
        ?ClientReference $client = null,
        ?DateTimeImmutable $consentGivenAt = null,
    ): int {
        // Recover a manager a previous failed flush closed (worker mode) before loading the form into it.
        $this->entityManagerResolver?->get();
        $form = $this->formRepository?->findOneBy(['slug' => $formSlug]);
        if (!$form instanceof ContactForm) {
            throw ContactFormNotFoundException::forSlug($formSlug);
        }

        $submission = new ContactSubmission();
        $submission->setForm($form);
        $submission->setLocale($locale);
        $submission->setIpHash($ipHash);
        $submission->setConsentGivenAt($consentGivenAt);
        $this->applyClient($submission, $client);

        foreach ($this->fieldRepository->findByFormOrdered($form) as $field) {
            $fieldName = $field->getName();
            if (!array_key_exists($fieldName, $values)) {
                continue;
            }

            $submission->addValue(
                (new ContactSubmissionValue())
                    ->setFieldName($fieldName)
                    ->setValue($this->valueNormalizer->normalize($field, $values[$fieldName], $form, $locale)),
            );
        }

        return (int) $this->store($submission)->getId();
    }

    /**
     * Client linkage: `client_reference` holds any id type (1.7), `client_label` the display label. 2.0 removed the
     * INTEGER `client_id` column (deprecated since 1.7).
     */
    private function applyClient(ContactSubmission $submission, ?object $client): void
    {
        if ($client instanceof ClientReference) {
            $submission->setClientReference($client->id);
            $submission->setClientLabel($client->label);

            return;
        }

        if ($client === null) {
            return;
        }

        if (method_exists($client, 'getId')) {
            $submission->setClientReference($this->clientReferenceOf($client, $client->getId()));
        }
        $submission->setClientLabel($this->clientLabelResolver->resolveLabel($client));
    }

    /**
     * `client_reference` keeps any integer, string or Stringable id up to 64 characters; any other id is stored as null
     * (only `client_label` then links the submission to the client) and a warning is logged.
     */
    private function clientReferenceOf(object $client, mixed $id): ?string
    {
        $reference = ObjectClientReferenceResolver::idOf($client);
        if ($reference !== null || $id === null) {
            return $reference;
        }

        $this->logger?->warning(
            'Contact form submission: the client id ({id_type}) of {client_class} is not an integer nor a string of at most 64 characters; client_reference is stored as null and only client_label links the submission to the client.',
            [
                'id_type'      => get_debug_type($id),
                'client_class' => $client::class,
            ],
        );

        return null;
    }

    private function store(ContactSubmission $submission): ContactSubmission
    {
        $entityManager = $this->entityManagerResolver?->get() ?? $this->entityManager;

        try {
            $entityManager->persist($submission);
            ContactFormWrites::assertOnlyBundleEntities($entityManager);
            $entityManager->flush();
        } catch (Throwable $exception) {
            $this->entityManagerResolver?->resetClosed();

            throw $exception;
        }

        try {
            $this->eventDispatcher->dispatch(new ContactSubmissionCreatedEvent($submission));

            $this->notifier->notify(
                ContactSubmissionNotification::fromSubmission($submission, $this->defaultNotificationRecipient),
            );
        } finally {
            $this->detachSubmission($entityManager, $submission);
        }

        return $submission;
    }

    /**
     * Submissions hold personal data; they must not stay in a long-lived identity map (worker mode).
     */
    private function detachSubmission(EntityManagerInterface $entityManager, ContactSubmission $submission): void
    {
        if (!$entityManager->isOpen()) {
            return;
        }

        foreach ($submission->getValues() as $value) {
            if ($entityManager->contains($value)) {
                $entityManager->detach($value);
            }
        }

        if ($entityManager->contains($submission)) {
            $entityManager->detach($submission);
        }
    }
}
