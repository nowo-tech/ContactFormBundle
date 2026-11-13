<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Nowo\ContactFormBundle\Entity\ContactForm;
use Nowo\ContactFormBundle\Entity\ContactFormField;
use Nowo\ContactFormBundle\Entity\ContactFormFieldTranslation;
use Nowo\ContactFormBundle\Entity\ContactFormTranslation;
use Nowo\ContactFormBundle\Entity\ContactSubmission;
use Nowo\ContactFormBundle\Entity\ContactSubmissionValue;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function dirname;
use function get_object_vars;
use function ksort;
use function sys_get_temp_dir;

use const CASE_LOWER;
use const PHP_VERSION_ID;

/**
 * 1.9.0: the shipped XML mapping (src/Resources/config/doctrine-xml/*.orm.xml) describes exactly the tables, columns,
 * indexes, constraints, associations, cascades and PHP types of the entity attributes, so switching
 * `doctrine.mapping_type` (or a host mapping the bundle itself from that directory) moves no column and drops nothing.
 * Checked with both naming strategies a host may use (column names that the attributes leave to the strategy are
 * left to it in the XML too).
 */
#[CoversNothing]
final class XmlMappingParityTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, bool}>
     */
    public static function entities(): iterable
    {
        foreach ([ContactForm::class, ContactFormField::class, ContactFormFieldTranslation::class, ContactFormTranslation::class, ContactSubmission::class, ContactSubmissionValue::class] as $class) {
            yield $class . ' (underscore naming)' => [$class, true];
            yield $class . ' (default naming)' => [$class, false];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('entities')]
    public function testTheXmlMetadataMatchesTheAttributes(string $class, bool $underscore): void
    {
        $attribute = $this->manager(new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity']), $underscore)->getClassMetadata($class);
        $xml       = $this->manager($this->xmlDriver(), $underscore)->getClassMetadata($class);

        self::assertSame($attribute->getTableName(), $xml->getTableName());
        self::assertSame($attribute->getIdentifier(), $xml->getIdentifier());
        self::assertSame($attribute->generatorType, $xml->generatorType);
        self::assertEquals($this->sorted($attribute->table['indexes'] ?? []), $this->sorted($xml->table['indexes'] ?? []));
        self::assertEquals($this->sorted($attribute->table['uniqueConstraints'] ?? []), $this->sorted($xml->table['uniqueConstraints'] ?? []));
        self::assertEquals($this->fields($attribute), $this->fields($xml));
        self::assertEquals($this->associations($attribute), $this->associations($xml));
        self::assertSame($attribute->customRepositoryClassName, $xml->customRepositoryClassName);
        self::assertSame($attribute->lifecycleCallbacks, $xml->lifecycleCallbacks);
    }

    public function testTheXmlSchemaHasNoDifference(): void
    {
        $attribute = $this->manager(new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity']));
        $xml       = $this->manager($this->xmlDriver());
        $from      = (new SchemaTool($attribute))->getSchemaFromMetadata($attribute->getMetadataFactory()->getAllMetadata());
        $to        = (new SchemaTool($xml))->getSchemaFromMetadata($xml->getMetadataFactory()->getAllMetadata());

        self::assertCount(6, $xml->getMetadataFactory()->getAllMetadata());
        self::assertTrue($attribute->getConnection()->createSchemaManager()->createComparator()->compareSchemas($from, $to)->isEmpty());
    }

    private function xmlDriver(): MappingDriver
    {
        return new SimplifiedXmlDriver([NowoContactFormExtension::XML_MAPPING_DIR => 'Nowo\\ContactFormBundle\\Entity']);
    }

    private function manager(MappingDriver $driver, bool $underscore = true): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl($driver);
        $config->setNamingStrategy($underscore ? new UnderscoreNamingStrategy(CASE_LOWER) : new DefaultNamingStrategy());
        $config->setProxyDir(sys_get_temp_dir() . '/nowo-contact-form-xml-test-proxies');
        $config->setProxyNamespace('NowoContactFormXmlTestProxies');
        $config->enableNativeLazyObjects(PHP_VERSION_ID >= 80400);

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, array<string, mixed>>
     */
    private function fields(ClassMetadata $metadata): array
    {
        $fields = [];
        foreach ($metadata->fieldMappings as $name => $mapping) {
            $fields[$name] = get_object_vars($mapping);
        }
        ksort($fields);

        return $fields;
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, array<string, mixed>>
     */
    private function associations(ClassMetadata $metadata): array
    {
        $associations = [];
        foreach ($metadata->associationMappings as $name => $mapping) {
            $vars                = get_object_vars($mapping);
            $vars['joinColumns'] = array_map(get_object_vars(...), $vars['joinColumns'] ?? []);
            $associations[$name] = $vars;
        }
        ksort($associations);

        return $associations;
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     *
     * @return array<string, array<string, mixed>>
     */
    private function sorted(array $definitions): array
    {
        ksort($definitions);

        return $definitions;
    }
}
