<?php
declare(strict_types=1);

namespace MauticPlugin\EmailDeliverabilityBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class EmailDeliverabilityIntegration extends AbstractIntegration
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'EmailDeliverability';
    }

    /**
     * @return string
     */
    public function getDisplayName(): string
    {
        return 'Email Deliverability Checker';
    }

    /**
     * @return string
     */
    public function getAuthenticationType(): string
    {
        return 'none';
    }

    /**
     * @return array
     */
    public function getSupportedFeatures(): array
    {
        return [];
    }

    /**
     * @return array
     */
    public function getRequiredKeyFields(): array
    {
        return [
            'api_url' => 'mautic.emaildeliverability.config.api_url',
            'api_key' => 'mautic.emaildeliverability.config.api_key',
        ];
    }

    /**
     * Get default values for keys
     *
     * @return array
     */
    public function getKeys(): array
    {
        $keys = parent::getKeys();
        
        // Set default values if not configured
        if (empty($keys['api_url'])) {
            $keys['api_url'] = 'https://emaildelivery.space/me/checkemail';
        }
        
        return $keys;
    }

    /**
     * Get the icon for the integration
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'plugins/EmailDeliverabilityBundle/Assets/img/icon.png';
    }

    /**
     * Get API URL from configuration
     *
     * @return string
     */
    public function getApiUrl(): string
    {
        $keys = $this->getKeys();
        return isset($keys['api_url']) ? $keys['api_url'] : 'https://emaildelivery.space/me/checkemail';
    }

    /**
     * Get API Key from configuration
     *
     * @return string
     */
    public function getApiKey(): string
    {
        $keys = $this->getKeys();
        return isset($keys['api_key']) ? $keys['api_key'] : '';
    }
}
