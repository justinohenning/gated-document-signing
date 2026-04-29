<?php

final class Projects {
  public function __construct(private Database $db, private array $config) {}

  public function createProject(string $name): array {
    $token = Util::randomToken(18);
    $this->db->exec(
      'INSERT INTO projects (name, token, is_active, allow_downloads, watermark_enabled, welcome_enabled, welcome_message, created_at)
       VALUES (:name, :token, 1, 1, 0, 0, NULL, UTC_TIMESTAMP())',
      [':name' => $name, ':token' => $token],
    );
    $id = (int)$this->db->pdo()->lastInsertId();
    return ['id' => $id, 'token' => $token];
  }

  public function getByToken(string $token): ?array {
    return $this->db->fetchOne('SELECT * FROM projects WHERE token = :t LIMIT 1', [':t' => $token]);
  }

  public function listProjects(): array {
    return $this->db->fetchAll('SELECT * FROM projects ORDER BY id DESC');
  }

  public function attachNda(int $projectId, string $originalName, string $storedPath): void {
    $this->db->exec(
      'INSERT INTO nda_templates (project_id, original_name, stored_path, created_at) VALUES (:pid, :on, :sp, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE original_name = VALUES(original_name), stored_path = VALUES(stored_path), created_at = UTC_TIMESTAMP()',
      [':pid' => $projectId, ':on' => $originalName, ':sp' => $storedPath],
    );
  }

  public function getNda(int $projectId): ?array {
    return $this->db->fetchOne('SELECT * FROM nda_templates WHERE project_id = :pid LIMIT 1', [':pid' => $projectId]);
  }

  public function upsertNdaFieldDef(int $projectId, array $def): void {
    $this->db->exec(
      'INSERT INTO nda_field_defs (project_id, field_key, field_label, page_num, x, y, w, h, required, created_at, updated_at)
       VALUES (:pid, :fk, :fl, :pg, :x, :y, :w, :h, :req, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
      [
        ':pid' => $projectId,
        ':fk' => (string)$def['field_key'],
        ':fl' => isset($def['field_label']) ? (string)$def['field_label'] : null,
        ':pg' => (int)$def['page_num'],
        ':x' => (float)$def['x'],
        ':y' => (float)$def['y'],
        ':w' => (float)$def['w'],
        ':h' => (float)$def['h'],
        ':req' => (int)($def['required'] ?? 1),
      ],
    );
  }

  public function listNdaFieldDefs(int $projectId): array {
    return $this->db->fetchAll('SELECT * FROM nda_field_defs WHERE project_id = :pid ORDER BY id ASC', [':pid' => $projectId]);
  }

  public function replaceNdaFieldDefs(int $projectId, array $defs): void {
    $this->db->exec('DELETE FROM nda_field_defs WHERE project_id = :pid', [':pid' => $projectId]);
    foreach ($defs as $def) {
      if (!is_array($def)) continue;
      $this->upsertNdaFieldDef($projectId, $def);
    }
  }

  public function addFile(int $projectId, string $originalName, string $storedPath, int $sizeBytes): void {
    $this->db->exec(
      'INSERT INTO project_files (project_id, original_name, stored_path, size_bytes, created_at) VALUES (:pid, :on, :sp, :sz, UTC_TIMESTAMP())',
      [':pid' => $projectId, ':on' => $originalName, ':sp' => $storedPath, ':sz' => $sizeBytes],
    );
  }

  public function listFiles(int $projectId): array {
    return $this->db->fetchAll('SELECT * FROM project_files WHERE project_id = :pid ORDER BY id DESC', [':pid' => $projectId]);
  }

  public function getFile(int $fileId): ?array {
    return $this->db->fetchOne('SELECT * FROM project_files WHERE id = :id LIMIT 1', [':id' => $fileId]);
  }

  /**
   * Remove a project file row and its stored file. Path must live under this project's files directory.
   */
  public function deleteProjectFile(int $projectId, int $fileId): bool {
    $row = $this->getFile($fileId);
    if (!$row || (int)$row['project_id'] !== $projectId) {
      return false;
    }
    $stored = (string)$row['stored_path'];
    $dirs = $this->ensureProjectDirs($projectId);
    $filesDir = $dirs['files'];
    $filesDirReal = realpath($filesDir);
    if ($filesDirReal === false) {
      return false;
    }
    if ($stored === '') {
      return false;
    }
    $underFiles = str_starts_with($stored, $filesDir . '/') || str_starts_with($stored, $filesDir . DIRECTORY_SEPARATOR);
    if (!$underFiles && $stored !== $filesDir) {
      return false;
    }
    if (is_file($stored)) {
      $real = realpath($stored);
      if ($real === false || !str_starts_with($real, $filesDirReal . DIRECTORY_SEPARATOR)) {
        return false;
      }
      @unlink($real);
    }
    $this->db->exec(
      'DELETE FROM project_files WHERE id = :id AND project_id = :pid',
      [':id' => $fileId, ':pid' => $projectId],
    );
    return true;
  }

  public function ensureProjectDirs(int $projectId): array {
    $base = rtrim($this->config['storage_dir'], '/');
    $proj = $base . '/projects/' . $projectId;
    $ndaDir = $proj . '/nda';
    $filesDir = $proj . '/files';
    $signedDir = $proj . '/signed';
    $sigDir = $proj . '/signatures';
    $wmDir = $proj . '/watermark';

    foreach ([$base, $base . '/projects', $proj, $ndaDir, $filesDir, $signedDir, $sigDir, $wmDir] as $d) {
      if (!is_dir($d)) {
        mkdir($d, 0770, true);
      }
    }

    return [
      'project' => $proj,
      'nda' => $ndaDir,
      'files' => $filesDir,
      'signed' => $signedDir,
      'signatures' => $sigDir,
      'watermark' => $wmDir,
    ];
  }
}

