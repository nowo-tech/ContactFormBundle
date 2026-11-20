<?php

declare(strict_types=1);

/*
 * Second process of the admin edit races (ContactFormConcurrencyDatabaseTest): saves an edit of a contact form or of a
 * form field with the bundle's own save unit and the row version of its page, inside an outer transaction that keeps the
 * row lock for <holdMs> ms, then commits. Prints "LOCKED" once the save ran (the row is locked and advanced) and "DONE"
 * after the commit.
 *
 * Usage: php save-edit.php <database url> <form|field> <id> <token> <holdMs> <new name>
 */

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Repository\ContactFormFieldRepository;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Tests\Integration\Database\ContactFormConcurrencyDatabaseTest;
use Nowo\ContactFormBundle\Tests\Support\FixedManagerRegistry;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $url, $kind, $id, $token, $holdMs, $name] = $argv;

$params     = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
$em         = ContactFormConcurrencyDatabaseTest::createManager($params);
$connection = $em->getConnection();
$connection->executeStatement($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'SET SESSION innodb_lock_wait_timeout = 10' : "SET lock_timeout = '10s'");
$registry = new FixedManagerRegistry($em);

$connection->beginTransaction();
if ($kind === 'form') {
    $entity = $em->find(ContactForm::class, (int) $id);
    if (!$entity instanceof ContactForm) {
        throw new RuntimeException('No form ' . $id);
    }
    $entity->setName($name);
    (new ContactFormRepository($registry))->saveEdit($entity, $em, (int) $token);
} else {
    $entity = $em->find(ContactFormField::class, (int) $id);
    if (!$entity instanceof ContactFormField) {
        throw new RuntimeException('No field ' . $id);
    }
    $entity->setName($name);
    (new ContactFormFieldRepository($registry))->saveEdit($entity, $em, (int) $token);
}
echo "LOCKED\n";
usleep(1000 * (int) $holdMs);
$connection->commit();
echo "DONE\n";
