<?php

final class Database {
  private \PDO $pdo;

  public function __construct(array $config) {
    $this->pdo = new \PDO(
      $config['db']['dsn'],
      $config['db']['user'],
      $config['db']['pass'],
      [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
      ],
    );
  }

  public function pdo(): \PDO {
    return $this->pdo;
  }

  /** Ensures the admins table exists (install.php) when schema.sql was not fully imported. */
  public function ensureAdminsTableExists(): void {
    $this->pdo->exec(
      'CREATE TABLE IF NOT EXISTS admins (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(255) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_admin_email (email)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    );
  }

  public function fetchOne(string $sql, array $params = []): ?array {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
  }

  public function fetchAll(string $sql, array $params = []): array {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
  }

  public function exec(string $sql, array $params = []): int {
    $stmt = $this->pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
  }
}

