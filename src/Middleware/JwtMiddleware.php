<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

class JwtMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if ($authHeader) {
            $jwt = sscanf($authHeader, 'Bearer %s')[0] ?? null;

            if ($jwt) {
                $decoded = JwtHelper::validateToken($jwt);

                if ($decoded) {
                    $request = $request->withAttribute('user', $decoded->data);
                    return $handler->handle($request);
                }
            }
        }

        $response = new SlimResponse();
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }
}
