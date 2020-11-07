<?php

namespace aquarius;

/*
 * aquarius
 * An application framework for Gemini capsules
 */

class Request
{
    /** @var string */
    private $path;
    /** @var string */
    private $query;
    /** @var string */
    private $input;
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

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getInput(): string
    {
        return $this->input;
    }

    public function getRemoteUser(): string
    {
        return $this->remote_user;
    }
}

class Response
{
    const INPUT = 10;
    const SENSITIVE_INPUT = 11;
    const SUCCESS = 20;
    const REDIRECT_TEMPORARY = 30;
    const REDIRECT_PERMANENT = 31;
    const TEMPORARY_FAILURE = 40;
    const SERVER_UNAVAILABLE = 41;
    const CGI_ERROR = 42;
    const PROXY_ERROR = 43;
    const SLOW_DOWN = 44;
    const PERMANENT_FAILURE = 50;
    const NOT_FOUND = 51;
    const GONE = 52;
    const PROXY_REQUEST_REFUSED = 53;
    const BAD_REQUEST = 59;
    const CLIENT_CERTIFICATE_REQUIRED = 60;
    const CERTIFICATE_NOT_AUTHORISED = 61;
    const CERTIFICATE_NOT_VALID = 62;

    /** @var int */
    private $status;
    /** @var string */
    private $meta;
    /** @var string */
    private $body;

    public function __construct(
        int $status = self::SUCCESS,
        string $meta = 'text/gemini',
        string $body = ''
    ) {
        $this->status = $status;
        $this->meta = $meta;
        $this->body = $body;
    }

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
    private $regex;
    /** @var array<mixed> */
    private $parameters = [];
    /** @var array<callable> */
    private $stack = [];

    public function __construct(string $regex, callable $callable)
    {
        $regex = '/'.trim($regex, '/');
        $this->regex = '/^'.str_replace('/', '\/', $regex).'$/';
        $this->stack[] = $callable;
    }

    public function __invoke(Request $request): ?Response
    {
        if (1 !== preg_match($this->regex, $request->getPath(), $matches)) {
            return null;
        }
        $this->parameters = array_slice($matches, 1);

        return call_user_func_array(
            [$this, 'next'],
            [$this, $request, new Response()]
        );
    }

    public function butFirst(callable $callable): self
    {
        $this->stack[] = $callable;

        return $this;
    }

    public function next(
        self $handler,
        Request $request,
        Response $response
    ): Response {
        $callable = array_pop($this->stack);
        if (null === $callable) {
            return $response;
        }

        return $callable($handler, $request, $response);
    }

    /** @return array<mixed> */
    public function getRouteParameters(): array
    {
        return $this->parameters;
    }
}

class App
{
    /** @var array<Handler> */
    private $handlers = [];

    public function addHandler(string $regex, callable $callable): Handler
    {
        $handler = new Handler($regex, $callable);
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
            $response = new Response(40, $e->getMessage());
        }
        if (null === $response) {
            $response = new Response(51, 'Not found');
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
