<?php

namespace aquarius;

/*
 * aquarius
 * An application framework for Gemini capsules.
 *
 * Andy Green hello@andygrn.co.uk
 */

class Request
{
    /** @var string */
    private $path;
    /** @var string */
    private $query;
    /** @var string */
    private $remote_user;

    public function __construct()
    {
        $this->path = '/'.trim($_SERVER['PATH_INFO'] ?? '', '/');
        $this->query = rawurldecode($_SERVER['QUERY_STRING'] ?? '');
        $this->remote_user = $_SERVER['REMOTE_USER'] ?? '';

        $cert_fingerprint = $_SERVER['TLS_CLIENT_HASH'] ?? '';
        if ('' !== $cert_fingerprint) {
            // Generate a valid session ID from certificate hash. We never need
            // to decode the base64, so it's fine to strip any =s off the end.
            $session_id = rtrim(base64_encode($cert_fingerprint), '=');
            session_id($session_id);
            session_start([
                'use_cookies' => 0,
            ]);
        }
    }

    /**
     * Get the current PATH_INFO, normalised with leading slash and without
     * trailing slash.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get the current QUERY_STRING, URL-decoded (using rawurldecode()).
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Get the current REMOTE_USER (probably a client certificate Common Name).
     */
    public function getRemoteUser(): string
    {
        return $this->remote_user;
    }
}

class Response
{
    const STATUS_INPUT = 10;
    const STATUS_SENSITIVE_INPUT = 11;
    const STATUS_SUCCESS = 20;
    const STATUS_REDIRECT_TEMPORARY = 30;
    const STATUS_REDIRECT_PERMANENT = 31;
    const STATUS_TEMPORARY_FAILURE = 40;
    const STATUS_SERVER_UNAVAILABLE = 41;
    const STATUS_CGI_ERROR = 42;
    const STATUS_PROXY_ERROR = 43;
    const STATUS_SLOW_DOWN = 44;
    const STATUS_PERMANENT_FAILURE = 50;
    const STATUS_NOT_FOUND = 51;
    const STATUS_GONE = 52;
    const STATUS_PROXY_REQUEST_REFUSED = 53;
    const STATUS_BAD_REQUEST = 59;
    const STATUS_CLIENT_CERTIFICATE_REQUIRED = 60;
    const STATUS_CERTIFICATE_NOT_AUTHORISED = 61;
    const STATUS_CERTIFICATE_NOT_VALID = 62;

    /** @var int */
    private $status;
    /** @var string */
    private $meta;
    /** @var string */
    private $body;

    public function __construct(
        int $status = self::STATUS_SUCCESS,
        string $meta = 'text/gemini',
        string $body = ''
    ) {
        $this->status = $status;
        $this->meta = $meta;
        $this->body = $body;
    }

    /**
     * Default ($status, $meta) is (Response::STATUS_SUCCESS, 'text/gemini').
     */
    public function setHeader(int $status, string $meta): void
    {
        $this->status = $status;
        $this->meta = $meta;
    }

    public function getHeader(): string
    {
        return "{$this->status} {$this->meta}\r\n";
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function appendBody(string $body): void
    {
        $this->body .= $body;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}

class Handler
{
    /** @var string */
    private $path_regex;
    /** @var array<mixed> */
    private $parameters = [];
    /** @var array<callable> */
    private $stack = [];

    public function __construct(string $path_regex, callable $callable)
    {
        $path_regex = '/'.trim($path_regex, '/');
        $this->path_regex = '/^'.str_replace('/', '\/', $path_regex).'$/';
        $this->stack[] = $callable;
    }

    public function __invoke(Request $request): ?Response
    {
        if (1 !== preg_match($this->path_regex, $request->getPath(), $matches)) {
            return null;
        }
        $this->parameters = array_slice($matches, 1);

        return call_user_func_array(
            [$this, 'next'],
            [$request, new Response()]
        );
    }

    /**
     * Call the next function in this handler's stack.
     */
    public function next(Request $request, Response $response): Response
    {
        $callable = array_pop($this->stack);
        if (null === $callable) {
            return $response;
        }
        $callable = \Closure::fromCallable($callable)->bindTo($this);

        return $callable($request, $response);
    }

    /**
     * Add a function to this handler's stack. The last one added will be the
     * first called.
     */
    public function butFirst(callable $callable): self
    {
        $this->stack[] = $callable;

        return $this;
    }

    /**
     * Get the path parameters captured from this handler's regex pattern.
     *
     * @return array<mixed>
     */
    public function getPathParameters(): array
    {
        return $this->parameters;
    }
}

class App
{
    /** @var array<Handler> */
    private $handlers = [];

    public function addHandler(string $path_regex, callable $callable): Handler
    {
        $handler = new Handler($path_regex, $callable);
        $this->handlers[] = $handler;

        return $handler;
    }

    public function run(): void
    {
        $request = new Request();

        // Intercept any output from handler callables (e.g. from 'echo'), else
        // we'd mess up the response headers.
        ob_start();

        $response = null;

        try {
            foreach ($this->handlers as $handler) {
                $response = $handler($request);
                if (null !== $response) {
                    break;
                }
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
            $response = new Response(40, 'Server error');
        }
        if (null === $response) {
            $response = new Response(51, '-');
        }

        $handler_output = ob_get_clean();
        if (is_string($handler_output)) {
            $response->appendBody($handler_output);
        }

        ob_start();
        echo $response->getHeader();
        echo $response->getBody();
        ob_end_flush();
    }
}
