/**
 * assets/js/studio_elements.js
 * Comprehensive Elements Workspace controller for MediaFusion Studio.
 * Manages library asset grids, add element to timeline overlays track, and category filtering.
 */

(function () {
  'use strict';

  let els = {};
  let currentProjectState = null;

  // Elements Library Assets Registry
  const ELEMENTS_REGISTRY = {
    stickers: [
      { id: 'pointing_hand', name: 'Pointing Hand 👆', url: 'assets/stickers/pointing_hand.png', desc: 'Sleek neon hand pointer' },
      { id: 'subscribe_bell', name: 'Subscribe Bell 🔔', url: 'assets/stickers/subscribe_bell.png', desc: 'Call to action subscribe prompt' },
      { id: 'fire_glow', name: 'Fire Glow 🔥', url: 'assets/stickers/fire_glow.png', desc: 'Glowing social fire overlay' },
      { id: 'explosion', name: 'Explosion Pop 💥', url: 'assets/stickers/explosion.png', desc: 'Comic book bang pop bubble' },
      { id: 'like_button', name: 'Like Button 👍', url: 'assets/stickers/like_button.png', desc: 'Thumps up facebook/youtube like' }
    ],
    emojis: [
      { id: 'emoji_fire', name: 'Fire', emoji: '🔥' },
      { id: 'emoji_bell', name: 'Bell', emoji: '🔔' },
      { id: 'emoji_heart', name: 'Heart', emoji: '❤️' },
      { id: 'emoji_laugh', name: 'Laugh', emoji: '😂' },
      { id: 'emoji_thumbs', name: 'Like', emoji: '👍' },
      { id: 'emoji_party', name: 'Party', emoji: '🎉' },
      { id: 'emoji_rocket', name: 'Rocket', emoji: '🚀' },
      { id: 'emoji_laptop', name: 'Laptop', emoji: '💻' },
      { id: 'emoji_bulb', name: 'Bulb', emoji: '💡' },
      { id: 'emoji_crown', name: 'Crown', emoji: '👑' }
    ],
    shapes: [
      { id: 'shape_circle', name: 'Circle ⚪', shape: 'circle', color: '#ec4899', strokeColor: '#ffffff', strokeWidth: 2 },
      { id: 'shape_rect', name: 'Rectangle ⬜', shape: 'rect', color: '#3b82f6', strokeColor: '#ffffff', strokeWidth: 2 },
      { id: 'shape_triangle', name: 'Triangle 🔺', shape: 'triangle', color: '#10b981', strokeColor: '#ffffff', strokeWidth: 2 },
      { id: 'shape_star', name: 'Star ⭐', shape: 'star', color: '#eab308', strokeColor: '#ffffff', strokeWidth: 2 },
      { id: 'shape_arrow', name: 'Arrow ➡️', shape: 'arrow', color: '#ef4444', strokeColor: '#ffffff', strokeWidth: 2 }
    ],
    graphics: [
      { id: 'vector_badge', name: 'Vector Badge 🛡️', url: 'assets/graphics/vector_badge.png', desc: 'Retro vector security shield badge' },
      { id: 'custom_banner', name: 'Custom Banner 🏷️', url: 'assets/graphics/custom_banner.png', desc: 'Flat vector overlay title banner' }
    ],
    animated: [
      { id: 'confetti_sparkles', name: 'Confetti Sparkles ✨', url: 'assets/animations/confetti_sparkles.gif', animationClass: 'pulse', desc: 'Pulsing color sparkles animation' },
      { id: 'neon_circle_pulse', name: 'Neon Circle Pulse 🌀', url: 'assets/animations/neon_circle_pulse.gif', animationClass: 'spin', desc: 'Spinning neon circular energy ring' }
    ]
  };

  function initDOMRefs() {
    els = {
      tabSelect: document.getElementById('elementsCategorySelect'),
      grid:      document.getElementById('elementsListGrid')
    };
  }

  // ── Render Library Grid ──
  function renderElementsGrid() {
    if (!els.grid || !els.tabSelect) return;
    const cat = els.tabSelect.value;
    const list = ELEMENTS_REGISTRY[cat] || [];

    els.grid.innerHTML = list.map(item => {
      let previewHtml = '';
      if (cat === 'emojis') {
        previewHtml = `<div style="font-size:32px; text-align:center;">${item.emoji}</div>`;
      } else if (cat === 'shapes') {
        previewHtml = `<div style="height:40px; display:flex; align-items:center; justify-content:center;">${renderShapePreviewSVG(item)}</div>`;
      } else {
        // Stickers/Graphics/Animated
        previewHtml = `<div style="height:40px; display:flex; align-items:center; justify-content:center;"><img src="${item.url}" style="max-height:100%; max-width:100%; object-fit:contain;"></div>`;
      }

      return `
        <div class="col-6 mb-2">
          <div class="card p-2 bg-dark text-light border-secondary element-preset-card text-center" style="cursor:pointer; min-height:115px;" data-id="${item.id}" data-cat="${cat}">
            <div class="mb-2">${previewHtml}</div>
            <div class="small font-weight-bold mb-1" style="font-size:10px; text-overflow:ellipsis; overflow:hidden; white-space:nowrap;">${item.name}</div>
            <button type="button" class="btn btn-cyber btn-sm py-0 px-2 w-100 btn-add-element-timeline" data-id="${item.id}" data-cat="${cat}" style="font-size:10px;">+ Add</button>
          </div>
        </div>
      `;
    }).join('');

    // Attach Add clicks
    els.grid.querySelectorAll('.btn-add-element-timeline').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const id = btn.dataset.id;
        const category = btn.dataset.cat;
        addElementToTimeline(category, id);
      });
    });
  }

  function renderShapePreviewSVG(item) {
    const strokeW = item.strokeWidth;
    const fill = item.color;
    const stroke = item.strokeColor;
    if (item.shape === 'circle') {
      return `<svg viewBox="0 0 100 100" style="width:36px; height:36px;"><circle cx="50" cy="50" r="40" fill="${fill}" stroke="${stroke}" stroke-width="${strokeW * 3}" /></svg>`;
    }
    if (item.shape === 'rect') {
      return `<svg viewBox="0 0 100 100" style="width:36px; height:36px;"><rect x="10" y="10" width="80" height="80" fill="${fill}" stroke="${stroke}" stroke-width="${strokeW * 3}" /></svg>`;
    }
    if (item.shape === 'triangle') {
      return `<svg viewBox="0 0 100 100" style="width:36px; height:36px;"><polygon points="50,10 90,90 10,90" fill="${fill}" stroke="${stroke}" stroke-width="${strokeW * 3}" /></svg>`;
    }
    if (item.shape === 'star') {
      return `<svg viewBox="0 0 100 100" style="width:36px; height:36px;"><polygon points="50,10 62,42 96,42 68,62 78,94 50,74 22,94 32,62 4,42 38,42" fill="${fill}" stroke="${stroke}" stroke-width="${strokeW * 3}" /></svg>`;
    }
    if (item.shape === 'arrow') {
      return `<svg viewBox="0 0 100 100" style="width:36px; height:36px;"><polygon points="10,40 55,40 55,20 90,50 55,80 55,60 10,60" fill="${fill}" stroke="${stroke}" stroke-width="${strokeW * 3}" /></svg>`;
    }
    return '';
  }

  // ── Add Element to Timeline ──
  function addElementToTimeline(cat, assetId) {
    const store = window.StudioProjectStore;
    if (!store) return;

    const list = ELEMENTS_REGISTRY[cat] || [];
    const asset = list.find(a => a.id === assetId);
    if (!asset) return;

    const st = JSON.parse(JSON.stringify(store.getState()));
    const playheadTime = window.PreviewEngine?.currentTime ?? 0.0;

    // Resolve or create text/overlay track
    let track = st.tracks.find(t => t.type === 'text' || t.type === 'overlay');
    if (!track) {
      track = { id: 'track_elements_overlay', type: 'text', name: 'Elements Overlay', muted: false, locked: false, visible: true, zIndex: 10 };
      st.tracks.push(track);
    }

    const duration = 4.0;
    const end = Math.min(st.projectSettings.duration || 15.0, playheadTime + duration);
    const clipDur = Math.max(1.0, end - playheadTime);

    // Build the NLE element item properties
    const newElementItem = {
      id: 'element_' + Date.now() + '_' + Math.random().toString(36).substring(2, 6),
      trackId: track.id,
      type: 'sticker', // reuse sticker type to hook NLE timeline behaviors automatically
      name: asset.name,
      start: playheadTime,
      duration: clipDur,
      elementType: cat.replace(/s$/, ''), // 'sticker', 'emoji', 'shape', 'graphic', 'animated'
      position: { x: 50, y: 50 },
      scale: { x: 1.0, y: 1.0 },
      rotation: 0,
      opacity: 1.0,
      style: {
        color: asset.color || '#ec4899',
        strokeColor: asset.strokeColor || '#ffffff',
        strokeWidth: asset.strokeWidth || 2,
        emoji: asset.emoji || '',
        url: asset.url || '',
        shape: asset.shape || '',
        animationClass: asset.animationClass || ''
      },
      animations: {
        in: 'fade',
        out: 'fade',
        combo: ''
      },
      keyframes: []
    };

    st.items.push(newElementItem);
    store.setState(st, true);

    // Auto-select in CapCut Editor to open properties inspector instantly
    if (window.CapCutEditor?.selectElement) {
      window.CapCutEditor.selectElement(newElementItem.id, 'sticker');
    } else if (window.CapCutEditor?.State) {
      window.CapCutEditor.State.selectedId = newElementItem.id;
      window.CapCutEditor.State.selectedType = 'sticker';
      window.CapCutEditor.renderInspector();
    }

    if (window.StudioTimeline && typeof window.StudioTimeline.render === 'function') {
      window.StudioTimeline.render();
    }
  }

  // ── State Change Listener ──
  function onStateChange(state) {
    currentProjectState = state;
  }

  // ── Initialize ──
  function init() {
    initDOMRefs();
    
    if (els.tabSelect) {
      els.tabSelect.addEventListener('change', renderElementsGrid);
      renderElementsGrid();
    }

    const store = window.StudioProjectStore;
    if (store) {
      store.subscribe(onStateChange);
      onStateChange(store.getState());
    }

    console.info('[StudioElements] Workspace controller initialized.');
  }

  document.addEventListener('DOMContentLoaded', init);

  // Expose Registry and API for integration testing
  window.StudioElements = {
    REGISTRY: ELEMENTS_REGISTRY,
    add: addElementToTimeline
  };

})();
