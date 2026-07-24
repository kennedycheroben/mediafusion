/**
 * assets/js/studio_templates.js
 * Templates workspace manager.
 * Supports multi-track timeline templates, previewing, aspect-ratio setup, and placeholder swapping.
 */

(function () {
  'use strict';

  // ── Predefined Template Registry ──
  const TEMPLATE_REGISTRY = [
    {
      id: 'template_cinematic_vlog',
      name: 'Cinematic Vlog Intro',
      description: 'Golden hour look with dissolved transitions & travel music.',
      aspectRatio: '16:9',
      duration: 8.0,
      thumbnail: 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=200&h=120&fit=crop',
      items: [
        {
          id: 'vlog_place_1',
          name: 'Placeholder Video 1',
          type: 'video',
          start: 0.0,
          duration: 4.0,
          url: 'assets/media/vlog_placeholder1.mp4',
          isPlaceholder: true,
          position: { x: 50, y: 50 },
          scale: { x: 1.0, y: 1.0 },
          rotation: 0,
          opacity: 1.0
        },
        {
          id: 'vlog_place_2',
          name: 'Placeholder Video 2',
          type: 'video',
          start: 4.0,
          duration: 4.0,
          url: 'assets/media/vlog_placeholder2.mp4',
          isPlaceholder: true,
          position: { x: 50, y: 50 },
          scale: { x: 1.0, y: 1.0 },
          rotation: 0,
          opacity: 1.0,
          transitionIn: { id: 'dissolve', name: 'Dissolve', duration: 1.0, easing: 'ease-in-out' }
        },
        {
          id: 'vlog_title',
          name: 'Vlog Main Title',
          type: 'text',
          start: 0.5,
          duration: 5.0,
          text: 'CINEMATIC TRAVEL',
          position: { x: 50, y: 40 },
          scale: { x: 1.2, y: 1.2 },
          rotation: 0,
          opacity: 1.0,
          style: {
            fontSize: 36,
            color: '#f39c12',
            strokeWidth: 2,
            strokeColor: '#000000'
          }
        },
        {
          id: 'vlog_sub',
          name: 'Vlog Subtitle',
          type: 'text',
          start: 1.5,
          duration: 4.0,
          text: 'Explore the uncharted paths',
          position: { x: 50, y: 60 },
          scale: { x: 1.0, y: 1.0 },
          rotation: 0,
          opacity: 1.0,
          style: {
            fontSize: 20,
            color: '#ffffff',
            strokeWidth: 1,
            strokeColor: '#000000'
          }
        },
        {
          id: 'vlog_music',
          name: 'Acoustic Travel Beat',
          type: 'audio',
          start: 0.0,
          duration: 8.0,
          url: 'assets/media/acoustic_travel.mp3',
          volume: 80
        }
      ]
    },
    {
      id: 'template_tiktok_street',
      name: 'TikTok Street Beat',
      description: 'Portrait action template with glitch beats & glowing fonts.',
      aspectRatio: '9:16',
      duration: 6.0,
      thumbnail: 'https://images.unsplash.com/photo-1511447333015-45b65e60f6d5?w=120&h=200&fit=crop',
      items: [
        {
          id: 'tok_place_1',
          name: 'Placeholder Video 1',
          type: 'video',
          start: 0.0,
          duration: 3.0,
          url: 'assets/media/street_placeholder1.mp4',
          isPlaceholder: true,
          position: { x: 50, y: 50 },
          scale: { x: 1.0, y: 1.0 },
          rotation: 0,
          opacity: 1.0
        },
        {
          id: 'tok_place_2',
          name: 'Placeholder Video 2',
          type: 'video',
          start: 3.0,
          duration: 3.0,
          url: 'assets/media/street_placeholder2.mp4',
          isPlaceholder: true,
          position: { x: 50, y: 50 },
          scale: { x: 1.0, y: 1.0 },
          rotation: 0,
          opacity: 1.0
        },
        {
          id: 'tok_title',
          name: 'Glitch Text Layer',
          type: 'text',
          start: 0.0,
          duration: 6.0,
          text: 'STREET STYLE',
          position: { x: 50, y: 30 },
          scale: { x: 1.2, y: 1.2 },
          rotation: -5,
          opacity: 1.0,
          style: {
            fontSize: 32,
            color: '#ff007f',
            strokeWidth: 3,
            strokeColor: '#ffffff'
          }
        },
        {
          id: 'tok_music',
          name: 'Tech House Loop',
          type: 'audio',
          start: 0.0,
          duration: 6.0,
          url: 'assets/media/tech_house.mp3',
          volume: 90
        }
      ]
    },
    {
      id: 'template_corporate_promo',
      name: 'Corporate Square Promo',
      description: 'Square product grid styled for LinkedIn & Instagram.',
      aspectRatio: '1:1',
      duration: 5.0,
      thumbnail: 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=200&h=200&fit=crop',
      items: [
        {
          id: 'corp_place_img',
          name: 'Placeholder Product Image',
          type: 'image',
          start: 0.0,
          duration: 5.0,
          url: 'assets/media/product_placeholder.jpg',
          isPlaceholder: true,
          position: { x: 50, y: 50 },
          scale: { x: 1.0, y: 1.0 },
          rotation: 0,
          opacity: 1.0
        },
        {
          id: 'corp_title',
          name: 'Promo Heading Text',
          type: 'text',
          start: 1.0,
          duration: 4.0,
          text: 'GROW YOUR BUSINESS',
          position: { x: 50, y: 75 },
          scale: { x: 1.0, y: 1.0 },
          rotation: 0,
          opacity: 1.0,
          style: {
            fontSize: 24,
            color: '#3498db',
            strokeWidth: 2,
            strokeColor: '#ffffff'
          }
        },
        {
          id: 'corp_music',
          name: 'Inspiring Corporate Motif',
          type: 'audio',
          start: 0.0,
          duration: 5.0,
          url: 'assets/media/inspiring_corporate.mp3',
          volume: 70
        }
      ]
    }
  ];

  function renderTemplatesList() {
    const listEl = document.querySelector('#sidebar-section-templates .capcut-asset-list');
    if (!listEl) return;

    listEl.innerHTML = TEMPLATE_REGISTRY.map(tmpl => {
      return `
        <div class="capcut-asset-card p-2 mb-2 d-flex flex-column bg-dark border border-secondary rounded" style="cursor:pointer;" data-template-id="${tmpl.id}">
          <div class="d-flex gap-2">
            <img src="${tmpl.thumbnail}" class="rounded" style="width: 70px; height: 50px; object-fit: cover;">
            <div style="flex:1;">
              <div class="font-weight-bold text-cyber" style="font-size:12px;">${tmpl.name}</div>
              <div class="text-secondary" style="font-size:10px;">Ratio: ${tmpl.aspectRatio} | Duration: ${tmpl.duration}s</div>
              <div class="text-muted italic text-truncate" style="font-size:10px; max-width: 140px;">${tmpl.description}</div>
            </div>
          </div>
          <button class="btn btn-cyber btn-xs w-100 mt-2 btn-apply-tmpl" data-template-id="${tmpl.id}">
            <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Apply Template
          </button>
        </div>
      `;
    }).join('');

    listEl.querySelectorAll('.btn-apply-tmpl, .capcut-asset-card').forEach(el => {
      el.addEventListener('click', (e) => {
        e.stopPropagation();
        const tmplId = el.dataset.templateId;
        applyTemplate(tmplId);
      });
    });
  }

  function applyTemplate(templateId) {
    const tmpl = TEMPLATE_REGISTRY.find(t => t.id === templateId);
    if (!tmpl) return;

    const store = window.StudioProjectStore;
    if (!store) return;

    // Build the new project state from the template
    const newProjectState = {
      projectSettings: {
        id: 'project_' + Date.now(),
        name: `${tmpl.name} Project`,
        duration: tmpl.duration,
        aspectRatio: tmpl.aspectRatio
      },
      items: JSON.parse(JSON.stringify(tmpl.items)), // Deep clone items
      tracks: []
    };

    store.setState(newProjectState, true);

    // Synchronize aspect ratio visual class on player if available
    const playerWrapper = document.getElementById('playerWrapper');
    if (playerWrapper) {
      playerWrapper.className = `player-ratio-${tmpl.aspectRatio.replace(':', '-')}`;
    }

    // Set timeline view parameters
    if (window.PreviewEngine && typeof window.PreviewEngine.seek === 'function') {
      window.PreviewEngine.seek(0.0);
    }
    if (window.StudioTimeline && typeof window.StudioTimeline.render === 'function') {
      window.StudioTimeline.render();
    }
    
    alert(`🎉 Successfully instantiated template: "${tmpl.name}"!`);
  }

  /**
   * Replaces target placeholder item with a custom uploaded media source
   * @param {string} itemId The unique ID of the placeholder clip
   * @param {string} fileUrl The URL of the chosen replacement media
   * @param {string} fileName The display name of the media file
   */
  function replacePlaceholderMedia(itemId, fileUrl, fileName) {
    const store = window.StudioProjectStore;
    if (!store) return;

    const state = store.getState();
    const item = state.items.find(i => i.id === itemId);
    if (!item) return;

    item.url = fileUrl;
    item.name = fileName;
    item.isPlaceholder = false; // Mark as resolved placeholder
    
    // Commit new state with undo checkpoint
    store.setState(state, true);

    if (window.PreviewEngine && typeof window.PreviewEngine.seek === 'function') {
      window.PreviewEngine.seek(window.PreviewEngine.currentTime);
    }
    if (window.StudioTimeline && typeof window.StudioTimeline.render === 'function') {
      window.StudioTimeline.render();
    }
  }

  function init() {
    renderTemplatesList();
    console.info('[StudioTemplates] Workspace initialized.');
  }

  document.addEventListener('DOMContentLoaded', init);

  // Expose public API
  window.StudioTemplates = {
    TEMPLATE_REGISTRY,
    applyTemplate,
    replacePlaceholderMedia
  };

})();
