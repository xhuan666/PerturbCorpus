<?php
require_once __DIR__ . '/config.php';

function cleanup_temp_json_files(): void
{
  $dir = __DIR__ . '/temp';
  if (!is_dir($dir)) {
    return;
  }
  $now = time();
  $expire = 60*60*24;
  $files = glob($dir . '/*.json') ?: [];
  foreach ($files as $file) {
    $mtime = @filemtime($file);
    if ($mtime === false) {
      continue;
    }
    if (($now - (int)$mtime) > $expire) {
      @unlink($file);
    }
  }
}

function render_faq_answer_html(string $text): string
{
  $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
  $withObfEmail = preg_replace_callback('/\{\{email2:([A-Za-z0-9+\/=]+)\}\}/', static function (array $m): string {
    $enc = (string)$m[1];
    return '<span class="obf-email" data-enc="' . htmlspecialchars($enc, ENT_QUOTES, 'UTF-8') . '">[email protected]</span>';
  }, $escaped);
  $emailBase = is_string($withObfEmail) ? $withObfEmail : $escaped;
  $withUrls = preg_replace_callback('/https?:\/\/[^\s<]+/i', static function (array $m): string {
    $full = (string)$m[0];
    $url = rtrim($full, '.,;:!?)');
    $tail = substr($full, strlen($url));
    $safeUrl = filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    $scheme = strtolower((string)parse_url($safeUrl, PHP_URL_SCHEME));
    if ($safeUrl === '' || !in_array($scheme, ['http', 'https'], true)) {
      return htmlspecialchars($full, ENT_QUOTES, 'UTF-8');
    }
    $escUrl = htmlspecialchars($safeUrl, ENT_QUOTES, 'UTF-8');
    return '<a class="faq-inline-link" href="' . $escUrl . '" target="_blank" rel="noopener noreferrer">' . $escUrl . '</a>' . htmlspecialchars($tail, ENT_QUOTES, 'UTF-8');
  }, $emailBase);
  $base = is_string($withUrls) ? $withUrls : $emailBase;

  $linked = preg_replace_callback('/Q(\d+)\.(\d+)/', static function (array $m): string {
    $anchor = 'q' . $m[1] . '-' . $m[2];
    return '<a class="faq-inline-link" href="#' . htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8') . '</a>';
  }, $base);
  $content = is_string($linked) ? $linked : $base;

  $lines = preg_split('/\R/u', $content) ?: [];
  $html = '';
  $listItems = [];

  $flushList = static function () use (&$listItems, &$html): void {
    if (!$listItems) return;
    $html .= '<ul>';
    foreach ($listItems as $item) {
      $html .= '<li>' . $item . '</li>';
    }
    $html .= '</ul>';
    $listItems = [];
  };

  foreach ($lines as $lineRaw) {
    $line = trim((string)$lineRaw);
    if ($line === '') {
      $flushList();
      continue;
    }
    if (preg_match('/^[●•]\s*(.+)$/u', $line, $m)) {
      $listItems[] = trim((string)$m[1]);
      continue;
    }
    if ($listItems) {
      $flushList();
    }
    $html .= '<p>' . $line . '</p>';
  }

  $flushList();

  return $html;
}

// Always clean up stale temp JSON files when FAQ is visited.
try {
  cleanup_temp_json_files();
} catch (Throwable $e) {
  // Keep FAQ page stable even if cleanup fails.
}
?><!doctype html>
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
  <title>PerturbCorpus FAQ</title>
  <link href="static/lib/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet" />
  <link href="static/style.css?ver=<?php echo uniqid(); ?>" rel="stylesheet" />
  <link rel="stylesheet" href="static/lib/katex/0.16.11/katex.min.css" />
  <script defer src="static/lib/katex/0.16.11/katex.min.js"></script>
  <script defer src="static/lib/katex/0.16.11/contrib/auto-render.min.js"></script>
  <style>
    .detail-shell {
      max-width: 1500px;
    }

    .detail-panel {
      background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
      border: 1px solid #dfe7f2;
      box-shadow: 0 14px 34px rgba(27, 43, 70, 0.08);
    }

    .faq-layout {
      display: grid;
      grid-template-columns: 300px minmax(0, 1fr);
      gap: 20px;
      align-items: start;
    }
    .faq-modules {
      position: sticky;
      top: 0;
      align-self: start;
      width: 100%;
      box-sizing: border-box;
      border: 1px solid #d8e2f0;
      border-radius: 14px;
      padding: 8px 14px 14px;
      background: #ffffff;
      box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
      max-height: calc(100vh - 16px);
      overflow: auto;
    }
    .faq-modules-title {
      font-size: 0.85rem;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      color: #64748b;
      margin: 0 0 8px 0;
      padding: 0 10px;
    }
    .faq-modules a {
      display: block;
      padding: 9px 11px;
      margin-bottom: 6px;
      border-radius: 8px;
      text-decoration: none;
      color: #0f172a;
      font-size: 0.95rem;
      border: 1px solid transparent;
      transition: all .15s ease;
    }
    .faq-modules a:hover {
      background: #f0f7ff;
      border-color: #dbeafe;
      color: #1d4ed8;
    }
    .faq-modules a.active {
      background: #e8f2ff;
      border-color: #bfdbfe;
      color: #1d4ed8;
      font-weight: 700;
    }
    .faq-section {
      border: 1px solid #d8e2f0;
      border-radius: 14px;
      padding: 16px 18px;
      background: #fff;
      margin-bottom: 14px;
      scroll-margin-top: 96px;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
    }
    .faq-section h3 {
      margin-bottom: 12px;
      font-size: 2.0rem;
      color: #0f172a;
      font-weight: 1500;
    }
    .faq-item {
      border-top: 1px dashed #d7dee9;
      padding-top: 10px;
      margin-top: 10px;
    }
    .faq-q {
      font-weight: 800;
      color: #111827;
      margin-bottom: 6px;
    }
    .faq-a {
      color: #4b5563;
      margin-bottom: 0;
    }
    .faq-a p {
      margin: 0;
      line-height: 1.8;
      font-size: 0.98rem;
    }
    .faq-a p + p {
      margin-top: 14px;
    }
    .faq-a p:last-child {
      margin-bottom: 0;
    }
    .faq-a ul {
      margin: 16px 0;
      padding-left: 1.2rem;
      list-style: none;
    }
    .faq-a ul:last-child {
      margin-bottom: 0;
    }
    .faq-a li {
      position: relative;
      margin: 10px 0;
      padding-left: 16px;
      line-height: 1.7;
      font-size: 0.98rem;
    }
    .faq-a li:last-child {
      margin-bottom: 0;
    }
    .faq-a li::before {
      content: '';
      position: absolute;
      left: 2px;
      top: 0.72em;
      width: 5px;
      height: 5px;
      border-radius: 999px;
      background: #64748b;
      transform: translateY(-50%);
    }
    .faq-inline-link {
      color: #2563eb;
      font-weight: 700;
      text-decoration: underline;
      text-underline-offset: 2px;
    }
    .faq-inline-link:hover {
      color: #1d4ed8;
    }
    .back-to-top {
      position: fixed;
      left: 18px;
      bottom: 22px;
      width: 42px;
      height: 42px;
      border: 1px solid #bfdbfe;
      border-radius: 999px;
      background: #eff6ff;
      color: #1d4ed8;
      box-shadow: 0 8px 18px rgba(37, 99, 235, 0.18);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      z-index: 1100;
      opacity: 0;
      transform: translateY(6px);
      pointer-events: none;
      transition: opacity .18s ease, transform .18s ease;
    }
    .back-to-top.show {
      opacity: 1;
      transform: translateY(0);
      pointer-events: auto;
    }
    .back-to-top:hover {
      background: #dbeafe;
      border-color: #93c5fd;
    }
    .back-to-top svg {
      width: 16px;
      height: 16px;
    }
    @media (max-width: 992px) {
      .faq-layout {
        grid-template-columns: 1fr;
      }
      .faq-modules {
        position: static;
        top: auto;
        max-height: none;
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
          <?php
          $faqModules = [
            [
              'id' => 'about',
              'title' => '1. About PerturbCorpus',
              'items' => [
                ['q' => 'Q1.1 What is PerturbCorpus?', 'a' => 'PerturbCorpus is an AI-ready genetic perturbation database integrating bulk and single cell RNA-seq data from public repositories. It provides uniformly processed expression matrices, standardized metadata (including unified perturbation gene names), and built-in tools for perturbation similarity and genetic interaction analysis.'],
                ['q' => 'Q1.2 Why does PerturbCorpus use unified processing?', 'a' => 'Existing perturbation databases suffer from scattered data, inconsistent metadata, mixed gene identifiers, and non-uniform pipelines, making them unsuitable for direct AI training. PerturbCorpus applies consistent pipelines and standardizes annotations across all datasets, enabling fair cross-dataset comparison and ready-to-use input for AI models.'],
              ],
            ],
            [
              'id' => 'source',
              'title' => '2. Data Sources',
              'items' => [
                ['q' => 'Q2.1 How is the data in PerturbCorpus collected?', 'a' => 'PerturbCorpus collects genetic perturbation data from publicly available GEO repositories, including both bulk RNA-seq and single cell RNA-seq datasets. The raw sequencing data (FASTQ files) are sourced from perturbation experiments such as CRISPR KO, CRISPRa, CRISPRi, overexpression.'],
                ['q' => 'Q2.2 What meta information is curated for each dataset?', 'a' => 'For each dataset, we systematically curate key meta information, including tissue source, cell type or cell line, perturbation type, perturbed gene(s) with both gene symbol and ENSEMBL ID. All collected FASTQ files are subsequently aligned and quantified using consistent pipelines (see Q3.1) to ensure cross-dataset comparability.'],
              ],
            ],
            [
              'id' => 'processing',
              'title' => '3. Data Processing and Analysis',
              'items' => [
                ['q' => 'Q3.1 What is the pipeline for generating expression count matrices?', 'a' => "For all datasets, we download the corresponding SRA files from GEO repositories (https://www.ncbi.nlm.nih.gov/geo/) and convert them to FASTQ. The processing then differs by data type:\n\n● scRNA-seq: FASTQ files are processed using Cell Ranger (10x Genomics) to generate a gene-level expression count matrix, followed by standard quality control.\n● Bulk RNA-seq: FASTQ files are processed using the ARCHS4 pipeline (kallisto with pseudoalignment) to generate a gene-level expression count matrix with built-in quality control. For datasets already available on ARCHS4, we directly download the precomputed count matrix without rerunning the pipeline."],
                ['q' => 'Q3.2 How is sgRNA assignment performed in scRNA-seq?', 'a' => 'For single cell perturbation data, sgRNAs are assigned to individual cells to determine which gene is perturbed in a given cell. In PerturbCorpus, the assignment is based on two criteria: (1) if sgRNA information is already provided in the original study, we directly use the published assignment results; (2) if the original study does not include sgRNA assignment information, we perform sgRNA assignment using the mixture model implemented in Pertpy.'],
                ['q' => 'Q3.3 How is perturbation effect evaluated in scRNA-seq?', 'a' => 'For single cell perturbation data, Mixscape analysis is used to classify cells into perturbed vs. non-perturbed states, calculate perturbation transcriptionally responsive (the fraction of cells truly affected by the perturbation), and quantify perturbation strength.'],
              ],
            ],
            [
              'id' => 'search',
              'title' => '4. Dataset Search',
              'items' => [
                ['q' => 'Q4.1 How do I search for datasets on the Home Page?', 'a' => 'The Home Page features an intuitive search box that allows users to quickly search for datasets of interest by entering keywords such as Tissue, Biosample Description(cell line or cell type), Perturbation gene. Users can also enter comma-separated values to search for multiple items at once (e.g., Tissue: Liver, Brain). After entering the query and clicking the search button, users are automatically redirected to a detailed search results page.'],
                ['q' => 'Q4.2: How do I browse and filter datasets using the PerturbCorpus Filter?', 'a' => "The PerturbCorpus Filter on the Browse Page allows users to narrow down datasets by applying criteria across four main categories: Sample, Assay, Biosample, and Perturbation.\n\n● Accession filters: Users can search by Dataset ID, GSM Accession, or GSE Accession. Multiple entries can be entered as comma-separated values (e.g., HSSC000001, MMBK000001), making it easy to locate specific datasets of interest.\n● Assay filters: Users can choose between Bulk or Single Cell assay scales, and further refine by assay type—such as knockdown/knockout (KO/KD), overexpression (OE), mixed perturbations (MIX), or specific CRISPR modalities (CRISPR-KO, CRISPRa, CRISPRi, or combined CRISPRa+CRISPRi).\n● Biosample filters: Users enable to specify the species (human or mouse), tissue of origin, classification type (e.g., primary cell, cell line, organoid, ESC), and biosample description (specific cell line or cell type).\n● Perturbation filters: Users can search by whether a single gene or multiple genes were perturbed, and by the specific perturbed gene(s) of interest.\n\nAfter selecting the desired filters from the left-side panel, users can identify the relevant dataset in the right-side panel. Clicking on a Dataset ID (e.g., HSSC000001) directs users to the Dataset Details page, where detailed analysis results—including Perturbation Details, Quality Control, Single cell UMAP Projections, and DEG Analysis—are available."],
              ],
            ],
            [
              'id' => 'correlation',
              'title' => '5. Correlation Explorer',
              'items' => [
                ['q' => 'Q5.1 What are the steps of the Correlation Explorer?', 'a' => "This tool allows users to compare perturbation similarity across different datasets.\n\n● Step 1 (Dataset Selection): Users select the species, tissue, cell type, and perturbation gene(s) of interest. For bulk data, cross-dataset comparison is supported (see Q5.2); for single cell data, comparison is limited to within the same dataset (see Q5.3)\n● Step 2 (Heatmap Generation): Users select the data type and similarity calculation method based on whether they are analyzing bulk or single cell data. After making the selections, clicking the Submit button generates the heatmap."],
                ['q' => 'Q5.2 What data can be compared in "Correlation Explorer - Bulk"?', 'a' => 'The "Correlation Explorer - Bulk" enables cross-dataset comparison of perturbation similarity for the same species, allowing users to flexibly define comparison groups by selecting any combination of tissue, cell type and perturbed gene across different datasets, then performing pairwise similarity calculations to explore how perturbation effects compare across diverse sample conditions.'],
                ['q' => 'Q5.3 What data can compare in "Correlation Explorer - Single cell" ?', 'a' => 'The "Correlation Explorer - Single cell" supports comparison only within the same dataset and does not support cross-dataset analysis; users must first narrow down to a single dataset by applying filters such as species, tissue, and cell type, then compare different perturbed genes within that dataset.'],
                ['q' => 'Q5.4: What data type and similarity metrics are available for bulk datasets?', 'a' => "For bulk datasets, mouse data is available only as the raw expression matrix, while human data can be either the raw expression matrix or embeddings generated by BulkFormer, a transformer-based deep learning model that captures complex gene–gene interactions. When using BulkFormer embeddings, users can select a pooling strategy:\n\n● Max: Takes the maximum value across all embedding dimensions for each sample.\n● Mean: Averages all embedding dimensions to produce the sample representation.\n● Median: Takes the median value across all embedding dimensions for each sample.\n● All: Adds the max, mean, and median embeddings together to obtain the final representation.\nAvailable similarity metrics for bulk data include Cosine similarity, Pearson distance and Spearman distance.For detailed definitions and formulas of these metrics, see Q5.7."],
                ['q' => 'Q5.5: What data type and similarity metrics are available for single cell datasets?', 'a' => "For single cell datasets, the data type can be either the raw expression matrix or embeddings generated by GenePT. GenePT is a gene perturbation embedding model built on large language model representations, providing precomputed gene-level embeddings that capture functional relationships between genes. Users can choose between two embedding:\n\n● text-embedding-ada-002: Embeddings generated using text-embedding-ada-002 model, which produces compact, semantically rich gene representations.\n● text-embedding-3-large: Embeddings generated by the text-embedding-3-large model\nAvailable similarity metrics for single cell data include E-distance, MMD, Cosine similarity, Pearson distance, and Spearman distance.For detailed definitions and formulas of these metrics, see Q5.7."],
                ['q' => 'Q5.6 How is the perturbation effect vector defined for each data type?', 'a' => "To systematically compare genetic perturbation effects across datasets, perturbation similarity is calculated for both bulk and single cell data. For each perturbation experiment, the effect vector is defined as the difference between treatment and control conditions:\n\\[v=\\bar{x}_{\\mathrm{treatment}}-\\bar{x}_{\\mathrm{control}}\\]\n● Bulk: TPM-normalized expression matrices are used, with \\(\\bar{x}_{\\mathrm{treatment}}\\) and \\(\\bar{x}_{\\mathrm{control}}\\) representing mean expression vectors across biological replicates.\n● Single cell: Raw count matrices are log-normalized, highly variable genes are selected, and PCA is performed for dimensionality reduction; the effect vector is then defined in PCA space, where \\(\\bar{x}_{\\mathrm{perturbed}}\\) and \\(\\bar{x}_{\\mathrm{control}}\\) are the centroids of perturbed and control cells, respectively."],
                ['q' => 'Q5.7 How are these similarity metrics defined and calculated?', 'a' => "The following metrics are computed between all pairs of perturbation effect vectors:\n\n● Cosine similarity measures the angular similarity between two perturbation effect vectors. For two effect vectors \\(v_i\\) and \\(v_j\\), it is defined as:\n\\[\\mathrm{cosine\\_similarity}(v_i, v_j)=\\frac{v_i\\cdot v_j}{\\|v_i\\|\\,\\|v_j\\|}\\]\nValues range from -1 to 1, where 1 indicates that the two vectors point in exactly the same direction (identical directional effects), 0 indicates orthogonal (uncorrelated) directions, and -1 indicates opposite directions.\n\n● Pearson distance is defined as \\(1-\\rho\\), where \\(\\rho\\) is the Pearson correlation coefficient between two perturbation effect vectors. Values range from 0 to 2, with 0 indicating perfect positive correlation and 2 indicating perfect negative correlation.\n● Spearman distance is defined as \\(1-\\rho_s\\), where \\(\\rho_s\\) is the Spearman rank correlation coefficient between two perturbation effect vectors. It assesses the strength of any monotonic relationship and is more robust to outliers than Pearson distance.\n● E-distance measures the statistical distance between the distributions of two perturbed cell populations in PCA space. For two cell populations \\(X\\) and \\(Y\\), it is defined as:\n\\[d_E(X,Y)=2\\,\\mathbb{E}\\left\\|X-Y\\right\\|-\\mathbb{E}\\left\\|X-X'\\right\\|-\\mathbb{E}\\left\\|Y-Y'\\right\\|\\]\nwhere \\(X,X'\\) are independent random vectors from the first population and \\(Y,Y'\\) are independent random vectors from the second population. It compares the average distance between the two groups to the average distance within each group. The metric is non-negative and equals zero if and only if the two distributions are identical.\n\n● Maximum Mean Discrepancy (MMD) measures the distance between two distributions by comparing their mean embeddings in a reproducing kernel Hilbert space (RKHS). It is computed using three kernel functions - linear, radial basis function (RBF), and quadratic polynomial - to capture differences at multiple scales.\n\nAll calculations generate distance and similarity matrices for each dataset, enabling downstream clustering and visualization."],
              ],
            ],
            [
              'id' => 'gi',
              'title' => '6. Genetic Interaction Classifier',
              'items' => [
                ['q' => 'Q6.1 What are the steps of the Genetic Interaction Classifier?', 'a' => "This tool classifies genetic interaction subtypes for multi-gene perturbations, including synergy, suppressor, additive, redundant, epistasis, and neomorphic.\n\n● Step 1 (Data Filter): Users select the species, tissue, cell line, cell type, and perturbation gene(s) of interest.\n● Step 2 (Genetic Interaction Results): Four scoring components (Magnitude, Equality of contribution, Model fit, and Similarity) and their corresponding default cutoffs (see Q6.3), both based on GEARS (Nature Biotechnology, 2023), are used to classify genetic interaction subtypes. Users can also adjust the cutoff values to define custom classification criteria. Clicking the \"Render GI Table\" button generates the classification results table (see Q6.4)."],
                ['q' => 'Q6.2: How are genetic interaction scores calculated?', 'a' => "To classify genetic interactions between perturbation pairs, four scoring components are calculated based on the GEARS framework (Nature Biotechnology, 2023). This analysis requires that for a given pair of genes, all three perturbation conditions are available: single perturbation of gene a, single perturbation of gene b, and double perturbation ab (where both genes are perturbed simultaneously). Effect vectors for each perturbation condition were derived as described above.\nFor each eligible perturbation pair (a, b, ab), Theil-Sen regression was performed to fit the model \\(ab=c_a\\cdot a+c_b\\cdot b\\) without intercept, using 10,000 random subsamples of 1,000 genes at a time. The regression coefficients \\(c_a\\) and \\(c_b\\) quantify the contribution of each single perturbation to the combined effect.\nFour metrics were computed from the regression results:\n\n● Magnitude is defined as \\(\\sqrt{c_a^2+c_b^2}\\), representing the overall strength of the interaction.\n● Model fit is calculated as the distance correlation between the predicted combination \\((c_a\\cdot a+c_b\\cdot b)\\) and the observed double perturbation \\(ab\\), measuring how well the additive model explains the observed effect.\n● Similarity is the distance correlation between the concatenated single perturbation vectors \\([a,b]\\) and the double perturbation vector \\(ab\\), assessing the transcriptional similarity between single and double perturbations.\n● Equality of contribution is calculated as the ratio of the smaller to the larger distance correlation between each single perturbation and the double perturbation:\n\\[\\frac{\\min(\\mathrm{dcor}(a,ab),\\mathrm{dcor}(b,ab))}{\\max(\\mathrm{dcor}(a,ab),\\mathrm{dcor}(b,ab))}\\]\nwhere \\(\\mathrm{dcor}\\) denotes distance correlation. This metric evaluates whether both single perturbations contribute equally to the combined effect."],
                ['q' => 'Q6.3 How are Genetic Interaction subtypes classified', 'a' => "Genetic interaction subtypes are classified based on the four scoring components (Magnitude, Model fit, Similarity, and Equality of contribution) using the threshold scheme defined in GEARS (Nature Biotechnology, 2023).\nThe default cutoffs are:\n\n● Additive: 1.00 ≤ Magnitude ≤ 1.15\n● Synergy: Magnitude > 1.15\n● Suppression: Magnitude < 1.00\n● Neomorphic: Model fit < 0.88\n● Redundant: Similarity > 0.85\n● Epistasis: Equality of contribution < 0.28\n\nAdditive, Synergy, and Suppression are mutually exclusive based on the Magnitude score, while Neomorphic, Redundant, and Epistasis are evaluated independently and are not mutually exclusive with each other or with the Magnitude-based classification. These thresholds were estimated by computing the relevant GI score for every perturbation pair known to express a given subtype, then taking the minimum score for lower-bounded conditions and the maximum score for upper-bounded conditions."],
                ['q' => 'Q6.4 What information is included in the Genetic Interaction Table?', 'a' => "The Genetic Interaction Classifier outputs a classification table that includes the following information for each perturbation pair. The Dataset ID column differs by data type: for bulk data, it is a combined identifier formatted as three Dataset ID joined by \"|\" (e.g., HSBK001481|HSBK001482|HSBK001484), representing the datasets for single perturbation of gene a, single perturbation of gene b, and double perturbation of gene a and gene b, respectively; for single cell data, it is a single Dataset ID (e.g., HSSC000156), as all three perturbation conditions are derived from different cell populations within the same dataset.\nThe Perturbed Gene column displays the gene pair formatted as gene a | gene b (e.g., CCM2|ROCK1) for both bulk and single cell data. The classification columns include Magnitude Class which shows Additive, Synergy, or Suppression based on the Magnitude score; Model Fit Class which shows either Neomorphic or Non Neomorphic; Similarity Class which shows either Redundant or Non Redundant; and Equality Class which shows either Epistasis or Non Epistasis. The table also provides metadata columns including assay_type, species, tissue, and cell_type, as well as the raw numeric scores for magnitude, model_fit, similarity, and equality, allowing users to view both the categorical classifications and the underlying scores for each perturbation pair."],
              ],
            ],
            [
              'id' => 'download',
              'title' => '7. Data Download',
              'items' => [
                ['q' => 'Q7.1 What bulk RNA-seq data are available for download?', 'a' => "Both human and mouse bulk data are available. For each species, we provide two files: the Gene Expression Matrix (.h5) and the Bulk Reference index (.idx). In total, 4 files are available for download.\n\n● Gene Expression Matrix: A single H5 file containing the perturbation expression matrix and corresponding metadata. The file has four top-level keys: experiment_meta, gene_meta, matrix, and sample_meta. experiment_meta contains experiment-level metadata such as assay_scale, biosample, species, target_gene, target_type, tissue, and sample_control/treatment. gene_meta includes ensemble_id, gene_name, and gene_type. matrix is the main expression matrix. sample_meta includes external_database, relation_database_id, relation_experiments, and sample_accession.\n● Bulk Reference Index: A kallisto reference index file (e.g., human_107_kallisto.idx) for users who wish to run their own quantification pipeline."],
                ['q' => 'Q7.2 What scRNA-seq data are available for download?', 'a' => "A total of 474 single cell datasets are available for download. Every dataset includes a Gene Expression Matrix (.h5ad). Additionally, if the original study does not provide sgRNA assignment information, we generate a CRISPR Guide Capture Matrix (.h5ad) for that dataset.\n\n● Gene Expression Matrix: Available for every dataset. Each file contains a single cell gene expression matrix along with associated metadata. Cell metadata includes cell-level quality control metrics and perturbation-related information such as perturbation gene symbol and perturbation Ensembl ID. Gene metadata includes gene-level annotations such as feature types, Ensembl ID, and gene type.\n● CRISPR Guide Capture Matrix: Available only when the original study does not provide sgRNA assignment information. In such cases, we generate this matrix to facilitate sgRNA assignment using the mixture model implemented in Pertpy. The matrix is processed from the CRISPR Guide Capture library using Cell Ranger (10x Genomics), where each element represents the UMI count of a given CRISPR guide (feature) in a specific cell barcode. The resulting AnnData object contains cells as rows and sgRNAs as columns, retaining sgRNA names (gene symbols) and feature types.\nUsers can locate datasets by either searching by Dataset ID (e.g., HSSC000001, MMSC000123) in the Search Dataset ID box, with multiple IDs entered as comma-separated values, or filtering by conditions using the dropdown menus for Tissue, Cell line, and Perturbation gene. After applying filters or searching by Dataset ID, the matching datasets are displayed in a table, and users can download datasets as needed."],
              ],
            ],
            [
              'id' => 'contact',
              'title' => '8. Contact',
              'items' => [
                ['q' => 'Q8.1: How can I contact the PerturbCorpus team?', 'a' => "For questions, feedback, or technical support regarding PerturbCorpus, please contact us at {{email2:ZW1oaGIxOTViMjVuWW1sdVowQm5hV0pvTG1GakxtTnU=}}. We welcome inquiries about data usage, tool functionality, collaboration opportunities, and bug reports.\nFor more information, please visit our website at https://www.zhaopage.com/."],
              ],
            ],
          ];
          ?>

          <div class="faq-layout">
            <aside class="faq-modules">
              <div class="faq-modules-title">Sections</div>
              <?php foreach ($faqModules as $module): ?>
                <a class="faq-module-link" href="#<?php echo htmlspecialchars($module['id']); ?>"><?php echo htmlspecialchars($module['title']); ?></a>
              <?php endforeach; ?>
            </aside>

            <section id="faqContentPanel">
              <?php foreach ($faqModules as $module): ?>
                <article class="faq-section" id="<?php echo htmlspecialchars($module['id']); ?>">
                  <h3><?php echo htmlspecialchars($module['title']); ?></h3>
                  <?php foreach ($module['items'] as $idx => $item): ?>
                    <?php
                      $qAnchor = '';
                      if (preg_match('/^Q(\d+)\.(\d+)/', (string)($item['q'] ?? ''), $m)) {
                        $qAnchor = 'q' . $m[1] . '-' . $m[2];
                      }
                    ?>
                    <div class="faq-item<?php echo $idx === 0 ? ' border-top-0 mt-0 pt-0' : ''; ?>">
                      <div class="faq-q"<?php echo $qAnchor !== '' ? ' id="' . htmlspecialchars($qAnchor) . '"' : ''; ?>><?php echo htmlspecialchars($item['q']); ?></div>
                      <div class="faq-a"><?php echo render_faq_answer_html((string)$item['a']); ?></div>
                    </div>
                  <?php endforeach; ?>
                </article>
              <?php endforeach; ?>
            </section>
          </div>
        </div>
      </div>
    </div>
  </main>
  <footer class="py-3 mt-auto text-center" style="background: transparent; border: none; width: 100%;">
    <div class="container-fluid px-4">
    <div class="footer-text-small-muted">&copy; <span id="year"></span> <a class="footer-link" href="https://www.zhaopage.com">Zhao Lab</a>. All rights reserved.</div>
    </div>
  </footer>
  <button id="backToTopBtn" class="back-to-top" type="button" aria-label="Back to top" title="Back to top">
    <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
      <path d="M8 3.2a.75.75 0 0 1 .53.22l4.2 4.2a.75.75 0 1 1-1.06 1.06L8.75 5.76V13a.75.75 0 0 1-1.5 0V5.76L4.33 8.68a.75.75 0 1 1-1.06-1.06l4.2-4.2A.75.75 0 0 1 8 3.2z"></path>
    </svg>
  </button>
  <script src="static/lib/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('year').textContent = new Date().getFullYear();

(function () {
  const btn = document.getElementById('backToTopBtn');
  const contentPanel = document.getElementById('faqContentPanel');
  if (!btn) return;

  function updateBtn() {
    btn.classList.toggle('show', window.scrollY > 240);
  }
  function positionBtn() {
    if (!contentPanel) return;
    const rect = contentPanel.getBoundingClientRect();
    const half = Math.round((btn.offsetWidth || 42) / 2);
    const extraGap = 240;
    const x = Math.max(8, Math.round(rect.left - half - extraGap));
    btn.style.left = `${x}px`;
  }
  btn.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  window.addEventListener('scroll', updateBtn, { passive: true });
  window.addEventListener('resize', positionBtn);
  window.addEventListener('scroll', positionBtn, { passive: true });
  positionBtn();
  updateBtn();
})();

(function () {
  const links = Array.from(document.querySelectorAll('.faq-module-link'));
  const sections = links
    .map((a) => document.querySelector(a.getAttribute('href')))
    .filter(Boolean);

  if (!links.length || !sections.length) return;

  function setActiveByHash(hash) {
    links.forEach((a) => a.classList.toggle('active', a.getAttribute('href') === hash));
  }

  function detectActiveSection() {
    const y = window.scrollY + 130;
    let activeId = sections[0].id;
    for (const section of sections) {
      if (section.offsetTop <= y) activeId = section.id;
      else break;
    }
    setActiveByHash('#' + activeId);
  }

  links.forEach((a) => {
    a.addEventListener('click', () => setActiveByHash(a.getAttribute('href')));
  });

  window.addEventListener('scroll', detectActiveSection, { passive: true });
  window.addEventListener('resize', detectActiveSection);
  detectActiveSection();
})();

(function () {
  function centerHashTarget() {
    const hash = decodeURIComponent(window.location.hash || '').trim();
    if (!hash || hash === '#') return;
    const id = hash.startsWith('#') ? hash.slice(1) : hash;
    const el = document.getElementById(id);
    if (!el) return;
    let targetEl = el;
    if (el.classList.contains('faq-section')) {
      targetEl = el.querySelector('h3') || el;
    }
    const rect = targetEl.getBoundingClientRect();
    const top = window.scrollY + rect.top - (window.innerHeight * 0.5) + (rect.height * 0.5);
    window.scrollTo({ top: Math.max(0, top), behavior: 'auto' });
  }

  window.addEventListener('hashchange', () => {
    setTimeout(centerHashTarget, 0);
  });
  window.addEventListener('load', () => {
    setTimeout(centerHashTarget, 80);
  });

  document.querySelectorAll('.faq-inline-link[href^="#"]').forEach((a) => {
    a.addEventListener('click', () => {
      setTimeout(centerHashTarget, 0);
    });
  });
})();

(function () {
  function decodeBase64Twice(enc) {
    try {
      return atob(atob(enc));
    } catch (e) {
      return '';
    }
  }

  document.querySelectorAll('.obf-email[data-enc]').forEach((node) => {
    const enc = node.getAttribute('data-enc') || '';
    const email = decodeBase64Twice(enc);
    if (!email) return;
    const span = document.createElement('span');
    span.textContent = email;
    span.setAttribute('aria-label', `Email ${email}`);
    node.replaceWith(span);
  });

  function renderFaqMath() {
    if (typeof window.renderMathInElement !== 'function') return;
    const root = document.querySelector('main') || document.body;
    window.renderMathInElement(root, {
      delimiters: [
        { left: '$$', right: '$$', display: true },
        { left: '\\(', right: '\\)', display: false },
        { left: '\\[', right: '\\]', display: true }
      ],
      throwOnError: false
    });
  }
  window.addEventListener('load', () => {
    setTimeout(renderFaqMath, 0);
  });
})();

</script>
</body>
</html>


