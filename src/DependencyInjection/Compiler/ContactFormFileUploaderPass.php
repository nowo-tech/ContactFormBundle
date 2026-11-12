<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use Nowo\ContactFormBundle\Form\ContactFormFieldDefinitionStepType;
use Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface;
use Nowo\ContactFormBundle\Service\ContactFormSubmissionValueNormalizer;
use Nowo\ContactFormBundle\Service\NullContactFormFileUploadHandler;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function interface_exists;
use function is_a;
use function is_string;

/**
 * Wires the file ports (1.8, REQ-ARCH-001 rule 3).
 *
 * 1. `file_upload.service`: the 1.8 {@see ContactFormFileUploaderInterface} alias points at the service when its class
 *    implements that port; the deprecated {@see ContactFormFileUploadHandlerInterface} alias (marked deprecated) only
 *    when it implements that one. A service whose class cannot be resolved keeps the pre-1.8 wiring (deprecated port).
 * 2. The bundle's consumers (the value normalizer and the admin field step) get the port the host provides: the 1.8
 *    port when the host aliased, configured or decorated it (or provides neither: the null handler), else the
 *    deprecated port, so a host that implements only the old interface keeps working unchanged. A decorator of the
 *    deprecated port keeps that port selected (unless the 1.8 port is decorated too), so it is never skipped.
 *
 * Runs before optimization: host definitions are loaded, decorators are not applied yet (they follow the alias).
 */
final class ContactFormFileUploaderPass implements CompilerPassInterface
{
    public const SERVICE_PARAMETER = 'nowo_contact_form.file_upload.service';

    private const PACKAGE = 'nowo-tech/contact-form-bundle';

    public function process(ContainerBuilder $container): void
    {
        $this->aliasConfiguredService($container);

        $hostNew    = $this->isHostProvided($container, ContactFormFileUploaderInterface::class);
        $hostLegacy = $this->isHostProvided($container, ContactFormFileUploadHandlerInterface::class);
        // MZ-C-1: a decorator of the deprecated port (antivirus, quarantine…) must keep running, also when the
        // configured service implements the 1.8 port too. Only a decorator of the 1.8 port takes precedence.
        $legacyDecorated = $this->isDecorated($container, ContactFormFileUploadHandlerInterface::class)
            && !$this->isDecorated($container, ContactFormFileUploaderInterface::class);
        $port = $legacyDecorated || (!$hostNew && $hostLegacy)
            ? ContactFormFileUploadHandlerInterface::class
            : ContactFormFileUploaderInterface::class;

        if ($container->hasDefinition(ContactFormSubmissionValueNormalizer::class)) {
            $container->getDefinition(ContactFormSubmissionValueNormalizer::class)
                ->setArgument('$fileUploadHandler', new Reference($port));
        }

        if ($container->hasDefinition(ContactFormFieldDefinitionStepType::class)) {
            $container->getDefinition(ContactFormFieldDefinitionStepType::class)
                ->setArgument('$fileUploadHandler', new Reference($port));
        }
    }

    private function aliasConfiguredService(ContainerBuilder $container): void
    {
        $service = $container->hasParameter(self::SERVICE_PARAMETER) ? $container->getParameter(self::SERVICE_PARAMETER) : null;

        if (!is_string($service) || $service === '') {
            return;
        }

        $class = $this->classOf($container, $service);

        if ($class === null) {
            // Unknown class (factory, synthetic, missing service): the pre-1.8 wiring, without a deprecation.
            $container->setAlias(ContactFormFileUploadHandlerInterface::class, $service);

            return;
        }

        $implementsNew    = is_a($class, ContactFormFileUploaderInterface::class, true);
        $implementsLegacy = is_a($class, ContactFormFileUploadHandlerInterface::class, true);

        if ($implementsNew) {
            $container->setAlias(ContactFormFileUploaderInterface::class, $service);
        }

        if ($implementsLegacy || !$implementsNew) {
            // Also a class that implements neither port keeps the pre-1.8 alias (and its error at injection time).
            $container->setAlias(ContactFormFileUploadHandlerInterface::class, $service)
                ->setDeprecated(self::PACKAGE, '1.8', 'The "%alias_id%" service alias is deprecated, use "' . ContactFormFileUploaderInterface::class . '" instead.');
        }
    }

    /**
     * True when the port's alias no longer resolves to the bundle's null handler, or a definition decorates it.
     */
    private function isHostProvided(ContainerBuilder $container, string $port): bool
    {
        if ($this->isDecorated($container, $port)) {
            return true;
        }

        if (!$container->hasAlias($port)) {
            return $container->has($port);
        }

        return $this->resolveAlias($container, $port) !== NullContactFormFileUploadHandler::class;
    }

    private function isDecorated(ContainerBuilder $container, string $port): bool
    {
        foreach ($container->getDefinitions() as $definition) {
            if (($definition->getDecoratedService()[0] ?? null) === $port) {
                return true;
            }
        }

        return false;
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

    /**
     * @return class-string|null
     */
    private function classOf(ContainerBuilder $container, string $service): ?string
    {
        $id = $this->resolveAlias($container, $service);

        if (!$container->hasDefinition($id)) {
            return null;
        }

        $definition = $container->getDefinition($id);
        $class      = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        if (!is_string($class) || $definition->getFactory() !== null || (!class_exists($class) && !interface_exists($class))) {
            return null;
        }

        return $class;
    }
}
