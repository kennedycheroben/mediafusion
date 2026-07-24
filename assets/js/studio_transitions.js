/**
 * assets/js/studio_transitions.js
 * Comprehensive Video Transitions registry and workspace controller.
 * Manages category filters, apply, remove, duration, and easing.
 */

(function () {
  'use strict';

  let els = {};
  let currentProjectState = null;

  // Extensible Transitions Registry organized by categories
  const TRANSITIONS_REGISTRY = {
    fade: [
      { id: 'cross_fade', name: 'Cross Fade 🌫️', desc: 'Smooth opacity overlap blend' },
      { id: 'fade_black', name: 'Fade to Black 🕶️', desc: 'Fade out to black, then fade in' },
      { id: 'fade_white', name: 'Fade to White ☁️', desc: 'Flash/fade out to white, then fade in' }
    ],
    dissolve: [
      { id: 'dissolve', name: 'Dissolve 🌫️', desc: 'Gradual cross-dissolve blend' },
      { id: 'film_grain', name: 'Film Grain Dissolve 🎞️', desc: 'Grainy film style noisy dissolve' }
    ],
    blur: [
      { id: 'blur_dissolve', name: 'Blur Dissolve 🌫️', desc: 'Cross-fade with heavy defocus' },
      { id: 'motion_blur', name: 'Motion Blur Transition 🚀', desc: 'Directional fast motion blur' }
    ],
    slide: [
      { id: 'slide_left', name: 'Slide Left ⬅️', desc: 'Slide next clip in from right side' },
      { id: 'slide_right', name: 'Slide Right ➡️', desc: 'Slide next clip in from left side' },
      { id: 'slide_up', name: 'Slide Up ⬆️', desc: 'Slide next clip in from bottom' },
      { id: 'slide_down', name: 'Slide Down ⬇️', desc: 'Slide next clip in from top' }
    ],
    zoom: [
      { id: 'zoom_in', name: 'Zoom In 🔍', desc: 'Incoming clip zooms up to fill screen' },
      { id: 'zoom_out', name: 'Zoom Out 🔎', desc: 'Outgoing clip shrinks into distance' },
      { id: 'zoom_blur', name: 'Zoom Blur 🌪️', desc: 'Scale transition with radial zoom blur' }
    ],
    spin: [
      { id: 'spin_cw', name: 'Spin Clockwise 🔄', desc: 'Fast clockwise spiral rotation' },
      { id: 'spin_ccw', name: 'Spin Counter-CW ↩️', desc: 'Fast counter-clockwise spiral rotation' }
    ],
    wipe: [
      { id: 'wipe_left', name: 'Wipe Left ⬅️', desc: 'Horizontal wipe revealing right side first' },
      { id: 'wipe_right', name: 'Wipe Right ➡️', desc: 'Horizontal wipe revealing left side first' },
      { id: 'wipe_up', name: 'Wipe Up ⬆️', desc: 'Vertical wipe revealing top side first' },
      { id: 'wipe_down', name: 'Wipe Down ⬇️', desc: 'Vertical wipe revealing bottom side first' }
    ],
    split: [
      { id: 'split_h', name: 'Split Horizontal ✂️', desc: 'Split screen opens horizontally from center' },
      { id: 'split_v', name: 'Split Vertical ✂️', desc: 'Split screen opens vertically from center' }
    ],
    mask: [
      { id: 'mask_circle', name: 'Circle Mask ⚪', desc: 'Expanding circular iris mask transition' },
      { id: 'mask_diamond', name: 'Diamond Mask 💎', desc: 'Expanding diamond shape mask transition' }
    ],
    distortion: [
      { id: 'wave_warp', name: 'Wave Warp 🌀', desc: 'Wavy dynamic ripple distortion transition' },
      { id: 'glitch_cut', name: 'Glitch Cut 👾', desc: 'Chromatic color shift digital glitch distortion' }
    ]
  };

  function initDOMRefs() {
    els = {
      catSelect:  document.getElementById('transitionCategorySelect'),
      grid:       document.getElementById('transitionsListGrid'),
      activeArea: document.getElementById('activeClipTransitionArea')
    };
  }

  // ── Render Category Grid ──
  function renderTransitionsGrid() {
    if (!els.grid || !els.catSelect) return;
    const cat = els.catSelect.value;
    const list = TRANSITIONS_REGISTRY[cat] || [];

    els.grid.innerHTML = list.map(trans => `
      <div class="col-6">
        <div class="card p-2 bg-dark text-light border-secondary transition-preset-card" style="cursor:pointer; min-height:85px;" data-id="${trans.id}">
          <div class="small font-weight-bold mb-1" style="font-size:11px;">${trans.name}</div>
          <div class="text-muted mb-2" style="font-size:9px; line-height: 1.1;">${trans.desc}</div>
          <button type="button" class="btn btn-cyber btn-sm py-0 px-2 w-100 btn-apply-transition" data-id="${trans.id}" style="font-size:10px;">+ Apply</button>
        </div>
      </div>
    `).join('');

    // Attach Apply clicks
    els.grid.querySelectorAll('.btn-apply-transition').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const transId = btn.dataset.id;
        applyTransitionToSelected(transId);
      });
    });
  }

  // ── Apply Transition ──
  function applyTransitionToSelected(transId) {
    const store = window.StudioProjectStore;
    const selectedId = window.CapCutEditor?.state?.selectedId || window.CapCutEditor?.State?.selectedId;
    if (!store || !selectedId) {
      alert('Please select an incoming video/image clip on the timeline to place transition at its start.');
      return;
    }

    const st = JSON.parse(JSON.stringify(store.getState()));
    const item = st.items.find(i => i.id === selectedId);
    if (!item) return;

    if (item.type !== 'video' && item.type !== 'image') {
      alert('Transitions can only be applied to video or image clips.');
      return;
    }

    // Find the transition metadata
    let foundTrans = null;
    for (const cat in TRANSITIONS_REGISTRY) {
      const match = TRANSITIONS_REGISTRY[cat].find(t => t.id === transId);
      if (match) {
        foundTrans = match;
        break;
      }
    }

    if (!foundTrans) return;

    // Apply or change transitionIn property
    item.transitionIn = {
      id:       foundTrans.id,
      name:     foundTrans.name,
      duration: item.transitionIn?.duration ?? 0.5,
      easing:   item.transitionIn?.easing ?? 'ease-in-out'
    };

    store.setState(st, true);
    renderActiveTransition(st);
    if (window.StudioTimeline && typeof window.StudioTimeline.render === 'function') {
      window.StudioTimeline.render();
    }
  }

  // ── Render Active Clip Transition Settings ──
  function renderActiveTransition(state) {
    if (!els.activeArea) return;
    const selectedId = window.CapCutEditor?.state?.selectedId || window.CapCutEditor?.State?.selectedId;
    if (!selectedId) {
      els.activeArea.innerHTML = '<div class="text-muted text-center py-3 italic">Select a clip to view transition settings</div>';
      return;
    }

    const item = state.items.find(i => i.id === selectedId);
    if (!item || !item.transitionIn) {
      els.activeArea.innerHTML = '<div class="text-muted text-center py-3 italic">No transition applied to this clip\'s start</div>';
      return;
    }

    const trans = item.transitionIn;

    els.activeArea.innerHTML = `
      <div class="card p-2 bg-dark border-secondary">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="font-weight-bold small text-light">${trans.name}</span>
          <button class="btn btn-sm btn-link text-danger p-0 btn-remove-transition" style="text-decoration:none;" title="Remove Transition"><i class="fa-solid fa-trash-can"></i></button>
        </div>
        
        <!-- Duration -->
        <div class="capcut-control-group mb-2">
          <div class="d-flex justify-content-between">
            <span class="text-muted" style="font-size:10px;">Duration</span>
            <span class="text-muted" style="font-size:10px;" id="lblTransDur">${trans.duration.toFixed(2)}s</span>
          </div>
          <input type="range" class="form-range trans-duration-slider" min="0.1" max="3.0" step="0.05" value="${trans.duration}">
        </div>

        <!-- Easing Selection -->
        <div class="capcut-control-group">
          <label class="text-muted" style="font-size:10px;">Easing Curve</label>
          <select class="form-select form-control-cyber form-select-sm trans-easing-select" style="font-size:10px; padding:2px 5px;">
            <option value="linear" ${trans.easing === 'linear' ? 'selected' : ''}>Linear</option>
            <option value="ease-in-out" ${trans.easing === 'ease-in-out' ? 'selected' : ''}>Ease In Out</option>
            <option value="ease-in" ${trans.easing === 'ease-in' ? 'selected' : ''}>Ease In</option>
            <option value="ease-out" ${trans.easing === 'ease-out' ? 'selected' : ''}>Ease Out</option>
          </select>
        </div>
      </div>
    `;

    // Hook listeners
    const durSlider = els.activeArea.querySelector('.trans-duration-slider');
    const easingSelect = els.activeArea.querySelector('.trans-easing-select');
    const removeBtn = els.activeArea.querySelector('.btn-remove-transition');

    durSlider.addEventListener('input', (e) => {
      const val = parseFloat(e.target.value) || 0.5;
      document.getElementById('lblTransDur').innerText = val.toFixed(2) + 's';
      updateTransitionProperty(t => { t.duration = val; });
    });

    durSlider.addEventListener('change', () => {
      commitStoreHistory();
    });

    easingSelect.addEventListener('change', (e) => {
      const val = e.target.value;
      updateTransitionProperty(t => { t.easing = val; });
      commitStoreHistory();
    });

    removeBtn.addEventListener('click', () => {
      removeTransitionFromClip();
    });
  }

  function updateTransitionProperty(updater) {
    const store = window.StudioProjectStore;
    const selectedId = window.CapCutEditor?.state?.selectedId || window.CapCutEditor?.State?.selectedId;
    if (!store || !selectedId) return;

    const st = store.getState();
    const item = st.items.find(i => i.id === selectedId);
    if (item && item.transitionIn) {
      updater(item.transitionIn);
      store.setState(st, false); // Dynamic update preview, commit history on change/blur
    }
  }

  function commitStoreHistory() {
    const store = window.StudioProjectStore;
    if (store) store.setState(store.getState(), true);
  }

  function removeTransitionFromClip() {
    const store = window.StudioProjectStore;
    const selectedId = window.CapCutEditor?.state?.selectedId || window.CapCutEditor?.State?.selectedId;
    if (!store || !selectedId) return;

    const st = JSON.parse(JSON.stringify(store.getState()));
    const item = st.items.find(i => i.id === selectedId);
    if (item && item.transitionIn) {
      delete item.transitionIn;
      store.setState(st, true);
      renderActiveTransition(st);
      if (window.StudioTimeline && typeof window.StudioTimeline.render === 'function') {
        window.StudioTimeline.render();
      }
    }
  }

  // ── State Change Listener ──
  function onStateChange(state) {
    currentProjectState = state;
    renderActiveTransition(state);
  }

  // ── Initialize ──
  function init() {
    initDOMRefs();
    
    if (els.catSelect) {
      els.catSelect.addEventListener('change', renderTransitionsGrid);
      renderTransitionsGrid();
    }

    const store = window.StudioProjectStore;
    if (store) {
      store.subscribe(onStateChange);
      onStateChange(store.getState());
    }

    console.info('[StudioTransitions] Workspace controller initialized.');
  }

  document.addEventListener('DOMContentLoaded', init);

  // Expose Registry and API for integration testing
  window.StudioTransitions = {
    REGISTRY: TRANSITIONS_REGISTRY,
    apply: applyTransitionToSelected,
    remove: removeTransitionFromClip
  };

})();
