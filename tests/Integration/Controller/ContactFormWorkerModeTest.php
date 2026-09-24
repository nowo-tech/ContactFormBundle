<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Controller;

use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\EventSubscriber\ContactFormEntityManagerSubscriber;
use Nowo\ContactFormBundle\Repository\ContactFormRepository;
use Nowo\ContactFormBundle\Service\ContactFormEntityManagerResolver;
use Nowo\ContactFormBundle\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Consecutive requests on the same kernel with services_resetter skipped (FrankenPHP worker mode, scenario B).
 */
#[CoversClass(ContactFormEntityManagerSubscriber::class)]
#[CoversClass(ContactFormEntityManagerResolver::class)]
final class ContactFormWorkerModeTest extends IntegrationTestCase
{
    public function testAdminEditMadeElsewhereIsVisibleOnNextRequest(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $this->seedContactForm('support');

        $this->requestWithoutReset($client, 'GET', '/en/contact/support');
        self::assertStringContainsString('Contact us', (string) $client->getResponse()->getContent());

        $connection = self::getEntityManager()->getConnection();
        $connection->executeStatement(
            'UPDATE ' . self::getEntityManager()->getClassMetadata(ContactFormTranslation::class)->getTableName() . ' SET title = ?',
            ['Talk to us'],
        );
        $connection->executeStatement(
            'UPDATE ' . self::getEntityManager()->getClassMetadata(ContactFormFieldTranslation::class)->getTableName() . ' SET label = ?',
            ['Your e-mail'],
        );

        $this->requestWithoutReset($client, 'GET', '/en/contact/support');
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Talk to us', $content);
        self::assertStringContainsString('Your e-mail', $content);
    }

    public function testSubmissionIsDetachedAndClosedManagerIsRecoveredOnNextRequest(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $this->seedContactForm('support');

        $this->submit($client, 'first@example.com');
        self::assertResponseRedirects('/en/contact/support');

        $identityMap = self::getEntityManager()->getUnitOfWork()->getIdentityMap();
        self::assertSame([], $identityMap[ContactSubmission::class] ?? []);
        self::assertSame([], $identityMap[ContactSubmissionValue::class] ?? []);

        self::getEntityManager()->close();

        $this->submit($client, 'second@example.com');
        self::assertResponseRedirects('/en/contact/support');
        self::assertTrue(self::getEntityManager()->isOpen());
        self::assertSame(2, self::getEntityManager()->getRepository(ContactSubmission::class)->count([]));

        $formRepository = self::getContainer()->get(ContactFormRepository::class);
        self::assertInstanceOf(ContactFormRepository::class, $formRepository);
        $repositoryManager = (new ReflectionMethod(ContactFormRepository::class, 'getEntityManager'))
            ->invoke($formRepository);
        self::assertTrue($repositoryManager->isOpen());
        self::assertNotNull($formRepository->findOneEnabledBySlug('support'));
    }

    public function testFailedFlushDoesNotBreakTheNextRequest(): void
    {
        $client = self::createTestClient();
        $this->resetDatabase();
        $this->seedContactForm('support');

        $valuesTable = self::getEntityManager()->getClassMetadata(ContactSubmissionValue::class)->getTableName();
        $connection  = self::getEntityManager()->getConnection();
        $connection->executeStatement(
            'CREATE TRIGGER worker_mode_fail BEFORE INSERT ON ' . $valuesTable . " BEGIN SELECT RAISE(ABORT, 'insert failed'); END",
        );

        try {
            $this->submit($client, 'broken@example.com');
            self::assertResponseStatusCodeSame(500);
            self::assertTrue(self::getEntityManager()->isOpen());
        } finally {
            $connection->executeStatement('DROP TRIGGER worker_mode_fail');
        }

        $this->submit($client, 'recovered@example.com');
        self::assertResponseRedirects('/en/contact/support');
    }

    private function submit(KernelBrowser $client, string $email): void
    {
        $this->requestWithoutReset($client, 'GET', '/en/contact/support');
        $token = $client->getCrawler()->filterXPath('//input[@name="form[_token]"]')->attr('value');

        $this->requestWithoutReset($client, 'POST', '/en/contact/support', [
            'form' => [
                'email'  => $email,
                '_token' => $token,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function requestWithoutReset(KernelBrowser $client, string $method, string $uri, array $parameters = []): void
    {
        (new ReflectionProperty(Kernel::class, 'resetServices'))->setValue($client->getKernel(), false);

        $client->request($method, $uri, $parameters);
    }

    private function seedContactForm(string $slug): void
    {
        $form = (new ContactForm())
            ->setName('Support')
            ->setSlug($slug)
            ->setEnabled(true)
            ->addTranslation(
                (new ContactFormTranslation())
                    ->setLocale('en')
                    ->setTitle('Contact us')
                    ->setSuccessMessage('Thanks!'),
            );

        $field = (new ContactFormField())
            ->setName('email')
            ->setType(ContactFieldType::Email)
            ->setRequired(true)
            ->setForm($form)
            ->addTranslation(
                (new ContactFormFieldTranslation())
                    ->setLocale('en')
                    ->setLabel('Email'),
            );

        $form->addField($field);

        $em = self::getEntityManager();
        $em->persist($form);
        $em->flush();
    }
}
