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
    $maxRow = $this->db->fetchOne(
      'SELECT COALESCE(MAX(sort_order), -1) AS m FROM project_files WHERE project_id = :pid',
      [':pid' => $projectId],
    );
    $nextOrder = (int)($maxRow['m'] ?? -1) + 1;
    $this->db->exec(
      'INSERT INTO project_files (project_id, original_name, stored_path, size_bytes, sort_order, created_at)
       VALUES (:pid, :on, :sp, :sz, :so, UTC_TIMESTAMP())',
      [':pid' => $projectId, ':on' => $originalName, ':sp' => $storedPath, ':sz' => $sizeBytes, ':so' => $nextOrder],
    );
  }

  public function listFiles(int $projectId): array {
    return $this->db->fetchAll(
      'SELECT * FROM project_files WHERE project_id = :pid AND deleted_at IS NULL ORDER BY sort_order ASC, id DESC',
      [':pid' => $projectId],
    );
  }

  /** Returns the user-visible name: display_name if set, else original_name. */
  public static function displayName(array $fileRow): string {
    $dn = isset($fileRow['display_name']) && is_string($fileRow['display_name']) && $fileRow['display_name'] !== ''
      ? $fileRow['display_name']
      : '';
    return $dn !== '' ? $dn : (string)$fileRow['original_name'];
  }

  /**
   * @param int[] $orderedFileIds All active (non-deleted) file IDs for this project, top to bottom.
   */
  public function reorderProjectFiles(int $projectId, array $orderedFileIds): void {
    $ids = [];
    foreach ($orderedFileIds as $fid) {
      $fid = (int)$fid;
      if ($fid > 0 && !in_array($fid, $ids, true)) {
        $ids[] = $fid;
      }
    }
    $rows = $this->db->fetchAll('SELECT id FROM project_files WHERE project_id = :pid AND deleted_at IS NULL', [':pid' => $projectId]);
    $all = array_map(static fn(array $r): int => (int)$r['id'], $rows);
    sort($all);
    $sorted = $ids;
    sort($sorted);
    if ($all !== $sorted || count($ids) !== count($all)) {
      throw new \InvalidArgumentException('File order must list each project file exactly once.');
    }
    $pos = 0;
    foreach ($ids as $fid) {
      $this->db->exec(
        'UPDATE project_files SET sort_order = :o WHERE id = :id AND project_id = :pid',
        [':o' => $pos++, ':id' => $fid, ':pid' => $projectId],
      );
    }
  }

  public function getFile(int $fileId): ?array {
    return $this->db->fetchOne('SELECT * FROM project_files WHERE id = :id LIMIT 1', [':id' => $fileId]);
  }

  /**
   * Soft-delete a project file: mark deleted_at so the row is hidden from file lists but
   * analytics data (which references file_id via URL paths) is preserved for reporting.
   * Also physically removes the stored file from disk.
   */
  public function deleteProjectFile(int $projectId, int $fileId): bool {
    $row = $this->getFile($fileId);
    if (!$row || (int)$row['project_id'] !== $projectId) {
      return false;
    }
    // Soft-delete: preserves the row so analytics can still resolve the file name.
    $this->db->exec(
      'UPDATE project_files SET deleted_at = UTC_TIMESTAMP() WHERE id = :id AND project_id = :pid AND deleted_at IS NULL',
      [':id' => $fileId, ':pid' => $projectId],
    );
    // Remove physical file if it is safely inside this project's files directory.
    $stored = (string)$row['stored_path'];
    if ($stored !== '' && is_file($stored)) {
      $storedReal = realpath($stored);
      $dirs = $this->ensureProjectDirs($projectId);
      $filesDirReal = realpath($dirs['files']);
      if (
        $storedReal !== false
        && $filesDirReal !== false
        && str_starts_with($storedReal, $filesDirReal . DIRECTORY_SEPARATOR)
      ) {
        @unlink($storedReal);
      }
    }
    return true;
  }

  /**
   * Rename a project file (sets display_name; original_name and stored path are unchanged).
   * Pass an empty string to clear the custom name and revert to original_name.
   */
  public function renameFile(int $projectId, int $fileId, string $displayName): bool {
    $row = $this->getFile($fileId);
    if (!$row || (int)$row['project_id'] !== $projectId) {
      return false;
    }
    $dn = trim($displayName);
    $this->db->exec(
      'UPDATE project_files SET display_name = :dn WHERE id = :id AND project_id = :pid',
      [':dn' => $dn !== '' ? $dn : null, ':id' => $fileId, ':pid' => $projectId],
    );
    return true;
  }

  public function ensureProjectDirs(int $projectId): array {
    $base = rtrim($this->config['storage_dir'], '/');
    $proj = $base . '/projects/' . $projectId;
    $ndaDir = $proj . '/nda';
    $contractDir = $proj . '/contract';
    $filesDir = $proj . '/files';
    $signedDir = $proj . '/signed';
    $sigDir = $proj . '/signatures';
    $wmDir = $proj . '/watermark';

    foreach ([$base, $base . '/projects', $proj, $ndaDir, $contractDir, $filesDir, $signedDir, $sigDir, $wmDir] as $d) {
      if (!is_dir($d)) {
        mkdir($d, 0770, true);
      }
    }

    return [
      'project' => $proj,
      'nda' => $ndaDir,
      'contract' => $contractDir,
      'files' => $filesDir,
      'signed' => $signedDir,
      'signatures' => $sigDir,
      'watermark' => $wmDir,
    ];
  }
}

