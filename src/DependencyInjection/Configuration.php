<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection;

use Nowo\ContactFormBundle\Service\DynamicContactFormBuilder;
use Nowo\ContactFormBundle\Ui\ContactFormPages;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_diff;
use function array_key_exists;
use function array_keys;
use function implode;
use function in_array;
use function is_scalar;
use function is_string;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Defines the configuration tree for the contact form bundle.
 *
 * Final since 2.0 (announced with `@final` since 1.5).
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_contact_form';

    /** Default admin route prefix (web_ui.path_prefix unset). */
    public const DEFAULT_PATH_PREFIX = '/admin/contact-forms';

    /** 1.x default layout (removed in 1.6; mapped to null = nowo_ui_kit.layouts.admin). */
    public const LEGACY_DEFAULT_LAYOUT = '@NowoContactFormBundle/admin/layout.html.twig';

    /**
     * Values the removed `web_ui.css_framework` accepted (1.x). Kept as a public constant; nothing reads it since 2.0.
     */
    public const CSS_FRAMEWORKS = ['bootstrap', 'bootstrap4', 'bootstrap5', 'tailwind', 'foundation', 'custom', 'tabler', 'none'];

    /**
     * Keys removed in 2.0 (deprecated since 1.6) => what replaces them. A leftover key fails the build with this text
     * instead of Symfony's generic "Unrecognized option".
     */
    public const REMOVED_ROOT_KEYS = [
        'admin_route_prefix' => 'set "nowo_contact_form.web_ui.path_prefix" instead',
    ];

    /** `web_ui` keys removed in 2.0 (deprecated since 1.6) => what replaces them. */
    public const REMOVED_WEB_UI_KEYS = [
        'css_framework' => 'set "nowo_ui_kit.css_framework" instead (the CSS stack is inherited from UiKit)',
        'icon_set'      => 'set "nowo_ui_kit.icon_set" instead',
    ];

    /** Deprecation of `doctrine.private_entity_manager: true` (2.0; the key is removed in 3.0). */
    public const PRIVATE_ENTITY_MANAGER_DEPRECATION = 'The "nowo_contact_form.doctrine.private_entity_manager" option has no effect since 2.0 without a dedicated doctrine.entity_manager (the private entity manager is then the only mode): remove it. With a dedicated doctrine.entity_manager, true still selects the private manager on it and removing the key switches that manager to direct flushing (your pending changes on it are flushed too), so keep it until 3.0 unless you want that. The key is removed in 3.0.';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $rootNode    = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
                ->ifArray()
                ->then(static fn (array $v): array => self::refuseRemovedKeys($v, self::REMOVED_ROOT_KEYS, ''))
            ->end()
            ->children()
                ->scalarNode('client_entity_class')
                    ->defaultNull()
                    ->info('Optional FQCN of the host client entity for linking submissions.')
                ->end()
                ->scalarNode('client_label_property')
                    ->defaultValue('email')
                    ->info('Property or getter used to display linked clients in admin.')
                ->end()
                ->scalarNode('client_user_accessor')
                    ->defaultNull()
                    ->info('Optional method on the authenticated user that returns the client entity (e.g. getClient).')
                ->end()
                ->scalarNode('client_reference_resolver')
                    ->defaultNull()
                    ->info('Optional service id implementing ClientReferenceResolverInterface (1.7): the client of the public form as an id reference (DDD hosts, no entity). Null = the client entity from client_entity_class / client_user_accessor.')
                ->end()
                ->scalarNode('ip_anonymization_salt')
                    ->defaultValue('%kernel.secret%')
                    ->info('Salt used when hashing IP addresses for GDPR storage.')
                ->end()
                ->integerNode('default_retention_days')
                    ->defaultValue(365)
                    ->min(1)
                    ->max(3650)
                    ->info('Default data retention period for new contact forms.')
                ->end()
                ->arrayNode('phone_prefixes')
                    ->info('Default international dialing prefixes for phone fields (code => label).')
                    ->defaultValue([
                        '+34'  => 'ES (+34)',
                        '+1'   => 'US (+1)',
                        '+33'  => 'FR (+33)',
                        '+44'  => 'UK (+44)',
                        '+49'  => 'DE (+49)',
                        '+351' => 'PT (+351)',
                    ])
                    ->normalizeKeys(false)
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('phone_input')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('value_format')
                            ->defaultValue('CONCATENATED')
                            ->info('Model format passed to PhoneInputBundle PhoneType (CONCATENATED recommended for submissions).')
                        ->end()
                        ->scalarNode('default_country')
                            ->defaultValue('ES')
                            ->info('Fallback default country ISO when a phone field does not specify one.')
                        ->end()
                        ->booleanNode('country_prefix_selector')
                            ->defaultTrue()
                            ->info('Enable the country prefix selector on PhoneInputBundle fields.')
                        ->end()
                        ->booleanNode('show_flag')
                            ->defaultTrue()
                            ->info('Show country flags in PhoneInputBundle prefix selector.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static function (array $v): array {
                            $v = self::refuseRemovedKeys($v, self::REMOVED_WEB_UI_KEYS, 'web_ui.');
                            // The 1.x default layout was removed (REQ-UI-003): null = nowo_ui_kit.layouts.admin.
                            if (($v['layout_template'] ?? null) === self::LEGACY_DEFAULT_LAYOUT) {
                                $v['layout_template'] = null;
                            }

                            return $v;
                        })
                    ->end()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('When false, admin CRUD routes respond 404 even if the bundle routes are imported.')
                        ->end()
                        ->scalarNode('path_prefix')
                            ->defaultNull()
                            ->info('URL path prefix of the admin routes (default /admin/contact-forms; host should lock it with security.access_control).')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || trim($v) === ''))
                                ->thenInvalid('web_ui.path_prefix must be a non-empty path or null.')
                            ->end()
                        ->end()
                        ->scalarNode('layout_template')
                            ->defaultNull()
                            ->info('Layout the admin pages extend (REQ-UI-003). Null (default) = nowo_ui_kit.layouts.admin. It must render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...): the 1.x layout bridge was removed in 2.0.')
                        ->end()
                        ->scalarNode('public_layout_template')
                            ->defaultNull()
                            ->info('Layout of the public contact form page. Null (default) = nowo_ui_kit.layouts.guest.')
                        ->end()
                        ->arrayNode('templates')
                            ->info('Per-page template overrides (page id => template): form_index, form_form, field_index, field_form, submission_index, submission_show, public_form. Extend @!NowoContactFormBundle/... and override a data block.')
                            ->useAttributeAsKey('page')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => array_diff(array_keys($v), array_keys(ContactFormPages::TEMPLATES)) !== [])
                                ->then(static function (array $v): never {
                                    throw new InvalidConfigurationException(sprintf('Unknown web_ui.templates page(s) "%s"; known: %s.', implode('", "', array_diff(array_keys($v), array_keys(ContactFormPages::TEMPLATES))), implode(', ', array_keys(ContactFormPages::TEMPLATES))));
                                })
                            ->end()
                        ->end()
                        ->integerNode('list_page_size')
                            ->defaultValue(20)
                            ->min(1)
                            ->max(200)
                            ->info('Default page size for admin form and submission lists.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('access_checker')
                            ->defaultNull()
                            ->info('Optional service id implementing ContactFormAccessCheckerInterface.')
                        ->end()
                        ->arrayNode('access_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->info('Roles that may access admin CRUD. Empty list is fail-closed (deny via access checker). Use allow_unauthenticated for demos only.')
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->defaultFalse()
                            ->info('DEV/DEMO ONLY. When true, admin UI may load without SecurityBundle / without login. Production MUST keep false.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('notifications')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                            ->info('Enable submission notifications via ContactSubmissionNotifierInterface.')
                        ->end()
                        ->scalarNode('service')
                            ->defaultNull()
                            ->info('Custom service id implementing ContactSubmissionNotifierInterface (overrides built-in notifiers).')
                        ->end()
                        ->scalarNode('default_recipient')
                            ->defaultNull()
                            ->info('Fallback notification recipient when the form has no notification_email.')
                        ->end()
                        ->arrayNode('mailer')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')
                                    ->defaultFalse()
                                    ->info('Send email via symfony/mailer when installed.')
                                ->end()
                                ->scalarNode('from')
                                    ->defaultValue('noreply@example.com')
                                    ->info('Sender address for mailer notifications.')
                                ->end()
                                ->scalarNode('subject')
                                    ->defaultValue('New contact submission: {form}')
                                    ->info('Email subject template; {form} is replaced with the form name.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('file_upload')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('service')
                            ->defaultNull()
                            ->info('Service id that stores files submitted through file fields (required for them): implements ContactFormFileUploaderInterface (the only upload port since 2.0).')
                        ->end()
                        ->scalarNode('max_size')
                            ->defaultValue(DynamicContactFormBuilder::DEFAULT_FILE_MAX_SIZE)
                            ->info('Default maximum upload size for file fields (Symfony File constraint format: 500k, 5M, 2Mi, bytes). Null = no bundle limit (PHP upload_max_filesize still applies). Overridable per field via options.max_size.')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_scalar($v) || preg_match('/^\\d++(k|ki|m|mi)?$/i', (string) $v) !== 1))
                                ->thenInvalid('Invalid file_upload.max_size %s: use bytes or a k/ki/M/Mi suffix (e.g. "5M").')
                            ->end()
                        ->end()
                        ->arrayNode('mime_types')
                            ->info('Default allowed MIME types for file fields (wildcards like "image/*" allowed). Empty list = any type. Overridable per field via options.mime_types.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue(DynamicContactFormBuilder::DEFAULT_FILE_MIME_TYPES)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('public_submission_rate_limit')
                    ->addDefaultsIfNotSet()
                    ->info('Rate limit for public form submissions (every POST, valid or not) per IP and form slug.')
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('limit')->defaultValue(5)->min(1)->end()
                        ->integerNode('interval_seconds')->defaultValue(60)->min(1)->end()
                        ->scalarNode('limiter')
                            ->defaultNull()
                            ->info('Optional symfony/rate-limiter factory service id (e.g. limiter.contact_form): atomic counting with a lock store instead of the cache.app counter; limit / interval_seconds are then the limiter\'s own.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('public_field_max_length')
                    ->addDefaultsIfNotSet()
                    ->info('Maximum length (characters) of submitted values: text / email / URL fields and textareas.')
                    ->children()
                        ->integerNode('text')->defaultValue(DynamicContactFormBuilder::DEFAULT_TEXT_MAX_LENGTH)->min(1)->end()
                        ->integerNode('textarea')->defaultValue(DynamicContactFormBuilder::DEFAULT_TEXTAREA_MAX_LENGTH)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => ($v['private_entity_manager'] ?? null) === false)
                        ->then(static function (array $v): never {
                            throw new InvalidConfigurationException(self::privateEntityManagerFalseMessage(is_string($v['entity_manager'] ?? null) ? $v['entity_manager'] : null));
                        })
                    ->end()
                    ->children()
                        ->scalarNode('table_prefix')
                            ->defaultValue('')
                            ->info('Prefix prepended to table names (nowo_contact_form, nowo_contact_submission, …). Empty = no prefix.')
                        ->end()
                        ->scalarNode('entity_manager')
                            ->defaultNull()
                            ->info('Doctrine entity manager the contact form entities are mapped on (1.7), e.g. a dedicated "contact_form" manager of a DDD host: the entities are mapped there only (also with auto_mapping on the default one) and every read, write and reset of the bundle uses it. Null (default) = the default entity manager, through the bundle\'s private entity manager (2.0: the bundle never flushes your manager). Naming the default manager is the same as null.')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1))
                                ->thenInvalid('nowo_contact_form.doctrine.entity_manager must be the name of a Doctrine entity manager (letters, digits and underscores).')
                            ->end()
                        ->end()
                        ->booleanNode('private_entity_manager')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/contact-form-bundle', '2.0', self::PRIVATE_ENTITY_MANAGER_DEPRECATION)
                            ->info('Deprecated since 2.0, removed in 3.0. The private entity manager (same connection, mapping and tables, never flushes your pending changes) is the only mode unless doctrine.entity_manager names a dedicated manager. true: accepted, no effect (with a dedicated doctrine.entity_manager it keeps the 1.13 behaviour: the private manager on that manager). false: refused (the 1.x host mode was removed in 2.0).')
                        ->end()
                        ->enumNode('mapping_type')
                            ->values(['attribute', 'xml'])
                            ->defaultValue('attribute')
                            ->info('Mapping driver of the bundle entities (1.9): attribute (default, unchanged) or xml (src/Resources/config/doctrine-xml/*.orm.xml, the same tables, columns, indexes and constraints) for hosts whose managers only load XML mapping. Registered on doctrine.entity_manager (or the default entity manager).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('conflict_response')
                    ->info('1.11 (REQ-RUNTIME-003): answer of a lock conflict (deadlock, lock wait timeout, serialization failure) on the bundle\'s routes. Unset: nowo-tech/transaction-doctrine\'s conflict_response when that bundle is registered, else 409 with Retry-After 1.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('status')
                            ->info('409 (default, CONFLICT_001 / CONFLICT_002) or 503. Null: follow transaction-doctrine, else 409.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value !== null && !in_array($value, ConflictResponsePolicy::STATUSES, true))
                                ->thenInvalid('nowo_contact_form.conflict_response.status must be 409 or 503, got %s.')
                            ->end()
                        ->end()
                        ->integerNode('retry_after')
                            ->info('Retry-After seconds of a CONFLICT_001 answer. Null: follow transaction-doctrine, else 1.')
                            ->defaultNull()
                            ->min(0)
                        ->end()
                        ->enumNode('commit_tracking')
                            ->values(['bundle', 'all', 'off'])
                            ->defaultValue('bundle')
                            ->info('Commits counted for CONFLICT_002 when nowo-tech/transaction-doctrine\'s commit_detection is off: bundle (default, the connection of the bundle\'s entity manager only), all (every Doctrine connection) or off (no middleware; only the failure itself and transaction-doctrine\'s request mark decide). With commit_detection on, transaction-doctrine\'s ledger is reused and the bundle adds no middleware.')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * Build error of `doctrine.private_entity_manager: false` (2.0 removed the 1.x host mode it kept).
     */
    public static function privateEntityManagerFalseMessage(?string $entityManager): string
    {
        $message = 'nowo_contact_form.doctrine.private_entity_manager: false is no longer supported: 2.0 removed the mode in which contact form writes flush your application\'s entity manager. Remove the key.';
        if ($entityManager !== null && $entityManager !== '') {
            $message .= sprintf(' doctrine.entity_manager "%s" stays: a dedicated manager keeps working as in 1.x; if it names your default entity manager, 2.0 uses the private entity manager on it.', $entityManager);
        } else {
            $message .= ' The bundle then writes through its private entity manager (same connection and tables), which never flushes your pending changes; or set doctrine.entity_manager to a dedicated manager.';
        }

        return $message . ' See UPGRADING.md "To 2.0.0".';
    }

    /**
     * Fails on a key removed in 2.0, naming its replacement.
     *
     * @param array<mixed> $values
     * @param array<string, string> $removed key => what replaces it
     *
     * @return array<mixed>
     */
    private static function refuseRemovedKeys(array $values, array $removed, string $path): array
    {
        foreach ($removed as $key => $replacement) {
            if (array_key_exists($key, $values)) {
                throw new InvalidConfigurationException(sprintf('The option "nowo_contact_form.%s%s" was removed in 2.0 (deprecated since 1.6): %s. See UPGRADING.md "To 2.0.0".', $path, $key, $replacement));
            }
        }

        return $values;
    }
}
