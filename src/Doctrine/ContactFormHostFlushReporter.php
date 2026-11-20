<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormEntityManagerClearSubscriber;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 1.13 (REQ-ARCH-001 rule 6 deprecation layer): reports the application's pending changes that a contact form write
 * flushes while neither `doctrine.private_entity_manager` nor `doctrine.entity_manager` is set (2.0 then writes
 * through a private entity manager and no longer flushes them).
 *
 * Called right before each flush of the bundle (admin saves and deletes, public submissions, the retention cleanup).
 * For each entity class outside `Nowo\ContactFormBundle\Entity` with pending work in the flushed manager, the
 * host-flush probe of nowo-tech/transaction-doctrine ({@see HostFlushProbe}) triggers one `E_USER_DEPRECATED` per
 * class and unit of work: "Your pending changes to App\X were flushed by NowoContactFormBundle; 2.0 no longer does."
 * The bundle itself never changes an application entity, so every class reported is the application's own work.
 *
 * Disabled (nothing is inspected) when either option is set: `private_entity_manager: true` writes through the private
 * manager (which holds no application change), and an explicit `false` or `entity_manager` keeps the current
 * behaviour in 2.0 too. A {@see PrivateEntityManager} is never inspected.
 *
 * Read-only: it never flushes, clears, closes or resets a manager (REQ-RUNTIME-003). The probe is the shared
 * `nowo_transaction_doctrine.host_flush_probe` when the application registers NowoTransactionDoctrineBundle, else one
 * of its own. Its "already reported" classes are reset at every outermost unit-of-work boundary (request, Messenger
 * message, console command: {@see ContactFormEntityManagerClearSubscriber}) and at `kernel.reset`, so a FrankenPHP
 * worker with the kernel reset off starts every unit clean.
 *
 * @internal registered by the bundle; not an extension point
 */
final readonly class ContactFormHostFlushReporter implements ResetInterface
{
    /** The bundle named in the deprecation. */
    public const BUNDLE_NAME = 'NowoContactFormBundle';

    /** The bundle's entity namespace: its classes are never reported. */
    public const OWN_NAMESPACES = ['Nowo\\ContactFormBundle\\Entity'];

    private HostFlushProbe $probe;

    /**
     * @param bool $enabled true only while neither `doctrine.private_entity_manager` nor `doctrine.entity_manager` is set
     * @param HostFlushProbe|null $probe transaction-doctrine's shared probe (null: one of its own)
     */
    public function __construct(
        private bool $enabled = false,
        ?HostFlushProbe $probe = null,
    ) {
        $this->probe = $probe ?? new HostFlushProbe();
    }

    /**
     * Right before the bundle flushes `$entityManager`: reports the application's pending changes it would write.
     */
    public function beforeFlush(EntityManagerInterface $entityManager): void
    {
        if (!$this->enabled || $entityManager instanceof PrivateEntityManager) {
            return;
        }

        $this->probe->beforeHostFlush($entityManager, self::BUNDLE_NAME, self::OWN_NAMESPACES);
    }

    /** A new unit of work (request, message, command) starts: nothing was reported yet. */
    public function reset(): void
    {
        $this->probe->reset();
    }
}
