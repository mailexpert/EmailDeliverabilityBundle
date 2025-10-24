<?php
declare(strict_types=1);

return [
    'name'        => 'Email Deliverability Plugin',
    'description' => 'Checks contact email deliverability using external API.',
    'version'     => '1.1',
    'author'      => 'Mail Xpert',
    
    'services' => [
        'events' => [
            'mautic.plugin.emaildeliverability.subscriber' => [
                'class'     => \MauticPlugin\EmailDeliverabilityBundle\EventListener\ContactSubscriber::class,
                'arguments' => [
                    'mautic.plugin.emaildeliverability.helper',
                    'mautic.lead.model.lead',
                    'monolog.logger.mautic',
                ],
                'tags' => [
                    'kernel.event_subscriber',
                ],
            ],
            'mautic.plugin.emaildeliverability.plugin_subscriber' => [
                'class'     => \MauticPlugin\EmailDeliverabilityBundle\EventListener\PluginSubscriber::class,
                'arguments' => [
                    'mautic.lead.model.field',
                ],
            ],
        ],
        'integrations' => [
            'mautic.integration.emaildeliverability' => [
                'class'     => \MauticPlugin\EmailDeliverabilityBundle\Integration\EmailDeliverabilityIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'session',
                    'request_stack',
                    'router',
                    'translator',
                    'monolog.logger.mautic',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                ],
            ],
        ],
        'other' => [
            'mautic.plugin.emaildeliverability.helper' => [
                'class'     => \MauticPlugin\EmailDeliverabilityBundle\Helper\DeliverabilityChecker::class,
                'arguments' => [
                    'mautic.helper.integration',
                    'monolog.logger.mautic',
                ],
            ],
        ],
    ],
];
