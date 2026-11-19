<?php
declare(strict_types=1);
/**
 * Minimal, dependency-free IMAP client.
 *
 * PHP's `imap` extension is not shipped by the official images any more and its
 * Debian build dependency (libc-client2007e-dev) no longer exists, so a container
 * build that needs it is fragile. Everything this application needs is a handful
 * of commands over an TLS socket, which PHP already speaks:
 *
 *   LOGIN, SELECT, UID SEARCH, UID FETCH, UID STORE, LOGOUT
 *
 * Only server data that the receipt parser consumes is fetched, and the whole
 * message body is retrieved in one round trip (`BODY.PEEK[]`) so the MIME parsing
 * is done locally by includes/mime.php and can be unit-tested without a network.
 *
 * The connection is read-only in practice: `PEEK` never sets \Seen by itself and
 * the worker sets \Seen explicitly after a message has been processed.
 */
/** Line/byte oriented transport used by TgImapClient. */
interface TgImapIo
{
    public function write(string $data): int|false;
    public function readLine(): ?string;
    public function readBytes(int $length): string;
    public function close(): void;
}

/** TLS socket transport used in production. */
final class TgImapSocketIo implements TgImapIo
{
    /** @param resource $socket */
    public function __construct(private $socket) {}

    public function write(string $data): int|false { return @fwrite($this->socket, $data); }

    public function readLine(): ?string
    {
        $line = @fgets($this->socket);
        return $line === false ? null : rtrim($line, "\r\n");
    }

    public function readBytes(int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @fread($this->socket, min(8192, $length - strlen($buffer)));
            if ($chunk === false || $chunk === '') break;
            $buffer .= $chunk;
        }
        return $buffer;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) @fclose($this->socket);
    }
}

final class TgImapClient
{
    private TgImapIo $io;
    /** @var resource|null only set when the transport is a real socket */
    private $stream = null;
    private int $tag = 0;
    private string $host;
    private bool $authenticated = false;
    /** @var list<string> */
    private array $capabilities = [];

    public function __construct(TgImapIo $io, string $host = 'unknown')
    {
        $this->io = $io;
        $this->host = $host;
    }

    /**
     * Build a client over an already-open stream. Used by the protocol tests,
     * which script the server side so command and literal handling can be
     * asserted without a network or a real mailbox.
     *
     * @param resource $socket
     */
    public static function fromStream($socket, string $host = 'test.local'): self
    {
        stream_set_timeout($socket, 5);
        $client = new self(new TgImapSocketIo($socket), $host);
        $client->stream = $socket;
        $client->stream = $socket;
        return $client;
    }

    /**
     * Open a TLS connection and log in. Port 993 is implicit TLS; any other port
     * upgrades with STARTTLS, which most providers still support.
     *
     * @throws RuntimeException on connect, TLS or authentication failure
     */
    /**
     * Turn the server's NO/BAD text into a short hint, without echoing secrets.
     *
     * Gmail answers a bad app password and a disabled-IMAP account differently,
     * and those need different fixes, so the distinction is worth surfacing.
     *
     * @param list<string> $lines
     */
    private static function classifyAuthFailure(array $lines, string $user): string
    {
        $text = strtolower(implode(' ', $lines));
        if ($text === '') return 'The server closed the connection without explaining why.';
        if (str_contains($text, 'application-specific password')
            || str_contains($text, 'app password')
            || str_contains($text, 'invalid credentials')) {
            return 'Gmail says: use a 16-character App Password, not your normal password.';
        }
        if (str_contains($text, 'imap') && (str_contains($text, 'disabled') || str_contains($text, 'not enabled'))) {
            return 'Gmail says: IMAP access is disabled for this mailbox. Enable it in Gmail settings.';
        }
        if (str_contains($text, 'web login required') || str_contains($text, 'unsafe')) {
            return 'Google blocked the sign-in. Complete the "web login required" step and try again.';
        }
        if (str_contains($text, 'too many') || str_contains($text, 'rate')) {
            return 'The provider is rate limiting this mailbox. Wait a few minutes.';
        }
        if (str_contains($text, 'locked') || str_contains($text, 'suspended')) {
            return 'The mailbox looks locked or suspended at the provider.';
        }
        if (str_contains($text, 'unknown user') || str_contains($text, 'no such user')) {
            return 'The provider does not recognise that mailbox address.';
        }
        // Generic fallback. Only echo vocabulary the IMAP protocol itself uses,
        // never free text from the server, because a NO reply may quote the
        // address or password that was just rejected.
        foreach ([
            'authenticationfailed' => 'The provider rejected the credentials.',
            'authentication failed' => 'The provider rejected the credentials.',
            'invalid credentials' => 'The provider rejected the credentials.',
            'authorization failed' => 'The mailbox is not authorised for IMAP access.',
            'temporarily' => 'The provider asked to try again shortly.',
            'unavailable' => 'The mailbox is temporarily unavailable at the provider.',
        ] as $needle => $message) {
            if (str_contains($text, $needle)) return $message;
        }
        return 'The server rejected the credentials. Check the address, the app password, and that IMAP is enabled.';
    }

    public static function connect(
        string $host,
        int $port,
        string $user,
        string $password,
        bool $validateCertificate = true,
        int $timeout = 10
    ): self {
        $host = trim($host);
        if (!preg_match('/^[A-Za-z0-9.-]{3,190}$/', $host)) {
            throw new RuntimeException('Invalid IMAP host');
        }
        $implicitTls = $port === 993;
        $remote = ($implicitTls ? 'ssl' : 'tcp').'://'.$host.':'.$port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $validateCertificate,
                'verify_peer_name' => $validateCertificate,
                'allow_self_signed' => !$validateCertificate,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            // Never echo the password. The host is safe to show, and the errstr
            // is what distinguishes a typo from a firewall from a bad cert.
            $detail = $errstr !== '' ? $errstr : 'connection timed out';
            $hint = match (true) {
                str_contains(strtolower($detail), 'getaddrinfo'),
                str_contains(strtolower($detail), 'name or service') => ' — the hostname does not resolve; check for a typo.',
                str_contains(strtolower($detail), 'refused') => ' — the port refused the connection; check the port number.',
                str_contains(strtolower($detail), 'timed out') => ' — no answer; a firewall or your host may block outbound IMAP.',
                str_contains(strtolower($detail), 'certificate'),
                str_contains(strtolower($detail), 'ssl') => ' — the TLS certificate was rejected. Only set IMAP_VALIDATE_CERT=false for a mail server you control.',
                default => '',
            };
            throw new RuntimeException(sprintf('Could not connect to %s:%d (%s)%s', $host, $port, $detail, $hint));
        }
        stream_set_timeout($socket, $timeout);

        $client = new self(new TgImapSocketIo($socket), $host);
        $client->stream = $socket;
        $greeting = $client->readLine();
        if ($greeting === null || !preg_match('/^\*\s+(OK|PREAUTH)/i', $greeting)) {
            $client->close();
            throw new RuntimeException('IMAP server did not send a valid greeting');
        }

        if (!$implicitTls) {
            $client->capabilities = $client->capabilitiesFromGreeting($greeting);
            $client->startTls($host, $validateCertificate);
        }

        $client->login($user, $password);
        return $client;
    }

    /** @return list<string> */
    private function capabilitiesFromGreeting(string $greeting): array
    {
        if (preg_match('/\[CAPABILITY ([^\]]+)\]/i', $greeting, $m)) {
            return preg_split('/\s+/', trim($m[1])) ?: [];
        }
        return [];
    }

    private function startTls(string $host, bool $validateCertificate): void
    {
        if ($this->capabilities && !in_array('STARTTLS', array_map('strtoupper', $this->capabilities), true)) {
            throw new RuntimeException('Server does not support STARTTLS; refusing to send credentials in clear text');
        }
        [$status, $lines] = $this->command('STARTTLS');
        if ($status !== 'OK') {
            throw new RuntimeException('STARTTLS was rejected: '.$this->describe($lines));
        }
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        if ($this->stream === null || !@stream_socket_enable_crypto($this->stream, true, $method)) {
            throw new RuntimeException('TLS handshake with the IMAP server failed');
        }
    }

    private function login(string $user, string $password): void
    {
        [$status, $lines] = $this->command('LOGIN '.$this->literal($user).' '.$this->literal($password));
        if ($status !== 'OK') {
            // Neither the address nor the password is ever echoed back. The
            // server's own NO/BAD text is kept as a short classification only,
            // because it is what tells "wrong password" apart from "IMAP is
            // disabled on this mailbox" — with identical wording the merchant
            // has nothing to act on.
            throw new RuntimeException(
                'IMAP authentication failed — check the mailbox address and app password. '
                .self::classifyAuthFailure($lines, $user)
            );
        }
        $this->authenticated = true;
    }

    /** Number of messages in the selected mailbox. */
    public function numMessages(): int
    {
        [$status, $lines] = $this->command('SELECT INBOX');
        if ($status !== 'OK') {
            throw new RuntimeException('Could not open INBOX: '.$this->describe($lines));
        }
        foreach ($lines as $line) {
            if (preg_match('/^\*\s+(\d+)\s+EXISTS/i', $line, $m)) return (int)$m[1];
        }
        return 0;
    }

    /**
     * All UIDs in the mailbox, ascending. Uses UID SEARCH ALL rather than UNSEEN:
     * a receipt can already be marked read by a phone or web client, and the
     * worker tracks its own watermark instead.
     *
     * @return list<int>
     */
    public function uids(): array
    {
        [$status, $lines] = $this->command('UID SEARCH ALL');
        if ($status !== 'OK') {
            throw new RuntimeException('Could not list messages: '.$this->describe($lines));
        }
        foreach ($lines as $line) {
            if (preg_match('/^\*\s+SEARCH(.*)$/i', $line, $m)) {
                $uids = preg_split('/\s+/', trim($m[1])) ?: [];
                $uids = array_values(array_filter(array_map('intval', $uids), static fn(int $uid): bool => $uid > 0));
                sort($uids);
                return $uids;
            }
        }
        return [];
    }

    /**
     * Fetch the raw RFC822 message. PEEK is essential: it must not mark the
     * message as read, because settlement decides when a message is consumed.
     */
    public function fetchRaw(int $uid): ?string
    {
        if ($uid < 1) return null;
        [$status, $lines, $literal] = $this->command('UID FETCH '.$uid.' (BODY.PEEK[])');
        if ($status !== 'OK') return null;
        return $literal !== '' ? $literal : null;
    }

    /** Mark a message as seen once it has been fully processed. */
    public function markSeen(int $uid): void
    {
        if ($uid < 1) return;
        $this->command('UID STORE '.$uid.' +FLAGS (\\Seen)');
    }

    public function logout(): void
    {
        try {
            // LOGOUT is valid before authentication too (RFC 3501), so send it
            // even when a login attempt failed and the session must be torn down.
            $this->command('LOGOUT');
        } catch (Throwable) {
            // Closing the socket is enough if the server already dropped us.
        }
        $this->close();
    }

    private function close(): void
    {
        $this->io->close();
        $this->authenticated = false;
    }

    /**
     * Send one tagged command and collect the server's response.
     *
     * @return array{0:string,1:list<string>,2:string} [status, untagged lines, first literal payload]
     */
    private function command(string $command): array
    {
        $tag = sprintf('A%04d', ++$this->tag);
        if ($this->write($tag.' '.$command."\r\n") === false) {
            throw new RuntimeException('IMAP connection closed while sending a command');
        }

        $lines = [];
        $literal = '';
        $status = 'NO';
        while (($line = $this->readLine()) !== null) {
            // A literal is announced as "{123}" at the end of the line.
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $expected = (int)$m[1];
                $data = $this->readBytes($expected);
                if (strlen($data) !== $expected) {
                    // A short read means the stream is misaligned: every later
                    // command would read the wrong bytes, so fail loudly instead
                    // of returning a truncated message that cannot be parsed.
                    throw new RuntimeException('IMAP connection ended while reading a message body');
                }
                if ($literal === '') $literal = $data;
                // The rest of the FETCH response continues on the same logical line.
                $continued = $this->readLine();
                $lines[] = rtrim($line).' <literal '.strlen($data).' bytes>';
                if ($continued !== null) {
                    if (str_starts_with($continued, $tag.' ')) {
                        $status = strtoupper(substr($continued, strlen($tag) + 1, 2));
                        break;
                    }
                    $lines[] = $continued;
                }
                continue;
            }
            if (str_starts_with($line, $tag.' ')) {
                $status = strtoupper(substr($line, strlen($tag) + 1, 2));
                break;
            }
            $lines[] = $line;
        }

        if ($status === 'NO' && $lines === []) {
            throw new RuntimeException('IMAP server returned no response');
        }
        return [$status, $lines, $literal];
    }

    /** Quote a value as an IMAP literal so any byte sequence is safe. */
    private function literal(string $value): string
    {
        return '{'.strlen($value)."}\r\n".$value;
    }

    private function write(string $data): int|false
    {
        return $this->io->write($data);
    }

    /** Read one CRLF-terminated line without the terminator. */
    private function readLine(): ?string
    {
        return $this->io->readLine();
    }

    private function readBytes(int $length): string
    {
        return $this->io->readBytes($length);
    }

    /** Short, credential-free description of a failure. */
    private function describe(array $lines): string
    {
        foreach ($lines as $line) {
            if (preg_match('/^\[?[A-Z-]*(?:AUTHENTICATIONFAILED|UNAVAILABLE|PERMISSION|LIMIT)\]?/i', $line)) return 'server rejected the request';
        }
        return 'unexpected server response';
    }

    public function __destruct()
    {
        $this->close();
    }
}
