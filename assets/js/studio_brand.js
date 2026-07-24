/**
 * assets/js/studio_brand.js
 * Brand Kit workspace manager.
 * Supports logos, brand colors, brand fonts, reusable assets, and application pathways.
 */

(function () {
  'use strict';

  let activeBrandKit = null;
  let els = {};

  function initDOMRefs() {
    els = {
      colorsList:       document.getElementById('brandColorsList'),
      fontsList:        document.getElementById('brandFontsList'),
      logosList:        document.getElementById('brandLogosList'),
      watermarksList:   document.getElementById('brandWatermarksList'),
      logoUploadInput:  document.getElementById('logoUploadInput'),
      btnUploadLogo:    document.getElementById('btnUploadLogo'),
      colorPickerInput: document.getElementById('brandColorPickerInput'),
      btnAddColor:      document.getElementById('btnAddBrandColor')
    };
  }

  function fetchBrandKit() {
    fetch('backend/brand_kit_handler.php')
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          activeBrandKit = data.brandKit;
          renderBrandKitUI();
        }
      })
      .catch(err => console.error('[BrandKit] Load failed:', err));
  }

  function saveBrandKit() {
    if (!activeBrandKit) return;
    fetch('backend/brand_kit_handler.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save_brand_kit', brandKit: activeBrandKit })
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        activeBrandKit = data.brandKit;
        renderBrandKitUI();
      }
    })
    .catch(err => console.error('[BrandKit] Save failed:', err));
  }

  function renderBrandKitUI() {
    if (!activeBrandKit) return;

    // Render colors
    if (els.colorsList) {
      els.colorsList.innerHTML = activeBrandKit.colors.map(col => {
        return `
          <div class="d-flex flex-column align-items-center mb-2" style="width: 50px;">
            <button class="btn-brand-color border border-secondary rounded-circle" 
                    style="width: 32px; height: 32px; background-color: ${col}; cursor: pointer;" 
                    data-color="${col}" title="Apply ${col}"></button>
            <span class="text-secondary mt-1" style="font-size: 8px;">${col.toUpperCase()}</span>
          </div>
        `;
      }).join('');

      els.colorsList.querySelectorAll('.btn-brand-color').forEach(btn => {
        btn.addEventListener('click', () => {
          const color = btn.dataset.color;
          applyColorToSelected(color);
        });
      });
    }

    // Render fonts
    if (els.fontsList) {
      els.fontsList.innerHTML = activeBrandKit.fonts.map(font => {
        return `
          <button class="btn btn-cyber btn-xs w-100 mb-1 btn-brand-font" data-font="${font}" style="font-family: '${font}', sans-serif;">
            ${font}
          </button>
        `;
      }).join('');

      els.fontsList.querySelectorAll('.btn-brand-font').forEach(btn => {
        btn.addEventListener('click', () => {
          const font = btn.dataset.font;
          applyFontToSelected(font);
        });
      });
    }

    // Render logos list
    if (els.logosList) {
      els.logosList.innerHTML = `
        <div class="d-flex gap-2 align-items-center bg-dark p-2 rounded border border-secondary mb-2" style="cursor:pointer;" id="brandLogoAssetCard">
          <img src="${activeBrandKit.logoUrl}" class="rounded bg-black border" style="width: 40px; height: 40px; object-fit: contain;">
          <div style="flex:1;">
            <div class="font-weight-bold text-cyber" style="font-size:11px;">Active Brand Logo</div>
            <div class="text-secondary" style="font-size:9px;">Click to inject as watermark</div>
          </div>
        </div>
      `;
      
      const logoCard = document.getElementById('brandLogoAssetCard');
      if (logoCard) {
        logoCard.addEventListener('click', () => {
          applyLogoAsWatermark(activeBrandKit.logoUrl);
        });
      }
    }

    // Render reusable watermarks list
    if (els.watermarksList) {
      els.watermarksList.innerHTML = activeBrandKit.watermarks.map(wm => {
        return `
          <div class="d-flex justify-content-between align-items-center p-2 mb-1 bg-dark border border-secondary rounded" style="cursor:pointer;" data-wm-id="${wm.id}">
            <div class="d-flex gap-2 align-items-center">
              <img src="${wm.url}" class="rounded border" style="width: 30px; height: 30px; object-fit: contain;">
              <div style="font-size:11px;">
                <div class="font-weight-bold text-cyber">${wm.name}</div>
                <div class="text-secondary" style="font-size:9px;">Pos: ${wm.x}%, ${wm.y}% | Scale: ${Math.round(wm.scale * 100)}%</div>
              </div>
            </div>
            <button class="btn btn-xs btn-cyber btn-apply-wm" data-wm-id="${wm.id}">Add</button>
          </div>
        `;
      }).join('');

      els.watermarksList.querySelectorAll('.btn-apply-wm, [data-wm-id]').forEach(el => {
        el.addEventListener('click', (e) => {
          e.stopPropagation();
          const wmId = el.dataset.wmId;
          const targetWm = activeBrandKit.watermarks.find(w => w.id === wmId);
          if (targetWm) {
            applyWatermarkAsset(targetWm);
          }
        });
      });
    }
  }

  function applyColorToSelected(hex) {
    const editor = window.CapCutEditor;
    if (!editor || !editor.State || !editor.State.selectedId) {
      alert('Please select a text, shape, or video clip first to apply the brand color.');
      return;
    }
    const store = window.StudioProjectStore;
    if (!store) return;

    const state = store.getState();
    const item = state.items.find(i => i.id === editor.State.selectedId);
    if (!item) return;

    if (item.type === 'text' || item.type === 'caption') {
      if (!item.style) item.style = {};
      item.style.color = hex;
    } else if (item.type === 'video' || item.type === 'image') {
      item.fill = hex; // Set aspect ratio fit background fill color
    } else if (item.type === 'sticker') { // vector shape element
      if (!item.style) item.style = {};
      item.style.color = hex;
      item.style.fillColor = hex;
    }

    store.setState(state, true);
    if (window.PreviewEngine && typeof window.PreviewEngine.seek === 'function') {
      window.PreviewEngine.seek(window.PreviewEngine.currentTime);
    }
  }

  function applyFontToSelected(font) {
    const editor = window.CapCutEditor;
    if (!editor || !editor.State || !editor.State.selectedId) {
      alert('Please select a text or caption overlay clip first to apply the brand font.');
      return;
    }
    const store = window.StudioProjectStore;
    if (!store) return;

    const state = store.getState();
    const item = state.items.find(i => i.id === editor.State.selectedId);
    if (!item) return;

    if (item.type === 'text' || item.type === 'caption') {
      if (!item.style) item.style = {};
      item.style.fontFamily = font;
      store.setState(state, true);
      if (window.PreviewEngine && typeof window.PreviewEngine.seek === 'function') {
        window.PreviewEngine.seek(window.PreviewEngine.currentTime);
      }
    } else {
      alert('Brand fonts can only be applied to text or captions layers.');
    }
  }

  function applyLogoAsWatermark(url) {
    const store = window.StudioProjectStore;
    if (!store) return;

    const state = store.getState();
    const duration = state.projectSettings?.duration ?? 10.0;

    const newLogoSticker = {
      id: 'brand_logo_' + Date.now(),
      name: 'Brand Logo Watermark',
      type: 'sticker',
      url: url,
      start: 0.0,
      duration: duration,
      position: { x: 85, y: 15 }, // Top-Right Corner
      scale: { x: 0.3, y: 0.3 },
      rotation: 0,
      opacity: 0.8
    };

    state.items.push(newLogoSticker);
    store.setState(state, true);
    
    if (window.StudioTimeline && typeof window.StudioTimeline.render === 'function') {
      window.StudioTimeline.render();
    }
    alert('🎉 Injected Brand Logo Watermark on the timeline!');
  }

  function applyWatermarkAsset(wm) {
    const store = window.StudioProjectStore;
    if (!store) return;

    const state = store.getState();
    const duration = state.projectSettings?.duration ?? 10.0;

    const newWm = {
      id: 'wm_' + Date.now(),
      name: wm.name,
      type: 'sticker',
      url: wm.url,
      start: 0.0,
      duration: duration,
      position: { x: wm.x, y: wm.y },
      scale: { x: wm.scale, y: wm.scale },
      rotation: 0,
      opacity: wm.opacity
    };

    state.items.push(newWm);
    store.setState(state, true);

    if (window.StudioTimeline && typeof window.StudioTimeline.render === 'function') {
      window.StudioTimeline.render();
    }
    alert(`🎉 Applied reusable brand watermark: "${wm.name}"!`);
  }

  function handleLogoUpload() {
    const file = els.logoUploadInput?.files[0];
    if (!file) return;

    const formData = new FormData();
    formData.append('logo_file', file);

    els.btnUploadLogo.disabled = true;
    els.btnUploadLogo.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Uploading...';

    fetch('backend/brand_kit_handler.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      els.btnUploadLogo.disabled = false;
      els.btnUploadLogo.innerHTML = '<i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload Logo';
      if (els.logoUploadInput) els.logoUploadInput.value = '';

      if (data.success) {
        activeBrandKit = data.brandKit;
        renderBrandKitUI();
        alert('🎉 Brand Logo updated successfully!');
      } else {
        alert(`Logo upload failed: ${data.message || 'Unknown error'}`);
      }
    })
    .catch(err => {
      els.btnUploadLogo.disabled = false;
      els.btnUploadLogo.innerHTML = '<i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload Logo';
      alert(`Logo upload failed: ${err.message || err}`);
    });
  }

  function handleAddColor() {
    const color = els.colorPickerInput?.value;
    if (!color) return;

    if (activeBrandKit.colors.includes(color)) {
      alert('Color is already in your brand colors palette.');
      return;
    }

    activeBrandKit.colors.push(color);
    saveBrandKit();
  }

  function init() {
    initDOMRefs();

    if (els.btnUploadLogo) {
      els.btnUploadLogo.addEventListener('click', () => els.logoUploadInput?.click());
    }
    if (els.logoUploadInput) {
      els.logoUploadInput.addEventListener('change', handleLogoUpload);
    }
    if (els.btnAddColor) {
      els.btnAddColor.addEventListener('click', handleAddColor);
    }

    fetchBrandKit();
    console.info('[StudioBrand] Workspace initialized.');
  }

  document.addEventListener('DOMContentLoaded', init);

  // Expose brand utilities publicly
  window.StudioBrand = {
    applyColorToSelected,
    applyFontToSelected,
    applyLogoAsWatermark,
    getBrandKit: () => activeBrandKit
  };

})();
