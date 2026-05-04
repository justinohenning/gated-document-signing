<?php

declare(strict_types=1);

/**
 * Outbound mail: SendGrid API (preferred) → SMTP → PHP mail() fallback.
 *
 * Priority:
 *  1. sendgrid_api_key is set → SendGrid v3 HTTP API (most reliable, bypasses SMTP port blocks)
 *  2. smtp.host is set        → raw SMTP AUTH LOGIN (TLS/SSL)
 *  3. fallback                → PHP mail()
 */
final class Mail {
  /**
   * Send HTML verification email. Returns true if accepted for delivery.
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
    $subject  = 'Your sign-in link — ' . $projectName;

    $safeUrl  = Util::h($verifyUrl);
    $safeName = Util::h($projectName);

    $text = "You requested access to \"{$projectName}\".\r\n\r\n"
      . "Click the link below to confirm your email and continue:\r\n{$verifyUrl}\r\n\r\n"
      . "This link expires in 24 hours. If you did not request this, you can ignore this message.\r\n";

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#111827">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px">
    <tr><td align="center">
      <table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:36px 32px">
        <tr><td>
          <h2 style="margin:0 0 8px;font-size:20px;font-weight:600;color:#111827">{$safeName}</h2>
          <p style="margin:0 0 24px;font-size:15px;color:#374151">You requested a sign-in link. Click the button below to confirm your email address and access the project.</p>
          <table cellpadding="0" cellspacing="0"><tr><td style="border-radius:8px;background:#111827">
            <a href="{$safeUrl}" style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px">Open project &rarr;</a>
          </td></tr></table>
          <p style="margin:24px 0 0;font-size:13px;color:#6b7280">Or copy this URL into your browser:<br>
            <span style="word-break:break-all;color:#374151">{$safeUrl}</span></p>
          <hr style="margin:28px 0;border:none;border-top:1px solid #e5e7eb">
          <p style="margin:0;font-size:12px;color:#9ca3af">This link expires in 24 hours. If you did not request access, you can safely ignore this email.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    // 1. SendGrid API key takes priority — most reliable, bypasses SMTP port blocks.
    $sgKey = trim((string)($config['sendgrid_api_key'] ?? ''));
    if ($sgKey !== '') {
      return self::sendViaSendGrid($sgKey, $toEmail, $fromAddr, $fromName, $subject, $text, $html);
    }

    // 2. Raw SMTP (AUTH LOGIN + TLS/SSL).
    $smtp = $config['smtp'] ?? null;
    if (is_array($smtp) && trim((string)($smtp['host'] ?? '')) !== '') {
      return self::sendViaSmtp($config, $toEmail, $fromAddr, $fromName, $subject, $text, $html);
    }

    // 3. PHP mail() — last resort; often lands in spam on shared hosting.
    return self::sendViaPhpMail($toEmail, $fromAddr, $fromName, $subject, $text, $html);
  }

  // ── SendGrid v3 HTTP API ────────────────────────────────────────────────────

  private static function sendViaSendGrid(
    string $apiKey,
    string $toEmail,
    string $fromAddr,
    string $fromName,
    string $subject,
    string $textBody,
    string $htmlBody,
  ): bool {
    if (!function_exists('curl_init')) {
      error_log('[gds] SendGrid requires the curl PHP extension — falling back');
      return false;
    }

    $payload = json_encode([
      'personalizations' => [['to' => [['email' => $toEmail]]]],
      'from'    => ['email' => $fromAddr, 'name' => $fromName],
      'subject' => $subject,
      'content' => [
        ['type' => 'text/plain', 'value' => $textBody],
        ['type' => 'text/html',  'value' => $htmlBody],
      ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
      error_log('[gds] SendGrid: failed to encode payload');
      return false;
    }

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $payload,
      CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
      ],
      CURLOPT_TIMEOUT        => 30,
      CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
      error_log('[gds] SendGrid curl error: ' . $curlErr);
      return false;
    }

    // SendGrid returns 202 Accepted on success; 4xx/5xx on error.
    if ($httpCode >= 200 && $httpCode < 300) {
      return true;
    }

    error_log('[gds] SendGrid API error HTTP ' . $httpCode . ': ' . (string)$response);
    return false;
  }

  // ── PHP mail() ─────────────────────────────────────────────────────────────

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

  // ── Raw SMTP (AUTH LOGIN + TLS/SSL) ────────────────────────────────────────

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
    $enc  = strtolower(trim((string)($smtp['encryption'] ?? 'tls')));

    $boundary = 'gds_' . bin2hex(random_bytes(16));
    $payload  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$textBody}\r\n"
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
    $fp  = @stream_socket_client($target, $errno, $errstr, 25, STREAM_CLIENT_CONNECT, $ctx);
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
