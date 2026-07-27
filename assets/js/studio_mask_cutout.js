/**
 * assets/js/studio_mask_cutout.js
 * Controller for the Mask & Cutout workspace.
 * Integrates shape adjustments, real-time chroma-key, and backend AI cutout handlers.
 */

(function () {
  'use strict';

  let els = {};
  let currentProjectState = null;

  function initDOMRefs() {
    els = {
      maskTypeSelect:        document.getElementById('maskTypeSelect'),
      sliderMaskX:          document.getElementById('sliderMaskX'),
      lblMaskX:             document.getElementById('lblMaskX'),
      sliderMaskY:          document.getElementById('sliderMaskY'),
      lblMaskY:             document.getElementById('lblMaskY'),
      sliderMaskWidth:      document.getElementById('sliderMaskWidth'),
      lblMaskWidth:         document.getElementById('lblMaskWidth'),
      sliderMaskHeight:     document.getElementById('sliderMaskHeight'),
      lblMaskHeight:        document.getElementById('lblMaskHeight'),
      sliderMaskRotation:   document.getElementById('sliderMaskRotation'),
      lblMaskRotation:      document.getElementById('lblMaskRotation'),
      sliderMaskFeather:    document.getElementById('sliderMaskFeather'),
      lblMaskFeather:       document.getElementById('lblMaskFeather'),
      chkMaskInvert:        document.getElementById('chkMaskInvert'),

      // Cutout elements
      btnAutoBgRemove:      document.getElementById('btnAutoBgRemove'),
      btnAiSubjectCutout:   document.getElementById('btnAiSubjectCutout'),
      cutoutConfigError:    document.getElementById('cutoutConfigError'),

      // Chroma Key elements
      chkChromaEnabled:     document.getElementById('chkChromaEnabled'),
      colorChromaKey:       document.getElementById('colorChromaKey'),
      sliderChromaSim:      document.getElementById('sliderChromaSim'),
      lblChromaSim:         document.getElementById('lblChromaSim'),
      sliderChromaSmooth:   document.getElementById('sliderChromaSmooth'),
      lblChromaSmooth:      document.getElementById('lblChromaSmooth')
    };
  }

  function getSelectedItem() {
    const editor = window.CapCutEditor;
    if (!editor || !editor.State || !editor.State.selectedId) return null;
    const store = window.StudioProjectStore;
    if (!store) return null;
    return store.getState().items.find(i => i.id === editor.State.selectedId);
  }

  function updateActiveMaskProperty(updaterFn) {
    const item = getSelectedItem();
    if (!item) return;
    const editor = window.CapCutEditor;
    if (editor && typeof editor.updateNleItemProperty === 'function') {
      editor.updateNleItemProperty(item, (target) => {
        if (!target.mask) {
          target.mask = {
            type: 'none',
            x: 50,
            y: 50,
            width: 30,
            height: 30,
            rotation: 0,
            feather: 0,
            invert: false
          };
        }
        updaterFn(target.mask);
      }, true);
    }
  }

  function updateActiveChromaProperty(updaterFn) {
    const item = getSelectedItem();
    if (!item) return;
    const editor = window.CapCutEditor;
    if (editor && typeof editor.updateNleItemProperty === 'function') {
      editor.updateNleItemProperty(item, (target) => {
        if (!target.chromaKey) {
          target.chromaKey = {
            enabled: false,
            color: '#00ff00',
            similarity: 30,
            smoothness: 10
          };
        }
        updaterFn(target.chromaKey);
      }, true);
    }
  }

  function syncWorkspaceUI(item) {
    if (!item) return;

    // Sync Mask controls
    const mask = item.mask || { type: 'none', x: 50, y: 50, width: 30, height: 30, rotation: 0, feather: 0, invert: false };
    if (els.maskTypeSelect) els.maskTypeSelect.value = mask.type;
    if (els.sliderMaskX) {
      els.sliderMaskX.value = mask.x;
      if (els.lblMaskX) els.lblMaskX.textContent = `${Math.round(mask.x)}%`;
    }
    if (els.sliderMaskY) {
      els.sliderMaskY.value = mask.y;
      if (els.lblMaskY) els.lblMaskY.textContent = `${Math.round(mask.y)}%`;
    }
    if (els.sliderMaskWidth) {
      els.sliderMaskWidth.value = mask.width;
      if (els.lblMaskWidth) els.lblMaskWidth.textContent = `${Math.round(mask.width)}%`;
    }
    if (els.sliderMaskHeight) {
      els.sliderMaskHeight.value = mask.height;
      if (els.lblMaskHeight) els.lblMaskHeight.textContent = `${Math.round(mask.height)}%`;
    }
    if (els.sliderMaskRotation) {
      els.sliderMaskRotation.value = mask.rotation;
      if (els.lblMaskRotation) els.lblMaskRotation.textContent = `${Math.round(mask.rotation)}°`;
    }
    if (els.sliderMaskFeather) {
      els.sliderMaskFeather.value = mask.feather;
      if (els.lblMaskFeather) els.lblMaskFeather.textContent = `${Math.round(mask.feather)}`;
    }
    if (els.chkMaskInvert) els.chkMaskInvert.checked = !!mask.invert;

    // Sync Chroma Key controls
    const chroma = item.chromaKey || { enabled: false, color: '#00ff00', similarity: 30, smoothness: 10 };
    if (els.chkChromaEnabled) els.chkChromaEnabled.checked = !!chroma.enabled;
    if (els.colorChromaKey) els.colorChromaKey.value = chroma.color || '#00ff00';
    if (els.sliderChromaSim) {
      els.sliderChromaSim.value = chroma.similarity;
      if (els.lblChromaSim) els.lblChromaSim.textContent = `${chroma.similarity}%`;
    }
    if (els.sliderChromaSmooth) {
      els.sliderChromaSmooth.value = chroma.smoothness;
      if (els.lblChromaSmooth) els.lblChromaSmooth.textContent = `${chroma.smoothness}%`;
    }
  }

  function handleCutoutRemoval(actionType) {
    const item = getSelectedItem();
    if (!item || !item.url) {
      alert('Please select a valid clip to perform cutout.');
      return;
    }

    const btn = actionType === 'background_removal' ? els.btnAutoBgRemove : els.btnAiSubjectCutout;
    const oldText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Processing Cutout...';
    if (els.cutoutConfigError) els.cutoutConfigError.classList.add('d-none');

    fetch('backend/cutout_handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ mediaUrl: item.url, action: actionType, csrf_token: window.csrfToken || '' })
    })
    .then(res => res.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = oldText;

      if (!data.success) {
        if (data.error === 'PROVIDER_UNCONFIGURED') {
          if (els.cutoutConfigError) els.cutoutConfigError.classList.remove('d-none');
        } else {
          alert(`Cutout failed: ${data.message || 'Unknown error'}`);
        }
        return;
      }

      // Cutout successful -> write cutoutUrl non-destructively
      const store = window.StudioProjectStore;
      if (store) {
        const st = store.getState();
        const activeItem = st.items.find(i => i.id === item.id);
        if (activeItem) {
          activeItem.cutoutUrl = data.cutoutUrl;
          store.setState(st, true);
          alert('🎉 Cutout completed successfully! Applied non-destructively to preview.');
        }
      }
    })
    .catch(err => {
      btn.disabled = false;
      btn.innerHTML = oldText;
      alert(`Cutout network request failed: ${err.message || err}`);
    });
  }

  function onStateChange(state) {
    currentProjectState = state;
    const item = getSelectedItem();
    if (item) {
      syncWorkspaceUI(item);
    }
  }

  function init() {
    initDOMRefs();

    // ── Bind Mask Inputs ──
    if (els.maskTypeSelect) {
      els.maskTypeSelect.addEventListener('change', (e) => {
        updateActiveMaskProperty(mask => { mask.type = e.target.value; });
      });
    }
    if (els.sliderMaskX) {
      els.sliderMaskX.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        if (els.lblMaskX) els.lblMaskX.textContent = `${Math.round(val)}%`;
        updateActiveMaskProperty(mask => { mask.x = val; });
      });
    }
    if (els.sliderMaskY) {
      els.sliderMaskY.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        if (els.lblMaskY) els.lblMaskY.textContent = `${Math.round(val)}%`;
        updateActiveMaskProperty(mask => { mask.y = val; });
      });
    }
    if (els.sliderMaskWidth) {
      els.sliderMaskWidth.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        if (els.lblMaskWidth) els.lblMaskWidth.textContent = `${Math.round(val)}%`;
        updateActiveMaskProperty(mask => { mask.width = val; });
      });
    }
    if (els.sliderMaskHeight) {
      els.sliderMaskHeight.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        if (els.lblMaskHeight) els.lblMaskHeight.textContent = `${Math.round(val)}%`;
        updateActiveMaskProperty(mask => { mask.height = val; });
      });
    }
    if (els.sliderMaskRotation) {
      els.sliderMaskRotation.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        if (els.lblMaskRotation) els.lblMaskRotation.textContent = `${Math.round(val)}°`;
        updateActiveMaskProperty(mask => { mask.rotation = val; });
      });
    }
    if (els.sliderMaskFeather) {
      els.sliderMaskFeather.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        if (els.lblMaskFeather) els.lblMaskFeather.textContent = `${Math.round(val)}`;
        updateActiveMaskProperty(mask => { mask.feather = val; });
      });
    }
    if (els.chkMaskInvert) {
      els.chkMaskInvert.addEventListener('change', (e) => {
        updateActiveMaskProperty(mask => { mask.invert = e.target.checked; });
      });
    }

    // ── Bind Chroma Key Inputs ──
    if (els.chkChromaEnabled) {
      els.chkChromaEnabled.addEventListener('change', (e) => {
        updateActiveChromaProperty(c => { c.enabled = e.target.checked; });
      });
    }
    if (els.colorChromaKey) {
      els.colorChromaKey.addEventListener('input', (e) => {
        updateActiveChromaProperty(c => { c.color = e.target.value; });
      });
    }
    if (els.sliderChromaSim) {
      els.sliderChromaSim.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        if (els.lblChromaSim) els.lblChromaSim.textContent = `${val}%`;
        updateActiveChromaProperty(c => { c.similarity = val; });
      });
    }
    if (els.sliderChromaSmooth) {
      els.sliderChromaSmooth.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        if (els.lblChromaSmooth) els.lblChromaSmooth.textContent = `${val}%`;
        updateActiveChromaProperty(c => { c.smoothness = val; });
      });
    }

    // ── Bind AI Cutouts ──
    if (els.btnAutoBgRemove) {
      els.btnAutoBgRemove.addEventListener('click', () => handleCutoutRemoval('background_removal'));
    }
    if (els.btnAiSubjectCutout) {
      els.btnAiSubjectCutout.addEventListener('click', () => handleCutoutRemoval('subject_cutout'));
    }

    // Subscribe to store updates
    const store = window.StudioProjectStore;
    if (store) {
      store.subscribe(onStateChange);
      // Run initial sync
      onStateChange(store.getState());
    }

    console.info('[StudioMaskCutout] Workspace initialized.');
  }

  document.addEventListener('DOMContentLoaded', init);

  // Export public workspace helper
  window.StudioMaskCutout = {
    syncWorkspaceUI,
    getSelectedItem
  };

})();
