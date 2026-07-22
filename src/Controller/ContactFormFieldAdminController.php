<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Form\ContactFormFieldFlowType;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Form\Flow\AbstractFlowType;
use Symfony\Component\Form\Flow\DataStorage\SessionDataStorage;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_array;
use function sprintf;

/**
 * Admin CRUD controller for customizable contact form fields.
 */
#[Route(
    '/admin/contact-forms/{formId}/fields',
    name: 'nowo_contact_form_fields_',
    requirements: ['formId' => '\d+'],
)]
class ContactFormFieldAdminController extends AbstractController
{
    public function __construct(
        private readonly ContactFormRepository $formRepository,
        private readonly ContactFormFieldRepository $fieldRepository,
        private readonly TranslatorInterface $translator,
        private readonly ParameterBagInterface $parameterBag,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(int $formId): Response
    {
        $form = $this->getContactForm($formId);

        return $this->render('@NowoContactFormBundle/admin/field/index.html.twig', [
            'contactForm' => $form,
            'fields'      => $this->fieldRepository->findByFormOrdered($form),
        ]);
    }

    #[Route('/new', name: 'new_redirect', methods: ['GET'])]
    public function newRedirect(int $formId): Response
    {
        return $this->redirectToRoute('nowo_contact_form_fields_new', [
            'formId' => $formId,
            'step'   => ContactFormFieldFlowType::STEP_DEFINITION,
        ]);
    }

    #[Route(
        '/new/{step}',
        name: 'new',
        requirements: ['step' => ContactFormFieldFlowType::STEP_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function new(int $formId, string $step, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form  = $this->getContactForm($formId);
        $field = (new ContactFormField())->setForm($form);
        $this->ensureTranslationsForEnabledLocales($field);

        return $this->handleForm($request, $entityManager, $form, $field, true, $step);
    }

    #[Route('/{id}/edit', name: 'edit_redirect', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function editRedirect(int $formId, int $id): Response
    {
        return $this->redirectToRoute('nowo_contact_form_fields_edit', [
            'formId' => $formId,
            'id'     => $id,
            'step'   => ContactFormFieldFlowType::STEP_DEFINITION,
        ]);
    }

    #[Route(
        '/{id}/edit/{step}',
        name: 'edit',
        requirements: [
            'id'   => '\d+',
            'step' => ContactFormFieldFlowType::STEP_REQUIREMENT,
        ],
        methods: ['GET', 'POST'],
    )]
    public function edit(int $formId, int $id, string $step, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form  = $this->getContactForm($formId);
        $field = $this->getField($form, $id);
        $this->ensureTranslationsForEnabledLocales($field);

        return $this->handleForm($request, $entityManager, $form, $field, false, $step);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $formId, int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form  = $this->getContactForm($formId);
        $field = $this->getField($form, $id);

        if (!$this->isCsrfTokenValid('delete-contact-field-' . $field->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entityManager->remove($field);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('nowo_contact_form.admin.field.deleted', [], 'NowoContactFormBundle'));

        return $this->redirectToRoute('nowo_contact_form_fields_index', ['formId' => $formId]);
    }

    private function handleForm(
        Request $request,
        EntityManagerInterface $entityManager,
        ContactForm $contactForm,
        ContactFormField $field,
        bool $isNew,
        string $step,
    ): Response {
        if (!class_exists(AbstractFlowType::class)) {
            throw new RuntimeException('Contact form field wizard requires symfony/form with FormFlow support (Symfony 7.4+ / 8.1+).');
        }

        if (!ContactFormFieldFlowType::isValidStep($step)) {
            throw $this->createNotFoundException();
        }

        $storageKey = $this->buildFieldFlowStorageKey($contactForm, $field, $isNew);
        $field      = $this->syncFieldFlowStepFromUrl($storageKey, $field, $step, $request);

        $flow = $this->createFieldFlow($storageKey, $field);
        $flow->handleRequest($request);

        $field = $flow->getData();
        if (!$field instanceof ContactFormField) {
            throw new RuntimeException('Unexpected field wizard data.');
        }

        $this->ensureTranslationsForEnabledLocales($field);

        if ($flow->isSubmitted() && $flow->isValid() && $flow->isFinished()) {
            if ($isNew) {
                $contactForm->addField($field);
                $entityManager->persist($field);
            }

            $field->setFlowStep(null);
            $entityManager->flush();

            $this->addFlash(
                'success',
                $this->translator->trans(
                    $isNew ? 'nowo_contact_form.admin.field.created' : 'nowo_contact_form.admin.field.updated',
                    [],
                    'NowoContactFormBundle',
                ),
            );

            return $this->redirectToRoute('nowo_contact_form_fields_index', ['formId' => $contactForm->getId()]);
        }

        if ($flow->isSubmitted() && $flow->isValid() && !$flow->isFinished()) {
            $nextStep = $flow->getStepForm()->getCursor()->getCurrentStep();

            return $this->redirectToRoute(
                $isNew ? 'nowo_contact_form_fields_new' : 'nowo_contact_form_fields_edit',
                [
                    'formId' => $contactForm->getId(),
                    'step'   => $nextStep,
                    ...($isNew ? [] : ['id' => $field->getId()]),
                ],
            );
        }

        $currentStep = $flow->getCursor()->getCurrentStep();

        if ($request->isMethod('GET') && $currentStep !== $step) {
            return $this->redirectToRoute(
                $isNew ? 'nowo_contact_form_fields_new' : 'nowo_contact_form_fields_edit',
                [
                    'formId' => $contactForm->getId(),
                    'step'   => $currentStep,
                    ...($isNew ? [] : ['id' => $field->getId()]),
                ],
            );
        }

        return $this->render('@NowoContactFormBundle/admin/field/form.html.twig', [
            'contactForm' => $contactForm,
            'field'       => $field,
            'form'        => $flow,
            'flow'        => $flow,
            'isNew'       => $isNew,
            'currentStep' => $currentStep,
        ]);
    }

    private function buildFieldFlowStorageKey(ContactForm $contactForm, ContactFormField $field, bool $isNew): string
    {
        return sprintf(
            'nowo_contact_form.field_flow.%d.%s',
            $contactForm->getId(),
            $isNew ? 'new' : (string) $field->getId(),
        );
    }

    private function syncFieldFlowStepFromUrl(
        string $storageKey,
        ContactFormField $field,
        string $step,
        Request $request,
    ): ContactFormField {
        if (!$request->isMethod('GET')) {
            return $field;
        }

        $session = $this->requestStack->getSession();
        $stored  = $session->get($storageKey);

        if ($stored instanceof ContactFormField) {
            $stored->setFlowStep($step);
            $session->set($storageKey, unserialize(serialize($stored)));

            return $stored;
        }

        $field->setFlowStep($step);

        return $field;
    }

    private function createFieldFlow(string $storageKey, ContactFormField $field): FormFlowInterface
    {
        /** @var FormFlowInterface $flow */
        $flow = $this->createForm(ContactFormFieldFlowType::class, $field, [
            'data_storage' => new SessionDataStorage($storageKey, $this->requestStack),
        ]);

        return $flow;
    }

    private function ensureTranslationsForEnabledLocales(ContactFormField $field): void
    {
        foreach ($this->getEnabledLocales() as $locale) {
            $this->ensureTranslationForLocale($field, $locale);
        }
    }

    /**
     * @return list<string>
     */
    private function getEnabledLocales(): array
    {
        $locales = $this->parameterBag->get('kernel.enabled_locales');

        if (!is_array($locales) || $locales === []) {
            return ['en'];
        }

        return array_values($locales);
    }

    private function ensureTranslationForLocale(ContactFormField $field, string $locale): void
    {
        if ($field->findTranslation($locale) instanceof ContactFormFieldTranslation) {
            return;
        }

        $field->addTranslation(
            (new ContactFormFieldTranslation())->setLocale($locale !== '' ? $locale : 'en'),
        );
    }

    private function getContactForm(int $formId): ContactForm
    {
        $form = $this->formRepository->find($formId);

        if (!$form instanceof ContactForm) {
            throw $this->createNotFoundException();
        }

        return $form;
    }

    private function getField(ContactForm $form, int $id): ContactFormField
    {
        $field = $this->fieldRepository->find($id);

        if (!$field instanceof ContactFormField || $field->getForm()?->getId() !== $form->getId()) {
            throw $this->createNotFoundException();
        }

        return $field;
    }
}
