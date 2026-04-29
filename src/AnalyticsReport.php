<?php

declare(strict_types=1);

/**
 * Helpers for admin analytics: normalize viewer URLs and estimate dwell time
 * on PDF pages from heartbeat events (payload.viewer).
 */
final class AnalyticsReport {
  /**
   * Strip volatile query params so admin preview vs normal view groups together.
   */
  public static function normalizeViewerPath(string $path): string {
    $p = parse_url($path, PHP_URL_PATH);
    if (!is_string($p) || $p === '') {
      $p = $path;
    }
    $q = parse_url($path, PHP_URL_QUERY);
    $params = [];
    if (is_string($q) && $q !== '') {
      parse_str($q, $params);
    }
    unset($params['preview_token'], $params['admin_preview'], $params['mode']);
    ksort($params);
    $qs = http_build_query($params);
    return $qs !== '' ? ($p . '?' . $qs) : $p;
  }

  /**
   * @return array{bucket: string, file_id: int|null, is_nda: bool}
   */
  public static function viewerPathBucket(string $path): array {
    $q = parse_url($path, PHP_URL_QUERY);
    $params = [];
    if (is_string($q) && $q !== '') {
      parse_str($q, $params);
    }
    if (!empty($params['nda'])) {
      return ['bucket' => 'nda', 'file_id' => null, 'is_nda' => true];
    }
    $fid = isset($params['file_id']) ? (int)$params['file_id'] : 0;
    if ($fid > 0) {
      return ['bucket' => 'file:' . $fid, 'file_id' => $fid, 'is_nda' => false];
    }
    $norm = self::normalizeViewerPath($path);
    return ['bucket' => 'path:' . md5($norm), 'file_id' => null, 'is_nda' => false];
  }

  /**
   * @param list<array{path: string, total_ms: int, views: int}> $rows
   * @return list<array{bucket: string, label: string, total_ms: int, views: int, file_id: int|null, is_nda: bool}>
   */
  public static function mergeDocumentBuckets(array $rows, callable $labelForBucket): array {
    $out = [];
    foreach ($rows as $r) {
      $path = (string)$r['path'];
      $b = self::viewerPathBucket($path);
      $key = $b['bucket'];
      if (!isset($out[$key])) {
        $out[$key] = [
          'bucket' => $key,
          'label' => $labelForBucket($b),
          'total_ms' => 0,
          'views' => 0,
          'file_id' => $b['file_id'],
          'is_nda' => $b['is_nda'],
        ];
      }
      $out[$key]['total_ms'] += (int)$r['total_ms'];
      $out[$key]['views'] += (int)$r['views'];
    }
    usort($out, static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);
    return array_values($out);
  }

  /**
   * Attribute time between consecutive analytics events to the viewer snapshot at the start of each interval.
   * Estimates dwell on PDF pages or spreadsheet tabs when payloads include viewer.* (15s heartbeats).
   *
   * @param list<array{created_at: string, payload_json: mixed}> $events oldest first
   * @return array<string, int> human-readable key => milliseconds
   */
  public static function viewerDetailDwellFromEvents(array $events, int $maxSegmentMs = 300000): array {
    $buckets = [];
    $n = count($events);
    if ($n < 2) {
      return $buckets;
    }
    for ($i = 0; $i < $n - 1; $i++) {
      $e0 = $events[$i];
      $e1 = $events[$i + 1];
      $t0 = strtotime((string)$e0['created_at']);
      $t1 = strtotime((string)$e1['created_at']);
      if ($t0 === false || $t1 === false) {
        continue;
      }
      $ms = (int)(($t1 - $t0) * 1000);
      if ($ms < 0) {
        continue;
      }
      $ms = min($maxSegmentMs, $ms);
      $payload = self::decodePayload($e0['payload_json'] ?? null);
      $viewer = is_array($payload) && isset($payload['viewer']) && is_array($payload['viewer'])
        ? $payload['viewer']
        : null;
      if ($viewer === null) {
        continue;
      }
      $vk = (string)($viewer['viewer_kind'] ?? '');
      $label = (string)($viewer['file_label'] ?? 'Document');
      if (($viewer['doc_kind'] ?? '') === 'nda') {
        $label = 'NDA · ' . $label;
      }
      $key = '';
      if ($vk === 'pdf') {
        $page = (int)($viewer['pdf_page'] ?? 0);
        if ($page < 1) {
          continue;
        }
        $key = $label . ' · Page ' . $page;
      } elseif ($vk === 'sheet') {
        $tab = trim((string)($viewer['sheet_tab'] ?? ''));
        if ($tab === '') {
          continue;
        }
        $key = $label . ' · Sheet: ' . $tab;
      } else {
        continue;
      }
      $buckets[$key] = ($buckets[$key] ?? 0) + $ms;
    }
    arsort($buckets);
    return $buckets;
  }

  /**
   * Build a session timeline for a single user from analytics_events rows.
   * Each segment attributes the time between consecutive events to the viewer snapshot at the start of the interval.
   *
   * @param list<array{created_at: string, session_id?: string, view_id?: string, payload_json: mixed}> $events oldest first
   * @return list<array{
   *   session_key: string,
   *   started_at: string,
   *   ended_at: string,
   *   total_ms: int,
   *   segments: list<array{
   *     label: string,
   *     ms: int,
   *     t0_ms: int,
   *     t1_ms: int,
   *     viewer_kind: string,
   *     doc_kind: string,
   *     file_id: int|null,
   *     doc_label: string,
   *     page_number: int|null,
   *     sheet_tab: string|null
   *   }>,
   *   view_count: int
   * }>
   */
  public static function buildViewerSessionTimeline(array $events, int $capMs = 1800000, int $maxSegmentMs = 300000): array {
    $groups = [];
    foreach ($events as $e) {
      $sid = isset($e['session_id']) && is_string($e['session_id']) ? $e['session_id'] : '';
      // Session is the full visit: group by session_id only (matches “login to leave” mental model).
      $key = ($sid !== '' ? $sid : 'no_session');
      $groups[$key][] = $e;
    }

    $out = [];
    foreach ($groups as $key => $rows) {
      usort($rows, static fn (array $a, array $b): int => strcmp((string)$a['created_at'], (string)$b['created_at']));
      $n = count($rows);
      if ($n < 2) {
        continue;
      }

      $segments = [];
      $total = 0;
      $viewsSeen = [];
      $sessionStartUnix = strtotime((string)$rows[0]['created_at']);
      if ($sessionStartUnix === false) {
        continue;
      }

      for ($i = 0; $i < $n - 1; $i++) {
        $e0 = $rows[$i];
        $e1 = $rows[$i + 1];
        $t0 = strtotime((string)$e0['created_at']);
        $t1 = strtotime((string)$e1['created_at']);
        if ($t0 === false || $t1 === false) {
          continue;
        }
        $ms = (int)(($t1 - $t0) * 1000);
        if ($ms < 0) {
          continue;
        }
        $ms = min($maxSegmentMs, $ms);
        if ($capMs > 0) {
          $ms = min($ms, $capMs);
        }

        $payload = self::decodePayload($e0['payload_json'] ?? null);
        $viewer = is_array($payload) && isset($payload['viewer']) && is_array($payload['viewer'])
          ? $payload['viewer']
          : null;
        if ($viewer === null) {
          continue;
        }

        $vk = (string)($viewer['viewer_kind'] ?? '');
        $docKind = (string)($viewer['doc_kind'] ?? '');
        $fileId = isset($viewer['file_id']) ? (int)$viewer['file_id'] : null;
        if ($fileId !== null && $fileId < 1) {
          $fileId = null;
        }
        $docLabel = (string)($viewer['file_label'] ?? 'Document');
        $docTitle = ($docKind === 'nda') ? ('NDA · ' . $docLabel) : $docLabel;

        $label = '';
        $pageNumber = null;
        $sheetTab = null;
        if ($vk === 'pdf') {
          $page = (int)($viewer['pdf_page'] ?? 0);
          if ($page > 0) {
            $label = 'Page ' . $page;
            $pageNumber = $page;
          }
        } elseif ($vk === 'sheet') {
          $tab = trim((string)($viewer['sheet_tab'] ?? ''));
          if ($tab !== '') {
            $label = 'Sheet: ' . $tab;
            $sheetTab = $tab;
          }
        } elseif ($vk === 'image') {
          $label = 'Image';
        } else {
          continue;
        }
        if ($label === '') {
          continue;
        }

        $t0Ms = max(0, (int)(($t0 - $sessionStartUnix) * 1000));
        $t1Ms = $t0Ms + $ms;

        // Coalesce consecutive identical segments.
        $lastIdx = count($segments) - 1;
        if (
          $lastIdx >= 0
          && $segments[$lastIdx]['label'] === $label
          && $segments[$lastIdx]['doc_label'] === $docTitle
          && $segments[$lastIdx]['viewer_kind'] === $vk
          && $segments[$lastIdx]['file_id'] === $fileId
          && $segments[$lastIdx]['doc_kind'] === $docKind
        ) {
          $segments[$lastIdx]['ms'] += $ms;
          $segments[$lastIdx]['t1_ms'] = $t1Ms;
        } else {
          $segments[] = [
            'label' => $label,
            'ms' => $ms,
            't0_ms' => $t0Ms,
            't1_ms' => $t1Ms,
            'viewer_kind' => $vk,
            'doc_kind' => $docKind,
            'file_id' => $fileId,
            'doc_label' => $docTitle,
            'page_number' => $pageNumber,
            'sheet_tab' => $sheetTab,
          ];
        }
        $total += $ms;

        $sid = isset($e0['session_id']) && is_string($e0['session_id']) ? $e0['session_id'] : '';
        if ($sid !== '') {
          $viewsSeen[$sid] = true;
        }
      }

      if ($total <= 0 || !$segments) {
        continue;
      }
      $out[] = [
        'session_key' => $key,
        'started_at' => (string)$rows[0]['created_at'],
        'ended_at' => (string)$rows[$n - 1]['created_at'],
        'total_ms' => $total,
        'segments' => $segments,
        'view_count' => count($viewsSeen) ?: 1,
      ];
    }

    usort($out, static fn (array $a, array $b): int => strcmp((string)$b['started_at'], (string)$a['started_at']));
    return $out;
  }

  /** @param mixed $payloadJson */
  private static function decodePayload(mixed $payloadJson): ?array {
    if (is_array($payloadJson)) {
      return $payloadJson;
    }
    if (!is_string($payloadJson) || $payloadJson === '') {
      return null;
    }
    $d = json_decode($payloadJson, true);
    return is_array($d) ? $d : null;
  }
}
