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
use Nowo\ContactFormBundle\Service\ContactFormEnabledLocales;
use Nowo\ContactFormBundle\Ui\ContactFormPageFactory;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Flow\DataStorage\SessionDataStorage;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_keys;
use function assert;
use function in_array;
use function is_array;
use function sprintf;

/**
 * Admin CRUD controller for customizable contact form fields.
 *
 * @final since 1.5, will be final in 2.0 (not an extension point; decorate or replace the service instead)
 */
#[Route(
    '/{formId}/fields',
    name: 'nowo_contact_form_fields_',
    requirements: ['formId' => '\d+'],
)]
class ContactFormFieldAdminController extends AbstractController
{
    public function __construct(
        private readonly ContactFormRepository $formRepository,
        private readonly ContactFormFieldRepository $fieldRepository,
        private readonly TranslatorInterface $translator,
        private readonly ContactFormEnabledLocales $enabledLocales,
        private readonly RequestStack $requestStack,
        private readonly ContactFormPageFactory $pages,
        private readonly CsrfOnlyFormFactory $csrfOnlyForms,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(int $formId): Response
    {
        $form = $this->getContactForm($formId);

        $fields      = $this->fieldRepository->findByFormOrdered($form);
        $deleteForms = [];
        foreach ($fields as $field) {
            $deleteForms[(int) $field->getId()] = $this->deleteForm($form, $field)->createView();
        }

        return $this->render($this->pages->template('field_index'), $this->pages->context([
            'page'        => $this->pages->fieldIndex($form, $fields, $deleteForms),
            'contactForm' => $form,
        ]));
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
        // The route requirement (ContactFormFieldFlowType::STEP_REQUIREMENT) only lets known steps through; symfony/form
        // ^7.4 ships FormFlow.
        $managedField = $field;
        $storageKey   = $this->buildFieldFlowStorageKey($contactForm, $field, $isNew);
        $field        = $this->syncFieldFlowStepFromUrl($storageKey, $field, $step, $request);

        $flow = $this->createFieldFlow($storageKey, $field);
        $flow->handleRequest($request);

        $field = $flow->getData();
        assert($field instanceof ContactFormField, 'The field wizard works on a ContactFormField (data_class).');

        $this->ensureTranslationsForEnabledLocales($field);

        if ($flow->isSubmitted() && $flow->isValid() && $flow->isFinished()) {
            if ($isNew) {
                $contactForm->addField($field);
                $entityManager->persist($field);
            } else {
                // The flow data comes back from the session storage as a detached copy: apply it to the managed
                // field so the flush below persists the edit.
                $field = $this->applyWizardResult($managedField, $field);
            }

            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
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
        $flowView    = $flow->createView();
        $steps       = [];
        foreach (array_keys(is_array($flowView->vars['visible_steps'] ?? null) ? $flowView->vars['visible_steps'] : []) as $visibleStep) {
            $steps[] = (string) $visibleStep;
        }
        if (!in_array($currentStep, $steps, true)) {
            $steps[] = $currentStep;
        }

        return $this->render($this->pages->template('field_form'), $this->pages->context([
            'page'        => $this->pages->fieldWizard($contactForm, $isNew ? null : $field->getId(), $flowView, $steps, $currentStep),
            'contactForm' => $contactForm,
            'field'       => $field,
            'isNew'       => $isNew,
            'currentStep' => $currentStep,
        ]), new Response(null, $flow->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Copies the wizard result (definition, type options, translations) onto the managed field and returns it.
     */
    private function applyWizardResult(ContactFormField $managed, ContactFormField $result): ContactFormField
    {
        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        $managed
            ->setName($result->getName())
            ->setType($result->getType())
            ->setRequired($result->isRequired())
            ->setSortOrder($result->getSortOrder())
            ->setOptions($result->getOptions());

        // Both sides carry one translation per enabled locale (ensureTranslationsForEnabledLocales()).
        foreach ($result->getTranslations() as $translation) {
            $managed->findTranslation($translation->getLocale())
                ?->setLabel($translation->getLabel())
                ->setPlaceholder($translation->getPlaceholder())
                ->setHelp($translation->getHelp())
                ->setSelectOptions($translation->getSelectOptions());
        }

        return $managed;
    }

    /**
     * CSRF-only delete form (REQ-TWIG-005): posts `_token` with the id checked by delete().
     *
     * @return FormInterface<mixed>
     */
    private function deleteForm(ContactForm $form, ContactFormField $field): FormInterface
    {
        return $this->csrfOnlyForms->create(
            $this->generateUrl('nowo_contact_form_fields_delete', ['formId' => $form->getId(), 'id' => $field->getId()]),
            'delete-contact-field-' . $field->getId(),
        );
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
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
            $stored->setFlowStep($step);
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
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
        foreach ($this->enabledLocales->all() as $locale) {
            $this->ensureTranslationForLocale($field, $locale);
        }
    }

    private function ensureTranslationForLocale(ContactFormField $field, string $locale): void
    {
        if ($field->findTranslation($locale) instanceof ContactFormFieldTranslation) {
            return;
        }

        $field->addTranslation(
            (new ContactFormFieldTranslation())->setLocale($locale),
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
