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
                'class'  => \MauticPlugin\EmailDeliverabilityBundle\EventListener\ContactSubscriber::class,
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
                'class'  => \MauticPlugin\EmailDeliverabilityBundle\EventListener\PluginSubscriber::class,
                'arguments' => [
                    'mautic.lead.model.field',
                    'database_connection',
                ],
            ],
            'mautic.plugin.emaildeliverability.subscriber.email_send' => [
                'class' => \MauticPlugin\EmailDeliverabilityBundle\EventListener\EmailSendSubscriber::class,
                'arguments' => [
                    'mautic.emaildeliverability.service.delivery_reporter',
                    'mautic.lead.model.lead',
                ],
                'tags' => [
                    'kernel.event_subscriber',
                ],
            ],
            'mautic.plugin.emaildeliverability.bounce_subscriber' => [
                'class' => \MauticPlugin\EmailDeliverabilityBundle\EventListener\BounceSubscriber::class,
                'arguments' => [
                    'mautic.lead.model.lead',
                    'monolog.logger.mautic',
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
            'mautic.emaildeliverability.service.delivery_reporter' => [
                'class' => \MauticPlugin\EmailDeliverabilityBundle\Service\DeliveryReporter::class,
                'arguments' => [
                    'monolog.logger.mautic',
                    'mautic.helper.integration',
                ],
            ],
        ],
    ],
];
