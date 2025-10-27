<?php

declare(strict_types=1);

namespace MauticPlugin\EmailDeliverabilityBundle\EventListener;

use Mautic\EmailBundle\EmailEvents;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Event\DoNotContactAddEvent;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\Model\LeadModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class BounceSubscriber implements EventSubscriberInterface
{
    private $leadModel;
    private $logger;

    public function __construct(
        LeadModel $leadModel,
        LoggerInterface $logger
    ) {
        file_put_contents('/tmp/bounce_constructor.log', date('Y-m-d H:i:s') . " - BounceSubscriber constructed\n", FILE_APPEND);
        $this->leadModel = $leadModel;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        /*
        return [
            'mautic.lead.dnc.contact_added' => ['onDncAdded', 0],
            'mautic.lead.post_dnc_contact_added' => ['onDncAdded', 0],
            LeadEvents::LEAD_POST_SAVE => ['checkDncStatus', 0],
        ];
        */
        return [];
    }


    public function onDncAdded(DoNotContactAddEvent $event): void
    {
        file_put_contents('/tmp/bounce_debug.log', date('Y-m-d H:i:s') . " - DNC event triggered\n", FILE_APPEND);

        // Get contact from event
        $lead = $event->getLead();

        if ($lead) {
            $email = $lead->getEmail();
            file_put_contents('/tmp/bounce_debug.log', date('Y-m-d H:i:s') . " - Email bounced: $email\n", FILE_APPEND);

            $lead->addUpdatedField('deliverability_status', 'soft_bounce');
            $this->leadModel->saveEntity($lead, false);
        }
    }

    public function onEmailFailed(LeadEvent $event): void
    {
        file_put_contents('/tmp/bounce_debug.log',
            date('Y-m-d H:i:s') . " - onEmailFailed triggered - Event class: " . get_class($event) . "\n",
            FILE_APPEND
        );

        if (method_exists($event, 'getLead')) {
            $lead = $event->getLead();
            if ($lead) {
                $email = $lead->getEmail();
                file_put_contents('/tmp/bounce_debug.log',
                    date('Y-m-d H:i:s') . " - Email failed for: $email\n",
                    FILE_APPEND
                );

                $lead->addUpdatedField('deliverability_status', 'soft_bounce');
                $this->leadModel->saveEntity($lead, false);
            }
        }
    }

    public function checkDncStatus(LeadEvent $event): void
    {
        file_put_contents('/tmp/bounce_debug.log',
            date('Y-m-d H:i:s') . " - LEAD_POST_SAVE triggered\n",
            FILE_APPEND
        );

        $lead = $event->getLead();

        if (!$lead) {
            file_put_contents('/tmp/bounce_debug.log',
                date('Y-m-d H:i:s') . " - No lead in event\n",
                FILE_APPEND
            );
            return;
        }

        $email = $lead->getEmail();
        file_put_contents('/tmp/bounce_debug.log',
            date('Y-m-d H:i:s') . " - Checking DNC for: $email\n",
            FILE_APPEND
        );

        // Check if contact has DNC for email with reason = bounced (2)
        $dnc = $lead->getDoNotContact();

        if (!$dnc) {
            file_put_contents('/tmp/bounce_debug.log',
                date('Y-m-d H:i:s') . " - No DNC entries found\n",
                FILE_APPEND
            );
            return;
        }

        file_put_contents('/tmp/bounce_debug.log',
            date('Y-m-d H:i:s') . " - Found " . count($dnc) . " DNC entries\n",
            FILE_APPEND
        );

        foreach ($dnc as $dncEntry) {
            $channel = $dncEntry->getChannel();
            $reason = $dncEntry->getReason();

            file_put_contents('/tmp/bounce_debug.log',
                date('Y-m-d H:i:s') . " - DNC Entry: Channel=$channel, Reason=$reason\n",
                FILE_APPEND
            );

            if ($channel === 'email' && $reason === 2) {
                file_put_contents('/tmp/bounce_debug.log',
                    date('Y-m-d H:i:s') . " - Found bounce! Updating status 13 for: $email\n",
                    FILE_APPEND
                );

                $currentStatus = $lead->getFieldValue('deliverability_status');
                file_put_contents('/tmp/bounce_debug.log',
                    date('Y-m-d H:i:s') . " - Current status: $currentStatus\n",
                    FILE_APPEND
                );

                if ($currentStatus !== 'soft_bounce') {
                    $lead->addUpdatedField('deliverability_status', 'soft_bounce');
                    file_put_contents('/tmp/bounce_debug.log',
                        date('Y-m-d H:i:s') . " - Status updated to soft_bounce 15\n",
                        FILE_APPEND
                    );
                }
                break;
            }
        }
    }
}
