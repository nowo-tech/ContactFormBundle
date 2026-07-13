<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Event\ContactSubmissionCreatedEvent;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotification;
use Nowo\ContactFormBundle\Notification\ContactSubmissionNotifierInterface;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Persists contact form submissions with GDPR metadata and optional client linkage.
 */
final class ContactSubmissionProcessor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContactFormFieldRepository $fieldRepository,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly ClientLabelResolver $clientLabelResolver,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ContactSubmissionNotifierInterface $notifier,
        private readonly ContactFormSubmissionValueNormalizer $valueNormalizer,
        private readonly ?string $defaultNotificationRecipient = null,
    ) {
    }

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
            $submission->setConsentGivenAt(new DateTimeImmutable());
        }

        if ($client !== null) {
            if (method_exists($client, 'getId')) {
                $submission->setClientId((int) $client->getId());
            }
            $submission->setClientLabel($this->clientLabelResolver->resolveLabel($client));
        }

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

        $this->entityManager->persist($submission);
        $this->entityManager->flush();

        $this->eventDispatcher->dispatch(new ContactSubmissionCreatedEvent($submission));

        $this->notifier->notify(
            ContactSubmissionNotification::fromSubmission($submission, $this->defaultNotificationRecipient),
        );

        return $submission;
    }
}
