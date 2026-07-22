<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class IntegrationTestCase extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected static function createTestClient(): KernelBrowser
    {
        $client = static::createClient(['debug' => false]);
        $client->disableReboot();

        return $client;
    }

    protected function resetDatabase(): void
    {
        $entityManager = self::getEntityManager();
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool    = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected static function getEntityManager(): EntityManagerInterface
    {
        $doctrine = static::getContainer()->get('doctrine');

        if (!$doctrine instanceof ManagerRegistry) {
            throw new LogicException('Doctrine manager registry is not available.');
        }

        $manager = $doctrine->getManager();

        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('Doctrine entity manager is not available.');
        }

        return $manager;
    }

    protected function tearDown(): void
    {
        restore_exception_handler();
        restore_error_handler();
        parent::tearDown();
        self::ensureKernelShutdown();
    }
}
