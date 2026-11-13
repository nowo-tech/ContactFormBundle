<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Nowo\ContactFormBundle\DependencyInjection\NowoContactFormExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_dir;

/**
 * Registers the mapping of the bundle entities on `doctrine.entity_manager` (or the default entity manager), 1.9.
 *
 * `doctrine.mapping_type: attribute` (the default) registers exactly the attribute driver of 1.7–1.8;
 * `doctrine.mapping_type: xml` registers the shipped XML mapping instead ({@see NowoContactFormExtension::XML_MAPPING_DIR},
 * the same tables, columns, indexes and constraints) for hosts whose managers load XML mapping only (REQ-ARCH-001
 * rule 2). The choice is read when the container is compiled, after the bundle configuration was processed.
 */
final readonly class ContactFormMappingDriverPass implements CompilerPassInterface
{
    public function __construct(
        private string $entityDir,
        private string $xmlDir = NowoContactFormExtension::XML_MAPPING_DIR,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $type = $container->hasParameter(NowoContactFormExtension::MAPPING_TYPE_PARAMETER)
            ? $container->getParameter(NowoContactFormExtension::MAPPING_TYPE_PARAMETER)
            : 'attribute';
        $managers = [NowoContactFormExtension::ENTITY_MANAGER_PARAMETER];

        if ($type === 'xml') {
            DoctrineOrmMappingsPass::createXmlMappingDriver([$this->xmlDir => ContactFormEntityManagerPass::ENTITY_NAMESPACE], $managers)
                ->process($container);

            return;
        }

        if (is_dir($this->entityDir)) {
            DoctrineOrmMappingsPass::createAttributeMappingDriver([ContactFormEntityManagerPass::ENTITY_NAMESPACE], [$this->entityDir], $managers)
                ->process($container);
        }
    }
}
