<?php
require_once __DIR__ . '/config.php';

$dbFile = DB_META_FILE;
$bulkDbFile = DB_BULK_DEG_FILE;
$bulkExprDbFile = DB_GENEEXP_FILE;
$gtexTcgaDbFile = DB_GTEX_TCGA_FILE;
$scPerturbStatesDbFile = DB_SC_PERTURB_STATES_FILE;
$scQcSummaryDbFile = DB_SC_QC_SUMMARY_FILE;
$bulkGoKeggDbFile = __DIR__ . '/sqlite3/bulk_go_kegg.db';
$scGoKeggDbFile = __DIR__ . '/sqlite3/single_cell_go_kegg.db';
$bulkGseaDbFile = __DIR__ . '/sqlite3/bulk_gsea.db';
$scGseaDbFile = __DIR__ . '/sqlite3/single_cell_gsea.db';
$scUmapDbFile = __DIR__ . '/sqlite3/single_cell_umap.db';
$scPerturbEnrichmentDbFile = __DIR__ . '/sqlite3/single_cell_pertubation_enrichment.db';
$scPerturbEnrichmentDbFileAlt = __DIR__ . '/sqlite3/single_cell_perturbation_enrichment.db';
$scDegDbFile = __DIR__ . '/sqlite3/single_cell_deg.db';

if (!file_exists($dbFile)) {
    die('Database not found: ' . basename($dbFile));
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function starts_with_prefix(string $value, string $prefix): bool
{
    return strncmp($value, $prefix, strlen($prefix)) === 0;
}

function safe_json_encode($value, int $flags = 0, int $depth = 512): string
{
    $json = json_encode($value, $flags | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE, $depth);
    return $json === false ? 'null' : $json;
}

function formatFieldValue(string $key, $value): string
{
    $val = h($value);
    if ($key === 'external_sample_control_accession' || $key === 'external_sample_treatment_accession' || $key === 'single_cell_pertubation_external_sample_accession') {
        return preg_replace('/(GSM\d+)/i', '<a href="https://www.ncbi.nlm.nih.gov/geo/query/acc.cgi?acc=$1" target="_blank" class="text-decoration-underline">$1</a>', $val);
    } elseif ($key === 'external_series_accession') {
        return preg_replace('/(GSE\d+)/i', '<a href="https://www.ncbi.nlm.nih.gov/geo/query/acc.cgi?acc=$1" target="_blank" class="text-decoration-underline">$1</a>', $val);
    }
    return $val;
}

function extractSampleIds($value): array
{
    preg_match_all('/GSM\d+/i', (string)$value, $matches);
    $ids = array_map('strtoupper', $matches[0] ?? []);
    $ids = array_values(array_unique(array_filter($ids)));
    sort($ids);
    return $ids;
}

function hasManySamples($value, int $limit = 3): bool
{
  return count(extractSampleIds($value)) > $limit;
}

function splitCommaSeparatedValues($value): array
{
    $parts = array_map('trim', explode(',', (string)$value));
    return array_values(array_filter($parts, static fn($item) => $item !== ''));
}

function parseExpressionList($value): array
{
  if ($value === null) {
    return [];
  }
  $items = array_map('trim', explode(',', (string)$value));
  $values = [];
  foreach ($items as $item) {
    if ($item === '') {
      continue;
    }
    if (is_numeric($item)) {
      $values[] = (float)$item;
    }
  }
  return $values;
}

function parseKeyValueExpression($value): array
{
  $out = [];
  if ($value === null || $value === '') {
    return $out;
  }
  $pairs = array_map('trim', explode(',', (string)$value));
  foreach ($pairs as $pair) {
    if ($pair === '') {
      continue;
    }
    $parts = array_map('trim', explode(':', $pair, 2));
    if (count($parts) !== 2) {
      continue;
    }
    $k = $parts[0];
    $v = $parts[1];
    if ($k === '' || !is_numeric($v)) {
      continue;
    }
    $out[$k] = (float)$v;
  }
  return $out;
}

function parsePipeSeparatedNumbers($value): array
{
  if ($value === null || $value === '') {
    return [];
  }
  $items = array_map('trim', explode('|', (string)$value));
  $values = [];
  foreach ($items as $item) {
    if ($item === '' || !is_numeric($item)) {
      continue;
    }
    $values[] = (float)$item;
  }
  return $values;
}

function parseSingleCellQcSummary($value): array
{
  $result = [
    'n_genes_by_counts' => [],
    'total_counts' => [],
    'pct_counts_mt' => []
  ];
  if ($value === null || trim((string)$value) === '') {
    return $result;
  }

  if (preg_match_all('/(?:^|;)(n_genes_by_counts|total_counts|pct_counts_mt):([^;]*)/i', (string)$value, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $m) {
      $key = strtolower(trim((string)($m[1] ?? '')));
      $vals = (string)($m[2] ?? '');
      if (array_key_exists($key, $result)) {
        $result[$key] = parsePipeSeparatedNumbers($vals);
      }
    }
  }
  return $result;
}

function fetchSingleCellQcDist(PDO $pdo, string $datasetId): array
{
  $stmt = $pdo->prepare('SELECT qc_value FROM qc_summary WHERE dataset_id = :dataset_id LIMIT 1');
  $stmt->bindValue(':dataset_id', $datasetId, PDO::PARAM_STR);
  $stmt->execute();
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row || !isset($row['qc_value'])) {
    return [
      'n_genes_by_counts' => [],
      'total_counts' => [],
      'pct_counts_mt' => []
    ];
  }
  return parseSingleCellQcSummary($row['qc_value']);
}

function fetchSingleCellUmap(PDO $pdo, string $datasetId, int $maxRows = 200000): array
{
  if ($datasetId === '') {
    return [];
  }

  $sql = 'SELECT u1, u2, c, g, m FROM sc_cell WHERE dataset_id = :dataset_id LIMIT :max_rows';
  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':dataset_id', $datasetId, PDO::PARAM_STR);
  $stmt->bindValue(':max_rows', max(1000, $maxRows), PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) {
    return [];
  }

  $points = [];
  foreach ($rows as $row) {
    $u1 = isset($row['u1']) && is_numeric($row['u1']) ? (int)$row['u1'] : 0;
    $u2 = isset($row['u2']) && is_numeric($row['u2']) ? (int)$row['u2'] : 0;
    $cluster = isset($row['c']) && is_numeric($row['c']) ? (int)$row['c'] : -1;
    $gene = trim((string)($row['g'] ?? ''));
    $mixscape = isset($row['m']) && is_numeric($row['m']) ? (int)$row['m'] : 0;
    $points[] = [$u1, $u2, $cluster, $gene, $mixscape];
  }

  return $points;
}

function fetchSingleCellPerturbEnrichment(PDO $pdo, string $datasetId): array
{
  if ($datasetId === '') {
    return [];
  }

  static $resolvedTable = null;
  if ($resolvedTable === null) {
    $candidateTables = [
      'sc_perturb_cluster_stats',
      'perturbation_enrichment',
      'pertgene_enrichment_plot_result'
    ];
    foreach ($candidateTables as $tableName) {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = :name");
      $stmt->bindValue(':name', $tableName, PDO::PARAM_STR);
      $stmt->execute();
      if ((int)$stmt->fetchColumn() > 0) {
        $resolvedTable = $tableName;
        break;
      }
    }
    if ($resolvedTable === null) {
      $allTables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
      foreach ($allTables as $tableName) {
        $pragmaRows = $pdo->query('PRAGMA table_info(' . $tableName . ')')->fetchAll(PDO::FETCH_ASSOC);
        $cols = [];
        foreach ($pragmaRows as $pr) {
          $cols[strtolower((string)($pr['name'] ?? ''))] = true;
        }
        $needed = ['dataset_id', 'perturbation_gene', 'cluster', 'cluster_cell_fraction', 'log2or', 'fdr'];
        $ok = true;
        foreach ($needed as $need) {
          if (!isset($cols[$need])) {
            $ok = false;
            break;
          }
        }
        if ($ok) {
          $resolvedTable = (string)$tableName;
          break;
        }
      }
    }
  }

  if ($resolvedTable === null) {
    return [];
  }

  $sql = 'SELECT perturbation_gene, cluster, cell_count, cluster_cell_fraction, log2or, fdr
          FROM ' . $resolvedTable . '
          WHERE dataset_id = :dataset_id';
  $stmt = $pdo->prepare($sql);
  $stmt->bindValue(':dataset_id', $datasetId, PDO::PARAM_STR);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) {
    return [];
  }

  $out = [];
  foreach ($rows as $row) {
    $gene = trim((string)($row['perturbation_gene'] ?? ''));
    if ($gene === '') {
      continue;
    }
    $cluster = isset($row['cluster']) && is_numeric($row['cluster']) ? (int)$row['cluster'] : 0;
    $cellCount = isset($row['cell_count']) && is_numeric($row['cell_count']) ? (int)$row['cell_count'] : 0;
    $ratio = isset($row['cluster_cell_fraction']) && is_numeric($row['cluster_cell_fraction']) ? (float)$row['cluster_cell_fraction'] : 0.0;
    $log2or = isset($row['log2or']) && is_numeric($row['log2or']) ? (float)$row['log2or'] : 0.0;
    $fdr = isset($row['fdr']) && is_numeric($row['fdr']) ? (float)$row['fdr'] : 1.0;
    $out[] = [$gene, $cluster, $cellCount, $ratio, $log2or, $fdr];
  }
  return $out;
}

function parseGeneFreqPairs($value): array
{
  if ($value === null || $value === '') {
    return [];
  }
  $pairs = array_filter(array_map('trim', explode('|', (string)$value)), static fn($item) => $item !== '');
  $items = [];
  foreach ($pairs as $pair) {
    $parts = array_map('trim', explode(':', $pair, 2));
    if (count($parts) !== 2) {
      continue;
    }
    $gene = $parts[0];
    $count = $parts[1];
    if ($gene === '' || !is_numeric($count)) {
      continue;
    }
    $items[] = ['gene' => $gene, 'count' => (int)$count];
  }
  return $items;
}

function containsNaToken(string $value): bool
{
  $text = trim($value);
  if ($text === '') {
    return false;
  }
  if (strcasecmp($text, 'NA') === 0) {
    return true;
  }
  $parts = array_map('trim', explode('|', $text));
  foreach ($parts as $part) {
    if ($part !== '' && strcasecmp($part, 'NA') === 0) {
      return true;
    }
  }
  return false;
}

function parsePerturbInfoToGeneFreq($value): array
{
  if ($value === null || trim((string)$value) === '') {
    return [];
  }
  $comboCounts = [];
  $records = array_filter(array_map('trim', explode(',', (string)$value)), static fn($item) => $item !== '');
  foreach ($records as $record) {
    $parts = explode(':', $record, 2);
    if (count($parts) !== 2) {
      continue;
    }
    $combo = trim($parts[0]);
    $countStr = trim($parts[1]);
    if ($combo === '' || !is_numeric($countStr)) {
      continue;
    }
    $count = (int)$countStr;
    if ($count <= 0) {
      continue;
    }
    if ($combo === '' || containsNaToken($combo)) {
      continue;
    }
    // Keep perturbation combination as-is, aggregate same combination counts.
    $comboCounts[$combo] = ($comboCounts[$combo] ?? 0) + $count;
  }
  if (empty($comboCounts)) {
    return [];
  }
  arsort($comboCounts, SORT_NUMERIC);
  $items = [];
  foreach ($comboCounts as $combo => $count) {
    $items[] = ['gene' => (string)$combo, 'count' => (int)$count];
  }
  return $items;
}


function parseControlKoNpCounts($value): array
{
  $parts = array_map('trim', explode(',', (string)$value));
  if (count($parts) < 3) {
    return [0, 0, 0];
  }
  $a = is_numeric($parts[0]) ? (int)$parts[0] : 0;
  $b = is_numeric($parts[1]) ? (int)$parts[1] : 0;
  $c = is_numeric($parts[2]) ? (int)$parts[2] : 0;
  return [$a, $b, $c];
}

function parsePerturbRatios($value): array
{
  if ($value === null || trim((string)$value) === '') {
    return [];
  }
  $items = [];
  $pairs = array_filter(array_map('trim', explode(',', (string)$value)), static fn($x) => $x !== '');
  foreach ($pairs as $pair) {
    $parts = explode(':', $pair, 2);
    if (count($parts) !== 2) {
      continue;
    }
    $gene = trim($parts[0]);
    $ratioStr = trim($parts[1]);
    if ($gene === '' || !is_numeric($ratioStr)) {
      continue;
    }
    $sp = (float)$ratioStr;
    if ($sp < 0) {
      $sp = 0.0;
    } elseif ($sp > 1) {
      $sp = 1.0;
    }
    $np = 1.0 - $sp;
    $items[] = [
      'gene' => $gene,
      'sp' => $sp,
      'np' => $np
    ];
  }
  return $items;
}

function parseSingleCellGeneCountPairs($geneNamesRaw, $cellCountsRaw): array
{
  $geneNames = splitCommaSeparatedValues($geneNamesRaw);
  $cellCounts = splitCommaSeparatedValues($cellCountsRaw);
  $maxCount = max(count($geneNames), count($cellCounts), 0);
  if ($maxCount <= 0) {
    return [];
  }

  $items = [];
  for ($index = 0; $index < $maxCount; $index++) {
    $geneName = trim((string)($geneNames[$index] ?? ''));
    if ($geneName === '' || containsNaToken($geneName)) {
      continue;
    }
    $countStr = trim((string)($cellCounts[$index] ?? ''));
    if ($countStr === '' || !is_numeric($countStr)) {
      continue;
    }
    $count = (int)$countStr;
    if ($count <= 0) {
      continue;
    }
    $items[] = ['gene' => $geneName, 'count' => $count];
  }

  return $items;
}

function fetchSingleCellGeneFreq(PDO $pdo, string $table, string $datasetId): array
{
  if ($datasetId === '') {
    return [];
  }

  $stmt = $pdo->prepare("SELECT meta_assay_target_gene_name, single_cell_assay_target_gene_cellcount FROM $table WHERE dataset_id = :dataset_id ORDER BY id ASC");
  $stmt->bindValue(':dataset_id', $datasetId, PDO::PARAM_STR);
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) {
    return [];
  }

  $geneCounts = [];
  foreach ($rows as $row) {
    $pairs = parseSingleCellGeneCountPairs(
      $row['meta_assay_target_gene_name'] ?? '',
      $row['single_cell_assay_target_gene_cellcount'] ?? ''
    );
    foreach ($pairs as $pair) {
      $gene = (string)($pair['gene'] ?? '');
      $count = (int)($pair['count'] ?? 0);
      if ($gene === '' || $count <= 0) {
        continue;
      }
      $geneCounts[$gene] = ($geneCounts[$gene] ?? 0) + $count;
    }
  }

  if (empty($geneCounts)) {
    return [];
  }

  arsort($geneCounts, SORT_NUMERIC);
  $out = [];
  foreach ($geneCounts as $gene => $count) {
    $out[] = ['gene' => $gene, 'count' => (int)$count];
  }
  return $out;
}

function fetchSingleCellDegEligibleGenes(PDO $pdo, string $table, string $datasetId, int $minCellCount = 10): array
{
  $rows = fetchSingleCellGeneFreq($pdo, $table, $datasetId);
  if (!$rows) {
    return [];
  }
  $out = [];
  foreach ($rows as $item) {
    $gene = trim((string)($item['gene'] ?? ''));
    $count = (int)($item['count'] ?? 0);
    if ($gene === '' || containsNaToken($gene)) {
      continue;
    }
    if ($count < $minCellCount) {
      continue;
    }
    $out[] = $gene;
  }
  return array_values(array_unique($out));
}

function fetchSingleCellPerturbStates(PDO $pdo, string $datasetId): array
{
  $stmt = $pdo->prepare('SELECT control_KO_NP, perturb_ratio FROM Perturbation_States WHERE dataset_id = :dataset_id LIMIT 1');
  $stmt->bindValue(':dataset_id', $datasetId, PDO::PARAM_STR);
  $stmt->execute();
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return ['pie' => [], 'bar' => [], 'has' => false];
  }
  [$controlCnt, $koCnt, $npCnt] = parseControlKoNpCounts($row['control_KO_NP'] ?? '');
  $pie = [
    ['name' => 'Control', 'value' => $controlCnt],
    ['name' => 'KO', 'value' => $koCnt],
    ['name' => 'NP', 'value' => $npCnt],
  ];
  $bar = parsePerturbRatios($row['perturb_ratio'] ?? '');
  return [
    'pie' => $pie,
    'bar' => $bar,
    'has' => (count($bar) > 0) || (($controlCnt + $koCnt + $npCnt) > 0)
  ];
}

function formatKpiValue($value): string
{
  if ($value === null || $value === '') {
    return '-';
  }
  if (is_numeric($value)) {
    $num = (float)$value;
    $decimals = (floor($num) == $num) ? 0 : 2;
    return number_format($num, $decimals, '.', ',');
  }
  return h($value);
}

function sqlRealExpr(string $column): string
{
  // Handle empty string / literal "NULL" saved as TEXT in SQLite.
  return "CAST(NULLIF(NULLIF(TRIM({$column}), ''), 'NULL') AS REAL)";
}

function parseNullableFloat($value): ?float
{
  if ($value === null) {
    return null;
  }
  $text = trim((string)$value);
  if ($text === '' || strcasecmp($text, 'NULL') === 0) {
    return null;
  }
  return is_numeric($text) ? (float)$text : null;
}

function parseSampleExpressionMap($value): array
{
  if ($value === null || $value === '') {
    return [];
  }
  $parts = array_map('trim', explode(',', (string)$value));
  $out = [];
  foreach ($parts as $part) {
    if ($part === '' || strpos($part, ':') === false) {
      continue;
    }
    [$sampleId, $numStr] = array_map('trim', explode(':', $part, 2));
    if ($sampleId === '' || !is_numeric($numStr)) {
      continue;
    }
    $out[$sampleId] = (float)$numStr;
  }
  return $out;
}

function parseValueStrMetrics($value): array
{
  $kv = parseKeyValueExpression($value);
  if (empty($kv)) {
    return [];
  }
  $out = [];
  foreach ($kv as $k => $v) {
    $out[strtolower((string)$k)] = (float)$v;
  }
  return $out;
}

function loadBulkMethod(PDO $pdo, string $datasetId): ?string
{
  // Preferred: explicit mapping table.
  try {
    $stmt = $pdo->prepare('SELECT method FROM dataset_method_map WHERE dataset_id = :dataset_id');
    $stmt->bindValue(':dataset_id', $datasetId, PDO::PARAM_STR);
    $stmt->execute();
    $value = $stmt->fetchColumn();
    if ($value !== false && $value !== null && trim((string)$value) !== '') {
      return (string)$value;
    }
  } catch (Throwable $e) {
    // Fallback below.
  }

  // Fallback: infer from value_str keys or dataset prefix.
  try {
    $stmt = $pdo->prepare('SELECT value_str FROM bulk_deg_result WHERE dataset_id = :dataset_id LIMIT 200');
    $stmt->bindValue(':dataset_id', $datasetId, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasNoi = false;
    $hasDeseq = false;
    foreach ($rows as $row) {
      $valueStr = strtolower((string)($row['value_str'] ?? ''));
      if (strpos($valueStr, 'd:') !== false || strpos($valueStr, 'prob:') !== false) {
        $hasNoi = true;
      }
      if (strpos($valueStr, 'lfcse:') !== false || strpos($valueStr, 'stat:') !== false) {
        $hasDeseq = true;
      }
    }
    if ($hasNoi && !$hasDeseq) {
      return 'NOISeq';
    }
    if ($hasDeseq && !$hasNoi) {
      return 'DESeq2';
    }
  } catch (Throwable $e) {
    // Fall through.
  }
  if (starts_with_prefix($datasetId, 'HSBK') || starts_with_prefix($datasetId, 'MSBK')) {
    return 'DEG';
  }
  return null;
}

function buildDegColumns(?string $method): array
{
  if ($method !== null && strcasecmp($method, 'NOISeq') === 0) {
    return [
      ['key' => 'gene_name', 'label' => 'Gene Name', 'type' => 'text', 'sortable' => false],
      ['key' => 'ensembl_id', 'label' => 'Ensembl ID', 'type' => 'text', 'sortable' => false],
      ['key' => 'base_mean', 'label' => 'baseMean', 'type' => 'number', 'sortable' => true],
      ['key' => 'log2fc', 'label' => 'log2FC', 'type' => 'number', 'sortable' => true],
      ['key' => 'd', 'label' => 'D', 'type' => 'number', 'sortable' => true],
      ['key' => 'prob', 'label' => 'prob', 'type' => 'number', 'sortable' => true]
    ];
  }
  return [
    ['key' => 'gene_name', 'label' => 'Gene Name', 'type' => 'text', 'sortable' => false],
    ['key' => 'ensembl_id', 'label' => 'Ensembl ID', 'type' => 'text', 'sortable' => false],
    ['key' => 'base_mean', 'label' => 'baseMean', 'type' => 'number', 'sortable' => true],
    ['key' => 'log2fc', 'label' => 'log2FC', 'type' => 'number', 'sortable' => true],
    ['key' => 'pvalue', 'label' => 'pvalue', 'type' => 'number', 'sortable' => true],
    ['key' => 'padj', 'label' => 'padj', 'type' => 'number', 'sortable' => true]
  ];
}

function buildSingleCellDegColumns(): array
{
  return [
    ['key' => 'gene_name', 'label' => 'Gene Name', 'type' => 'text', 'sortable' => true],
    ['key' => 'ensembl_id', 'label' => 'Ensembl ID', 'type' => 'text', 'sortable' => true],
    ['key' => 'log2fc', 'label' => 'log2FC', 'type' => 'number', 'sortable' => true],
    ['key' => 'pvalue', 'label' => 'pvalue', 'type' => 'number', 'sortable' => true],
    ['key' => 'padj', 'label' => 'padj', 'type' => 'number', 'sortable' => true],
    ['key' => 'score', 'label' => 'score', 'type' => 'number', 'sortable' => true]
  ];
}

function loadDemoEnrichmentRows(PDO $pdo, string $tableName, ?string $ontology = null, int $limit = 20): array
{
    $threshold = -log(0.05, 10);
    $where = 'WHERE "-log10(p.adjust)" >= :threshold';
    if ($ontology !== null) {
        $where .= ' AND ONTOLOGY = :ontology';
    }

    $selectPrefix = $ontology !== null ? 'ONTOLOGY, ' : '';
    $sql = 'SELECT ' . $selectPrefix . 'Description, FoldEnrichment, "Count" AS count_value, "-log10(p.adjust)" AS score FROM ' . $tableName . ' ' . $where . ' ORDER BY score DESC, count_value DESC LIMIT :limit';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':threshold', $threshold, PDO::PARAM_STR);
    if ($ontology !== null) {
        $stmt->bindValue(':ontology', $ontology, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'ontology' => (string)($row['ONTOLOGY'] ?? $ontology ?? ''),
            'description' => (string)($row['Description'] ?? ''),
            'fold_enrichment' => (float)($row['FoldEnrichment'] ?? 0),
            'count' => (int)($row['count_value'] ?? 0),
            'score' => (float)($row['score'] ?? 0),
        ];
    }
    return $rows;
}

function sqliteTableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
        $stmt->bindValue(':name', $tableName, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function sqliteTableHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . $tableName . ')');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (strcasecmp((string)($row['name'] ?? ''), $columnName) === 0) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

function pickBestSingleCellPerturbation(
    ?PDO $scDegPdo,
    ?PDO $scGoKeggPdo,
    ?PDO $scGseaPdo,
    string $datasetId,
    ?array $allowedPerturbations = null
): array {
    $result = ['selected' => '', 'perturbations' => []];
    if (!$scDegPdo || $datasetId === '') {
        return $result;
    }

    try {
        $stmt = $scDegPdo->prepare(
            'SELECT p.perturbation_gene
             FROM sc_deg_perturb p
             JOIN sc_deg_dataset d ON d.dataset_pk = p.dataset_fk
             WHERE d.dataset_id = :dataset_id
             ORDER BY p.perturbation_gene ASC'
        );
        $stmt->bindValue(':dataset_id', $datasetId, PDO::PARAM_STR);
        $stmt->execute();
        $perturbations = array_values(array_filter(
            array_map(static fn($x) => trim((string)$x), $stmt->fetchAll(PDO::FETCH_COLUMN)),
            static fn($x) => $x !== '' && !containsNaToken((string)$x)
        ));
        if (count($perturbations) === 0) {
            return $result;
        }
        if (is_array($allowedPerturbations)) {
            $allowedSet = [];
            foreach ($allowedPerturbations as $g) {
                $v = trim((string)$g);
                if ($v !== '') {
                    $allowedSet[$v] = true;
                }
            }
            if ($allowedSet) {
                $perturbations = array_values(array_filter($perturbations, static fn($p) => isset($allowedSet[$p])));
            }
        }
        if (count($perturbations) === 0) {
            return $result;
        }
        $result['perturbations'] = $perturbations;

        $scores = [];
        foreach ($perturbations as $p) {
            $scores[$p] = ['deg' => 0, 'go' => 0, 'gsea' => 0];
        }

        $degStmt = $scDegPdo->prepare(
            'SELECT p.perturbation_gene, COUNT(*) AS n
             FROM sc_deg_significant s
             JOIN sc_deg_perturb p ON p.perturb_pk = s.perturb_fk
             JOIN sc_deg_dataset d ON d.dataset_pk = s.dataset_fk
             WHERE d.dataset_id = :dataset_id
             GROUP BY p.perturbation_gene'
        );
        $degStmt->bindValue(':dataset_id', $datasetId, PDO::PARAM_STR);
        $degStmt->execute();
        foreach ($degStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $gene = trim((string)($row['perturbation_gene'] ?? ''));
            if ($gene !== '' && isset($scores[$gene])) {
                $scores[$gene]['deg'] = (int)($row['n'] ?? 0);
            }
        }

        if ($scGoKeggPdo) {
            try {
                $goStmt = $scGoKeggPdo->prepare(
                    'SELECT p.perturbation_gene, COUNT(*) AS n
                     FROM enrich_fact f
                     JOIN dataset_dict d ON d.dataset_pk = f.dataset_fk
                     JOIN perturb_dict p ON p.perturb_pk = f.perturb_fk
                     WHERE d.dataset_id = :dataset_id
                       AND f.padj_x1e8 < 5000000
                     GROUP BY p.perturbation_gene'
                );
                $goStmt->bindValue(':dataset_id', $datasetId, PDO::PARAM_STR);
                $goStmt->execute();
                foreach ($goStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $gene = trim((string)($row['perturbation_gene'] ?? ''));
                    if ($gene !== '' && isset($scores[$gene])) {
                        $scores[$gene]['go'] = (int)($row['n'] ?? 0);
                    }
                }
            } catch (Throwable $e) {
                // Keep GO score as 0 when schema/data unavailable.
            }
        }

        if ($scGseaPdo) {
            try {
                $gseaStmt = $scGseaPdo->prepare(
                    'SELECT p.perturbation_gene, COUNT(*) AS n
                     FROM gsea_node_fact n
                     JOIN dataset_dict d ON d.dataset_pk = n.dataset_fk
                     JOIN perturb_dict p ON p.perturb_pk = n.perturb_fk
                     WHERE d.dataset_id = :dataset_id
                       AND n.fdr_x1e6 < 250000
                     GROUP BY p.perturbation_gene'
                );
                $gseaStmt->bindValue(':dataset_id', $datasetId, PDO::PARAM_STR);
                $gseaStmt->execute();
                foreach ($gseaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $gene = trim((string)($row['perturbation_gene'] ?? ''));
                    if ($gene !== '' && isset($scores[$gene])) {
                        $scores[$gene]['gsea'] = (int)($row['n'] ?? 0);
                    }
                }
            } catch (Throwable $e) {
                // Keep GSEA score as 0 when schema/data unavailable.
            }
        }

        $best = $perturbations[0];
        $bestScore = [-1, -1, -1, -1];
        foreach ($perturbations as $p) {
            $deg = (int)($scores[$p]['deg'] ?? 0);
            $go = (int)($scores[$p]['go'] ?? 0);
            $gsea = (int)($scores[$p]['gsea'] ?? 0);
            $completeness = ($deg > 0 ? 1 : 0) + ($go > 0 ? 1 : 0) + ($gsea > 0 ? 1 : 0);
            $composite = [
                $completeness,
                $go + $gsea,
                $deg,
                ($go > 0 ? 1 : 0) + ($gsea > 0 ? 1 : 0)
            ];
            if ($composite > $bestScore) {
                $best = $p;
                $bestScore = $composite;
            }
        }
        $result['selected'] = $best;
        return $result;
    } catch (Throwable $e) {
        return $result;
    }
}

$id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$groupId = isset($_GET['group_id']) ? trim((string)$_GET['group_id']) : '';
$table = DB_TABLE;

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$bulkPdo = null;
if (file_exists($bulkDbFile)) {
  $bulkPdo = new PDO('sqlite:' . $bulkDbFile);
  $bulkPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $bulkPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$exprPdo = null;
if (file_exists($bulkExprDbFile)) {
  $exprPdo = new PDO('sqlite:' . $bulkExprDbFile);
  $exprPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $exprPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$gtexPdo = null;
if (file_exists($gtexTcgaDbFile)) {
  $gtexPdo = new PDO('sqlite:' . $gtexTcgaDbFile);
  $gtexPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $gtexPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$bulkGoKeggPdo = null;
if (file_exists($bulkGoKeggDbFile)) {
  $bulkGoKeggPdo = new PDO('sqlite:' . $bulkGoKeggDbFile);
  $bulkGoKeggPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $bulkGoKeggPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$scGoKeggPdo = null;
if (file_exists($scGoKeggDbFile)) {
  $scGoKeggPdo = new PDO('sqlite:' . $scGoKeggDbFile);
  $scGoKeggPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $scGoKeggPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$bulkGseaPdo = null;
if (file_exists($bulkGseaDbFile)) {
  $bulkGseaPdo = new PDO('sqlite:' . $bulkGseaDbFile);
  $bulkGseaPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $bulkGseaPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$scGseaPdo = null;
if (file_exists($scGseaDbFile)) {
  $scGseaPdo = new PDO('sqlite:' . $scGseaDbFile);
  $scGseaPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $scGseaPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$scPerturbStatesPdo = null;
if (file_exists($scPerturbStatesDbFile)) {
  $scPerturbStatesPdo = new PDO('sqlite:' . $scPerturbStatesDbFile);
  $scPerturbStatesPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $scPerturbStatesPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$scQcSummaryPdo = null;
if (file_exists($scQcSummaryDbFile)) {
  $scQcSummaryPdo = new PDO('sqlite:' . $scQcSummaryDbFile);
  $scQcSummaryPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $scQcSummaryPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$scDegPdo = null;
if (file_exists($scDegDbFile)) {
  $scDegPdo = new PDO('sqlite:' . $scDegDbFile);
  $scDegPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $scDegPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$scUmapPdo = null;
if (file_exists($scUmapDbFile)) {
  $scUmapPdo = new PDO('sqlite:' . $scUmapDbFile);
  $scUmapPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $scUmapPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

$scPerturbEnrichmentPdo = null;
$scPerturbEnrichmentResolved = null;
if (file_exists($scPerturbEnrichmentDbFile)) {
  $scPerturbEnrichmentResolved = $scPerturbEnrichmentDbFile;
} elseif (file_exists($scPerturbEnrichmentDbFileAlt)) {
  $scPerturbEnrichmentResolved = $scPerturbEnrichmentDbFileAlt;
}
if ($scPerturbEnrichmentResolved !== null) {
  $scPerturbEnrichmentPdo = new PDO('sqlite:' . $scPerturbEnrichmentResolved);
  $scPerturbEnrichmentPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $scPerturbEnrichmentPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

if (isset($_GET['deg_page'])) {
  $page = isset($_GET['deg_page']) ? max(1, (int)$_GET['deg_page']) : 1;
  $pageSize = isset($_GET['page_size']) ? max(1, min(200, (int)$_GET['page_size'])) : 10;
  $sortKey = isset($_GET['sort_key']) ? (string)$_GET['sort_key'] : 'log2fc';
  $sortDir = isset($_GET['sort_dir']) ? strtolower((string)$_GET['sort_dir']) : 'desc';

  $datasetIdForDeg = isset($_GET['dataset_id']) ? trim((string)$_GET['dataset_id']) : '';
  if ($datasetIdForDeg === '' && $id !== '' && ctype_digit($id)) {
    $stmt = $pdo->prepare("SELECT dataset_id FROM $table WHERE id = :id LIMIT 1");
    $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
    $stmt->execute();
    $datasetIdForDeg = (string)($stmt->fetchColumn() ?: '');
  }
  if ($datasetIdForDeg === '' && $groupId !== '') {
    $datasetIdForDeg = $groupId;
  }

  $isSingleCellDegDataset = (starts_with_prefix($datasetIdForDeg, 'HSSC') || starts_with_prefix($datasetIdForDeg, 'MMSC'));
  if ($isSingleCellDegDataset) {
    $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';
    $normalizedRows = [];
    $total = 0;
    $offset = ($page - 1) * $pageSize;
    $perturbationGene = isset($_GET['perturbation_gene']) ? trim((string)$_GET['perturbation_gene']) : '';
    $regulation = strtolower(trim((string)($_GET['regulation'] ?? 'all')));
    if (!in_array($regulation, ['all', 'up', 'down'], true)) {
      $regulation = 'all';
    }

    if (!$scDegPdo || $datasetIdForDeg === '') {
      header('Content-Type: application/json; charset=utf-8');
      echo safe_json_encode([
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => 0,
        'rows' => [],
        'method' => 'SC_DEG',
        'perturbation_gene' => '',
        'regulation' => $regulation
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    if ($perturbationGene === '') {
      $eligibleGenes = fetchSingleCellDegEligibleGenes($pdo, $table, $datasetIdForDeg, 10);
      $pick = pickBestSingleCellPerturbation($scDegPdo, $scGoKeggPdo, $scGseaPdo, $datasetIdForDeg, $eligibleGenes);
      $perturbationGene = (string)($pick['selected'] ?? '');
    }

    if ($perturbationGene !== '') {
      $regWhere = '';
      if ($regulation === 'up') {
        $regWhere = ' AND s.regulation = 1 ';
      } elseif ($regulation === 'down') {
        $regWhere = ' AND s.regulation = -1 ';
      }

      $allowedSort = [
        'gene_name' => 'g.gene_name',
        'ensembl_id' => 'g.ensembl_id',
        'log2fc' => 's.log2fc',
        'pvalue' => 's.pvalue',
        'padj' => 's.padj',
        'score' => 's.score'
      ];
      $sortExpr = $allowedSort[$sortKey] ?? 's.log2fc';

      $countSql = 'SELECT COUNT(*)
                   FROM sc_deg_significant s
                   JOIN sc_deg_perturb p ON p.perturb_pk = s.perturb_fk
                   JOIN sc_deg_dataset d ON d.dataset_pk = s.dataset_fk
                   WHERE d.dataset_id = :dataset_id
                     AND p.perturbation_gene = :perturbation_gene
                     ' . $regWhere;
      $countStmt = $scDegPdo->prepare($countSql);
      $countStmt->bindValue(':dataset_id', $datasetIdForDeg, PDO::PARAM_STR);
      $countStmt->bindValue(':perturbation_gene', $perturbationGene, PDO::PARAM_STR);
      $countStmt->execute();
      $total = (int)$countStmt->fetchColumn();

      $sql = 'SELECT g.gene_name, g.ensembl_id, s.log2fc, s.pvalue, s.padj, s.score
              FROM sc_deg_significant s
              JOIN sc_deg_perturb p ON p.perturb_pk = s.perturb_fk
              JOIN sc_deg_dataset d ON d.dataset_pk = s.dataset_fk
              JOIN sc_deg_gene g ON g.gene_pk = s.gene_fk
              WHERE d.dataset_id = :dataset_id
                AND p.perturbation_gene = :perturbation_gene
                ' . $regWhere . '
              ORDER BY ' . $sortExpr . ' ' . $sortDir . '
              LIMIT :limit OFFSET :offset';
      $stmt = $scDegPdo->prepare($sql);
      $stmt->bindValue(':dataset_id', $datasetIdForDeg, PDO::PARAM_STR);
      $stmt->bindValue(':perturbation_gene', $perturbationGene, PDO::PARAM_STR);
      $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
      $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
      $stmt->execute();
      $rows = $stmt->fetchAll();

      foreach ($rows as $row) {
        $normalizedRows[] = [
          'gene_name' => (string)($row['gene_name'] ?? ''),
          'ensembl_id' => (string)($row['ensembl_id'] ?? ''),
          'base_mean' => null,
          'log2fc' => parseNullableFloat($row['log2fc'] ?? null),
          'd' => null,
          'prob' => null,
          'pvalue' => parseNullableFloat($row['pvalue'] ?? null),
          'padj' => parseNullableFloat($row['padj'] ?? null),
          'score' => parseNullableFloat($row['score'] ?? null)
        ];
      }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo safe_json_encode([
      'page' => $page,
      'pageSize' => $pageSize,
      'total' => $total,
      'rows' => $normalizedRows,
      'method' => 'SC_DEG',
      'perturbation_gene' => $perturbationGene,
      'regulation' => $regulation
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $degMethod = null;
  if ($bulkPdo && $datasetIdForDeg !== '') {
    $degMethod = loadBulkMethod($bulkPdo, $datasetIdForDeg);
  }

  if (!$bulkPdo || $datasetIdForDeg === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo safe_json_encode([
      'page' => $page,
      'pageSize' => $pageSize,
      'total' => 0,
      'rows' => [],
      'method' => $degMethod
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($degMethod === null) {
    $degMethod = 'DEG';
  }
  $isNoiMethod = (strcasecmp((string)$degMethod, 'NOISeq') === 0);

  $allowedSort = [
    'base_mean' => 'base_mean',
    'log2fc' => 'log2fc',
    'pvalue' => 'pvalue',
    'padj' => 'padj'
  ];
  if ($isNoiMethod) {
    $allowedSort['d'] = 'd';
    $allowedSort['prob'] = 'prob';
  }
  $sortColumn = $allowedSort[$sortKey] ?? 'log2fc';
  $sortExpr = sqlRealExpr($sortColumn);
  $log2fcExpr = sqlRealExpr('log2fc');
  $padjExpr = sqlRealExpr('padj');
  $baseMeanExpr = sqlRealExpr('base_mean');
  $pvalueExpr = sqlRealExpr('pvalue');
  $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';
  $normalizedRows = [];
  $total = 0;
  $offset = ($page - 1) * $pageSize;

  if ($isNoiMethod) {
    $sql = "SELECT gene_name, ensembl_id,
                   {$baseMeanExpr} AS base_mean,
                   {$log2fcExpr} AS log2fc,
                   {$pvalueExpr} AS pvalue,
                   {$padjExpr} AS padj,
                   value_str
            FROM bulk_deg_result
            WHERE dataset_id = :dataset_id
              AND {$log2fcExpr} IS NOT NULL";
    $stmt = $bulkPdo->prepare($sql);
    $stmt->bindValue(':dataset_id', $datasetIdForDeg, PDO::PARAM_STR);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $prepared = [];
    foreach ($rows as $row) {
      $log2fc = parseNullableFloat($row['log2fc'] ?? null);
      if ($log2fc === null || abs($log2fc) < 1) {
        continue;
      }
      $metrics = parseValueStrMetrics($row['value_str'] ?? '');
      $prob = isset($metrics['prob']) ? (float)$metrics['prob'] : null;
      $d = isset($metrics['d']) ? (float)$metrics['d'] : null;
      if ($prob === null) {
        $pv = parseNullableFloat($row['pvalue'] ?? null);
        if ($pv !== null) {
          $prob = 1.0 - $pv;
        }
      }
      if ($prob === null || $prob < 0.95) {
        continue;
      }
      $prepared[] = [
        'gene_name' => (string)($row['gene_name'] ?? ''),
        'ensembl_id' => (string)($row['ensembl_id'] ?? ''),
        'base_mean' => parseNullableFloat($row['base_mean'] ?? null),
        'log2fc' => $log2fc,
        'd' => $d,
        'prob' => $prob,
        'pvalue' => parseNullableFloat($row['pvalue'] ?? null),
        'padj' => parseNullableFloat($row['padj'] ?? null),
      ];
    }

    usort($prepared, function ($a, $b) use ($sortColumn, $sortDir) {
      $va = $a[$sortColumn] ?? null;
      $vb = $b[$sortColumn] ?? null;
      if ($va === null && $vb === null) return 0;
      if ($va === null) return 1;
      if ($vb === null) return -1;
      if (is_numeric($va) && is_numeric($vb)) {
        $cmp = (float)$va <=> (float)$vb;
      } else {
        $cmp = strcmp((string)$va, (string)$vb);
      }
      return $sortDir === 'asc' ? $cmp : -$cmp;
    });

    $total = count($prepared);
    $normalizedRows = array_slice($prepared, $offset, $pageSize);
  } else {
    $totalStmt = $bulkPdo->prepare(
      'SELECT COUNT(*) FROM bulk_deg_result
       WHERE dataset_id = :dataset_id
         AND ' . $log2fcExpr . ' IS NOT NULL
         AND ' . $padjExpr . ' IS NOT NULL
         AND ' . $padjExpr . ' < 0.05
         AND ABS(' . $log2fcExpr . ') >= 1'
    );
    $totalStmt->bindValue(':dataset_id', $datasetIdForDeg, PDO::PARAM_STR);
    $totalStmt->execute();
    $total = (int)$totalStmt->fetchColumn();

    $sql = "SELECT gene_name, ensembl_id,
                   {$baseMeanExpr} AS base_mean,
                   {$log2fcExpr} AS log2fc,
                   {$pvalueExpr} AS pvalue,
                   {$padjExpr} AS padj
            FROM bulk_deg_result
            WHERE dataset_id = :dataset_id
              AND {$log2fcExpr} IS NOT NULL
              AND {$padjExpr} IS NOT NULL
              AND {$padjExpr} < 0.05
              AND ABS({$log2fcExpr}) >= 1
            ORDER BY {$sortExpr} {$sortDir}
            LIMIT :limit OFFSET :offset";
    $stmt = $bulkPdo->prepare($sql);
    $stmt->bindValue(':dataset_id', $datasetIdForDeg, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
      $normalizedRows[] = [
        'gene_name' => (string)($row['gene_name'] ?? ''),
        'ensembl_id' => (string)($row['ensembl_id'] ?? ''),
        'base_mean' => $row['base_mean'] ?? null,
        'log2fc' => $row['log2fc'] ?? null,
        'd' => null,
        'prob' => null,
        'pvalue' => $row['pvalue'] ?? null,
        'padj' => $row['padj'] ?? null
      ];
    }
  }

  header('Content-Type: application/json; charset=utf-8');
  echo safe_json_encode([
    'page' => $page,
    'pageSize' => $pageSize,
    'total' => $total,
    'rows' => $normalizedRows,
    'method' => $degMethod
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if (isset($_GET['chart_api'])) {
  $datasetIdForChart = isset($_GET['dataset_id']) ? trim((string)$_GET['dataset_id']) : '';
  if ($datasetIdForChart === '' && $id !== '' && ctype_digit($id)) {
    $stmt = $pdo->prepare("SELECT dataset_id FROM $table WHERE id = :id LIMIT 1");
    $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
    $stmt->execute();
    $datasetIdForChart = (string)($stmt->fetchColumn() ?: '');
  }
  if ($datasetIdForChart === '' && $groupId !== '') {
    $datasetIdForChart = $groupId;
  }

  $apiName = trim((string)$_GET['chart_api']);
  $payload = ['ok' => true, 'dataset_id' => $datasetIdForChart];

  try {
    if ($apiName === 'single_cell_gene_freq') {
      $rows = fetchSingleCellGeneFreq($pdo, $table, $datasetIdForChart);
      $payload['rows'] = $rows;
      $payload['count'] = count($rows);
    } elseif ($apiName === 'single_cell_perturb_states') {
      $state = ['pie' => [], 'bar' => [], 'has' => false];
      if ($scPerturbStatesPdo && $datasetIdForChart !== '') {
        $state = fetchSingleCellPerturbStates($scPerturbStatesPdo, $datasetIdForChart);
      }
      $payload['pie'] = $state['pie'];
      $payload['bar'] = $state['bar'];
      $payload['has'] = (bool)$state['has'];
    } elseif ($apiName === 'single_cell_qc_dist') {
      $dist = [
        'n_genes_by_counts' => [],
        'total_counts' => [],
        'pct_counts_mt' => []
      ];
      if ($scQcSummaryPdo && $datasetIdForChart !== '') {
        $dist = fetchSingleCellQcDist($scQcSummaryPdo, $datasetIdForChart);
      }
      $payload['dist'] = $dist;
      $payload['has'] = !empty($dist['n_genes_by_counts']) || !empty($dist['total_counts']) || !empty($dist['pct_counts_mt']);
    } elseif ($apiName === 'single_cell_umap') {
      $points = [];
      if ($scUmapPdo && $datasetIdForChart !== '') {
        $points = fetchSingleCellUmap($scUmapPdo, $datasetIdForChart);
      }
      $payload['points'] = $points;
      $payload['count'] = count($points);
      $payload['has'] = count($points) > 0;
    } elseif ($apiName === 'single_cell_deg_options') {
      $payload['perturbations'] = [];
      $payload['selected'] = '';
      if ($scDegPdo && $datasetIdForChart !== '') {
        $eligibleGenes = fetchSingleCellDegEligibleGenes($pdo, $table, $datasetIdForChart, 10);
        $pick = pickBestSingleCellPerturbation($scDegPdo, $scGoKeggPdo, $scGseaPdo, $datasetIdForChart, $eligibleGenes);
        $payload['perturbations'] = (array)($pick['perturbations'] ?? []);
        $payload['selected'] = (string)($pick['selected'] ?? '');
      }
      $payload['count'] = count($payload['perturbations']);
      $payload['has'] = $payload['count'] > 0;
    } elseif ($apiName === 'single_cell_deg_volcano') {
      $perturbationGene = trim((string)($_GET['perturbation_gene'] ?? ''));
      $regulation = strtolower(trim((string)($_GET['regulation'] ?? 'all')));
      if (!in_array($regulation, ['all', 'up', 'down'], true)) {
        $regulation = 'all';
      }
      $payload['volcano'] = ['other' => [], 'up' => [], 'down' => []];
      $payload['count'] = 0;
      if ($scDegPdo && $datasetIdForChart !== '' && $perturbationGene !== '') {
        $regWhere = '';
        if ($regulation === 'up') {
          $regWhere = ' AND s.regulation = 1 ';
        } elseif ($regulation === 'down') {
          $regWhere = ' AND s.regulation = -1 ';
        }
        $stmt = $scDegPdo->prepare(
          'SELECT g.gene_name, g.ensembl_id, s.log2fc, s.padj
           FROM sc_deg_significant s
           JOIN sc_deg_perturb p ON p.perturb_pk = s.perturb_fk
           JOIN sc_deg_dataset d ON d.dataset_pk = s.dataset_fk
           JOIN sc_deg_gene g ON g.gene_pk = s.gene_fk
           WHERE d.dataset_id = :dataset_id
             AND p.perturbation_gene = :perturbation_gene ' . $regWhere
        );
        $stmt->bindValue(':dataset_id', $datasetIdForChart, PDO::PARAM_STR);
        $stmt->bindValue(':perturbation_gene', $perturbationGene, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $payload['count'] = count($rows);
        foreach ($rows as $row) {
          $log2fc = parseNullableFloat($row['log2fc'] ?? null);
          $padj = parseNullableFloat($row['padj'] ?? null);
          if ($log2fc === null || $padj === null || $padj < 0) {
            continue;
          }
          $geneLabel = trim((string)($row['gene_name'] ?? ''));
          if ($geneLabel === '') {
            $geneLabel = (string)($row['ensembl_id'] ?? '');
          }
          $point = [(float)$log2fc, (float)(-log(max($padj, 1e-300), 10)), $geneLabel, (float)$padj];
          $isSig = (abs($log2fc) >= 1.0 && $padj < 0.05);
          if ($isSig) {
            if ($log2fc > 0) {
              $payload['volcano']['up'][] = $point;
            } elseif ($log2fc < 0) {
              $payload['volcano']['down'][] = $point;
            } else {
              $payload['volcano']['other'][] = $point;
            }
          } else {
            $payload['volcano']['other'][] = $point;
          }
        }
      }
      $payload['has'] = ($payload['count'] > 0);
      $payload['perturbation_gene'] = $perturbationGene;
      $payload['regulation'] = $regulation;
    } elseif ($apiName === 'single_cell_perturb_enrichment') {
      $rows = [];
      if (!$scPerturbEnrichmentPdo) {
        $payload = ['ok' => false, 'error' => 'single_cell_perturbation_enrichment db not found'];
      } elseif ($datasetIdForChart === '') {
        $payload = ['ok' => false, 'error' => 'missing dataset_id'];
      } else {
        $rows = fetchSingleCellPerturbEnrichment($scPerturbEnrichmentPdo, $datasetIdForChart);
        $payload['rows'] = $rows;
        $payload['count'] = count($rows);
        $payload['has'] = count($rows) > 0;
      }
    } elseif ($apiName === 'bulk_go_kegg_enrichment') {
      if (!$bulkGoKeggPdo) {
        $payload = ['ok' => false, 'error' => 'bulk_go_kegg db not found'];
      } elseif ($datasetIdForChart === '') {
        $payload = ['ok' => false, 'error' => 'missing dataset_id'];
      } else {
        $direction = strtolower(trim((string)($_GET['direction'] ?? 'up')));
        if (!in_array($direction, ['up', 'down', 'all'], true)) {
          $direction = 'up';
        }
        $mode = strtolower(trim((string)($_GET['mode'] ?? 'go')));
        if (!in_array($mode, ['go', 'kegg'], true)) {
          $mode = 'go';
        }
        $ontology = strtoupper(trim((string)($_GET['ontology'] ?? 'BP')));
        if (!in_array($ontology, ['BP', 'CC', 'MF'], true)) {
          $ontology = 'BP';
        }

        $directionCode = ($direction === 'up') ? 1 : 0;
        $categoryCode = 1;
        if ($mode === 'kegg') {
          $categoryCode = 4;
          $ontology = 'KEGG';
        } elseif ($ontology === 'CC') {
          $categoryCode = 2;
        } elseif ($ontology === 'MF') {
          $categoryCode = 3;
        }

        $hasOverlapRatio = sqliteTableHasColumn($bulkGoKeggPdo, 'enrich_fact', 'overlap_ratio_x1e4');
        $ratioExpr = $hasOverlapRatio ? 'f.overlap_ratio_x1e4' : '0';
        $sql = 'SELECT t.term AS description,
                       f.odds_ratio_x1000 AS fold_x1000,
                       f.overlap_hit AS count_value,
                       f.neglog10padj_x1000 AS score_x1000,
                       ' . $ratioExpr . ' AS overlap_ratio_x1e4,
                       f.direction_code AS direction_code
                FROM enrich_fact f
                JOIN dataset_dict d ON d.dataset_pk = f.dataset_fk
                JOIN term_dict t ON t.term_pk = f.term_fk
                WHERE d.dataset_id = :dataset_id
                  AND t.category_code = :category_code
                  AND f.padj_x1e8 < 5000000';
        if ($direction !== 'all') {
          $sql .= ' AND f.direction_code = :direction_code';
        }
        $sql .= ' ORDER BY f.neglog10padj_x1000 DESC, f.overlap_hit DESC LIMIT 20';
        $stmt = $bulkGoKeggPdo->prepare($sql);
        $stmt->bindValue(':dataset_id', $datasetIdForChart, PDO::PARAM_STR);
        if ($direction !== 'all') {
          $stmt->bindValue(':direction_code', $directionCode, PDO::PARAM_INT);
        }
        $stmt->bindValue(':category_code', $categoryCode, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $payload['rows'] = array_map(static function ($r) use ($ontology) {
          return [
            'ontology' => $ontology,
            'description' => (string)($r['description'] ?? ''),
            'fold_enrichment' => ((int)($r['fold_x1000'] ?? 0)) / 1000.0,
            'count' => (int)($r['count_value'] ?? 0),
            'score' => ((int)($r['score_x1000'] ?? 0)) / 1000.0,
            'overlap_ratio' => ((int)($r['overlap_ratio_x1e4'] ?? 0)) / 10000.0,
            'direction' => ((int)($r['direction_code'] ?? 0) === 1) ? 'up' : 'down'
          ];
        }, $rows);
        $payload['count'] = count($payload['rows']);
        $payload['has'] = ($payload['count'] > 0);
        $payload['direction'] = $direction;
        $payload['mode'] = $mode;
        $payload['ontology'] = $ontology;
      }
    } elseif ($apiName === 'single_cell_go_kegg_enrichment') {
      if (!$scGoKeggPdo) {
        $payload = ['ok' => false, 'error' => 'single_cell_go_kegg db not found'];
      } elseif ($datasetIdForChart === '') {
        $payload = ['ok' => false, 'error' => 'missing dataset_id'];
      } else {
        $perturbationGene = trim((string)($_GET['perturbation_gene'] ?? ''));
        if ($perturbationGene === '') {
          $payload = ['ok' => false, 'error' => 'missing perturbation_gene'];
        } else {
          $direction = strtolower(trim((string)($_GET['direction'] ?? 'up')));
          if (!in_array($direction, ['up', 'down', 'all'], true)) {
            $direction = 'up';
          }
          $mode = strtolower(trim((string)($_GET['mode'] ?? 'go')));
          if (!in_array($mode, ['go', 'kegg'], true)) {
            $mode = 'go';
          }
          $ontology = strtoupper(trim((string)($_GET['ontology'] ?? 'BP')));
          if (!in_array($ontology, ['BP', 'CC', 'MF'], true)) {
            $ontology = 'BP';
          }

          $directionCode = ($direction === 'up') ? 1 : 0;
          $categoryCode = 1;
          if ($mode === 'kegg') {
            $categoryCode = 4;
            $ontology = 'KEGG';
          } elseif ($ontology === 'CC') {
            $categoryCode = 2;
          } elseif ($ontology === 'MF') {
            $categoryCode = 3;
          }

          $hasOverlapRatio = sqliteTableHasColumn($scGoKeggPdo, 'enrich_fact', 'overlap_ratio_x1e4');
          $ratioExpr = $hasOverlapRatio ? 'f.overlap_ratio_x1e4' : '0';
          $sql = 'SELECT t.term AS description,
                         f.odds_ratio_x1000 AS fold_x1000,
                         f.overlap_hit AS count_value,
                         f.neglog10padj_x1000 AS score_x1000,
                         ' . $ratioExpr . ' AS overlap_ratio_x1e4,
                         f.direction_code AS direction_code
                  FROM enrich_fact f
                  JOIN dataset_dict d ON d.dataset_pk = f.dataset_fk
                  JOIN perturb_dict p ON p.perturb_pk = f.perturb_fk
                  JOIN term_dict t ON t.term_pk = f.term_fk
                  WHERE d.dataset_id = :dataset_id
                    AND p.perturbation_gene = :perturbation_gene
                    AND t.category_code = :category_code
                    AND f.padj_x1e8 < 5000000';
          if ($direction !== 'all') {
            $sql .= ' AND f.direction_code = :direction_code';
          }
          $sql .= ' ORDER BY f.neglog10padj_x1000 DESC, f.overlap_hit DESC LIMIT 20';

          $stmt = $scGoKeggPdo->prepare($sql);
          $stmt->bindValue(':dataset_id', $datasetIdForChart, PDO::PARAM_STR);
          $stmt->bindValue(':perturbation_gene', $perturbationGene, PDO::PARAM_STR);
          if ($direction !== 'all') {
            $stmt->bindValue(':direction_code', $directionCode, PDO::PARAM_INT);
          }
          $stmt->bindValue(':category_code', $categoryCode, PDO::PARAM_INT);
          $stmt->execute();
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          $payload['rows'] = array_map(static function ($r) use ($ontology) {
            return [
              'ontology' => $ontology,
              'description' => (string)($r['description'] ?? ''),
              'fold_enrichment' => ((int)($r['fold_x1000'] ?? 0)) / 1000.0,
              'count' => (int)($r['count_value'] ?? 0),
              'score' => ((int)($r['score_x1000'] ?? 0)) / 1000.0,
              'overlap_ratio' => ((int)($r['overlap_ratio_x1e4'] ?? 0)) / 10000.0,
              'direction' => ((int)($r['direction_code'] ?? 0) === 1) ? 'up' : 'down'
            ];
          }, $rows);
          $payload['count'] = count($payload['rows']);
          $payload['has'] = ($payload['count'] > 0);
          $payload['direction'] = $direction;
          $payload['mode'] = $mode;
          $payload['ontology'] = $ontology;
          $payload['perturbation_gene'] = $perturbationGene;
        }
      }
    } elseif ($apiName === 'bulk_gsea') {
      if (!$bulkGseaPdo) {
        $payload = ['ok' => false, 'error' => 'bulk_gsea db not found'];
      } elseif ($datasetIdForChart === '') {
        $payload = ['ok' => false, 'error' => 'missing dataset_id'];
      } else {
        $nodeStmt = $bulkGseaPdo->prepare(
          'SELECT n.pathway_fk, p.pathway_name, n.nes_x1000, n.fdr_x1e6, n.overlap_ratio_x1e4
           FROM gsea_node_fact n
           JOIN dataset_dict d ON d.dataset_pk = n.dataset_fk
           JOIN pathway_dict p ON p.pathway_pk = n.pathway_fk
           WHERE d.dataset_id = :dataset_id
             AND n.fdr_x1e6 < 250000
           ORDER BY ABS(n.nes_x1000) DESC, n.fdr_x1e6 ASC'
        );
        $nodeStmt->bindValue(':dataset_id', $datasetIdForChart, PDO::PARAM_STR);
        $nodeStmt->execute();
        $nodeRows = $nodeStmt->fetchAll(PDO::FETCH_ASSOC);

        $barRows = [];
        foreach (array_slice($nodeRows, 0, 20) as $r) {
          $barRows[] = [
            'pathway' => (string)($r['pathway_name'] ?? ''),
            'nes' => ((int)($r['nes_x1000'] ?? 0)) / 1000.0,
            'fdr' => ((int)($r['fdr_x1e6'] ?? 0)) / 1000000.0
          ];
        }

        $nodeRowsForMap = array_slice($nodeRows, 0, 80);
        $nodeFkSet = [];
        $mapNodes = [];
        foreach ($nodeRowsForMap as $r) {
          $fk = (int)($r['pathway_fk'] ?? 0);
          if ($fk <= 0) {
            continue;
          }
          $nodeFkSet[$fk] = true;
          $mapNodes[] = [
            'id' => $fk,
            'name' => (string)($r['pathway_name'] ?? ''),
            'nes' => ((int)($r['nes_x1000'] ?? 0)) / 1000.0,
            'fdr' => ((int)($r['fdr_x1e6'] ?? 0)) / 1000000.0,
            'overlap_ratio' => ((int)($r['overlap_ratio_x1e4'] ?? 0)) / 10000.0
          ];
        }

        $edgeStmt = $bulkGseaPdo->prepare(
          'SELECT e.source_pathway_fk, e.target_pathway_fk, e.weight_x1e4
           FROM gsea_edge_fact e
           JOIN dataset_dict d ON d.dataset_pk = e.dataset_fk
           WHERE d.dataset_id = :dataset_id
           ORDER BY e.weight_x1e4 DESC'
        );
        $edgeStmt->bindValue(':dataset_id', $datasetIdForChart, PDO::PARAM_STR);
        $edgeStmt->execute();
        $edgeRows = $edgeStmt->fetchAll(PDO::FETCH_ASSOC);
        $mapEdges = [];
        foreach ($edgeRows as $e) {
          $src = (int)($e['source_pathway_fk'] ?? 0);
          $tgt = (int)($e['target_pathway_fk'] ?? 0);
          if ($src <= 0 || $tgt <= 0 || !isset($nodeFkSet[$src]) || !isset($nodeFkSet[$tgt])) {
            continue;
          }
          $mapEdges[] = [
            'source' => $src,
            'target' => $tgt,
            'weight' => ((int)($e['weight_x1e4'] ?? 0)) / 10000.0
          ];
          if (count($mapEdges) >= 300) {
            break;
          }
        }

        $payload['bar_rows'] = $barRows;
        $payload['map_nodes'] = $mapNodes;
        $payload['map_edges'] = $mapEdges;
        $payload['count'] = count($barRows);
        $payload['has'] = count($barRows) > 0;
      }
    } elseif ($apiName === 'single_cell_gsea') {
      if (!$scGseaPdo) {
        $payload = ['ok' => false, 'error' => 'single_cell_gsea db not found'];
      } elseif ($datasetIdForChart === '') {
        $payload = ['ok' => false, 'error' => 'missing dataset_id'];
      } else {
        $perturbationGene = trim((string)($_GET['perturbation_gene'] ?? ''));
        if ($perturbationGene === '') {
          $payload = ['ok' => false, 'error' => 'missing perturbation_gene'];
        } else {
          $nodeStmt = $scGseaPdo->prepare(
            'SELECT n.pathway_fk, pth.pathway_name, n.nes_x1000, n.fdr_x1e6, n.overlap_ratio_x1e4
             FROM gsea_node_fact n
             JOIN dataset_dict d ON d.dataset_pk = n.dataset_fk
             JOIN perturb_dict p ON p.perturb_pk = n.perturb_fk
             JOIN pathway_dict pth ON pth.pathway_pk = n.pathway_fk
             WHERE d.dataset_id = :dataset_id
               AND p.perturbation_gene = :perturbation_gene
               AND n.fdr_x1e6 < 250000
             ORDER BY ABS(n.nes_x1000) DESC, n.fdr_x1e6 ASC'
          );
          $nodeStmt->bindValue(':dataset_id', $datasetIdForChart, PDO::PARAM_STR);
          $nodeStmt->bindValue(':perturbation_gene', $perturbationGene, PDO::PARAM_STR);
          $nodeStmt->execute();
          $nodeRows = $nodeStmt->fetchAll(PDO::FETCH_ASSOC);

          $barRows = [];
          foreach (array_slice($nodeRows, 0, 20) as $r) {
            $barRows[] = [
              'pathway' => (string)($r['pathway_name'] ?? ''),
              'nes' => ((int)($r['nes_x1000'] ?? 0)) / 1000.0,
              'fdr' => ((int)($r['fdr_x1e6'] ?? 0)) / 1000000.0
            ];
          }

          $nodeRowsForMap = array_slice($nodeRows, 0, 80);
          $nodeFkSet = [];
          $mapNodes = [];
          foreach ($nodeRowsForMap as $r) {
            $fk = (int)($r['pathway_fk'] ?? 0);
            if ($fk <= 0) {
              continue;
            }
            $nodeFkSet[$fk] = true;
            $mapNodes[] = [
              'id' => $fk,
              'name' => (string)($r['pathway_name'] ?? ''),
              'nes' => ((int)($r['nes_x1000'] ?? 0)) / 1000.0,
              'fdr' => ((int)($r['fdr_x1e6'] ?? 0)) / 1000000.0,
              'overlap_ratio' => ((int)($r['overlap_ratio_x1e4'] ?? 0)) / 10000.0
            ];
          }

          $edgeStmt = $scGseaPdo->prepare(
            'SELECT e.source_pathway_fk, e.target_pathway_fk, e.weight_x1e4
             FROM gsea_edge_fact e
             JOIN dataset_dict d ON d.dataset_pk = e.dataset_fk
             JOIN perturb_dict p ON p.perturb_pk = e.perturb_fk
             WHERE d.dataset_id = :dataset_id
               AND p.perturbation_gene = :perturbation_gene
             ORDER BY e.weight_x1e4 DESC'
          );
          $edgeStmt->bindValue(':dataset_id', $datasetIdForChart, PDO::PARAM_STR);
          $edgeStmt->bindValue(':perturbation_gene', $perturbationGene, PDO::PARAM_STR);
          $edgeStmt->execute();
          $edgeRows = $edgeStmt->fetchAll(PDO::FETCH_ASSOC);
          $mapEdges = [];
          foreach ($edgeRows as $e) {
            $src = (int)($e['source_pathway_fk'] ?? 0);
            $tgt = (int)($e['target_pathway_fk'] ?? 0);
            if ($src <= 0 || $tgt <= 0 || !isset($nodeFkSet[$src]) || !isset($nodeFkSet[$tgt])) {
              continue;
            }
            $mapEdges[] = [
              'source' => $src,
              'target' => $tgt,
              'weight' => ((int)($e['weight_x1e4'] ?? 0)) / 10000.0
            ];
            if (count($mapEdges) >= 300) {
              break;
            }
          }

          $payload['bar_rows'] = $barRows;
          $payload['map_nodes'] = $mapNodes;
          $payload['map_edges'] = $mapEdges;
          $payload['count'] = count($barRows);
          $payload['has'] = count($barRows) > 0;
          $payload['perturbation_gene'] = $perturbationGene;
        }
      }
    } else {
      $payload = ['ok' => false, 'error' => 'unsupported chart_api'];
    }
  } catch (Throwable $e) {
    $payload = ['ok' => false, 'error' => 'chart_api failed: ' . $e->getMessage()];
  }

  header('Content-Type: application/json; charset=utf-8');
  echo safe_json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}


$metaRows = [];
$mode = '';
$titleValue = '';

if ($id !== '' && ctype_digit($id)) {
  $mode = 'id';
  $titleValue = $id;
  $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = :id LIMIT 1");
  $stmt->bindValue(':id', (int)$id, PDO::PARAM_INT);
  $stmt->execute();
  $metaRow = $stmt->fetch();
  $metaRows = $metaRow ? [$metaRow] : [];
} elseif ($groupId !== '') {
  $mode = 'group_id';
  $titleValue = $groupId;
  $stmt = $pdo->prepare("SELECT * FROM $table WHERE dataset_id = :group_id ORDER BY id ASC");
  $stmt->bindValue(':group_id', $groupId, PDO::PARAM_STR);
  $stmt->execute();
  $metaRows = $stmt->fetchAll();
} else {
  $metaRow = null;
}

$metaRow = $metaRows[0] ?? null;
$error = '';
if ($mode === '') {
  $error = 'Please provide id or group_id.';
} elseif (count($metaRows) === 0) {
  $error = 'Record not found.';
}

$bulkMethod = null;
$hasBulkDegData = false;
$bulkDegCount = 0;
$degTableColumns = buildDegColumns(null);
// Narrower Ensembl ID, wider baseMean.
$degColWidths = [20, 14, 14, 10, 14, 14];
if ($bulkPdo && $metaRow && !empty($metaRow['dataset_id'])) {
  $bulkMethod = loadBulkMethod($bulkPdo, (string)$metaRow['dataset_id']);
  $degTableColumns = buildDegColumns($bulkMethod);
  $isNoiMethod = (strcasecmp((string)$bulkMethod, 'NOISeq') === 0);
  if ($isNoiMethod) {
    $degCountStmt = $bulkPdo->prepare(
      'SELECT ' . sqlRealExpr('log2fc') . ' AS log2fc, ' . sqlRealExpr('pvalue') . ' AS pvalue, value_str
       FROM bulk_deg_result
       WHERE dataset_id = :dataset_id
         AND ' . sqlRealExpr('log2fc') . ' IS NOT NULL'
    );
    $degCountStmt->bindValue(':dataset_id', (string)$metaRow['dataset_id'], PDO::PARAM_STR);
    $degCountStmt->execute();
    $countRows = $degCountStmt->fetchAll();
    $bulkDegCount = 0;
    foreach ($countRows as $crow) {
      $log2fc = parseNullableFloat($crow['log2fc'] ?? null);
      if ($log2fc === null || abs($log2fc) < 1) {
        continue;
      }
      $metrics = parseValueStrMetrics($crow['value_str'] ?? '');
      $prob = isset($metrics['prob']) ? (float)$metrics['prob'] : null;
      if ($prob === null) {
        $pv = parseNullableFloat($crow['pvalue'] ?? null);
        if ($pv !== null) {
          $prob = 1.0 - $pv;
        }
      }
      if ($prob !== null && $prob >= 0.95) {
        $bulkDegCount++;
      }
    }
  } else {
    $degCountStmt = $bulkPdo->prepare(
      'SELECT COUNT(*) FROM bulk_deg_result
       WHERE dataset_id = :dataset_id
         AND ' . sqlRealExpr('log2fc') . ' IS NOT NULL
         AND ' . sqlRealExpr('padj') . ' IS NOT NULL
         AND ' . sqlRealExpr('padj') . ' < 0.05
         AND ABS(' . sqlRealExpr('log2fc') . ') >= 1'
    );
    $degCountStmt->bindValue(':dataset_id', (string)$metaRow['dataset_id'], PDO::PARAM_STR);
    $degCountStmt->execute();
    $bulkDegCount = (int)$degCountStmt->fetchColumn();
  }
  $hasBulkDegData = $bulkDegCount > 0;
}

$targetRows = [];
if ($metaRow && !empty($metaRow['dataset_id'])) {
  $targetStmt = $pdo->prepare("SELECT * FROM $table WHERE dataset_id = :dataset_id ORDER BY id ASC");
  $targetStmt->bindValue(':dataset_id', (string)$metaRow['dataset_id'], PDO::PARAM_STR);
  $targetStmt->execute();
  $targetRows = $targetStmt->fetchAll();
}

$expandedTargetRows = [];
$currentDatasetId = $metaRow['dataset_id'] ?? '';
$isSingleCellTarget = (starts_with_prefix($currentDatasetId, 'HSSC') || starts_with_prefix($currentDatasetId, 'MMSC'));
$isSingleCellDegEnabled = ($isSingleCellTarget && $scDegPdo !== null);
$singleCellDegPerturbations = [];
$singleCellDegDefaultPerturbation = '';
$singleCellDegDefaultRegulation = 'all';
if ($isSingleCellDegEnabled && $currentDatasetId !== '') {
  try {
    $eligibleGenes = fetchSingleCellDegEligibleGenes($pdo, $table, $currentDatasetId, 10);
    $pick = pickBestSingleCellPerturbation($scDegPdo, $scGoKeggPdo, $scGseaPdo, $currentDatasetId, $eligibleGenes);
    $singleCellDegPerturbations = (array)($pick['perturbations'] ?? []);
    $singleCellDegDefaultPerturbation = (string)($pick['selected'] ?? '');
    if ($singleCellDegDefaultPerturbation === '' && count($singleCellDegPerturbations) > 0) {
      $singleCellDegDefaultPerturbation = (string)$singleCellDegPerturbations[0];
    }
  } catch (Throwable $e) {
    $singleCellDegPerturbations = [];
    $singleCellDegDefaultPerturbation = '';
  }
}
$singleCellGeneCountMap = [];
if ($isSingleCellTarget && $currentDatasetId !== '') {
  $singleCellGeneFreqRows = fetchSingleCellGeneFreq($pdo, $table, (string)$currentDatasetId);
  foreach ($singleCellGeneFreqRows as $freqItem) {
    $gene = trim((string)($freqItem['gene'] ?? ''));
    $count = (int)($freqItem['count'] ?? 0);
    if ($gene !== '' && $count > 0) {
      $singleCellGeneCountMap[$gene] = $count;
    }
  }
}

foreach ($targetRows as $item) {
  $geneNames = splitCommaSeparatedValues($item['meta_assay_target_gene_name'] ?? '');
  $assayTypes = splitCommaSeparatedValues($item['meta_assay_type'] ?? '');
  $geneTypes = splitCommaSeparatedValues($item['meta_assay_target_gene_type'] ?? '');
  $ensemblIds = splitCommaSeparatedValues($item['meta_assay_target_gene_ensembl_id'] ?? '');
  $maxCount = max(count($geneNames), count($assayTypes), count($geneTypes), count($ensemblIds), 1);
  for ($index = 0; $index < $maxCount; $index++) {
    $geneName = trim((string)($geneNames[$index] ?? ''));
    if ($isSingleCellTarget && ($geneName === '' || containsNaToken($geneName))) {
      continue;
    }
    $expandedTargetRows[] = [
      'meta_assay_target_gene_name' => $geneName,
      // For single-cell rows, keep full meta_assay_type text from dataset_meta.
      'meta_assay_type' => $isSingleCellTarget ? trim((string)($item['meta_assay_type'] ?? '')) : ($assayTypes[$index] ?? ''),
      'meta_assay_target_gene_type' => $geneTypes[$index] ?? '',
      'meta_assay_target_gene_ensembl_id' => $ensemblIds[$index] ?? '',
      'cell_count' => ($isSingleCellTarget && isset($singleCellGeneCountMap[$geneName])) ? (int)$singleCellGeneCountMap[$geneName] : null,
    ];
  }
}

$targetGeneNumber = 0;
if ($metaRow) {
  $targetGeneNumber = (int)($metaRow['meta_assay_target_gene_number'] ?? 0);
  if ($isSingleCellTarget) {
    $targetGeneNumber = count($expandedTargetRows);
  } elseif ($targetGeneNumber <= 0) {
    $targetGeneNumber = count($expandedTargetRows) > 0 ? count($expandedTargetRows) : count($targetRows);
  }
}

$singleCellDistData = [
  'n_genes_by_counts' => [],
  'total_counts' => [],
  'pct_counts_mt' => []
];
$hasSingleCellDist = false;
$singleCellGeneFreq = [];
$hasSingleCellGeneFreq = false;
$singleCellPerturbPie = [];
$singleCellPerturbBar = [];
$hasSingleCellPerturbStates = false;
if ($isSingleCellTarget && $metaRow) {
  $hasSingleCellDist = true;
}

$controlSamples = extractSampleIds($metaRow['external_sample_control_accession'] ?? '');
$treatmentSamples = extractSampleIds($metaRow['external_sample_treatment_accession'] ?? '');
$expressionSamples = array_values(array_unique(array_merge($controlSamples, $treatmentSamples)));
$expressionSampleGroups = [];
foreach ($controlSamples as $sid) {
  $expressionSampleGroups[$sid] = 'control';
}
foreach ($treatmentSamples as $sid) {
  $expressionSampleGroups[$sid] = 'treatment';
}
$controlExpressions = [];
$treatmentExpressions = [];
$expressionValuesBySample = [];
$hasDemoData = false;
if ($exprPdo) {
try {
  $speciesStr = strtolower($metaRow['meta_biosample_species'] ?? '');
  $tpmTable = (strpos($speciesStr, 'mus') !== false || strpos($speciesStr, 'mouse') !== false) ? 'mouse_bulk_tpm' : 'human_bulk_tpm';
  $stmt = $exprPdo->prepare("SELECT expr_values FROM {$tpmTable} WHERE sample_id = :sid");
  foreach ($controlSamples as $sid) {
    $stmt->bindValue(':sid', $sid, PDO::PARAM_STR);
    $stmt->execute();
    $rowsDemo = $stmt->fetchAll();
    foreach ($rowsDemo as $demoRow) {
      $values = parseExpressionList($demoRow['expr_values'] ?? '');
      if (!$values) {
        continue;
      }
      foreach ($values as $value) {
        $controlExpressions[] = $value;
        $expressionValuesBySample[$sid][] = $value;
      }
    }
  }
  foreach ($treatmentSamples as $sid) {
    $stmt->bindValue(':sid', $sid, PDO::PARAM_STR);
    $stmt->execute();
    $rowsDemo = $stmt->fetchAll();
    foreach ($rowsDemo as $demoRow) {
      $values = parseExpressionList($demoRow['expr_values'] ?? '');
      if (!$values) {
        continue;
      }
      foreach ($values as $value) {
        $treatmentExpressions[] = $value;
        $expressionValuesBySample[$sid][] = $value;
      }
    }
  }
  $hasDemoData = count($controlExpressions) > 0 || count($treatmentExpressions) > 0;
} catch (Throwable $e) {
  $controlExpressions = [];
  $treatmentExpressions = [];
  $expressionValuesBySample = [];
  $hasDemoData = false;
}
}

$gtexTcgaPayload = [
  'gtex' => ['labels' => [], 'series' => []],
  'tcga' => ['labels' => [], 'series' => []]
];
$hasGtexTcga = false;
$hasGtex = false;
$hasTcga = false;
$isBulkHsbk = starts_with_prefix($currentDatasetId, 'HSBK');
if ($isBulkHsbk && $metaRow && $gtexPdo) {
  try {
    $genes = array_values(array_unique(array_filter(array_map('trim', array_column($expandedTargetRows, 'meta_assay_target_gene_ensembl_id')))));
    if (!empty($genes)) {
      $placeholders = implode(',', array_fill(0, count($genes), '?'));
      $stmt = $gtexPdo->prepare('SELECT gene, exp_str FROM gtex_tcga_exps WHERE gene IN (' . $placeholders . ')');
      foreach ($genes as $index => $gene) {
        $stmt->bindValue($index + 1, $gene, PDO::PARAM_STR);
      }
      $stmt->execute();
      $rows = $stmt->fetchAll();
      if (!empty($rows)) {
        $gtexLabelMap = [];
        $tcgaLabelMap = [];
        $gtexSeriesMap = [];
        $tcgaSeriesMap = [];

        foreach ($rows as $row) {
          $geneName = (string)($row['gene'] ?? '');
          if ($geneName === '') {
            continue;
          }
          $kv = parseKeyValueExpression($row['exp_str'] ?? '');
          if (empty($kv)) {
            continue;
          }
          $gtexSeriesMap[$geneName] = [];
          $tcgaSeriesMap[$geneName] = [];
          foreach ($kv as $key => $value) {
            if (starts_with_prefix($key, 'GTEx_')) {
              $label = substr($key, 5);
              $gtexLabelMap[$label] = true;
              $gtexSeriesMap[$geneName][$label] = (float)$value;
            } elseif (starts_with_prefix($key, 'TCGA_')) {
              $label = substr($key, 5);
              $tcgaLabelMap[$label] = true;
              $tcgaSeriesMap[$geneName][$label] = (float)$value;
            }
          }
        }

        $gtexLabels = array_keys($gtexLabelMap);
        $tcgaLabels = array_keys($tcgaLabelMap);
        sort($gtexLabels, SORT_NATURAL | SORT_FLAG_CASE);
        sort($tcgaLabels, SORT_NATURAL | SORT_FLAG_CASE);
        $gtexTcgaPayload['gtex']['labels'] = $gtexLabels;
        $gtexTcgaPayload['tcga']['labels'] = $tcgaLabels;

        foreach ($gtexSeriesMap as $geneName => $labelToValue) {
          $values = [];
          foreach ($gtexLabels as $label) {
            $values[] = $labelToValue[$label] ?? null;
          }
          if (!empty($values)) {
            $gtexTcgaPayload['gtex']['series'][] = ['name' => $geneName, 'data' => $values];
          }
        }
        foreach ($tcgaSeriesMap as $geneName => $labelToValue) {
          $values = [];
          foreach ($tcgaLabels as $label) {
            $values[] = $labelToValue[$label] ?? null;
          }
          if (!empty($values)) {
            $gtexTcgaPayload['tcga']['series'][] = ['name' => $geneName, 'data' => $values];
          }
        }

        $hasGtex = count($gtexTcgaPayload['gtex']['series']) > 0 && count($gtexLabels) > 0;
        $hasTcga = count($gtexTcgaPayload['tcga']['series']) > 0 && count($tcgaLabels) > 0;
        $hasGtexTcga = ($hasGtex || $hasTcga);
      }
    }
  } catch (Throwable $e) {
    $gtexTcgaPayload = [
      'gtex' => ['labels' => [], 'series' => []],
      'tcga' => ['labels' => [], 'series' => []]
    ];
    $hasGtexTcga = false;
    $hasGtex = false;
    $hasTcga = false;
  }
}

$degTableData = [];
$degSignificantRows = [];
$degVolcanoData = ['other' => [], 'up' => [], 'down' => []];
$degCount = $isSingleCellDegEnabled ? 0 : $bulkDegCount;
$hasDegData = $isSingleCellDegEnabled ? false : $hasBulkDegData;
$degHeatmapDataByN = [];
$degDatasetId = '';
$degFilterText = '|log2FC| >= 1 & padj < 0.05';
$degVolcanoYLabel = '-log10(padj)';
if ($metaRow && !empty($metaRow['dataset_id'])) {
  $degDatasetId = (string)$metaRow['dataset_id'];
} elseif ($groupId !== '') {
  $degDatasetId = (string)$groupId;
}

$isNoiMethodForDeg = (!$isSingleCellDegEnabled && strcasecmp((string)$bulkMethod, 'NOISeq') === 0);
if ($isNoiMethodForDeg) {
  $degFilterText = '|log2FC| >= 1 & prob >= 0.95';
  $degVolcanoYLabel = 'prob';
}

if ($isSingleCellDegEnabled) {
  $degTableColumns = buildSingleCellDegColumns();
  $degColWidths = [20, 24, 14, 14, 14, 14];
  $degFilterText = '|log2FC| >= 1 & padj < 0.05';
  if ($singleCellDegDefaultPerturbation !== '') {
    try {
      $stmt = $scDegPdo->prepare(
        'SELECT g.gene_name, g.ensembl_id, s.log2fc, s.padj
         FROM sc_deg_significant s
         JOIN sc_deg_perturb p ON p.perturb_pk = s.perturb_fk
         JOIN sc_deg_dataset d ON d.dataset_pk = s.dataset_fk
         JOIN sc_deg_gene g ON g.gene_pk = s.gene_fk
         WHERE d.dataset_id = :dataset_id
           AND p.perturbation_gene = :perturbation_gene'
      );
      $stmt->bindValue(':dataset_id', $degDatasetId, PDO::PARAM_STR);
      $stmt->bindValue(':perturbation_gene', $singleCellDegDefaultPerturbation, PDO::PARAM_STR);
      $stmt->execute();
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $degCount = count($rows);
      $hasDegData = $degCount > 0;
      foreach ($rows as $row) {
        $log2fc = parseNullableFloat($row['log2fc'] ?? null);
        $padj = parseNullableFloat($row['padj'] ?? null);
        if ($log2fc === null || $padj === null || $padj < 0) {
          continue;
        }
        $geneLabel = trim((string)($row['gene_name'] ?? ''));
        if ($geneLabel === '') {
          $geneLabel = (string)($row['ensembl_id'] ?? '');
        }
        $point = [(float)$log2fc, (float)(-log(max($padj, 1e-300), 10)), $geneLabel, (float)$padj];
        $isSig = (abs($log2fc) >= 1.0 && $padj < 0.05);
        if ($isSig) {
          if ($log2fc > 0) {
            $degVolcanoData['up'][] = $point;
          } elseif ($log2fc < 0) {
            $degVolcanoData['down'][] = $point;
          } else {
            $degVolcanoData['other'][] = $point;
          }
        } else {
          $degVolcanoData['other'][] = $point;
        }
      }
    } catch (Throwable $e) {
      $degCount = 0;
      $hasDegData = false;
      $degVolcanoData = ['other' => [], 'up' => [], 'down' => []];
    }
  }
}

if (!$isSingleCellDegEnabled && $bulkPdo && $degDatasetId !== '') {
  try {
    $degStmt = $bulkPdo->prepare(
      'SELECT gene_name, ensembl_id, control_exp, treat_exp,
              ' . sqlRealExpr('base_mean') . ' AS base_mean,
              ' . sqlRealExpr('log2fc') . ' AS log2fc,
              ' . sqlRealExpr('pvalue') . ' AS pvalue,
              ' . sqlRealExpr('padj') . ' AS padj,
              value_str
       FROM bulk_deg_result
       WHERE dataset_id = :dataset_id'
    );
    $degStmt->bindValue(':dataset_id', $degDatasetId, PDO::PARAM_STR);
    $degStmt->execute();
    $degTableData = $degStmt->fetchAll();
    if ($isNoiMethodForDeg) {
      foreach ($degTableData as &$row) {
        $metrics = parseValueStrMetrics($row['value_str'] ?? '');
        $row['d'] = $metrics['d'] ?? null;
        $row['prob'] = $metrics['prob'] ?? null;
        if ($row['prob'] === null) {
          $pv = parseNullableFloat($row['pvalue'] ?? null);
          if ($pv !== null) {
            $row['prob'] = 1.0 - $pv;
          }
        }
      }
      unset($row);
    } else {
      foreach ($degTableData as &$row) {
        $row['d'] = null;
        $row['prob'] = null;
      }
      unset($row);
    }
    $hasDegData = count($degTableData) > 0;
    $degSignificantRows = array_values(array_filter($degTableData, function ($row) use ($isNoiMethodForDeg) {
      $log2fc = parseNullableFloat($row['log2fc'] ?? null);
      if ($log2fc === null || abs($log2fc) < 1) {
        return false;
      }
      if ($isNoiMethodForDeg) {
        $prob = parseNullableFloat($row['prob'] ?? null);
        return $prob !== null && $prob >= 0.95;
      }
      $padj = parseNullableFloat($row['padj'] ?? null);
      return $padj !== null && $padj < 0.05;
    }));
    $degCount = count($degSignificantRows);

    foreach ($degTableData as $row) {
      $log2fc = parseNullableFloat($row['log2fc'] ?? null);
      $padj = parseNullableFloat($row['padj'] ?? null);
      $prob = parseNullableFloat($row['prob'] ?? null);
      if ($log2fc === null) {
        continue;
      }
      $rawMetric = null;
      if ($isNoiMethodForDeg) {
        if ($prob === null) {
          continue;
        }
        $rawMetric = (float)$prob;
        $y = (float)$prob;
      } else {
        if ($padj === null || $padj < 0) {
          continue;
        }
        $rawMetric = (float)$padj;
        $y = -log(max($padj, 1e-300), 10);
      }
      $geneLabel = trim((string)($row['gene_name'] ?? ''));
      if ($geneLabel === '') {
        $geneLabel = (string)($row['ensembl_id'] ?? '');
      }
      $point = [(float)$log2fc, (float)$y, $geneLabel, $rawMetric];
      $isSig = false;
      if (abs($log2fc) >= 1) {
        if ($isNoiMethodForDeg) {
          $isSig = ($prob !== null && $prob >= 0.95);
        } else {
          $isSig = ($padj !== null && $padj < 0.05);
        }
      }
      if ($isSig) {
        if ($log2fc > 0) {
          $degVolcanoData['up'][] = $point;
        } elseif ($log2fc < 0) {
          $degVolcanoData['down'][] = $point;
        } else {
          $degVolcanoData['other'][] = $point;
        }
      } else {
        $degVolcanoData['other'][] = $point;
      }
    }
  } catch (Throwable $e) {
    $degTableData = [];
    $degSignificantRows = [];
    $degVolcanoData = ['other' => [], 'up' => [], 'down' => []];
    $degCount = 0;
    $hasDegData = false;
  }
}

if ($hasDegData && $metaRow) {
  try {
    $heatmapSamples = [];
    $buildGeneList = function (array $rows, int $n) use ($isNoiMethodForDeg): array {
      $filtered = array_values(array_filter($rows, function ($row) {
        $log2fc = parseNullableFloat($row['log2fc'] ?? null);
        return $log2fc !== null && abs($log2fc) >= 1;
      }));
      if ($isNoiMethodForDeg) {
        $filtered = array_values(array_filter($filtered, function ($row) {
          $prob = parseNullableFloat($row['prob'] ?? null);
          return $prob !== null && $prob >= 0.95;
        }));
      } else {
        $filtered = array_values(array_filter($filtered, function ($row) {
          $padj = parseNullableFloat($row['padj'] ?? null);
          return $padj !== null && $padj < 0.05;
        }));
      }
      if (empty($filtered)) {
        return [];
      }

      $byDesc = $filtered;
      usort($byDesc, function ($a, $b) {
        return (float)($b['log2fc'] ?? 0) <=> (float)($a['log2fc'] ?? 0);
      });
      $byAsc = $filtered;
      usort($byAsc, function ($a, $b) {
        return (float)($a['log2fc'] ?? 0) <=> (float)($b['log2fc'] ?? 0);
      });

      $maxUnique = count(array_unique(array_map(static fn($r) => (string)($r['ensembl_id'] ?? ''), $filtered)));
      $target = min($n, $maxUnique);
      $half = (int)floor($n / 2);

      $picked = [];
      $seen = [];
      $takeFrom = static function (array $source, int $limit) use (&$picked, &$seen) {
        if ($limit <= 0) {
          return;
        }
        $added = 0;
        foreach ($source as $row) {
          $gid = (string)($row['ensembl_id'] ?? '');
          if ($gid === '' || isset($seen[$gid])) {
            continue;
          }
          $seen[$gid] = true;
          $picked[] = $row;
          $added++;
          if ($added >= $limit) {
            break;
          }
        }
      };

      $takeFrom($byDesc, $half);
      $takeFrom($byAsc, $half);
      if (count($picked) < $target) {
        $takeFrom($byDesc, $target);
      }
      $picked = array_slice($picked, 0, $target);
      usort($picked, function ($a, $b) {
        $fa = parseNullableFloat($a['log2fc'] ?? null) ?? 0.0;
        $fb = parseNullableFloat($b['log2fc'] ?? null) ?? 0.0;
        $ga = $fa >= 0 ? 0 : 1; // up first, down second
        $gb = $fb >= 0 ? 0 : 1;
        if ($ga !== $gb) {
          return $ga <=> $gb;
        }
        if ($ga === 0) {
          // up genes: higher log2FC first
          return $fb <=> $fa;
        }
        // down genes: lower (more negative) log2FC first
        return $fa <=> $fb;
      });
      return $picked;
    };

    foreach ([10, 20, 30, 50] as $nVal) {
      $picked = $buildGeneList($degTableData, $nVal);
      $geneLabels = [];
      $directions = [];
      $values = [];
      $controlSampleSet = [];
      $treatSampleSet = [];
      $geneToSampleExpr = [];

      foreach ($picked as $idx => $row) {
        $geneLabel = trim((string)($row['gene_name'] ?? ''));
        if ($geneLabel === '') {
          $geneLabel = (string)($row['ensembl_id'] ?? '');
        }
        if ($geneLabel === '') {
          continue;
        }
        $controlMap = parseSampleExpressionMap($row['control_exp'] ?? '');
        $treatMap = parseSampleExpressionMap($row['treat_exp'] ?? '');
        $merged = $controlMap + $treatMap;
        if (empty($merged)) {
          continue;
        }
        $geneLabels[] = $geneLabel;
        $log2fc = parseNullableFloat($row['log2fc'] ?? null);
        $directions[] = ($log2fc !== null && $log2fc >= 0) ? 'up' : 'down';
        $geneToSampleExpr[] = $merged;
        foreach ($controlMap as $sid => $expr) {
          $controlSampleSet[$sid] = true;
        }
        foreach ($treatMap as $sid => $expr) {
          $treatSampleSet[$sid] = true;
        }
      }

      $controlSamples = array_keys($controlSampleSet);
      $treatSamples = array_keys($treatSampleSet);
      sort($controlSamples);
      sort($treatSamples);
      $samples = array_values(array_unique(array_merge($controlSamples, $treatSamples)));
      if (empty($heatmapSamples)) {
        $heatmapSamples = $samples;
      }
      $sampleIndexMap = [];
      foreach ($samples as $xIndex => $sid) {
        $sampleIndexMap[$sid] = $xIndex;
      }
      foreach ($geneToSampleExpr as $yIndex => $sampleExprMap) {
        foreach ($sampleExprMap as $sid => $expr) {
          if (!isset($sampleIndexMap[$sid])) {
            continue;
          }
          $value = log(max((float)$expr, 0.0) + 0.001, 2);
          $values[] = [$sampleIndexMap[$sid], $yIndex, $value];
        }
      }
      $degHeatmapDataByN[(string)$nVal] = [
        'samples' => $samples,
        'genes' => $geneLabels,
        'directions' => $directions,
        'values' => $values
      ];
    }
  } catch (Throwable $e) {
    $degHeatmapDataByN = [];
  }
}

$isBulkGoKeggEnabled = (!$isSingleCellTarget && $bulkGoKeggPdo !== null);
$isSingleCellGoKeggEnabled = ($isSingleCellTarget && $scGoKeggPdo !== null);
$isBulkGseaEnabled = (!$isSingleCellTarget && $bulkGseaPdo !== null);
$isSingleCellGseaEnabled = ($isSingleCellTarget && $scGseaPdo !== null);
$demoGoDataByOntology = ['BP' => [], 'CC' => [], 'MF' => []];
$demoKeggData = [];
$enrichmentKeys = ['ontology', 'description', 'fold_enrichment', 'count', 'score', 'overlap_ratio', 'direction'];
$demoGoPayload = ['keys' => $enrichmentKeys, 'data' => ['BP' => [], 'CC' => [], 'MF' => []]];
$demoKeggPayload = ['keys' => $enrichmentKeys, 'rows' => []];
if (!$isBulkGoKeggEnabled) {
  try {
    foreach (['BP', 'CC', 'MF'] as $ontology) {
      $demoGoDataByOntology[$ontology] = loadDemoEnrichmentRows($pdo, 'demo_go', $ontology, 20);
    }
    $demoKeggData = loadDemoEnrichmentRows($pdo, 'demo_kegg', null, 20);

    foreach (['BP', 'CC', 'MF'] as $ontology) {
      foreach ($demoGoDataByOntology[$ontology] as $row) {
        $demoGoPayload['data'][$ontology][] = [
          $row['ontology'] ?? $ontology,
          $row['description'] ?? '',
          $row['fold_enrichment'] ?? 0,
          $row['count'] ?? 0,
          $row['score'] ?? 0,
          0,
          ''
        ];
      }
    }
    foreach ($demoKeggData as $row) {
      $demoKeggPayload['rows'][] = [
        $row['ontology'] ?? '',
        $row['description'] ?? '',
        $row['fold_enrichment'] ?? 0,
        $row['count'] ?? 0,
        $row['score'] ?? 0,
        0,
        ''
      ];
    }
  } catch (Throwable $e) {
    $demoGoDataByOntology = ['BP' => [], 'CC' => [], 'MF' => []];
    $demoKeggData = [];
    $demoGoPayload = ['keys' => $enrichmentKeys, 'data' => ['BP' => [], 'CC' => [], 'MF' => []]];
    $demoKeggPayload = ['keys' => $enrichmentKeys, 'rows' => []];
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
  <title>PerturbCorpus Detail</title>
  <link href="static/lib/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet" />
  <link href="static/style.css?ver=<?php echo uniqid(); ?>" rel="stylesheet" />
  <style>
    .detail-shell {
      max-width: 1500px;
    }

    .detail-panel {
      background: rgba(255, 255, 255, 0.95);
      border: 1px solid rgba(204, 210, 220, 0.95);
      box-shadow: 0 12px 32px rgba(27, 43, 70, 0.06);
    }

    .detail-panel-title {
      display: flex;
      align-items: center;
      gap: 0.85rem;
      margin-bottom: 1rem;
    }

    .detail-panel-title h2,
    .detail-panel-title h3 {
      margin-bottom: 0;
      color: #222a35;
    }

    .detail-panel-accent {
      width: 4px;
      height: 28px;
      border-radius: 999px;
      background: #343a40;
      flex: 0 0 auto;
    }

    .detail-field {
      padding: 0.2rem 0;
      color: #1f2937;
      font-size: 1.05rem;
    }

    .detail-field strong {
      color: #374151;
    }

    .accession-scroll {
      display: inline-block;
      max-width: 100%;
      overflow-x: auto;
      white-space: nowrap;
      padding-bottom: 2px;
    }

    .accession-scroll.long {
      max-width: 360px;
    }

    .section-topbar {
      border: 1px solid #d3d7de;
      border-bottom: none;
      border-radius: 10px 10px 0 0;
      background: #f8f9fb;
      padding: 0.65rem 1rem;
      font-size: 1.6rem;
      font-weight: 800;
      color: #222a35;
    }

    .section-body {
      border: 1px solid #d3d7de;
      border-radius: 0 0 10px 10px;
      background: #ffffff;
      padding: 1.25rem;
    }

    .detail-table {
      border: 1px solid #d5dae2;
      border-radius: 0;
      overflow: hidden;
      box-shadow: none;
    }

    .detail-table table {
      margin-bottom: 0;
    }

    .detail-table thead th {
      background: #25292f;
      color: #fff;
      border-color: #25292f;
      font-weight: 700;
      vertical-align: middle;
      padding: 0.95rem 1rem;
    }

    .detail-table tbody td {
      padding: 0.9rem 1rem;
      border-color: #dde2e8;
      color: #1f2937;
      font-size: 1rem;
    }

    .detail-table tbody tr:nth-child(odd) {
      background: #f6f8fb;
    }

    .detail-table tbody tr:hover {
      background: #eef4ff;
    }

    .target-table thead th {
      background: linear-gradient(
        90deg,
        rgb(216, 241, 252) 0%,
        rgb(231, 247, 254) 100%
      ) !important;
      color: var(--sky-dim) !important;
      border-bottom: 2px solid rgba(14, 165, 233, 0.25) !important;
      font-weight: 700;
      letter-spacing: 0.6px;
      text-transform: uppercase;
      font-size: 0.82rem;
      vertical-align: middle;
      position: sticky !important;
      top: 0 !important;
      z-index: 2 !important;
      background-clip: padding-box;
    }

    .target-table tbody tr:nth-child(even) {
      background: #f6f8fb;
    }

    .target-table td {
      color: #1f2937;
    }

    .target-table tbody tr:hover {
      background: #eef4ff;
    }

    .target-table th,
    .target-table td {
      padding-top: 0.55rem;
      padding-bottom: 0.55rem;
    }

    .target-table-scroll {
      max-height: 560px;
      overflow-y: auto;
      overflow-x: auto;
      border: 1px solid #d5dae2;
      border-radius: 0;
      background: #fff;
    }

    .chart-wrap {
      position: relative;
      width: 100%;
      height: 420px;
    }

    .volcano-wrap {
      position: relative;
      width: 100%;
      height: 360px;
    }

    .heatmap-wrap {
      position: relative;
      width: 100%;
      height: 420px;
    }

    #expressionDensityChart {
      width: 100%;
      height: 100%;
    }

    #demoVolcanoChart {
      width: 100%;
      height: 100%;
    }

    #degHeatmapChart {
      width: 100%;
      height: 100%;
    }

    .deg-heatmap-controls {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin-bottom: 0.75rem;
    }

    .heatmap-note {
      display: flex;
      justify-content: flex-end;
      gap: 0.75rem;
      font-size: 0.75rem;
      margin-top: -0.25rem;
      margin-bottom: 0.5rem;
    }

    .heatmap-note span {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
    }

    .heatmap-dot {
      width: 8px;
      height: 8px;
      border-radius: 999px;
      display: inline-block;
    }

    .deg-controls {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: center;
      margin-bottom: 0.75rem;
    }

    .deg-controls select,
    .deg-controls button {
      font-size: 0.9rem;
    }

    .deg-pagination {
      display: flex;
      gap: 0.5rem;
      align-items: center;
      justify-content: flex-end;
      margin-top: 0.75rem;
    }

    .deg-table {
      table-layout: fixed;
      width: 100%;
    }

    .deg-table th,
    .deg-table td {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .deg-sort {
      cursor: pointer;
      user-select: none;
      white-space: nowrap;
      position: relative;
      padding-right: 28px;
    }

    .deg-sort-indicator {
      display: inline-flex;
      flex-direction: column;
      gap: 2px;
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
    }

    .deg-sort-arrow {
      width: 0;
      height: 0;
      border-left: 4px solid transparent;
      border-right: 4px solid transparent;
    }

    .deg-sort-arrow.up {
      border-bottom: 6px solid #9ca3af;
    }

    .deg-sort-arrow.down {
      border-top: 6px solid #9ca3af;
    }

    .deg-sort:hover .deg-sort-arrow {
      border-bottom-color: #374151;
      border-top-color: #374151;
    }

    .deg-sort[data-active="asc"] .deg-sort-arrow.up,
    .deg-sort[data-active="desc"] .deg-sort-arrow.down {
      border-bottom-color: #111827;
      border-top-color: #111827;
    }

    .deg-expand {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 0.5rem;
      margin-top: 0.5rem;
    }

    .deg-expand button {
      font-size: 0.9rem;
    }

    .deg-collapse {
      display: none;
      justify-content: center;
      margin-bottom: 0.75rem;
    }

    .deg-collapse button {
      font-size: 0.9rem;
      color: #d1d5db;
    }

    .deg-collapse button:hover {
      color: #6b7280;
    }

    .deg-expand-hint {
      font-size: 0.75rem;
      color: #1f2937;
      padding: 0.15rem 0.5rem;
      border-radius: 999px;
      background: linear-gradient(120deg, #eef2ff, #e0f2fe);
    }

    .enrichment-shell {
      border: 1px solid #d5dae2;
      background: #fff;
      border-radius: 0.75rem;
      padding: 1rem;
    }

    .enrichment-tabbar,
    .enrichment-ontologybar {
      display: flex;
      justify-content: center;
      gap: 0.5rem;
      flex-wrap: wrap;
      margin-bottom: 0.85rem;
    }

    .enrichment-chart-wrap {
      position: relative;
      width: 100%;
      height: 560px;
    }

    .enrichment-chart {
      width: 100%;
      height: 100%;
    }

    .singlecell-chart-wrap {
      position: relative;
      width: 100%;
      height: 260px;
      background: #fbfcfe;
      border: 1px solid #e5e7eb;
      border-radius: 10px;
      padding: 6px;
    }


    .kpi-card {
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .kpi-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    
    .enrichment-empty {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 240px;
      color: #6b7280;
      background: #f9fafb;
      border: 1px dashed #d1d5db;
      border-radius: 0.75rem;
    }

    @media (max-width: 768px) {
      .chart-wrap {
        height: 300px;
      }
    }
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

  <main class="layout-page">
    <div class="container-fluid py-2 pt-2 detail-shell">
      <div class="row justify-content-center">
        <div class="col-12 bg-white border rounded-3 shadow-sm p-3 p-md-4 p-lg-5 detail-panel">
          <?php if ($error): ?>
            <div class="alert alert-warning mb-0"><?php echo h($error); ?></div>
          <?php else: ?>
            <div class="row g-4 mb-4">
              <div class="col-12 col-lg-6">
                <section class="detail-panel rounded-3 p-4 h-100">
                  <div class="detail-panel-title">
                    <span class="detail-panel-accent"></span>
                    <h2 class="h4 fw-semibold">Dataset Overview</h2>
                  </div>
                  <div class="detail-table">
                    <table class="table table-borderless align-middle mb-0">
                      <tbody>
                        <tr>
                          <td class="fw-semibold" style="width: 42%;">Dataset ID</td>
                          <td><?php echo h($metaRow['dataset_id'] ?? ''); ?></td>
                        </tr>
                        <tr>
                  <td class="fw-semibold">External Series Accession</td>
                          <td class="text-break"><?php echo formatFieldValue('external_series_accession', $metaRow['external_series_accession'] ?? ''); ?></td>
                        </tr>
                        <?php
                        $dsId = $metaRow['dataset_id'] ?? '';
                        $isScDataset = starts_with_prefix($dsId, 'HSSC') || starts_with_prefix($dsId, 'MMSC');
                        ?>
                        <?php if ($isScDataset): ?>
                        <tr>
                          <td class="fw-semibold">External Sample Accession</td>
                          <td class="text-break">
                            <span class="accession-scroll"><?php echo formatFieldValue('single_cell_pertubation_external_sample_accession', $metaRow['single_cell_pertubation_external_sample_accession'] ?? ''); ?></span>
                          </td>
                        </tr>
                        <?php else: ?>
                        <tr>
                          <td class="fw-semibold">External Control Sample Accession</td>
                          <td class="text-break">
                            <span class="accession-scroll <?php echo hasManySamples($metaRow['external_sample_control_accession'] ?? '') ? 'long' : ''; ?>"><?php echo formatFieldValue('external_sample_control_accession', $metaRow['external_sample_control_accession'] ?? ''); ?></span>
                          </td>
                        </tr>
                        <tr>
                          <td class="fw-semibold">External Treatment Sample Accession</td>
                          <td class="text-break">
                            <span class="accession-scroll <?php echo hasManySamples($metaRow['external_sample_treatment_accession'] ?? '') ? 'long' : ''; ?>"><?php echo formatFieldValue('external_sample_treatment_accession', $metaRow['external_sample_treatment_accession'] ?? ''); ?></span>
                          </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                          <td class="fw-semibold">Assay Scale</td>
                          <td><?php
                            $assayScaleRaw = strtolower(trim((string)($metaRow['meta_assay_scale'] ?? '')));
                            if ($assayScaleRaw === 'single cell') echo 'Single Cell';
                            elseif ($assayScaleRaw === 'bulk') echo 'Bulk';
                            else echo h($metaRow['meta_assay_scale'] ?? '');
                          ?></td>
                        </tr>
                        <tr>
                          <td class="fw-semibold">Species</td>
                          <td><?php echo h($metaRow['meta_biosample_species'] ?? ''); ?></td>
                        </tr>
                        <tr>
                          <td class="fw-semibold">Classification</td>
                          <td><?php echo h($metaRow['meta_biosample_classification_type'] ?? ''); ?></td>
                        </tr>
                        <tr>
                          <td class="fw-semibold">Tissue</td>
                          <td><?php echo h($metaRow['meta_biosample_tissue_name'] ?? ''); ?></td>
                        </tr>
                        <tr>
                          <td class="fw-semibold">Biosample Description</td>
                          <td><?php echo h($metaRow['meta_biosample_description'] ?? ''); ?></td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </section>


              </div>
              <div class="col-12 col-lg-6">
                <?php if ($isSingleCellTarget): ?>
                  <section id="scGeneFreqSection" class="detail-panel rounded-3 p-4 h-100">
                    <div class="detail-panel-title">
                      <span class="detail-panel-accent"></span>
                      <h2 class="h4 fw-semibold">Cell Count Per Perturbation</h2>
                      <span id="scGeneFreqBadge" class="badge text-bg-light border ms-auto">Loading...</span>
                    </div>
                    <div class="chart-wrap">
                      <div id="singleCellGeneFreqChart" style="width: 100%; height: 100%;"></div>
                    </div>
                    <div id="singleCellGeneFreqEmpty" class="text-muted mt-3 d-none">No cell count data available for this dataset.</div>
                  </section>
                <?php else: ?>
                  <section id="expressionDensitySection" class="detail-panel rounded-3 p-4 h-100">
                    <div class="detail-panel-title">
                      <span class="detail-panel-accent"></span>
                      <h2 class="h4 fw-semibold">Expression Density</h2>
                    </div>
                    <div class="chart-wrap">
                      <div id="expressionDensityChart"></div>
                    </div>
                    <?php if (!$hasDemoData): ?>
                      <div class="text-muted mt-3">No expression data found for the specified samples.</div>
                    <?php endif; ?>
                  </section>
                <?php endif; ?>
              </div>
            </div>

            <?php if ($isSingleCellTarget): ?>
            <section id="scPerturbDetailSection" class="detail-panel rounded-3 p-3 p-lg-4 mt-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">Perturbation Details</h2>
              </div>
              <div class="fs-5 lh-base text-body mb-3 detail-field"><strong>Number of Perturbed Genes:</strong> <?php echo h($targetGeneNumber); ?></div>

              <div class="table-responsive target-table-scroll">
                <table class="table target-table align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Perturbed Gene Name</th>
                      <th>Gene Type</th>
                      <th>Ensembl ID</th>
                      <th>Assay Type</th>
                      <th>Cell Count</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (count($expandedTargetRows) === 0): ?>
                      <tr>
                        <td colspan="5" class="text-center text-muted py-4">No target gene records found.</td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($expandedTargetRows as $item): ?>
                        <tr>
                          <td><?php echo h($item['meta_assay_target_gene_name'] ?? ''); ?></td>
                          <td><?php echo h($item['meta_assay_target_gene_type'] ?? ''); ?></td>
                          <td><?php echo h($item['meta_assay_target_gene_ensembl_id'] ?? ''); ?></td>
                          <td><?php echo h($item['meta_assay_type'] ?? ''); ?></td>
                          <td><?php echo isset($item['cell_count']) && $item['cell_count'] !== null ? number_format((int)$item['cell_count']) : '-'; ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </section>
            <?php endif; ?>

            <?php 
            if (isset($dsId) && (starts_with_prefix($dsId, 'HSSC') || starts_with_prefix($dsId, 'MMSC'))): 
            ?>
            <section id="scLibraryMetricsSection" class="detail-panel rounded-3 p-3 p-lg-4 mb-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">Library Quality Metrics</h2>
              </div>
              <div class="row g-3">
                <div class="col-12 col-xl-8">
                  <div class="row g-3 h-100">
                    <?php
                    $kpis = [
                      'Total Genes Detected' => $metaRow['single_cell_pertubation_total_genes_detected'] ?? '',
                      'Median Genes per Cell' => $metaRow['single_cell_pertubation_median_genes_per_cell'] ?? '',
                      'Raw Cell Count' => $metaRow['single_cell_pertubation_raw_cell_count'] ?? '',
                      'Filtered Cell Count' => $metaRow['single_cell_pertubation_filtered_cell_count'] ?? '',
                      'Number of Reads' => $metaRow['single_cell_pertubation_number_of_reads'] ?? '',
                      'Mean Reads per Cell' => $metaRow['single_cell_pertubation_mean_reads_per_cell'] ?? ''
                    ];
                    foreach ($kpis as $label => $val):
                    ?>
                    <div class="col-6 col-sm-4" style="height: 50%;">
                      <div class="kpi-card p-3 border rounded-3 text-center h-100 d-flex flex-column justify-content-center" style="background-color: #fcfcfc;">
                        <div class="text-muted small mb-1"><?php echo h($label); ?></div>
                        <div class="fs-4 fw-bold text-dark"><?php echo formatKpiValue($val); ?></div>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="col-12 col-xl-4">
                  <div class="row g-3 h-100">
                    <div class="col-12 col-sm-6 col-xl-12" style="height: 50%;">
                       <div class="kpi-card border rounded-3 d-flex flex-column align-items-center justify-content-center h-100 p-2" style="background-color: #fcfcfc;">
                           <div id="chartSeqSat" style="width:100%; height:180px;"></div>
                       </div>
                    </div>
                    <div class="col-12 col-sm-6 col-xl-12" style="height: 50%;">
                       <div class="kpi-card border rounded-3 d-flex flex-column align-items-center justify-content-center h-100 p-2" style="background-color: #fcfcfc;">
                           <div id="chartFracRead" style="width:100%; height:180px;"></div>
                       </div>
                    </div>
                  </div>
                </div>
              </div>
            </section>

            <?php if ($isSingleCellTarget): ?>
            <section id="scQcDistSection" class="detail-panel rounded-3 p-3 p-lg-4 mb-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">Single Cell QC Distributions</h2>
              </div>
              <div class="row g-3">
                <div class="col-12 col-lg-4">
                  <div class="singlecell-chart-wrap" id="chartSingleCellGenes"></div>
                </div>
                <div class="col-12 col-lg-4">
                  <div class="singlecell-chart-wrap" id="chartSingleCellCounts"></div>
                </div>
                <div class="col-12 col-lg-4">
                  <div class="singlecell-chart-wrap" id="chartSingleCellMito"></div>
                </div>
              </div>
              <div id="singleCellDistEmpty" class="text-muted mt-2 d-none">No single-cell QC distribution data available for this dataset.</div>
            </section>
            <?php endif; ?>

            <?php if ($isSingleCellTarget): ?>
            <section id="scPerturbStatesSection" class="detail-panel rounded-3 p-3 p-lg-4 mb-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">Perturbation States</h2>
              </div>
              <div class="row g-3">
                <div id="perturbStatePieCol" class="col-12 col-lg-4">
                  <div class="chart-wrap" style="height: 320px;">
                    <div id="perturbStatePieChart" style="width: 100%; height: 100%;"></div>
                  </div>
                </div>
                <div id="perturbStateBarCol" class="col-12 col-lg-8">
                  <div class="chart-wrap" style="height: 320px;">
                    <div id="perturbStateBarChart" style="width: 100%; height: 100%;"></div>
                  </div>
                </div>
              </div>
              <div id="perturbStateEmpty" class="text-muted mt-2 d-none">No perturbation state data available for this dataset.</div>
            </section>
            <?php endif; ?>

            <?php if ($isSingleCellTarget): ?>
            <section id="scUmapSection" class="detail-panel rounded-3 p-3 p-lg-4 mb-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">Single Cell Sequencing UMAP Projections</h2>
                <span id="singleCellUmapBadge" class="badge text-bg-light border ms-auto">Loading...</span>
              </div>
              <div class="row g-3 mb-3">
                <div class="col-12 col-lg-6">
                  <div class="small text-muted mb-2 fw-semibold">Cluster (fixed)</div>
                  <div class="chart-wrap" style="height: 430px;">
                    <div id="singleCellUmapClusterChart" style="width: 100%; height: 100%;"></div>
                  </div>
                </div>
                <div class="col-12 col-lg-6">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="small text-muted fw-semibold">Custom Label</div>
                    <select id="singleCellUmapLabelSelect" class="form-select form-select-sm" style="width: 300px;">
                      <option value="gene" selected>Show as Perturbed Genes</option>
                      <option value="mixscape">Show as Mixscape Classification</option>
                    </select>
                  </div>
                  <div class="chart-wrap" style="height: 430px;">
                    <div id="singleCellUmapLabelChart" style="width: 100%; height: 100%;"></div>
                  </div>
                </div>
              </div>
              <div id="singleCellUmapEmpty" class="text-muted mt-2 d-none">No single-cell UMAP data available for this dataset.</div>
            </section>
            <?php endif; ?>

            <?php if ($isSingleCellTarget): ?>
            <section id="scPerturbEnrichSection" class="detail-panel rounded-3 p-3 p-lg-4 mb-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">Enrichment and Distribution of Perturbation Cells across Cell Clusters</h2>
              </div>
              <div class="row g-3">
                <div class="col-12 col-lg-6">
                  <div class="chart-wrap" style="height: 460px;">
                    <div id="singleCellEnrichLog2orChart" style="width: 100%; height: 100%;"></div>
                  </div>
                </div>
                <div class="col-12 col-lg-6">
                  <div class="chart-wrap" style="height: 460px;">
                    <div id="singleCellEnrichFracChart" style="width: 100%; height: 100%;"></div>
                  </div>
                </div>
              </div>
              <div id="singleCellEnrichEmpty" class="text-muted mt-2 d-none">No perturbation enrichment data available for this dataset.</div>
            </section>
            <?php endif; ?>

            <?php endif; ?>

            <?php if (!$isSingleCellTarget): ?>
            <section class="detail-panel rounded-3 p-3 p-lg-4 mt-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">Perturbation Details</h2>
              </div>
              <div class="fs-5 lh-base text-body mb-3 detail-field"><strong>Number of Perturbed Genes:</strong> <?php echo h($targetGeneNumber); ?></div>

              <div class="table-responsive target-table-scroll">
                <table class="table target-table align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Perturbed Gene Name</th>
                      <th>Assay Type</th>
                      <th>Gene Type</th>
                      <th>Ensembl ID</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (count($expandedTargetRows) === 0): ?>
                      <tr>
                        <td colspan="4" class="text-center text-muted py-4">No target gene records found.</td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($expandedTargetRows as $item): ?>
                        <tr>
                          <td><?php echo h($item['meta_assay_target_gene_name'] ?? ''); ?></td>
                          <td><?php echo h($item['meta_assay_type'] ?? ''); ?></td>
                          <td><?php echo h($item['meta_assay_target_gene_type'] ?? ''); ?></td>
                          <td><?php echo h($item['meta_assay_target_gene_ensembl_id'] ?? ''); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </section>
            <?php endif; ?>

            <?php if ($hasGtexTcga): ?>
            <section class="detail-panel rounded-3 p-3 p-lg-4 mt-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">Tissue/Cancer Expression of Perturbed Genes (GTEx/TCGA)</h2>
              </div>
              <div class="row g-3">
                <div class="col-12 col-lg-6">
                  <div class="chart-wrap" style="height: 360px;">
                    <div id="gtexExpressionChart" style="width: 100%; height: 100%;"></div>
                  </div>
                  <?php if (!$hasGtex): ?>
                    <div class="text-muted mt-2">No GTEx expression data available for target genes.</div>
                  <?php endif; ?>
                </div>
                <div class="col-12 col-lg-6">
                  <div class="chart-wrap" style="height: 360px;">
                    <div id="tcgaExpressionChart" style="width: 100%; height: 100%;"></div>
                  </div>
                  <?php if (!$hasTcga): ?>
                    <div class="text-muted mt-2">No TCGA expression data available for target genes.</div>
                  <?php endif; ?>
                </div>
              </div>
            </section>
            <?php endif; ?>

            <section id="degTableSection" class="detail-panel rounded-3 p-3 p-lg-4 mt-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">Table of Differentially Expressed Genes (DEGs)</h2>
                <span id="degGeneCountBadge" class="badge text-bg-light border ms-auto">Number of Differentially Expressed Genes: <?php echo (int)$degCount; ?></span>
              </div>
              <div class="d-flex align-items-center mb-2 flex-wrap gap-2">
                <label class="text-muted small mb-0 me-2">Page size:</label>
                <select id="degPageSize" class="form-select form-select-sm" style="width: 120px;">
                  <option value="10" selected>10</option>
                  <option value="20">20</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <?php if ($isSingleCellDegEnabled): ?>
                  <label class="text-muted small mb-0 ms-2" for="scDegPerturbSelect">Perturbed Gene:</label>
                  <a class="small text-decoration-none" href="faq.php#correlation" target="_blank" rel="noopener" title="Shown genes satisfy cell count >= 10 and have differential expression results under current filters."></a>
                  <select id="scDegPerturbSelect" class="form-select form-select-sm" style="min-width: 260px; max-width: 420px;">
                    <?php foreach ($singleCellDegPerturbations as $pert): ?>
                      <option value="<?php echo h($pert); ?>" <?php echo ($pert === $singleCellDegDefaultPerturbation) ? 'selected' : ''; ?>>
                        <?php echo h($pert); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
                <span class="text-muted small ms-3">Filter: <?php echo h($degFilterText); ?></span>
              </div>
              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0 deg-table">
                  <colgroup>
                    <?php foreach ($degColWidths as $width): ?>
                      <col style="width: <?php echo (float)$width; ?>%;" />
                    <?php endforeach; ?>
                  </colgroup>
                  <thead>
                    <tr>
                      <?php foreach ($degTableColumns as $col): ?>
                        <?php if (!empty($col['sortable'])): ?>
                          <th class="deg-sort" data-key="<?php echo h($col['key']); ?>">
                            <span class="deg-sort-label"><?php echo h($col['label']); ?></span>
                            <span class="deg-sort-indicator"><span class="deg-sort-arrow up"></span><span class="deg-sort-arrow down"></span></span>
                          </th>
                        <?php else: ?>
                          <th><?php echo h($col['label']); ?></th>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </tr>
                  </thead>
                  <tbody id="degTableBody">
                    <?php if (!$hasBulkDegData): ?>
                      <tr>
                        <td colspan="<?php echo count($degTableColumns); ?>" class="text-center text-muted py-3">No differentially expressed genes</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <div class="deg-pagination" id="degPagination" style="display: none;">
                <button class="btn btn-outline-secondary btn-sm" type="button" id="degPrevBtn">Prev</button>
                <span class="text-muted small" id="degPageInfo"></span>
                <button class="btn btn-outline-secondary btn-sm" type="button" id="degNextBtn">Next</button>
              </div>
              <!-- removed expand/collapse controls; pagination controlled by page-size selector -->
            </section>
            
            <section id="degVolcanoSection" class="detail-panel rounded-3 p-3 p-lg-4 mt-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">Distribution of Up and Down Regulated DEGs</h2>
              </div>
              <div class="text-muted small mb-2">Filter: <?php echo h($degFilterText); ?></div>
              <div class="volcano-wrap">
                <div id="demoVolcanoChart"></div>
              </div>
              <?php if (!$hasDegData): ?>
                <div class="text-muted mt-3">No DEG data found for this dataset.</div>
              <?php endif; ?>
            </section>

            <?php if (!$isSingleCellTarget): ?>
            <section id="degHeatmapSection" class="detail-panel rounded-3 p-3 p-lg-4 mt-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">Expression Heatmap of Differentially Expressed Genes</h2>
              </div>
              <div class="heatmap-note">
                <span><i class="heatmap-dot" style="background:#ef4444;"></i> Up-regulated genes</span>
                <span><i class="heatmap-dot" style="background:#2563eb;"></i> Down-regulated genes</span>
                <span><i class="heatmap-dot" style="background:#10b981;"></i> Control samples</span>
                <span><i class="heatmap-dot" style="background:#f59e0b;"></i> Treatment samples</span>
              </div>
              <div class="deg-heatmap-controls">
                <label class="form-label mb-0" for="degHeatmapN">Top/Bottom</label>
                <select id="degHeatmapN" class="form-select form-select-sm" style="width: 120px;">
                  <option value="10">10 genes</option>
                  <option value="20" selected>20 genes</option>
                  <option value="30">30 genes</option>
                  <option value="50">50 genes</option>
                </select>
                <span class="text-muted small">Filter: <?php echo h($degFilterText); ?></span>
              </div>
              <div class="heatmap-wrap">
                <div id="degHeatmapChart"></div>
              </div>
              <?php if (!$hasDegData): ?>
                <div class="text-muted mt-3">No DEG data found for this dataset.</div>
              <?php endif; ?>
            </section>
            <?php endif; ?>

            <section id="enrichmentSection" class="detail-panel rounded-3 p-3 p-lg-4 mt-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">GO/KEGG Enrichment Analysis</h2>
                <div class="d-flex align-items-center ms-auto gap-2">
                  <label class="text-muted small mb-0" for="bulkEnrichDirectionSelect">Direction</label>
                  <select id="bulkEnrichDirectionSelect" class="form-select form-select-sm" style="width: 150px;">
                    <option value="up">Up-regulated</option>
                    <option value="down">Down-regulated</option>
                    <option value="all" selected>All</option>
                  </select>
                </div>
              </div>
              <div class="enrichment-shell">
                <div class="enrichment-tabbar">
                  <button type="button" class="btn btn-primary btn-sm" data-enrichment-tab="go">GO Enrichment</button>
                  <button type="button" class="btn btn-outline-primary btn-sm" data-enrichment-tab="kegg">KEGG Enrichment</button>
                </div>

                <div id="goEnrichmentPanel">
                  <div class="enrichment-ontologybar">
                    <button type="button" class="btn btn-primary btn-sm" data-go-ontology="BP">Biological Process</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-go-ontology="CC">Cellular Component</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-go-ontology="MF">Molecular Function</button>
                  </div>
                  <div class="enrichment-chart-wrap">
                    <div id="goEnrichmentChart" class="enrichment-chart"></div>
                  </div>
                  <div class="text-muted small mt-2">Top 20 terms with p.adjust &lt; 0.05. Bubble size reflects Overlap Gene Ratio.</div>
                </div>

                <div id="keggEnrichmentPanel" class="d-none">
                  <div class="enrichment-chart-wrap">
                    <div id="keggEnrichmentChart" class="enrichment-chart"></div>
                  </div>
                  <div class="text-muted small mt-2">Top 20 KEGG pathways with p.adjust &lt; 0.05. Bubble size reflects Overlap Gene Ratio.</div>
                </div>
              </div>
              <?php if (!$hasDegData): ?>
                <div class="text-muted mt-3">No demo enrichment data found.</div>
              <?php endif; ?>
            </section>

            <section id="gseaSection" class="detail-panel rounded-3 p-3 p-lg-4 mt-4">
              <div class="detail-panel-title">
                <span class="detail-panel-accent"></span>
                <h2 class="h4 fw-semibold">Gene Set Enrichment Analysis (GSEA) against MSigDB Hallmark</h2>
              </div>
              <div class="text-muted small mb-2">FDR cutoff: 0.25 (up/down pathways shown together). NES (Normalized Enrichment Score) measures the magnitude and direction of gene set enrichment.</div>
              <div class="row g-3">
                <div class="col-12 col-lg-6">
                  <div class="chart-wrap" style="height: 420px;">
                    <div id="gseaNesChart" style="width: 100%; height: 100%;"></div>
                  </div>
                </div>
                <div class="col-12 col-lg-6">
                  <div class="chart-wrap" style="height: 420px;">
                    <div id="gseaMapChart" style="width: 100%; height: 100%;"></div>
                  </div>
                </div>
              </div>
              <div id="gseaEmpty" class="text-muted mt-2 d-none">No GSEA data available for current selection.</div>
            </section>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>

  <footer class="py-3 mt-auto text-center" style="background: transparent; border: none; width: 100%;">
    <div class="container-fluid px-4">
    <div class="footer-text-small-muted">&copy; <span id="year"></span> <a class="footer-link" href="https://www.zhaopage.com">Zhao Lab</a>. All rights reserved.</div>
    </div>
  </footer>

  <script src="static/lib/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js"></script>
  <script>

    const expressionSeriesBySample = <?php echo safe_json_encode($expressionValuesBySample, JSON_UNESCAPED_UNICODE); ?>;
    const expressionSampleGroups = <?php echo safe_json_encode($expressionSampleGroups, JSON_UNESCAPED_UNICODE); ?>;
    const expressionSamples = <?php echo safe_json_encode($expressionSamples, JSON_UNESCAPED_UNICODE); ?>;
    const scSatStr = <?php echo safe_json_encode($metaRow['single_cell_pertubation_sequencing_saturation'] ?? '0%', JSON_UNESCAPED_UNICODE); ?>;
    const scFracStr = <?php echo safe_json_encode($metaRow['single_cell_pertubation_fraction_reads_in_cells'] ?? '0%', JSON_UNESCAPED_UNICODE); ?>;
    const degCount = <?php echo (int)$degCount; ?>;
    const degVolcanoData = <?php echo safe_json_encode($degVolcanoData, JSON_UNESCAPED_UNICODE); ?>;
    const degHeatmapDataByN = <?php echo safe_json_encode($degHeatmapDataByN, JSON_UNESCAPED_UNICODE); ?>;
    const degVolcanoYLabel = <?php echo safe_json_encode($degVolcanoYLabel, JSON_UNESCAPED_UNICODE); ?>;
    const degIsNoiMethod = <?php echo safe_json_encode($isNoiMethodForDeg, JSON_UNESCAPED_UNICODE); ?>;
    const degTableColumns = <?php echo safe_json_encode($degTableColumns, JSON_UNESCAPED_UNICODE); ?>;
    const degDatasetId = <?php echo safe_json_encode($metaRow['dataset_id'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
    const isSingleCellDegEnabled = <?php echo safe_json_encode($isSingleCellDegEnabled, JSON_UNESCAPED_UNICODE); ?>;
    const scDegPerturbations = <?php echo safe_json_encode($singleCellDegPerturbations, JSON_UNESCAPED_UNICODE); ?>;
    const scDegDefaultPerturbation = <?php echo safe_json_encode($singleCellDegDefaultPerturbation, JSON_UNESCAPED_UNICODE); ?>;
    const scDegDefaultRegulation = <?php echo safe_json_encode($singleCellDegDefaultRegulation, JSON_UNESCAPED_UNICODE); ?>;
    const isBulkGoKeggEnabled = <?php echo safe_json_encode($isBulkGoKeggEnabled, JSON_UNESCAPED_UNICODE); ?>;
    const isSingleCellGoKeggEnabled = <?php echo safe_json_encode($isSingleCellGoKeggEnabled, JSON_UNESCAPED_UNICODE); ?>;
    const isBulkGseaEnabled = <?php echo safe_json_encode($isBulkGseaEnabled, JSON_UNESCAPED_UNICODE); ?>;
    const isSingleCellGseaEnabled = <?php echo safe_json_encode($isSingleCellGseaEnabled, JSON_UNESCAPED_UNICODE); ?>;
    const demoGoPayload = <?php echo safe_json_encode($demoGoPayload, JSON_UNESCAPED_UNICODE); ?>;
    const demoKeggPayload = <?php echo safe_json_encode($demoKeggPayload, JSON_UNESCAPED_UNICODE); ?>;
    const singleCellDistData = <?php echo safe_json_encode($singleCellDistData, JSON_UNESCAPED_UNICODE); ?>;
    const pageDatasetId = <?php echo safe_json_encode($metaRow['dataset_id'] ?? '', JSON_UNESCAPED_UNICODE); ?>;
    const gtexTcgaPayload = <?php echo safe_json_encode($gtexTcgaPayload, JSON_UNESCAPED_UNICODE); ?>;
    const isSingleCellTargetPage = <?php echo safe_json_encode($isSingleCellTarget, JSON_UNESCAPED_UNICODE); ?>;

    const setSectionVisible = (sectionId, visible) => {
      const el = document.getElementById(sectionId);
      if (!el) return;
      el.classList.toggle('d-none', !visible);
    };

    // Use log2(tpm + 0.001) and keep two decimal places.
    const logTransform = (values) => values.map((v) => Number(Math.log2(v + 0.001).toFixed(2)));
    const available = expressionSamples.filter((k) => expressionSeriesBySample[k] && expressionSeriesBySample[k].length > 0);
    if (!isSingleCellTargetPage) {
      setSectionVisible('expressionDensitySection', available.length > 0);
    }

    if (available.length > 0) {
      const chartEl = document.getElementById('expressionDensityChart');
      if (chartEl) {
      const allValues = available.flatMap((k) => logTransform(expressionSeriesBySample[k]));
      const minX = Math.min(...allValues);
      const maxX = Math.max(...allValues);
      const points = 120;
      const range = maxX - minX || 1;
      const step = range / (points - 1);
      const labels = Array.from({ length: points }, (_, i) => minX + step * i);

      const gaussianKernel = (u) => Math.exp(-0.5 * u * u) / Math.sqrt(2 * Math.PI);
      const estimateBandwidth = (values) => {
        const n = values.length || 1;
        const mean = values.reduce((acc, v) => acc + v, 0) / n;
        const variance = values.reduce((acc, v) => acc + (v - mean) ** 2, 0) / n;
        const std = Math.sqrt(variance) || 1;
        return 1.06 * std * Math.pow(n, -0.2);
      };

      const palettes = {
        control: ['#2563eb', '#1d4ed8', '#60a5fa', '#93c5fd'],
        treatment: ['#ef4444', '#dc2626', '#f97316', '#fb7185']
      };
      const paletteIndex = { control: 0, treatment: 0 };

      const series = available.map((k) => {
        const values = logTransform(expressionSeriesBySample[k]);
        const n = values.length || 1;
        const bandwidth = estimateBandwidth(values) || step;
        const density = labels.map((x) => {
          let sum = 0;
          for (let i = 0; i < n; i += 1) {
            sum += gaussianKernel((x - values[i]) / bandwidth);
          }
          return sum / (n * bandwidth);
        });
        const pairs = labels.map((x, i) => [x, density[i]]);
        const group = expressionSampleGroups[k] === 'treatment' ? 'treatment' : 'control';
        const color = palettes[group][paletteIndex[group] % palettes[group].length];
        paletteIndex[group] += 1;
        return {
          name: `${k} (${group === 'control' ? 'Control' : 'Treatment'})`,
          type: 'line',
          data: pairs,
          showSymbol: false,
          smooth: 0.35,
          lineStyle: { width: 2.5, color }
        };
      });

      const chart = echarts.init(chartEl);
      chart.setOption({
        title: {
          text: 'Gene Expression Distribution (Control vs Treatment)',
          left: 'center',
          textStyle: { fontSize: 14, fontWeight: 600 }
        },
        tooltip: { trigger: 'axis' },
        legend: {
          top: 26,
          right: 12,
          textStyle: { fontSize: 11 }
        },
        grid: { left: 72, right: 24, top: 116, bottom: 50 },
        xAxis: {
          type: 'value',
          name: 'Gene Expression (log2(TPM))',
          nameLocation: 'middle',
          nameGap: 32,
          min: minX,
          max: maxX,
          splitLine: { lineStyle: { color: '#e9edf3' } }
        },
        yAxis: {
          type: 'value',
          name: 'Density',
          nameLocation: 'middle',
          nameGap: 60,
          splitLine: { lineStyle: { color: '#e9edf3' } }
        },
        series
      });

      window.addEventListener('resize', () => chart.resize());
      setTimeout(() => chart.resize(), 0);
      }
    }

    const renderGeneExpressionChart = (el, title, payload) => {
      if (!el || !payload || !payload.labels || !payload.series || payload.series.length === 0) {
        return null;
      }
      const colors = ['#2563eb', '#ef4444', '#10b981', '#f59e0b', '#7c3aed', '#06b6d4', '#f97316'];
      const series = payload.series.map((item, index) => ({
        name: item.name,
        type: 'bar',
        data: item.data,
        barMaxWidth: 14,
        itemStyle: { color: colors[index % colors.length] }
      }));

      const chart = echarts.init(el);
      const categoryCount = payload.labels.length;
      chart.setOption({
        title: { text: title, left: 'center', textStyle: { fontSize: 14, fontWeight: 600 } },
        tooltip: { trigger: 'axis' },
        legend: { top: 26, right: 12, textStyle: { fontSize: 11 } },
        grid: { left: 60, right: 24, top: 116, bottom: 50 },
        graphic: [
          {
            type: 'text',
            right: 10,
            top: 6,
            style: {
              text: `Categories: ${categoryCount}`,
              fill: '#374151',
              font: '12px sans-serif'
            }
          }
        ],
        xAxis: {
          type: 'category',
          data: payload.labels,
          axisLabel: { rotate: 30, fontSize: 8, interval: 0 },
          splitLine: { show: false }
        },
        yAxis: {
          type: 'value',
          name: 'Mean expression (TPM)',
          nameLocation: 'middle',
          nameGap: 45,
          splitLine: { lineStyle: { color: '#e9edf3' } }
        },
        series
      });
      window.addEventListener('resize', () => chart.resize());
      setTimeout(() => chart.resize(), 0);
      return chart;
    };

    if (gtexTcgaPayload && gtexTcgaPayload.gtex) {
      renderGeneExpressionChart(
        document.getElementById('gtexExpressionChart'),
        'GTEx Expression by Tissue Type',
        gtexTcgaPayload.gtex
      );
    }

    if (gtexTcgaPayload && gtexTcgaPayload.tcga) {
      renderGeneExpressionChart(
        document.getElementById('tcgaExpressionChart'),
        'TCGA Expression by Cancer Type',
        gtexTcgaPayload.tcga
      );
    }

    const buildViolinChart = (el, values, title, color) => {
      if (!el || !values || values.length === 0) {
        return null;
      }
      const sorted = [...values].sort((a, b) => a - b);
      const minVal = sorted[0];
      const maxVal = sorted[sorted.length - 1];
      const points = 120;
      const range = maxVal - minVal || 1;
      const step = range / (points - 1);
      const ys = Array.from({ length: points }, (_, i) => minVal + step * i);

      const gaussianKernel = (u) => Math.exp(-0.5 * u * u) / Math.sqrt(2 * Math.PI);
      const estimateBandwidth = (vals) => {
        const n = vals.length || 1;
        const mean = vals.reduce((acc, v) => acc + v, 0) / n;
        const variance = vals.reduce((acc, v) => acc + (v - mean) ** 2, 0) / n;
        const std = Math.sqrt(variance) || 1;
        return 1.06 * std * Math.pow(n, -0.2);
      };

      const bandwidth = estimateBandwidth(sorted) || step;
      const density = ys.map((y) => {
        let sum = 0;
        for (let i = 0; i < sorted.length; i += 1) {
          sum += gaussianKernel((y - sorted[i]) / bandwidth);
        }
        return sum / (sorted.length * bandwidth);
      });

      const maxDensity = Math.max(...density) || 1;
      const scale = 0.85 / maxDensity;
      const positive = ys.map((y, i) => [density[i] * scale, y]);
      const negative = ys.map((y, i) => [-density[i] * scale, y]);
      const median = sorted[Math.floor(sorted.length / 2)];

      const chart = echarts.init(el);
      chart.setOption({
        title: { text: title, left: 'center', textStyle: { fontSize: 14, fontWeight: 600, color: '#1f2937' } },
        grid: { left: 48, right: 16, top: 40, bottom: 32 },
        tooltip: {
          trigger: 'axis',
          axisPointer: { type: 'line' },
          formatter: (params) => {
            const val = params && params[0] ? params[0].value[1] : null;
            if (val === null || val === undefined) {
              return '';
            }
            return `${title}<br/>Value: ${Number(val).toFixed(2)}`;
          }
        },
        xAxis: {
          type: 'value',
          min: -1,
          max: 1,
          axisLabel: { show: false },
          splitLine: { show: false },
          axisTick: { show: false },
          axisLine: { show: false }
        },
        yAxis: {
          type: 'value',
          min: minVal,
          max: maxVal,
          axisLabel: { fontSize: 10, color: '#6b7280' },
          axisLine: { lineStyle: { color: '#d1d5db' } },
          splitLine: { lineStyle: { color: '#eef2f7' } }
        },
        graphic: [
          {
            type: 'line',
            left: '10%',
            right: '10%',
            top: 0,
            shape: { x1: 0, y1: 0, x2: 0, y2: 0 },
            invisible: true
          },
          {
            type: 'line',
            z: 10,
            shape: {
              x1: 0,
              y1: 0,
              x2: 0,
              y2: 0
            },
            style: {
              stroke: '#111827',
              lineWidth: 1.2
            },
            bbox: {
              x: 0,
              y: 0,
              width: 0,
              height: 0
            }
          }
        ],
        series: [
          {
            type: 'line',
            data: positive,
            showSymbol: false,
            lineStyle: { width: 1, color },
            areaStyle: {
              color: new echarts.graphic.LinearGradient(0, 0, 1, 0, [
                { offset: 0, color: `${color}55` },
                { offset: 1, color }
              ])
            }
          },
          {
            type: 'line',
            data: negative,
            showSymbol: false,
            lineStyle: { width: 1, color },
            areaStyle: {
              color: new echarts.graphic.LinearGradient(1, 0, 0, 0, [
                { offset: 0, color: `${color}55` },
                { offset: 1, color }
              ])
            }
          }
        ]
      });
      chart.on('finished', () => {
        const yAxis = chart.getModel().getComponent('yAxis');
        if (!yAxis) {
          return;
        }
        const yCoord = chart.convertToPixel({ yAxisIndex: 0 }, median);
        const xMin = chart.convertToPixel({ xAxisIndex: 0 }, -0.55);
        const xMax = chart.convertToPixel({ xAxisIndex: 0 }, 0.55);
        chart.setOption({
          graphic: [
            {
              type: 'line',
              z: 10,
              shape: { x1: xMin, y1: yCoord, x2: xMax, y2: yCoord },
              style: { stroke: '#111827', lineWidth: 1.2 }
            }
          ]
        }, false);
      });
      window.addEventListener('resize', () => chart.resize());
      return chart;
    };

    const renderSingleCellQcDist = (distData) => {
      const data = distData || {};
      const sampleForPlot = (arr, maxN = 12000) => {
        const src = Array.isArray(arr) ? arr : [];
        if (src.length <= maxN) {
          return src;
        }
        const step = src.length / maxN;
        const out = [];
        for (let i = 0; i < maxN; i += 1) {
          out.push(src[Math.floor(i * step)]);
        }
        return out;
      };

      const genes = sampleForPlot(data.n_genes_by_counts);
      const counts = sampleForPlot(data.total_counts);
      const mito = sampleForPlot(data.pct_counts_mt);
      const hasAny = genes.length > 0 || counts.length > 0 || mito.length > 0;
      const emptyEl = document.getElementById('singleCellDistEmpty');
      if (!hasAny) {
        setSectionVisible('scQcDistSection', false);
        if (emptyEl) emptyEl.classList.remove('d-none');
        return;
      }
      setSectionVisible('scQcDistSection', true);
      if (emptyEl) emptyEl.classList.add('d-none');

      buildViolinChart(
        document.getElementById('chartSingleCellGenes'),
        genes,
        'Number of Genes per Cell',
        '#7aaed6'
      );
      buildViolinChart(
        document.getElementById('chartSingleCellCounts'),
        counts,
        'Total Counts (UMI) per Cell',
        '#7aaed6'
      );
      buildViolinChart(
        document.getElementById('chartSingleCellMito'),
        mito,
        'Mitochondrial Percentage per Cell',
        '#7aaed6'
      );
    };

    if (singleCellDistData && (singleCellDistData.n_genes_by_counts.length > 0 || singleCellDistData.total_counts.length > 0 || singleCellDistData.pct_counts_mt.length > 0)) {
      renderSingleCellQcDist(singleCellDistData);
    }

    const fetchChartApi = async (apiName) => {
      if (!pageDatasetId) {
        return { ok: false, error: 'missing dataset_id' };
      }
      const url = new URL(window.location.href);
      url.searchParams.set('chart_api', apiName);
      url.searchParams.set('dataset_id', pageDatasetId);
      const res = await fetch(url.toString());
      if (!res.ok) {
        throw new Error(`chart_api ${apiName} failed`);
      }
      return res.json();
    };

    const renderSingleCellGeneFreq = (rows) => {
      const badgeEl = document.getElementById('scGeneFreqBadge');
      const emptyEl = document.getElementById('singleCellGeneFreqEmpty');
      if (badgeEl) {
        badgeEl.textContent = `Number of Perturbed Genes: ${Array.isArray(rows) ? rows.length : 0}`;
      }
      if (!Array.isArray(rows) || rows.length === 0) {
        setSectionVisible('scGeneFreqSection', false);
        if (emptyEl) emptyEl.classList.remove('d-none');
        return;
      }
      setSectionVisible('scGeneFreqSection', true);
      if (emptyEl) emptyEl.classList.add('d-none');

      const sortedFreq = [...rows]
        .filter((item) => item && Number.isFinite(Number(item.count)))
        .sort((a, b) => Number(b.count) - Number(a.count));
      const plotFreq = sortedFreq.slice(0, 500);
      const rawCounts = plotFreq.map((item) => Number(item.count));
      const maxRaw = rawCounts.length ? Math.max(...rawCounts) : 0;
      const yCap = maxRaw > 2000 ? 2000 : maxRaw;
      const rankData = plotFreq.map((item, index) => {
        const rawCount = Number(item.count);
        const cappedCount = yCap > 0 ? Math.min(rawCount, yCap) : rawCount;
        return [index + 1, cappedCount, item.gene || '', rawCount];
      });

      const freqChartEl = document.getElementById('singleCellGeneFreqChart');
      if (!freqChartEl) {
        return;
      }
      const freqChart = echarts.init(freqChartEl);
      freqChart.setOption({
        tooltip: {
          trigger: 'axis',
          axisPointer: { type: 'line' },
          formatter: (params) => {
            const pick = Array.isArray(params) ? params[params.length - 1] : params;
            const value = (pick && pick.data) || [];
            const rank = value[0] ?? 'NA';
            const count = value[3] ?? value[1] ?? 'NA';
            const gene = value[2] || 'NA';
            return `Perturbation: ${gene}<br/>Rank: ${rank}<br/>Cell Count: ${count}`;
          }
        },
        grid: { left: 70, right: 24, top: 20, bottom: 50 },
        xAxis: {
          type: 'value',
          name: 'Rank',
          nameLocation: 'middle',
          nameGap: 32,
          min: 1,
          max: rankData.length,
          splitLine: { lineStyle: { color: '#e9edf3' } }
        },
        yAxis: {
          type: 'value',
          name: 'Cell Count',
          nameLocation: 'middle',
          nameGap: 55,
          max: yCap || null,
          splitLine: { lineStyle: { color: '#e9edf3' } }
        },
        series: [
          {
            type: 'line',
            data: rankData.map((item) => [item[0], item[1]]),
            silent: true,
            tooltip: { show: false },
            showSymbol: false,
            smooth: 0.2,
            lineStyle: { width: 2, color: '#4b5563' }
          },
          {
            type: 'scatter',
            data: rankData,
            symbolSize: 6,
            itemStyle: { color: '#2563eb' }
          }
        ]
      });
      window.addEventListener('resize', () => freqChart.resize());
      setTimeout(() => freqChart.resize(), 0);
    };

    const renderPerturbStates = (pieRows, barRows) => {
      const emptyEl = document.getElementById('perturbStateEmpty');
      const barCol = document.getElementById('perturbStateBarCol');
      const pieCol = document.getElementById('perturbStatePieCol');
      const hasBar = Array.isArray(barRows) && barRows.length > 0;
      const hasPie = Array.isArray(pieRows) && pieRows.length > 0;
      const pieNameMap = {
        'Control': 'NTC',
        'KO': 'Effective (Perturbed)',
        'NP': 'Ineffective (Non-perturbed)'
      };
      const mappedPieRows = (Array.isArray(pieRows) ? pieRows : []).map((item) => ({
        ...item,
        name: pieNameMap[item?.name] || item?.name || 'NA'
      }));
      const stateColors = {
        ntc: '#cbd5e1',
        effective: '#93c5fd',
        ineffective: '#fda4af'
      };

      if (!hasPie && !hasBar) {
        setSectionVisible('scPerturbStatesSection', false);
        if (emptyEl) emptyEl.classList.remove('d-none');
        return;
      }
      setSectionVisible('scPerturbStatesSection', true);
      if (emptyEl) emptyEl.classList.add('d-none');

      if (barCol && pieCol) {
        if (hasBar) {
          barCol.className = 'col-12 col-lg-8';
          pieCol.className = 'col-12 col-lg-4';
          barCol.classList.remove('d-none');
        } else {
          barCol.classList.add('d-none');
          pieCol.className = 'col-12 col-lg-5 mx-auto';
        }
      }

      const pieEl = document.getElementById('perturbStatePieChart');
      if (pieEl && hasPie) {
        const pieChart = echarts.init(pieEl);
        pieChart.setOption({
          title: {
            text: 'Cell count by classification',
            left: 'center',
            top: 4,
            textStyle: { fontSize: 14, fontWeight: 600 }
          },
          tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
          legend: { bottom: 0, left: 'center' },
          series: [
            {
              name: 'Cell States',
              type: 'pie',
              radius: ['42%', '72%'],
              center: ['50%', '50%'],
              label: { formatter: '{b}\n{d}%' },
              data: mappedPieRows,
              color: [stateColors.ntc, stateColors.effective, stateColors.ineffective]
            }
          ]
        });
        window.addEventListener('resize', () => pieChart.resize());
      }

      const barEl = document.getElementById('perturbStateBarChart');
      if (barEl && hasBar) {
        const barChart = echarts.init(barEl);
        const barItems = [...barRows].slice(0, 20);
        const genes = barItems.map((x) => x.gene || 'NA');
        const spVals = barItems.map((x) => Number(x.sp ?? 0));
        const npVals = barItems.map((x) => Number(x.np ?? 0));
        // Dynamic compression: when gene count is small, narrow the plot area a bit
        // to avoid overly sparse bars; keep a reasonable floor for natural layout.
        const totalSlots = 20;
        const itemCount = genes.length;
        const containerWidth = barEl.clientWidth || 900;
        const baseLeft = 58;
        const baseRight = 18;
        const basePlotWidth = Math.max(140, containerWidth - baseLeft - baseRight);
        const scaledRatio = itemCount > 0 ? Math.max(0.75, Math.min(1, itemCount / totalSlots)) : 1;
        const scaledPlotWidth = Math.max(140, Math.round(basePlotWidth * scaledRatio));
        const remainingWidth = Math.max(0, basePlotWidth - scaledPlotWidth);
        const dynamicLeft = baseLeft + Math.round(remainingWidth / 2);
        const dynamicRight = baseRight + Math.round(remainingWidth / 2);
        barChart.setOption({
          title: {
            text: 'Proportion of effective perturbations per gene',
            left: 'center',
            textStyle: { fontSize: 14, fontWeight: 600 }
          },
          tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params) => {
              const p = Array.isArray(params) ? params : [];
              const gene = p[0]?.axisValueLabel || 'NA';
              const np = p.find((x) => x.seriesName === 'Ineffective (Non-perturbed)')?.value ?? 0;
              const sp = p.find((x) => x.seriesName === 'Effective (Perturbed)')?.value ?? 0;
              return `Perturbation: ${gene}<br/>Ineffective (Non-perturbed): ${(Number(np) * 100).toFixed(1)}%<br/>Effective (Perturbed): ${(Number(sp) * 100).toFixed(1)}%`;
            }
          },
          legend: {
            top: 26,
            right: 4,
            itemWidth: 12,
            itemHeight: 10,
            textStyle: { fontSize: 11 }
          },
          graphic: [
            {
              type: 'text',
              top: 8,
              right: 4,
              style: {
                text: 'Mixscape-predicted classification',
                fill: '#374151',
                fontSize: 11,
                fontWeight: 600
              }
            }
          ],
          grid: { left: dynamicLeft, right: dynamicRight, top: 84, bottom: 78 },
          xAxis: { type: 'category', data: genes, axisLabel: { rotate: 45, fontSize: 10, interval: 0 } },
          yAxis: {
            type: 'value',
            min: 0,
            max: 1,
            name: 'Percentage of cells',
            axisLabel: { formatter: (v) => `${Math.round(v * 100)}%` },
            splitLine: { lineStyle: { color: '#e9edf3' } }
          },
          series: [
            { name: 'Effective (Perturbed)', type: 'bar', stack: 'ratio', data: spVals, itemStyle: { color: stateColors.effective } },
            { name: 'Ineffective (Non-perturbed)', type: 'bar', stack: 'ratio', data: npVals, itemStyle: { color: stateColors.ineffective } }
          ]
        });
        window.addEventListener('resize', () => barChart.resize());
      }
    };

    const renderSingleCellUmap = (points) => {
      const badgeEl = document.getElementById('singleCellUmapBadge');
      const emptyEl = document.getElementById('singleCellUmapEmpty');
      const clusterEl = document.getElementById('singleCellUmapClusterChart');
      const labelEl = document.getElementById('singleCellUmapLabelChart');
      const labelSelect = document.getElementById('singleCellUmapLabelSelect');

      if (!clusterEl || !labelEl) {
        return;
      }

      if (badgeEl) {
        badgeEl.textContent = `Number of Cells: ${Array.isArray(points) ? points.length : 0}`;
      }

      if (!Array.isArray(points) || points.length === 0) {
        setSectionVisible('scUmapSection', false);
        if (emptyEl) emptyEl.classList.remove('d-none');
        return;
      }
      setSectionVisible('scUmapSection', true);
      if (emptyEl) emptyEl.classList.add('d-none');

      const colorPalette = ['#4e79a7', '#f28e2b', '#59a14f', '#e15759', '#76b7b2', '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ab', '#6f4e7c', '#2f5597'];
      const mixscapeName = (code) => {
        const c = Number(code);
        if (c === 1) return 'Effective';
        if (c === 2) return 'Ineffective';
        if (c === 3) return 'Control/NTC';
        return 'NA';
      };
      const mixscapeColor = {
        'NA': '#9ca3af',
        'Control/NTC': '#cbd5e1',
        'Effective': '#4e79a7',
        'Ineffective': '#e15759'
      };

      const formatPoint = (p) => {
        const x = Number(p[0] ?? 0) / 10000;
        const y = Number(p[1] ?? 0) / 10000;
        const cluster = Number.isFinite(Number(p[2])) ? Number(p[2]) : -1;
        const gene = String(p[3] ?? '').trim();
        const mix = Number(p[4] ?? 0);
        return { x, y, cluster, gene, mix };
      };

      const normalized = points.map(formatPoint);
      const chartCommon = {
        grid: { left: 56, right: 118, top: 22, bottom: 58, containLabel: true },
        xAxis: { type: 'value', name: 'UMAP1', nameLocation: 'middle', nameGap: 34, splitLine: { show: false } },
        yAxis: { type: 'value', name: 'UMAP2', nameLocation: 'middle', nameGap: 46, splitLine: { show: false } },
        tooltip: {
          trigger: 'item',
          formatter: (params) => {
            const d = params?.data || [];
            const x = Number(d[0] ?? 0).toFixed(3);
            const y = Number(d[1] ?? 0).toFixed(3);
            const cluster = d[2] ?? 'NA';
            const gene = d[3] || 'NA';
            const mix = d[4] || 'NA';
            return `UMAP1: ${x}<br/>UMAP2: ${y}<br/>Cluster: ${cluster}<br/>Label: ${gene}<br/>Mixscape: ${mix}`;
          }
        }
      };

      // Left chart: fixed cluster labels.
      const clusterGroups = new Map();
      normalized.forEach((item) => {
        const key = String(item.cluster);
        if (!clusterGroups.has(key)) clusterGroups.set(key, []);
        clusterGroups.get(key).push([item.x, item.y, item.cluster, item.gene || 'NA', mixscapeName(item.mix)]);
      });
      const clusterKeys = Array.from(clusterGroups.keys()).sort((a, b) => Number(a) - Number(b));
      const clusterSeries = clusterKeys.map((k, i) => ({
        name: `Cluster ${k}`,
        type: 'scatter',
        symbolSize: 2,
        large: true,
        progressive: 8000,
        itemStyle: { color: colorPalette[i % colorPalette.length], opacity: 0.85 },
        data: clusterGroups.get(k)
      }));

      const clusterChart = echarts.init(clusterEl);
      clusterChart.setOption({
        ...chartCommon,
        legend: {
          type: 'scroll',
          orient: 'vertical',
          right: 6,
          top: 20,
          bottom: 14,
          textStyle: { fontSize: 10 },
          itemWidth: 10,
          itemHeight: 8,
          backgroundColor: '#ffffff',
          borderColor: '#e5e7eb',
          borderWidth: 1,
          padding: [6, 6, 6, 6]
        },
        series: clusterSeries
      });

      // Right chart: switchable labels.
      const labelChart = echarts.init(labelEl);
      const geneCounts = new Map();
      normalized.forEach((item) => {
        const g = String(item.gene || '').trim();
        if (!g || /^na$/i.test(g)) return;
        geneCounts.set(g, (geneCounts.get(g) || 0) + 1);
      });
      const topGeneSet = new Set(
        Array.from(geneCounts.entries())
          .sort((a, b) => b[1] - a[1])
          .slice(0, 20)
          .map((x) => x[0])
      );
      const renderLabelChart = (mode) => {
        const groups = new Map();

        normalized.forEach((item) => {
          let label = 'NA';
          if (mode === 'cluster') {
            label = `Cluster ${item.cluster}`;
          } else if (mode === 'gene') {
            const gene = String(item.gene || '').trim();
            if (!gene || /^na$/i.test(gene)) {
              label = 'NA';
            } else if (topGeneSet.has(gene)) {
              label = gene;
            } else {
              label = 'Other';
            }
          } else {
            label = mixscapeName(item.mix);
          }
          if (!groups.has(label)) groups.set(label, []);
          groups.get(label).push([item.x, item.y, item.cluster, item.gene || 'NA', mixscapeName(item.mix)]);
        });

        const labels = Array.from(groups.keys()).sort((a, b) => {
          const rank = (name) => {
            if (name === 'NA') return 0;
            if (name === 'Other') return 1;
            return 2;
          };
          const ra = rank(a);
          const rb = rank(b);
          if (ra !== rb) return ra - rb; // NA/Other first => rendered underneath others
          return a.localeCompare(b);
        });
        const legendLabels = labels.filter((name) => {
          if (mode === 'gene') return name !== 'NA' && name !== 'Other';
          if (mode === 'mixscape') return name !== 'NA';
          return true;
        });
        const series = labels.map((name, idx) => ({
          name,
          type: 'scatter',
          symbolSize: 2,
          large: true,
          progressive: 8000,
          itemStyle: {
            color: (
              ((mode === 'gene') && (name === 'NA' || name === 'Other')) ||
              ((mode === 'mixscape') && name === 'NA')
            )
              ? '#9ca3af'
              : (mode === 'mixscape'
                ? (mixscapeColor[name] || colorPalette[idx % colorPalette.length])
                : colorPalette[idx % colorPalette.length]),
            opacity: 0.85
          },
          data: groups.get(name)
        }));

        // Important: fully replace option on mode switch, otherwise echarts may merge
        // previous legend/series entries and produce mixed labels.
        labelChart.clear();
        labelChart.setOption({
          ...chartCommon,
          legend: {
            type: 'scroll',
            data: legendLabels,
            orient: 'vertical',
            right: 6,
            top: 20,
            bottom: 14,
            textStyle: { fontSize: 10 },
            itemWidth: 10,
            itemHeight: 8,
            backgroundColor: '#ffffff',
            borderColor: '#e5e7eb',
            borderWidth: 1,
            padding: [6, 6, 6, 6]
          },
          series
        }, true);
      };

      renderLabelChart(labelSelect ? labelSelect.value : 'mixscape');
      if (labelSelect) {
        labelSelect.addEventListener('change', (e) => {
          renderLabelChart(e.target.value || 'mixscape');
        });
      }

      window.addEventListener('resize', () => {
        clusterChart.resize();
        labelChart.resize();
      });
      setTimeout(() => {
        clusterChart.resize();
        labelChart.resize();
      }, 0);
    };

    const renderSingleCellPerturbEnrichment = (rows) => {
      const emptyEl = document.getElementById('singleCellEnrichEmpty');
      const leftEl = document.getElementById('singleCellEnrichLog2orChart');
      const rightEl = document.getElementById('singleCellEnrichFracChart');
      if (!leftEl || !rightEl) {
        return;
      }

      if (!Array.isArray(rows) || rows.length === 0) {
        setSectionVisible('scPerturbEnrichSection', false);
        if (emptyEl) emptyEl.classList.remove('d-none');
        return;
      }
      setSectionVisible('scPerturbEnrichSection', true);
      if (emptyEl) emptyEl.classList.add('d-none');

      const normalized = rows
        .map((r) => ({
          gene: String(r?.[0] ?? '').trim(),
          cluster: Number(r?.[1] ?? 0),
          cellCount: Number(r?.[2] ?? 0),
          frac: Number(r?.[3] ?? 0),
          log2or: Number(r?.[4] ?? 0),
          fdr: Number(r?.[5] ?? 1)
        }))
        .filter((x) => x.gene !== '' && Number.isFinite(x.cluster));

      if (normalized.length === 0) {
        setSectionVisible('scPerturbEnrichSection', false);
        if (emptyEl) emptyEl.classList.remove('d-none');
        return;
      }

      const geneTotals = new Map();
      normalized.forEach((x) => {
        geneTotals.set(x.gene, (geneTotals.get(x.gene) || 0) + (Number.isFinite(x.cellCount) ? x.cellCount : 0));
      });
      const genes = Array.from(geneTotals.entries())
        .sort((a, b) => b[1] - a[1])
        .slice(0, 20)
        .map((x) => x[0]);
      const geneSet = new Set(genes);

      const clusters = Array.from(new Set(normalized.map((x) => x.cluster)))
        .sort((a, b) => a - b);
      const clusterToIdx = new Map(clusters.map((c, i) => [c, i]));
      const geneToIdx = new Map(genes.map((g, i) => [g, i]));

      const leftData = [];
      const rightData = [];
      const starData = [];
      const rightMeta = new Map();
      let leftMax = 0;
      let rightMax = 0;

      normalized.forEach((x) => {
        if (!geneSet.has(x.gene)) return;
        const xi = clusterToIdx.get(x.cluster);
        const yi = geneToIdx.get(x.gene);
        if (xi === undefined || yi === undefined) return;

        const log2or = Number.isFinite(x.log2or) ? x.log2or : 0;
        const frac = Number.isFinite(x.frac) ? x.frac : 0;
        const fdr = Number.isFinite(x.fdr) ? x.fdr : 1;
        const count = Number.isFinite(x.cellCount) ? x.cellCount : 0;

        leftData.push([xi, yi, log2or > 0 ? log2or : '-']);
        rightData.push([xi, yi, frac > 0 ? frac : '-']);
        rightMeta.set(`${x.gene}|${x.cluster}`, { count, frac });

        if (log2or > leftMax) leftMax = log2or;
        if (frac > rightMax) rightMax = frac;
        if (log2or > 0 && fdr < 0.05) {
          starData.push([xi, yi]);
        }
      });

      const commonOpt = {
        grid: { left: 92, right: 92, top: 92, bottom: 92, containLabel: true },
        xAxis: {
          type: 'category',
          data: clusters.map((x) => String(x)),
          name: 'Cluster',
          nameLocation: 'middle',
          nameGap: 58,
          axisLabel: { rotate: 45, fontSize: 10 }
        },
        yAxis: {
          type: 'category',
          data: genes,
          name: 'Perturbed Genes',
          nameLocation: 'middle',
          nameGap: 88,
          axisLabel: { fontSize: 10 }
        }
      };

      const leftChart = echarts.init(leftEl);
      leftChart.setOption({
        ...commonOpt,
        title: {
          text: 'Enrichment of Perturbed Genes Across Cell Clusters',
          subtext: 'log2(OR) > 0, ★: FDR ≤ 0.05',
          left: 'center',
          top: 10,
          textStyle: { fontSize: 13, fontWeight: 600 },
          subtextStyle: { fontSize: 11, color: '#6b7280' }
        },
        tooltip: {
          formatter: (p) => {
            const d = p?.data || [];
            const cluster = clusters[d[0]] ?? 'NA';
            const gene = genes[d[1]] ?? 'NA';
            const v = d[2];
            return `Perturb: ${gene}<br/>Cluster: ${cluster}<br/>log2OR: ${v === '-' ? 'NA' : Number(v).toFixed(4)}`;
          }
        },
        visualMap: {
          min: 0,
          max: leftMax > 0 ? leftMax : 1,
          precision: 1,
          formatter: (value) => Number(value).toFixed(1),
          orient: 'vertical',
          right: 8,
          top: 'middle',
          itemWidth: 12,
          itemHeight: 160,
          calculable: true,
          inRange: { color: ['#fff5f0', '#fb6a4a', '#a50f15'] }
        },
        series: [
          {
            type: 'heatmap',
            data: leftData,
            progressive: 4000,
            itemStyle: { borderColor: '#e5e7eb', borderWidth: 0.5 }
          },
          {
            type: 'scatter',
            data: starData,
            symbol: 'path://M512 64L627 396h349L694 598l115 332-282-205-282 205 115-332L48 396h349L512 64z',
            symbolSize: 10,
            itemStyle: { color: '#111827' },
            tooltip: { show: false },
            z: 3
          }
        ]
      });

      const rightChart = echarts.init(rightEl);
      rightChart.setOption({
        ...commonOpt,
        title: {
          text: 'Proportion of Cells for Each Perturbed Gene Across Cell Clusters',
          subtext: 'Fraction = Number of perturbed cells in the cluster / Total cells of the same perturbed gene across all clusters',
          left: 'center',
          top: 10,
          textStyle: { fontSize: 13, fontWeight: 600 },
          subtextStyle: { fontSize: 11, color: '#6b7280' }
        },
        tooltip: {
          formatter: (p) => {
            const d = p?.data || [];
            const cluster = clusters[d[0]] ?? 'NA';
            const gene = genes[d[1]] ?? 'NA';
            const m = rightMeta.get(`${gene}|${cluster}`) || { count: 0, frac: 0 };
            const ratio = Number.isFinite(m.frac) ? m.frac : 0;
            return `Counts: ${m.count}<br/>Perturb: ${gene}<br/>Cluster: ${cluster}<br/>Ratio: ${ratio.toFixed(4)}`;
          }
        },
        visualMap: {
          min: 0,
          max: rightMax > 0 ? rightMax : 1,
          precision: 1,
          formatter: (value) => Number(value).toFixed(1),
          orient: 'vertical',
          right: 8,
          top: 'middle',
          itemWidth: 12,
          itemHeight: 160,
          calculable: true,
          inRange: { color: ['#fff5f0', '#fb6a4a', '#a50f15'] }
        },
        series: [
          {
            type: 'heatmap',
            data: rightData,
            progressive: 4000,
            itemStyle: { borderColor: '#e5e7eb', borderWidth: 0.5 }
          }
        ]
      });

      window.addEventListener('resize', () => {
        leftChart.resize();
        rightChart.resize();
      });
      setTimeout(() => {
        leftChart.resize();
        rightChart.resize();
      }, 0);
    };

    const lazyTargets = [];
    const qcGenesEl = document.getElementById('chartSingleCellGenes');
    if (qcGenesEl) {
      lazyTargets.push({
        el: qcGenesEl,
        loaded: false,
        load: async () => {
          const resp = await fetchChartApi('single_cell_qc_dist');
          renderSingleCellQcDist(resp.dist || {});
        }
      });
    }

    const freqChartEl = document.getElementById('singleCellGeneFreqChart');
    if (freqChartEl) {
      lazyTargets.push({
        el: freqChartEl,
        loaded: false,
        load: async () => {
          const resp = await fetchChartApi('single_cell_gene_freq');
          renderSingleCellGeneFreq(resp.rows || []);
        }
      });
    }

    const statesPieEl = document.getElementById('perturbStatePieChart');
    const statesBarEl = document.getElementById('perturbStateBarChart');
    if (statesPieEl || statesBarEl) {
      lazyTargets.push({
        el: statesPieEl || statesBarEl,
        loaded: false,
        load: async () => {
          const resp = await fetchChartApi('single_cell_perturb_states');
          renderPerturbStates(resp.pie || [], resp.bar || []);
        }
      });
    }

    const umapClusterEl = document.getElementById('singleCellUmapClusterChart');
    const umapLabelEl = document.getElementById('singleCellUmapLabelChart');
    if (umapClusterEl || umapLabelEl) {
      lazyTargets.push({
        el: umapClusterEl || umapLabelEl,
        loaded: false,
        load: async () => {
          const resp = await fetchChartApi('single_cell_umap');
          renderSingleCellUmap(resp.points || []);
        }
      });
    }

    const enrichLeftEl = document.getElementById('singleCellEnrichLog2orChart');
    const enrichRightEl = document.getElementById('singleCellEnrichFracChart');
    if (enrichLeftEl || enrichRightEl) {
      lazyTargets.push({
        el: enrichLeftEl || enrichRightEl,
        loaded: false,
        load: async () => {
          const resp = await fetchChartApi('single_cell_perturb_enrichment');
          renderSingleCellPerturbEnrichment(resp.rows || []);
        }
      });
    }

    const runLazyTarget = (target) => {
      if (!target || target.loaded) {
        return;
      }
      target.loaded = true;
      target.load().catch(() => {
        const emptyDist = document.getElementById('singleCellDistEmpty');
        const emptyA = document.getElementById('singleCellGeneFreqEmpty');
        const emptyB = document.getElementById('perturbStateEmpty');
        const emptyC = document.getElementById('singleCellUmapEmpty');
        const emptyD = document.getElementById('singleCellEnrichEmpty');
        if (emptyDist) emptyDist.classList.remove('d-none');
        if (emptyA) emptyA.classList.remove('d-none');
        if (emptyB) emptyB.classList.remove('d-none');
        if (emptyC) emptyC.classList.remove('d-none');
        if (emptyD) emptyD.classList.remove('d-none');
      });
    };

    if (lazyTargets.length > 0) {
      if (!('IntersectionObserver' in window)) {
        lazyTargets.forEach((t) => runLazyTarget(t));
      } else {
        const observer = new IntersectionObserver((entries) => {
          entries.forEach((entry) => {
            if (!entry.isIntersecting) {
              return;
            }
            const target = lazyTargets.find((t) => t.el === entry.target);
            runLazyTarget(target);
            if (entry.target) {
              observer.unobserve(entry.target);
            }
          });
        }, { root: null, rootMargin: '200px 0px', threshold: 0.01 });

        lazyTargets.forEach((t) => {
          if (t.el) {
            observer.observe(t.el);
            // If element is already in/near viewport, force-trigger immediately.
            const rect = t.el.getBoundingClientRect();
            if (rect.top < (window.innerHeight + 240) && rect.bottom > -240) {
              runLazyTarget(t);
              observer.unobserve(t.el);
            }
          }
        });

        // Final fallback: avoid permanent blank charts when observer misses edge cases.
        setTimeout(() => {
          lazyTargets.forEach((t) => runLazyTarget(t));
        }, 1500);
      }
    }

    let degVolcanoChart = null;
    const volcanoEl = document.getElementById('demoVolcanoChart');
    const buildVolcanoSeries = (volcanoPayload) => {
      const other = [];
      const up = [];
      const down = [];
      const payload = volcanoPayload || { other: [], up: [], down: [] };
      (payload.other || []).forEach((point) => {
        other.push({ value: [point[0], point[1], point[3]], gene: point[2] || '' });
      });
      (payload.up || []).forEach((point) => {
        up.push({ value: [point[0], point[1], point[3]], gene: point[2] || '' });
      });
      (payload.down || []).forEach((point) => {
        down.push({ value: [point[0], point[1], point[3]], gene: point[2] || '' });
      });
      return { other, up, down };
    };

    const renderDegVolcano = (volcanoPayload) => {
      if (!volcanoEl) {
        setSectionVisible('degVolcanoSection', false);
        return;
      }
      const totalPoints = ((volcanoPayload?.other || []).length + (volcanoPayload?.up || []).length + (volcanoPayload?.down || []).length);
      setSectionVisible('degVolcanoSection', totalPoints > 0);
      if (totalPoints <= 0) {
        return;
      }
      if (!degVolcanoChart) {
        degVolcanoChart = echarts.init(volcanoEl);
      }
      const seriesData = buildVolcanoSeries(volcanoPayload);
      degVolcanoChart.setOption({
        tooltip: {
          trigger: 'item',
          formatter: (params) => {
            const data = params.data || {};
            const value = data.value || [];
            const gene = data.gene || 'NA';
            const x = Number.isFinite(value[0]) ? value[0].toFixed(3) : 'NA';
            const y = Number.isFinite(value[1]) ? value[1].toFixed(3) : 'NA';
            const raw = Number.isFinite(value[2]) ? value[2].toFixed(4) : 'NA';
            if (degIsNoiMethod) {
              return `Gene: ${gene}<br/>log2FC: ${x}<br/>prob: ${raw}<br/>${degVolcanoYLabel}: ${y}`;
            }
            return `Gene: ${gene}<br/>log2FC: ${x}<br/>padj: ${raw}<br/>${degVolcanoYLabel}: ${y}`;
          }
        },
        grid: { left: 60, right: 24, top: 20, bottom: 50 },
        xAxis: {
          type: 'value',
          name: 'log2FC',
          nameLocation: 'middle',
          nameGap: 32,
          splitLine: { lineStyle: { color: '#e9edf3' } }
        },
        yAxis: {
          type: 'value',
          name: degVolcanoYLabel,
          nameLocation: 'middle',
          nameGap: 44,
          min: degIsNoiMethod ? 0.94 : null,
          max: degIsNoiMethod ? 1.0 : null,
          interval: degIsNoiMethod ? 0.01 : null,
          splitLine: { lineStyle: { color: '#e9edf3' } }
        },
          series: [
          {
            name: 'Other',
            type: 'scatter',
            data: seriesData.other,
            symbolSize: 4,
            itemStyle: { color: '#94a3b8' },
            emphasis: { focus: 'series' }
          },
          {
            name: 'Significant Up',
            type: 'scatter',
            data: seriesData.up,
            symbolSize: 5,
            itemStyle: { color: '#ef4444' },
            emphasis: { focus: 'series' }
          },
          {
            name: 'Significant Down',
            type: 'scatter',
            data: seriesData.down,
            symbolSize: 5,
            itemStyle: { color: '#2563eb' },
            emphasis: { focus: 'series' }
          }
        ]
      });
    };

    if (degCount > 0) {
      renderDegVolcano(degVolcanoData);
    }

    window.addEventListener('resize', () => {
      if (degVolcanoChart) {
        degVolcanoChart.resize();
      }
    });
    setTimeout(() => {
      if (degVolcanoChart) {
        degVolcanoChart.resize();
      }
    }, 0);

    const heatmapSelect = document.getElementById('degHeatmapN');
    const heatmapEl = document.getElementById('degHeatmapChart');
    if (heatmapSelect && heatmapEl && degCount > 0) {
      const heatmapChart = echarts.init(heatmapEl);

      const formatHeatmapValue = (value) => {
        const num = Number(value);
        if (!Number.isFinite(num)) {
          return 'NA';
        }
        const abs = Math.abs(num);
        return num.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 4 });
      };

      const buildHeatmap = () => {
        const n = String(heatmapSelect.value || '20');
        const payload = degHeatmapDataByN[n] || { samples: [], genes: [], values: [] };
        const samples = payload.samples || [];
        const genes = payload.genes || [];
        const directions = payload.directions || [];
        const values = payload.values || [];
        const hasHeatmapData = samples.length > 0 && genes.length > 0 && values.length > 0;
        setSectionVisible('degHeatmapSection', hasHeatmapData);
        if (!hasHeatmapData) {
          return;
        }

        // Keep all gene labels visible by increasing chart height with row count.
        const dynamicHeight = Math.max(420, genes.length * 18 + 150);
        const heatmapWrap = heatmapEl.closest('.heatmap-wrap');
        if (heatmapWrap) {
          heatmapWrap.style.height = `${dynamicHeight}px`;
        }
        heatmapEl.style.height = '100%';
        heatmapChart.resize();

        const allValues = values.map((v) => v[2]);
        const minVal = allValues.length ? Math.min(...allValues) : -1;
        const maxVal = allValues.length ? Math.max(...allValues) : 1;

        heatmapChart.setOption({
          tooltip: {
            position: 'top',
            formatter: (params) => {
              const gene = genes[params.data[1]] || 'NA';
              const sample = samples[params.data[0]] || 'NA';
              const value = formatHeatmapValue(params.data[2]);
              // show label as log2(TPM) but value is computed using log2(TPM + 0.001)
              return `Gene: ${gene}<br/>Sample: ${sample}<br/>log2(TPM): ${value}`;
            }
          },
          grid: { left: 140, right: 24, top: 10, bottom: 78 },
          xAxis: {
            type: 'category',
            data: samples,
            axisLabel: {
              rotate: 15,
              margin: 14,
              formatter: (value) => {
                const group = expressionSampleGroups[value];
                return group === 'control' ? `{control|${value}}` : `{treat|${value}}`;
              },
                rich: {
                control: { color: '#10b981', fontWeight: 600 },
                treat: { color: '#f59e0b', fontWeight: 600 }
              }
            }
          },
          yAxis: {
            type: 'category',
            data: genes,
            axisLabel: {
              interval: 0,
              fontSize: 10,
              formatter: (value, index) => {
                const dir = directions[index] || 'down';
                return dir === 'up' ? `{up|${value}}` : `{down|${value}}`;
              },
              rich: {
                up: { color: '#ef4444', fontWeight: 600 },
                down: { color: '#2563eb', fontWeight: 600 }
              }
            }
          },
          visualMap: {
            min: minVal,
            max: maxVal,
            calculable: false,
            orient: 'horizontal',
            left: 'center',
            bottom: 6,
            inRange: { color: ['#2563eb', '#f8fafc', '#ef4444'] }
          },
          series: [
            {
              name: 'Expression',
              type: 'heatmap',
              data: values,
              label: { show: false },
              emphasis: { itemStyle: { borderColor: '#111827', borderWidth: 1 } }
            }
          ]
        });
      };

      heatmapSelect.addEventListener('change', buildHeatmap);
      buildHeatmap();
      window.addEventListener('resize', () => heatmapChart.resize());
      setTimeout(() => heatmapChart.resize(), 0);
    } else {
      setSectionVisible('degHeatmapSection', false);
    }

    if (document.getElementById('degTableBody')) {
      const tableBody = document.getElementById('degTableBody');
      const pageSizeSelect = document.getElementById('degPageSize');
      const pagination = document.getElementById('degPagination');
      const pageInfo = document.getElementById('degPageInfo');
      const prevBtn = document.getElementById('degPrevBtn');
      const nextBtn = document.getElementById('degNextBtn');
      const sortHeaders = document.querySelectorAll('.deg-sort');
      let pageSize = Number(pageSizeSelect.value) || 10;
      let currentPage = 1;
      let sortKey = 'log2fc';
      let sortDir = 'desc';
      const scDegPerturbSelect = document.getElementById('scDegPerturbSelect');
      const degGeneCountBadge = document.getElementById('degGeneCountBadge');
      let activePerturbation = (scDegPerturbSelect && scDegPerturbSelect.value) || scDegDefaultPerturbation || (scDegPerturbations[0] || '');

    const enrichmentDirectionSelect = document.getElementById('bulkEnrichDirectionSelect');
    const enrichmentTabButtons = document.querySelectorAll('[data-enrichment-tab]');
    const goOntologyButtons = document.querySelectorAll('[data-go-ontology]');
    const goPanel = document.getElementById('goEnrichmentPanel');
    const keggPanel = document.getElementById('keggEnrichmentPanel');
    const goChartEl = document.getElementById('goEnrichmentChart');
    const keggChartEl = document.getElementById('keggEnrichmentChart');
    const gseaNesEl = document.getElementById('gseaNesChart');
    const gseaMapEl = document.getElementById('gseaMapChart');
    const gseaEmptyEl = document.getElementById('gseaEmpty');
    let activeEnrichmentTab = 'go';
    let activeGoOntology = 'BP';
    let activeEnrichmentDirection = (enrichmentDirectionSelect && enrichmentDirectionSelect.value) ? enrichmentDirectionSelect.value : 'all';
    let goChart = null;
    let keggChart = null;
    let gseaNesChart = null;
    let gseaMapChart = null;
    let goHasRows = null;
    let keggHasRows = null;
    let enrichmentEverHadData = false;
    let enrichmentAvailabilityProbing = false;

    const scheduleChartResize = (...charts) => {
      const resizeAll = () => {
        charts.forEach((c) => {
          if (c && typeof c.resize === 'function') {
            c.resize();
          }
        });
      };
      // Run more than once because Bootstrap/layout transitions may settle later.
      requestAnimationFrame(resizeAll);
      setTimeout(resizeAll, 0);
      setTimeout(resizeAll, 120);
    };

    const updateEnrichmentSectionVisibility = () => {
      if (isSingleCellGoKeggEnabled) {
        // For single-cell pages, do not show during probing to avoid flash.
        // Show only after availability is confirmed.
        setSectionVisible('enrichmentSection', enrichmentEverHadData);
        if (enrichmentEverHadData) {
          scheduleChartResize(goChart, keggChart);
        }
        return;
      }
      if (enrichmentEverHadData) {
        setSectionVisible('enrichmentSection', true);
        scheduleChartResize(goChart, keggChart);
        return;
      }
      // Keep section visible while any side is still unknown (null).
      // Hide only when both GO and KEGG are explicitly confirmed empty.
      const bothConfirmedEmpty = (goHasRows === false && keggHasRows === false);
      const visible = !bothConfirmedEmpty;
      setSectionVisible('enrichmentSection', visible);
      if (visible) {
        scheduleChartResize(goChart, keggChart);
      }
    };

    const inflateEnrichmentRows = (payloadRows) => {
      const keys = (demoGoPayload && demoGoPayload.keys) || [];
      const keyIndex = Object.fromEntries(keys.map((key, index) => [key, index]));
      return (payloadRows || []).map((row) => ({
        ontology: row[keyIndex.ontology] ?? '',
        description: row[keyIndex.description] ?? '',
        fold_enrichment: row[keyIndex.fold_enrichment] ?? 0,
        count: row[keyIndex.count] ?? 0,
        score: row[keyIndex.score] ?? 0,
        overlap_ratio: row[keyIndex.overlap_ratio] ?? 0,
        direction: row[keyIndex.direction] ?? ''
      }));
    };

    const getAxisLabelHoverTipEl = () => {
      let el = document.getElementById('enrichmentAxisLabelHoverTip');
      if (el) return el;
      el = document.createElement('div');
      el.id = 'enrichmentAxisLabelHoverTip';
      el.style.position = 'fixed';
      el.style.zIndex = '9999';
      el.style.pointerEvents = 'none';
      // Match the requested white tooltip card style.
      el.style.padding = '10px 12px';
      el.style.borderRadius = '6px';
      el.style.background = '#ffffff';
      el.style.border = '1px solid #dbeafe';
      el.style.color = '#374151';
      el.style.fontSize = '16px';
      el.style.lineHeight = '1.5';
      el.style.fontFamily = 'sans-serif';
      el.style.maxWidth = '520px';
      el.style.boxShadow = '0 10px 24px rgba(15, 23, 42, 0.18)';
      el.style.whiteSpace = 'normal';
      el.style.wordBreak = 'break-word';
      el.style.display = 'none';
      document.body.appendChild(el);
      return el;
    };

    const escapeHtml = (value) => String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

    const showAxisLabelHoverTip = (text, mouseEvent) => {
      const el = getAxisLabelHoverTipEl();
      if (!text) {
        el.style.display = 'none';
        return;
      }
      el.innerHTML = `${escapeHtml(String(text))}`;
      el.style.display = 'block';
      const evt = mouseEvent || {};
      const vw = window.innerWidth || document.documentElement.clientWidth || 1200;
      const vh = window.innerHeight || document.documentElement.clientHeight || 800;
      const rect = el.getBoundingClientRect();
      let x = Number(evt.clientX || 0) + 12;
      let y = Number(evt.clientY || 0) + 12;
      if (x + rect.width + 8 > vw) x = Math.max(8, vw - rect.width - 8);
      if (y + rect.height + 8 > vh) y = Math.max(8, vh - rect.height - 8);
      el.style.left = `${x}px`;
      el.style.top = `${y}px`;
    };

    const hideAxisLabelHoverTip = () => {
      const el = document.getElementById('enrichmentAxisLabelHoverTip');
      if (el) el.style.display = 'none';
    };

    const bindEnrichmentAxisHover = (chartInstance) => {
      if (!chartInstance || chartInstance.__axisHoverBound) return;

      const onMouseOver = (params) => {
        if (params && params.componentType === 'yAxis') {
          const text = params.value || params.name || '';
          const evt = params.event && params.event.event ? params.event.event : null;
          showAxisLabelHoverTip(text, evt);
        } else {
          hideAxisLabelHoverTip();
        }
      };
      const onMouseMove = (params) => {
        if (params && params.componentType === 'yAxis') {
          const text = params.value || params.name || '';
          const evt = params.event && params.event.event ? params.event.event : null;
          showAxisLabelHoverTip(text, evt);
        }
      };
      const onMouseOut = () => {
        hideAxisLabelHoverTip();
      };
      const onGlobalOut = () => {
        hideAxisLabelHoverTip();
      };

      chartInstance.on('mouseover', onMouseOver);
      chartInstance.on('mousemove', onMouseMove);
      chartInstance.on('mouseout', onMouseOut);
      chartInstance.getZr().on('globalout', onGlobalOut);
      chartInstance.__axisHoverBound = true;
    };

    const renderEnrichmentChart = (chart, el, title, rows, showOntology = false) => {
      if (!el) {
        return chart;
      }
      const nextChart = chart || echarts.init(el);
      const seriesData = rows.map((row) => [
        Number(row.fold_enrichment) || 0,
        row.description || '',
        Number(row.score) || 0,
        Number(row.count) || 0,
        row.ontology || '',
        Number(row.overlap_ratio) || 0,
        row.direction || ''
      ]);
      const labels = rows.map((row) => row.description || '');
      const scores = rows.map((row) => Number(row.score) || 0);
      const minScore = scores.length ? Math.min(...scores) : 0;
      const maxScore = scores.length ? Math.max(...scores) : 1;
      nextChart.setOption({
        title: { text: title, left: 'center', textStyle: { fontSize: 15, fontWeight: 700 } },
        tooltip: {
          trigger: 'item',
          backgroundColor: '#ffffff',
          borderColor: '#dbeafe',
          borderWidth: 1,
          textStyle: {
            color: '#374151',
            fontSize: 16,
            lineHeight: 24
          },
          extraCssText: 'box-shadow: 0 10px 24px rgba(15,23,42,0.18); border-radius: 6px; padding: 10px 12px;',
          formatter: (params) => {
            const value = params.data || [];
            const ontology = showOntology ? `<br/>Ontology: ${value[4] || ''}` : '';
            const direction = value[6] ? `<br/>Direction: ${String(value[6])}` : '';
            return `Description: ${value[1] || ''}${ontology}${direction}<br/>Fold enrichment: ${Number(value[0] || 0).toFixed(4)}<br/>Count: ${Number(value[3] || 0)}<br/>Overlap ratio: ${Number(value[5] || 0).toFixed(4)}<br/>-log10(p.adjust): ${Number(value[2] || 0).toFixed(4)}`;
          }
        },
        grid: { left: 280, right: 110, top: 70, bottom: 24 },
        xAxis: {
          type: 'value',
          name: 'Fold enrichment',
          nameLocation: 'middle',
          nameGap: 28,
          splitLine: { lineStyle: { color: '#e9edf3' } }
        },
        yAxis: {
          type: 'category',
          triggerEvent: true,
          data: labels,
          inverse: false,
          axisLabel: { width: 250, overflow: 'truncate', interval: 0 }
        },
        visualMap: {
          min: minScore,
          max: maxScore,
          dimension: 2,
          right: 18,
          top: 'middle',
          orient: 'vertical',
          text: ['High -log10(p.adjust)', 'Low -log10(p.adjust)'],
          textGap: 8,
          inRange: { color: ['#60a5fa', '#34d399', '#facc15'] },
          calculable: false,
          itemWidth: 14,
          itemHeight: 110
        },
        series: [{
          type: 'scatter',
          data: seriesData,
          encode: { x: 0, y: 1, tooltip: [0, 1, 2, 3, 5, 6], itemName: 1 },
          symbolSize: (value) => {
            const overlapRatio = Number(value[5] || 0);
            if (overlapRatio > 0) {
              return Math.max(10, Math.min(30, 8 + overlapRatio * 90));
            }
            const count = Math.max(Number(value[3] || 0), 1);
            return Math.max(10, Math.min(28, Math.sqrt(count) * 3.8));
          },
          itemStyle: { opacity: 0.9 },
          emphasis: { focus: 'series' }
        }],
        graphic: rows.length === 0 ? [{ type: 'text', left: 'center', top: 'middle', style: { text: 'No terms pass p.adjust < 0.05', fill: '#6b7280', font: '14px sans-serif' } }] : []
      }, true);
      bindEnrichmentAxisHover(nextChart);
      return nextChart;
    };

    const syncEnrichmentTabButtons = () => {
      enrichmentTabButtons.forEach((button) => {
        const isActive = button.dataset.enrichmentTab === activeEnrichmentTab;
        button.classList.toggle('btn-primary', isActive);
        button.classList.toggle('btn-outline-primary', !isActive);
      });
    };

    const syncGoOntologyButtons = () => {
      goOntologyButtons.forEach((button) => {
        const isActive = button.dataset.goOntology === activeGoOntology;
        button.classList.toggle('btn-primary', isActive);
        button.classList.toggle('btn-outline-primary', !isActive);
      });
    };

    const fetchEnrichmentRows = async (mode, ontology, directionOverride = null) => {
      const url = new URL(window.location.href);
      const useScApi = !!isSingleCellGoKeggEnabled;
      url.searchParams.set('chart_api', useScApi ? 'single_cell_go_kegg_enrichment' : 'bulk_go_kegg_enrichment');
      url.searchParams.set('dataset_id', degDatasetId || pageDatasetId || '');
      const direction = directionOverride === null ? activeEnrichmentDirection : directionOverride;
      url.searchParams.set('direction', direction);
      url.searchParams.set('mode', mode);
      if (useScApi) {
        url.searchParams.set('perturbation_gene', activePerturbation || '');
      }
      if (mode === 'go') {
        url.searchParams.set('ontology', ontology || 'BP');
      }
      const res = await fetch(url.toString());
      if (!res.ok) {
        throw new Error('Failed to load GO/KEGG enrichment');
      }
      const json = await res.json();
      if (json && json.ok === false) {
        throw new Error(json.error || 'GO/KEGG enrichment error');
      }
      return (json && Array.isArray(json.rows)) ? json.rows : [];
    };

    let enrichmentProbeSeq = 0;
    const evaluateEnrichmentAvailabilityForPerturbation = async () => {
      // Keep old behavior for bulk/demo.
      if (!isSingleCellGoKeggEnabled) {
        enrichmentEverHadData = true;
        updateEnrichmentSectionVisibility();
        return;
      }
      if (!activePerturbation) {
        enrichmentEverHadData = false;
        updateEnrichmentSectionVisibility();
        return;
      }

      const seq = ++enrichmentProbeSeq;
      enrichmentAvailabilityProbing = true;
      updateEnrichmentSectionVisibility();
      // Section existence rule: keep when any option has data.
      const directions = ['all', 'up', 'down'];
      const goOntologies = ['BP', 'CC', 'MF'];
      let hasAny = false;
      let hadError = false;

      for (const dir of directions) {
        for (const ont of goOntologies) {
          let rows = [];
          try {
            rows = await fetchEnrichmentRows('go', ont, dir);
          } catch (_) {
            hadError = true;
            rows = [];
          }
          if (seq !== enrichmentProbeSeq) return;
          if (Array.isArray(rows) && rows.length > 0) {
            hasAny = true;
            break;
          }
        }
        if (hasAny) break;

        let keggRows = [];
        try {
          keggRows = await fetchEnrichmentRows('kegg', 'KEGG', dir);
        } catch (_) {
          hadError = true;
          keggRows = [];
        }
        if (seq !== enrichmentProbeSeq) return;
        if (Array.isArray(keggRows) && keggRows.length > 0) {
          hasAny = true;
          break;
        }
      }

      if (seq !== enrichmentProbeSeq) return;
      // Be conservative on transient backend/network errors: keep section visible.
      enrichmentEverHadData = hasAny || hadError;
      enrichmentAvailabilityProbing = false;
      updateEnrichmentSectionVisibility();
    };

    const showEnrichmentTab = async (tab) => {
      activeEnrichmentTab = tab;
      if (goPanel) {
        goPanel.classList.toggle('d-none', tab !== 'go');
      }
      if (keggPanel) {
        keggPanel.classList.toggle('d-none', tab !== 'kegg');
      }
      syncEnrichmentTabButtons();
      setTimeout(() => {
        if (tab === 'go' && goChart) {
          goChart.resize();
        }
        if (tab === 'kegg' && keggChart) {
          keggChart.resize();
        }
      }, 0);
      if (tab === 'go') {
        await renderGoEnrichment(activeGoOntology);
      } else {
        await renderKeggEnrichment();
      }
    };

    const renderGoEnrichment = async (ontology) => {
      activeGoOntology = ontology;
      syncGoOntologyButtons();
      let rows = [];
      if (isBulkGoKeggEnabled || isSingleCellGoKeggEnabled) {
        rows = await fetchEnrichmentRows('go', ontology);
      } else {
        rows = inflateEnrichmentRows((demoGoPayload && demoGoPayload.data && demoGoPayload.data[ontology]) || []);
      }
      goHasRows = rows.length > 0;
      if (rows.length > 0) {
        enrichmentEverHadData = true;
      }
      updateEnrichmentSectionVisibility();
      goChart = renderEnrichmentChart(goChart, goChartEl, '', rows, true);
      scheduleChartResize(goChart);
    };

    const renderKeggEnrichment = async () => {
      let rows = [];
      if (isBulkGoKeggEnabled || isSingleCellGoKeggEnabled) {
        rows = await fetchEnrichmentRows('kegg', 'KEGG');
      } else {
        rows = inflateEnrichmentRows((demoKeggPayload && demoKeggPayload.rows) || []);
      }
      keggHasRows = rows.length > 0;
      if (rows.length > 0) {
        enrichmentEverHadData = true;
      }
      updateEnrichmentSectionVisibility();
      keggChart = renderEnrichmentChart(keggChart, keggChartEl, '', rows, false);
      scheduleChartResize(keggChart);
    };

    enrichmentTabButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const tab = button.dataset.enrichmentTab;
        if (tab) {
          showEnrichmentTab(tab).catch(() => {
            goHasRows = false;
            keggHasRows = false;
            updateEnrichmentSectionVisibility();
          });
        }
      });
    });

    goOntologyButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const ontology = button.dataset.goOntology;
        if (ontology) {
          renderGoEnrichment(ontology).catch(() => {
            goHasRows = false;
            updateEnrichmentSectionVisibility();
          });
        }
      });
    });

    if (enrichmentDirectionSelect) {
      enrichmentDirectionSelect.addEventListener('change', () => {
        const v = String(enrichmentDirectionSelect.value || 'all').toLowerCase();
        activeEnrichmentDirection = (v === 'up' || v === 'down' || v === 'all') ? v : 'all';
        goHasRows = null;
        keggHasRows = null;
        showEnrichmentTab(activeEnrichmentTab).catch(() => {
          goHasRows = false;
          keggHasRows = false;
          updateEnrichmentSectionVisibility();
        });
      });
    }

    if (isSingleCellGoKeggEnabled) {
      enrichmentEverHadData = false;
      updateEnrichmentSectionVisibility();
    }
    showEnrichmentTab('go').catch(() => {
      goHasRows = false;
      keggHasRows = false;
      updateEnrichmentSectionVisibility();
    });
    evaluateEnrichmentAvailabilityForPerturbation().catch(() => {
      enrichmentEverHadData = false;
      enrichmentAvailabilityProbing = false;
      updateEnrichmentSectionVisibility();
    });

    const fetchGseaPayload = async () => {
      if (!(isBulkGseaEnabled || isSingleCellGseaEnabled)) {
        return { bar_rows: [], map_nodes: [], map_edges: [], has: false };
      }
      const url = new URL(window.location.href);
      const useScApi = !!isSingleCellGseaEnabled;
      url.searchParams.set('chart_api', useScApi ? 'single_cell_gsea' : 'bulk_gsea');
      url.searchParams.set('dataset_id', degDatasetId || pageDatasetId || '');
      if (useScApi) {
        url.searchParams.set('perturbation_gene', activePerturbation || '');
      }
      const res = await fetch(url.toString());
      if (!res.ok) {
        throw new Error('Failed to load GSEA');
      }
      const json = await res.json();
      if (json && json.ok === false) {
        throw new Error(json.error || 'GSEA error');
      }
      return json || { bar_rows: [], map_nodes: [], map_edges: [], has: false };
    };

    const renderGseaNesChart = (rows) => {
      if (!gseaNesEl) {
        return;
      }
      if (!gseaNesChart) {
        gseaNesChart = echarts.init(gseaNesEl);
      }
      const barRows = Array.isArray(rows) ? rows : [];
      const labels = barRows.map((x) => {
        const name = String(x.pathway || '').trim();
        return name;
      });
      const values = barRows.map((x) => Number(x.nes || 0));
      const fdrs = barRows.map((x) => Number(x.fdr || 1));
      const colors = values.map((v) => v >= 0 ? '#dc2626' : '#2563eb');

      gseaNesChart.setOption({
        title: { text: 'Top 20 Enriched Pathways (NES)', left: 'center', textStyle: { fontSize: 14, fontWeight: 600 } },
        tooltip: {
          trigger: 'axis',
          axisPointer: { type: 'shadow' },
          formatter: (params) => {
            const p = Array.isArray(params) ? params[0] : params;
            const idx = p?.dataIndex ?? 0;
            const pathway = labels[idx] || 'Unlabeled pathway';
            const nes = Number(values[idx] ?? 0);
            const fdr = Number(fdrs[idx] ?? 1);
            return `Pathway: ${pathway}<br/>NES: ${nes.toFixed(3)}<br/>FDR: ${fdr.toExponential(2)}`;
          }
        },
        grid: { left: 160, right: 24, top: 44, bottom: 24 },
        xAxis: { type: 'value', splitLine: { lineStyle: { color: '#e9edf3' } } },
        yAxis: {
          type: 'category',
          data: labels,
          inverse: true,
          triggerEvent: true,
          axisLabel: { width: 140, overflow: 'truncate' }
        },
        series: [{
          type: 'bar',
          data: values,
          itemStyle: { color: (p) => colors[p.dataIndex] || '#64748b' }
        }],
        graphic: barRows.length === 0 ? [{ type: 'text', left: 'center', top: 'middle', style: { text: 'No pathways under FDR < 0.25', fill: '#6b7280', font: '14px sans-serif' } }] : []
      }, true);
      bindEnrichmentAxisHover(gseaNesChart);
    };

    const renderGseaMapChart = (nodes, edges) => {
      if (!gseaMapEl) {
        return;
      }
      if (!gseaMapChart) {
        gseaMapChart = echarts.init(gseaMapEl);
      }
      const nodeList = Array.isArray(nodes) ? nodes : [];
      const edgeList = Array.isArray(edges) ? edges : [];
      const graphNodes = nodeList.map((n) => {
        const ratio = Math.max(0, Number(n.overlap_ratio || 0));
        const size = Math.max(8, Math.min(42, ratio * 220));
        const nes = Number(n.nes || 0);
        const direction = nes >= 0 ? 'Up-regulated pathway' : 'Down-regulated pathway';
        return {
          id: String(n.id),
          name: String(n.name || ''),
          value: nes,
          symbolSize: size,
          category: nes >= 0 ? 0 : 1,
          direction,
          itemStyle: {
            color: nes >= 0 ? '#dc2626' : '#2563eb'
          }
        };
      });
      const graphEdges = edgeList.map((e) => ({
        source: String(e.source),
        target: String(e.target),
        lineStyle: { width: Math.max(0.5, Math.min(4, Number(e.weight || 0) * 6)), opacity: 0.5, color: '#9ca3af' }
      }));

      gseaMapChart.setOption({
        title: { text: 'Enrichment Map', left: 'center', textStyle: { fontSize: 14, fontWeight: 600 } },
        tooltip: {
          formatter: (p) => {
            if (p.dataType === 'node') {
              const d = p.data || {};
              const nes = Number(d.value || 0);
              return `Pathway: ${d.name || 'NA'}<br/>Direction: ${d.direction || 'NA'}<br/>NES: ${nes.toFixed(3)}`;
            }
            return '';
          }
        },
        legend: {
          top: 24,
          right: 8,
          orient: 'vertical',
          data: ['Up-regulated pathway', 'Down-regulated pathway'],
          textStyle: { fontSize: 11 }
        },
        series: [{
          type: 'graph',
          layout: 'force',
          roam: true,
          draggable: true,
          force: { repulsion: 160, edgeLength: [30, 120], gravity: 0.06 },
          categories: [
            { name: 'Up-regulated pathway', itemStyle: { color: '#dc2626' } },
            { name: 'Down-regulated pathway', itemStyle: { color: '#2563eb' } }
          ],
          data: graphNodes,
          links: graphEdges,
          label: { show: true, position: 'right', fontSize: 9, formatter: (p) => (p.name || '').slice(0, 28) },
          lineStyle: { opacity: 0.5, width: 1, color: '#9ca3af' }
        }],
        graphic: graphNodes.length === 0 ? [{ type: 'text', left: 'center', top: 'middle', style: { text: 'No network nodes available', fill: '#6b7280', font: '14px sans-serif' } }] : []
      }, true);
    };

    const showGsea = async () => {
      try {
        const payload = await fetchGseaPayload();
        const barRows = payload?.bar_rows || [];
        const mapNodes = payload?.map_nodes || [];
        const mapEdges = payload?.map_edges || [];
        const hasGsea = barRows.length > 0 || mapNodes.length > 0;
        setSectionVisible('gseaSection', hasGsea);
        if (gseaEmptyEl) {
          gseaEmptyEl.classList.toggle('d-none', hasGsea);
        }
        renderGseaNesChart(barRows);
        renderGseaMapChart(mapNodes, mapEdges);
        if (hasGsea) {
          scheduleChartResize(gseaNesChart, gseaMapChart);
        }
      } catch (e) {
        setSectionVisible('gseaSection', false);
        if (gseaEmptyEl) {
          gseaEmptyEl.classList.remove('d-none');
        }
      }
    };

    showGsea().catch(() => {});
    window.addEventListener('resize', () => {
      if (gseaNesChart) gseaNesChart.resize();
      if (gseaMapChart) gseaMapChart.resize();
    });

      const formatSig = (value, key = '') => {
        const num = Number(value);
        if (!Number.isFinite(num)) {
          return '';
        }
        if (key === 'pvalue' || key === 'padj') {
          if (num === 0) return '0';
          return num.toExponential(3);
        }
        return num.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 6 });
      };

      const updateSortIndicators = () => {
        sortHeaders.forEach((header) => {
          if (header.dataset.key === sortKey) {
            header.dataset.active = sortDir;
          } else {
            header.dataset.active = '';
          }
        });
      };

      const renderTable = (rows, total, page) => {
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        currentPage = Math.min(Math.max(page, 1), totalPages);
        tableBody.innerHTML = '';

        if (!rows || rows.length === 0) {
          const tr = document.createElement('tr');
          const td = document.createElement('td');
          td.colSpan = (degTableColumns || []).length || 1;
          td.className = 'text-center text-muted py-3';
          td.textContent = 'No differentially expressed genes';
          tr.appendChild(td);
          tableBody.appendChild(tr);
          pagination.style.display = 'none';
          pageInfo.textContent = '';
          prevBtn.disabled = true;
          nextBtn.disabled = true;
          return;
        }

        rows.forEach((row) => {
          const tr = document.createElement('tr');
          (degTableColumns || []).forEach((col) => {
            const td = document.createElement('td');
            const rawValue = row ? row[col.key] : '';
            if (col.type === 'text') {
              td.className = 'text-break';
              td.textContent = rawValue ?? '';
            } else {
              td.textContent = formatSig(rawValue, col.key || '');
            }
            tr.appendChild(td);
          });
          tableBody.appendChild(tr);
        });

        pagination.style.display = 'flex';
        pageInfo.textContent = `Page ${currentPage} / ${totalPages}`;
        prevBtn.disabled = currentPage <= 1;
        nextBtn.disabled = currentPage >= totalPages;
      };

      const updateDegCountBadge = (count) => {
        if (!degGeneCountBadge) {
          return;
        }
        const safeCount = Number.isFinite(Number(count)) ? Number(count) : 0;
        degGeneCountBadge.textContent = `Number of Differentially Expressed Genes: ${safeCount}`;
      };

      const fetchDegPage = async () => {
        pageSize = Number(pageSizeSelect.value) || 10;
        const url = new URL(window.location.href);
        url.searchParams.set('deg_page', String(currentPage));
        url.searchParams.set('page_size', String(pageSize));
        url.searchParams.set('sort_key', sortKey);
        url.searchParams.set('sort_dir', sortDir);
        if (degDatasetId) {
          url.searchParams.set('dataset_id', degDatasetId);
        }
        if (isSingleCellDegEnabled) {
          url.searchParams.set('perturbation_gene', activePerturbation || '');
          url.searchParams.set('regulation', 'all');
        }
        const response = await fetch(url.toString());
        if (!response.ok) {
          throw new Error('Failed to load DEG table');
        }
        return response.json();
      };

      const fetchSingleCellVolcano = async () => {
        if (!isSingleCellDegEnabled || !activePerturbation) {
          setSectionVisible('degVolcanoSection', false);
          return;
        }
        const url = new URL(window.location.href);
        url.searchParams.set('chart_api', 'single_cell_deg_volcano');
        url.searchParams.set('dataset_id', degDatasetId || pageDatasetId || '');
        url.searchParams.set('perturbation_gene', activePerturbation);
        url.searchParams.set('regulation', 'all');
        const res = await fetch(url.toString());
        if (!res.ok) {
          throw new Error('Failed to load single-cell DEG volcano');
        }
        const json = await res.json();
        if (json && json.ok === false) {
          throw new Error(json.error || 'single-cell DEG volcano error');
        }
        const volcano = (json && json.volcano) ? json.volcano : { other: [], up: [], down: [] };
        renderDegVolcano(volcano);
      };

      const loadTable = async () => {
        try {
          const data = await fetchDegPage();
          const rows = data.rows || [];
          const total = Number(data.total) || 0;
          if (isSingleCellDegEnabled) {
            activePerturbation = (data.perturbation_gene || activePerturbation || '').trim();
            if (scDegPerturbSelect && activePerturbation && scDegPerturbSelect.value !== activePerturbation) {
              scDegPerturbSelect.value = activePerturbation;
            }
          }
          updateDegCountBadge(total);
          setSectionVisible('degTableSection', true);
          if (total <= 0) {
            setSectionVisible('degVolcanoSection', false);
            if (!isSingleCellDegEnabled) {
              setSectionVisible('enrichmentSection', false);
              setSectionVisible('gseaSection', false);
            }
          }
          renderTable(rows, total, Number(data.page) || 1);
          if (isSingleCellDegEnabled) {
            fetchSingleCellVolcano().catch(() => {});
          }
          if (isBulkGseaEnabled || isSingleCellGseaEnabled) {
            showGsea().catch(() => {});
          }
        } catch (error) {
          const span = (degTableColumns || []).length || 1;
          tableBody.innerHTML = `<tr><td colspan="${span}" class="text-center text-muted py-3">Failed to load DEG data.</td></tr>`;
          pagination.style.display = 'none';
          updateDegCountBadge(0);
          setSectionVisible('degTableSection', true);
          setSectionVisible('degVolcanoSection', false);
          if (!isSingleCellDegEnabled) {
            setSectionVisible('enrichmentSection', false);
            setSectionVisible('gseaSection', false);
          }
        }
      };

      sortHeaders.forEach((header) => {
        header.addEventListener('click', () => {
          const key = header.dataset.key;
          if (!key) {
            return;
          }
          if (sortKey === key) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
          } else {
            sortKey = key;
            sortDir = 'desc';
          }
          currentPage = 1;
          updateSortIndicators();
          loadTable();
        });
      });

      pageSizeSelect.addEventListener('change', () => {
        currentPage = 1;
        loadTable();
      });

      prevBtn.addEventListener('click', () => {
        currentPage = Math.max(1, currentPage - 1);
        loadTable();
      });

      nextBtn.addEventListener('click', () => {
        currentPage += 1;
        loadTable();
      });
      if (scDegPerturbSelect) {
        scDegPerturbSelect.addEventListener('change', () => {
          activePerturbation = scDegPerturbSelect.value || '';
          currentPage = 1;
          loadTable();
          // Hide first, then show only if data exists for this target gene.
          enrichmentEverHadData = false;
          enrichmentAvailabilityProbing = true;
          goHasRows = null;
          keggHasRows = null;
          updateEnrichmentSectionVisibility();
          activeEnrichmentTab = 'go';
          activeGoOntology = 'BP';
          if (enrichmentDirectionSelect) {
            enrichmentDirectionSelect.value = 'all';
            activeEnrichmentDirection = 'all';
          }
          evaluateEnrichmentAvailabilityForPerturbation().catch(() => {
            enrichmentEverHadData = false;
            enrichmentAvailabilityProbing = false;
            updateEnrichmentSectionVisibility();
          });
          showEnrichmentTab('go').catch(() => {
            goHasRows = false;
            keggHasRows = false;
            updateEnrichmentSectionVisibility();
          });
          showGsea().catch(() => {});
        });
      }
      updateSortIndicators();
      loadTable();
    }
    
    if (document.getElementById('chartSeqSat')) {
        const parsePercent = (val) => {
           if (!val) return 0;
           const s = val.toString().trim();
           if (s.includes('%')) {
               const n = parseFloat(s.replace('%', ''));
               return isNaN(n) ? 0 : Number(n.toFixed(2));
           }
           const n = parseFloat(s);
           // if it's a decimal like 0.98, multiply by 100
           return isNaN(n) ? 0 : Number((n * 100).toFixed(2));
        };
        const satVal = parsePercent(scSatStr);
        const fracVal = parsePercent(scFracStr);

        const gaugeOptions = (val, title) => ({
            series: [
                {
                    type: 'gauge',
                    startAngle: 180,
                    endAngle: 0,
                    center: ['50%', '75%'],
                    radius: '100%',
                    min: 0,
                    max: 100,
                    splitNumber: 2,
                    axisLine: {
                        lineStyle: { width: 10, color: [[1, '#e2e8f0']] }
                    },
                    progress: {
                        show: true,
                        width: 10,
                        roundCap: true,
                        itemStyle: {
                            color: new echarts.graphic.LinearGradient(0, 0, 1, 0, [
                                { offset: 0, color: '#60a5fa' },
                                { offset: 1, color: '#3b82f6' }
                            ])
                        }
                    },
                    pointer: { show: false },
                    axisTick: { show: false },
                    splitLine: { show: false },
                    axisLabel: { show: false },
                    title: {
                        show: true,
                        offsetCenter: [0, '-22%'],
                        fontSize: 13,
                        lineHeight: 16,
                        align: 'center',
                        color: '#6b7280'
                    },
                    detail: {
                        valueAnimation: true,
                        offsetCenter: [0, '15%'],
                        fontSize: 22,
                        fontWeight: 'bold',
                        formatter: '{value}%',
                        color: '#1f2937'
                    },
                    data: [{ value: val, name: title }]
                }
            ]
        });

        const satChart = echarts.init(document.getElementById('chartSeqSat'));
        satChart.setOption(gaugeOptions(satVal, 'Sequencing\nSaturation'));

        if (document.getElementById('chartFracRead')) {
            const fracChart = echarts.init(document.getElementById('chartFracRead'));
            fracChart.setOption(gaugeOptions(fracVal, 'Fraction Reads\nin Cells'));
            
            window.addEventListener('resize', () => {
                satChart.resize();
                fracChart.resize();
            });
        }
    }
  </script>
<script>document.getElementById('year').textContent = new Date().getFullYear();</script>
</body>
</html>





