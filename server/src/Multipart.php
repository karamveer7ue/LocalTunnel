<?php

declare(strict_types=1);

namespace Tunnel;

/**
 * Rebuilds a multipart/form-data body from $_POST and $_FILES.
 *
 * Only needed when the host keeps enable_post_data_reading on: PHP then consumes multipart
 * bodies itself and php://input comes back empty. The rebuilt form carries the same fields
 * and files, but PHP has already renamed some fields (dots and spaces become underscores),
 * kept only the last of repeated names, and dropped files above upload_max_filesize.
 */
final class Multipart
{
    /** @return array{0: int, 1: string} size of the body written to $path, and its Content-Type */
    public static function rebuild(string $path): array
    {
        $boundary = '----TunnelFormBoundary' . bin2hex(random_bytes(12));
        $out = Store::open($path, 'wb');
        foreach (self::fields($_POST, null) as [$name, $value]) {
            fwrite($out, "--$boundary\r\nContent-Disposition: form-data; name=\"" . self::quote($name) . "\"\r\n\r\n$value\r\n");
        }
        foreach ($_FILES as $field => $info) {
            foreach (self::files((string)$field, $info) as [$name, $file]) {
                if ($file['error'] !== UPLOAD_ERR_OK && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                fwrite($out, "--$boundary\r\nContent-Disposition: form-data; name=\"" . self::quote($name) . '"; filename="' . self::quote((string)$file['name']) . "\"\r\n"
                    . 'Content-Type: ' . ($file['type'] !== '' ? $file['type'] : 'application/octet-stream') . "\r\n\r\n");
                if ($file['error'] === UPLOAD_ERR_OK) {
                    $in = Store::open($file['tmp_name'], 'rb');
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                }
                fwrite($out, "\r\n");
            }
        }
        fwrite($out, "--$boundary--\r\n");
        fclose($out);
        return [(int)filesize($path), 'multipart/form-data; boundary=' . $boundary];
    }

    /**
     * @param array<mixed> $values
     * @return iterable<array{0: string, 1: string}>
     */
    private static function fields(array $values, ?string $prefix): iterable
    {
        foreach ($values as $key => $value) {
            $name = $prefix === null ? (string)$key : $prefix . '[' . $key . ']';
            if (is_array($value)) {
                yield from self::fields($value, $name);
            } else {
                yield [$name, (string)$value];
            }
        }
    }

    /**
     * $_FILES stores nested uploads "inside out" (name[a][b], type[a][b], ...); this walks
     * them back into one entry per file.
     *
     * @param array<string, mixed> $info
     * @return iterable<array{0: string, 1: array{name: string, type: string, tmp_name: string, error: int}}>
     */
    private static function files(string $name, array $info): iterable
    {
        if (!is_array($info['name'])) {
            yield [$name, [
                'name' => (string)$info['name'],
                'type' => (string)$info['type'],
                'tmp_name' => (string)$info['tmp_name'],
                'error' => (int)$info['error'],
            ]];
            return;
        }
        foreach (array_keys($info['name']) as $key) {
            $child = [];
            foreach (['name', 'type', 'tmp_name', 'error'] as $part) {
                $child[$part] = $info[$part][$key];
            }
            yield from self::files($name . '[' . $key . ']', $child);
        }
    }

    /** Escapes a field or file name the way browsers do (HTML Standard, multipart/form-data encoding). */
    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }
}
