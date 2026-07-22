<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Controller;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Service\ClientResolverInterface;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionRateLimiter;
use Nowo\ContactFormBundle\Service\ContactSubmissionProcessor;
use Nowo\ContactFormBundle\Service\DynamicContactFormBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public controller for rendering and submitting contact forms.
 */
#[Route(
    '/{_locale}/contact',
    name: 'nowo_contact_form_public_',
    requirements: ['_locale' => '[a-z]{2}'],
    defaults: ['_locale' => 'en'],
)]
class ContactFormPublicController extends AbstractController
{
    public function __construct(
        private readonly ContactFormRepository $formRepository,
        private readonly DynamicContactFormBuilder $formBuilder,
        private readonly ContactSubmissionProcessor $submissionProcessor,
        private readonly ClientResolverInterface $clientResolver,
        private readonly ContactFormSubmissionRateLimiter $submissionRateLimiter,
    ) {
    }

    #[Route('/{slug}', name: 'show', methods: ['GET', 'POST'])]
    public function show(string $slug, Request $request): Response
    {
        $contactForm = $this->formRepository->findOneEnabledBySlug($slug);

        if (!$contactForm instanceof ContactForm) {
            throw $this->createNotFoundException();
        }

        $locale      = $request->getLocale();
        $translation = $contactForm->getTranslationForLocale($locale);
        $symfonyForm = $this->formBuilder->createForm($contactForm, $locale);
        $symfonyForm->handleRequest($request);

        if ($symfonyForm->isSubmitted() && $symfonyForm->isValid()) {
            $this->submissionRateLimiter->consume($request, $slug);

            $this->submissionProcessor->process(
                $contactForm,
                $symfonyForm,
                $request,
                $locale,
                $this->clientResolver->resolve(),
            );

            $this->addFlash(
                'success',
                $translation->getSuccessMessage() ?? 'Thank you for your message.',
            );

            return $this->redirectToRoute('nowo_contact_form_public_show', [
                'slug'    => $slug,
                '_locale' => $locale,
            ]);
        }

        return $this->render('@NowoContactFormBundle/public/contact_form.html.twig', [
            'contactForm' => $contactForm,
            'translation' => $translation,
            'form'        => $symfonyForm,
        ]);
    }
}
