<?php
require_once __DIR__ . '/config.php';

const DB_BULK_COR_FILE = __DIR__ . '/sqlite3/bulk_cor.db';
const HEATMAP_MAX_LABELS = 4000;
const HEATMAP_DEFAULT_BATCH_NUM = 500;

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = json_encode(['ok' => false, 'message' => 'JSON encoding failed.'], JSON_UNESCAPED_UNICODE);
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
    $key = hash('sha256', 'bulk_cor_ajax|' . $ip);
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
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'temp';
    if (!is_dir($dir)) {
        return;
    }
    $now = time();
    foreach ((glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: []) as $file) {
        $mtime = @filemtime($file);
        if ($mtime === false) continue;
        if (($now - (int)$mtime) > $expireSeconds) {
            @unlink($file);
        }
    }
}

function ini_bytes(string $val): int
{
    $v = trim($val);
    if ($v === '' || $v === '-1') {
        return -1;
    }
    $num = (float)$v;
    $unit = strtolower(substr($v, -1));
    $mult = match ($unit) {
        'g' => 1024 * 1024 * 1024,
        'm' => 1024 * 1024,
        'k' => 1024,
        default => 1,
    };
    return (int)round($num * $mult);
}

function ensure_runtime_limits_for_heatmap(): void
{
    @set_time_limit(180);
    $cur = ini_bytes((string)ini_get('memory_limit'));
    $target = 1024 * 1024 * 1024; // 1G
    if ($cur !== -1 && $cur < $target) {
        @ini_set('memory_limit', '1G');
    }
}

function request_param(string $key, mixed $default = ''): mixed
{
    if (array_key_exists($key, $_POST)) {
        return $_POST[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    return $default;
}

function contains_substr(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) !== false;
}

function split_gene_tokens(string $raw): array
{
    $parts = preg_split('/[,\|;]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $g = trim((string)$p);
        if ($g !== '') {
            $out[$g] = true;
        }
    }
    return array_keys($out);
}

function normalize_metric_name(string $metric): string
{
    $m = strtolower(trim($metric));
    return match ($m) {
        'pearson', 'pearson_distance' => 'pearson_distance',
        'spearman', 'spearman_distance' => 'spearman_distance',
        default => 'cosine_similarity',
    };
}

function metric_column(string $metric): string
{
    return match (normalize_metric_name($metric)) {
        'pearson_distance' => 'pearson_i',
        'spearman_distance' => 'spearman_i',
        default => 'cosine_i',
    };
}

function metric_diag_value(string $metric): float
{
    return normalize_metric_name($metric) === 'cosine_similarity' ? 1.0 : 0.0;
}

function get_bulk_cor_pdo(): PDO
{
    if (!file_exists(DB_BULK_COR_FILE)) {
        throw new RuntimeException('bulk_cor.db not found.');
    }
    if (!file_exists(DB_META_FILE)) {
        throw new RuntimeException('dataset_meta.db not found.');
    }
    $pdo = new PDO('sqlite:' . DB_BULK_COR_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Read-heavy endpoint tuning.
    $pdo->exec("PRAGMA cache_size = -80000");
    $pdo->exec("PRAGMA temp_store = MEMORY");
    $pdo->exec("PRAGMA mmap_size = 268435456");
    $pdo->exec("ATTACH DATABASE " . $pdo->quote(DB_META_FILE) . " AS meta_db");
    ensure_meta_indexes($pdo);
    return $pdo;
}

function meta_dataset_from_clause(): string
{
    return "FROM meta_db.dataset_meta dm INNER JOIN perturbation p ON p.name = dm.dataset_id";
}

function ensure_meta_indexes(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS meta_db.idx_dm_dataset_id ON dataset_meta(dataset_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS meta_db.idx_dm_species ON dataset_meta(meta_biosample_species)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS meta_db.idx_dm_tissue ON dataset_meta(meta_biosample_tissue_name)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS meta_db.idx_dm_desc ON dataset_meta(meta_biosample_description)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS meta_db.idx_dm_target_gene_nocase ON dataset_meta(meta_assay_target_gene_name COLLATE NOCASE)");
    } catch (Throwable $e) {
        // Best-effort acceleration only; ignore when metadata DB is read-only.
    }
}

function get_metric_scale(PDO $pdo): float
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $mv = $pdo->query("SELECT value FROM meta WHERE key='metric_scale' LIMIT 1")->fetchColumn();
    $cached = ($mv !== false && is_numeric($mv)) ? max(1.0, (float)$mv) : 1000.0;
    return $cached;
}

function build_filter_where(array $filters, array &$params): string
{
    $where = [];

    $speciesList = $filters['species'] ?? [];
    if (!is_array($speciesList)) {
        $speciesList = [];
    }
    $speciesList = array_values(array_unique(array_filter(array_map('trim', $speciesList), static fn($x) => $x !== '')));
    if (count($speciesList) > 0) {
        $in = [];
        foreach ($speciesList as $i => $v) {
            $k = ':species_' . $i;
            $in[] = $k;
            $params[$k] = $v;
        }
        $where[] = "dm.meta_biosample_species IS NOT NULL";
        $where[] = "dm.meta_biosample_species COLLATE NOCASE IN (" . implode(',', $in) . ")";
    }

    $tissues = $filters['tissues'] ?? [];
    if (!is_array($tissues)) {
        $tissues = [];
    }
    $tissues = array_values(array_unique(array_filter(array_map('trim', $tissues), static fn($x) => $x !== '')));
    if (count($tissues) > 0) {
        $in = [];
        foreach ($tissues as $i => $v) {
            $k = ':tissue_' . $i;
            $in[] = $k;
            $params[$k] = $v;
        }
        $where[] = "dm.meta_biosample_tissue_name IS NOT NULL";
        $where[] = "dm.meta_biosample_tissue_name COLLATE NOCASE IN (" . implode(',', $in) . ")";
    }

    $celllines = $filters['celllines'] ?? [];
    if (!is_array($celllines)) {
        $celllines = [];
    }
    $celllines = array_values(array_unique(array_filter(array_map('trim', $celllines), static fn($x) => $x !== '')));
    if (count($celllines) > 0) {
        $in = [];
        foreach ($celllines as $i => $v) {
            $k = ':cellline_' . $i;
            $in[] = $k;
            $params[$k] = $v;
        }
        $where[] = "dm.meta_biosample_description IS NOT NULL";
        $where[] = "dm.meta_biosample_description COLLATE NOCASE IN (" . implode(',', $in) . ")";
    }

    $genes = $filters['genes'] ?? [];
    if (!is_array($genes)) {
        $genes = [];
    }
    $genes = array_values(array_unique(array_filter(array_map(static fn($x) => trim((string)$x), $genes), static fn($x) => $x !== '')));
    if (count($genes) > 0) {
        $or = [];
        foreach ($genes as $i => $g) {
            $k = ':gene_like_' . $i;
            $or[] = "dm.meta_assay_target_gene_name IS NOT NULL AND INSTR((',' || REPLACE(REPLACE(COALESCE(dm.meta_assay_target_gene_name,''), '|', ','), ' ', '') || ','), $k) > 0";
            $params[$k] = ',' . str_replace(' ', '', $g) . ',';
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }

    return count($where) > 0 ? (' AND ' . implode(' AND ', $where)) : '';
}

function parse_csv_param(string $key): array
{
    $raw = (string)request_param($key, '');
    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn($x) => $x !== ''));
}

function ajax_dataset_filter(PDO $pdo): void
{
    $species = parse_csv_param('species');
    $tissues = parse_csv_param('tissues');
    $celllines = parse_csv_param('celllines');
    $genes = parse_csv_param('genes');

    if (count($species) !== 1) {
        json_response([
            'ok' => false,
            'message' => 'Please select exactly one species before querying Dataset ID.',
            'total' => 0,
            'dataset_ids' => [],
        ], 400);
    }

    // Module 1 does not bind method; it filters metadata and intersects with perturbation names.
    $params = [];
    $extra = build_filter_where(
        [
            'species' => $species,
            'tissues' => $tissues,
            'celllines' => $celllines,
            'genes' => $genes,
        ],
        $params
    );

    $baseFrom = meta_dataset_from_clause();
    $countSql = "
        SELECT COUNT(DISTINCT dm.dataset_id) AS n
        $baseFrom
        WHERE 1=1
          $extra
    ";
    $stCount = $pdo->prepare($countSql);
    $stCount->execute($params);
    $total = (int)($stCount->fetchColumn() ?: 0);

    // Do not auto-pick top IDs when out of supported range.
    if ($total > HEATMAP_MAX_LABELS || $total < 2) {
        json_response([
            'ok' => true,
            'total' => $total,
            'returned' => 0,
            'dataset_ids' => [],
        ]);
    }

    $lim = min(HEATMAP_MAX_LABELS, max(100, (int)request_param('limit', HEATMAP_MAX_LABELS)));
    $listSql = "
        SELECT DISTINCT dm.dataset_id
        $baseFrom
        WHERE 1=1
          $extra
        ORDER BY dm.dataset_id ASC
        LIMIT :lim
    ";
    $st = $pdo->prepare($listSql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, PDO::PARAM_STR);
    }
    $st->bindValue(':lim', $lim, PDO::PARAM_INT);
    $st->execute();
    $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

    json_response([
        'ok' => true,
        'total' => $total,
        'returned' => count($ids),
        'dataset_ids' => $ids,
    ]);
}

function ajax_filter_options(PDO $pdo): void
{
    $group = strtolower(trim((string)request_param('group', '')));
    $mode = strtolower(trim((string)request_param('mode', 'biosample_first')));
    $scope = strtolower(trim((string)request_param('scope', 'eligible')));
    $level = max(1, min(3, (int)request_param('level', 1)));
    $q = trim((string)request_param('q', ''));
    $limit = min(5000, max(40, (int)request_param('limit', 2000)));

    $species = parse_csv_param('species');
    $tissues = parse_csv_param('tissues');
    $celllines = parse_csv_param('celllines');
    $genes = parse_csv_param('genes');

    $whereParams = [];
    $filterForOptions = ['species' => [], 'tissues' => [], 'celllines' => [], 'genes' => []];
    if ($scope === 'all') {
        if ($group !== 'species') {
            $filterForOptions['species'] = $species;
        }
    } else {
        if ($group !== 'species') {
            $filterForOptions['species'] = $species;
        }
        if ($mode === 'perturbed_gene_first') {
            // L1: gene; L2: tissue; L3: cellline
            if ($group === 'tissue') {
                $filterForOptions['genes'] = $genes;
            } elseif ($group === 'cellline') {
                $filterForOptions['genes'] = $genes;
                $filterForOptions['tissues'] = $tissues;
            }
        } else {
            // biosample_first
            // L1: tissue; L2: cellline; L3: gene
            if ($group === 'cellline') {
                $filterForOptions['tissues'] = $tissues;
            } elseif ($group === 'gene') {
                $filterForOptions['tissues'] = $tissues;
                $filterForOptions['celllines'] = $celllines;
            }
        }
    }
    $extra = build_filter_where($filterForOptions, $whereParams);

    if (in_array($group, ['species', 'tissue', 'cellline'], true)) {
        $col = match ($group) {
            'species' => 'dm.meta_biosample_species',
            'tissue' => 'dm.meta_biosample_tissue_name',
            default => 'dm.meta_biosample_description',
        };
        $baseFrom = meta_dataset_from_clause();
        $sql = "
          SELECT DISTINCT $col AS v
          $baseFrom
          WHERE 1=1
            $extra
            AND $col IS NOT NULL
            AND TRIM($col) <> ''
            AND $col LIKE :q COLLATE NOCASE
          ORDER BY v
          LIMIT :lim
        ";
        $st = $pdo->prepare($sql);
        foreach ($whereParams as $k => $v) {
            $st->bindValue($k, $v, PDO::PARAM_STR);
        }
        $st->bindValue(':q', '%' . $q . '%', PDO::PARAM_STR);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        json_response([
            'ok' => true,
            'group' => $group,
            'options' => $st->fetchAll(PDO::FETCH_COLUMN) ?: [],
            'mode' => $mode,
            'scope' => $scope,
            'level' => $level,
        ]);
    }

    if ($group === 'gene') {
        $opts = [];
        $seen = [];
        $qNeedle = trim($q);

        $hasTargetGenesTable = (int)$pdo->query("SELECT COUNT(*) FROM meta_db.sqlite_master WHERE type='table' AND name='target_genes'")->fetchColumn() > 0;
        $hasTargetGenesDatasetId = false;
        if ($hasTargetGenesTable) {
            $ti = $pdo->query("PRAGMA meta_db.table_info(target_genes)")->fetchAll() ?: [];
            foreach ($ti as $col) {
                $cn = strtolower(trim((string)($col['name'] ?? $col[1] ?? '')));
                if ($cn === 'dataset_id') {
                    $hasTargetGenesDatasetId = true;
                    break;
                }
            }
        }

        if ($hasTargetGenesTable && $hasTargetGenesDatasetId) {
            $sql = "
              SELECT DISTINCT TRIM(tg.gene_name) AS g
              FROM meta_db.target_genes tg
              INNER JOIN meta_db.dataset_meta dm ON dm.dataset_id = tg.dataset_id
              INNER JOIN perturbation p ON p.name = dm.dataset_id
              WHERE 1=1
                $extra
                AND tg.gene_name IS NOT NULL
                AND TRIM(tg.gene_name) <> ''
                AND (:q = '' OR INSTR(tg.gene_name, :q) > 0)
              ORDER BY g
              LIMIT :lim
            ";
            $st = $pdo->prepare($sql);
            foreach ($whereParams as $k => $v) {
                $st->bindValue($k, $v, PDO::PARAM_STR);
            }
            $st->bindValue(':q', $qNeedle, PDO::PARAM_STR);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            $opts = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } else {
            $baseFrom = meta_dataset_from_clause();
            $sql = "
              SELECT dm.meta_assay_target_gene_name AS raw_gene
              $baseFrom
              WHERE 1=1
                $extra
                AND dm.meta_assay_target_gene_name IS NOT NULL
                AND TRIM(dm.meta_assay_target_gene_name) <> ''
            ";
            $st = $pdo->prepare($sql);
            foreach ($whereParams as $k => $v) {
                $st->bindValue($k, $v, PDO::PARAM_STR);
            }
            $st->execute();
            while ($row = $st->fetch()) {
                foreach (split_gene_tokens((string)($row['raw_gene'] ?? '')) as $g) {
                    if ($qNeedle !== '' && strpos($g, $qNeedle) === false) continue;
                    if (isset($seen[$g])) continue;
                    $seen[$g] = true;
                    $opts[] = $g;
                    if (count($opts) >= $limit) break 2;
                }
            }
            sort($opts, SORT_STRING);
        }
        json_response([
            'ok' => true,
            'group' => 'gene',
            'options' => array_values(array_slice($opts, 0, $limit)),
            'mode' => $mode,
            'scope' => $scope,
            'level' => $level,
        ]);
    }

    json_response(['ok' => false, 'message' => 'Unknown filter group'], 400);
}

function ajax_filter_index(PDO $pdo): void
{
    $species = parse_csv_param('species');
    if (count($species) !== 1) {
        json_response(['ok' => false, 'message' => 'Please select exactly one species.'], 400);
    }
    $sp = (string)$species[0];

    $sql = "
      SELECT
        dm.dataset_id AS dataset_id,
        COALESCE(dm.meta_biosample_tissue_name, '') AS tissue,
        COALESCE(dm.meta_biosample_description, '') AS cellline,
        COALESCE(dm.meta_assay_target_gene_name, '') AS raw_gene
      FROM meta_db.dataset_meta dm
      INNER JOIN perturbation p ON p.name = dm.dataset_id
      WHERE dm.meta_biosample_species IS NOT NULL
        AND dm.meta_biosample_species COLLATE NOCASE = :species
    ";
    $st = $pdo->prepare($sql);
    $st->bindValue(':species', $sp, PDO::PARAM_STR);
    $st->execute();

    $rows = [];
    $tissueSet = [];
    $cellSet = [];
    $geneSet = [];
    $datasetSet = [];

    while ($r = $st->fetch()) {
        $datasetId = trim((string)($r['dataset_id'] ?? ''));
        if ($datasetId === '') {
            continue;
        }
        $tissue = trim((string)($r['tissue'] ?? ''));
        $cellline = trim((string)($r['cellline'] ?? ''));
        $rawGene = (string)($r['raw_gene'] ?? '');
        $genes = split_gene_tokens($rawGene);

        if ($tissue !== '') $tissueSet[$tissue] = true;
        if ($cellline !== '') $cellSet[$cellline] = true;
        foreach ($genes as $g) $geneSet[$g] = true;
        $datasetSet[$datasetId] = true;

        $rows[] = [
            'd' => $datasetId,
            't' => $tissue,
            'c' => $cellline,
            'g' => $genes,
        ];
    }

    $tissues = array_keys($tissueSet);
    $celllines = array_keys($cellSet);
    $genes = array_keys($geneSet);
    sort($tissues, SORT_STRING);
    sort($celllines, SORT_STRING);
    sort($genes, SORT_STRING);

    json_response([
        'ok' => true,
        'species' => $sp,
        'rows' => $rows,
        'options' => [
            'tissue' => $tissues,
            'cellline' => $celllines,
            'gene' => $genes,
        ],
        'dataset_count' => count($datasetSet),
        'row_count' => count($rows),
    ]);
}

function build_heatmap_selected_payload(PDO $pdo, bool $includeValues = true): array
{
    $datasetRaw = trim((string)request_param('dataset_ids', ''));
    $method = trim((string)request_param('method', 'exp_cor'));
    $metric = normalize_metric_name((string)request_param('metric', 'cosine_similarity'));
    $speciesSel = strtolower(trim((string)request_param('species', '')));

    if ($speciesSel !== '' && (str_contains($speciesSel, 'mouse') || str_contains($speciesSel, 'mus')) && strtolower($method) !== 'exp_cor') {
        json_response(['ok' => false, 'message' => 'Mouse only supports expression_correlation in this page.'], 400);
    }

    $datasetIds = array_values(array_unique(array_filter(array_map('trim', explode(',', $datasetRaw)), static fn($x) => $x !== '')));
    if (count($datasetIds) < 2) {
        json_response(['ok' => false, 'message' => 'Please select at least 2 Dataset ID.'], 400);
    }
    $inputCount = count($datasetIds);
    $renderMax = HEATMAP_MAX_LABELS;
    if ($inputCount > $renderMax) {
        json_response([
            'ok' => false,
            'message' => "Too many selected Dataset ID ($inputCount). Please reduce your selection to the limit ($renderMax).",
            'input_count' => $inputCount,
            'max_ids' => $renderMax,
        ], 400);
    }

    $mcol = metric_column($metric);
    $scale = get_metric_scale($pdo);

    $nameParams = [];
    $nameIn = [];
    foreach ($datasetIds as $i => $id) {
        $k = ':s' . $i;
        $nameIn[] = $k;
        $nameParams[$k] = $id;
    }

    // Map selected dataset_id strings to perturbation PKs once.
    $selPkMap = [];
    $selPkSql = "SELECT perturb_pk, name FROM perturbation WHERE name IN (" . implode(',', $nameIn) . ")";
    $selPkSt = $pdo->prepare($selPkSql);
    foreach ($nameParams as $k => $v) {
        $selPkSt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $selPkSt->execute();
    while ($r = $selPkSt->fetch()) {
        $selPkMap[(string)$r['name']] = (int)$r['perturb_pk'];
    }
    $selectedPerturbPks = [];
    foreach ($datasetIds as $id) {
        if (isset($selPkMap[$id])) {
            $selectedPerturbPks[] = (int)$selPkMap[$id];
        }
    }
    $selectedPerturbPks = array_values(array_unique($selectedPerturbPks));
    if (count($selectedPerturbPks) < 2) {
        json_response(['ok' => false, 'message' => 'Selected Dataset ID are not present in perturbation dictionary.']);
    }

    // Method can contain multiple shards (e.g. Human/Mouse). Choose the shard
    // with maximal overlap with selected dataset_ids instead of fixed max n_rows.
    $pkSql = "
      SELECT d.dataset_pk
      FROM dataset d
      INNER JOIN method m ON m.method_pk = d.method_pk
      WHERE m.method_name = :method
      ORDER BY d.n_rows DESC
    ";
    $pkSt = $pdo->prepare($pkSql);
    $pkSt->execute([':method' => $method]);
    $candidatePks = array_map('intval', $pkSt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (!$candidatePks) {
        json_response(['ok' => false, 'message' => "No dataset found for method=$method"]);
    }

    $datasetPk = null;
    $labels = [];
    $pks = [];

    // Fast path: use dataset_perturbation helper table (new schema).
    $hasDpTable = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='dataset_perturbation'")->fetchColumn() > 0;
    if ($hasDpTable) {
        $candPh = implode(',', array_fill(0, count($candidatePks), '?'));
        $selPh = implode(',', array_fill(0, count($selectedPerturbPks), '?'));
        $scoreSql = "
          SELECT dp.dataset_pk, COUNT(*) AS n
          FROM dataset_perturbation dp
          WHERE dp.dataset_pk IN ($candPh)
            AND dp.perturb_pk IN ($selPh)
          GROUP BY dp.dataset_pk
          ORDER BY n DESC, dp.dataset_pk ASC
          LIMIT 1
        ";
        $scoreSt = $pdo->prepare($scoreSql);
        $binds = array_merge($candidatePks, $selectedPerturbPks);
        foreach ($binds as $i => $v) {
            $scoreSt->bindValue($i + 1, (int)$v, PDO::PARAM_INT);
        }
        $scoreSt->execute();
        $best = $scoreSt->fetch();
        if ($best) {
            $datasetPk = (int)$best['dataset_pk'];
        }
        if ($datasetPk === null) {
            json_response(['ok' => false, 'message' => "No dataset shard found for method=$method"]);
        }

        $labelSql = "
          SELECT p.name, dp.perturb_pk
          FROM dataset_perturbation dp
          INNER JOIN perturbation p ON p.perturb_pk = dp.perturb_pk
          WHERE dp.dataset_pk = ?
            AND dp.perturb_pk IN ($selPh)
          ORDER BY p.name ASC
        ";
        $labelSt = $pdo->prepare($labelSql);
        $labelSt->bindValue(1, (int)$datasetPk, PDO::PARAM_INT);
        foreach ($selectedPerturbPks as $i => $pk) {
            $labelSt->bindValue($i + 2, (int)$pk, PDO::PARAM_INT);
        }
        $labelSt->execute();
        while ($r = $labelSt->fetch()) {
            $nm = (string)$r['name'];
            $pk = (int)$r['perturb_pk'];
            $labels[] = $nm;
            $pks[$nm] = $pk;
        }
    } else {
        // Backward-compatible fallback for old schema.
        $scoreSql = "
          SELECT COUNT(DISTINCT name) AS n
          FROM (
            SELECT p1.name AS name
            FROM correlation c
            INNER JOIN perturbation p1 ON p1.perturb_pk = c.p1_pk
            WHERE c.dataset_pk = :dataset_pk
              AND p1.name IN (" . implode(',', $nameIn) . ")
            UNION
            SELECT p2.name AS name
            FROM correlation c
            INNER JOIN perturbation p2 ON p2.perturb_pk = c.p2_pk
            WHERE c.dataset_pk = :dataset_pk
              AND p2.name IN (" . implode(',', $nameIn) . ")
          )
        ";
        $scoreSt = $pdo->prepare($scoreSql);
        $bestCnt = -1;
        foreach ($candidatePks as $pk) {
            $scoreSt->bindValue(':dataset_pk', $pk, PDO::PARAM_INT);
            foreach ($nameParams as $k => $v) {
                $scoreSt->bindValue($k, $v, PDO::PARAM_STR);
            }
            $scoreSt->execute();
            $cnt = (int)($scoreSt->fetchColumn() ?: 0);
            if ($cnt > $bestCnt) {
                $bestCnt = $cnt;
                $datasetPk = $pk;
            }
        }
        if ($datasetPk === null) {
            json_response(['ok' => false, 'message' => "No dataset shard found for method=$method"]);
        }
        $selSql = "
          SELECT DISTINCT name
          FROM (
            SELECT p1.name AS name
            FROM correlation c
            INNER JOIN perturbation p1 ON p1.perturb_pk = c.p1_pk
            WHERE c.dataset_pk = :dataset_pk
              AND p1.name IN (" . implode(',', $nameIn) . ")
            UNION
            SELECT p2.name AS name
            FROM correlation c
            INNER JOIN perturbation p2 ON p2.perturb_pk = c.p2_pk
            WHERE c.dataset_pk = :dataset_pk
              AND p2.name IN (" . implode(',', $nameIn) . ")
          )
          ORDER BY name
        ";
        $selSt = $pdo->prepare($selSql);
        $selSt->bindValue(':dataset_pk', (int)$datasetPk, PDO::PARAM_INT);
        foreach ($nameParams as $k => $v) {
            $selSt->bindValue($k, $v, PDO::PARAM_STR);
        }
        $selSt->execute();
        $labels = $selSt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (count($labels) > 0) {
            $pkSql = "SELECT perturb_pk, name FROM perturbation WHERE name IN (" . implode(',', array_fill(0, count($labels), '?')) . ")";
            $pkSt = $pdo->prepare($pkSql);
            foreach ($labels as $i => $name) {
                $pkSt->bindValue($i + 1, $name, PDO::PARAM_STR);
            }
            $pkSt->execute();
            while ($r = $pkSt->fetch()) {
                $pks[(string)$r['name']] = (int)$r['perturb_pk'];
            }
        }
    }

    if (count($labels) < 2) {
        json_response([
            'ok' => false,
            'message' => "Selected Dataset ID overlap less than 2 under method=$method",
            'debug_dataset_pk' => (int)$datasetPk,
            'debug_overlap_count' => (int)count($labels),
        ]);
    }
    if (count($labels) > $renderMax) {
        json_response([
            'ok' => false,
            'message' => "Overlapped labels (" . count($labels) . ") exceeds the limit ($renderMax).",
            'input_count' => $inputCount,
            'used_count' => count($datasetIds),
            'overlap_count' => count($labels),
            'max_ids' => $renderMax,
        ], 400);
    }

    $triples = [];
    if ($includeValues) {
        $idx = [];
        foreach ($labels as $i => $name) {
            $idx[(string)$name] = $i;
        }
        $pkVals = array_values($pks);
        if (count($pkVals) < 2) {
            json_response(['ok' => false, 'message' => 'No perturbation PK mapping found.']);
        }
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
        foreach ($binds as $i => $v) {
            $pairSt->bindValue($i + 1, $v, PDO::PARAM_INT);
        }
        $pairSt->execute();

        $diag = metric_diag_value($metric);
        $n = count($labels);
        for ($i = 0; $i < $n; $i++) {
            $triples[] = [$i, $i, $diag];
        }
        $pkToName = array_flip($pks);
        while ($r = $pairSt->fetch()) {
            $n1 = $pkToName[(int)$r['p1_pk']] ?? null;
            $n2 = $pkToName[(int)$r['p2_pk']] ?? null;
            if ($n1 === null || $n2 === null) {
                continue;
            }
            $a = $idx[(string)$n1];
            $b = $idx[(string)$n2];
            $v = ((float)$r['v_i']) / $scale;
            $triples[] = [$a, $b, $v];
            $triples[] = [$b, $a, $v];
        }
    }

    // Metadata lookup for tooltip display.
    $metaMap = [];
    if (count($labels) > 0) {
        $ph = implode(',', array_fill(0, count($labels), '?'));
        $metaSql = "
          SELECT
            dm.dataset_id,
            COALESCE(TRIM(dm.meta_assay_target_gene_name), '') AS target_gene,
            COALESCE(TRIM(dm.meta_biosample_species), '') AS species,
            COALESCE(TRIM(dm.meta_biosample_tissue_name), '') AS tissue
          FROM meta_db.dataset_meta dm
          WHERE dm.dataset_id IN ($ph)
        ";
        $metaSt = $pdo->prepare($metaSql);
        foreach ($labels as $i => $datasetId) {
            $metaSt->bindValue($i + 1, $datasetId, PDO::PARAM_STR);
        }
        $metaSt->execute();

        $metaAgg = [];
        while ($row = $metaSt->fetch()) {
            $datasetId = (string)$row['dataset_id'];
            if (!isset($metaAgg[$datasetId])) {
                $metaAgg[$datasetId] = [
                    'target_gene' => [],
                    'species' => [],
                    'tissue' => [],
                ];
            }
            $tg = trim((string)($row['target_gene'] ?? ''));
            $sp = trim((string)($row['species'] ?? ''));
            $ts = trim((string)($row['tissue'] ?? ''));
            if ($tg !== '') {
                $metaAgg[$datasetId]['target_gene'][$tg] = true;
            }
            if ($sp !== '') {
                $metaAgg[$datasetId]['species'][$sp] = true;
            }
            if ($ts !== '') {
                $metaAgg[$datasetId]['tissue'][$ts] = true;
            }
        }

        foreach ($labels as $datasetId) {
            if (!isset($metaAgg[$datasetId])) {
                $metaMap[$datasetId] = [
                    'target_gene' => 'NA',
                    'species' => 'NA',
                    'tissue' => 'NA',
                ];
                continue;
            }
            $tgVals = array_keys($metaAgg[$datasetId]['target_gene']);
            $spVals = array_keys($metaAgg[$datasetId]['species']);
            $tsVals = array_keys($metaAgg[$datasetId]['tissue']);
            sort($tgVals, SORT_STRING);
            sort($spVals, SORT_STRING);
            sort($tsVals, SORT_STRING);
            $metaMap[$datasetId] = [
                'target_gene' => count($tgVals) > 0 ? implode(' | ', $tgVals) : 'NA',
                'species' => count($spVals) > 0 ? implode(' | ', $spVals) : 'NA',
                'tissue' => count($tsVals) > 0 ? implode(' | ', $tsVals) : 'NA',
            ];
        }
    }

    return [
        'ok' => true,
        'dataset_id' => implode(',', $labels),
        'method' => $method,
        'metric' => $metric,
        'labels' => $labels,
        'values' => $triples,
        'pks' => $pks,
        'meta_map' => $metaMap,
        'dataset_pk' => (int)$datasetPk,
        'input_count' => $inputCount,
        'used_count' => count($datasetIds),
        'trimmed' => false,
        'max_ids' => $renderMax,
        'scale' => $scale,
        'metric_column' => $mcol,
    ];
}

function ajax_heatmap_selected(PDO $pdo): void
{
    $payload = build_heatmap_selected_payload($pdo, true);
    json_response($payload);
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

function stream_write_or_throw($fh, string $text): void
{
    if (fwrite($fh, $text) === false) {
        throw new RuntimeException('Failed while writing heatmap JSON stream.');
    }
}

function get_correlation_chunk_map(PDO $pdo, int $datasetPk, string $metricCol, float $scale, array $rowPkChunk, array $allColPks): array
{
    if (count($rowPkChunk) === 0 || count($allColPks) === 0) {
        return [];
    }
    $rowPh = implode(',', array_fill(0, count($rowPkChunk), '?'));
    $colPh = implode(',', array_fill(0, count($allColPks), '?'));

    // test3-style chunk query: rows chunk x all columns.
    // We keep bidirectional compatibility using UNION ALL in case correlation stores one triangle.
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
    $binds = array_merge([(int)$datasetPk], $rowPkChunk, $allColPks, [(int)$datasetPk], $rowPkChunk, $allColPks);
    foreach ($binds as $i => $v) {
        $st->bindValue($i + 1, (int)$v, PDO::PARAM_INT);
    }
    $st->execute();

    $map = [];
    while ($r = $st->fetch()) {
        $rp = (int)$r['row_pk'];
        $cp = (int)$r['col_pk'];
        $map[$rp][$cp] = ((float)$r['v_i']) / $scale;
    }
    return $map;
}

function write_heatmap_dataset_json_stream(PDO $pdo, string $path, array $payload, int $batchNum = HEATMAP_DEFAULT_BATCH_NUM): void
{
    $labels = array_values($payload['labels'] ?? []);
    $metaMap = is_array($payload['meta_map'] ?? null) ? $payload['meta_map'] : [];
    $metric = (string)($payload['metric'] ?? 'metric');
    $mcol = metric_column($metric);
    $scale = is_numeric($payload['scale'] ?? null) ? (float)$payload['scale'] : get_metric_scale($pdo);
    $datasetPk = (int)($payload['dataset_pk'] ?? 0);
    $pksByName = is_array($payload['pks'] ?? null) ? $payload['pks'] : [];
    $n = count($labels);
    if ($datasetPk <= 0 || $n < 2) {
        throw new RuntimeException('Invalid payload for streaming heatmap JSON.');
    }

    $allPks = [];
    foreach ($labels as $name) {
        if (!isset($pksByName[$name])) {
            throw new RuntimeException('Missing perturbation PK mapping for selected labels.');
        }
        $allPks[] = (int)$pksByName[$name];
    }
    $rowId = [];
    $rowGene = [];
    $rowSpecies = [];
    $rowTissue = [];
    $colId = [];
    $colGene = [];
    $colSpecies = [];
    $colTissue = [];

    foreach ($labels as $label) {
        $m = is_array($metaMap[$label] ?? null) ? $metaMap[$label] : [];
        $gene = trim((string)($m['target_gene'] ?? 'NA'));
        $species = trim((string)($m['species'] ?? 'NA'));
        $tissue = trim((string)($m['tissue'] ?? 'NA'));
        $rowId[] = (string)$label;
        $rowGene[] = $gene !== '' ? $gene : 'NA';
        $rowSpecies[] = $species !== '' ? $species : 'NA';
        $rowTissue[] = $tissue !== '' ? $tissue : 'NA';
        $colId[] = (string)$label;
        $colGene[] = $gene !== '' ? $gene : 'NA';
        $colSpecies[] = $species !== '' ? $species : 'NA';
        $colTissue[] = $tissue !== '' ? $tissue : 'NA';
    }

    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new RuntimeException('Failed to open temp heatmap file for writing.');
    }

    $batchNum = max(1, $batchNum);
    $gcEveryNChunks = 12;
    $chunkCounter = 0;
    $diagVal = metric_diag_value($metric);

    try {
        stream_write_or_throw($fh, '{');
        stream_write_or_throw($fh, '"rows":' . $n . ',');
        stream_write_or_throw($fh, '"columns":' . $n . ',');
        stream_write_or_throw($fh, '"seriesArrays":[[');

        $globalRowIndex = 0;
        for ($chunkStart = 0; $chunkStart < $n; $chunkStart += $batchNum) {
            $chunkPks = array_slice($allPks, $chunkStart, $batchNum);
            $chunkMap = get_correlation_chunk_map($pdo, $datasetPk, $mcol, $scale, $chunkPks, $allPks);

            foreach ($chunkPks as $rowPk) {
                $row = [];
                foreach ($allPks as $colPk) {
                    if ($rowPk === $colPk) {
                        $row[] = $diagVal;
                    } elseif (isset($chunkMap[$rowPk][$colPk])) {
                        $row[] = $chunkMap[$rowPk][$colPk];
                    } else {
                        $row[] = 0.0;
                    }
                }
                if ($globalRowIndex > 0) {
                    stream_write_or_throw($fh, ',');
                }
                $rowJson = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
                if ($rowJson === false) {
                    throw new RuntimeException('Failed to encode heatmap matrix row.');
                }
                stream_write_or_throw($fh, $rowJson);
                unset($row);
                $globalRowIndex++;
            }
            unset($chunkMap, $chunkPks);
            $chunkCounter++;
            if ($chunkCounter % $gcEveryNChunks === 0) {
                gc_collect_cycles();
            }
        }

        $rowMeta = [
            'vectors' => [
                ['name' => 'id', 'array' => $rowId, 'properties' => []],
                ['name' => 'target_gene', 'array' => $rowGene, 'properties' => []],
                ['name' => 'species', 'array' => $rowSpecies, 'properties' => []],
                ['name' => 'tissue', 'array' => $rowTissue, 'properties' => []],
            ],
        ];
        $colMeta = [
            'vectors' => [
                ['name' => 'id', 'array' => $colId, 'properties' => []],
                ['name' => 'target_gene', 'array' => $colGene, 'properties' => []],
                ['name' => 'species', 'array' => $colSpecies, 'properties' => []],
                ['name' => 'tissue', 'array' => $colTissue, 'properties' => []],
            ],
        ];
        $seriesNames = [$metric];
        $seriesDataTypes = ['Float32'];

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
        if (is_resource($fh)) {
            fclose($fh);
        }
        @unlink($path);
        throw $e;
    }
}

function ajax_heatmap_selected_prepare(PDO $pdo): void
{
    ensure_runtime_limits_for_heatmap();
    $payload = build_heatmap_selected_payload($pdo, false);
    if (!isset($payload['labels']) || !is_array($payload['labels']) || count($payload['labels']) < 2) {
        json_response(['ok' => false, 'message' => 'Not enough labels to build heatmap file.'], 400);
    }

    $tempDir = ensure_temp_dir();
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
    $path = $tempDir . DIRECTORY_SEPARATOR . $name;
    $batchNum = (int)request_param('batch_num', HEATMAP_DEFAULT_BATCH_NUM);
    $batchNum = min(1000, max(10, $batchNum));
    write_heatmap_dataset_json_stream($pdo, $path, $payload, $batchNum);

    $openOptions = [
        'dataset' => 'temp/' . $name,
        'name' => 'Bulk Correlation Heatmap',
    ];
    $iframeSrc = 'heatmap.php?json=' . rawurlencode(json_encode($openOptions, JSON_UNESCAPED_UNICODE));

    $resp = [
        'ok' => true,
        'temp_file' => 'temp/' . $name,
        'iframe_src' => $iframeSrc,
        'n_labels' => count($payload['labels']),
        'trimmed' => false,
        'input_count' => (int)($payload['input_count'] ?? count($payload['labels'])),
        'used_count' => (int)($payload['used_count'] ?? count($payload['labels'])),
        'max_ids' => (int)($payload['max_ids'] ?? HEATMAP_MAX_LABELS),
    ];

    json_response($resp);
}

$ajaxReq = request_param('ajax', null);
if ($ajaxReq !== null) {
    try {
        enforce_ajax_rate_limit();
        $pdo = get_bulk_cor_pdo();
        $ajax = strtolower(trim((string)$ajaxReq));
        if ($ajax === 'datasets') {
            ajax_dataset_filter($pdo);
        } elseif ($ajax === 'filter_options') {
            ajax_filter_options($pdo);
        } elseif ($ajax === 'filter_index') {
            ajax_filter_index($pdo);
        } elseif ($ajax === 'heatmap_selected_prepare') {
            ajax_heatmap_selected_prepare($pdo);
        } elseif ($ajax === 'heatmap_selected') {
            ajax_heatmap_selected($pdo);
        } else {
            json_response(['ok' => false, 'message' => 'Unknown ajax endpoint'], 400);
        }
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 500);
    }
}

$dbError = null;
$methods = [];
$speciesOptions = [];
$tissues = [];
$celllines = [];
$genes = [];

try {
    $pdo = get_bulk_cor_pdo();

    $methods = $pdo->query("SELECT method_name FROM method ORDER BY method_name ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $speciesOptions = $pdo->query("
      SELECT dm.meta_biosample_species
      FROM meta_db.dataset_meta dm
      WHERE dm.meta_biosample_species IS NOT NULL
        AND TRIM(dm.meta_biosample_species) <> ''
      GROUP BY dm.meta_biosample_species
      ORDER BY dm.meta_biosample_species ASC
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $tissues = $pdo->query("
      SELECT dm.meta_biosample_tissue_name
      FROM meta_db.dataset_meta dm
      WHERE dm.meta_biosample_tissue_name IS NOT NULL
        AND TRIM(dm.meta_biosample_tissue_name) <> ''
      GROUP BY dm.meta_biosample_tissue_name
      ORDER BY dm.meta_biosample_tissue_name ASC
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

$celllines = $pdo->query("
SELECT dm.meta_biosample_description
FROM meta_db.dataset_meta dm
WHERE dm.meta_biosample_description IS NOT NULL
AND TRIM(dm.meta_biosample_description) <> ''
GROUP BY dm.meta_biosample_description
ORDER BY dm.meta_biosample_description ASC
")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    // Do not preload genes to avoid heavy initial HTML rendering.
    $genes = [];
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$defaultMethod = 'exp_cor';
if (!in_array($defaultMethod, $methods, true) && count($methods) > 0) {
    $defaultMethod = (string)$methods[0];
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
    }
    .method-help-link:hover {
      color: #0f172a;
      border-color: #64748b;
      text-decoration: none;
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
    .is-disabled-option { color: #94a3b8 !important; }
    .disabled-tag { color: #94a3b8; font-size: .72rem; }
    .dataset-list { max-height: 360px; overflow: auto; border: 1px solid #dbe2ea; border-radius: 10px; padding: 10px; background: #f8fafc; }
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
    .flow-guide { border: 1px solid #dbe2ea; border-radius: 14px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); box-shadow: 0 8px 24px rgba(15,23,42,0.06); }
    .flow-step { border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff; padding: 12px; height: 100%; }
    .flow-badge { width: 28px; height: 28px; border-radius: 999px; background: #0f172a; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: .8rem; font-weight: 700; }
    .flow-title { font-size: .95rem; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
    .flow-text { font-size: .84rem; color: #475569; margin: 0; }
    .page-offset-top { padding-top: 0; }
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
        <h1 class="h3 fw-bold mb-1">Correlation Explorer - Bulk</h1>
        <div class="muted-small">This tool compares perturbation similarity across user-selected bulk datasets</div>
      </div>

      <section class="flow-guide p-3 mb-3">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <h2 class="h5 mb-0">How to Use</h2>
          
        </div>
        <div class="row g-2">
          <div class="col-12 col-md-3">
            <div class="flow-step">
              <div class="d-flex align-items-center gap-2 mb-1">
                <span class="flow-badge">1</span>
                <div class="flow-title">Choose Filters</div>
              </div>
              <p class="flow-text">Select Species, Tissue, Cell Type, and Perturbed Gene(s).</p>
            </div>
          </div>
          <div class="col-12 col-md-3">
            <div class="flow-step">
              <div class="d-flex align-items-center gap-2 mb-1">
                <span class="flow-badge">2</span>
                <div class="flow-title">Review Dataset ID</div>
              </div>
              <p class="flow-text">Heatmap rendering requires <strong>2 to <?php echo (int)HEATMAP_MAX_LABELS; ?></strong> matched Dataset ID.</p>
            </div>
          </div>
          <div class="col-12 col-md-3">
            <div class="flow-step">
              <div class="d-flex align-items-center gap-2 mb-1">
                <span class="flow-badge">3</span>
                <div class="flow-title">Set Visualization</div>
              </div>
              <p class="flow-text">Choose Correlation Data Source and Correlation Method based on your analysis goal.</p>
            </div>
          </div>
          <div class="col-12 col-md-3">
            <div class="flow-step">
              <div class="d-flex align-items-center gap-2 mb-1">
                <span class="flow-badge">4</span>
                <div class="flow-title">Generate Heatmap</div>
              </div>
              <p class="flow-text">Click "Submit and render heatmaps" to visualize the Correlation Matrix.</p>
            </div>
          </div>
        </div>
      </section>

      <?php if ($dbError !== null): ?>
        <div class="alert alert-danger">Database error: <?php echo h($dbError); ?></div>
      <?php endif; ?>

      <section class="panel-card p-3 mb-3">
        <h2 class="h5 mb-3">Step 1. Dataset Selection</h2>
        <div class="mb-2 muted-small fw-semibold filter-logic-highlight">
          <strong>Select species first. Options within the same box are matched by OR, while different filter boxes are combined by AND. Gray options are unavailable.</strong>
        </div>
        <div class="mb-2">
          <span class="muted-small me-2">Matched Dataset ID:</span>
          <span class="fw-bold text-dark" id="datasetCountText">0</span>
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
                    <label class="form-check-label small" for="modeBiosampleFirst">Biosample First</label>
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
                <div class="subtle-label mb-0">Species (Required, Select One)</div>
                <div id="speciesOptions" class="d-flex flex-wrap align-items-center gap-3 species-inline">
                  <?php foreach ($speciesOptions as $i => $s): $id = 'species_' . $i; ?>
                    <div class="form-check mb-0">
                      <input class="form-check-input filter-checkbox filter-species" type="radio" name="species_single" value="<?php echo h($s); ?>" id="<?php echo h($id); ?>">
                      <label class="form-check-label small" for="<?php echo h($id); ?>"><?php echo h($s); ?></label>
                    </div>
                  <?php endforeach; ?>
                </div>
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
          <div class="col-12 col-md filter-main-col" id="filterColCellline">
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
              <div class="filter-scroll" id="celllineOptions"></div>
            </div>
          </div>
          <div class="col-12 col-md-auto filter-and-col" id="filterAnd2">
            <span class="filter-and-badge">AND</span>
          </div>
          <div class="col-12 col-md filter-main-col" id="filterColGene">
            <div class="filter-box">
              <div class="filter-head">
                <div class="subtle-label mb-0">Perturbed Gene</div>
                <div class="filter-tools">
                  <span id="geneCount" class="filter-count">0/0</span>
                  <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="gene" data-action="all">Select All</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="gene" data-action="clear">Unselect All</button>
                </div>
              </div>
              <input type="search" id="geneSearch" class="form-control form-control-sm mb-2" placeholder="Search perturbed gene">
              <div class="filter-scroll" id="geneOptions"></div>
            </div>
          </div>
        </div>

        <div class="alert alert-warning py-2 px-3 mb-2 d-none" id="datasetAutoHint"></div>
      </section>

      <section class="panel-card p-3" id="step2Panel">
        <h2 class="h5 mb-3">Step 2. Heatmap Generation</h2>
        <div class="row g-2 mb-2">
          <div class="col-12 col-md-4">
            <label class="subtle-label mb-1 d-flex align-items-center" for="methodSel">
              <span>Aggregation and Correlation Mode</span>
              <a class="method-help-link" href="faq.php#q5-4" target="_blank" rel="noopener noreferrer" title="Open FAQ Q5.4" aria-label="Open FAQ Q5.4">?</a>
            </label>
            <select id="methodSel" class="form-select form-select-sm">
              <?php foreach ($methods as $m): ?>
                <?php
                  $methodLabel = $m;
                  if ($m === 'exp_cor') {
                    $methodLabel = 'Raw Expression';
                  } elseif (preg_match('/^bulkformer(?:[_-](all|mean|max|median))?$/i', (string)$m, $mm)) {
                    $suffix = strtolower((string)($mm[1] ?? ''));
                    if ($suffix === '') {
                      $methodLabel = 'Bulkformer';
                    } else {
                      $methodLabel = 'Bulkformer ' . ucfirst($suffix);
                    }
                  }
                ?>
                <option value="<?php echo h($m); ?>" <?php echo $m === $defaultMethod ? 'selected' : ''; ?>>
                  <?php echo h($methodLabel); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="muted-small mt-1" id="methodHintText"></div>
          </div>
          <div class="col-12 col-md-4">
            <label class="subtle-label mb-1 d-flex align-items-center" for="metricSel">
              <span>Similarity Metric</span>
              <a class="method-help-link" href="faq.php#q5-7" target="_blank" rel="noopener noreferrer" title="Open FAQ Q5.7" aria-label="Open FAQ Q5.7">?</a>
            </label>
            <select id="metricSel" class="form-select form-select-sm">
              <option value="cosine_similarity" selected>Cosine Similarity</option>
              <option value="pearson_distance">Pearson Distance</option>
              <option value="spearman_distance">Spearman Distance</option>
            </select>
          </div>
          <div class="col-12 col-md-4 plot-btn-col">
            <button type="button" id="plotBtn" class="btn btn-primary btn-sm">Submit and render heatmaps</button>
          </div>
        </div>

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
    const MAX_SELECTED_DATASET_IDS = <?php echo (int)HEATMAP_MAX_LABELS; ?>;
    const DATASET_COUNT_HIGHLIGHT_MAX = MAX_SELECTED_DATASET_IDS;
    const RENDER_DATASET_MAX = MAX_SELECTED_DATASET_IDS;
    const ajaxEndpoint = window.location.pathname.split('/').pop() || 'bulk_correlation_tool.php';

    const els = {
      selectedFilterSummary: document.getElementById('selectedFilterSummary'),
      speciesOptions: document.getElementById('speciesOptions'),
      tissueOptions: document.getElementById('tissueOptions'),
      celllineOptions: document.getElementById('celllineOptions'),
      geneOptions: document.getElementById('geneOptions'),
      tissueSearch: document.getElementById('tissueSearch'),
      celllineSearch: document.getElementById('celllineSearch'),
      geneSearch: document.getElementById('geneSearch'),
      filterColTissue: document.getElementById('filterColTissue'),
      filterColCellline: document.getElementById('filterColCellline'),
      filterColGene: document.getElementById('filterColGene'),
      filterAnd1: document.getElementById('filterAnd1'),
      filterAnd2: document.getElementById('filterAnd2'),
      modeBiosampleFirst: document.getElementById('modeBiosampleFirst'),
      modeGeneFirst: document.getElementById('modeGeneFirst'),
      methodSel: document.getElementById('methodSel'),
      metricSel: document.getElementById('metricSel'),
      methodHintText: document.getElementById('methodHintText'),
      plotBtn: document.getElementById('plotBtn'),
      step2Panel: document.getElementById('step2Panel'),
      datasetCountText: document.getElementById('datasetCountText'),
      datasetAutoHint: document.getElementById('datasetAutoHint'),
      heatmapContainer: document.getElementById('heatmapContainer'),
    };

    const state = {
      mode: 'biosample_first',
      selected: { species: new Set(), tissue: new Set(), cellline: new Set(), gene: new Set() },
      options: { species: [], tissue: [], cellline: [], gene: [] },
      allOptions: { species: [], tissue: [], cellline: [], gene: [] },
      render: {
        tissue: { list: [], rendered: 0 },
        cellline: { list: [], rendered: 0 },
        gene: { list: [], rendered: 0 },
      },
      datasetIds: [],
      selectedDatasetIds: [],
      localIndexBySpecies: new Map(),
      localIndex: null,
    };

    const allMethodOptions = Array.from(els.methodSel.options).map((op) => ({ value: op.value, label: op.textContent || op.value }));
    let filterReqSeq = 0;

    function debounce(fn, wait = 220) {
      let t = null;
      return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), wait); };
    }

    async function fetchJson(url, init = null) {
      const reqInit = init || { cache: 'no-store' };
      if (!('cache' in reqInit)) reqInit.cache = 'no-store';
      const res = await fetch(url, reqInit);
      const text = await res.text();
      let data = null;
      try { data = text ? JSON.parse(text) : null; } catch (_) { data = null; }
      if (!res.ok) {
        const msg = (data && data.message) ? data.message : `HTTP ${res.status}`;
        throw new Error(msg);
      }
      if (!data) throw new Error('Empty or non-JSON response');
      return data;
    }

    async function postAjax(payloadObj) {
      const params = new URLSearchParams();
      Object.entries(payloadObj).forEach(([k, v]) => params.set(k, String(v ?? '')));
      return fetchJson(ajaxEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString(),
        cache: 'no-store',
      });
    }

    function esc(v) {
      return String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#39;');
    }

    function selectedArray(group) { return Array.from(state.selected[group]); }
    function selectedSpecies() { return selectedArray('species')[0] || ''; }

    function currentFilters() {
      return {
        species: selectedArray('species'),
        tissues: selectedArray('tissue'),
        celllines: selectedArray('cellline'),
        genes: selectedArray('gene'),
      };
    }

    // If a group has all eligible options selected, treat it as "no restriction"
    // to avoid sending very large filter payloads to backend.
    function currentFiltersForQuery() {
      const raw = currentFilters();
      const f = {
        species: raw.species.slice(),
        tissues: raw.tissues.slice(),
        celllines: raw.celllines.slice(),
        genes: raw.genes.slice(),
      };
      const isFull = (group) => {
        const opts = state.options[group] || [];
        const sel = state.selected[group] || new Set();
        return opts.length > 0 && sel.size === opts.length;
      };
      if (isFull('tissue')) f.tissues = [];
      if (isFull('cellline')) f.celllines = [];
      if (isFull('gene')) f.genes = [];
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
      let payload = null;
      try {
        payload = await postAjax({ ajax: 'filter_index', species: sp });
      } catch (_) {
        state.localIndex = null;
        return false;
      }
      if (reqSeq !== null && reqSeq !== filterReqSeq) return false;
      if (!payload || !payload.ok || !Array.isArray(payload.rows)) {
        state.localIndex = null;
        return false;
      }
      const rows = payload.rows;
      const datasetSet = new Set();
      const dsMeta = new Map(); // ds -> { t, c, g[] }
      const byTissue = new Map(); // tissue -> Set(ds)
      const byCellline = new Map(); // cellline -> Set(ds)
      const byGene = new Map(); // gene -> Set(ds)
      const addToMapSet = (map, key, ds) => {
        const k = String(key || '').trim();
        if (!k) return;
        let s = map.get(k);
        if (!s) {
          s = new Set();
          map.set(k, s);
        }
        s.add(ds);
      };
      for (const r of rows) {
        const ds = String(r.d || '').trim();
        if (!ds) continue;
        const t = String(r.t || '').trim();
        const c = String(r.c || '').trim();
        const gArr = Array.isArray(r.g) ? r.g.map((v) => String(v || '').toUpperCase().trim()).filter((v) => v !== '') : [];
        datasetSet.add(ds);
        dsMeta.set(ds, { t, c, g: gArr });
        addToMapSet(byTissue, t, ds);
        addToMapSet(byCellline, c, ds);
        for (const g of gArr) addToMapSet(byGene, g, ds);
      }
      const idx = {
        species: sp,
        rows,
        options: payload.options || { tissue: [], cellline: [], gene: [] },
        allDatasetSet: datasetSet,
        dsMeta,
        byTissue,
        byCellline,
        byGene,
      };
      state.localIndexBySpecies.set(sp, idx);
      state.localIndex = idx;
      return true;
    }

    function setIntersect(a, b) {
      if (!a || !b) return new Set();
      const small = a.size <= b.size ? a : b;
      const big = a.size <= b.size ? b : a;
      const out = new Set();
      for (const v of small) {
        if (big.has(v)) out.add(v);
      }
      return out;
    }

    function unionBySelected(map, selectedValues) {
      const vals = Array.isArray(selectedValues) ? selectedValues : [];
      if (!vals.length) return null;
      const out = new Set();
      for (const v of vals) {
        const s = map.get(String(v));
        if (!s) continue;
        for (const ds of s) out.add(ds);
      }
      return out;
    }

    function localMatchDatasetSet(filters) {
      const idx = state.localIndex;
      if (!idx || !idx.allDatasetSet) return new Set();
      let result = new Set(idx.allDatasetSet);
      const tSet = unionBySelected(idx.byTissue, (filters.tissues || []).map((v) => String(v).trim()));
      const cSet = unionBySelected(idx.byCellline, (filters.celllines || []).map((v) => String(v).trim()));
      const gSet = unionBySelected(idx.byGene, (filters.genes || []).map((v) => String(v).toUpperCase().trim()));
      if (tSet) result = setIntersect(result, tSet);
      if (cSet) result = setIntersect(result, cSet);
      if (gSet) result = setIntersect(result, gSet);
      return result;
    }

    function uniqueSorted(values) {
      const set = new Set();
      for (const v of values) {
        const s = String(v || '').trim();
        if (s !== '') set.add(s);
      }
      return Array.from(set).sort((a, b) => a.localeCompare(b));
    }

    function localExtractOptions(group, datasetSet) {
      const idx = state.localIndex;
      if (!idx || !idx.dsMeta) return [];
      const ids = datasetSet instanceof Set ? datasetSet : new Set();
      if (group === 'tissue') {
        const vals = [];
        for (const ds of ids) {
          const m = idx.dsMeta.get(ds);
          if (m && m.t) vals.push(m.t);
        }
        return uniqueSorted(vals);
      }
      if (group === 'cellline') {
        const vals = [];
        for (const ds of ids) {
          const m = idx.dsMeta.get(ds);
          if (m && m.c) vals.push(m.c);
        }
        return uniqueSorted(vals);
      }
      if (group === 'gene') {
        const vals = [];
        for (const ds of ids) {
          const m = idx.dsMeta.get(ds);
          const gs = m && Array.isArray(m.g) ? m.g : [];
          for (const g of gs) vals.push(g);
        }
        return uniqueSorted(vals.map((v) => String(v).toUpperCase()));
      }
      return [];
    }

    function hasExplicitEmptySelection() {
      // Cross-group logic is AND; if any active lower filter group is explicitly unselected,
      // the final dataset match should be empty.
      if (state.selected.species.size !== 1) return false;
      const level1 = modeGroupByLevel(1);
      if (!state.selected[level1] || state.selected[level1].size === 0) return false;
      return ['tissue', 'cellline', 'gene'].some((g) => (state.selected[g] || new Set()).size === 0);
    }

    function speciesMode() {
      const s = (selectedArray('species')[0] || '').toLowerCase();
      if (!s) return 'none';
      if (s.includes('mouse') || s.includes('mus')) return 'mouse';
      if (s.includes('human') || s.includes('homo')) return 'human';
      return 'other';
    }

    function updateMethodOptionsBySpecies() {
      const mode = speciesMode();
      const prev = els.methodSel.value;
      let allowed = allMethodOptions;
      if (mode === 'mouse') allowed = allMethodOptions.filter((x) => x.value === 'exp_cor');
      if (!allowed.length) allowed = allMethodOptions.slice(0, 1);
      els.methodSel.innerHTML = allowed.map((x) => `<option value="${esc(x.value)}">${esc(x.label)}</option>`).join('');
      const keep = allowed.find((x) => x.value === prev);
      els.methodSel.value = keep ? prev : allowed[0].value;
      if (mode === 'mouse') {
        els.methodHintText.textContent = 'Mouse: only Raw Expression is available.';
      } else if (mode === 'human') {
        els.methodHintText.textContent = 'Human: Raw Expression and BulkFormer embedding are available.';
      } else {
        els.methodHintText.textContent = '';
      }
    }

    function modeGroupByLevel(level) {
      if (state.mode === 'perturbed_gene_first') {
        if (level === 1) return 'gene';
        if (level === 2) return 'tissue';
        return 'cellline';
      }
      if (level === 1) return 'tissue';
      if (level === 2) return 'cellline';
      return 'gene';
    }

    function applyModeLayout() {
      if (!els.filterColTissue || !els.filterColCellline || !els.filterColGene) return;
      if (state.mode === 'perturbed_gene_first') {
        els.filterColGene.style.order = '1';
        if (els.filterAnd1) els.filterAnd1.style.order = '2';
        els.filterColTissue.style.order = '3';
        if (els.filterAnd2) els.filterAnd2.style.order = '4';
        els.filterColCellline.style.order = '5';
      } else {
        els.filterColTissue.style.order = '1';
        if (els.filterAnd1) els.filterAnd1.style.order = '2';
        els.filterColCellline.style.order = '3';
        if (els.filterAnd2) els.filterAnd2.style.order = '4';
        els.filterColGene.style.order = '5';
      }
    }

    function groupLevel(group) {
      if (state.mode === 'perturbed_gene_first') {
        if (group === 'gene') return 1;
        if (group === 'tissue') return 2;
        if (group === 'cellline') return 3;
        return 0;
      }
      if (group === 'tissue') return 1;
      if (group === 'cellline') return 2;
      if (group === 'gene') return 3;
      return 0;
    }

    function getSearchValue(group) {
      if (group === 'tissue') return (els.tissueSearch.value || '').trim();
      if (group === 'cellline') return (els.celllineSearch.value || '').trim();
      return (els.geneSearch.value || '').trim();
    }

    function getWrapEl(group) {
      if (group === 'species') return els.speciesOptions;
      if (group === 'tissue') return els.tissueOptions;
      if (group === 'cellline') return els.celllineOptions;
      return els.geneOptions;
    }

    async function fetchGroupOptions(group, scope, reqSeq = null) {
      if (state.localIndex && group !== 'species') {
        const f = currentFiltersForQuery();
        let localFilters = { tissues: [], celllines: [], genes: [] };
        if (scope === 'all') {
          if (group !== 'species') {
            // species already pinned by local index
          }
        } else {
          if (state.mode === 'perturbed_gene_first') {
            // L1: gene; L2: tissue; L3: cellline
            if (group === 'tissue') {
              localFilters.genes = f.genes;
            } else if (group === 'cellline') {
              localFilters.genes = f.genes;
              localFilters.tissues = f.tissues;
            }
          } else {
            // L1: tissue; L2: cellline; L3: gene
            if (group === 'cellline') {
              localFilters.tissues = f.tissues;
            } else if (group === 'gene') {
              localFilters.tissues = f.tissues;
              localFilters.celllines = f.celllines;
            }
          }
        }
        const dsSet = localMatchDatasetSet(localFilters);
        return localExtractOptions(group, dsSet);
      }
      const f = currentFiltersForQuery();
      const payload = await postAjax({
        ajax: 'filter_options', group, mode: state.mode, scope, level: groupLevel(group), q: '', limit: 5000,
        species: f.species.join(','), tissues: f.tissues.join(','), celllines: f.celllines.join(','), genes: f.genes.join(','),
      });
      if (reqSeq !== null && reqSeq !== filterReqSeq) return [];
      return (payload && payload.ok && Array.isArray(payload.options)) ? payload.options : [];
    }

    function renderSpecies(reset = true) {
      const wrap = els.speciesOptions;
      const all = state.allOptions.species || [];
      const selected = selectedArray('species')[0] || '';
      const list = all.slice();
      if (!list.length) {
        wrap.innerHTML = '<div class="muted-small">No options.</div>'; return;
      }
      const html = list.map((v, i) => {
        const id = `species_${i}_${String(v).replace(/[^A-Za-z0-9_]/g, '_')}`;
        return `<div class="form-check mb-0"><input class="form-check-input filter-checkbox filter-species" type="radio" name="species_single" value="${esc(v)}" id="${id}" ${selected === v ? 'checked' : ''}><label class="form-check-label small" for="${id}">${esc(v)}</label></div>`;
      }).join('');
      wrap.innerHTML = html;
    }

    function renderGroup(group, reset = true) {
      const wrap = getWrapEl(group);
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
        return `<div class="form-check mb-1 filter-item"><input class="form-check-input filter-checkbox filter-${group}" type="checkbox" value="${esc(v)}" id="${id}" ${selected.has(v) ? 'checked' : ''} ${disabled ? 'disabled' : ''}><label class="form-check-label small${cls}" for="${id}">${esc(v)}${disabled ? ' <span class="disabled-tag">(Unavailable)</span>' : ''}</label></div>`;
      }).join('');
      if (start === 0) wrap.innerHTML = html;
      else wrap.insertAdjacentHTML('beforeend', html);

      const oldHint = wrap.querySelector('.opt-hint');
      if (oldHint) oldHint.remove();
      const hint = document.createElement('div');
      hint.className = 'muted-small opt-hint';
      const remain = st.list.length - st.rendered;
      hint.textContent = remain > 0 ? `Showing ${st.rendered}/${st.list.length}. Scroll to load more (${remain} left).` : `Showing all ${st.list.length}.`;
      wrap.appendChild(hint);
    }

    function shortenValues(arr, n = 5) {
      if (arr.length <= n) return arr.join(', ');
      return `${arr.slice(0, n).join(', ')} ... (+${arr.length - n})`;
    }

    function updateSelectedSummary() {
      const f = currentFilters();
      const parts = [];
      if (f.species.length) parts.push(`Species: ${shortenValues(f.species)}`);
      if (f.tissues.length) parts.push(`Tissue: ${shortenValues(f.tissues)}`);
      if (f.celllines.length) parts.push(`Biosample Description: ${shortenValues(f.celllines)}`);
      if (f.genes.length) parts.push(`Perturbed Gene: ${shortenValues(f.genes)}`);
      if (!f.species.length) { els.selectedFilterSummary.textContent = 'Select one species to start dataset matching.'; return; }
      parts.push(`Cascade Mode: ${state.mode === 'perturbed_gene_first' ? 'Perturbed Gene First' : 'Biosample First'}`);
      els.selectedFilterSummary.textContent = `Selected filters: ${parts.join(' | ')}`;
    }

    function updateFilterCounts() {
      const selectedN = (group) => state.selected[group].size;
      const setCount = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
      setCount('speciesCount', `${selectedN('species')}/${(state.allOptions.species || []).length}`);
      setCount('tissueCount', `${selectedN('tissue')}/${(state.options.tissue || []).length}`);
      setCount('celllineCount', `${selectedN('cellline')}/${(state.options.cellline || []).length}`);
      setCount('geneCount', `${selectedN('gene')}/${(state.options.gene || []).length}`);
    }

    async function refreshSpecies(reqSeq = null) {
      const payload = await postAjax({ ajax: 'filter_options', group: 'species', mode: state.mode, scope: 'all', level: 0, q: '', limit: 5000, species: '', tissues: '', celllines: '', genes: '' });
      if (reqSeq !== null && reqSeq !== filterReqSeq) return;
      const nextOpts = (payload && payload.ok && Array.isArray(payload.options)) ? payload.options : [];
      // Keep current selection/options on transient empty response to avoid full state reset.
      if (nextOpts.length === 0 && (state.selected.species || new Set()).size > 0 && (state.allOptions.species || []).length > 0) {
        renderSpecies();
        updateFilterCounts();
        return;
      }
      state.allOptions.species = nextOpts;
      renderSpecies(); updateFilterCounts();
    }

    async function refreshCascadeFromLevel(startLevel, reqSeq = null) {
      if (state.selected.species.size !== 1) {
        state.localIndex = null;
        ['tissue', 'cellline', 'gene'].forEach((g) => { state.options[g] = []; state.allOptions[g] = []; state.selected[g] = new Set(); getWrapEl(g).innerHTML = '<div class="muted-small">Select one species first.</div>'; });
        renderDatasetChecklist([], 0); updateSelectedSummary(); updateFilterCounts(); return;
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
          const parentGroup = modeGroupByLevel(level - 1);
          const hasParentSelected = (state.selected[parentGroup] || new Set()).size > 0;
          // Strict hierarchy: child only becomes selectable after parent has at least one selection.
          if (hasParentSelected) {
            eligibleOpts = await fetchGroupOptions(g, 'eligible', reqSeq);
          } else {
            eligibleOpts = [];
          }
        }
        if (reqSeq !== null && reqSeq !== filterReqSeq) return;
        state.allOptions[g] = allOpts;
        state.options[g] = eligibleOpts;
        const eligibleSet = new Set(eligibleOpts);
        const prev = state.selected[g] || new Set();
        // Auto-select downstream eligible options once parent level has a selection.
        if (level > 1 && eligibleOpts.length > 0) {
          state.selected[g] = new Set(eligibleOpts);
        } else {
          state.selected[g] = new Set(Array.from(prev).filter((v) => eligibleSet.has(v)));
        }
        renderGroup(g);
      }
      updateSelectedSummary(); updateFilterCounts(); await fetchDatasets(reqSeq);
    }

    async function refreshAllFiltersAndDatasets(changedGroup = null, modeSwitched = false) {
      const reqSeq = ++filterReqSeq;
      applyModeLayout();
      // Species options are stable; avoid refetching on every downstream click.
      if (changedGroup === 'species' || !changedGroup || modeSwitched) await refreshSpecies(reqSeq);
      updateMethodOptionsBySpecies();
      if (modeSwitched || changedGroup === 'species' || !changedGroup) {
        if (modeSwitched || changedGroup === 'species') {
          state.selected.tissue.clear();
          state.selected.cellline.clear();
          state.selected.gene.clear();
        }
        await refreshCascadeFromLevel(1, reqSeq);
        return;
      }
      const lv = groupLevel(changedGroup);
      if (lv === 1) await refreshCascadeFromLevel(2, reqSeq);
      else if (lv === 2) await refreshCascadeFromLevel(3, reqSeq);
      else { updateSelectedSummary(); updateFilterCounts(); await fetchDatasets(reqSeq); }
    }

    async function fetchDatasets(reqSeq = null) {
      const f = currentFiltersForQuery();
      if (f.species.length !== 1) {
        renderDatasetChecklist([], 0);
        return;
      }
      const level1Group = modeGroupByLevel(1);
      if (!state.selected[level1Group] || state.selected[level1Group].size === 0) {
        renderDatasetChecklist([], 0);
        return;
      }
      if (hasExplicitEmptySelection()) {
        renderDatasetChecklist([], 0);
        if (els.datasetAutoHint) {
          els.datasetAutoHint.classList.remove('d-none');
          els.datasetAutoHint.classList.remove('alert-warning');
          els.datasetAutoHint.classList.add('alert-danger');
          els.datasetAutoHint.textContent = 'Matched 0 Dataset ID. Please select at least one option in each filter group.';
        }
        return;
      }
      if (state.localIndex) {
        const dsSet = localMatchDatasetSet({
          tissues: f.tissues,
          celllines: f.celllines,
          genes: f.genes,
        });
        const totalMatched = dsSet.size;
        let ids = [];
        if (totalMatched >= 2 && totalMatched <= MAX_SELECTED_DATASET_IDS) {
          ids = Array.from(dsSet).sort((a, b) => a.localeCompare(b));
        }
        renderDatasetChecklist(ids, totalMatched);
        return;
      }
      const payload = await postAjax({ ajax: 'datasets', species: f.species.join(','), tissues: f.tissues.join(','), celllines: f.celllines.join(','), genes: f.genes.join(','), limit: String(MAX_SELECTED_DATASET_IDS) });
      if (reqSeq !== null && reqSeq !== filterReqSeq) return;
      const ids = (payload && payload.ok && Array.isArray(payload.dataset_ids)) ? payload.dataset_ids : [];
      renderDatasetChecklist(ids, payload?.total || 0);
    }

    function updateDatasetCountText() {
      const totalRaw = parseInt(String(els.datasetCountText.datasetTotal || 0), 10);
      const total = Number.isFinite(totalRaw) ? totalRaw : 0;
      els.datasetCountText.textContent = String(total);
      const outOfRange = total < 2 || total > DATASET_COUNT_HIGHLIGHT_MAX;
      els.datasetCountText.classList.toggle('text-danger', outOfRange);
      els.datasetCountText.classList.toggle('text-dark', !outOfRange);
      syncRenderGate(total);
    }

    function syncRenderGate(totalMatched) {
      const total = Number.isFinite(Number(totalMatched)) ? Number(totalMatched) : 0;
      const valid = total >= 2 && total <= RENDER_DATASET_MAX;
      els.plotBtn.disabled = !valid;
      els.methodSel.disabled = false;
      els.metricSel.disabled = false;
      els.step2Panel.classList.remove('render-disabled');
    }

    function renderDatasetChecklist(ids, totalCount) {
      state.datasetIds = Array.isArray(ids) ? ids.slice() : [];
      const totalMatched = Number.isFinite(Number(totalCount)) ? Number(totalCount) : state.datasetIds.length;
      els.datasetCountText.datasetTotal = String(totalMatched);
      if (totalMatched > RENDER_DATASET_MAX || totalMatched < 2) {
        state.selectedDatasetIds = [];
      } else {
        state.selectedDatasetIds = state.datasetIds.slice();
      }
      if (els.datasetAutoHint) {
        if (totalMatched > RENDER_DATASET_MAX) {
          els.datasetAutoHint.classList.remove('alert-warning');
          els.datasetAutoHint.classList.add('alert-danger');
          els.datasetAutoHint.classList.remove('d-none');
          els.datasetAutoHint.textContent = `Matched ${totalMatched} Dataset ID. This tool supports 2 to ${RENDER_DATASET_MAX}. Please refine your filters and reduce the matched Dataset ID.`;
        } else if (totalMatched > 0 && totalMatched < 2) {
          els.datasetAutoHint.classList.remove('alert-warning');
          els.datasetAutoHint.classList.add('alert-danger');
          els.datasetAutoHint.classList.remove('d-none');
          els.datasetAutoHint.textContent = `Matched ${totalMatched} Dataset ID. At least 2 IDs are required. Please broaden your filters.`;
        } else {
          els.datasetAutoHint.classList.remove('alert-danger');
          els.datasetAutoHint.classList.add('alert-warning');
          els.datasetAutoHint.classList.add('d-none');
          els.datasetAutoHint.textContent = '';
        }
      }
      updateDatasetCountText();
    }

    function selectedDatasetIds() { return state.selectedDatasetIds.slice(); }

    function calcHeatmapViewport(nLabels) {
      const n = Number.isFinite(Number(nLabels)) ? Number(nLabels) : 0;
      const widthPx = Math.max(980, Math.min(1600, 420 + (n * 78)));
      const heightPx = Math.max(760, Math.min(1240, 360 + (n * 60)));
      return { widthPx, heightPx };
    }

    async function plotHeatmaps() {
      const ids = selectedDatasetIds();
      const totalMatched = parseInt(String(els.datasetCountText.datasetTotal || 0), 10) || 0;
      const startedAt = performance.now();
      const elapsedText = () => `${((performance.now() - startedAt) / 1000).toFixed(2)}s`;
      els.heatmapContainer.innerHTML = '';
      if (state.selected.species.size !== 1) { els.heatmapContainer.innerHTML = '<div class="alert alert-warning mb-0">Please select exactly one species first.</div>'; return; }
      if (totalMatched > RENDER_DATASET_MAX) {
        els.heatmapContainer.innerHTML = `<div class="alert alert-danger mb-0">Matched Dataset ID exceed the limit (${totalMatched} > ${RENDER_DATASET_MAX}). Please refine your filters and try again.</div>`;
        return;
      }
      if (totalMatched > 0 && totalMatched < 2) {
        els.heatmapContainer.innerHTML = `<div class="alert alert-danger mb-0">Matched Dataset ID is below the minimum requirement (${totalMatched} < 2). Please broaden your filters and try again.</div>`;
        return;
      }
      if (!ids.length) { els.heatmapContainer.innerHTML = `<div class="alert alert-danger mb-0">No valid Dataset ID selected. Please refine filters to match 2 to ${RENDER_DATASET_MAX}.</div>`; return; }
      if (ids.length < 2) { els.heatmapContainer.innerHTML = '<div class="alert alert-warning mb-0">At least 2 Dataset ID are required to render a correlation heatmap.</div>'; return; }
      const method = els.methodSel.value || 'exp_cor';
      const metric = els.metricSel.value || 'cosine_similarity';
      const renderMax = RENDER_DATASET_MAX;
      if (ids.length > renderMax) { els.heatmapContainer.innerHTML = `<div class="alert alert-warning mb-0">Too many selected Dataset ID (${ids.length}). Please reduce your selection to ${renderMax} or fewer.</div>`; return; }

      const card = document.createElement('div');
      card.className = 'heatmap-card';
      card.innerHTML = `<div class="d-flex justify-content-between align-items-start mb-1 gap-2"><div class="fw-semibold">Selected Dataset ID submatrix</div><div class="heatmap-header-right"><a id="heatmapOpenFullscreen" class="btn btn-sm btn-outline-dark heatmap-open-link d-none" href="#" target="_blank" rel="noopener noreferrer" aria-label="Open fullscreen" title="Open fullscreen"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 3 3 3 3 9"></polyline><line x1="3" y1="3" x2="10" y2="10"></line><polyline points="15 3 21 3 21 9"></polyline><line x1="21" y1="3" x2="14" y2="10"></line><polyline points="3 15 3 21 9 21"></polyline><line x1="3" y1="21" x2="10" y2="14"></line><polyline points="21 15 21 21 15 21"></polyline><line x1="21" y1="21" x2="14" y2="14"></line></svg></a><div class="muted-small text-end" id="heatmapHintText">preparing...</div></div></div><div class="heatmap-box heatmap-loading-wrap" id="heatmapLoadingWrap"><div class="heatmap-loading-wheel"></div><div class="muted-small">Generating heatmap data, please wait...</div></div>`;
      els.heatmapContainer.appendChild(card);
      const hint = card.querySelector('#heatmapHintText');
      const tick = setInterval(() => { if (hint) hint.textContent = `preparing... ${elapsedText()}`; }, 200);
      const timeoutHint = 'Heatmap rendering timed out. Please narrow the filters (fewer Dataset ID) and try again.';
      const normalizeErrorMessage = (msg) => {
        const m = String(msg || '');
        return /timed?\s*out|timeout|504|gateway timeout/i.test(m) ? timeoutHint : m;
      };
      try {
        const params = new URLSearchParams();
        params.set('ajax', 'heatmap_selected_prepare');
        params.set('dataset_ids', ids.join(','));
        params.set('method', method);
        params.set('metric', metric);
        params.set('species', selectedArray('species')[0] || '');
        const payload = await fetchJson(ajaxEndpoint, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: params.toString(), cache: 'no-store' });
        if (!payload || !payload.ok) {
          clearInterval(tick);
          hint.textContent = payload && payload.message ? normalizeErrorMessage(payload.message) : 'load failed';
          return;
        }
        if (!payload.iframe_src) { clearInterval(tick); hint.textContent = 'invalid heatmap source'; return; }
        const openFullscreen = card.querySelector('#heatmapOpenFullscreen');
        if (openFullscreen) { openFullscreen.href = payload.iframe_src; openFullscreen.classList.remove('d-none'); }
        let hintText = `${payload.n_labels || 0} ids`;
        hint.textContent = `${hintText} | opening workspace... ${elapsedText()}`;
        const box = card.querySelector('.heatmap-box');
        const iframe = document.createElement('iframe');
        iframe.className = 'heatmap-iframe'; iframe.loading = 'lazy'; iframe.src = payload.iframe_src;
        const vp = calcHeatmapViewport(payload.n_labels || 0);
        iframe.style.width = `${vp.widthPx}px`; iframe.style.maxWidth = '100%'; iframe.style.height = `${vp.heightPx}px`;
        box.style.minHeight = `${Math.max(560, vp.heightPx + 16)}px`; box.classList.remove('heatmap-loading-wrap');
        iframe.addEventListener('load', () => { clearInterval(tick); hint.textContent = `${hintText} | done in ${elapsedText()}`; }, { once: true });
        iframe.addEventListener('error', () => { clearInterval(tick); hint.textContent = `failed to open heatmap workspace (${elapsedText()})`; }, { once: true });
        box.innerHTML = ''; box.appendChild(iframe);
      } catch (e) {
        clearInterval(tick);
        const raw = e && e.message ? String(e.message) : '';
        const msg = normalizeErrorMessage(raw);
        card.querySelector('#heatmapHintText').textContent = msg ? `load failed: ${msg}` : 'load failed';
      }
    }

    function onOptionChange(group, wrap) {
      wrap.addEventListener('change', (e) => {
        const t = e.target;
        if (!(t instanceof HTMLInputElement)) return;
        if (!t.classList.contains(`filter-${group}`)) return;
        if (group === 'species') {
          state.selected.species = t.checked ? new Set([t.value]) : new Set();
          renderSpecies(true);
        } else {
          if (t.checked) state.selected[group].add(t.value);
          else state.selected[group].delete(t.value);
          // Re-render current group first so selected options float to top immediately.
          renderGroup(group, true);
        }
        // Refresh local counters immediately to avoid lag while async cascade is in flight.
        updateFilterCounts();
        updateSelectedSummary();
        refreshAllFiltersAndDatasets(group).catch(() => {});
      });
      if (group === 'species') return;
      wrap.addEventListener('scroll', () => {
        const nearBottom = wrap.scrollTop + wrap.clientHeight >= wrap.scrollHeight - 18;
        if (!nearBottom) return;
        renderGroup(group, false);
      });
    }

    const debouncedSearch = debounce(() => {
      renderGroup('tissue', true); renderGroup('cellline', true); renderGroup('gene', true);
    }, 220);

    onOptionChange('species', els.speciesOptions);
    onOptionChange('tissue', els.tissueOptions);
    onOptionChange('cellline', els.celllineOptions);
    onOptionChange('gene', els.geneOptions);

    document.querySelectorAll('button[data-group][data-action]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const group = btn.getAttribute('data-group');
        const action = btn.getAttribute('data-action');
        if (!group || !state.selected[group]) return;
        if (action === 'clear') state.selected[group].clear();
        else if (action === 'all') {
          if (group === 'species') {
            const first = (state.allOptions.species || [])[0];
            state.selected.species = first ? new Set([first]) : new Set();
          } else {
            state.selected[group] = new Set(state.options[group] || []);
          }
        }
        // Immediate UI sync for current filter box.
        if (group === 'species') renderSpecies(true);
        else renderGroup(group, true);
        // Refresh local counters immediately to avoid lag while async cascade is in flight.
        updateFilterCounts();
        updateSelectedSummary();
        refreshAllFiltersAndDatasets(group).catch(() => {});
      });
    });

    els.tissueSearch.addEventListener('input', debouncedSearch);
    els.celllineSearch.addEventListener('input', debouncedSearch);
    els.geneSearch.addEventListener('input', debouncedSearch);

    [els.modeBiosampleFirst, els.modeGeneFirst].forEach((el) => {
      el.addEventListener('change', () => {
        if (!el.checked) return;
        state.mode = el.value;
        applyModeLayout();
        refreshAllFiltersAndDatasets('species', true).catch(() => {});
      });
    });

    els.plotBtn.addEventListener('click', plotHeatmaps);
    els.methodSel.addEventListener('change', () => {
      if (speciesMode() === 'mouse' && els.methodSel.value !== 'exp_cor') els.methodSel.value = 'exp_cor';
    });

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((node) => {
      new bootstrap.Tooltip(node);
    });

    applyModeLayout();
    syncRenderGate(0);
    refreshAllFiltersAndDatasets().catch(() => {});
  </script>
<script>document.getElementById('year').textContent = new Date().getFullYear();</script>
</body>
</html>






