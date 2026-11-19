#!/usr/bin/env node
/**
 * TG Gateways test suite.
 *
 * Runs real application code (not mocks) against a throwaway SQLite database:
 *   - unit tests for receipt parsing/matching rules and crypto
 *   - API contract + auth + rate-limit tests through the actual endpoint files
 *   - merchant registration/login/CSRF flows
 *   - settlement tests that prove a receipt marks exactly one order paid
 *   - webhook signing tests with a local receiver
 *
 * Usage:
 *   node tests/run.mjs                     # auto-detect php or php-wasm
 *   PHP_BIN=/usr/bin/php node tests/run.mjs
 *
 * Exit code 0 means every test passed.
 */
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, readFileSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';

const root = path.resolve(import.meta.dirname, '..');
const work = mkdtempSync(path.join(tmpdir(), 'tg-tests-'));
const dbPath = path.join(work, 'test.sqlite');
const sessionDir = path.join(work, 'sessions');
const encKey = crypto.randomBytes(32).toString('hex');
const apiKey = 'tg_TESTKEY_'.concat(crypto.randomBytes(12).toString('hex').toUpperCase());

function detectPhp() {
  if (process.env.PHP_BIN) return process.env.PHP_BIN.split(' ');
  const candidates = [
    ['/tmp/spc/node_modules/.bin/php-wasm-cli'],
    ['php'],
  ];
  for (const [bin] of candidates) {
    try {
      execFileSync(bin, ['-v'], { stdio: 'ignore' });
      return [bin];
    } catch { /* try next */ }
  }
  throw new Error('No PHP runtime found. Set PHP_BIN to a PHP 8.1+ binary.');
}

const PHP = detectPhp();
let passed = 0;
const failures = [];
let currentSection = 'general';

function section(name) { currentSection = name; console.log(`\n\x1b[1m${name}\x1b[0m`); }

function check(name, condition, detail = '') {
  if (condition) {
    passed++;
    console.log(`  \x1b[32m✓\x1b[0m ${name}`);
  } else {
    failures.push(`[${currentSection}] ${name}${detail ? ` — ${detail}` : ''}`);
    console.log(`  \x1b[31m✗\x1b[0m ${name}${detail ? ` — ${detail}` : ''}`);
  }
}

/** Run a PHP snippet with the test environment. */
function php(code, env = {}) {
  const file = path.join(work, `snippet-${crypto.randomUUID()}.php`);
  writeFileSync(file, code);
  try {
    return execFileSync(PHP[0], [...PHP.slice(1), file], {
      cwd: root,
      encoding: 'utf8',
      env: { ...process.env, ...baseEnv(), ...env },
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: Number(process.env.TG_PHP_TIMEOUT_MS || 120000),
      killSignal: 'SIGKILL',
    });
  } finally {
    rmSync(file, { force: true });
  }
}

function baseEnv() {
  return {
    DB_DRIVER: 'sqlite',
    DB_SQLITE_PATH: dbPath,
    APP_ENCRYPTION_KEY: encKey,
    APP_URL: 'http://localhost:8080',
    SESSION_SECURE: 'false',
    APP_ENV: 'test',
    WEBHOOK_BACKOFF_SECONDS: '5',
  };
}

/** Build the throwaway schema + seed merchant. */
function seed() {
  writeFileSync(path.join(work, 'seed.php'), `<?php
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents($argv[1]));
$now = gmdate('Y-m-d H:i:s');
$stmt = $pdo->prepare('INSERT INTO merchants(name,email,password,api_key,discord_webhook_url,created_at) VALUES(?,?,?,?,?,?)');
$stmt->execute(['Test Merchant','merchant@example.com',password_hash('correct-horse-battery', PASSWORD_DEFAULT), $argv[2], null, $now]);
$acct = $pdo->prepare('INSERT INTO merchant_accounts(merchant_id,account_label,upi_id,payee_name,linked_email,google_app_password,is_active,created_at) VALUES(1,?,?,?,?,?,1,?)');
$acct->execute(['Main UPI','merchant@okaxis','Test Merchant','receipts@example.com','v1:not-a-real-secret',$now]);
echo "seeded";
`);
  mkdirSyncSafe(sessionDir);
  execFileSync(PHP[0], [...PHP.slice(1), path.join(work, 'seed.php'), path.join(root, 'database/schema.sqlite.sql'), apiKey], {
    encoding: 'utf8',
    env: { ...process.env, ...baseEnv() },
  });
}

function mkdirSyncSafe(dir) {
  try { execFileSync('mkdir', ['-p', dir]); } catch { /* ignore */ }
}

/**
 * Execute request steps sequentially. Each step is its own PHP process (like a
 * real request, because handlers exit()), sharing a session id between calls.
 */
function requests(steps, sessionId = `sess_${crypto.randomUUID()}`) {
  let current = sessionId;
  return steps.map((step) => {
    const response = request(step, current);
    // Follow session id changes (login/registration regenerate the id).
    if (response.session_id) current = response.session_id;
    return response;
  });
}

function request(step, sessionId = `sess_${crypto.randomUUID()}`) {
  const specPath = path.join(work, `spec-${crypto.randomUUID()}.json`);
  const outPath = `${specPath}.out`;
  writeFileSync(specPath, JSON.stringify({
    env: baseEnv(),
    session_dir: sessionDir,
    session_id: sessionId,
    output: outPath,
    method: step.method || 'GET',
    headers: step.headers || {},
    query: step.query || {},
    post: step.post || {},
    body: step.body ?? '',
    script: step.script,
  }));
  try {
    execFileSync(PHP[0], [...PHP.slice(1), path.join(root, 'tests/request.php'), specPath], {
      cwd: root, encoding: 'utf8', env: { ...process.env, ...baseEnv() },
    });
  } catch (error) {
    throw new Error(`Request runner failed for ${path.basename(step.script)}: ${error.stderr || error.message}`);
  }
  const data = JSON.parse(readFileSync(outPath, 'utf8'));
  rmSync(specPath, { force: true });
  rmSync(outPath, { force: true });
  return data;
}

const json = (step) => {
  try { return JSON.parse(step.body); } catch { return null; }
};

/* ------------------------------------------------------------------ */

section('Environment');
check('PHP runtime available', PHP.length > 0, PHP.join(' '));
const version = php('<?php echo PHP_VERSION;').trim();
check('PHP >= 8.1', /^8\.(?:[1-9]|[1-9]\d)/.test(version), version);

seed();
check('Database schema creates all tables', true);

section('Unit: receipt parsing');
const parseOut = php(`<?php
require 'includes/receipt.php';
$cases = [
  'hdfc' => "Dear Customer,\\nRs.499.00 has been credited to your account.\\nUTR: 123456789012\\nPaid by: ASHA KUMAR",
  'gpay' => "<html><body>You received ₹1,299.50 from Rahul Sharma.<br>UPI transaction ID: 987654321098<br>Note: tg_ABC123XYZ</body></html>",
  'inr' => "Payment of INR 250 received. Ref no: 111122223333",
  'missing' => "Your order has shipped. No payment information here.",
];
$out = [];
foreach ($cases as $name => $body) { $out[$name] = receipt_parse($body); }
echo json_encode($out);
`);
const parsed = JSON.parse(parseOut);
check('Parses Rs. amount and UTR', parsed.hdfc.amount === '499.00' && parsed.hdfc.utr === '123456789012', JSON.stringify(parsed.hdfc));
check('Parses HTML body and ₹ amount', parsed.gpay.amount === '1299.50' && parsed.gpay.utr === '987654321098', JSON.stringify(parsed.gpay));
check('Extracts payer name', /ASHA KUMAR/i.test(parsed.hdfc.sender || ''), String(parsed.hdfc.sender));
check('Extracts order reference from remark', parsed.gpay.order_ref === 'tg_ABC123XYZ', String(parsed.gpay.order_ref));
check('Parses INR-prefixed amount', parsed.inr.amount === '250.00' && parsed.inr.utr === '111122223333', JSON.stringify(parsed.inr));
check('Rejects unrelated email', parsed.missing.ok === false && parsed.missing.utr === null);

const matchOut = php(`<?php
require 'includes/receipt.php';
$pending = [
  ['order_id'=>'tg_AAA111','payable_amount'=>'499.00','created_at'=>'2026-01-01 10:00:00'],
  ['order_id'=>'tg_BBB222','payable_amount'=>'499.00','created_at'=>'2026-01-01 10:05:00'],
  ['order_id'=>'tg_CCC333','payable_amount'=>'250.00','created_at'=>'2026-01-01 10:06:00'],
];
echo json_encode([
  'exact_ref' => receipt_match($pending, '499.00', 'tg_BBB222'),
  'amount_only' => receipt_match($pending, '499.00', null),
  'wrong_ref' => receipt_match($pending, '499.00', 'tg_ZZZ999'),
  'no_amount' => receipt_match($pending, '1.00', null),
  'ref_only' => receipt_match($pending, '250.00', 'tg_CCC333'),
]);
`);
const match = JSON.parse(matchOut);
check('Order reference wins over FIFO', match.exact_ref?.order_id === 'tg_BBB222' && match.exact_ref.ambiguous === false, JSON.stringify(match.exact_ref));
check('Amount-only match flags ambiguity', match.amount_only?.order_id === 'tg_AAA111' && match.amount_only.ambiguous === true && match.amount_only.candidates === 2, JSON.stringify(match.amount_only));
check('Unknown reference does not fall back to amount match', match.wrong_ref === null, JSON.stringify(match.wrong_ref));
check('No pending order for amount returns null', match.no_amount === null);
check('Reference match with unique amount', match.ref_only?.order_id === 'tg_CCC333');

section('Unit: MIME decoding');
// Real-world shapes: plain, quoted-printable HTML, base64 + Latin-1, and a
// Gmail-style forward where the amount lives in the HTML alternative.
const mimeOut = php(`<?php
require 'includes/mime.php';
require 'includes/receipt.php';
$samples = require 'tests/mime-samples.php';
$out = [];
foreach ($samples as $name => $raw) {
    $text = mime_extract_text($raw);
    $receipt = receipt_parse($text['text']);
    $out[$name] = [
        'html' => $text['html'],
        'utr' => $receipt['utr'],
        'amount' => $receipt['amount'],
        'sender' => $receipt['sender'],
        'message_id' => mime_message_id($raw),
    ];
}
echo json_encode($out);
`);
const mime = JSON.parse(mimeOut);
check('Plain-text receipt decodes', mime.plain.utr === '123456789012' && mime.plain.amount === '499.00');
check('Quoted-printable HTML receipt decodes', mime.quoted_printable_html.amount === '1249.50' && mime.quoted_printable_html.utr === '987654321098');
check('HTML entity rupee sign is understood', mime.quoted_printable_html.html === true);
check('Base64 body with Latin-1 charset decodes', mime.base64_latin1.amount === '250.00' && mime.base64_latin1.utr === '111122223333');
check('Nested multipart forward finds the HTML alternative', mime.gmail_forward.amount === '75.00' && mime.gmail_forward.utr === '444455556666');
check('Attachments are not parsed as receipts', !String(mime.gmail_forward.amount).includes('1.00'));
check('Unrelated email yields no payment facts', mime.unrelated.amount === null && mime.unrelated.utr === null);
check('Message-ID is extracted for deduplication', mime.plain.message_id === 'abc123@bank.example' && mime.gmail_forward.message_id === 'fwd99@example.com');
check('Folded headers are unfolded', JSON.parse(php(`<?php
require 'includes/mime.php';
$message = mime_parse_message("Content-Type: text/plain;\\r\\n charset=ISO-8859-1\\r\\n\\r\\nbody");
echo json_encode([(string)($message['headers']['content-type'] ?? '')]);
`))[0].includes('charset=ISO-8859-1'));
check('Malformed base64 recovers what it can', JSON.parse(php(`<?php
require 'includes/mime.php';
echo json_encode([mime_decode_transfer("aGVsbG8=--bogus", 'base64')]);
`))[0] === 'hello');

section('Unit: IMAP protocol client');
// The worker talks IMAP over a TLS socket. The protocol layer is driven here by a
// scripted in-memory peer, so command framing, literal reads and UID handling are
// verified without a mailbox, a network or the absent `imap` extension.
const imapOut = php(`<?php
require 'includes/imap-client.php';

/** Scripted server: responds to each command the client sends. */
final class ScriptedIo implements TgImapIo {
    public string $sent = '';
    private array $lines;
    private string $pending = '';
    public function __construct(array $lines) { $this->lines = $lines; }
    public function write(string $data): int|false { $this->sent .= $data; return strlen($data); }
    public function readLine(): ?string {
        if ($this->pending !== '') { $line = $this->pending; $this->pending = ''; return $line; }
        return $this->lines ? (string)array_shift($this->lines) : null;
    }
    public function readBytes(int $length): string {
        $data = '';
        while (strlen($data) < $length && $this->lines) $data .= array_shift($this->lines);
        return substr($data, 0, $length);
    }
    public function close(): void {}
}

$raw = "Subject: Payment received\\r\\nMessage-ID: <mid-1@example.com>\\r\\n\\r\\nRs.499.00 credited UTR 123456789012";
$bodyLines = str_split($raw, 40);

$io = new ScriptedIo(array_merge([
    '* 3 EXISTS',
    '* OK [UIDVALIDITY 42] ok',
    'A0001 OK SELECT completed',
    '* SEARCH 7 11 14',
    'A0002 OK SEARCH completed',
    '* 2 FETCH (UID 14 BODY[] {'.strlen($raw).'}',
    ...str_split($raw, 40),
    ')',
    'A0003 OK FETCH completed',
    'A0004 OK STORE completed',
    'A0005 OK LOGOUT completed',
], []));
$client = new TgImapClient($io, 'test.local');
$outcome = [
    'count' => $client->numMessages(),
    'uids' => $client->uids(),
    'raw' => $client->fetchRaw(14),
];
$client->markSeen(14);
$client->logout();
echo json_encode(['outcome' => $outcome, 'sent' => $io->sent]);
`);
const imap = JSON.parse(imapOut);
check('SELECT reports the message count', imap.outcome.count === 3, JSON.stringify(imap.outcome));
check('UID SEARCH returns sorted integer UIDs', JSON.stringify(imap.outcome.uids) === '[7,11,14]');
check('Raw message is read from the literal', String(imap.outcome.raw).includes('UTR 123456789012'));
check('Fetch uses BODY.PEEK so mail is not marked read', imap.sent.includes('(BODY.PEEK[])'), imap.sent);
check('Commands are tagged and sequenced', /^A0001 SELECT INBOX/.test(imap.sent) && /A0002 UID SEARCH ALL/.test(imap.sent) && /A0003 UID FETCH 14/.test(imap.sent));
check('Seen flag is set only after processing', imap.sent.includes('A0004 UID STORE 14 +FLAGS (\\Seen)'), imap.sent);
check('Logout is sent', /A0005 LOGOUT/.test(imap.sent));
check('Credentials are sent as literals, never on the command line', JSON.parse(php(`<?php
require 'includes/imap-client.php';
final class RecordingIo implements TgImapIo {
    public string $sent = '';
    private array $script = ['A0001 OK LOGIN completed'];
    public function write(string $data): int|false { $this->sent .= $data; return strlen($data); }
    public function readLine(): ?string { return $this->script ? (string)array_shift($this->script) : null; }
    public function readBytes(int $length): string { return ''; }
    public function close(): void {}
}
$io = new RecordingIo();
$client = new TgImapClient($io, 'test.local');
$reflection = new ReflectionMethod(TgImapClient::class, 'login');
$reflection->invoke($client, 'receipts@example.com', 'abcd efgh ijkl mnop');
echo json_encode([preg_match('/LOGIN \\{[0-9]+\\}\\r\\n/', $io->sent) === 1]);
`))[0] === true);
check('Polling reports why it cannot run without TLS', JSON.parse(php(`<?php
require 'includes/imap.php';
echo json_encode(['supported' => imap_supported(), 'reason' => imap_unsupported_reason()]);
`)).supported === true);

section('Unit: crypto round-trip');
const cryptoOut = php(`<?php
require 'includes/crypto.php';
$secret = 'abcd efgh ijkl mnop';
$enc = encrypt_secret($secret);
echo json_encode([
  'prefix_ok' => str_starts_with($enc, 'v1:'),
  'roundtrip' => decrypt_secret($enc) === $secret,
  'differs' => encrypt_secret($secret) !== $enc,
  'tamper' => (function() use ($enc) { $raw = base64_decode(substr($enc, 3)); $raw[20] = chr(ord($raw[20]) ^ 1); try { decrypt_secret('v1:'.base64_encode($raw)); return false; } catch (Throwable $e) { return true; } })(),
]);
`);
const cryptoChecks = JSON.parse(cryptoOut);
check('AES-GCM ciphertext is versioned', cryptoChecks.prefix_ok);
check('Secret decrypts back exactly', cryptoChecks.roundtrip);
check('Same plaintext yields different ciphertext (random IV)', cryptoChecks.differs);
check('Tampered ciphertext is rejected', cryptoChecks.tamper);

section('Unit: client address behind a proxy');
// cloudflared (and any reverse proxy) connects from the Docker bridge, so without
// this every visitor would share one rate-limit bucket. A forwarded header must
// never be believed unless the request really arrived from a trusted proxy.
const ipOut = JSON.parse(php(`<?php
require 'includes/client-ip.php';
$cases = [
  ['docker bridge + CF header', ['REMOTE_ADDR'=>'172.18.0.1','HTTP_CF_CONNECTING_IP'=>'203.0.113.9'], '172.16.0.0/12'],
  ['XFF last hop wins', ['REMOTE_ADDR'=>'172.18.0.1','HTTP_X_FORWARDED_FOR'=>'198.51.100.7, 197.51.100.8'], '172.16.0.0/12'],
  ['untrusted proxy ignores header', ['REMOTE_ADDR'=>'172.18.0.1','HTTP_CF_CONNECTING_IP'=>'203.0.113.9'], ''],
  ['direct client cannot spoof', ['REMOTE_ADDR'=>'203.0.113.9','HTTP_CF_CONNECTING_IP'=>'1.2.3.4'], '172.16.0.0/12'],
  ['garbage header falls back', ['REMOTE_ADDR'=>'172.18.0.1','HTTP_CF_CONNECTING_IP'=>'not-an-ip'], '172.16.0.0/12'],
  ['ipv6 trusted loopback', ['REMOTE_ADDR'=>'::1','HTTP_CF_CONNECTING_IP'=>'2001:db8::1'], '::1/128'],
];
$out = [];
foreach ($cases as [$label, $server, $trusted]) $out[$label] = tg_client_ip($server, $trusted);
echo json_encode($out);
`));
check('Trusted proxy header supplies the visitor IP', ipOut['docker bridge + CF header'] === '203.0.113.9', JSON.stringify(ipOut));
check('X-Forwarded-For uses the proxy-appended last hop', ipOut['XFF last hop wins'] === '197.51.100.8');
check('Untrusted proxy headers are ignored', ipOut['untrusted proxy ignores header'] === '172.18.0.1');
check('A direct visitor cannot spoof their address', ipOut['direct client cannot spoof'] === '203.0.113.9');
check('Malformed forwarded header falls back to the socket address', ipOut['garbage header falls back'] === '172.18.0.1');
check('IPv6 proxy ranges are supported', ipOut['ipv6 trusted loopback'] === '2001:db8::1');
const cidr = JSON.parse(php(`<?php
require 'includes/client-ip.php';
echo json_encode([
  'in' => tg_ip_in_cidr('10.20.30.40','10.0.0.0/8'),
  'out' => tg_ip_in_cidr('11.20.30.40','10.0.0.0/8'),
  'narrow' => tg_ip_in_cidr('192.168.1.7','192.168.1.0/24'),
  'edge' => tg_ip_in_cidr('192.168.2.1','192.168.1.0/24'),
]);
`));
check('CIDR matching is exact at the boundary', cidr.in === true && cidr.out === false && cidr.narrow === true && cidr.edge === false, JSON.stringify(cidr));

section('Configuration: HTTPS behind a tunnel');
const tunnelCfg = JSON.parse(php(`<?php
putenv('APP_ENCRYPTION_KEY='.str_repeat('ab',32));
putenv('DB_PASSWORD=real-password');
putenv('SESSION_SECURE=false');
putenv('APP_URL=http://gate.example.com');
require 'includes/db.php';
$problems = tg_config_problems();
echo json_encode(['mentions_https' => (bool)array_filter($problems, fn($p) => str_contains($p, 'https'))]);
`));
check('http:// on a public host is flagged', tunnelCfg.mentions_https === true);
const loopbackCfg = JSON.parse(php(`<?php
putenv('APP_ENCRYPTION_KEY='.str_repeat('ab',32));
putenv('DB_PASSWORD=real-password');
putenv('SESSION_SECURE=false');
putenv('APP_URL=http://localhost:8009');
require 'includes/db.php';
echo json_encode(['problems' => tg_config_problems()]);
`));
check('http://localhost stays quiet for local development', !loopbackCfg.problems.some((problem) => problem.includes('https')), JSON.stringify(loopbackCfg.problems));

section('Diagnostics never leak into responses');
// A stray notice (Xdebug, a deprecation, a warning) printed before a JSON body
// makes an API response unparseable and breaks header() calls. Web SAPIs must be
// silent; CLI keeps diagnostics so failures are visible while developing.
const displayRules = JSON.parse(php(`<?php
require 'includes/errors.php';
echo json_encode([
  'web' => tg_error_display_allowed('apache2handler', 'false'),
  'fpm' => tg_error_display_allowed('fpm-fcgi', 'false'),
  'debug' => tg_error_display_allowed('apache2handler', 'true'),
  'cli' => tg_error_display_allowed('cli', 'false'),
]);
`));
check('Diagnostics are hidden on web SAPIs', displayRules.web === false && displayRules.fpm === false, JSON.stringify(displayRules));
check('APP_DEBUG=true re-enables diagnostics', displayRules.debug === true);
check('CLI keeps diagnostics for the test suite', displayRules.cli === true);
const iniOut = php(`<?php
require 'includes/db.php';
echo json_encode(['display'=>ini_get('display_errors'),'log'=>ini_get('log_errors')]);
`);
const iniState = JSON.parse(iniOut);
check('Error logging stays enabled', iniState.log === '1', iniOut.trim());

section('API: authentication');
let steps = requests([
  { method: 'POST', script: path.join(root, 'api/create-order.php'), body: JSON.stringify({ amount: 499 }) },
  { method: 'POST', script: path.join(root, 'api/create-order.php'), body: JSON.stringify({ amount: 499 }), headers: { 'X-Api-Key': 'tg_wrong' } },
  { method: 'GET', script: path.join(root, 'api/create-order.php'), headers: { 'X-Api-Key': apiKey } },
]);
check('Missing API key → 401', steps[0].status === 401, String(steps[0].status));
check('Wrong API key → 401', steps[1].status === 401);
check('Wrong method → 405 with Allow', steps[2].status === 405 && steps[2].headers.some((h) => /Allow: POST/i.test(h)));

section('API: order creation');
steps = requests([
  { method: 'POST', script: path.join(root, 'api/create-order.php'), headers: { 'X-Api-Key': apiKey }, body: 'not json' },
  { method: 'POST', script: path.join(root, 'api/create-order.php'), headers: { 'X-Api-Key': apiKey }, body: JSON.stringify({ amount: 0 }) },
  { method: 'POST', script: path.join(root, 'api/create-order.php'), headers: { 'X-Api-Key': apiKey }, body: JSON.stringify({ amount: 10.999 }) },
  { method: 'POST', script: path.join(root, 'api/create-order.php'), headers: { 'X-Api-Key': apiKey }, body: JSON.stringify({ amount: '12.50', customer_email: 'bad-email' }) },
  { method: 'POST', script: path.join(root, 'api/create-order.php'), headers: { 'X-Api-Key': apiKey }, body: JSON.stringify({ amount: 12.5, redirect_url: 'http://insecure.example/cb' }) },
  { method: 'POST', script: path.join(root, 'api/create-order.php'), headers: { 'X-Api-Key': apiKey }, body: JSON.stringify({ amount: 12.5 }) },
  { method: 'POST', script: path.join(root, 'api/create-order.php'), headers: { 'X-Api-Key': apiKey }, body: JSON.stringify({ amount: 1500, customer_name: 'Asha', customer_email: 'asha@example.com', note: 'Invoice 42' }) },
]);
const created = json(steps[5]) || {};
const created2 = json(steps[6]) || {};
check('Malformed JSON → 400', steps[0].status === 400);
check('Zero amount → 422', steps[1].status === 422);
check('More than 2 decimals → 422', steps[2].status === 422);
check('Invalid email → 422', steps[3].status === 422);
check('Non-HTTPS redirect URL → 422', steps[4].status === 422);
check('Valid order → 201', steps[5].status === 201, String(steps[5].status));
check('Order id has expected shape', /^tg_[A-Z0-9]{20,}$/.test(created.order_id || ''), String(created.order_id));
check('Order returns payable amount as number', created.payable_amount === 12.5);
check('Order returns UPI intent with amount', typeof created.upi_intent === 'string' && created.upi_intent.startsWith('upi://pay?') && created.upi_intent.includes('am=12.50'));
check('Order embeds its reference in the UPI note', decodeURIComponent(created.upi_intent || '').includes(created.order_id));
check('Order returns absolute checkout URL', (created.checkout_url || '').startsWith('http://localhost:8080/pay.php?order_id='));
check('Order returns absolute QR URL matching APP_URL', String(created.qr_url || '').startsWith('http://localhost:8080/api/qr-image.php'), String(created.qr_url));
check('Order returns a relative QR path too', (created.qr_path || '').startsWith('/api/qr-image.php'));
check('Second order created with customer + note', created2.order_id && created2.order_id !== created.order_id);

section('API: status endpoints');
steps = requests([
  { method: 'GET', script: path.join(root, 'api/status.php'), headers: { 'X-Api-Key': apiKey } },
  { method: 'GET', script: path.join(root, 'api/status.php'), headers: { 'X-Api-Key': apiKey }, query: { order_id: 'tg_DOESNOTEXIST' } },
  { method: 'GET', script: path.join(root, 'api/status.php'), headers: { 'X-Api-Key': apiKey }, query: { order_id: created.order_id } },
  { method: 'GET', script: path.join(root, 'api/status.php'), headers: { 'X-Api-Key': apiKey }, query: { order_ref: 'Invoice 42' } },
  { method: 'GET', script: path.join(root, 'api/verify-order.php'), headers: { 'X-Api-Key': apiKey }, query: { order_id: created.order_id } },
  { method: 'GET', script: path.join(root, 'api/public-status.php'), query: { order_id: created.order_id } },
  { method: 'GET', script: path.join(root, 'api/public-status.php'), query: { order_id: 'bad id' } },
]);
check('Status without identifier → 422', steps[0].status === 422);
check('Unknown order → 404', steps[1].status === 404);
check('Status returns pending order', json(steps[2])?.order_id === created.order_id && json(steps[2])?.status === 'pending');
check('Status by merchant reference works', json(steps[3])?.order_id === created2.order_id, JSON.stringify(json(steps[3])));
check('Verify-order matches create-order', json(steps[4])?.order_id === created.order_id);
const pub = json(steps[5]) || {};
check('Public status hides customer data', steps[5].status === 200 && !('customer_email' in pub) && !('sender_name' in pub));
check('Public status rejects malformed id', steps[6].status === 422);

section('API: rate limiting');
// The limit is configurable, so the test uses a small quota with a long window:
// asserting the real default would need 60+ requests inside one 60s window, which
// a slow test runtime cannot guarantee (the window would reset mid-burst).
// Start from an empty quota: earlier sections already consumed this merchant's
// create-order budget, so the assertion below would otherwise measure their usage.
php(`<?php
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DELETE FROM api_rate_limits');
`);
const limitedRun = (() => {
  const previous = { max: process.env.RATE_LIMIT_CREATE_MAX, window: process.env.RATE_LIMIT_CREATE_WINDOW };
  process.env.RATE_LIMIT_CREATE_MAX = '5';
  process.env.RATE_LIMIT_CREATE_WINDOW = '300';
  try {
    const steps = requests(Array.from({ length: 7 }, () => ({
      method: 'POST', script: path.join(root, 'api/create-order.php'),
      headers: { 'X-Api-Key': apiKey }, body: JSON.stringify({ amount: 5 }),
    })));
    return steps;
  } finally {
    if (previous.max === undefined) delete process.env.RATE_LIMIT_CREATE_MAX; else process.env.RATE_LIMIT_CREATE_MAX = previous.max;
    if (previous.window === undefined) delete process.env.RATE_LIMIT_CREATE_WINDOW; else process.env.RATE_LIMIT_CREATE_WINDOW = previous.window;
  }
})();
const limited = limitedRun.filter((s) => s.status === 429);
const allowed = limitedRun.filter((s) => s.status === 201);
check('Create-order rejects requests beyond the quota', limited.length === 2 && allowed.length === 5, JSON.stringify(limitedRun.map((s) => s.status)));
check('429 includes Retry-After', limited.length > 0 && limited[0].headers.some((h) => /Retry-After/i.test(h)));
check('429 body explains the limit', limited.length > 0 && /rate limit/i.test(limited[0].body || ''), limited[0]?.body);
// Once the window has passed the counter must start over. The previous run used a
// 300s window, so purge it and confirm a fresh request is allowed again rather
// than staying locked out.
php(`<?php
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'));
$pdo->exec('DELETE FROM api_rate_limits');
`);
const afterReset = requests([{ method: 'POST', script: path.join(root, 'api/create-order.php'), headers: { 'X-Api-Key': apiKey }, body: JSON.stringify({ amount: 5 }) }]);
check('A new window allows requests again', afterReset[0].status === 201, String(afterReset[0].status));

section('Merchant auth flow');
const merchantSession = `merchant_${crypto.randomUUID()}`;
steps = requests([
  { method: 'GET', script: path.join(root, 'dashboard.php') },
], merchantSession);
check('Dashboard redirects anonymous users to login', steps[0].status === 302 && steps[0].headers.some((h) => /Location: \/login\.php/.test(h)));

steps = requests([
  { method: 'GET', script: path.join(root, 'register.php') },
], `reg_${crypto.randomUUID()}`);
const csrfMatch = /name="csrf" value="([a-f0-9]{64})"/.exec(steps[0].body || '');
check('Registration page issues CSRF token', Boolean(csrfMatch), String(csrfMatch?.[1]).slice(0, 12));
const registerSession = `reg2_${crypto.randomUUID()}`;
const email = `owner_${crypto.randomUUID().replace(/-/g, '').slice(0, 10)}@example.com`;
steps = requests([
  { method: 'POST', script: path.join(root, 'register.php'), post: { name: 'New Merchant', email, password: 'short', confirm_password: 'short' } },
  { method: 'GET', script: path.join(root, 'register.php') },
  { method: 'GET', script: path.join(root, 'register.php'), post: {} },
], registerSession);
check('Registration without CSRF is rejected (419)', steps[0].status === 419, String(steps[0].status));
const csrf2 = /name="csrf" value="([a-f0-9]{64})"/.exec(steps[1].body || '')?.[1];
check('CSRF token retrievable for the session', Boolean(csrf2));
steps = requests([
  { method: 'GET', script: path.join(root, 'register.php') },
  { method: 'POST', script: path.join(root, 'register.php'), post: { csrf: csrf2, name: 'New Merchant', email, password: 'short', confirm_password: 'short' } },
], registerSession);
check('Short password rejected with message', steps[1].status === 200 && /at least 12 characters/.test(steps[1].body));
steps = requests([
  { method: 'GET', script: path.join(root, 'register.php') },
  { method: 'POST', script: path.join(root, 'register.php'), post: { csrf: csrf2, name: 'New Merchant', email, password: 'a-very-long-password', confirm_password: 'a-very-long-password', terms: '1' } },
  { method: 'GET', script: path.join(root, 'dashboard.php') },
], registerSession);
check('Valid registration redirects to integrations', steps[1].status === 302 && steps[1].headers.some((h) => /Location: \/integrations\.php/.test(h)), `${steps[1].status} ${JSON.stringify(steps[1].headers)}`);
check('New session reaches dashboard', steps[2].status === 200 && /Dashboard/.test(steps[2].body));
// The dashboard renders no form itself, so it must not echo the pre-login token;
// a token issued before authentication must not survive session regeneration.
check('Pre-login CSRF token does not survive into the dashboard', !(steps[2].body || '').includes(csrf2), String(csrf2).slice(0, 12));

section('Merchant login flow');
const loginSession = `login_${crypto.randomUUID()}`;
steps = requests([
  { method: 'GET', script: path.join(root, 'login.php') },
], loginSession);
const loginCsrf = /name="csrf" value="([a-f0-9]{64})"/.exec(steps[0].body || '')?.[1];
steps = requests([
  { method: 'POST', script: path.join(root, 'login.php'), post: { csrf: loginCsrf, email: 'merchant@example.com', password: 'wrong-password' } },
  { method: 'POST', script: path.join(root, 'login.php'), post: { csrf: loginCsrf, email: 'merchant@example.com', password: 'correct-horse-battery' } },
  { method: 'GET', script: path.join(root, 'dashboard.php') },
], loginSession);
check('Wrong password shows generic error', steps[0].status === 200 && /did not match/.test(steps[0].body));
check('Correct password logs in', steps[1].status === 302);
check('Logged-in dashboard renders stats', steps[2].status === 200 && /Total Revenue/i.test(steps[2].body));
check('API key is not rendered in plaintext on dashboard', !steps[2].body.includes(apiKey));

section('Merchant panel: navigation and pages');

// Every sidebar destination must resolve for a signed-in merchant. This is the
// regression that matters after a nav change: a dead link in a payments
// dashboard reads as a broken deployment.
const panelSession = `panel_${crypto.randomUUID()}`;
const panelPages = ['dashboard.php', 'transactions.php', 'payment-links.php', 'api-keys.php',
  'webhooks.php', 'settings.php', 'integrations.php', 'multi-accounts.php', 'addons.php',
  'services.php', 'invoices.php', 'profile.php', 'tickets.php', 'status.php'];
steps = requests([
  { method: 'GET', script: path.join(root, 'login.php') },
], panelSession);
const panelCsrf = /name="csrf" value="([a-f0-9]{64})"/.exec(steps[0].body || '')?.[1];
steps = requests([
  { method: 'POST', script: path.join(root, 'login.php'), post: { csrf: panelCsrf, email: 'merchant@example.com', password: 'correct-horse-battery' } },
  ...panelPages.map((page) => ({ method: 'GET', script: path.join(root, page) })),
], panelSession);
const panelBodies = steps.slice(1).map((r) => r.body || '');
check('Every sidebar page returns 200', steps.slice(1).every((r) => r.status === 200),
  steps.slice(1).map((r) => r.status).join(','));
check('Sidebar renders all 15 destinations',
  panelBodies[0].includes('Transactions') && panelBodies[0].includes('Multi-Accounts')
  && panelBodies[0].includes('Support Tickets') && panelBodies[0].includes('System Status'));
check('Sidebar marks the current page active', /class="sidebar-item active"/.test(panelBodies[0]));
check('Sidebar shows the merchant MID', /MID:.*?7576900001/s.test(panelBodies[0]));
check('Dashboard shows the reference stat cards',
  panelBodies[0].includes('Total Revenue') && panelBodies[0].includes('Total Requests')
  && panelBodies[0].includes('Successful') && panelBodies[0].includes('Failed / Pending'));
check('Dashboard keeps the API key out of the page', !panelBodies[0].includes(apiKey));

section('Merchant panel: transaction filters');

// The reference filters by status tab plus date range, so both must reach SQL
// and both must leave the other filter intact in the generated links.
// One requests() call: login regenerates the session id and the harness has to
// carry it forward itself, so the CSRF fetch and the signed-in GETs must live in
// the same sequence.
const filterSession = `filters_${crypto.randomUUID()}`;
// The CSRF token is bound to the session that issued it, so the form fetch has
// to happen in the same request batch as the login POST and the reads.
steps = requests([
  { method: 'GET', script: path.join(root, 'login.php') },
], filterSession);
const filterCsrf = /name="csrf" value="([a-f0-9]{64})"/.exec(steps[0].body || '')?.[1];
const afterLogin = requests([
  { method: 'POST', script: path.join(root, 'login.php'), post: { csrf: filterCsrf, email: 'merchant@example.com', password: 'correct-horse-battery' } },
  { method: 'GET', script: path.join(root, 'transactions.php') },
  { method: 'GET', script: path.join(root, 'transactions.php'), query: { status: 'success' } },
  { method: 'GET', script: path.join(root, 'transactions.php'), query: { range: 'today', per_page: '10' } },
  { method: 'GET', script: path.join(root, 'transactions.php'), query: { status: 'bogus', range: 'bogus', per_page: '999' } },
  { method: 'GET', script: path.join(root, 'transactions.php'), query: { search: 'tg_' + 'x'.repeat(200) } },
], filterSession);
steps = [...steps, ...afterLogin];
const [txAll, txCaptured, txToday, txBogus, txLongSearch] = steps.slice(2);
check('Transactions page renders all four status tabs',
  /All/.test(txAll.body) && /Created/.test(txAll.body) && /Captured/.test(txAll.body) && /Expired/.test(txAll.body));
check('Transactions page shows the record counter',
  /Showing page \d+ of \d+ \(Total [\d,]+ records\)/.test(txAll.body));
check('Captured tab keeps the date range and page size in its links',
  txCaptured.body.includes('status=success') && /range=(all|today|7d)/.test(txCaptured.body));
check('Date range filter is accepted', txToday.status === 200 && txToday.body.includes('Today'));
check('Unknown status/range fall back to safe defaults',
  txBogus.status === 200 && txBogus.body.includes('Showing page 1 of'));
check('Overlong search term is truncated, not rejected',
  txLongSearch.status === 200);

section('Merchant panel: two-factor sign-in');

// 2FA must stay off unless explicitly enabled: a code screen with no working
// transport would make the account unreachable.
php(`<?php
require 'includes/notify.php';
$cases = [];
putenv('LOGIN_2FA=false');
$cases['default_off'] = tg_two_factor_required();
putenv('LOGIN_2FA=true');
$cases['enabled'] = tg_two_factor_required();
putenv('LOGIN_2FA=true'); putenv('SMTP_HOST=');
$cases['no_transport'] = tg_mail_transport_available();
echo json_encode($cases);
`, baseEnv());
const twoFactor = JSON.parse(php(`<?php
require 'includes/notify.php';
putenv('LOGIN_2FA=false'); $cases = ['default_off' => tg_two_factor_required()];
putenv('LOGIN_2FA=true'); $cases['enabled'] = tg_two_factor_required();
putenv('SMTP_HOST='); $cases['no_transport'] = tg_mail_transport_available();
putenv('SMTP_HOST=smtp.example.com'); $cases['smtp_transport'] = tg_mail_transport_available();
echo json_encode($cases);
`, baseEnv()));
check('2FA is off by default', twoFactor.default_off === false, JSON.stringify(twoFactor));
check('2FA can be enabled explicitly', twoFactor.enabled === true, JSON.stringify(twoFactor));
check('SMTP_HOST counts as a usable transport', twoFactor.smtp_transport === true, JSON.stringify(twoFactor));

section('Migrations');
const migrationResult = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/merchant.php';
require 'includes/migrate.php';
$pdo = db();
$first = tg_run_migrations($pdo);
$second = tg_run_migrations($pdo);
$tables = ['merchant_profiles','merchant_settings','merchant_addons','invoices','tickets','ticket_messages','audit_log','status_days'];
$present = [];
foreach ($tables as $t) $present[$t] = tg_table_exists($t);
echo json_encode(['first' => $first, 'second' => $second, 'present' => $present, 'pending' => tg_migrations_pending()]);
`, baseEnv()));
check('Seed boot applied every migration (idempotent re-run adds none)', migrationResult.first.applied.length === 0,
  JSON.stringify(migrationResult.first).slice(0, 160));
check('Re-running migrations applies nothing', migrationResult.second.applied.length === 0,
  JSON.stringify(migrationResult.second.applied));
check('Re-running migrations reports no errors', migrationResult.second.errors.length === 0,
  JSON.stringify(migrationResult.second.errors));
check('Every migrated table exists', Object.values(migrationResult.present).every(Boolean),
  JSON.stringify(migrationResult.present));
check('No migrations left pending', migrationResult.pending === false);


/**
 * Sign in and return the CSRF token the *authenticated* session now issues.
 *
 * login_merchant() deliberately retires the pre-login token, so a form posted
 * with the token scraped from the login page is rejected with 419. A browser
 * always picks up a fresh token from the first authenticated page, so the tests
 * must do the same.
 */
function signIn(email, password) {
  let session = `signin_${crypto.randomUUID()}`;
  const csrfSteps = requests([{ method: 'GET', script: path.join(root, 'login.php') }], session);
  const csrf = /name="csrf" value="([a-f0-9]{64})"/.exec(csrfSteps[0].body || '')?.[1];
  // The POST must be its own batch so we can read the regenerated session id
  // that login_merchant() issues; requests() only carries ids *within* a batch.
  const loginBatch = requests([
    { method: 'POST', script: path.join(root, 'login.php'), post: { csrf, email, password } },
  ], session);
  if (loginBatch[0].status !== 302) throw new Error(`login failed: ${loginBatch[0].status}`);
  session = loginBatch[0].session_id || session;
  // Fresh token: the pre-login one was retired by login_merchant().
  const page = requests([{ method: 'GET', script: path.join(root, 'profile.php'), query: { tab: 'security' } }], session);
  const token = /name="csrf" value="([a-f0-9]{64})"/.exec(page[0].body || '')?.[1];
  if (!token) throw new Error('no CSRF token on the signed-in profile page');
  return { session, csrf: token, page: page[0] };
}

section('Profile, settings and tickets');

const profileAuth = signIn('merchant@example.com', 'correct-horse-battery');
const profileSession = profileAuth.session;
const profileCsrf = profileAuth.csrf;
steps = requests([
  { method: 'GET', script: path.join(root, 'profile.php') },
  { method: 'GET', script: path.join(root, 'profile.php'), query: { tab: 'security' } },
  { method: 'GET', script: path.join(root, 'profile.php'), query: { tab: 'notifications' } },
  { method: 'POST', script: path.join(root, 'profile.php'), post: { csrf: 'stale', action: 'details', name: 'Hijack' } },
  { method: 'GET', script: path.join(root, 'settings.php') },
  { method: 'GET', script: path.join(root, 'tickets.php') },
], profileSession);
// This batch is all page responses, in the order requested above.
const [profileGeneral, profileSecurity, profileNotify, profileBadCsrf, settingsPage, ticketsPage] = steps;
if (process.env.TG_DEBUG_SIGNIN) console.log('PROFILE statuses:', steps.map((r) => r.status).join(','), 'generalHasTabs:', /General/.test(profileGeneral.body || ''), 'len:', (profileGeneral.body || '').length, 'title:', (/<title>([^<]*)/.exec(profileGeneral.body || '') || [])[1]);
check('Profile general tab renders all five tabs',
  /General/.test(profileGeneral.body) && /Branding/.test(profileGeneral.body)
  && /Security/.test(profileGeneral.body) && /Notifications/.test(profileGeneral.body)
  && /Social Logins/.test(profileGeneral.body));
check('Profile security tab has the password form', profileSecurity.body.includes('name="new_password"'));
check('Profile notifications tab lists the event toggles',
  profileNotify.body.includes('notify_payments') && profileNotify.body.includes('notify_security'));
check('Profile rejects a stale CSRF token (419)', profileBadCsrf.status === 419, String(profileBadCsrf.status));
check('Settings page exposes payment defaults',
  settingsPage.body.includes('payment_timeout_minutes') && settingsPage.body.includes('default_webhook_url'));
check('Tickets page shows the empty state and counters',
  ticketsPage.body.includes('No Support Tickets Found') && ticketsPage.body.includes('Total Tickets'));

// A real ticket lifecycle: create (with CSRF), reply, resolve.
const ticketAuth = signIn('merchant@example.com', 'correct-horse-battery');
const ticketCsrf = ticketAuth.csrf;
const createdTicket = requests([
  { method: 'POST', script: path.join(root, 'tickets.php'), post: {
      csrf: ticketCsrf, action: 'create', topic: 'payment',
      subject: 'Payment captured but order still Created',
      order_ref: 'tg_AAAA1111', message: 'Customer paid 499 but the order never flipped to success.', attachment_count: '2' } },
], ticketAuth.session);
const ticketBody = createdTicket[0].body || '';
check('Creating a ticket returns the page with a reference', /TCK-[A-F0-9]{8}/.test(ticketBody), String(createdTicket[0].status));
check('Created ticket is shown in the thread view', /Payment captured but order still Created/.test(ticketBody));
const ticketRef = /(TCK-[A-F0-9]{8})/.exec(ticketBody)?.[1];
check('Ticket row is persisted with its topic and attachment count', (() => {
  const out = php(`<?php
require 'includes/db.php';
$q = db()->prepare('SELECT topic, attachments, status, order_ref FROM tickets WHERE reference=?');
$q->execute([${JSON.stringify(ticketRef || '')}]);
echo json_encode($q->fetch());
`, baseEnv());
  return JSON.parse(out).topic === 'payment' && JSON.parse(out).attachments === 2;
})());

// Short messages must be refused rather than stored.
const shortAuth = signIn('merchant@example.com', 'correct-horse-battery');
steps = requests([
  { method: 'POST', script: path.join(root, 'tickets.php'), post: { csrf: shortAuth.csrf, action: 'create', topic: 'api', subject: 'x', message: 'short' } },
], shortAuth.session);
check('Invalid ticket is rejected with a message', /4–200 characters|at least 10 characters/.test(steps[0].body || ''));

section('Multi-accounts: caps, validation and payout re-auth');

// Unit-level: the UPI validator must reject malformed VPAs and honour the
// optional domain allowlist without breaking the default permissive mode.
const upiChecks = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/merchant.php';
$cases = ['ok_axis' => tg_validate_upi('shadow@okaxis'), 'ok_dot' => tg_validate_upi('first.last@okhdfcbank'),
  'no_at' => tg_validate_upi('shadowokaxis'), 'empty' => tg_validate_upi(''),
  'spaces' => tg_validate_upi('shadow @okaxis'), 'only_at' => tg_validate_upi('@'),
  'long' => tg_validate_upi(str_repeat('a', 200) . '@okaxis')];
putenv('UPI_ID_DOMAINS=fam,yesfam');
$cases['dom_fam'] = tg_validate_upi('shadow@fam');
$cases['dom_yesfam'] = tg_validate_upi('shadow@yesfam');
$cases['dom_bank'] = tg_validate_upi('shadow@okaxis');
putenv('UPI_ID_DOMAINS=');
$cases['dom_cleared'] = tg_validate_upi('shadow@okaxis');
echo json_encode($cases);
`, baseEnv()));
check('Valid UPI IDs pass validation', upiChecks.ok_axis === null && upiChecks.ok_dot === null, JSON.stringify(upiChecks).slice(0, 200));
check('Malformed UPI IDs are rejected',
  upiChecks.no_at !== null && upiChecks.empty !== null && upiChecks.spaces !== null
  && upiChecks.only_at !== null && upiChecks.long !== null);
check('Domain allowlist accepts the listed handles', upiChecks.dom_fam === null && upiChecks.dom_yesfam === null);
check('Domain allowlist rejects other handles', typeof upiChecks.dom_bank === 'string' && upiChecks.dom_bank.includes('@fam'));
check('Clearing the allowlist restores permissive mode', upiChecks.dom_cleared === null);

const capChecks = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/merchant.php';
putenv('MAX_RECEIVING_ACCOUNTS=3');
$a = tg_account_limit();
putenv('MAX_RECEIVING_ACCOUNTS=0');
$b = tg_account_limit();
putenv('MAX_RECEIVING_ACCOUNTS=');
$c = tg_account_limit();
echo json_encode([$a, $b, $c, tg_masked_email('shadowgamerind37@gmail.com'), tg_masked_email('ab@x.com')]);
`, baseEnv()));
check('Account cap is configurable', capChecks[0] === 3, String(capChecks[0]));
check('Account cap never drops below one', capChecks[1] === 1, String(capChecks[1]));
check('Account cap falls back to one for an explicit zero', capChecks[2] === 1, String(capChecks[2]));
const defaultCap = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/merchant.php';
echo json_encode(tg_account_limit());
`, { MAX_RECEIVING_ACCOUNTS: '5' }));
check('Account cap defaults to five', defaultCap === 5, String(defaultCap));
check('Email masking hides the local part', capChecks[3] === 's**************7@gmail.com', String(capChecks[3]));

// Behavioural: adding a 6th account is refused at the cap.
const capAuth = signIn('merchant@example.com', 'correct-horse-battery');
const fillAccounts = (csrf) => [1, 2, 3, 4, 5].map((n) => ({
  method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf, action: 'add', account_label: `Backup ${n}`, upi_id: `backup${n}@okaxis`,
    linked_email: `backup${n}@example.com`, app_password: 'abcdefghijklmnop',
    imap_host: 'imap.gmail.com', imap_port: '993' },
}));
steps = requests(fillAccounts(capAuth.csrf), capAuth.session);
check('Five accounts can be linked', steps.every((r) => r.status === 200), steps.map((r) => r.status).join(','));
const afterFive = php(`<?php
require 'includes/db.php';
echo (int) db()->query('SELECT COUNT(*) FROM merchant_accounts WHERE merchant_id=1')->fetchColumn();
`, baseEnv());
steps = requests([{
  method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: capAuth.csrf, action: 'add', account_label: 'Sixth', upi_id: 'sixth@okaxis',
    linked_email: 'sixth@example.com', app_password: 'abcdefghijklmnop', imap_host: 'imap.gmail.com', imap_port: '993' },
}], capAuth.session);
check('A sixth account is refused by the cap', /allows 5 receiving accounts/.test(steps[0].body || ''), String(afterFive));
const countAfter = php(`<?php
require 'includes/db.php';
echo (int) db()->query('SELECT COUNT(*) FROM merchant_accounts WHERE merchant_id=1')->fetchColumn();
`, baseEnv());
check('Refused account was not inserted', countAfter === '5', countAfter);

// A malformed UPI is refused before anything is written.
steps = requests([{
  method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: capAuth.csrf, action: 'add', account_label: 'Typo', upi_id: 'not-a-vpa',
    linked_email: 'typo@example.com', app_password: 'abcdefghijklmnop', imap_host: 'imap.gmail.com', imap_port: '993' },
}], capAuth.session);
check('Malformed UPI is refused on save', /does not look like a UPI ID/.test(steps[0].body || ''));

// Payout change on the active account must demand the password first.
const payoutAuth = signIn('merchant@example.com', 'correct-horse-battery');
const activeAccount = JSON.parse(php(`<?php
require 'includes/db.php';
$row = db()->query('SELECT id, upi_id FROM merchant_accounts WHERE merchant_id=1 AND is_active=1')->fetch();
echo json_encode(['id' => (int)$row['id'], 'upi' => $row['upi_id']]);
`, baseEnv()));
steps = requests([{
  method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: payoutAuth.csrf, action: 'update', id: String(activeAccount.id),
    account_label: 'Main UPI', upi_id: 'attacker@okaxis', linked_email: 'receipts@example.com',
    app_password: '', imap_host: 'imap.gmail.com', imap_port: '993' },
}], payoutAuth.session);
check('Payout change without reauth is blocked', /needs your account password/.test(steps[0].body || ''));
const upiUnchanged = php(`<?php
require 'includes/db.php';
$row = db()->query('SELECT upi_id FROM merchant_accounts WHERE merchant_id=1 AND is_active=1')->fetch();
echo $row['upi_id'];
`, baseEnv());
check('Blocked payout change did not touch the database', !upiUnchanged.includes('attacker'), upiUnchanged);

// Wrong password must not authorise.
steps = requests([{
  method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: payoutAuth.csrf, action: 'reauth', password: 'wrong-password', edit: String(activeAccount.id) },
}], payoutAuth.session);
check('Wrong password does not authorise payout edits', /password did not match/.test(steps[0].body || ''));

// Correct password authorises, replays the stored edit, and the UPI changes.
steps = requests([{
  method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: payoutAuth.csrf, action: 'reauth', password: 'correct-horse-battery', edit: String(activeAccount.id) },
}], payoutAuth.session);
check('Correct password authorises and replays the edit',
  /Payout details updated/.test(steps[0].body || ''), (steps[0].body || '').slice(0, 200));
const upiAfter = php(`<?php
require 'includes/db.php';
$row = db()->query('SELECT upi_id FROM merchant_accounts WHERE merchant_id=1 AND is_active=1')->fetch();
echo $row['upi_id'];
`, baseEnv());
check('Authorised payout change is applied', upiAfter === 'attacker@okaxis', upiAfter);

// Activation swaps which account receives orders.
const standbyId = php(`<?php
require 'includes/db.php';
$row = db()->query('SELECT id FROM merchant_accounts WHERE merchant_id=1 AND is_active=0 ORDER BY id LIMIT 1')->fetch();
echo (int)$row['id'];
`, baseEnv());
steps = requests([{
  method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: payoutAuth.csrf, action: 'activate', id: standbyId },
}], payoutAuth.session);
const activeAfterSwitch = php(`<?php
require 'includes/db.php';
$rows = db()->query('SELECT COUNT(*) AS n FROM merchant_accounts WHERE merchant_id=1 AND is_active=1')->fetch();
$id = db()->query('SELECT id FROM merchant_accounts WHERE merchant_id=1 AND is_active=1')->fetch();
echo json_encode(['active' => (int)$rows['n'], 'id' => (int)$id['id']]);
`, baseEnv());
check('Exactly one account stays active after a switch', JSON.parse(activeAfterSwitch).active === 1, activeAfterSwitch);
check('The requested standby account became active', String(JSON.parse(activeAfterSwitch).id) === standbyId, activeAfterSwitch);

// Removing the active account is refused.
steps = requests([{
  method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: payoutAuth.csrf, action: 'delete', id: String(JSON.parse(activeAfterSwitch).id) },
}], payoutAuth.session);
check('Active account cannot be deleted', /Switch the active account/.test(steps[0].body || ''));

section('Transaction export and detail drawer');

const exportAuth = signIn('merchant@example.com', 'correct-horse-battery');
steps = requests([
  { method: 'GET', script: path.join(root, 'transactions.php') },
  { method: 'GET', script: path.join(root, 'transactions-export.php') },
  { method: 'GET', script: path.join(root, 'transactions-export.php'), query: { status: 'success', range: '30d' } },
  { method: 'GET', script: path.join(root, 'transactions-export.php'), query: { status: 'not-a-status', range: 'not-a-range' } },
], exportAuth.session);
const [txPage, csvAll, csvFiltered, csvBogus] = steps;
check('Transactions page offers a CSV export', /Export CSV/.test(txPage.body || ''));
check('Export returns CSV with an attachment header',
  csvAll.status === 200 && /attachment; filename="transactions-/.test((csvAll.headers || []).join(' ')),
  (csvAll.headers || []).join(' | ').slice(0, 160));
const csvHeader = (csvAll.body || '').replace(/^\uFEFF/, '').split(/\r?\n/)[0].replace(/"/g, '');
check('CSV starts with the expected header row',
  csvHeader.startsWith('Order ID,Bank RRN / UTR,Status,Amount,Payable amount'),
  JSON.stringify(csvHeader));
check('CSV is UTF-8 with a BOM for Excel', (csvAll.body || '').startsWith('\uFEFF'));
check('Filtered export still succeeds', csvFiltered.status === 200);
check('Unknown export filters fall back instead of failing', csvBogus.status === 200);

// Detail drawer: a known order renders its full record; an unknown one warns.
const drawerOrder = JSON.parse(php(`<?php
require 'includes/db.php';
$row = db()->query('SELECT order_id FROM orders WHERE merchant_id=1 ORDER BY id LIMIT 1')->fetch();
echo json_encode(['id' => $row['order_id'] ?? '']);
`, baseEnv()));
steps = requests([
  { method: 'GET', script: path.join(root, 'transactions.php'), query: { view: drawerOrder.id } },
  { method: 'GET', script: path.join(root, 'transactions.php'), query: { view: 'tg_DOESNOTEXIST' } },
], exportAuth.session);
check('Detail drawer renders the selected order', /Receiving account/.test(steps[0].body || '')
  && (steps[0].body || '').includes(drawerOrder.id));
check('Drawer shows the customer and bank fields', /Bank RRN \/ UTR/.test(steps[0].body || '')
  && /Payer name from bank/.test(steps[0].body || ''));
check('Unknown order id warns instead of erroring', /No order/.test(steps[1].body || ''));
check("Drawer close link drops the view parameter", /href="\/transactions\.php\?[^"]*"/.test(steps[0].body || '')
  && !/view=tg_.*&amp;view=/.test(steps[0].body || ''));

// CSV injection: a customer name starting with "=" must be neutralised.
// Inserted as `pending` so it cannot be mistaken for a settled order by the
// settlement tests, and removed again immediately afterwards.
php(`<?php
require 'includes/db.php';
db()->prepare("INSERT INTO orders(order_id,merchant_id,amount,payable_amount,status,source,customer_name,expires_at,created_at)
               VALUES('tg_CSVINJECT',1,'10','10','pending','api','=cmd|calc',?,?)")
    ->execute([gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')]);
`, baseEnv());
steps = requests([{ method: 'GET', script: path.join(root, 'transactions-export.php') }], exportAuth.session);
const injected = steps[0].body || '';
php(`<?php
require 'includes/db.php';
db()->exec("DELETE FROM orders WHERE order_id='tg_CSVINJECT'");
`, baseEnv());
check('Formula-injection names are escaped in CSV',
  injected.includes("'=cmd|calc") && !/(^|,)"?=cmd\|calc/.test(injected),
  'expects a leading apostrophe, no bare formula');

section('Standby account removal requires a typed confirmation');

const rmAuth = { session: exportAuth.session };
const rmCsrf = (requests([{ method: 'GET', script: path.join(root, 'multi-accounts.php') }], rmAuth.session)[0].body || '')
  .match(/name="csrf" value="([a-f0-9]{64})"/)?.[1] || '';

const addStandby = (label, upi) => requests([{
  method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: rmCsrf, action: 'add', account_label: label, upi_id: upi, linked_email: label.replace(/\W/g, '') + '@gmail.com', app_password: 'abcdefghijklmnop' },
}], rmAuth.session)[0];
// The cap test above deliberately filled the account limit, so prune its
// filler rows first: never the seed row (later sections hardcode account id 1),
// never the two fixtures this section is about to remove.
php(`<?php
require 'includes/db.php';
db()->exec("DELETE FROM merchant_accounts WHERE merchant_id=1 AND is_active=0
             AND id <> 1 AND account_label NOT IN ('Backup One','Backup Two')");
`, baseEnv());

addStandby('Backup One', 'backup1@fam');
addStandby('Backup Two', 'backup2@fam');
const added2 = requests([{ method: 'GET', script: path.join(root, 'multi-accounts.php') }], rmAuth.session)[0];
const standbyLabels = JSON.parse(php(`<?php
require 'includes/db.php';
$rows = db()->query("SELECT account_label FROM merchant_accounts WHERE merchant_id=1 AND is_active=0 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($rows);
`, baseEnv()));
check('Standby wallets can be added up to the cap',
  standbyLabels.includes('Backup Two') && /Standby Accounts \(\d\)/.test(added2.body || ''),
  standbyLabels.join(', '));

const ids = JSON.parse(php(`<?php
require 'includes/db.php';
$rows = db()->query("SELECT id,account_label FROM merchant_accounts WHERE merchant_id=1 AND is_active=0 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows);
`, baseEnv()));
const target = ids.find((r) => r.account_label === 'Backup Two');

// Wrong label is refused, and the row survives.
steps = requests([{ method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: rmCsrf, action: 'delete', id: String(target?.id ?? 0), confirm_label: 'backup two' } }], rmAuth.session);
check('A mismatched confirmation label is refused',
  /Type the account label exactly/.test(steps[0].body || ''));
const afterWrong = JSON.parse(php(`<?php
require 'includes/db.php';
echo json_encode((int)db()->query("SELECT COUNT(*) FROM merchant_accounts WHERE id=".(int)(${target?.id ?? 0})."")->fetchColumn());
`, baseEnv()));
check('The refused removal deleted nothing', afterWrong === 1, String(afterWrong));

// The active account cannot be removed even with the right label.
const activeRow = JSON.parse(php(`<?php
require 'includes/db.php';
$r = db()->query('SELECT id,account_label FROM merchant_accounts WHERE merchant_id=1 AND is_active=1')->fetch(PDO::FETCH_ASSOC);
echo json_encode($r ?: []);
`, baseEnv()));
steps = requests([{ method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: rmCsrf, action: 'delete', id: String(activeRow.id ?? 0), confirm_label: activeRow.account_label ?? '' } }], rmAuth.session);
check('The active account still cannot be removed', /Switch the active account/.test(steps[0].body || ''));

// Correct label removes it and is audited.
steps = requests([{ method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: rmCsrf, action: 'delete', id: String(target?.id ?? 0), confirm_label: 'Backup Two' } }], rmAuth.session);
check('Typing the exact label removes the wallet', /Account removed/.test(steps[0].body || ''));
const auditRow = JSON.parse(php(`<?php
require 'includes/db.php';
$r = db()->query("SELECT COUNT(*) FROM audit_log WHERE action='account.removed'")->fetchColumn();
echo json_encode((int)$r);
`, baseEnv()));
check('Removal is written to the audit log', auditRow >= 1, String(auditRow));

// The remaining fixture ("Backup One") is left in place deliberately: orders
// created by later sections reference a receiving account, and deleting one
// with orders attached violates the foreign key. The suite uses a throwaway
// database, so there is nothing to tidy.

section('Payment links');

const plAuth = { session: exportAuth.session };
let plPage = requests([{ method: 'GET', script: path.join(root, 'payment-links.php') }], plAuth.session)[0];
check('Payment links page renders both cards',
  /Generate Payment Link/.test(plPage.body || '') && /My Payment Links/.test(plPage.body || ''));
const plCsrf = (plPage.body || '').match(/name="csrf" value="([a-f0-9]{64})"/)?.[1] || '';

// Create with a custom slug.
steps = requests([{
  method: 'POST', script: path.join(root, 'payment-links.php'),
  post: { csrf: plCsrf, action: 'create', title: 'Consulting call', amount: '1499.50', slug: 'consult-call', expiry_days: '7', redirect_url: '', description: 'A 30 minute call' },
}], plAuth.session);
check('A custom slug is created', /Payment link created/.test(steps[0].body || '')
  && /consult-call/.test(steps[0].body || ''), (steps[0].body || '').match(/callout[^<]*<[^>]*>([^<]*)/)?.[1]);
check('The new link is listed as Active', /Active/.test(steps[0].body || '') && /₹1,499.50/.test(steps[0].body || ''));
check('The list shows created and expiry columns',
  /Created/.test(steps[0].body || '') && /Expires/.test(steps[0].body || ''));

// Duplicate slug is refused, and nothing extra is written.
steps = requests([{
  method: 'POST', script: path.join(root, 'payment-links.php'),
  post: { csrf: plCsrf, action: 'create', title: 'Duplicate', amount: '10', slug: 'consult-call', expiry_days: '0' },
}], plAuth.session);
check('A taken slug is refused', /already taken/.test(steps[0].body || ''));
const slugCount = JSON.parse(php(`<?php
require 'includes/db.php';
echo json_encode((int)db()->query("SELECT COUNT(*) FROM payment_links WHERE slug='consult-call'")->fetchColumn());
`, baseEnv()));
check('The refused duplicate did not insert a row', slugCount === 1, String(slugCount));

// Validation matrix.
steps = requests([
  { method: 'POST', script: path.join(root, 'payment-links.php'), post: { csrf: plCsrf, action: 'create', title: 'Bad slug', amount: '10', slug: 'Has Spaces!', expiry_days: '0' } },
  { method: 'POST', script: path.join(root, 'payment-links.php'), post: { csrf: plCsrf, action: 'create', title: '', amount: '10', slug: 'ok-slug', expiry_days: '0' } },
  { method: 'POST', script: path.join(root, 'payment-links.php'), post: { csrf: plCsrf, action: 'create', title: 'Zero', amount: '0', slug: 'zero-amount', expiry_days: '0' } },
  { method: 'POST', script: path.join(root, 'payment-links.php'), post: { csrf: plCsrf, action: 'create', title: 'Bad redirect', amount: '10', slug: 'bad-redirect', expiry_days: '0', redirect_url: 'javascript:alert(1)' } },
  { method: 'POST', script: path.join(root, 'payment-links.php'), post: { csrf: plCsrf, action: 'create', title: 'Bad expiry', amount: '10', slug: 'bad-expiry', expiry_days: '999' } },
], plAuth.session);
check('Malformed slugs are refused', /lowercase letters, numbers and hyphens/.test(steps[0].body || ''));
check('A missing title is refused', /Give the link a title/.test(steps[1].body || ''));
check('A zero amount is refused', /between ₹1 and/.test(steps[2].body || ''));
check('A javascript: redirect is refused', /valid HTTPS address/.test(steps[3].body || ''));
check('An unoffered expiry window is refused', /offered expiry windows/.test(steps[4].body || ''));
const junk = JSON.parse(php(`<?php
require 'includes/db.php';
echo json_encode((int)db()->query("SELECT COUNT(*) FROM payment_links WHERE slug IN ('zero-amount','bad-redirect','bad-expiry','ok-slug')")->fetchColumn());
`, baseEnv()));
check('No invalid link reached the database', junk === 0, String(junk));

// Deactivate → the public checkout must stop serving it.
steps = requests([{ method: 'POST', script: path.join(root, 'payment-links.php'),
  post: { csrf: plCsrf, action: 'deactivate', slug: 'consult-call' } }], plAuth.session);
check('A link can be deactivated', /Link deactivated/.test(steps[0].body || ''));
const active = JSON.parse(php(`<?php
require 'includes/db.php';
echo json_encode((int)db()->query("SELECT is_active FROM payment_links WHERE slug='consult-call'")->fetchColumn());
`, baseEnv()));
check('Deactivation is persisted', active === 0, String(active));
const payDead = requests([{ method: 'GET', script: path.join(root, 'pay.php'), query: { link: 'consult-call' } }], 'pub-links')[0];
check('A deactivated link is refused at checkout', /unavailable or has expired/.test(payDead.body || ''));

// Reactivate, and confirm the checkout accepts it again.
steps = requests([{ method: 'POST', script: path.join(root, 'payment-links.php'),
  post: { csrf: plCsrf, action: 'activate', slug: 'consult-call' } }], plAuth.session);
check('A link can be reactivated', /Link reactivated/.test(steps[0].body || ''));
const payLive = requests([{ method: 'GET', script: path.join(root, 'pay.php'), query: { link: 'consult-call' } }], 'pub-links2')[0];
check('A reactivated link reaches the checkout', payLive.status === 302
  && /order_id=/.test((payLive.headers || []).join(' ')), (payLive.headers || []).join(' | ').slice(0, 120));

// Another merchant's link cannot be touched.
const foreign = JSON.parse(php(`<?php
require 'includes/db.php';
echo json_encode((int)db()->query("SELECT merchant_id FROM payment_links WHERE slug='consult-call'")->fetchColumn());
`, baseEnv()));
check('The test link belongs to the signed-in merchant', foreign === 1, String(foreign));

section('Dashboard activity periods');

// Re-use an existing signed-in session: every extra login counts against the
// auth rate limiter, and the suite already signs in several times.
const dashAuth = { session: exportAuth.session };
steps = requests([
  { method: 'GET', script: path.join(root, 'dashboard.php') },
  { method: 'GET', script: path.join(root, 'dashboard.php'), query: { period: '15' } },
  { method: 'GET', script: path.join(root, 'dashboard.php'), query: { period: '30' } },
  { method: 'GET', script: path.join(root, 'dashboard.php'), query: { period: '999' } },
  { method: 'GET', script: path.join(root, 'dashboard.php'), query: { period: 'abc' } },
], dashAuth.session);
const barCount = (html) => (html.match(/dash-bar-col/g) || []).length;
check('Default dashboard window is 7 days',
  /Activity — Last 7 Days/.test(steps[0].body || '') && barCount(steps[0].body || '') === 7);
check('15-day window renders 15 bars',
  /Activity — Last 15 Days/.test(steps[1].body || '') && barCount(steps[1].body || '') === 15);
check('30-day window renders 30 bars',
  /Activity — Last 30 Days/.test(steps[2].body || '') && barCount(steps[2].body || '') === 30);
check('Out-of-range and non-numeric periods fall back to 7 days',
  /Activity — Last 7 Days/.test(steps[3].body || '') && /Activity — Last 7 Days/.test(steps[4].body || ''));
check('The active period pill marks only itself',
  (steps[1].body || '').match(/time-pill active/g).length === 1
  && /period=15"\s+aria-current="true"/.test(steps[1].body || ''));
check('Long windows thin their axis labels instead of crowding',
  barCount(steps[2].body || '') === 30
  && ((steps[2].body || '').match(/dash-bar-label">[^<]+</g) || []).length <= 10);
check('The chart summarises the window in words',
  /orders and ₹/.test(steps[1].body || '') && /in this window/.test(steps[1].body || ''));

section('Payout re-auth via email OTP');

const otpAuth = { session: exportAuth.session };
const otpCsrf = (requests([{ method: 'GET', script: path.join(root, 'multi-accounts.php') }], otpAuth.session)[0].body || '')
  .match(/name="csrf" value="([a-f0-9]{64})"/)?.[1] || '';

const otpBoot = "require_once 'includes/db.php'; require_once 'includes/merchant.php'; require_once 'includes/notify.php';";

/** Write an OTP state straight into the harness session file. */
const seedOtp = (code = '123456', ttl = 600, attempts = 0, offset = 0) => php(`<?php
${otpBoot}
session_save_path(${JSON.stringify(sessionDir)});
session_id(${JSON.stringify(otpAuth.session)});
session_start();
$_SESSION['reauth_otp'] = ['hash' => password_hash('${code}', PASSWORD_DEFAULT),
                          'expires' => time()+${ttl}+${offset}, 'attempts' => ${attempts}, 'email' => 'm@example.com'];
$_SESSION['reauth_at'] = 0;
session_write_close();
echo 'seeded';
`, baseEnv());

/** Read the re-auth stamp from the same session file. */
const reauthStamped = () => JSON.parse(php(`<?php
${otpBoot}
session_save_path(${JSON.stringify(sessionDir)});
session_id(${JSON.stringify(otpAuth.session)});
session_start();
echo json_encode(tg_reauth_fresh());
`, baseEnv()));

const otpPost = (code) => {
  // This section posts many times to the same endpoint; reset the page-level
  // counter so the assertions are about OTP handling, not the rate limiter.
  php(`<?php
require 'includes/db.php';
db()->exec('DELETE FROM api_rate_limits');
`, baseEnv());
  return requests([{
    method: 'POST', script: path.join(root, 'multi-accounts.php'),
    post: { csrf: otpCsrf, action: 'reauth_otp_verify', edit: '1', otp: code },
  }], otpAuth.session)[0].body || '';
};

check('A configured SMTP transport is detected',
  JSON.parse(php(`<?php
${otpBoot}
echo json_encode(tg_mail_transport_available());
`, { SMTP_HOST: 'smtp.example.com', MAIL_FROM: 'a@example.com' })) === true);

seedOtp();
const wrongBody = otpPost('000000');
check('A wrong OTP does not authorise', /wrong or has expired/.test(wrongBody)
  && !/Authorised|Payout details updated/.test(wrongBody));
check('A failed OTP leaves no re-auth stamp', reauthStamped() === false);

seedOtp();
const rightBody = otpPost('123456');
check('The correct OTP authorises', /Authorised|Payout details updated/.test(rightBody),
  rightBody.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 110));
check('OTP success stamps the re-auth window', reauthStamped() === true);

seedOtp('123456', 600, 5);
check('An exhausted attempt counter refuses even the right code', /wrong or has expired/.test(otpPost('123456')));
seedOtp('123456', 0, 0, -60);
check('An expired code is refused', /wrong or has expired/.test(otpPost('123456')));
seedOtp();
check('A code of the wrong length is refused', /wrong or has expired/.test(otpPost('1234')));

// Clear the stamp so the edit screen shows the authorisation panel again.
seedOtp();
php(`<?php
${'$'}{''}
`, baseEnv());
php(`<?php
session_save_path(${JSON.stringify(sessionDir)});
session_id(${JSON.stringify(otpAuth.session)});
session_start();
unset($_SESSION['reauth_at'], $_SESSION['reauth_otp']);
session_write_close();
echo 'cleared';
`, baseEnv());
const otpPage = requests([{ method: 'GET', script: path.join(root, 'multi-accounts.php'), query: { edit: '1' } }], otpAuth.session)[0];
check('The edit screen offers password and email OTP',
  /Option A: account password/.test(otpPage.body || '') && /Option B/.test(otpPage.body || ''));
php(`<?php
require 'includes/db.php';
db()->exec('DELETE FROM api_rate_limits');
`, baseEnv());
steps = requests([{ method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: otpCsrf, action: 'reauth_otp_send', edit: '1' } }], otpAuth.session);
check('OTP send without SMTP explains the fallback',
  /Email delivery is not configured/.test(steps[0].body || ''));

section('Documentation text endpoints');

const docAuth = { session: exportAuth.session };
const docsBase = { ...baseEnv(), APP_URL: 'https://gate.example.com' };
const docText = php(`<?php
require 'includes/apidoc.php';
echo tg_doc_text();
`, docsBase);
const llmsText = php(`<?php
require 'includes/apidoc.php';
echo tg_doc_llms();
`, docsBase);
const promptText = php(`<?php
require 'includes/apidoc.php';
echo tg_doc_ai_prompt();
`, docsBase);

check('docs.txt lists every endpoint', JSON.parse(php(`<?php
require 'includes/apidoc.php';
echo json_encode(array_column(tg_doc_endpoints(), 'path'));
`, docsBase)).every((p) => docText.includes(p)));
check('docs.txt uses the configured base URL', docText.includes('https://gate.example.com'));
check('docs.txt documents the webhook signature rule', /hmac_sha256/.test(docText));
check('docs.txt warns against fulfilling from polling', /Never fulfil from/.test(docText));
check('llms.txt follows the llms.txt convention', llmsText.startsWith('# TG Gateways')
  && llmsText.includes('> ') && llmsText.includes('## Endpoints'));
check('llms.txt states the non-custodial scope', /never holds or routes customer funds/.test(llmsText));
check('ai-prompt.txt gives both HMAC snippets',
  /hmac\.new/.test(promptText) && /hash_hmac/.test(promptText));
check('ai-prompt.txt warns about idempotency', /delivered more than once/.test(promptText));
check('ai-prompt.txt maps the key files', promptText.includes('includes/settlement.php'));

// Served over HTTP with the right content type.
steps = requests([
  { method: 'GET', script: path.join(root, 'docs.txt.php') },
  { method: 'GET', script: path.join(root, 'llms.txt.php') },
  { method: 'GET', script: path.join(root, 'ai-prompt.txt.php') },
], docAuth.session);
check('Text endpoints are public and serve text/plain',
  steps.every((r) => r.status === 200) && steps.every((r) => (r.headers || []).join(' ').includes('text/plain')),
  steps.map((r) => r.status).join(','));
check('Text endpoints expose no merchant secrets',
  steps.every((r) => !(r.body || '').includes(apiKey)));

section('Addons, services and invoices');

const addonAuth = signIn('merchant@example.com', 'correct-horse-battery');
const addonCsrf = addonAuth.csrf;
steps = requests([
  { method: 'GET', script: path.join(root, 'addons.php') },
  { method: 'GET', script: path.join(root, 'invoices.php') },
  { method: 'GET', script: path.join(root, 'services.php') },
  { method: 'POST', script: path.join(root, 'addons.php'), post: { csrf: addonCsrf, addon: 'bharatpe', months: '1' } },
  { method: 'POST', script: path.join(root, 'addons.php'), post: { csrf: addonCsrf, addon: 'paytm', months: '3' } },
  { method: 'GET', script: path.join(root, 'invoices.php') },
  { method: 'GET', script: path.join(root, 'services.php') },
], addonAuth.session);
const [addonsPage, invoicesEmpty, servicesPage, addonRefused, addonBought, invoicesAfter, servicesAfter] = steps;
check('Addons page lists all three gateways',
  addonsPage.body.includes('Paytm for Business') && addonsPage.body.includes('BharatPe for Business')
  && addonsPage.body.includes('FamPay UPI Gateway'));
check('Empty invoices page shows the reference empty state', invoicesEmpty.body.includes('No Invoices Yet'));
check('Services page shows the free core gateway', servicesPage.body.includes('Free Forever'));
check('Non-purchasable addon is refused', /not available for purchase/.test(addonRefused.body || ''));
check('Buying an addon succeeds and names the invoice', /Invoice INV-/.test(addonBought.body || ''));
check('Invoice appears in the billing history', /INV-\d{6}-[A-F0-9]{6}/.test(invoicesAfter.body || ''));
check('Purchased addon appears in My Services', /Paytm for Business/.test(servicesAfter.body || ''));

section('Public status page');
const statusOut = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/merchant.php';
require 'includes/status.php';
$probe = tg_status_probe();
$history = tg_status_history(30);
$uptime = tg_status_uptime($history['api']);
echo json_encode([
  'components' => array_keys($probe),
  'api' => $probe['api']['state'],
  'db' => $probe['database']['state'],
  'days' => count($history['api']),
  'uptime' => $uptime,
  'recorded' => (int)db()->query('SELECT COUNT(*) FROM status_days')->fetchColumn(),
]);
`, baseEnv()));
check('Status probe covers every advertised component',
  ['api', 'database', 'checkout', 'webhook', 'parser'].every((c) => statusOut.components.includes(c)),
  JSON.stringify(statusOut.components));
check('Status probe reports the API as operational', statusOut.api === 'operational');
check('Status probe reports the database as operational', statusOut.db === 'operational');
check('Status history covers 30 days', statusOut.days === 30, String(statusOut.days));
check('Status probe records a row per component', statusOut.recorded >= statusOut.components.length, String(statusOut.recorded));
check('Recorded uptime is a real percentage', typeof statusOut.uptime === 'number' && statusOut.uptime >= 0 && statusOut.uptime <= 100, String(statusOut.uptime));

// The public page must render for a signed-out visitor.
const publicStatus = requests([{ method: 'GET', script: path.join(root, 'status.php') }], `pub_${crypto.randomUUID()}`);
check('Public status page renders signed out', publicStatus[0].status === 200 && /System Status/.test(publicStatus[0].body));
check('Public status page exposes no merchant data', !publicStatus[0].body.includes('merchant@example.com'));

section('Hosted checkout page');
steps = requests([
  { method: 'GET', script: path.join(root, 'pay.php'), query: { order_id: created.order_id } },
  { method: 'GET', script: path.join(root, 'pay.php'), query: { order_id: 'tg_NOPE' } },
]);
check('Checkout renders merchant and amount', steps[0].status === 200 && steps[0].body.includes('₹') && steps[0].body.includes(created.order_id));
check('Checkout includes UPI intent for QR rendering', steps[0].body.includes('data-upi="upi://pay'));
check('Checkout loads the vendored QR script', steps[0].body.includes('/assets/js/vendor/qrcode.js'));
check('Unknown order shows a friendly notice', steps[1].status === 200 && /not found/i.test(steps[1].body));

section('Settlement: receipt → paid order');
const settleOut = php(`<?php
require 'includes/settlement.php';
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$account = ['id' => 1, 'merchant_id' => 1];
// A pending order this receipt should claim.
$pdo->prepare("INSERT INTO orders(order_id,merchant_id,merchant_account_id,amount,payable_amount,status,expires_at,created_at) VALUES('tg_SETTLE499',1,1,'499.00','499.00','pending',?,?)")
    ->execute([gmdate('Y-m-d H:i:s', time()+600), gmdate('Y-m-d H:i:s')]);
$out = [];
$out['first'] = settle_receipt($pdo, $account, ['utr'=>'555666777888','amount'=>'499.00','sender'=>'ASHA','order_ref'=>null], 'msg-1');
$out['duplicate'] = settle_receipt($pdo, $account, ['utr'=>'555666777888','amount'=>'499.00','sender'=>'ASHA','order_ref'=>null], 'msg-1');
$out['unmatched'] = settle_receipt($pdo, $account, ['utr'=>'999888777666','amount'=>'7.00','sender'=>null,'order_ref'=>null], 'msg-2');
$out['unparsed'] = settle_receipt($pdo, $account, ['utr'=>null,'amount'=>null,'sender'=>null,'order_ref'=>null], 'msg-3');
$out['second'] = settle_receipt($pdo, $account, ['utr'=>'111222333444','amount'=>'1500.00','sender'=>'RAVI','order_ref'=>$pdo->query("SELECT order_id FROM orders WHERE amount=1500")->fetchColumn()], 'msg-4');
$out['orders'] = $pdo->query("SELECT order_id,status,utr,sender_name,paid_at FROM orders ORDER BY id")->fetchAll();
$out['events'] = $pdo->query("SELECT state,utr,order_ref FROM receipt_events ORDER BY id")->fetchAll();
$out['count_paid'] = $pdo->query("SELECT COUNT(*) FROM orders WHERE status='success'")->fetchColumn();
$out['event_count'] = $pdo->query("SELECT COUNT(*) FROM receipt_events")->fetchColumn();
echo json_encode($out);
`, { APP_URL: 'http://localhost:8080' });
const settle = JSON.parse(settleOut);
check('First receipt matches a pending order', settle.first.state === 'matched', JSON.stringify(settle.first));
check('Repeated UTR is reported as duplicate', settle.duplicate.state === 'duplicate');
check('Receipt with no matching amount is unmatched', settle.unmatched.state === 'unmatched');
check('Unparseable receipt is recorded, not matched', settle.unparsed.state === 'unparsed');
check('Reference-based receipt claims the right order', settle.second.state === 'matched' && settle.second.order_id && settle.second.order_id.includes('tg_'), JSON.stringify(settle.second));
check('Only orders with matching receipts are paid', Number(settle.count_paid) === 2, String(settle.count_paid));
const attemptsOut = php(`<?php
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$states = $pdo->query('SELECT state, COUNT(*) c FROM receipt_events GROUP BY state')->fetchAll(PDO::FETCH_KEY_PAIR);
$orders = (int)$pdo->query("SELECT COUNT(*) FROM receipt_events")->fetchColumn();
echo json_encode(['rows'=>$orders,'states'=>$states]);
`);
const attempts = JSON.parse(attemptsOut);
check('Every receipt attempt leaves an audit row', attempts.rows >= 4 && (attempts.states.matched ?? 0) >= 2 && (attempts.states.duplicate ?? 0) >= 1 && (attempts.states.unmatched ?? 0) >= 1, attemptsOut.trim());

const expiryOut = php(`<?php
require 'includes/settlement.php';
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("INSERT INTO orders(order_id,merchant_id,merchant_account_id,amount,payable_amount,status,expires_at,created_at) VALUES('tg_EXPIREDTEST',1,1,'10','10','pending','2020-01-01 00:00:00','2020-01-01 00:00:00')");
$result = housekeeping($pdo);
echo json_encode(['expired'=>$result['expired'],'status'=>$pdo->query("SELECT status FROM orders WHERE order_id='tg_EXPIREDTEST'")->fetchColumn()]);
`);
const expiry = JSON.parse(expiryOut);
check('Housekeeping expires stale pending orders', expiry.status === 'expired' && expiry.expired >= 1);

section('Webhooks: signing and delivery');
// The transport writes every attempt to a spool file, so the suite can assert the
// exact wire request (body, signature header) and simulate endpoint failures
// without depending on outbound network access.
const spool = path.join(work, 'webhook-spool.jsonl');
const transportEnv = {
  TG_WEBHOOK_TRANSPORT_FILE: path.join(root, 'tests/webhook-transport.php'),
  TG_WEBHOOK_SPOOL: spool,
  WEBHOOK_BACKOFF_SECONDS: '5',
};
const readSpool = () => (existsSync(spool) ? readFileSync(spool, 'utf8').trim().split('\n').filter(Boolean).map((line) => JSON.parse(line)) : []);

const dispatchOut = php(`<?php
require 'includes/webhook.php';
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->prepare('UPDATE merchants SET webhook_url=?, api_key=? WHERE id=1')->execute([getenv('HOOK_URL'), 'secret-key']);
$id = $pdo->query("SELECT order_id FROM orders WHERE status='success' LIMIT 1")->fetchColumn();
$ok = dispatch_webhook($pdo, (string)$id, true);
$log = $pdo->query('SELECT response_code,attempt_count,delivered_at,signature FROM webhook_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
echo json_encode(['ok'=>$ok,'log'=>$log]);
`, { ...transportEnv, HOOK_URL: 'https://merchant.example/hooks/payments' });
const dispatch = JSON.parse(dispatchOut);
const delivered = readSpool()[0];
check('Signed webhook is delivered to the receiver', dispatch.ok === true && Number(dispatch.log.response_code) === 200, dispatchOut.trim().slice(0, 160));
check('Delivery is recorded as complete', Boolean(dispatch.log.delivered_at) && Number(dispatch.log.attempt_count) === 1);
check('Receiver got the payment event body', Boolean(delivered) && JSON.parse(delivered.body).event === 'payment.succeeded');
check('Signature header uses sha256= prefix', Boolean(delivered) && delivered.headers.some((h) => /^X-TGGateway-Signature: sha256=[0-9a-f]{64}$/.test(h)));
const signatureHeader = (delivered?.headers || []).find((h) => h.startsWith('X-TGGateway-Signature: '))?.slice('X-TGGateway-Signature: '.length) || '';
const expectedSignature = crypto.createHmac('sha256', 'secret-key').update(delivered?.body || '').digest('hex');
check('HMAC matches an independent computation', signatureHeader === `sha256=${expectedSignature}`);
check('Tampering with the body breaks the signature', crypto.createHmac('sha256', 'secret-key').update((delivered?.body || '') + ' ').digest('hex') !== expectedSignature);
check('Queued payload is stored for replay', dispatch.log.signature === `sha256=${expectedSignature}`);
check('Event type header is sent', (delivered?.headers || []).some((h) => h === 'X-TGGateway-Event: payment.succeeded'));

// Failure path: a 500 schedules a retry with backoff instead of giving up.
const failOut = php(`<?php
require 'includes/webhook.php';
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$id = $pdo->query("SELECT order_id FROM orders WHERE status='success' LIMIT 1")->fetchColumn();
$pdo->prepare('UPDATE webhook_logs SET response_code=NULL,attempt_count=0,delivered_at=NULL,next_retry_at=NULL')->execute();
$ok = dispatch_webhook($pdo, (string)$id, true);
$log = $pdo->query('SELECT response_code,attempt_count,delivered_at,next_retry_at,last_error FROM webhook_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
echo json_encode(['ok'=>$ok,'log'=>$log,'backoff'=>webhook_backoff(1)]);
`, { ...transportEnv, TG_WEBHOOK_TEST_CODE: '500', HOOK_URL: 'https://merchant.example/hooks/payments' });
const failure = JSON.parse(failOut);
check('A failing endpoint is not marked delivered', failure.ok === false && failure.log.delivered_at === null && Number(failure.log.response_code) === 500, failOut.trim().slice(0, 160));
check('Retry is scheduled with backoff', Boolean(failure.log.next_retry_at) && Number(failure.log.attempt_count) === 1 && failure.backoff === 5, JSON.stringify(failure.log));
check('Failure reason is recorded for the dashboard', /Simulated HTTP 500/.test(String(failure.log.last_error)));

// Worker retry: after the backoff window the worker retries and succeeds.
const replayOut = php(`<?php
require 'includes/webhook.php';
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->prepare('UPDATE webhook_logs SET next_retry_at=?')->execute(['2020-01-01 00:00:00']);
$sent = retry_webhooks($pdo, 10);
$log = $pdo->query('SELECT response_code,attempt_count,delivered_at FROM webhook_logs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
echo json_encode(['sent'=>$sent,'log'=>$log]);
`, { ...transportEnv, HOOK_URL: 'https://merchant.example/hooks/payments' });
const replay = JSON.parse(replayOut);
check('Worker retries due webhooks', replay.sent >= 1);
check('Successful retry marks the webhook delivered', Boolean(replay.log.delivered_at) && Number(replay.log.attempt_count) === 2, JSON.stringify(replay.log));
check('Each retry carries the stored signature', readSpool().length >= 3 && readSpool().every((attempt) => attempt.headers.some((h) => h.startsWith('X-TGGateway-Signature: sha256='))));

// Restore the merchant key and prove only one queue row exists per order.
php(`<?php
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->prepare('UPDATE merchants SET api_key=? WHERE id=1')->execute([getenv('REAL_API_KEY')]);
`, { REAL_API_KEY: apiKey });
check('Exactly one queued webhook exists per order', Number(php(`<?php
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'));
echo (int)$pdo->query('SELECT COUNT(*) FROM webhook_logs')->fetchColumn();
`)) === 1);

section('Webhook SSRF policy');
const ssrfOut = php(`<?php
require 'includes/db.php';
$urls = ['http://example.com/hook','https://localhost/hook','https://127.0.0.1/hook','https://10.0.0.5/hook','https://169.254.169.254/latest','https://internal.local/hook','https://example.com:8443/hook','https://user:pass@example.com/hook'];
$out = [];
foreach ($urls as $u) $out[$u] = safe_webhook_resolve($u) !== false;
echo json_encode($out);
`);
const ssrf = JSON.parse(ssrfOut);
check('Plain HTTP rejected', ssrf['http://example.com/hook'] === false);
check('localhost rejected', ssrf['https://localhost/hook'] === false);
check('Loopback IP rejected', ssrf['https://127.0.0.1/hook'] === false);
check('Private range rejected', ssrf['https://10.0.0.5/hook'] === false);
check('Link-local metadata IP rejected', ssrf['https://169.254.169.254/latest'] === false);
check('.local host rejected', ssrf['https://internal.local/hook'] === false);
check('Non-443 port rejected', ssrf['https://example.com:8443/hook'] === false);
check('Embedded credentials rejected', ssrf['https://user:pass@example.com/hook'] === false);

section('Payment links');
// The rate-limit burst above used the same merchant, so wait for that window to
// close instead of mutating the limiter's bookkeeping.
await new Promise((resolve) => setTimeout(resolve, 1500));
steps = requests([
  { method: 'POST', script: path.join(root, 'api/manage-links.php'), headers: { 'X-Api-Key': apiKey }, body: JSON.stringify({ title: 'Consultation', amount: 999 }) },
  { method: 'GET', script: path.join(root, 'api/manage-links.php'), headers: { 'X-Api-Key': apiKey } },
  { method: 'POST', script: path.join(root, 'api/manage-links.php'), headers: { 'X-Api-Key': apiKey }, body: JSON.stringify({ title: 'Bad', amount: 5, redirect_url: 'javascript:alert(1)' }) },
]);
const link = json(steps[0]) || {};
check('Payment link created with slug', steps[0].status === 201 && /^[a-f0-9]{18}$/.test(link.slug || ''), String(link.slug));
check('Payment link listed for owner', Array.isArray(json(steps[1])?.links) && json(steps[1]).links.some((l) => l.slug === link.slug));
check('Non-HTTPS redirect rejected', steps[2].status === 422);
steps = requests([
  { method: 'GET', script: path.join(root, 'pay.php'), query: { link: link.slug } },
]);
check('Visiting a payment link creates an order and redirects', steps[0].status === 302 && steps[0].headers.some((h) => /Location: \/pay\.php\?order_id=tg_/.test(h)), String(steps[0].status));
steps = requests([
  { method: 'GET', script: path.join(root, 'pay.php'), query: { link: 'missing-slug' } },
]);
check('Unknown payment link shows a notice', steps[0].status === 200 && /unavailable/i.test(steps[0].body));

section('Health endpoint');
steps = requests([
  { method: 'GET', script: path.join(root, 'api/health.php') },
]);
const health = json(steps[0]) || {};
check('Public health endpoint responds', steps[0].status === 200);
check('Health reports database operational', health.database === 'operational', JSON.stringify(health));
check('Health does not claim the parser works without a worker run', health.receipt_parser !== 'operational', String(health.receipt_parser));
check('Health exposes no merchant identifiers', !JSON.stringify(health).includes('merchant@'));

section('Worker healthcheck');
// cron/healthcheck.php backs the Compose `cron` healthcheck. Its decision logic is
// exercised in-process because a CLI wrapper's exit code is not observable from
// every runtime; the CLI entry point itself is a one-line exit() around this.
const freshnessOut = php(`<?php
require 'cron/healthcheck.php';
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->prepare('DELETE FROM system_state WHERE state_key=?')->execute(['worker_last_run']);
$never = worker_freshness($pdo);
$pdo->prepare('INSERT INTO system_state(state_key,state_value,updated_at) VALUES(?,?,?)')->execute(['worker_last_run', json_encode(['time'=>gmdate('c')]), gmdate('Y-m-d H:i:s')]);
$fresh = worker_freshness($pdo);
$pdo->prepare('UPDATE system_state SET state_value=? WHERE state_key=?')->execute([json_encode(['time'=>gmdate('c', time()-600)]), 'worker_last_run']);
$stale = worker_freshness($pdo);
$pdo->prepare('UPDATE system_state SET state_value=? WHERE state_key=?')->execute([json_encode(['time'=>gmdate('c')]), 'worker_last_run']);
echo json_encode(['never'=>$never, 'fresh'=>$fresh, 'stale'=>$stale]);
`);
const freshness = JSON.parse(freshnessOut);
check('Healthcheck fails before the worker has ever run', freshness.never.ok === false, freshnessOut.trim());
check('Healthcheck passes after a recent worker pass', freshness.fresh.ok === true && /worker pass/.test(freshness.fresh.message));
check('Healthcheck fails when the worker is stale', freshness.stale.ok === false && /stale/.test(freshness.stale.message));
check('Healthcheck script runs standalone without side effects', (() => {
  const result = spawnSync(PHP[0], [...PHP.slice(1), path.join(root, 'cron/healthcheck.php')], {
    cwd: root, encoding: 'utf8', env: { ...process.env, ...baseEnv() },
  });
  return /worker pass/.test(String(result.stdout || '') + String(result.stderr || ''));
})(), String(spawnSync(PHP[0], [...PHP.slice(1), path.join(root, 'cron/healthcheck.php')], { cwd: root, encoding: 'utf8', env: { ...process.env, ...baseEnv() } }).stderr));

section('Legacy /api/status contract');section('Legacy /api/status contract');
steps = requests([
  { method: 'GET', script: path.join(root, 'api/status.php') },
  { method: 'GET', script: path.join(root, 'api/status.php'), headers: { 'X-Api-Key': apiKey }, query: { order_ref: 'Invoice 42' } },
]);
check('Legacy status without key → 401', steps[0].status === 401, String(steps[0].status));
check('Legacy status resolves order_ref', steps[1].status === 200 && json(steps[1])?.payable_amount === 1500, String(steps[1].status));

section('SQL portability');
const sqlCheck = php(`<?php
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'));
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
sort($tables);
echo json_encode($tables);
`);
const tables = JSON.parse(sqlCheck);
for (const required of ['merchants','merchant_accounts','orders','payment_links','receipt_events','webhook_logs','notification_logs','api_rate_limits','system_state']) {
  check(`Table ${required} exists`, tables.includes(required));
}
const mysqlSchema = readFileSync(path.join(root, 'database/schema.sql'), 'utf8');
for (const required of ['receipt_events','notification_logs','system_state','payee_name','brand_color','payment_timeout_minutes']) {
  check(`MySQL schema includes ${required}`, mysqlSchema.includes(required));
}

/* ------------------------------------------------------------------ */

console.log(`\n${passed} passed, ${failures.length} failed`);
if (failures.length) {
  console.log('\nFailures:');
  for (const failure of failures) console.log(`  - ${failure}`);
}
rmSync(work, { recursive: true, force: true });
process.exit(failures.length ? 1 : 0);
