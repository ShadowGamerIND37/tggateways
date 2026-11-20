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
import { mkdtempSync, writeFileSync, readFileSync, rmSync, existsSync, readdirSync } from 'node:fs';
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
    SMTP_HOST: '',
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
/** Extracts a cookie value the response tried to set, or null. */
function setCookie(response, name) {
  const headers = response.headers || [];
  for (const header of headers) {
    // Match the name at the start of the value, after the "Set-Cookie: "
    // prefix or a "; " separator. Anchoring with ^ alone misses the prefix.
    const m = new RegExp(`(?:^Set-Cookie:\\s*|;\\s*)${name}=([^;]*)`, 'i').exec(header);
    if (m && m[1] !== '') return m[1];
  }
  return null;
}

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
    cookies: step.cookies || {},
    files: step.files || [],
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

// ============================================================
// Admin panel
// ------------------------------------------------------------
// The panel is only worth having if its gates actually hold, so these
// checks are about refusal as much as success: an unauthenticated visit
// must bounce, a merchant session must not be enough, the seeded owner
// must exist exactly once, and the last owner must not be able to
// delete or demote themselves.
// ============================================================
section('Admin panel');

const adminSeed = JSON.parse(php(`<?php
require 'includes/db.php';
$pdo = db();
$row = $pdo->query("SELECT id,email,role,status,must_change_password FROM admins ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo json_encode(['rows' => (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn(), 'row' => $row ?: null]);
`, baseEnv()));
check('First run seeds exactly one administrator',
  adminSeed.rows === 1 && adminSeed.row?.email === 'admin', JSON.stringify(adminSeed));
check('The seeded administrator is a flagged owner',
  adminSeed.row?.role === 'owner' && adminSeed.row?.status === 'active'
  && Number(adminSeed.row?.must_change_password) === 1,
  JSON.stringify(adminSeed.row));

const seedPasswordOk = JSON.parse(php(`<?php
require 'includes/db.php';
$hash = (string)db()->query('SELECT password FROM admins ORDER BY id LIMIT 1')->fetchColumn();
echo json_encode(['admin_admin' => password_verify('admin', $hash), 'wrong' => password_verify('notadmin', $hash)]);
`, baseEnv()));
check('The default credential is admin/admin', seedPasswordOk.admin_admin === true && seedPasswordOk.wrong === false);

// Re-running the seeder must not add a second account or reset a changed password.
const reseed = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/admin.php';
require 'includes/crypto.php';
$pdo = db();
$pdo->exec("UPDATE admins SET password='" . password_hash('rotated-secret', PASSWORD_DEFAULT) . "' WHERE id=(SELECT MIN(id) FROM admins)");
tg_admin_seed_default();
$hash = (string)$pdo->query('SELECT password FROM admins ORDER BY id LIMIT 1')->fetchColumn();
echo json_encode(['count' => (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn(), 'kept_rotation' => password_verify('rotated-secret', $hash)]);
`, baseEnv()));
check('Seeding runs once and never resets a rotated password',
  reseed.count === 1 && reseed.kept_rotation === true, JSON.stringify(reseed));
// Put the documented default back for the remainder of the section.
php(`<?php
require 'includes/db.php';
db()->prepare('UPDATE admins SET password=?, must_change_password=1 WHERE id=(SELECT MIN(id) FROM admins)')
    ->execute([password_hash('admin', PASSWORD_DEFAULT)]);
`, baseEnv());

// --- The gate ---
const adminGate = requests([{ method: 'GET', script: path.join(root, 'admin/index.php') }], `adm_${crypto.randomUUID()}`);
check('The admin area redirects an anonymous visitor',
  adminGate[0].status === 302 && /\/admin\/login\.php/.test((adminGate[0].headers || []).join(' ')),
  `${adminGate[0].status} ${(adminGate[0].headers || []).join(' ').slice(0, 120)}`);

// A merchant session must not open the panel: the two auth systems share no state.
const merchantSessionForAdmin = signIn('merchant@example.com', 'correct-horse-battery');
const merchantOnAdmin = requests([{ method: 'GET', script: path.join(root, 'admin/index.php') }], merchantSessionForAdmin.session);
check('A merchant session cannot open the admin panel',
  merchantOnAdmin[0].status === 302 && /\/admin\/login\.php/.test((merchantOnAdmin[0].headers || []).join(' ')),
  `${merchantOnAdmin[0].status}`);

// --- Signing in ---
const adminSession = `adm_${crypto.randomUUID()}`;
const gatePage = requests([{ method: 'GET', script: path.join(root, 'admin/login.php') }], adminSession);
let adminCsrf = /name="csrf" value="([a-f0-9]{64})"/.exec(gatePage[0].body || '')?.[1];
check('The admin gate renders a CSRF-protected form', Boolean(adminCsrf), (gatePage[0].body || '').slice(0, 80));

const wrongAdmin = requests([{
  method: 'POST', script: path.join(root, 'admin/login.php'),
  post: { csrf: adminCsrf, email: 'admin', password: 'definitely-wrong' },
}], adminSession);
check('A wrong admin password is refused',
  wrongAdmin[0].status === 200 && /did not match an administrator/.test(wrongAdmin[0].body || '')
  && !setCookie(wrongAdmin[0], 'tg_admin_session'),
  String(wrongAdmin[0].status));

const adminLogin = requests([{
  method: 'POST', script: path.join(root, 'admin/login.php'),
  post: { csrf: adminCsrf, email: 'admin', password: 'admin' },
}], adminSession);
const adminToken = setCookie(adminLogin[0], 'tg_admin_session');
check('The seeded credential signs in',
  adminLogin[0].status === 302 && Boolean(adminToken), `${adminLogin[0].status} token=${adminToken ? 'yes' : 'no'}`);
// The cookie a sign-in sets must survive the browser's session. Emitting
// Max-Age=0 (or any past Expires) on a login makes the browser delete the
// cookie immediately, which looks like "login worked but nothing is saved".
const loginCookieHeader = (adminLogin[0].headers || []).find((h) => /^Set-Cookie:.*tg_admin_session=/.test(h)) || '';
check('The sign-in cookie is not self-deleting',
  loginCookieHeader !== '' && !/Max-Age=0\b/i.test(loginCookieHeader)
  && !/Expires=Thu, 01 Jan 1970/i.test(loginCookieHeader),
  loginCookieHeader.slice(0, 140));

const adminCookies = { tg_admin_session: adminToken || '' };
const adminHome = requests([{ method: 'GET', script: path.join(root, 'admin/index.php'), cookies: adminCookies }], adminSession);
check('The overview renders for a signed-in operator',
  adminHome[0].status === 200 && /Overview/.test(adminHome[0].body || '')
  && /Merchants/.test(adminHome[0].body || ''), String(adminHome[0].status));
check('The overview warns that the default password is still in use',
  /admin<\/code>\/<code>admin/.test(adminHome[0].body || ''), (adminHome[0].body || '').match(/default.{0,80}/i)?.[0] || 'no warning');

// Every page must load, not just the overview.
const adminPages = ['merchants.php', 'operations.php', 'orders.php', 'addons.php', 'tickets.php', 'audit.php', 'admins.php', 'settings.php'];
const adminPageResults = requests(adminPages.map((p) => ({
  method: 'GET', script: path.join(root, 'admin', p), cookies: adminCookies,
})), adminSession);
for (const [i, page] of adminPages.entries()) {
  check(`Admin ${page} loads without an error`,
    adminPageResults[i].status === 200 && !/Something went wrong/.test(adminPageResults[i].body || '')
    && !adminPageResults[i].exception,
    `${adminPageResults[i].status} ${adminPageResults[i].exception || ''}`);
}

// Operational queues and support permissions use the real admin endpoints.
const opsFixture = JSON.parse(php(`<?php
require 'includes/db.php';
$p=db();
$p->prepare('INSERT INTO merchants(name,email,password,api_key) VALUES(?,?,?,?)')->execute(['Ops Fixture','ops-fixture@example.com','x','tg_OPSFIXTURE']);
$m=(int)$p->lastInsertId();
$p->prepare('INSERT INTO merchant_accounts(merchant_id,account_label,upi_id,linked_email,google_app_password) VALUES(?,?,?,?,?)')->execute([$m,'Ops account','ops@upi','ops@example.com','secret']);
$a=(int)$p->lastInsertId();
for ($i=0;$i<55;$i++) {
 $id='ops-order-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);
 $p->prepare('INSERT INTO orders(order_id,merchant_id,merchant_account_id,amount,payable_amount,status,expires_at) VALUES(?,?,?,?,?,?,?)')->execute([$id,$m,$a,10,10,$i===0?'refunded':'success','2030-01-01 00:00:00']);
}
$p->prepare('INSERT INTO webhook_logs(order_id,payload,signature,attempt_count,next_retry_at,response_code) VALUES(?,?,?,?,?,?)')->execute(['ops-order-000','PRIVATE_PAYLOAD','PRIVATE_SIGNATURE',2,'2030-01-01 00:00:00',500]);
$p->prepare('INSERT INTO webhook_logs(order_id,payload,attempt_count,next_retry_at) VALUES(?,?,?,?)')->execute(['ops-order-001','PRIVATE_PAYLOAD',5,null]);
$p->prepare('INSERT INTO receipt_events(merchant_id,merchant_account_id,utr,amount,matched_order_id,state,detail) VALUES(?,?,?,?,?,?,?)')->execute([$m,$a,'ops-utr',10,'ops-order-000','ambiguous','PRIVATE_DETAIL']);
$p->prepare('INSERT INTO tickets(merchant_id,reference,subject) VALUES(?,?,?)')->execute([$m,'ops-ticket','Ops support test']);
$t=(int)$p->lastInsertId();
$p->prepare('INSERT INTO system_state(state_key,state_value,updated_at) VALUES(?,?,?) ON CONFLICT(state_key) DO UPDATE SET state_value=excluded.state_value,updated_at=excluded.updated_at')->execute(['worker_last_run','{}','2020-01-02 03:04:05']);
echo json_encode(['merchant'=>$m,'ticket'=>$t]);
`));
const opsScript = path.join(root, 'admin/operations.php');
const ordersScript = path.join(root, 'admin/orders.php');
const ticketsScript = path.join(root, 'admin/tickets.php');
const opsPages = requests([
  {script:opsScript,cookies:adminCookies,query:{view:'webhooks',state:'scheduled',q:'ops-order'}},
  {script:opsScript,cookies:adminCookies,query:{view:'webhooks',state:'attention',q:'ops-order'}},
  {script:opsScript,cookies:adminCookies,query:{view:'receipts',state:'ambiguous',q:'ops-utr'}},
  {script:ordersScript,cookies:adminCookies,query:{q:'ops-order',per_page:'25',page:'3'}},
  {script:ordersScript,cookies:adminCookies,query:{q:'ops-order',status:'refunded'}},
  {script:path.join(root,'admin/settings.php'),cookies:adminCookies},
  {script:ticketsScript,cookies:adminCookies,query:{ticket:String(opsFixture.ticket)}},
], adminSession);
check('Operations scheduled filter finds retry without leaking secrets', opsPages[0].status===200 && /ops-order-000/.test(opsPages[0].body) && !/ops-order-001|PRIVATE_PAYLOAD|PRIVATE_SIGNATURE/.test(opsPages[0].body), opsPages[0].exception || '');
check('Operations attention filter finds unscheduled event', opsPages[1].status===200 && /ops-order-001/.test(opsPages[1].body) && !/ops-order-000/.test(opsPages[1].body), opsPages[1].exception || '');
check('Receipt queue filters ambiguous events without exposing detail', opsPages[2].status===200 && /ops-utr/.test(opsPages[2].body) && /already settled/.test(opsPages[2].body) && !/PRIVATE_DETAIL/.test(opsPages[2].body), opsPages[2].exception || '');
check('Orders reach older pages and aggregate all filtered orders', opsPages[3].status===200 && /55 matching orders/.test(opsPages[3].body) && /550\.00/.test(opsPages[3].body) && /ops-order-000/.test(opsPages[3].body) && !/ops-order-054/.test(opsPages[3].body), opsPages[3].exception || '');
check('Refunded orders can be filtered', opsPages[4].status===200 && /ops-order-000/.test(opsPages[4].body) && !/ops-order-001/.test(opsPages[4].body), opsPages[4].exception || '');
check('Deployment reports worker completion separately from mailbox polling', /2020-01-02 03:04:05/.test(opsPages[5].body) && /Mailbox polling/.test(opsPages[5].body) && /does not guarantee/.test(opsPages[5].body));
const opsTicketCsrf = /name="admin_csrf" value="([a-f0-9]{64})"/.exec(opsPages[6].body || '')?.[1];
const supportPost = requests([{method:'POST',script:ticketsScript,cookies:adminCookies,query:{ticket:String(opsFixture.ticket)},post:{admin_csrf:opsTicketCsrf,ticket_id:String(opsFixture.ticket),body:'Saved reply'}}],adminSession)[0];
check('Support reply is saved and reports failed SMTP honestly', supportPost.status===200 && /Reply saved/.test(supportPost.body) && /Email notification could not be sent/.test(supportPost.body) && /Saved reply/.test(supportPost.body), supportPost.exception || '');
const supportState = JSON.parse(php(`<?php require 'includes/db.php'; $q=db()->prepare('SELECT status,last_author FROM tickets WHERE id=?'); $q->execute([${opsFixture.ticket}]); echo json_encode($q->fetch());`));
check('Support reply uses merchant-facing status and author', supportState.status==='awaiting_reply' && supportState.last_author==='admin', JSON.stringify(supportState));
php(`<?php require 'includes/db.php'; db()->exec("UPDATE admins SET role='staff' WHERE email='admin'");`);
const staffPost = requests([{method:'POST',script:ticketsScript,cookies:adminCookies,post:{admin_csrf:opsTicketCsrf,ticket_id:String(opsFixture.ticket),body:'Staff reply',close:'1'}}],adminSession)[0];
const closedState = JSON.parse(php(`<?php require 'includes/db.php'; $q=db()->prepare('SELECT status,last_author FROM tickets WHERE id=?'); $q->execute([${opsFixture.ticket}]); echo json_encode($q->fetch());`));
check('Staff can reply and resolve a ticket', staffPost.status===200 && /ticket was closed/.test(staffPost.body) && closedState.status==='resolved', JSON.stringify(closedState));

// Change the session account role temporarily to exercise an actual viewer session.
php(`<?php require 'includes/db.php'; db()->exec("UPDATE admins SET role='viewer' WHERE email='admin'");`);
const viewerOps = requests([{script:opsScript,cookies:adminCookies}],adminSession)[0];
const viewerTicket = requests([{script:ticketsScript,cookies:adminCookies,query:{ticket:String(opsFixture.ticket)}}],adminSession)[0];
const viewerPost = requests([{method:'POST',script:ticketsScript,cookies:adminCookies,post:{admin_csrf:opsTicketCsrf,ticket_id:String(opsFixture.ticket),body:'Forbidden reply'}}],adminSession)[0];
const viewerState = JSON.parse(php(`<?php require 'includes/db.php'; echo json_encode((int)db()->query("SELECT COUNT(*) FROM ticket_messages WHERE body='Forbidden reply'")->fetchColumn());`));
php(`<?php require 'includes/db.php'; db()->exec("UPDATE admins SET role='owner' WHERE email='admin'");`);
check('Viewer cannot open sensitive operations', viewerOps.status===403, String(viewerOps.status));
check('Viewer can read support but cannot see reply form', viewerTicket.status===200 && /read-only access/.test(viewerTicket.body) && !/name="body"/.test(viewerTicket.body));
check('Viewer cannot post support replies even with CSRF', viewerPost.status===403 && viewerState===0, String(viewerPost.status));
php(`<?php require 'includes/db.php'; db()->prepare('DELETE FROM merchants WHERE id=?')->execute([${opsFixture.merchant}]);`);

// --- Suspension actually stops a merchant ---
const suspendFixture = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/crypto.php';
$pdo = db();
$stmt = $pdo->prepare('INSERT INTO merchants(name,email,password,api_key) VALUES(?,?,?,?)');
$stmt->execute(['Suspend Me','suspend-me@example.com',password_hash('correct-horse-battery', PASSWORD_DEFAULT),'tg_SUSPENDMEKEY']);
$pdo->exec("UPDATE merchants SET status='active' WHERE email='suspend-me@example.com'");
echo json_encode(['id' => (int)$pdo->lastInsertId()]);
`, baseEnv()));
const victimAuth = signIn('suspend-me@example.com', 'correct-horse-battery');
const victimOk = requests([{ method: 'GET', script: path.join(root, 'dashboard.php') }], victimAuth.session);
check('The suspension fixture can sign in first', victimOk[0].status === 200, String(victimOk[0].status));

const merchantsCsrf = /name="admin_csrf" value="([a-f0-9]{64})"/.exec(
  requests([{ method: 'GET', script: path.join(root, 'admin/merchants.php'), cookies: adminCookies }], adminSession)[0].body || ''
)?.[1];
const suspendPost = requests([{
  method: 'POST', script: path.join(root, 'admin/merchants.php'), cookies: adminCookies,
  post: { admin_csrf: merchantsCsrf, action: 'suspend', merchant_id: String(suspendFixture.id), reason: 'Test suspension' },
}], adminSession);
check('An operator can suspend a merchant',
  suspendPost[0].status === 200 && /suspended/i.test(suspendPost[0].body || ''), String(suspendPost[0].status));

const afterSuspend = requests([{ method: 'GET', script: path.join(root, 'dashboard.php') }], victimAuth.session);
check('A suspended merchant is signed out of the dashboard',
  afterSuspend[0].status === 302 && /\/login\.php/.test((afterSuspend[0].headers || []).join(' ')),
  `${afterSuspend[0].status} ${(afterSuspend[0].headers || []).join(' ')}`);

const susSession = `sus_${crypto.randomUUID()}`;
const suspendedLogin = requests([
  { method: 'GET', script: path.join(root, 'login.php') },
], susSession);
const susCsrf = /name="csrf" value="([a-f0-9]{64})"/.exec(suspendedLogin[0].body || '')?.[1];
// Same session id as the GET that minted the token: the CSRF value is per
// session, so posting it under a fresh id is a 419, not a login attempt.
const susAttempt = requests([{
  method: 'POST', script: path.join(root, 'login.php'),
  post: { csrf: susCsrf, email: 'suspend-me@example.com', password: 'correct-horse-battery' },
}], susSession);
check('A suspended merchant cannot sign back in',
  susAttempt[0].status === 200 && /suspended/i.test(susAttempt[0].body || ''), String(susAttempt[0].status));

const susApi = requests([{
  method: 'POST', script: path.join(root, 'api/create-order.php'),
  headers: { 'X-Api-Key': 'tg_SUSPENDMEKEY' },
  body: JSON.stringify({ amount: 100 }),
}]);
check('A suspended merchant\'s API key is refused',
  susApi[0].status === 403 && /suspended/i.test(susApi[0].body || ''), `${susApi[0].status} ${(susApi[0].body || '').slice(0, 90)}`);

// Restore, then confirm the account works again — suspension must be reversible.
const restorePost = requests([{
  method: 'POST', script: path.join(root, 'admin/merchants.php'), cookies: adminCookies,
  post: { admin_csrf: merchantsCsrf, action: 'restore', merchant_id: String(suspendFixture.id) },
}], adminSession);
// Suspension deliberately destroys the session, so restoring cannot resurrect
// the old cookie. The correct check is that the credential works again.
// Clear the login limiter first: this section signs in several times and the
// real rate limit (12 per 5 min) is shared across the whole suite.
php(`<?php
require 'includes/db.php';
try { db()->exec('DELETE FROM api_rate_limits'); } catch (Throwable) {}
`, baseEnv());
const restoredAuth = signIn('suspend-me@example.com', 'correct-horse-battery');
const afterRestore = requests([{ method: 'GET', script: path.join(root, 'dashboard.php') }], restoredAuth.session);
check('Restoring a merchant lets them sign in again',
  restorePost[0].status === 200 && afterRestore[0].status === 200, `${afterRestore[0].status}`);
php(`<?php
require 'includes/db.php';
db()->prepare('DELETE FROM merchants WHERE email=?')->execute(['suspend-me@example.com']);
`, baseEnv());

// --- The last owner is protected ---
const ownerGuard = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/admin.php';
$pdo = db();
$owner = $pdo->query("SELECT id FROM admins WHERE role='owner' AND status='active' ORDER BY id LIMIT 1")->fetchColumn();
echo json_encode(['owner_id' => (int)$owner, 'others' => tg_admin_owner_count((int)$owner)]);
`, baseEnv()));
check('The deployment has exactly one owner, so the guard matters',
  ownerGuard.others === 0, JSON.stringify(ownerGuard));

const demoteLastOwner = requests([{
  method: 'POST', script: path.join(root, 'admin/admins.php'), cookies: adminCookies,
  post: { admin_csrf: merchantsCsrf, action: 'role', admin_id: String(ownerGuard.owner_id), new_role: 'staff' },
}], adminSession);
const ownerStillOwner = JSON.parse(php(`<?php
require 'includes/db.php';
echo json_encode(db()->query("SELECT role FROM admins WHERE id=" . (int)${JSON.stringify(ownerGuard.owner_id)})->fetchColumn());
`, baseEnv()));
check('The last owner cannot demote themselves away',
  /last active owner/i.test(demoteLastOwner[0].body || '') && ownerStillOwner === 'owner',
  `${ownerStillOwner}`);

const deleteLastOwner = requests([{
  method: 'POST', script: path.join(root, 'admin/admins.php'), cookies: adminCookies,
  post: { admin_csrf: merchantsCsrf, action: 'delete', admin_id: String(ownerGuard.owner_id) },
}], adminSession);
check('The last owner cannot delete their own account',
  /cannot delete your own account/i.test(deleteLastOwner[0].body || ''), (deleteLastOwner[0].body || '').match(/cannot[^<.]{0,60}/i)?.[0] || '');

// --- CSRF and logout ---
const noCsrf = requests([{
  method: 'POST', script: path.join(root, 'admin/merchants.php'), cookies: adminCookies,
  post: { action: 'suspend', merchant_id: '1' },
}], adminSession);
check('An admin POST without a CSRF token is rejected', noCsrf[0].status === 419, String(noCsrf[0].status));

const adminLogout = requests([{ method: 'GET', script: path.join(root, 'admin/logout.php'), cookies: adminCookies }], adminSession);
const afterLogout = requests([{ method: 'GET', script: path.join(root, 'admin/index.php'), cookies: adminCookies }], adminSession);
check('Signing out revokes the session server-side',
  adminLogout[0].status === 302 && afterLogout[0].status === 302, `${afterLogout[0].status}`);

// ============================================================
// Payer UTR claims
// ------------------------------------------------------------
// The checkout page is unauthenticated, so a claim must record an
// assertion and change nothing else. These checks exist because the
// obvious implementation — feed the UTR straight into settle_receipt —
// lets a stranger mark an order paid with an invented reference.
// ============================================================
section('Payer UTR claims');

const claimFixture = JSON.parse(php(`<?php
require 'includes/db.php';
$pdo = db();
$pdo->exec("DELETE FROM orders WHERE order_id IN ('tg_CLAIMTEST1','tg_CLAIMTEST2')");
$pdo->exec("DELETE FROM receipt_events WHERE utr='778899001122'");
$acc = $pdo->query('SELECT id FROM merchant_accounts WHERE merchant_id=1 ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO orders(order_id,merchant_id,merchant_account_id,amount,payable_amount,status,expires_at,created_at) VALUES('tg_CLAIMTEST1',1,?,'321.00','321.00','pending',?,?)")
    ->execute([$acc, gmdate('Y-m-d H:i:s', time()+1800), gmdate('Y-m-d H:i:s')]);
echo json_encode(['account' => (int)$acc]);
`, baseEnv()));

const claimPost = requests([{
  method: 'POST', script: path.join(root, 'pay.php'),
  query: { order_id: 'tg_CLAIMTEST1' },
  post: { action: 'claim_utr', order_id: 'tg_CLAIMTEST1', utr: '778899001122' },
}], `claim_${crypto.randomUUID()}`);
check('A payer can submit a UTR from checkout',
  claimPost[0].status === 200 && /recorded that reference/i.test(claimPost[0].body || ''),
  String(claimPost[0].status));

// The whole point: the order must still be pending.
const claimState = JSON.parse(php(`<?php
require 'includes/db.php';
$pdo = db();
echo json_encode([
  'order_status' => $pdo->query("SELECT status FROM orders WHERE order_id='tg_CLAIMTEST1'")->fetchColumn(),
  'event_state' => $pdo->query("SELECT state FROM receipt_events WHERE utr='778899001122'")->fetchColumn(),
]);
`, baseEnv()));
check('A claim does not mark the order paid',
  claimState.order_status === 'pending', `order=${claimState.order_status}`);
check('A claim is recorded as an assertion, not a settlement',
  claimState.event_state === 'claimed', `state=${claimState.event_state}`);

// A short or non-numeric reference is a typo, not a payment.
const badClaim = requests([{
  method: 'POST', script: path.join(root, 'pay.php'),
  query: { order_id: 'tg_CLAIMTEST1' },
  post: { action: 'claim_utr', order_id: 'tg_CLAIMTEST1', utr: '123' },
}], `claim_${crypto.randomUUID()}`);
check('A malformed UTR is rejected',
  /8 to 18 digits/i.test(badClaim[0].body || ''), (badClaim[0].body || '').match(/8 to 18[^<]{0,40}/)?.[0] || '');

// Re-submitting the same reference must not create a second row.
const repeatClaim = requests([{
  method: 'POST', script: path.join(root, 'pay.php'),
  query: { order_id: 'tg_CLAIMTEST1' },
  post: { action: 'claim_utr', order_id: 'tg_CLAIMTEST1', utr: '778899001122' },
}], `claim_${crypto.randomUUID()}`);
const claimRows = Number(php(`<?php
require 'includes/db.php';
echo db()->query("SELECT COUNT(*) FROM receipt_events WHERE utr='778899001122'")->fetchColumn();
`, baseEnv()));
check('A repeated claim does not duplicate the record',
  claimRows === 1, `${claimRows} rows`);

// The merchant confirms it, which is what actually settles the order.
php(`<?php
require 'includes/db.php';
try { db()->exec('DELETE FROM api_rate_limits'); } catch (Throwable) {}
`, baseEnv());
const claimMerchant = signIn('merchant@example.com', 'correct-horse-battery');
const claimsPage = requests([{ method: 'GET', script: path.join(root, 'transactions.php') }], claimMerchant.session);
check('The merchant sees references awaiting confirmation',
  /awaiting your confirmation/i.test(claimsPage[0].body || '') && /778899001122/.test(claimsPage[0].body || ''),
  (claimsPage[0].body || '').match(/awaiting your confirmation/i)?.[0] || 'panel missing');

const claimCsrf = /name="csrf" value="([a-f0-9]{64})"/.exec(claimsPage[0].body || '')?.[1];
const claimId = JSON.parse(php(`<?php
require 'includes/db.php';
echo json_encode((int)db()->query("SELECT id FROM receipt_events WHERE utr='778899001122'")->fetchColumn());
`, baseEnv()));
const confirmPost = requests([{
  method: 'POST', script: path.join(root, 'transactions.php'),
  post: { csrf: claimCsrf, action: 'claims', claim_id: String(claimId), decision: 'confirm' },
}], claimMerchant.session);
check('The merchant can confirm a claim',
  /Confirmed\./i.test(confirmPost[0].body || ''), (confirmPost[0].body || '').match(/Confirmed[^<.]{0,50}/)?.[0] || '');

const afterConfirm = JSON.parse(php(`<?php
require 'includes/db.php';
$pdo = db();
echo json_encode([
  'order_status' => $pdo->query("SELECT status FROM orders WHERE order_id='tg_CLAIMTEST1'")->fetchColumn(),
  'utr_on_order' => $pdo->query("SELECT utr FROM orders WHERE order_id='tg_CLAIMTEST1'")->fetchColumn(),
  'event_state' => $pdo->query("SELECT state FROM receipt_events WHERE utr='778899001122'")->fetchColumn(),
]);
`, baseEnv()));
check('Confirming settles the order through the normal path',
  afterConfirm.order_status === 'success' && afterConfirm.utr_on_order === '778899001122'
  && afterConfirm.event_state === 'matched', JSON.stringify(afterConfirm));

// Rejecting must leave the order open.
const rejectFixture = JSON.parse(php(`<?php
require 'includes/db.php';
$pdo = db();
$pdo->exec("DELETE FROM receipt_events WHERE utr='554433221100'");
$acc = $pdo->query('SELECT id FROM merchant_accounts WHERE merchant_id=1 ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare("INSERT INTO orders(order_id,merchant_id,merchant_account_id,amount,payable_amount,status,expires_at,created_at) VALUES('tg_CLAIMTEST2',1,?,'77.00','77.00','pending',?,?)")
    ->execute([$acc, gmdate('Y-m-d H:i:s', time()+1800), gmdate('Y-m-d H:i:s')]);
echo json_encode(['account' => (int)$acc]);
`, baseEnv()));
requests([{
  method: 'POST', script: path.join(root, 'pay.php'),
  query: { order_id: 'tg_CLAIMTEST2' },
  post: { action: 'claim_utr', order_id: 'tg_CLAIMTEST2', utr: '554433221100' },
}], `claim_${crypto.randomUUID()}`);
const rejectId = JSON.parse(php(`<?php
require 'includes/db.php';
echo json_encode((int)db()->query("SELECT id FROM receipt_events WHERE utr='554433221100'")->fetchColumn());
`, baseEnv()));
const rejectPost = requests([{
  method: 'POST', script: path.join(root, 'transactions.php'),
  post: { csrf: claimCsrf, action: 'claims', claim_id: String(rejectId), decision: 'reject' },
}], claimMerchant.session);
const afterReject = JSON.parse(php(`<?php
require 'includes/db.php';
$pdo = db();
echo json_encode([
  'order_status' => $pdo->query("SELECT status FROM orders WHERE order_id='tg_CLAIMTEST2'")->fetchColumn(),
  'event_state' => $pdo->query("SELECT state FROM receipt_events WHERE utr='554433221100'")->fetchColumn(),
]);
`, baseEnv()));
check('Rejecting a claim leaves the order open',
  afterReject.order_status === 'pending' && afterReject.event_state === 'rejected', JSON.stringify(afterReject));

php(`<?php
require 'includes/db.php';
$pdo = db();
$pdo->exec("DELETE FROM orders WHERE order_id IN ('tg_CLAIMTEST1','tg_CLAIMTEST2')");
$pdo->exec("DELETE FROM receipt_events WHERE utr IN ('778899001122','554433221100')");
`, baseEnv());

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

// A wallet with pending orders must not be removable: deleting it would NULL
// merchant_account_id on those orders and settlement could never claim them.
const guardAuth = { session: exportAuth.session };
const guardIds = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/crypto.php';
$pdo = db();
$pdo->exec("DELETE FROM orders WHERE order_id='tg_ORPHANGUARD'");
$acc = $pdo->query('SELECT id FROM merchant_accounts WHERE merchant_id=1 ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare('INSERT INTO merchant_accounts(merchant_id,account_label,upi_id,linked_email,imap_host,imap_port,google_app_password,is_active,created_at) VALUES(1,?,?,?,?,?,?,0,?)')
    ->execute(['Guard Wallet','guard@fam','guard@example.com','imap.gmail.com',993,encrypt_secret('abcdefghijklmnop'),gmdate('Y-m-d H:i:s')]);
$guard = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO orders(order_id,merchant_id,merchant_account_id,amount,payable_amount,status,expires_at,created_at) VALUES('tg_ORPHANGUARD',1,?,'5','5','pending',?,?)")
    ->execute([$guard, gmdate('Y-m-d H:i:s', time()+600), gmdate('Y-m-d H:i:s')]);
echo json_encode(['guard' => $guard, 'label' => 'Guard Wallet']);
`, baseEnv()));
const guardCsrf = (requests([{ method: 'GET', script: path.join(root, 'multi-accounts.php') }], guardAuth.session)[0].body || '')
  .match(/name="csrf" value="([a-f0-9]{64})"/)?.[1] || '';
steps = requests([{ method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: guardCsrf, action: 'delete', id: String(guardIds.guard), confirm_label: 'Guard Wallet' } }], guardAuth.session);
check('A wallet with pending orders cannot be removed',
  /still has 1 pending order/.test(steps[0].body || ''), (steps[0].body || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 140));
const guardSurvived = JSON.parse(php(`<?php
require 'includes/db.php';
$pdo = db();
echo json_encode([
  'wallets' => (int)$pdo->query('SELECT COUNT(*) FROM merchant_accounts WHERE account_label="Guard Wallet"')->fetchColumn(),
  'account_on_order' => $pdo->query("SELECT merchant_account_id FROM orders WHERE order_id='tg_ORPHANGUARD'")->fetchColumn(),
]);
`, baseEnv()));
check('The protected wallet and its order link survive',
  guardSurvived.wallets === 1 && String(guardSurvived.account_on_order ?? '') !== '', JSON.stringify(guardSurvived));
php(`<?php
require 'includes/db.php';
$pdo = db();
$pdo->exec("DELETE FROM orders WHERE order_id='tg_ORPHANGUARD'");
$pdo->exec("DELETE FROM merchant_accounts WHERE account_label='Guard Wallet'");
`, baseEnv());

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

section('Ticket attachments');

const attAuth = { session: exportAuth.session };
php(`<?php
require 'includes/db.php';
db()->exec('DELETE FROM api_rate_limits');
`, baseEnv());
const attPage = requests([{ method: 'GET', script: path.join(root, 'tickets.php') }], attAuth.session)[0];
const attCsrf = (attPage.body || '').match(/name="csrf" value="([a-f0-9]{64})"/)?.[1] || '';
check('The ticket form offers a multi-file picker',
  /Attach Screenshots \(Max 5\)/.test(attPage.body || '')
  && /name="attachments\[\]"[^>]*multiple/.test(attPage.body || ''));

// Real fixture bytes: a 1x1 PNG, and a text file dressed up as an image.
const tmpDir = mkdtempSync(path.join(tmpdir(), 'tgatt-'));
const pngPath = path.join(tmpDir, 'shot.png');
writeFileSync(pngPath, Buffer.from('89504E470D0A1A0A0000000D49484452000000010000000108060000001F15C4890000000A49444154789C6300010000050001', 'hex'));
const fakePath = path.join(tmpDir, 'evil.png');
writeFileSync(fakePath, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
const bigPath = path.join(tmpDir, 'big.png');
writeFileSync(bigPath, Buffer.concat([Buffer.from('89504E470D0A1A0A', 'hex'), Buffer.alloc(5 * 1024 * 1024 + 10)]));

const createTicket = (extra) => requests([{
  method: 'POST', script: path.join(root, 'tickets.php'),
  post: { csrf: attCsrf, action: 'create', topic: 'api', subject: 'Upload test', message: 'A ticket with a screenshot attached.' },
  ...extra,
}], attAuth.session)[0];

const withPng = createTicket({ files: [['attachments', 'screen.png', pngPath, 0, 68]] });
check('A PNG attachment is accepted', /Ticket TCK-/.test(withPng.body || ''), (withPng.body || '').slice(0, 120));
check('The attachment is listed with its size', /screen\.png/.test(withPng.body || ''), '');

const stored = JSON.parse(php(`<?php
require 'includes/db.php';
$rows = db()->query("SELECT id,stored_name,original_name,mime,bytes FROM ticket_attachments ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows);
`, baseEnv()));
check('Exactly one attachment row was written', stored.length === 1, JSON.stringify(stored));
check('The stored mime comes from the file bytes, not the name', stored[0]?.mime === 'image/png', stored[0]?.mime);
check('The stored name is random with a derived extension', /^[a-f0-9]{32}\.png$/.test(stored[0]?.stored_name || ''));
check('The file exists outside the web root',
  existsSync(path.join(root, 'storage', 'attachments', stored[0]?.stored_name || 'x')));

// A file whose bytes are SVG must be rejected even though it is named .png.
const browserBad = createTicket({ files: [['attachments', 'evil.png', fakePath, 0, 60]] });
check('Content sniffing beats a misleading filename', /Only JPG, PNG, WEBP and PDF/.test(browserBad.body || ''));
const afterBad = JSON.parse(php(`<?php
require 'includes/db.php';
echo json_encode((int)db()->query('SELECT COUNT(*) FROM ticket_attachments')->fetchColumn());
`, baseEnv()));
check('The rejected file wrote no row', afterBad === 1, String(afterBad));

const bigUpload = createTicket({ files: [['attachments', 'big.png', bigPath, 0, 5 * 1024 * 1024 + 10]] });
check('An oversize file is refused', /up to 5 MB each|between 1 byte and 5 MB/i.test(bigUpload.body || ''),
  (bigUpload.body || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 140));

// Download: owner only, always as an attachment.
const attId = stored[0]?.id;
const ownerDownload = requests([{ method: 'GET', script: path.join(root, 'attachment.php'), query: { id: String(attId) } }], attAuth.session)[0];
check('The owner can download the file', ownerDownload.status === 200
  && (ownerDownload.headers || []).join(' ').includes('attachment;'),
  `status=${ownerDownload.status} headers=${(ownerDownload.headers || []).join(' | ').slice(0, 200)} body=${(ownerDownload.body || '').slice(0, 120)}`);
check('Downloads are served with nosniff', (ownerDownload.headers || []).join(' ').toLowerCase().includes('nosniff'));
const anonDownload = requests([{ method: 'GET', script: path.join(root, 'attachment.php'), query: { id: String(attId) } }], `anon_${crypto.randomUUID()}`)[0];
check('A signed-out visitor cannot download it', anonDownload.status === 302 || anonDownload.status === 403);
const missingDownload = requests([{ method: 'GET', script: path.join(root, 'attachment.php'), query: { id: '999999' } }], attAuth.session)[0];
check('An unknown attachment id is a clean 404', missingDownload.status === 404);

rmSync(tmpDir, { recursive: true, force: true });

section('Integrations: Gmail & FamPay setup');

const intAuth = { session: exportAuth.session };
php(`<?php
require 'includes/db.php';
db()->exec('DELETE FROM api_rate_limits');
`, baseEnv());
let intPage = requests([{ method: 'GET', script: path.join(root, 'integrations.php') }], intAuth.session)[0];
check('Integrations page presents the Gmail & FamPay card',
  /Gmail &amp; FamPay/.test(intPage.body || '') && /Verify &amp; Connect/.test(intPage.body || ''));
check('The four-step app password guide is visible',
  /Setup Instructions/.test(intPage.body || '')
  && /2-Step Verification/.test(intPage.body || '')
  && /Enable IMAP/.test(intPage.body || '')
  && /app password/i.test(intPage.body || ''));
check('A connection test is offered', /Test mailbox connection/.test(intPage.body || ''));
const intCsrf = (intPage.body || '').match(/name="csrf" value="([a-f0-9]{64})"/)?.[1] || '';

// Validation: a malformed UPI and a short app password must be refused.
steps = requests([
  { method: 'POST', script: path.join(root, 'integrations.php'), post: { csrf: intCsrf, action: 'save', account_label: 'Primary UPI', upi_id: 'not-a-vpa', linked_email: 'inbox@example.com', app_password: '', imap_host: 'imap.gmail.com', imap_port: '993' } },
  { method: 'POST', script: path.join(root, 'integrations.php'), post: { csrf: intCsrf, action: 'save', account_label: 'Primary UPI', upi_id: 'shop@fam', linked_email: 'inbox@example.com', app_password: 'short', imap_host: 'imap.gmail.com', imap_port: '993' } },
  { method: 'POST', script: path.join(root, 'integrations.php'), post: { csrf: intCsrf, action: 'save', account_label: 'Primary UPI', upi_id: 'shop@fam', linked_email: 'not-an-email', app_password: '', imap_host: 'imap.gmail.com', imap_port: '993' } },
], intAuth.session);
check('A malformed UPI is refused', /does not look like a UPI ID/.test(steps[0].body || ''));
check('A short app password is refused', /exactly 16 characters/.test(steps[1].body || ''));
check('A malformed mailbox address is refused', /Gmail address that receives/.test(steps[2].body || ''));

// Disconnect requires the mailbox address typed exactly.
const intEmail = JSON.parse(php(`<?php
require 'includes/db.php';
$r = db()->query('SELECT linked_email FROM merchant_accounts WHERE merchant_id=1 ORDER BY is_active DESC, id LIMIT 1')->fetch();
echo json_encode($r['linked_email'] ?? '');
`, baseEnv()));
steps = requests([{ method: 'POST', script: path.join(root, 'integrations.php'),
  post: { csrf: intCsrf, action: 'disconnect', confirm_label: 'wrong@example.com' } }], intAuth.session);
check('A mismatched disconnect confirmation is refused',
  /Type the mailbox address exactly/.test(steps[0].body || ''));
const stillStored = JSON.parse(php(`<?php
require 'includes/db.php';
$r = db()->query("SELECT google_app_password FROM merchant_accounts WHERE merchant_id=1 ORDER BY is_active DESC, id LIMIT 1")->fetchColumn();
echo json_encode(trim((string)$r) !== '');
`, baseEnv()));
check('A refused disconnect leaves the credential in place', stillStored === true);

// The correct address disconnects: only the password is cleared.
steps = requests([{ method: 'POST', script: path.join(root, 'integrations.php'),
  post: { csrf: intCsrf, action: 'disconnect', confirm_label: intEmail } }], intAuth.session);
check('Typing the mailbox address disconnects', /Mailbox disconnected/.test(steps[0].body || ''));
const afterDisconnect = JSON.parse(php(`<?php
require 'includes/db.php';
$row = db()->query("SELECT google_app_password,upi_id,linked_email FROM merchant_accounts WHERE merchant_id=1 ORDER BY is_active DESC, id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo json_encode(['password' => trim((string)$row['google_app_password']), 'upi' => $row['upi_id'], 'email' => $row['linked_email']]);
`, baseEnv()));
check('Disconnecting clears only the stored password', afterDisconnect.password === '');
check('Disconnecting keeps the UPI and mailbox on file',
  afterDisconnect.upi !== '' && afterDisconnect.email !== '', JSON.stringify(afterDisconnect));
const disconnectAudit = JSON.parse(php(`<?php
require 'includes/db.php';
echo json_encode((int)db()->query("SELECT COUNT(*) FROM audit_log WHERE action='integration.disconnected'")->fetchColumn());
`, baseEnv()));
check('Disconnecting is audited', disconnectAudit >= 1, String(disconnectAudit));

// Restore a credential so later sections (and the page state) are unchanged.
php(`<?php
require 'includes/db.php';
require 'includes/crypto.php';
db()->prepare('UPDATE merchant_accounts SET google_app_password=? WHERE merchant_id=1')->execute([encrypt_secret('abcdefghijklmnop')]);
`, baseEnv());

section('IMAP setup correctness');

// These four bugs all produced the same symptom ("IMAP setup not working"),
// so each is pinned to the specific behaviour that was wrong.
const imapCfg = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/merchant.php';
$pdo = db();
// Deterministic fixture: one active wallet plus an older standby row.
// Accounts are UPDATED, never deleted: orders reference them with
// ON DELETE SET NULL, so deleting a wallet silently orphans pending orders and
// they can never be settled by a receipt.
$ids = $pdo->query('SELECT id FROM merchant_accounts WHERE merchant_id=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$upd = $pdo->prepare('UPDATE merchant_accounts SET account_label=?,upi_id=?,linked_email=?,imap_host=?,imap_port=?,google_app_password=?,is_active=?,created_at=? WHERE id=?');
if (count($ids) >= 2) {
    $upd->execute(['Old Standby','old@fam','old@example.com','imap.gmail.com',993,'v1:stale',0,'2026-01-01 00:00:00',$ids[0]]);
    $upd->execute(['Active Wallet','live@fam','live@example.com','imap.gmail.com',993,'v1:live',1,'2026-02-01 00:00:00',$ids[1]]);
} else {
    $upd->execute(['Active Wallet','live@fam','live@example.com','imap.gmail.com',993,'v1:live',1,'2026-02-01 00:00:00',$ids[0]]);
    $pdo->prepare('INSERT INTO merchant_accounts(merchant_id,account_label,upi_id,linked_email,imap_host,imap_port,google_app_password,is_active,created_at) VALUES(1,?,?,?,?,?,?,?,?)')
        ->execute(['Old Standby','old@fam','old@example.com','imap.gmail.com',993,'v1:stale',0,'2026-01-01 00:00:00']);
}
echo json_encode(['id' => (int)$pdo->lastInsertId()]);
`, baseEnv()));

// Bug 1: the page displayed the active wallet but wrote to the oldest row.
const imapCsrf = (requests([{ method: 'GET', script: path.join(root, 'integrations.php') }], exportAuth.session)[0].body || '')
  .match(/name="csrf" value="([a-f0-9]{64})"/)?.[1] || '';
const imapSave = requests([{ method: 'POST', script: path.join(root, 'integrations.php'),
  post: { csrf: imapCsrf, action: 'save', account_label: 'Renamed Active', upi_id: 'live@fam',
          linked_email: 'live@example.com', app_password: '', imap_host: 'imap.gmail.com', imap_port: '993' } }],
  exportAuth.session)[0];
const labels = JSON.parse(php(`<?php
require 'includes/db.php';
$r = db()->query('SELECT account_label,is_active FROM merchant_accounts WHERE merchant_id=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($r);
`, baseEnv()));
check('Integrations edits the active wallet, not the oldest row',
  labels.some((r) => r.account_label === 'Renamed Active' && Number(r.is_active) === 1)
  && labels.some((r) => r.account_label === 'Old Standby'),
  (imapSave.body || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').match(/callout[^>]*>\s*<svg[^>]*>.*?<\/svg>\s*<span>(.{0,160})/)?.[1] || JSON.stringify(labels));

// Bug 2: a disconnected (blank password) active row must not be polled at all.
php(`<?php
require 'includes/db.php';
db()->exec("UPDATE merchant_accounts SET google_app_password='' WHERE is_active=1 AND merchant_id=1");
`, baseEnv());
const polled = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/imap.php';
$pdo = db();
echo json_encode(count($pdo->query("SELECT id FROM merchant_accounts WHERE is_active=1 AND google_app_password IS NOT NULL AND google_app_password <> ''")->fetchAll()));
`, baseEnv()));
check('A disconnected mailbox is excluded from polling', polled === 0, String(polled));
const disconnectedMsg = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/crypto.php';
require 'includes/imap.php';
try {
    imap_open_account(['id'=>1,'merchant_id'=>1,'linked_email'=>'x@y.com','imap_host'=>'imap.gmail.com','imap_port'=>993,'google_app_password'=>'']);
    echo json_encode(['threw' => false]);
} catch (Throwable $e) {
    echo json_encode(['threw' => true, 'msg' => $e->getMessage()]);
}
`, baseEnv()));
check('Opening a disconnected mailbox says to reconnect',
  disconnectedMsg.threw === true && /disconnected/i.test(disconnectedMsg.msg || ''), disconnectedMsg.msg);

// Bug 3: provider errors must be classified, not collapsed into one message.
const classify = JSON.parse(php(`<?php
require 'includes/imap-client.php';
$ref = new ReflectionMethod('TgImapClient', 'classifyAuthFailure');
echo json_encode([
  'apppass'  => $ref->invoke(null, ['[ALERT] Please log in with an application-specific password'], 'a@b.com'),
  'imapoff'  => $ref->invoke(null, ['NO [ALERT] IMAP access is disabled for this account'], 'a@b.com'),
  'unknown'  => $ref->invoke(null, ['NO [AUTHENTICATIONFAILED] Unknown user'], 'a@b.com'),
  'empty'    => $ref->invoke(null, [], 'a@b.com'),
  'leaks'    => str_contains($ref->invoke(null, ['NO secret-password-123'], 'a@b.com'), 'secret-password-123'),
]);
`, baseEnv()));
check('An app-password failure explains the fix', /App Password/i.test(classify.apppass || ''), classify.apppass);
check('A disabled-IMAP failure is distinguished', /IMAP access is disabled/i.test(classify.imapoff || ''), classify.imapoff);
check('An unknown mailbox is distinguished', /does not recognise/i.test(classify.unknown || ''), classify.unknown);
check('An empty server reply still yields a message', (classify.empty || '').length > 10);
check('Failure text never echoes the password', classify.leaks === false);

// Bug 5: the first wallet a merchant adds must become active immediately.
// Use a throwaway merchant: deleting merchant 1's wallets would orphan orders.
const firstMerchant = JSON.parse(php(`<?php
require 'includes/db.php';
$pdo = db();
$pdo->prepare('INSERT INTO merchants(name,email,password,api_key) VALUES(?,?,?,?)')
    ->execute(['First Wallet Co','first-wallet@example.com',password_hash('correct-horse-battery', PASSWORD_DEFAULT),'tg_FIRSTWALLETKEY']);
echo json_encode(['id' => (int)$pdo->lastInsertId()]);
`, baseEnv()));
// Sign in as that merchant: tg_account_count() is per merchant, so this is the
// only way to exercise the genuine "this is my first wallet" branch.
const firstAuth = signIn('first-wallet@example.com', 'correct-horse-battery');
const firstCsrf = (requests([{ method: 'GET', script: path.join(root, 'multi-accounts.php') }], firstAuth.session)[0].body || '')
  .match(/name="csrf" value="([a-f0-9]{64})"/)?.[1] || '';
const firstAdd = requests([{ method: 'POST', script: path.join(root, 'multi-accounts.php'),
  post: { csrf: firstCsrf, action: 'add', account_label: 'First Wallet', upi_id: 'first@fam', linked_email: 'first@example.com',
    app_password: 'abcdefghijklmnop', imap_host: 'imap.gmail.com', imap_port: '993' } }], firstAuth.session)[0];
const firstActive = JSON.parse(php(`<?php
require 'includes/db.php';
$r = db()->query('SELECT is_active FROM merchant_accounts ORDER BY id DESC LIMIT 1')->fetch();
echo json_encode((int)($r['is_active'] ?? -1));
`, baseEnv()));
check('The first wallet becomes active', firstActive === 1, String(firstActive));
check('The page says it is live, not a standby',
  /Primary wallet connected and set active/i.test(firstAdd.body || ''), '');

// Merchant 1's wallets were updated in place, so nothing needs restoring.

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

check('AI prompt uses the configured deployment URL and explains order creation',
  promptText.includes('https://gate.example.com') && promptText.includes('POST /api/create-order.php')
  && promptText.includes('payable_amount') && promptText.includes('1,000,000'));
check('AI prompt documents real webhook signature prefix and duplicate handling',
  promptText.includes("'sha256=' + hmac.new") && promptText.includes("'sha256=' . hash_hmac")
  && promptText.includes('delivered more than once') && promptText.includes('raw HTTP body'));
check('AI prompt explains idempotency limitations rather than promising exactly once',
  promptText.includes('not one atomic transaction') && /do not\s+assume every 4xx/i.test(promptText));
check('AI prompt distinguishes manual refunds from nonexistent API and events',
  promptText.includes('does not transfer funds') && promptText.includes('no refund API or payment.refunded webhook'));
check('Text endpoint lists actual link and transaction parameters',
  !docText.includes('create|list|update|delete') && !docText.includes('custom slug')
  && docText.includes('limit: 1–100') && docText.includes('search: order ID or UTR'));
check('Text endpoint specifies sha256= webhook signature prefix', docText.includes('sha256= followed by hex hmac_sha256'));

// Exercise the actual PHP code printed in the prompt, not a second hand-written copy.
const phpVerifyExample = promptText.match(/PHP signature check[^\n]*:\n([\s\S]*?)\n\nOTHER IMPLEMENTED ROUTES/);
const exampleBody = '{"order_id":"tg_TEST1234"}';
const signedExample = 'sha256=' + crypto.createHmac('sha256', 'example-key').update(exampleBody).digest('hex');
const phpExampleResult = phpVerifyExample && JSON.parse(php(`<?php
$rawBody = '${exampleBody}'; $apiKey = 'example-key';
$_SERVER['HTTP_X_TGGATEWAY_SIGNATURE'] = '${signedExample}';
${phpVerifyExample[1]}
$good = $valid;
$_SERVER['HTTP_X_TGGATEWAY_SIGNATURE'] = 'sha256=bad';
${phpVerifyExample[1]}
echo json_encode([$good, $valid]);
`));
check('Published PHP webhook snippet accepts a real signature and rejects a bad one',
  phpExampleResult && phpExampleResult[0] === true && phpExampleResult[1] === false);

const pythonVerifyExample = promptText.match(/Python signature check[^\n]*:\n([\s\S]*?)\n\nPHP signature check/);
let pythonExampleResult = '';
if (pythonVerifyExample) {
  const snippet = pythonVerifyExample[1].split('\n').map(line => line.replace(/^  /, '')).join('\n');
  pythonExampleResult = execFileSync('python3', ['-c',
    `api_key = 'example-key'\nraw_body = b'${exampleBody}'\nsupplied = '${signedExample}'\n${snippet}\nprint(valid)\nsupplied = 'sha256=bad'\n${snippet}\nprint(valid)`
  ], { encoding: 'utf8' }).trim();
}
check('Published Python webhook snippet accepts a real signature and rejects a bad one',
  pythonExampleResult === 'True\nFalse');



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

const docsPage = request({ method: 'GET', script: path.join(root, 'docs.php') });
const htmlPrompt = (docsPage.body.match(/<pre id="ai-prompt">([\s\S]*?)<\/pre>/) || [])[1] || '';
const decodedPrompt = htmlPrompt.replace(/&lt;/g, '<').replace(/&gt;/g, '>')
  .replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&amp;/g, '&');
check('Copyable HTML prompt matches the public text endpoint',
  docsPage.status === 200 && decodedPrompt === steps[2].body);
check('Dedicated prompt copy block is not modified with an embedded copy button',
  docsPage.body.includes("pre.id === 'ai-prompt'"));

check('Prompt copy button uses the selector handler, not the literal-value handler',
  docsPage.body.includes('data-copy-target="#ai-prompt"')
  && !docsPage.body.includes('data-copy="#ai-prompt"')
  && readFileSync(path.join(root, 'assets/js/main.js'), 'utf8').includes("querySelectorAll('[data-copy-target]')"));



section('Layout and stylesheets');

const cssPage = requests([{ method: 'GET', script: path.join(root, 'assets/css/design-system.css') }], 'css-anon')[0];
const cssText = cssPage.body || '';
check('The stylesheet is served', cssPage.status === 200 && cssText.length > 20000, String(cssText.length));
check('The mobile stacking rule follows the two-column rule',
  cssText.lastIndexOf('grid-template-columns: 1fr !important') > cssText.lastIndexOf('1.55fr 1fr'));
check('Dialogs and steps are styled',
  cssText.includes('.tg-dialog') && cssText.includes('.tg-steps'));
check('Status pills for the new link states exist',
  cssText.includes('.status-success') && cssText.includes('.status-expired'));

// Every panel page must render signed in without warnings or notices.
const pageList = ['dashboard.php', 'transactions.php', 'payment-links.php', 'api-keys.php', 'webhooks.php',
  'settings.php', 'integrations.php', 'multi-accounts.php', 'addons.php', 'services.php', 'invoices.php',
  'profile.php', 'tickets.php', 'status.php', 'docs.php', 'privacy.php'];
const renderedPages = requests(pageList.map((p) => ({ method: 'GET', script: path.join(root, p) })), exportAuth.session);
const broken = renderedPages
  .map((r, i) => ({ page: pageList[i], status: r.status, noise: /PHP (Warning|Notice|Fatal)/.test(r.body || '') }))
  .filter((r) => r.status !== 200 || r.noise);
check('Every panel page renders cleanly', broken.length === 0, broken.map((r) => `${r.page}:${r.status}`).join(', '));

section('Addons, services and invoices');

const addonAuth = signIn('merchant@example.com', 'correct-horse-battery');
const addonCsrf = addonAuth.csrf;
steps = requests([
  { method: 'GET', script: path.join(root, 'addons.php') },
  { method: 'GET', script: path.join(root, 'invoices.php') },
  { method: 'GET', script: path.join(root, 'services.php') },
  { method: 'POST', script: path.join(root, 'addons.php'), post: { csrf: addonCsrf, addon: 'bharatpe', months: '1' } },
  { method: 'POST', script: path.join(root, 'addons.php'), post: { csrf: addonCsrf, addon: 'slice', months: '1' } },
  { method: 'POST', script: path.join(root, 'addons.php'), post: { csrf: addonCsrf, addon: 'mobikwik', months: '1' } },
  { method: 'POST', script: path.join(root, 'addons.php'), post: { csrf: addonCsrf, addon: 'phonepe', months: '1' } },
  { method: 'POST', script: path.join(root, 'addons.php'), post: { csrf: addonCsrf, addon: 'paytm', months: '3' } },
  { method: 'GET', script: path.join(root, 'invoices.php') },
  { method: 'GET', script: path.join(root, 'services.php') },
], addonAuth.session);
const [addonsPage, invoicesEmpty, servicesPage, addonRefused, sliceRefused, mobikwikRefused, phonepeRefused, paytmRefused, invoicesAfter, servicesAfter] = steps;
check('Addons page lists the core, addon and coming-soon gateways',
  ['FamPay UPI Gateway', 'Paytm for Business', 'BharatPe for Business', 'Slice UPI Gateway', 'MobiKwik UPI Gateway', 'PhonePe for Business']
    .every((name) => (addonsPage.body || '').includes(name)),
  'one or more gateway cards missing');
check('Empty invoices page shows the reference empty state', invoicesEmpty.body.includes('No Invoices Yet'));
check('Services page shows the free core gateway', servicesPage.body.includes('Free Forever'));
check('Every external gateway is refused, including Paytm',
  [addonRefused, sliceRefused, mobikwikRefused, phonepeRefused, paytmRefused]
    .every((r) => /not available for purchase/.test(r.body || '')),
  [addonRefused, sliceRefused, mobikwikRefused, phonepeRefused, paytmRefused].map((r) => /not available/.test(r.body || '')).join(','));
check('Paytm is advertised as coming soon',
  /Paytm for Business[\s\S]{0,400}Coming Soon|Coming Soon[\s\S]{0,400}Paytm for Business/i.test(addonsPage.body || ''),
  'Paytm card no longer says Coming Soon');
check('Addon cards show the real brand marks',
  ['fampay', 'paytm', 'bharatpe', 'slice', 'mobikwik', 'phonepe']
    .every((key) => (addonsPage.body || '').includes(`/assets/img/gateways/${key}.png`)),
  'one or more gateway logos missing');
check('A refused purchase creates no invoice',
  !/INV-\d{6}-/.test(invoicesAfter.body || ''), 'an invoice appeared for a refused addon');

// ============================================================
// Form control styling
// ------------------------------------------------------------
// Several panels were written against an older markup idiom: a `.field`
// wrapper, a bare <input> with no class, and no `.field` rule in the sheet.
// Those controls fell through to the browser default and rendered at a
// different height and font to the styled inputs beside them. `.field` had
// only `.field-label` / `.field-val`, and the single global input rule set
// nothing but font-family.
//
// These checks fail if a new page reintroduces an unstyled control, or if the
// fallback that now catches them is removed.
// ============================================================
section('Form control styling');

const formCss = readFileSync(path.join(root, 'assets/css/design-system.css'), 'utf8');
const textBoxBlock = formCss.slice(formCss.indexOf('Text boxes: one look for every form control'));

check('A baseline style exists for unstyled text inputs',
  textBoxBlock.includes('input[type="text"]') && textBoxBlock.includes('input[type="number"]')
  && textBoxBlock.includes('input[type="url"]'),
  'the fallback selector list is missing a text-like input type');
check('Selects and textareas are covered by the baseline',
  /^select\s*\{/m.test(textBoxBlock) && /^textarea\s*\{/m.test(textBoxBlock),
  'select or textarea has no baseline rule');
check('The legacy .field wrapper is styled',
  /^\.field\s*\{/m.test(textBoxBlock) && /\.field > label/.test(textBoxBlock),
  '.field still has no rule, so its labels sit tight against the control');
check('Focus is visible on the baseline controls',
  textBoxBlock.includes('box-shadow') && /:focus/.test(textBoxBlock),
  'no focus ring in the baseline block');
check('Read-only and disabled controls are distinguishable',
  textBoxBlock.includes('input[readonly]') && textBoxBlock.includes(':disabled'),
  'read-only/disabled states are not styled');

// Every visible control in every merchant and admin page must carry a class.
const pageFiles = [...readdirSync(root).filter((f) => f.endsWith('.php')),
                   ...readdirSync(path.join(root, 'admin')).filter((f) => f.endsWith('.php')).map((f) => `admin/${f}`)];
const bare = [];
for (const file of pageFiles) {
  const body = readFileSync(path.join(root, file), 'utf8');
  const controls = body.match(/<(?:input|select|textarea)\b[^>]*>/g) || [];
  for (const control of controls) {
    if (/type="(hidden|submit|button)"/.test(control)) continue;
    const isRadioOrCheck = /type="(checkbox|radio)"/.test(control);
    const styled = /class="[^"]*(form-input|form-select|admin-input|admin-select|admin-textarea)/.test(control);
    // Checkboxes, radios and file pickers are styled by element, not class.
    // A search box inside .admin-search is deliberately borderless: the
    // wrapper draws the frame, so the element itself carries no class.
    const inWrapper = /type="search"/.test(control) && /admin-search/.test(body);
    if (!styled && !isRadioOrCheck && !/type="file"/.test(control) && !inWrapper) {
      bare.push(`${file}: ${control.replace(/\s+/g, ' ').slice(0, 70)}`);
    }
  }
}
check('No page ships an unstyled text box',
  bare.length === 0, bare.slice(0, 4).join(' | '));

// The CSS must stay parseable: an unbalanced brace silently kills every rule
// after it, which would unstyle the whole application without an error.
const opens = (formCss.match(/\{/g) || []).length;
const closes = (formCss.match(/\}/g) || []).length;
check('The stylesheet braces balance', opens === closes, `${opens} open / ${closes} close`);

const usedVars = new Set([...formCss.matchAll(/var\(--([a-z0-9-]+)/g)].map((m) => m[1]));
const definedVars = new Set([...formCss.matchAll(/--([a-z0-9-]+)\s*:/g)].map((m) => m[1]));
const undefinedVars = [...usedVars].filter((v) => !definedVars.has(v));
check('Every CSS variable used is defined',
  undefinedVars.length === 0, undefinedVars.slice(0, 6).join(', '));

// ============================================================
// Reachable forms and styled tables
// ------------------------------------------------------------
// Two whole classes of bug that the HTTP-level tests cannot see, because
// they POST the action directly and never look at the button a user has
// to click:
//   1. A form with no submit control. Every field is fillable and
//      nothing can send it. The support ticket form shipped this way.
//   2. A <table> with no `.table` class, so no padding, no header
//      treatment and no `white-space: nowrap` — columns wrap and
//      collapse into each other. Nine merchant tables shipped this way.
// ============================================================
section('Reachable forms and styled tables');

const formShopPages = readdirSync(root).filter((f) => f.endsWith('.php'));
const formAdminPages = readdirSync(path.join(root, 'admin')).filter((f) => f.endsWith('.php')).map((f) => `admin/${f}`);

// --- 1. Every form that posts somewhere must have something to click ---
const formless = [];
for (const file of [...formShopPages, ...formAdminPages]) {
  const body = readFileSync(path.join(root, file), 'utf8');
  const forms = body.match(/<form\b[\s\S]*?<\/form>/g) || [];
  for (const form of forms) {
    // A form holding only hidden inputs is a programmatic action (retry,
    // delete, toggle) and is submitted by a button in a sibling form or by
    // JS — those are checked by the button existing *inside* the form.
    const hasVisibleInput = /<(input|select|textarea)\b(?![^>]*type="hidden")[^>]*>/.test(form);
    const hasSubmit = /<button\b[^>]*(?!type="button")[^>]*>/i.test(form)
      || /type="submit"/i.test(form)
      // A <select onchange="this.form.submit()"> submits the form without a
      // button. It is not accessible without JS, so pages that rely on it also
      // carry a real button; the detector accepts either.
      || /onchange="this\.form\.submit\(\)"/i.test(form);
    if (hasVisibleInput && !hasSubmit) {
      formless.push(`${file}: ${form.replace(/\s+/g, ' ').slice(0, 90)}`);
    }
  }
}
check('Every form with visible fields has a submit control',
  formless.length === 0, formless.slice(0, 3).join(' | '));

// The support ticket form specifically: it is the one that regressed.
const ticketPage = readFileSync(path.join(root, 'tickets.php'), 'utf8');
const newTicketForm = (ticketPage.match(/<form[^>]*action" value="create"[\s\S]*?<\/form>/) || [])[0]
  || (ticketPage.match(/<form[\s\S]*?value="create"[\s\S]*?<\/form>/) || [])[0] || '';
check('The ticket form can actually be submitted',
  newTicketForm !== '' && /<button[^>]*type="submit"|<button[^>]*>[\s\S]*?Open ticket/i.test(newTicketForm),
  newTicketForm === '' ? 'form not found' : 'no submit button inside the create form');

// --- 2. Tables must be styled, whether by class or by element ---
const tableCss = readFileSync(path.join(root, 'assets/css/design-system.css'), 'utf8');
check('Tables are styled at the element level',
  /^table\s*\{/m.test(tableCss) && /table thead th[\s\S]{0,200}padding/.test(tableCss),
  'no element-level table rule; a <table> without a class renders unstyled');
check('Table cells do not wrap',
  /table th,\s*\ntable td[\s\S]{0,120}white-space:\s*nowrap/.test(tableCss),
  'nowrap missing, so long values wrap and collapse columns');

const unclassed = [];
for (const file of [...formShopPages, ...formAdminPages]) {
  const body = readFileSync(path.join(root, file), 'utf8');
  for (const tag of body.match(/<table\b[^>]*>/g) || []) {
    if (!/class="/.test(tag)) unclassed.push(`${file}: ${tag}`);
  }
}
check('No page ships an unclassed table',
  unclassed.length === 0, unclassed.slice(0, 3).join(' | '));

// --- 3. Documentation readability ---
const docs = readFileSync(path.join(root, 'docs.php'), 'utf8');
const bodySize = /\.docs-main p,\.docs-main li\{font-size:([\d.]+)px/.exec(docs);
check('Documentation body copy is at least 13px',
  bodySize && Number(bodySize[1]) >= 13, bodySize ? bodySize[1] + 'px' : 'size not found');
const navSize = /\.docs-nav a\{[^}]*font-size:([\d.]+)px/.exec(docs);
check('Documentation sidebar links are at least 12px',
  navSize && Number(navSize[1]) >= 12, navSize ? navSize[1] + 'px' : 'size not found');
check('Every documentation section is linked from the sidebar',
  [...docs.matchAll(/<h2 id="([a-z-]+)"/g)].every((m) => docs.includes(`href="#${m[1]}"`)),
  'a section exists with no sidebar link');

// ============================================================
// Password recovery
// ------------------------------------------------------------
// Until this existed, login.php ended at "ask your deployment administrator"
// and the only way back into a locked account was an operator editing the
// database. These check the properties that make it safe to expose publicly.
// ============================================================
// ============================================================
// Idempotent order creation
// ------------------------------------------------------------
// A payment API cannot require the caller to distinguish "my request never
// arrived" from "it arrived and the response was lost". Without this, the
// standard remedy — retry on timeout — creates a second order for one intended
// charge. These check the guarantees an integrator relies on.
// ============================================================
// ============================================================
// Refunds
// ------------------------------------------------------------
// The gateway could take money and never give it back: there was no refund
// table, status or event. This is a direct-UPI deployment, so the merchant
// returns money from their own app and the app records it. What software can
// actually guarantee is the arithmetic and the audit trail.
// ============================================================
section('Refunds');

const refundSetup = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/refund.php';
$pdo = db();
$pdo->prepare("INSERT INTO orders(order_id,merchant_id,amount,payable_amount,status,expires_at,created_at) VALUES('tg_RF1',1,500,500,'success',?,?)")->execute([db_now(600), db_now()]);
$pdo->prepare("INSERT INTO orders(order_id,merchant_id,amount,payable_amount,status,expires_at,created_at) VALUES('tg_RF2',1,900,900,'pending',?,?)")->execute([db_now(600), db_now()]);
echo json_encode(['ok'=>true]);
`));
check('A paid order exists to refund against', refundSetup.ok === true);

const partial = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/refund.php';
$r = refund_create(db(), 1, 'tg_RF1', '200.00', 'damaged', 'Asha', '4123');
$s = refund_summary(db(), 'tg_RF1');
echo json_encode(['r'=>$r,'s'=>$s]);
`));
check('A partial refund is recorded', partial.r.ok === true, JSON.stringify(partial.r));
check('A partial refund leaves the order paid', partial.s.order_status === 'success', partial.s.order_status);
check('A partial refund reduces what is refundable', partial.s.remaining === 300.0, String(partial.s.remaining));

const overRefund = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/refund.php';
$r = refund_create(db(), 1, 'tg_RF1', '400.00', 'too much', 'Asha', null);
echo json_encode(['r'=>$r, 's'=>refund_summary(db(),'tg_RF1')]);
`));
check('Refunding more than remains is refused', overRefund.r.ok === false, JSON.stringify(overRefund.r));
check('The refused refund changes nothing', overRefund.s.refunded === 200.0, String(overRefund.s.refunded));

const complete = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/refund.php';
$r = refund_create(db(), 1, 'tg_RF1', '300.00', 'rest', 'Asha', '9999');
$s = refund_summary(db(), 'tg_RF1');
echo json_encode(['r'=>$r,'s'=>$s,'list'=>refunds_for_order(db(),1,'tg_RF1')]);
`));
check('Refunding the exact balance succeeds', complete.r.ok === true, JSON.stringify(complete.r));
check('A fully refunded order changes status', complete.s.order_status === 'refunded', complete.s.order_status);
check('The summary agrees the order is fully refunded', complete.s.full === true && complete.s.remaining === 0.0, JSON.stringify(complete.s));
check('Both refunds are listed with their bank references', complete.list.length === 2, String(complete.list.length));

const guards = JSON.parse(php(`<?php
require 'includes/db.php';
require 'includes/refund.php';
$pdo = db();
$out = [];
$out['again']     = refund_create($pdo, 1, 'tg_RF1', '10.00', 'x', 'Asha', null);
$out['unpaid']    = refund_create($pdo, 1, 'tg_RF2', '10.00', 'x', 'Asha', null);
$out['zero']      = refund_create($pdo, 1, 'tg_RF1', '0', 'x', 'Asha', null);
$out['negative']  = refund_create($pdo, 1, 'tg_RF1', '-50', 'x', 'Asha', null);
$out['precision'] = refund_create($pdo, 1, 'tg_RF1', '10.123', 'x', 'Asha', null);
$out['foreign']   = refund_create($pdo, 999, 'tg_RF1', '10', 'x', 'Mal', null);
$out['missing']   = refund_create($pdo, 1, 'tg_NOPE', '10', 'x', 'Asha', null);
echo json_encode($out);
`));
for (const [name, label] of [
  ['again', 'A fully refunded order accepts no more refunds'],
  ['unpaid', 'An unpaid order cannot be refunded'],
  ['zero', 'A zero refund is refused'],
  ['negative', 'A negative refund is refused'],
  ['precision', 'A refund with three decimal places is refused'],
  ['foreign', 'Another merchant cannot refund this order'],
  ['missing', 'Refunding an unknown order is refused'],
]) {
  check(label, guards[name].ok === false, JSON.stringify(guards[name]));
}

check('Refunds are written to the audit log', Number(php(`<?php
require 'includes/db.php';
echo db()->query("SELECT COUNT(*) FROM audit_log WHERE action='order.refunded'")->fetchColumn();
`).trim()) === 2, 'audit rows');

// --- UI and presentation ---
const txBody = readFileSync(path.join(root, 'transactions.php'), 'utf8');
check('The transaction page offers a refund form',
  /name="action" value="refund"/.test(txBody) && /Record refund/.test(txBody), 'no refund form');
check('Refunding requires a CSRF token', (() => {
  const form = (txBody.match(/<form method="post" class="refund-form">[\s\S]*?<\/form>/) || [''])[0];
  return /name="csrf"/.test(form) && /value="refund"/.test(form);
})(), 'csrf missing from the refund form');
check('A refunded order has its own status badge',
  /'refunded' => \['refunded', 'Refunded'\]/.test(readFileSync(path.join(root, 'includes/merchant.php'), 'utf8')));
check('The badge has its own colour, not green or grey',
  /\.status-refunded\s*\{/.test(readFileSync(path.join(root, 'assets/css/design-system.css'), 'utf8')));

// Revenue must reconcile: collected minus refunded, not silently shrunk.
const dash = readFileSync(path.join(root, 'dashboard.php'), 'utf8');
check('Dashboard revenue subtracts refunds rather than dropping refunded orders',
  /status IN \('success','refunded'\)/.test(dash) && /\$stats\['net'\]/.test(dash), 'revenue not netted');
check('Dashboard shows collected minus refunded when refunds exist',
  /collected/.test(dash) && /refunded/.test(dash), 'no reconciliation note');

section('Idempotent order creation');

const idemPost = (body, key) => {
  const headers = { 'Content-Type': 'application/json', 'X-Api-Key': apiKey };
  if (key !== undefined) headers['Idempotency-Key'] = key;
  return request({
    method: 'POST', script: path.join(root, 'api/create-order.php'),
    body: JSON.stringify(body), headers,
  });
};
const orderCount = () => Number(php(`<?php
require 'includes/db.php';
echo db()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
`).trim());

const before = orderCount();
const idemKey = 'idem-' + crypto.randomBytes(8).toString('hex');
const payload = { amount: 249.5, note: 'cart_42' };

const firstCall = idemPost(payload, idemKey);
check('First request with a key creates the order', firstCall.status === 201, String(firstCall.status));
check('First request creates exactly one order', orderCount() === before + 1, `${before} -> ${orderCount()}`);

const idemReplay = idemPost(payload, idemKey);
check('A replay returns the original status code', idemReplay.status === 201, String(idemReplay.status));
check('A replay returns the same order', json(idemReplay)?.order_id === json(firstCall)?.order_id,
  `${json(idemReplay)?.order_id} vs ${json(firstCall)?.order_id}`);
check('A replay returns a byte-identical body', idemReplay.body === firstCall.body, 'bodies differ');
check('A replay does not create a second order', orderCount() === before + 1, `${before} -> ${orderCount()}`);
check('A replay is marked as such', (idemReplay.headers || []).some((h) => /Idempotent-Replay:\s*true/i.test(h)),
  JSON.stringify(idemReplay.headers || []).slice(0, 120));

const reused = idemPost({ amount: 999, note: 'different cart' }, idemKey);
check('Reusing a key with a different body is a 409', reused.status === 409, String(reused.status));
check('A rejected reuse creates no order', orderCount() === before + 1, `${before} -> ${orderCount()}`);
check('The 409 explains what to do', /different request body/i.test(String(json(reused)?.error)), String(json(reused)?.error));

// Re-serialising the same intent must still be recognised as a replay.
const reordered = idemPost({ note: 'cart_42', amount: 249.5 }, idemKey);
check('JSON key order does not defeat idempotency', reordered.status === 201 && json(reordered)?.order_id === json(firstCall)?.order_id,
  String(reordered.status));

// Without a key the endpoint keeps its original contract.
const noKeyA = idemPost({ amount: 75 });
const noKeyB = idemPost({ amount: 75 });
check('Omitting the key still creates a new order each time',
  json(noKeyA)?.order_id !== json(noKeyB)?.order_id && orderCount() === before + 3,
  `${json(noKeyA)?.order_id} / ${json(noKeyB)?.order_id}`);

// A validation failure must not consume the key, or the caller can never retry.
const retryKey = 'retry-' + crypto.randomBytes(8).toString('hex');
const invalid = idemPost({ amount: -5 }, retryKey);
const corrected = idemPost({ amount: 120 }, retryKey);
check('A 4xx does not burn the Idempotency-Key', invalid.status === 422 && corrected.status === 201,
  `${invalid.status} then ${corrected.status}`);

// Over-long and non-ASCII keys are rejected rather than silently truncated.
check('An over-long key is rejected', idemPost(payload, 'k'.repeat(200)).status === 400);
check('A key containing a space is rejected', idemPost(payload, 'has space').status === 400);

// Keys are scoped per merchant: another merchant cannot replay into this one.
const scoped = php(`<?php
require 'includes/db.php';
require 'includes/idempotency.php';
$pdo = db();
// A row for merchant 1 must be invisible to merchant 2.
$pdo->prepare('INSERT INTO idempotency_keys(merchant_id,idem_key,request_hash,response_body,response_code,created_at) VALUES(1,?,?,?,201,?)')
    ->execute(['shared-key', hash('sha256','x'), '{"order_id":"tg_A"}', db_now()]);
$h = idem_request_hash('{"amount":1}');
$a = idem_reserve($pdo, 1, 'shared-key', $h);
$b = idem_reserve($pdo, 999, 'shared-key', $h);
echo json_encode(['m1'=>$a['state'],'m999'=>$b['state']]);
`);
const scopedStates = JSON.parse(scoped);
check('Idempotency keys are scoped per merchant',
  scopedStates.m1 === 'mismatch' && scopedStates.m999 === 'new', scoped);

// The canonical hash must ignore formatting, not content.
const hashTest = php(`<?php
require 'includes/idempotency.php';
echo json_encode([
  'same' => idem_request_hash('{"a":1,"b":{"c":2,"d":3}}') === idem_request_hash('{"b":{"d":3,"c":2},"a":1}'),
  'differs' => idem_request_hash('{"a":1}') !== idem_request_hash('{"a":2}'),
]);
`);
const hashes = JSON.parse(hashTest);
check('Request hashing ignores key order but not values',
  hashes.same === true && hashes.differs === true, hashTest);

check('The OpenAPI contract advertises the header',
  /Idempotency-Key/.test(readFileSync(path.join(root, 'openapi.json'), 'utf8')));
check('The documentation explains retry safety',
  /Idempotency-Key/.test(readFileSync(path.join(root, 'docs.php'), 'utf8')));

section('Password recovery');

const resetBody = (sql, extra = {}) => php(`<?php
require 'includes/password-reset.php';
$pdo = db();
${sql}
`, extra);

// The pages must exist and be reachable without a session.
const forgotPage = request({ script: 'forgot-password.php', method: 'GET' });
check('Forgot-password page loads for a signed-out visitor',
  forgotPage.status === 200 && /Reset your password/.test(forgotPage.body || ''), String(forgotPage.status));
check('Login page links to password recovery',
  /forgot-password\.php/.test(String(request({ script: 'login.php' }).body || '')));
check('Reset page rejects a missing token', (() => {
  const r = request({ script: 'reset-password.php', method: 'GET' });
  return r.status === 200 && /invalid, already used, or expired/.test(r.body || '');
})(), 'got ' + String(request({ script: 'reset-password.php' }).status));

// A real request must create a hashed token and never store the raw one.
const minted = JSON.parse(resetBody(`
$before = (int)$pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn();
tg_password_reset_request('merchant@example.com');
$row = $pdo->query('SELECT * FROM password_resets ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
echo json_encode(['before'=>$before,'row'=>$row]);
`));
check('Requesting a reset stores a token', Number(minted.row?.id) > 0, JSON.stringify(minted).slice(0, 160));
check('Stored token is a 64-char hash, not the token itself',
  /^[a-f0-9]{64}$/.test(String(minted.row?.token_hash)) && minted.row?.token === undefined,
  JSON.stringify(minted.row));

// Unknown addresses must be indistinguishable from real ones.
const unknown = JSON.parse(resetBody(`echo json_encode(['r'=>tg_password_reset_request('nobody@example.com'),'n'=>(int)$pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn()]);`));
check('Unknown address returns success without creating a token',
  unknown.r === true && unknown.n === Number(minted.row?.id), JSON.stringify(unknown));

// A second request must supersede the first, so old emails stop working.
const supersede = JSON.parse(resetBody(`
tg_password_reset_request('merchant@example.com');
tg_password_reset_request('merchant@example.com');
echo json_encode([
  'live'=>(int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE used_at IS NULL')->fetchColumn(),
  'used'=>(int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE used_at IS NOT NULL')->fetchColumn(),
]);
`));
check('A new request invalidates the previous link',
  supersede.live === 1 && supersede.used >= 1, JSON.stringify(supersede));

// Full consume: weak rejected, good accepted, password changed, replay dead.
const consumed = JSON.parse(resetBody(`
$token = bin2hex(random_bytes(32));
$pdo->prepare('UPDATE password_resets SET token_hash=? WHERE used_at IS NULL')->execute([hash('sha256',$token)]);
$weak = tg_password_reset_consume($token, 'short');
$good = tg_password_reset_consume($token, 'a-much-better-password');
$hash = $pdo->query('SELECT password FROM merchants WHERE id=1')->fetchColumn();
$replay = tg_password_reset_consume($token, 'another-password');
echo json_encode([
  'weak'=>$weak,'good'=>$good,
  'verifies'=>password_verify('a-much-better-password',$hash),
  'old_gone'=>!password_verify('correct-horse-battery',$hash),
  'replay'=>$replay,
]);
`));
check('Recovery rejects a short password', /at least 8 characters/.test(String(consumed.weak)), String(consumed.weak));
check('Recovery accepts a valid password', consumed.good === '', String(consumed.good));
check('Recovery actually changes the stored password',
  consumed.verifies === true && consumed.old_gone === true, JSON.stringify(consumed));
check('A used link cannot be replayed', /already used|invalid/.test(String(consumed.replay)), String(consumed.replay));

// Expired tokens must be refused even though the hash matches.
const expired = JSON.parse(resetBody(`
$token = bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO password_resets(merchant_id,token_hash,expires_at,created_at) VALUES(1,?,?,?)')
    ->execute([hash('sha256',$token), date('Y-m-d H:i:s', time()-60), db_now()]);
echo json_encode(['r'=>tg_password_reset_consume($token,'a-much-better-password')]);
`));
check('An expired link is refused', /invalid|expired/.test(String(expired.r)), String(expired.r));

// Garbage and traversal attempts must not reach the database.
const garbage = JSON.parse(resetBody(`echo json_encode(['a'=>tg_password_reset_lookup('../../etc/passwd'),'b'=>tg_password_reset_lookup(str_repeat('z',64))]);`));
check('Malformed tokens are rejected before any query',
  garbage.a === null && garbage.b === null, JSON.stringify(garbage));

// The reset link must never be built from an attacker-controlled Host header.
const baseUrl = JSON.parse(resetBody(`
$_SERVER['HTTP_HOST'] = 'evil.example.com';
echo json_encode(['url'=>tg_public_base_url()]);
`));
check('Reset links use APP_URL, not the request Host header',
  String(baseUrl.url).startsWith('http://localhost:8080'), String(baseUrl.url));

// ============================================================
// Pagination
// ------------------------------------------------------------
// tickets.php and invoices.php loaded every row the merchant had. These check
// the paging math and that older rows are reachable rather than just cut off.
// ============================================================
// ============================================================
// API key usage tracking
// ------------------------------------------------------------
// `api_key_last_used_at` was in both schemas from the first commit and never
// written, so nothing could tell a merchant whether a key was still in use.
// ============================================================
section('API key usage');

const keyTouch = JSON.parse(php(`<?php
$pdo = new PDO('sqlite:'.getenv('DB_SQLITE_PATH'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
require 'includes/db.php';
$pdo->prepare('UPDATE merchants SET api_key_last_used_at=NULL WHERE id=1')->execute();
$before = $pdo->query('SELECT api_key_last_used_at FROM merchants WHERE id=1')->fetchColumn();
merchant_touch_api_key(1);
$after = $pdo->query('SELECT api_key_last_used_at FROM merchants WHERE id=1')->fetchColumn();
// A second call in the same process must not write again.
$pdo->prepare('UPDATE merchants SET api_key_last_used_at=? WHERE id=1')->execute(['2020-01-01 00:00:00']);
merchant_touch_api_key(1);
$throttled = $pdo->query('SELECT api_key_last_used_at FROM merchants WHERE id=1')->fetchColumn();
echo json_encode(['before'=>$before,'after'=>$after,'throttled'=>$throttled]);
`));
check('An unused key starts with no last-used timestamp', keyTouch.before === null, JSON.stringify(keyTouch.before));
check('Authenticating an API call records its use', Boolean(keyTouch.after), JSON.stringify(keyTouch.after));
check('Usage writes are throttled per request', keyTouch.throttled === '2020-01-01 00:00:00', JSON.stringify(keyTouch.throttled));
check('The API key page shows when the key was last used',
  /api_key_last_used_at/.test(readFileSync(path.join(root, 'api-keys.php'), 'utf8'))
  && /tg_time_ago/.test(readFileSync(path.join(root, 'api-keys.php'), 'utf8')));

section('Pagination');

const pagerMath = JSON.parse(php(`<?php
require 'includes/pager.php';
$_GET = ['page'=>'3','per_page'=>'10'];
$a = tg_paginate(95, 25);
$_GET = ['page'=>'99','per_page'=>'10'];
$b = tg_paginate(95, 25);
$_GET = ['page'=>'0','per_page'=>'9999'];
$c = tg_paginate(95, 25);
echo json_encode(['a'=>$a,'b'=>$b,'c'=>$c]);
`));
check('Pagination clamps a page past the end', pagerMath.b?.page === pagerMath.b?.pages, JSON.stringify(pagerMath.b));
check('Pagination rejects an absurd page size', pagerMath.c?.perPage === 25, JSON.stringify(pagerMath.c));
// 95 rows at 10 per page is 10 pages; page 3 starts at index 20.
check('Pagination computes the offset from the page',
  pagerMath.a?.offset === 20 && pagerMath.a?.pages === 10, JSON.stringify(pagerMath.a));

const pagerRender = php(`<?php
require 'includes/pager.php';
$_GET = ['page'=>'2'];
$_SERVER['REQUEST_URI'] = '/tickets.php?status=open';
echo tg_pager(tg_paginate(95, 25), ['status'=>'open']);
`);
check('Pager renders page links and keeps the filter',
  /aria-current="page"/.test(String(pagerRender)) && /status=open/.test(String(pagerRender)),
  String(pagerRender).slice(0, 120));
check('Pager is hidden for a single page',
  php(`<?php require 'includes/pager.php'; echo '['.tg_pager(tg_paginate(3, 25)).']';`) === '[]');

// Both list pages must actually carry the control and a LIMIT.
for (const page of ['tickets.php', 'invoices.php']) {
  const body = readFileSync(path.join(root, page), 'utf8');
  check(`${page} pages its table`, /LIMIT/.test(body) && /tg_pager\(/.test(body), 'no LIMIT or pager');
}
check('Invoice totals cover every invoice, not just the visible page',
  /SELECT COUNT\(\*\) n,[\s\S]{0,300}FROM invoices/.test(readFileSync(path.join(root, 'invoices.php'), 'utf8')),
  'totals are still computed from the fetched rows');

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

section('Checkout windows, API activity and billing records');
const featureSeed = JSON.parse(php(`<?php
require 'includes/db.php';
$pdo = db();
$pdo->prepare('INSERT INTO merchants(name,email,password,api_key,payment_timeout_minutes) VALUES(?,?,?,?,?)')->execute(['Expiry Test','expiry@example.com',password_hash('expiry-test-pass', PASSWORD_DEFAULT),'tg_feature_test_key',7]);
$id=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO merchant_accounts(merchant_id,account_label,upi_id,payee_name,linked_email,google_app_password,is_active,created_at) VALUES(?,?,?,?,?,?,1,?)')->execute([$id,'Main','test@upi','Test','test@example.com','v1:test',db_now()]);
$pdo->prepare('INSERT INTO payment_links(merchant_id,slug,title,amount,is_active,created_at) VALUES(?,?,?,?,1,?)')->execute([$id,'feature-window-link','Window',50,db_now()]);
$pdo->prepare('INSERT INTO invoices(merchant_id,invoice_no,description,amount,tax_amount,status,issued_at) VALUES(?,?,?,?,?,?,?)')->execute([$id,'INV-FEATURE','Test billing',100,18,'paid',db_now()]);
echo json_encode(['merchant'=>$id, 'invoice'=>(int)$pdo->lastInsertId()]);
`));
const featureAuth = signIn('expiry@example.com', 'expiry-test-pass');
const sameSecondsSetting = request({method:'POST', script:path.join(root,'settings.php'), post:{csrf:featureAuth.csrf, action:'general', payment_timeout_minutes:'120', payment_timeout_unit:'seconds'}},featureAuth.session);
const sameSecondsForm = request({method:'GET', script:path.join(root,'settings.php')},featureAuth.session);
check('Settings preserves seconds even when divisible by 60', sameSecondsSetting.body.includes('Payment defaults saved.') && /value="120"/.test(sameSecondsForm.body) && /value="seconds" selected/.test(sameSecondsForm.body));
const sameMinutesSetting = request({method:'POST', script:path.join(root,'settings.php'), post:{csrf:featureAuth.csrf, action:'general', payment_timeout_minutes:'2', payment_timeout_unit:'minutes'}},featureAuth.session);
const sameMinutesForm = request({method:'GET', script:path.join(root,'settings.php')},featureAuth.session);
check('Settings also preserves a minutes selection', sameMinutesSetting.body.includes('Payment defaults saved.') && /value="2"/.test(sameMinutesForm.body) && /value="minutes" selected/.test(sameMinutesForm.body));
const windowSet = request({method:'POST', script:path.join(root,'settings.php'), post:{csrf:featureAuth.csrf, action:'general', payment_timeout_minutes:'45', payment_timeout_unit:'seconds'}},featureAuth.session);
check('Settings saves a seconds payment window', windowSet.status === 200 && windowSet.body.includes('Payment defaults saved.'));
const windowForm = request({method:'GET',script:path.join(root,'settings.php')},featureAuth.session);
check('Settings reloads the chosen seconds and value', /value="45"/.test(windowForm.body) && /value="seconds" selected/.test(windowForm.body));
const badWindow = request({method:'POST',script:path.join(root,'settings.php'), post:{csrf:featureAuth.csrf,action:'general',payment_timeout_minutes:'0',payment_timeout_unit:'seconds'}},featureAuth.session);
check('Settings rejects invalid seconds', badWindow.body.includes('Choose 1–3600 seconds'));
const featureOrder = request({method:'POST',script:path.join(root,'api/create-order.php'),headers:{'X-Api-Key':'tg_feature_test_key'},body:JSON.stringify({amount:50})});
const featureOrderJson = json(featureOrder) || {};
check('API creation uses the configured seconds', featureOrder.status === 201 && featureOrderJson.expires_in_seconds === 45, featureOrder.body);
const storedExpiry = JSON.parse(php(`<?php
require 'includes/db.php'; $q=db()->prepare('SELECT expires_at FROM orders WHERE order_id=?'); $q->execute(['${featureOrderJson.order_id || ''}']); echo json_encode($q->fetchColumn());
`));
check('API response expiry equals the persisted expiry', storedExpiry === featureOrderJson.expires_at);
const linkResponse = request({method:'GET',script:path.join(root,'pay.php'),query:{link:'feature-window-link'}});
const linkExpiry = JSON.parse(php(`<?php
require 'includes/db.php'; $q=db()->prepare("SELECT created_at,expires_at FROM orders WHERE merchant_id=? AND source='link' ORDER BY id DESC LIMIT 1"); $q->execute([${featureSeed.merchant}]); $r=$q->fetch(); echo json_encode($r ? strtotime($r['expires_at'])-strtotime($r['created_at']) : null);
`));
check('Payment links use the same seconds setting', linkResponse.status === 302 && linkExpiry === 45, String(linkExpiry));
const featurePage = request({method:'GET',script:path.join(root,'pay.php'),query:{order_id:featureOrderJson.order_id}});
check('Checkout timer is anchored to server remaining time', featurePage.body.includes('const secondsRemaining = ') && featurePage.body.includes('performance.now()'));

check('Checkout initially renders its timer and labels the seconds', /id="countdown">[0-9]{2}:[0-9]{2}<\/strong>/.test(featurePage.body) && featurePage.body.includes('(min:sec)'));

check('Checkout hides payment options while waiting for authoritative expiry', featurePage.body.includes("getElementById('payment-options').hidden = true") && featurePage.body.includes('Checking payment status'));

// Execute the actual inline checkout script with a controllable clock and API.
const checkoutScript = (featurePage.body.match(/<script>\s*(\(function \(\) \{[\s\S]*?\}\)\(\);)\s*<\/script>/) || [])[1];
const nodes = Object.fromEntries(['qr','payment-status','payment-options','expiry-label','countdown'].map(id => [id, { dataset:{upi:'upi://pay'}, textContent:'', innerHTML:'', hidden:false, className:'' }]));
const timers = [];
let fakeNow = 0, simulatedStatus = 'pending';
const vm = await import('node:vm');
if (checkoutScript) vm.runInNewContext(checkoutScript, {
  document:{ getElementById:id=>nodes[id], querySelectorAll:()=>[] },
  qrcode:()=>({addData(){},make(){},createSvgTag(){return '<svg></svg>'}}),
  performance:{now:()=>fakeNow},
  fetch:async()=>({ok:true,json:async()=>({status:simulatedStatus})}),
  setTimeout:fn=>timers.push(fn), location:{assign(){}}, console,
});
await new Promise(resolve => setImmediate(resolve));
const initialCountdown = nodes.countdown.textContent;
fakeNow = 46000;
const timerBeforeExpiry = timers.shift();
if (timerBeforeExpiry) timerBeforeExpiry();
check('Checkout countdown dynamically reaches zero and hides payment controls', (/^00:(?:[0-4][0-9])$/.test(initialCountdown) && initialCountdown !== '00:00') && nodes.countdown.textContent === '00:00' && nodes['payment-options'].hidden && nodes['payment-status'].textContent.includes('Checking'), JSON.stringify({script:!!checkoutScript,initialCountdown, countdown:nodes.countdown.textContent,status:nodes['payment-status'],timers:timers.length}));
simulatedStatus = 'expired';
const pollAfterExpiry = timers.shift();
if (pollAfterExpiry) pollAfterExpiry();
await new Promise(resolve => setImmediate(resolve));
check('Checkout displays expiry only after the server confirms it', nodes['payment-status'].className === 'wait expired' && nodes['expiry-label'].hidden, JSON.stringify({status:nodes['payment-status'],timers:timers.length}));
check('Checkout responses opt out of caching payment details', (featurePage.headers || []).some(h => /cache-control:.*no-store/i.test(h)));
check('Checkout presents an accessible summary and payment choices',
  featurePage.body.includes('aria-label="Payment summary"') && featurePage.body.includes('aria-label="Payment options"')
  && featurePage.body.includes('Amount to pay') && featurePage.body.includes('Payment status')
  && featurePage.body.includes('role="status" aria-live="polite"'));
const checkoutStyles = readFileSync(path.join(root,'assets/css/checkout.css'),'utf8');
check('Checkout embeds the current CSS in its no-store response', featurePage.body.includes('<style data-checkout-css>'+checkoutStyles+'</style>') && !featurePage.body.includes('href="/assets/css/checkout.css"'));
check('Checkout layout adapts to mobile and respects reduced motion',
  checkoutStyles.includes('@media (max-width: 720px)') && checkoutStyles.includes('@media (max-width: 440px)')
  && checkoutStyles.includes('@media (prefers-reduced-motion: reduce)') && checkoutStyles.includes('animation: none'));
check('Checkout explains the QR and status limitations without JavaScript', featurePage.body.includes('<noscript>') && featurePage.body.includes('JavaScript is needed to render the QR'));
// Run the real checkout script, including pending, errors, recovery and final states.
async function checkoutStateHarness(responses) {
  const ids = ['qr','payment-status','payment-options','expiry-label','countdown','checkout-content-heading','claim-section','claim-result','checkout-finished','checkout-finished-icon','checkout-finished-title','checkout-finished-message'];
  const elements = Object.fromEntries(ids.map(id => [id, {dataset:{upi:'upi://pay?pa=test%40upi&am=50.00'},textContent:'',hidden:id === 'checkout-finished',className:''}]));
  const scheduled = [];
  let n = 0;
  vm.runInNewContext(checkoutScript, {
    document:{getElementById:id=>elements[id],querySelectorAll:()=>[]},
    qrcode:()=>({addData(){},make(){},createSvgTag(){return '<svg></svg>'}}),
    performance:{now:()=>0},
    fetch:async()=>{ const result=responses[Math.min(n++,responses.length-1)]; if (result === 'offline') throw new Error('offline'); return {ok:true,json:async()=>result}; },
    setTimeout:(fn,delay)=>scheduled.push({fn,delay}),location:{assign(){}},console,
  });
  await new Promise(resolve=>setImmediate(resolve));
  return {elements,scheduled};
}
const successfulCheckout = await checkoutStateHarness([{status:'success',utr:'123456789012'}]);
check('Confirmed payment replaces the payment controls with the completed state',
  successfulCheckout.elements['payment-options'].hidden && successfulCheckout.elements['claim-section'].hidden
  && !successfulCheckout.elements['checkout-finished'].hidden && successfulCheckout.elements['checkout-finished-title'].textContent === 'Payment complete'
  && successfulCheckout.elements['payment-status'].textContent.includes('123456789012'));
const refundedCheckout = await checkoutStateHarness([{status:'refunded'}]);
check('A refunded status is not shown as a completed payment',
  refundedCheckout.elements['payment-options'].hidden && refundedCheckout.elements['checkout-finished-title'].textContent === 'Payment unavailable'
  && refundedCheckout.elements['payment-status'].textContent.includes('refunded'));
const offlineCheckout = await checkoutStateHarness(['offline',{status:'pending'}]);
const offlineWarned = offlineCheckout.elements['payment-status'].className === 'wait warning'
  && offlineCheckout.elements['payment-status'].textContent.includes('do not pay again');
const retryPoll = offlineCheckout.scheduled.find(x=>x.delay === 3000);
if (retryPoll) retryPoll.fn();
await new Promise(resolve=>setImmediate(resolve));
check('A failed status check warns without claiming payment and recovers when polling resumes',
  offlineWarned && offlineCheckout.elements['payment-status'].className === 'wait'
  && offlineCheckout.elements['payment-status'].textContent === 'Waiting for payment…');


check('Checkout displays local icons for all four payment options',
  featurePage.body.includes('upi-generic-icon') && featurePage.body.includes('/assets/img/gateways/googlepay.svg')
  && featurePage.body.includes('/assets/img/gateways/phonepe.png') && featurePage.body.includes('/assets/img/gateways/paytm.png')
  && ['Open UPI app', 'Google Pay', 'PhonePe', 'Paytm'].every(name => featurePage.body.includes(name)));
check('Checkout has scan, app and manual payment guidance',
  featurePage.body.includes('Scan to pay') && featurePage.body.includes('Pay with an app')
  && featurePage.body.includes('Use the exact amount') && featurePage.body.includes('not automatic proof of payment'));
check('Google Pay icon is included locally', existsSync(path.join(root,'assets/img/gateways/googlepay.svg')));

function simulateCheckoutLinks(userAgent) {
  const upi = 'upi://pay?pa=merchant%40upi&am=50.00&tn=tg_TEST';
  const packages = ['com.google.android.apps.nbu.paisa.user','com.phonepe.app','net.one97.paytm'];
  const links = packages.map(upiPackage => ({ dataset: { upiPackage }, href: upi }));
  const elements = Object.fromEntries(['qr','payment-status','payment-options','expiry-label','countdown','app-link-note'].map(id => [id, {dataset:{upi},textContent:'',hidden:false}]));
  if (checkoutScript) vm.runInNewContext(checkoutScript, {
    document: {getElementById:id=>elements[id],querySelectorAll:selector=>selector === '[data-upi-package]' ? links : []},
    navigator:{userAgent},
    qrcode:()=>({addData(){},make(){},createSvgTag(){return '<svg></svg>'}}),
    performance:{now:()=>0}, fetch:async()=>({ok:true,json:async()=>({status:'pending'})}),
    setTimeout:()=>{}, location:{assign(){}}, console,
  });
  return {links,upi,note:elements['app-link-note'].textContent};
}
const androidLinks = simulateCheckoutLinks('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/128.0 Mobile Safari/537.36');
check('Android Chrome app buttons target the corresponding packages without changing payment details',
  androidLinks.links.every(link => link.href === androidLinks.upi.replace('upi://','intent://') + '#Intent;scheme=upi;package=' + link.dataset.upiPackage + ';end')
  && androidLinks.note.includes('If unavailable'));
const iphoneLinks = simulateCheckoutLinks('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile Safari/604.1');
const webviewLinks = simulateCheckoutLinks('Mozilla/5.0 (Linux; Android 14; Pixel 7; wv) AppleWebKit/537.36 Chrome/128.0 Mobile Safari/537.36');
check('Other devices and Android WebViews retain the generic UPI links',
  iphoneLinks.links.every(link=>link.href === iphoneLinks.upi) && webviewLinks.links.every(link=>link.href === webviewLinks.upi));
// Reusable link lifetime and per-checkout countdown are separate controls.
const linkSettingsPage = request({method:'GET', script:path.join(root,'payment-links.php')}, featureAuth.session);
check('Payment link expiry offers custom seconds and explains checkout countdowns',
  linkSettingsPage.body.includes('value="custom_seconds"') && linkSettingsPage.body.includes('name="expiry_value"')
  && linkSettingsPage.body.includes('Each checkout has its own payment countdown'));
const expiryUiScript = readFileSync(path.join(root,'assets/js/payment-link-expiry.js'),'utf8');
const expiryUiNodes = {
  'link-expiry': {value:'0',addEventListener(event,cb){this.onchange=cb;}},
  'link-expiry-custom': {hidden:false}, 'link-expiry-value': {},
  'link-expiry-value-label': {}, 'link-expiry-value-help': {},
};
vm.runInNewContext(expiryUiScript,{document:{getElementById:id=>expiryUiNodes[id]}});
const hiddenOnPreset = expiryUiNodes['link-expiry-custom'].hidden && expiryUiNodes['link-expiry-value'].disabled;
expiryUiNodes['link-expiry'].value = 'custom_seconds'; expiryUiNodes['link-expiry'].onchange();
const secondsInputVisible = !expiryUiNodes['link-expiry-custom'].hidden && expiryUiNodes['link-expiry-value'].required && expiryUiNodes['link-expiry-value-label'].textContent === 'Expiry in seconds';
expiryUiNodes['link-expiry'].value = 'custom_minutes'; expiryUiNodes['link-expiry'].onchange();
check('Choosing custom seconds exposes a required numeric field', hiddenOnPreset && secondsInputVisible && expiryUiNodes['link-expiry-value'].max === '10080');
const secondsLink = request({method:'POST',script:path.join(root,'payment-links.php'),post:{csrf:featureAuth.csrf,action:'create',title:'Seconds expiry',amount:'50',slug:'seconds-lifetime',expiry_days:'custom_seconds',expiry_value:'120'}}, featureAuth.session);
const customLinkTimes = JSON.parse(php(`<?php require 'includes/db.php'; $q=db()->prepare('SELECT created_at,expires_at FROM payment_links WHERE merchant_id=? AND slug=?'); $q->execute([${featureSeed.merchant},'seconds-lifetime']); $r=$q->fetch(); echo json_encode($r ? ['seconds'=>strtotime($r['expires_at'])-strtotime($r['created_at']),'formatted'=>date('d M Y, H:i:s',strtotime($r['expires_at']))] : null);`));
check('A custom seconds expiry is saved precisely', secondsLink.body.includes('Payment link created') && customLinkTimes?.seconds === 120, JSON.stringify(customLinkTimes));
check('Payment links show expiry time to the second', secondsLink.body.includes(customLinkTimes?.formatted || 'NOT FOUND') && secondsLink.body.includes('server timezone'));
const customLinkOpen = request({method:'GET',script:path.join(root,'pay.php'),query:{link:'seconds-lifetime'}});
check('A seconds-lifetime link opens before it expires', customLinkOpen.status === 302, String(customLinkOpen.status));
const openedLinkOrder = JSON.parse(php(`<?php require 'includes/db.php'; $q=db()->prepare("SELECT o.order_id FROM orders o JOIN payment_links l ON l.id=o.payment_link_id WHERE l.slug=? ORDER BY o.id DESC LIMIT 1"); $q->execute(['seconds-lifetime']); echo json_encode($q->fetchColumn());`));
php(`<?php require 'includes/db.php'; db()->prepare('UPDATE payment_links SET expires_at=? WHERE slug=?')->execute([db_now(-1),'seconds-lifetime']);`);
const expiredLinkOpen = request({method:'GET',script:path.join(root,'pay.php'),query:{link:'seconds-lifetime'}});
const linkOrderCount = JSON.parse(php(`<?php require 'includes/db.php'; $q=db()->prepare("SELECT COUNT(*) FROM orders o JOIN payment_links l ON l.id=o.payment_link_id WHERE l.slug=?"); $q->execute(['seconds-lifetime']); echo json_encode((int)$q->fetchColumn());`));
check('An expired reusable link cannot create another checkout', expiredLinkOpen.body.includes('unavailable or has expired') && linkOrderCount === 1, String(linkOrderCount));
const previouslyOpened = request({method:'GET',script:path.join(root,'pay.php'),query:{order_id:openedLinkOrder}});
check('A checkout opened before link expiry keeps its own payment window', previouslyOpened.status === 200 && previouslyOpened.body.includes('id="payment-options"'));
const invalidCustoms = ['','0','-1','1.5','604801'];
const badCustomResults = invalidCustoms.map((value,i)=>request({method:'POST',script:path.join(root,'payment-links.php'),post:{csrf:featureAuth.csrf,action:'create',title:'Invalid seconds',amount:'10',slug:'invalid-sec-'+i,expiry_days:'custom_seconds',expiry_value:value}},featureAuth.session));
check('Missing, zero, negative, fractional and excessive seconds are rejected', badCustomResults.every(r=>r.body.includes('Enter a whole number from 1 to 604800 seconds')));
check('Invalid expiry keeps the selected unit and other form values', badCustomResults[3].body.includes('value="custom_seconds" selected') && badCustomResults[3].body.includes('value="1.5"') && badCustomResults[3].body.includes('value="Invalid seconds"'));
const minutesLink = request({method:'POST',script:path.join(root,'payment-links.php'),post:{csrf:featureAuth.csrf,action:'create',title:'Minutes expiry',amount:'50',slug:'minutes-lifetime',expiry_days:'custom_minutes',expiry_value:'3'}},featureAuth.session);
const minuteDiff = JSON.parse(php(`<?php require 'includes/db.php'; $q=db()->prepare('SELECT created_at,expires_at FROM payment_links WHERE slug=?'); $q->execute(['minutes-lifetime']); $r=$q->fetch(); echo json_encode($r ? strtotime($r['expires_at'])-strtotime($r['created_at']) : null);`));
check('Custom minutes are also applied precisely', minutesLink.body.includes('Payment link created') && minuteDiff === 180, String(minuteDiff));



php(`<?php require 'includes/db.php'; db()->prepare("UPDATE orders SET expires_at=? WHERE order_id=?")->execute([db_now(-2),'${featureOrderJson.order_id}']);`);
const expiredStatus = request({method:'GET',script:path.join(root,'api/checkout-status.php'),query:{order_id:featureOrderJson.order_id}});
check('Status endpoint expires orders on the server', json(expiredStatus)?.status === 'expired');

const expiredCheckout = request({method:'GET',script:path.join(root,'pay.php'),query:{order_id:featureOrderJson.order_id}});
check('Expired checkout does not display payment-app buttons', expiredCheckout.status === 200 && !expiredCheckout.body.includes('data-upi-package=') && !expiredCheckout.body.includes('id="payment-options"'));


const signedExpiryOrder = request({method:'POST',script:path.join(root,'api/create-order.php'),headers:{'X-Api-Key':'tg_feature_test_key'},body:JSON.stringify({amount:51})});
const signedExpiryId = json(signedExpiryOrder)?.order_id || '';
php(`<?php require 'includes/db.php'; db()->prepare("UPDATE orders SET expires_at=? WHERE order_id=?")->execute([db_now(-2),'${signedExpiryId}']);`);
const verifiedExpired = request({method:'GET',script:path.join(root,'api/verify-order.php'),headers:{'X-Api-Key':'tg_feature_test_key'},query:{order_id:signedExpiryId}});
check('Authenticated verification expires stale orders without a worker or checkout visit', json(verifiedExpired)?.status === 'expired');

const qrExpiryOrder = request({method:'POST',script:path.join(root,'api/create-order.php'),headers:{'X-Api-Key':'tg_feature_test_key'},body:JSON.stringify({amount:52})});
const qrExpiryId = json(qrExpiryOrder)?.order_id || '';
php(`<?php require 'includes/db.php'; db()->prepare("UPDATE orders SET expires_at=? WHERE order_id=?")->execute([db_now(-2),'${qrExpiryId}']);`);
const expiredQr = request({method:'GET',script:path.join(root,'api/qr-image.php'),query:{order_id:qrExpiryId}});
check('Expired orders no longer expose a QR or UPI intent even without a worker', expiredQr.status === 404 && !expiredQr.body.includes('upi://'));


const activity = JSON.parse(php(`<?php require 'includes/db.php'; $q=db()->prepare('SELECT request_count FROM api_request_activity WHERE merchant_id=? AND activity_date=?'); $q->execute([${featureSeed.merchant},date('Y-m-d')]); echo json_encode((int)$q->fetchColumn());`));
check('Authenticated API requests are recorded in activity', activity === 4, String(activity));
const activityPage = request({method:'GET',script:path.join(root,'api-keys.php')},featureAuth.session);
check('Activity chart shows real request count and collection limitation', activityPage.status === 200 && activityPage.body.includes(': 4 requests') && activityPage.body.includes('earlier activity is not available'));
const invoiceList = request({method:'GET',script:path.join(root,'invoices.php')},featureAuth.session);
const invoiceView = request({method:'GET',script:path.join(root,'billing-invoice.php'),query:{id:String(featureSeed.invoice)}},featureAuth.session);
const foreignInvoiceView = request({method:'GET',script:path.join(root,'billing-invoice.php'),query:{id:String(featureSeed.invoice)}},profileAuth.session);
check('Invoice list links to a printable billing record with tax included', invoiceList.body.includes('/billing-invoice.php?id='+featureSeed.invoice) && invoiceList.body.includes('118.00'));
check('Billing record renders stored totals without claiming tax status', invoiceView.status === 200 && invoiceView.body.includes('118.00') && invoiceView.body.includes('not represented as a statutory tax invoice'));
check('Another merchant cannot view a billing record', foreignInvoiceView.status === 404, String(foreignInvoiceView.status));
const refundReceipt = request({method:'GET',script:path.join(root,'invoice.php'),query:{order_id:'tg_RF1'}},profileAuth.session);
check('Fully refunded payments still have a receipt with refund history', refundReceipt.status === 200 && refundReceipt.body.includes('Fully refunded') && refundReceipt.body.includes('500.00'));

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
