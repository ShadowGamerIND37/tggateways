<?php
declare(strict_types=1);
/**
 * Plain-text API reference at /docs.txt.
 *
 * Generated from includes/apidoc.php so it cannot drift from the HTML docs.
 * The Docker image needs the .htaccess rewrite rule to serve .txt through PHP.
 */
require_once __DIR__.'/includes/apidoc.php';
tg_header('Content-Type: text/plain; charset=utf-8');
tg_header('Cache-Control: public, max-age=300');
echo tg_doc_text();
