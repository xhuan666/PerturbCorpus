<?php
require_once __DIR__ . '/config.php';

const GI_SOURCE = 'bulk';
const GI_TITLE = 'Genetic Interaction Classifier - Bulk';
const DB_GI_FILE = __DIR__ . '/sqlite3/bulk_sc_gi.db';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function request_param(string $key, $default = '') {
  if (array_key_exists($key, $_POST)) return $_POST[$key];
  if (array_key_exists($key, $_GET)) return $_GET[$key];
  return $default;
}
function json_response(array $payload, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    $json = json_encode(['ok' => false, 'message' => 'JSON encoding failed.'], JSON_UNESCAPED_UNICODE);
  }
  echo $json;
  exit;
}
function client_ip_for_rate_limit(): string {
  $candidates = [
    (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
    (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
    (string)($_SERVER['REMOTE_ADDR'] ?? ''),
  ];
  foreach ($candidates as $raw) {
    if ($raw === '') continue;
    $ip = trim(explode(',', $raw)[0]);
    if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
  }
  return 'unknown';
}
function enforce_ajax_rate_limit(int $maxRequests = 90, int $windowSeconds = 60): void {
  $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  if ($remoteAddr === '127.0.0.1' || $remoteAddr === '::1' || $remoteAddr === 'localhost') {
    return;
  }
  $dir = __DIR__ . '/temp/ratelimit';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  if (!is_dir($dir)) return;
  $ip = client_ip_for_rate_limit();
  $key = hash('sha256', 'bulk_gi_ajax|' . $ip);
  $file = $dir . '/' . $key . '.json';
  $now = time();
  $state = ['ts' => $now, 'count' => 0];
  if (is_file($file)) {
    $parsed = json_decode((string)@file_get_contents($file), true);
    if (is_array($parsed)) {
      $state['ts'] = isset($parsed['ts']) ? (int)$parsed['ts'] : $now;
      $state['count'] = isset($parsed['count']) ? (int)$parsed['count'] : 0;
    }
  }
  if (($now - $state['ts']) >= $windowSeconds) {
    $state['ts'] = $now;
    $state['count'] = 0;
  }
  $state['count']++;
  @file_put_contents($file, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
  if ($state['count'] > $maxRequests) {
    json_response(['ok' => false, 'message' => 'Too many requests. Please try again later.'], 429);
  }
}
function get_pdo(): PDO {
  if (!file_exists(DB_GI_FILE)) throw new RuntimeException('bulk_sc_gi.db not found.');
  $pdo = new PDO('sqlite:' . DB_GI_FILE);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  return $pdo;
}
function parse_list(string $raw): array {
  $arr = array_map('trim', explode(',', $raw));
  return array_values(array_unique(array_filter($arr, static fn($x) => $x !== '')));
}
function parse_param_list(string $key): array {
  $raw = request_param($key, null);
  if (is_array($raw)) {
    $arr = array_map('trim', $raw);
    return array_values(array_unique(array_filter($arr, static fn($x) => $x !== '')));
  }
  $altRaw = request_param($key . '[]', null);
  if (is_array($altRaw)) {
    $arr = array_map('trim', $altRaw);
    return array_values(array_unique(array_filter($arr, static fn($x) => $x !== '')));
  }
  return parse_list((string)request_param($key, ''));
}
function split_dataset_tokens(string $datasetId): array {
  $parts = preg_split('/\|+/', (string)$datasetId) ?: [];
  $out = [];
  foreach ($parts as $p) {
    $v = trim($p);
    if ($v !== '') $out[$v] = true;
  }
  return array_keys($out);
}
function celltype_sql(string $alias = 'g'): string {
  return "COALESCE(NULLIF($alias.meta_biosample_classification_type,''), NULLIF($alias.meta_biosample_description,''))";
}
function species_sql(string $alias = 'g'): string {
  return "$alias.meta_biosample_species";
}
function build_gi_filter_where(array $filters, array &$params, string $alias = 'g'): string {
  $where = [];

  $species = $filters['species'] ?? [];
  if (!is_array($species)) $species = [];
  $species = array_values(array_unique(array_filter(array_map('trim', $species), static fn($x) => $x !== '')));
  if ($species) {
    $ph = [];
    foreach ($species as $i => $v) { $k = ':species_' . $i; $ph[] = $k; $params[$k] = $v; }
    $where[] = "TRIM(COALESCE(" . species_sql($alias) . ",''))<>''";
    $where[] = species_sql($alias) . " IN (" . implode(',', $ph) . ")";
  }

  $tissues = $filters['tissues'] ?? [];
  if (!is_array($tissues)) $tissues = [];
  $tissues = array_values(array_unique(array_filter(array_map('trim', $tissues), static fn($x) => $x !== '')));
  if ($tissues) {
    $ph = [];
    foreach ($tissues as $i => $v) { $k = ':tissue_' . $i; $ph[] = $k; $params[$k] = $v; }
    $where[] = "TRIM(COALESCE($alias.meta_biosample_tissue_name,''))<>''";
    $where[] = "$alias.meta_biosample_tissue_name IN (" . implode(',', $ph) . ")";
  }

  $cellTypes = $filters['cell_types'] ?? [];
  if (!is_array($cellTypes)) $cellTypes = [];
  $cellTypes = array_values(array_unique(array_filter(array_map('trim', $cellTypes), static fn($x) => $x !== '')));
  if ($cellTypes) {
    $ph = [];
    foreach ($cellTypes as $i => $v) { $k = ':cell_' . $i; $ph[] = $k; $params[$k] = $v; }
    $where[] = "TRIM(COALESCE(" . celltype_sql($alias) . ",''))<>''";
    $where[] = celltype_sql($alias) . " IN (" . implode(',', $ph) . ")";
  }

  $perturbs = $filters['perturbations'] ?? [];
  if (!is_array($perturbs)) $perturbs = [];
  $perturbs = array_values(array_unique(array_filter(array_map('trim', $perturbs), static fn($x) => $x !== '')));
  if ($perturbs) {
    $ph = [];
    foreach ($perturbs as $i => $v) { $k = ':perturb_' . $i; $ph[] = $k; $params[$k] = $v; }
    $where[] = "TRIM(COALESCE($alias.target_gene_name,''))<>''";
    $where[] = "$alias.target_gene_name IN (" . implode(',', $ph) . ")";
  }

  return $where ? (' AND ' . implode(' AND ', $where)) : '';
}
function classify_row(array $r, float $magLow, float $magHigh, float $simCut, float $eqCut, float $modelCut): array {
  $m = is_numeric($r['magnitude'] ?? null) ? (float)$r['magnitude'] : null;
  $sim = is_numeric($r['similarity'] ?? null) ? (float)$r['similarity'] : null;
  $eq = is_numeric($r['equality'] ?? null) ? (float)$r['equality'] : null;
  $mf = is_numeric($r['model_fit'] ?? null) ? (float)$r['model_fit'] : null;
  return [
    'synergy' => ($m !== null && $m > $magHigh),
    'suppressor' => ($m !== null && $m < $magLow),
    'additive' => ($m !== null && $m >= $magLow && $m <= $magHigh),
    'redundant' => ($sim !== null && $sim > $simCut),
    'epistasis' => ($eq !== null && $eq < $eqCut),
    'neomorphic' => ($mf !== null && $mf < $modelCut),
  ];
}
function compute_dynamic_classes(array $r, float $magLow, float $magHigh, float $simCut, float $eqCut, float $modelCut): array {
  $m = is_numeric($r['magnitude'] ?? null) ? (float)$r['magnitude'] : null;
  $sim = is_numeric($r['similarity'] ?? null) ? (float)$r['similarity'] : null;
  $eq = is_numeric($r['equality'] ?? null) ? (float)$r['equality'] : null;
  $mf = is_numeric($r['model_fit'] ?? null) ? (float)$r['model_fit'] : null;

  if ($m === null) $magClass = 'unknown';
  elseif ($m > $magHigh) $magClass = 'synergy';
  elseif ($m < $magLow) $magClass = 'suppression';
  else $magClass = 'additive';

  if ($mf === null) $mfClass = 'unknown';
  elseif ($mf < $modelCut) $mfClass = 'neomorphic';
  else $mfClass = 'non_neomorphic';

  if ($sim === null) $simClass = 'unknown';
  elseif ($sim > $simCut) $simClass = 'redundant';
  else $simClass = 'non_redundant';

  if ($eq === null) $eqClass = 'unknown';
  elseif ($eq < $eqCut) $eqClass = 'epistasis';
  else $eqClass = 'non_epistasis';

  return [
    'magnitude_class' => $magClass,
    'model_fit_class' => $mfClass,
    'similarity_class' => $simClass,
    'equality_class' => $eqClass,
  ];
}

if ((string)request_param('ajax', '') !== '') {
  try {
    enforce_ajax_rate_limit();
    $pdo = get_pdo();
    $ajax = strtolower(trim((string)request_param('ajax', '')));
    if ($ajax === 'filter_options') {
      $group = strtolower(trim((string)request_param('group', '')));
      $mode = strtolower(trim((string)request_param('mode', 'biosample_first')));
      $scope = strtolower(trim((string)request_param('scope', 'eligible')));
      $q = trim((string)request_param('q', ''));
      $limit = min(5000, max(40, (int)request_param('limit', 2000)));

      $speciesSel = parse_param_list('species');
      $tissuesSel = parse_param_list('tissues');
      $cellTypesSel = parse_param_list('cell_types');
      $perturbsSel = parse_param_list('perturbations');

      if (!in_array($group, ['species', 'tissue', 'cell', 'perturb'], true)) {
        json_response(['ok' => false, 'message' => 'Invalid group.'], 400);
      }

      $whereParams = [];
      $filterForOptions = ['species' => [], 'tissues' => [], 'cell_types' => [], 'perturbations' => []];
      if ($scope === 'all') {
        if ($group !== 'species') {
          $filterForOptions['species'] = $speciesSel;
        }
      } else {
        if ($group !== 'species') {
          $filterForOptions['species'] = $speciesSel;
        }
        if ($mode === 'perturbed_gene_first') {
          // L1 perturb, L2 tissue, L3 cell
          if ($group === 'tissue') {
            $filterForOptions['perturbations'] = $perturbsSel;
          } elseif ($group === 'cell') {
            $filterForOptions['perturbations'] = $perturbsSel;
            $filterForOptions['tissues'] = $tissuesSel;
          }
        } else {
          // L1 tissue, L2 cell, L3 perturb
          if ($group === 'cell') {
            $filterForOptions['tissues'] = $tissuesSel;
          } elseif ($group === 'perturb') {
            $filterForOptions['tissues'] = $tissuesSel;
            $filterForOptions['cell_types'] = $cellTypesSel;
          }
        }
      }
      $extra = build_gi_filter_where($filterForOptions, $whereParams, 'g');

      $col = match ($group) {
        'species' => species_sql('g'),
        'tissue' => 'g.meta_biosample_tissue_name',
        'cell' => celltype_sql('g'),
        default => 'g.target_gene_name',
      };
      $sql = "
        SELECT DISTINCT $col AS v
        FROM gi_result g
        WHERE g.source='bulk'
          $extra
          AND TRIM(COALESCE($col,'')) <> ''
          AND $col LIKE :q COLLATE NOCASE
        ORDER BY v
        LIMIT :lim
      ";
      $st = $pdo->prepare($sql);
      foreach ($whereParams as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
      $st->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
      $st->bindValue(':lim', $limit, PDO::PARAM_INT);
      $st->execute();
      json_response([
        'ok' => true,
        'group' => $group,
        'mode' => $mode,
        'scope' => $scope,
        'options' => $st->fetchAll(PDO::FETCH_COLUMN) ?: [],
      ]);
    }
    if ($ajax === 'datasets') {
      $speciesSel = parse_param_list('species');
      $tissues = parse_param_list('tissues');
      $cellTypes = parse_param_list('cell_types');
      $perturbs = parse_param_list('perturbations');
      $limitRaw = (int)request_param('limit', 0);
      $limit = $limitRaw <= 0 ? 0 : min(200000, max(100, $limitRaw));
      if (!$speciesSel) {
        json_response(['ok' => true, 'dataset_ids' => [], 'count' => 0]);
      }
      $params = [];
      $extra = build_gi_filter_where([
        'species' => $speciesSel,
        'tissues' => $tissues,
        'cell_types' => $cellTypes,
        'perturbations' => $perturbs,
      ], $params, 'g');
      $sql = "
        SELECT DISTINCT g.dataset_id AS dataset_id
        FROM gi_result g
        WHERE g.source='bulk'
          $extra
        ORDER BY g.dataset_id
      ";
      if ($limit > 0) {
        $sql .= "\n LIMIT :lim";
      }
      $st = $pdo->prepare($sql);
      foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
      if ($limit > 0) {
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
      }
      $st->execute();
      $idsSet = [];
      foreach (($st->fetchAll() ?: []) as $r) {
        foreach (split_dataset_tokens((string)($r['dataset_id'] ?? '')) as $tok) $idsSet[$tok] = true;
      }
      $ids = array_keys($idsSet);
      sort($ids, SORT_NATURAL | SORT_FLAG_CASE);
      json_response(['ok' => true, 'dataset_ids' => $ids, 'count' => count($ids)]);
    }
    if ($ajax === 'filter_index') {
      $speciesSel = parse_param_list('species');
      if (!$speciesSel) {
        json_response(['ok' => false, 'message' => 'Species required'], 400);
      }
      $sp = (string)$speciesSel[0];
      $sql = "
        SELECT
          g.dataset_id AS dataset_id,
          COALESCE(g.meta_biosample_tissue_name, '') AS tissue,
          " . celltype_sql('g') . " AS cell_type,
          COALESCE(g.target_gene_name, '') AS perturb
        FROM gi_result g
        WHERE g.source='bulk'
          AND " . species_sql('g') . " = :species
      ";
      $st = $pdo->prepare($sql);
      $st->bindValue(':species', $sp, PDO::PARAM_STR);
      $st->execute();
      $rows = [];
      $tSet = [];
      $cSet = [];
      $pSet = [];
      while ($r = $st->fetch()) {
        $d = trim((string)($r['dataset_id'] ?? ''));
        if ($d === '') continue;
        $t = trim((string)($r['tissue'] ?? ''));
        $c = trim((string)($r['cell_type'] ?? ''));
        $p = trim((string)($r['perturb'] ?? ''));
        if ($t !== '') $tSet[$t] = true;
        if ($c !== '') $cSet[$c] = true;
        if ($p !== '') $pSet[$p] = true;
        $rows[] = ['d' => $d, 't' => $t, 'c' => $c, 'p' => $p];
      }
      $tissues = array_keys($tSet); sort($tissues, SORT_STRING);
      $cells = array_keys($cSet); sort($cells, SORT_STRING);
      $perts = array_keys($pSet); sort($perts, SORT_STRING);
      json_response(['ok' => true, 'species' => $sp, 'rows' => $rows, 'options' => ['tissue' => $tissues, 'cell' => $cells, 'perturb' => $perts]]);
    }
    if ($ajax === 'options') {
      $tissues = $pdo->query("
        SELECT DISTINCT meta_biosample_tissue_name
        FROM gi_result
        WHERE source='bulk' AND TRIM(COALESCE(meta_biosample_tissue_name,''))<>''
        ORDER BY meta_biosample_tissue_name
      ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
      $cellTypes = $pdo->query("
        SELECT DISTINCT " . celltype_sql('g') . " AS cell_type
        FROM gi_result g
        WHERE g.source='bulk' AND TRIM(COALESCE(" . celltype_sql('g') . ",''))<>''
        ORDER BY cell_type
      ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
      $perturbs = $pdo->query("
        SELECT DISTINCT g.target_gene_name
        FROM gi_result g
        WHERE g.source='bulk'
          AND TRIM(COALESCE(g.target_gene_name,'')) <> ''
        ORDER BY g.target_gene_name
      ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
      json_response(['ok' => true, 'tissues' => $tissues, 'cell_types' => $cellTypes, 'perturbations' => $perturbs]);
    }
    if ($ajax === 'linked_options') {
      $speciesSel = parse_param_list('species');
      $tissuesSel = parse_param_list('tissues');
      $cellTypesSel = parse_param_list('cell_types');
      $perturbsSel = parse_param_list('perturbations');
      $qSpecies = trim((string)request_param('q_species', ''));
      $qTissue = trim((string)request_param('q_tissue', ''));
      $qCell = trim((string)request_param('q_cell', ''));
      $qPerturb = trim((string)request_param('q_perturb', ''));
      $limit = min(5000, max(40, (int)request_param('limit', 2000)));

      $buildIn = static function(array $vals, string $prefix, array &$params): string {
        $ph = [];
        foreach ($vals as $i => $v) { $k = ':' . $prefix . $i; $ph[] = $k; $params[$k] = $v; }
        return implode(',', $ph);
      };

      // species options: constrained by other groups, not by species itself
      $params = [':q' => '%' . $qSpecies . '%'];
      $where = ["g.source='bulk'", "TRIM(COALESCE(" . species_sql('g') . ",''))<>''", "LOWER(" . species_sql('g') . ") LIKE LOWER(:q)"];
      if ($tissuesSel) $where[] = "g.meta_biosample_tissue_name IN (" . $buildIn($tissuesSel, 't', $params) . ")";
      if ($cellTypesSel) $where[] = celltype_sql('g') . " IN (" . $buildIn($cellTypesSel, 'c', $params) . ")";
      if ($perturbsSel) $where[] = "g.target_gene_name IN (" . $buildIn($perturbsSel, 'p', $params) . ")";
      $sql = "SELECT DISTINCT " . species_sql('g') . " AS species FROM gi_result g WHERE " . implode(' AND ', $where) . " ORDER BY species LIMIT :lim";
      $st = $pdo->prepare($sql);
      foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
      $st->bindValue(':lim', $limit, PDO::PARAM_INT);
      $st->execute();
      $species = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

      // tissue options: constrained by other groups (cell/perturb), not by tissue itself
      $params = [':q' => '%' . $qTissue . '%'];
      $where = ["g.source='bulk'", "TRIM(COALESCE(g.meta_biosample_tissue_name,''))<>''", "LOWER(g.meta_biosample_tissue_name) LIKE LOWER(:q)"];
      if ($speciesSel) $where[] = species_sql('g') . " IN (" . $buildIn($speciesSel, 's', $params) . ")";
      if ($cellTypesSel) $where[] = celltype_sql('g') . " IN (" . $buildIn($cellTypesSel, 'c', $params) . ")";
      if ($perturbsSel) $where[] = "g.target_gene_name IN (" . $buildIn($perturbsSel, 'p', $params) . ")";
      $sql = "SELECT DISTINCT g.meta_biosample_tissue_name FROM gi_result g WHERE " . implode(' AND ', $where) . " ORDER BY g.meta_biosample_tissue_name LIMIT :lim";
      $st = $pdo->prepare($sql);
      foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
      $st->bindValue(':lim', $limit, PDO::PARAM_INT);
      $st->execute();
      $tissues = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

      // cell type options: constrained by other groups (tissue/perturb), not by cell itself
      $params = [':q' => '%' . $qCell . '%'];
      $where = ["g.source='bulk'", "TRIM(COALESCE(" . celltype_sql('g') . ",''))<>''", "LOWER(" . celltype_sql('g') . ") LIKE LOWER(:q)"];
      if ($speciesSel) $where[] = species_sql('g') . " IN (" . $buildIn($speciesSel, 's', $params) . ")";
      if ($tissuesSel) $where[] = "g.meta_biosample_tissue_name IN (" . $buildIn($tissuesSel, 't', $params) . ")";
      if ($perturbsSel) $where[] = "g.target_gene_name IN (" . $buildIn($perturbsSel, 'p', $params) . ")";
      $sql = "SELECT DISTINCT " . celltype_sql('g') . " AS cell_type FROM gi_result g WHERE " . implode(' AND ', $where) . " ORDER BY cell_type LIMIT :lim";
      $st = $pdo->prepare($sql);
      foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
      $st->bindValue(':lim', $limit, PDO::PARAM_INT);
      $st->execute();
      $cellTypes = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

      // perturb options: constrained by other groups (tissue/cell), not by perturb itself
      $params = [':q' => '%' . $qPerturb . '%'];
      $where = ["g.source='bulk'", "TRIM(COALESCE(g.target_gene_name,''))<>''", "LOWER(g.target_gene_name) LIKE LOWER(:q)"];
      if ($speciesSel) $where[] = species_sql('g') . " IN (" . $buildIn($speciesSel, 's', $params) . ")";
      if ($tissuesSel) $where[] = "g.meta_biosample_tissue_name IN (" . $buildIn($tissuesSel, 't', $params) . ")";
      if ($cellTypesSel) $where[] = celltype_sql('g') . " IN (" . $buildIn($cellTypesSel, 'c', $params) . ")";
      $sql = "SELECT DISTINCT g.target_gene_name FROM gi_result g WHERE " . implode(' AND ', $where) . " ORDER BY g.target_gene_name LIMIT :lim";
      $st = $pdo->prepare($sql);
      foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
      $st->bindValue(':lim', $limit, PDO::PARAM_INT);
      $st->execute();
      $perturbs = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

      json_response(['ok' => true, 'species' => $species, 'tissues' => $tissues, 'cell_types' => $cellTypes, 'perturbations' => $perturbs]);
    }
    if ($ajax === 'perturb_options') {
      $tissues = parse_param_list('tissues');
      $cellTypes = parse_param_list('cell_types');
      $where = ["g.source='bulk'", "TRIM(COALESCE(g.target_gene_name,''))<>''"];
      $params = [];
      if ($tissues) {
        $ph = [];
        foreach ($tissues as $i => $v) { $k = ':t' . $i; $ph[] = $k; $params[$k] = $v; }
        $where[] = "g.meta_biosample_tissue_name IN (" . implode(',', $ph) . ")";
      }
      if ($cellTypes) {
        $ph = [];
        foreach ($cellTypes as $i => $v) { $k = ':c' . $i; $ph[] = $k; $params[$k] = $v; }
        $where[] = celltype_sql('g') . " IN (" . implode(',', $ph) . ")";
      }
      $sql = "SELECT DISTINCT g.target_gene_name
              FROM gi_result g
              WHERE " . implode(' AND ', $where) . "
              ORDER BY g.target_gene_name";
      $st = $pdo->prepare($sql);
      foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
      $st->execute();
      $perturbs = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
      json_response(['ok' => true, 'perturbations' => $perturbs]);
    }
    if ($ajax === 'metric_bounds') {
      $row = $pdo->query("
        SELECT
          MIN(magnitude) AS mag_min, MAX(magnitude) AS mag_max,
          MIN(model_fit) AS model_min, MAX(model_fit) AS model_max,
          MIN(similarity) AS sim_min, MAX(similarity) AS sim_max,
          MIN(equality) AS eq_min, MAX(equality) AS eq_max
        FROM gi_result
        WHERE source='bulk'
      ")->fetch() ?: [];
      json_response([
        'ok' => true,
        'bounds' => [
          'magnitude' => ['min' => (float)($row['mag_min'] ?? 0.0), 'max' => (float)($row['mag_max'] ?? 1.0)],
          'model_fit' => ['min' => (float)($row['model_min'] ?? 0.0), 'max' => (float)($row['model_max'] ?? 1.0)],
          'similarity' => ['min' => (float)($row['sim_min'] ?? 0.0), 'max' => (float)($row['sim_max'] ?? 1.0)],
          'equality' => ['min' => (float)($row['eq_min'] ?? 0.0), 'max' => (float)($row['eq_max'] ?? 1.0)],
        ],
      ]);
    }
    if ($ajax === 'dataset_ids') {
      $speciesSel = parse_param_list('species');
      $tissues = parse_param_list('tissues');
      $cellTypes = parse_param_list('cell_types');
      $perturbs = parse_param_list('perturbations');
      $limit = min(20000, max(100, (int)request_param('limit', 5000)));

      $where = ["g.source='bulk'"];
      $params = [];
      if ($speciesSel) {
        $ph = [];
        foreach ($speciesSel as $i => $v) { $k = ':s' . $i; $ph[] = $k; $params[$k] = $v; }
        $where[] = species_sql('g') . " IN (" . implode(',', $ph) . ")";
      }
      if ($tissues) {
        $ph = [];
        foreach ($tissues as $i => $v) { $k = ':t' . $i; $ph[] = $k; $params[$k] = $v; }
        $where[] = "g.meta_biosample_tissue_name IN (" . implode(',', $ph) . ")";
      }
      if ($cellTypes) {
        $ph = [];
        foreach ($cellTypes as $i => $v) { $k = ':c' . $i; $ph[] = $k; $params[$k] = $v; }
        $where[] = celltype_sql('g') . " IN (" . implode(',', $ph) . ")";
      }
      if ($perturbs) {
        $ph = [];
        foreach ($perturbs as $i => $v) { $k = ':p' . $i; $ph[] = $k; $params[$k] = $v; }
        $where[] = "g.target_gene_name IN (" . implode(',', $ph) . ")";
      }

      $sql = "SELECT DISTINCT g.dataset_id AS dataset_id
              FROM gi_result g
              WHERE " . implode(' AND ', $where) . "
              ORDER BY g.dataset_id
              LIMIT :lim";
      $st = $pdo->prepare($sql);
      foreach ($params as $k => $v) $st->bindValue($k, $v, PDO::PARAM_STR);
      $st->bindValue(':lim', $limit, PDO::PARAM_INT);
      $st->execute();
      $idsSet = [];
      foreach (($st->fetchAll() ?: []) as $r) {
        foreach (split_dataset_tokens((string)($r['dataset_id'] ?? '')) as $tok) $idsSet[$tok] = true;
      }
      $ids = array_keys($idsSet);
      sort($ids, SORT_NATURAL | SORT_FLAG_CASE);
      json_response(['ok' => true, 'dataset_ids' => $ids, 'count' => count($ids)]);
    }
    if ($ajax === 'query') {
      $datasetIds = parse_param_list('dataset_ids');
      $speciesSel = parse_param_list('species');
      $tissues = parse_param_list('tissues');
      $cellTypes = parse_param_list('cell_types');
      $perturbs = parse_param_list('perturbations');
      $types = parse_param_list('gi_types');
      if (!$types) {
        $types = ['synergy','suppressor','additive','redundant','epistasis','neomorphic'];
      }
      $magLow = (float)request_param('mag_low', 1.0);
      $magHigh = (float)request_param('mag_high', 1.15);
      $simCut = (float)request_param('sim_cut', 0.85);
      $eqCut = (float)request_param('eq_cut', 0.28);
      $modelCut = (float)request_param('model_cut', 0.88);
      $page = max(1, (int)request_param('page', 1));
      $pageSize = 40;
      $offset = ($page - 1) * $pageSize;

      $where = ["g.source='bulk'"];
      $params = [];
      if ($speciesSel) {
        $ph = [];
        foreach ($speciesSel as $i => $v) { $k = ':s' . $i; $ph[] = $k; $params[$k] = $v; }
        $where[] = species_sql('g') . " IN (" . implode(',', $ph) . ")";
      }
      if ($datasetIds) {
        $ph = [];
        foreach ($datasetIds as $i => $v) { $k = ':d' . $i; $ph[] = "('|' || UPPER(COALESCE(g.dataset_id,'')) || '|') LIKE UPPER($k)"; $params[$k] = '%|' . $v . '|%'; }
        $where[] = '(' . implode(' OR ', $ph) . ')';
      }
      if ($tissues) {
        $ph = [];
        foreach ($tissues as $i => $v) { $k = ':t' . $i; $ph[] = $k; $params[$k] = $v; }
        $where[] = "g.meta_biosample_tissue_name IN (" . implode(',', $ph) . ")";
      }
      if ($cellTypes) {
        $ph = [];
        foreach ($cellTypes as $i => $v) { $k = ':c' . $i; $ph[] = $k; $params[$k] = $v; }
        $where[] = celltype_sql('g') . " IN (" . implode(',', $ph) . ")";
      }
      if ($perturbs) {
        $ph = [];
        foreach ($perturbs as $i => $v) { $k = ':p' . $i; $ph[] = $k; $params[$k] = $v; }
        $where[] = "g.target_gene_name IN (" . implode(',', $ph) . ")";
      }
      $params[':mag_low'] = $magLow;
      $params[':mag_high'] = $magHigh;
      $params[':sim_cut'] = $simCut;
      $params[':eq_cut'] = $eqCut;
      $params[':model_cut'] = $modelCut;

      $typeClauses = [];
      foreach ($types as $type) {
        $t = strtolower(trim((string)$type));
        if ($t === 'synergy') $typeClauses[] = "(g.magnitude IS NOT NULL AND g.magnitude > :mag_high)";
        elseif ($t === 'suppressor') $typeClauses[] = "(g.magnitude IS NOT NULL AND g.magnitude < :mag_low)";
        elseif ($t === 'additive') $typeClauses[] = "(g.magnitude IS NOT NULL AND g.magnitude >= :mag_low AND g.magnitude <= :mag_high)";
        elseif ($t === 'redundant') $typeClauses[] = "(g.similarity IS NOT NULL AND g.similarity > :sim_cut)";
        elseif ($t === 'epistasis') $typeClauses[] = "(g.equality IS NOT NULL AND g.equality < :eq_cut)";
        elseif ($t === 'neomorphic') $typeClauses[] = "(g.model_fit IS NOT NULL AND g.model_fit < :model_cut)";
      }
      $typeClauses = array_values(array_unique($typeClauses));
      if ($typeClauses) {
        $where[] = '(' . implode(' OR ', $typeClauses) . ')';
      }
      $whereSql = implode(' AND ', $where);
      $countSql = "SELECT COUNT(*) FROM gi_result g WHERE " . $whereSql;
      $countStmt = $pdo->prepare($countSql);
      foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
      $countStmt->execute();
      $totalCount = (int)$countStmt->fetchColumn();
      $totalPages = $totalCount > 0 ? (int)ceil($totalCount / $pageSize) : 0;
      if ($totalPages > 0 && $page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $pageSize;
      }
      $sql = "SELECT g.dataset_id AS dataset_id,g.target_gene_name AS target_gene_name,g.assay_type AS assay_type,
              g.magnitude AS magnitude,g.model_fit AS model_fit,g.similarity AS similarity,g.equality AS equality,
              g.magnitude_class AS magnitude_class,g.model_fit_class AS model_fit_class,g.similarity_class AS similarity_class,g.equality_class AS equality_class
              ,g.meta_biosample_species AS species
              ,g.meta_biosample_tissue_name AS tissue
              ," . celltype_sql('g') . " AS cell_type
              FROM gi_result g
              WHERE " . $whereSql . "
              ORDER BY g.combo_group
              LIMIT :page_size OFFSET :offset";
      $st = $pdo->prepare($sql);
      foreach ($params as $k => $v) $st->bindValue($k, $v);
      $st->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
      $st->bindValue(':offset', $offset, PDO::PARAM_INT);
      $st->execute();
      $rows = $st->fetchAll() ?: [];
      $out = [];
      foreach ($rows as $r) {
        $r = array_merge($r, compute_dynamic_classes($r, $magLow, $magHigh, $simCut, $eqCut, $modelCut));
        $out[] = $r;
      }
      json_response([
        'ok' => true,
        'rows' => $out,
        'count' => $totalCount,
        'total_count' => $totalCount,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => $totalPages,
      ]);
    }
    json_response(['ok' => false, 'message' => 'Unknown ajax'], 400);
  } catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 500);
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <!-- Matomo -->
<script>
  var _paq = window._paq = window._paq || [];
  /* tracker methods like "setCustomDimension" should be called before "trackPageView" */
  _paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  (function() {
    var u="https://matomo.scicdn.com/";
    _paq.push(['setTrackerUrl', u+'matomo.php']);
    _paq.push(['setSiteId', '8']);
    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
  })();
</script>
<!-- End Matomo Code -->
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>PerturbCorpus Tools</title>
  <link href="static/lib/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet" />
  <link href="static/style.css?ver=<?php echo uniqid(); ?>" rel="stylesheet" />
  <style>
    .panel-card { border: 1px solid #e5e7eb; border-radius: 14px; background: #fff; box-shadow: 0 6px 24px rgba(15,23,42,0.06); }
    .subtle-label { font-size: .86rem; color: #475569; font-weight: 600; }
    .cascade-help-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 16px;
      height: 16px;
      margin-left: 6px;
      border-radius: 999px;
      border: 1px solid #94a3b8;
      color: #475569;
      font-size: 11px;
      font-weight: 700;
      line-height: 1;
      cursor: help;
      user-select: none;
      background: #fff;
    }
    .cascade-help-tooltip {
      --bs-tooltip-bg: #f8fafc;
      --bs-tooltip-color: #334155;
      --bs-tooltip-opacity: 1;
      --bs-tooltip-max-width: 460px;
    }
    .cascade-help-tooltip .tooltip-inner {
      border: 1px solid #dbe2ea;
      box-shadow: 0 6px 18px rgba(15, 23, 42, 0.10);
      text-align: left;
      line-height: 1.45;
      padding: 8px 10px;
    }
    .filter-box { border: 1px solid #dbe2ea; border-radius: 10px; background: #f8fafc; padding: 10px; }
    .filter-and-col {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 20px;
    }
    .filter-and-badge {
      font-size: .82rem;
      font-weight: 800;
      color: #1e293b;
      background: #e2e8f0;
      border: 1px solid #94a3b8;
      border-radius: 999px;
      padding: 3px 12px;
      line-height: 1.2;
      letter-spacing: .02em;
    }
    @media (min-width: 768px) {
      .filter-main-col {
        flex: 1 1 0;
        max-width: none;
      }
      .filter-and-col {
        flex: 0 0 auto;
        width: auto;
        padding-left: .35rem;
        padding-right: .35rem;
      }
    }
    .filter-head { display:flex; align-items:center; justify-content:space-between; gap:.5rem; margin-bottom:.25rem; }
    .filter-tools { display:flex; align-items:center; gap:.35rem; }
    .filter-count { font-size:.75rem; color:#64748b; min-width:42px; text-align:right; }
    .filter-scroll { max-height: 220px; overflow: auto; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; padding: 8px; }
    .filter-item { margin-bottom: 4px; }
    .is-disabled-option { color:#9ca3af !important; }
    .disabled-tag { color:#9ca3af; font-size:.75rem; }
    .species-inline .form-check-input { margin-top: 0.15rem; }
    .dataset-list { max-height: 360px; overflow: auto; border: 1px solid #dbe2ea; border-radius: 10px; padding: 10px; background: #f8fafc; }
    .muted-small { color: #64748b; font-size: .83rem; }
    .page-offset-top { padding-top: 0; }
    .cutoff-panel { border: 1px solid #dbe2ea; border-radius: 12px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); padding: 12px; }
    .cutoff-card { border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 10px; height: 100%; }
    .cutoff-title { font-size: .82rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: .02em; margin-bottom: 6px; }
    .cutoff-value { font-size: .88rem; font-weight: 700; color: #0f172a; }
    .range-chip { display: inline-block; font-size: .72rem; color: #64748b; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 999px; padding: 1px 8px; margin-left: 4px; }
    .gi-type-wrap { border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; padding: 8px 10px; }
    .gi-type-item { display: inline-flex; align-items: center; gap: 6px; margin-right: 14px; margin-bottom: 6px; font-size: .84rem; color: #334155; }
    .runbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 10px; }
    .gi-module2-layout { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 12px; }
    .gi-explain-card { border: 1px solid #dbe2ea; border-radius: 12px; background: #ffffff; padding: 10px; height: 100%; }
    .gi-explain-title { font-weight: 800; color: #0f172a; font-size: .95rem; margin-bottom: 8px; }
    .gi-explain-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .gi-explain-table th, .gi-explain-table td { border: 1px solid #dbe2ea; padding: 6px 8px; vertical-align: middle; }
    .gi-explain-table th { background: #dbeafe; color: #0f172a; font-weight: 800; }
    .gi-explain-type { color: #111111; font-weight: 700; }
    .guide-value { color: #1d4ed8; font-weight: 700; }
    @media (max-width: 991.98px) { .gi-module2-layout { grid-template-columns: 1fr; } }
    .metric-guide-card { border: 1px solid #dbe2ea; border-radius: 14px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); }
    .metric-guide-imgwrap { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; padding: 10px; display: flex; align-items: center; justify-content: center; width: 100%; }
    .metric-guide-img { display: block; width: 100%; max-width: 100%; height: auto; object-fit: contain; }
    .dual-range { position: relative; height: 36px; }
    .dual-range .track { position: absolute; top: 15px; left: 0; right: 0; height: 6px; border-radius: 999px; background: #dee2e6; }
    .dual-range .fill { position: absolute; top: 15px; height: 6px; border-radius: 999px; background: #0d6efd; }
    .dual-range input[type=range] {
      position: absolute;
      left: 0;
      right: 0;
      top: 0;
      width: 100%;
      pointer-events: none;
      background: transparent;
      -webkit-appearance: none;
      appearance: none;
      border: 0;
      margin: 0;
      height: 36px;
    }
    .dual-range input[type=range]::-webkit-slider-runnable-track {
      height: 6px;
      background: transparent;
      border: 0;
    }
    .dual-range input[type=range]::-webkit-slider-thumb {
      -webkit-appearance: none;
      appearance: none;
      pointer-events: auto;
      position: relative;
      z-index: 2;
      width: 1rem;
      height: 1rem;
      border-radius: 50%;
      background: #0d6efd;
      border: 0;
      box-shadow: 0 0 0 1px rgba(13, 110, 253, 0.25);
      margin-top: -5px;
      cursor: pointer;
    }
    .dual-range input[type=range]::-moz-range-track {
      height: 6px;
      background: transparent;
      border: 0;
    }
    .dual-range input[type=range]::-moz-range-progress {
      height: 6px;
      background: transparent;
      border: 0;
    }
    .dual-range input[type=range]::-moz-range-thumb {
      pointer-events: auto;
      position: relative;
      z-index: 2;
      width: 1rem;
      height: 1rem;
      border-radius: 50%;
      background: #0d6efd;
      border: 0;
      box-shadow: 0 0 0 1px rgba(13, 110, 253, 0.25);
      cursor: pointer;
    }
    .gi-table-scroll { max-height: 65vh; overflow-y: auto; overflow-x: auto; border: 1px solid #d5dae2; border-radius: 0; background: #fff; }
    .gi-result-table thead th {
      position: sticky !important;
      top: 0 !important;
      z-index: 2 !important;
      background-clip: padding-box;
      background: #e0f2fe !important;
      opacity: 1 !important;
    }
    .gi-result-table thead,
    .gi-result-table thead tr,
    .gi-result-table thead th { background-color: #e0f2fe !important; }
    .gi-result-table thead th { white-space: nowrap; }
    .gi-result-table th:nth-child(3), .gi-result-table td:nth-child(3) { min-width: 140px; }
    .gi-result-table th:nth-child(4), .gi-result-table td:nth-child(4) { min-width: 140px; }
    .gi-result-table th:nth-child(5), .gi-result-table td:nth-child(5) { min-width: 140px; }
    .gi-result-table th:nth-child(6), .gi-result-table td:nth-child(6) { min-width: 130px; }
    .method-help-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 16px;
      height: 16px;
      margin-left: 6px;
      border-radius: 999px;
      border: 1px solid #94a3b8;
      color: #475569;
      font-size: 11px;
      font-weight: 700;
      line-height: 1;
      text-decoration: none;
      background: #fff;
      vertical-align: middle;
    }
    .method-help-link:hover {
      color: #0f172a;
      border-color: #64748b;
      text-decoration: none;
    }
    .flow-guide { border: 1px solid #dbe2ea; border-radius: 14px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); box-shadow: 0 8px 24px rgba(15,23,42,0.06); }
    .flow-step { border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff; padding: 12px; height: 100%; }
    .flow-badge { width: 28px; height: 28px; border-radius: 999px; background: #0f172a; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 700; }
    .flow-title { font-size: .95rem; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
    .flow-text { font-size: .84rem; color: #475569; margin: 0; }
  </style>
</head>
<body class="layout-body subpage-bg d-flex flex-column min-vh-100">
<?php include 'background.php'; ?>
<?php
$__nav_current = basename($_SERVER['PHP_SELF'] ?? '');
$__tools_active = in_array($__nav_current, ['bulk_correlation_tool.php', 'sc_correlation_tool.php', 'bulk_gi_tool.php', 'sc_gi_tool.php'], true);
?>
<nav class="navbar navbar-expand-lg fixed-top nav-bar" id="topNav">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="index.php">
      <span class="nav-brand-word">PerturbCorpus</span>
    </a>
    <div class="ms-auto nav-actions">
      <a class="nav-action <?php echo $__nav_current === 'index.php' ? 'active' : ''; ?>" href="index.php">Home</a>
      <a class="nav-action <?php echo in_array($__nav_current, ['browse.php', 'browse_detail.php'], true) ? 'active' : ''; ?>" href="browse.php">Browse</a>
      <a class="nav-action <?php echo $__nav_current === 'statistics.php' ? 'active' : ''; ?>" href="statistics.php">Statistics</a>

      <div class="nav-tools <?php echo $__tools_active ? 'active' : ''; ?>">
        <span class="nav-action <?php echo $__tools_active ? 'active' : ''; ?>" role="button" aria-haspopup="true" aria-expanded="false">Tools</span>
                <div class="nav-tools-menu">
          <div class="nav-tools-group">
            <div class="nav-tools-group-title">Correlation Explorer</div>
            <div class="nav-tools-group-buttons">
              <a class="nav-tools-link nav-tools-pill <?php echo $__nav_current === 'bulk_correlation_tool.php' ? 'active' : ''; ?>" href="bulk_correlation_tool.php">Bulk</a>
              <a class="nav-tools-link nav-tools-pill <?php echo $__nav_current === 'sc_correlation_tool.php' ? 'active' : ''; ?>" href="sc_correlation_tool.php">Single Cell</a>
            </div>
          </div>
          <div class="nav-tools-group">
            <div class="nav-tools-group-title">Genetic Interaction Classifier</div>
            <div class="nav-tools-group-buttons">
              <a class="nav-tools-link nav-tools-pill <?php echo $__nav_current === 'bulk_gi_tool.php' ? 'active' : ''; ?>" href="bulk_gi_tool.php">Bulk</a>
              <a class="nav-tools-link nav-tools-pill <?php echo $__nav_current === 'sc_gi_tool.php' ? 'active' : ''; ?>" href="sc_gi_tool.php">Single Cell</a>
            </div>
          </div>
        </div>
      </div>

      <a class="nav-action <?php echo $__nav_current === 'download.php' ? 'active' : ''; ?>" href="download.php">Download</a>
      <a class="nav-action <?php echo $__nav_current === 'faq.php' ? 'active' : ''; ?>" href="faq.php">FAQ</a>
    </div>
  </div>
</nav>

<main class="layout-page gi-main pb-5">
  <div class="container-fluid page-shell pt-2">
    <div class="mb-3">
      <h1 class="h3 fw-bold mb-1"><?php echo h(GI_TITLE); ?></h1>
      <div class="muted-small">This tool identifies genetic interaction subtype in user-selected bulk datasets.</div>
    </div>

    <section class="flow-guide p-3 mb-3">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <h2 class="h5 mb-0">How to Use</h2>
        
      </div>
      <div class="row g-2">
        <div class="col-12 col-md-4">
          <div class="flow-step">
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="flow-badge">1</span>
              <div class="flow-title">Choose Filters</div>
            </div>
            <p class="flow-text">Select Species, Tissue, Cell Type.</p>
          </div>
        </div>
        <div class="col-12 col-md-4">
          <div class="flow-step">
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="flow-badge">2</span>
              <div class="flow-title">Set Cutoff</div>
            </div>
            <p class="flow-text">Adjust the default cutoff values to define custom criteria as needed.</p>
          </div>
        </div>
        <div class="col-12 col-md-4">
          <div class="flow-step">
            <div class="d-flex align-items-center gap-2 mb-1">
              <span class="flow-badge">3</span>
              <div class="flow-title">Render Result Table</div>
            </div>
            <p class="flow-text">Click "Render Genetic Interaction Table" to generate the genetic interaction classification results.</p>
          </div>
        </div>
      </div>
    </section>

    <section class="panel-card p-3 mb-3">
        <h2 class="h5 mb-3">Step 1. Dataset Selection</h2>
        <div class="mb-2 muted-small fw-semibold filter-logic-highlight">
          <strong>Select species first. Options within the same box are matched by OR, while different filter boxes are combined by AND. Gray options are unavailable.</strong>
        </div>
        <div class="mb-2">
          <span class="muted-small me-2">Selected/Matched Dataset ID:</span>
          <span class="fw-bold text-dark" id="datasetCountText" data-total="0">0</span>
        </div>
        <div id="selectedFilterSummary" class="alert alert-light border small py-2 mb-2">Selected filters: none</div>
        <div class="row g-2 mb-2">
          <div class="col-12">
            <div class="filter-box">
              <div class="d-flex flex-wrap align-items-center gap-3">
                <div class="subtle-label mb-0">Cascade Mode</div>
                <div class="d-flex flex-wrap gap-3">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="cascade_mode" id="modeBiosampleFirst" value="biosample_first" checked>
                    <label class="form-check-label small" for="modeBiosampleFirst">Biosample first</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="cascade_mode" id="modeGeneFirst" value="perturbed_gene_first">
                    <label class="form-check-label small" for="modeGeneFirst">Perturbed Gene First</label>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-12">
            <div class="filter-box">
              <div class="d-flex flex-wrap align-items-center gap-3">
                <div class="subtle-label mb-0">Species (Required, Select One or More)</div>
                <div id="speciesOptions" class="d-flex flex-wrap align-items-center gap-3 species-inline"></div>
                <span id="speciesCount" class="filter-count">0/0</span>
              </div>
            </div>
          </div>
          <div class="col-12 col-md filter-main-col" id="filterColTissue">
            <div class="filter-box">
              <div class="filter-head">
                <div class="subtle-label mb-0">Tissue</div>
                <div class="filter-tools">
                  <span id="tissueCount" class="filter-count">0/0</span>
                  <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="tissue" data-action="all">Select All</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="tissue" data-action="clear">Unselect All</button>
                </div>
              </div>
              <input type="search" id="tissueSearch" class="form-control form-control-sm mb-2" placeholder="Search tissue">
              <div class="filter-scroll" id="tissueOptions"></div>
            </div>
          </div>
          <div class="col-12 col-md-auto filter-and-col" id="filterAnd1">
            <span class="filter-and-badge">AND</span>
          </div>
          <div class="col-12 col-md filter-main-col" id="filterColCell">
            <div class="filter-box">
              <div class="filter-head">
                <div class="subtle-label mb-0">Cell Type</div>
                <div class="filter-tools">
                  <span id="cellCount" class="filter-count">0/0</span>
                  <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="cell" data-action="all">Select All</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="cell" data-action="clear">Unselect All</button>
                </div>
              </div>
              <input type="search" id="cellTypeSearch" class="form-control form-control-sm mb-2" placeholder="Search cell type">
              <div class="filter-scroll" id="cellTypeOptions"></div>
            </div>
          </div>
          <div class="col-12 col-md-auto filter-and-col" id="filterAnd2">
            <span class="filter-and-badge">AND</span>
          </div>
          <div class="col-12 col-md filter-main-col" id="filterColPerturb">
            <div class="filter-box">
              <div class="filter-head">
                <div class="subtle-label mb-0">Perturbed Gene</div>
                <div class="filter-tools">
                  <span id="perturbCount" class="filter-count">0/0</span>
                  <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="perturb" data-action="all">Select All</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="perturb" data-action="clear">Unselect All</button>
                </div>
              </div>
              <input type="search" id="perturbSearch" class="form-control form-control-sm mb-2" placeholder="Search perturbation">
              <div class="filter-scroll" id="perturbOptions"></div>
            </div>
          </div>
        </div>
    </section>

    <section class="metric-guide-card p-3 mb-3">
      <h2 class="h6 mb-2">Classification of Genetic Interaction</h2>
      <div class="metric-guide-imgwrap">
        <img src="static/combo_PT_v3.png" alt="Genetic Interaction metric guide" class="metric-guide-img" />
      </div>
    </section>

    <section class="panel-card p-3">
        <h2 class="h5 mb-3">Step 2. Genetic Interaction Classification</h2>
        <div class="gi-module2-layout">
          <div class="gi-explain-card">
            <div class="gi-explain-title">Classification Criteria <a class="method-help-link" href="faq.php#q6-2" target="_blank" rel="noopener noreferrer" title="Open FAQ Q6.2" aria-label="Open FAQ Q6.2">?</a></div>
            <table class="gi-explain-table">
              <thead><tr><th>Genetic Interaction Type</th><th>Cutoff</th></tr></thead>
              <tbody>
                <tr><td class="gi-explain-type">Additive</td><td><span id="guideMagLow2" class="guide-value">1.00</span> &le; Magnitude &le; <span id="guideMagHigh2" class="guide-value">1.15</span></td></tr>
                <tr><td class="gi-explain-type">Synergy</td><td>Magnitude &gt; <span id="guideMagHigh" class="guide-value">1.15</span></td></tr>
                <tr><td class="gi-explain-type">Suppression</td><td>Magnitude &lt; <span id="guideMagLow" class="guide-value">1.00</span></td></tr>
                <tr><td>Neomorphic</td><td>Model Fit &lt; <span id="guideModelCut" class="guide-value">0.88</span></td></tr>
                <tr><td>Redundant</td><td>Similarity &gt; <span id="guideSimCut" class="guide-value">0.85</span></td></tr>
                <tr><td>Epistasis</td><td>Equality &lt; <span id="guideEqCut" class="guide-value">0.28</span></td></tr>
              </tbody>
            </table>
          </div>
          <div class="cutoff-panel">
            <div class="row g-2">
              <div class="col-12">
                <div class="cutoff-card">
                  <div class="cutoff-title">Magnitude</div>
                  <div class="cutoff-value">low=<span id="magLowVal">-</span>, high=<span id="magHighVal">-</span><span class="range-chip" id="magRange"></span></div>
                  <div class="dual-range">
                    <div class="track"></div>
                    <div class="fill" id="magFill"></div>
                    <input id="magLow" type="range" />
                    <input id="magHigh" type="range" />
                  </div>
                </div>
              </div>
              <div class="col-12 col-md-4">
                <div class="cutoff-card">
                  <div class="cutoff-title">Similarity</div>
                  <div class="cutoff-value"><span id="simCutVal">-</span><span class="range-chip" id="simRange"></span></div>
                  <input id="simCut" type="range" class="form-range mb-0" />
                </div>
              </div>
              <div class="col-12 col-md-4">
                <div class="cutoff-card">
                  <div class="cutoff-title">Equality</div>
                  <div class="cutoff-value"><span id="eqCutVal">-</span><span class="range-chip" id="eqRange"></span></div>
                  <input id="eqCut" type="range" class="form-range mb-0" />
                </div>
              </div>
              <div class="col-12 col-md-4">
                <div class="cutoff-card">
                  <div class="cutoff-title">Model Fit</div>
                  <div class="cutoff-value"><span id="modelCutVal">-</span><span class="range-chip" id="modelRange"></span></div>
                  <input id="modelCut" type="range" class="form-range mb-0" />
                </div>
              </div>
            </div>
            <div class="runbar">
              <button id="resetCutoffBtn" class="btn btn-outline-secondary btn-sm" type="button">Reset to defaults</button>
              <div id="countText" class="small fw-bold text-dark">0 rows</div>
              <button id="runBtn" class="btn btn-primary btn-sm">Render Genetic Interaction Table</button>
            </div>
            <div class="d-flex align-items-center justify-content-end gap-2 mt-2">
              <button id="prevPageBtn" type="button" class="btn btn-outline-secondary btn-sm" onclick="window.__giPrevPage && window.__giPrevPage()">Prev</button>
              <div id="pageInfoText" class="small text-muted">Page 0 / 0</div>
              <button id="nextPageBtn" type="button" class="btn btn-outline-secondary btn-sm" onclick="window.__giNextPage && window.__giNextPage()">Next</button>
            </div>
          </div>
        </div>
        <div class="table-responsive gi-table-scroll mt-2">
          <table class="table table-sm table-striped align-middle gi-result-table">
            <thead class="table-light">
              <tr>
                <th>Dataset ID <a class="method-help-link" href="faq.php#q6-4" target="_blank" rel="noopener" title="Dataset ID format differs by data type; see FAQ for details.">?</a></th><th>Perturbed Gene</th><th>Magnitude Class</th><th>Model Fit Class</th><th>Similarity Class</th><th>Equality Class</th>
                <th>assay_type</th><th>species</th><th>tissue</th><th>cell_type</th>
                <th>magnitude</th><th>model_fit</th><th>similarity</th><th>equality</th>
              </tr>
            </thead>
            <tbody id="tb"></tbody>
          </table>
        </div>
      </section>
  </div>
</main>
<footer class="py-3 mt-auto text-center" style="background: transparent; border: none; width: 100%;">
  <div class="container-fluid px-4">
    <div class="footer-text-small-muted">&copy; <span id="year"></span> <a class="footer-link" href="https://www.zhaopage.com">Zhao Lab</a>. All rights reserved.</div>
  </div>
</footer>
<script src="static/lib/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  const nav = document.getElementById('topNav');
  if (!nav) return;
  const syncNav = () => {
    nav.classList.toggle('scrolled', (window.scrollY || window.pageYOffset || 0) > 8);
  };
  syncNav();
  window.addEventListener('resize', syncNav, { passive: true });
  window.addEventListener('scroll', syncNav, { passive: true });
  window.addEventListener('load', syncNav);
  requestAnimationFrame(syncNav);
})();
async function j(url, init = null){
  const r = await fetch(url, { cache: 'no-store', ...(init || {}) });
  const text = await r.text();
  let payload = null;
  try {
    payload = text ? JSON.parse(text) : null;
  } catch (_) {
    payload = null;
    if (text) {
      const start = text.indexOf('{');
      const end = text.lastIndexOf('}');
      if (start >= 0 && end > start) {
        const jsonLike = text.slice(start, end + 1);
        try { payload = JSON.parse(jsonLike); } catch (_) { payload = null; }
      }
    }
  }
  if (!r.ok) throw new Error((payload && payload.message) ? payload.message : `HTTP ${r.status}`);
  if (!payload) throw new Error('Empty response');
  return payload;
}
const ajaxEndpoint = window.location.pathname.split('/').pop() || 'bulk_gi_tool.php';
const GEARS_DEFAULTS = { magLow: 1.0, magHigh: 1.15, simCut: 0.85, eqCut: 0.28, modelCut: 0.88 };
const OPTION_BATCH = 40;
const els = {
  selectedFilterSummary: document.getElementById('selectedFilterSummary'),
  datasetCountText: document.getElementById('datasetCountText'),
  speciesOptions: document.getElementById('speciesOptions'),
  tissueOptions: document.getElementById('tissueOptions'),
  cellOptions: document.getElementById('cellTypeOptions'),
  perturbOptions: document.getElementById('perturbOptions'),
  tissueSearch: document.getElementById('tissueSearch'),
  cellSearch: document.getElementById('cellTypeSearch'),
  perturbSearch: document.getElementById('perturbSearch'),
  modeBiosampleFirst: document.getElementById('modeBiosampleFirst'),
  modeGeneFirst: document.getElementById('modeGeneFirst'),
  filterColTissue: document.getElementById('filterColTissue'),
  filterColCell: document.getElementById('filterColCell'),
  filterColPerturb: document.getElementById('filterColPerturb'),
  filterAnd1: document.getElementById('filterAnd1'),
  filterAnd2: document.getElementById('filterAnd2'),
};
const state = {
  mode: 'biosample_first',
  selected: { species: new Set(), tissue: new Set(), cell: new Set(), perturb: new Set() },
  options: { species: [], tissue: [], cell: [], perturb: [] },
  allOptions: { species: [], tissue: [], cell: [], perturb: [] },
  render: { tissue: { list: [], rendered: 0 }, cell: { list: [], rendered: 0 }, perturb: { list: [], rendered: 0 } },
  matchedDatasetIds: [],
  localIndexBySpecies: new Map(),
  localIndex: null,
  resultPage: 1,
  resultPageSize: 40,
  resultTotalPages: 0,
  resultTotalCount: 0,
};
let filterReqSeq = 0;
function gv(id){ return document.getElementById(id).value.trim(); }
function esc(v){ return String(v ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;'); }
function selectedArray(group){ return Array.from(state.selected[group]); }
function selectedSpecies(){
  if ((state.selected.species || new Set()).size !== 1) return '';
  return selectedArray('species')[0] || '';
}
function appendArrayParams(qs, key, vals){ for(const v of vals){ qs.append(key + '[]', v); } }
function currentFilters(){
  return { species: selectedArray('species'), tissues: selectedArray('tissue'), cell_types: selectedArray('cell'), perturbations: selectedArray('perturb') };
}
function currentFiltersForQuery(){
  const f = currentFilters();
  const isFull = (group) => {
    const opts = state.options[group] || [];
    const sel = state.selected[group] || new Set();
    return opts.length > 0 && sel.size === opts.length;
  };
  if (isFull('tissue')) f.tissues = [];
  if (isFull('cell')) f.cell_types = [];
  if (isFull('perturb')) f.perturbations = [];
  return f;
}
async function ensureLocalIndex(reqSeq = null){
  const sp = selectedSpecies();
  if (!sp) { state.localIndex = null; return false; }
  if (state.localIndexBySpecies.has(sp)) {
    state.localIndex = state.localIndexBySpecies.get(sp) || null;
    return !!state.localIndex;
  }
  let p = null;
  try { p = await j(ajaxEndpoint, { method: 'POST', body: new URLSearchParams({ ajax: 'filter_index', 'species[]': sp }) }); } catch(_) { p = null; }
  if (reqSeq !== null && reqSeq !== filterReqSeq) return false;
  if (!p || !p.ok || !Array.isArray(p.rows)) { state.localIndex = null; return false; }
  const idx = { species: sp, rows: p.rows, options: p.options || { tissue: [], cell: [], perturb: [] } };
  state.localIndexBySpecies.set(sp, idx);
  state.localIndex = idx;
  return true;
}
function uniqueSorted(vals){
  const s = new Set();
  for (const v of vals) { const x = String(v || '').trim(); if (x) s.add(x); }
  return Array.from(s).sort((a,b)=>a.localeCompare(b));
}
function localFilterRows(filters){
  const idx = state.localIndex;
  if (!idx || !Array.isArray(idx.rows)) return [];
  const tSet = new Set((filters.tissues || []).map(v => String(v)));
  const cSet = new Set((filters.cell_types || []).map(v => String(v)));
  const pSet = new Set((filters.perturbations || []).map(v => String(v)));
  const hasT = tSet.size > 0, hasC = cSet.size > 0, hasP = pSet.size > 0;
  const out = [];
  for (const r of idx.rows) {
    if (hasT && !tSet.has(String(r.t || ''))) continue;
    if (hasC && !cSet.has(String(r.c || ''))) continue;
    if (hasP && !pSet.has(String(r.p || ''))) continue;
    out.push(r);
  }
  return out;
}
function localExtractOptions(group, rows){
  if (group === 'tissue') return uniqueSorted(rows.map(r => r.t || ''));
  if (group === 'cell') return uniqueSorted(rows.map(r => r.c || ''));
  if (group === 'perturb') return uniqueSorted(rows.map(r => r.p || ''));
  return [];
}
function modeGroupByLevel(level){
  if (state.mode === 'perturbed_gene_first') {
    if (level === 1) return 'perturb';
    if (level === 2) return 'tissue';
    return 'cell';
  }
  if (level === 1) return 'tissue';
  if (level === 2) return 'cell';
  return 'perturb';
}
function groupLevel(group){
  if (state.mode === 'perturbed_gene_first') {
    if (group === 'perturb') return 1;
    if (group === 'tissue') return 2;
    if (group === 'cell') return 3;
  } else {
    if (group === 'tissue') return 1;
    if (group === 'cell') return 2;
    if (group === 'perturb') return 3;
  }
  return 0;
}
function applyModeLayout(){
  if (state.mode === 'perturbed_gene_first') {
    els.filterColPerturb.style.order = '1';
    if (els.filterAnd1) els.filterAnd1.style.order = '2';
    els.filterColTissue.style.order = '3';
    if (els.filterAnd2) els.filterAnd2.style.order = '4';
    els.filterColCell.style.order = '5';
  } else {
    els.filterColTissue.style.order = '1';
    if (els.filterAnd1) els.filterAnd1.style.order = '2';
    els.filterColCell.style.order = '3';
    if (els.filterAnd2) els.filterAnd2.style.order = '4';
    els.filterColPerturb.style.order = '5';
  }
}
function hasExplicitEmptySelection(){
  if (state.selected.species.size === 0) return false;
  const level1 = modeGroupByLevel(1);
  if ((state.selected[level1] || new Set()).size === 0) return false;
  return ['tissue', 'cell', 'perturb'].some((g) => (state.selected[g] || new Set()).size === 0);
}
function getSearch(group){
  if (group === 'tissue') return (els.tissueSearch.value || '').trim();
  if (group === 'cell') return (els.cellSearch.value || '').trim();
  if (group === 'perturb') return (els.perturbSearch.value || '').trim();
  return '';
}
function getWrap(group){
  if (group === 'species') return els.speciesOptions;
  if (group === 'tissue') return els.tissueOptions;
  if (group === 'cell') return els.cellOptions;
  return els.perturbOptions;
}
async function fetchGroupOptions(group, scope, reqSeq = null){
  if (state.localIndex && group !== 'species') {
    const f = currentFiltersForQuery();
    const local = { tissues: [], cell_types: [], perturbations: [] };
    if (scope !== 'all') {
      if (state.mode === 'perturbed_gene_first') {
        if (group === 'tissue') local.perturbations = f.perturbations;
        else if (group === 'cell') { local.perturbations = f.perturbations; local.tissues = f.tissues; }
      } else {
        if (group === 'cell') local.tissues = f.tissues;
        else if (group === 'perturb') { local.tissues = f.tissues; local.cell_types = f.cell_types; }
      }
    }
    const rows = localFilterRows(local);
    return localExtractOptions(group, rows);
  }
  const f = currentFiltersForQuery();
  const qs = new URLSearchParams();
  qs.set('ajax', 'filter_options');
  qs.set('group', group);
  qs.set('mode', state.mode);
  qs.set('scope', scope);
  qs.set('level', String(groupLevel(group)));
  qs.set('q', getSearch(group));
  qs.set('limit', '5000');
  appendArrayParams(qs, 'species', f.species);
  appendArrayParams(qs, 'tissues', f.tissues);
  appendArrayParams(qs, 'cell_types', f.cell_types);
  appendArrayParams(qs, 'perturbations', f.perturbations);
  const p = await j(ajaxEndpoint, { method: 'POST', body: qs });
  if (reqSeq !== null && reqSeq !== filterReqSeq) return [];
  return (p && p.ok && Array.isArray(p.options)) ? p.options : [];
}
function renderSpecies(){
  const wrap = getWrap('species');
  const all = state.allOptions.species || [];
  const selected = state.selected.species || new Set();
  if (!all.length) { wrap.innerHTML = '<div class="muted-small">No options.</div>'; return; }
  const ordered = [...all.filter(v => selected.has(v)), ...all.filter(v => !selected.has(v))];
  wrap.innerHTML = ordered.map((v, i) => {
    const id = `species_${i}_${String(v).replace(/[^A-Za-z0-9_]/g,'_')}`;
    return `<div class="form-check mb-0"><input class="form-check-input filter-checkbox filter-species" type="checkbox" value="${esc(v)}" id="${id}" ${selected.has(v) ? 'checked' : ''}><label class="form-check-label small" for="${id}">${esc(v)}</label></div>`;
  }).join('');
}
function renderGroup(group, reset = true){
  const wrap = getWrap(group);
  const all = state.allOptions[group] || [];
  const eligible = state.options[group] || [];
  const eligibleSet = new Set(eligible);
  const selected = state.selected[group];
  const q = getSearch(group).toLowerCase();
  const ordered = [...eligible.filter(v => selected.has(v)), ...eligible.filter(v => !selected.has(v)), ...all.filter(v => !eligibleSet.has(v))];
  const list = q ? ordered.filter((v) => v.toLowerCase().includes(q)) : ordered;
  if (!list.length) { wrap.innerHTML = '<div class="muted-small">No options.</div>'; return; }
  if (reset) { state.render[group].list = list; state.render[group].rendered = 0; wrap.innerHTML = ''; }
  const st = state.render[group];
  const start = st.rendered;
  const end = Math.min(start + OPTION_BATCH, st.list.length);
  const chunk = st.list.slice(start, end);
  st.rendered = end;
  const html = chunk.map((v, i) => {
    const id = `${group}_${start + i}_${String(v).replace(/[^A-Za-z0-9_]/g,'_')}`;
    const disabled = !eligibleSet.has(v);
    return `<div class="form-check mb-1 filter-item"><input class="form-check-input filter-checkbox filter-${group}" type="checkbox" value="${esc(v)}" id="${id}" ${selected.has(v) ? 'checked' : ''} ${disabled ? 'disabled' : ''}><label class="form-check-label small${disabled ? ' is-disabled-option' : ''}" for="${id}">${esc(v)}${disabled ? ' <span class="disabled-tag">(Unavailable)</span>' : ''}</label></div>`;
  }).join('');
  if (start === 0) wrap.innerHTML = html; else wrap.insertAdjacentHTML('beforeend', html);
  const oldHint = wrap.querySelector('.opt-hint'); if (oldHint) oldHint.remove();
  const remain = st.list.length - st.rendered;
  const hint = document.createElement('div'); hint.className = 'muted-small opt-hint';
  hint.textContent = remain > 0 ? `Showing ${st.rendered}/${st.list.length}. Scroll to load more (${remain} left).` : `Showing all ${st.list.length}.`;
  wrap.appendChild(hint);
}
function updateFilterSummary(){
  const toText = (arr, label) => arr.length ? `${label}: ${arr.slice(0, 5).join(', ')}${arr.length > 5 ? ` ... (+${arr.length - 5})` : ''}` : '';
  const f = currentFilters();
  const parts = [toText(f.species, 'Species'), toText(f.tissues, 'Tissue'), toText(f.cell_types, 'Cell Type'), toText(f.perturbations, 'Perturbed Gene')].filter(Boolean);
  parts.push(`Cascade Mode: ${state.mode === 'perturbed_gene_first' ? 'Perturbed Gene First' : 'Biosample first'}`);
  els.selectedFilterSummary.textContent = 'Selected filters: ' + (parts.length ? parts.join(' | ') : 'none');
}
function updateFilterCounts(){
  const setCount = (id, selectedN, totalN) => { const el = document.getElementById(id); if (el) el.textContent = `${selectedN}/${totalN}`; };
  setCount('speciesCount', state.selected.species.size, (state.allOptions.species || []).length);
  setCount('tissueCount', state.selected.tissue.size, (state.options.tissue || []).length);
  setCount('cellCount', state.selected.cell.size, (state.options.cell || []).length);
  setCount('perturbCount', state.selected.perturb.size, (state.options.perturb || []).length);
}
function updateDatasetCountText(matched){
  const n = Number.isFinite(Number(matched)) ? Number(matched) : 0;
  els.datasetCountText.datasetTotal = String(n);
  els.datasetCountText.textContent = String(n);
}
async function fetchDatasets(reqSeq = null){
  if (state.selected.species.size === 0) {
    state.matchedDatasetIds = [];
    updateDatasetCountText(0);
    return;
  }
  const level1 = modeGroupByLevel(1);
  if ((state.selected[level1] || new Set()).size === 0) {
    state.matchedDatasetIds = [];
    updateDatasetCountText(0);
    return;
  }
  if (hasExplicitEmptySelection()) {
    state.matchedDatasetIds = [];
    updateDatasetCountText(0);
    return;
  }
  if (state.localIndex) {
    const f = currentFiltersForQuery();
    const rows = localFilterRows({ tissues: f.tissues, cell_types: f.cell_types, perturbations: f.perturbations });
    const idsSet = {};
    for (const r of rows) {
      for (const tok of String(r.d || '').split('|').map(x => x.trim()).filter(Boolean)) idsSet[tok] = true;
    }
    const ids = Object.keys(idsSet).sort((a,b)=>a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
    state.matchedDatasetIds = ids;
    updateDatasetCountText(ids.length);
    return;
  }
  const f = currentFiltersForQuery();
  const qs = new URLSearchParams();
  qs.set('ajax', 'datasets');
  appendArrayParams(qs, 'species', f.species);
  appendArrayParams(qs, 'tissues', f.tissues);
  appendArrayParams(qs, 'cell_types', f.cell_types);
  appendArrayParams(qs, 'perturbations', f.perturbations);
  qs.set('limit', '0');
  const p = await j(ajaxEndpoint, { method: 'POST', body: qs });
  if (reqSeq !== null && reqSeq !== filterReqSeq) return;
  state.matchedDatasetIds = Array.isArray(p.dataset_ids) ? p.dataset_ids : [];
  updateDatasetCountText(Number(p.count || state.matchedDatasetIds.length || 0));
}
async function refreshSpecies(reqSeq = null){
  const opts = await fetchGroupOptions('species', 'all', reqSeq);
  // Keep current selection/options on transient empty response to avoid full state reset.
  if (!Array.isArray(opts) || opts.length === 0) {
    if ((state.selected.species || new Set()).size > 0 && (state.allOptions.species || []).length > 0) {
      renderSpecies();
      updateFilterCounts();
      return;
    }
  }
  state.allOptions.species = Array.isArray(opts) ? opts : [];
  state.options.species = Array.isArray(opts) ? opts : [];
  const set = new Set(state.allOptions.species);
  state.selected.species = new Set(Array.from(state.selected.species).filter(v => set.has(v)));
  renderSpecies();
  updateFilterCounts();
}
async function refreshCascadeFromLevel(startLevel, reqSeq = null){
  if (state.selected.species.size === 0) {
    state.localIndex = null;
    ['tissue', 'cell', 'perturb'].forEach((g) => {
      state.options[g] = [];
      state.allOptions[g] = [];
      state.selected[g] = new Set();
      getWrap(g).innerHTML = '<div class="muted-small">Select at least one species first.</div>';
    });
    state.matchedDatasetIds = [];
    updateDatasetCountText(0);
    updateFilterSummary();
    updateFilterCounts();
    return;
  }
  await ensureLocalIndex(reqSeq);
  if (reqSeq !== null && reqSeq !== filterReqSeq) return;
  for (let level = startLevel; level <= 3; level++) {
    const g = modeGroupByLevel(level);
    const allOpts = await fetchGroupOptions(g, 'all', reqSeq);
    let eligibleOpts = [];
    if (level === 1) {
      eligibleOpts = await fetchGroupOptions(g, 'eligible', reqSeq);
    } else {
      const parent = modeGroupByLevel(level - 1);
      if ((state.selected[parent] || new Set()).size > 0) eligibleOpts = await fetchGroupOptions(g, 'eligible', reqSeq);
    }
    if (reqSeq !== null && reqSeq !== filterReqSeq) return;
    state.allOptions[g] = allOpts;
    state.options[g] = eligibleOpts;
    const eligibleSet = new Set(eligibleOpts);
    const prev = state.selected[g] || new Set();
    if (level > 1 && eligibleOpts.length > 0) state.selected[g] = new Set(eligibleOpts);
    else state.selected[g] = new Set(Array.from(prev).filter(v => eligibleSet.has(v)));
    renderGroup(g, true);
  }
  updateFilterSummary();
  updateFilterCounts();
  await fetchDatasets(reqSeq);
}
async function refreshAllFiltersAndDatasets(changedGroup = null, modeSwitched = false){
  const reqSeq = ++filterReqSeq;
  applyModeLayout();
  // Species options are stable; avoid refetching on every downstream click.
  if (changedGroup === 'species' || !changedGroup || modeSwitched) await refreshSpecies(reqSeq);
  if (modeSwitched || changedGroup === 'species' || !changedGroup) {
    if (modeSwitched || changedGroup === 'species') {
      state.selected.tissue.clear();
      state.selected.cell.clear();
      state.selected.perturb.clear();
    }
    await refreshCascadeFromLevel(1, reqSeq);
    return;
  }
  const lv = groupLevel(changedGroup);
  if (lv === 1) await refreshCascadeFromLevel(2, reqSeq);
  else if (lv === 2) await refreshCascadeFromLevel(3, reqSeq);
  else {
    updateFilterSummary();
    updateFilterCounts();
    await fetchDatasets(reqSeq);
  }
}
function debounce(fn, wait = 220){
  let t = null;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), wait); };
}
function fmt2(v){ const n = Number(v); return Number.isFinite(n) ? n.toFixed(2) : '0.00'; }
function fmtCell2(v){
  if (v === null || v === undefined || v === '') return '';
  const n = Number(v);
  return Number.isFinite(n) ? n.toFixed(2) : '';
}
function toClassLabel(v){
  return String(v ?? '')
    .replace(/[_-]+/g, ' ')
    .replace(/\b([a-z])/g, (_, c) => c.toUpperCase());
}
function getCurrentCutoffs(){
  return {
    magLow: Number(gv('magLow')),
    magHigh: Number(gv('magHigh')),
    simCut: Number(gv('simCut')),
    eqCut: Number(gv('eqCut')),
    modelCut: Number(gv('modelCut')),
  };
}
function computeClassesFromMetrics(m, mf, sim, eq, cut){
  let magClass = 'unknown';
  if (Number.isFinite(m)) {
    if (m > cut.magHigh) magClass = 'synergy';
    else if (m < cut.magLow) magClass = 'suppression';
    else magClass = 'additive';
  }
  let mfClass = 'unknown';
  if (Number.isFinite(mf)) mfClass = (mf < cut.modelCut) ? 'neomorphic' : 'non_neomorphic';
  let simClass = 'unknown';
  if (Number.isFinite(sim)) simClass = (sim > cut.simCut) ? 'redundant' : 'non_redundant';
  let eqClass = 'unknown';
  if (Number.isFinite(eq)) eqClass = (eq < cut.eqCut) ? 'epistasis' : 'non_epistasis';
  return { magClass, mfClass, simClass, eqClass };
}
function refreshClassColumnsInTable(){
  const tb = document.getElementById('tb');
  if (!tb) return;
  const cut = getCurrentCutoffs();
  const rows = tb.querySelectorAll('tr');
  rows.forEach((tr) => {
    const m = Number(tr.dataset.magnitude);
    const mf = Number(tr.dataset.modelFit);
    const sim = Number(tr.dataset.similarity);
    const eq = Number(tr.dataset.equality);
    const cls = computeClassesFromMetrics(m, mf, sim, eq, cut);
    const cells = tr.children;
    if (cells.length >= 6) {
      cells[2].textContent = toClassLabel(cls.magClass);
      cells[3].textContent = toClassLabel(cls.mfClass);
      cells[4].textContent = toClassLabel(cls.simClass);
      cells[5].textContent = toClassLabel(cls.eqClass);
    }
  });
}
function updateGiGuide(){
  const magLow = document.getElementById('magLow');
  const magHigh = document.getElementById('magHigh');
  const sim = document.getElementById('simCut');
  const eq = document.getElementById('eqCut');
  const model = document.getElementById('modelCut');
  const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = fmt2(val); };
  if (!magLow || !magHigh || !sim || !eq || !model) return;
  setText('guideMagLow', magLow.value);
  setText('guideMagLow2', magLow.value);
  setText('guideMagHigh', magHigh.value);
  setText('guideMagHigh2', magHigh.value);
  setText('guideSimCut', sim.value);
  setText('guideEqCut', eq.value);
  setText('guideModelCut', model.value);
}
function setSlider(id,min,max,val){
  const el = document.getElementById(id);
  const lo = Number(min), hi = Number(max);
  const step = Math.max((hi-lo)/1000, 0.0001);
  el.min = String(lo); el.max = String(hi); el.step = String(step);
  el.value = String(Math.max(lo, Math.min(hi, Number(val))));
}
function bindSliderValue(sliderId, labelId, fmt=2){
  const s = document.getElementById(sliderId), l = document.getElementById(labelId);
  const u = ()=> { l.textContent = Number(s.value).toFixed(fmt); updateGiGuide(); refreshClassColumnsInTable(); };
  s.addEventListener('input', u); u();
}
function updateMagFill(){
  const lo = document.getElementById('magLow'), hi = document.getElementById('magHigh');
  const fill = document.getElementById('magFill');
  const min = Number(lo.min), max = Number(lo.max);
  const lp = ((Number(lo.value)-min)/(max-min))*100;
  const hp = ((Number(hi.value)-min)/(max-min))*100;
  fill.style.left = `${lp}%`; fill.style.width = `${Math.max(0, hp-lp)}%`;
}
function bindMagPair(){
  const lo = document.getElementById('magLow'), hi = document.getElementById('magHigh');
  const sync = (src)=> {
    const step = Number(lo.step) || 0.0001;
    let lv = Number(lo.value), hv = Number(hi.value);
    if (lv >= hv) {
      if (src === 'low') lv = Math.max(Number(lo.min), hv - step);
      else hv = Math.min(Number(hi.max), lv + step);
      lo.value = String(lv); hi.value = String(hv);
    }
    document.getElementById('magLowVal').textContent = Number(lo.value).toFixed(2);
    document.getElementById('magHighVal').textContent = Number(hi.value).toFixed(2);
    updateMagFill();
    updateGiGuide();
    refreshClassColumnsInTable();
  };
  lo.addEventListener('input', ()=>sync('low'));
  hi.addEventListener('input', ()=>sync('high'));
  sync('high');
}
function applyDefaultCutoffs(){
  const lo = document.getElementById('magLow');
  const hi = document.getElementById('magHigh');
  const sim = document.getElementById('simCut');
  const eq = document.getElementById('eqCut');
  const mf = document.getElementById('modelCut');
  if (!lo || !hi || !sim || !eq || !mf) return;

  lo.value = String(Math.max(Number(lo.min), Math.min(Number(lo.max), GEARS_DEFAULTS.magLow)));
  hi.value = String(Math.max(Number(hi.min), Math.min(Number(hi.max), GEARS_DEFAULTS.magHigh)));
  sim.value = String(Math.max(Number(sim.min), Math.min(Number(sim.max), GEARS_DEFAULTS.simCut)));
  eq.value = String(Math.max(Number(eq.min), Math.min(Number(eq.max), GEARS_DEFAULTS.eqCut)));
  mf.value = String(Math.max(Number(mf.min), Math.min(Number(mf.max), GEARS_DEFAULTS.modelCut)));

  // Trigger existing UI sync handlers.
  lo.dispatchEvent(new Event('input', { bubbles: true }));
  hi.dispatchEvent(new Event('input', { bubbles: true }));
  sim.dispatchEvent(new Event('input', { bubbles: true }));
  eq.dispatchEvent(new Event('input', { bubbles: true }));
  mf.dispatchEvent(new Event('input', { bubbles: true }));
}
async function loadBounds(){
  const p = await j(`${ajaxEndpoint}?ajax=metric_bounds`);
  if(!p.ok) return;
  const b = p.bounds || {}, mag = b.magnitude || {min:0,max:1}, sim = b.similarity || {min:0,max:1}, eq = b.equality || {min:0,max:1}, mf = b.model_fit || {min:0,max:1};
  document.getElementById('magRange').textContent = `[${Number(mag.min).toFixed(2)}, ${Number(mag.max).toFixed(2)}]`;
  document.getElementById('simRange').textContent = `[0.00, 1.00]`;
  document.getElementById('eqRange').textContent = `[0.00, 1.00]`;
  document.getElementById('modelRange').textContent = `[0.00, 1.00]`;

  const magMin = Math.min(Number(mag.min), GEARS_DEFAULTS.magLow, GEARS_DEFAULTS.magHigh);
  const magMax = Math.max(Number(mag.max), GEARS_DEFAULTS.magLow, GEARS_DEFAULTS.magHigh);
  const simMin = 0;
  const simMax = 1;
  const eqMin = 0;
  const eqMax = 1;
  const mfMin = 0;
  const mfMax = 1;

  setSlider('magLow', magMin, magMax, GEARS_DEFAULTS.magLow);
  setSlider('magHigh', magMin, magMax, GEARS_DEFAULTS.magHigh);
  setSlider('simCut', simMin, simMax, GEARS_DEFAULTS.simCut);
  setSlider('eqCut', eqMin, eqMax, GEARS_DEFAULTS.eqCut);
  setSlider('modelCut', mfMin, mfMax, GEARS_DEFAULTS.modelCut);
  bindMagPair(); bindSliderValue('simCut', 'simCutVal'); bindSliderValue('eqCut', 'eqCutVal'); bindSliderValue('modelCut', 'modelCutVal');
  applyDefaultCutoffs();
  updateGiGuide();
}
async function run(){
  await runPage(1);
}
function updatePagerUi(){
  const infoEl = document.getElementById('pageInfoText');
  const page = Number(state.resultPage || 1);
  const totalPages = Number(state.resultTotalPages || 0);
  if (infoEl) infoEl.textContent = `Page ${totalPages > 0 ? page : 0} / ${totalPages}`;
}
async function runPage(page){
  const species = selectedArray('species');
  const countEl = document.getElementById('countText');
  if(!species.length){
    if (countEl) {
      countEl.textContent = 'Please select at least one species in Step 1.';
      countEl.classList.remove('text-dark');
      countEl.classList.add('text-danger');
    }
    state.resultPage = 1;
    state.resultTotalPages = 0;
    state.resultTotalCount = 0;
    updatePagerUi();
    return;
  }
  const currentPage = Math.max(1, Number(page || 1));
  const infoEl = document.getElementById('pageInfoText');
  if (infoEl) infoEl.textContent = 'Loading...';
  const qs = new URLSearchParams();
  qs.set('ajax','query');
  appendArrayParams(qs, 'species', selectedArray('species'));
  appendArrayParams(qs, 'tissues', selectedArray('tissue'));
  appendArrayParams(qs, 'cell_types', selectedArray('cell'));
  appendArrayParams(qs, 'perturbations', selectedArray('perturb'));
  qs.set('mag_low', gv('magLow')); qs.set('mag_high', gv('magHigh'));
  qs.set('sim_cut', gv('simCut')); qs.set('eq_cut', gv('eqCut')); qs.set('model_cut', gv('modelCut'));
  qs.set('page', String(currentPage));
  qs.set('page_size', String(state.resultPageSize || 200));
  let p = null;
  try {
    p = await j(ajaxEndpoint, { method: 'POST', body: qs });
  } catch (e) {
    if (countEl) {
      countEl.textContent = (e && e.message) ? e.message : 'Query failed';
      countEl.classList.remove('text-danger');
      countEl.classList.add('text-dark');
    }
    state.resultPage = currentPage;
    state.resultTotalPages = 0;
    state.resultTotalCount = 0;
    updatePagerUi();
    return;
  }
  const tb = document.getElementById('tb'); tb.innerHTML = '';
  if(!p.ok){
    if (countEl) {
      countEl.textContent = p.message || 'Query failed';
      countEl.classList.remove('text-danger');
      countEl.classList.add('text-dark');
    }
    state.resultPage = currentPage;
    state.resultTotalPages = 0;
    state.resultTotalCount = 0;
    updatePagerUi();
    return;
  }
  for(const r of (p.rows||[])){
    const tr = document.createElement('tr');
    tr.dataset.magnitude = (r.magnitude ?? '');
    tr.dataset.modelFit = (r.model_fit ?? '');
    tr.dataset.similarity = (r.similarity ?? '');
    tr.dataset.equality = (r.equality ?? '');
    tr.innerHTML = `<td>${r.dataset_id||''}</td><td>${r.target_gene_name||''}</td><td>${toClassLabel(r.magnitude_class)}</td><td>${toClassLabel(r.model_fit_class)}</td><td>${toClassLabel(r.similarity_class)}</td><td>${toClassLabel(r.equality_class)}</td>
    <td>${r.assay_type||''}</td><td>${r.species||''}</td><td>${r.tissue||''}</td><td>${r.cell_type||''}</td>
    <td>${fmtCell2(r.magnitude)}</td><td>${fmtCell2(r.model_fit)}</td><td>${fmtCell2(r.similarity)}</td><td>${fmtCell2(r.equality)}</td>`;
    tb.appendChild(tr);
  }
  refreshClassColumnsInTable();
  state.resultPage = Number(p.page || currentPage);
  state.resultTotalPages = Number(p.total_pages || 0);
  state.resultTotalCount = Number(p.total_count || p.count || 0);
  updatePagerUi();
  if (countEl) {
    countEl.textContent = `${state.resultTotalCount || 0} rows`;
    countEl.classList.remove('text-danger');
    countEl.classList.add('text-dark');
  }
}
async function clearAllFilters(){
  state.selected.species.clear();
  state.selected.tissue.clear();
  state.selected.cell.clear();
  state.selected.perturb.clear();
  document.getElementById('tissueSearch').value = '';
  document.getElementById('cellTypeSearch').value = '';
  document.getElementById('perturbSearch').value = '';
  await refreshAllFiltersAndDatasets('species', true);
}
document.getElementById('runBtn').addEventListener('click', run);
document.getElementById('prevPageBtn').addEventListener('click', () => {
  if ((state.resultPage || 1) > 1) runPage((state.resultPage || 1) - 1);
});
document.getElementById('nextPageBtn').addEventListener('click', () => {
  if ((state.resultPage || 1) < (state.resultTotalPages || 0)) runPage((state.resultPage || 1) + 1);
});
window.__giPrevPage = () => {
  if ((state.resultPage || 1) > 1) runPage((state.resultPage || 1) - 1);
};
window.__giNextPage = () => {
  if ((state.resultPage || 1) < (state.resultTotalPages || 0)) runPage((state.resultPage || 1) + 1);
};
document.getElementById('resetCutoffBtn').addEventListener('click', applyDefaultCutoffs);
document.querySelectorAll('button[data-group][data-action]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const group = btn.getAttribute('data-group');
    const action = btn.getAttribute('data-action');
    if (!group || !state.selected[group]) return;
    if (action === 'all') state.selected[group] = new Set(state.options[group] || []);
    if (action === 'clear') state.selected[group].clear();
    renderGroup(group, true);
    updateFilterSummary();
    updateFilterCounts();
    refreshAllFiltersAndDatasets(group).catch(() => {});
  });
});
function bindFilterGroup(group){
  const wrap = getWrap(group);
  wrap.addEventListener('change', (e) => {
    const t = e.target;
    if (!(t instanceof HTMLInputElement)) return;
    if (!t.classList.contains(`filter-${group}`)) return;
    if (t.checked) state.selected[group].add(t.value);
    else state.selected[group].delete(t.value);
    if (group === 'species') renderSpecies();
    else renderGroup(group, true);
    refreshAllFiltersAndDatasets(group).catch(() => {});
  });
  if (group === 'species') return;
  wrap.addEventListener('scroll', () => {
    const nearBottom = wrap.scrollTop + wrap.clientHeight >= wrap.scrollHeight - 18;
    if (nearBottom) renderGroup(group, false);
  });
}
const debRefresh = debounce(() => {
  renderGroup('tissue', true);
  renderGroup('cell', true);
  renderGroup('perturb', true);
}, 220);
document.getElementById('tissueSearch').addEventListener('input', debRefresh);
document.getElementById('cellTypeSearch').addEventListener('input', debRefresh);
document.getElementById('perturbSearch').addEventListener('input', debRefresh);
[els.modeBiosampleFirst, els.modeGeneFirst].forEach((el) => {
  el.addEventListener('change', () => {
    if (!el.checked) return;
    state.mode = el.value;
    refreshAllFiltersAndDatasets('species', true).catch(() => {});
  });
});
bindFilterGroup('species');
bindFilterGroup('tissue');
bindFilterGroup('cell');
bindFilterGroup('perturb');
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((node) => {
  new bootstrap.Tooltip(node);
});
Promise.all([refreshAllFiltersAndDatasets(), loadBounds()]).catch(() => {});
</script>
<script>document.getElementById('year').textContent = new Date().getFullYear();</script>
</body>
</html>


