/**
 * assets/js/studio_captions.js
 * Comprehensive Captions and Transcript workspace controller.
 * Integrates speech-to-text provider architecture, timing updates, splits, merges, transcript synchronization.
 */

(function () {
  'use strict';

  let els = {};
  let currentProjectState = null;

  function initDOMRefs() {
    els = {
      mediaSelect:        document.getElementById('sttMediaSelect'),
      langSelect:         document.getElementById('sttLangSelect'),
      generateBtn:        document.getElementById('btnGenerateSTT'),
      addManualBtn:       document.getElementById('btnAddManualCaption'),
      configError:        document.getElementById('sttConfigError'),
      captionsList:       document.getElementById('captionsManagerList'),
      transcriptSearch:   document.getElementById('transcriptSearch'),
      transcriptViewArea: document.getElementById('transcriptViewArea')
    };
  }

  // ── Populating media select dropdown ──
  function populateMediaSelect(state) {
    if (!els.mediaSelect) return;
    const previousSelection = els.mediaSelect.value;
    
    // Clear list
    els.mediaSelect.innerHTML = '<option value="">-- Choose Audio/Video Clip --</option>';
    
    const mediaClips = (state.items || []).filter(item => item.type === 'video' || item.type === 'audio');
    mediaClips.forEach(clip => {
      const opt = document.createElement('option');
      opt.value = clip.id;
      opt.textContent = `${clip.name} (${clip.type.toUpperCase()} - ${clip.duration.toFixed(1)}s)`;
      if (clip.id === previousSelection) {
        opt.selected = true;
      }
      els.mediaSelect.appendChild(opt);
    });
  }

  // ── Render Captions list ──
  function renderCaptionsList(state) {
    if (!els.captionsList) return;

    const captions = (state.items || []).filter(item => item.type === 'caption');
    if (captions.length === 0) {
      els.captionsList.innerHTML = '<div class="text-muted text-center py-3 italic">No captions generated yet</div>';
      return;
    }

    // Sort captions by start time
    const sorted = [...captions].sort((a, b) => a.start - b.start);

    els.captionsList.innerHTML = sorted.map((cap, i) => `
      <div class="card p-2 mb-2 bg-dark border-secondary caption-item-row" data-id="${cap.id}">
        <div class="row g-1 mb-1">
          <div class="col-4">
            <label class="small text-muted" style="font-size:10px;">Start (s)</label>
            <input type="number" step="0.1" min="0" class="form-control form-control-cyber form-control-sm cap-time-start" value="${cap.start.toFixed(1)}" style="padding:2px 4px; font-size:11px;">
          </div>
          <div class="col-4">
            <label class="small text-muted" style="font-size:10px;">End (s)</label>
            <input type="number" step="0.1" min="0" class="form-control form-control-cyber form-control-sm cap-time-end" value="${(cap.start + cap.duration).toFixed(1)}" style="padding:2px 4px; font-size:11px;">
          </div>
          <div class="col-4 d-flex align-items-end justify-content-end gap-1">
            <button class="btn btn-cyber btn-sm px-1 btn-cap-split" title="Split Caption" style="padding:2px 4px;font-size:10px;"><i class="fa-solid fa-scissors"></i></button>
            <button class="btn btn-cyber btn-sm px-1 btn-cap-merge" title="Merge Down" ${i === sorted.length - 1 ? 'disabled' : ''} style="padding:2px 4px;font-size:10px;"><i class="fa-solid fa-compress"></i></button>
            <button class="btn btn-cyber btn-sm px-1 btn-cap-delete text-danger" title="Delete" style="padding:2px 4px;font-size:10px;"><i class="fa-solid fa-trash-can"></i></button>
          </div>
        </div>
        <textarea class="form-control form-control-cyber form-control-sm cap-content-textarea" rows="2" style="font-size:11px; line-height: 1.2;">${cap.content || ''}</textarea>
      </div>
    `).join('');

    // Attach list event listeners
    els.captionsList.querySelectorAll('.caption-item-row').forEach(row => {
      const id = row.dataset.id;
      
      // Start time change
      row.querySelector('.cap-time-start').addEventListener('change', (e) => {
        const val = parseFloat(e.target.value) || 0.0;
        updateCaptionProperty(id, cap => {
          const oldEnd = cap.start + cap.duration;
          cap.start = val;
          cap.duration = Math.max(0.1, oldEnd - val);
        });
      });

      // End time change
      row.querySelector('.cap-time-end').addEventListener('change', (e) => {
        const val = parseFloat(e.target.value) || 0.0;
        updateCaptionProperty(id, cap => {
          cap.duration = Math.max(0.1, val - cap.start);
        });
      });

      // Text content update
      row.querySelector('.cap-content-textarea').addEventListener('input', (e) => {
        const val = e.target.value;
        updateCaptionProperty(id, cap => {
          cap.content = val;
        });
      });

      // Split caption
      row.querySelector('.btn-cap-split').addEventListener('click', () => {
        splitCaptionItem(id);
      });

      // Merge down
      row.querySelector('.btn-cap-merge').addEventListener('click', () => {
        mergeCaptionItem(id);
      });

      // Delete caption
      row.querySelector('.btn-cap-delete').addEventListener('click', () => {
        deleteCaptionItem(id);
      });
    });
  }

  // ── Render Transcript workspace ──
  function renderTranscript(state) {
    if (!els.transcriptViewArea) return;

    const captions = (state.items || []).filter(item => item.type === 'caption');
    if (captions.length === 0) {
      els.transcriptViewArea.innerHTML = '<div class="text-muted text-center py-4 italic">No transcription segments loaded</div>';
      return;
    }

    const searchQuery = els.transcriptSearch ? els.transcriptSearch.value.trim().toLowerCase() : '';
    const sorted = [...captions].sort((a, b) => a.start - b.start);
    const playheadTime = window.PreviewEngine?.currentTime ?? 0;

    const filtered = sorted.filter(cap => {
      if (!searchQuery) return true;
      return (cap.content || '').toLowerCase().includes(searchQuery);
    });

    if (filtered.length === 0) {
      els.transcriptViewArea.innerHTML = '<div class="text-muted text-center py-3">No matching dialogue found</div>';
      return;
    }

    els.transcriptViewArea.innerHTML = filtered.map(cap => {
      const isCurrent = playheadTime >= cap.start && playheadTime <= cap.start + cap.duration;
      return `
        <div class="p-2 mb-2 rounded transcript-block ${isCurrent ? 'bg-primary text-white' : 'bg-dark text-light border-secondary border'}" 
          style="cursor: pointer; transition: background 0.2s;" data-id="${cap.id}" data-start="${cap.start}">
          <div class="d-flex justify-content-between align-items-center mb-1" style="font-size:10px; opacity:0.8;">
            <span>⏱️ ${cap.start.toFixed(2)}s - ${(cap.start + cap.duration).toFixed(2)}s</span>
            <button class="btn btn-sm btn-link text-danger p-0 btn-remove-spoken" data-id="${cap.id}" title="Remove Spoken Section" style="font-size:10px; text-decoration:none;">
              <i class="fa-solid fa-scissors"></i> Cut
            </button>
          </div>
          <div class="transcript-text" style="font-size:12px;">${cap.content || ''}</div>
        </div>
      `;
    }).join('');

    // Attach transcript navigation and edit hooks
    els.transcriptViewArea.querySelectorAll('.transcript-block').forEach(block => {
      const id = block.dataset.id;
      const startTime = parseFloat(block.dataset.start);

      // Single click seeks to timestamp
      block.addEventListener('click', (e) => {
        if (e.target.closest('.btn-remove-spoken')) return;
        window.PreviewEngine?.seek(startTime);
      });

      // Remove spoken section
      block.querySelector('.btn-remove-spoken')?.addEventListener('click', (e) => {
        e.stopPropagation();
        removeSpokenSection(id);
      });
    });
  }

  // ── Sync Helper: update single caption property ──
  function updateCaptionProperty(id, updater) {
    const store = window.StudioProjectStore;
    if (!store) return;
    const st = store.getState();
    const activeItem = st.items.find(i => i.id === id);
    if (activeItem) {
      updater(activeItem);
      store.setState(st, false); // commitState to autosave but bypass pushUndo during live inputs
    }
  }

  // ── Split a Caption in half ──
  function splitCaptionItem(id) {
    const store = window.StudioProjectStore;
    if (!store) return;
    const st = JSON.parse(JSON.stringify(store.getState()));
    const idx = st.items.findIndex(i => i.id === id);
    if (idx === -1) return;

    const cap = st.items[idx];
    const halfDur = cap.duration / 2;

    const part1 = {
      ...cap,
      duration: halfDur
    };
    
    const part2 = {
      ...cap,
      id:       store.generateUUID('clip_caption'),
      start:    cap.start + halfDur,
      duration: halfDur,
      content:  '...'
    };

    st.items.splice(idx, 1, part1, part2);
    store.setState(st, true);
    if (window.CapCutEditor?.showSplitToast) {
      window.CapCutEditor.showSplitToast('✂️ Caption split successfully');
    }
  }

  // ── Merge caption with the next caption ──
  function mergeCaptionItem(id) {
    const store = window.StudioProjectStore;
    if (!store) return;
    const st = JSON.parse(JSON.stringify(store.getState()));
    const capList = st.items.filter(i => i.type === 'caption').sort((a, b) => a.start - b.start);
    
    const idx = capList.findIndex(i => i.id === id);
    if (idx === -1 || idx === capList.length - 1) return;

    const capA = capList[idx];
    const capB = capList[idx + 1];

    // Combine duration and content
    capA.duration = (capB.start + capB.duration) - capA.start;
    capA.content = (capA.content || '') + ' ' + (capB.content || '');

    // Remove capB from state
    st.items = st.items.filter(it => it.id !== capB.id);

    store.setState(st, true);
    if (window.CapCutEditor?.showSplitToast) {
      window.CapCutEditor.showSplitToast('🔗 Captions merged successfully');
    }
  }

  // ── Delete a Caption ──
  function deleteCaptionItem(id) {
    const store = window.StudioProjectStore;
    if (!store) return;
    const st = JSON.parse(JSON.stringify(store.getState()));
    st.items = st.items.filter(it => it.id !== id);
    store.setState(st, true);
  }

  // ── Remove spoken section and cut original media ──
  function removeSpokenSection(id) {
    const store = window.StudioProjectStore;
    if (!store) return;
    const st = JSON.parse(JSON.stringify(store.getState()));
    const cap = st.items.find(i => i.id === id);
    if (!cap) return;

    // Delete the caption
    st.items = st.items.filter(it => it.id !== id);

    // Optionally split and cut video/audio segments overlapping the spoken section (non-destructive trim)
    st.items.forEach(item => {
      if (item.type === 'video' || item.type === 'audio') {
        const overlapStart = Math.max(item.start, cap.start);
        const overlapEnd = Math.min(item.start + item.duration, cap.start + cap.duration);
        
        if (overlapStart < overlapEnd) {
          // spoken overlap exists -> trim original clip
          const cutDuration = overlapEnd - overlapStart;
          item.duration = Math.max(0.1, item.duration - cutDuration);
        }
      }
    });

    store.setState(st, true);
    if (window.CapCutEditor?.showSplitToast) {
      window.CapCutEditor.showSplitToast('✂️ Removed spoken segment from timeline');
    }
  }

  // ── Generate AI Captions STT Flow ──
  function handleSTTGeneration() {
    const mediaId = els.mediaSelect.value;
    const language = els.langSelect.value;
    const store = window.StudioProjectStore;

    if (!mediaId || !store) {
      alert('Please select an active media clip to transcribe.');
      return;
    }

    const state = store.getState();
    const clip = state.items.find(i => i.id === mediaId);
    if (!clip || !clip.url) {
      alert('Selected clip has no source media URL.');
      return;
    }

    // Loader state
    els.generateBtn.disabled = true;
    els.generateBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Transcribing...';
    els.configError.classList.add('d-none');

    fetch('backend/stt_handler.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': typeof csrfToken !== 'undefined' ? csrfToken : ''
      },
      body: JSON.stringify({ mediaUrl: clip.url, language: language })
    })
    .then(res => res.json())
    .then(data => {
      els.generateBtn.disabled = false;
      els.generateBtn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate AI Captions';

      if (!data.success) {
        if (data.error === 'API_KEY_MISSING') {
          els.configError.classList.remove('d-none');
        } else {
          alert(`Speech-to-Text failed: ${data.message || 'Unknown error'}`);
        }
        return;
      }

      // Successful transcription -> parse verbose_json segments
      const segments = data.segments || [];
      if (segments.length === 0) {
        alert('Transcribed successfully, but no segments or dialogue were detected.');
        return;
      }

      const newState = JSON.parse(JSON.stringify(store.getState()));
      let count = 0;

      // Filter out existing captions to prevent overlaps
      newState.items = newState.items.filter(it => it.type !== 'caption');

      segments.forEach(seg => {
        const segStart = (parseFloat(seg.start) || 0.0) + clip.start;
        const segEnd = (parseFloat(seg.end) || 0.0) + clip.start;
        const segDur = segEnd - segStart;

        const newCaption = store.createTimelineItem({
          type:          'caption',
          trackId:       'track_caption_main',
          name:          'Caption',
          start:         segStart,
          duration:      Math.max(0.5, segDur),
          content:       seg.text || ''
        });
        newState.items.push(newCaption);
        count++;
      });

      store.setState(newState, true);
      alert(`🎉 Successfully generated and mapped ${count} captions to the timeline!`);
    })
    .catch(err => {
      els.generateBtn.disabled = false;
      els.generateBtn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate AI Captions';
      alert(`Transcription network request failed: ${err.message || err}`);
    });
  }

  // ── Manual Captioning ──
  function handleAddManualCaption() {
    const store = window.StudioProjectStore;
    if (!store) return;

    const playheadTime = window.PreviewEngine?.currentTime ?? 0;
    const newState = JSON.parse(JSON.stringify(store.getState()));

    const manualCap = store.createTimelineItem({
      type:     'caption',
      trackId:  'track_caption_main',
      name:     'Manual Caption',
      start:    playheadTime,
      duration: 2.5,
      content:  'Enter subtitle text here'
    });

    newState.items.push(manualCap);
    store.setState(newState, true);
  }

  // ── State Change Listener ──
  function onStateChange(state) {
    currentProjectState = state;
    populateMediaSelect(state);
    renderCaptionsList(state);
    renderTranscript(state);
  }

  // ── Initialize Event Bindings ──
  function init() {
    initDOMRefs();

    if (els.generateBtn) {
      els.generateBtn.addEventListener('click', handleSTTGeneration);
    }
    if (els.addManualBtn) {
      els.addManualBtn.addEventListener('click', handleAddManualCaption);
    }
    if (els.transcriptSearch) {
      els.transcriptSearch.addEventListener('input', () => {
        if (currentProjectState) renderTranscript(currentProjectState);
      });
    }

    const store = window.StudioProjectStore;
    if (store) {
      store.subscribe(onStateChange);
      // Run initial render
      onStateChange(store.getState());
    }

    // Sync transcript highlights dynamically when playhead seeks
    setInterval(() => {
      if (currentProjectState && els.transcriptViewArea) {
        renderTranscript(currentProjectState);
      }
    }, 400);

    console.info('[StudioCaptions] Controller initialized.');
  }

  document.addEventListener('DOMContentLoaded', init);

})();
