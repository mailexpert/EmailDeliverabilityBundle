<?php

declare(strict_types=1);

namespace MauticPlugin\EmailDeliverabilityBundle\Helper;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;

class DeliverabilityChecker
{
    private $integrationHelper;
    private $logger;

    public function __construct(
        IntegrationHelper $integrationHelper,
        LoggerInterface $logger
    ) {
        $this->integrationHelper = $integrationHelper;
        $this->logger = $logger;
    }

    /**
     * Check email deliverability status
     *
     * @param string $email
     * @return string deliverable|soft_bounce|hard_bounce|unknown
     */
    public function check(string $email): string
    {
        // Get integration settings
        $integration = $this->integrationHelper->getIntegrationObject('EmailDeliverability');
        
        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            $this->logger->warning('Email Deliverability plugin is not configured or enabled');
            return 'unknown';
        }

        $apiUrl = $integration->getApiUrl();
        $apiKey = $integration->getApiKey();

        $this->logger->info('DeliverabilityChecker check called', [
            'email' => $email,
            'api_url' => $apiUrl,
            'has_api_key' => !empty($apiKey),
        ]);

        if (empty($apiUrl)) {
            $this->logger->warning('Email Deliverability API URL not configured');
            return 'unknown';
        }

        $url = rtrim($apiUrl, '/') . '?email=' . urlencode($email);
        
        $this->logger->info('Making API request', ['url' => $url]);
        
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => $apiKey ? "Authorization: Bearer $apiKey\r\n" : "",
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ];

        try {
            $context = stream_context_create($options);
            $response = @file_get_contents($url, false, $context);
            
            if ($response === false) {
                $this->logger->error('Failed to connect to Email Deliverability API', [
                    'email' => $email,
                    'url' => $url,
                ]);
                return 'unknown';
            }

            $this->logger->info('API response received', [
                'email' => $email,
                'response_length' => strlen($response),
                'response' => substr($response, 0, 200),
            ]);

            $data = json_decode($response, true);
            
            if (!is_array($data)) {
                $this->logger->warning('Invalid JSON response from Email Deliverability API', [
                    'email' => $email,
                    'response' => $response,
                ]);
                return 'unknown';
            }

            $status = strtolower($data['status'] ?? '');
            
            $this->logger->info('Processing API response', [
                'email' => $email,
                'status' => $status,
                'data' => json_encode($data),
            ]);
            
            switch ($status) {
                case 'delivered':
                    return 'deliverable';
                    
                case 'failed':
                    // Check for soft bounce
                    if (!empty($data['soft_bounce_date']) && $data['soft_bounce_date'] !== 'null') {
                        $this->logger->info('Email marked as soft bounce', ['email' => $email]);
                        return 'soft_bounce';
                    }
                    
                    // Check for hard bounce
                    if (!empty($data['hard_bounce_date']) && $data['hard_bounce_date'] !== 'null') {
                        $this->logger->info('Email marked as hard bounce', ['email' => $email]);
                        return 'hard_bounce';
                    }
                    
                    return 'unknown';
                    
                default:
                    $this->logger->debug('Unknown status from API', [
                        'email' => $email,
                        'status' => $status,
                    ]);
                    return 'unknown';
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Exception while checking email deliverability', [
                'email' => $email,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 'unknown';
        }
    }

    /**
     * Get API configuration status
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        $integration = $this->integrationHelper->getIntegrationObject('EmailDeliverability');
        
        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            return false;
        }

        $apiUrl = $integration->getApiUrl();
        return !empty($apiUrl);
    }

    /**
     * Get API URL for debugging
     *
     * @return string|null
     */
    public function getApiUrl(): ?string
    {
        $integration = $this->integrationHelper->getIntegrationObject('EmailDeliverability');
        
        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            return null;
        }

        return $integration->getApiUrl();
    }
}
