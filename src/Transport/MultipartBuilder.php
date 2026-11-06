<?php

namespace Relay\Transport;

use Relay\Util;

/**
 * ساخت بدنه multipart/form-data به‌صورت دستی.
 * فقط برای ترنسپورت fallback لازم است (cURL خودش این کار را می‌کند).
 */
class MultipartBuilder
{
    /**
     * @param array<string,mixed> $fields مقادیر رشته‌ای یا \CURLFile
     * @return array{0:string,1:string} [بدنه, مقدار هدر Content-Type]
     */
    public static function build(array $fields): array
    {
        $boundary = '----RelayBoundary' . Util::randomId(12);
        $body     = '';

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n";

            if ($value instanceof \CURLFile) {
                $filename = $value->getPostFilename() !== '' ? $value->getPostFilename() : 'file';
                $mime     = $value->getMimeType() !== '' ? $value->getMimeType() : 'application/octet-stream';
                $contents = @file_get_contents($value->getFilename());

                $body .= 'Content-Disposition: form-data; name="' . self::escape((string) $name)
                    . '"; filename="' . self::escape($filename) . "\"\r\n";
                $body .= 'Content-Type: ' . $mime . "\r\n\r\n";
                $body .= ($contents === false ? '' : $contents) . "\r\n";

                continue;
            }

            $body .= 'Content-Disposition: form-data; name="' . self::escape((string) $name) . "\"\r\n\r\n";
            $body .= (string) $value . "\r\n";
        }

        $body .= '--' . $boundary . "--\r\n";

        return [$body, 'multipart/form-data; boundary=' . $boundary];
    }

    private static function escape(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '', ''], $value);
    }
}
