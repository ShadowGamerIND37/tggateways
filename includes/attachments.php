<?php
declare(strict_types=1);
/**
 * Ticket attachments.
 *
 * Files live outside the web root, under storage/attachments, and are only
 * ever served through attachment.php after the merchant is re-checked as the
 * owner. Uploads are validated by magic bytes rather than by the name or the
 * client-supplied Content-Type, because both are attacker-controlled.
 */

/** Where uploaded attachments are kept. Never inside the document root. */
function tg_attachment_dir(): string {
    $dir = dirname(__DIR__).'/storage/attachments';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/** Accepted types, keyed by the sniffed mime. Extension is derived from this. */
function tg_attachment_types(): array {
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
}

/**
 * Sniff a file's real type from its leading bytes.
 *
 * @return string|null mime on success, null when the type is not allowed
 */
function tg_attachment_sniff(string $path): ?string {
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    $head = (string)fread($fh, 16);
    fclose($fh);
    if (str_starts_with($head, "\xFF\xD8\xFF")) return 'image/jpeg';
    if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) return 'image/png';
    if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') return 'image/webp';
    if (str_starts_with($head, '%PDF-')) return 'application/pdf';
    return null;
}

/**
 * Validate and store one uploaded file.
 *
 * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
 * @return array{ok:bool,error?:string,row?:array}
 */
function tg_attachment_store(array $file, int $ticketId, int $merchantId, ?int $messageId, int $maxBytes = 5242880): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'no-file'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'The upload failed. Try again with a smaller file.'];
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        // Defensive: the harness and some FPM setups hand us a normal temp file.
        if (!is_file((string)$file['tmp_name'])) {
            return ['ok' => false, 'error' => 'The upload could not be read.'];
        }
    }
    $bytes = (int)($file['size'] ?? 0);
    if ($bytes <= 0 || $bytes > $maxBytes) {
        return ['ok' => false, 'error' => 'Each file must be between 1 byte and '.round($maxBytes / 1048576).' MB.'];
    }
    $mime = tg_attachment_sniff((string)$file['tmp_name']);
    if ($mime === null || !isset(tg_attachment_types()[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, WEBP and PDF files are accepted.'];
    }
    $stored = bin2hex(random_bytes(16)).'.'.tg_attachment_types()[$mime];
    $target = tg_attachment_dir().'/'.$stored;
    $moved = @move_uploaded_file((string)$file['tmp_name'], $target)
        || @rename((string)$file['tmp_name'], $target);
    if (!$moved) {
        return ['ok' => false, 'error' => 'The file could not be stored. Check that storage/ is writable.'];
    }
    @chmod($target, 0600);

    $name = mb_substr(basename((string)($file['name'] ?? $stored)), 0, 200);
    db()->prepare('INSERT INTO ticket_attachments(ticket_id,message_id,merchant_id,stored_name,original_name,mime,bytes,created_at)
                   VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$ticketId, $messageId, $merchantId, $stored, $name, $mime, $bytes, gmdate('Y-m-d H:i:s')]);

    return ['ok' => true, 'row' => ['stored_name' => $stored, 'original_name' => $name, 'mime' => $mime, 'bytes' => $bytes]];
}

/** All attachments for a ticket, oldest first. */
function tg_attachments_for(int $ticketId, int $merchantId): array {
    $q = db()->prepare('SELECT id,ticket_id,message_id,stored_name,original_name,mime,bytes,created_at
                          FROM ticket_attachments WHERE ticket_id=? AND merchant_id=? ORDER BY id');
    $q->execute([$ticketId, $merchantId]);
    return $q->fetchAll();
}

/** Absolute path for a stored attachment, or null when it is missing. */
function tg_attachment_path(string $storedName): ?string {
    // The stored name is generated, but validate anyway: it reaches the disk.
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $storedName)) return null;
    $path = tg_attachment_dir().'/'.$storedName;
    return is_file($path) ? $path : null;
}

/** Human-readable size. */
function tg_attachment_size(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1).' MB';
    if ($bytes >= 1024) return round($bytes / 1024).' KB';
    return $bytes.' B';
}

/**
 * Store the files posted with a ticket form.
 *
 * Accepts up to five files, rejects oversize or unsupported ones individually
 * (one bad file must not discard the others), and never raises past the caller.
 *
 * @return array{stored:int,errors:list<string>}
 */
function tg_ticket_collect_uploads(int $ticketId, int $merchantId, ?int $messageId = null): array {
    $out = ['stored' => 0, 'errors' => []];
    if (!isset($_FILES['attachments']) || !is_array($_FILES['attachments']['name'] ?? null)) return $out;

    $names = (array)$_FILES['attachments']['name'];
    $tmp = (array)($_FILES['attachments']['tmp_name'] ?? []);
    $errors = (array)($_FILES['attachments']['error'] ?? []);
    $sizes = (array)($_FILES['attachments']['size'] ?? []);
    // A browser sends one entry per slot; anything beyond five is ignored.
    foreach (array_slice(array_keys($names), 0, 5) as $i) {
        $result = tg_attachment_store([
            'name' => (string)$names[$i],
            'tmp_name' => (string)($tmp[$i] ?? ''),
            'error' => (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($sizes[$i] ?? 0),
        ], $ticketId, $merchantId, $messageId);
        if ($result['ok']) {
            $out['stored']++;
        } elseif (($result['error'] ?? '') !== 'no-file' && count($out['errors']) < 2) {
            $out['errors'][] = (string)($result['error'] ?? 'A file could not be attached.');
        }
    }
    return $out;
}
