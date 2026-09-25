<?php

declare(strict_types=1);

namespace DPay\Laravel\Testing;

/** One API call your code made through the fake. */
final class RecordedRequest
{
    /** @var array<string, mixed>|null */
    public readonly ?array $json;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
    ) {
        $decoded = $body === null ? null : json_decode($body, true);
        $json = null;
        if (is_array($decoded)) {
            $json = [];
            foreach ($decoded as $key => $value) {
                $json[(string) $key] = $value;
            }
        }
        $this->json = $json;
    }

    public function path(): string
    {
        return (string) parse_url($this->url, PHP_URL_PATH);
    }

    /** @return array<string, string> */
    public function query(): array
    {
        $out = [];
        parse_str((string) parse_url($this->url, PHP_URL_QUERY), $raw);
        foreach ($raw as $k => $v) {
            $out[(string) $k] = is_scalar($v) ? (string) $v : '';
        }

        return $out;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return $value;
            }
        }

        return null;
    }

    public function idempotencyKey(): ?string
    {
        return $this->header('Idempotency-Key');
    }

    public function is(string $method, string $path): bool
    {
        return strtoupper($method) === $this->method && $this->path() === $path;
    }
}
