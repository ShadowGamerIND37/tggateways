<?php
declare(strict_types=1);
/**
 * Download one ticket attachment.
 *
 * Ownership is re-checked on every request: a guessed id must not leak another
 * merchant's file. Files are streamed from outside the document root, always
 * as an attachment, with nosniff so a stored SVG/HTML can never execute.
 */
require_once __DIR__.'/includes/attachments.php';
require_once __DIR__.'/includes/auth.php';
$merchant = require_login();
$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { http_response_code(400); exit('Bad request'); }

$q = db()->prepare('SELECT stored_name,original_name,mime,bytes FROM ticket_attachments WHERE id=? AND merchant_id=?');
$q->execute([$id, (int)$merchant['id']]);
$row = $q->fetch();
if (!$row) { http_response_code(404); exit('Attachment not found'); }

$path = tg_attachment_path((string)$row['stored_name']);
if ($path === null) { http_response_code(410); exit('Attachment is no longer stored'); }

// Keep the filename readable but safe for a header: strip quotes and control
// characters and fall back to the stored name when nothing useful remains.
$name = preg_replace('/[^\w.\- ]+/u', '_', (string)$row['original_name']) ?: (string)$row['stored_name'];
tg_header('Content-Type: '.($row['mime'] ?: 'application/octet-stream'));
tg_header('Content-Disposition: attachment; filename="'.$name.'"; filename*=UTF-8\'\''.rawurlencode($name));
tg_header('Content-Length: '.(string)filesize($path));
tg_header('X-Content-Type-Options: nosniff');
tg_header('Cache-Control: private, no-store');

// readfile() bypasses an output buffer, which breaks any harness that captures
// the response; a chunked echo streams the same bytes and also keeps memory
// flat for a large PDF.
$fh = fopen($path, 'rb');
if ($fh === false) { http_response_code(500); exit('Attachment could not be opened'); }
while (!feof($fh)) {
    $chunk = fread($fh, 65536);
    if ($chunk === false) break;
    echo $chunk;
    flush();
}
fclose($fh);
