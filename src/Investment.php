<?php

declare(strict_types=1);

/**
 * Per-project investment module: funding goal, contract PDF + field defs, visitor commitments.
 */
final class Investment {
  public function __construct(private Database $db) {}

  /**
   * @return array{
   *   enabled:int,
   *   goal_amount:float,
   *   goal_currency:string,
   *   min_commitment:?float,
   *   equity_offered_pct:?float,
   *   project_id?:int
   * }
   */
  public function getSettings(int $projectId): array {
    $row = $this->db->fetchOne(
      'SELECT * FROM investment_settings WHERE project_id = :pid LIMIT 1',
      [':pid' => $projectId],
    );
    if (!$row) {
      return [
        'project_id' => $projectId,
        'enabled' => 0,
        'goal_amount' => 0.0,
        'goal_currency' => 'USD',
        'min_commitment' => null,
        'equity_offered_pct' => null,
      ];
    }
    $min = $row['min_commitment'] ?? null;
    $eq = $row['equity_offered_pct'] ?? null;
    $eqf = null;
    if ($eq !== null && $eq !== '' && is_numeric($eq)) {
      $eqf = (float)$eq;
      if (!is_finite($eqf) || $eqf <= 0) {
        $eqf = null;
      } else {
        $eqf = min(100.0, $eqf);
      }
    }
    return [
      'project_id' => $projectId,
      'enabled' => (int)($row['enabled'] ?? 0),
      'goal_amount' => (float)($row['goal_amount'] ?? 0),
      'goal_currency' => (string)($row['goal_currency'] ?? 'USD'),
      'min_commitment' => $min !== null && $min !== '' ? (float)$min : null,
      'equity_offered_pct' => $eqf,
    ];
  }

  /**
   * Pro‑rata share of the company offered at full goal: (commitment ÷ goal) × equity_offered_pct.
   * Capped at equity_offered_pct. Returns null when not configured or inputs are invalid.
   */
  public function impliedOwnershipPercent(float $committedAmount, array $settings): ?float {
    $goal = max(0.0, (float)($settings['goal_amount'] ?? 0));
    if ($goal <= 0 || !is_finite($committedAmount) || $committedAmount <= 0) {
      return null;
    }
    $eq = $settings['equity_offered_pct'] ?? null;
    if ($eq === null || $eq === '') {
      return null;
    }
    $eqf = (float)$eq;
    if (!is_finite($eqf) || $eqf <= 0) {
      return null;
    }
    $eqf = min(100.0, max(0.0, $eqf));
    $raw = ($committedAmount / $goal) * $eqf;
    return min($eqf, max(0.0, $raw));
  }

  /**
   * Render an SVG pie chart visualising how the funding round maps to company ownership.
   * Returns full HTML (chart + legend) or '' when the inputs aren't enough to draw it.
   *
   * Slices, going clockwise from the top:
   *   1. Retained by creator (= 100 − equity_offered_pct)
   *   2. Available for sale (= remainder of the offered slice)
   *   3. Committed by others
   *   4. Your commitment
   *
   * @param string|null $brandColor optional CSS color used for "Your commitment" highlight
   */
  public static function equityPieSvg(
    float $equityOfferedPct,
    float $goalAmount,
    float $totalCommitted,
    float $myCommitted,
    string $currency,
    ?string $brandColor = null,
  ): string {
    if (!is_finite($equityOfferedPct) || $equityOfferedPct <= 0 || $equityOfferedPct > 100) {
      return '';
    }
    if (!is_finite($goalAmount) || $goalAmount <= 0) {
      return '';
    }
    $totalCommitted = max(0.0, $totalCommitted);
    $myCommitted = max(0.0, min($myCommitted, $totalCommitted));
    $othersCommitted = max(0.0, $totalCommitted - $myCommitted);

    $eqOffered = max(0.0, min(100.0, $equityOfferedPct));
    $eqRetained = max(0.0, 100.0 - $eqOffered);

    $eqYou = ($myCommitted / $goalAmount) * $eqOffered;
    $eqOthers = ($othersCommitted / $goalAmount) * $eqOffered;
    $eqYou = max(0.0, min($eqOffered, $eqYou));
    $eqOthers = max(0.0, min($eqOffered - $eqYou, $eqOthers));
    $eqAvailable = max(0.0, $eqOffered - $eqYou - $eqOthers);

    $brand = $brandColor !== null && $brandColor !== '' ? $brandColor : '#1d4ed8';
    $colors = [
      'retained' => '#e5e7eb',
      'available' => '#bfdbfe',
      'others' => '#3b82f6',
      'you' => $brand,
    ];

    $slices = [
      ['key' => 'retained', 'label' => 'Retained', 'pct' => $eqRetained, 'amt' => null],
      ['key' => 'available', 'label' => 'Still available for sale', 'pct' => $eqAvailable, 'amt' => max(0.0, $goalAmount - $totalCommitted)],
      ['key' => 'others', 'label' => 'Committed by others', 'pct' => $eqOthers, 'amt' => $othersCommitted],
      ['key' => 'you', 'label' => 'Your commitment', 'pct' => $eqYou, 'amt' => $myCommitted],
    ];

    $cx = 90.0;
    $cy = 90.0;
    $r = 78.0;
    $svgSlices = '';
    $startDeg = 0.0;
    foreach ($slices as $s) {
      $pct = (float)$s['pct'];
      $color = $colors[$s['key']] ?? '#999';
      $key = (string)$s['key'];
      $d = '';
      if ($pct > 0.0001) {
        $sweep = ($pct / 100.0) * 360.0;
        $endDeg = $startDeg + $sweep;
        if ($pct >= 99.999) {
          $d = sprintf('M %.3f %.3f m -%.3f 0 a %.3f %.3f 0 1 0 %.3f 0 a %.3f %.3f 0 1 0 -%.3f 0',
            $cx, $cy, $r, $r, $r, ($r * 2), $r, $r, ($r * 2));
        } else {
          $a1 = deg2rad($startDeg - 90.0);
          $a2 = deg2rad($endDeg - 90.0);
          $x1 = $cx + $r * cos($a1);
          $y1 = $cy + $r * sin($a1);
          $x2 = $cx + $r * cos($a2);
          $y2 = $cy + $r * sin($a2);
          $largeArc = $sweep > 180.0 ? 1 : 0;
          $d = sprintf(
            'M %.3f %.3f L %.3f %.3f A %.3f %.3f 0 %d 1 %.3f %.3f Z',
            $cx, $cy, $x1, $y1, $r, $r, $largeArc, $x2, $y2,
          );
        }
        $startDeg = $endDeg;
      }
      $svgSlices .= '<path data-key="' . Util::h($key) . '" d="' . $d . '" fill="' . $color . '" stroke="#ffffff" stroke-width="1.5" />';
    }

    $svg = '<svg class="gds-equity-pie__chart" viewBox="0 0 180 180" role="img" aria-label="Company ownership breakdown">'
      . $svgSlices
      . '</svg>';

    $fmtPct = static fn(float $p): string => Util::h(number_format($p, 2)) . '%';
    $fmtAmt = static function (?float $amt) use ($currency): string {
      if ($amt === null) {
        return '';
      }
      return Util::h($currency . ' ' . number_format($amt, 0));
    };

    $legendRows = '';
    foreach ($slices as $s) {
      $color = $colors[$s['key']] ?? '#999';
      $pct = (float)$s['pct'];
      $amt = $s['amt'];
      $key = (string)$s['key'];
      $hasAmtRow = ($amt !== null);
      $amtText = $hasAmtRow ? $fmtAmt((float)$amt) : '';
      $amtStyle = ($hasAmtRow && $pct > 0) ? '' : ';display:none';
      $amtHtml = '<span class="gds-equity-pie__amt" data-key="' . Util::h($key) . '" style="' . $amtStyle . '">' . $amtText . '</span>';
      $legendRows .= '<li class="gds-equity-pie__row" data-key="' . Util::h($key) . '">'
        . '<span class="gds-equity-pie__swatch" style="background:' . Util::h($color) . '"></span>'
        . '<span class="gds-equity-pie__label">' . Util::h((string)$s['label']) . '</span>'
        . '<span class="gds-equity-pie__pct" data-key="' . Util::h($key) . '">' . $fmtPct($pct) . '</span>'
        . $amtHtml
        . '</li>';
    }

    $dataAttrs = ' data-eq-offered="' . Util::h(number_format($eqOffered, 4, '.', '')) . '"'
      . ' data-goal-amount="' . Util::h(number_format($goalAmount, 2, '.', '')) . '"'
      . ' data-total-committed="' . Util::h(number_format($totalCommitted, 2, '.', '')) . '"'
      . ' data-my-committed="' . Util::h(number_format($myCommitted, 2, '.', '')) . '"'
      . ' data-currency="' . Util::h($currency) . '"';

    return '<div class="gds-equity-pie"' . $dataAttrs . '>'
      . $svg
      . '<div class="gds-equity-pie__body">'
      . '<ul class="gds-equity-pie__legend">' . $legendRows . '</ul>'
      . '</div>'
      . '</div>';
  }

  public function saveSettings(int $projectId, array $data): void {
    $enabled = !empty($data['enabled']) ? 1 : 0;
    $goal = max(0.0, (float)($data['goal_amount'] ?? 0));
    $currency = strtoupper(trim((string)($data['goal_currency'] ?? 'USD')));
    if ($currency === '' || strlen($currency) > 8) {
      $currency = 'USD';
    }
    $minRaw = $data['min_commitment'] ?? null;
    $min = null;
    if ($minRaw !== null && $minRaw !== '') {
      $min = max(0.0, (float)$minRaw);
    }
    $eq = null;
    if (array_key_exists('equity_offered_pct', $data)) {
      $eqRaw = $data['equity_offered_pct'];
      if ($eqRaw !== null && $eqRaw !== '') {
        $ev = (float)$eqRaw;
        if (is_finite($ev) && $ev > 0) {
          $eq = min(100.0, max(0.0, $ev));
        }
      }
    }
    $this->db->exec(
      'INSERT INTO investment_settings (project_id, enabled, goal_amount, goal_currency, min_commitment, equity_offered_pct, updated_at)
       VALUES (:pid, :en, :ga, :gc, :min, :eq, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE
         enabled = VALUES(enabled),
         goal_amount = VALUES(goal_amount),
         goal_currency = VALUES(goal_currency),
         min_commitment = VALUES(min_commitment),
         equity_offered_pct = VALUES(equity_offered_pct),
         updated_at = UTC_TIMESTAMP()',
      [
        ':pid' => $projectId,
        ':en' => $enabled,
        ':ga' => $goal,
        ':gc' => $currency,
        ':min' => $min,
        ':eq' => $eq,
      ],
    );
  }

  public function attachContract(int $projectId, string $originalName, string $storedPath): void {
    $this->db->exec(
      'INSERT INTO investment_contracts (project_id, original_name, stored_path, created_at)
       VALUES (:pid, :on, :sp, UTC_TIMESTAMP())
       ON DUPLICATE KEY UPDATE original_name = VALUES(original_name), stored_path = VALUES(stored_path), created_at = UTC_TIMESTAMP()',
      [':pid' => $projectId, ':on' => $originalName, ':sp' => $storedPath],
    );
  }

  public function getContract(int $projectId): ?array {
    return $this->db->fetchOne(
      'SELECT * FROM investment_contracts WHERE project_id = :pid LIMIT 1',
      [':pid' => $projectId],
    );
  }

  public function upsertContractFieldDef(int $projectId, array $def): void {
    $this->db->exec(
      'INSERT INTO investment_contract_fields (project_id, field_key, field_label, page_num, x, y, w, h, required, created_at, updated_at)
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

  /** @return list<array<string,mixed>> */
  public function listContractFieldDefs(int $projectId): array {
    return $this->db->fetchAll(
      'SELECT * FROM investment_contract_fields WHERE project_id = :pid ORDER BY id ASC',
      [':pid' => $projectId],
    );
  }

  /** @param list<array<string,mixed>> $defs */
  public function replaceContractFieldDefs(int $projectId, array $defs): void {
    $this->db->exec('DELETE FROM investment_contract_fields WHERE project_id = :pid', [':pid' => $projectId]);
    foreach ($defs as $def) {
      if (!is_array($def)) {
        continue;
      }
      $this->upsertContractFieldDef($projectId, $def);
    }
  }

  public function getCommitment(int $projectId, string $email): ?array {
    return $this->db->fetchOne(
      'SELECT * FROM investment_commitments WHERE project_id = :pid AND signer_email = :em LIMIT 1',
      [':pid' => $projectId, ':em' => strtolower(trim($email))],
    );
  }

  public function getTotalCommitted(int $projectId): float {
    $row = $this->db->fetchOne(
      'SELECT COALESCE(SUM(committed_amount), 0) AS s FROM investment_commitments WHERE project_id = :pid',
      [':pid' => $projectId],
    );
    return (float)($row['s'] ?? 0);
  }

  /**
   * True when the funding goal is set and total commitments have reached or exceeded it.
   */
  public function isFundingClosed(int $projectId): bool {
    $s = $this->getSettings($projectId);
    if ((int)($s['enabled'] ?? 0) !== 1) {
      return false;
    }
    $goal = (float)($s['goal_amount'] ?? 0);
    if ($goal <= 0) {
      return false;
    }
    $total = $this->getTotalCommitted($projectId);
    return $total >= $goal;
  }

  /** @return list<array<string,mixed>> */
  public function listWaitlist(int $projectId): array {
    return $this->db->fetchAll(
      'SELECT * FROM investment_waitlist WHERE project_id = :pid ORDER BY updated_at DESC, id DESC',
      [':pid' => $projectId],
    );
  }

  public function upsertWaitlistEntry(int $projectId, array $data): void {
    $this->db->exec(
      'INSERT INTO investment_waitlist (
         project_id, full_name, email, phone, address, desired_amount, desired_currency, created_at, updated_at
       ) VALUES (
         :pid, :nm, :em, :ph, :addr, :damt, :dcur, UTC_TIMESTAMP(), UTC_TIMESTAMP()
       )
       ON DUPLICATE KEY UPDATE
         full_name = VALUES(full_name),
         phone = VALUES(phone),
         address = VALUES(address),
         desired_amount = VALUES(desired_amount),
         desired_currency = VALUES(desired_currency),
         updated_at = UTC_TIMESTAMP()',
      [
        ':pid' => $projectId,
        ':nm' => (string)$data['full_name'],
        ':em' => strtolower(trim((string)$data['email'])),
        ':ph' => (string)$data['phone'],
        ':addr' => (string)$data['address'],
        ':damt' => (float)$data['desired_amount'],
        ':dcur' => (string)($data['desired_currency'] ?? 'USD'),
      ],
    );
  }

  /** @return list<array<string,mixed>> */
  public function listCommitmentsForProject(int $projectId): array {
    return $this->db->fetchAll(
      'SELECT * FROM investment_commitments WHERE project_id = :pid ORDER BY committed_at DESC, id DESC',
      [':pid' => $projectId],
    );
  }

  public function hasCommitted(int $projectId, string $email): bool {
    return $this->getCommitment($projectId, $email) !== null;
  }

  /**
   * Insert or update commitment for (project, email). Paths must be on disk before call.
   */
  public function recordCommitment(array $params): void {
    $this->db->exec(
      'INSERT INTO investment_commitments (
         project_id, signer_email, signer_name, signer_position, signer_address,
         committed_amount, currency, committed_at, ip_address, user_agent,
         signature_image_path, signed_receipt_path, signed_pdf_path
       ) VALUES (
         :pid, :em, :nm, :pos, :addr,
         :amt, :cur, UTC_TIMESTAMP(), :ip, :ua,
         :sigp, :recp, :pdfp
       )
       ON DUPLICATE KEY UPDATE
         signer_name = VALUES(signer_name),
         signer_position = VALUES(signer_position),
         signer_address = VALUES(signer_address),
         committed_amount = VALUES(committed_amount),
         currency = VALUES(currency),
         committed_at = VALUES(committed_at),
         ip_address = VALUES(ip_address),
         user_agent = VALUES(user_agent),
         signature_image_path = VALUES(signature_image_path),
         signed_receipt_path = VALUES(signed_receipt_path),
         signed_pdf_path = VALUES(signed_pdf_path)',
      [
        ':pid' => $params['project_id'],
        ':em' => strtolower(trim((string)$params['signer_email'])),
        ':nm' => (string)$params['signer_name'],
        ':pos' => (string)$params['signer_position'],
        ':addr' => (string)($params['signer_address'] ?? ''),
        ':amt' => (float)$params['committed_amount'],
        ':cur' => (string)($params['currency'] ?? 'USD'),
        ':ip' => (string)($params['ip_address'] ?? ''),
        ':ua' => (string)($params['user_agent'] ?? ''),
        ':sigp' => (string)$params['signature_image_path'],
        ':recp' => (string)$params['signed_receipt_path'],
        ':pdfp' => $params['signed_pdf_path'] ?? null,
      ],
    );
  }
}
