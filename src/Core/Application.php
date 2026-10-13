<?php

declare(strict_types=1);

namespace DigitalLibrary\Core;

use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Middleware\Authenticate;
use DigitalLibrary\Http\Middleware\Middleware;
use DigitalLibrary\Http\Middleware\RedirectIfAuthenticated;
use DigitalLibrary\Http\Middleware\RequirePermission;
use DigitalLibrary\Http\Middleware\SecurityHeaders;
use DigitalLibrary\Http\Middleware\SetLocale;
use DigitalLibrary\Http\Middleware\StartSession;
use DigitalLibrary\Http\Middleware\VerifyCsrf;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Http\Router;
use DigitalLibrary\Security\Permission;

final class Application
{
    private readonly Router $router;

    public function __construct(private readonly Services $services)
    {
        $this->router = new Router();
        (require $services->basePath . '/config/routes.php')($this->router, $services);
    }

    public function services(): Services
    {
        return $this->services;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): Response
    {
        try {
            // The health check stays outside the session/CSRF stack.
            if ($request->path() === '/health') {
                return $this->dispatch($request);
            }
            $config = $this->services->config;
            $global = [
                new SecurityHeaders($config->bool('SECURITY_HSTS', false)),
                new StartSession($this->services->session(), $this->services->csrf(), $this->services->view()),
                new SetLocale($this->services->translator(), $this->services->session(), $this->services->defaultLocale()),
            ];
            $errorBoundary = new class ($this) implements Middleware {
                public function __construct(private readonly Application $app)
                {
                }

                public function process(Request $request, callable $next): Response
                {
                    try {
                        return $next($request);
                    } catch (\Throwable $e) {
                        return $this->app->renderError($e, $request);
                    }
                }
            };
            $global[] = $errorBoundary;
            $global[] = new VerifyCsrf($this->services->csrf(), $config->get('APP_URL'));

            return $this->pipeline($global, fn (Request $r): Response => $this->dispatch($r))($request);
        } catch (\Throwable $e) {
            return $this->renderError($e, $request, false);
        }
    }

    public function renderError(\Throwable $e, Request $request, bool $withLayout = true): Response
    {
        $status = $e instanceof HttpException ? $e->status() : 500;
        if ($status >= 500) {
            // Full detail goes to the server log only.
            error_log(sprintf('[digital-library] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
        }
        $debug = $this->services->config->debug() ? $e::class . ': ' . $e->getMessage() : null;

        try {
            $view = $this->services->view();
            $translator = $this->services->translator();
            $titleKey = 'errors.' . $status . '.title';
            $messageKey = $e instanceof HttpException ? $e->getMessage() : 'errors.500.message';
            $html = $view->render('errors/error', [
                'status' => $status,
                'title' => $translator->has($titleKey) ? $titleKey : 'errors.generic.title',
                'message' => $translator->has($messageKey) ? $messageKey : 'errors.generic.message',
                'debug' => $debug,
                'page_title' => $translator->get($translator->has($titleKey) ? $titleKey : 'errors.generic.title'),
            ], $withLayout ? 'layout' : null);
            $response = Response::html($html, $status);
        } catch (\Throwable) {
            $response = new Response('An unexpected error occurred.', $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ($status === 405) {
            $response->setHeader('Allow', 'GET, POST');
        }

        return (new SecurityHeaders())->process($request, static fn (): Response => $response);
    }

    private function dispatch(Request $request): Response
    {
        [$route, $params] = $this->router->match($request->method(), $request->path());
        $middleware = array_map(fn (string $alias): Middleware => $this->resolveMiddleware($alias), $route->middleware);
        $handler = $route->handler;

        $response = $this->pipeline($middleware, static fn (Request $r): Response => $handler($r, $params))($request);
        if ($request->method() === 'HEAD') {
            return new Response('', $response->status(), $response->headers());
        }

        return $response;
    }

    private function resolveMiddleware(string $alias): Middleware
    {
        if (str_starts_with($alias, 'can:')) {
            return new RequirePermission($this->services->authorizer(), Permission::from(substr($alias, 4)));
        }

        return match ($alias) {
            'auth' => new Authenticate($this->services->auth(), $this->services->session()),
            'guest' => new RedirectIfAuthenticated($this->services->auth()),
            default => throw new \LogicException('Unknown middleware alias ' . $alias),
        };
    }

    /**
     * @param list<Middleware>           $middleware
     * @param callable(Request): Response $core
     *
     * @return callable(Request): Response
     */
    private function pipeline(array $middleware, callable $core): callable
    {
        $next = $core;
        foreach (array_reverse($middleware) as $layer) {
            $next = static fn (Request $request): Response => $layer->process($request, $next);
        }

        return $next;
    }
}
