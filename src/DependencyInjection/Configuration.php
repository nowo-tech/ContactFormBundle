<?php

declare(strict_types=1);

namespace Nowo\ContactFormBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Defines the configuration tree for the contact form bundle.
 */
class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_contact_form';

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
                    ->defaultValue('/admin/contact-forms')
                    ->info('URL prefix for bundle admin CRUD routes.')
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
            ->end();

        return $treeBuilder;
    }
}
