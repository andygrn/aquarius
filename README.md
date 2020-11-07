
# aquarius

A PHP application framework for Gemini capsules, including:

- Simple header/body response model, with 'middleware' capability.
- Regex routing, with optional named or unnamed parameters.
- Automatic session linking to client certificates.
- Everything in a single file.

It should be reasonably familiar to anyone who's used a PHP web framework
before.


## Apps and Handlers

A single aquarius **App** has one or more **Handlers**.

A handler is a route and a stack of functions. The app will run the first
handler whose route matches the request path. A handler's function stack must
return a **Response**, which will be sent to the client.

Functions are added to a handler's stack with the `butFirst` method. The last
function added to the stack runs first. Stack functions *should* call the next
function in the stack with the handler's `next` method, but they may choose to
return the response directly and bypass the rest of the stack.

Handler functions can be any valid [callable](https://www.php.net/manual/en/language.types.callable.php).


## CGI variables

To do anything useful, aquarius requires at least `PATH_INFO` and `QUERY_STRING`
to be defined by the CGI host. `REMOTE_USER` too, if you plan to use
`Request::getRemoteUser()`.

To enable the client certificate session behaviour, aquarius also requires
`TLS_CLIENT_HASH`. I'm not sure how standard it is, but
[Jetforce](https://github.com/michael-lazar/jetforce),
[Molly Brown](https://github.com/LukeEmmet/molly-brown), and
[GLV-1.12556](https://github.com/spc476/GLV-1.12556) appear to support it.


## Example apps

```php

// A simple greeting and visit counter.

$app = new aquarius\App();

$app->addHandler('/', function ($handler, $request, $response) {
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

$app->addHandler('/add-to-list', function ($handler, $request, $response) {
    $query = $request->getQuery();
    if (0 === strlen($query)) {
        $response->setHeader($response::INPUT, 'Enter item name');
        return $handler->next($handler, $request, $response);
    }

    if (!isset($_SESSION['list'])) {
        $_SESSION['list'] = [];
    }
    $_SESSION['list'][] = $query;

    $response->setHeader($response::REDIRECT_TEMPORARY, '/list');
    return $handler->next($handler, $request, $response);
});

$app->run();
```

```php

// Route parameters with regex capturing groups.

$app = new aquarius\App();

function show_route_parameters($handler, $request, $response)
{
    $parameters = $handler->getRouteParameters();
    $response->appendBody(var_export($parameters, true));
    return $handler->next($handler, $request, $response);
}

$app->addHandler('/page/\d+', 'show_route_parameters');
// Match:      /page/1    /page/123
// Parameters: []         []

$app->addHandler('/page/([^/]+)/([^/]+)', 'show_route_parameters');
// Match:      /page/hello/world    /page/1/2:
// Parameters: ['hello','world']    ['1','2']

$app->addHandler('/page/(\d+)(?:/(\d+)(?:/(\d+))?)?', 'show_route_parameters');
// Match:      /page/1    /page/1/2    /page/1/2/3
// Parameters: ['1']      ['1','2']    ['1','2','3']

$app->addHandler('/page/(?<foo>\d+)', 'show_route_parameters');
// Match:      /page/1           /page/2
// Parameters: ['foo' => '1']    ['foo' => '2']

$app->run();
```

```php

// Forcing a session (i.e. a client certificate) with middleware.

$app = new aquarius\App();

function require_session($handler, $request, $response)
{
    if (PHP_SESSION_ACTIVE !== session_status()) {
        $response->setHeader(
            $response::CLIENT_CERTIFICATE_REQUIRED,
            'Certificate required'
        );
        return $response;
    }
    return $handler->next($handler, $request, $response);
}

$app->addHandler('/private-lounge', function ($handler, $request, $response) {
    $response->setBody('Members only 😎');
    return $handler->next($handler, $request, $response);
})
->butFirst('require_session');

$app->run();
```
