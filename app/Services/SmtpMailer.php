<?php

class SmtpMailer
{
    public function sendPasswordResetCode(string $recipient, string $code): void
    {
        $host = getenv('EMAIL_SMTP_HOST');
        $port = filter_var(getenv('EMAIL_SMTP_PORT'), FILTER_VALIDATE_INT);
        $username = getenv('EMAIL_SMTP_USER');
        $password = getenv('EMAIL_SMTP_APP_PASSWORD');
        $fromAddress = getenv('EMAIL_FROM_ADDRESS');

        if (
            !is_string($host) || trim($host) === ''
            || $port === false || $port < 1 || $port > 65535
            || !is_string($username) || trim($username) === ''
            || !is_string($password) || trim($password) === ''
            || !is_string($fromAddress) || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/^\d{6}$/', $code) !== 1
        ) {
            throw new RuntimeException('Email delivery is not configured correctly.');
        }

        $timeout = 15;
        $socket = @stream_socket_client(
            'tcp://' . trim($host) . ':' . $port,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            throw new RuntimeException('Could not connect to the configured email server.');
        }

        stream_set_timeout($socket, $timeout);
        try {
            $this->expectResponse($socket, [220]);
            $this->sendCommand($socket, 'EHLO localhost', [250]);
            $this->sendCommand($socket, 'STARTTLS', [220]);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('Could not establish a secure connection to the email server.');
            }

            $this->sendCommand($socket, 'EHLO localhost', [250]);
            $this->sendCommand($socket, 'AUTH LOGIN', [334]);
            $this->sendCommand($socket, base64_encode(trim($username)), [334]);
            $this->sendCommand($socket, base64_encode(preg_replace('/\s+/', '', $password)), [235]);
            $this->sendCommand($socket, 'MAIL FROM:<' . trim($fromAddress) . '>', [250]);
            $this->sendCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->sendCommand($socket, 'DATA', [354]);

            $message = implode("\r\n", [
                'From: Payroll & Benefits <' . trim($fromAddress) . '>',
                'To: <' . $recipient . '>',
                'Subject: Your password reset code',
                'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                'Your password reset code is ' . $code . '.',
                '',
                'It expires in 10 minutes and can only be used once. If you did not request a reset, you can ignore this email.',
            ]);
            $message = preg_replace('/(?m)^\./', '..', $message);
            $this->writeAll($socket, $message . "\r\n.\r\n");
            $this->expectResponse($socket, [250]);
        } finally {
            fclose($socket);
        }
    }

    private function sendCommand($socket, string $command, array $expectedCodes): void
    {
        $this->writeAll($socket, $command . "\r\n");
        $this->expectResponse($socket, $expectedCodes);
    }

    private function writeAll($socket, string $data): void
    {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('The email server connection was interrupted.');
            }
            $offset += $written;
        }
    }

    private function expectResponse($socket, array $expectedCodes): void
    {
        $responseCode = null;
        do {
            $line = fgets($socket, 1024);
            if ($line === false) {
                throw new RuntimeException('The email server did not return a valid response.');
            }
            if (preg_match('/^(\d{3})([ -])/', $line, $matches) !== 1) {
                throw new RuntimeException('The email server returned an invalid response.');
            }
            $responseCode = (int) $matches[1];
            $isFinalLine = $matches[2] === ' ';
        } while (!$isFinalLine);

        if (!in_array($responseCode, $expectedCodes, true)) {
            throw new RuntimeException('The email server rejected the request (response ' . $responseCode . ').');
        }
    }
}
