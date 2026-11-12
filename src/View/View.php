<?php

declare(strict_types=1);

namespace DigitalLibrary\View;

use DigitalLibrary\I18n\Translator;

/**
 * Plain-PHP template renderer. Templates receive their data as local
 * variables plus $view; all output must go through $view->e() (or helpers
 * that escape internally). Template names are restricted to a safe charset
 * so they can never be used for path traversal.
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(
        private readonly string $directory,
        private readonly Translator $translator,
    ) {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function shared(string $key, mixed $default = null): mixed
    {
        return $this->shared[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }

        return $this->renderFile($layout, $data + ['content' => $content]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function partial(string $template, array $data = []): string
    {
        return $this->renderFile($template, $data);
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Translated and escaped.
     *
     * @param array<string, string|int> $params
     */
    public function t(string $key, array $params = []): string
    {
        return $this->e($this->translator->get($key, $params));
    }

    /**
     * Translated but NOT escaped: only for passing to helpers or partials that
     * escape their input (e.g. option labels in partials/select).
     *
     * @param array<string, string|int> $params
     */
    public function trans(string $key, array $params = []): string
    {
        return $this->translator->get($key, $params);
    }

    public function locale(): string
    {
        return $this->translator->locale();
    }

    /**
     * Builds a local URL with an escaped query string; empty values are dropped.
     *
     * @param array<string, scalar|null> $query
     */
    public function url(string $path, array $query = []): string
    {
        $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');

        return $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->e($this->shared('csrf_token', '')) . '">';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderFile(string $template, array $data): string
    {
        if (!preg_match('#^[a-z0-9_]+(?:/[a-z0-9_]+)*$#', $template)) {
            throw new \InvalidArgumentException('Invalid template name.');
        }
        $file = $this->directory . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Template not found: ' . $template);
        }
        $view = $this;
        $vars = $data + $this->shared;

        return (static function () use ($file, $vars, $view): string {
            extract($vars, EXTR_SKIP);
            ob_start();
            try {
                require $file;

                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        })();
    }
}
