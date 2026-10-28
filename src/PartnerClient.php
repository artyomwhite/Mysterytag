<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

final class PartnerClient
{
    public function __construct(
        private ClientInterface $http,
        private string $url,
        private LoggerInterface $log,
        private int $maxAttempts = 3,
        private int $retryDelayMs = 200,
    ) {
    }

    /**
     * Sends the event; retries (up to $maxAttempts in total) on network errors,
     * timeouts, HTTP 429 and HTTP 5xx. Other 4xx are not retried.
     */
    public function send(int $eventId, array $payload): DeliveryResult
    {
        $attempts = [];
        $success = false;

        for ($no = 1; $no <= $this->maxAttempts; $no++) {
            $started = microtime(true);
            $status = null;
            $error = null;
            $retryable = false;

            try {
                $response = $this->http->request('POST', $this->url, ['json' => $payload]);
                $status = $response->getStatusCode();

                if ($status >= 200 && $status < 300) {
                    $success = true;
                } elseif ($status === 429 || $status >= 500) {
                    $retryable = true;
                    $error = "HTTP {$status}";
                } else {
                    $error = "HTTP {$status} (not retried)";
                }
            } catch (GuzzleException $e) {
                $retryable = true;
                // Keep this compatible with the slim PHP image without requiring mbstring.
                $error = substr($e->getMessage(), 0, 500);
            }

            $attempts[] = [
                'no' => $no,
                'http_status' => $status,
                'error' => $error,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];

            if ($success) {
                $this->log->info('Partner delivery ok', ['event_id' => $eventId, 'attempt' => $no, 'http_status' => $status]);
                break;
            }

            $this->log->warning('Partner delivery failed', [
                'event_id' => $eventId,
                'attempt' => $no,
                'max_attempts' => $this->maxAttempts,
                'http_status' => $status,
                'error' => $error,
                'will_retry' => $retryable && $no < $this->maxAttempts,
            ]);

            if (!$retryable) {
                break;
            }

            if ($no < $this->maxAttempts) {
                usleep($this->retryDelayMs * 1000 * (2 ** ($no - 1))); // 200ms, 400ms, ...
            }
        }

        return new DeliveryResult($success, $attempts);
    }
}
