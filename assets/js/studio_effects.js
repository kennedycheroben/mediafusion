/**
 * assets/js/studio_effects.js
 * Comprehensive Extensible Effects Registry and Workspace controller.
 * Manages category filters, apply, remove, intensity, duration, and offsets.
 */

(function () {
  'use strict';

  let els = {};
  let currentProjectState = null;

  // Extensible Effects Registry organized by categories
  const EFFECTS_REGISTRY = {
    trending: [
      { id: 'cyber_bloom', name: 'Cyber Bloom 🔥', desc: 'Glowing futuristic neon dream aesthetic' },
      { id: 'neon_pulse', name: 'Neon Pulse ⚡', desc: 'Pulsating neon light overlay' }
    ],
    basic: [
      { id: 'gaussian_blur', name: 'Gaussian Blur ⚙️', desc: 'Soften details and hide background noise' },
      { id: 'vignette_dark', name: 'Vignette Frame 🔍', desc: 'Darken edges to focus center attention' }
    ],
    retro: [
      { id: 'vintage_sepia', name: 'Vintage Sepia 📻', desc: 'Classic golden brown nostalgia' },
      { id: 'noir_mono', name: 'Film Noir B&W 🎥', desc: 'High-contrast monochrome drama' }
    ],
    cinematic: [
      { id: 'cinematic_anamorphic', name: 'Anamorphic Glow 🎬', desc: 'Wide cinema style horizontal bloom' }
    ],
    glitch: [
      { id: 'rgb_split', name: 'RGB Split Glitch 👾', desc: 'Channel displacement rendering chromatic shift' }
    ],
    light: [
      { id: 'light_leak', name: 'Rainbow Light Leak 🌈', desc: 'Dynamic solar prisms sweep overlay' }
    ],
    lens: [
      { id: 'lens_fisheye', name: 'Fish-eye Vignette 👁️', desc: 'Curved lens boundary perspective' }
    ],
    blur: [
      { id: 'radial_blur', name: 'Radial Zoom Blur 🌫️', desc: 'Fast motion camera zoom blurring' }
    ],
    distortion: [
      { id: 'ripple_wave', name: 'Ripple Water Wave 🌀', desc: 'Undulating water distortion curves' }
    ],
    nature: [
      { id: 'nature_mist', name: 'Morning Mist Fog 🍃', desc: 'Dense white atmospheric low contrast fog' }
    ],
    spark: [
      { id: 'spark_glitter', name: 'Sparkle Glitter ✨', desc: 'Glittering sparks drifting randomly' }
    ],
    love: [
      { id: 'dreamy_love', name: 'Heart Beats Aura ❤️', desc: 'Pulsating pink-red dream halo outline' }
    ],
    motion: [
      { id: 'camera_shake', name: 'Camera Shake 🎥', desc: 'Simulate high intensity hand shake' }
    ]
  };

  function initDOMRefs() {
    els = {
      catSelect:  document.getElementById('effectCategorySelect'),
      grid:       document.getElementById('effectsListGrid'),
      activeArea: document.getElementById('activeClipEffectsArea')
    };
  }

  // ── Render Category Grid ──
  function renderEffectsGrid() {
    if (!els.grid || !els.catSelect) return;
    const cat = els.catSelect.value;
    const list = EFFECTS_REGISTRY[cat] || [];

    els.grid.innerHTML = list.map(eff => `
      <div class="col-6">
        <div class="card p-2 bg-dark text-light border-secondary effect-preset-card" style="cursor:pointer; min-height:85px;" data-id="${eff.id}">
          <div class="small font-weight-bold mb-1" style="font-size:11px;">${eff.name}</div>
          <div class="text-muted mb-2" style="font-size:9px; line-height: 1.1;">${eff.desc}</div>
          <button type="button" class="btn btn-cyber btn-sm py-0 px-2 w-100 btn-add-effect-clip" data-id="${eff.id}" style="font-size:10px;">+ Apply</button>
        </div>
      </div>
    `).join('');

    // Attach Apply clicks
    els.grid.querySelectorAll('.btn-add-effect-clip').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const effectId = btn.dataset.id;
        applyEffectToSelected(effectId);
      });
    });
  }

  // ── Apply Effect ──
  function applyEffectToSelected(effectId) {
    const store = window.StudioProjectStore;
    const selectedId = window.CapCutEditor?.state?.selectedId;
    if (!store || !selectedId) {
      alert('Please select a video/image clip on the timeline first.');
      return;
    }

    const st = JSON.parse(JSON.stringify(store.getState()));
    const item = st.items.find(i => i.id === selectedId);
    if (!item) return;

    if (!Array.isArray(item.effects)) {
      item.effects = [];
    }

    // Locate effect template in registry
    let foundEffect = null;
    for (const cat in EFFECTS_REGISTRY) {
      const match = EFFECTS_REGISTRY[cat].find(e => e.id === effectId);
      if (match) {
        foundEffect = match;
        break;
      }
    }

    if (!foundEffect) return;

    // Build effect object
    const newEffect = {
      id:          foundEffect.id,
      name:        foundEffect.name,
      intensity:   75,
      startOffset: 0.0,
      duration:    item.duration
    };

    item.effects.push(newEffect);
    store.setState(st, true);
    renderActiveClipEffects(st);
  }

  // ── Render Active Clip Effects List ──
  function renderActiveClipEffects(state) {
    if (!els.activeArea) return;
    const selectedId = window.CapCutEditor?.state?.selectedId;
    if (!selectedId) {
      els.activeArea.innerHTML = '<div class="text-muted text-center py-3 italic">Select a clip to view applied effects</div>';
      return;
    }

    const item = state.items.find(i => i.id === selectedId);
    if (!item || !Array.isArray(item.effects) || item.effects.length === 0) {
      els.activeArea.innerHTML = '<div class="text-muted text-center py-3 italic">No active effects applied to this clip</div>';
      return;
    }

    els.activeArea.innerHTML = item.effects.map((eff, idx) => `
      <div class="card p-2 mb-2 bg-dark border-secondary effect-row-item" data-idx="${idx}">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="font-weight-bold small text-light">${eff.name}</span>
          <button class="btn btn-sm btn-link text-danger p-0 btn-delete-effect" data-idx="${idx}" style="text-decoration:none;"><i class="fa-solid fa-trash-can"></i></button>
        </div>
        
        <!-- Intensity -->
        <div class="capcut-control-group mb-1">
          <div class="d-flex justify-content-between">
            <span class="text-muted" style="font-size:10px;">Intensity</span>
            <span class="text-muted" style="font-size:10px;">${eff.intensity}%</span>
          </div>
          <input type="range" class="form-range effect-intensity-slider" data-idx="${idx}" min="0" max="100" value="${eff.intensity}">
        </div>

        <!-- Timings -->
        <div class="row g-1 mt-1">
          <div class="col-6">
            <label class="text-muted" style="font-size:9px;">Start Offset (s)</label>
            <input type="number" step="0.1" min="0" class="form-control form-control-cyber form-control-sm effect-start-input" data-idx="${idx}" value="${eff.startOffset.toFixed(1)}" style="padding:2px 4px; font-size:10px;">
          </div>
          <div class="col-6">
            <label class="text-muted" style="font-size:9px;">Duration (s)</label>
            <input type="number" step="0.1" min="0.1" class="form-control form-control-cyber form-control-sm effect-duration-input" data-idx="${idx}" value="${eff.duration.toFixed(1)}" style="padding:2px 4px; font-size:10px;">
          </div>
        </div>
      </div>
    `).join('');

    // Attach listeners
    els.activeArea.querySelectorAll('.effect-row-item').forEach(row => {
      const idx = parseInt(row.dataset.idx);

      // Intensity Slider
      row.querySelector('.effect-intensity-slider').addEventListener('input', (e) => {
        const val = parseInt(e.target.value) || 0;
        updateEffectProperty(idx, eff => {
          eff.intensity = val;
        });
      });
      row.querySelector('.effect-intensity-slider').addEventListener('change', () => {
        commitStoreHistory();
      });

      // Start Offset Input
      row.querySelector('.effect-start-input').addEventListener('change', (e) => {
        const val = parseFloat(e.target.value) || 0.0;
        updateEffectProperty(idx, eff => {
          eff.startOffset = val;
        });
        commitStoreHistory();
      });

      // Duration Input
      row.querySelector('.effect-duration-input').addEventListener('change', (e) => {
        const val = parseFloat(e.target.value) || 0.1;
        updateEffectProperty(idx, eff => {
          eff.duration = val;
        });
        commitStoreHistory();
      });

      // Delete Effect
      row.querySelector('.btn-delete-effect').addEventListener('click', () => {
        deleteEffectFromClip(idx);
      });
    });
  }

  function updateEffectProperty(idx, updater) {
    const store = window.StudioProjectStore;
    const selectedId = window.CapCutEditor?.state?.selectedId;
    if (!store || !selectedId) return;

    const st = store.getState();
    const item = st.items.find(i => i.id === selectedId);
    if (item && item.effects && item.effects[idx]) {
      updater(item.effects[idx]);
      store.setState(st, false); // Dynamic update preview, commit history on change/blur
    }
  }

  function commitStoreHistory() {
    const store = window.StudioProjectStore;
    if (store) store.setState(store.getState(), true);
  }

  function deleteEffectFromClip(idx) {
    const store = window.StudioProjectStore;
    const selectedId = window.CapCutEditor?.state?.selectedId;
    if (!store || !selectedId) return;

    const st = JSON.parse(JSON.stringify(store.getState()));
    const item = st.items.find(i => i.id === selectedId);
    if (item && item.effects) {
      item.effects.splice(idx, 1);
      store.setState(st, true);
      renderActiveClipEffects(st);
    }
  }

  // ── State Change Listener ──
  function onStateChange(state) {
    currentProjectState = state;
    renderActiveClipEffects(state);
  }

  // ── Initialize ──
  function init() {
    initDOMRefs();
    
    if (els.catSelect) {
      els.catSelect.addEventListener('change', renderEffectsGrid);
      renderEffectsGrid();
    }

    const store = window.StudioProjectStore;
    if (store) {
      store.subscribe(onStateChange);
      onStateChange(store.getState());
    }

    console.info('[StudioEffects] Workspace controller initialized.');
  }

  document.addEventListener('DOMContentLoaded', init);

})();
