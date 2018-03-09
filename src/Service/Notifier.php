<?php
declare(strict_types=1);

namespace App\Service;

use GuzzleHttp\Client as GuzzleClient;

class Notifier
{
    /**
     * Is slack enabled to receive notifications
     * @var boolean
     */
    protected $slackEnabled = false;

    /**
     * Slack configuration if enabled
     * @var array
     */
    protected $slack = [];

    /**
     * Message that is to be sent
     * @var string
     */
    protected $message;

    /**
     * @param array $notificationConfig
     */
    public function __construct(
        array $notificationConfig
    ) {
        if (false !== $notificationConfig['slack_enabled']) {
            $this->slackEnabled = true;
            $this->slack = [
                'url' => $notificationConfig['slack_url'],
                'channel' => $notificationConfig['slack_channel'],
            ];
        }
    }

    /**
     * Check if slack is enabled
     * @return boolean
     */
    private function isSlackEnabled()
    {
        return ($this->slackEnabled === true);
    }

    /**
     * Return new Guzzle client
     * @return \GuzzleHttp\Client
     */
    protected function getGuzzle()
    {
        return new GuzzleClient();
    }

    /**
     * Send a message to all enabled services
     * @param  string $message
     * @return int The number of successful messages sent
     */
    public function sendNotification($message)
    {
        $this->message = $message;
        $count = 0;
        foreach (['Slack'] as $type) {
            $function = 'send' . $type;
            $success = $this->$function();

            if (true === $success) {
                $count += 1;
            }
        }

        return $count;
    }

    /**
     * Send a message to the configured slack channel
     * @return boolean
     */
    private function sendSlack()
    {
        if (false === $this->isSlackEnabled()) {
            return false;
        }

        $payload = [
            'channel' => $this->slack['channel'],
            'text' => $this->message,
            'username' => 'Database Dumper'
        ];

        $guzzle = $this->getGuzzle();
        $response = $guzzle->request('POST', $this->slack['url'], [
            'headers' => [
                'Accept'     => 'application/json'
            ],
            'json' => $payload
        ]);

        return($response->getStatusCode() === 200);
    }
}
