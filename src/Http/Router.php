<?php
declare(strict_types=1);

namespace Dormitory\Http;

use Dormitory\Application;
use Throwable;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:callable,options:array<string,mixed>}> */
    private array $routes = [];

    public function __construct(private readonly Application $app)
    {
    }

    /** @param array<string,mixed> $options */
    public function add(string $method, string $path, callable $handler, array $options = []): self
    {
        $quoted = preg_quote($path, '#');
        $regex = preg_replace('/\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}/', '(?P<$1>[^/]+)', $quoted);
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '/?$#',
            'handler' => $handler,
            'options' => $options,
        ];
        return $this;
    }

    public function get(string $path, callable $handler, array $options = []): self
    {
        return $this->add('GET', $path, $handler, $options);
    }

    public function post(string $path, callable $handler, array $options = []): self
    {
        return $this->add('POST', $path, $handler, $options);
    }

    public function put(string $path, callable $handler, array $options = []): self
    {
        return $this->add('PUT', $path, $handler, $options);
    }

    public function delete(string $path, callable $handler, array $options = []): self
    {
        return $this->add('DELETE', $path, $handler, $options);
    }

    public function dispatch(Request $request): Response
    {
        try {
            foreach ($this->routes as $route) {
                if ($request->method !== $route['method'] || !preg_match($route['regex'], $request->path, $matches)) {
                    continue;
                }
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = rawurldecode($value);
                    }
                }
                $matched = $request->withParams($params);
                $this->app->guard($matched, $route['options']);
                $response = ($route['handler'])($matched);
                if (!$response instanceof Response) {
                    throw new HttpException(500, 'Route did not return a response', 'INVALID_HANDLER');
                }
                return $response;
            }

            return str_starts_with($request->path, '/api/')
                ? Response::error('Not found', 404, 'NOT_FOUND')
                : Response::htmlError(404);
        } catch (HttpException $error) {
            if (!str_starts_with($request->path, '/api/') && $error->status === 401) {
                $page = rtrim($request->path, '/') ?: '/';
                if ($page === '/resident') {
                    return Response::redirect('/resident/login');
                }
                if ($page === '/admin') {
                    return Response::redirect('/admin/login');
                }
            }
            return str_starts_with($request->path, '/api/')
                ? Response::error($error->getMessage(), $error->status, $error->errorCode, $error->details)
                : Response::htmlError($error->status, $error->status >= 500 ? $request->requestId : null);
        } catch (Throwable $error) {
            error_log(sprintf('[%s] %s: %s', $request->requestId, $error::class, $error->getMessage()));
            return str_starts_with($request->path, '/api/')
                ? Response::error('Internal server error', 500, 'INTERNAL_ERROR', ['request_id' => $request->requestId])
                : Response::htmlError(500, $request->requestId);
        }
    }
}
