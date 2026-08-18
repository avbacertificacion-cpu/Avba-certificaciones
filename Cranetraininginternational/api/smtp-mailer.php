<?php
/**
 * Minimal dependency-free SMTP client used to send the contact form email.
 * Supports SMTPS (implicit TLS, e.g. port 465) and STARTTLS (e.g. port 587)
 * with AUTH LOGIN. No third-party library — this is the whole client.
 */

final class SmtpMailer
{
    /** @var string */ private $host;
    /** @var int */    private $port;
    /** @var string */ private $secure; // "ssl" | "tls" | ""
    /** @var string */ private $username;
    /** @var string */ private $password;
    /** @var int */    private $timeout;

    public function __construct(string $host, int $port, string $secure, string $username, string $password, int $timeout = 15)
    {
        $this->host = $host;
        $this->port = $port;
        $this->secure = $secure;
        $this->username = $username;
        $this->password = $password;
        $this->timeout = $timeout;
    }

    /**
     * @param string $fromEmail
     * @param string $fromName
     * @param string $toEmail
     * @param string $subject
     * @param string $body Plain text body
     * @param string|null $replyTo
     * @throws RuntimeException on any SMTP failure
     */
    public function send(string $fromEmail, string $fromName, string $toEmail, string $subject, string $body, ?string $replyTo = null): void
    {
        $transport = $this->secure === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if (!$socket) {
            throw new RuntimeException("SMTP connection failed: $errstr ($errno)");
        }

        stream_set_timeout($socket, $this->timeout);

        $this->expect($socket, 220);
        $this->command($socket, 'EHLO ' . $this->heloHost(), 250);

        if ($this->secure === 'tls') {
            $this->command($socket, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed');
            }
            $this->command($socket, 'EHLO ' . $this->heloHost(), 250);
        }

        $this->command($socket, 'AUTH LOGIN', 334);
        $this->command($socket, base64_encode($this->username), 334);
        $this->command($socket, base64_encode($this->password), 235);

        $this->command($socket, 'MAIL FROM:<' . $fromEmail . '>', 250);
        $this->command($socket, 'RCPT TO:<' . $toEmail . '>', 250);
        $this->command($socket, 'DATA', 354);

        $headers = [];
        $headers[] = 'From: ' . $this->encodeHeader($fromName) . ' <' . $fromEmail . '>';
        $headers[] = 'To: <' . $toEmail . '>';
        if ($replyTo) {
            $headers[] = 'Reply-To: <' . $replyTo . '>';
        }
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $headers[] = 'Date: ' . date('r');

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $this->dotStuff($body) . "\r\n.";
        $this->command($socket, $data, 250);
        $this->command($socket, 'QUIT', 221);

        fclose($socket);
    }

    private function heloHost(): string
    {
        return gethostname() ?: 'localhost';
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function dotStuff(string $body): string
    {
        return preg_replace('/^\./m', '..', $body);
    }

    private function command($socket, string $line, int $expectedCode): string
    {
        fwrite($socket, $line . "\r\n");
        return $this->expect($socket, $expectedCode);
    }

    private function expect($socket, int $expectedCode): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            // Multi-line SMTP replies use "code-" until the final "code ".
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('SMTP server closed the connection unexpectedly');
        }

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new RuntimeException("Unexpected SMTP response (expected $expectedCode): $response");
        }

        return $response;
    }
}
