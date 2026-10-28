<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http;
use App\PostbackService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PostbackController
{
    public function __construct(private PostbackService $service)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        [$status, $body] = $this->service->handle($request->getQueryParams());

        return Http::json($response, $status, $body);
    }
}
