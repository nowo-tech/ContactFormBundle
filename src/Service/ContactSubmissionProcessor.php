<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Client\ClientReference;
use Nowo\ContactFormBundle\Client\ObjectClientReferenceResolver;
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
use function filter_var;
use function get_debug_type;
use function is_int;
use function is_string;
use function preg_match;

use const FILTER_VALIDATE_INT;

/**
 * Persists contact form submissions with GDPR metadata and optional client linkage.
 *
 * {@see self::process()} serves the public form (Symfony form + request); {@see self::record()} is the request-free port
 * (1.7). Both flush the bundle's entity manager only ({@see ContactFormEntityManagerResolver}: `doctrine.entity_manager`,
 * else the manager that maps the entities) and dispatch the created event after that flush.
 */
final readonly class ContactSubmissionProcessor implements ContactSubmissionRecorderInterface
{
    /** Signed 32-bit range of the INTEGER `client_id` column. */
    private const CLIENT_ID_MIN = -2147483648;
    private const CLIENT_ID_MAX = 2147483647;

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
    ) {
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
                    ->setValue($this->valueNormalizer->normalize($field, $value, $form)),
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
                    ->setValue($this->valueNormalizer->normalize($field, $values[$fieldName], $form)),
            );
        }

        return (int) $this->store($submission)->getId();
    }

    /**
     * Client linkage: `client_reference` holds any id type (1.7); the deprecated INTEGER `client_id` only a 32-bit
     * integer id; `client_label` the display label.
     */
    private function applyClient(ContactSubmission $submission, ?object $client): void
    {
        if ($client instanceof ClientReference) {
            $submission->setClientId($this->int32($client->id));
            $submission->setClientReference($client->id);
            $submission->setClientLabel($client->label);

            return;
        }

        if ($client === null) {
            return;
        }

        if (method_exists($client, 'getId')) {
            $submission->setClientId($this->normalizeClientId($client, $client->getId()));
            $submission->setClientReference(ObjectClientReferenceResolver::idOf($client));
        }
        $submission->setClientLabel($this->clientLabelResolver->resolveLabel($client));
    }

    private function store(ContactSubmission $submission): ContactSubmission
    {
        $entityManager = $this->entityManagerResolver?->get() ?? $this->entityManager;

        try {
            $entityManager->persist($submission);
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
     * `client_id` is a 32-bit INTEGER column: only integer ids (or strings holding one) in the signed 32-bit range are
     * stored. Any other id (UUID, ULID, non-numeric string, object, bigint above the range) used to be cast with
     * `(int)` and truncated or clamped, so submissions of different clients shared one id; it is now stored as null.
     * Since 1.7 `client_reference` keeps such ids as strings; a warning is logged only when it cannot either.
     */
    private function normalizeClientId(object $client, mixed $id): ?int
    {
        if ($id === null) {
            return null;
        }

        if (is_int($id) && $id >= self::CLIENT_ID_MIN && $id <= self::CLIENT_ID_MAX) {
            return $id;
        }

        if (is_string($id) && ($int = $this->int32($id)) !== null) {
            return $int;
        }

        // 1.7: client_reference holds any integer, string or Stringable id up to 64 characters; only other ids are lost.
        if (ObjectClientReferenceResolver::idOf($client) !== null) {
            return null;
        }

        $this->logger?->warning(
            'Contact form submission: the client id ({id_type}) of {client_class} is not a 32-bit integer nor a string of at most 64 characters; client_id and client_reference are stored as null and only client_label links the submission to the client.',
            [
                'id_type'      => get_debug_type($id),
                'client_class' => $client::class,
            ],
        );

        return null;
    }

    /**
     * The id as a signed 32-bit integer when it is one (string form), else null.
     */
    private function int32(string $id): ?int
    {
        if (preg_match('/^-?(?:0|[1-9]\d{0,9})$/', $id) !== 1) {
            return null;
        }

        $int = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => self::CLIENT_ID_MIN, 'max_range' => self::CLIENT_ID_MAX]]);

        return is_int($int) ? $int : null;
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
