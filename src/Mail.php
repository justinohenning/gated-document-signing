<?php

declare(strict_types=1);

/**
 * Outbound mail: PHP mail() or optional SMTP (AUTH LOGIN + TLS).
 */
final class Mail {
  /**
   * Send HTML verification email. Returns true if accepted for delivery / SMTP ok.
   */
  public static function sendVerificationEmail(
    array $config,
    string $toEmail,
    string $projectName,
    string $verifyUrl,
  ): bool {
    $fromAddr = trim((string)($config['mail_from_address'] ?? ''));
    if ($fromAddr === '' || !filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
      error_log('[gds] mail_from_address missing or invalid in config.php');
      return false;
    }
    $fromName = (string)($config['mail_from_name'] ?? 'Gated Document Signing');
    $subject = 'Confirm your email — ' . $projectName;
    $text = "Confirm your email to access documents for “{$projectName}”.\r\n\r\nOpen this link:\r\n{$verifyUrl}\r\n\r\nIf you did not request this, you can ignore this message.\r\n";
    $safeUrl = Util::h($verifyUrl);
    $safeName = Util::h($projectName);
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:system-ui,Segoe UI,sans-serif;line-height:1.5;color:#111">'
      . '<p>Confirm your email to access documents for <strong>' . $safeName . '</strong>.</p>'
      . '<p><a href="' . $safeUrl . '">Open project</a></p>'
      . '<p style="font-size:14px;color:#555">Or paste this URL into your browser:<br><span style="word-break:break-all">' . $safeUrl . '</span></p>'
      . '<p style="font-size:13px;color:#777">If you did not request this, you can ignore this message.</p>'
      . '</body></html>';

    $smtp = $config['smtp'] ?? null;
    if (is_array($smtp) && trim((string)($smtp['host'] ?? '')) !== '') {
      return self::sendViaSmtp($config, $toEmail, $fromAddr, $fromName, $subject, $text, $html);
    }
    return self::sendViaPhpMail($toEmail, $fromAddr, $fromName, $subject, $text, $html);
  }

  private static function sendViaPhpMail(
    string $toEmail,
    string $fromAddr,
    string $fromName,
    string $subject,
    string $textBody,
    string $htmlBody,
  ): bool {
    $boundary = 'gds_' . bin2hex(random_bytes(16));
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = [
      'MIME-Version: 1.0',
      'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
      'From: ' . self::mimeEncodeName($fromName) . ' <' . $fromAddr . '>',
      'Reply-To: ' . $fromAddr,
      'X-Mailer: PHP/' . PHP_VERSION,
    ];
    $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$textBody}\r\n"
      . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$htmlBody}\r\n"
      . "--{$boundary}--\r\n";
    return @mail($toEmail, $encodedSubject, $body, implode("\r\n", $headers));
  }

  private static function mimeEncodeName(string $name): string {
    return '=?UTF-8?B?' . base64_encode($name) . '?=';
  }

  /** SMTP with ssl (465) or tls STARTTLS (587) + AUTH LOGIN. */
  private static function sendViaSmtp(
    array $config,
    string $toEmail,
    string $fromAddr,
    string $fromName,
    string $subject,
    string $textBody,
    string $htmlBody,
  ): bool {
    $smtp = $config['smtp'];
    $host = trim((string)($smtp['host'] ?? ''));
    $port = (int)($smtp['port'] ?? 587);
    $user = (string)($smtp['username'] ?? $smtp['user'] ?? '');
    $pass = (string)($smtp['password'] ?? $smtp['pass'] ?? '');
    $enc = strtolower(trim((string)($smtp['encryption'] ?? 'tls')));

    $boundary = 'gds_' . bin2hex(random_bytes(16));
    $payload = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$textBody}\r\n"
      . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$htmlBody}\r\n"
      . "--{$boundary}--\r\n";

    $msg = 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
      . 'From: ' . self::mimeEncodeName($fromName) . " <{$fromAddr}>\r\n"
      . "To: <{$toEmail}>\r\n"
      . "MIME-Version: 1.0\r\n"
      . 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n\r\n"
      . $payload;

    $target = ($enc === 'ssl')
      ? "ssl://{$host}:{$port}"
      : "tcp://{$host}:{$port}";
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($target, $errno, $errstr, 25, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
      error_log("[gds] SMTP connect failed: {$errstr} ({$errno})");
      return false;
    }
    stream_set_timeout($fp, 30);
    try {
      self::smtpExpect($fp, [220]);
      self::smtpSendLine($fp, 'EHLO ' . ($smtp['ehlo_host'] ?? 'localhost') . "\r\n", [250]);
      if ($enc === 'tls') {
        self::smtpSendLine($fp, "STARTTLS\r\n", [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
          throw new \RuntimeException('STARTTLS failed');
        }
        self::smtpSendLine($fp, 'EHLO ' . ($smtp['ehlo_host'] ?? 'localhost') . "\r\n", [250]);
      }
      if ($user !== '' || $pass !== '') {
        self::smtpSendLine($fp, "AUTH LOGIN\r\n", [334]);
        self::smtpSendLine($fp, base64_encode($user) . "\r\n", [334]);
        self::smtpSendLine($fp, base64_encode($pass) . "\r\n", [235]);
      }
      self::smtpSendLine($fp, "MAIL FROM:<{$fromAddr}>\r\n", [250]);
      self::smtpSendLine($fp, "RCPT TO:<{$toEmail}>\r\n", [250, 251]);
      self::smtpSendLine($fp, "DATA\r\n", [354]);
      $dataBody = str_replace("\r\n", "\n", $msg);
      $dataBody = str_replace("\n", "\r\n", $dataBody);
      $dataBody = preg_replace('/^\./m', '..', $dataBody);
      fwrite($fp, $dataBody . "\r\n.\r\n");
      self::smtpReadMultiline($fp, [250]);
      self::smtpSendLine($fp, "QUIT\r\n", [221]);
      return true;
    } catch (\Throwable $e) {
      error_log('[gds] SMTP send failed: ' . $e->getMessage());
      return false;
    } finally {
      fclose($fp);
    }
  }

  /** @param resource $fp */
  private static function smtpSendLine($fp, string $line, array $expectCodes): void {
    fwrite($fp, $line);
    self::smtpReadMultiline($fp, $expectCodes);
  }

  /** @param resource $fp */
  private static function smtpReadMultiline($fp, array $expectCodes): string {
    $buf = '';
    while (($line = @fgets($fp, 515)) !== false) {
      $buf .= $line;
      if (strlen($line) >= 4 && $line[3] === ' ') {
        break;
      }
    }
    $code = (int)substr($buf, 0, 3);
    if (!in_array($code, $expectCodes, true)) {
      throw new \RuntimeException('SMTP unexpected: ' . trim($buf));
    }
    return $buf;
  }

  /** @param resource $fp */
  private static function smtpExpect($fp, array $expectCodes): void {
    self::smtpReadMultiline($fp, $expectCodes);
  }
}
