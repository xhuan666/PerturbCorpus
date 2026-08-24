<?php
require_once __DIR__ . '/config.php';

const DB_SC_COR_FILE_PRIMARY = __DIR__ . '/sqlite3/single_cell_cor.db';
const DB_SC_COR_FILE_FALLBACK = __DIR__ . '/sqlite3/sc_cor.db';
const HEATMAP_MAX_LABELS = 4000;
const HEATMAP_DEFAULT_BATCH_NUM = 200;

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function request_param(string $key, $default = '')
{
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    return $default;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = json_encode([
            'ok' => false,
            'message' => 'JSON encoding failed.',
        ], JSON_UNESCAPED_UNICODE);
    }
    echo $json;
    exit;
}

function client_ip_for_rate_limit(): string
{
    $candidates = [
        (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''),
        (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
        (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ];
    foreach ($candidates as $raw) {
        if ($raw === '') continue;
        $ip = trim(explode(',', $raw)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return 'unknown';
}

function enforce_ajax_rate_limit(int $maxRequests = 90, int $windowSeconds = 60): void
{
    $dir = __DIR__ . '/temp/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        return;
    }
    $ip = client_ip_for_rate_limit();
    $key = hash('sha256', 'sc_cor_ajax|' . $ip);
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

function cleanup_stale_temp_json_files(int $expireSeconds = 86400): void
{
    $dir = __DIR__ . '/temp';
    if (!is_dir($dir)) return;
    $now = time();
    foreach ((glob($dir . '/*.json') ?: []) as $file) {
        $mtime = @filemtime($file);
        if ($mtime === false) continue;
        if (($now - (int)$mtime) > $expireSeconds) {
            @unlink($file);
        }
    }
}

function get_sc_cor_pdo(): PDO
{
    $dbFile = null;
    if (file_exists(DB_SC_COR_FILE_PRIMARY)) {
        $dbFile = DB_SC_COR_FILE_PRIMARY;
    } elseif (file_exists(DB_SC_COR_FILE_FALLBACK)) {
        $dbFile = DB_SC_COR_FILE_FALLBACK;
    }
    if ($dbFile === null) {
        throw new RuntimeException('single_cell_cor.db (or fallback sc_cor.db) not found.');
    }
    if (!file_exists(DB_META_FILE)) {
        throw new RuntimeException('dataset_meta.db not found.');
    }
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("ATTACH DATABASE " . $pdo->quote(DB_META_FILE) . " AS meta_db");
    return $pdo;
}

function ini_bytes(string $val): int
{
    $v = trim($val);
    if ($v === '' || $v === '-1') return PHP_INT_MAX;
    $u = strtolower(substr($v, -1));
    $n = (float)$v;
    return match ($u) {
        'g' => (int)($n * 1024 * 1024 * 1024),
        'm' => (int)($n * 1024 * 1024),
        'k' => (int)($n * 1024),
        default => (int)$n,
    };
}

function ensure_runtime_limits_for_heatmap(): void
{
    @set_time_limit(0);
    $cur = ini_bytes((string)ini_get('memory_limit'));
    $target = 1024 * 1024 * 1024; // 1G
    if ($cur < $target) {
        @ini_set('memory_limit', '1G');
    }
}

function normalize_metric_name(string $metric): string
{
    $m = strtolower(trim($metric));
    return match ($m) {
        'pearson', 'pearson_distance' => 'pearson_distance',
        'spearman', 'spearman_distance' => 'spearman_distance',
        'edistance', 'e_distance' => 'edistance',
        'mmd' => 'mmd',
        default => 'cosine_similarity',
    };
}

function metric_column(string $metric): string
{
    return match (normalize_metric_name($metric)) {
        'pearson_distance' => 'pearson_i',
        'spearman_distance' => 'spearman_i',
        'edistance' => 'edistance_i',
        'mmd' => 'mmd_i',
        default => 'cosine_i',
    };
}

function metric_diag_value(string $metric): float
{
    $m = normalize_metric_name($metric);
    if ($m === 'cosine_similarity') return 1.0;
    return 0.0;
}

function method_allowed_metrics(string $method): array
{
    $m = strtolower(trim($method));
    if ($m === 'exp_cor') {
        return ['cosine_similarity', 'pearson_distance', 'spearman_distance', 'edistance', 'mmd'];
    }
    // ada/model3 are generated by GenePT embeddings.
    return ['cosine_similarity', 'pearson_distance', 'spearman_distance'];
}

function build_filter_where(array $filters, array &$params): string
{
    $where = [];

    $species = $filters['species'] ?? [];
    if (!is_array($species)) $species = [];
    $species = array_values(array_unique(array_filter(array_map('trim', $species), static fn($x) => $x !== '')));
    if ($species) {
        $in = [];
        foreach ($species as $i => $v) {
            $k = ':species_' . $i;
            $in[] = $k;
            $params[$k] = $v;
        }
        $where[] = "LOWER(COALESCE(dm.meta_biosample_species,'')) IN (" . implode(',', array_map(static fn($k) => "LOWER($k)", $in)) . ")";
    }

    $tissues = $filters['tissues'] ?? [];
    if (!is_array($tissues)) $tissues = [];
    $tissues = array_values(array_unique(array_filter(array_map('trim', $tissues), static fn($x) => $x !== '')));
    if ($tissues) {
        $in = [];
        foreach ($tissues as $i => $v) {
            $k = ':tissue_' . $i;
            $in[] = $k;
            $params[$k] = $v;
        }
        $where[] = "LOWER(COALESCE(dm.meta_biosample_tissue_name,'')) IN (" . implode(',', array_map(static fn($k) => "LOWER($k)", $in)) . ")";
    }

    $celllines = $filters['celllines'] ?? [];
    if (!is_array($celllines)) $celllines = [];
    $celllines = array_values(array_unique(array_filter(array_map('trim', $celllines), static fn($x) => $x !== '')));
    if ($celllines) {
        $in = [];
        foreach ($celllines as $i => $v) {
            $k = ':cell_' . $i;
            $in[] = $k;
            $params[$k] = $v;
        }
        $where[] = "LOWER(COALESCE(dm.meta_biosample_description,'')) IN (" . implode(',', array_map(static fn($k) => "LOWER($k)", $in)) . ")";
    }

    return $where ? (' AND ' . implode(' AND ', $where)) : '';
}

function perturbation_class(string $label): string
{
    $v = trim($label);
    if ($v === '' || strtoupper($v) === 'NA') return 'NA';
    if (strtoupper($v) === 'CONTROL' || strtoupper($v) === 'CTRL') return 'Control';
    $parts = array_values(array_filter(array_map('trim', explode('|', $v)), static fn($x) => $x !== ''));
    foreach ($parts as $p) {
        if (strtoupper($p) === 'NA') return 'NA';
    }
    $n = count($parts);
    if ($n <= 1) return 'Single-gene';
    if ($n === 2) return 'Double-gene';
    return 'Multi-gene';
}

function ajax_filter_options(PDO $pdo): void
{
    $group = strtolower(trim((string)request_param('group', '')));
    $scope = strtolower(trim((string)request_param('scope', 'eligible')));
    $level = max(1, min(2, (int)request_param('level', 1)));
    $q = trim((string)request_param('q', ''));
    $limit = min(5000, max(40, (int)request_param('limit', 2000)));

    $species = array_values(array_filter(array_map('trim', explode(',', (string)request_param('species', ''))), static fn($x) => $x !== ''));
    $tissues = array_values(array_filter(array_map('trim', explode(',', (string)request_param('tissues', ''))), static fn($x) => $x !== ''));
    $celllines = array_values(array_filter(array_map('trim', explode(',', (string)request_param('celllines', ''))), static fn($x) => $x !== ''));

    $params = [];
    $filterForOptions = ['species' => [], 'tissues' => [], 'celllines' => []];
    // Fixed cascade mode for single-cell correlation: biosample_first.
    // L1: tissue; L2: cellline.
    if ($scope === 'all') {
        if ($group !== 'species') {
            $filterForOptions['species'] = $species;
        }
    } else {
        if ($group !== 'species') {
            $filterForOptions['species'] = $species;
        }
        if ($group === 'cellline') {
            $filterForOptions['tissues'] = $tissues;
        }
    }
    $extra = build_filter_where($filterForOptions, $params);

    $col = match ($group) {
        'species' => 'dm.meta_biosample_species',
        'tissue' => 'dm.meta_biosample_tissue_name',
        'cellline' => 'dm.meta_biosample_description',
        default => '',
    };
    if ($col === '') {
        json_response(['ok' => false, 'message' => 'Unknown filter group'], 400);
    }
    $params[':q'] = '%' . $q . '%';
    $sql = "
      SELECT DISTINCT $col AS v
      FROM meta_db.dataset_meta dm
      INNER JOIN dataset d ON d.dataset_id = dm.dataset_id
      WHERE 1=1 $extra
        AND $col IS NOT NULL
        AND TRIM($col) <> ''
        AND LOWER($col) LIKE LOWER(:q)
      ORDER BY v
      LIMIT :lim
    ";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, PDO::PARAM_STR);
    }
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    json_response([
        'ok' => true,
        'group' => $group,
        'options' => $st->fetchAll(PDO::FETCH_COLUMN) ?: [],
        'scope' => $scope,
        'level' => $level,
    ]);
}

function ajax_dataset_filter(PDO $pdo): void
{
    $species = array_values(array_filter(array_map('trim', explode(',', (string)request_param('species', ''))), static fn($x) => $x !== ''));
    $tissues = array_values(array_filter(array_map('trim', explode(',', (string)request_param('tissues', ''))), static fn($x) => $x !== ''));
    $celllines = array_values(array_filter(array_map('trim', explode(',', (string)request_param('celllines', ''))), static fn($x) => $x !== ''));

    $params = [];
    $extra = build_filter_where([
        'species' => $species,
        'tissues' => $tissues,
        'celllines' => $celllines,
    ], $params);

    $countSql = "
      SELECT COUNT(DISTINCT d.dataset_id)
      FROM dataset d
      INNER JOIN meta_db.dataset_meta dm ON dm.dataset_id = d.dataset_id
      WHERE 1=1 $extra
    ";
    $stCount = $pdo->prepare($countSql);
    $stCount->execute($params);
    $total = (int)($stCount->fetchColumn() ?: 0);

    $lim = min(2000, max(50, (int)request_param('limit', 1000)));
    $listSql = "
      SELECT DISTINCT d.dataset_id
      FROM dataset d
      INNER JOIN meta_db.dataset_meta dm ON dm.dataset_id = d.dataset_id
      WHERE 1=1 $extra
      ORDER BY d.dataset_id ASC
      LIMIT :lim
    ";
    $st = $pdo->prepare($listSql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, PDO::PARAM_STR);
    }
    $st->bindValue(':lim', $lim, PDO::PARAM_INT);
    $st->execute();

    json_response([
        'ok' => true,
        'total' => $total,
        'dataset_ids' => $st->fetchAll(PDO::FETCH_COLUMN) ?: [],
    ]);
}

function ajax_filter_index(PDO $pdo): void
{
    $species = array_values(array_filter(array_map('trim', explode(',', (string)request_param('species', ''))), static fn($x) => $x !== ''));
    if (count($species) !== 1) {
        json_response(['ok' => false, 'message' => 'Please select exactly one species.'], 400);
    }
    $sp = (string)$species[0];
    $sql = "
      SELECT DISTINCT
        d.dataset_id AS dataset_id,
        COALESCE(dm.meta_biosample_tissue_name, '') AS tissue,
        COALESCE(dm.meta_biosample_description, '') AS cellline
      FROM dataset d
      INNER JOIN meta_db.dataset_meta dm ON dm.dataset_id = d.dataset_id
      WHERE LOWER(COALESCE(dm.meta_biosample_species,'')) = LOWER(:species)
    ";
    $st = $pdo->prepare($sql);
    $st->bindValue(':species', $sp, PDO::PARAM_STR);
    $st->execute();
    $rows = [];
    $tSet = [];
    $cSet = [];
    while ($r = $st->fetch()) {
        $d = trim((string)($r['dataset_id'] ?? ''));
        if ($d === '') continue;
        $t = trim((string)($r['tissue'] ?? ''));
        $c = trim((string)($r['cellline'] ?? ''));
        if ($t !== '') $tSet[$t] = true;
        if ($c !== '') $cSet[$c] = true;
        $rows[] = ['d' => $d, 't' => $t, 'c' => $c];
    }
    $tissues = array_keys($tSet); sort($tissues, SORT_STRING);
    $celllines = array_keys($cSet); sort($celllines, SORT_STRING);
    json_response(['ok' => true, 'species' => $sp, 'rows' => $rows, 'options' => ['tissue' => $tissues, 'cellline' => $celllines]]);
}

function ajax_perturbations(PDO $pdo): void
{
    $datasetId = trim((string)request_param('dataset_id', ''));
    $method = trim((string)request_param('method', ''));
    if ($datasetId === '') {
        json_response(['ok' => false, 'message' => 'Dataset ID required'], 400);
    }

    $whereMethod = '';
    $params = [':dataset_id' => $datasetId];
    if ($method !== '') {
        $whereMethod = ' AND m.method_name = :method ';
        $params[':method'] = $method;
    }

    $sql = "
      SELECT DISTINCT name FROM (
        SELECT p1.name AS name
        FROM correlation c
        JOIN dataset d ON d.dataset_pk = c.dataset_pk
        JOIN method m ON m.method_pk = d.method_pk
        JOIN perturbation p1 ON p1.perturb_pk = c.p1_pk
        WHERE d.dataset_id = :dataset_id $whereMethod
        UNION
        SELECT p2.name AS name
        FROM correlation c
        JOIN dataset d ON d.dataset_pk = c.dataset_pk
        JOIN method m ON m.method_pk = d.method_pk
        JOIN perturbation p2 ON p2.perturb_pk = c.p2_pk
        WHERE d.dataset_id = :dataset_id $whereMethod
      )
      ORDER BY name
    ";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, PDO::PARAM_STR);
    }
    $st->execute();
    $labels = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $rows = [];
    foreach ($labels as $lab) {
        $rows[] = [
            'label' => $lab,
            'class' => perturbation_class((string)$lab),
        ];
    }
    json_response(['ok' => true, 'dataset_id' => $datasetId, 'rows' => $rows, 'count' => count($rows)]);
}

function build_heatmap_payload(PDO $pdo, bool $includeValues = true): array
{
    $datasetId = trim((string)request_param('dataset_id', ''));
    $method = trim((string)request_param('method', 'ada'));
    $metric = normalize_metric_name((string)request_param('metric', 'cosine_similarity'));
    $selectedRaw = trim((string)request_param('perturbations', ''));
    $maxLabels = HEATMAP_MAX_LABELS;

    if ($datasetId === '') {
        throw new InvalidArgumentException('Dataset ID required');
    }

    $allowed = method_allowed_metrics($method);
    if (!in_array($metric, $allowed, true)) {
        $metric = $allowed[0];
    }
    $mcol = metric_column($metric);

    $scale = 10000.0;
    $mv = $pdo->query("SELECT value FROM meta WHERE key='metric_scale' LIMIT 1")->fetchColumn();
    if ($mv !== false && is_numeric($mv)) {
        $scale = max(1.0, (float)$mv);
    }

    $dsSql = "
      SELECT d.dataset_pk
      FROM dataset d
      JOIN method m ON m.method_pk = d.method_pk
      WHERE d.dataset_id = :dataset_id AND m.method_name = :method
      ORDER BY d.n_rows DESC
      LIMIT 1
    ";
    $dsSt = $pdo->prepare($dsSql);
    $dsSt->execute([':dataset_id' => $datasetId, ':method' => $method]);
    $datasetPk = $dsSt->fetchColumn();
    if ($datasetPk === false) {
        throw new RuntimeException("No data for Dataset ID=$datasetId, method=$method");
    }

    $selected = array_values(array_unique(array_filter(array_map('trim', explode(',', $selectedRaw)), static fn($x) => $x !== '')));
    if (count($selected) < 2) {
        throw new InvalidArgumentException('At least 2 perturbation labels are required to render a correlation heatmap.');
    }
    $inputCount = count($selected);
    if ($inputCount > $maxLabels) {
        throw new InvalidArgumentException("Too many selected perturbation labels ($inputCount). Please reduce your selection to $maxLabels or fewer labels.");
    }

    // Resolve perturbation PKs for selected labels.
    $ph = implode(',', array_fill(0, count($selected), '?'));
    $pkSql = "SELECT perturb_pk, name FROM perturbation WHERE name IN ($ph)";
    $pkSt = $pdo->prepare($pkSql);
    foreach ($selected as $i => $name) {
        $pkSt->bindValue($i + 1, $name, PDO::PARAM_STR);
    }
    $pkSt->execute();
    $nameToPk = [];
    while ($r = $pkSt->fetch()) {
        $nameToPk[(string)$r['name']] = (int)$r['perturb_pk'];
    }
    $labels = array_values(array_filter($selected, static fn($n) => isset($nameToPk[$n])));
    if (count($labels) < 2) {
        throw new InvalidArgumentException('Selected labels not found in this dataset/method.');
    }

    $idx = [];
    foreach ($labels as $i => $n) $idx[$n] = $i;
    $pkVals = array_map(static fn($n) => $nameToPk[$n], $labels);
    $pkIn = implode(',', array_fill(0, count($pkVals), '?'));

    $pairSql = "
      SELECT c.p1_pk, c.p2_pk, c.$mcol AS v_i
      FROM correlation c
      WHERE c.dataset_pk = ?
        AND c.$mcol IS NOT NULL
        AND c.p1_pk IN ($pkIn)
        AND c.p2_pk IN ($pkIn)
    ";
    $pairSt = $pdo->prepare($pairSql);
    $binds = array_merge([(int)$datasetPk], $pkVals, $pkVals);
    foreach ($binds as $i => $v) $pairSt->bindValue($i + 1, $v, PDO::PARAM_INT);
    $triples = [];
    if ($includeValues) {
        $pairSt->execute();
        $pairs = $pairSt->fetchAll();
        $pkToName = array_flip($nameToPk);
        $diag = metric_diag_value($metric);
        $n = count($labels);
        for ($i = 0; $i < $n; $i++) $triples[] = [$i, $i, $diag];
        foreach ($pairs as $r) {
            $n1 = $pkToName[(int)$r['p1_pk']] ?? null;
            $n2 = $pkToName[(int)$r['p2_pk']] ?? null;
            if ($n1 === null || $n2 === null) continue;
            if (!isset($idx[$n1]) || !isset($idx[$n2])) continue;
            $a = $idx[$n1];
            $b = $idx[$n2];
            $v = ((float)$r['v_i']) / $scale;
            $triples[] = [$a, $b, $v];
            $triples[] = [$b, $a, $v];
        }
    }

    return [
        'ok' => true,
        'dataset_id' => $datasetId,
        'method' => $method,
        'metric' => $metric,
        'labels' => $labels,
        'values' => $triples,
        'input_count' => $inputCount,
        'used_count' => count($labels),
        'trimmed' => false,
        'max_labels' => $maxLabels,
        'dataset_pk' => (int)$datasetPk,
        'metric_col' => $mcol,
        'scale' => $scale,
        'diag' => metric_diag_value($metric),
        'pks' => array_map(static fn($n) => (int)$nameToPk[$n], $labels),
    ];
}

function ajax_heatmap(PDO $pdo): void
{
    json_response(build_heatmap_payload($pdo));
}

function ensure_temp_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'temp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        throw new RuntimeException('Failed to create temp directory.');
    }
    cleanup_stale_temp_json_files();
    return $dir;
}

function matrix_from_payload(array $labels, array $triples): array
{
    $n = count($labels);
    $matrix = array_fill(0, $n, array_fill(0, $n, null));
    foreach ($triples as $t) {
        if (!is_array($t) || count($t) < 3) {
            continue;
        }
        $i = (int)$t[0];
        $j = (int)$t[1];
        $v = is_numeric($t[2]) ? (float)$t[2] : null;
        if ($i >= 0 && $i < $n && $j >= 0 && $j < $n) {
            $matrix[$i][$j] = $v;
        }
    }
    return $matrix;
}

function dataset_json_from_payload(array $payload): array
{
    $labels = array_values($payload['labels'] ?? []);
    $triples = array_values($payload['values'] ?? []);
    $metric = (string)($payload['metric'] ?? 'metric');

    $matrix = matrix_from_payload($labels, $triples);
    $rowId = [];
    $colId = [];
    foreach ($labels as $label) {
        $rowId[] = (string)$label;
        $colId[] = (string)$label;
    }

    return [
        'rows' => count($labels),
        'columns' => count($labels),
        'seriesArrays' => [$matrix],
        'seriesDataTypes' => ['Float32'],
        'seriesNames' => [$metric],
        'rowMetadataModel' => [
            'vectors' => [
                ['name' => 'id', 'array' => $rowId, 'properties' => []],
            ],
        ],
        'columnMetadataModel' => [
            'vectors' => [
                ['name' => 'id', 'array' => $colId, 'properties' => []],
            ],
        ],
    ];
}

function stream_write_or_throw($fh, string $text): void
{
    if (fwrite($fh, $text) === false) {
        throw new RuntimeException('Failed while writing heatmap JSON stream.');
    }
}

function get_correlation_chunk_map_sc(PDO $pdo, int $datasetPk, string $metricCol, float $scale, array $rowPkChunk, array $allColPks): array
{
    if (count($rowPkChunk) === 0 || count($allColPks) === 0) {
        return [];
    }
    $rowPh = implode(',', array_fill(0, count($rowPkChunk), '?'));
    $colPh = implode(',', array_fill(0, count($allColPks), '?'));
    $sql = "
      SELECT c.p1_pk AS row_pk, c.p2_pk AS col_pk, c.$metricCol AS v_i
      FROM correlation c
      WHERE c.dataset_pk = ?
        AND c.$metricCol IS NOT NULL
        AND c.p1_pk IN ($rowPh)
        AND c.p2_pk IN ($colPh)
      UNION ALL
      SELECT c.p2_pk AS row_pk, c.p1_pk AS col_pk, c.$metricCol AS v_i
      FROM correlation c
      WHERE c.dataset_pk = ?
        AND c.$metricCol IS NOT NULL
        AND c.p2_pk IN ($rowPh)
        AND c.p1_pk IN ($colPh)
    ";
    $st = $pdo->prepare($sql);
    $binds = array_merge(
        [(int)$datasetPk],
        array_map('intval', $rowPkChunk),
        array_map('intval', $allColPks),
        [(int)$datasetPk],
        array_map('intval', $rowPkChunk),
        array_map('intval', $allColPks)
    );
    foreach ($binds as $i => $v) {
        $st->bindValue($i + 1, $v, PDO::PARAM_INT);
    }
    $st->execute();
    $out = [];
    while ($r = $st->fetch()) {
        $rowPk = (int)($r['row_pk'] ?? 0);
        $colPk = (int)($r['col_pk'] ?? 0);
        $vRaw = $r['v_i'] ?? null;
        if ($rowPk <= 0 || $colPk <= 0 || $vRaw === null) continue;
        if (!isset($out[$rowPk])) $out[$rowPk] = [];
        $out[$rowPk][$colPk] = ((float)$vRaw) / $scale;
    }
    return $out;
}

function write_heatmap_dataset_json_stream_sc(PDO $pdo, string $path, array $payload, int $batchNum = HEATMAP_DEFAULT_BATCH_NUM): void
{
    $labels = array_values($payload['labels'] ?? []);
    $pks = array_values($payload['pks'] ?? []);
    $metric = (string)($payload['metric'] ?? 'metric');
    $datasetPk = (int)($payload['dataset_pk'] ?? 0);
    $metricCol = (string)($payload['metric_col'] ?? 'cosine_i');
    $scale = (float)($payload['scale'] ?? 10000.0);
    $diag = (float)($payload['diag'] ?? 0.0);
    $n = count($labels);
    if ($n < 2 || count($pks) !== $n || $datasetPk <= 0) {
        throw new RuntimeException('Invalid payload for streaming heatmap JSON.');
    }
    if ($scale <= 0) $scale = 1.0;
    $pkToIndex = [];
    foreach ($pks as $i => $pk) {
        $pkToIndex[(int)$pk] = $i;
    }
    $rowMeta = ['vectors' => [['name' => 'id', 'array' => array_map('strval', $labels), 'properties' => new stdClass()]]];
    $colMeta = ['vectors' => [['name' => 'id', 'array' => array_map('strval', $labels), 'properties' => new stdClass()]]];

    $fh = @fopen($path, 'wb');
    if (!is_resource($fh)) {
        throw new RuntimeException('Failed to open temp file for writing.');
    }
    try {
        stream_write_or_throw($fh, '{');
        stream_write_or_throw($fh, '"rows":' . $n . ',');
        stream_write_or_throw($fh, '"columns":' . $n . ',');
        stream_write_or_throw($fh, '"seriesArrays":[[');

        $chunkSize = max(10, (int)$batchNum);
        $firstRow = true;
        for ($start = 0; $start < $n; $start += $chunkSize) {
            $end = min($n, $start + $chunkSize);
            $rowChunkPks = array_slice($pks, $start, $end - $start);
            $chunkMap = get_correlation_chunk_map_sc($pdo, $datasetPk, $metricCol, $scale, $rowChunkPks, $pks);

            for ($r = $start; $r < $end; $r++) {
                $rowPk = (int)$pks[$r];
                $rowVals = array_fill(0, $n, null);
                $rowVals[$r] = $diag;
                if (isset($chunkMap[$rowPk])) {
                    foreach ($chunkMap[$rowPk] as $colPk => $val) {
                        $j = $pkToIndex[(int)$colPk] ?? null;
                        if ($j === null) continue;
                        $rowVals[$j] = $val;
                    }
                }
                $rowJson = json_encode($rowVals, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($rowJson === false) $rowJson = '[]';
                if (!$firstRow) {
                    stream_write_or_throw($fh, ',');
                } else {
                    $firstRow = false;
                }
                stream_write_or_throw($fh, $rowJson);
            }
            fflush($fh);
        }

        $seriesDataTypes = ['Float32'];
        $seriesNames = [$metric];
        stream_write_or_throw($fh, ']],');
        stream_write_or_throw($fh, '"seriesDataTypes":' . json_encode($seriesDataTypes, JSON_UNESCAPED_UNICODE) . ',');
        stream_write_or_throw($fh, '"seriesNames":' . json_encode($seriesNames, JSON_UNESCAPED_UNICODE) . ',');
        stream_write_or_throw($fh, '"rowMetadataModel":' . json_encode($rowMeta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . ',');
        stream_write_or_throw($fh, '"columnMetadataModel":' . json_encode($colMeta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        stream_write_or_throw($fh, '}');
        fflush($fh);
        fclose($fh);
        $fh = null;
    } catch (Throwable $e) {
        if (is_resource($fh)) fclose($fh);
        @unlink($path);
        throw $e;
    }
}

function ajax_heatmap_prepare(PDO $pdo): void
{
    ensure_runtime_limits_for_heatmap();
    $payload = build_heatmap_payload($pdo, false);
    if (!isset($payload['labels']) || !is_array($payload['labels']) || count($payload['labels']) < 2) {
        json_response(['ok' => false, 'message' => 'Not enough labels to build heatmap file.'], 400);
    }
    $tempDir = ensure_temp_dir();
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
    $path = $tempDir . DIRECTORY_SEPARATOR . $name;
    $batchNum = (int)request_param('batch_num', HEATMAP_DEFAULT_BATCH_NUM);
    $batchNum = min(1000, max(10, $batchNum));
    write_heatmap_dataset_json_stream_sc($pdo, $path, $payload, $batchNum);

    $openOptions = [
        'dataset' => 'temp/' . $name,
        'name' => 'Single-cell Correlation Heatmap',
    ];
    $iframeSrc = 'heatmap.php?json=' . rawurlencode(json_encode($openOptions, JSON_UNESCAPED_UNICODE));

    json_response([
        'ok' => true,
        'temp_file' => 'temp/' . $name,
        'iframe_src' => $iframeSrc,
        'n_labels' => count($payload['labels']),
        'trimmed' => (bool)($payload['trimmed'] ?? false),
        'input_count' => (int)($payload['input_count'] ?? count($payload['labels'])),
        'used_count' => (int)($payload['used_count'] ?? count($payload['labels'])),
        'max_labels' => (int)($payload['max_labels'] ?? 0),
    ]);
}

if ((string)request_param('ajax', '') !== '') {
    try {
        enforce_ajax_rate_limit();
        $pdo = get_sc_cor_pdo();
        $ajax = strtolower(trim((string)request_param('ajax', '')));
        if ($ajax === 'filter_options') {
            ajax_filter_options($pdo);
        } elseif ($ajax === 'datasets') {
            ajax_dataset_filter($pdo);
        } elseif ($ajax === 'filter_index') {
            ajax_filter_index($pdo);
        } elseif ($ajax === 'perturbations') {
            ajax_perturbations($pdo);
        } elseif ($ajax === 'heatmap_prepare') {
            ajax_heatmap_prepare($pdo);
        } elseif ($ajax === 'heatmap') {
            ajax_heatmap($pdo);
        } else {
            json_response(['ok' => false, 'message' => 'Unknown ajax endpoint'], 400);
        }
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 500);
    }
}

$dbError = null;
$methods = [];
try {
    $pdo = get_sc_cor_pdo();
    $methods = $pdo->query("SELECT method_name FROM method ORDER BY method_name ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}
$defaultMethod = in_array('exp_cor', $methods, true) ? 'exp_cor' : ((count($methods) > 0) ? (string)$methods[0] : 'ada');
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
    .species-inline .form-check-input { margin-top: 0.15rem; }
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
    .is-disabled-option { color: #94a3b8 !important; }
    .disabled-tag { color: #94a3b8; font-size: .72rem; }
    .dataset-list { max-height: 220px; overflow: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; background: #fff; }
    .pert-list { max-height: 300px; overflow: auto; border: 1px solid #dbe2ea; border-radius: 10px; padding: 10px; background: #f8fafc; }
    .cascade-four .filter-box { height: 100%; display: flex; flex-direction: column; }
    .cascade-four .filter-scroll {
      height: 220px;
      max-height: 220px;
    }
    /* Dataset/Perturbed Gene have no search input; compensate with taller list area. */
    .cascade-four .dataset-list,
    .cascade-four .pert-list {
      height: 270px;
      max-height: 270px;
    }
    .cascade-four .pert-list {
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 8px;
      background: #fff;
    }
    .heatmap-card { border: 1px solid #dbe2ea; border-radius: 12px; padding: 12px; background: #fff; }
    .heatmap-header-right { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
    .heatmap-box {
      width: 100%;
      min-height: 560px;
      display: flex;
      justify-content: center;
      align-items: flex-start;
      overflow: auto;
    }
    .heatmap-iframe {
      width: min(100%, 1320px);
      height: 760px;
      border: 0;
      border-radius: 8px;
      background: #fff;
      display: block;
      margin: 0 auto;
      flex: 0 0 auto;
    }
    .heatmap-open-link {
      width: 30px;
      height: 30px;
      padding: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      line-height: 1;
      white-space: nowrap;
    }
    .heatmap-open-link svg { width: 16px; height: 16px; }
    .heatmap-loading-wrap { min-height: 420px; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 10px; }
    .heatmap-loading-wheel {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      border: 10px solid rgba(15, 23, 42, 0.12);
      border-top-color: #0f172a;
      animation: pc-spin 0.8s linear infinite;
    }
    @keyframes pc-spin { to { transform: rotate(360deg); } }
    .muted-small { color: #64748b; font-size: .83rem; }
    .flow-guide { border: 1px solid #dbe2ea; border-radius: 14px; background: linear-gradient(180deg,#fff 0%,#f8fafc 100%); box-shadow: 0 8px 24px rgba(15,23,42,0.06); }
    .flow-step { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; padding: 12px; height: 100%; }
    .flow-badge { width: 28px; height: 28px; border-radius: 999px; background: #0f172a; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; }
    .flow-title { font-size:.95rem; font-weight:700; color:#0f172a; margin-bottom:4px; }
    .flow-text { font-size:.84rem; color:#475569; margin:0; }
    .page-offset-top { padding-top: 0; }
    .method-note-nowrap { white-space: nowrap; }
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
    .plot-btn-col { display: flex; justify-content: flex-end; }
    .plot-btn-col #plotBtn { margin-left: auto; }
    @media (min-width: 768px) {
      .plot-btn-col {
        align-items: flex-start;
        padding-top: 1.85rem;
      }
    }
  </style>
</head>
<body class="layout-body d-flex flex-column min-vh-100">
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
      <h1 class="h3 fw-bold mb-1">Correlation Explorer - Single Cell</h1>
      <div class="muted-small">This tool compares perturbation similarity across perturbed genes within the same single cell dataset.</div>
    </div>

    <section class="flow-guide p-3 mb-3">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <h2 class="h5 mb-0">How to Use</h2>
        
      </div>
      <div class="row g-2">
        <div class="col-12 col-md-3"><div class="flow-step"><div class="d-flex align-items-center gap-2 mb-1"><span class="flow-badge">1</span><div class="flow-title">Choose Filters</div></div><p class="flow-text">Select Species, Tissue, Cell Type.</p></div></div>
        <div class="col-12 col-md-3"><div class="flow-step"><div class="d-flex align-items-center gap-2 mb-1"><span class="flow-badge">2</span><div class="flow-title">Select Dataset and Perturbations</div></div><p class="flow-text">Choose exactly one Dataset ID from matched results. Select <strong>2 to 4000</strong> genes for comparison.</p></div></div>
        <div class="col-12 col-md-3"><div class="flow-step"><div class="d-flex align-items-center gap-2 mb-1"><span class="flow-badge">3</span><div class="flow-title">Set Visualization</div></div><p class="flow-text">Choose Correlation Data Source and Correlation Method based on your analysis goal.</p></div></div>
        <div class="col-12 col-md-3"><div class="flow-step"><div class="d-flex align-items-center gap-2 mb-1"><span class="flow-badge">4</span><div class="flow-title">Generate Heatmap</div></div><p class="flow-text">Click "Submit and render heatmaps" to visualize the Correlation Matrix.</p></div></div>
      </div>
    </section>

    <?php if ($dbError !== null): ?>
      <div class="alert alert-danger">Database error: <?php echo h($dbError); ?></div>
    <?php endif; ?>

    <section class="panel-card p-3 mb-3">
      <h2 class="h5 mb-3">Step1. Dataset and Perturbed Genes Selection</h2>
      <div class="mb-2 muted-small fw-semibold filter-logic-highlight">
        <strong>Select species first. Options within the same box are matched by OR, while different filter boxes are combined by AND. Gray options are unavailable.</strong>
      </div>
      <div class="mb-2">
        <span class="muted-small me-2">Matched perturbed genes:</span>
        <span class="fw-bold text-dark" id="pertCountText">0</span>
      </div>
      <div id="selectedFilterSummary" class="alert alert-light border small py-2 mb-2">Selected filters: none</div>
      <div class="row g-2 mb-2 cascade-four">
        <div class="col-12">
          <div class="filter-box h-100">
            <div class="d-flex flex-wrap align-items-center gap-3">
              <div class="subtle-label mb-0">Species (Required, Select One)</div>
              <div id="speciesOptions" class="d-flex flex-wrap align-items-center gap-3 species-inline">
                <div class="muted-small">Loading species...</div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-3">
          <div class="filter-box h-100">
            <div class="filter-head">
              <div class="subtle-label mb-0">Tissue</div>
              <div class="filter-tools">
                <span id="tissueCount" class="filter-count">0/0</span>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="tissue" data-action="all">Select All</button>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="tissue" data-action="clear">Unselect All</button>
              </div>
            </div>
            <input type="search" id="tissueSearch" class="form-control form-control-sm mb-2" placeholder="Search tissue">
            <div class="filter-scroll" id="tissueOptions"><div class="muted-small">Select one species first.</div></div>
          </div>
        </div>
        <div class="col-12 col-md-3">
          <div class="filter-box">
            <div class="filter-head">
              <div class="subtle-label mb-0">Cell Type</div>
              <div class="filter-tools">
                <span id="celllineCount" class="filter-count">0/0</span>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="cellline" data-action="all">Select All</button>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="cellline" data-action="clear">Unselect All</button>
              </div>
            </div>
            <input type="search" id="celllineSearch" class="form-control form-control-sm mb-2" placeholder="Search biosample description">
            <div class="filter-scroll" id="celllineOptions"><div class="muted-small">Select one species first.</div></div>
          </div>
        </div>
        <div class="col-12 col-md-3">
          <div class="filter-box h-100">
            <div class="filter-head">
              <div class="subtle-label mb-0">Dataset ID (Single selection only)</div>
              <div class="filter-tools">
                <span id="datasetCountText" class="filter-count">0/0</span>
              </div>
            </div>
            <div class="dataset-list" id="datasetList">
              <div class="muted-small">Select biosample description first.</div>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-3">
          <div class="filter-box h-100">
            <div class="filter-head">
              <div class="subtle-label mb-0">Perturbed Gene</div>
              <div class="filter-tools">
                <button type="button" id="pertCheckAllBtn" class="btn btn-outline-secondary btn-sm py-0 px-2">Select All</button>
                <button type="button" id="pertUncheckAllBtn" class="btn btn-outline-secondary btn-sm py-0 px-2">Unselect All</button>
              </div>
            </div>
            <div class="pert-list" id="pertList"><div class="muted-small text-danger">Please select one Dataset ID first.</div></div>
          </div>
        </div>
      </div>
    </section>

    <section class="panel-card p-3">
      <h2 class="h5 mb-3">Step 2. Heatmap Generation</h2>
      <div class="row g-2 mb-2">
        <div class="col-12 col-md-4">
          <label class="subtle-label mb-1" for="methodSel">Correlation data source
            <a class="method-help-link" href="faq.php#q5-5" target="_blank" rel="noopener noreferrer" title="Open FAQ Q5.5" aria-label="Open FAQ Q5.5">?</a>
          </label>
          <select id="methodSel" class="form-select form-select-sm">
            <?php foreach ($methods as $m): ?>
              <?php
                $methodLabel = $m;
                if ($m === 'ada') {
                  $methodLabel = 'text-embedding-ada-002 model';
                } elseif ($m === 'model3') {
                  $methodLabel = 'text-embedding-3-large model';
                } elseif ($m === 'exp_cor') {
                  $methodLabel = 'Raw Expression';
                }
              ?>
              <option value="<?php echo h($m); ?>" <?php echo $m === $defaultMethod ? 'selected' : ''; ?>><?php echo h($methodLabel); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="muted-small mt-1 method-note-nowrap">`text-embedding-ada-002 model` and `text-embedding-3-large model` are generated by GenePT embeddings.</div>
        </div>
        <div class="col-12 col-md-2">
          <label class="subtle-label mb-1 d-flex align-items-center" for="metricSel">
            <span>Similarity Metric</span>
            <a class="method-help-link" href="faq.php#q5-7" target="_blank" rel="noopener noreferrer" title="Open FAQ Q5.7" aria-label="Open FAQ Q5.7">?</a>
          </label>
          <select id="metricSel" class="form-select form-select-sm"></select>
          <div class="muted-small mt-1" id="metricHint"></div>
        </div>
        <div class="col-12 col-md-6 plot-btn-col">
          <button type="button" id="plotBtn" class="btn btn-primary btn-sm ms-auto">Submit and render heatmap</button>
        </div>
      </div>
      <div class="muted-small mb-2">Heatmap rendering requires 2 to <?php echo (int)HEATMAP_MAX_LABELS; ?> perturbed genes.</div>
      <div id="heatmapContainer" class="d-flex flex-column gap-3"></div>
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
  const OPTION_BATCH = 40;
  const MAX_SELECTED_PERTURBATIONS = <?php echo (int)HEATMAP_MAX_LABELS; ?>;
  const ajaxEndpoint = window.location.pathname.split('/').pop() || 'sc_correlation_tool.php';

  const els = {
    selectedFilterSummary: document.getElementById('selectedFilterSummary'),
    tissueSearch: document.getElementById('tissueSearch'),
    celllineSearch: document.getElementById('celllineSearch'),
    speciesOptions: document.getElementById('speciesOptions'),
    tissueOptions: document.getElementById('tissueOptions'),
    celllineOptions: document.getElementById('celllineOptions'),
    datasetList: document.getElementById('datasetList'),
    datasetCountText: document.getElementById('datasetCountText'),
    pertList: document.getElementById('pertList'),
    pertCountText: document.getElementById('pertCountText'),
    pertCheckAllBtn: document.getElementById('pertCheckAllBtn'),
    pertUncheckAllBtn: document.getElementById('pertUncheckAllBtn'),
    methodSel: document.getElementById('methodSel'),
    metricSel: document.getElementById('metricSel'),
    metricHint: document.getElementById('metricHint'),
    plotBtn: document.getElementById('plotBtn'),
    heatmapContainer: document.getElementById('heatmapContainer'),
  };

  const metricMap = {
    ada: ['cosine_similarity', 'pearson_distance', 'spearman_distance'],
    model3: ['cosine_similarity', 'pearson_distance', 'spearman_distance'],
    exp_cor: ['cosine_similarity', 'pearson_distance', 'spearman_distance', 'edistance', 'mmd'],
  };
  const methodLabelMap = {
    ada: 'text-embedding-ada-002 model',
    model3: 'text-embedding-3-large model',
    exp_cor: 'Raw Expression',
  };
  const metricLabelMap = {
    cosine_similarity: 'Cosine Similarity',
    pearson_distance: 'Pearson Distance',
    spearman_distance: 'Spearman Distance',
    edistance: 'E-distance',
    mmd: 'MMD',
  };

  const state = {
    selected: { species: new Set(), tissue: new Set(), cellline: new Set() },
    options: { species: [], tissue: [], cellline: [] },
    allOptions: { species: [], tissue: [], cellline: [] },
    render: { species: { list: [], rendered: 0 }, tissue: { list: [], rendered: 0 }, cellline: { list: [], rendered: 0 } },
    pert: { rows: [], selected: new Set(), totalMatched: 0, render: { list: [], rendered: 0 } },
    localIndexBySpecies: new Map(),
    localIndex: null,
  };
  let filterReqSeq = 0;
  let selectedDatasetId = '';

  function escHtml(v) {
    return String(v ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
  }
  function debounce(fn, wait = 260) { let t = null; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), wait); }; }
  async function fetchJson(url, init = null) {
    const res = await fetch(url, { cache: 'no-store', ...(init || {}) });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  }
  function selectedArray(group) { return Array.from(state.selected[group]); }
  function selectedSpecies() { return selectedArray('species')[0] || ''; }
  function currentFilters() {
    return {
      species: selectedArray('species'),
      tissues: selectedArray('tissue'),
      celllines: selectedArray('cellline'),
    };
  }
  function currentFiltersForQuery() {
    const f = currentFilters();
    const isFull = (group) => {
      const opts = state.options[group] || [];
      const sel = state.selected[group] || new Set();
      return opts.length > 0 && sel.size === opts.length;
    };
    if (isFull('tissue')) f.tissues = [];
    if (isFull('cellline')) f.celllines = [];
    return f;
  }
  async function ensureLocalIndex(reqSeq = null) {
    const sp = selectedSpecies();
    if (!sp) {
      state.localIndex = null;
      return false;
    }
    if (state.localIndexBySpecies.has(sp)) {
      state.localIndex = state.localIndexBySpecies.get(sp) || null;
      return !!state.localIndex;
    }
    const params = new URLSearchParams();
    params.set('ajax', 'filter_index');
    params.set('species', sp);
    let payload = null;
    try { payload = await fetchJson(`${ajaxEndpoint}?${params.toString()}`); } catch (_) { payload = null; }
    if (reqSeq !== null && reqSeq !== filterReqSeq) return false;
    if (!payload || !payload.ok || !Array.isArray(payload.rows)) {
      state.localIndex = null;
      return false;
    }
    const idx = { species: sp, rows: payload.rows, options: payload.options || { tissue: [], cellline: [] } };
    state.localIndexBySpecies.set(sp, idx);
    state.localIndex = idx;
    return true;
  }
  function uniqueSorted(vals) {
    const s = new Set();
    for (const v of vals) {
      const x = String(v || '').trim();
      if (x !== '') s.add(x);
    }
    return Array.from(s).sort((a, b) => a.localeCompare(b));
  }
  function localFilterRows(filters) {
    const idx = state.localIndex;
    if (!idx || !Array.isArray(idx.rows)) return [];
    const tSet = new Set((filters.tissues || []).map((v) => String(v)));
    const cSet = new Set((filters.celllines || []).map((v) => String(v)));
    const hasT = tSet.size > 0;
    const hasC = cSet.size > 0;
    const out = [];
    for (const r of idx.rows) {
      if (hasT && !tSet.has(String(r.t || ''))) continue;
      if (hasC && !cSet.has(String(r.c || ''))) continue;
      out.push(r);
    }
    return out;
  }
  function localExtractOptions(group, rows) {
    if (group === 'tissue') return uniqueSorted(rows.map((r) => r.t || ''));
    if (group === 'cellline') return uniqueSorted(rows.map((r) => r.c || ''));
    return [];
  }
  function getSearchValue(group) {
    return group === 'tissue' ? (els.tissueSearch.value || '').trim() : (els.celllineSearch.value || '').trim();
  }
  function getWrap(group) {
    return group === 'species' ? els.speciesOptions : (group === 'tissue' ? els.tissueOptions : els.celllineOptions);
  }
  function modeGroupByLevel(level) {
    if (level === 1) return 'tissue';
    return 'cellline';
  }
  function groupLevel(group) {
    if (group === 'tissue') return 1;
    if (group === 'cellline') return 2;
    return 0;
  }

  async function fetchGroupOptions(group, scope, reqSeq = null) {
    if (state.localIndex && group !== 'species') {
      const f = currentFiltersForQuery();
      const local = { tissues: [], celllines: [] };
      if (scope !== 'all' && group === 'cellline') {
        local.tissues = f.tissues;
      }
      const rows = localFilterRows(local);
      return localExtractOptions(group, rows);
    }
    const f = currentFiltersForQuery();
    const params = new URLSearchParams();
    params.set('ajax', 'filter_options');
    params.set('group', group);
    params.set('scope', scope);
    params.set('level', String(groupLevel(group)));
    params.set('q', group === 'species' ? '' : getSearchValue(group));
    params.set('limit', '5000');
    params.set('species', f.species.join(','));
    params.set('tissues', f.tissues.join(','));
    params.set('celllines', f.celllines.join(','));
    const payload = await fetchJson(`${ajaxEndpoint}?${params.toString()}`);
    const opts = payload && payload.ok && Array.isArray(payload.options) ? payload.options : [];
    if (reqSeq !== null && reqSeq !== filterReqSeq) return [];
    return opts;
  }

  function renderSpecies(reset = true) {
    const wrap = els.speciesOptions;
    const all = state.allOptions.species || [];
    const selected = selectedArray('species')[0] || '';
    if (!all.length) {
      wrap.innerHTML = '<div class="muted-small">No species options.</div>';
      return;
    }
    if (reset) {
      state.render.species.list = all.slice();
      state.render.species.rendered = 0;
      wrap.innerHTML = '';
    }
    const st = state.render.species;
    const start = st.rendered;
    const end = Math.min(start + OPTION_BATCH, st.list.length);
    const chunk = st.list.slice(start, end);
    st.rendered = end;
    const html = chunk.map((v, i) => {
      const id = `species_${start + i}_${String(v).replace(/[^A-Za-z0-9_]/g, '_')}`;
      return `<div class="form-check mb-0"><input class="form-check-input filter-checkbox filter-species" type="radio" name="species_single" value="${escHtml(v)}" id="${id}" ${selected === v ? 'checked' : ''}><label class="form-check-label small" for="${id}">${escHtml(v)}</label></div>`;
    }).join('');
    if (start === 0) wrap.innerHTML = html;
    else wrap.insertAdjacentHTML('beforeend', html);
  }

  function renderGroup(group, reset = true) {
    const wrap = getWrap(group);
    const all = state.allOptions[group] || [];
    const eligible = state.options[group] || [];
    const eligibleSet = new Set(eligible);
    const selected = state.selected[group];
    const q = getSearchValue(group).toLowerCase();
    const ordered = [...eligible.filter((v) => selected.has(v)), ...eligible.filter((v) => !selected.has(v)), ...all.filter((v) => !eligibleSet.has(v))];
    const list = q ? ordered.filter((v) => v.toLowerCase().includes(q)) : ordered;
    if (!list.length) { wrap.innerHTML = '<div class="muted-small">No options.</div>'; return; }
    if (reset) {
      state.render[group].list = list;
      state.render[group].rendered = 0;
      wrap.innerHTML = '';
    }
    const st = state.render[group];
    const start = st.rendered;
    const end = Math.min(start + OPTION_BATCH, st.list.length);
    const chunk = st.list.slice(start, end);
    st.rendered = end;
    const html = chunk.map((v, i) => {
      const id = `${group}_${start + i}_${String(v).replace(/[^A-Za-z0-9_]/g, '_')}`;
      const disabled = !eligibleSet.has(v);
      const cls = disabled ? ' is-disabled-option' : '';
      return `<div class="form-check mb-1 filter-item"><input class="form-check-input filter-checkbox filter-${group}" type="checkbox" value="${escHtml(v)}" id="${id}" ${selected.has(v) ? 'checked' : ''} ${disabled ? 'disabled' : ''}><label class="form-check-label small${cls}" for="${id}">${escHtml(v)}${disabled ? ' <span class="disabled-tag">(Unavailable)</span>' : ''}</label></div>`;
    }).join('');
    if (start === 0) wrap.innerHTML = html;
    else wrap.insertAdjacentHTML('beforeend', html);
    const old = wrap.querySelector('.opt-hint');
    if (old) old.remove();
    const remain = st.list.length - st.rendered;
    const hint = document.createElement('div');
    hint.className = 'muted-small opt-hint';
    hint.textContent = remain > 0 ? `Showing ${st.rendered}/${st.list.length}. Scroll to load more (${remain} left).` : `Showing all ${st.list.length}.`;
    wrap.appendChild(hint);
  }

  function shorten(arr, n = 5) { return arr.length <= n ? arr.join(', ') : `${arr.slice(0, n).join(', ')} ... (+${arr.length - n})`; }
  function updateSummary() {
    const f = currentFilters();
    const parts = [];
    if (f.species.length) parts.push(`Species: ${shorten(f.species)}`);
    if (f.tissues.length) parts.push(`Tissue: ${shorten(f.tissues)}`);
    if (f.celllines.length) parts.push(`Biosample Description: ${shorten(f.celllines)}`);
    if (selectedDatasetId) parts.push(`Dataset ID: ${selectedDatasetId}`);
    if (!f.species.length) {
      els.selectedFilterSummary.textContent = 'Selected filters: none | Species is required (single select).';
      return;
    }
    els.selectedFilterSummary.textContent = `Selected filters: ${parts.join(' | ')}`;
  }
  function updateFilterCounts() {
    const selectedN = (group) => state.selected[group].size;
    const setCount = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    setCount('tissueCount', `${selectedN('tissue')}/${(state.options.tissue || []).length}`);
    setCount('celllineCount', `${selectedN('cellline')}/${(state.options.cellline || []).length}`);
  }

  async function refreshSpecies(reqSeq = null) {
    const opts = await fetchGroupOptions('species', 'all', reqSeq);
    if (reqSeq !== null && reqSeq !== filterReqSeq) return;
    // Keep current selection/options on transient empty response to avoid full state reset.
    if ((!Array.isArray(opts) || opts.length === 0) && (state.selected.species || new Set()).size > 0 && (state.allOptions.species || []).length > 0) {
      renderSpecies(true);
      updateFilterCounts();
      return;
    }
    state.allOptions.species = Array.isArray(opts) ? opts : [];
    const selected = selectedArray('species')[0] || '';
    if (selected && !state.allOptions.species.includes(selected)) state.selected.species.clear();
    renderSpecies(true);
    updateFilterCounts();
  }

  async function refreshCascadeFromLevel(startLevel, reqSeq = null) {
    if (state.selected.species.size !== 1) {
      state.localIndex = null;
      ['tissue', 'cellline'].forEach((g) => {
        state.options[g] = [];
        state.allOptions[g] = [];
        state.selected[g] = new Set();
        getWrap(g).innerHTML = '<div class="muted-small">Select one species first.</div>';
      });
      renderDatasetRadios([], 0, 'Select one species first.');
      updateSummary();
      updateFilterCounts();
      return;
    }
    await ensureLocalIndex(reqSeq);
    if (reqSeq !== null && reqSeq !== filterReqSeq) return;
    for (let level = startLevel; level <= 2; level++) {
      const g = modeGroupByLevel(level);
      const allOpts = await fetchGroupOptions(g, 'all', reqSeq);
      let eligibleOpts = [];
      if (level === 1) {
        eligibleOpts = await fetchGroupOptions(g, 'eligible', reqSeq);
      } else {
        const hasParentSelected = (state.selected.tissue || new Set()).size > 0;
        eligibleOpts = hasParentSelected ? await fetchGroupOptions(g, 'eligible', reqSeq) : [];
      }
      if (reqSeq !== null && reqSeq !== filterReqSeq) return;
      state.allOptions[g] = allOpts;
      state.options[g] = eligibleOpts;
      const eligibleSet = new Set(eligibleOpts);
      const prev = state.selected[g] || new Set();
      state.selected[g] = new Set(Array.from(prev).filter((v) => eligibleSet.has(v)));
      renderGroup(g, true);
    }
    updateSummary();
    updateFilterCounts();
    await fetchDatasets(reqSeq);
  }

  async function refreshAllFiltersAndDatasets(changedGroup = null) {
    const reqSeq = ++filterReqSeq;
    // Species options are stable; avoid refetching on every downstream click.
    if (changedGroup === 'species' || !changedGroup) await refreshSpecies(reqSeq);
    if (changedGroup === 'species' || !changedGroup) {
      if (changedGroup === 'species') {
        state.selected.tissue.clear();
        state.selected.cellline.clear();
        selectedDatasetId = '';
        renderPerturbations([], true, true);
      }
      await refreshCascadeFromLevel(1, reqSeq);
      return;
    }
    const lv = groupLevel(changedGroup);
    if (lv === 1) {
      state.selected.cellline.clear();
      selectedDatasetId = '';
      renderPerturbations([], true, true);
      await refreshCascadeFromLevel(2, reqSeq);
    }
    else {
      if (changedGroup === 'cellline') {
        selectedDatasetId = '';
        renderPerturbations([], true, true);
      }
      updateSummary();
      updateFilterCounts();
      await fetchDatasets(reqSeq);
    }
  }

  function renderDatasetRadios(ids, total, emptyHint = 'No matched Dataset ID.') {
    els.datasetList.innerHTML = '';
    const hasSelected = selectedDatasetId !== '' && ids.includes(selectedDatasetId);
    els.datasetCountText.textContent = `${hasSelected ? 1 : 0}/${total}`;
    if (!ids.length) {
      els.datasetList.innerHTML = `<div class="muted-small">${escHtml(emptyHint)}</div>`;
      selectedDatasetId = '';
      renderPerturbations([], true, true);
      updateSummary();
      return;
    }
    els.datasetList.innerHTML = ids.map((id, i) => {
      const rid = `ds_${i}_${String(id).replace(/[^A-Za-z0-9_]/g, '_')}`;
      return `<div class="form-check mb-1">
        <input class="form-check-input ds-radio" type="radio" name="dataset_pick" value="${escHtml(id)}" id="${rid}" ${selectedDatasetId === id ? 'checked' : ''}>
        <label class="form-check-label" for="${rid}" style="font-size:.88rem;">${escHtml(id)}</label>
      </div>`;
    }).join('');
    if (!ids.includes(selectedDatasetId)) {
      selectedDatasetId = '';
      renderPerturbations([], true, true);
    } else {
      loadPerturbations();
    }
    const selectedNow = selectedDatasetId !== '' ? 1 : 0;
    els.datasetCountText.textContent = `${selectedNow}/${total}`;
    updateSummary();
    syncRenderGate();
  }

  async function fetchDatasets(reqSeq = null) {
    const f = currentFiltersForQuery();
    if (f.species.length !== 1) {
      renderDatasetRadios([], 0, 'Select one species first.');
      return;
    }
    if ((state.selected.tissue || new Set()).size === 0) {
      renderDatasetRadios([], 0, 'Select at least one tissue first.');
      return;
    }
    if ((state.selected.cellline || new Set()).size === 0) {
      renderDatasetRadios([], 0, 'Select at least one biosample description first.');
      return;
    }
    if (state.localIndex) {
      const rows = localFilterRows({ tissues: f.tissues, celllines: f.celllines });
      const idSet = new Set();
      for (const r of rows) {
        const d = String(r.d || '').trim();
        if (d !== '') idSet.add(d);
      }
      const ids = Array.from(idSet).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
      renderDatasetRadios(ids, ids.length);
      return;
    }
    const params = new URLSearchParams();
    params.set('ajax', 'datasets');
    params.set('species', f.species.join(','));
    params.set('tissues', f.tissues.join(','));
    params.set('celllines', f.celllines.join(','));
    params.set('limit', '1000');
    const payload = await fetchJson(`${ajaxEndpoint}?${params.toString()}`);
    if (reqSeq !== null && reqSeq !== filterReqSeq) return;
    if (!payload || !payload.ok) { renderDatasetRadios([], 0, 'Failed to load Dataset ID.'); return; }
    renderDatasetRadios(payload.dataset_ids || [], payload.total || 0);
  }

  function renderPerturbations(rows = null, reset = true, resetSelection = false) {
    if (reset) {
      if (Array.isArray(rows)) {
        state.pert.rows = rows.slice();
        state.pert.totalMatched = state.pert.rows.length;
        if (resetSelection) {
          state.pert.selected = new Set(state.pert.rows.map((r) => String(r.label || '')).filter((v) => v !== ''));
        }
      } else {
        state.pert.totalMatched = state.pert.rows.length;
      }
      state.pert.render.list = state.pert.rows.slice();
      state.pert.render.rendered = 0;
      els.pertList.innerHTML = '';
    }
    if (!state.pert.render.list.length) {
      els.pertList.innerHTML = '<div class="muted-small">No perturbation labels.</div>';
      updatePertCount(0);
      return;
    }
    const st = state.pert.render;
    const start = st.rendered;
    const end = Math.min(start + OPTION_BATCH, st.list.length);
    const chunk = st.list.slice(start, end);
    st.rendered = end;
    const html = chunk.map((r, i) => {
      const idx = start + i;
      const lab = String(r.label || '');
      const cls = r.class || 'NA';
      const id = `pert_${idx}_${String(lab).replace(/[^A-Za-z0-9_]/g, '_')}`;
      return `<div class="form-check mb-1">
        <input class="form-check-input pert-check" type="checkbox" value="${escHtml(lab)}" id="${id}" ${state.pert.selected.has(lab) ? 'checked' : ''}>
        <label class="form-check-label small" for="${id}">${escHtml(lab)} <span class="text-muted">(${escHtml(cls)})</span></label>
      </div>`;
    }).join('');
    if (start === 0) els.pertList.innerHTML = html;
    else els.pertList.insertAdjacentHTML('beforeend', html);
    const old = els.pertList.querySelector('.opt-hint');
    if (old) old.remove();
    const remain = st.list.length - st.rendered;
    const hint = document.createElement('div');
    hint.className = 'muted-small opt-hint';
    hint.textContent = remain > 0 ? `Showing ${st.rendered}/${st.list.length}. Scroll to load more (${remain} left).` : `Showing all ${st.list.length}.`;
    els.pertList.appendChild(hint);
    updatePertCount(state.pert.totalMatched);
  }
  function updatePertCount(totalOverride = null) {
    const matched = Number.isFinite(Number(totalOverride)) ? Number(totalOverride) : state.pert.totalMatched;
    els.pertCountText.textContent = String(matched);
    const outOfRange = matched < 2 || matched > MAX_SELECTED_PERTURBATIONS;
    els.pertCountText.classList.toggle('text-danger', outOfRange);
    els.pertCountText.classList.toggle('text-dark', !outOfRange);
    syncRenderGate();
  }

  function syncRenderGate() {
    const hasDataset = selectedDatasetId !== '';
    const selectedPertN = selectedPerturbations().length;
    const validPertN = selectedPertN >= 2 && selectedPertN <= MAX_SELECTED_PERTURBATIONS;
    els.plotBtn.disabled = !(hasDataset && validPertN);
  }
  async function loadPerturbations() {
    if (!selectedDatasetId) { renderPerturbations([], true, true); return; }
    const params = new URLSearchParams();
    params.set('ajax', 'perturbations');
    params.set('dataset_id', selectedDatasetId);
    params.set('method', els.methodSel.value || 'ada');
    const payload = await fetchJson(`${ajaxEndpoint}?${params.toString()}`);
    if (!payload || !payload.ok) { renderPerturbations([], true, true); return; }
    renderPerturbations(payload.rows || [], true, true);
  }
  function selectedPerturbations() { return Array.from(state.pert.selected); }
  function updateMetricOptions() {
    const m = els.methodSel.value || 'ada';
    const opts = metricMap[m] || ['cosine_similarity'];
    const old = els.metricSel.value;
    els.metricSel.innerHTML = '';
    opts.forEach((v) => {
      const op = document.createElement('option');
      op.value = v;
      op.textContent = metricLabelMap[v] || v;
      if (v === old) op.selected = true;
      els.metricSel.appendChild(op);
    });
    if (![...opts].includes(els.metricSel.value)) els.metricSel.value = opts[0];
    els.metricHint.textContent = '';
  }

  function calcHeatmapViewport(nLabels) {
    const n = Number.isFinite(Number(nLabels)) ? Number(nLabels) : 0;
    const widthPx = Math.max(980, Math.min(1600, 420 + (n * 78)));
    const heightPx = Math.max(760, Math.min(1240, 360 + (n * 60)));
    return { widthPx, heightPx };
  }

  async function renderHeatmap() {
    const startedAt = performance.now();
    const elapsedText = () => `${((performance.now() - startedAt) / 1000).toFixed(2)}s`;
    els.heatmapContainer.innerHTML = '';
    if (!selectedDatasetId) {
      els.heatmapContainer.innerHTML = '<div class="alert alert-danger mb-0">Please select one Dataset ID first.</div>';
      return;
    }
    const perts = selectedPerturbations();
    if (perts.length < 2) {
      els.heatmapContainer.innerHTML = '<div class="alert alert-warning mb-0">At least 2 perturbed genes are required to render a correlation heatmap.</div>';
      return;
    }
    if (perts.length > MAX_SELECTED_PERTURBATIONS) {
      els.heatmapContainer.innerHTML = `<div class="alert alert-warning mb-0">Too many selected perturbed genes (${perts.length}). Please reduce your selection to ${MAX_SELECTED_PERTURBATIONS} or fewer genes.</div>`;
      return;
    }

    const method = els.methodSel.value || 'ada';
    const metric = els.metricSel.value || 'cosine_similarity';
    const methodLabel = methodLabelMap[method] || method;
    const metricLabel = metricLabelMap[metric] || metric;

    const card = document.createElement('div');
    card.className = 'heatmap-card';
    card.innerHTML = `
      <div class="d-flex justify-content-between align-items-start mb-1 gap-2">
        <div class="fw-semibold">${selectedDatasetId} | ${methodLabel} | ${metricLabel}</div>
        <div class="heatmap-header-right">
          <a id="heatmapOpenFullscreen" class="btn btn-sm btn-outline-dark heatmap-open-link d-none" href="#" target="_blank" rel="noopener noreferrer" aria-label="Open fullscreen" title="Open fullscreen">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polyline points="9 3 3 3 3 9"></polyline>
              <line x1="3" y1="3" x2="10" y2="10"></line>
              <polyline points="15 3 21 3 21 9"></polyline>
              <line x1="21" y1="3" x2="14" y2="10"></line>
              <polyline points="3 15 3 21 9 21"></polyline>
              <line x1="3" y1="21" x2="10" y2="14"></line>
              <polyline points="21 15 21 21 15 21"></polyline>
              <line x1="21" y1="21" x2="14" y2="14"></line>
            </svg>
          </a>
          <div class="muted-small text-end" id="hmHint">loading...</div>
        </div>
      </div>
      <div id="div_heatmap_status_message" class="muted-small mb-2"></div>
      <div class="heatmap-box heatmap-loading-wrap">
        <div class="heatmap-loading-wheel"></div>
        <div class="muted-small">Generating heatmap data, please wait...</div>
      </div>
    `;
    els.heatmapContainer.appendChild(card);
    const hint = card.querySelector('#hmHint');
    const tick = setInterval(() => {
      if (hint) hint.textContent = `preparing... ${elapsedText()}`;
    }, 200);

    try {
      const params = new URLSearchParams();
      params.set('ajax', 'heatmap_prepare');
      params.set('dataset_id', selectedDatasetId);
      params.set('method', method);
      params.set('metric', metric);
      params.set('perturbations', perts.join(','));
      const payload = await fetchJson(ajaxEndpoint, { method: 'POST', body: params });
      if (!payload || !payload.ok) {
        clearInterval(tick);
        hint.textContent = payload && payload.message ? payload.message : `load failed (${elapsedText()})`;
        return;
      }
      if (!payload.iframe_src) {
        clearInterval(tick);
        hint.textContent = `invalid heatmap source (${elapsedText()})`;
        return;
      }

      const openFullscreen = card.querySelector('#heatmapOpenFullscreen');
      if (openFullscreen) {
        openFullscreen.href = payload.iframe_src;
        openFullscreen.classList.remove('d-none');
      }

      let t = `${payload.n_labels || 0} labels`;
      if (payload.trimmed) t += ` (trimmed: ${payload.used_count}/${payload.input_count})`;
      hint.textContent = `${t} | opening workspace... ${elapsedText()}`;

      const box = card.querySelector('.heatmap-box');
      const iframe = document.createElement('iframe');
      iframe.className = 'heatmap-iframe';
      iframe.loading = 'lazy';
      iframe.src = payload.iframe_src;
      const vp = calcHeatmapViewport(payload.n_labels || 0);
      iframe.style.width = `${vp.widthPx}px`;
      iframe.style.maxWidth = '100%';
      iframe.style.height = `${vp.heightPx}px`;
      box.style.minHeight = `${Math.max(560, vp.heightPx + 16)}px`;
      box.classList.remove('heatmap-loading-wrap');
      iframe.addEventListener('load', () => {
        clearInterval(tick);
        hint.textContent = `${t} | done in ${elapsedText()}`;
      }, { once: true });
      iframe.addEventListener('error', () => {
        clearInterval(tick);
        hint.textContent = `failed to open heatmap workspace (${elapsedText()})`;
      }, { once: true });
      box.innerHTML = '';
      box.appendChild(iframe);
    } catch (e) {
      clearInterval(tick);
      hint.textContent = e && e.message ? `load failed: ${e.message}` : `load failed (${elapsedText()})`;
    }
  }

  function bindFilterEvents() {
    els.speciesOptions.addEventListener('change', (e) => {
      const t = e.target;
      if (!(t instanceof HTMLInputElement)) return;
      if (!t.classList.contains('filter-species')) return;
      state.selected.species = t.checked ? new Set([t.value]) : new Set();
      renderSpecies(true);
      refreshAllFiltersAndDatasets('species').catch(() => {});
    });
    els.speciesOptions.addEventListener('scroll', () => {
      const wrap = els.speciesOptions;
      const nearBottom = wrap.scrollTop + wrap.clientHeight >= wrap.scrollHeight - 18;
      if (nearBottom) renderSpecies(false);
    });

    function bindGroup(group, wrap) {
      wrap.addEventListener('change', (e) => {
        const t = e.target;
        if (!(t instanceof HTMLInputElement)) return;
        if (!t.classList.contains(`filter-${group}`)) return;
        if (t.checked) state.selected[group].add(t.value);
        else state.selected[group].delete(t.value);
        // Keep selected options pinned to top immediately (same UX as bulk correlation tool).
        renderGroup(group, true);
        refreshAllFiltersAndDatasets(group).catch(() => {});
      });
      wrap.addEventListener('scroll', () => {
        const nearBottom = wrap.scrollTop + wrap.clientHeight >= wrap.scrollHeight - 18;
        if (nearBottom) renderGroup(group, false);
      });
    }
    bindGroup('tissue', els.tissueOptions);
    bindGroup('cellline', els.celllineOptions);
    document.querySelectorAll('button[data-group][data-action]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const group = btn.getAttribute('data-group');
        const action = btn.getAttribute('data-action');
        if (!group || !state.selected[group]) return;
        if (action === 'all') state.selected[group] = new Set(state.options[group] || []);
        if (action === 'clear') state.selected[group].clear();
        renderGroup(group, true);
        updateSummary();
        updateFilterCounts();
        refreshAllFiltersAndDatasets(group).catch(() => {});
      });
    });

    els.datasetList.addEventListener('change', (e) => {
      if (e.target && e.target.classList.contains('ds-radio')) {
        selectedDatasetId = e.target.value;
        const total = els.datasetList.querySelectorAll('.ds-radio').length;
        els.datasetCountText.textContent = `${selectedDatasetId ? 1 : 0}/${total}`;
        updateSummary();
        loadPerturbations();
        syncRenderGate();
      }
    });

    els.pertList.addEventListener('change', (e) => {
      if (e.target && e.target.classList.contains('pert-check')) {
        const v = e.target.value || '';
        if (e.target.checked) state.pert.selected.add(v);
        else state.pert.selected.delete(v);
        updatePertCount();
      }
    });
    els.pertList.addEventListener('scroll', () => {
      const nearBottom = els.pertList.scrollTop + els.pertList.clientHeight >= els.pertList.scrollHeight - 18;
      if (nearBottom) renderPerturbations(null, false);
    });
  }

  async function boot() {
    updateMetricOptions();

    bindFilterEvents();
    await refreshAllFiltersAndDatasets();

    const debSearch = debounce(() => refreshAllFiltersAndDatasets().catch(() => {}), 260);
    els.tissueSearch.addEventListener('input', debSearch);
    els.celllineSearch.addEventListener('input', debSearch);

    els.pertCheckAllBtn.addEventListener('click', () => {
      state.pert.selected = new Set(state.pert.rows.map((r) => String(r.label || '')).filter((v) => v !== ''));
      renderPerturbations(null, true, false);
      updatePertCount();
    });
    els.pertUncheckAllBtn.addEventListener('click', () => {
      state.pert.selected.clear();
      renderPerturbations(null, true, false);
      updatePertCount();
    });

    els.methodSel.addEventListener('change', () => {
      updateMetricOptions();
      loadPerturbations();
    });
    els.plotBtn.addEventListener('click', renderHeatmap);
    syncRenderGate();
  }

  boot();
</script>
<script>document.getElementById('year').textContent = new Date().getFullYear();</script>
</body>
</html>





