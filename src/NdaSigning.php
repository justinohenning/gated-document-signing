<?php

final class NdaSigning {
  public function __construct(private Database $db, private array $config) {}

  public function hasSigned(int $projectId, string $email): bool {
    $row = $this->db->fetchOne(
      'SELECT id FROM signatures WHERE project_id = :pid AND signer_email = :em LIMIT 1',
      [':pid' => $projectId, ':em' => $email],
    );
    return $row !== null;
  }

  public function getSignatureRecord(int $projectId, string $email): ?array {
    return $this->db->fetchOne(
      'SELECT * FROM signatures WHERE project_id = :pid AND signer_email = :em ORDER BY id DESC LIMIT 1',
      [':pid' => $projectId, ':em' => $email],
    );
  }

  public function listSignaturesForProject(int $projectId): array {
    return $this->db->fetchAll(
      'SELECT * FROM signatures WHERE project_id = :pid ORDER BY id DESC',
      [':pid' => $projectId],
    );
  }

  public function issueAccessToken(int $projectId, string $email): string {
    $token = Util::randomToken(24);
    $hash = hash('sha256', $token . '|' . $this->config['app_secret']);
    $this->db->exec(
      'INSERT INTO access_tokens (project_id, signer_email, token_hash, created_at, last_used_at)
       VALUES (:pid, :em, :th, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
      [':pid' => $projectId, ':em' => $email, ':th' => $hash],
    );
    return $token;
  }

  public function validateAccessToken(int $projectId, string $token): ?string {
    $hash = hash('sha256', $token . '|' . $this->config['app_secret']);
    $row = $this->db->fetchOne(
      'SELECT signer_email FROM access_tokens WHERE project_id = :pid AND token_hash = :th LIMIT 1',
      [':pid' => $projectId, ':th' => $hash],
    );
    if (!$row) return null;
    $this->db->exec(
      'UPDATE access_tokens SET last_used_at = UTC_TIMESTAMP() WHERE project_id = :pid AND token_hash = :th',
      [':pid' => $projectId, ':th' => $hash],
    );
    return (string)$row['signer_email'];
  }

  public function saveSignaturePng(string $dataUrlPng, string $destPath): void {
    if (!str_starts_with($dataUrlPng, 'data:image/png;base64,')) {
      throw new \RuntimeException('Invalid signature format.');
    }
    $b64 = substr($dataUrlPng, strlen('data:image/png;base64,'));
    $bin = base64_decode($b64, true);
    if ($bin === false) {
      throw new \RuntimeException('Invalid base64 signature.');
    }
    file_put_contents($destPath, $bin);
  }

  public function recordSigning(array $params): int {
    $this->db->exec(
      'INSERT INTO signatures (
         project_id, signer_email, signer_name, signer_position, signer_address,
         signed_at, ip_address, user_agent,
         signature_image_path, signed_receipt_path, signed_pdf_path
       ) VALUES (
         :pid, :em, :nm, :pos, :addr,
         UTC_TIMESTAMP(), :ip, :ua,
         :sigp, :recp, :pdfp
       )',
      [
        ':pid' => $params['project_id'],
        ':em' => $params['signer_email'],
        ':nm' => $params['signer_name'],
        ':pos' => $params['signer_position'],
        ':addr' => $params['signer_address'] ?? '',
        ':ip' => $params['ip_address'],
        ':ua' => $params['user_agent'],
        ':sigp' => $params['signature_image_path'],
        ':recp' => $params['signed_receipt_path'],
        ':pdfp' => $params['signed_pdf_path'] ?? null,
      ],
    );
    return (int)$this->db->pdo()->lastInsertId();
  }
}

