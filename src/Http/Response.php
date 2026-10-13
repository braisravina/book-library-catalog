<?php

declare(strict_types=1);

namespace DigitalLibrary\Http;

final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @param array<mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    /**
     * Raw bytes (e.g. an image). Callers set the content type explicitly.
     */
    public static function binary(string $bytes, string $contentType, int $status = 200): self
    {
        return new self($bytes, $status, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    /**
     * Redirects only to local, absolute paths to rule out open redirects.
     */
    public static function redirect(string $path, int $status = 303): self
    {
        if (!self::isSafeLocalPath($path)) {
            $path = '/';
        }

        return new self('', $status, ['Location' => $path]);
    }

    public static function isSafeLocalPath(string $path): bool
    {
        return str_starts_with($path, '/')
            && !str_starts_with($path, '//')
            && !str_contains($path, '\\')
            && !preg_match('/[\x00-\x1F\x7F]/', $path);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function setHeader(string $name, string $value): self
    {
        // Header injection guard: values must never contain line breaks.
        if (preg_match('/[\r\n]/', $name . $value)) {
            throw new \InvalidArgumentException('Header values must not contain line breaks.');
        }
        $this->headers[self::canonical($name)] = $value;

        return $this;
    }

    public function header(string $name): ?string
    {
        return $this->headers[self::canonical($name)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        echo $this->body;
    }

    private static function canonical(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
