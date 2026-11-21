<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sprintf;
use function str_contains;

/**
 * Refuses, when the container is built, every trace of the upload port removed in 2.0
 * (`Service\ContactFormFileUploadHandlerInterface`, deprecated since 1.8; REQ-ARCH-001 rule 3):
 *
 * - a service definition whose loading errors name the removed interface. A class registered by resource
 *   autodiscovery that still implements it cannot be loaded, so Symfony keeps the definition with only an error
 *   (`Class "…\ContactFormFileUploadHandlerInterface" not found while loading "App\…"`) and never reads its
 *   `#[AsAlias]` / `#[AsDecorator]` attributes; unused, the definition would later be removed without a word and the
 *   host's uploader (or a decorator that scans or quarantines files) would be dropped silently. Only the exact class
 *   name, quoted as Symfony quotes it, matches: an unrelated missing class keeps its lazy error;
 * - a service or an alias registered under the removed id (for example `#[AsAlias]` of it on a loadable class, or a
 *   `services.yaml` alias): 2.0 never reads that id, so the service is not silently adopted as the uploader either;
 * - a decorator of the removed id (also with `onInvalid: IGNORE`, which Symfony would drop without a word).
 *
 * Registered twice before optimization: at priority 1000, before any other pass looks at the definitions (loading
 * errors), and at priority 99, after Symfony has turned `#[AsDecorator]` attributes into decorated services. Each
 * message names the service, the removed interface, the version that deprecated it and the replacement.
 *
 * @internal registered by the bundle
 */
final class ContactFormRemovedPortsPass implements CompilerPassInterface
{
    /** Id (and class name) of the upload port deprecated since 1.8 and removed in 2.0. */
    public const REMOVED_UPLOAD_PORT = 'Nowo\\ContactFormBundle\\Service\\ContactFormFileUploadHandlerInterface';

    /** Second sentence of the upload port build errors. */
    public const HINT = '"Nowo\ContactFormBundle\Service\ContactFormFileUploadHandlerInterface" (deprecated since 1.8) was removed in 2.0, so a class that still implements it no longer loads: implement "Nowo\ContactFormBundle\Upload\ContactFormFileUploaderInterface" (store(UploadedContactFile, ContactFormFileTarget)) and alias, decorate or configure that id instead (see UPGRADING.md "To 1.8.0" and "To 2.0.0").';

    /**
     * @throws LogicException on the first trace of the removed port
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasAlias(self::REMOVED_UPLOAD_PORT) || $container->hasDefinition(self::REMOVED_UPLOAD_PORT)) {
            $target = $container->hasAlias(self::REMOVED_UPLOAD_PORT) ? sprintf(' (an alias of "%s")', (string) $container->getAlias(self::REMOVED_UPLOAD_PORT)) : '';

            throw new LogicException(sprintf('The service id "%s"%s is no longer read by NowoContactFormBundle. %s', self::REMOVED_UPLOAD_PORT, $target, self::HINT));
        }

        $quoted = '"' . self::REMOVED_UPLOAD_PORT . '"';
        foreach ($container->getDefinitions() as $id => $definition) {
            foreach ($definition->getErrors() as $error) {
                if (str_contains((string) $error, $quoted)) {
                    throw new LogicException(sprintf('The service "%s" cannot be loaded: %s %s', $id, (string) $error, self::HINT));
                }
            }

            if (($definition->getDecoratedService()[0] ?? null) === self::REMOVED_UPLOAD_PORT) {
                throw new LogicException(sprintf('The service "%s" decorates "%s", which no longer exists. Decorate "%s" instead. %s', $id, self::REMOVED_UPLOAD_PORT, ContactFormFileUploaderInterface::class, self::HINT));
            }
        }
    }
}
