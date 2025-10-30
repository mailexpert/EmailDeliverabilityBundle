<?php

namespace MauticPlugin\EmailDeliverabilityBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Event\DoNotContactAddEvent;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\EmailBundle\Event\EmailOpenEvent;
use MauticPlugin\EmailDeliverabilityBundle\Service\DeliveryReporter;

class EmailSendSubscriber implements EventSubscriberInterface
{
    /**
     * @var DeliveryReporter
     */
    private $deliveryReporter;
    private $leadModel;

    public function __construct(DeliveryReporter $deliveryReporter, LeadModel $leadModel)
    {
        @file_put_contents('/tmp/email_send_event.log', date('Y-m-d H:i:s') . " - EmailSendSubscriber constructed\n", FILE_APPEND);
        $this->deliveryReporter = $deliveryReporter;
        $this->leadModel = $leadModel;

    }

    public static function getSubscribedEvents()
    {
        return [
            EmailEvents::EMAIL_ON_SEND => ['onEmailSend', -400],
            EmailEvents::EMAIL_ON_OPEN => ['onEmailOpen', 0],
            EmailEvents::EMAIL_FAILED => ['onEmailFailed', -400],
            'mautic.lead.dnc.contact_added' => ['onDncAdded', 0],
            'mautic.lead.post_dnc_contact_added' => ['onDncAdded', 0],
            LeadEvents::LEAD_POST_SAVE => ['checkDncStatus', 0],
        ];
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

    public function checkDncStatus(LeadEvent $event): void
    {
        file_put_contents('/tmp/bounce_debug.log', date('Y-m-d H:i:s') . " - checkDncStatus 33 triggered\n", FILE_APPEND);
        
        $lead = $event->getLead();
        
        // Check if contact has DNC for email with reason = bounced (2)
        $dnc = $lead->getDoNotContact();
        
        if ($dnc) {
            foreach ($dnc as $dncEntry) {
                if ($dncEntry->getChannel() === 'email' && $dncEntry->getReason() === 2) {
                    $email = $lead->getEmail();
                    file_put_contents('/tmp/bounce_debug.log', date('Y-m-d H:i:s') . " - Contact has bounce DNC: $email \n", FILE_APPEND);
                    
                    // Update deliverability status
                    $currentStatus = $lead->getFieldValue('deliverability_status');
                    if ($currentStatus !== 'soft_bounce') {
                        $apiSucceeded = $this->deliveryReporter->reportFailure(
                            $email,
                            new \DateTime(),
                            "",
                            "",
                            'soft_bounce'
                        );
                        if ($apiSucceeded) {
                            $lead->addUpdatedField('deliverability_status', 'soft_bounce');
                            $this->leadModel->saveEntity($lead, false);
                            file_put_contents('/tmp/bounce_debug.log', date('Y-m-d H:i:s') . " - Updated status to bounced\n", FILE_APPEND);
                       }
                    }
                    break;
                }
            }
        }
    }


    /**
     * Triggered when email is sent
     */
    public function onEmailSend(EmailSendEvent $event)
    {
        @file_put_contents('/tmp/email_send_event.log', date('Y-m-d H:i:s') . " - onEmailSend event fired\n", FILE_APPEND);
        $email = $event->getEmail();
        if (!$email) {
            @file_put_contents('/tmp/email_send_event.log', "  No email object found\n", FILE_APPEND);
            return;
        }
        @file_put_contents('/tmp/email_send_event.log', "  Email ID: " . $email->getId() . "\n", FILE_APPEND);
        $lead = $event->getLead();
        if (is_array($lead)) {
            // Convert array → entity
            $lead = $this->leadModel->getEntity($lead['id']);
        }
        $emailAddress = $lead->getEmail();

        if (!$emailAddress) {
            @file_put_contents('/tmp/email_send_event.log', "  No email address found\n", FILE_APPEND);
            return;
        }
        @file_put_contents('/tmp/email_send_event.log', "  Email address: $emailAddress\n", FILE_APPEND);

        // Store send data temporarily (we'll update with delivery status later)
        $this->deliveryReporter->recordEmailSend(
            $emailAddress,
            new \DateTime(),
            $event->getEmail()->getId()
        );
        // Check if email was sent successfully
        // In Mautic 5, check if there are errors instead
        $errors = $event->getErrors();

        if (!empty($errors)) {
            @file_put_contents('/tmp/email_send_event.log', "  Errors found: " . json_encode($errors) . "\n", FILE_APPEND);
            $apiSucceeded = $this->deliveryReporter->reportFailure(
                $email, new \DateTime(), "-100", "Domain error",
                'failure'
            );
            if ($apiSucceeded) {
                //SaveLead
                $lead->addUpdatedField('deliverability_status', 'hard_bounce');
                $this->leadModel->saveEntity($lead, false);
            }
        } else {
            @file_put_contents('/tmp/email_send_event.log', "  No errors - email sent successfully\n", FILE_APPEND);
            $apiSucceeded = $this->deliveryReporter->reportDelivery(
                $lead->getEmail(), new \DateTime(), 250, 'OK', 'sent'
            );
            if ($apiSucceeded) {
                //SaveLead
                $lead->addUpdatedField('deliverability_status', 'sent');
                $this->leadModel->saveEntity($lead, false);
            }
        }
    }


    /**
     * Email opened = delivery confirmed
     */
    public function onEmailOpen(EmailOpenEvent $event)
    {
        @file_put_contents('/tmp/email_open_event.log', date('Y-m-d H:i:s') . " - onEmailOpen fired\n", FILE_APPEND);
    
        $lead = $event->getLead();
    
        // Ensure we have a Lead entity
        if (is_array($lead)) {
            if (!isset($lead['id'])) {
                @file_put_contents('/tmp/email_open_event.log', " - Lead array missing ID, abort\n", FILE_APPEND);
                return;
            }
            $lead = $this->leadModel->getEntity($lead['id']);
        }
    
        if (!$lead) {
            @file_put_contents('/tmp/email_open_event.log', " - No Lead entity found, abort\n", FILE_APPEND);
            return;
        }
    
        $email = $lead->getEmail();
        if (!$email) {
            @file_put_contents('/tmp/email_open_event.log', " - Lead has no email, abort\n", FILE_APPEND);
            return;
        }
    
        @file_put_contents('/tmp/email_open_event.log', " - Reporting delivery for $email\n", FILE_APPEND);
    
        $apiSucceeded = $this->deliveryReporter->reportDelivery(
            $email,
            new \DateTime(),
            250,
            'OK',
            'delivered'
        );
    
        if ($apiSucceeded) {
            $lead->addUpdatedField('deliverability_status', 'deliverable');
            $this->leadModel->saveEntity($lead, false);
            @file_put_contents('/tmp/email_open_event.log', " - deliverability_status updated\n", FILE_APPEND);
        }
    }
    
    /**
     * Email failed
     */
    public function onEmailFailed($event)
    {
        @file_put_contents('/tmp/email_failed_event.log', date('Y-m-d H:i:s') . " - onEmailFailed event fired\n", FILE_APPEND);
        // Handle different event types
        if (!method_exists($event, 'getLead')) {
            return;
        }

        $lead = $event->getLead();
        $email = $lead->getEmail();
        
        if (!$email) {
            return;
        }

        if (is_array($lead)) {
            // Convert array → entity
            $lead = $this->leadModel->getEntity($lead['id']);
        }

        $reason = method_exists($event, 'getReason') ? $event->getReason() : 'Unknown error';
        
        // Determine if hard or soft bounce
        $isSoftBounce = $this->isSoftBounce($reason);
        
        // Extract SMTP code from reason
        preg_match('/\b([245]\d{2})\b/', $reason, $matches);
        $smtpCode = $matches[1] ?? 400;
        $bounceType = $isSoftBounce ? 'soft_bounce' : 'hard_bounce';
        $apiSucceeded = $this->deliveryReporter->reportFailure(
            $email, new \DateTime(), $smtpCode, $reason, $bounceType
        );
        if ($apiSucceeded) {
            //SaveLead
            $lead->addUpdatedField('deliverability_status', $bounceType);
            $this->leadModel->saveEntity($lead, false);
        }
    }

    private function isSoftBounce($reason)
    {
        @file_put_contents('/tmp/email_open_event.log', date('Y-m-d H:i:s') . " - isSoftBounce called reason: ". $reason . " \n", FILE_APPEND);
        $softBouncePatterns = [
            '/mailbox full/i',
            '/quota exceeded/i',
            '/temporarily/i',
            '/try again/i',
            '/greylisted/i',
            '/4\d{2}/', // 4xx SMTP codes
        ];

        foreach ($softBouncePatterns as $pattern) {
            if (preg_match($pattern, $reason)) {
                return true;
            }
        }

        return false;
    }
}
