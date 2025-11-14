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
use Mautic\EmailBundle\Event\ParseEmailEvent;
use MauticPlugin\EmailDeliverabilityBundle\Service\DeliveryReporter;
use Mautic\EmailBundle\MonitoredEmail\Mailbox;
use Mautic\EmailBundle\MonitoredEmail\Message;

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
            EmailEvents::EMAIL_PARSE => ['onParse', 0],
            //'mautic.lead.dnc.contact_added' => ['onDncAdded', 0],
            //'mautic.lead.post_dnc_contact_added' => ['onDncAdded', 0],
            //LeadEvents::LEAD_POST_SAVE => ['checkDncStatus', 0],
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
        if (!$lead) {
            @file_put_contents('/tmp/email_send_event.log', "  No lead object found\n", FILE_APPEND);
            return;
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

    public function onParse(ParseEmailEvent $event): void
    {
        @file_put_contents('/tmp/email_parse_event.log', date('Y-m-d H:i:s') . " - onParse called\n", FILE_APPEND);
        
        // Get all messages from the event
        $messages = $event->getMessages();
        
        if (empty($messages)) {
            @file_put_contents('/tmp/email_parse_event.log', date('Y-m-d H:i:s') . " - No messages found\n", FILE_APPEND);
            return;
        }
        
        // Loop through each message
        foreach ($messages as $message) {
            @file_put_contents('/tmp/email_parse_event.log', 
                date('Y-m-d H:i:s') . " - Processing message: " . $message->id . "\n", FILE_APPEND);

            // Check if this is a bounce email
            if (!$this->isBounceEmail($message)) {
                @file_put_contents('/tmp/email_parse_event.log', date('Y-m-d H:i:s') . " - onParse Not bounce $message ", FILE_APPEND);
                continue;
            }
    
            $bounceData = $this->extractBounceData($message);
            @file_put_contents('/tmp/email_parse_event.log', date('Y-m-d H:i:s') . " - onParse bounce \n", FILE_APPEND);
            
            if ($bounceData) {
                @file_put_contents('/tmp/email_parse_event.log', date('Y-m-d H:i:s') . " - onParse bounceData " . print_r($bounceData, true) . "\n\n", FILE_APPEND);

                if ($bounceData['bounce_type'] !== 'blocked' && $bounceData['bounce_type'] !== 'unknown') {
                    $emailAddress = $bounceData['recipient_email'];
            
                    if (!$emailAddress) {
                        @file_put_contents('/tmp/email_parse_event.log', "  No email address found\n", FILE_APPEND);
                        continue;
                    }
                    $apiSucceeded = $this->deliveryReporter->reportFailure(
                        $emailAddress, new \DateTime(), $bounceData['smtp_code'], $bounceData['smtp_message']               , $bounceData['bounce_type']
                    );
                    if ($apiSucceeded) {
                        $lead = $this->getLeadByEmail($bounceData['recipient_email']);
                        if (!$lead) {
                            @file_put_contents('/tmp/email_parse_event.log', "  No lead object found\n", FILE_APPEND);
                            continue;
                        }
                        //SaveLead
                        $lead->addUpdatedField('deliverability_status', $bounceData['bounce_type']);
                        $this->leadModel->saveEntity($lead, false);
                    }
                }
            }
        }
    }

    private function getLeadByEmail($email)
    {
        if (empty($email)) {
            return null;
        }

        // Method 1: Using LeadModel's getRepository
        $leadRepo = $this->leadModel->getRepository();
        
        // Find leads by email
        $leads = $leadRepo->getLeadsByFieldValue('email', $email);
        
        if (!empty($leads)) {
            // Return the first lead (most recent)
            return reset($leads);
        }

        // Method 2: Alternative using direct repository query
        // Uncomment if Method 1 doesn't work
        
        $leads = $leadRepo->findBy(['email' => $email], ['dateAdded' => 'DESC'], 1);
        if (!empty($leads)) {
            return $leads[0];
        }

        return null;
    }

    private function isBounceEmail(Message $message): bool
    {
        $subject = $message->subject;
        $fromAddress = $message->fromAddress;
        
        // Common bounce indicators
        $bounceIndicators = [
            'Mail Delivery Failed',
            'Delivery Status Notification',
            'Undelivered Mail Returned to Sender',
            'Returned mail',
            'Mail delivery failed',
            'failure notice',
            'Undeliverable',
        ];
        
        foreach ($bounceIndicators as $indicator) {
            if (stripos($subject, $indicator) !== false) {
                return true;
            }
        }
        
        // Check common bounce sender addresses
        $bounceSenders = ['mailer-daemon@', 'postmaster@', 'noreply@'];
        foreach ($bounceSenders as $sender) {
            if (stripos($fromAddress, $sender) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function extractBounceData(Message $message): ?array
    {
        $body = $message->textPlain ?: $message->textHtml;
        //@file_put_contents('/tmp/email_parse_event.log', date('Y-m-d H:i:s') . " - onParse body $body ", FILE_APPEND);
 
        // Handle date - it's a string, not a DateTime object
        $dateString = null;
        if (!empty($message->date)) {
            if ($message->date instanceof \DateTime) {
                $dateString = $message->date->format('Y-m-d H:i:s');
            } else {
                // It's already a string
                $dateString = $message->date;
            }
        }
        
        $bounceData = [
            'message_id' => $message->id,
            'subject' => $message->subject,
            'from' => $message->fromAddress,
            'to' => $message->toAddress ?? '',
            'date' => $dateString,
            'smtp_code' => null,
            'smtp_message' => null,
            'bounce_type' => null,
            'recipient_email' => null,
            //'raw_body' => $body,
        ];

        // Extract SMTP code and message
        $this->extractSmtpDetails($body, $bounceData);
        
        // Extract recipient email
        $bounceData['recipient_email'] = $this->extractRecipientEmail($body, $message);

        // Extract sender IP if it's a blacklist issue
        $bounceData['sender_ip'] = $this->extractSenderIp($body);
        
        // Determine bounce type (hard or soft)
        $this->determineBounceTypeAndCategory($body, $bounceData);
        
        return $bounceData;
    }

    private function extractSenderIp($body)
    {
        // Pattern to match IP addresses in square brackets
        if (preg_match('/\[(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\]/', $body, $matches)) {
            // Check if it's related to blocklist/blacklist context
            $contextWords = ['block list', 'blocklist', 'blacklist', 'blocked', 'network is on our'];
            foreach ($contextWords as $word) {
                if (stripos($body, $word) !== false) {
                    return $matches[1];
                }
            }
        }
        
        return null;
    }

    private function extractSmtpDetails(string $body, array &$bounceData): void
    {
        // Pattern 1: Standard SMTP response (e.g., "550 5.1.1 User unknown")
        if (preg_match('/\b([45]\d{2})\s+(\d\.\d\.\d)\s+(.+?)(?:\n|$)/i', $body, $matches)) {
            $bounceData['smtp_code'] = $matches[1];
            $bounceData['smtp_message'] = trim($matches[3]);
            return;
        }
        
        // Pattern 2: SMTP code without extended code (e.g., "550 User unknown")
        if (preg_match('/\b([45]\d{2})\s+(.+?)(?:\n|$)/i', $body, $matches)) {
            $bounceData['smtp_code'] = $matches[1];
            $bounceData['smtp_message'] = trim($matches[2]);
            return;
        }
        
        // Pattern 3: Diagnostic-Code or Status fields
        if (preg_match('/(?:Diagnostic-Code|Status):\s*(?:smtp;?\s*)?([45]\d{2})\s+(.+?)(?:\n|$)/i', $body, $matches)) {
            $bounceData['smtp_code'] = $matches[1];
            $bounceData['smtp_message'] = trim($matches[2]);
            return;
        }
        
        // Pattern 4: Action: failed with code
        if (preg_match('/Action:\s*failed.*?([45]\d{2})\s+(.+?)(?:\n|$)/is', $body, $matches)) {
            $bounceData['smtp_code'] = $matches[1];
            $bounceData['smtp_message'] = trim($matches[2]);
            return;
        }
        
        // Pattern 5: Remote-MTA response
        if (preg_match('/(?:Remote-MTA|Final-Recipient).*?([45]\d{2})\s+(.+?)(?:\n|$)/is', $body, $matches)) {
            $bounceData['smtp_code'] = $matches[1];
            $bounceData['smtp_message'] = trim($matches[2]);
            return;
        }
    }
    
    private function extractRecipientEmail($body, Message $message)
    {
        if (empty($body)) {
            return null;
        }
    
        // Try to find email in common bounce message patterns
        $patterns = [
            // Pattern for <email@domain.com>: format (like in your example)
            '/<([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})>:/i',
            
            // Standard patterns
            '/<?([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})>?\s*(?:does not exist|not found|unknown|invalid|out of storage|quota)/i',
            '/(?:user|recipient|address|mailbox):\s*<?([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})>?/i',
            '/Final-Recipient:\s*rfc822;\s*<?([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})>?/i',
            '/X-Failed-Recipients:\s*<?([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})>?/i',
            '/To:\s*<?([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})>?/i',
            
            // Generic pattern - any email in angle brackets
            '/<([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})>/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                $email = strtolower(trim($matches[1]));
                
                // Validate it's a proper email
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    @file_put_contents('/tmp/email_parse_event.log', 
                        date('Y-m-d H:i:s') . " - Extracted email: " . $email . " using pattern: " . $pattern . "\n", 
                        FILE_APPEND
                    );
                    return $email;
                }
            }
        }
        
        // Try to use the toAddress from the message object if available
        if (!empty($message->toAddress)) {
            // Extract email from "Name <email@domain.com>" format
            if (preg_match('/<([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})>/i', $message->toAddress, $matches)) {
                return strtolower(trim($matches[1]));
            }
            // If it's already just an email
            if (filter_var($message->toAddress, FILTER_VALIDATE_EMAIL)) {
                return strtolower(trim($message->toAddress));
            }
        }
        
        // Last resort - find ANY valid email in the body
        if (preg_match_all('/([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})/i', $body, $matches)) {
            foreach ($matches[1] as $potentialEmail) {
                $email = strtolower(trim($potentialEmail));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    // Skip common system emails
                    $skipEmails = ['postmaster@', 'mailer-daemon@', 'noreply@', 'no-reply@'];
                    $shouldSkip = false;
                    foreach ($skipEmails as $skipPattern) {
                        if (stripos($email, $skipPattern) !== false) {
                            $shouldSkip = true;
                            break;
                        }
                    }
                    
                    if (!$shouldSkip) {
                        @file_put_contents('/tmp/email_parse_event.log', 
                            date('Y-m-d H:i:s') . " - Extracted email (last resort): " . $email . "\n", 
                            FILE_APPEND
                        );
                        return $email;
                    }
                }
            }
        }
        
        @file_put_contents('/tmp/email_parse_event.log', 
            date('Y-m-d H:i:s') . " - Could not extract email from body\n", 
            FILE_APPEND
        );
        
        return null;
    }

    private function determineBounceType(?string $smtpCode): string
    {
        if (!$smtpCode) {
            return 'soft_bounce';
        }
        
        // Hard bounces (permanent failures)
        $hardBounceCodes = ['500', '501', '502', '503', '504', '510', '511', '512', '513', '523', '530', '541', '550', '551', '552', '553', '554'];
        
        // Soft bounces (temporary failures)
        $softBounceCodes = ['420', '421', '422', '431', '432', '441', '442', '446', '447', '449', '450', '451', '452', '471'];
        
        if (in_array($smtpCode, $hardBounceCodes)) {
            return 'hard_bounce';
        }
        
        if (in_array($smtpCode, $softBounceCodes)) {
            return 'soft_bounce';
        }
        
        // Default classification based on first digit
        if ($smtpCode[0] === '5') {
            return 'hard_bounce';
        }
        
        if ($smtpCode[0] === '4') {
            return 'soft_bounce';
        }
        
        return 'soft_bounce';
    }


    private function determineBounceTypeAndCategory($body, array &$bounceData)
    {
        $smtpCode = $bounceData['smtp_code'];
        $smtpMessage = strtolower($bounceData['smtp_message'] ?? '');
        $bodyLower = strtolower($body);
        
        // Check for blacklist/blocklist indicators first
        $blacklistIndicators = [
            'block list',
            'blocklist',
            'blacklist',
            'blocked',
            'on our block',
            'reputation',
            'spam',
            'blacklisted',
            'listed in',
            'RBL',
            'DNSBL',
            'spamhaus',
            'barracuda',
            'contact your Internet service provider',
            'part of their network is on our block',
        ];
        
        $isBlacklisted = false;
        foreach ($blacklistIndicators as $indicator) {
            if (stripos($bodyLower, $indicator) !== false || stripos($smtpMessage, $indicator) !== false) {
                $isBlacklisted = true;
                break;
            }
        }
        
        if ($isBlacklisted) {
            $bounceData['bounce_type'] = 'blocked';
            $bounceData['bounce_category'] = 'sender_blocked';
            return;
        }
        
        // Check for mailbox full (soft bounce, recipient issue)
        $mailboxFullIndicators = [
            'quota',
            'out of storage',
            'mailbox full',
            'over quota',
            'insufficient storage',
            'storage space',
        ];
        
        foreach ($mailboxFullIndicators as $indicator) {
            if (stripos($bodyLower, $indicator) !== false || stripos($smtpMessage, $indicator) !== false) {
                $bounceData['bounce_type'] = 'soft_bounce';
                $bounceData['bounce_category'] = 'mailbox_full';
                return;
            }
        }
        
        // Check for invalid recipient (hard bounce)
        $invalidRecipientIndicators = [
            'user unknown',
            'does not exist',
            'invalid recipient',
            'no such user',
            'unknown user',
            'recipient address rejected',
            'user not found',
            'mailbox not found',
            'no mailbox',
        ];
        
        foreach ($invalidRecipientIndicators as $indicator) {
            if (stripos($bodyLower, $indicator) !== false || stripos($smtpMessage, $indicator) !== false) {
                $bounceData['bounce_type'] = 'hard_bounce';
                $bounceData['bounce_category'] = 'invalid_recipient';
                return;
            }
        }
        
        // Check for domain issues
        $domainIssueIndicators = [
            'domain not found',
            'no mx record',
            'host not found',
            'domain does not exist',
        ];
        
        foreach ($domainIssueIndicators as $indicator) {
            if (stripos($bodyLower, $indicator) !== false || stripos($smtpMessage, $indicator) !== false) {
                $bounceData['bounce_type'] = 'hard_bounce';
                $bounceData['bounce_category'] = 'invalid_domain';
                return;
            }
        }
        
        // Check for greylisting (temporary, retry later)
        $greylistIndicators = [
            'greylisted',
            'greylist',
            'try again later',
            'temporarily rejected',
            'deferred',
        ];
        
        foreach ($greylistIndicators as $indicator) {
            if (stripos($bodyLower, $indicator) !== false || stripos($smtpMessage, $indicator) !== false) {
                $bounceData['bounce_type'] = 'soft_bounce';
                $bounceData['bounce_category'] = 'greylisted';
                return;
            }
        }
        
        // Fallback to SMTP code classification
        if (!$smtpCode) {
            $bounceData['bounce_type'] = 'unknown';
            $bounceData['bounce_category'] = 'unknown';
            return;
        }
        
        // Hard bounces (permanent failures)
        $hardBounceCodes = ['500', '501', '502', '503', '504', '510', '511', '512', '513', '523', '530', '541', '550', '551', '552', '553', '554'];
        
        // Soft bounces (temporary failures)
        $softBounceCodes = ['420', '421', '422', '431', '432', '441', '442', '446', '447', '449', '450', '451', '452', '471'];
        
        if (in_array($smtpCode, $hardBounceCodes)) {
            $bounceData['bounce_type'] = 'hard_bounce';
            $bounceData['bounce_category'] = 'permanent_failure';
            return;
        }
        
        if (in_array($smtpCode, $softBounceCodes)) {
            $bounceData['bounce_type'] = 'soft_bounce';
            $bounceData['bounce_category'] = 'temporary_failure';
            return;
        }
        
        // Default classification based on first digit
        if (isset($smtpCode[0])) {
            if ($smtpCode[0] === '5') {
                $bounceData['bounce_type'] = 'hard_bounce';
                $bounceData['bounce_category'] = 'permanent_failure';
            } elseif ($smtpCode[0] === '4') {
                $bounceData['bounce_type'] = 'soft_bounce';
                $bounceData['bounce_category'] = 'temporary_failure';
            } else {
                $bounceData['bounce_type'] = 'unknown';
                $bounceData['bounce_category'] = 'unknown';
            }
        } else {
            $bounceData['bounce_type'] = 'unknown';
            $bounceData['bounce_category'] = 'unknown';
        }
    }
}
