<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventRepository;
use App\Http;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class EventController
{
    private const STATUSES = ['pending', 'sent', 'failed'];

    public function __construct(private EventRepository $events)
    {
    }

    /** GET /events?status=&click_id=&limit=&offset= */
    public function index(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();

        $status = isset($q['status']) && is_string($q['status']) && $q['status'] !== '' ? $q['status'] : null;
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            return Http::json($response, 422, ['status' => 'error', 'message' => 'Invalid status filter']);
        }
        $clickId = isset($q['click_id']) && is_string($q['click_id']) && $q['click_id'] !== '' ? $q['click_id'] : null;

        $limit = min(100, max(1, (int) ($q['limit'] ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $result = $this->events->list($status, $clickId, $limit, $offset);

        return Http::json($response, 200, [
            'data' => $result['items'],
            'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    /** GET /events/{id} — event with the full list of delivery attempts */
    public function show(Request $request, Response $response, array $args): Response
    {
        $event = $this->events->find((int) $args['id']);

        if ($event === null) {
            return Http::json($response, 404, ['status' => 'error', 'message' => 'Event not found']);
        }

        return Http::json($response, 200, ['data' => $event]);
    }
}
