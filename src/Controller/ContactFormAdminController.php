<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Concurrency\RowVersionGuard;
use Nowo\ContactFormBundle\Concurrency\StaleVersionException;
use Nowo\ContactFormBundle\Doctrine\ContactFormWrites;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Form\ContactFormType;
use Nowo\ContactFormBundle\Form\RowVersionField;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Security\SafeRelativePath;
use Nowo\ContactFormBundle\Service\ContactFormEnabledLocales;
use Nowo\ContactFormBundle\Ui\ContactFormPageFactory;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\UiKitBundle\View\Pagination;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

use function ceil;
use function in_array;
use function is_string;
use function max;

use const PHP_URL_HOST;
use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * Admin CRUD controller for contact form definitions.
 *
 * Final since 2.0 (announced with `@final` since 1.5): decorate or replace the service instead of extending it.
 */
#[Route('', name: 'nowo_contact_form_admin_')]
final class ContactFormAdminController extends AbstractController
{
    public function __construct(
        private readonly ContactFormRepository $formRepository,
        private readonly TranslatorInterface $translator,
        #[Autowire(param: 'nowo_contact_form.default_retention_days')]
        private readonly int $defaultRetentionDays,
        private readonly ParameterBagInterface $parameterBag,
        private readonly ContactFormEnabledLocales $enabledLocales,
        private readonly ContactFormPageFactory $pages,
        private readonly CsrfOnlyFormFactory $csrfOnlyForms,
        /*
         * @deprecated since 2.0, ignored; removed in 3.0 (1.13's host-flush reporter, removed with the host mode)
         */
        ?object $hostFlushReporter = null,
    ) {
        unset($hostFlushReporter);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pageSize = max(1, (int) $this->parameterBag->get('nowo_contact_form.web_ui.list_page_size'));
        $page     = max(1, $request->query->getInt('page', 1));
        $total    = $this->formRepository->count([]);
        $pages    = max(1, (int) ceil($total / $pageSize));
        if ($page > $pages) {
            $page = $pages;
        }

        $forms       = $this->formRepository->findOrderedPage($page, $pageSize);
        $deleteForms = [];
        foreach ($forms as $form) {
            $deleteForms[(int) $form->getId()] = $this->deleteForm($form)->createView();
        }

        return $this->render($this->pages->template('form_index'), $this->pages->context([
            'page' => $this->pages->formIndex(
                $forms,
                new Pagination($page, $pageSize, $total, $pages, 'nowo_contact_form_admin_index', $request->query->all()),
                $deleteForms,
            ),
        ]));
    }

    #[Route('/locale/{locale}', name: 'switch_locale', methods: ['GET'], priority: 10)]
    public function switchLocale(string $locale, Request $request, LocaleSwitcher $localeSwitcher): RedirectResponse
    {
        if (!in_array($locale, $this->enabledLocales->all(), true)) {
            throw $this->createNotFoundException();
        }

        if ($request->hasSession()) {
            $request->getSession()->set('_locale', $locale);
        }

        $localeSwitcher->setLocale($locale);

        $redirectUrl = $this->resolveSafeRedirectUrl($request);

        if ($redirectUrl !== null) {
            return $this->redirect($redirectUrl);
        }

        return $this->redirectToRoute('nowo_contact_form_admin_index');
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = (new ContactForm())->setRetentionDays($this->defaultRetentionDays);
        $this->ensureTranslationsForEnabledLocales($form);

        return $this->handleForm($request, $entityManager, $form, true);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->getContactForm($id);
        $this->ensureTranslationsForEnabledLocales($form);

        return $this->handleForm($request, $entityManager, $form, false);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager): RedirectResponse
    {
        $form = $this->getContactForm($id);

        if (!$this->isCsrfTokenValid('delete-contact-form-' . $form->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entityManager->remove($form);
        ContactFormWrites::assertOnlyBundleEntities($entityManager);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('nowo_contact_form.admin.form.deleted', [], 'NowoContactFormBundle'));

        return $this->redirectToRoute('nowo_contact_form_admin_index');
    }

    private function handleForm(
        Request $request,
        EntityManagerInterface $entityManager,
        ContactForm $contactForm,
        bool $isNew,
    ): Response {
        $symfonyForm = $this->createForm(ContactFormType::class, $contactForm);
        $symfonyForm->handleRequest($request);

        $stale = false;
        if ($symfonyForm->isSubmitted() && $symfonyForm->isValid()) {
            if ($isNew) {
                $entityManager->persist($contactForm);
                ContactFormWrites::assertOnlyBundleEntities($entityManager);
                $entityManager->flush();
            } else {
                // 1.12 (REQ-RUNTIME-003 rule 4): the page's row version is checked and advanced in the save's
                // transaction; a form saved by someone else since the page was opened is not overwritten.
                try {
                    $this->formRepository->saveEdit($contactForm, $entityManager, RowVersionField::read($symfonyForm));
                } catch (StaleVersionException) {
                    $stale = true;
                    // Drop the form's unsaved changes from the managed entities (refresh only: the manager is never
                    // cleared or reset), so no later flush of the manager can write them over the other admin's save.
                    RowVersionGuard::discard($entityManager, $contactForm, [$contactForm->getTranslations()]);
                    // The UiKit layout prints the flashes of every type above the page.
                    $this->addFlash('error', $this->translator->trans(StaleVersionException::MESSAGE_KEY, [], 'NowoContactFormBundle'));
                }
            }

            if (!$stale) {
                $this->addFlash(
                    'success',
                    $this->translator->trans(
                        $isNew ? 'nowo_contact_form.admin.form.created' : 'nowo_contact_form.admin.form.updated',
                        [],
                        'NowoContactFormBundle',
                    ),
                );

                return $this->redirectToRoute('nowo_contact_form_admin_index');
            }
        }

        return $this->render($this->pages->template('form_form'), $this->pages->context([
            'page'        => $this->pages->formForm($symfonyForm->createView(), $contactForm, $isNew, $isNew ? null : $this->deleteForm($contactForm)->createView()),
            'contactForm' => $contactForm,
            'isNew'       => $isNew,
        ]), $this->answer($symfonyForm->isSubmitted(), $stale));
    }

    /**
     * The status of the re-rendered page: 409 + `X-Error-Code: CONTACT_FORM_STALE_VERSION` for a stale edit (a domain
     * answer, never CONFLICT_001 / CONFLICT_002: retrying the same page cannot succeed), 422 for a form error.
     */
    private function answer(bool $submitted, bool $stale): Response
    {
        if ($stale) {
            return new Response(null, Response::HTTP_CONFLICT, ['X-Error-Code' => StaleVersionException::ERROR_CODE]);
        }

        return new Response(null, $submitted ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * CSRF-only delete form (REQ-TWIG-005): posts `_token` with the id checked by delete().
     *
     * @return FormInterface<mixed>
     */
    private function deleteForm(ContactForm $form): FormInterface
    {
        return $this->csrfOnlyForms->create(
            $this->generateUrl('nowo_contact_form_admin_delete', ['id' => $form->getId()]),
            'delete-contact-form-' . $form->getId(),
        );
    }

    private function ensureTranslationsForEnabledLocales(ContactForm $form): void
    {
        foreach ($this->enabledLocales->all() as $locale) {
            $this->ensureTranslationForLocale($form, $locale);
        }
    }

    private function ensureTranslationForLocale(ContactForm $form, string $locale): void
    {
        if ($form->findTranslation($locale) instanceof ContactFormTranslation) {
            return;
        }

        $form->addTranslation(
            (new ContactFormTranslation())->setLocale($locale),
        );
    }

    private function getContactForm(int $id): ContactForm
    {
        $form = $this->formRepository->find($id);

        if (!$form instanceof ContactForm) {
            throw $this->createNotFoundException();
        }

        return $form;
    }

    /**
     * Where to go back after a locale switch: the `redirect` query parameter or the same-host Referer, only when it is
     * a safe same-origin path inside the admin prefix (SafeRelativePath: no `//`, `\`, whitespace or control
     * characters — open redirect CWE-601 fixed in 1.6.0).
     */
    private function resolveSafeRedirectUrl(Request $request): ?string
    {
        $adminPrefix = $this->parameterBag->get('nowo_contact_form.web_ui.path_prefix');
        $adminPrefix = is_string($adminPrefix) ? $adminPrefix : '';
        $redirect    = $request->query->getString('redirect');

        if ($redirect !== '' && SafeRelativePath::isSafeUnder($redirect, $adminPrefix)) {
            return $redirect;
        }

        $referer = $request->headers->get('referer');

        if (!is_string($referer) || $referer === '') {
            return null;
        }

        $refererHost = parse_url($referer, PHP_URL_HOST);
        $refererPath = parse_url($referer, PHP_URL_PATH);

        if ($refererHost !== $request->getHost() || !is_string($refererPath)) {
            return null;
        }

        $query = parse_url($referer, PHP_URL_QUERY);
        $path  = $refererPath . (is_string($query) && $query !== '' ? '?' . $query : '');

        return SafeRelativePath::isSafeUnder($path, $adminPrefix) ? $path : null;
    }
}
