<?php
require_once __DIR__ . '/config.php';

$dbFile = DB_FILE;

if (!file_exists($dbFile)) {
    die('Database not found: ' . basename($dbFile));
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function enforce_ajax_rate_limit(int $maxRequests = 120, int $windowSeconds = 60): void
{
    $dir = __DIR__ . '/temp/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir)) {
        return;
    }

    $ip = client_ip_for_rate_limit();
    $key = hash('sha256', 'browse_ajax|' . $ip);
    $file = $dir . '/' . $key . '.json';
    $now = time();
    $state = ['ts' => $now, 'count' => 0];
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $parsed = json_decode((string)$raw, true);
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
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Too many requests. Please try again later.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function formatTissueDisplay(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return $raw;
    }
    return preg_replace_callback('/[A-Za-z]+/', static function (array $m): string {
        $w = $m[0];
        return strtoupper(substr($w, 0, 1)) . strtolower(substr($w, 1));
    }, $raw) ?? $raw;
}

function fetchDistinctValues(PDO $pdo, string $column): array
{
    static $allowed = [
        'id',
        'dataset_id',
        'external_series_accession',
        'meta_assay_type',
        'meta_biosample_species',
        'meta_assay_scale',
        'meta_assay_target_gene_type',
        'meta_biosample_tissue_name',
        'meta_biosample_classification_type',
        'meta_biosample_description',
        'meta_assay_target_gene_name',
    ];
    if (!in_array($column, $allowed, true)) {
        return [];
    }
    $table = DB_TABLE;
    $sql = "SELECT DISTINCT $column AS value FROM $table WHERE $column IS NOT NULL AND TRIM($column) != '' ORDER BY $column ASC";
    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
}

function addExactInCondition(string $column, array $values, array &$where, array &$params, string $paramPrefix): void
{
    $values = array_values(array_filter(array_map('trim', $values), static fn($v) => $v !== ''));
    if (count($values) === 0) {
        return;
    }

    $placeholders = [];
    foreach ($values as $i => $value) {
        $key = ':' . $paramPrefix . '_' . $i;
        $placeholders[] = $key;
        $params[$key] = $value;
    }
    $where[] = $column . ' IN (' . implode(',', $placeholders) . ')';
}

function renderCheckboxSection(
    string $fieldName,
    string $label,
    array $options,
    array $selected,
    bool $showSearch = true,
    bool $lazyLoad = false
): void
{
    $selectedMap = array_fill_keys(array_map('strval', $selected), true);
  echo '<div class="facet-group">';
    echo '<div class="d-flex align-items-center justify-content-between py-2">';
    echo '<div class="fw-semibold">' . h($label) . '</div>';
    echo '<button class="btn btn-sm btn-link p-0 text-decoration-none explore-link" type="button" data-bs-toggle="collapse" data-bs-target="#' . h($fieldName) . 'Block">Toggle</button>';
    echo '</div>';
    echo '<div id="' . h($fieldName) . 'Block" class="collapse show">';
  if ($showSearch) {
    echo '<div class="facet-search-wrap">';
      echo '<div class="input-group input-group-sm">';
      echo '<input type="search" class="form-control facet-search" data-facet-target="' . h($fieldName) . 'Options" placeholder="Search ' . h($label) . '">';
      if ($lazyLoad) {
        echo '<button type="button" class="btn btn-outline-secondary facet-search-btn" data-facet-target="' . h($fieldName) . 'Options" data-mode="collapsed" title="Expand search">?</button>';
      } else {
        echo '<button type="button" class="btn btn-outline-secondary facet-search-btn" data-facet-target="' . h($fieldName) . 'Options">Search</button>';
      }
      echo '</div>';
    echo '</div>';
  }
    echo '<div class="explore-options-scroll overflow-auto pe-2" style="max-height: 220px;">';
  echo '<div id="' . h($fieldName) . 'Options"'
    . ' data-field-name="' . h($fieldName) . '"'
    . ' data-group-label="' . h($label) . '"'
    . ' data-batch-size="40"'
    . ' data-visible-count="40"'
    . ' data-lazy="' . ($lazyLoad ? '1' : '0') . '">';

    if ($lazyLoad) {
        if (count($selected) > 0) {
            foreach ($selected as $option) {
                $value = (string)$option;
                $displayValue = $value;
                if ($fieldName === 'meta_assay_scale') {
                    $scale = strtolower(trim($value));
                    if ($scale === 'single cell') {
                        $displayValue = 'Single Cell Sequencing';
                    } elseif ($scale === 'bulk') {
                        $displayValue = 'Bulk Sequencing';
                    }
                }
                if ($fieldName === 'meta_biosample_tissue_name') {
                    $displayValue = formatTissueDisplay($value);
                }
                $id = $fieldName . '_' . md5($value);
                $searchText = h(strtolower($value));
                echo '<div class="form-check explore-tissue-item mb-1" data-search-text="' . $searchText . '">';
                echo '<input class="form-check-input filter-checkbox" type="checkbox" name="' . h($fieldName) . '[]" value="' . h($value) . '" id="' . h($id) . '" data-group-label="' . h($label) . '" checked>';
                echo '<label class="form-check-label small" for="' . h($id) . '">' . h($displayValue) . '</label>';
                echo '</div>';
            }
        } else {
            echo '<div class="text-light small py-2">Loading options...</div>';
        }
    } else {
        if (count($options) === 0) {
            echo '<div class="text-light small py-2">No options</div>';
        } else {
            foreach ($options as $option) {
                $value = (string)$option;
                $displayValue = $value;
                if ($fieldName === 'meta_assay_scale') {
                    $scale = strtolower(trim($value));
                    if ($scale === 'single cell') {
                        $displayValue = 'Single Cell Sequencing';
                    } elseif ($scale === 'bulk') {
                        $displayValue = 'Bulk Sequencing';
                    }
                }
                if ($fieldName === 'meta_biosample_tissue_name') {
                    $displayValue = formatTissueDisplay($value);
                }
                $checked = isset($selectedMap[$value]) ? 'checked' : '';
                $id = $fieldName . '_' . md5($value);
                $searchText = h(strtolower($value));
                echo '<div class="form-check explore-tissue-item mb-1" data-search-text="' . $searchText . '">';
                echo '<input class="form-check-input filter-checkbox" type="checkbox" name="' . h($fieldName) . '[]" value="' . h($value) . '" id="' . h($id) . '" data-group-label="' . h($label) . '" ' . $checked . '>';
                echo '<label class="form-check-label small" for="' . h($id) . '">' . h($displayValue) . '</label>';
                echo '</div>';
            }
        }
    }

    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

function renderTargetGeneCell($targetGene): string
{
    $raw = trim((string)$targetGene);
    if ($raw === '') {
      return '<span class="text-light">N/A</span>';
    }

    $parts = preg_split('/[,\|]/', $raw);
    $genes = [];
    foreach ($parts as $part) {
      $value = trim((string)$part);
      if ($value === '') continue;
      $genes[$value] = true;
    }
    $genes = array_keys($genes);
    sort($genes);
    if (count($genes) <= 1) {
      return h($genes[0] ?? $raw);
    }

    $html = '<div class="target-gene-box" title="' . h($raw) . '">';
    foreach ($genes as $gene) {
      $html .= '<div class="target-gene-item">' . h($gene) . '</div>';
    }
    $html .= '</div>';
    return $html;
  }

  function renderPublicDatasetCell($publicDataset): string
  {
    $raw = trim((string)$publicDataset);
    if ($raw === '') {
      return '<span class="text-light">N/A</span>';
    }

    $parts = explode('|', $raw);
    $links = [];
    foreach ($parts as $part) {
      $part = trim($part);
      if (preg_match('/^(GSE\d+)/i', $part, $matches)) {
        $acc = strtoupper($matches[1]);
        $url = 'https://www.ncbi.nlm.nih.gov/geo/query/acc.cgi?acc=' . rawurlencode($acc);
        $links[] = '<a class="text-decoration-none fw-semibold" href="' . h($url) . '" target="_blank" rel="noopener noreferrer">' . h($part) . '</a>';
      } else {
        $links[] = h($part);
      }
    }

    return implode('<span class="text-muted mx-1">|</span>', $links);
  }

function renderGeneTypeCell($geneType): string
{
    $raw = trim((string)$geneType);
    if ($raw === '') {
      return '<span class="text-light">N/A</span>';
    }

    $parts = preg_split('/[,\|]/', $raw);
    $types = [];
    foreach ($parts as $part) {
      $value = trim((string)$part);
      if ($value === '') continue;
      $types[$value] = true;
    }
    $types = array_keys($types);
    sort($types);
    if (count($types) <= 1) {
      return h($types[0] ?? $raw);
    }

    $html = '<div class="target-gene-box" title="' . h($raw) . '">';
    foreach ($types as $type) {
      $html .= '<div class="target-gene-item">' . h($type) . '</div>';
    }
    $html .= '</div>';
    return $html;
  }

function getAssayTypeCategory($raw): string
{
    $rawText = trim((string)$raw);
    if ($rawText === '') return 'OTHER';

    // Normalize delimiters, split, and de-duplicate tokens before categorization.
    $normalized = str_replace(['|', ';'], ',', $rawText);
    $normalized = preg_replace('/\.(?=[A-Za-z])/u', ',', $normalized);
    $parts = preg_split('/,+/u', (string)$normalized) ?: [];

    $cats = [];
    foreach ($parts as $p) {
        $u = strtoupper(trim((string)$p));
        if ($u === '') continue;

        if (strpos($u, 'CRISPRA') !== false) {
            $cats['CRISPRa'] = true;
            continue;
        }
        if (strpos($u, 'CRISPRI') !== false) {
            $cats['CRISPRi'] = true;
            continue;
        }
        if (strpos($u, 'CRISPR') !== false && strpos($u, 'KO') !== false) {
            $cats['CRISPR-KO'] = true;
            continue;
        }
        if (strpos($u, 'KO/KD') !== false || strpos($u, 'KD') !== false || (strpos($u, ' KO') !== false) || str_starts_with($u, 'KO')) {
            $cats['KO/KD'] = true;
            continue;
        }
        if (strpos($u, 'OE') !== false) {
            $cats['OE'] = true;
        }
    }

    $keys = array_keys($cats);
    if (count($keys) === 0) return $rawText ?: 'OTHER';
    if (count($keys) === 1) return $keys[0];

    // Keep legacy meaning for classic bulk dual mode.
    if (isset($cats['KO/KD']) && isset($cats['OE']) && count($keys) === 2) return 'MIX';

    $order = ['KO/KD' => 1, 'OE' => 2, 'CRISPR-KO' => 3, 'CRISPRa' => 4, 'CRISPRi' => 5];
    usort($keys, static fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
    return implode(' + ', $keys);
}

function loadTargetGeneOptions(PDO $pdo, string $cacheFile, int $dbMtime): array
{
    if (file_exists($cacheFile) && filemtime($cacheFile) >= $dbMtime) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['options']) && is_array($cached['options'])) {
            return $cached['options'];
        }
    }

    $rawGenes = fetchDistinctValues($pdo, 'meta_assay_target_gene_name');
    $uniqueGenes = [];
    foreach ($rawGenes as $rg) {
        $parts = preg_split('/[,\|]/', (string)$rg);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $uniqueGenes[$p] = true;
            }
        }
    }
    $uniqueGenesList = array_keys($uniqueGenes);
    sort($uniqueGenesList);
    @file_put_contents($cacheFile, json_encode(['options' => $uniqueGenesList]));
    return $uniqueGenesList;
}

$page = 1;
$limit = 25;
$limitList = [10, 25, 50, 100];

if (isset($_GET['page'])) {
    $page = max(1, (int)$_GET['page']);
}

if (isset($_GET['limit'])) {
    $requestedLimit = max(1, (int)$_GET['limit']);
    if (in_array($requestedLimit, $limitList, true)) {
        $limit = $requestedLimit;
    } else {
        $limit = 25;
        // Canonicalize invalid page-size params in URL (except ajax requests).
        if (!isset($_GET['ajax']) && !isset($_GET['ajax_options'])) {
            $redirectParams = $_GET;
            $redirectParams['limit'] = (string)$limit;
            $redirectUrl = 'browse.php';
            $query = http_build_query($redirectParams);
            if ($query !== '') {
                $redirectUrl .= '?' . $query;
            }
            header('Location: ' . $redirectUrl, true, 302);
            exit;
        }
    }
}

$filterFields = [
    'id' => [],
    'dataset_id' => [],
    'external_series_accession' => [],
  'gse_accession' => [],
    'gsm_accession' => [],
    'meta_assay_type' => [],
    'meta_biosample_species' => [],
    'meta_assay_scale' => [],
    'meta_perturb_nums' => [],
    'meta_assay_target_gene_name' => [],
    'meta_assay_target_gene_type' => [],
    'meta_biosample_tissue_name' => [],
    'meta_biosample_classification_type' => [],
    'meta_biosample_description' => [],
];

foreach ($filterFields as $key => $_) {
    if (isset($_GET[$key])) {
    // For sample text inputs, normalize to array for unified cleaning.
    if (($key === 'gsm_accession' || $key === 'gse_accession' || $key === 'dataset_id') && !is_array($_GET[$key])) {
            $filterFields[$key] = [$_GET[$key]];
        } else {
            $filterFields[$key] = is_array($_GET[$key]) ? array_values($_GET[$key]) : [$_GET[$key]];
        }
    }
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Execute optimization PRAGMAs for performance
$pdo->exec('PRAGMA temp_store = MEMORY;');
$pdo->exec('PRAGMA cache_size = -10000;');
$pdo->exec('PRAGMA mmap_size = 268435456;');

$cacheFile = __DIR__ . '/perbbase_options_cache_' . md5($dbFile) . '.json';
$targetGeneCacheFile = __DIR__ . '/perbbase_target_gene_cache_' . md5($dbFile) . '.json';
$dbMtime = filemtime($dbFile);

if (isset($_GET['ajax_options']) && (string)$_GET['ajax_options'] === 'target_gene') {
    enforce_ajax_rate_limit();
    $q = trim((string)($_GET['q'] ?? ''));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limitOpt = max(1, min(200, (int)($_GET['limit'] ?? 40)));
    $all = loadTargetGeneOptions($pdo, $targetGeneCacheFile, $dbMtime);

    if ($q !== '') {
        $qLower = mb_strtolower($q, 'UTF-8');
        $all = array_values(array_filter($all, static function ($item) use ($qLower) {
            return mb_strpos(mb_strtolower((string)$item, 'UTF-8'), $qLower) !== false;
        }));
    }
    $total = count($all);
    $options = array_slice($all, $offset, $limitOpt);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'q' => $q,
        'total' => $total,
        'offset' => $offset,
        'limit' => $limitOpt,
        'has_more' => ($offset + count($options)) < $total,
        'returned' => count($options),
        'options' => $options,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (file_exists($cacheFile) && filemtime($cacheFile) >= $dbMtime) {
    $cachedData = json_decode(file_get_contents($cacheFile), true);
    if (is_array($cachedData) && isset($cachedData['optionData'], $cachedData['assayTypeMap'])) {
        $assayTypeMap = $cachedData['assayTypeMap'];
        $optionData = $cachedData['optionData'];
    }
}

if (!isset($optionData)) {
    $rawAssayTypes = fetchDistinctValues($pdo, 'meta_assay_type');
    $assayTypeMap = [];
    foreach ($rawAssayTypes as $raw) {
        $cat = getAssayTypeCategory($raw);
        if (!isset($assayTypeMap[$cat])) {
            $assayTypeMap[$cat] = [];
        }
        $assayTypeMap[$cat][] = $raw;
    }

    $optionData = [
        'id' => fetchDistinctValues($pdo, 'id'),
        'dataset_id' => fetchDistinctValues($pdo, 'dataset_id'),
        'external_series_accession' => fetchDistinctValues($pdo, 'external_series_accession'),
        'meta_assay_type' => [],
        'meta_biosample_species' => fetchDistinctValues($pdo, 'meta_biosample_species'),
        'meta_assay_scale' => fetchDistinctValues($pdo, 'meta_assay_scale'),
    'meta_perturb_nums' => ['Single-gene', 'Multi-gene'],
        // Lazy-loaded via ajax_options=target_gene.
        'meta_assay_target_gene_name' => [],
        'meta_assay_target_gene_type' => fetchDistinctValues($pdo, 'meta_assay_target_gene_type'),
        'meta_biosample_tissue_name' => fetchDistinctValues($pdo, 'meta_biosample_tissue_name'),
        'meta_biosample_classification_type' => fetchDistinctValues($pdo, 'meta_biosample_classification_type'),
        'meta_biosample_description' => fetchDistinctValues($pdo, 'meta_biosample_description'),
    ];

    @file_put_contents($cacheFile, json_encode([
        'optionData' => $optionData,
        'assayTypeMap' => $assayTypeMap
    ]));
}

// Normalize cached assay options to the current KO/KD label and rebuild visible option order.
// Also repair legacy caches that mistakenly grouped CRISPR-KO into KD/KO-KD buckets.
$crisprKoFromLegacy = [];
foreach (['KD', 'KO/KD'] as $legacyKey) {
    if (!isset($assayTypeMap[$legacyKey]) || !is_array($assayTypeMap[$legacyKey])) continue;
    $kept = [];
    foreach ($assayTypeMap[$legacyKey] as $rawType) {
        $u = strtoupper(trim((string)$rawType));
        if ($u !== '' && strpos($u, 'CRISPR') !== false && strpos($u, 'KO') !== false) {
            $crisprKoFromLegacy[] = $rawType;
            continue;
        }
        $kept[] = $rawType;
    }
    $assayTypeMap[$legacyKey] = $kept;
}

$assayTypeMap['KO/KD'] = array_values(array_unique(array_merge(
    $assayTypeMap['KO/KD'] ?? [],
    $assayTypeMap['KD'] ?? []
)));
unset($assayTypeMap['KD']);

$assayTypeMap['CRISPR-KO'] = array_values(array_unique(array_merge(
    $assayTypeMap['CRISPR-KO'] ?? [],
    $crisprKoFromLegacy
)));

$assayOrder = ['KO/KD' => 1, 'OE' => 2, 'MIX' => 3, 'CRISPR-KO' => 4, 'CRISPRa' => 5, 'CRISPRi' => 6];
$assayTypeKeys = array_keys($assayTypeMap);
usort($assayTypeKeys, static fn($a, $b) => ($assayOrder[$a] ?? 99) <=> ($assayOrder[$b] ?? 99) ?: strcmp($a, $b));
$optionData['meta_assay_type'] = $assayTypeKeys;

// Keep perturbation type options stable regardless of stale cache content.
$optionData['meta_perturb_nums'] = ['Single Gene', 'Multiple Genes'];

// Persist normalized assay map/options so legacy stale cache does not keep reappearing.
@file_put_contents($cacheFile, json_encode([
    'optionData' => $optionData,
    'assayTypeMap' => $assayTypeMap
]));

if (isset($_GET['ajax_options']) && (string)$_GET['ajax_options'] === 'facet') {
    enforce_ajax_rate_limit();
    $field = trim((string)($_GET['field'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limitOpt = max(1, min(200, (int)($_GET['limit'] ?? 40)));

    $allowedFields = [
        'meta_assay_target_gene_name',
        'meta_assay_target_gene_type',
        'meta_biosample_tissue_name',
        'meta_biosample_classification_type',
        'meta_biosample_description',
    ];

    if (!in_array($field, $allowedFields, true)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'unsupported field'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($field === 'meta_assay_target_gene_name') {
        $all = loadTargetGeneOptions($pdo, $targetGeneCacheFile, $dbMtime);
    } else {
        $all = isset($optionData[$field]) && is_array($optionData[$field]) ? $optionData[$field] : [];
    }

    if ($q !== '') {
        $qLower = mb_strtolower($q, 'UTF-8');
        $all = array_values(array_filter($all, static function ($item) use ($qLower) {
            return mb_strpos(mb_strtolower((string)$item, 'UTF-8'), $qLower) !== false;
        }));
    }

    $total = count($all);
    $options = array_slice($all, $offset, $limitOpt);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'field' => $field,
        'q' => $q,
        'total' => $total,
        'offset' => $offset,
        'limit' => $limitOpt,
        'has_more' => ($offset + count($options)) < $total,
        'returned' => count($options),
        'options' => $options,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$where = [];
$params = [];
foreach ($filterFields as $column => $values) {
    if (count($values) === 0) continue;

    if ($column === 'meta_assay_type') {
        $mappedValues = [];
        foreach ($values as $cat) {
            // Backward-compatible: old links may still pass KD
            if ($cat === 'KD') $cat = 'KO/KD';
            if (isset($assayTypeMap[$cat])) {
                $mappedValues = array_merge($mappedValues, $assayTypeMap[$cat]);
            }
        }
        if (count($mappedValues) > 0) {
            addExactInCondition($column, array_unique($mappedValues), $where, $params, $column);
        } else {
            $where[] = '1=0'; /* No Match */
        }
    } elseif ($column === 'meta_assay_target_gene_name') {
        $genes = array_values(array_unique(array_filter(array_map(static function ($v) {
            return trim((string)$v);
        }, $values), static fn($v) => $v !== '')));

        if (count($genes) > 0) {
            $genePlaceholders = [];
            $rawLikeConds = [];
            foreach ($genes as $i => $gene) {
                $key = ':tg_' . $i;
                $genePlaceholders[] = $key;
                $params[$key] = $gene;

                // Token-aware match over raw meta_assay_target_gene_name:
                // normalize delimiters "|" -> ",", remove spaces,
                // then perform case-sensitive token match as ",GENE,".
                $likeKey = ':tg_like_' . $i;
                $params[$likeKey] = ',' . str_replace(' ', '', $gene) . ',';
                $rawLikeConds[] = "INSTR((',' || REPLACE(REPLACE(COALESCE(meta_assay_target_gene_name,''), '|', ','), ' ', '') || ','), $likeKey) > 0";
            }

            $where[] = '('
                . 'id IN (SELECT perbbase_id FROM target_genes WHERE TRIM(gene_name) IN (' . implode(',', $genePlaceholders) . '))'
                . ' OR '
                . '(' . implode(' OR ', $rawLikeConds) . ')'
                . ')';
        }
    } elseif ($column === 'meta_perturb_nums') {
        $pConds = [];
        $norm = array_map(static function ($v) {
            $x = strtolower(trim((string)$v));
            $x = str_replace([' ', '_'], '', $x);
            return $x;
        }, $values);
        $normSet = array_fill_keys($norm, true);
        $hasSingle = isset($normSet['single']) || isset($normSet['singlegene']) || isset($normSet['single-gene']);
        $hasMulti = isset($normSet['multiplicity']) || isset($normSet['multigenes']) || isset($normSet['multi-gene']) || isset($normSet['multigene']);

        if ($hasSingle) {
            $pConds[] = "(is_multigene = 0)";
        }
        if ($hasMulti) {
            $pConds[] = "(is_multigene = 1)";
        }
        if (count($pConds) > 0) {
            $where[] = '(' . implode(' OR ', $pConds) . ')';
        } else {
            $where[] = '1=0';
        }
    } elseif ($column === 'dataset_id') {
      $rawDatasetStr = implode(',', $values);
      $rawDatasetStr = str_replace(['锟斤拷', '锟斤拷', ';', "\r", "\n", "\t"], ',', $rawDatasetStr);
      $rawDatasetStr = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $rawDatasetStr);
      $rawDatasetStr = str_replace(['，', '；', '|'], ',', (string)$rawDatasetStr);

      $datasetIds = array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', (string)$rawDatasetStr)), static fn($v) => $v !== ''));
      preg_match_all('/(?:HSSC|MMSC|HSBK|MMBK)\d+/i', (string)$rawDatasetStr, $datasetMatches);
      $datasetStrict = array_values(array_unique(array_filter(array_map('trim', $datasetMatches[0] ?? []), static fn($v) => $v !== '')));
      if (count($datasetStrict) > 0) {
        $datasetIds = $datasetStrict;
      }

      if (count($datasetIds) > 0) {
        $datasetConds = [];
        foreach ($datasetIds as $i => $datasetId) {
          $key = ':dataset_' . $i;
          $datasetConds[] = 'upper(dataset_id) = ' . $key;
          $params[$key] = strtoupper($datasetId);
        }
        $where[] = '(' . implode(' OR ', $datasetConds) . ')';
      }
    } elseif ($column === 'gsm_accession') {
        $rawGsmStr = implode(',', $values);
        // Clean multi-format delimiters including Chinese comma (锟斤拷), semicolons, ZERO-WIDTH spaces, and all whitespace
        $rawGsmStr = str_replace(['锟斤拷', '锟斤拷', ';', "\r", "\n", "\t"], ',', $rawGsmStr);
        $rawGsmStr = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $rawGsmStr);
        
        $gsms = array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', $rawGsmStr)), static fn($v) => $v !== ''));
        // Normalize additional delimiters and prefer strict GSM token extraction.
        $rawGsmStr = str_replace(['，', '；', '|'], ',', (string)$rawGsmStr);
        preg_match_all('/GSM\d+/i', (string)$rawGsmStr, $gsmMatches);
        $gsmStrict = array_values(array_unique(array_filter(array_map('trim', $gsmMatches[0] ?? []), static fn($v) => $v !== '')));
        if (count($gsmStrict) > 0) {
            $gsms = $gsmStrict;
        }
        
        if (count($gsms) > 0) {
            $gsmPlaceholders = [];
            foreach ($gsms as $i => $gsm) {
                $key = ':gsm_' . $i;
                $gsmPlaceholders[] = $key;
                $params[$key] = strtoupper($gsm); 
            }
            $where[] = 'id IN (SELECT perbbase_id FROM sample_accessions WHERE upper(gsm_accession) IN (' . implode(',', $gsmPlaceholders) . '))';
        }
    } elseif ($column === 'gse_accession') {
      $rawGseStr = implode(',', $values);
      $rawGseStr = str_replace(['锟斤拷', '锟斤拷', ';', "\r", "\n", "\t"], ',', $rawGseStr);
      $rawGseStr = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $rawGseStr);

      $gses = array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', $rawGseStr)), static fn($v) => $v !== ''));
      // Normalize additional delimiters and prefer strict GSE token extraction.
      $rawGseStr = str_replace(['，', '；', '|'], ',', (string)$rawGseStr);
      preg_match_all('/GSE\d+/i', (string)$rawGseStr, $gseMatches);
      $gseStrict = array_values(array_unique(array_filter(array_map('trim', $gseMatches[0] ?? []), static fn($v) => $v !== '')));
      if (count($gseStrict) > 0) {
        $gses = $gseStrict;
      }
      if (count($gses) > 0) {
        $gseConds = [];
        foreach ($gses as $i => $gse) {
          $key = ':gse_' . $i;
          $gseConds[] = 'upper(external_series_accession) LIKE ' . $key;
          $params[$key] = '%' . strtoupper($gse) . '%';
        }
        $where[] = '(' . implode(' OR ', $gseConds) . ')';
      }
    } else {
        addExactInCondition($column, $values, $where, $params, $column);
    }
}

$whereSql = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : '';

$table = DB_TABLE;
$countStmt = $pdo->prepare("SELECT COUNT(*) AS total FROM $table $whereSql");
$countStmt->execute($params);
$totalRecord = (int)$countStmt->fetchColumn();

$pageTotal = max(1, (int)ceil($totalRecord / $limit));
if ($page > $pageTotal) {
    $page = $pageTotal;
}

$offset = ($page - 1) * $limit;

$table = DB_TABLE;
$listSql = "SELECT id, dataset_id, external_series_accession, meta_assay_type, meta_biosample_species, meta_assay_scale, meta_assay_target_gene_name, meta_assay_target_gene_type, meta_biosample_tissue_name, meta_biosample_classification_type, meta_biosample_description
            FROM $table
            $whereSql
            ORDER BY
              CASE substr(dataset_id, 1, 4)
                WHEN 'HSSC' THEN 1
                WHEN 'MMSC' THEN 2
                WHEN 'HSBK' THEN 3
                WHEN 'MMBK' THEN 4
                ELSE 99
              END ASC,
              CAST(substr(dataset_id, 5) AS INTEGER) ASC,
              dataset_id ASC
            LIMIT :limit OFFSET :offset";

$listStmt = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStmt->bindValue($key, $value, PDO::PARAM_STR);
}
$listStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$rows = $listStmt->fetchAll();

$queryParams = $_GET;
$queryParams['page'] = $page;
$queryParams['limit'] = $limit;

$buildUrl = static function (int $targetPage) use ($queryParams): string {
    $params = $queryParams;
    $params['page'] = $targetPage;
    return 'browse.php?' . http_build_query($params);
};

$urlFirst = $buildUrl(1);
$urlPrev = $buildUrl(max(1, $page - 1));
$urlNext = $buildUrl(min($pageTotal, $page + 1));
$urlLast = $buildUrl($pageTotal);

$classPrev = $page <= 1 ? 'disabled' : '';
$classNext = $page >= $pageTotal ? 'disabled' : '';

$infoHtml = 'Showing ' . ($totalRecord > 0 ? ($offset + 1) : 0) . ' - ' . min($page * $limit, $totalRecord) . ' out of ' . $totalRecord . ' records';

$paginationHtml = '
<ul class="pagination pagination-sm mb-0 download-pagination">
  <li class="page-item"><a class="page-link" href="' . $urlFirst . '">First</a></li>
  <li class="page-item ' . $classPrev . '"><a class="page-link" href="' . $urlPrev . '">Previous</a></li>
  <li class="page-item ' . $classNext . '"><a class="page-link" href="' . $urlNext . '">Next</a></li>
  <li class="page-item"><a class="page-link" href="' . $urlLast . '">Last</a></li>
</ul>
';

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === '1') {
    enforce_ajax_rate_limit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'page' => $page,
        'limit' => $limit,
        'totalRecord' => $totalRecord,
        'pageTotal' => $pageTotal,
        'infoHtml' => $infoHtml,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$fieldLabels = [
    'id' => 'ID',
    'dataset_id' => 'Dataset ID',
    'external_series_accession' => 'External Accession',
    'meta_assay_type' => 'Assay Type',
    'meta_biosample_species' => 'Species',
    'meta_assay_scale' => 'Assay Scale',
    'meta_perturb_nums' => 'Number of Perturbations',
    'meta_assay_target_gene_name' => 'Perturbed Gene',
    'meta_assay_target_gene_type' => 'Target Gene Type',
    'meta_biosample_tissue_name' => 'Tissue',
    'meta_biosample_classification_type' => 'Classification Type',
    'meta_biosample_description' => 'Biosample Description',
];
?>
<!doctype html>
<html lang="zh-CN">
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
  <title>PerturbCorpus Browse</title>
  <link href="static/lib/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet" />
  <link href="static/style.css?ver=<?php echo uniqid(); ?>" rel="stylesheet" />
  <style>
    .download-table thead th,
    .download-table thead tr th {
      color: #1f2937 !important;
      text-decoration: none !important;
      cursor: default;
    }
    .filter-help-link {
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
    .filter-help-link:hover {
      color: #0f172a;
      border-color: #64748b;
      text-decoration: none;
    }
    #dataset_id_input::placeholder,
    #gsm_accession_input::placeholder,
    #gse_accession_input::placeholder {
      font-size: 0.78rem !important;
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

  <main class="layout-page">
    <div class="container-fluid px-0 pt-1">
      <div class="row g-0 align-items-start">
        <aside class="col-12 col-lg-3 col-xl-2 explore-left">
  <div class="p-2 h-100">
    <div class="download-panel h-100">
      <form action="browse.php" method="GET">
        <input type="hidden" name="limit" value="<?php echo h($limit); ?>">
        <div class="p-3 h-100 d-flex flex-column">
              <div class="d-flex align-items-center justify-content-between mb-3">
                <h1 class="h5 mb-0 fw-bold">PerturbCorpus Filter
                  <a class="filter-help-link" href="faq.php#q4-2" target="_blank" rel="noopener noreferrer" title="Open FAQ Q4.2" aria-label="Open FAQ Q4.2">?</a>
                </h1>
                <div class="d-flex gap-2">
                  <button class="btn btn-sm btn-outline-secondary" type="button" id="clear-filters-btn">Clear</button>
                </div>
              </div>

              <div id="active-filters-container" class="mb-3 d-flex flex-wrap gap-1"></div>

              <div class="accordion accordion-flush" id="test_filter">
                <div class="accordion-item border mb-2">
                  <h2 class="accordion-header">
                    <button class="accordion-button collapsed py-2 px-3 explore-acc-head" type="button" data-bs-toggle="collapse" data-bs-target="#sampleBlock" aria-expanded="false">Accession <span class="ms-1 text-muted" title="Multiple entries can be entered as comma-separated values."></span></button>
                  </h2>
                  <div id="sampleBlock" class="accordion-collapse collapse show">
                    <div class="accordion-body px-3 py-2">
                      <div class="mb-2">
                        <label class="form-label mb-1 fw-semibold text-dark small">Dataset ID</label>
                        <div class="input-group input-group-sm">
                          <input type="text" class="form-control" name="dataset_id" placeholder="e.g., HSSC000001, MMSC000123" id="dataset_id_input" value="<?php echo isset($_GET['dataset_id']) ? h(is_array($_GET['dataset_id']) ? implode(', ', $_GET['dataset_id']) : $_GET['dataset_id']) : ''; ?>">
                          <button class="btn btn-outline-primary px-2" type="button" id="dataset_commit_btn" title="Commit">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M10.97 4.97a.75.75 0 0 1 1.07 1.05l-3.99 4.99a.75.75 0 0 1-1.08.02L4.324 8.384a.75.75 0 1 1 1.06-1.06l2.094 2.093 3.473-4.425a.267.267 0 0 1 .02-.022z"/></svg>
                          </button>
                        </div>
                      </div>
                      <div class="mb-2">
                        <label class="form-label mb-1 fw-semibold text-dark small">GSM Accession</label>
                        <div class="input-group input-group-sm">
                          <input type="text" class="form-control" name="gsm_accession" placeholder="e.g., GSM3308844" id="gsm_accession_input" value="<?php echo isset($_GET['gsm_accession']) ? h(is_array($_GET['gsm_accession']) ? implode(', ', $_GET['gsm_accession']) : $_GET['gsm_accession']) : ''; ?>">
                          <button class="btn btn-outline-primary px-2" type="button" id="gsm_commit_btn" title="Commit">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M10.97 4.97a.75.75 0 0 1 1.07 1.05l-3.99 4.99a.75.75 0 0 1-1.08.02L4.324 8.384a.75.75 0 1 1 1.06-1.06l2.094 2.093 3.473-4.425a.267.267 0 0 1 .02-.022z"/></svg>
                          </button>
                        </div>
                      </div>
                      <div class="mb-2">
                        <label class="form-label mb-1 fw-semibold text-dark small">GSE Accession</label>
                        <div class="input-group input-group-sm">
                          <input type="text" class="form-control" name="gse_accession" placeholder="e.g., GSE107185" id="gse_accession_input" value="<?php echo isset($_GET['gse_accession']) ? h(is_array($_GET['gse_accession']) ? implode(', ', $_GET['gse_accession']) : $_GET['gse_accession']) : ''; ?>">
                          <button class="btn btn-outline-primary px-2" type="button" id="gse_commit_btn" title="Commit">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M10.97 4.97a.75.75 0 0 1 1.07 1.05l-3.99 4.99a.75.75 0 0 1-1.08.02L4.324 8.384a.75.75 0 1 1 1.06-1.06l2.094 2.093 3.473-4.425a.267.267 0 0 1 .02-.022z"/></svg>
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="accordion-item border mb-2">
                  <h2 class="accordion-header">
                    <button class="accordion-button collapsed py-2 px-3 explore-acc-head" type="button" data-bs-toggle="collapse" data-bs-target="#assayBlock" aria-expanded="false">Assay</button>
                  </h2>
                  <div id="assayBlock" class="accordion-collapse collapse show">
                    <div class="accordion-body px-3 py-2">
                      <?php
                        renderCheckboxSection('meta_assay_scale', $fieldLabels['meta_assay_scale'], $optionData['meta_assay_scale'], $filterFields['meta_assay_scale'], false);
                        echo '<hr class="my-2" />';
                        renderCheckboxSection('meta_assay_type', $fieldLabels['meta_assay_type'], $optionData['meta_assay_type'], $filterFields['meta_assay_type'], false);
                      ?>
                    </div>
                  </div>
                </div>

                <div class="accordion-item border mb-2">
                  <h2 class="accordion-header">
                    <button class="accordion-button collapsed py-2 px-3 explore-acc-head" type="button" data-bs-toggle="collapse" data-bs-target="#biosampleBlock" aria-expanded="false">Biosample</button>
                  </h2>
                  <div id="biosampleBlock" class="accordion-collapse collapse show">
                    <div class="accordion-body px-3 py-2">
                      <?php
                        renderCheckboxSection('meta_biosample_species', $fieldLabels['meta_biosample_species'], $optionData['meta_biosample_species'], $filterFields['meta_biosample_species'], false);
                        echo '<hr class="my-2" />';
                        renderCheckboxSection('meta_biosample_tissue_name', $fieldLabels['meta_biosample_tissue_name'], $optionData['meta_biosample_tissue_name'], $filterFields['meta_biosample_tissue_name'], true, true);
                        echo '<hr class="my-2" />';
                        renderCheckboxSection('meta_biosample_classification_type', $fieldLabels['meta_biosample_classification_type'], $optionData['meta_biosample_classification_type'], $filterFields['meta_biosample_classification_type'], true, true);
                        echo '<hr class="my-2" />';
                        renderCheckboxSection('meta_biosample_description', $fieldLabels['meta_biosample_description'], $optionData['meta_biosample_description'], $filterFields['meta_biosample_description'], true, true);
                      ?>
                    </div>
                  </div>
                </div>

                <div class="accordion-item border mb-2">
                  <h2 class="accordion-header">
                    <button class="accordion-button collapsed py-2 px-3 explore-acc-head" type="button" data-bs-toggle="collapse" data-bs-target="#perturbationBlock" aria-expanded="false">Perturbation</button>
                  </h2>
                  <div id="perturbationBlock" class="accordion-collapse collapse show">
                    <div class="accordion-body px-3 py-2">
                      <?php
                        renderCheckboxSection('meta_perturb_nums', $fieldLabels['meta_perturb_nums'], $optionData['meta_perturb_nums'], $filterFields['meta_perturb_nums'], false);
                        echo '<hr class="my-2" />';
                        renderCheckboxSection(
                          'meta_assay_target_gene_name',
                          $fieldLabels['meta_assay_target_gene_name'],
                          $optionData['meta_assay_target_gene_name'],
                          $filterFields['meta_assay_target_gene_name'],
                          true,
                          true
                        );
                      ?>
                    </div>
                  </div>
                </div>

              </div>

              <div class="mt-3 d-grid gap-2">
                <div class="text-light small">Use checkbox groups to narrow records.</div>
              </div>
            </div>
      </form>
    </div>
  </div>
</aside>

        <section class="col-12 col-lg-9 col-xl-10 explore-right">
          <div class="p-2">
            <div class="download-panel d-flex flex-column">
              <div class="download-toolbar d-flex align-items-center justify-content-between px-3 py-3 flex-wrap gap-2">
                <div class="fw-semibold" id="table-info">
                  <?php echo $infoHtml; ?>
                </div>
                <form action="browse.php" method="GET">
                  <?php foreach ($filterFields as $key => $values): ?>
                    <?php foreach ($values as $value): ?>
                      <input type="hidden" name="<?php echo h($key); ?>[]" value="<?php echo h($value); ?>">
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                  <div class="d-flex align-items-center gap-2">
                    <select id="perPageSelect" name="limit" class="form-select form-select-sm">
                      <?php foreach ($limitList as $n): ?>
                        <option value="<?php echo $n; ?>" <?php echo $n === $limit ? 'selected' : ''; ?>><?php echo $n; ?> samples per page</option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </form>
              </div>

              <div class="table-responsive download-table-wrap" style="position:relative;">
                <table class="table table-hover align-middle download-table" style="table-layout: fixed; word-wrap: break-word; min-width: 1200px;">
                  <thead>
                    <tr>
                      <th style="width: 10%;">Dataset ID</th>
                      <th style="width: 14%;">External Accession</th>
                      <th style="width: 9%;">Assay Scale</th>
                      <th style="width: 6%;">Assay Type</th>
                      <th style="width: 9%;">Perturbed Gene</th>
                      <th style="width: 10%;">Gene Type</th>
                      <th style="width: 8%;">Species</th>
                      <th style="width: 10%;">Classification</th>
                      <th style="width: 9%;">Tissue</th>
                      <th style="width: 14%;">Biosample Description</th>
                    </tr>
                  </thead>
                  <tbody id="browseTableBody">
                  <?php if (count($rows) > 0): ?>
                    <?php foreach ($rows as $row): ?>
                      <tr>
                        <td>
                          <a class="text-decoration-none fw-semibold" href="browse_detail.php?group_id=<?php echo h($row['dataset_id']); ?>" target="_blank" rel="noopener noreferrer"><?php echo h($row['dataset_id']); ?></a>
                        </td>
                        <td><?php echo renderPublicDatasetCell($row['external_series_accession']); ?></td>
                        <td><?php echo h(strtolower(trim((string)$row['meta_assay_scale'])) === 'single cell' ? 'Single Cell' : (strtolower(trim((string)$row['meta_assay_scale'])) === 'bulk' ? 'Bulk' : (string)$row['meta_assay_scale'])); ?></td>
                        <td><?php echo h(getAssayTypeCategory($row['meta_assay_type'])); ?></td>
                        <td><?php echo renderTargetGeneCell($row['meta_assay_target_gene_name']); ?></td>
                        <td><?php echo renderGeneTypeCell($row['meta_assay_target_gene_type']); ?></td>
                        <td><?php echo h($row['meta_biosample_species']); ?></td>
                        <td><?php echo h($row['meta_biosample_classification_type']); ?></td>
                        <td><?php echo h(formatTissueDisplay((string)$row['meta_biosample_tissue_name'])); ?></td>
                        <td><?php echo h($row['meta_biosample_description']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <tr>
                      <td colspan="10" class="text-center text-light py-5">No records found.</td>
                    </tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <div class="download-footerbar d-flex justify-content-between align-items-center px-3 py-3 flex-wrap gap-2">
                <div class="text-light small">Page <span id="current-page-num"><?php echo $page; ?></span></div>
                <nav aria-label="Test DB pagination" id="table-pagination-nav">
                  <?php echo $paginationHtml; ?>
                </nav>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  </main>

  <footer class="py-3 mt-auto text-center" style="background: transparent; border: none; width: 100%;">
    <div class="container-fluid px-4">
    <div class="footer-text-small-muted">&copy; <span id="year"></span> <a class="footer-link" href="https://www.zhaopage.com">Zhao Lab</a>. All rights reserved.</div>
    </div>
  </footer>

  <script src="static/lib/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
  <script>

    const applyFacetFilter = (targetId, query) => {
      const target = document.getElementById(targetId);
      if (!target) return;

      const normalized = query.trim().toLowerCase();
      const items = target.querySelectorAll('.form-check');
      const batchSize = Number(target.getAttribute('data-batch-size') || '40');
      const currentVisible = Number(target.getAttribute('data-visible-count') || String(batchSize));
      let visibleCount = 0;
      
      items.forEach((item) => {
        const text = item.getAttribute('data-search-text') || '';
        const matched = !normalized || text.includes(normalized);
        const checkedInput = item.querySelector('input.filter-checkbox:checked');
        if (!matched) {
          item.style.display = 'none';
          return;
        }
        if (checkedInput) {
          item.style.display = '';
          return;
        }
        if (visibleCount < currentVisible) {
          item.style.display = '';
          visibleCount += 1;
        } else {
          item.style.display = 'none';
        }
      });
      target.setAttribute('data-query', normalized);
      target.setAttribute('data-match-count', String(visibleCount));
    };

    const lazyTimers = {};

    const loadLazyFacetOptions = async (targetId, query = '', append = false) => {
      const target = document.getElementById(targetId);
      if (!target) return;
      const fieldName = target.getAttribute('data-field-name');
      const groupLabel = target.getAttribute('data-group-label') || '';
      const isLazy = target.getAttribute('data-lazy') === '1';
      if (!isLazy) {
        applyFacetFilter(targetId, query);
        return;
      }

      const selectedFromDom = Array.from(target.querySelectorAll('input.filter-checkbox:checked')).map((el) => String(el.value || '').trim()).filter(Boolean);
      const sp = new URLSearchParams(window.location.search || '');
      const selectedFromUrl = [
        ...sp.getAll(`${fieldName}[]`),
        ...sp.getAll(fieldName || '')
      ].map((v) => String(v || '').trim()).filter(Boolean);
      const selectedRaw = new Set([...selectedFromDom, ...selectedFromUrl]);
      const selectedLower = new Set(Array.from(selectedRaw).map((v) => v.toLowerCase()));

      const batchSize = Number(target.getAttribute('data-batch-size') || '40');
      const isSameQuery = (target.getAttribute('data-query') || '') === (query || '');
      const offset = append && isSameQuery ? Number(target.getAttribute('data-offset') || '0') : 0;
      if (!append) {
        target.innerHTML = '<div class="text-light small py-2">Loading target genes...</div>';
      }
      try {
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('ajax_options', 'facet');
        url.searchParams.set('field', fieldName || '');
        url.searchParams.set('q', query || '');
        url.searchParams.set('offset', String(offset));
        url.searchParams.set('limit', String(batchSize));
        const res = await fetch(url.toString());
        if (!res.ok) throw new Error('failed to load target gene options');
        const data = await res.json();
        const options = Array.isArray(data.options) ? data.options : [];
        if (options.length === 0 && !append) {
          target.innerHTML = '<div class="text-light small py-2">No options</div>';
          target.setAttribute('data-query', query || '');
          target.setAttribute('data-offset', '0');
          target.setAttribute('data-has-more', '0');
          return;
        }
        let renderOptions = options;
        if (!append && selectedRaw.size > 0) {
          const seenLower = new Set();
          renderOptions = [];
          Array.from(selectedRaw).forEach((v) => {
            const key = String(v).toLowerCase();
            if (!seenLower.has(key)) {
              seenLower.add(key);
              renderOptions.push(v);
            }
          });
          options.forEach((v) => {
            const key = String(v).toLowerCase();
            if (!seenLower.has(key)) {
              seenLower.add(key);
              renderOptions.push(v);
            }
          });
        }
        const listHtml = renderOptions.map((value) => {
          const safeValue = escapeHtml(value);
          const displayText = (fieldName === 'meta_biosample_tissue_name') ? formatTissueLabel(value) : String(value);
          const checked = selectedLower.has(String(value).trim().toLowerCase()) ? 'checked' : '';
          const id = `${fieldName}_${String(value).replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 80)}`;
          const searchText = escapeHtml(String(value).toLowerCase());
          return `
            <div class="form-check explore-tissue-item mb-1" data-search-text="${searchText}">
              <input class="form-check-input filter-checkbox" type="checkbox" name="${fieldName}[]" value="${safeValue}" id="${id}" data-group-label="${escapeHtml(groupLabel)}" ${checked}>
              <label class="form-check-label small" for="${id}">${escapeHtml(displayText)}</label>
            </div>
          `;
        }).join('');
        if (append) {
          target.insertAdjacentHTML('beforeend', listHtml);
        } else {
          target.innerHTML = listHtml;
        }
        const hasMore = !!(data && data.has_more);
        target.setAttribute('data-query', query || '');
        target.setAttribute('data-offset', String(offset + options.length));
        target.setAttribute('data-has-more', hasMore ? '1' : '0');
        if (typeof updateActiveFilters === 'function') {
          updateActiveFilters();
        }
      } catch (err) {
        if (!append) {
          target.innerHTML = '<div class="text-light small py-2">Failed to load options.</div>';
        }
      }
    };

    document.querySelectorAll('.facet-search-btn').forEach((button) => {
      button.addEventListener('click', () => {
        const targetId = button.getAttribute('data-facet-target');
        const wrap = button.closest('.input-group');
        const input = wrap ? wrap.querySelector('.facet-search') : null;
        if (targetId === 'meta_assay_target_gene_nameOptions' && button.dataset.mode === 'collapsed') {
          button.dataset.mode = 'ready';
          button.textContent = 'Search';
          button.title = 'Search';
          if (input) input.focus();
          // First click expands search mode and asynchronously loads options.
          loadLazyFacetOptions(targetId, input ? input.value : '');
          return;
        }
        const target = targetId ? document.getElementById(targetId) : null;
        if (target) {
          const batchSize = Number(target.getAttribute('data-batch-size') || '40');
          target.setAttribute('data-visible-count', String(batchSize));
        }
        loadLazyFacetOptions(targetId, input ? input.value : '');
      });
    });

    document.querySelectorAll('.facet-search').forEach((input) => {
      // 锟斤拷锟斤拷锟斤拷锟斤拷锟斤拷锟矫伙拷每锟斤拷一锟斤拷锟斤拷母锟斤拷锟斤拷锟矫硷拷锟劫碉拷 JS 锟斤拷锟斤拷锟斤拷锟斤拷锟睫革拷刷锟铰ｏ拷锟斤拷锟斤拷锟劫帮拷锟截筹拷(Enter)锟斤拷去锟斤拷
      input.addEventListener('input', (event) => {
        const targetId = input.getAttribute('data-facet-target');
        const target = targetId ? document.getElementById(targetId) : null;
        const isLazy = !!(target && target.getAttribute('data-lazy') === '1');
        if (isLazy) {
          const btn = document.querySelector(`.facet-search-btn[data-facet-target="${targetId}"]`);
          if (btn && btn.dataset.mode !== 'ready') {
            return;
          }
          const query = (input.value || '').trim();
          clearTimeout(lazyTimers[targetId]);
          if (query.length === 0) {
            loadLazyFacetOptions(targetId, '');
            return;
          }
          if (query.length < 2) {
            return;
          }
          lazyTimers[targetId] = setTimeout(() => {
            loadLazyFacetOptions(targetId, query);
          }, 350);
          return;
        }
        if (target) {
          const batchSize = Number(target.getAttribute('data-batch-size') || '40');
          target.setAttribute('data-visible-count', String(batchSize));
        }
        applyFacetFilter(targetId, input.value);
      });
      // 锟斤拷止锟斤拷锟斤拷锟斤拷虬椿爻锟斤拷锟斤拷锟斤拷锟斤拷锟斤拷页锟斤拷锟斤拷锟斤拷锟结交锟斤拷转
      input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          const targetId = input.getAttribute('data-facet-target');
          const target = targetId ? document.getElementById(targetId) : null;
          const isLazy = !!(target && target.getAttribute('data-lazy') === '1');
          if (isLazy) {
            const btn = document.querySelector(`.facet-search-btn[data-facet-target="${targetId}"]`);
            if (btn && btn.dataset.mode === 'collapsed') {
              btn.dataset.mode = 'ready';
              btn.textContent = 'Search';
              btn.title = 'Search';
            }
            loadLazyFacetOptions(targetId, input.value);
          }
        }
      });
    });

    const initFacetVirtualLists = () => {
      document.querySelectorAll('div[id$="Options"]').forEach((target) => {
        if (!target.id) return;
        const isLazy = target.getAttribute('data-lazy') === '1';
        const batchSize = Number(target.getAttribute('data-batch-size') || '40');
        target.setAttribute('data-visible-count', String(batchSize));
        if (!isLazy) {
          applyFacetFilter(target.id, '');
        }
      });

      document.querySelectorAll('.explore-options-scroll').forEach((scrollEl) => {
        scrollEl.addEventListener('scroll', () => {
          const nearBottom = scrollEl.scrollTop + scrollEl.clientHeight >= scrollEl.scrollHeight - 24;
          if (!nearBottom) return;
          const target = scrollEl.querySelector('div[id$="Options"]');
          if (!target || !target.id) return;
          const isLazy = target.getAttribute('data-lazy') === '1';
          if (isLazy) {
            if (target.getAttribute('data-loading') === '1') return;
            if (target.getAttribute('data-has-more') !== '1') return;
            target.setAttribute('data-loading', '1');
            const query = target.getAttribute('data-query') || '';
            loadLazyFacetOptions(target.id, query, true).finally(() => {
              target.setAttribute('data-loading', '0');
            });
            return;
          }

          const batchSize = Number(target.getAttribute('data-batch-size') || '40');
          const currentVisible = Number(target.getAttribute('data-visible-count') || String(batchSize));
          target.setAttribute('data-visible-count', String(currentVisible + batchSize));
          const query = target.getAttribute('data-query') || '';
          applyFacetFilter(target.id, query);
        });
      });
    };

    const updateActiveFilters = () => {
        const normalizeDisplayValue = (groupLabel, value) => {
          const gl = String(groupLabel || '').toLowerCase();
          const vv = String(value || '');
          if (gl === 'assay scale') {
            const v = vv.trim().toLowerCase();
          if (v === 'single cell') return 'Single Cell';
          if (v === 'bulk') return 'Bulk';
        }
        return vv;
      };
      const container = document.getElementById('active-filters-container');
      if (!container) return;
      container.innerHTML = '';

      const gsmInput = document.getElementById('gsm_accession_input');
      const datasetInput = document.getElementById('dataset_id_input');

      if (datasetInput && datasetInput.value.trim() !== '') {
        const badge = document.createElement('span');
        badge.className = 'badge bg-white text-dark border fw-normal d-inline-flex align-items-center px-2 py-1 shadow-sm';
        badge.style.maxWidth = '100%';
        badge.style.lineHeight = '1.5';

        const textSpan = document.createElement('span');
        textSpan.className = 'text-truncate text-start';
        textSpan.style.minWidth = '0';
        textSpan.textContent = 'Dataset: ' + datasetInput.value.trim();
        badge.appendChild(textSpan);

        const closeIcon = document.createElement('span');
        closeIcon.innerHTML = '&times;';
        closeIcon.className = 'ms-2 text-secondary flex-shrink-0';
        closeIcon.style.cursor = 'pointer';
        closeIcon.style.fontSize = '1.3em';
        closeIcon.style.lineHeight = '1';
        closeIcon.addEventListener('click', (e) => {
          e.preventDefault();
          datasetInput.value = '';
          typeof triggerAjaxUpdate === "function" ? triggerAjaxUpdate() : document.querySelector('.explore-left form').submit();
        });
        badge.appendChild(closeIcon);

        container.appendChild(badge);
      }

      if (gsmInput && gsmInput.value.trim() !== '') {
        const badge = document.createElement('span');
        badge.className = 'badge bg-white text-dark border fw-normal d-inline-flex align-items-center px-2 py-1 shadow-sm';
        badge.style.maxWidth = '100%';
        badge.style.lineHeight = '1.5';
        
        const textSpan = document.createElement('span');
        textSpan.className = 'text-truncate text-start';
        textSpan.style.minWidth = '0';
        textSpan.textContent = 'Sample: ' + gsmInput.value.trim();
        badge.appendChild(textSpan);
        
        const closeIcon = document.createElement('span');
        closeIcon.innerHTML = '&times;';
        closeIcon.className = 'ms-2 text-secondary flex-shrink-0';
        closeIcon.style.cursor = 'pointer';
        closeIcon.style.fontSize = '1.3em';
        closeIcon.style.lineHeight = '1';
        closeIcon.addEventListener('click', (e) => {
          e.preventDefault();
          gsmInput.value = '';
          typeof triggerAjaxUpdate === "function" ? triggerAjaxUpdate() : document.querySelector('.explore-left form').submit();
        });
        badge.appendChild(closeIcon);
        
        container.appendChild(badge);
      }

      const gseInput = document.getElementById('gse_accession_input');
      if (gseInput && gseInput.value.trim() !== '') {
        const badge = document.createElement('span');
        badge.className = 'badge bg-white text-dark border fw-normal d-inline-flex align-items-center px-2 py-1 shadow-sm';
        badge.style.maxWidth = '100%';
        badge.style.lineHeight = '1.5';

        const textSpan = document.createElement('span');
        textSpan.className = 'text-truncate text-start';
        textSpan.style.minWidth = '0';
        textSpan.textContent = 'Series: ' + gseInput.value.trim();
        badge.appendChild(textSpan);

        const closeIcon = document.createElement('span');
        closeIcon.innerHTML = '&times;';
        closeIcon.className = 'ms-2 text-secondary flex-shrink-0';
        closeIcon.style.cursor = 'pointer';
        closeIcon.style.fontSize = '1.3em';
        closeIcon.style.lineHeight = '1';
        closeIcon.addEventListener('click', (e) => {
          e.preventDefault();
          gseInput.value = '';
          typeof triggerAjaxUpdate === "function" ? triggerAjaxUpdate() : document.querySelector('.explore-left form').submit();
        });
        badge.appendChild(closeIcon);

        container.appendChild(badge);
      }

      document.querySelectorAll('.filter-checkbox').forEach((cb) => {
        if (cb.checked) {
          const groupLabel = cb.getAttribute('data-group-label');
          const value = cb.value;
          const badge = document.createElement('span');
          badge.className = 'badge bg-white text-dark border fw-normal d-inline-flex align-items-center px-2 py-1 shadow-sm';
          badge.style.maxWidth = '100%';
          badge.style.lineHeight = '1.5';
          
          const textSpan = document.createElement('span');
          textSpan.className = 'text-truncate text-start';
          textSpan.style.minWidth = '0';
          textSpan.textContent = groupLabel + ': ' + normalizeDisplayValue(groupLabel, value);
          badge.appendChild(textSpan);
          
          const closeIcon = document.createElement('span');
          closeIcon.innerHTML = '&times;';
          closeIcon.className = 'ms-2 text-secondary flex-shrink-0';
          closeIcon.style.cursor = 'pointer';
          closeIcon.style.fontSize = '1.3em';
          closeIcon.style.lineHeight = '1';
          closeIcon.addEventListener('click', (e) => {
            e.preventDefault();
            cb.checked = false;
            typeof triggerAjaxUpdate === "function" ? triggerAjaxUpdate() : document.querySelector('.explore-left form').submit();
          });
          badge.appendChild(closeIcon);
          
          container.appendChild(badge);
        }
      });
    };

    const form = document.querySelector('.explore-left form');

    function buildFilterUrl() {
      if (!form) return window.location.href;
      const formData = new FormData(form);
      const params = new URLSearchParams();
      // append checkboxes and inputs
      for (const [key, value] of formData.entries()) {
        if (value.trim() !== '' && key !== 'limit') {
          params.append(key, value);
        }
      }
      // append limit from right panel
      const perPageSelect = document.getElementById('perPageSelect');
      if (perPageSelect) {
        params.append('limit', perPageSelect.value);
      }
      return 'browse.php?' + params.toString();
    }

    async function triggerAjaxUpdate(targetUrl = null) {
      if (!targetUrl) {
        targetUrl = buildFilterUrl();
      }
      
      const wrap = document.querySelector('.download-table-wrap');
      if (wrap) wrap.style.opacity = '0.5';

      try {
        const ajaxUrl = new URL(targetUrl, window.location.origin);
        ajaxUrl.searchParams.set('ajax', '1');
        const response = await fetch(ajaxUrl.toString());
        if (!response.ok) throw new Error('Network response was not ok');
        const data = await response.json();
        if (!data || data.ok !== true) throw new Error('Invalid JSON payload');
        renderBrowseData(data, targetUrl);

        // Restore active badges
        updateActiveFilters();
        
        if (wrap) wrap.style.opacity = '1';

        // Push URL state
        window.history.pushState({path: targetUrl}, '', targetUrl);

        // Re-bind dynamic events (pagination, length select)
        bindDynamicEvents();

      } catch (err) {
        console.error('AJAX update failed. Reloading full page.', err);
        window.location.href = targetUrl;
      }
    }

    const escapeHtml = (value) => {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#39;');
    };

    const assayTypeCategory = (raw) => {
      const rawText = String(raw ?? '').trim();
      if (!rawText) return 'OTHER';

      const normalized = rawText.replace(/[|;]/g, ',').replace(/\.(?=[A-Za-z])/g, ',');
      const parts = normalized.split(',').map(v => v.trim()).filter(Boolean);
      const cats = new Set();
      parts.forEach((p) => {
        const u = p.toUpperCase();
        if (u.includes('CRISPRA')) { cats.add('CRISPRa'); return; }
        if (u.includes('CRISPRI')) { cats.add('CRISPRi'); return; }
        if (u.includes('CRISPR') && u.includes('KO')) { cats.add('CRISPR-KO'); return; }
        if (u.includes('KO/KD') || u.includes('KD') || u.startsWith('KO') || u.includes(' KO')) { cats.add('KO/KD'); return; }
        if (u.includes('OE')) { cats.add('OE'); }
      });

      const arr = Array.from(cats);
      if (arr.length === 0) return rawText || 'OTHER';
      if (arr.length === 1) return arr[0];
      if (cats.has('KO/KD') && cats.has('OE') && arr.length === 2) return 'MIX';
      const order = { 'KO/KD': 1, 'OE': 2, 'CRISPR-KO': 3, 'CRISPRa': 4, 'CRISPRi': 5 };
      arr.sort((a, b) => (order[a] ?? 99) - (order[b] ?? 99) || a.localeCompare(b));
      return arr.join(' + ');
    };

    const renderTargetGeneCellHtml = (targetGene) => {
      const raw = String(targetGene ?? '').trim();
      if (!raw) return '<span class="text-light">N/A</span>';
      const uniq = Array.from(new Set(raw.split(/[,\|]/).map(v => v.trim()).filter(Boolean))).sort();
      if (uniq.length <= 1) return escapeHtml(uniq[0] || raw);
      const title = uniq.join(', ');
      return `<div class="target-gene-box" title="${escapeHtml(title)}">${uniq.map(g => `<div class="target-gene-item">${escapeHtml(g)}</div>`).join('')}</div>`;
    };

    const renderGeneTypeCellHtml = (geneType) => {
      const raw = String(geneType ?? '').trim();
      if (!raw) return '<span class="text-light">N/A</span>';
      const uniq = Array.from(new Set(raw.split(/[,\|]/).map(v => v.trim()).filter(Boolean))).sort();
      if (uniq.length <= 1) return escapeHtml(uniq[0] || raw);
      const title = uniq.join(', ');
      return `<div class="target-gene-box" title="${escapeHtml(title)}">${uniq.map(t => `<div class="target-gene-item">${escapeHtml(t)}</div>`).join('')}</div>`;
    };

    const formatTissueLabel = (value) => {
      const raw = String(value ?? '').trim();
      if (!raw) return raw;
      return raw.replace(/[A-Za-z]+/g, (w) => (
        w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()
      ));
    };

    const renderPublicDatasetCellHtml = (publicDataset) => {
      const raw = String(publicDataset ?? '').trim();
      if (!raw) return '<span class="text-light">N/A</span>';
      const parts = raw.split('|').map(v => v.trim()).filter(Boolean);
      const links = parts.map((part) => {
        const m = part.match(/^(GSE\d+)/i);
        if (m) {
          const acc = m[1].toUpperCase();
          const href = `https://www.ncbi.nlm.nih.gov/geo/query/acc.cgi?acc=${encodeURIComponent(acc)}`;
          return `<a class="text-decoration-none fw-semibold" href="${href}" target="_blank" rel="noopener noreferrer">${escapeHtml(part)}</a>`;
        }
        return escapeHtml(part);
      });
      return links.join('<span class="text-muted mx-1">|</span>');
    };

    const buildPageUrl = (baseUrl, pageNo) => {
      const url = new URL(baseUrl, window.location.origin);
      url.searchParams.set('page', String(pageNo));
      url.searchParams.delete('ajax');
      return `${url.pathname}?${url.searchParams.toString()}`;
    };

    const renderBrowseData = (data, targetUrl) => {
      const rows = Array.isArray(data.rows) ? data.rows : [];
      const page = Number(data.page) || 1;
      const pageTotal = Math.max(1, Number(data.pageTotal) || 1);

      const infoEl = document.getElementById('table-info');
      if (infoEl) infoEl.innerHTML = data.infoHtml || '';

      const tbody = document.getElementById('browseTableBody');
      if (tbody) {
        if (rows.length === 0) {
          tbody.innerHTML = '<tr><td colspan="10" class="text-center text-light py-5">No records found.</td></tr>';
        } else {
          tbody.innerHTML = rows.map((row) => `
            <tr>
              <td><a class="text-decoration-none fw-semibold" href="browse_detail.php?group_id=${encodeURIComponent(row.dataset_id ?? '')}" target="_blank" rel="noopener noreferrer">${escapeHtml(row.dataset_id ?? '')}</a></td>
              <td>${renderPublicDatasetCellHtml(row.external_series_accession)}</td>
              <td>${escapeHtml((() => { const s=String(row.meta_assay_scale ?? '').trim().toLowerCase(); if (s==='single cell') return 'Single Cell'; if (s==='bulk') return 'Bulk'; return String(row.meta_assay_scale ?? ''); })())}</td>
              <td>${escapeHtml(assayTypeCategory(row.meta_assay_type))}</td>
              <td>${renderTargetGeneCellHtml(row.meta_assay_target_gene_name)}</td>
              <td>${renderGeneTypeCellHtml(row.meta_assay_target_gene_type)}</td>
              <td>${escapeHtml(row.meta_biosample_species ?? '')}</td>
              <td>${escapeHtml(row.meta_biosample_classification_type ?? '')}</td>
              <td>${escapeHtml(formatTissueLabel(row.meta_biosample_tissue_name ?? ''))}</td>
              <td>${escapeHtml(row.meta_biosample_description ?? '')}</td>
            </tr>
          `).join('');
        }
      }

      const pageNum = document.getElementById('current-page-num');
      if (pageNum) pageNum.textContent = String(page);

      const nav = document.getElementById('table-pagination-nav');
      if (nav) {
        const prevDisabled = page <= 1 ? 'disabled' : '';
        const nextDisabled = page >= pageTotal ? 'disabled' : '';
        nav.innerHTML = `
          <ul class="pagination pagination-sm mb-0 download-pagination">
            <li class="page-item"><a class="page-link" href="${buildPageUrl(targetUrl, 1)}">First</a></li>
            <li class="page-item ${prevDisabled}"><a class="page-link" href="${buildPageUrl(targetUrl, Math.max(1, page - 1))}">Previous</a></li>
            <li class="page-item ${nextDisabled}"><a class="page-link" href="${buildPageUrl(targetUrl, Math.min(pageTotal, page + 1))}">Next</a></li>
            <li class="page-item"><a class="page-link" href="${buildPageUrl(targetUrl, pageTotal)}">Last</a></li>
          </ul>
        `;
      }
    };

    function bindDynamicEvents() {
      document.querySelectorAll('.download-pagination a').forEach(a => {
        a.addEventListener('click', function(e) {
          e.preventDefault();
          triggerAjaxUpdate(this.href);
        });
      });

      const perPageSelect = document.getElementById('perPageSelect');
      if (perPageSelect && !perPageSelect.dataset.boundAjaxChange) {
        perPageSelect.dataset.boundAjaxChange = '1';
        perPageSelect.addEventListener('change', function(e) {
          const url = new URL(window.location.href);
          url.searchParams.set('limit', this.value);
          url.searchParams.set('page', 1);
          triggerAjaxUpdate(url.toString());
        });
      }
    }

    if (!window.__browseCheckboxDelegated) {
      window.__browseCheckboxDelegated = true;
      document.addEventListener('change', (e) => {
        if (e.target && e.target.classList && e.target.classList.contains('filter-checkbox')) {
          triggerAjaxUpdate();
        }
      });
    }

    const datasetCommitBtn = document.getElementById('dataset_commit_btn');
    if (datasetCommitBtn) {
      datasetCommitBtn.addEventListener('click', () => triggerAjaxUpdate());
    }

    const gsmCommitBtn = document.getElementById('gsm_commit_btn');
    if (gsmCommitBtn) {
      gsmCommitBtn.addEventListener('click', () => triggerAjaxUpdate());
    }

    const gseCommitBtn = document.getElementById('gse_commit_btn');
    if (gseCommitBtn) {
      gseCommitBtn.addEventListener('click', () => triggerAjaxUpdate());
    }

    const datasetInputEl = document.getElementById('dataset_id_input');
    if (datasetInputEl) {
      datasetInputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          triggerAjaxUpdate();
        }
      });
    }

    const gsmInputEl = document.getElementById('gsm_accession_input');
    if (gsmInputEl) {
      gsmInputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          triggerAjaxUpdate();
        }
      });
    }

    const gseInputEl = document.getElementById('gse_accession_input');
    if (gseInputEl) {
      gseInputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          triggerAjaxUpdate();
        }
      });
    }

    window.addEventListener('popstate', (e) => {
      window.location.reload(); 
    });

    const clearFiltersBtn = document.getElementById('clear-filters-btn');
    if (clearFiltersBtn) {
      clearFiltersBtn.addEventListener('click', () => {
        window.location.href = 'browse.php';
      });
    }

    function applyPrefillFiltersFromStatistics() {
      let payload = null;
      try {
        const raw = sessionStorage.getItem('browse_prefill_filters');
        if (!raw) return false;
        payload = JSON.parse(raw);
      } catch (e) {
        return false;
      }
      if (!payload || typeof payload !== 'object' || !payload.filters || typeof payload.filters !== 'object') {
        return false;
      }

      // Clear one-time payload to avoid repeated auto-apply.
      try { sessionStorage.removeItem('browse_prefill_filters'); } catch (e) {}

      const entries = Object.entries(payload.filters);
      if (!entries.length) return false;

      entries.forEach(([field, values]) => {
        if (!Array.isArray(values)) return;
        const wanted = new Set(values.map(v => String(v).trim()).filter(Boolean));
        if (!wanted.size) return;
        const boxes = document.querySelectorAll(`input.filter-checkbox[name="${field}[]"]`);
        if (!boxes.length) return;
        boxes.forEach((box) => {
          box.checked = wanted.has(String(box.value).trim());
        });
      });
      return true;
    }

    const prefillApplied = applyPrefillFiltersFromStatistics();
    if (prefillApplied) {
      triggerAjaxUpdate();
    }

    initFacetVirtualLists();
    document.querySelectorAll('.facet-search-btn').forEach((btn) => {
      const targetId = btn.getAttribute('data-facet-target') || '';
      const target = targetId ? document.getElementById(targetId) : null;
      if (target && target.getAttribute('data-lazy') === '1') {
        btn.dataset.mode = 'ready';
        btn.textContent = 'Search';
        btn.title = 'Search';
      }
    });
    document.querySelectorAll('div[id$="Options"][data-lazy="1"]').forEach((target) => {
      if (!target.id) return;
      loadLazyFacetOptions(target.id, '');
    });

    updateActiveFilters();
    bindDynamicEvents();
  </script>
<script>document.getElementById('year').textContent = new Date().getFullYear();</script>
</body>
</html>
