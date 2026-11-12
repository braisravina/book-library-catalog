<?php

declare(strict_types=1);

namespace DigitalLibrary\Http;

final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var (\Closure(resource): void)|null writes the body to a stream instead of $body */
    private ?\Closure $stream = null;

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
     * A body produced incrementally (e.g. a large export). The writer
     * receives a writable stream; nothing is buffered in memory when sent.
     *
     * @param callable(resource): void $writer
     * @param array<string, string>    $headers
     */
    public static function stream(callable $writer, array $headers, int $status = 200): self
    {
        $response = new self('', $status, $headers);
        $response->stream = \Closure::fromCallable($writer);

        return $response;
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

    /**
     * The full body. For streamed responses this runs the writer into
     * memory once (used by tests and HEAD handling, not by send()).
     */
    public function body(): string
    {
        if ($this->stream !== null) {
            $buffer = fopen('php://temp', 'w+b');
            ($this->stream)($buffer);
            rewind($buffer);
            $this->body = (string) stream_get_contents($buffer);
            fclose($buffer);
            $this->stream = null;
        }

        return $this->body;
    }

    public function isStreamed(): bool
    {
        return $this->stream !== null;
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
        if ($this->stream !== null) {
            $output = fopen('php://output', 'wb');
            ($this->stream)($output);
            fclose($output);

            return;
        }
        echo $this->body;
    }

    private static function canonical(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
