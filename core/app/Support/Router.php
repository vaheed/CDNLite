<?php

namespace App\Support;

class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, bool $auth = false, bool $edgeAuth = false): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
            'auth' => $auth,
            'edge_auth' => $edgeAuth,
        ];
    }

    public function dispatch(Request $request): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = $this->match($route['pattern'], $request->path);
            if ($params === null) {
                continue;
            }

            return ['route' => $route, 'params' => $params];
        }

        return null;
    }

    private function match(string $pattern, string $path): ?array
    {
        if (!str_contains($pattern, '{')) {
            return $pattern === $path ? [] : null;
        }

        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $m): string {
            return '(?P<' . $m[1] . '>[0-9a-fA-F-]+)';
        }, preg_quote($pattern, '#'));
        $regex = str_replace('\{\?\}', '(.*?)', $regex ?? '');
        if (!is_string($regex)) {
            return null;
        }

        if (!preg_match('#^' . $regex . '$#', $path, $m)) {
            return null;
        }

        $params = [];
        foreach ($m as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }
}

