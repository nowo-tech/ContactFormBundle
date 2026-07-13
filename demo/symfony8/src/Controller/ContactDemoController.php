<?php

declare(strict_types=1);

namespace App\Controller;

use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demo home page linking to contact form features.
 */
final class ContactDemoController extends AbstractController
{
    public function __construct(
        private readonly ContactFormRepository $formRepository,
    ) {
    }

    #[Route('/{_locale}', name: 'demo_home', requirements: ['_locale' => 'en|es'], defaults: ['_locale' => 'en'])]
    public function index(): Response
    {
        return $this->render('demo/home.html.twig', [
            'contactForms' => $this->formRepository->findBy(['enabled' => true], ['name' => 'ASC']),
        ]);
    }
}
