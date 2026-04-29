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

