<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Form\ContactFormType;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

use function ceil;
use function in_array;
use function is_array;
use function is_string;
use function max;

use const PHP_URL_HOST;
use const PHP_URL_PATH;
use const PHP_URL_QUERY;

/**
 * Admin CRUD controller for contact form definitions.
 */
#[Route('', name: 'nowo_contact_form_admin_')]
class ContactFormAdminController extends AbstractController
{
    public function __construct(
        private readonly ContactFormRepository $formRepository,
        private readonly TranslatorInterface $translator,
        #[Autowire(param: 'nowo_contact_form.default_retention_days')]
        private readonly int $defaultRetentionDays,
        private readonly ParameterBagInterface $parameterBag,
    ) {
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

        return $this->render('@NowoContactFormBundle/admin/form/index.html.twig', [
            'forms'    => $this->formRepository->findOrderedPage($page, $pageSize),
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
            'pageSize' => $pageSize,
        ]);
    }

    #[Route('/locale/{locale}', name: 'switch_locale', methods: ['GET'], priority: 10)]
    public function switchLocale(string $locale, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        if (!in_array($locale, $this->getEnabledLocales(), true)) {
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
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->getContactForm($id);

        if (!$this->isCsrfTokenValid('delete-contact-form-' . $form->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entityManager->remove($form);
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

        if ($symfonyForm->isSubmitted() && $symfonyForm->isValid()) {
            if ($isNew) {
                $entityManager->persist($contactForm);
            }

            $entityManager->flush();

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

        return $this->render('@NowoContactFormBundle/admin/form/form.html.twig', [
            'contactForm' => $contactForm,
            'form'        => $symfonyForm,
            'isNew'       => $isNew,
        ]);
    }

    private function ensureTranslationsForEnabledLocales(ContactForm $form): void
    {
        foreach ($this->getEnabledLocales() as $locale) {
            $this->ensureTranslationForLocale($form, $locale);
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

    private function ensureTranslationForLocale(ContactForm $form, string $locale): void
    {
        if ($form->findTranslation($locale) instanceof ContactFormTranslation) {
            return;
        }

        $form->addTranslation(
            (new ContactFormTranslation())->setLocale($locale !== '' ? $locale : 'en'),
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

    private function resolveSafeRedirectUrl(Request $request): ?string
    {
        $redirect = $request->query->getString('redirect');

        if ($redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            return $redirect;
        }

        $referer = $request->headers->get('referer');

        if (!is_string($referer) || $referer === '') {
            return null;
        }

        $refererHost = parse_url($referer, PHP_URL_HOST);
        $currentHost = $request->getHost();

        if ($refererHost !== null && $refererHost !== $currentHost) {
            return null;
        }

        $refererPath = parse_url($referer, PHP_URL_PATH);

        $adminPrefix = $this->parameterBag->get('nowo_contact_form.admin_route_prefix');
        if (!is_string($adminPrefix) || !is_string($refererPath) || !str_starts_with($refererPath, $adminPrefix)) {
            return null;
        }

        $query = parse_url($referer, PHP_URL_QUERY);

        return $refererPath . ($query ? '?' . $query : '');
    }
}
