<?php
/**
 * fatal-handler.php — the error page of last resort.
 *
 * Flight renders views/error/500 for anything it can catch. It cannot catch a true fatal:
 * memory exhausted, max_execution_time, a compile error, a missing class during autoload
 * before the framework is up. Those used to leave the visitor with one of two things:
 *
 *   - display_errors on  → the raw PHP message, server paths included, sent with HTTP 200
 *   - display_errors off → an EMPTY body with HTTP 500, which a browser replaces with its own
 *                          "This page isn't working" screen
 *
 * This file is required FIRST by public/index.php, before the autoloader, and depends on
 * nothing: no Composer, no Flight, no config, no database, no view files. Whatever broke, this
 * still runs. It is deliberately not a class for the same reason — nothing has to load it.
 *
 * It answers only true fatals. Everything Flight can handle keeps going to views/error/500.
 */

/** Freed in the handler so there is room to work after "Allowed memory size exhausted". */
$GLOBALS['__tiknix_fatal_reserve'] = str_repeat('x', 65536);

/**
 * The page itself. Self-contained on purpose: inline CSS, no external asset, no variable that
 * could be undefined. Comfortably over 512 bytes — below that, some browsers substitute their
 * own error screen for the server's.
 */
function tiknix_error_page(int $code, string $heading, string $message): string {
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>' . $code . ' - ' . $h($heading) . '</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
         background: #f5f5f5; color: #333; display: flex; align-items: center; justify-content: center;
         min-height: 100vh; margin: 0; padding: 1rem; box-sizing: border-box; }
  .error-container { text-align: center; max-width: 500px; width: 100%; padding: 2rem; background: #fff;
                     border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
  h1 { color: #dc3545; font-size: 3rem; margin: 0 0 1rem 0; }
  h2 { font-size: 1.5rem; margin: 0 0 1rem 0; }
  p  { color: #666; margin: 1rem 0; line-height: 1.5; }
  a  { color: #007bff; text-decoration: none; }
  a:hover { text-decoration: underline; }
  @media (prefers-color-scheme: dark) {
    body { background: #14161a; color: #e6e6e6; }
    .error-container { background: #1f2329; box-shadow: none; }
    p { color: #a9b0ba; }
  }
</style>
</head>
<body>
  <div class="error-container">
    <h1>' . $code . '</h1>
    <h2>' . $h($heading) . '</h2>
    <p>' . $h($message) . '</p>
    <p><a href="/">Go back to the homepage</a></p>
  </div>
</body>
</html>
';
}

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) return;
    // True fatals only. Warnings and notices are not this function's business, and neither is
    // anything Flight already turned into an exception and rendered.
    if (!($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR))) return;

    unset($GLOBALS['__tiknix_fatal_reserve']);

    // Nothing in here may throw. Flight installs an error handler that turns every warning
    // into an ErrorException, and an exception inside a shutdown function is a second fatal
    // with no page at all — which is precisely what happened the first time this ran: the
    // status call below warned, the warning became an exception, and the visitor got an empty
    // 500. So warnings are swallowed for the rest of this function, which is its last act.
    set_error_handler(static fn(): bool => true);

    // The message goes to the LOG, never to the visitor.
    error_log(sprintf('ERROR PHP fatal (type %d): %s in %s:%d', $err['type'], $err['message'], $err['file'] ?? '?', $err['line'] ?? 0));

    if (PHP_SAPI === 'cli') return;   // a terminal wants the message PHP already printed, not HTML

    $page = tiknix_error_page(500, 'Server Error', 'Something went wrong on our end. The problem has been recorded. Please try again in a moment.');

    if (!headers_sent()) {
        // Throw away whatever half-rendered output was buffered, and any raw error text with it.
        while (ob_get_level() > 0) { @ob_end_clean(); }
        // The status LINE, replacing whatever is there. http_response_code() refuses once
        // anything has called header('HTTP/…'), and Flight does exactly that.
        header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 500 Internal Server Error', true, 500);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        echo $page;
    } else {
        // Part of the page is already with the browser, so neither the status nor what was sent
        // can be taken back. Say so visibly instead of leaving a page that just stops.
        echo '<div role="alert" style="margin:1rem;padding:1rem;border:1px solid #dc3545;border-radius:6px;'
           . 'background:#fff;color:#842029;font-family:sans-serif">Something went wrong while loading this page. '
           . 'The problem has been recorded. <a href="">Reload</a> or <a href="/">go back to the homepage</a>.</div>';
    }
    // Let the visitor go now; anything registered after this (the firehose reporter) may take seconds.
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
});
