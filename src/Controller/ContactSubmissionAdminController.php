<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Repository\ContactSubmissionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use function ceil;
use function max;

/**
 * Admin controller for viewing and managing contact submissions.
 *
 * @final since 1.5, will be final in 2.0 (not an extension point; decorate or replace the service instead)
 */
#[Route(
    '/{formId}/submissions',
    name: 'nowo_contact_form_submissions_',
    requirements: ['formId' => '\d+'],
)]
class ContactSubmissionAdminController extends AbstractController
{
    public function __construct(
        private readonly ContactFormRepository $formRepository,
        private readonly ContactSubmissionRepository $submissionRepository,
        private readonly TranslatorInterface $translator,
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(int $formId, Request $request): Response
    {
        $form     = $this->getContactForm($formId);
        $pageSize = max(1, (int) $this->parameterBag->get('nowo_contact_form.web_ui.list_page_size'));
        $page     = max(1, $request->query->getInt('page', 1));
        $total    = $this->submissionRepository->countByForm($form);
        $pages    = max(1, (int) ceil($total / $pageSize));
        if ($page > $pages) {
            $page = $pages;
        }

        return $this->render('@NowoContactFormBundle/admin/submission/index.html.twig', [
            'contactForm' => $form,
            'submissions' => $this->submissionRepository->findByFormOrderedPage($form, $page, $pageSize),
            'page'        => $page,
            'pages'       => $pages,
            'total'       => $total,
            'pageSize'    => $pageSize,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $formId, int $id): Response
    {
        $form       = $this->getContactForm($formId);
        $submission = $this->getSubmission($form, $id);

        // REQ-TWIG-005: the delete button is a Symfony form; `_token` keeps the id checked by delete().
        $deleteForm = $this->container->get('form.factory')->createNamed('', FormType::class, null, [
            'action'          => $this->generateUrl('nowo_contact_form_submissions_delete', ['formId' => $formId, 'id' => $submission->getId()]),
            'method'          => 'POST',
            'csrf_field_name' => '_token',
            'csrf_token_id'   => 'delete-submission-' . $submission->getId(),
        ]);

        return $this->render('@NowoContactFormBundle/admin/submission/show.html.twig', [
            'contactForm' => $form,
            'submission'  => $submission,
            'deleteForm'  => $deleteForm->createView(),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $formId, int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form       = $this->getContactForm($formId);
        $submission = $this->getSubmission($form, $id);

        if (!$this->isCsrfTokenValid('delete-submission-' . $submission->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $entityManager->remove($submission);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('nowo_contact_form.admin.submission.deleted', [], 'NowoContactFormBundle'));

        return $this->redirectToRoute('nowo_contact_form_submissions_index', ['formId' => $formId]);
    }

    private function getContactForm(int $formId): ContactForm
    {
        $form = $this->formRepository->find($formId);

        if (!$form instanceof ContactForm) {
            throw $this->createNotFoundException();
        }

        return $form;
    }

    private function getSubmission(ContactForm $form, int $id): ContactSubmission
    {
        $submission = $this->submissionRepository->find($id);

        if (!$submission instanceof ContactSubmission || $submission->getForm()?->getId() !== $form->getId()) {
            throw $this->createNotFoundException();
        }

        return $submission;
    }
}
