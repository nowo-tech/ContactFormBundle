<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Integration\Upload;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Enum\ContactFieldType;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\DddHost\Infrastructure\InMemoryAttachmentVault;
use Nowo\ContactFormBundle\Tests\Integration\Upload\Fixtures\UploadRecorder;
use Nowo\ContactFormBundle\Upload\UploadedContactFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function basename;
use function count;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_file;
use function str_repeat;

/**
 * 1.8 equivalence: the same submissions through the real public form give the same result with a host on the
 * deprecated `ContactFormFileUploadHandlerInterface` and with a host on the new `ContactFormFileUploaderInterface`.
 *
 * - Validation is unchanged: the same files are accepted or rejected (size limit, server-side MIME allowlist,
 *   spoofed extension, script content) and the port is never called for a rejected file.
 * - What the host storage sees is the same: client name (unchanged, traversal included), size, server-detected MIME
 *   type, content, form slug and field name; the move keeps only the last segment of the client name.
 * - The stored submission values are the same.
 *
 * Plus a DDD host (`ddd` variant) whose adapter maps the bundle VO to its own framework-free domain port.
 */
#[CoversClass(ContactFormSubmissionValueNormalizer::class)]
#[CoversClass(UploadedContactFile::class)]
final class FileUploadEquivalenceTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    /**
     * @return array<string, array{content: string|null, name: string, accepted: bool}>
     */
    private function cases(): array
    {
        return [
            'pdf'             => ['content' => self::PDF, 'name' => 'report.pdf', 'accepted' => true],
            'plain text'      => ['content' => "line one\nline two\n", 'name' => 'notes.txt', 'accepted' => true],
            'traversal name'  => ['content' => "just text\n", 'name' => '../../../etc/passwd.txt', 'accepted' => true],
            'backslash name'  => ['content' => "just text\n", 'name' => '..\\..\\win.ini.txt', 'accepted' => true],
            'oversized'       => ['content' => str_repeat("a\n", 1600), 'name' => 'big.txt', 'accepted' => false],
            'html as pdf'     => ['content' => "<!DOCTYPE html><html><body><script>alert(1)</script></body></html>\n", 'name' => 'invoice.pdf', 'accepted' => false],
            'php as image'    => ['content' => "<?php echo 'x';\n", 'name' => 'avatar.png', 'accepted' => false],
            'svg not allowed' => ['content' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'name' => 'logo.svg', 'accepted' => false],
            'no file'         => ['content' => null, 'name' => '', 'accepted' => true],
        ];
    }

    #[IgnoreDeprecations]
    public function testDeprecatedHandlerAndNewUploaderBehaveTheSame(): void
    {
        $legacy = $this->runCases('legacy');
        $new    = $this->runCases('new');

        foreach ($this->cases() as $case => $expected) {
            $old = $legacy[$case];
            $cur = $new[$case];

            self::assertSame($expected['accepted'] ? 302 : 422, $old['status'], $case . ': legacy status');
            self::assertSame($old['status'], $cur['status'], $case . ': status');
            self::assertSame($old['stored'], $cur['stored'], $case . ': stored submission value');
            self::assertSame($old['movedName'], $cur['movedName'], $case . ': moved file name');
            self::assertSame($old['movedContent'], $cur['movedContent'], $case . ': moved file content');

            if ($old['seen'] === null) {
                self::assertNull($cur['seen'], $case . ': the port is not called');
                continue;
            }

            self::assertNotNull($cur['seen'], $case);
            unset($old['seen']['storage'], $old['seen']['storedPath'], $cur['seen']['storage'], $cur['seen']['storedPath']);
            self::assertSame($old['seen'], $cur['seen'], $case . ': what the host storage sees');
            // The client name is the one UploadedFile::getClientOriginalName() gives (HttpFoundation keeps its last segment).
            self::assertSame((new UploadedFile(__FILE__, $expected['name'], null, null, true))->getClientOriginalName(), $cur['seen']['clientName'], $case . ': client name');
        }

        // Rejected files never reach either port; accepted ones always do.
        self::assertNull($new['oversized']['seen']);
        self::assertNull($new['html as pdf']['seen']);
        self::assertSame('passwd.txt', $new['traversal name']['movedName']);
        self::assertSame('win.ini.txt', $new['backslash name']['movedName']);
        self::assertSame('application/pdf', $new['pdf']['seen']['mimeType'] ?? null);
    }

    public function testDddHostAdapterStoresThroughItsOwnDomainPort(): void
    {
        $result = $this->runCases('ddd', ['pdf', 'traversal name', 'html as pdf']);

        self::assertSame(302, $result['pdf']['status']);
        self::assertSame('att-1', $result['pdf']['stored']);
        self::assertSame('att-2', $result['traversal name']['stored']);
        self::assertSame(422, $result['html as pdf']['status']);
        self::assertNull($result['html as pdf']['stored']);

        self::assertSame(
            [
                'att-1' => ['owner' => 'support/attachment', 'name' => 'report.pdf', 'content' => self::PDF],
                'att-2' => ['owner' => 'support/attachment', 'name' => 'passwd.txt', 'content' => "just text\n"],
            ],
            $result['vault'],
        );
    }

    /**
     * @param list<string>|null $only
     *
     * @return array<string, mixed>
     */
    private function runCases(string $variant, ?array $only = null): array
    {
        $kernel = new UploadPortsKernel($variant);
        $fs     = new Filesystem();
        $fs->remove($kernel->baseDir());
        $kernel->boot();
        $results = [];

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $em     = $this->manager($container);
            $schema = new SchemaTool($em);
            $schema->createSchema($em->getMetadataFactory()->getAllMetadata());
            $this->seed($em);

            $client = new KernelBrowser($kernel);
            $client->disableReboot();
            $recorder = $container->get(UploadRecorder::class);
            self::assertInstanceOf(UploadRecorder::class, $recorder);

            foreach ($this->cases() as $case => $spec) {
                if ($only !== null && !in_array($case, $only, true)) {
                    continue;
                }

                $client->request('GET', '/en/contact/support');
                $token = $client->getCrawler()->filterXPath('//input[@name="form[_token]"]')->attr('value');
                $files = [];
                if ($spec['content'] !== null) {
                    $path = $kernel->baseDir() . '/upload-' . md5($case);
                    file_put_contents($path, $spec['content']);
                    $files = ['form' => ['attachment' => new UploadedFile($path, $spec['name'], 'application/pdf', null, true)]];
                }

                $before = count($recorder->seen);
                $client->request('POST', '/en/contact/support', ['form' => ['email' => 'user@example.com', '_token' => $token]], $files);
                $status = $client->getResponse()->getStatusCode();
                $seen   = $recorder->seen[$before] ?? null;

                $em = $this->manager($container);
                $em->clear();
                $last   = $em->getRepository(ContactSubmission::class)->findOneBy([], ['id' => 'DESC']);
                $stored = null;
                if ($status === 302 && $last instanceof ContactSubmission) {
                    foreach ($last->getValues() as $value) {
                        if ($value->getFieldName() === 'attachment') {
                            $stored = $value->getValue();
                        }
                    }
                    $em->remove($last);
                    $em->flush();
                }

                $moved          = $seen['storedPath'] ?? null;
                $results[$case] = [
                    'status'       => $status,
                    'stored'       => $stored,
                    'seen'         => $seen,
                    'movedName'    => $moved === null ? null : basename($moved),
                    'movedContent' => $moved !== null && is_file($moved) ? file_get_contents($moved) : null,
                ];
            }

            if ($variant === 'ddd') {
                $vault = $container->get(InMemoryAttachmentVault::class);
                self::assertInstanceOf(InMemoryAttachmentVault::class, $vault);
                $results['vault'] = $vault->kept;
            }
        } finally {
            $kernel->shutdown();
            $fs->remove($kernel->baseDir());
        }

        return $results;
    }

    private function manager(ContainerInterface $container): EntityManagerInterface
    {
        $doctrine = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function seed(EntityManagerInterface $em): void
    {
        $form = (new ContactForm())
            ->setName('Support')
            ->setSlug('support')
            ->setEnabled(true)
            ->addTranslation((new ContactFormTranslation())->setLocale('en')->setTitle('Contact us')->setSuccessMessage('Thanks!'));

        $email = (new ContactFormField())
            ->setName('email')
            ->setType(ContactFieldType::Email)
            ->setRequired(true)
            ->setSortOrder(1)
            ->setForm($form)
            ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Email'));

        // 2k limit; the bundle's default MIME allowlist (PDF, text, office, raster images; no SVG / HTML).
        $attachment = (new ContactFormField())
            ->setName('attachment')
            ->setType(ContactFieldType::File)
            ->setRequired(false)
            ->setSortOrder(2)
            ->setOptions(['max_size' => '2k'])
            ->setForm($form)
            ->addTranslation((new ContactFormFieldTranslation())->setLocale('en')->setLabel('Attachment'));

        $form->addField($email);
        $form->addField($attachment);
        $em->persist($form);
        $em->flush();
        $em->clear();
    }
}
