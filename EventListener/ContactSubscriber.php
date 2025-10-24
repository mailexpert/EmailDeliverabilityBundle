<?php

declare(strict_types=1);

namespace MauticPlugin\EmailDeliverabilityBundle\EventListener;

use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\EmailDeliverabilityBundle\Helper\DeliverabilityChecker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class ContactSubscriber implements EventSubscriberInterface
{
    private $checker;
    private $leadModel;
    private $logger;
    private static $processed = [];

    public function __construct(
        DeliverabilityChecker $checker, 
        LeadModel $leadModel,
        LoggerInterface $logger
    ) {
        file_put_contents('/tmp/constructor.log', date('Y-m-d H:i:s') . " - Constructor called\n", FILE_APPEND);
        $this->checker = $checker;
        $this->leadModel = $leadModel;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LEAD_PRE_SAVE => ['onContactPreSave', 0],
            LeadEvents::LEAD_POST_SAVE => ['onContactPostSave', 0],
        ];
    }

    public function onContactPreSave(LeadEvent $event): void
    {
        file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - PRE_SAVE called\n", FILE_APPEND);
        $this->processLead($event, 'PRE');
    }

    public function onContactPostSave(LeadEvent $event): void
    {
        file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - POST_SAVE called\n", FILE_APPEND);
        $this->processLead($event, 'POST');
    }

    private function processLead(LeadEvent $event, string $stage): void
    {
        $lead = $event->getLead();
        $leadId = $lead->getId();
        $email = $lead->getEmail();

        // Prevent duplicate processing
        if (isset(self::$processed[$leadId])) {
            file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - Already processed ID: $leadId\n", FILE_APPEND);
            return;
        }

        if (!$email) {
            file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - No email for ID: $leadId\n", FILE_APPEND);
            return;
        }

        $currentStatus = $lead->getFieldValue('deliverability_status');
        
        file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - [$stage] Email: $email, Status: $currentStatus\n", FILE_APPEND);
        
        if (!empty($currentStatus) && $currentStatus !== 'not_checked' && $currentStatus !== 'Not Checked') {
            file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - Already has valid status\n", FILE_APPEND);
            return;
        }

        $status = $this->checker->check($email);
        
        file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - API returned: $status\n", FILE_APPEND);
        
        $lead->addUpdatedField('deliverability_status', $status);
        
        if ($stage === 'POST') {
            // Save again in POST_SAVE
            $this->leadModel->saveEntity($lead, false);
            file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - Saved in POST_SAVE\n", FILE_APPEND);
        }
        
        self::$processed[$leadId] = true;
    }
}
