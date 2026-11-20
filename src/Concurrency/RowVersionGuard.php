<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Concurrency;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;

use function array_map;
use function array_values;
use function explode;
use function implode;
use function spl_object_id;
use function sprintf;

/**
 * Compare-and-swap of the version column of an edited row (REQ-RUNTIME-003 rule 4, optimistic concurrency).
 *
 * `advance()` runs one atomic `UPDATE ... SET row_version = row_version + 1 WHERE id = ? AND row_version = ?` on the connection of
 * the entity manager. Inside the caller's transaction it also takes the row lock, so the flush that follows writes
 * the row nobody else can change, and a save that lost the race waits for the winner's commit and then matches no row.
 * It reads the database, never the identity map, so a manager that kept a loaded row across requests (worker mode
 * with the kernel reset off) cannot make a stale page look current.
 *
 * Stateless: a utility, no state on any service.
 */
final class RowVersionGuard
{
    /**
     * Advances the row by one version when it still has the version the edited page was built from.
     *
     * @param EntityManagerInterface $entityManager The manager of the entity (only its connection is used; it is never flushed, cleared or reset)
     * @param VersionedRowInterface $row The edited entity
     * @param int $expected The version carried by the submitted page
     *
     * @throws StaleVersionException when the row was saved by someone else since the page was opened, or no longer exists
     */
    public static function advance(EntityManagerInterface $entityManager, VersionedRowInterface $row, int $expected): void
    {
        $connection = $entityManager->getConnection();
        $platform   = $connection->getDatabasePlatform();
        $metadata   = $entityManager->getClassMetadata($row::class);
        $identifier = array_values($metadata->getIdentifierValues($row));
        $id         = $identifier[0] ?? null;

        $table    = implode('.', array_map($platform->quoteSingleIdentifier(...), explode('.', $metadata->getTableName())));
        $version  = $platform->quoteSingleIdentifier($metadata->getColumnName('rowVersion'));
        $affected = $connection->executeStatement(
            sprintf(
                'UPDATE %s SET %s = %s + 1 WHERE %s = ? AND %s = ?',
                $table,
                $version,
                $version,
                $platform->quoteSingleIdentifier($metadata->getColumnName($metadata->getSingleIdentifierFieldName())),
                $version,
            ),
            [$id, $expected],
        );

        if ((int) $affected !== 1) {
            throw new StaleVersionException($row::class, $id, $expected);
        }

        if ($entityManager instanceof PrivateEntityManager) {
            // 1.13: a rollback of this level now makes the private manager's identity map stale (row version in memory).
            $entityManager->noteWrite();
        }

        $row->setRowVersion($expected + 1);
    }

    /**
     * Drops the unsaved form changes of a refused (stale) edit from the managed entities: the row and the children of
     * its collections (translations) are refreshed, and the orphan removals the form scheduled are cancelled. Never a
     * clear or a reset of the manager (it may be the host's). Without it, a later flush of the same manager (worker
     * mode, kernel reset off) would write the outdated page over the other admin's save, or delete a translation the
     * form had blanked (`removeElement()` on an orphan-removal collection schedules the delete at once).
     * Children the form added and that were never persisted are dropped with the row's refreshed collections.
     *
     * @param EntityManagerInterface $entityManager The manager of the entity
     * @param VersionedRowInterface $row The edited entity
     * @param iterable<Collection<array-key, covariant object>> $collections The collections the form edited through the row (translations), read before the refresh
     */
    public static function discard(EntityManagerInterface $entityManager, VersionedRowInterface $row, iterable $collections = []): void
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $children   = [];
        foreach ($collections as $collection) {
            // The snapshot holds the children as loaded, including those the form removed from the collection.
            foreach ($collection instanceof PersistentCollection ? $collection->getSnapshot() : [] as $child) {
                $children[spl_object_id($child)] = $child;
            }
            foreach ($collection as $child) {
                $children[spl_object_id($child)] = $child;
            }
        }

        $entityManager->refresh($row);
        foreach ($children as $child) {
            $unitOfWork->cancelOrphanRemoval($child);
            if ($entityManager->contains($child)) {
                $entityManager->refresh($child);
            }
        }
    }
}
