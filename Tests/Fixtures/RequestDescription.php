<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test-environment only: what a request carried, as the /_test/echo and /_test/sink routes report
 * it, so a test can pin what an outbound client sent.
 */
final class RequestDescription
{
    /**
     * @return array{method: string, query: array<string, mixed>, headers: array<string, string>, content_type: ?string, body: string, fields: array<string, mixed>, files: list<array{field: string, name: string, type: string, size: int, sha256: string}>}
     */
    public static function of(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', array_map('strval', (array) $values));
        }

        $files = [];
        self::collectFiles($request->files->all(), '', $files);

        return [
            'method' => $request->getMethod(),
            'query' => $request->query->all(),
            'headers' => $headers,
            'content_type' => $request->headers->get('Content-Type'),
            'body' => base64_encode($request->getContent()),
            'fields' => $request->request->all(),
            'files' => $files,
        ];
    }

    /**
     * @param array<array-key, mixed> $tree
     * @param list<array{field: string, name: string, type: string, size: int, sha256: string}> $files
     */
    private static function collectFiles(array $tree, string $prefix, array &$files): void
    {
        foreach ($tree as $key => $value) {
            $field = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
            if ($value instanceof UploadedFile) {
                $path = $value->getPathname();
                $files[] = [
                    'field' => $field,
                    'name' => $value->getClientOriginalName(),
                    'type' => (string) $value->getClientMimeType(),
                    'size' => (int) filesize($path),
                    'sha256' => (string) hash_file('sha256', $path),
                ];
            } elseif (is_array($value)) {
                self::collectFiles($value, $field, $files);
            }
        }
    }
}
