<?php

declare(strict_types=1);

require_once __DIR__ . '/Mail.php';
require_once __DIR__ . '/VisitorEmailVerification.php';
require_once __DIR__ . '/Investment.php';

/**
 * Shared application wiring for public/ and admin/ bootstraps.
 */
final class Startup {
  /**
   * @return array{0: Database, 1: Projects, 2: NdaSigning, 3: VisitorEmailVerification, 4: Investment}
   */
  public static function connect(array $config): array {
    Auth::startSession();
    $db = new Database($config);
    $db->ensureApplicationTablesExist();
    $db->ensureEmailVerifyTokensTable();
    $db->ensureProjectFilesSortOrderColumn();
    $db->ensureProjectFilesExtendedColumns();
    $db->ensureInvestmentTables();
    Branding::ensureSchema($db);
    $projects = new Projects($db, $config);
    $ndaSigning = new NdaSigning($db, $config);
    $emailVerification = new VisitorEmailVerification($db, $config);
    $investment = new Investment($db);
    $GLOBALS['gds_branding'] = Branding::get($db, $config);
    return [$db, $projects, $ndaSigning, $emailVerification, $investment];
  }

  public static function failBootstrap(Throwable $e, array $config): void {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    error_log('[gds] bootstrap: ' . $e->getMessage());
    $debug = filter_var($config['debug'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($debug) {
      echo $e->getMessage() . "\n\n" . $e->getTraceAsString();
    } else {
      echo "Application startup failed. Typical causes: wrong database host/name/user/password in config.php, "
        . "empty database (import schema.sql), or missing PDO MySQL driver.\n\n"
        . "Check your hosting PHP error log. To show details on this page temporarily, add "
        . "'debug' => true to config.php (remove after fixing).\n";
    }
    exit;
  }
}
