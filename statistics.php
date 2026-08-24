<?php
require_once __DIR__ . '/config.php';

function classifyAssayTypeForStats($raw): string
{
  $rawText = trim((string)$raw);
  if ($rawText === '') return 'OTHER';

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
    if (strpos($u, 'KO/KD') !== false || strpos($u, 'KD') !== false || strpos($u, ' KO') !== false || str_starts_with($u, 'KO')) {
      $cats['KO/KD'] = true;
      continue;
    }
    if (strpos($u, 'OE') !== false) {
      $cats['OE'] = true;
    }
  }

  $keys = array_keys($cats);
  if (count($keys) === 0) return 'OTHER';
  if (count($keys) === 1) return $keys[0];
  if (isset($cats['KO/KD']) && isset($cats['OE']) && count($keys) === 2) return 'MIX';

  $order = ['KO/KD' => 1, 'OE' => 2, 'CRISPR-KO' => 3, 'CRISPRa' => 4, 'CRISPRi' => 5];
  usort($keys, static fn($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99) ?: strcmp($a, $b));
  return implode(' + ', $keys);
}

$overview = [
  'human_bulk_samples' => 0,
  'human_single_cell_samples' => 0,
  'human_single_cell_cells' => 0,
  'mouse_bulk_samples' => 0,
  'mouse_single_cell_samples' => 0,
  'mouse_single_cell_cells' => 0,
];
$section2Stats = [
  'all' => ['classification' => [], 'tissue' => []],
  'bulk' => ['classification' => [], 'tissue' => []],
  'single_cell' => ['classification' => [], 'tissue' => []],
];
$targetSummary = [
  'all_records' => 0,
  'all_unique_genes' => 0,
  'bulk_records' => 0,
  'bulk_unique_genes' => 0,
  'sc_records' => 0,
  'sc_unique_genes' => 0,
];
$allPerturbTypeRatio = [
  'KO/KD' => 0,
  'OE' => 0,
  'MIX' => 0,
  'CRISPR-KO' => 0,
  'CRISPRa' => 0,
  'CRISPRi' => 0,
  'CRISPRa + CRISPRi' => 0,
];
$bulkPerturbTypeRatio = [
  'Bulk KO/KD' => 0,
  'Bulk OE' => 0,
  'Bulk MIX' => 0,
];
$allSingleMulti = [
  'Single-gene (All)' => 0,
  'Multi-gene (All)' => 0,
];
$bulkSingleMulti = [
  'Single-gene (Bulk)' => 0,
  'Multi-gene (Bulk)' => 0,
];
$scPerturbTypeRatio = [
  'sc CRISPRi' => 0,
  'sc CRISPRa' => 0,
  'sc CRISPRa + CRISPRi' => 0,
  'sc CRISPR-KO' => 0,
  'sc OE' => 0,
];
$scSingleMulti = [
  'Single-gene (Single cell)' => 0,
  'Multi-gene (Single cell)' => 0,
];
$allTopGenes = [];
$bulkTopGenes = [];
$scTopGenes = [];
$scMixscapeSummary = [
  'Human' => ['effective' => 0, 'ineffective' => 0, 'control' => 0],
  'Mouse' => ['effective' => 0, 'ineffective' => 0, 'control' => 0],
];
$scPerturbRatioValues = [];
$dbError = null;

try {
  $pdo = new PDO('sqlite:' . DB_FILE);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

  $datasetRows = $pdo->query(
    "SELECT
      dataset_id,
      external_sample_control_accession,
      external_sample_treatment_accession,
      single_cell_pertubation_external_sample_accession,
      single_cell_pertubation_filtered_cell_count,
      single_cell_pertubation_raw_cell_count,
      single_cell_assay_target_gene_cellcount
     FROM dataset_meta"
  )->fetchAll();

  $splitAccessions = static function ($raw): array {
    $text = trim((string)$raw);
    if ($text === '') {
      return [];
    }
    $parts = preg_split('/[|,;]+/', $text) ?: [];
    $clean = [];
    foreach ($parts as $part) {
      $item = strtoupper(trim((string)$part));
      if ($item !== '') {
        $clean[$item] = true;
      }
    }
    return array_keys($clean);
  };

  $bulkSampleSet = [
    'HSBK' => [],
    'MMBK' => [],
  ];
  $scSampleSet = [
    'HSSC' => [],
    'MMSC' => [],
  ];
  $scCellBySample = [
    'HSSC' => [],
    'MMSC' => [],
  ];

  foreach ($datasetRows as $row) {
    $datasetId = strtoupper(trim((string)($row['dataset_id'] ?? '')));
    $prefix = substr($datasetId, 0, 4);

    if ($prefix === 'HSBK' || $prefix === 'MMBK') {
      $samples = array_merge(
        $splitAccessions($row['external_sample_control_accession'] ?? ''),
        $splitAccessions($row['external_sample_treatment_accession'] ?? '')
      );
      foreach ($samples as $sample) {
        $bulkSampleSet[$prefix][$sample] = true;
      }
      continue;
    }

    if ($prefix === 'HSSC' || $prefix === 'MMSC') {
      $sampleAcc = strtoupper(trim((string)($row['single_cell_pertubation_external_sample_accession'] ?? '')));
      if ($sampleAcc === '') {
        continue;
      }

      $scSampleSet[$prefix][$sampleAcc] = true;

      $filteredCell = is_numeric($row['single_cell_pertubation_filtered_cell_count'] ?? null)
        ? (float)$row['single_cell_pertubation_filtered_cell_count']
        : null;
      $rawCell = is_numeric($row['single_cell_pertubation_raw_cell_count'] ?? null)
        ? (float)$row['single_cell_pertubation_raw_cell_count']
        : null;
      $targetText = trim((string)($row['single_cell_assay_target_gene_cellcount'] ?? ''));
      $targetCell = is_numeric($targetText) ? (float)$targetText : null;
      $cellCount = $filteredCell ?? $rawCell ?? $targetCell ?? 0.0;

      if (!isset($scCellBySample[$prefix][$sampleAcc]) || $cellCount > $scCellBySample[$prefix][$sampleAcc]) {
        $scCellBySample[$prefix][$sampleAcc] = $cellCount;
      }
    }
  }

  $overview['human_bulk_samples'] = count($bulkSampleSet['HSBK']);
  $overview['mouse_bulk_samples'] = count($bulkSampleSet['MMBK']);
  $overview['human_single_cell_samples'] = count($scSampleSet['HSSC']);
  $overview['mouse_single_cell_samples'] = count($scSampleSet['MMSC']);
  $overview['human_single_cell_cells'] = array_sum($scCellBySample['HSSC']);
  $overview['mouse_single_cell_cells'] = array_sum($scCellBySample['MMSC']);

  $buildSection2Rows = static function (PDO $pdo, string $mode, string $labelBaseExpr, int $limit): array {
    $mode = strtolower(trim($mode));
    $prefixExpr = "SUBSTR(COALESCE(dataset_id, ''), 1, 4)";
    $whereExpr = "1 = 1";

    if ($mode === 'bulk') {
      $whereExpr = "$prefixExpr IN ('HSBK', 'MMBK')";
    } elseif ($mode === 'single_cell') {
      $whereExpr = "$prefixExpr IN ('HSSC', 'MMSC')";
    }

    $labelExpr = $labelBaseExpr;

    $sql = "SELECT
      $labelExpr AS label,
      COUNT(*) AS total
     FROM dataset_meta
     WHERE $whereExpr
     GROUP BY label
     ORDER BY total DESC
     LIMIT $limit";

    return $pdo->query($sql)->fetchAll() ?: [];
  };

  $classificationLabelExpr = "COALESCE(NULLIF(TRIM(meta_biosample_classification_type), ''), 'Unknown')";
  $tissueLabelExpr = "COALESCE(NULLIF(TRIM(meta_biosample_tissue_name), ''), NULLIF(TRIM(meta_biosample_classification_type), ''), 'Unknown')";

  foreach (['all', 'bulk', 'single_cell'] as $mode) {
    $section2Stats[$mode]['classification'] = $buildSection2Rows($pdo, $mode, $classificationLabelExpr, 12);
    $section2Stats[$mode]['tissue'] = $buildSection2Rows($pdo, $mode, $tissueLabelExpr, 10);
  }

  $targetSummary = $pdo->query(
    "SELECT
      (SELECT COUNT(*) FROM dataset_meta) AS all_records,
      (SELECT COUNT(*) FROM dataset_meta WHERE SUBSTR(COALESCE(dataset_id, ''), 1, 4) IN ('HSBK', 'MMBK')) AS bulk_records,
      (SELECT COUNT(*) FROM dataset_meta WHERE SUBSTR(COALESCE(dataset_id, ''), 1, 4) IN ('HSSC', 'MMSC')) AS sc_records"
  )->fetch() ?: $targetSummary;
  // Use fixed summary values (no runtime calculation for unique perturbed genes).
  $targetSummary['all_unique_genes'] = 10332;
  $targetSummary['bulk_unique_genes'] = 6215;
  $targetSummary['sc_unique_genes'] = 6149;

  $perturbRaw = $pdo->query(
    "SELECT
      LOWER(COALESCE(meta_assay_scale, '')) AS scale,
      LOWER(COALESCE(meta_assay_type, '')) AS assay_type,
      COUNT(*) AS total
     FROM dataset_meta
     GROUP BY scale, assay_type"
  )->fetchAll();

  foreach ($perturbRaw as $row) {
    $scale = (string)$row['scale'];
    $assayType = (string)$row['assay_type'];
    $total = (int)$row['total'];
    $assayCategory = classifyAssayTypeForStats($assayType);

    if (isset($allPerturbTypeRatio[$assayCategory])) {
      $allPerturbTypeRatio[$assayCategory] += $total;
    }

    if ($scale === 'bulk') {
      if ($assayCategory === 'KO/KD') {
        $bulkPerturbTypeRatio['Bulk KO/KD'] += $total;
      } elseif ($assayCategory === 'OE') {
        $bulkPerturbTypeRatio['Bulk OE'] += $total;
      } elseif ($assayCategory === 'MIX') {
        $bulkPerturbTypeRatio['Bulk MIX'] += $total;
      }
    }

    if (strpos($scale, 'single') !== false || strpos($scale, 'sc') !== false) {
      if ($assayCategory === 'CRISPRi') {
        $scPerturbTypeRatio['sc CRISPRi'] += $total;
      } elseif ($assayCategory === 'CRISPRa') {
        $scPerturbTypeRatio['sc CRISPRa'] += $total;
      } elseif ($assayCategory === 'CRISPRa + CRISPRi') {
        $scPerturbTypeRatio['sc CRISPRa + CRISPRi'] += $total;
      } elseif ($assayCategory === 'CRISPR-KO') {
        $scPerturbTypeRatio['sc CRISPR-KO'] += $total;
      } elseif ($assayCategory === 'OE') {
        $scPerturbTypeRatio['sc OE'] += $total;
      }
    }
  }

  $singleMulti = $pdo->query(
    "SELECT
      SUM(CASE WHEN COALESCE(is_multigene, 0) = 0 THEN 1 ELSE 0 END) AS single_gene,
      SUM(CASE WHEN COALESCE(is_multigene, 0) = 1 THEN 1 ELSE 0 END) AS multi_gene
     FROM dataset_meta
     WHERE LOWER(meta_assay_scale) = 'bulk'"
  )->fetch();

  if ($singleMulti) {
    $bulkSingleMulti['Single-gene (Bulk)'] = (int)($singleMulti['single_gene'] ?? 0);
    $bulkSingleMulti['Multi-gene (Bulk)'] = (int)($singleMulti['multi_gene'] ?? 0);
  }

  $allSingleMultiRaw = $pdo->query(
    "SELECT
      SUM(CASE WHEN COALESCE(is_multigene, 0) = 0 THEN 1 ELSE 0 END) AS single_gene,
      SUM(CASE WHEN COALESCE(is_multigene, 0) = 1 THEN 1 ELSE 0 END) AS multi_gene
     FROM dataset_meta"
  )->fetch();
  if ($allSingleMultiRaw) {
    $allSingleMulti['Single-gene (All)'] = (int)($allSingleMultiRaw['single_gene'] ?? 0);
    $allSingleMulti['Multi-gene (All)'] = (int)($allSingleMultiRaw['multi_gene'] ?? 0);
  }

  $scSingleMultiRaw = $pdo->query(
    "SELECT
      SUM(CASE WHEN COALESCE(is_multigene, 0) = 0 THEN 1 ELSE 0 END) AS single_gene,
      SUM(CASE WHEN COALESCE(is_multigene, 0) = 1 THEN 1 ELSE 0 END) AS multi_gene
     FROM dataset_meta
     WHERE SUBSTR(COALESCE(dataset_id, ''), 1, 4) IN ('HSSC', 'MMSC')"
  )->fetch();
  if ($scSingleMultiRaw) {
    $scSingleMulti['Single-gene (Single cell)'] = (int)($scSingleMultiRaw['single_gene'] ?? 0);
    $scSingleMulti['Multi-gene (Single cell)'] = (int)($scSingleMultiRaw['multi_gene'] ?? 0);
  }

  $bulkTopGenes = $pdo->query(
    "SELECT
      TRIM(tg.gene_name) AS label,
      COUNT(*) AS total
     FROM target_genes tg
     INNER JOIN dataset_meta dm ON dm.id = tg.perbbase_id
     WHERE LOWER(dm.meta_assay_scale) = 'bulk'
       AND TRIM(COALESCE(tg.gene_name, '')) <> ''
     GROUP BY TRIM(tg.gene_name)
     ORDER BY total DESC, TRIM(tg.gene_name) COLLATE BINARY ASC
     LIMIT 50"
  )->fetchAll();

  $allTopGenes = $pdo->query(
    "SELECT
      TRIM(tg.gene_name) AS label,
      COUNT(*) AS total
     FROM target_genes tg
     INNER JOIN dataset_meta dm ON dm.id = tg.perbbase_id
     WHERE TRIM(COALESCE(tg.gene_name, '')) <> ''
     GROUP BY TRIM(tg.gene_name)
     ORDER BY total DESC, TRIM(tg.gene_name) COLLATE BINARY ASC
     LIMIT 50"
  )->fetchAll();

  $scTopGenes = $pdo->query(
    "SELECT
      TRIM(tg.gene_name) AS label,
      COUNT(*) AS total
     FROM target_genes tg
     INNER JOIN dataset_meta dm ON dm.id = tg.perbbase_id
     WHERE SUBSTR(COALESCE(dm.dataset_id, ''), 1, 4) IN ('HSSC', 'MMSC')
       AND TRIM(COALESCE(tg.gene_name, '')) <> ''
     GROUP BY TRIM(tg.gene_name)
     ORDER BY total DESC, TRIM(tg.gene_name) COLLATE BINARY ASC
     LIMIT 50"
  )->fetchAll();

  $scStatePdo = new PDO('sqlite:' . __DIR__ . '/sqlite3/single_cell_PerturbStates.db');
  $scStatePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $scStatePdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $stateRows = $scStatePdo->query("SELECT dataset_id, control_KO_NP FROM Perturbation_States")->fetchAll();
  foreach ($stateRows as $stateRow) {
    $datasetId = strtoupper(trim((string)($stateRow['dataset_id'] ?? '')));
    $prefix = substr($datasetId, 0, 4);
    $species = $prefix === 'HSSC' ? 'Human' : ($prefix === 'MMSC' ? 'Mouse' : null);
    if ($species === null) {
      continue;
    }

    $parts = preg_split('/[,\s]+/', trim((string)($stateRow['control_KO_NP'] ?? ''))) ?: [];
    $vals = array_values(array_map('intval', array_filter($parts, static fn($v) => $v !== '')));
    if (count($vals) < 3) {
      continue;
    }

    // control_KO_NP stores: non-perturbed, perturbed, control.
    $scMixscapeSummary[$species]['ineffective'] += $vals[0];
    $scMixscapeSummary[$species]['effective'] += $vals[1];
    $scMixscapeSummary[$species]['control'] += $vals[2];
  }

  $scRatioPdo = new PDO('sqlite:' . __DIR__ . '/sqlite3/single_cell_pertubation_ratio.db');
  $scRatioPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $scRatioPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $ratioRows = $scRatioPdo->query("SELECT perturbed_ratio FROM perturb_ratio_meta")->fetchAll();
  foreach ($ratioRows as $ratioRow) {
    $ratio = $ratioRow['perturbed_ratio'] ?? null;
    if (!is_numeric($ratio)) {
      continue;
    }
    $value = (float)$ratio;
    if ($value < 0) {
      $value = 0.0;
    } elseif ($value > 1) {
      $value = 1.0;
    }
    $scPerturbRatioValues[] = $value;
  }
} catch (Throwable $e) {
  $dbError = $e->getMessage();
}

$section1Labels = ['Human', 'Mouse'];
$section1BulkSampleData = [
  (int)($overview['human_bulk_samples'] ?? 0),
  (int)($overview['mouse_bulk_samples'] ?? 0),
];
$section1ScSampleData = [
  (int)($overview['human_single_cell_samples'] ?? 0),
  (int)($overview['mouse_single_cell_samples'] ?? 0),
];
$section1ScCellData = [
  (int)round((float)($overview['human_single_cell_cells'] ?? 0)),
  (int)round((float)($overview['mouse_single_cell_cells'] ?? 0)),
];

$section2ChartData = [];
foreach ($section2Stats as $mode => $statGroup) {
  $classRows = $statGroup['classification'] ?? [];
  $tissueRows = $statGroup['tissue'] ?? [];
  $section2ChartData[$mode] = [
    'classification' => [
      'labels' => array_map(static fn($row) => $row['label'], $classRows),
      'data' => array_map(static fn($row) => (int)$row['total'], $classRows),
    ],
    'tissue' => [
      'labels' => array_map(static fn($row) => $row['label'], $tissueRows),
      'data' => array_map(static fn($row) => (int)$row['total'], $tissueRows),
    ],
  ];
}

$bulkPerturbTypeLabels = array_keys($bulkPerturbTypeRatio);
$bulkPerturbTypeData = array_values($bulkPerturbTypeRatio);
$allPerturbTypeLabels = array_keys($allPerturbTypeRatio);
$allPerturbTypeData = array_values($allPerturbTypeRatio);
$scPerturbTypeLabels = array_keys($scPerturbTypeRatio);
$scPerturbTypeData = array_values($scPerturbTypeRatio);

$allSingleMultiLabels = array_keys($allSingleMulti);
$allSingleMultiData = array_values($allSingleMulti);
$bulkSingleMultiLabels = array_keys($bulkSingleMulti);
$bulkSingleMultiData = array_values($bulkSingleMulti);
$scSingleMultiLabels = array_keys($scSingleMulti);
$scSingleMultiData = array_values($scSingleMulti);

$allTopGeneLabels = array_map(static fn($row) => $row['label'], $allTopGenes);
$allTopGeneData = array_map(static fn($row) => (int)$row['total'], $allTopGenes);
$topGeneLabels = array_map(static fn($row) => $row['label'], $bulkTopGenes);
$topGeneData = array_map(static fn($row) => (int)$row['total'], $bulkTopGenes);
$scTopGeneLabels = array_map(static fn($row) => $row['label'], $scTopGenes);
$scTopGeneData = array_map(static fn($row) => (int)$row['total'], $scTopGenes);
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
  <title>PerturbCorpus Statistics</title>
  <link href="static/lib/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet" />
  <link href="static/style.css?ver=<?php echo uniqid(); ?>" rel="stylesheet" />
  <style>
    .chart-wrap {
      position: relative;
      height: 320px;
    }
    .section1-chart-wrap {
      height: 320px;
    }
    .section1-header {
      min-height: 38px;
    }

    .detail-shell {
      max-width: 1500px;
    }

    .detail-panel {
      background: rgba(255, 255, 255, 0.95);
      border: 1px solid rgba(204, 210, 220, 0.95);
      box-shadow: 0 12px 32px rgba(27, 43, 70, 0.06);
    }

    .flat-option-group {
      display: inline-flex;
      flex-wrap: wrap;
      gap: 6px;
      align-items: center;
    }

    .flat-option-btn {
      border: 1px solid #d6deeb;
      background: #ffffff;
      color: #2f3f55;
      border-radius: 999px;
      font-size: 0.8rem;
      line-height: 1.2;
      padding: 0.28rem 0.62rem;
      transition: all 0.15s ease;
      cursor: pointer;
      user-select: none;
    }

    .flat-option-btn:hover {
      border-color: #94a9cc;
      color: #1f3558;
    }

    .flat-option-btn.is-active {
      border-color: #2f5597;
      background: #2f5597;
      color: #ffffff;
      box-shadow: 0 3px 10px rgba(47, 85, 151, 0.28);
    }

    .flat-select-hidden {
      display: none !important;
    }

    @media (max-width: 768px) {
      .chart-wrap {
        height: 260px;
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
</script>

  <main class="layout-page">
    <div class="container-fluid py-2 pt-2 detail-shell">
      <div class="text-center mb-5">
        <h2 class="display-5 fw-bold mb-3" style="color: #2c3e50;">Statistics Dashboard</h2>
      </div>

      <?php if ($dbError !== null): ?>
        <div class="row justify-content-center mb-4">
          <div class="col-12 bg-white border rounded-4 shadow-sm p-3 p-md-4">
            <div class="alert alert-danger mb-0" role="alert">
              Failed to load statistics from the database: <?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="row justify-content-center">
        <div class="col-12 bg-white border rounded-3 shadow-sm p-3 p-md-4 p-lg-5 detail-panel">

          <section class="mb-5">
            <h3 class="h3 fw-bold mb-3" style="color: #2c3e50;">1. Sample and Cell Statistics</h3>
            <div class="row g-4">
              <div class="col-12 col-lg-6">
                <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                  <div class="d-flex align-items-center justify-content-between gap-2 mb-3 section1-header">
                    <h4 class="h5 mb-0">Bulk</h4>
                    <div style="width: 130px;"></div>
                  </div>
                  <div class="chart-wrap section1-chart-wrap">
                    <canvas id="bulkSampleChart"></canvas>
                  </div>
                </div>
              </div>
              <div class="col-12 col-lg-6">
                <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                  <div class="d-flex align-items-center justify-content-between gap-2 mb-3 section1-header">
                    <h4 class="h5 mb-0">Single cell</h4>
                    <div class="d-flex align-items-center gap-2">
                      <label class="small text-secondary" for="singleCellMetric">Mode</label>
                      <select id="singleCellMetric" class="form-select form-select-sm flat-select" style="width: 130px;">
                        <option value="cell" selected>Cell</option>
                        <option value="sample">Sample</option>
                      </select>
                    </div>
                  </div>
                  <div class="chart-wrap section1-chart-wrap">
                    <canvas id="singleCellChart"></canvas>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section class="mb-5">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
              <h3 class="h3 fw-bold mb-0" style="color: #2c3e50;">2. Tissue and Cell Type Statistics</h3>
              <div class="d-flex align-items-center gap-2">
                <label class="small text-secondary" for="section2Mode">Mode</label>
                <select id="section2Mode" class="form-select form-select-sm flat-select" style="width: 160px;">
                  <option value="all" selected>All</option>
                  <option value="bulk">Bulk</option>
                  <option value="single_cell">Single cell</option>
                </select>
              </div>
            </div>
            <div class="row g-4">
              <div class="col-12 col-lg-6">
                <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                  <h4 class="h5 mb-3">Classification Type</h4>
                  <div class="chart-wrap">
                    <canvas id="classificationChart"></canvas>
                  </div>
                </div>
              </div>
              <div class="col-12 col-lg-6">
                <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                  <h4 class="h5 mb-3">Top 10 Tissue</h4>
                  <div class="chart-wrap">
                    <canvas id="tissueTopChart"></canvas>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <section class="mb-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
              <h3 class="h3 fw-bold mb-0" style="color: #2c3e50;">3. Perturbed Gene Statistics</h3>
              <div class="d-flex align-items-center gap-2">
                <label class="small text-secondary" for="section3Mode">Mode</label>
                <select id="section3Mode" class="form-select form-select-sm flat-select" style="width: 160px;">
                  <option value="all" selected>All</option>
                  <option value="bulk">Bulk</option>
                  <option value="single_cell">Single cell</option>
                </select>
              </div>
            </div>

            <div class="row g-4">
              <div class="col-12" id="allModuleWrap">
                <div class="bg-white border rounded-4 shadow-sm p-4 h-100">

                  <div class="row g-4 mb-4">
                    <div class="col-12 col-md-6">
                      <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
              <div class="small text-secondary mb-2">Perturbation datasets (All)</div>
                        <div class="display-6 fw-bold text-primary-emphasis lh-1"><?php echo number_format((int)($targetSummary['all_records'] ?? 0)); ?></div>
                      </div>
                    </div>
                    <div class="col-12 col-md-6">
                      <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
              <div class="small text-secondary mb-2">Unique perturbed genes (All)</div>
                        <div class="display-6 fw-bold text-primary-emphasis lh-1"><?php echo number_format((int)($targetSummary['all_unique_genes'] ?? 0)); ?></div>
                      </div>
                    </div>
                  </div>

                  <div class="row g-4 mb-4">
                    <div class="col-12 col-lg-6">
                      <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                        <h4 class="h5 mb-3">Assay Type of Datasets (All)</h4>
                        <div class="chart-wrap">
                          <canvas id="allPerturbTypeChart"></canvas>
                        </div>
                      </div>
                    </div>
                    <div class="col-12 col-lg-6">
                      <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                        <h4 class="h5 mb-3">Single vs. Multi-Gene Perturbations (All)</h4>
                        <div class="chart-wrap">
                          <canvas id="allSingleMultiChart"></canvas>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="bg-white border rounded-4 shadow-sm p-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                      <h4 class="h5 mb-0">Top Perturbed Gene (All)</h4>
                      <div class="d-flex align-items-center gap-2">
                        <label class="small text-secondary" for="allTopGeneLimit">Show</label>
                        <select id="allTopGeneLimit" class="form-select form-select-sm flat-select" style="width: 120px;">
                          <option value="10" selected>Top 10</option>
                          <option value="20">Top 20</option>
                          <option value="50">Top 50</option>
                        </select>
                      </div>
                    </div>
                    <div class="chart-wrap" style="height: 360px;">
                      <canvas id="allTopGeneChart"></canvas>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-12 d-none" id="bulkModuleWrap">
                <div class="bg-white border rounded-4 shadow-sm p-4 h-100">

                  <div class="row g-4 mb-4">
                    <div class="col-12 col-md-6">
                      <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                        <div class="small text-secondary mb-2">Perturbation datasets (Bulk)</div>
                        <div class="display-6 fw-bold text-primary-emphasis lh-1"><?php echo number_format((int)($targetSummary['bulk_records'] ?? 0)); ?></div>
                      </div>
                    </div>
                    <div class="col-12 col-md-6">
                      <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                        <div class="small text-secondary mb-2">Unique perturbed genes (Bulk)</div>
                        <div class="display-6 fw-bold text-primary-emphasis lh-1"><?php echo number_format((int)($targetSummary['bulk_unique_genes'] ?? 0)); ?></div>
                      </div>
                    </div>
                  </div>

                  <div class="row g-4 mb-4">
                    <div class="col-12 col-lg-6">
                      <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                        <h4 class="h5 mb-3">Assay Type of Datasets (Bulk)</h4>
                        <div class="chart-wrap">
                          <canvas id="bulkPerturbTypeChart"></canvas>
                        </div>
                      </div>
                    </div>
                    <div class="col-12 col-lg-6">
                      <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                        <h4 class="h5 mb-3">Single vs. Multi-Gene Perturbations (Bulk)</h4>
                        <div class="chart-wrap">
                          <canvas id="bulkSingleMultiChart"></canvas>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="bg-white border rounded-4 shadow-sm p-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                      <h4 class="h5 mb-0">Top Perturbed Gene (Bulk)</h4>
                      <div class="d-flex align-items-center gap-2">
                        <label class="small text-secondary" for="topGeneLimit">Show</label>
                        <select id="topGeneLimit" class="form-select form-select-sm flat-select" style="width: 120px;">
                          <option value="10" selected>Top 10</option>
                          <option value="20">Top 20</option>
                          <option value="50">Top 50</option>
                        </select>
                      </div>
                    </div>
                    <div class="chart-wrap" style="height: 360px;">
                      <canvas id="topGeneChart"></canvas>
                    </div>
                  </div>
                </div>
              </div>

              <div class="col-12 d-none" id="singleCellModuleWrap">
                <div class="bg-white border rounded-4 shadow-sm p-4 h-100">

                  <div class="row g-4 mb-4">
                    <div class="col-12 col-md-6">
                      <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                        <div class="small text-secondary mb-2">Perturbation datasets (Single cell)</div>
                        <div class="display-6 fw-bold text-primary-emphasis lh-1"><?php echo number_format((int)($targetSummary['sc_records'] ?? 0)); ?></div>
                      </div>
                    </div>
                    <div class="col-12 col-md-6">
                      <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                        <div class="small text-secondary mb-2">Unique perturbed genes (Single cell)</div>
                        <div class="display-6 fw-bold text-primary-emphasis lh-1"><?php echo number_format((int)($targetSummary['sc_unique_genes'] ?? 0)); ?></div>
                      </div>
                    </div>
                  </div>

                  <div class="row g-4 mb-4">
                    <div class="col-12 col-lg-6">
                      <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                        <h4 class="h5 mb-3">Assay Type of Datasets (Single cell)</h4>
                        <div class="chart-wrap">
                          <canvas id="scPerturbTypeChart"></canvas>
                        </div>
                      </div>
                    </div>
                    <div class="col-12 col-lg-6">
                      <div class="bg-white border rounded-4 shadow-sm p-4 h-100">
                        <h4 class="h5 mb-3">Single vs. Multi-Gene Perturbations (Single cell)</h4>
                        <div class="chart-wrap">
                          <canvas id="scSingleMultiChart"></canvas>
                        </div>
                      </div>
                    </div>
                  </div>

                  <h4 class="h5 mb-2">Mixscape-predicted Classification</h4>
                  <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered align-middle mb-0">
                      <thead class="table-primary">
                        <tr>
                          <th>Species</th>
                          <th>Effective (Perturbed)</th>
                          <th>Ineffective (Non-perturbed)</th>
                          <th>Control</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr>
                          <td>Human</td>
                          <td><?php echo number_format((int)($scMixscapeSummary['Human']['effective'] ?? 0)); ?></td>
                          <td><?php echo number_format((int)($scMixscapeSummary['Human']['ineffective'] ?? 0)); ?></td>
                          <td><?php echo number_format((int)($scMixscapeSummary['Human']['control'] ?? 0)); ?></td>
                        </tr>
                        <tr>
                          <td>Mouse</td>
                          <td><?php echo number_format((int)($scMixscapeSummary['Mouse']['effective'] ?? 0)); ?></td>
                          <td><?php echo number_format((int)($scMixscapeSummary['Mouse']['ineffective'] ?? 0)); ?></td>
                          <td><?php echo number_format((int)($scMixscapeSummary['Mouse']['control'] ?? 0)); ?></td>
                        </tr>
                      </tbody>
                    </table>
                  </div>

                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <h4 class="h5 mb-0">Dataset Count by Perturbed Ratio</h4>
                    <div class="d-flex align-items-center gap-2">
                      <label class="small text-secondary" for="scRatioBinWidth">Bin Width</label>
                      <select id="scRatioBinWidth" class="form-select form-select-sm flat-select" style="width: 120px;">
                        <option value="0.05">0.05</option>
                        <option value="0.1" selected>0.1</option>
                        <option value="0.2">0.2</option>
                        <option value="0.25">0.25</option>
                      </select>
                    </div>
                  </div>
                  <div class="chart-wrap">
                    <canvas id="scRatioChart"></canvas>
                  </div>

                  <div class="bg-white border rounded-4 shadow-sm p-4 mt-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                      <h4 class="h5 mb-0">Top Perturbed Gene (Single cell)</h4>
                      <div class="d-flex align-items-center gap-2">
                        <label class="small text-secondary" for="scTopGeneLimit">Show</label>
                        <select id="scTopGeneLimit" class="form-select form-select-sm flat-select" style="width: 120px;">
                          <option value="10" selected>Top 10</option>
                          <option value="20">Top 20</option>
                          <option value="50">Top 50</option>
                        </select>
                      </div>
                    </div>
                    <div class="chart-wrap" style="height: 360px;">
                      <canvas id="scTopGeneChart"></canvas>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

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
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
  <script>
    const section1Labels = <?php echo json_encode($section1Labels, JSON_UNESCAPED_UNICODE); ?>;
    const section1BulkSampleData = <?php echo json_encode($section1BulkSampleData, JSON_UNESCAPED_UNICODE); ?>;
    const section1ScSampleData = <?php echo json_encode($section1ScSampleData, JSON_UNESCAPED_UNICODE); ?>;
    const section1ScCellData = <?php echo json_encode($section1ScCellData, JSON_UNESCAPED_UNICODE); ?>;

    const section2ChartData = <?php echo json_encode($section2ChartData, JSON_UNESCAPED_UNICODE); ?>;

    const bulkPerturbTypeLabels = <?php echo json_encode($bulkPerturbTypeLabels, JSON_UNESCAPED_UNICODE); ?>;
    const bulkPerturbTypeData = <?php echo json_encode($bulkPerturbTypeData, JSON_UNESCAPED_UNICODE); ?>;
    const allPerturbTypeLabels = <?php echo json_encode($allPerturbTypeLabels, JSON_UNESCAPED_UNICODE); ?>;
    const allPerturbTypeData = <?php echo json_encode($allPerturbTypeData, JSON_UNESCAPED_UNICODE); ?>;
    const scPerturbTypeLabels = <?php echo json_encode($scPerturbTypeLabels, JSON_UNESCAPED_UNICODE); ?>;
    const scPerturbTypeData = <?php echo json_encode($scPerturbTypeData, JSON_UNESCAPED_UNICODE); ?>;

    const allSingleMultiLabels = <?php echo json_encode($allSingleMultiLabels, JSON_UNESCAPED_UNICODE); ?>;
    const allSingleMultiData = <?php echo json_encode($allSingleMultiData, JSON_UNESCAPED_UNICODE); ?>;
    const bulkSingleMultiLabels = <?php echo json_encode($bulkSingleMultiLabels, JSON_UNESCAPED_UNICODE); ?>;
    const bulkSingleMultiData = <?php echo json_encode($bulkSingleMultiData, JSON_UNESCAPED_UNICODE); ?>;
    const scSingleMultiLabels = <?php echo json_encode($scSingleMultiLabels, JSON_UNESCAPED_UNICODE); ?>;
    const scSingleMultiData = <?php echo json_encode($scSingleMultiData, JSON_UNESCAPED_UNICODE); ?>;

    const allTopGeneLabels = <?php echo json_encode($allTopGeneLabels, JSON_UNESCAPED_UNICODE); ?>;
    const allTopGeneData = <?php echo json_encode($allTopGeneData, JSON_UNESCAPED_UNICODE); ?>;
    const topGeneLabels = <?php echo json_encode($topGeneLabels, JSON_UNESCAPED_UNICODE); ?>;
    const topGeneData = <?php echo json_encode($topGeneData, JSON_UNESCAPED_UNICODE); ?>;
    const scTopGeneLabels = <?php echo json_encode($scTopGeneLabels, JSON_UNESCAPED_UNICODE); ?>;
    const scTopGeneData = <?php echo json_encode($scTopGeneData, JSON_UNESCAPED_UNICODE); ?>;
    const scPerturbRatioValues = <?php echo json_encode($scPerturbRatioValues, JSON_UNESCAPED_UNICODE); ?>;

    const palette = ['#4e79a7', '#59a14f', '#f28e2b', '#e15759', '#76b7b2', '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ab', '#6f4e7c', '#2f5597'];
    const speciesPrefixMap = { Human: 'HSSC', Mouse: 'MMSC' };
    const bulkSpeciesPrefixMap = { Human: 'HSBK', Mouse: 'MMBK' };

    const enhanceFlatSelects = () => {
      const selects = document.querySelectorAll('select.flat-select');
      selects.forEach((selectEl) => {
        if (selectEl.dataset.flatBound === '1') return;
        selectEl.dataset.flatBound = '1';
        selectEl.classList.add('flat-select-hidden');

        const group = document.createElement('div');
        group.className = 'flat-option-group';
        group.setAttribute('role', 'group');
        group.setAttribute('aria-label', selectEl.id || 'option-group');

        const syncActive = () => {
          const current = String(selectEl.value ?? '');
          group.querySelectorAll('.flat-option-btn').forEach((btn) => {
            btn.classList.toggle('is-active', btn.dataset.value === current);
          });
        };

        Array.from(selectEl.options).forEach((opt) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'flat-option-btn';
          btn.dataset.value = String(opt.value ?? '');
          btn.textContent = String(opt.textContent ?? '').trim();
          btn.addEventListener('click', () => {
            if (selectEl.value === btn.dataset.value) return;
            selectEl.value = btn.dataset.value;
            selectEl.dispatchEvent(new Event('change', { bubbles: true }));
          });
          group.appendChild(btn);
        });

        selectEl.insertAdjacentElement('afterend', group);
        selectEl.addEventListener('change', syncActive);
        syncActive();
      });
    };

    enhanceFlatSelects();

    const buildBrowseUrl = (pairs) => {
      const params = new URLSearchParams();
      (pairs || []).forEach(([k, v]) => {
        if (v === undefined || v === null) return;
        const s = String(v).trim();
        if (!s || s === 'Unknown') return;
        params.append(k, s);
      });
      return `browse.php?${params.toString()}`;
    };

    const gotoBrowseWithFilterState = (filters) => {
      try {
        sessionStorage.setItem('browse_prefill_filters', JSON.stringify({
          source: 'statistics',
          ts: Date.now(),
          filters: filters || {}
        }));
      } catch (e) {}
      window.location.href = 'browse.php';
    };

    const gotoBrowse = (pairs) => {
      window.location.href = buildBrowseUrl(pairs);
    };

    const applyModeToPairs = (pairs, mode) => {
      if (mode === 'bulk') {
        pairs.push(
          ['dataset_prefix[]', 'HSBK'],
          ['dataset_prefix[]', 'MMBK'],
          ['meta_assay_scale[]', 'Bulk']
        );
      } else if (mode === 'single_cell') {
        pairs.push(
          ['dataset_prefix[]', 'HSSC'],
          ['dataset_prefix[]', 'MMSC'],
          ['meta_assay_scale[]', 'Single cell']
        );
      }
      return pairs;
    };

    const buildBarChartOptions = (yLabel) => ({
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { precision: 0 },
          title: { display: true, text: yLabel }
        },
        x: {
          grid: { display: false }
        }
      }
    });

    const buildPiePlugins = () => ({
      legend: {
        position: 'bottom',
        labels: { boxWidth: 14, font: { size: 11 } }
      },
      tooltip: {
        callbacks: {
          label: (ctx) => {
            const raw = Number(ctx.raw);
            const count = Number.isFinite(raw) ? raw.toLocaleString() : (ctx.raw ?? 0);
            return `Count: ${count}`;
          }
        }
      }
    });

    const buildRatioHistogram = (values, binWidth) => {
      const width = Math.max(0.01, Math.min(1, Number(binWidth) || 0.1));
      const bins = Math.max(1, Math.ceil(1 / width));
      const labels = [];
      const counts = new Array(bins).fill(0);

      for (let i = 0; i < bins; i += 1) {
        const start = i * width;
        const end = Math.min(1, start + width);
        labels.push(`${start.toFixed(2)}-${end.toFixed(2)}`);
      }

      values.forEach((raw) => {
        const value = Math.max(0, Math.min(1, Number(raw) || 0));
        const index = Math.min(bins - 1, Math.floor(value / width));
        counts[index] += 1;
      });

      return { labels, counts };
    };

    const bulkSampleChart = new Chart(document.getElementById('bulkSampleChart'), {
      type: 'bar',
      data: {
        labels: section1Labels,
        datasets: [{
          label: 'Number of Samples',
          data: section1BulkSampleData,
          backgroundColor: ['#4e79a7', '#59a14f'],
          borderRadius: 6,
          maxBarThickness: 46
        }]
      },
      options: {
        ...buildBarChartOptions('Number of Samples'),
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const label = bulkSampleChart.data.labels[elements[0].index];
          const species = label === 'Human' ? 'Homo sapiens' : (label === 'Mouse' ? 'Mus musculus' : '');
          if (!species) return;
          gotoBrowseWithFilterState({
            meta_biosample_species: [species],
            meta_assay_scale: ['Bulk']
          });
        }
      }
    });

    const singleCellChart = new Chart(document.getElementById('singleCellChart'), {
      type: 'bar',
      data: {
        labels: section1Labels,
        datasets: [{
          label: 'Number of Cells',
          data: section1ScCellData,
          backgroundColor: ['#4e79a7', '#59a14f'],
          borderRadius: 6,
          maxBarThickness: 46
        }]
      },
      options: {
        ...buildBarChartOptions('Number of Cells'),
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const label = singleCellChart.data.labels[elements[0].index];
          const species = label === 'Human' ? 'Homo sapiens' : (label === 'Mouse' ? 'Mus musculus' : '');
          if (!species) return;
          gotoBrowseWithFilterState({
            meta_biosample_species: [species],
            meta_assay_scale: ['Single cell']
          });
        }
      }
    });

    const singleCellMetricSelect = document.getElementById('singleCellMetric');
    if (singleCellMetricSelect) {
      singleCellMetricSelect.addEventListener('change', (event) => {
        const metric = event.target.value;
        const isSampleMetric = metric === 'sample';
        singleCellChart.data.datasets[0].data = isSampleMetric ? section1ScSampleData : section1ScCellData;
        singleCellChart.data.datasets[0].label = isSampleMetric ? 'Number of Samples' : 'Number of Cells';
        singleCellChart.options.scales.y.title.text = isSampleMetric ? 'Number of Samples' : 'Number of Cells';
        singleCellChart.update();
      });
    }

    const classificationChart = new Chart(document.getElementById('classificationChart'), {
      type: 'pie',
      data: {
        labels: section2ChartData.all.classification.labels,
        datasets: [{ data: section2ChartData.all.classification.data, backgroundColor: palette }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: buildPiePlugins(),
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const label = classificationChart.data.labels[elements[0].index];
          const mode = section2ModeSelect ? section2ModeSelect.value : 'all';
          const pairs = [['meta_biosample_classification_type[]', label]];
          gotoBrowse(applyModeToPairs(pairs, mode));
        }
      }
    });

    const tissueTopChart = new Chart(document.getElementById('tissueTopChart'), {
      type: 'bar',
      data: {
        labels: section2ChartData.all.tissue.labels,
        datasets: [{
          label: 'Count',
          data: section2ChartData.all.tissue.data,
          backgroundColor: '#59a14f',
          borderRadius: 6,
          maxBarThickness: 40
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0 },
            title: { display: true, text: 'Number of Datasets' }
          },
          x: { ticks: { autoSkip: false, maxRotation: 35, minRotation: 20, font: { size: 10 } }, grid: { display: false } }
        },
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const label = tissueTopChart.data.labels[elements[0].index];
          const mode = section2ModeSelect ? section2ModeSelect.value : 'all';
          const pairs = [['meta_biosample_tissue_name[]', label]];
          gotoBrowse(applyModeToPairs(pairs, mode));
        }
      }
    });

    const section2ModeSelect = document.getElementById('section2Mode');
    if (section2ModeSelect) {
      section2ModeSelect.addEventListener('change', (event) => {
        const mode = event.target.value;
        const payload = section2ChartData[mode] || section2ChartData.all;

        classificationChart.data.labels = payload.classification.labels;
        classificationChart.data.datasets[0].data = payload.classification.data;
        classificationChart.update();

        tissueTopChart.data.labels = payload.tissue.labels;
        tissueTopChart.data.datasets[0].data = payload.tissue.data;
        tissueTopChart.update();
      });
    }

    const section3ModeSelect = document.getElementById('section3Mode');
    const allModuleWrap = document.getElementById('allModuleWrap');
    const bulkModuleWrap = document.getElementById('bulkModuleWrap');
    const singleCellModuleWrap = document.getElementById('singleCellModuleWrap');
    if (section3ModeSelect && allModuleWrap && bulkModuleWrap && singleCellModuleWrap) {
      section3ModeSelect.addEventListener('change', (event) => {
        const mode = event.target.value;
        const showAll = mode === 'all';
        const showBulk = mode === 'bulk';
        const showSingle = mode === 'single_cell';
        allModuleWrap.classList.toggle('d-none', !showAll);
        bulkModuleWrap.classList.toggle('d-none', !showBulk);
        singleCellModuleWrap.classList.toggle('d-none', !showSingle);
      });
    }

    const initialScRatio = buildRatioHistogram(scPerturbRatioValues, 0.1);
    const scRatioChart = new Chart(document.getElementById('scRatioChart'), {
      type: 'bar',
      data: {
        labels: initialScRatio.labels,
        datasets: [{
          label: 'Dataset Count',
          data: initialScRatio.counts,
          backgroundColor: '#6f4e7c',
          borderRadius: 6,
          maxBarThickness: 32
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Number of Datasets' } },
          x: { ticks: { maxRotation: 35, minRotation: 20 }, title: { display: true, text: 'Perturbed Ratio Interval' }, grid: { display: false } }
        }
      }
    });

    const scRatioBinWidthSelect = document.getElementById('scRatioBinWidth');
    if (scRatioBinWidthSelect) {
      scRatioBinWidthSelect.addEventListener('change', (event) => {
        const width = Number(event.target.value) || 0.1;
        const next = buildRatioHistogram(scPerturbRatioValues, width);
        scRatioChart.data.labels = next.labels;
        scRatioChart.data.datasets[0].data = next.counts;
        scRatioChart.update();
      });
    }

    const allPerturbTypeChart = new Chart(document.getElementById('allPerturbTypeChart'), {
      type: 'doughnut',
      data: {
        labels: allPerturbTypeLabels,
        datasets: [{ data: allPerturbTypeData, backgroundColor: palette.slice(0, allPerturbTypeLabels.length) }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: buildPiePlugins(),
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const label = allPerturbTypeChart.data.labels[elements[0].index] || '';
          if (!label) return;
          gotoBrowse([
            ['meta_assay_type[]', label]
          ]);
        }
      }
    });

    const allSingleMultiChart = new Chart(document.getElementById('allSingleMultiChart'), {
      type: 'pie',
      data: {
        labels: allSingleMultiLabels,
        datasets: [{ data: allSingleMultiData, backgroundColor: ['#4e79a7', '#e15759'] }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: buildPiePlugins(),
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const label = allSingleMultiChart.data.labels[elements[0].index] || '';
          const perturb = label.toLowerCase().includes('multi') ? 'multigenes' : 'singlegene';
          gotoBrowse([
            ['meta_perturb_nums[]', perturb]
          ]);
        }
      }
    });

    const allTopGeneChart = new Chart(document.getElementById('allTopGeneChart'), {
      type: 'bar',
      data: {
        labels: allTopGeneLabels.slice(0, 10),
        datasets: [{
          label: 'Count',
          data: allTopGeneData.slice(0, 10),
          backgroundColor: '#6f4e7c',
          borderRadius: 6,
          maxBarThickness: 38
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0 },
            title: { display: true, text: 'Number of Datasets' }
          },
          x: { ticks: { autoSkip: false, maxRotation: 35, minRotation: 20, font: { size: 10 } }, grid: { display: false } }
        },
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const index = elements[0].index;
          const gene = allTopGeneChart.data.labels[index];
          if (!gene || gene === 'Unknown') return;
          gotoBrowse([
            ['meta_assay_target_gene_name[]', gene]
          ]);
        }
      }
    });

    const allTopGeneLimitSelect = document.getElementById('allTopGeneLimit');
    if (allTopGeneLimitSelect) {
      allTopGeneLimitSelect.addEventListener('change', (event) => {
        const limit = Number(event.target.value) || 10;
        allTopGeneChart.data.labels = allTopGeneLabels.slice(0, limit);
        allTopGeneChart.data.datasets[0].data = allTopGeneData.slice(0, limit);
        allTopGeneChart.update();
      });
    }

    const bulkPerturbTypeChart = new Chart(document.getElementById('bulkPerturbTypeChart'), {
      type: 'doughnut',
      data: {
        labels: bulkPerturbTypeLabels,
        datasets: [{ data: bulkPerturbTypeData, backgroundColor: palette.slice(0, bulkPerturbTypeLabels.length) }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: buildPiePlugins(),
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const label = bulkPerturbTypeChart.data.labels[elements[0].index] || '';
          let assay = '';
          if (label.includes('MIX')) assay = 'MIX';
          else if (label.includes('OE')) assay = 'OE';
          else if (label.includes('KO/KD')) assay = 'KD';
          if (!assay) return;
          gotoBrowse([
            ['dataset_prefix[]', 'HSBK'],
            ['dataset_prefix[]', 'MMBK'],
            ['meta_assay_scale[]', 'Bulk'],
            ['meta_assay_type[]', assay]
          ]);
        }
      }
    });

    const bulkSingleMultiChart = new Chart(document.getElementById('bulkSingleMultiChart'), {
      type: 'pie',
      data: {
        labels: bulkSingleMultiLabels,
        datasets: [{ data: bulkSingleMultiData, backgroundColor: ['#4e79a7', '#e15759'] }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: buildPiePlugins(),
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const label = bulkSingleMultiChart.data.labels[elements[0].index] || '';
          const perturb = label.toLowerCase().includes('multi') ? 'multigenes' : 'singlegene';
          gotoBrowse([
            ['dataset_prefix[]', 'HSBK'],
            ['dataset_prefix[]', 'MMBK'],
            ['meta_assay_scale[]', 'Bulk'],
            ['meta_perturb_nums[]', perturb]
          ]);
        }
      }
    });

    const scPerturbTypeChart = new Chart(document.getElementById('scPerturbTypeChart'), {
      type: 'doughnut',
      data: {
        labels: scPerturbTypeLabels,
        datasets: [{ data: scPerturbTypeData, backgroundColor: palette.slice(0, scPerturbTypeLabels.length) }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: buildPiePlugins(),
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const label = scPerturbTypeChart.data.labels[elements[0].index] || '';
          let assay = '';
          if (label.includes('CRISPRa + CRISPRi')) assay = 'CRISPRa + CRISPRi';
          else if (label.includes('CRISPRi')) assay = 'CRISPRi';
          else if (label.includes('CRISPRa')) assay = 'CRISPRa';
          else if (label.toUpperCase().includes('CRISPR-KO') || label.toUpperCase().includes('CRISPRKO')) assay = 'CRISPR-KO';
          else if (label.includes('OE')) assay = 'OE';
          if (!assay) return;
          gotoBrowse([
            ['dataset_prefix[]', 'HSSC'],
            ['dataset_prefix[]', 'MMSC'],
            ['meta_assay_scale[]', 'Single cell'],
            ['meta_assay_type[]', assay]
          ]);
        }
      }
    });

    const scSingleMultiChart = new Chart(document.getElementById('scSingleMultiChart'), {
      type: 'pie',
      data: {
        labels: scSingleMultiLabels,
        datasets: [{ data: scSingleMultiData, backgroundColor: ['#4e79a7', '#e15759'] }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: buildPiePlugins(),
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const label = scSingleMultiChart.data.labels[elements[0].index] || '';
          const perturb = label.toLowerCase().includes('multi') ? 'multigenes' : 'singlegene';
          gotoBrowse([
            ['dataset_prefix[]', 'HSSC'],
            ['dataset_prefix[]', 'MMSC'],
            ['meta_assay_scale[]', 'Single cell'],
            ['meta_perturb_nums[]', perturb]
          ]);
        }
      }
    });

    const scTopGeneChart = new Chart(document.getElementById('scTopGeneChart'), {
      type: 'bar',
      data: {
        labels: scTopGeneLabels.slice(0, 10),
        datasets: [{
          label: 'Count',
          data: scTopGeneData.slice(0, 10),
          backgroundColor: '#6f4e7c',
          borderRadius: 6,
          maxBarThickness: 38
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0 },
            title: { display: true, text: 'Number of Datasets' }
          },
          x: { ticks: { autoSkip: false, maxRotation: 35, minRotation: 20, font: { size: 10 } }, grid: { display: false } }
        },
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) return;
          const index = elements[0].index;
          const gene = scTopGeneChart.data.labels[index];
          if (!gene || gene === 'Unknown') return;
          gotoBrowse([
            ['dataset_prefix[]', 'HSSC'],
            ['dataset_prefix[]', 'MMSC'],
            ['meta_assay_scale[]', 'Single cell'],
            ['meta_assay_target_gene_name[]', gene]
          ]);
        }
      }
    });

    const scTopGeneLimitSelect = document.getElementById('scTopGeneLimit');
    if (scTopGeneLimitSelect) {
      scTopGeneLimitSelect.addEventListener('change', (event) => {
        const limit = Number(event.target.value) || 10;
        scTopGeneChart.data.labels = scTopGeneLabels.slice(0, limit);
        scTopGeneChart.data.datasets[0].data = scTopGeneData.slice(0, limit);
        scTopGeneChart.update();
      });
    }

    const topGeneChart = new Chart(document.getElementById('topGeneChart'), {
      type: 'bar',
      data: {
        labels: topGeneLabels.slice(0, 10),
        datasets: [{
          label: 'Count',
          data: topGeneData.slice(0, 10),
          backgroundColor: '#6f4e7c',
          borderRadius: 6,
          maxBarThickness: 38
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: {
            beginAtZero: true,
            ticks: { precision: 0 },
            title: { display: true, text: 'Number of Datasets' }
          },
          x: { ticks: { autoSkip: false, maxRotation: 35, minRotation: 20, font: { size: 10 } }, grid: { display: false } }
        },
        onHover: (event, elements) => {
          event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        },
        onClick: (event, elements) => {
          if (!elements.length) {
            return;
          }
          const index = elements[0].index;
          const gene = topGeneChart.data.labels[index];
          if (!gene || gene === 'Unknown') {
            return;
          }
          const params = new URLSearchParams();
          params.append('dataset_prefix[]', 'HSBK');
          params.append('dataset_prefix[]', 'MMBK');
          params.append('meta_assay_scale[]', 'Bulk');
          params.append('meta_assay_target_gene_name[]', gene);
          window.location.href = `browse.php?${params.toString()}`;
        }
      }
    });

    const topGeneLimitSelect = document.getElementById('topGeneLimit');
    if (topGeneLimitSelect) {
      topGeneLimitSelect.addEventListener('change', (event) => {
        const limit = Number(event.target.value) || 10;
        topGeneChart.data.labels = topGeneLabels.slice(0, limit);
        topGeneChart.data.datasets[0].data = topGeneData.slice(0, limit);
        topGeneChart.update();
      });
    }
  </script>
<script>document.getElementById('year').textContent = new Date().getFullYear();</script>
</body>
</html>








