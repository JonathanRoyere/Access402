<?php

declare(strict_types=1);

namespace Access402\Services;

use Access402\Support\Helpers;

final class ProtectedFileUrlService
{
    public const QUERY_ARG = 'access402_file';

    public function __construct(private readonly RuleMatcher $matcher)
    {
    }

    public function current_request_token(): string
    {
        $value = $_GET[self::QUERY_ARG] ?? '';

        return sanitize_text_field(wp_unslash((string) $value));
    }

    public function filter_attachment_url(string $url, int $attachment_id = 0): string
    {
        unset($attachment_id);

        return $this->maybe_protect_url($url);
    }

    public function maybe_protect_url(string $url): string
    {
        $url = trim($url);

        if ($url === '' || $this->is_protected_url($url)) {
            return $url;
        }

        $path = $this->public_path_from_url($url);

        if ($path === null || ! $this->is_likely_file_path($path) || ! $this->is_protected_path($path)) {
            return $url;
        }

        return $this->protected_url_for_path($path);
    }

    public function rewrite_content_urls(string $content): string
    {
        if ($content === '' || ! str_contains($content, 'href=')) {
            return $content;
        }

        $pattern = '/\bhref=([\'"])([^\'"]+)\1/i';

        return (string) preg_replace_callback(
            $pattern,
            function (array $matches): string {
                $rewritten = $this->maybe_protect_url(html_entity_decode((string) ($matches[2] ?? ''), ENT_QUOTES, 'UTF-8'));

                if ($rewritten === (string) ($matches[2] ?? '')) {
                    return (string) ($matches[0] ?? '');
                }

                return sprintf(
                    'href=%1$s%2$s%1$s',
                    (string) ($matches[1] ?? '"'),
                    esc_url($rewritten)
                );
            },
            $content
        );
    }

    public function protected_url_for_path(string $path): string
    {
        return add_query_arg(
            self::QUERY_ARG,
            $this->encode_path_token($path),
            home_url('/')
        );
    }

    public function decode_path_token(string $token): string|\WP_Error
    {
        $token = trim($token);

        if ($token === '') {
            return new \WP_Error('access402_invalid_file_token', __('The protected file request is missing its target path token.', 'access402'));
        }

        $padding = (4 - (strlen($token) % 4)) % 4;
        $decoded = base64_decode(strtr($token . str_repeat('=', $padding), '-_', '+/'), true);

        if (! is_string($decoded) || trim($decoded) === '') {
            return new \WP_Error('access402_invalid_file_token', __('The protected file request path token could not be decoded.', 'access402'));
        }

        $path = Helpers::normalize_path($decoded);

        if ($path === '/' || str_contains($path, "\0")) {
            return new \WP_Error('access402_invalid_file_path', __('The protected file request path is not valid.', 'access402'));
        }

        return $path;
    }

    public function resolve_local_file(string $public_path): array|\WP_Error
    {
        $public_path   = Helpers::normalize_path($public_path);
        $relative_path = $this->relative_public_path($public_path);
        $root_path     = realpath(ABSPATH);
        $absolute_path = realpath(ABSPATH . ltrim($relative_path, '/'));

        if (! is_string($root_path) || ! is_string($absolute_path)) {
            return new \WP_Error('access402_file_missing', __('The requested file could not be found.', 'access402'));
        }

        if (! str_starts_with($absolute_path, $root_path . DIRECTORY_SEPARATOR) && $absolute_path !== $root_path) {
            return new \WP_Error('access402_file_outside_root', __('The requested file is outside the WordPress root and cannot be served.', 'access402'));
        }

        if (! is_file($absolute_path) || ! is_readable($absolute_path)) {
            return new \WP_Error('access402_file_missing', __('The requested file could not be read.', 'access402'));
        }

        $filename = wp_basename($absolute_path);
        $mime     = wp_check_filetype($absolute_path);
        $mime_type = is_array($mime) && ! empty($mime['type']) ? (string) $mime['type'] : 'application/octet-stream';

        return [
            'public_path'   => $public_path,
            'absolute_path' => $absolute_path,
            'filename'      => $filename,
            'mime_type'     => $mime_type,
            'size'          => (int) filesize($absolute_path),
            'last_modified' => gmdate('D, d M Y H:i:s', (int) filemtime($absolute_path)) . ' GMT',
        ];
    }

    public function is_protected_path(string $path): bool
    {
        return is_array($this->matcher->match($path));
    }

    private function encode_path_token(string $path): string
    {
        return rtrim(strtr(base64_encode(Helpers::normalize_path($path)), '+/', '-_'), '=');
    }

    private function is_protected_url(string $url): bool
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        if ($query === '') {
            return false;
        }

        parse_str($query, $params);

        return isset($params[self::QUERY_ARG]);
    }

    private function public_path_from_url(string $url): ?string
    {
        $parts = wp_parse_url($url);

        if ($parts === false || ! is_array($parts)) {
            return null;
        }

        $url_host  = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

        if ($url_host !== '' && $site_host !== '' && $url_host !== $site_host) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');

        if ($path === '') {
            return null;
        }

        return Helpers::normalize_path($path);
    }

    private function relative_public_path(string $path): string
    {
        $path      = Helpers::normalize_path($path);
        $site_path = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        $site_path = Helpers::normalize_path($site_path === '' ? '/' : $site_path);

        if ($site_path !== '/' && str_starts_with($path, $site_path . '/')) {
            return '/' . ltrim(substr($path, strlen($site_path)), '/');
        }

        if ($path === $site_path) {
            return '/';
        }

        return $path;
    }

    private function is_likely_file_path(string $path): bool
    {
        return pathinfo($path, PATHINFO_EXTENSION) !== '';
    }
}
