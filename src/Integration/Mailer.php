<?php
declare(strict_types=1);
namespace App\Integration;
use App\Infrastructure\Database;

/**
 * SMTP mailer (Gmail-compatible) with queue persistence.
 * Sends via STARTTLS on port 587. Queue rows are marked sent/failed.
 */
final class Mailer
{
    public function __construct(private Database $db, private array $config) {}

    public function enabled(): bool
    {
        return ($this->config['SMTP_ENABLED'] ?? '0') === '1'
            && ($this->config['SMTP_HOST'] ?? '') !== ''
            && ($this->config['SMTP_USER'] ?? '') !== ''
            && ($this->config['SMTP_PASSWORD'] ?? '') !== '';
    }

    /** Queue an email; returns queue item id. */
    public function queue(string $toEmail, string $subject, string $bodyHtml, ?string $userId = null): string
    {
        $toEmail=trim($toEmail);
        if (!filter_var($toEmail,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$toEmail)) throw new \InvalidArgumentException('Invalid email recipient');
        $id = Database::id();
        $this->db->execute(
            'INSERT INTO email_queue_items(id,user_id,to_email,subject,body,status,attempts,created_at) VALUES(?,?,?,?,?,?,?,?)',
            [$id, $userId, $toEmail, $subject, $bodyHtml, 'pending', 0, time()]
        );
        return $id;
    }

    /** Send all pending queue items (called by scheduler/worker). */
    public function flushQueue(int $limit = 50): array
    {
        $rows = $this->db->all(
            "SELECT * FROM email_queue_items WHERE status='pending' AND attempts<5 ORDER BY created_at LIMIT ?",
            [$limit]
        );
        $sent = 0; $failed = 0;
        foreach ($rows as $row) {
            try {
                $this->send($row['to_email'], $row['subject'], $row['body']);
                $this->db->execute("UPDATE email_queue_items SET status='sent',sent_at=? WHERE id=?", [time(), $row['id']]);
                $sent++;
            } catch (\Throwable $e) {
                $this->db->execute('UPDATE email_queue_items SET attempts=attempts+1 WHERE id=?', [$row['id']]);
                $failed++;
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** Send one email immediately (throws on failure). */
    public function send(string $toEmail, string $subject, string $bodyHtml): void
    {
        if (!$this->enabled()) throw new \RuntimeException('SMTP not configured');
        $host = $this->config['SMTP_HOST'];
        $port = (int)($this->config['SMTP_PORT'] ?? 587);
        $user = $this->config['SMTP_USER'];
        $pass = $this->config['SMTP_PASSWORD'];
        $from = $this->config['SMTP_FROM'] !== '' ? $this->config['SMTP_FROM'] : $user;

        if (!filter_var($toEmail,FILTER_VALIDATE_EMAIL) || !filter_var($from,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invalid email address');
        if (preg_match('/[\r\n]/',(string)($this->config['SMTP_FROM_NAME']??''))) throw new \InvalidArgumentException('Invalid sender name');
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 15);
        if (!$fp) throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        stream_set_timeout($fp, 15);

        $this->smtpCmd($fp, 220);
        $this->smtpSend($fp, "EHLO " . ($this->config['APP_URL'] !== '' ? parse_url($this->config['APP_URL'], PHP_URL_HOST) ?: 'localhost' : 'localhost'));
        $this->smtpCmd($fp, 250);
        // STARTTLS
        $this->smtpSend($fp, 'STARTTLS');
        $this->smtpCmd($fp, 220);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            throw new \RuntimeException('SMTP TLS negotiation failed');
        }
        $this->smtpSend($fp, "EHLO " . ($this->config['APP_URL'] !== '' ? parse_url($this->config['APP_URL'], PHP_URL_HOST) ?: 'localhost' : 'localhost'));
        $this->smtpCmd($fp, 250);
        $this->smtpSend($fp, 'AUTH LOGIN');
        $this->smtpCmd($fp, 334);
        $this->smtpSend($fp, base64_encode($user));
        $this->smtpCmd($fp, 334);
        $this->smtpSend($fp, base64_encode($pass));
        $this->smtpCmd($fp, 235);
        $this->smtpSend($fp, "MAIL FROM:<{$from}>");
        $this->smtpCmd($fp, 250);
        $this->smtpSend($fp, "RCPT TO:<{$toEmail}>");
        $this->smtpCmd($fp, 250);
        $this->smtpSend($fp, 'DATA');
        $this->smtpCmd($fp, 354);
        $headers = [
            'From: ' . ((string)($this->config['SMTP_FROM_NAME'] ?? '') !== '' ? $this->encodeHeader($this->config['SMTP_FROM_NAME']) . ' <' . $from . '>' : $from),
            'To: <' . $toEmail . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . ($this->config['APP_URL'] !== '' ? parse_url($this->config['APP_URL'], PHP_URL_HOST) ?: 'localhost' : 'localhost') . '>',
        ];
        $this->smtpSend($fp, implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($bodyHtml)));
        $this->smtpSend($fp, '.');
        $this->smtpCmd($fp, 250);
        $this->smtpSend($fp, 'QUIT');
        fclose($fp);
    }

    private function smtpSend($fp, string $line): void
    {
        fwrite($fp, $line . "\r\n");
    }

    private function smtpCmd($fp, int $expected): string
    {
        $resp = '';
        while (($line = fgets($fp, 515)) !== false) {
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $code = (int)substr($resp, 0, 3);
        if ($code !== $expected) {
            throw new \RuntimeException("SMTP expected {$expected}, got: " . trim($resp));
        }
        return $resp;
    }

    private function encodeHeader(string $s): string
    {
        if (preg_match('/^[\x20-\x7E]*$/D', $s)) return $s;
        // RFC 2047: each encoded-word must be <= 75 chars, so split long subjects
        // into multiple encoded-words (separated by a space; clients join them).
        $b64 = base64_encode($s);
        $chunks = str_split($b64, 44); // 44 base64 chars -> word of 56 chars incl. delimiters
        return implode(' ', array_map(fn($c) => '=?UTF-8?B?' . $c . '?=', $chunks));
    }
}
