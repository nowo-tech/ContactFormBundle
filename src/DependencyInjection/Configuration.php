<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection;

use Nowo\ContactFormBundle\Service\DynamicContactFormBuilder;
use Nowo\ContactFormBundle\Ui\ContactFormPages;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_diff;
use function array_keys;
use function implode;
use function is_scalar;
use function is_string;
use function sprintf;
use function trim;

/**
 * Defines the configuration tree for the contact form bundle.
 *
 * @final since 1.5, will be final in 2.0 (not an extension point; decorate or replace the service instead)
 */
class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_contact_form';

    /** Default admin route prefix (web_ui.path_prefix / deprecated admin_route_prefix unset). */
    public const DEFAULT_PATH_PREFIX = '/admin/contact-forms';

    /** 1.x default layout (removed in 1.6; mapped to null = nowo_ui_kit.layouts.admin). */
    public const LEGACY_DEFAULT_LAYOUT = '@NowoContactFormBundle/admin/layout.html.twig';

    public const CSS_FRAMEWORKS = ['bootstrap', 'bootstrap4', 'bootstrap5', 'tailwind', 'foundation', 'custom', 'tabler', 'none'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $rootNode    = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
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
                ->scalarNode('ip_anonymization_salt')
                    ->defaultValue('%kernel.secret%')
                    ->info('Salt used when hashing IP addresses for GDPR storage.')
                ->end()
                ->integerNode('default_retention_days')
                    ->defaultValue(365)
                    ->min(1)
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
                ->scalarNode('admin_route_prefix')
                    ->defaultNull()
                    ->setDeprecated('nowo-tech/contact-form-bundle', '1.6', 'The "%path%" option is deprecated, use "nowo_contact_form.web_ui.path_prefix" instead (REQ-UI-003).')
                    ->info('Deprecated since 1.6: use web_ui.path_prefix.')
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static function (array $v): array {
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
                            ->info('Layout the admin pages extend (REQ-UI-003). Null (default) = nowo_ui_kit.layouts.admin. A 1.x layout without the UiKit canonical blocks is wrapped in a deprecated bridge.')
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
                        ->enumNode('css_framework')
                            ->values(self::CSS_FRAMEWORKS)
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/contact-form-bundle', '1.6', 'The "%path%" option is deprecated, set "nowo_ui_kit.css_framework" instead (REQ-UI-003).')
                            ->info('Deprecated since 1.6: the CSS stack is inherited from nowo_ui_kit.css_framework (copied there when the host did not set it).')
                        ->end()
                        ->enumNode('icon_set')
                            ->values(['bootstrap-icons', 'tabler-icons', 'ux_icon', 'svg_inline', 'none'])
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/contact-form-bundle', '1.6', 'The "%path%" option is deprecated, set "nowo_ui_kit.icon_set" instead (REQ-UI-003).')
                            ->info('Deprecated since 1.6: inherited from nowo_ui_kit.icon_set (copied there when the host did not set it).')
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
                            ->info('Custom service id implementing ContactFormFileUploadHandlerInterface (required for file fields).')
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
                    ->info('Rate limit for public form submissions per IP and form slug.')
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('limit')->defaultValue(5)->min(1)->end()
                        ->integerNode('interval_seconds')->defaultValue(60)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_prefix')
                            ->defaultValue('')
                            ->info('Prefix prepended to table names (nowo_contact_form, nowo_contact_submission, …). Empty = no prefix.')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
