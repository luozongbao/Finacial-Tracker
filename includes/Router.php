<?php
/**
 * Router.php — method + path to handler.
 *
 * The contract (docs/issues/issue-012.md, §10):
 *
 *   1. EXACT path matching. No wildcards, no regex routes, no :id
 *      placeholders. A literal map of about twenty routes is easier to read
 *      than a router, and easier to audit.
 *   2. The method is part of the match. GET /transactions and
 *      POST /transactions are different handlers.
 *   3. HEAD is dispatched as GET.
 *   4. An unknown path is a 404 and a known path with the wrong method is a
 *      405 with an Allow: header. Both are thrown as an HttpException, which
 *      the front controller (issue-014) catches and turns into the matching
 *      error view. The router does not render the error page itself — that
 *      belongs to the shell (issue-013).
 *   5. The handler is required, not evaluated, and the path comes from the
 *      route table — never from the request. A handler path built from user
 *      input is a remote code execution hole.
 *
 * The route table lives in one place: defaultRoutes(). The front controller
 * builds its router from that map, so the URLs the product answers to are
 * listed exactly once in the codebase.
 *
 * RECONCILIATION with issue-012 §10: the table there lists
 * `GET /transactions/{id}/edit`. A router with exact matching cannot match
 * that, and the note in the same section says the path segment is literal
 * and the id is a query parameter. Issue-018 §3 settles it: the route is
 * `GET /transactions/edit?id=<id>`. That is what is registered here.
 */

declare(strict_types=1);

final class Router
{
    /**
     * The route table: path => method => handler.
     *
     * @var array<string, array<string, string>>
     */
    private array $routes = [];

    /**
     * Register a GET route.
     */
    public function get(string $path, string $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /**
     * Register a POST route.
     */
    public function post(string $path, string $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /**
     * Register a route for any method.
     */
    public function add(string $method, string $path, string $handler): void
    {
        $this->routes[self::normalizePath($path)][strtoupper($method)] = $handler;
    }

    /**
     * The v0.2 route table.
     *
     * Every URL the product answers to, in one list. The handler is a path
     * relative to the application root, or `redirect:<target>` for the one
     * route that is a redirect rather than a view.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function defaultRoutes(): array
    {
        return [
            // Guest — sign in, register, forgot password, reset password.
            ['GET',  '/login',                 'views/login.php'],
            ['POST', '/login',                 'views/login.php'],
            ['GET',  '/register',              'views/register.php'],
            ['POST', '/register',              'views/register.php'],
            ['GET',  '/forgot-password',       'views/forgot_password.php'],
            ['POST', '/forgot-password',       'views/forgot_password.php'],
            ['GET',  '/reset-password',        'views/reset_password.php'],
            ['POST', '/reset-password',        'views/reset_password.php'],
            ['GET',  '/verify-email',          'views/verify_email.php'],

            // One canonical home URL.
            ['GET',  '/',                      'redirect:/dashboard'],

            // The application.
            ['GET',  '/dashboard',             'views/dashboard.php'],
            ['GET',  '/transactions',          'views/transactions.php'],
            ['GET',  '/transactions/new',      'views/transaction_form.php'],
            ['GET',  '/transactions/edit',     'views/transaction_form.php'],
            ['POST', '/transactions/save',     'views/transaction_save.php'],
            ['POST', '/transactions/delete',   'views/transaction_delete.php'],
            ['GET',  '/reports',               'views/reports.php'],
            ['GET',  '/categories',            'views/categories.php'],
            ['POST', '/categories/save',       'views/category_save.php'],
            ['POST', '/categories/delete',     'views/category_delete.php'],
            ['GET',  '/settings',              'views/settings.php'],
            ['POST', '/settings/profile',      'views/settings.php'],
            ['POST', '/settings/password',     'views/settings.php'],
            ['POST', '/logout',                'views/logout.php'],
        ];
    }

    /**
     * Build a router from the default route table.
     */
    public static function withDefaultRoutes(): self
    {
        $router = new self();

        foreach (self::defaultRoutes() as [$method, $path, $handler]) {
            $router->add($method, $path, $handler);
        }

        return $router;
    }

    /**
     * Normalise a path: one leading slash, no trailing slash, no doubled
     * slashes.
     *
     * /transactions and /transactions/ are the same page, and a doubled
     * slash must not become a second route.
     */
    public static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $path = (string) preg_replace('#/{2,}#', '/', $path);

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * Match a method and a path against the table.
     *
     * The answer is data, not a side effect, so that the decision can be
     * asserted without a web server.
     *
     * @return array{status: int, handler: string|null, allow: string[], headers: array<string, string>}
     */
    public function resolve(string $method, string $path): array
    {
        $method = strtoupper($method);

        // A HEAD request is a GET without the body.
        if ($method === 'HEAD') {
            $method = 'GET';
        }

        $path = self::normalizePath($path);

        if (!isset($this->routes[$path])) {
            return [
                'status'  => 404,
                'handler' => null,
                'allow'   => [],
                'headers' => [],
            ];
        }

        $allowed = array_keys($this->routes[$path]);
        sort($allowed);

        if (!isset($this->routes[$path][$method])) {
            return [
                'status'  => 405,
                'handler' => null,
                'allow'   => $allowed,
                'headers' => ['Allow' => implode(', ', $allowed)],
            ];
        }

        return [
            'status'  => 200,
            'handler' => $this->routes[$path][$method],
            'allow'   => $allowed,
            'headers' => [],
        ];
    }

    /**
     * The registered paths, sorted. Handy in a test and in the debugger.
     *
     * @return string[]
     */
    public function paths(): array
    {
        $paths = array_keys($this->routes);
        sort($paths);

        return $paths;
    }

    /**
     * Match the request and run the handler.
     *
     * @param string|null $method Defaults to the request's method.
     * @param string|null $path   Defaults to the request's path.
     *
     * @throws HttpException 404 for an unknown path, 405 for the wrong
     *                        method — the front controller renders the page.
     * @throws RuntimeException When the handler file is missing.
     */
    public function dispatch(?string $method = null, ?string $path = null): void
    {
        $method = $method ?? (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $uri = $path ?? (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $parsed = parse_url($uri, PHP_URL_PATH);
        $requestPath = is_string($parsed) && $parsed !== '' ? $parsed : '/';

        $resolution = $this->resolve($method, $requestPath);

        if ($resolution['status'] === 404) {
            http_response_code(404);
            throw new HttpException(404, 'That page does not exist.');
        }

        if ($resolution['status'] === 405) {
            http_response_code(405);

            // The Allow: header goes out with the response. It is sent here
            // as well as carried by the exception, so the status is correct
            // even when the boundary only renders the view.
            foreach ($resolution['headers'] as $name => $value) {
                header($name . ': ' . $value);
            }

            throw new HttpException(
                405,
                'That page does not accept this request.',
                $resolution['headers']
            );
        }

        $handler = (string) $resolution['handler'];

        // A redirect route: the handler is a target, not a file. The root
        // path is the one route of the product that is not a view.
        if (str_starts_with($handler, 'redirect:')) {
            $target = substr($handler, strlen('redirect:'));
            header('Location: ' . $target, true, 302);

            return;
        }

        // The handler path comes from the route table, never from the
        // request — it is a file name, and a file name from the request is
        // remote code execution.
        $file = dirname(__DIR__) . '/' . ltrim($handler, '/');

        if (!is_file($file)) {
            throw new RuntimeException('Route handler not found: ' . $handler);
        }

        require $file;
    }
}
