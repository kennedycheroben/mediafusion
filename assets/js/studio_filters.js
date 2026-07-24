/**
 * assets/js/studio_filters.js
 * Comprehensive Filters and Color Adjustments workspace controller.
 * Integrates presets, categories, collapsible panels, RGB spline curves, and HSL slider adjustments.
 */

(function () {
  'use strict';

  let els = {};
  let currentProjectState = null;
  let activeCurveChannel = 'rgb'; // 'rgb', 'r', 'g', 'b'
  let activeHslColor = 'red'; // 'red', 'orange', 'yellow', 'green', 'cyan', 'blue', 'magenta'
  let curvePoints = {
    rgb: [[0, 0], [255, 255]],
    r:   [[0, 0], [255, 255]],
    g:   [[0, 0], [255, 255]],
    b:   [[0, 0], [255, 255]]
  };
  let draggedPointIndex = -1;

  const PRESET_FILTERS = [
    { id: 'cinematic_1', name: 'Warm Sunset Gold', desc: 'Golden hour cinematic LUT', cat: 'cinematic', adjustments: { brightness: 105, contrast: 110, saturation: 120, exposure: 100, temperature: 15, tint: 5 } },
    { id: 'retro_1', name: 'VHS Glitch Tape', desc: '80s CRT analog feel', cat: 'retro', adjustments: { brightness: 95, contrast: 90, saturation: 85, exposure: 95, temperature: -5, tint: 10 } },
    { id: 'mono_1', name: 'B&W Film Noir', desc: 'High contrast monochrome', cat: 'monochrome', adjustments: { brightness: 100, contrast: 140, saturation: 0, exposure: 100, temperature: 0, tint: 0 } },
    { id: 'cyber_1', name: 'Cyberpunk Magenta', desc: 'Neon purple & teal grade', cat: 'cyberpunk', adjustments: { brightness: 105, contrast: 115, saturation: 130, exposure: 100, temperature: -20, tint: 25 } }
  ];

  function initDOMRefs() {
    els = {
      grid:            document.getElementById('filtersListGrid'),
      intensitySlider: document.getElementById('sliderFilterIntensity'),
      intensityLabel:  document.getElementById('lblFilterIntensity'),
      resetBtn:        document.getElementById('btnResetFilters'),
      catBtns:         document.querySelectorAll('.filter-cat-btn'),
      
      // Collapsible headers
      hdrColor:        document.getElementById('hdrColorAdjustments'),
      bodyColor:       document.getElementById('bodyColorAdjustments'),
      icoColor:        document.getElementById('icoColorAdjustments'),
      
      hdrCurves:       document.getElementById('hdrCurves'),
      bodyCurves:      document.getElementById('bodyCurves'),
      icoCurves:       document.getElementById('icoCurves'),
      
      hdrHsl:          document.getElementById('hdrHSL'),
      bodyHsl:         document.getElementById('bodyHSL'),
      icoHsl:          document.getElementById('icoHSL'),

      // Canvas
      curvesCanvas:    document.getElementById('curveAdjustmentCanvas'),
      curvesCtx:       document.getElementById('curveAdjustmentCanvas')?.getContext('2d'),
      channelBtns:     document.querySelectorAll('.curve-channel-btn'),

      // HSL elements
      hslTabs:         document.querySelectorAll('.hsl-color-tab'),
      hslSliders:      document.querySelectorAll('.hsl-slider'),
      
      // Adj sliders
      adjSliders:      document.querySelectorAll('.adj-slider')
    };
  }

  // ── Collapsible Panel Toggles ──
  function setupCollapsiblePanels() {
    const bindToggle = (hdr, body, ico) => {
      if (!hdr || !body) return;
      hdr.addEventListener('click', () => {
        const isHidden = body.classList.contains('d-none');
        if (isHidden) {
          body.classList.remove('d-none');
          if (ico) ico.className = 'fa-solid fa-chevron-up';
          if (hdr.id === 'hdrCurves') drawCurves();
        } else {
          body.classList.add('d-none');
          if (ico) ico.className = 'fa-solid fa-chevron-down';
        }
      });
    };

    bindToggle(els.hdrColor, els.bodyColor, els.icoColor);
    bindToggle(els.hdrCurves, els.bodyCurves, els.icoCurves);
    bindToggle(els.hdrHsl, els.bodyHsl, els.icoHsl);
  }

  // ── Filter Categories Rendering ──
  function renderFiltersGrid(activeCat = 'all') {
    if (!els.grid) return;
    
    const filtered = activeCat === 'all' 
      ? PRESET_FILTERS 
      : PRESET_FILTERS.filter(f => f.cat === activeCat || activeCat === 'all');

    els.grid.innerHTML = filtered.map(f => `
      <div class="col-6">
        <div class="card p-2 bg-dark text-light border-secondary filter-preset-card" style="cursor:pointer;" data-id="${f.id}">
          <div class="small font-weight-bold mb-1">${f.name}</div>
          <div class="text-muted" style="font-size:10px; line-height: 1.1;">${f.desc}</div>
        </div>
      </div>
    `).join('');

    // Attach click events to cards
    els.grid.querySelectorAll('.filter-preset-card').forEach(card => {
      card.addEventListener('click', () => {
        const presetId = card.dataset.id;
        applyPresetFilter(presetId);
      });
    });
  }

  function setupCategoryButtons() {
    els.catBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        els.catBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        renderFiltersGrid(btn.dataset.cat);
      });
    });
  }

  // ── Apply Preset Filter ──
  function applyPresetFilter(presetId) {
    const store = window.StudioProjectStore;
    if (!store) return;

    const selectedId = window.CapCutEditor?.state?.selectedId;
    if (!selectedId) {
      alert('Please select a video/image clip on the timeline first.');
      return;
    }

    const preset = PRESET_FILTERS.find(f => f.id === presetId);
    if (!preset) return;

    const st = JSON.parse(JSON.stringify(store.getState()));
    const item = st.items.find(i => i.id === selectedId);
    if (!item) return;

    // Apply adjustments
    item.filters = {
      ...item.filters,
      ...preset.adjustments
    };

    store.setState(st, true);
    syncSlidersToSelected(st);
  }

  // ── Sync UI sliders to active state values ──
  function syncSlidersToSelected(state) {
    const selectedId = window.CapCutEditor?.state?.selectedId;
    if (!selectedId) return;

    const item = state.items.find(i => i.id === selectedId);
    if (!item) return;

    const f = item.filters || {};
    
    // Update color sliders values
    els.adjSliders.forEach(slider => {
      const prop = slider.dataset.prop;
      let val = 0;
      if (prop === 'brightness') val = f.brightness ?? 100;
      else if (prop === 'contrast') val = f.contrast ?? 100;
      else if (prop === 'saturation') val = f.saturation ?? 100;
      else if (prop === 'exposure') val = f.exposure ?? 100;
      else if (prop === 'temperature') val = f.temperature ?? 0;
      else if (prop === 'tint') val = f.tint ?? 0;
      else if (prop === 'vibrance') val = f.vibrance ?? 0;
      else if (prop === 'highlights') val = f.highlights ?? 0;
      else if (prop === 'shadows') val = f.shadows ?? 0;
      else if (prop === 'sharpness') val = item.colorAdjustment?.sharpness ?? 0;
      
      slider.value = val;
      updateSliderLabel(prop, val);
    });

    // Sync HSL parameters
    if (item.colorAdjustment?.hsl?.[activeHslColor]) {
      const hslVal = item.colorAdjustment.hsl[activeHslColor];
      els.hslSliders.forEach(slider => {
        const channel = slider.dataset.hsl; // 'h', 's', 'l'
        slider.value = hslVal[channel] ?? 0;
        updateHslLabel(channel, slider.value);
      });
    }

    // Sync Curves points
    if (item.colorAdjustment?.curves) {
      curvePoints = JSON.parse(JSON.stringify(item.colorAdjustment.curves));
    } else {
      curvePoints = {
        rgb: [[0, 0], [255, 255]],
        r:   [[0, 0], [255, 255]],
        g:   [[0, 0], [255, 255]],
        b:   [[0, 0], [255, 255]]
      };
    }
    drawCurves();
  }

  function updateSliderLabel(prop, val) {
    const suffix = (prop === 'highlights' || prop === 'shadows' || prop === 'temperature' || prop === 'tint' || prop === 'sharpness') ? '' : '%';
    const labelIdMap = {
      brightness: 'valAdjBrightness',
      contrast:   'valAdjContrast',
      saturation: 'valAdjSaturation',
      exposure:   'valAdjExposure',
      highlights: 'valAdjHighlights',
      shadows:    'valAdjShadows',
      temperature:'valAdjTemp',
      tint:       'valAdjTint',
      sharpness:  'valAdjSharpness',
      vibrance:   'valAdjVibrance'
    };
    const lbl = document.getElementById(labelIdMap[prop]);
    if (lbl) lbl.textContent = `${val}${suffix}`;
  }

  function updateHslLabel(channel, val) {
    if (channel === 'h') {
      const lbl = document.getElementById('valHslHue');
      if (lbl) lbl.textContent = val;
    } else if (channel === 's') {
      const lbl = document.getElementById('valHslSat');
      if (lbl) lbl.textContent = val;
    } else if (channel === 'l') {
      const lbl = document.getElementById('valHslLum');
      if (lbl) lbl.textContent = val;
    }
  }

  // ── Color Slider Change Handlers ──
  function setupColorSliders() {
    els.adjSliders.forEach(slider => {
      slider.addEventListener('input', (e) => {
        const prop = slider.dataset.prop;
        const val = parseInt(e.target.value) || 0;
        updateSliderLabel(prop, val);

        const store = window.StudioProjectStore;
        const selectedId = window.CapCutEditor?.state?.selectedId;
        if (!store || !selectedId || !window.CapCutEditor?.updateNleItemProperty) return;

        const st = store.getState();
        const item = st.items.find(i => i.id === selectedId);
        if (item) {
          window.CapCutEditor.updateNleItemProperty(item, (target) => {
            if (prop === 'sharpness') {
              if (!target.colorAdjustment) target.colorAdjustment = {};
              target.colorAdjustment.sharpness = val;
            } else {
              if (!target.filters) target.filters = {};
              target.filters[prop] = val;
            }
          }, false);
        }
      });

      // Push history undo/redo on touch/drag end
      slider.addEventListener('change', () => {
        const store = window.StudioProjectStore;
        if (store) store.setState(store.getState(), true);
      });
    });
  }

  // ── Reset All Adjustments ──
  function setupResetButton() {
    if (!els.resetBtn) return;
    els.resetBtn.addEventListener('click', () => {
      const store = window.StudioProjectStore;
      const selectedId = window.CapCutEditor?.state?.selectedId;
      if (!store || !selectedId) return;

      const st = JSON.parse(JSON.stringify(store.getState()));
      const item = st.items.find(i => i.id === selectedId);
      if (item) {
        item.filters = { saturation: 100, contrast: 100, brightness: 100, exposure: 100, temperature: 0, tint: 0, highlights: 0, shadows: 0, vibrance: 0 };
        item.colorAdjustment = {
          sharpness: 0,
          hsl: {
            red: { h:0, s:0, l:0 },
            orange: { h:0, s:0, l:0 },
            yellow: { h:0, s:0, l:0 },
            green: { h:0, s:0, l:0 },
            cyan: { h:0, s:0, l:0 },
            blue: { h:0, s:0, l:0 },
            magenta: { h:0, s:0, l:0 }
          },
          curves: {
            rgb: [[0, 0], [255, 255]],
            r:   [[0, 0], [255, 255]],
            g:   [[0, 0], [255, 255]],
            b:   [[0, 0], [255, 255]]
          }
        };
        store.setState(st, true);
        syncSlidersToSelected(st);
      }
    });
  }

  // ── HSL Sliders Setup ──
  function setupHslTabsAndSliders() {
    els.hslTabs.forEach(tab => {
      tab.addEventListener('click', () => {
        els.hslTabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        activeHslColor = tab.dataset.color;
        
        // Sync sliders
        const store = window.StudioProjectStore;
        if (store) syncSlidersToSelected(store.getState());
      });
    });

    els.hslSliders.forEach(slider => {
      slider.addEventListener('input', (e) => {
        const channel = slider.dataset.hsl; // 'h', 's', 'l'
        const val = parseInt(e.target.value) || 0;
        updateHslLabel(channel, val);

        const store = window.StudioProjectStore;
        const selectedId = window.CapCutEditor?.state?.selectedId;
        if (!store || !selectedId) return;

        const st = store.getState();
        const item = st.items.find(i => i.id === selectedId);
        if (item) {
          if (!item.colorAdjustment) item.colorAdjustment = {};
          if (!item.colorAdjustment.hsl) item.colorAdjustment.hsl = {};
          if (!item.colorAdjustment.hsl[activeHslColor]) {
            item.colorAdjustment.hsl[activeHslColor] = { h: 0, s: 0, l: 0 };
          }
          item.colorAdjustment.hsl[activeHslColor][channel] = val;
          store.setState(st, false);
        }
      });

      slider.addEventListener('change', () => {
        const store = window.StudioProjectStore;
        if (store) store.setState(store.getState(), true);
      });
    });
  }

  // ── Curves Spline drawing ──
  function drawCurves() {
    const canvas = els.curvesCanvas;
    const ctx = els.curvesCtx;
    if (!canvas || !ctx) return;

    const w = canvas.width;
    const h = canvas.height;
    ctx.clearRect(0, 0, w, h);

    // 1. Draw Grid
    ctx.strokeStyle = '#2d2d2d';
    ctx.lineWidth = 1;
    // vertical grid lines
    for (let x = 40; x < w; x += 40) {
      ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, h); ctx.stroke();
    }
    // horizontal grid lines
    for (let y = 40; y < h; y += 40) {
      ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(w, y); ctx.stroke();
    }

    // Diagonal reference line
    ctx.strokeStyle = '#444';
    ctx.setLineDash([2, 2]);
    ctx.beginPath(); ctx.moveTo(0, h); ctx.lineTo(w, 0); ctx.stroke();
    ctx.setLineDash([]);

    // 2. Plot Spline Path
    const pts = curvePoints[activeCurveChannel] || [[0, 0], [255, 255]];
    const sorted = [...pts].sort((a, b) => a[0] - b[0]);

    // Choose line color
    const colorsMap = { rgb: '#ffffff', r: '#ff4444', g: '#44ff44', b: '#4444ff' };
    ctx.strokeStyle = colorsMap[activeCurveChannel];
    ctx.lineWidth = 2;

    ctx.beginPath();
    sorted.forEach((pt, idx) => {
      // Map 0-255 values to canvas width/height (y is inverted!)
      const cx = (pt[0] / 255) * w;
      const cy = h - (pt[1] / 255) * h;
      if (idx === 0) ctx.moveTo(cx, cy);
      else ctx.lineTo(cx, cy);
    });
    ctx.stroke();

    // 3. Draw Points
    sorted.forEach(pt => {
      const cx = (pt[0] / 255) * w;
      const cy = h - (pt[1] / 255) * h;
      ctx.fillStyle = colorsMap[activeCurveChannel];
      ctx.beginPath();
      ctx.arc(cx, cy, 5, 0, 2 * Math.PI);
      ctx.fill();
      ctx.strokeStyle = '#000000';
      ctx.lineWidth = 1.5;
      ctx.stroke();
    });
  }

  // ── Curves Canvas interactions ──
  function setupCurvesCanvas() {
    const canvas = els.curvesCanvas;
    if (!canvas) return;

    // Channel selection tabs
    els.channelBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        els.channelBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeCurveChannel = btn.dataset.channel;
        drawCurves();
      });
    });

    // Helper: get canvas coordinates
    const getCoords = (e) => {
      const rect = canvas.getBoundingClientRect();
      const x = Math.round(((e.clientX - rect.left) / rect.width) * 255);
      const y = Math.round((1.0 - (e.clientY - rect.top) / rect.height) * 255);
      return [Math.max(0, Math.min(255, x)), Math.max(0, Math.min(255, y))];
    };

    // MouseDown: click or drag point
    canvas.addEventListener('mousedown', (e) => {
      const [x, y] = getCoords(e);
      const pts = curvePoints[activeCurveChannel];

      // Find closest point within radius 15
      let closeIdx = -1;
      let minDist = 20;
      pts.forEach((pt, idx) => {
        const dist = Math.sqrt((pt[0] - x)**2 + (pt[1] - y)**2);
        if (dist < minDist) {
          minDist = dist;
          closeIdx = idx;
        }
      });

      if (closeIdx !== -1) {
        draggedPointIndex = closeIdx;
      }
    });

    // MouseMove: drag points
    canvas.addEventListener('mousemove', (e) => {
      if (draggedPointIndex === -1) return;
      const [x, y] = getCoords(e);
      const pts = curvePoints[activeCurveChannel];

      // Endpoint constraints
      if (draggedPointIndex === 0) {
        pts[0][1] = y; // left boundary keeps x=0
      } else if (draggedPointIndex === pts.length - 1) {
        pts[pts.length - 1][1] = y; // right boundary keeps x=255
      } else {
        pts[draggedPointIndex][0] = x;
        pts[draggedPointIndex][1] = y;
      }

      drawCurves();
      updateCurvesStateInStore(false);
    });

    // MouseUp: stop dragging & push undo state
    canvas.addEventListener('mouseup', () => {
      if (draggedPointIndex !== -1) {
        draggedPointIndex = -1;
        updateCurvesStateInStore(true);
      }
    });

    // DoubleClick: add/remove point
    canvas.addEventListener('dblclick', (e) => {
      const [x, y] = getCoords(e);
      const pts = curvePoints[activeCurveChannel];

      // Find if clicked on existing point to delete it (except boundaries)
      let closeIdx = -1;
      pts.forEach((pt, idx) => {
        const dist = Math.sqrt((pt[0] - x)**2 + (pt[1] - y)**2);
        if (dist < 15) closeIdx = idx;
      });

      if (closeIdx !== -1) {
        if (closeIdx > 0 && closeIdx < pts.length - 1) {
          pts.splice(closeIdx, 1);
        }
      } else {
        // Add new point
        pts.push([x, y]);
      }
      
      drawCurves();
      updateCurvesStateInStore(true);
    });
  }

  function updateCurvesStateInStore(pushUndo = false) {
    const store = window.StudioProjectStore;
    const selectedId = window.CapCutEditor?.state?.selectedId;
    if (!store || !selectedId) return;

    const st = store.getState();
    const item = st.items.find(i => i.id === selectedId);
    if (item) {
      if (!item.colorAdjustment) item.colorAdjustment = {};
      item.colorAdjustment.curves = JSON.parse(JSON.stringify(curvePoints));
      store.setState(st, pushUndo);
    }
  }

  // ── State Change Listener ──
  function onStateChange(state) {
    currentProjectState = state;
    syncSlidersToSelected(state);
  }

  // ── Initialize ──
  function init() {
    initDOMRefs();

    setupCollapsiblePanels();
    setupCategoryButtons();
    renderFiltersGrid('all');
    setupColorSliders();
    setupResetButton();
    setupHslTabsAndSliders();
    setupCurvesCanvas();

    const store = window.StudioProjectStore;
    if (store) {
      store.subscribe(onStateChange);
      onStateChange(store.getState());
    }

    console.info('[StudioFilters] Controller initialized.');
  }

  document.addEventListener('DOMContentLoaded', init);

})();
