<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class IntegrationTestCase extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected static function createTestClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient(['debug' => false]);
        $client->disableReboot();

        return $client;
    }

    protected function resetDatabase(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool    = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        restore_exception_handler();
        restore_error_handler();
        parent::tearDown();
        self::ensureKernelShutdown();
    }
}
