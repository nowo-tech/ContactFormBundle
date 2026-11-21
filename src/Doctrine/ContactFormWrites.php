<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Exception\ContactFormNotFoundException;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;

use function array_merge;
use function sprintf;
use function str_starts_with;

/**
 * Guards of the bundle's writes through its private entity manager (2.0, REQ-ARCH-001 rule 6; wave-1 lessons XS and
 * W1-2). Stateless: nothing is kept between calls (FrankenPHP worker mode with the kernel reset off).
 *
 * - {@see self::adoptForm()}: a contact form loaded through another manager (the application's `find()`, a host query,
 *   a route value resolver) is re-loaded by id in the bundle's manager before a write refers to it, so it is never
 *   persisted as a new row nor written through the wrong manager;
 * - {@see self::assertOnlyBundleEntities()}: right before a flush of the private manager, any entity it holds that is not
 *   a bundle entity (an application entity loaded or persisted through `nowo_contact_form.entity_manager`, for example
 *   by a listener) fails the write before anything is written: the private manager only ever writes the bundle's own
 *   rows, and the application flushes its entities through its own manager.
 *
 * Neither ever flushes, clears or detaches a manager (REQ-RUNTIME-003).
 *
 * @internal used by the bundle's write paths
 */
final class ContactFormWrites
{
    /** Namespace of the only classes the private manager may write. */
    public const ENTITY_NAMESPACE = 'Nowo\\ContactFormBundle\\Entity\\';

    private function __construct()
    {
    }

    /**
     * The contact form as an entity of `$entityManager`: the same object when that manager holds it, else the row
     * re-loaded by id.
     *
     * @throws ContactFormNotFoundException when the form was never stored or its row is gone
     */
    public static function adoptForm(EntityManagerInterface $entityManager, ContactForm $form): ContactForm
    {
        if ($entityManager->contains($form)) {
            return $form;
        }

        $id      = $form->getId();
        $managed = $id === null ? null : $entityManager->find(ContactForm::class, $id);
        if (!$managed instanceof ContactForm) {
            throw ContactFormNotFoundException::forSlug($form->getSlug());
        }

        return $managed;
    }

    /**
     * Fails when the private manager is about to write an entity that is not one of the bundle's. Other managers (a
     * dedicated `doctrine.entity_manager`, the DDD option) are the application's own choice and are not inspected.
     *
     * @throws LogicException naming the first foreign class; nothing has been written
     */
    public static function assertOnlyBundleEntities(EntityManagerInterface $entityManager): void
    {
        if (!$entityManager instanceof PrivateEntityManager || !$entityManager->isCreated()) {
            return;
        }

        // Managed entities (the identity map, by root class) and new or removed ones: read only, nothing is computed.
        $unitOfWork = $entityManager->getUnitOfWork();
        $classes    = [];
        foreach ($unitOfWork->getIdentityMap() as $class => $entities) {
            if ($entities !== []) {
                $classes[] = $class;
            }
        }
        foreach (array_merge($unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityDeletions()) as $entity) {
            // The mapped class, not a proxy class name.
            $classes[] = $entityManager->getClassMetadata($entity::class)->getName();
        }

        foreach ($classes as $class) {
            if (!str_starts_with($class, self::ENTITY_NAMESPACE)) {
                throw new LogicException(sprintf('A %s entity is held by NowoContactFormBundle\'s private entity manager: the bundle writes only its own entities there, never your application\'s. Load, persist and flush %s through your own entity manager (see UPGRADING.md "To 2.0.0", "Private manager only").', $class, $class));
            }
        }
    }
}
