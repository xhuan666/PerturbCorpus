<?php
require_once __DIR__ . '/config.php';
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
  <title>PerturbCorpus</title>
  <link href="static/lib/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet" />
  <link href="static/style.css?ver=<?php echo uniqid(); ?>" rel="stylesheet" />
  <style>
    @media (min-width: 992px) {
      body.layout-body.home-page {
        overflow: hidden;
      }
      body.layout-body.home-page main.home-main {
        min-height: calc(100vh - var(--nav-offset) - 52px);
        display: flex;
      }
      body.layout-body.home-page .home-hero-fit {
        min-height: 100%;
        width: 100%;
        display: flex;
        align-items: center;
      }
      body.layout-body.home-page .home-hero-inner {
        padding-top: 0.2rem !important;
        padding-bottom: 0.2rem !important;
      }
      body.layout-body.home-page .home-hero-title {
        font-size: clamp(2.2rem, 3.1vw, 3rem) !important;
        line-height: 1.12;
        margin-bottom: 0.85rem !important;
      }
      body.layout-body.home-page .home-hero-subtitle {
        margin-bottom: 1rem !important;
      }
      body.layout-body.home-page .home-footer {
        padding-top: 0.4rem !important;
        padding-bottom: 0.4rem !important;
      }
    }

    .quick-search-panel {
      background: rgba(255, 255, 255, 0.78);
      border: 1px solid rgba(14, 165, 233, 0.22);
      border-radius: 12px;
      box-shadow: 0 2px 10px rgba(15, 23, 42, 0.05);
      padding: 8px !important;
    }
    .quick-search-row {
      display: grid;
      grid-template-columns: 220px 1fr 120px;
      gap: 10px;
      align-items: center;
      padding: 0;
      border: none;
      border-radius: 0;
      background: transparent;
    }
    .quick-search-panel .form-select,
    .quick-search-panel .form-control {
      border: 1px solid rgba(14, 165, 233, 0.20);
      border-radius: 8px;
      background: #fff;
      transition: all 0.18s ease;
      min-height: 40px;
      font-size: 0.92rem;
    }
    .quick-search-input-wrap {
      position: relative;
      min-width: 0;
    }
    .quick-search-suggest {
      position: absolute;
      top: calc(100% + 4px);
      left: 0;
      right: 0;
      z-index: 60;
      max-height: 280px;
      overflow: auto;
      margin: 0;
      padding: 4px 0;
      list-style: none;
      border: 1px solid rgba(14, 165, 233, 0.28);
      border-radius: 8px;
      background: #fff;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.10);
      text-align: left;
    }
    .quick-search-suggest-item {
      padding: 6px 10px;
      font-size: 0.90rem;
      color: #1f2937;
      cursor: pointer;
      line-height: 1.3;
      word-break: break-word;
      text-align: left;
    }
    .quick-search-suggest-item:hover,
    .quick-search-suggest-item.active {
      background: rgba(14, 165, 233, 0.10);
    }
    .quick-search-suggest-empty {
      padding: 6px 10px;
      font-size: 0.88rem;
      color: #6b7280;
      text-align: left;
    }
    .quick-search-panel .form-select {
      font-size: 0.88rem;
      font-weight: 500;
    }
    .quick-search-panel .form-select:focus,
    .quick-search-panel .form-control:focus {
      box-shadow: 0 0 0 0.16rem rgba(14, 165, 233, 0.12);
      border-color: rgba(14, 165, 233, 0.45);
      background: #fff;
    }
    .quick-search-panel .btn-primary {
      border-radius: 8px;
      min-height: 40px;
      font-weight: 600;
      box-shadow: none;
      font-size: 0.88rem;
      padding: 0 0.8rem;
    }
    @media (max-width: 768px) {
      .quick-search-row {
        grid-template-columns: 1fr;
        gap: 8px;
      }
    }
  </style>
</head>
<body class="layout-body home-page d-flex flex-column min-vh-100">
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


  <main class="home-main">
    <!-- Module 1: Overall Database Introduction -->
    <section class="hero-shell home-hero-fit position-relative overflow-hidden pt-0">
      <div class="container-fluid page-shell home-hero-inner pb-5 pt-2 mt-0 position-relative" style="z-index: 2;">
        <div class="row justify-content-center text-center">
          <div class="col-12">
            <h1 class="fw-bold hero-title-main home-hero-title mb-4" style="font-size: 3.2rem; color: #2c3e50;">An AI-Ready Genetic Perturbation Database</h1>
<p class="lead home-hero-subtitle mb-5 mx-auto" style="max-width: 980px; color: #1f3f6d; font-size: 1.2rem; font-weight: 700;">
              A unified collection of bulk and single cell perturbation transcriptomes with standardized metadata, model-ready datasets, and built-in analysis tools for AI-driven biological discovery.
            </p>
            <div class="mx-auto" style="max-width: 920px;">
              <form id="homeQuickSearchForm" class="quick-search-panel p-2 p-md-2">
                <div class="quick-search-row">
                  <select id="homeQuickSearchField" class="form-select" aria-label="Select filter field">
                    <option value="meta_biosample_tissue_name" selected>Tissue</option>
                    <option value="meta_biosample_description">Biosample Description</option>
                    <option value="meta_assay_target_gene_name">Perturbed Gene</option>
                  </select>
                  <div class="quick-search-input-wrap">
                    <input
                      id="homeQuickSearchInput"
                      type="text"
                      class="form-control"
                      placeholder="Please input"
                      aria-label="Quick search input"
                      autocomplete="off"
                    >
                    <ul id="homeQuickSuggest" class="quick-search-suggest d-none" role="listbox" aria-label="Suggestions"></ul>
                  </div>
                  <button class="btn btn-primary" type="submit">Search</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
      <!-- Background graphical element -->
      <div class="virtual-cell-stage position-absolute top-50 start-50 translate-middle opacity-25" style="z-index: 1; transform: scale(1.5);">
        <span class="cell-orb"></span><span class="cell-orb"></span><span class="cell-orb"></span><span class="cell-orb"></span>
      </div>
    </section>
  </main>

  <footer class="home-footer py-3 mt-auto text-center" style="background: transparent; border: none; width: 100%;">
    <div class="container-fluid px-4">
    <div class="footer-text-small-muted">&copy; <span id="year"></span> <a class="footer-link" href="https://www.zhaopage.com">Zhao Lab</a>. All rights reserved.</div>
    </div>
  </footer>

  <script src="static/lib/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
  <script>

    (function () {
      const form = document.getElementById('homeQuickSearchForm');
      const fieldSel = document.getElementById('homeQuickSearchField');
      const input = document.getElementById('homeQuickSearchInput');
      const msg = document.getElementById('homeQuickSearchMsg');
      const suggest = document.getElementById('homeQuickSuggest');
      if (!form || !fieldSel || !input || !suggest) return;

      const placeholderMap = {
        'meta_biosample_tissue_name': 'e.g. Liver,Brain',
        'meta_biosample_description': 'e.g. A549,Cardiomyocyte',
        'meta_assay_target_gene_name': 'e.g. TP53,BRCA1'
      };
      const syncPlaceholder = function () {
        const key = fieldSel.value || 'meta_biosample_tissue_name';
        input.placeholder = placeholderMap[key] || 'Please input';
      };
      fieldSel.addEventListener('change', syncPlaceholder);
      syncPlaceholder();

      const hideMsg = function () {
        if (!msg) return;
        msg.classList.add('d-none');
        msg.textContent = '';
      };
      const showMsg = function (text) {
        if (!msg) return;
        msg.textContent = text;
        msg.classList.remove('d-none');
      };

      const uniq = function (arr) {
        return arr.filter((x, i) => arr.indexOf(x) === i);
      };
      const escapeHtml = function (value) {
        return String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      };

      const fetchFacetMatches = async function (field, token) {
        const url = new URL('browse.php', window.location.href);
        url.searchParams.set('ajax_options', 'facet');
        url.searchParams.set('field', field);
        url.searchParams.set('q', token);
        url.searchParams.set('limit', '200');
        const res = await fetch(url.toString(), { cache: 'no-store' });
        if (!res.ok) {
          throw new Error('Search request failed');
        }
        const data = await res.json();
        const options = (data && data.ok && Array.isArray(data.options)) ? data.options : [];
        if (!options.length) return [];

        // Prefer exact case-insensitive match; fallback to contains matches.
        const lower = token.toLowerCase();
        const exact = options.filter((x) => String(x).toLowerCase() === lower);
        return exact.length ? exact : options;
      };

      let suggestTimer = null;
      let suggestReqSeq = 0;
      let suggestItems = [];
      let suggestActive = -1;

      const hideSuggest = function () {
        suggest.classList.add('d-none');
        suggest.innerHTML = '';
        suggestItems = [];
        suggestActive = -1;
      };

      const showSuggest = function () {
        suggest.classList.remove('d-none');
      };

      const getLastToken = function (raw) {
        const parts = String(raw || '').split(/[,\uFF0C]+/);
        return (parts[parts.length - 1] || '').trim();
      };

      const replaceLastToken = function (raw, chosen) {
        const src = String(raw || '');
        const parts = src.split(/[,\uFF0C]+/);
        parts[parts.length - 1] = String(chosen || '');
        return parts
          .map((x) => x.trim())
          .filter((x) => x !== '')
          .join(', ');
      };

      const renderSuggest = function () {
        if (!suggestItems.length) {
          suggest.innerHTML = '<li class="quick-search-suggest-empty">No matched data.</li>';
          showSuggest();
          return;
        }
        suggest.innerHTML = suggestItems.map((item, idx) => (
          `<li class="quick-search-suggest-item${idx === suggestActive ? ' active' : ''}" data-idx="${idx}" role="option" aria-selected="${idx === suggestActive ? 'true' : 'false'}">${escapeHtml(item)}</li>`
        )).join('');
        showSuggest();
      };

      const fetchSuggest = async function () {
        const token = getLastToken(input.value || '');
        if (!token || token.length < 1) {
          hideSuggest();
          return;
        }
        const reqSeq = ++suggestReqSeq;
        const url = new URL('browse.php', window.location.href);
        url.searchParams.set('ajax_options', 'facet');
        url.searchParams.set('field', fieldSel.value || 'meta_biosample_tissue_name');
        url.searchParams.set('q', token);
        url.searchParams.set('limit', '30');
        try {
          const res = await fetch(url.toString(), { cache: 'no-store' });
          if (!res.ok) throw new Error('suggest failed');
          const data = await res.json();
          if (reqSeq !== suggestReqSeq) return;
          const options = (data && data.ok && Array.isArray(data.options)) ? data.options.map((x) => String(x)) : [];
          const t = token.toLowerCase();
          const prefix = [];
          const contain = [];
          options.forEach((v) => {
            const l = v.toLowerCase();
            if (l.startsWith(t)) prefix.push(v);
            else if (l.includes(t)) contain.push(v);
          });
          suggestItems = uniq(prefix.concat(contain)).slice(0, 12);
          suggestActive = -1;
          renderSuggest();
        } catch (e) {
          hideSuggest();
        }
      };

      const chooseSuggest = function (idx) {
        if (idx < 0 || idx >= suggestItems.length) return;
        input.value = replaceLastToken(input.value, suggestItems[idx]);
        hideSuggest();
        input.focus();
      };

      form.addEventListener('submit', async function (e) {
        e.preventDefault();
        hideMsg();
        const raw = (input.value || '').trim();
        if (!raw) {
          showMsg('Please input keyword(s).');
          return;
        }

        const tokens = raw
          .split(/[,\uFF0C]+/)
          .map((x) => x.trim())
          .filter((x, i, arr) => x !== '' && arr.indexOf(x) === i);
        if (!tokens.length) {
          showMsg('Please input valid keyword(s).');
          return;
        }

        const fieldKey = fieldSel.value || 'meta_biosample_tissue_name';
        const allMatches = [];
        try {
          const groups = await Promise.all(tokens.map((kw) => fetchFacetMatches(fieldKey, kw)));
          groups.forEach((g) => {
            (g || []).forEach((item) => allMatches.push(String(item)));
          });
        } catch (err) {
          showMsg('Search failed. Please try again.');
          return;
        }

        const matched = uniq(allMatches);
        if (!matched.length) {
          showMsg('No matched data.');
          return;
        }
        if (matched.length > 120) {
          showMsg('Too many matches. Please input a more specific keyword.');
          return;
        }

        const params = new URLSearchParams();
        params.set('limit', '25');
        matched.forEach((kw) => {
          params.append(fieldKey + '[]', kw);
        });
        window.location.href = 'browse.php?' + params.toString();
      });

      fieldSel.addEventListener('change', () => {
        hideSuggest();
      });

      input.addEventListener('input', () => {
        if (suggestTimer) clearTimeout(suggestTimer);
        suggestTimer = setTimeout(fetchSuggest, 160);
      });

      input.addEventListener('keydown', (e) => {
        if (suggest.classList.contains('d-none') || !suggestItems.length) return;
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          suggestActive = (suggestActive + 1) % suggestItems.length;
          renderSuggest();
          return;
        }
        if (e.key === 'ArrowUp') {
          e.preventDefault();
          suggestActive = (suggestActive - 1 + suggestItems.length) % suggestItems.length;
          renderSuggest();
          return;
        }
        if (e.key === 'Enter' && suggestActive >= 0) {
          e.preventDefault();
          chooseSuggest(suggestActive);
          return;
        }
        if (e.key === 'Escape') {
          hideSuggest();
        }
      });

      suggest.addEventListener('mousedown', (e) => {
        const li = e.target.closest('.quick-search-suggest-item');
        if (!li) return;
        e.preventDefault();
        const idx = Number(li.getAttribute('data-idx') || '-1');
        chooseSuggest(idx);
      });

      document.addEventListener('click', (e) => {
        const wrap = e.target.closest('.quick-search-input-wrap');
        if (!wrap) hideSuggest();
      });
    })();
  </script>
<script>document.getElementById('year').textContent = new Date().getFullYear();</script>
</body>
</html>





