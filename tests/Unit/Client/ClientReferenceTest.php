<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Client;

use InvalidArgumentException;
use Nowo\ContactFormBundle\Client\ClientReference;
use Nowo\ContactFormBundle\Client\ObjectClientReferenceResolver;
use Nowo\ContactFormBundle\Service\ClientLabelResolver;
use Nowo\ContactFormBundle\Service\ClientResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

use function str_repeat;

final class ClientReferenceTest extends TestCase
{
    public function testKeepsTheIdAsGivenAndNormalisesTheLabel(): void
    {
        $reference = new ClientReference(' 42', '  ');
        self::assertSame(' 42', $reference->id);
        self::assertNull($reference->label);

        self::assertSame(str_repeat('a', 255), (new ClientReference('7', str_repeat('a', 300)))->label);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'too long' => [str_repeat('x', 65)];
    }

    #[DataProvider('invalidIds')]
    public function testRejectsInvalidIds(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ClientReference($id);
    }

    public function testObjectResolverAdaptsTheClientEntity(): void
    {
        $client = new class {
            public string $email = 'client@example.com';

            public function getId(): Stringable
            {
                return new class implements Stringable {
                    public function __toString(): string
                    {
                        return '01890a5d-ac96-774b-bcce-b302099a8057';
                    }
                };
            }
        };
        $resolver = new ObjectClientReferenceResolver($this->clientResolver($client), new ClientLabelResolver(null, 'email'));

        $reference = $resolver->resolve();
        self::assertInstanceOf(ClientReference::class, $reference);
        self::assertSame('01890a5d-ac96-774b-bcce-b302099a8057', $reference->id);
        self::assertSame('client@example.com', $reference->label);
        self::assertSame($reference, $resolver->fromObject($reference));
    }

    public function testObjectResolverWithoutClientOrUsableId(): void
    {
        $labels = new ClientLabelResolver(null, 'email');
        self::assertNull((new ObjectClientReferenceResolver($this->clientResolver(null), $labels))->resolve());
        self::assertNull((new ObjectClientReferenceResolver($this->clientResolver(new stdClass()), $labels))->resolve());

        self::assertSame('12', ObjectClientReferenceResolver::idOf(new class {
            public function getId(): int
            {
                return 12;
            }
        }));
        self::assertNull(ObjectClientReferenceResolver::idOf(new class {
            public function getId(): float
            {
                return 1.5;
            }
        }));
    }

    private function clientResolver(?object $client): ClientResolverInterface
    {
        return new class($client) implements ClientResolverInterface {
            public function __construct(private readonly ?object $client)
            {
            }

            public function resolve(): ?object
            {
                return $this->client;
            }
        };
    }
}
