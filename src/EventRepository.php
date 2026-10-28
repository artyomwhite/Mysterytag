<?php

declare(strict_types=1);

namespace App;

use PDO;

final class EventRepository
{
    private ?PDO $pdo = null;

    /** @param callable():PDO $connect lazy connection factory */
    public function __construct(private $connect)
    {
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= ($this->connect)();
    }

    public function create(string $clickId, string $event, string $amount, string $dedupHash): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO events (click_id, event, amount, dedup_hash) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$clickId, $event, $amount, $dedupHash]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function saveDelivery(int $eventId, DeliveryResult $result): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $ins = $pdo->prepare(
                'INSERT INTO delivery_attempts (event_id, attempt_no, http_status, error, duration_ms)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($result->attempts as $a) {
                $ins->execute([$eventId, $a['no'], $a['http_status'], $a['error'], $a['duration_ms']]);
            }

            $pdo->prepare(
                'UPDATE events
                    SET status = ?, attempts = ?, last_http_status = ?, last_error = ?, processed_at = CURRENT_TIMESTAMP(3)
                  WHERE id = ?'
            )->execute([
                $result->success ? 'sent' : 'failed',
                count($result->attempts),
                $result->lastHttpStatus(),
                $result->success ? null : $result->lastError(),
                $eventId,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @return array{items: array<int,array>, total: int} */
    public function list(?string $status, ?string $clickId, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($clickId !== null) {
            $where[] = 'click_id = ?';
            $params[] = $clickId;
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $count = $this->pdo()->prepare("SELECT COUNT(*) FROM events {$w}");
        $count->execute($params);

        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $stmt = $this->pdo()->prepare("SELECT * FROM events {$w} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);

        return [
            'items' => array_map([$this, 'format'], $stmt->fetchAll()),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM events WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $att = $this->pdo()->prepare(
            'SELECT attempt_no, http_status, error, duration_ms, created_at
               FROM delivery_attempts WHERE event_id = ? ORDER BY attempt_no'
        );
        $att->execute([$id]);

        $event = $this->format($row);
        $event['delivery_attempts'] = array_map(static fn (array $a) => [
            'attempt_no' => (int) $a['attempt_no'],
            'http_status' => $a['http_status'] !== null ? (int) $a['http_status'] : null,
            'error' => $a['error'],
            'duration_ms' => (int) $a['duration_ms'],
            'created_at' => $a['created_at'],
        ], $att->fetchAll());

        return $event;
    }

    private function format(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'click_id' => $r['click_id'],
            'event' => $r['event'],
            'amount' => (float) Amount::normalize($r['amount']),
            'status' => $r['status'],
            'attempts' => (int) $r['attempts'],
            'last_http_status' => $r['last_http_status'] !== null ? (int) $r['last_http_status'] : null,
            'last_error' => $r['last_error'],
            'created_at' => $r['created_at'],
            'processed_at' => $r['processed_at'],
        ];
    }
}
