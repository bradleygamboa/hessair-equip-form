/* ════════════════════════════════════════
   Hess Air Quote Form v2 — WordPress Plugin Script
   Reads systems + config from inline JSON;
   hessqfeData.ajaxUrl / nonce injected by wp_localize_script().
════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── Boot: parse inline JSON ── */
  const dataEl   = document.getElementById('hessqfeSystemsData');
  const configEl = document.getElementById('hessqfeConfigData');
  if (!dataEl || !configEl) return;

  let SYSTEMS = [];
  let CONFIG  = {};
  try { SYSTEMS = JSON.parse(dataEl.textContent) || []; } catch (e) { SYSTEMS = []; }
  try { CONFIG  = JSON.parse(configEl.textContent) || {}; } catch (e) { CONFIG = {}; }

  // Assign stable row IDs for selection tracking
  SYSTEMS.forEach((s, i) => { s._id = 'sys_' + i; });

  const TIER_META = [
    { tier: 4, label: 'Preferred Value', stars: '★★★★', cls: 'hqf-blue',   badgeCls: 'hqf-t4' },
    { tier: 3, label: 'Popular Value',   stars: '★★★',  cls: 'hqf-green',  badgeCls: 'hqf-t3' },
    { tier: 2, label: 'Enhanced Value',  stars: '★★',   cls: 'hqf-yellow', badgeCls: 'hqf-t2' },
    { tier: 1, label: 'Contractor Value',stars: '★',    cls: 'hqf-gray',   badgeCls: 'hqf-t1' },
  ];


  /* ── State ──
     filtered stays empty until the user picks at least one filter value. The
     full catalog is large, so we show a prompt instead of a giant list. */
  const state = {
    filtered:        [],
    hasFilter:       false,
    sortKey:         null,
    sortAsc:         true,
    comparedUnits:   [],   // array of system objects user added via "Compare"
    selectedUnit:    null, // single system from comparedUnits chosen for the quote
    quoteNumber:     null,
  };

  /* ── Formatters ── */
  const fmt$   = n => (n == null || n === '') ? '—' : '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  const fmtMo  = n => (n == null || n === '') ? '—' : '$' + Number(n).toFixed(2) + '/mo*';
  const fmtDay = n => (n == null || n === '') ? '—' : '$' + Number(n).toFixed(2) + '/day*';
  // tierMetaFor — used for the static value-package table (1-4) AND for unit
  // tier badges in the units/compare tables. Tier 0 = Basic (no stars, label
  // only). Tiers 5/6 are higher-end units that don't map to a value-package
  // column but still get their own star string + colored badge.
  const EXTRA_TIER_META = {
    0: { tier: 0, label: 'Basic',   stars: '',        cls: '', badgeCls: 'hqf-t0' },
    5: { tier: 5, label: 'Premium', stars: '★★★★★',   cls: '', badgeCls: 'hqf-t5' },
    6: { tier: 6, label: 'Elite',   stars: '★★★★★★',  cls: '', badgeCls: 'hqf-t6' },
  };
  function tierMetaFor(t) {
    const n = Number(t);
    const fixed = TIER_META.find(m => m.tier === n);
    if (fixed) return fixed;
    if (EXTRA_TIER_META[n]) return EXTRA_TIER_META[n];
    return { tier: 0, label: '', stars: '', cls: '', badgeCls: '' };
  }

  function tierBadge(tier) {
    const m = tierMetaFor(tier);
    // Basic tier shows the word "Basic" instead of stars
    const inner = (Number(tier) === 0) ? 'Basic' : (m.stars || '—');
    return `<span class="hqf-tier-badge ${m.badgeCls}">${inner}</span>`;
  }

  function generateQuoteNumber() {
    const now = new Date();
    const d = now.getFullYear().toString()
      + String(now.getMonth() + 1).padStart(2, '0')
      + String(now.getDate()).padStart(2, '0');
    return 'HA-' + d + '-' + String(Math.floor(1000 + Math.random() * 9000));
  }

  /* ── Helpers: resolve cell value from system by column key ── */
  function cellValue(sys, key) {
    switch (key) {
      case 'brand':    return sys.brand || '';
      case 'system':   return sys.system || '';
      case 'capacity': return (sys.capacity != null && sys.capacity !== '') ? (sys.capacity + ' Ton') : '';
      case 'tier':     return tierBadge(sys.tier);
      case 'model_id': return `<code style="font-size:0.79rem;">${escapeHtml(sys.model_id || '')}</code>`;
      case 'seer2':    return (sys.seer2 != null && sys.seer2 !== '') ? String(sys.seer2) : '';
      case 'year':     return sys.year ? String(sys.year) : '';
      case 'stage':    return sys.stage_label || sys.stage || '';
      case 'price':    return fmt$(sys.price);
      case 'monthly':  return fmtMo(sys.monthly);
      case 'daily':    return fmtDay(sys.daily);
      default:         return '';
    }
  }

  function sortValue(sys, key) {
    switch (key) {
      case 'brand':    return (sys.brand || '').toLowerCase();
      case 'system':   return (sys.system || '').toLowerCase();
      case 'capacity': return Number(sys.capacity) || 0;
      case 'tier':     return Number(sys.tier) || 0;
      case 'model_id': return (sys.model_id || '').toLowerCase();
      case 'seer2':    return Number(sys.seer2) || 0;
      case 'year':     return Number(sys.year) || 0;
      case 'stage':    return (sys.stage_label || sys.stage || '').toLowerCase();
      case 'price':    return Number(sys.price) || 0;
      case 'monthly':  return Number(sys.monthly) || 0;
      case 'daily':    return Number(sys.daily) || 0;
      default:         return '';
    }
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  }

  function visibleColumns() {
    const cols = CONFIG.tableCols || {};
    return Object.keys(cols).filter(k => Number(cols[k].visible) === 1).map(k => ({ key: k, label: cols[k].label }));
  }

  function visibleCardFields() {
    const fields = CONFIG.cardFields || {};
    return Object.keys(fields).filter(k => Number(fields[k].visible) === 1).map(k => ({ key: k, label: fields[k].label }));
  }

  /* ── Populate filter dropdowns from loaded data ── */
  function populateFilters() {
    const uniq = (arr) => [...new Set(arr.filter(v => v !== '' && v != null))];

    const brands     = uniq(SYSTEMS.map(s => s.brand)).sort();
    const systems    = uniq(SYSTEMS.map(s => s.system)).sort();
    const capacities = uniq(SYSTEMS.map(s => s.capacity)).sort((a, b) => a - b);

    fillSelect('hessqfeFilterBrand',    brands,     'All Brands');
    fillSelect('hessqfeFilterSystem',   systems,    'All Types');
    fillSelect('hessqfeFilterCapacity', capacities, 'All Capacities');
  }

  function fillSelect(id, values, placeholder) {
    const el = document.getElementById(id);
    if (!el) return;
    el.innerHTML = `<option value="">${placeholder}</option>` +
      values.map(v => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`).join('');
  }

  /* ── Filters ── */
  function applyFilters() {
    const brand    = (document.getElementById('hessqfeFilterBrand')?.value    || '');
    const system   = (document.getElementById('hessqfeFilterSystem')?.value   || '');
    const capacity = (document.getElementById('hessqfeFilterCapacity')?.value || '');

    state.hasFilter = !!(brand || system || capacity);

    if (!state.hasFilter) {
      state.filtered = [];
    } else {
      state.filtered = SYSTEMS.filter(s => {
        if (brand    && s.brand    !== brand)               return false;
        if (system   && s.system   !== system)              return false;
        if (capacity && String(s.capacity) !== String(capacity)) return false;
        return true;
      });
    }

    const el = document.getElementById('hessqfeAlertNoResults');
    if (el) el.classList.toggle('hessqf-show', state.hasFilter && state.filtered.length === 0);
    renderTable();
  }

  function clearFilters() {
    // Reset filter dropdowns
    ['hessqfeFilterBrand', 'hessqfeFilterSystem', 'hessqfeFilterCapacity'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });

    // Reset selection + filter state back to initial
    state.filtered        = [];
    state.hasFilter       = false;
    state.comparedUnits   = [];
    state.selectedUnit    = null;
    state.sortKey         = null;
    state.sortAsc         = true;

    // Hide downstream sections (compare + package tables, selection bar)
    const cardsSec = document.getElementById('hessqfeTierCardsSection');
    const pkgSec   = document.getElementById('hessqfePackageSection');
    const barSec   = document.getElementById('hessqfeSelectionBarSection');
    if (cardsSec) cardsSec.style.display = 'none';
    if (pkgSec)   pkgSec.style.display   = 'none';
    if (barSec)   barSec.style.display   = 'none';

    // Clear out grids so stale content doesn't flash on next open
    const grid    = document.getElementById('hessqfeTierCardsGrid');
    const pkgGrid = document.getElementById('hessqfePackageGrid');
    if (grid)    grid.innerHTML    = '';
    if (pkgGrid) pkgGrid.innerHTML = '';
    resetMatrixState();

    // Clear cost-adjustment inputs
    populateCostAdjustments();

    // Clear selection-bar display values
    ['hessqfeSelectedUnitDisplay','hessqfeSelectedPriceDisplay','hessqfeSelectedSeer2Display'].forEach(id => {
      const e = document.getElementById(id);
      if (e) e.textContent = '—';
    });

    // Hide alerts
    document.getElementById('hessqfeAlertNoResults')  ?.classList.remove('hessqf-show');
    document.getElementById('hessqfeAlertNoSelection')?.classList.remove('hessqf-show');

    renderTable();
  }

  /* ── Sort ── */
  function sortTable(key) {
    state.sortAsc = (state.sortKey === key) ? !state.sortAsc : true;
    state.sortKey = key;

    state.filtered.sort((a, b) => {
      const va = sortValue(a, key);
      const vb = sortValue(b, key);
      if (typeof va === 'number' && typeof vb === 'number') {
        return state.sortAsc ? va - vb : vb - va;
      }
      return state.sortAsc
        ? String(va).localeCompare(String(vb))
        : String(vb).localeCompare(String(va));
    });

    renderTable();
  }

  /* ── Render table ── */
  function renderTableHead() {
    const head = document.getElementById('hessqfeProductTableHead');
    if (!head) return;
    const cols = visibleColumns();
    head.innerHTML = cols.map(c => {
      const ind = state.sortKey === c.key ? (state.sortAsc ? ' ▲' : ' ▼') : ' ⇅';
      return `<th data-key="${c.key}">${escapeHtml(c.label)}<span class="hessqf-sort-icon">${ind}</span></th>`;
    }).join('') + '<th>Action</th>';

    // Attach sort handlers
    head.querySelectorAll('th[data-key]').forEach(th => {
      th.addEventListener('click', () => sortTable(th.dataset.key));
    });
  }

  function renderTable() {
    renderTableHead();
    const tbody = document.getElementById('hessqfeProductTableBody');
    if (!tbody) return;

    const cols = visibleColumns();
    const colCount = cols.length + 1;

    const financingInfo = document.getElementById('hessqfeFinancingInfo');

    // Prompt state — user hasn't picked any filter yet
    if (!state.hasFilter) {
      tbody.innerHTML = `<tr><td colspan="${colCount}" class="hqf-no-results hqf-prompt">
        <div class="hqf-prompt-icon">🔍</div>
        <div class="hqf-prompt-title">Choose a filter above to see matching systems</div>
        <div class="hqf-prompt-sub">${SYSTEMS.length} systems available. Pick a Brand, System Type, Capacity, or Year to narrow down.</div>
      </td></tr>`;
      if (financingInfo) financingInfo.style.display = 'none';
      return;
    }

    if (state.filtered.length === 0) {
      tbody.innerHTML = `<tr><td colspan="${colCount}" class="hqf-no-results">No products match your filters. Try removing one to broaden your search.</td></tr>`;
      if (financingInfo) financingInfo.style.display = 'none';
      return;
    }

    if (financingInfo) financingInfo.style.display = '';

    tbody.innerHTML = state.filtered.map(s => {
      const inCompare = state.comparedUnits.some(u => u._id === s._id);
      const cells = cols.map(c =>
        `<td data-label="${escapeHtml(c.label)}" data-key="${escapeHtml(c.key)}">${cellValue(s, c.key)}</td>`
      ).join('');
      return `<tr class="${inCompare ? 'hqf-selected-row' : ''}" data-sys="${s._id}">
        ${cells}
        <td>
          <button type="button" class="hessqf-btn hessqf-btn-primary hessqf-btn-sm hqf-add-btn" data-sys="${s._id}">
            ${inCompare ? '&#10003; Added' : 'Compare'}
          </button>
        </td>
      </tr>`;
    }).join('');

    tbody.querySelectorAll('.hqf-add-btn').forEach(btn => {
      btn.addEventListener('click', () => toggleCompare(btn.dataset.sys));
    });

    initResizableColumns();
  }

  /* ── Resizable columns ──
     Table uses table-layout:fixed with equal-share columns by default so the
     whole table always fits its container width. We only switch columns to
     explicit pixel widths once the user begins dragging a resize handle. */
  function initResizableColumns() {
    const table = document.getElementById('hessqfeProductTable');
    if (!table) return;
    if (window.innerWidth <= 700) return;

    const ths = Array.from(table.querySelectorAll('thead th'));

    ths.forEach((th, i) => {
      if (i === ths.length - 1) return; // skip Action col
      if (th.querySelector('.hqf-col-resizer')) return;

      const resizer = document.createElement('div');
      resizer.className = 'hqf-col-resizer';
      th.appendChild(resizer);

      let startX, startWidth;
      resizer.addEventListener('mousedown', e => {
        e.preventDefault();
        e.stopPropagation();

        // Lock every th to its current rendered width so sibling columns
        // don't reflow while we resize one.
        ths.forEach(h => { h.style.width = h.getBoundingClientRect().width + 'px'; });

        startX     = e.pageX;
        startWidth = th.getBoundingClientRect().width;
        resizer.classList.add('hqf-resizing');

        const onMove = mv => {
          const newW = Math.max(60, startWidth + (mv.pageX - startX));
          th.style.width = newW + 'px';
        };
        const onUp = () => {
          resizer.classList.remove('hqf-resizing');
          document.removeEventListener('mousemove', onMove);
          document.removeEventListener('mouseup',   onUp);
        };
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup',   onUp);
      });
    });
  }

  /* ── Compare list management ── */
  function toggleCompare(sysId) {
    const sys = SYSTEMS.find(s => s._id === sysId);
    if (!sys) return;
    const idx = state.comparedUnits.findIndex(u => u._id === sysId);
    if (idx >= 0) {
      state.comparedUnits.splice(idx, 1);
      delete matrixState[sysId];
      if (state.selectedUnit && state.selectedUnit._id === sysId) {
        state.selectedUnit = null;
      }
    } else {
      state.comparedUnits.push(sys);
      initMatrixStateFor(sys);
    }

    renderTable();
    buildCompareTable();
    populateCostAdjustments();
    updateSectionVisibility();
    updateSelectionBar();
  }

  /* ── Show/hide the Cost Adjustment/Selection Bar
     sections based on whether units are compared / a unit is selected. ── */
  function updateSectionVisibility() {
    const cardsSec = document.getElementById('hessqfeTierCardsSection');
    const barSec   = document.getElementById('hessqfeSelectionBarSection');
    const hasAny   = state.comparedUnits.length > 0;
    const hasSel   = !!state.selectedUnit;
    if (cardsSec) cardsSec.style.display = hasAny ? 'block' : 'none';
    if (barSec)   barSec.style.display   = hasSel ? 'block' : 'none';
  }

  function removeFromCompare(sysId) {
    // alias used by × buttons in compare table headers
    toggleCompare(sysId);
  }

  /* ── Per-unit editable state (pricing inputs) ─────────────────
     matrixState[unit._id] = { system_price, outdoor, indoor, installation, options, down, tradeIn }
     One entry per compared unit; preserved across re-renders.
  ──────────────────────────────────────────────────────────────── */
  const matrixState = {};

function parseMoney(v) {
    if (v == null) return 0;
    const n = parseFloat(String(v).replace(/[^0-9.\-]/g, ''));
    return Number.isFinite(n) ? n : 0;
  }

  function formatMoneyDisplay(n) {
    if (n == null || n === '' || isNaN(n)) return '';
    const num = Number(n);
    if (num === 0) return '';
    return '$' + num.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  function initMatrixStateFor(unit, force) {
    if (!unit) return;
    if (!force && matrixState[unit._id]) return;
    matrixState[unit._id] = {
      outdoor:      parseMoney(unit.outdoor_price),
      indoor:       parseMoney(unit.indoor_price),
      system_price: parseMoney(unit.price),
      installation: 0,
      installationList: [],
      options:      0,
      optionsList:  [],
      down:         0,
      tradeIn:      0,
      downNotes:         [],
      tradeInNotes:      [],
    };
  }

  function resetMatrixState() {
    Object.keys(matrixState).forEach(k => delete matrixState[k]);
  }

  function totalFor(unitId) {
    const s = matrixState[unitId];
    if (!s) return 0;
    return (s.system_price + s.installation + s.options) - s.tradeIn;
  }

  function unitDisplayName(u) {
    return (u && (u.model_id || u.outdoor_model)) || '—';
  }

  /* Brand → product photo (rendered above the model name in the compare
     table). Hosted in the WordPress Media Library under the May 2026 upload
     folder, so they can be re-uploaded without re-deploying the plugin.
     Ruud and "Ruud (Jobber)" share the same photo. Returns '' if the brand
     is unknown so callers can skip rendering an <img>. */
  function brandImageUrl(brand) {
    const base = (window.hessqfeData && hessqfeData.assetsUrl) ? hessqfeData.assetsUrl + 'images/' : '';
    if (!base) return '';
    const b = String(brand || '').toLowerCase();
    if (b.indexOf('carrier') === 0)  return base + 'carrier.png';
    if (b.indexOf('trane')   === 0)  return base + 'trane.png';
    if (b.indexOf('runtru')  === 0)  return base + 'runtru.jpg';
    if (b.indexOf('ruud')    === 0)  return base + 'ruud.png';
    return '';
  }

  /* ── Top table: per-unit compare ── */
  function buildCompareTable() {
    const grid = document.getElementById('hessqfeTierCardsGrid');
    if (!grid) return;

    const units = state.comparedUnits;
    if (units.length === 0) {
      grid.innerHTML = `<div class="hqf-tc-empty">No units added yet. Click "Compare" on any unit above to start.</div>`;
      return;
    }

    // Make sure each unit has matrix state
    units.forEach(u => initMatrixStateFor(u));

    // Column header per compared unit (brand image + model name + remove button)
    const colHeader = units.map(u => {
      const tier  = Number(u.tier);
      const meta  = tierMetaFor(tier);
      const stars = meta.stars || '';
      const img   = brandImageUrl(u.brand);
      return `<th class="hqf-tc-col-head hqf-tc-unit-head">
        <button type="button" class="hqf-tc-remove" data-remove="${u._id}" aria-label="Remove from compare" title="Remove">×</button>
        ${img ? `<img src="${escapeHtml(img)}" alt="${escapeHtml(u.brand || '')}" class="hqf-tc-brand-img" />` : ''}
        <div class="hqf-tc-tier-stars">${stars}</div>
        <div class="hqf-tc-tier-model">${escapeHtml(u.brand || '')}</div>
        <div class="hqf-tc-img-disclaimer">*General Brand Picture</div>
      </th>`;
    }).join('');

    // Helper: read-only value row across compared units
    const valueRow = (label, getter, extraCls) => `<tr class="${extraCls || ''}">
      <th class="hqf-tc-label">${escapeHtml(label)}</th>
      ${units.map(u =>
        `<td class="hqf-tc-cell">${getter(u)}</td>`
      ).join('')}
    </tr>`;

    // Unit details row
    const detailsCells = units.map(u => {
      const det = [
        u.capacity ? (u.capacity + ' Ton') : '',
        u.seer2 != null ? ('SEER2 ' + u.seer2) : '',
        u.system || '',
      ].filter(Boolean).join(' · ');
      return `<td class="hqf-tc-cell hqf-tc-details-cell">${escapeHtml(det || '—')}</td>`;
    }).join('');

    // Per-unit Select button
    const selectRow = `<tr class="hqf-tc-select-row">
      <td></td>
      ${units.map(u => {
        const isSel = state.selectedUnit && state.selectedUnit._id === u._id;
        return `<td class="hqf-tc-cell">
          <button type="button" class="hessqf-btn hessqf-btn-pink hessqf-btn-sm hqf-unit-select-btn" data-sys="${u._id}" ${isSel ? 'disabled' : ''}>
            ${isSel ? '&#10003; Selected' : 'Select Unit'}
          </button>
        </td>`;
      }).join('')}
    </tr>`;

    grid.innerHTML = `<div class="hqf-tc-wrap">
      <table class="hqf-tc">
        <thead>
          <tr><th class="hqf-tc-corner"></th>${colHeader}</tr>
        </thead>
        <tbody>
          <tr class="hqf-tc-details-row">
            <th class="hqf-tc-label">Unit Details</th>
            ${detailsCells}
          </tr>
          ${valueRow('Cap. Stg.',       u => escapeHtml(u.stage_label || u.stage || '—'))}
          ${valueRow('Daily Invest.',   u => escapeHtml(fmtDay(u.daily)))}
          ${valueRow('Monthly Pay',     u => escapeHtml(fmtMo(u.monthly)))}
          ${valueRow('Complete System', u => escapeHtml(fmt$(u.price)))}
          ${valueRow('Outdoor Unit',    u => escapeHtml(fmt$(u.outdoor_price)))}
          ${valueRow('Indoor Unit',     u => escapeHtml(fmt$(u.indoor_price)))}
          ${selectRow}
        </tbody>
      </table>
    </div>`;

    // Remove (×) wiring
    grid.querySelectorAll('.hqf-tc-remove').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        removeFromCompare(btn.dataset.remove);
      });
    });

    // Select Unit wiring
    grid.querySelectorAll('.hqf-unit-select-btn').forEach(btn => {
      btn.addEventListener('click', () => selectCompareUnit(btn.dataset.sys));
    });
  }

  /* ── Bottom table: static value-package features ── */
  function selectCompareUnit(sysId) {
    const u = state.comparedUnits.find(x => x._id === sysId);
    if (!u) return;
    state.selectedUnit = u;
    buildCompareTable();
    populateCostAdjustments();
    updateSectionVisibility();
    updateSelectionBar();
    const el = document.getElementById('hessqfeAlertNoSelection');
    if (el) el.classList.remove('hessqf-show');
  }

  /* ── Cost Adjustments (Options / Installation / Down Payment / Trade In) ──
     Single set of inputs in the Selection Bar section, applied to
     whichever unit is currently selected. ── */
  const COST_ADJ_FIELDS = [
    ['hessqfeAdjDown',         'down'],
    ['hessqfeAdjTradeIn',      'tradeIn'],
  ];

  // Notes line items for Down Payment, Trade In
  const NOTE_FIELDS = [
    { key: 'downNotes',         addBtn: 'hessqfeAddDownNoteBtn',         form: 'hessqfeAddDownNoteForm',         input: 'hessqfeNewDownNote',         save: 'hessqfeNewDownNoteAdd',         cancel: 'hessqfeNewDownNoteCancel',         list: 'hessqfeDownNotesList' },
    { key: 'tradeInNotes',      addBtn: 'hessqfeAddTradeInNoteBtn',      form: 'hessqfeAddTradeInNoteForm',      input: 'hessqfeNewTradeInNote',      save: 'hessqfeNewTradeInNoteAdd',      cancel: 'hessqfeNewTradeInNoteCancel',      list: 'hessqfeTradeInNotesList' },
  ];

  function recalcOptionsTotal(u) {
    if (!u || !matrixState[u._id]) return;
    const ms = matrixState[u._id];
    ms.options = (ms.optionsList || []).reduce((sum, o) => sum + o.amount, 0);
  }

  function recalcInstallationTotal(u) {
    if (!u || !matrixState[u._id]) return;
    const ms = matrixState[u._id];
    ms.installation = (ms.installationList || []).reduce((sum, o) => sum + o.amount, 0);
  }

  function updateOptionsInputDisplay() {
    const inp = document.getElementById('hessqfeAdjOptions');
    if (!inp) return;
    const u = state.selectedUnit;
    if (!u) { inp.value = ''; return; }
    const ms = matrixState[u._id];
    const val = ms ? ms.options : 0;
    inp.value = val ? formatMoneyDisplay(val) : '';
  }

  function updateInstallationInputDisplay() {
    const inp = document.getElementById('hessqfeAdjInstallation');
    if (!inp) return;
    const u = state.selectedUnit;
    if (!u) { inp.value = ''; return; }
    const ms = matrixState[u._id];
    const val = ms ? ms.installation : 0;
    inp.value = val ? formatMoneyDisplay(val) : '';
  }

  function renderOptionsList() {
    const listEl = document.getElementById('hessqfeOptionsList');
    if (!listEl) return;
    const u = state.selectedUnit;
    const items = (u && matrixState[u._id] && matrixState[u._id].optionsList) || [];
    if (items.length === 0) { listEl.innerHTML = ''; return; }
    listEl.innerHTML = items.map((o, i) => `
      <div class="hqf-option-item">
        <span class="hqf-option-label">${escapeHtml(o.label)}</span>
        <span class="hqf-option-amount">${escapeHtml(formatMoneyDisplay(o.amount) || '$0')}</span>
        <button type="button" class="hqf-option-remove" data-idx="${i}" aria-label="Remove ${escapeHtml(o.label)}">&times;</button>
      </div>
    `).join('');
    listEl.querySelectorAll('.hqf-option-remove').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = Number(btn.dataset.idx);
        const sel = state.selectedUnit;
        if (!sel || !matrixState[sel._id]) return;
        matrixState[sel._id].optionsList.splice(idx, 1);
        recalcOptionsTotal(sel);
        renderOptionsList();
        updateOptionsInputDisplay();
        updateSelectionBar();
      });
    });
  }

  function renderInstallationList() {
    const listEl = document.getElementById('hessqfeInstallationList');
    if (!listEl) return;
    const u = state.selectedUnit;
    const items = (u && matrixState[u._id] && matrixState[u._id].installationList) || [];
    if (items.length === 0) { listEl.innerHTML = ''; return; }
    listEl.innerHTML = items.map((o, i) => `
      <div class="hqf-option-item">
        <span class="hqf-option-label">${escapeHtml(o.label)}</span>
        <span class="hqf-option-amount">${escapeHtml(formatMoneyDisplay(o.amount) || '$0')}</span>
        <button type="button" class="hqf-option-remove" data-idx="${i}" aria-label="Remove ${escapeHtml(o.label)}">&times;</button>
      </div>
    `).join('');
    listEl.querySelectorAll('.hqf-option-remove').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = Number(btn.dataset.idx);
        const sel = state.selectedUnit;
        if (!sel || !matrixState[sel._id]) return;
        matrixState[sel._id].installationList.splice(idx, 1);
        recalcInstallationTotal(sel);
        renderInstallationList();
        updateInstallationInputDisplay();
        updateSelectionBar();
      });
    });
  }

  function renderNotesList(field) {
    const listEl = document.getElementById(field.list);
    if (!listEl) return;
    const u = state.selectedUnit;
    const items = (u && matrixState[u._id] && matrixState[u._id][field.key]) || [];
    if (items.length === 0) { listEl.innerHTML = ''; return; }
    listEl.innerHTML = items.map((note, i) => `
      <div class="hqf-option-item">
        <span class="hqf-option-label">${escapeHtml(note)}</span>
        <button type="button" class="hqf-option-remove" data-idx="${i}" aria-label="Remove note">&times;</button>
      </div>
    `).join('');
    listEl.querySelectorAll('.hqf-option-remove').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = Number(btn.dataset.idx);
        const sel = state.selectedUnit;
        if (!sel || !matrixState[sel._id]) return;
        matrixState[sel._id][field.key].splice(idx, 1);
        renderNotesList(field);
      });
    });
  }

  function populateCostAdjustments() {
    const u = state.selectedUnit;
    COST_ADJ_FIELDS.forEach(([id, field]) => {
      const inp = document.getElementById(id);
      if (!inp) return;
      if (!u) {
        inp.value = '';
        inp.disabled = true;
        return;
      }
      inp.disabled = false;
      const ms = matrixState[u._id];
      const val = ms ? ms[field] : 0;
      inp.value = val ? formatMoneyDisplay(val) : '';
    });

    const addBtn  = document.getElementById('hessqfeAddOptionBtn');
    const addForm = document.getElementById('hessqfeAddOptionForm');
    if (addBtn) addBtn.disabled = !u;
    if (addForm && !u) addForm.style.display = 'none';

    updateOptionsInputDisplay();
    renderOptionsList();

    const installAddBtn  = document.getElementById('hessqfeAddInstallationBtn');
    const installAddForm = document.getElementById('hessqfeAddInstallationForm');
    if (installAddBtn) installAddBtn.disabled = !u;
    if (installAddForm && !u) installAddForm.style.display = 'none';

    updateInstallationInputDisplay();
    renderInstallationList();

    NOTE_FIELDS.forEach(field => {
      const noteAddBtn  = document.getElementById(field.addBtn);
      const noteAddForm = document.getElementById(field.form);
      if (noteAddBtn) noteAddBtn.disabled = !u;
      if (noteAddForm && !u) noteAddForm.style.display = 'none';
      renderNotesList(field);
    });
  }

  function initCostAdjustments() {
    COST_ADJ_FIELDS.forEach(([id, field]) => {
      const inp = document.getElementById(id);
      if (!inp) return;
      inp.addEventListener('input', () => {
        const u = state.selectedUnit;
        if (!u || !matrixState[u._id]) return;
        matrixState[u._id][field] = parseMoney(inp.value);
        updateSelectionBar();
      });
      inp.addEventListener('focus', () => {
        const raw = parseMoney(inp.value);
        inp.value = raw ? String(raw) : '';
        inp.select && inp.select();
      });
      inp.addEventListener('blur', () => {
        const raw = parseMoney(inp.value);
        inp.value = raw ? formatMoneyDisplay(raw) : '';
      });
    });

    // Add Option (line items)
    const addBtn    = document.getElementById('hessqfeAddOptionBtn');
    const addForm   = document.getElementById('hessqfeAddOptionForm');
    const labelInp  = document.getElementById('hessqfeNewOptionLabel');
    const costInp   = document.getElementById('hessqfeNewOptionCost');
    const saveBtn   = document.getElementById('hessqfeNewOptionAdd');
    const cancelBtn = document.getElementById('hessqfeNewOptionCancel');

    const closeAddForm = () => {
      if (labelInp) labelInp.value = '';
      if (costInp)  costInp.value  = '';
      if (addForm)  addForm.style.display = 'none';
    };

    if (addBtn && addForm) {
      addBtn.addEventListener('click', () => {
        if (addBtn.disabled) return;
        const isOpen = addForm.style.display !== 'none';
        addForm.style.display = isOpen ? 'none' : 'flex';
        if (!isOpen && labelInp) labelInp.focus();
      });
    }

    if (saveBtn) {
      saveBtn.addEventListener('click', () => {
        const u = state.selectedUnit;
        if (!u || !matrixState[u._id]) return;
        const label  = (labelInp.value || '').trim();
        const amount = parseMoney(costInp.value);
        if (!label || !amount) return;
        matrixState[u._id].optionsList = matrixState[u._id].optionsList || [];
        matrixState[u._id].optionsList.push({ label, amount });
        recalcOptionsTotal(u);
        renderOptionsList();
        updateOptionsInputDisplay();
        updateSelectionBar();
        closeAddForm();
      });
    }

    if (cancelBtn) cancelBtn.addEventListener('click', closeAddForm);

    [labelInp, costInp].forEach(el => {
      if (!el) return;
      el.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); saveBtn?.click(); }
        else if (e.key === 'Escape') { closeAddForm(); }
      });
    });

    // Add Installation/Procurement item (line items)
    const installAddBtn    = document.getElementById('hessqfeAddInstallationBtn');
    const installAddForm   = document.getElementById('hessqfeAddInstallationForm');
    const installLabelInp  = document.getElementById('hessqfeNewInstallationLabel');
    const installCostInp   = document.getElementById('hessqfeNewInstallationCost');
    const installSaveBtn   = document.getElementById('hessqfeNewInstallationAdd');
    const installCancelBtn = document.getElementById('hessqfeNewInstallationCancel');

    const closeInstallForm = () => {
      if (installLabelInp) installLabelInp.value = '';
      if (installCostInp)  installCostInp.value  = '';
      if (installAddForm)  installAddForm.style.display = 'none';
    };

    if (installAddBtn && installAddForm) {
      installAddBtn.addEventListener('click', () => {
        if (installAddBtn.disabled) return;
        const isOpen = installAddForm.style.display !== 'none';
        installAddForm.style.display = isOpen ? 'none' : 'flex';
        if (!isOpen && installLabelInp) installLabelInp.focus();
      });
    }

    if (installSaveBtn) {
      installSaveBtn.addEventListener('click', () => {
        const u = state.selectedUnit;
        if (!u || !matrixState[u._id]) return;
        const label  = (installLabelInp.value || '').trim();
        const amount = parseMoney(installCostInp.value);
        if (!label || !amount) return;
        matrixState[u._id].installationList = matrixState[u._id].installationList || [];
        matrixState[u._id].installationList.push({ label, amount });
        recalcInstallationTotal(u);
        renderInstallationList();
        updateInstallationInputDisplay();
        updateSelectionBar();
        closeInstallForm();
      });
    }

    if (installCancelBtn) installCancelBtn.addEventListener('click', closeInstallForm);

    [installLabelInp, installCostInp].forEach(el => {
      if (!el) return;
      el.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); installSaveBtn?.click(); }
        else if (e.key === 'Escape') { closeInstallForm(); }
      });
    });

    // Add Note (Down Payment, Trade In)
    NOTE_FIELDS.forEach(field => {
      const noteAddBtn  = document.getElementById(field.addBtn);
      const noteAddForm = document.getElementById(field.form);
      const noteInp     = document.getElementById(field.input);
      const noteSaveBtn = document.getElementById(field.save);
      const noteCancelBtn = document.getElementById(field.cancel);

      const closeNoteForm = () => {
        if (noteInp) noteInp.value = '';
        if (noteAddForm) noteAddForm.style.display = 'none';
      };

      if (noteAddBtn && noteAddForm) {
        noteAddBtn.addEventListener('click', () => {
          if (noteAddBtn.disabled) return;
          const isOpen = noteAddForm.style.display !== 'none';
          noteAddForm.style.display = isOpen ? 'none' : 'flex';
          if (!isOpen && noteInp) noteInp.focus();
        });
      }

      if (noteSaveBtn) {
        noteSaveBtn.addEventListener('click', () => {
          const u = state.selectedUnit;
          if (!u || !matrixState[u._id]) return;
          const note = (noteInp.value || '').trim();
          if (!note) return;
          matrixState[u._id][field.key] = matrixState[u._id][field.key] || [];
          matrixState[u._id][field.key].push(note);
          renderNotesList(field);
          closeNoteForm();
        });
      }

      if (noteCancelBtn) noteCancelBtn.addEventListener('click', closeNoteForm);

      if (noteInp) {
        noteInp.addEventListener('keydown', e => {
          if (e.key === 'Enter') { e.preventDefault(); noteSaveBtn?.click(); }
          else if (e.key === 'Escape') { closeNoteForm(); }
        });
      }
    });
  }

  /* ── Selection Bar ── */
  function currentTotalInvestment() {
    const p = state.selectedUnit;
    if (!p) return 0;
    if (!matrixState[p._id]) initMatrixStateFor(p);
    return totalFor(p._id);
  }

  function updateSelectionBar() {
    const p = state.selectedUnit;
    const setEl = (id, val) => { const e = document.getElementById(id); if (e) e.textContent = val; };

    setEl('hessqfeSelectedUnitDisplay',  p ? unitDisplayName(p) : '—');

    if (!p) {
      setEl('hessqfeSelectedPriceDisplay', '—');
      setEl('hessqfeSelectedAmountFinancedDisplay', '—');
      setEl('hessqfeSelectedSeer2Display', '—');
      return;
    }
    const total = currentTotalInvestment();
    const down  = (matrixState[p._id] || {}).down || 0;
    setEl('hessqfeSelectedPriceDisplay', fmt$(total));
    setEl('hessqfeSelectedAmountFinancedDisplay', fmt$(total - down));
    setEl('hessqfeSelectedSeer2Display', p.seer2 != null ? p.seer2 : '—');
  }

  /* ── Step Navigation ── */
  function goToStep2() {
    if (!state.selectedUnit) {
      const el = document.getElementById('hessqfeAlertNoSelection');
      if (el) el.classList.add('hessqf-show');
      document.getElementById('hessqfeSelectionBarSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }

    if (!state.quoteNumber) state.quoteNumber = generateQuoteNumber();
    const qnEl = document.getElementById('hessqfeQuoteNumberDisplay');
    if (qnEl) qnEl.textContent = state.quoteNumber;

    const p = state.selectedUnit;
    if (!matrixState[p._id]) initMatrixStateFor(p);
    const s = matrixState[p._id] || { outdoor:0, indoor:0, system_price:0, installation:0, options:0, down:0, tradeIn:0 };
    const total = s.system_price + s.installation + s.options - s.tradeIn;

    const summaryEl = document.getElementById('hessqfeStep2Summary');
    if (summaryEl) {
      const withNotes = (value, items) => (items && items.length) ? `${value} (${items.join(', ')})` : value;
      const rows = [
        ['Hess Associate',  document.getElementById('hessqfeFieldAssociate')?.value.trim() || '—'],
        ['Existing Unit Brand',   document.getElementById('hessqfeFieldExistingBrand')?.value.trim() || '—'],
        ['Existing Model #',      document.getElementById('hessqfeFieldExistingModel')?.value.trim() || '—'],
        ['Existing Serial #',     document.getElementById('hessqfeFieldExistingSerial')?.value.trim() || '—'],
        ['Attic / Closet Unit',   (document.querySelector('input[name="hessqfeExistingAtticCloset"]:checked') || {}).value || 'None'],
        ['Quote Number',     state.quoteNumber],
        ['Selected Unit',    unitDisplayName(p)],
        ['Brand',            p.brand || '—'],
        ['System Type',      p.system || '—'],
        ['Capacity',         p.capacity ? (p.capacity + ' Ton') : '—'],
        ['Cap. Stg.',        p.stage_label || p.stage || '—'],
        ['SEER2',            p.seer2 != null ? p.seer2 : '—'],
        ['Outdoor Unit',     p.outdoor_model || '—'],
        ['Indoor Unit',      p.indoor_model || '—'],
        ['Options',          withNotes(fmt$(s.options), (s.optionsList || []).map(o => o.label))],
        ['Procurement/Labor/Materials', withNotes(fmt$(s.installation), (s.installationList || []).map(o => o.label))],
        ['Down Payment/Cash/Credit Card', withNotes(fmt$(s.down), s.downNotes || [])],
        ['Other',         withNotes('-' + fmt$(s.tradeIn), s.tradeInNotes || [])],
        ['Total Investment', fmt$(total)],
        ['Amount Financed',  fmt$(total - s.down)],
        ['Monthly Payment',  fmtMo(p.monthly)],
        ['Daily Investment', fmtDay(p.daily)],
      ];
      summaryEl.innerHTML = rows.map(([l, v]) => {
        const isTotal = l === 'Total Investment';
        return `<div class="hessqf-summary-row${isTotal ? ' hqf-summary-total' : ''}"><span class="hqf-slabel">${escapeHtml(l)}</span><span class="hqf-svalue">${escapeHtml(String(v))}</span></div>`;
      }).join('');
    }

    showStep(2);
  }

  function goToStep1() { showStep(1); }

  function showStep(n) {
    document.getElementById('hessqfeStepPanel1')?.classList.toggle('active', n === 1);
    document.getElementById('hessqfeStepPanel2')?.classList.toggle('active', n === 2);
    const topInfo = document.getElementById('hessqfeTopInfoCard');
    if (topInfo) topInfo.style.display = (n === 2) ? 'none' : '';
    document.getElementById('hessqfeStep1Pill')?.classList.toggle('active', n === 1);
    document.getElementById('hessqfeStep1Pill')?.classList.toggle('done',   n === 2);
    document.getElementById('hessqfeStep2Pill')?.classList.toggle('active', n === 2);
    window.scrollTo({ top: 0, behavior: 'smooth' });
    // Re-measure the signature pad now that step 2 is laid out (it would have
    // been 0×0 while hidden, which throws off cursor mapping).
    if (n === 2 && window.hessqfeSignaturePad && window.hessqfeSignaturePad.resize) {
      requestAnimationFrame(() => window.hessqfeSignaturePad.resize());
    }
  }

  /* ── Form Validation ── */
  function validateField(id, errId, msg, extra) {
    const el  = document.getElementById(id);
    const err = document.getElementById(errId);
    if (!el || !err) return true;
    const val = el.value.trim();
    if (!val || (extra && !extra(val))) {
      el.classList.add('hqf-error');
      err.textContent = msg;
      return false;
    }
    el.classList.remove('hqf-error');
    err.textContent = '';
    return true;
  }

  /* ── Submit ── */
  function submitForm() {
    const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const v1 = validateField('hessqfeFieldName',     'hessqfeErrName',     'Full name is required.');
    const v2 = validateField('hessqfeFieldPhone',    'hessqfeErrPhone',    'Phone number is required.');
    const v3 = validateField('hessqfeFieldEmail',    'hessqfeErrEmail',    'A valid email address is required.', v => emailRe.test(v));
    const v4 = validateField('hessqfeFieldAddress',  'hessqfeErrAddress',  'Address is required.');
    const v5 = validateField('hessqfeFieldAssociate','hessqfeErrAssociate','Hess associate name is required.');
    if (!v1 || !v2 || !v3 || !v4 || !v5) return;

    const p = state.selectedUnit;
    if (!matrixState[p._id]) initMatrixStateFor(p);
    const ms = matrixState[p._id] || { outdoor:0, indoor:0, system_price:0, installation:0, options:0, down:0, tradeIn:0 };
    const msSystemPrice   = ms.system_price || (ms.outdoor + ms.indoor);
    const totalInvestment = msSystemPrice + ms.installation + ms.options - ms.tradeIn;
    const amountFinanced  = totalInvestment - ms.down;
    const optionsBreakdown      = (ms.optionsList || []).map(o => `${o.label}: ${fmt$(o.amount)}`).join('; ');
    const installationBreakdown = (ms.installationList || []).map(o => `${o.label}: ${fmt$(o.amount)}`).join('; ');

    const associate        = document.getElementById('hessqfeFieldAssociate').value.trim();
    const existingBrand    = document.getElementById('hessqfeFieldExistingBrand')?.value.trim()   || '';
    const existingModel    = document.getElementById('hessqfeFieldExistingModel')?.value.trim()   || '';
    const existingSerial   = document.getElementById('hessqfeFieldExistingSerial')?.value.trim()  || '';
    const existingAtticCloset = (document.querySelector('input[name="hessqfeExistingAtticCloset"]:checked') || {}).value || 'None';
    const name      = document.getElementById('hessqfeFieldName').value.trim();
    const phone     = document.getElementById('hessqfeFieldPhone').value.trim();
    const email     = document.getElementById('hessqfeFieldEmail').value.trim();
    const address   = document.getElementById('hessqfeFieldAddress').value.trim();
    const schedule  = document.getElementById('hessqfeFieldSchedule').value;
    const comments  = document.getElementById('hessqfeFieldComments').value.trim();
    const financing0pct = (document.querySelector('input[name="hessqfeFinancing0pct"]:checked') || {}).value || '';
    const signature = (window.hessqfeSignaturePad && window.hessqfeSignaturePad.getDataURL()) || '';

    const submitBtn = document.getElementById('hessqfeSubmitBtn');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Submitting…'; }

    const errorEl = document.getElementById('hessqfeAlertSubmitError');
    if (errorEl) errorEl.classList.remove('hessqf-show');

    const fd = new FormData();
    fd.append('action',       'hessqfe_submit');
    fd.append('nonce',        hessqfeData.nonce);
    fd.append('quoteNumber',  state.quoteNumber);
    fd.append('associate',    associate);
    fd.append('existingBrand',       existingBrand);
    fd.append('existingModel',       existingModel);
    fd.append('existingSerial',      existingSerial);
    fd.append('existingAtticCloset', existingAtticCloset);
    fd.append('name',         name);
    fd.append('phone',        phone);
    fd.append('email',        email);
    fd.append('address',      address);
    fd.append('schedule',     schedule);
    fd.append('comments',     comments);
    fd.append('financing0pct', financing0pct);
    fd.append('ahri',         p.ahri     || '');
    fd.append('modelId',      p.model_id || p.outdoor_model || '');
    fd.append('brand',        p.brand    || '');
    fd.append('system',       p.system   || '');
    fd.append('capacity',     p.capacity ? (p.capacity + ' Ton') : '');
    fd.append('unitTier',     p.tier ? String(p.tier) : '');
    fd.append('valuePackage', '');
    fd.append('tier',         '');
    fd.append('seer2',        p.seer2 != null ? String(p.seer2) : '');
    fd.append('price',        fmt$(p.price));
    fd.append('systemPrice',  fmt$(msSystemPrice));
    fd.append('installation', fmt$(ms.installation));
    fd.append('installationBreakdown', installationBreakdown);
    fd.append('options',      fmt$(ms.options));
    fd.append('optionsBreakdown', optionsBreakdown);
    fd.append('downPayment',  fmt$(ms.down));
    fd.append('tradeIn',      fmt$(ms.tradeIn));
    fd.append('downNotes',         (ms.downNotes || []).join('; '));
    fd.append('tradeInNotes',      (ms.tradeInNotes || []).join('; '));
    fd.append('totalInvestment', fmt$(totalInvestment));
    fd.append('amountFinanced',  fmt$(amountFinanced));
    fd.append('monthly',      fmtMo(p.monthly));
    fd.append('daily',        fmtDay(p.daily));
    fd.append('outdoorModel', p.outdoor_model || '');
    fd.append('indoorModel',  p.indoor_model  || '');
    fd.append('stage',        p.stage_label || p.stage || '');
    fd.append('signature',    signature);

    fetch(hessqfeData.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (data && data.success) {
          const emailSent = data.data?.customerEmail !== false;
          showConfirmation(name, email, address, p, {}, schedule, comments, emailSent);
        } else {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Submit Quote →'; }
          if (errorEl) {
            errorEl.textContent = data?.data?.message || 'Submission failed. Please try again.';
            errorEl.classList.add('hessqf-show');
          }
        }
      })
      .catch(() => {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Submit Quote →'; }
        if (errorEl) {
          errorEl.textContent = 'A network error occurred. Please check your connection and try again.';
          errorEl.classList.add('hessqf-show');
        }
      });
  }

  /* ── Confirmation ── */
  function showConfirmation(name, email, address, p, meta, schedule, comments, emailSent) {
    const qn = state.quoteNumber;
    const qnEl    = document.getElementById('hessqfeConfirmQuoteNumber');
    const noteEl  = document.getElementById('hessqfeConfirmEmailNote');
    const detailEl= document.getElementById('hessqfeConfirmDetails');

    if (qnEl) qnEl.textContent = qn;
    if (noteEl) {
      if (emailSent !== false) {
        noteEl.textContent = `A copy of this quote has been sent to ${email}`;
        noteEl.style.color = '#c0457a';
      } else {
        noteEl.textContent = `Quote captured. A confirmation email to ${email} may be delayed — your office copy was delivered.`;
        noteEl.style.color = '#856404';
      }
    }

    if (detailEl) {
      const sysRows = [
        ['Quote #',        qn],
        ['Selected Unit',  unitDisplayName(p)],
        ['Brand',          p.brand || '—'],
        ['Type',           p.system || '—'],
        ['Capacity',       p.capacity ? (p.capacity + ' Ton') : '—'],
        ['Cap. Stg.',      p.stage_label || p.stage || '—'],
        ['SEER2',          p.seer2 != null ? p.seer2 : '—'],
        ['Price',          fmt$(p.price)],
        ['Monthly',        fmtMo(p.monthly)],
        ['Daily',          fmtDay(p.daily)],
      ];

      const contactRows = [
        ['Hess Associate', document.getElementById('hessqfeFieldAssociate').value.trim()],
        ['Name',    name],
        ['Phone',   document.getElementById('hessqfeFieldPhone').value.trim()],
        ['Email',   email],
        ['Address', address],
        ['Timing',  schedule],
        ...(comments ? [['Notes', comments]] : []),
      ];

      detailEl.innerHTML = `
        <div class="hqf-confirm-block">
          <h4>Selected System</h4>
          ${sysRows.map(([l,v]) => `<div class="hqf-confirm-row"><span class="hqf-dl">${escapeHtml(l)}</span><span class="hqf-dv">${escapeHtml(String(v))}</span></div>`).join('')}
        </div>
        <div class="hqf-confirm-block">
          <h4>Contact Details</h4>
          ${contactRows.map(([l,v]) => `<div class="hqf-confirm-row"><span class="hqf-dl">${escapeHtml(l)}</span><span class="hqf-dv">${escapeHtml(String(v))}</span></div>`).join('')}
        </div>`;
    }

    document.getElementById('hessqfeStepPanel2')?.classList.remove('active');
    document.getElementById('hessqfeStep2Pill')?.classList.remove('active');
    document.getElementById('hessqfeStep2Pill')?.classList.add('done');
    document.getElementById('hessqfeConfirmationPanel')?.classList.add('hessqf-show');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  /* ── Signature Pad ──
     Lightweight HTML5 canvas signature capture (mouse + touch). Exposes a
     small API on window.hessqfeSignaturePad: { isEmpty, clear, getDataURL }. */
  function initSignaturePad() {
    const canvas = document.getElementById('hessqfeSignaturePad');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    // Cursor accuracy depends on the canvas's drawing-surface size matching
    // its CSS-rendered size. We scale the surface by devicePixelRatio for
    // sharpness and apply an inverse transform so that drawing commands stay
    // in CSS-pixel space (matching pointer coordinates exactly).
    let cssW = 0;
    let cssH = 0;

    function resize() {
      // Snapshot any existing strokes so we can redraw them after resize
      const prev = (cssW > 0 && cssH > 0) ? canvas.toDataURL('image/png') : null;

      const ratio = window.devicePixelRatio || 1;
      const rect  = canvas.getBoundingClientRect();
      const w     = Math.round(rect.width);
      const h     = Math.round(rect.height);
      if (w === 0 || h === 0) return; // canvas not visible yet

      cssW = w;
      cssH = h;
      canvas.width  = w * ratio;
      canvas.height = h * ratio;
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
      ctx.lineWidth   = 2;
      ctx.lineCap     = 'round';
      ctx.lineJoin    = 'round';
      ctx.strokeStyle = '#1a3a5c';

      if (prev) {
        const img = new Image();
        img.onload = () => ctx.drawImage(img, 0, 0, w, h);
        img.src = prev;
      }
    }

    // Trigger a resize the moment the canvas becomes visible / changes size
    if (typeof ResizeObserver !== 'undefined') {
      const ro = new ResizeObserver(() => resize());
      ro.observe(canvas);
    } else {
      window.addEventListener('resize', resize);
    }
    resize();

    let drawing = false;
    let dirty   = false;
    let last    = null;

    // Convert client (viewport) coordinates → canvas content-box coordinates,
    // accounting for any border or padding via getBoundingClientRect() and
    // the canvas's content-box scaling (rect.width vs canvas.clientWidth).
    function pos(evt) {
      const rect   = canvas.getBoundingClientRect();
      const borderL = canvas.clientLeft || 0;
      const borderT = canvas.clientTop  || 0;
      const t       = evt.touches && evt.touches[0];
      const cx      = t ? t.clientX : evt.clientX;
      const cy      = t ? t.clientY : evt.clientY;
      // Map from viewport pixels into canvas-content CSS pixels — needed in
      // case the canvas is CSS-scaled to a size different from rect.width
      // (which happens if a parent applies transforms or the canvas attribute
      // size differs from the rendered size during a resize race).
      const scaleX = rect.width  ? (cssW || rect.width)  / rect.width  : 1;
      const scaleY = rect.height ? (cssH || rect.height) / rect.height : 1;
      return {
        x: (cx - rect.left - borderL) * scaleX,
        y: (cy - rect.top  - borderT) * scaleY,
      };
    }
    function start(e) {
      e.preventDefault();
      drawing = true;
      last    = pos(e);
      // Single-tap dot
      ctx.beginPath();
      ctx.arc(last.x, last.y, 1, 0, Math.PI * 2);
      ctx.fillStyle = ctx.strokeStyle;
      ctx.fill();
      dirty = true;
    }
    function move(e) {
      if (!drawing) return;
      e.preventDefault();
      const p = pos(e);
      ctx.beginPath();
      ctx.moveTo(last.x, last.y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      last  = p;
      dirty = true;
    }
    function end(e) {
      if (!drawing) return;
      if (e) e.preventDefault();
      drawing = false;
      last    = null;
    }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    canvas.addEventListener('mouseup',   end);
    canvas.addEventListener('mouseleave', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove',  move,  { passive: false });
    canvas.addEventListener('touchend',   end);
    canvas.addEventListener('touchcancel', end);

    function clear() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      dirty = false;
    }
    document.getElementById('hessqfeSignatureClear')?.addEventListener('click', clear);

    window.hessqfeSignaturePad = {
      isEmpty:    () => !dirty,
      clear:      clear,
      resize:     resize,
      getDataURL: () => dirty ? canvas.toDataURL('image/png') : '',
    };
  }

  /* ── Init ── */
  document.addEventListener('DOMContentLoaded', () => {
    populateFilters();
    renderTable();
    initSignaturePad();
    initCostAdjustments();

    ['hessqfeFilterBrand', 'hessqfeFilterSystem', 'hessqfeFilterCapacity'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('change', applyFilters);
    });

    document.getElementById('hessqfeFilterSearchBtn')?.addEventListener('click', applyFilters);
    document.getElementById('hessqfeFilterClearBtn') ?.addEventListener('click', clearFilters);
    document.getElementById('hessqfeGoToStep2Btn')   ?.addEventListener('click', goToStep2);
    document.getElementById('hessqfeBackBtn')        ?.addEventListener('click', goToStep1);
    document.getElementById('hessqfeSubmitBtn')      ?.addEventListener('click', submitForm);

    // Auto-populate the Step 2 contact fields from the customer info collected
    // at the top of the form. The Step 2 fields remain editable afterward.
    [
      ['hessqfeFieldCustomerName',    'hessqfeFieldName'],
      ['hessqfeFieldCustomerEmail',   'hessqfeFieldEmail'],
      ['hessqfeFieldCustomerPhone',   'hessqfeFieldPhone'],
      ['hessqfeFieldCustomerAddress', 'hessqfeFieldAddress'],
    ].forEach(([sourceId, targetId]) => {
      const source = document.getElementById(sourceId);
      const target = document.getElementById(targetId);
      if (!source || !target) return;
      source.addEventListener('input', () => { target.value = source.value; });
    });
  });
})();
