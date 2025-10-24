<?php

namespace MauticPlugin\EmailDeliverabilityBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\PluginEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class PluginSubscriber implements EventSubscriberInterface
{
    private $fieldModel;

    public function __construct(FieldModel $fieldModel)
    {
        $this->fieldModel = $fieldModel;
    }

    public static function getSubscribedEvents()
    {
        return [
            PluginEvents::ON_PLUGIN_INSTALL => ['onPluginInstall', 0],
            PluginEvents::ON_PLUGIN_UPDATE => ['onPluginUpdate', 0],
        ];
    }

    public function onPluginInstall(PluginInstallEvent $event)
    {
        $plugin = $event->getPlugin();
        
        // Check if this is your plugin
        if ($plugin->getBundle() !== 'EmailDeliverabilityBundle') {
            return;
        }

        $this->createCustomField();
    }

    public function onPluginUpdate(PluginInstallEvent $event)
    {
        $plugin = $event->getPlugin();
        
        if ($plugin->getBundle() !== 'EmailDeliverabilityBundle') {
            return;
        }

        $this->createCustomField();
    }

    private function createCustomField()
    {
        // Check if field already exists
        $existingField = $this->fieldModel->getRepository()->findOneBy([
            'alias' => 'deliverability_status'
        ]);

        if ($existingField) {
            return; // Field already exists
        }

        // Create the custom field
        $field = new LeadField();
        $field->setLabel('Deliverability Status');
        $field->setAlias('deliverability_status');
        $field->setType('select'); // or 'text', 'boolean', etc.
        $field->setObject('lead'); // 'lead' for contacts
        $field->setGroup('core'); // or 'professional', 'social', etc.
        $field->setIsPublished(true);
        
        // If it's a select field, add options
        $field->setProperties([
            'list' => [
                ['label' => 'Deliverable', 'value' => 'deliverable'],
                ['label' => 'Hard Bounce', 'value' => 'hard_bounce'],
                ['label' => 'Soft Bounce', 'value' => 'soft_bounce'],
                ['label' => 'Unknown', 'value' => 'unknown'],
                ['label' => 'Invalid', 'value' => 'invalid'],
                ['label' => 'Not Checked', 'value' => 'not_checked'],
            ]
        ]);

        $this->fieldModel->saveEntity($field);
    }
}
