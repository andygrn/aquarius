
# aquarius

A PHP application framework for Gemini capsules, including:

- Simple header/body response model, with 'middleware' capability.
- Regex routing, with optional named or unnamed parameters.
- Automatic session linking to client certificates.
- Everything in a single file.

It should be reasonably familiar to anyone who's used a PHP web framework
before.

See it in action at **gemini://andygrn.co.uk/apps/aquarius**


## Installation

Via Composer: `composer require andygrn/aquarius`

Or download the file and `require 'aquarius.php';`.

Requires PHP 7.3 or above.


## Apps and Handlers

A single aquarius **App** has one or more **Handlers**.

A handler is a path regex and a stack of functions. The app will run the stack
of the first handler whose regex matches the request path. A handler's function
stack must return a **Response**, which will be sent to the client.

Functions are added to a handler's stack with the `butFirst` method. The last
function added to the stack runs first. Stack functions *should* pass to the
next function down the stack with `return $this->next($request, $response);`,
but they may choose to `return $response;` directly to bypass the rest of the
stack.

Handler functions can be any valid [callable](https://www.php.net/manual/en/language.types.callable.php).


## CGI variables

To do anything useful, aquarius requires at least `PATH_INFO` and `QUERY_STRING`
to be defined by the CGI host. `REMOTE_USER` too, if you plan to use
`Request::getRemoteUser()`.

To enable the client certificate session behaviour, aquarius also requires
`TLS_CLIENT_HASH`. I'm not sure how standard it is, but
[Jetforce](https://github.com/michael-lazar/jetforce),
[Molly Brown](https://tildegit.org/solderpunk/molly-brown/), and
[GLV-1.12556](https://github.com/spc476/GLV-1.12556) appear to support it.


## API

### Request

`Request::getPath(): string`
Get the current PATH_INFO, normalised with leading slash and without trailing
slash.

`Request::getQuery(): string`
Get the current QUERY_STRING, URL-decoded (using `rawurldecode()`).

`Request::getRemoteUser(): string`
Get the current REMOTE_USER (probably a client certificate Common Name).

### Response

`Response::setHeader(int $status, string $meta): void`
Set the header line of the response. Default response `($status, $meta)` is
`(Response::STATUS_SUCCESS, 'text/gemini')`, so you may not need to call this.

`Response::getStatus(): int`
Get the response status code.

`Response::getMeta(): string`
Get the response meta string.

`Response::setBody(string $body): void`
Set the entire response body.

`Response::appendBody(string $body): void`
Append to the response body.

`Response::getBody(): string`
Get the response body.

### Handler

`Handler::next(Request $request, Response $response): Response`
Call the next function in this handler's stack.

`Handler::butFirst(callable $callable): self`
Add a function to this handler's stack. The last one added will be the first
called. Returns itself so calls can be chained.

`Handler::getPathParameters(): array<mixed>`
Get the path parameters captured from this handler's regex pattern.

### App

`App::addHandler(string $path_regex, callable $callable): Handler`
Create a new Handler to run on `$path_regex`, with `$callable` as the first
function in its stack.

`App::run(): void`
Resolve the current path to a handler and run it (or serve 51 response if no
handler matches).


## Example apps

```php

// A simple greeting and visit counter.

$app = new aquarius\App();

$app->addHandler('/', function ($request, $response) {
    $remote_user = $request->getRemoteUser();
    if ('' === $remote_user) {
        $remote_user = 'stranger';
    }
    $response->appendBody("Hello, $remote_user.\n\n");

    if (PHP_SESSION_ACTIVE === session_status()) {
        if (!isset($_SESSION['visit_count'])) {
            $_SESSION['visit_count'] = 0;
        }
        ++$_SESSION['visit_count'];
        $response->appendBody("You've been here {$_SESSION['visit_count']} times.");
    }

    return $response;
});

$app->run();
```

```php

// Collecting input.

$app = new aquarius\App();

$app->addHandler('/add-to-list', function ($request, $response) {
    $query = $request->getQuery();
    if (0 === strlen($query)) {
        $response->setHeader($response::STATUS_INPUT, 'Enter item name');
        return $this->next($request, $response);
    }

    if (!isset($_SESSION['list'])) {
        $_SESSION['list'] = [];
    }
    $_SESSION['list'][] = $query;

    $response->setHeader($response::STATUS_REDIRECT_TEMPORARY, '/list');
    return $this->next($request, $response);
});

$app->run();
```

```php

// Path parameters with regex capturing groups.

$app = new aquarius\App();

function show_path_parameters($request, $response)
{
    $parameters = $this->getPathParameters();
    $response->appendBody(var_export($parameters, true));
    return $this->next($request, $response);
}

$app->addHandler('/page/\d+', 'show_path_parameters');
// Match:      /page/1    /page/123
// Parameters: []         []

$app->addHandler('/page/([^/]+)/([^/]+)', 'show_path_parameters');
// Match:      /page/hello/world    /page/1/2:
// Parameters: ['hello','world']    ['1','2']

$app->addHandler('/page/(\d+)(?:/(\d+)(?:/(\d+))?)?', 'show_path_parameters');
// Match:      /page/1    /page/1/2    /page/1/2/3
// Parameters: ['1']      ['1','2']    ['1','2','3']

$app->addHandler('/page/(?<foo>\d+)', 'show_path_parameters');
// Match:      /page/1           /page/2
// Parameters: ['foo' => '1']    ['foo' => '2']

$app->run();
```

```php

// Forcing a session (i.e. a client certificate) with middleware.

$app = new aquarius\App();

function require_session($request, $response)
{
    if (PHP_SESSION_ACTIVE !== session_status()) {
        $response->setHeader(
            $response::STATUS_CLIENT_CERTIFICATE_REQUIRED,
            'Certificate required'
        );
        return $response;
    }
    return $this->next($request, $response);
}

$app->addHandler('/private-lounge', function ($request, $response) {
    $response->setBody('Members only 😎');
    return $this->next($request, $response);
})
->butFirst('require_session');

$app->run();
```
