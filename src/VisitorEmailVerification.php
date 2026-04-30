<?php

declare(strict_types=1);

final class VisitorEmailVerification {
  public function __construct(private Database $db, private array $config) {}

  /**
   * Store a one-time token and send the magic link email.
   * Replaces any pending token for the same project + email.
   */
  public function sendVerificationLink(int $projectId, string $projectToken, string $email, string $projectName): bool {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return false;
    }
    $secret = (string)($this->config['app_secret'] ?? '');
    if ($secret === '') {
      error_log('[gds] app_secret required for email verification tokens');
      return false;
    }

    $this->db->exec(
      'DELETE FROM email_verify_tokens WHERE project_id = :pid AND email = :em',
      [':pid' => $projectId, ':em' => $email],
    );
    $this->db->exec(
      'DELETE FROM email_verify_tokens WHERE expires_at < UTC_TIMESTAMP()',
      [],
    );

    $raw = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw . '|' . $secret);
    $ttlHours = max(1, min(168, (int)($this->config['email_verify_ttl_hours'] ?? 24)));
    $expires = gmdate('Y-m-d H:i:s', time() + $ttlHours * 3600);

    $this->db->exec(
      'INSERT INTO email_verify_tokens (project_id, email, token_hash, expires_at, created_at)
       VALUES (:pid, :em, :th, :ex, UTC_TIMESTAMP())',
      [':pid' => $projectId, ':em' => $email, ':th' => $hash, ':ex' => $expires],
    );

    $url = Util::emailVerificationUrl($this->config, $projectToken, $raw);
    return Mail::sendVerificationEmail($this->config, $email, $projectName, $url);
  }

  /**
   * Validate magic link token and return the verified email, or null.
   * Consumes the token on success.
   */
  public function consumeVerifyToken(int $projectId, string $rawHexToken): ?string {
    $rawHexToken = strtolower(preg_replace('/[^a-f0-9]/', '', $rawHexToken));
    if (strlen($rawHexToken) !== 64) {
      return null;
    }
    $secret = (string)($this->config['app_secret'] ?? '');
    if ($secret === '') {
      return null;
    }
    $hash = hash('sha256', $rawHexToken . '|' . $secret);
    $row = $this->db->fetchOne(
      'SELECT id, email FROM email_verify_tokens
       WHERE project_id = :pid AND token_hash = :th AND expires_at >= UTC_TIMESTAMP()
       LIMIT 1',
      [':pid' => $projectId, ':th' => $hash],
    );
    if (!$row) {
      return null;
    }
    $this->db->exec(
      'DELETE FROM email_verify_tokens WHERE id = :id',
      [':id' => (int)$row['id']],
    );
    return strtolower(trim((string)$row['email']));
  }
}
