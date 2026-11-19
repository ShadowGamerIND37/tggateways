<?php
declare(strict_types=1);
/**
 * Router for the browser preview (PHP built-in server).
 *
 * The application is written for Apache at the document root. The built-in
 * server needs three things mapped: directory indexes, the /assets/* static
 * tree, and resolvable URLs for the pages.
 *
 * Run from the repository root:
 *   php -S 0.0.0.0:8090 -t . tests/preview-router.php
 */
$root = dirname(__DIR__);
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Static assets are served straight from disk.
if (str_starts_with($uri, '/assets/')) {
    $file = realpath($root.$uri);
    if ($file !== false && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file)) {
        $types = ['css' => 'text/css', 'js' => 'application/javascript', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'woff2' => 'font/woff2'];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: '.($types[$ext] ?? 'application/octet-stream'));
        readfile($file);
        return true;
    }
    http_response_code(404);
    return true;
}

$self = realpath(__FILE__);
$candidates = $uri === '/'
    ? ['/index.php']
    : [$uri, rtrim($uri, '/').'.php'];

foreach ($candidates as $candidate) {
    $script = realpath($root.$candidate);
    if ($script === false || $script === $self) continue;
    if (!str_starts_with($script, $root.DIRECTORY_SEPARATOR) || !is_file($script)) continue;
    if (!str_ends_with($script, '.php')) continue;
    $_SERVER['SCRIPT_NAME'] = '/'.basename($script);
    require $script;
    return true;
}

http_response_code(404);
echo '<!doctype html><meta charset="utf-8"><title>Not found</title>'
   . '<body style="font:15px system-ui;padding:48px"><h1>404</h1>'
   . '<p>No page at <code>'.htmlspecialchars($uri, ENT_QUOTES).'</code>.</p>'
   . '<p><a href="/dashboard.php">Dashboard</a> · <a href="/">Home</a></p>';
return true;
