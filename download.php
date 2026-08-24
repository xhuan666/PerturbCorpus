<?php
require_once __DIR__ . '/config.php';

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function build_download_url(string $subDir, string $fileName): string
{
    $base = rtrim((string)DOWNLOAD_BASE_URL, '/');
    $sub = trim($subDir, '/');
    $file = ltrim($fileName, '/');
    return $base . '/' . $sub . '/' . $file;
}

function load_h5ad_md5_map(string $txtFile): array
{
    if (!is_file($txtFile)) return [];
    $lines = @file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    $out = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        if (!preg_match('/^([a-fA-F0-9]{32})\s+(.+\.h5ad)$/', $line, $m)) continue;
        $md5 = strtolower($m[1]);
        $file = trim($m[2]);
        $datasetId = strtoupper((string)explode('.', $file, 2)[0]);
        if ($datasetId === '') continue;
        $out[$datasetId] = ['md5' => $md5, 'file' => $file];
    }
    return $out;
}

function load_md5_maps_from_db(string $dbFile): array
{
    $out = [
        'sc_gene' => [],
        'sc_crispr' => [],
        'bulk' => [],   // key: "bulk_h5|Human" / "kallisto_index|Mouse"
    ];
    if (!is_file($dbFile)) {
        return $out;
    }
    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $sqlSc = "
          SELECT category, dataset_id, file_name, md5
          FROM file_md5_index
          WHERE category IN ('sc_gene_h5ad', 'sc_crispr_h5ad')
        ";
        foreach (($pdo->query($sqlSc)->fetchAll() ?: []) as $r) {
            $cat = trim((string)($r['category'] ?? ''));
            $datasetId = strtoupper(trim((string)($r['dataset_id'] ?? '')));
            $file = trim((string)($r['file_name'] ?? ''));
            $md5 = strtolower(trim((string)($r['md5'] ?? '')));
            if ($datasetId === '' && strpos($file, '.') !== false) {
                $datasetId = strtoupper(trim((string)explode('.', $file, 2)[0]));
            }
            if ($datasetId === '' || $file === '' || $md5 === '') continue;
            if ($cat === 'sc_gene_h5ad') {
                $out['sc_gene'][$datasetId] = ['md5' => $md5, 'file' => $file];
            } elseif ($cat === 'sc_crispr_h5ad') {
                $out['sc_crispr'][$datasetId] = ['md5' => $md5, 'file' => $file];
            }
        }

        $sqlBulk = "
          SELECT category, file_name, md5
          FROM file_md5_index
          WHERE category IN ('bulk_h5', 'kallisto_index')
        ";
        foreach (($pdo->query($sqlBulk)->fetchAll() ?: []) as $r) {
            $cat = trim((string)($r['category'] ?? ''));
            $file = trim((string)($r['file_name'] ?? ''));
            $md5 = strtolower(trim((string)($r['md5'] ?? '')));
            if ($cat === '' || $file === '' || $md5 === '') continue;
            $species = 'Unknown';
            $lf = strtolower($file);
            if (strpos($lf, 'human') !== false) $species = 'Human';
            if (strpos($lf, 'mouse') !== false) $species = 'Mouse';
            $out['bulk'][$cat . '|' . $species] = ['md5' => $md5, 'file' => $file];
        }
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

function parse_dataset_ids_csv(string $raw): array
{
    $ids = [];
    foreach (explode(',', $raw) as $x) {
        $v = strtoupper(trim((string)$x));
        if ($v === '') continue;
        // Keep id token conservative/safe.
        if (!preg_match('/^[A-Z0-9_-]+$/', $v)) continue;
        $ids[$v] = true;
    }
    return array_keys($ids);
}

$downloads = [
    ['species' => 'Human', 'file' => 'human.h5', 'size' => '13.0G', 'md5' => 'TBD'],
    ['species' => 'Mouse', 'file' => 'mouse.h5', 'size' => '11.0G', 'md5' => 'TBD'],
];

$indexDownloads = [
    ['species' => 'Human', 'file' => 'human_107_kallisto.idx', 'size' => '3.2G', 'md5' => 'TBD'],
    ['species' => 'Mouse', 'file' => 'mouse_107_kallisto.idx', 'size' => '2.4G', 'md5' => 'TBD'],
];

$speciesOrder = ['Human', 'Mouse'];
$groupedDownloads = [];
$groupedIndexDownloads = [];
$md5Db = load_md5_maps_from_db(__DIR__ . '/sqlite3/download_md5.db');
$md5GeneMap = $md5Db['sc_gene'] ?? [];
$md5CrisprMap = $md5Db['sc_crispr'] ?? [];
if (!$md5GeneMap) {
    $md5GeneMap = load_h5ad_md5_map(__DIR__ . '/030.md5sum/gene_expression_h5ad.md5sum.txt');
}
if (!$md5CrisprMap) {
    $md5CrisprMap = load_h5ad_md5_map(__DIR__ . '/030.md5sum/crispr_guide_capture_h5ad.md5sum.txt');
}

if (isset($_REQUEST['ajax']) && (string)$_REQUEST['ajax'] === 'export_links') {
    $linkType = strtolower(trim((string)($_REQUEST['link_type'] ?? '')));
    $datasetRaw = (string)($_REQUEST['dataset_ids'] ?? '');
    $datasetIds = parse_dataset_ids_csv($datasetRaw);

    if (!in_array($linkType, ['crispr', 'exp'], true)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Invalid link_type. Use crispr or exp.";
        exit;
    }
    if (count($datasetIds) === 0) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "No Dataset ID provided.";
        exit;
    }

    $lines = [];
    foreach ($datasetIds as $datasetId) {
        if ($linkType === 'crispr') {
            $file = trim((string)($md5CrisprMap[$datasetId]['file'] ?? ''));
            if ($file === '') continue;
            $lines[] = build_download_url('download/020.sgrna_h5ad', $file);
        } else {
            $file = trim((string)($md5GeneMap[$datasetId]['file'] ?? ''));
            if ($file === '') continue;
            $lines[] = build_download_url('download/010.gene_expression_h5ad', $file);
        }
    }

    $lines = array_values(array_unique($lines));
    sort($lines, SORT_STRING);
    $body = implode("\n", $lines);
    $fname = $linkType === 'crispr' ? 'single_cell_crispr_links.txt' : 'single_cell_expression_links.txt';

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    echo $body;
    exit;
}

foreach ($downloads as &$item) {
    $key = 'bulk_h5|' . ($item['species'] ?? 'Unknown');
    if (isset($md5Db['bulk'][$key]['md5'])) {
        $item['md5'] = $md5Db['bulk'][$key]['md5'];
    }
}
unset($item);
foreach ($indexDownloads as &$item) {
    $key = 'kallisto_index|' . ($item['species'] ?? 'Unknown');
    if (isset($md5Db['bulk'][$key]['md5'])) {
        $item['md5'] = $md5Db['bulk'][$key]['md5'];
    }
}
unset($item);

foreach ($downloads as $item) {
    $groupedDownloads[$item['species']][] = $item;
}
foreach ($indexDownloads as $item) {
    $groupedIndexDownloads[$item['species']][] = $item;
}

$scUiRows = [];
try {
    $pdo = new PDO('sqlite:' . DB_META_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $sql = "
      SELECT dataset_id, meta_biosample_species, meta_biosample_tissue_name, meta_biosample_description, meta_assay_target_gene_name
      FROM dataset_meta
      WHERE (dataset_id LIKE 'HSSC%' OR dataset_id LIKE 'MMSC%')
      ORDER BY dataset_id ASC
    ";
    $rows = $pdo->query($sql)->fetchAll() ?: [];
    foreach ($rows as $r) {
            $datasetId = strtoupper(trim((string)($r['dataset_id'] ?? '')));
        if ($datasetId === '') continue;
        $species = trim((string)($r['meta_biosample_species'] ?? 'Unknown'));
        $tissue = trim((string)($r['meta_biosample_tissue_name'] ?? 'Unknown'));
        $cellline = trim((string)($r['meta_biosample_description'] ?? 'Unknown'));
        $rawGene = trim((string)($r['meta_assay_target_gene_name'] ?? ''));
        $geneTokens = [];
        if ($rawGene !== '') {
            $parts = preg_split('/[,\|;]+/', $rawGene) ?: [];
            foreach ($parts as $p) {
                $g = trim((string)$p);
                if ($g !== '') $geneTokens[$g] = true;
            }
        }
        if (!$geneTokens) $geneTokens['Unknown'] = true;
        $geneKeys = array_keys($geneTokens);
        sort($geneKeys, SORT_STRING);
        $scUiRows[] = [
            'dataset_id' => $datasetId,
            'species' => $species,
            'tissue' => $tissue,
            'cellline' => $cellline,
            'gene_tokens' => $geneKeys,
            'gene_md5' => $md5GeneMap[$datasetId]['md5'] ?? '',
            'gene_file' => $md5GeneMap[$datasetId]['file'] ?? ($datasetId . '.gene_expression.h5ad'),
            'crispr_md5' => $md5CrisprMap[$datasetId]['md5'] ?? '',
            'crispr_file' => $md5CrisprMap[$datasetId]['file'] ?? ($datasetId . '.crispr_guide_capture.h5ad'),
        ];
    }
} catch (Throwable $e) {
    $scUiRows = [];
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
  <title>PerturbCorpus Download</title>
  <link href="static/lib/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet" />
  <link href="static/style.css?v=<?php echo (int)@filemtime(__DIR__ . '/static/style.css'); ?>" rel="stylesheet" />
  <style>
    .detail-shell { max-width: 1500px; }
    .detail-panel { background: rgba(255,255,255,.95); border: 1px solid rgba(204,210,220,.95); box-shadow: 0 12px 32px rgba(27,43,70,.06); }

    .dl-title { font-size: 2rem; font-weight: 800; color: #0f172a; margin-bottom: .35rem; }
    .dl-sub { font-size: 1.05rem; font-weight: 600; color: #334155; margin-bottom: 1rem; }
    .dl-section-title { font-size: 1.75rem; font-weight: 800; color: #111827; margin-bottom: .8rem; }
    .dl-module { border: 1px solid #d7dee9; background: #fff; border-radius: .9rem; padding: 1rem 1.1rem; box-shadow: 0 4px 14px rgba(15,23,42,.05); margin-bottom: 1rem; }
    .download-definitions { border: 1px solid #d7dee9; background: #ffffff; border-radius: .8rem; padding: .95rem 1rem; margin-bottom: 1rem; }
    .download-definitions .title { font-weight: 800; color: #1f334c; margin-bottom: .45rem; font-size: 1.1rem; }
    .download-definitions ul { margin: 0; padding-left: 1rem; color: #455a74; line-height: 1.45; }
    .download-definitions li { margin-bottom: .2rem; }
    .inline-tag { display: inline-block; background: #e9eef5; border: 1px solid #d6deea; color: #24364d; border-radius: 999px; padding: .05rem .55rem; font-weight: 700; margin: 0 .12rem; }

    .species-col-title { font-size: 1.4rem; font-weight: 800; margin-bottom: .55rem; color: #111827; text-align: center; }
    .species-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .55rem; }
    .species-panel {
      border: 1px solid #d7dee9;
      background: #f9fbff;
      border-radius: .9rem;
      padding: .75rem;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
      height: 100%;
    }

    .download-card {
      border: 1px solid #d7dee9;
      background: #ffffff;
      transition: transform .2s ease, box-shadow .2s ease;
    }
    .download-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(15,23,42,.08); }
    .download-card .card-kicker { font-size: .9rem; letter-spacing: .04em; text-transform: uppercase; color: #455a74; font-weight: 800; margin-bottom: .4rem; }
    .download-card .card-note { font-size: .9rem; color: #5f7189; margin-bottom: .65rem; }
    .download-card code { color: #111827; }
    .download-meta { color: #5a6c84; font-size: .95rem; margin-bottom: .3rem; }
    .dl-meta-label { font-size: 1rem; font-weight: 800; color: #334155; margin-bottom: .2rem; }

    .sc-filter-shell { border: 1px solid #d7dee9; border-radius: .8rem; background: #fff; padding: .9rem; }
    .sc-top-row { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; margin-bottom: .8rem; }
    .sc-top-row input[type="text"] { max-width: 500px; min-width: 520px; flex: 1 1 620px; }
    .sc-instruction { font-size: .9rem; margin-bottom: .45rem; color: #334155; }
    .sc-dataset-count { display: flex; align-items: center; gap: .45rem; margin-bottom: .8rem; }
    .sc-species-wrap { border: 1px solid #dbe2ea; border-radius: .65rem; background: #fff; padding: .6rem .7rem; margin-bottom: .8rem; }
    .sc-species-head { display: flex; align-items: center; justify-content: space-between; gap: .4rem; margin-bottom: .45rem; }
    .sc-species-title { font-size: .9rem; font-weight: 700; color: #0f172a; }
    .sc-species-options { display: flex; gap: 1rem; flex-wrap: wrap; }
    .sc-groups { display: flex; align-items: stretch; gap: .75rem; }
    .sc-and-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 44px;
      padding: .2rem .5rem;
      border-radius: 999px;
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      color: #334155;
      font-size: .74rem;
      font-weight: 700;
      letter-spacing: .02em;
      align-self: center;
    }
    .sc-group { flex: 1 1 0; min-width: 0; border: 1px solid #dbe2ea; border-radius: .65rem; background: #fff; min-height: 270px; display: flex; flex-direction: column; }
    .sc-group-head { padding: .55rem .65rem; border-bottom: 1px solid #e5eaf1; display: flex; align-items: center; justify-content: space-between; gap: .4rem; }
    .sc-group-title { font-size: .94rem; font-weight: 800; color: #0f172a; }
    .sc-group-count { font-size: .75rem; color: #64748b; }
    .sc-group-tools { display: flex; gap: .35rem; flex-wrap: wrap; padding: .45rem .65rem; border-bottom: 1px solid #f1f5f9; }
    .sc-group-tools .btn { --bs-btn-padding-y: .1rem; --bs-btn-padding-x: .45rem; --bs-btn-font-size: .74rem; }
    .sc-group-tools input { height: 30px; font-size: .8rem; }
    .sc-option-list { padding: .45rem .65rem; max-height: 220px; overflow: auto; display: grid; gap: .28rem; }
    .sc-option-item { display: flex; align-items: center; gap: .45rem; font-size: .84rem; color: #334155; }
    .sc-option-item input { margin: 0; }
    .sc-option-item.sc-option-disabled { color: #94a3b8; }
    .sc-option-item.sc-option-disabled input { cursor: not-allowed; }
    .sc-option-item.sc-option-disabled .sc-option-unavail { color: #64748b; font-size: .75rem; }
    .sc-option-empty { color: #94a3b8; font-size: .82rem; }
    .sc-results { border: 1px solid #d7dee9; border-radius: .7rem; background: #fff; margin-top: .8rem; }
    .sc-results-head { padding: .55rem .7rem; border-bottom: 1px solid #e2e8f0; font-weight: 700; color: #334155; font-size: .92rem; display: flex; align-items: center; justify-content: space-between; gap: .5rem; flex-wrap: wrap; }
    .sc-export-tools { display: flex; gap: .4rem; flex-wrap: wrap; }
    .sc-results-body { max-height: 300px; overflow: auto; }
    .sc-result-row { display: grid; grid-template-columns: 1.05fr .85fr .85fr 1.15fr 1.15fr 1.05fr; gap: .45rem; padding: .5rem .7rem; border-bottom: 1px solid #f1f5f9; align-items: center; font-size: .88rem; }
    .sc-result-row:last-child { border-bottom: none; }
    .muted-mini { color: #64748b; font-size: .8rem; }
    .sc-head-row { background: #f8fafc; font-weight: 700; color: #334155; }
    .sc-results-body .sc-head-row {
      position: sticky;
      top: 0;
      z-index: 2;
      background: #f8fafc;
      border-bottom: 1px solid #dbe2ea;
    }
    .dl-btn-group { display: flex; gap: .35rem; flex-wrap: wrap; }
    .md5-text { word-break: break-all; }
    @media (max-width: 992px) {
      .sc-groups { flex-direction: column; }
      .sc-and-badge { width: fit-content; margin: .1rem auto; }
      .sc-result-row { grid-template-columns: 1fr; }
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
  <div class="container-fluid py-2 pt-2 detail-shell" id="download-top">
    <div class="row justify-content-center">
      <div class="col-12 bg-white border rounded-3 shadow-sm p-3 p-md-4 p-lg-5 detail-panel">
        <section class="dl-module">
          <h1 class="dl-title">Download</h1>
    <div class="dl-sub">Bulk and single cell genetic perturbation resources for human and mouse.</div>
        </section>

        <section class="download-definitions dl-module">
          <div class="title">Definitions</div>
          <ul>
            <li><span class="inline-tag">Bulk</span> datasets include bulk perturbation expression data with meta information (.h5) and corresponding index files (.idx), provided separately for human and mouse.</li>
      <li><span class="inline-tag">Single Cell</span> datasets are indexed by Dataset ID (474 available); download per dataset (.h5ad) via Dataset ID search or cell type / Perturbed Gene filters.</li>
            <li><span class="inline-tag">HSSC</span> / <span class="inline-tag">MMSC</span> indicate single cell Dataset ID for Human / Mouse.</li>
            <li><span class="inline-tag">HSBK</span> / <span class="inline-tag">MMBK</span> indicate bulk Dataset ID for Human / Mouse.</li>
          </ul>
        </section>

        <section class="mb-4 dl-module">
          <h2 class="dl-section-title">Bulk</h2>
          <div class="row g-3">
            <?php foreach ($speciesOrder as $species): ?>
              <?php
                $speciesDownloads = $groupedDownloads[$species] ?? [];
                $speciesBulkDownloads = $speciesDownloads;
                $speciesIndexFiles = $groupedIndexDownloads[$species] ?? [];
              ?>
              <div class="col-12 col-lg-6">
                <div class="species-panel">
                  <div class="species-col-title"><?php echo h($species); ?></div>
                  <div class="species-grid">
                    <?php foreach ($speciesBulkDownloads as $item): ?>
                      <section class="p-2 h-100 rounded-4 download-card">
                        <div class="card-kicker">Expression Matrix</div>
                        <div class="card-note">Bulk Perturbation expression data for downstream analysis.</div>
                        <div class="mb-2"><div class="dl-meta-label">H5 File</div><code><?php echo h($item['file']); ?></code></div>
                        <div class="mb-2"><div class="dl-meta-label">File Size</div><code><?php echo h($item['size']); ?></code></div>
                        <div class="mb-2"><div class="dl-meta-label">MD5</div><code><?php echo h($item['md5']); ?></code></div>
                        <?php $bulkUrl = build_download_url('download/040.bulk/010.h5', (string)$item['file']); ?>
                        <a class="btn btn-outline-primary btn-sm" href="<?php echo h($bulkUrl); ?>" target="_blank" rel="noopener">Download</a>
                      </section>
                    <?php endforeach; ?>

                    <?php foreach ($speciesIndexFiles as $indexItem): ?>
                      <section class="p-2 h-100 rounded-4 download-card">
                        <div class="card-kicker">Bulk Reference</div>
                        <div class="card-note">Corresponding index file for quantification pipeline.</div>
                        <div class="mb-2"><div class="dl-meta-label">Index File</div><code><?php echo h($indexItem['file']); ?></code></div>
                        <div class="mb-2"><div class="dl-meta-label">File Size</div><code><?php echo h($indexItem['size']); ?></code></div>
                        <div class="mb-2"><div class="dl-meta-label">MD5</div><code><?php echo h($indexItem['md5']); ?></code></div>
                        <?php $idxUrl = build_download_url('download/040.bulk/020.kallito_index', (string)$indexItem['file']); ?>
                        <a class="btn btn-outline-primary btn-sm" href="<?php echo h($idxUrl); ?>" target="_blank" rel="noopener">Download</a>
                      </section>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="dl-module">
  <h2 class="dl-section-title">Single Cell</h2>
          <div class="sc-filter-shell">
            <div class="sc-top-row">
              <label for="scSearchInput" class="fw-semibold mb-0">Search Dataset ID: <span class="text-muted" title="Multiple entries can be entered as comma-separated values."></span></label>
              <input type="text" id="scSearchInput" class="form-control form-control-sm" placeholder="e.g. HSSC000001, MMSC000123 (comma-separated, optional)" />
              <button type="button" id="scResetBtn" class="btn btn-outline-secondary btn-sm">Reset</button>
            </div>
            <div class="mb-2 muted-small fw-semibold filter-logic-highlight"><strong>Select species first. Options within the same box are matched by OR, while different filter boxes are combined by AND. Gray options are unavailable.</strong></div>
            <div class="sc-dataset-count">
              <span class="muted-small">Selected/Matched Dataset ID:</span>
              <span class="fw-bold text-dark" id="scDatasetCountText">0</span>
            </div>

            <div class="sc-species-wrap">
              <div class="sc-species-head">
                <div class="sc-species-title">Species (Required, Select One or More)</div>
                <div class="sc-group-count" id="scSpeciesCount">0/0</div>
              </div>
              <div class="sc-species-options" id="scSpeciesList"></div>
            </div>

            <div class="sc-groups">
              <div class="sc-group">
                <div class="sc-group-head">
                  <div class="sc-group-title">Tissue</div>
                  <div class="sc-group-count" id="scTissueCount">0/0</div>
                </div>
                <div class="sc-group-tools">
          <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="tissue" data-action="all">Select All</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="tissue" data-action="clear">Unselect All</button>
                  <input type="text" class="form-control form-control-sm" id="scSearchTissue" placeholder="Search tissue..." />
                </div>
                <div class="sc-option-list" id="scTissueList"></div>
              </div>

              <span class="sc-and-badge">AND</span>

              <div class="sc-group">
                <div class="sc-group-head">
                  <div class="sc-group-title">Cell Type</div>
                  <div class="sc-group-count" id="scCelllineCount">0/0</div>
                </div>
                <div class="sc-group-tools">
          <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="cellline" data-action="all">Select All</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="cellline" data-action="clear">Unselect All</button>
                  <input type="text" class="form-control form-control-sm" id="scSearchCellline" placeholder="Search cell type..." />
                </div>
                <div class="sc-option-list" id="scCelllineList"></div>
              </div>

              <span class="sc-and-badge">AND</span>

              <div class="sc-group">
                <div class="sc-group-head">
                  <div class="sc-group-title">Perturbed Gene</div>
                  <div class="sc-group-count" id="scGeneCount">0/0</div>
                </div>
                <div class="sc-group-tools">
          <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="gene" data-action="all">Select All</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-group="gene" data-action="clear">Unselect All</button>
                  <input type="text" class="form-control form-control-sm" id="scSearchGene" placeholder="Search target gene..." />
                </div>
                <div class="sc-option-list" id="scGeneList"></div>
              </div>
            </div>
          </div>
          <div class="sc-results">
            <div class="sc-results-head">
      <div>Matched Single Cell Sequencing datasets: <span id="scMatchCount">0</span></div>
              <div class="sc-export-tools">
                <button type="button" class="btn btn-outline-primary btn-sm" id="scExportCrisprTxtBtn">Export CRISPR links (.txt)</button>
                <button type="button" class="btn btn-outline-primary btn-sm" id="scExportExpTxtBtn">Export Expression links (.txt)</button>
              </div>
            </div>
            <div class="sc-results-body" id="scResultsBody"></div>
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
<script>
  const downloadBaseUrl = <?php echo json_encode((string)DOWNLOAD_BASE_URL, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
  const scRows = <?php echo json_encode($scUiRows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;

  const scSearchInput = document.getElementById('scSearchInput');
  const scResetBtn = document.getElementById('scResetBtn');
  const scExportCrisprTxtBtn = document.getElementById('scExportCrisprTxtBtn');
  const scExportExpTxtBtn = document.getElementById('scExportExpTxtBtn');
  const scResultsBody = document.getElementById('scResultsBody');
  const scMatchCount = document.getElementById('scMatchCount');
  const scDatasetCountText = document.getElementById('scDatasetCountText');
  const scSpeciesListEl = document.getElementById('scSpeciesList');
  const scSpeciesCount = document.getElementById('scSpeciesCount');

  const scTissueListEl = document.getElementById('scTissueList');
  const scCelllineListEl = document.getElementById('scCelllineList');
  const scGeneListEl = document.getElementById('scGeneList');
  const scTissueCount = document.getElementById('scTissueCount');
  const scCelllineCount = document.getElementById('scCelllineCount');
  const scGeneCount = document.getElementById('scGeneCount');

  const scSearchTissue = document.getElementById('scSearchTissue');
  const scSearchCellline = document.getElementById('scSearchCellline');
  const scSearchGene = document.getElementById('scSearchGene');

  const selected = {
    species: new Set(),
    tissue: new Set(),
    cellline: new Set(),
    gene: new Set()
  };
  const OPTION_BATCH_SIZE = 40;
  const allSpecies = [];
  const allOptions = { tissue: [], cellline: [], gene: [] };
  const availableOptions = { tissue: [], cellline: [], gene: [] };
  const optionRenderState = {
    tissue: { filtered: [], rendered: 0 },
    cellline: { filtered: [], rendered: 0 },
    gene: { filtered: [], rendered: 0 }
  };

  function escapeHtml(v) {
    return String(v ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function joinUrl(base, subDir, fileName) {
    const b = String(base || '').replace(/\/+$/, '');
    const s = String(subDir || '').replace(/^\/+|\/+$/g, '');
    const f = String(fileName || '').replace(/^\/+/, '');
    return `${b}/${s}/${f}`;
  }

  function normalizeRows(rows) {
    return (rows || []).map((r) => ({
      dataset_id: String(r.dataset_id || ''),
      species: String(r.species || 'Unknown'),
      tissue: String(r.tissue || 'Unknown'),
      cellline: String(r.cellline || 'Unknown'),
      gene_tokens: Array.isArray(r.gene_tokens) ? r.gene_tokens.map((g) => String(g || 'Unknown')) : ['Unknown'],
      crispr_md5: String(r.crispr_md5 || ''),
      gene_md5: String(r.gene_md5 || ''),
      crispr_file: String(r.crispr_file || ''),
      gene_file: String(r.gene_file || '')
    }));
  }

  const rowsNorm = normalizeRows(scRows);
  const rowsBySpecies = new Map();
  rowsNorm.forEach((r) => {
    const sp = String(r.species || 'Unknown');
    if (!rowsBySpecies.has(sp)) rowsBySpecies.set(sp, []);
    rowsBySpecies.get(sp).push(r);
  });
  rowsNorm.forEach((r) => allSpecies.push(r.species || 'Unknown'));

  function getAllSpeciesOptions() {
    return sortedValues(new Set(allSpecies));
  }

  function sortedValues(setObj) {
    return Array.from(setObj).sort((a, b) => a.localeCompare(b));
  }

  function uniqueValues(rows, key) {
    const s = new Set();
    rows.forEach((r) => s.add(String(r[key] || 'Unknown')));
    return sortedValues(s);
  }

  function uniqueGenes(rows) {
    const s = new Set();
    rows.forEach((r) => (r.gene_tokens || []).forEach((g) => s.add(String(g || 'Unknown'))));
    return sortedValues(s);
  }

  function hasSpeciesSelection() {
    return selected.species.size > 0;
  }

  function candidateRowsBySpecies() {
    if (!hasSpeciesSelection()) return [];
    const out = [];
    selected.species.forEach((sp) => {
      const chunk = rowsBySpecies.get(String(sp)) || [];
      for (let i = 0; i < chunk.length; i += 1) out.push(chunk[i]);
    });
    return out;
  }

  function rowPassesDatasetSearch(r) {
    const qRaw = (scSearchInput?.value || '').trim().toUpperCase();
    const qTokens = qRaw ? qRaw.split(',').map((x) => x.trim()).filter((x) => x !== '') : [];
    if (!qTokens.length) return true;
    const ds = String(r.dataset_id || '').toUpperCase();
    return qTokens.some((tok) => ds.includes(tok));
  }

  function filteredRows(excludeGroup = null) {
    if (!hasSpeciesSelection()) return [];
    return candidateRowsBySpecies().filter((r) => {
      if (!rowPassesDatasetSearch(r)) return false;
      if (excludeGroup !== 'tissue' && selected.tissue.size && !selected.tissue.has(r.tissue)) return false;
      if (excludeGroup !== 'cellline' && selected.cellline.size && !selected.cellline.has(r.cellline)) return false;
      if (excludeGroup !== 'gene' && selected.gene.size) {
        const hit = (r.gene_tokens || []).some((g) => selected.gene.has(g));
        if (!hit) return false;
      }
      return true;
    });
  }

  function rowsForGroupAll(group) {
    if (!hasSpeciesSelection()) return [];
    return candidateRowsBySpecies().filter((r) => {
      if (!rowPassesDatasetSearch(r)) return false;
      return true;
    });
  }

  function allValuesForGroup(group) {
    const rows = rowsForGroupAll(group);
    if (group === 'tissue') return uniqueValues(rows, 'tissue');
    if (group === 'cellline') return uniqueValues(rows, 'cellline');
    return uniqueGenes(rows);
  }

  function eligibleValuesForGroup(group) {
    if (group === 'tissue') return allValuesForGroup('tissue');
    if (group === 'cellline') {
      if (selected.tissue.size === 0) return [];
      return uniqueValues(
        candidateRowsBySpecies().filter((r) => {
          if (!rowPassesDatasetSearch(r)) return false;
          if (selected.tissue.size && !selected.tissue.has(r.tissue)) return false;
          return true;
        }),
        'cellline'
      );
    }
    if (selected.cellline.size === 0) return [];
    return uniqueGenes(
      candidateRowsBySpecies().filter((r) => {
        if (!rowPassesDatasetSearch(r)) return false;
        if (selected.tissue.size && !selected.tissue.has(r.tissue)) return false;
        if (selected.cellline.size && !selected.cellline.has(r.cellline)) return false;
        return true;
      })
    );
  }

  function hasExplicitEmptySelection() {
    if (!hasSpeciesSelection()) return false;
    if (selected.tissue.size === 0) return false;
    return ['tissue', 'cellline', 'gene'].some((g) => (selected[g] || new Set()).size === 0);
  }

  function renderSpeciesOptions() {
    const options = getAllSpeciesOptions();
    scSpeciesCount.textContent = `${selected.species.size}/${options.length}`;
    scSpeciesListEl.innerHTML = options.map((v) => {
      const checked = selected.species.has(v) ? 'checked' : '';
      return `<label class="form-check form-check-inline mb-1"><input class="form-check-input" type="checkbox" name="scSpecies" value="${escapeHtml(v)}" ${checked}><span class="form-check-label">${escapeHtml(v)}</span></label>`;
    }).join('');
  }

  function getOptionLabel(group) {
    if (group === 'tissue') return 'tissue';
    if (group === 'cellline') return 'cell type';
    return 'perturbed gene';
  }

  function renderOptions(group, container, valuesAll, valuesEligible, searchText, reset = true) {
    if (!hasSpeciesSelection()) {
      container.innerHTML = '<div class="sc-option-empty">Select species first.</div>';
      return;
    }
    const q = String(searchText || '').trim().toLowerCase();
    const availableSet = new Set(valuesEligible || []);
    const selectedVals = Array.from(selected[group] || []);
    const selectedEligible = selectedVals.filter((v) => availableSet.has(v));
    const selectedUnavailable = selectedVals.filter((v) => !availableSet.has(v));
    const eligibleUnselected = (valuesEligible || []).filter((v) => !selected[group].has(v));
    const unavailableUnselected = (valuesAll || []).filter((v) => !availableSet.has(v) && !selected[group].has(v));
    const orderedRaw = selectedEligible.concat(selectedUnavailable).concat(eligibleUnselected).concat(unavailableUnselected);
    const seen = new Set();
    const orderedUnique = orderedRaw.filter((v) => {
      const k = String(v);
      if (seen.has(k)) return false;
      seen.add(k);
      return true;
    });
    const ordered = q ? orderedUnique.filter((v) => String(v).toLowerCase().includes(q)) : orderedUnique;

    if (reset) {
      optionRenderState[group].filtered = ordered;
      optionRenderState[group].rendered = 0;
    }

    const state = optionRenderState[group];
    if (!state.filtered.length) {
      container.innerHTML = '<div class="sc-option-empty">No options</div>';
      return;
    }

    const start = state.rendered;
    const end = Math.min(start + OPTION_BATCH_SIZE, state.filtered.length);
    const chunk = state.filtered.slice(start, end);
    state.rendered = end;

    const html = chunk.map((v) => {
      const checked = selected[group].has(v) ? 'checked' : '';
      const unavailable = !availableSet.has(v);
      const cls = unavailable ? 'sc-option-item sc-option-disabled' : 'sc-option-item';
      const suffix = unavailable ? ' <span class="sc-option-unavail">(Unavailable)</span>' : '';
      return `<label class="${cls}"><input type="checkbox" data-group="${group}" data-value="${escapeHtml(v)}" ${checked} ${unavailable ? 'disabled' : ''} /> <span>${escapeHtml(v)}${suffix}</span></label>`;
    }).join('');

    if (start === 0) {
      container.innerHTML = html;
    } else {
      container.insertAdjacentHTML('beforeend', html);
    }

    const remaining = state.filtered.length - state.rendered;
    const oldHint = container.querySelector('.sc-option-hint');
    if (oldHint) oldHint.remove();
    const hint = document.createElement('div');
    hint.className = 'sc-option-empty sc-option-hint';
    if (remaining > 0) {
      hint.textContent = `Showing ${state.rendered}/${state.filtered.length} ${getOptionLabel(group)} options. Scroll to load more (${remaining} left).`;
    } else {
      hint.textContent = `Showing all ${state.filtered.length} ${getOptionLabel(group)} options.`;
    }
    container.appendChild(hint);
  }

  function loadMoreOptions(group) {
    if (!optionRenderState[group] || !optionRenderState[group].filtered.length) return;
    if (optionRenderState[group].rendered >= optionRenderState[group].filtered.length) return;
    const listEl = group === 'tissue' ? scTissueListEl : (group === 'cellline' ? scCelllineListEl : scGeneListEl);
    if (!listEl) return;
    renderOptions(group, listEl, allOptions[group], availableOptions[group], group === 'tissue' ? (scSearchTissue?.value || '') : (group === 'cellline' ? (scSearchCellline?.value || '') : (scSearchGene?.value || '')), false);
  }

  function rowsByCurrentSelections() {
    if (!hasSpeciesSelection()) return [];
    if (selected.tissue.size === 0) return [];
    if (hasExplicitEmptySelection()) return [];
    return filteredRows(null);
  }

  function currentFilteredDatasetIds() {
    const rows = rowsByCurrentSelections();
    const uniq = new Set();
    rows.forEach((r) => {
      const ds = String(r.dataset_id || '').trim().toUpperCase();
      if (ds) uniq.add(ds);
    });
    return Array.from(uniq);
  }

  function submitLinkExport(linkType) {
    const ids = currentFilteredDatasetIds();
    if (!ids.length) {
      window.alert('No matched Dataset ID under current filters.');
      return;
    }
    const form = document.createElement('form');
    form.method = 'post';
    form.action = window.location.pathname.split('/').pop() || 'download.php';
    form.target = '_blank';

    const add = (name, value) => {
      const inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = name;
      inp.value = value;
      form.appendChild(inp);
    };
    add('ajax', 'export_links');
    add('link_type', linkType);
    add('dataset_ids', ids.join(','));
    document.body.appendChild(form);
    form.submit();
    form.remove();
  }

  function renderOptionPanelsOnly() {
    renderOptions('tissue', scTissueListEl, allOptions.tissue, availableOptions.tissue, scSearchTissue?.value || '', true);
    renderOptions('cellline', scCelllineListEl, allOptions.cellline, availableOptions.cellline, scSearchCellline?.value || '', true);
    renderOptions('gene', scGeneListEl, allOptions.gene, availableOptions.gene, scSearchGene?.value || '', true);
  }

  function renderScRows(rows) {
    scMatchCount.textContent = String(rows.length);
    if (scDatasetCountText) {
      scDatasetCountText.textContent = String(rows.length);
    }
    if (!rows.length) {
      scResultsBody.innerHTML = hasSpeciesSelection()
        ? '<div class="alert alert-danger m-3 mb-0">Matched 0 Dataset ID. Please select at least one option in each filter group.</div>'
        : '<div class="p-3 muted-mini">Select species first.</div>';
      return;
    }
    const head = `
      <div class="sc-result-row sc-head-row">
        <div>Dataset ID</div>
        <div>Tissue</div>
        <div>Cell Type</div>
        <div>CRISPR MD5</div>
        <div>GeneExp MD5</div>
        <div>Download</div>
      </div>
    `;
    const body = rows.slice(0, 500).map((r) => `
      <div class="sc-result-row">
        <div><strong>${escapeHtml(r.dataset_id || '')}</strong></div>
        <div>${escapeHtml(r.tissue || 'Unknown')}</div>
        <div>${escapeHtml(r.cellline || 'Unknown')}</div>
        <div class="md5-text">${(r.crispr_md5 || '').trim() ? escapeHtml(r.crispr_md5) : '<span class="muted-mini">N/A</span>'}</div>
        <div class="md5-text">${(r.gene_md5 || '').trim() ? escapeHtml(r.gene_md5) : '<span class="muted-mini">N/A</span>'}</div>
        <div class="dl-btn-group">
          ${((r.crispr_md5 || '').trim()
            ? `<a class="btn btn-outline-primary btn-sm" href="${escapeHtml(joinUrl(downloadBaseUrl, 'download/020.sgrna_h5ad', r.crispr_file || ''))}" target="_blank" rel="noopener" title="${escapeHtml(r.crispr_file || '')}">CRISPR h5ad</a>`
            : `<button class="btn btn-outline-secondary btn-sm" disabled title="${escapeHtml(r.crispr_file || '')}">CRISPR h5ad</button>`)}
          ${((r.gene_md5 || '').trim()
            ? `<a class="btn btn-outline-primary btn-sm" href="${escapeHtml(joinUrl(downloadBaseUrl, 'download/010.gene_expression_h5ad', r.gene_file || ''))}" target="_blank" rel="noopener" title="${escapeHtml(r.gene_file || '')}">GeneExp h5ad</a>`
            : `<button class="btn btn-outline-secondary btn-sm" disabled title="${escapeHtml(r.gene_file || '')}">GeneExp h5ad</button>`)}
        </div>
      </div>
    `).join('');
    scResultsBody.innerHTML = head + body;
  }

  function refreshPanelsAndResults(changedGroup = null) {
    renderSpeciesOptions();

    if (!hasSpeciesSelection()) {
      selected.tissue.clear();
      selected.cellline.clear();
      selected.gene.clear();
      allOptions.tissue = [];
      allOptions.cellline = [];
      allOptions.gene = [];
      availableOptions.tissue = [];
      availableOptions.cellline = [];
      availableOptions.gene = [];
      scTissueCount.textContent = '0/0';
      scCelllineCount.textContent = '0/0';
      scGeneCount.textContent = '0/0';
      renderOptionPanelsOnly();
      renderScRows([]);
      return;
    }
    let startLevel = 1;
    if (changedGroup === 'species') {
      selected.tissue.clear();
      selected.cellline.clear();
      selected.gene.clear();
      startLevel = 1;
    } else if (changedGroup === 'search') {
      startLevel = 1;
    } else if (!changedGroup) {
      startLevel = 1;
    } else if (changedGroup === 'tissue') {
      startLevel = 2;
    } else if (changedGroup === 'cellline') {
      startLevel = 3;
    } else {
      startLevel = 4;
    }

    const groupByLevel = (lv) => (lv === 1 ? 'tissue' : (lv === 2 ? 'cellline' : 'gene'));

    for (let lv = startLevel; lv <= 3; lv += 1) {
      const g = groupByLevel(lv);
      const allVals = allValuesForGroup(g);
      const eligibleVals = eligibleValuesForGroup(g);
      allOptions[g] = allVals;
      availableOptions[g] = eligibleVals;
      const eligibleSet = new Set(eligibleVals);
      const prev = selected[g] || new Set();
      if ((changedGroup === null || changedGroup === 'species') && eligibleVals.length > 0) {
        selected[g] = new Set(eligibleVals);
      } else if (lv > 1 && eligibleVals.length > 0) {
        selected[g] = new Set(eligibleVals);
      } else {
        selected[g] = new Set(Array.from(prev).filter((v) => eligibleSet.has(v)));
      }
    }

    const tissueAvail = availableOptions.tissue || [];
    const celllineAvail = availableOptions.cellline || [];
    const geneAvail = availableOptions.gene || [];

    const selectedOrAllCount = (group, total) => {
      const n = selected[group]?.size || 0;
      return n === 0 ? total : n;
    };
    scTissueCount.textContent = `${selectedOrAllCount('tissue', tissueAvail.length)}/${tissueAvail.length}`;
    scCelllineCount.textContent = `${selectedOrAllCount('cellline', celllineAvail.length)}/${celllineAvail.length}`;
    scGeneCount.textContent = `${selectedOrAllCount('gene', geneAvail.length)}/${geneAvail.length}`;

    renderOptionPanelsOnly();

    renderScRows(rowsByCurrentSelections());
  }

  scSpeciesListEl?.addEventListener('change', (e) => {
    const target = e.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.type !== 'checkbox') return;
    if (target.name !== 'scSpecies') return;
    const next = String(target.value || '');
    if (target.checked) selected.species.add(next);
    else selected.species.delete(next);
    refreshPanelsAndResults('species');
  });

  [scTissueListEl, scCelllineListEl, scGeneListEl].forEach((container) => {
    container?.addEventListener('change', (e) => {
      const target = e.target;
      if (!(target instanceof HTMLInputElement)) return;
      if (target.type !== 'checkbox') return;
      const group = target.getAttribute('data-group');
      const value = target.getAttribute('data-value');
      if (!group || !value || !selected[group]) return;
      if (target.checked) selected[group].add(value);
      else selected[group].delete(value);
      refreshPanelsAndResults(group);
    });
    container?.addEventListener('scroll', () => {
      const list = container;
      if (!list) return;
      const nearBottom = list.scrollTop + list.clientHeight >= list.scrollHeight - 18;
      if (!nearBottom) return;
      if (list === scTissueListEl) loadMoreOptions('tissue');
      if (list === scCelllineListEl) loadMoreOptions('cellline');
      if (list === scGeneListEl) loadMoreOptions('gene');
    });
  });

  document.querySelectorAll('.sc-group-tools button[data-group][data-action]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const group = btn.getAttribute('data-group');
      const action = btn.getAttribute('data-action');
      if (!group || !selected[group]) return;
      if (!hasSpeciesSelection()) return;
      const currentAvail = availableOptions[group] || [];
      if (action === 'all') selected[group] = new Set(currentAvail);
      if (action === 'clear') selected[group].clear();
      const listEl = group === 'tissue' ? scTissueListEl : (group === 'cellline' ? scCelllineListEl : scGeneListEl);
      if (listEl) {
        renderOptions(group, listEl, allOptions[group], availableOptions[group], group === 'tissue' ? (scSearchTissue?.value || '') : (group === 'cellline' ? (scSearchCellline?.value || '') : (scSearchGene?.value || '')), true);
      }
      refreshPanelsAndResults(group);
    });
  });

  function debounce(fn, delay = 120) {
    let timer = null;
    return (...args) => {
      if (timer) clearTimeout(timer);
      timer = setTimeout(() => fn(...args), delay);
    };
  }

  function applyDefaultSelectAll() {
    selected.species = new Set(getAllSpeciesOptions());
    selected.tissue.clear();
    selected.cellline.clear();
    selected.gene.clear();
  }

  [scSearchTissue, scSearchCellline, scSearchGene].forEach((input) => {
    input?.addEventListener('input', debounce(() => renderOptionPanelsOnly(), 120));
  });

  scSearchInput?.addEventListener('input', debounce(() => refreshPanelsAndResults('search'), 120));

  scResetBtn?.addEventListener('click', () => {
    if (scSearchInput) scSearchInput.value = '';
    if (scSearchTissue) scSearchTissue.value = '';
    if (scSearchCellline) scSearchCellline.value = '';
    if (scSearchGene) scSearchGene.value = '';
    applyDefaultSelectAll();
    refreshPanelsAndResults('species');
  });

  scExportCrisprTxtBtn?.addEventListener('click', () => submitLinkExport('crispr'));
  scExportExpTxtBtn?.addEventListener('click', () => submitLinkExport('exp'));

  applyDefaultSelectAll();
  refreshPanelsAndResults('species');
</script>
<script>document.getElementById('year').textContent = new Date().getFullYear();</script>
</body>
</html>





