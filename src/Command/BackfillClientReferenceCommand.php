<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function sprintf;
use function str_contains;

/**
 * Upgrade step of 2.0 (owner decision Q-W4-2): copies the INTEGER `client_id` of submissions written before 1.7 into
 * `client_reference`, before the schema migration drops `client_id`.
 *
 * 2.0 no longer maps `client_id` (deprecated since 1.7) and no longer reads it as a fallback, so a submission whose
 * client is only in that column loses its client link when the column is dropped. The command runs on the connection of
 * the bundle's entity manager, with the configured table prefix, and only touches rows where `client_id` is set and
 * `client_reference` is empty (the 1.7 backfill of UPGRADING "To 1.7.0"), in one `UPDATE`:
 *
 * - idempotent: a second run finds nothing to copy, a row with a `client_reference` is never overwritten;
 * - safe while the application runs: 2.0 never writes `client_id`, 1.13 writes both columns together;
 * - when the column (or the table) does not exist (already dropped, or a schema created by 2.0) there is nothing to do;
 * - one statement: it changes every pending row or, when it fails, none (a lock conflict is reported, run it again).
 *
 * A lock conflict is reported as such (run it again); any other failure is a real error.
 */
#[AsCommand(
    name: 'nowo:contact-form:backfill-client-reference',
    description: 'Copy the legacy client_id of contact submissions into client_reference before the 2.0 schema migration drops client_id',
)]
final class BackfillClientReferenceCommand extends Command
{
    /** The INTEGER column 2.0 removed (deprecated since 1.7). */
    public const LEGACY_COLUMN = 'client_id';

    public function __construct(
        private readonly ContactFormEntityManagerResolver $entityManagerResolver,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the submissions that would be backfilled');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io            = new SymfonyStyle($input, $output);
        $entityManager = $this->entityManagerResolver->get();
        $connection    = $entityManager->getConnection();
        $platform      = $connection->getDatabasePlatform();
        $metadata      = $entityManager->getClassMetadata(ContactSubmission::class);
        $quoting       = $entityManager->getConfiguration()->getQuoteStrategy();
        $table         = $quoting->getTableName($metadata, $platform);
        $reference     = $quoting->getColumnName('clientReference', $metadata, $platform);

        if (!$this->hasLegacyColumn($connection, $table)) {
            $io->success(sprintf('The table "%s" has no "%s" column: nothing to backfill.', $metadata->getTableName(), self::LEGACY_COLUMN));

            return Command::SUCCESS;
        }

        $pending = $this->countPending($connection, $table, $reference);
        if ((bool) $input->getOption('dry-run')) {
            $io->success(sprintf('%d submission(s) would get their client_reference from client_id.', $pending));

            return Command::SUCCESS;
        }

        try {
            $updated = $pending === 0 ? 0 : (int) $connection->executeStatement(sprintf(
                'UPDATE %1$s SET %2$s = CAST(%3$s AS %4$s) WHERE %3$s IS NOT NULL AND %2$s IS NULL',
                $table,
                $reference,
                self::LEGACY_COLUMN,
                $platform instanceof AbstractMySQLPlatform ? 'CHAR(64)' : 'VARCHAR(64)',
            ));
        } catch (Throwable $exception) {
            if (!(new TransientFailureClassifier())->isTransient($exception)) {
                throw $exception;
            }

            $io->error('A lock conflict with another write stopped the backfill; nothing was changed. Run the command again.');

            return Command::FAILURE;
        }

        $left = $this->countPending($connection, $table, $reference);
        if ($left > 0) {
            $io->error(sprintf('%d submission(s) still have a client_id without a client_reference. Run the command again before dropping the column.', $left));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d submission(s) backfilled. Every client_id is now in client_reference: the schema migration may drop "%s.%s".', $updated, $metadata->getTableName(), self::LEGACY_COLUMN));

        return Command::SUCCESS;
    }

    /**
     * Whether the table still has the legacy column (portable on every platform and DBAL 3 / 4, without the schema
     * introspection API): a missing column or table is "nothing to backfill".
     */
    private function hasLegacyColumn(Connection $connection, string $table): bool
    {
        try {
            $connection->fetchOne(sprintf('SELECT %s FROM %s WHERE 1 = 0', self::LEGACY_COLUMN, $table));
        } catch (InvalidFieldNameException|TableNotFoundException) {
            return false;
        } catch (DriverException $exception) {
            // SQLite reports an unknown column in a SELECT without a dedicated DBAL exception.
            if ($connection->getDatabasePlatform() instanceof SQLitePlatform && str_contains($exception->getMessage(), 'no such column')) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    private function countPending(Connection $connection, string $table, string $reference): int
    {
        return (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL AND %s IS NULL', $table, self::LEGACY_COLUMN, $reference));
    }
}
