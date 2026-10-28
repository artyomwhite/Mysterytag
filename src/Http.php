<?php

declare(strict_types=1);

namespace App;

use Psr\Http\Message\ResponseInterface;

final class Http
{
    public static function json(ResponseInterface $response, int $status, array $body): ResponseInterface
    {
        $response->getBody()->write(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
