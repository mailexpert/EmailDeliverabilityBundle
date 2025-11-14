<?php

declare(strict_types=1);

namespace MauticPlugin\EmailDeliverabilityBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\LeadBundle\Event\LeadEvent;
use MauticPlugin\EmailDeliverabilityBundle\Helper\DeliverabilityChecker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

class ContactSubscriber implements EventSubscriberInterface
{
    private $checker;
    private $leadModel;
    private $fieldModel;
    private $logger;
    private static $processed = [];

    public function __construct(
        DeliverabilityChecker $checker, 
        LeadModel $leadModel,
        FieldModel $fieldModel,
        LoggerInterface $logger
    ) {
        file_put_contents('/tmp/constructor.log', date('Y-m-d H:i:s') . " - Constructor called for ContactSubscriber\n", FILE_APPEND);
        $this->checker = $checker;
        $this->leadModel = $leadModel;
        $this->fieldModel = $fieldModel;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LEAD_PRE_SAVE => ['onContactPreSave', 0],
            //LeadEvents::LEAD_POST_SAVE => ['onContactPostSave', 0],
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => ['onViewInjectCustomContent', 0],
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

    public function onViewInjectCustomContent(CustomContentEvent $event)
    {
        //file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - onViewInjectCustomContent called\n", FILE_APPEND);
        //Debug: Log all available information
        $viewName = $event->getViewName();
        $context = $event->getContext();
        $vars = $event->getVars();
        
        //file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - ViewName: '$viewName' Context: '$context'\n", FILE_APPEND);
        // Only inject on lead detail view
        if ($viewName !== '@MauticLead/Lead/lead.html.twig' || $context !== 'tabs.content') {
            //file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - onViewInjectCustomContent return 1 ViewName : $event->getViewName() \n", FILE_APPEND);
            return;
        }

        $lead = $event->getVars()['lead'] ?? null;
        if (!$lead) {
            //file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - onViewInjectCustomContent return 2\n", FILE_APPEND);
            return;
        }

        $status = $lead->getFieldValue('deliverability_status') ?? 'not_checked';
        $label = $this->getLabelForField('deliverability_status', $status);
        
        // Color coding based on status
        $colors = [
            'deliverable' => 'success',
            'hard_bounce' => 'danger',
            'soft_bounce' => 'warning',
            'sent' => 'primary',
            'unknown' => 'default',
            'not_checked' => 'info',
        ];
        
        $color = $colors[$status] ?? 'default';
        
        $html = sprintf(
            '<div class="panel panel-default"><div class="panel-heading"><h3 class="panel-title">Email Deliverability</h3></div><div class="panel-body"><span class="label label-%s">%s</span></div></div>',
            $color,
            //ucwords(str_replace('_', ' ', $status))
            $label
        );
        $html = sprintf(
            '<h6 class="fw-sb">Deliverability Status</h6><p><div class="panel-body"><span class="label label-%s">%s</span></div></p>',
            $color,
            //ucwords(str_replace('_', ' ', $status))
            $label
        );

        //file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - onViewInjectCustomContent html $html \n", FILE_APPEND);
        $event->addContent($html,  'lead.detail.right');
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
        $this->logger->error('processLead calling check', []);
        $new_status = $this->checker->check($email);
        
        file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - API returned: $new_status currentStatus $currentStatus \n", FILE_APPEND);
        
        $lead->addUpdatedField('deliverability_status', $new_status);
        
        $this->logger->error('processLead values ', ['new_status' => $new_status, 'currentStatus' => $currentStatus]);
        if ($stage === 'POST' && $currentStatus !== $new_status) {
            // Save again in POST_SAVE
            file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - Saving in POST_SAVE\n", FILE_APPEND);
            $this->logger->error('Saving in POST_SAVE');
            //$this->leadModel->saveEntity($lead, false);
        }
        else {
            $this->logger->error('No need to saveeeE');
            file_put_contents('/tmp/deliverability_debug.log', date('Y-m-d H:i:s') . " - No need to save\n", FILE_APPEND);
        }
        
        self::$processed[$leadId] = true;
    }

    public function getLabelForField($alias, $value)
    {

        $field = $this->fieldModel->getRepository()->findOneBy(['alias' => $alias]);
        $label = $value;
        $this->logger->error("=== DEBUG Field: $alias and value $value ===");
        if ($field) {
            $properties = $field->getProperties();
            // Debug: Log what we're getting
            // TEMPORARY DEBUG - Remove after checking logs
            // Use Mautic's logger
            $this->logger->error("=== DEBUG Field: $alias ===");
            $this->logger->error("Value: $value");
            $this->logger->error("Properties: ", $properties);
            $this->logger->error("=== END DEBUG ===");
            if (isset($properties['list']) && is_array($properties['list'])) {
                foreach ($properties['list'] as $option) {
                    if (isset($option['value']) && $option['value'] === $value) {
                        $label = $option['label'];
                        break;
                    }
                }
            }
        }
        return $label;
    }

}
