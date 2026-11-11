<?php

declare(strict_types=1);

namespace DigitalLibrary\Core;

use DigitalLibrary\Http\HostPolicy;
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
            $config = $this->services->config;
            // Fail closed: production refuses to serve with an invalid configuration
            // (the same rules as `bin/console config:check`).
            if ($this->misconfiguredProduction()) {
                return $request->path() === '/health'
                    ? self::unavailable(Response::json(['status' => 'unavailable'], 503))
                    : self::unavailable($this->renderError(new HttpException(503), $request, false));
            }
            // The health check stays outside the Host, session and CSRF stack
            // (container probes address the container directly).
            if ($request->path() === '/health') {
                return $this->dispatch($request);
            }
            // Only the host of APP_URL is answered; malformed or foreign Host
            // headers are refused before any session or database work.
            $hosts = HostPolicy::fromAppUrl($config->get('APP_URL'));
            if ($hosts !== null && !$hosts->allows($request->header('Host'))) {
                return $this->renderError(new HttpException(400, 'errors.400.message'), $request, false);
            }
            // Maintenance mode (restores): refuse everything before any session or database work.
            if ($this->services->maintenance()->isActive()) {
                $response = $this->renderError(new HttpException(503), $request, false);
                $response->setHeader('Retry-After', '300');

                return $response;
            }
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

    /**
     * Production validation results per worker process, keyed by the raw
     * configuration (Config::fingerprint()): the setting names in error, or
     * an empty list. The environment of a PHP-FPM worker does not change
     * while it runs, so validating once per process and configuration is
     * equivalent to validating every request; a changed configuration (new
     * container or worker) is validated again. Bounded in size.
     *
     * @var array<string, list<string>>
     */
    private static array $productionErrors = [];

    private function misconfiguredProduction(): bool
    {
        $config = $this->services->config;
        if (!$config->isProduction()) {
            return false;
        }
        $key = $config->fingerprint();
        if (!array_key_exists($key, self::$productionErrors)) {
            $errors = (new ConfigValidator($this->services->basePath))->validate($config)['errors'];
            $keys = array_values(array_unique(array_column($errors, 0)));
            if (count(self::$productionErrors) >= 8) {
                self::$productionErrors = [];
            }
            self::$productionErrors[$key] = $keys;
            if ($keys !== []) {
                // Logged once per process and configuration; keys only, never values.
                error_log('[digital-library] refusing to serve: invalid production configuration (' . implode(', ', $keys) . '); run bin/console config:check');
            }
        }

        return self::$productionErrors[$key] !== [];
    }

    private static function unavailable(Response $response): Response
    {
        $response->setHeader('Retry-After', '300');
        $response->setHeader('Cache-Control', 'no-store');

        return $response;
    }

    public function renderError(\Throwable $e, Request $request, bool $withLayout = true): Response
    {
        $status = $e instanceof HttpException ? $e->status() : 500;
        if ($status >= 500 && $status !== 503) {
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
