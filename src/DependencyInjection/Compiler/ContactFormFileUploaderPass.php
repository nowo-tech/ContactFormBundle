<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function sprintf;

/**
 * Wires the file port (REQ-ARCH-001 rule 3). Since 2.0 {@see ContactFormFileUploaderInterface} is the only one.
 *
 * 1. `file_upload.service`: the port's alias points at the service. A service whose class is known must implement the
 *    port, otherwise the build fails (a class that still implements the removed
 *    `Service\ContactFormFileUploadHandlerInterface` cannot even be loaded, so its class is read without throwing).
 *    A service whose class is only known at runtime (factory, synthetic) cannot be checked here; a service id with no
 *    definition is left to the container, which reports the unknown service itself.
 * 2. The bundle's consumers (the value normalizer and the admin field step) get the port.
 *
 * The removed port's id, decorators and loading errors are refused by {@see ContactFormRemovedPortsPass}.
 *
 * Runs before optimization: host definitions are loaded, decorators are not applied yet (they follow the alias).
 */
final class ContactFormFileUploaderPass implements CompilerPassInterface
{
    public const SERVICE_PARAMETER = 'nowo_contact_form.file_upload.service';

    public function process(ContainerBuilder $container): void
    {
        $this->aliasConfiguredService($container);

        if ($container->hasDefinition(ContactFormSubmissionValueNormalizer::class)) {
            $container->getDefinition(ContactFormSubmissionValueNormalizer::class)
                ->setArgument('$fileUploadHandler', new Reference(ContactFormFileUploaderInterface::class));
        }

        if ($container->hasDefinition(ContactFormFieldDefinitionStepType::class)) {
            $container->getDefinition(ContactFormFieldDefinitionStepType::class)
                ->setArgument('$fileUploadHandler', new Reference(ContactFormFileUploaderInterface::class));
        }
    }

    private function aliasConfiguredService(ContainerBuilder $container): void
    {
        $service = $container->hasParameter(self::SERVICE_PARAMETER) ? $container->getParameter(self::SERVICE_PARAMETER) : null;

        if (!is_string($service) || $service === '') {
            return;
        }

        $id = $this->resolveAlias($container, $service);
        if ($container->hasDefinition($id)) {
            $definition = $container->getDefinition($id);
            $class      = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);
            $runtime    = $definition->getFactory() !== null || $definition->isSynthetic();
            if (!$runtime && is_string($class) && !($container->getReflectionClass($class, false)?->implementsInterface(ContactFormFileUploaderInterface::class) ?? false)) {
                throw new LogicException(sprintf('nowo_contact_form.file_upload.service: the service "%s" (%s) must implement "%s", the only upload port since 2.0. %s', $service, $class, ContactFormFileUploaderInterface::class, ContactFormRemovedPortsPass::HINT));
            }
        }

        $container->setAlias(ContactFormFileUploaderInterface::class, $service);
    }

    private function resolveAlias(ContainerBuilder $container, string $id): string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }

        return $id;
    }
}
