<?php

declare(strict_types=1);

namespace App;

use Psr\Log\LoggerInterface;

final class PostbackService
{
    public function __construct(
        private EventRepository $events,
        private DedupService $dedup,
        private PartnerClient $partner,
        private LoggerInterface $log,
    ) {
    }

    /** @return array{0:int, 1:array} [HTTP status, JSON body] */
    public function handle(array $query): array
    {
        $clickId = $this->str($query['click_id'] ?? null);
        $event = $this->str($query['event'] ?? null);
        $amount = $this->str($query['amount'] ?? null);

        if (
            $clickId === '' || strlen($clickId) > 255
            || $event === '' || strlen($event) > 100
            || !Amount::isValid($amount)
        ) {
            return [422, ['status' => 'error', 'message' => 'Invalid parameters']];
        }

        $normalized = Amount::normalize($amount);
        $key = DedupService::key($clickId, $event, $normalized);

        try {
            $isNew = $this->dedup->claim($key);
        } catch (\Throwable $e) {
            $this->log->error('Redis unavailable', ['error' => $e->getMessage()]);

            return [503, ['status' => 'error', 'message' => 'Service temporarily unavailable']];
        }

        if (!$isNew) {
            $this->log->info('Duplicate postback', ['click_id' => $clickId, 'event' => $event, 'amount' => $normalized]);

            return [200, ['status' => 'duplicate', 'message' => 'Event already received']];
        }

        try {
            $id = $this->events->create($clickId, $event, $normalized, $key);
        } catch (\Throwable $e) {
            $this->log->error('Failed to store event', ['click_id' => $clickId, 'error' => $e->getMessage()]);
            // Free the key, otherwise the ad network's retry would be treated as a duplicate of an event we never stored.
            try {
                $this->dedup->release($key);
            } catch (\Throwable) {
            }

            return [500, ['status' => 'error', 'message' => 'Failed to store event']];
        }

        $this->log->info('Postback accepted', ['event_id' => $id, 'click_id' => $clickId, 'event' => $event, 'amount' => $normalized]);

        $result = $this->partner->send($id, [
            'click_id' => $clickId,
            'event' => $event,
            'amount' => (float) $normalized,
        ]);

        try {
            $this->events->saveDelivery($id, $result);
        } catch (\Throwable $e) {
            $this->log->error('Failed to save delivery result', ['event_id' => $id, 'error' => $e->getMessage()]);
        }

        return [200, ['status' => 'accepted', 'message' => 'Event received']];
    }

    private function str(mixed $v): string
    {
        return is_string($v) ? trim($v) : '';
    }
}
