<?php

namespace Relay;

/**
 * درخواستی که از کلاینت به رله رسیده است.
 *
 * نکته مهم: مسیر از REQUEST_URI خوانده می‌شود نه PATH_INFO، چون
 * PATH_INFO توسط وب‌سرور نرمال‌سازی می‌شود و «//» داخل
 * https://host/path را خراب می‌کند.
 */
class IncomingRequest
{
    /** @var string */
    private $method;

    /** @var string مسیر خام (percent-encode شده) بعد از محل نصب اسکریپت */
    private $path;

    /** @var string */
    private $queryString;

    /** @var array<string,string> هدرها با کلید lowercase */
    private $headers;

    /** @var string */
    private $clientIp;

    /** @var string */
    private $id;

    /** @var array<string,string>|null */
    private $parsedQuery = null;

    /** @var string پیشوندی که محل نصب اسکریپت است، مثل '' یا '/relay' */
    private $mount = '';

    private function __construct()
    {
    }

    public static function capture(): self
    {
        $request = new self();

        $request->method      = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $request->headers     = self::collectHeaders();
        $request->clientIp    = Util::clientIp();
        $request->id          = Util::randomId();
        $request->queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');

        $resolved       = self::resolvePath();
        $request->path  = $resolved[0];
        $request->mount = $resolved[1];

        return $request;
    }

    /**
     * مسیر مربوط به رله را از REQUEST_URI بیرون می‌کشد و
     * پیشوند محل نصب (مثلاً /relay یا /relay/index.php) را حذف می‌کند.
     *
     * @return array{0:string,1:string} [مسیر رله, پیشوند حذف‌شده]
     */
    private static function resolvePath(): array
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        // جدا کردن query string
        $questionMark = strpos($uri, '?');

        if ($questionMark !== false) {
            $uri = substr($uri, 0, $questionMark);
        }

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $mount  = '';

        // حالت بدون mod_rewrite:  /relay/index.php/r/KEY/https://...
        if ($script !== '' && Util::startsWith($uri, $script)) {
            $mount = $script;
            $uri   = substr($uri, strlen($script));
        } else {
            $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

            if ($dir !== '' && $dir !== '.' && Util::startsWith($uri, $dir . '/')) {
                $mount = $dir;
                $uri   = substr($uri, strlen($dir));
            } elseif ($dir !== '' && $dir !== '.' && $uri === $dir) {
                $mount = $dir;
                $uri   = '';
            }
        }

        if ($uri === '' || $uri[0] !== '/') {
            $uri = '/' . $uri;
        }

        return [$uri, $mount];
    }

    /**
     * پیشوند محل نصب اسکریپت (بدون اسلش انتهایی).
     */
    public function mount(): string
    {
        return $this->mount;
    }

    /**
     * @return array<string,string>
     */
    private static function collectHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            $raw = getallheaders();

            if (is_array($raw)) {
                foreach ($raw as $name => $value) {
                    $headers[strtolower((string) $name)] = (string) $value;
                }
            }
        }

        // تکمیل/جایگزینی از $_SERVER (روی برخی هاست‌ها getallheaders نیست)
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (strpos($key, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));

                if (!isset($headers[$name])) {
                    $headers[$name] = (string) $value;
                }
            }
        }

        // این دو در $_SERVER بدون پیشوند HTTP_ هستند
        if (!empty($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] !== '') {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function queryString(): string
    {
        return $this->queryString;
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function contentType(): string
    {
        return $this->header('content-type');
    }

    public function contentLength(): ?int
    {
        $value = $this->header('content-length');

        return $value === '' ? null : (int) $value;
    }

    /**
     * پارامتر کوئری (از QUERY_STRING خام پارس می‌شود تا مستقل از $_GET باشد).
     *
     * @return array<string,string>
     */
    public function queryParams(): array
    {
        if ($this->parsedQuery === null) {
            $parsed = [];
            parse_str($this->queryString, $parsed);

            $this->parsedQuery = array_map(
                static function ($value) {
                    return is_array($value) ? '' : (string) $value;
                },
                $parsed
            );
        }

        return $this->parsedQuery;
    }

    public function query(string $name, string $default = ''): string
    {
        $params = $this->queryParams();

        return isset($params[$name]) ? $params[$name] : $default;
    }

    /**
     * آیا این درخواست بدنه دارد؟
     *
     * طبق HTTP، بدنه وقتی وجود دارد که Content-Length آمده باشد یا
     * Transfer-Encoding: chunked باشد — مستقل از متد. اگر فقط به متد
     * نگاه کنیم، یک POST بدون بدنه به‌اشتباه به آپلود chunked تبدیل می‌شود.
     */
    public function hasBody(): bool
    {
        if ($this->isChunked()) {
            return true;
        }

        return ($this->contentLength() ?? 0) > 0;
    }

    public function isChunked(): bool
    {
        return stripos($this->header('transfer-encoding'), 'chunked') !== false;
    }

    /**
     * مسیر را به بخش‌های خام (بدون urldecode) تقسیم می‌کند.
     *
     * @return array<int,string>
     */
    public function pathSegments(): array
    {
        $trimmed = trim($this->path, '/');

        if ($trimmed === '') {
            return [];
        }

        return explode('/', $trimmed);
    }
}
