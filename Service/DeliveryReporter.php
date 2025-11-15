<?php

namespace MauticPlugin\EmailDeliverabilityBundle\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Mautic\PluginBundle\Helper\IntegrationHelper;

class DeliveryReporter
{
    private $integrationHelper;
    private $client;
    private $logger;
    private $cache;

    public function __construct(
        LoggerInterface $logger,
        IntegrationHelper $integrationHelper
    ) {
        $this->logger = $logger;
        $this->integrationHelper = $integrationHelper;
        $this->client = new Client([
            'timeout' => 10,
            'http_errors' => false,
        ]);
        $this->cache = [];
        @file_put_contents('/tmp/delivery_reporter_construct.log', date('Y-m-d H:i:s') . " - DeliveryReporter constructed\n", FILE_APPEND);
    }

    /**
     * Get the integration instance
     */
    private function getIntegration()
    {
        return $this->integrationHelper->getIntegrationObject('EmailDeliverability');
    }

    /**
     * Check if integration is configured and enabled
     */
    private function isEnabled()
    {
        $integration = $this->getIntegration();
        @file_put_contents('/tmp/delivery_reporter_isenabled.log', date('Y-m-d H:i:s') . " - isEnabled check\n", FILE_APPEND);

        if (!$integration || !$integration->getIntegrationSettings()->getIsPublished()) {
            @file_put_contents('/tmp/delivery_reporter_isenabled.log', "  Integration NOT found\n", FILE_APPEND);
            return false;
        }
        try {
            $settings = $integration->getIntegrationSettings();
            
            if (!$settings || !$settings->getIsPublished()) {
                @file_put_contents('/tmp/delivery_reporter_isenabled.log', "  Integration NOT published\n", FILE_APPEND);
                return false;
            }

            $apiKey = $integration->getApiKey();

            if (empty($apiKey)) {
                @file_put_contents('/tmp/delivery_reporter_isenabled.log', "  API key EMPTY\n", FILE_APPEND);
                return false;
            }

            @file_put_contents('/tmp/delivery_reporter_isenabled.log', "  Integration ENABLED\n", FILE_APPEND);
            return true;
        } catch (\Exception $e) {
            @file_put_contents('/tmp/delivery_reporter_isenabled.log', "  ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
    }

    /**
     * Record email send for later correlation
     */
    public function recordEmailSend($email, \DateTime $sendDate, $emailId = null)
    {
        @file_put_contents('/tmp/delivery_reporter_record.log', date('Y-m-d H:i:s') . " - recordEmailSend called for: $email\n", FILE_APPEND);
        $this->logger->info('recordEmailSend called', [
            'email' => $email,
            'email_id' => $emailId,
        ]);
        if (!$this->isEnabled()) {
            @file_put_contents('/tmp/delivery_reporter_record.log', "  Integration not enabled, skipping\n", FILE_APPEND);
            return;
        }
        $this->cache[$email] = [
            'send_date' => $sendDate,
            'email_id' => $emailId,
        ];

        $this->logger->debug("Recorded email send", [
            'email' => $email,
            'send_date' => $sendDate->format('Y-m-d H:i:s'),
        ]);
        @file_put_contents('/tmp/delivery_reporter_record.log', "  Email send recorded: $email\n", FILE_APPEND);
    }

    /**
     * Get stored send date for an email
     */
    private function getSendDate($email)
    {
        if (isset($this->cache[$email]['send_date'])) {
            return $this->cache[$email]['send_date'];
        }
        
        return new \DateTime();
    }

    /**
     * Report successful delivery
     */
    public function reportDelivery($email, \DateTime $deliveredDate, $smtpCode = 250, $smtpMessage = 'OK', $status = 'sent')
    {
        @file_put_contents('/tmp/delivery_reporter_delivery.log', date('Y-m-d H:i:s') . " - reportDelivery called for: $email\n", FILE_APPEND);
        if (!$this->isEnabled()) {
            return false;
        }

        $sendDate = $this->getSendDate($email);

        $payload = [
            'email' => $email,
            'send_date' => $sendDate->format('Y-m-d H:i:s'),
            'delivered_date' => $deliveredDate->format('Y-m-d H:i:s'),
            'hard_bounce_date' => null,
            'hard_bounce_reason' => '',
            'soft_bounce_date' => null,
            'soft_bounce_reason' => '',
            'smtp_code' => (int)$smtpCode,
            'smtp_message' => $smtpMessage,
            'status' => $status,
        ];

        return $this->sendToApi($payload, $email);
    }

    /**
     * Report email failure
     */
    public function reportFailure($email, \DateTime $failureDate, $smtpCode, $reason, $bounceType = 'hard_bounce')
    {
        @file_put_contents('/tmp/delivery_reporter_failure.log', date('Y-m-d H:i:s') . " - reportFailure called for: $email\n", FILE_APPEND);
        if (!$this->isEnabled()) {
            return false;
        }
        //Just in case email address is in the reason, replace with generic parameter
        $reason = str_replace($email, "<email>", $reason);

        $sendDate = $this->getSendDate($email);

        $isHardBounce = ($bounceType === 'hard_bounce');

        $payload = [
            'email' => $email,
            'send_date' => $sendDate->format('Y-m-d H:i:s'),
            'delivered_date' => null,
            'hard_bounce_date' => $isHardBounce ? $failureDate->format('Y-m-d H:i:s') : null,
            'hard_bounce_reason' => $isHardBounce ? $reason : '',
            'soft_bounce_date' => !$isHardBounce ? $failureDate->format('Y-m-d H:i:s') : null,
            'soft_bounce_reason' => !$isHardBounce ? $reason : '',
            'smtp_code' => (int)$smtpCode,
            'smtp_message' => $reason,
            'status' => 'failed',
        ];

        return $this->sendToApi($payload, $email);
    }

    /**
     * Send payload to API
     */
    private function sendToApi(array $payload, $email)
    {
        @file_put_contents('/tmp/delivery_reporter_api.log', date('Y-m-d H:i:s') . " - sendToApi called\n", FILE_APPEND);
        @file_put_contents('/tmp/delivery_reporter_api.log', "  Payload: " . json_encode($payload) . "\n", FILE_APPEND);
        
        try {
            $integration = $this->getIntegration();
            
            if (!$integration) {
                @file_put_contents('/tmp/delivery_reporter_api.log', "  Integration not found\n", FILE_APPEND);
                return false;
            }

            $apiUrl = $integration->getSubmitUrl();
            $apiKey = $integration->getApiKey();

            @file_put_contents('/tmp/delivery_reporter_api.log', "  API URL: $apiUrl\n", FILE_APPEND);
            @file_put_contents('/tmp/delivery_reporter_api.log', "  API Key: " . (empty($apiKey) ? 'EMPTY' : 'SET') . "\n", FILE_APPEND);

            if (empty($apiUrl) || empty($apiKey)) {
                $this->logger->warning("Email Deliverability API not configured properly");
                return false;
            }

            $response = $this->client->post($apiUrl, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            @file_put_contents('/tmp/delivery_reporter_api.log', "  Response: $statusCode\n", FILE_APPEND);           
            if ($statusCode >= 200 && $statusCode < 300) {
                @file_put_contents('/tmp/delivery_reporter_api.log', "  SUCCESS!\n", FILE_APPEND);
                $this->logger->info("Email delivery reported to API successfully", [
                    'email' => $email,
                    'status' => $payload['status'],
                    'http_status' => $statusCode,
                ]);
            } else {
                @file_put_contents('/tmp/delivery_reporter_api.log', "  ERROR: " . $response->getBody()->getContents() . "\n", FILE_APPEND);
                $this->logger->warning("Email delivery API returned non-success status", [
                    'email' => $email,
                    'http_status' => $statusCode,
                    'response' => $response->getBody()->getContents(),
                ]);
            }

            unset($this->cache[$email]);
            return true;
        } catch (\Exception $e) {
            @file_put_contents('/tmp/delivery_reporter_api.log', "  EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
            $this->logger->error("Failed to report email delivery to API", [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
