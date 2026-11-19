<?php
declare(strict_types=1);
/** LLM context document at /llms.txt, generated from includes/apidoc.php. */
require_once __DIR__.'/includes/apidoc.php';
tg_header('Content-Type: text/plain; charset=utf-8');
tg_header('Cache-Control: public, max-age=300');
echo tg_doc_llms();
