<?php

namespace MauticPlugin\EmailDeliverabilityBundle\EventListener;

use Doctrine\DBAL\Connection;
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
    private $connection;

    public function __construct(FieldModel $fieldModel, Connection $connection)
    {
        $this->fieldModel = $fieldModel;
        $this->connection = $connection;
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
        $this->createDatabaseColumn();
    }

    public function onPluginUpdate(PluginInstallEvent $event)
    {
        $plugin = $event->getPlugin();
        
        if ($plugin->getBundle() !== 'EmailDeliverabilityBundle') {
            return;
        }

        $this->createCustomField();
        $this->createDatabaseColumn();
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
        $field->setIsListable(true); // ADD THIS LINE - Shows in contact list
        $field->setIsShortVisible(true); // ADD THIS LINE - Visible by default
        $field->setDefaultValue('not_checked'); // ADD THIS LINE        

        // If it's a select field, add options
        $field->setProperties([
            'list' => [
                ['label' => 'Deliverable', 'value' => 'deliverable'],
                ['label' => 'Hard Bounce', 'value' => 'hard_bounce'],
                ['label' => 'Soft Bounce', 'value' => 'soft_bounce'],
                ['label' => 'Unknown', 'value' => 'unknown'],
                ['label' => 'Not Checked', 'value' => 'not_checked'],
            ]
        ]);

        $this->fieldModel->saveEntity($field);
    }

    private function createDatabaseColumn()
    {
        // Check if column exists
        $schemaManager = $this->connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns('leads');
        
        if (isset($columns['deliverability_status'])) {
            return; // Column already exists
        }
        
        // Add column to leads table
        $this->connection->executeStatement(
            "ALTER TABLE leads ADD COLUMN deliverability_status VARCHAR(50) NULL DEFAULT 'not_checked'"
        );
    }
}
