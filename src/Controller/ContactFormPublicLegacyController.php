<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Redirects legacy public contact URLs without a locale prefix.
 */
final class ContactFormPublicLegacyController extends AbstractController
{
    #[Route('/contact/{slug}', name: 'nowo_contact_form_public_legacy_show', methods: ['GET'])]
    public function redirectToLocalizedShow(string $slug, Request $request): Response
    {
        $locale = $request->getLocale();

        return $this->redirectToRoute('nowo_contact_form_public_show', [
            'slug'    => $slug,
            '_locale' => $locale !== '' ? $locale : 'en',
        ]);
    }
}
