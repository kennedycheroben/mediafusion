/**
 * assets/js/studio_media.js
 *
 * Complete Media Workspace Module for MediaFusion Studio.
 *
 * Responsibilities:
 *  - Drag-and-drop + click-to-browse upload with progress bars
 *  - Multiple file queue with per-file cancellation
 *  - Server-side upload via backend/studio_media_handler.php
 *  - Media library listing with search, filter, sort
 *  - In-place preview (video, image, audio)
 *  - Rename and Delete actions with ownership safety
 *  - "Add to Timeline" via StudioProjectStore
 */

(function () {
  'use strict';

  const API = 'backend/studio_media_handler.php';

  // ── State ──────────────────────────────────────────────────────────────────
  const state = {
    media: [],
    filter: 'all',     // 'all' | 'video' | 'image' | 'audio'
    sort:   'created_at',
    order:  'DESC',
    search: '',
    uploads: new Map() // key: fileQueueId, val: { xhr, file, progress }
  };

  // ── DOM refs ───────────────────────────────────────────────────────────────
  let els = {};

  function initDOMRefs() {
    els = {
      dropZone:    document.getElementById('mediaDropZone'),
      fileInput:   document.getElementById('mediaFileInput'),
      browseBtn:   document.getElementById('mediaBrowseBtn'),
      uploadQueue: document.getElementById('mediaUploadQueue'),
      grid:        document.getElementById('mediaLibraryGrid'),
      searchInput: document.getElementById('mediaSearchInput'),
      filterBtns:  document.querySelectorAll('[data-media-filter]'),
      sortSelect:  document.getElementById('mediaSortSelect'),
      emptyState:  document.getElementById('mediaEmptyState'),
      preview:     document.getElementById('mediaPreviewOverlay'),
      previewEl:   document.getElementById('mediaPreviewContent'),
      previewClose:document.getElementById('mediaPreviewClose'),
    };
  }

  // ── Upload ─────────────────────────────────────────────────────────────────
  function bindUploadEvents() {
    // Click browse
    els.browseBtn?.addEventListener('click', () => els.fileInput?.click());
    els.dropZone?.addEventListener('click', (e) => {
      if (e.target === els.dropZone || e.target.closest('.media-drop-inner')) {
        els.fileInput?.click();
      }
    });

    // File input change
    els.fileInput?.addEventListener('change', (e) => {
      queueFiles(Array.from(e.target.files || []));
      e.target.value = ''; // reset so same file can be re-queued
    });

    // Drag events on drop zone
    if (els.dropZone) {
      els.dropZone.addEventListener('dragover',  (e) => { e.preventDefault(); els.dropZone.classList.add('drag-active'); });
      els.dropZone.addEventListener('dragleave', ()  => els.dropZone.classList.remove('drag-active'));
      els.dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        els.dropZone.classList.remove('drag-active');
        queueFiles(Array.from(e.dataTransfer.files || []));
      });
    }
  }

  function queueFiles(files) {
    if (!files.length) return;
    files.forEach(file => uploadFile(file));
  }

  function uploadFile(file) {
    const queueId = 'upload_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6);

    // Build queue item UI
    const card = buildQueueCard(queueId, file.name);
    els.uploadQueue?.prepend(card);

    const xhr = new XMLHttpRequest();
    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('media_file', file);
    formData.append('csrf_token', window.csrfToken || '');

    // Progress
    xhr.upload.addEventListener('progress', (e) => {
      if (!e.lengthComputable) return;
      const pct = Math.round((e.loaded / e.total) * 100);
      updateQueueProgress(queueId, pct);
    });

    // Success / Error
    xhr.addEventListener('load', () => {
      let data;
      try { data = JSON.parse(xhr.responseText); } catch { data = { success: false, message: 'Invalid server response.' }; }
      if (data.success) {
        markQueueSuccess(queueId);
        prependToLibrary(data);
        showToast('✅ Uploaded: ' + (data.name || file.name));
      } else {
        markQueueError(queueId, data.message || 'Upload failed.');
        showToast('❌ ' + (data.message || 'Upload failed.'), 'error');
      }
    });

    xhr.addEventListener('error', () => {
      markQueueError(queueId, 'Network error during upload.');
      showToast('❌ Network error.', 'error');
    });

    xhr.addEventListener('abort', () => {
      markQueueError(queueId, 'Upload cancelled.');
    });

    state.uploads.set(queueId, { xhr, file });

    xhr.open('POST', API);
    xhr.send(formData);
  }

  function cancelUpload(queueId) {
    const entry = state.uploads.get(queueId);
    if (entry) {
      entry.xhr.abort();
      state.uploads.delete(queueId);
    }
    document.getElementById(queueId)?.remove();
  }

  function buildQueueCard(queueId, fileName) {
    const card = document.createElement('div');
    card.id = queueId;
    card.className = 'media-queue-card';
    card.innerHTML = `
      <div class="media-queue-name" title="${escHtml(fileName)}">${escHtml(truncate(fileName, 28))}</div>
      <div class="media-queue-progress-wrap">
        <div class="media-queue-bar"><div class="media-queue-fill" id="bar_${queueId}" style="width:0%"></div></div>
        <span class="media-queue-pct" id="pct_${queueId}">0%</span>
        <button class="media-queue-cancel" title="Cancel" onclick="StudioMedia.cancelUpload('${queueId}')">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
    `;
    return card;
  }

  function updateQueueProgress(queueId, pct) {
    const bar = document.getElementById('bar_' + queueId);
    const pctEl = document.getElementById('pct_' + queueId);
    if (bar) bar.style.width = pct + '%';
    if (pctEl) pctEl.textContent = pct + '%';
  }

  function markQueueSuccess(queueId) {
    const card = document.getElementById(queueId);
    if (!card) return;
    card.classList.add('success');
    setTimeout(() => card?.remove(), 2500);
    state.uploads.delete(queueId);
  }

  function markQueueError(queueId, msg) {
    const card = document.getElementById(queueId);
    if (!card) return;
    card.classList.add('error');
    const pctEl = document.getElementById('pct_' + queueId);
    if (pctEl) pctEl.textContent = '✗';
    const bar = document.getElementById('bar_' + queueId);
    if (bar) bar.style.background = '#ef4444';
    setTimeout(() => card?.remove(), 4000);
    state.uploads.delete(queueId);
  }

  // ── Library ────────────────────────────────────────────────────────────────
  function fetchLibrary() {
    const params = new URLSearchParams({
      action: 'list',
      type:   state.filter === 'all' ? '' : state.filter,
      search: state.search,
      sort:   state.sort,
      order:  state.order
    });
    fetch(API + '?' + params)
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          state.media = data.media || [];
          renderGrid();
        }
      })
      .catch(err => console.warn('Media library fetch failed:', err));
  }

  function renderGrid() {
    if (!els.grid) return;
    els.grid.innerHTML = '';
    if (state.media.length === 0) {
      if (els.emptyState) els.emptyState.style.display = 'flex';
      return;
    }
    if (els.emptyState) els.emptyState.style.display = 'none';

    state.media.forEach(item => {
      els.grid.appendChild(buildMediaCard(item));
    });
  }

  function prependToLibrary(item) {
    state.media.unshift(item);
    if (els.emptyState) els.emptyState.style.display = 'none';
    const card = buildMediaCard(item);
    card.classList.add('media-card-new');
    els.grid?.prepend(card);
  }

  function buildMediaCard(item) {
    const card = document.createElement('div');
    card.className = 'media-card';
    card.dataset.id = item.id;
    card.dataset.type = item.mediaType || item.media_type;

    const thumb = buildThumb(item);
    const info  = buildInfo(item);
    const actions = buildActions(item);

    card.appendChild(thumb);
    card.appendChild(info);
    card.appendChild(actions);

    return card;
  }

  function buildThumb(item) {
    const type = item.mediaType || item.media_type;
    const wrap = document.createElement('div');
    wrap.className = 'media-card-thumb';

    const mediaUrl = item.public_url || item.url || item.publicUrl;

    if (item.thumbnail_url || item.thumbnailUrl) {
      const img = document.createElement('img');
      img.src = item.thumbnail_url || item.thumbnailUrl;
      img.alt = item.name;
      img.loading = 'lazy';
      wrap.appendChild(img);
    } else if (type === 'video' && mediaUrl) {
      const vid = document.createElement('video');
      vid.src = mediaUrl + '#t=0.5';
      vid.preload = 'metadata';
      vid.muted = true;
      vid.playsInline = true;
      vid.style.width = '100%';
      vid.style.height = '100%';
      vid.style.objectFit = 'cover';
      vid.style.pointerEvents = 'none';
      wrap.appendChild(vid);
    } else if (type === 'image' && mediaUrl) {
      const img = document.createElement('img');
      img.src = mediaUrl;
      img.alt = item.name;
      img.loading = 'lazy';
      wrap.appendChild(img);
    } else {
      const icon = document.createElement('div');
      icon.className = 'media-card-icon';
      icon.innerHTML = type === 'video' ? '<i class="fa-solid fa-film"></i>' :
                       type === 'audio' ? '<i class="fa-solid fa-music"></i>' :
                                          '<i class="fa-solid fa-image"></i>';
      wrap.appendChild(icon);
    }

    // Type badge
    const badge = document.createElement('span');
    badge.className = 'media-type-badge media-type-' + type;
    badge.textContent = type;
    wrap.appendChild(badge);

    // Duration badge
    if (item.duration) {
      const dur = document.createElement('span');
      dur.className = 'media-duration-badge';
      dur.textContent = formatDuration(item.duration);
      wrap.appendChild(dur);
    }

    // Click to preview
    wrap.addEventListener('click', () => showPreview(item));

    return wrap;
  }

  function buildInfo(item) {
    const info = document.createElement('div');
    info.className = 'media-card-info';

    const nameEl = document.createElement('div');
    nameEl.className = 'media-card-name';
    nameEl.textContent = item.name;
    nameEl.title = item.name;
    info.appendChild(nameEl);

    const meta = document.createElement('div');
    meta.className = 'media-card-meta';
    const parts = [];
    if (item.width && item.height) parts.push(item.width + '×' + item.height);
    if (item.fps) parts.push(Math.round(item.fps) + 'fps');
    if (item.file_size) parts.push(formatBytes(item.file_size));
    meta.textContent = parts.join(' · ');
    info.appendChild(meta);

    return info;
  }

  function buildActions(item) {
    const wrap = document.createElement('div');
    wrap.className = 'media-card-actions';

    const addBtn = document.createElement('button');
    addBtn.className = 'media-action-btn media-add-btn';
    addBtn.title = 'Add to Timeline';
    addBtn.innerHTML = '<i class="fa-solid fa-circle-plus"></i>';
    addBtn.addEventListener('click', (e) => { e.stopPropagation(); addToTimeline(item); });

    const previewBtn = document.createElement('button');
    previewBtn.className = 'media-action-btn';
    previewBtn.title = 'Preview';
    previewBtn.innerHTML = '<i class="fa-solid fa-eye"></i>';
    previewBtn.addEventListener('click', (e) => { e.stopPropagation(); showPreview(item); });

    const renameBtn = document.createElement('button');
    renameBtn.className = 'media-action-btn';
    renameBtn.title = 'Rename';
    renameBtn.innerHTML = '<i class="fa-solid fa-pen"></i>';
    renameBtn.addEventListener('click', (e) => { e.stopPropagation(); startRename(item, renameBtn); });

    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'media-action-btn media-delete-btn';
    deleteBtn.title = 'Delete';
    deleteBtn.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
    deleteBtn.addEventListener('click', (e) => { e.stopPropagation(); confirmDelete(item); });

    wrap.append(addBtn, previewBtn, renameBtn, deleteBtn);
    return wrap;
  }

  // ── Add to Timeline ────────────────────────────────────────────────────────
  function addToTimeline(item) {
    const store = window.StudioProjectStore;
    if (!store) {
      showToast('Editor not ready.', 'error');
      return;
    }

    const type = item.mediaType || item.media_type;
    const projectState = store.getState();
    const duration = parseFloat(item.duration) || 5.0;

    // Find the latest end time on the appropriate track
    const trackId = type === 'audio' ? 'track_audio_sfx' :
                    type === 'image' ? 'track_video_main' : 'track_video_main';
    const trackItems = projectState.items.filter(i => i.trackId === trackId);
    const startTime  = trackItems.reduce((max, i) => Math.max(max, i.start + i.duration), 0);

    // Add to media library registry first
    const mediaId = 'media_' + item.id;
    if (!projectState.mediaLibrary.find(m => m.id === mediaId)) {
      projectState.mediaLibrary.push({
        id:        mediaId,
        name:      item.name,
        url:       item.public_url || item.url,
        mimeType:  item.mime_type || item.mimeType,
        duration:  duration,
        width:     item.width,
        height:    item.height,
        thumbnail: item.thumbnail_url || item.thumbnailUrl || null
      });
    }

    const mediaUrl = item.public_url || item.url || item.publicUrl || null;

    const newItem = store.createTimelineItem({
      type:          type,
      trackId:       trackId,
      sourceMediaId: mediaId,
      url:           mediaUrl,
      name:          item.name,
      start:         startTime,
      duration:      duration,
      sourceStart:   0,
      sourceEnd:     duration,
    });

    const newState = JSON.parse(JSON.stringify(projectState));
    newState.items.push(newItem);

    // Extend project duration if needed
    const newEnd = startTime + duration;
    if (newEnd > newState.projectSettings.duration) {
      newState.projectSettings.duration = newEnd;
    }

    store.setState(newState);

    // Update legacy state bridge for backward-compat rendering
    if (window.CapCutEditor) {
      if (type === 'video') {
        const videoUrl = item.public_url || item.url;
        window.CapCutEditor.State.videoUrl = videoUrl;
        const videoEl = document.getElementById('capcutVideo');
        if (videoEl && videoUrl) {
          videoEl.src = videoUrl;
          videoEl.style.display = '';
          videoEl.load();
          videoEl.onloadedmetadata = function() {
            const realDuration = videoEl.duration;
            if (isFinite(realDuration) && realDuration > 0) {
              window.CapCutEditor.State.duration = Math.ceil(realDuration);
              window.CapCutEditor.State.videoClip.trimEnd = realDuration;
              window.CapCutEditor.State.videoClip.sourceDuration = realDuration;
              // Update the store item with real duration
              const st = store.getState();
              const stClone = JSON.parse(JSON.stringify(st));
              const clip = stClone.items.find(i => i.id === newItem.id);
              if (clip) {
                clip.duration = realDuration;
                clip.sourceEnd = realDuration;
              }
              if (realDuration > stClone.projectSettings.duration) {
                stClone.projectSettings.duration = realDuration;
              }
              store.setState(stClone);
            }
          };
        }
        window.CapCutEditor.State.duration = duration;
        window.CapCutEditor.State.videoClip.trimEnd = duration;
        window.CapCutEditor.State.videoClip.sourceDuration = duration;
      } else if (type === 'audio') {
        window.CapCutEditor.State.audioClips.push({
          id:       newItem.id,
          name:     item.name,
          type:     'custom',
          start:    startTime,
          duration: duration,
          volume:   100,
          url:      item.public_url || item.url
        });
      }
      if (window.CapCutEditor.TimelineManager) {
        window.CapCutEditor.TimelineManager.renderTimelineClips();
        window.CapCutEditor.TimelineManager.recalculateTimelineDuration();
      }
    }

    showToast('✅ Added to timeline: ' + item.name);
  }

  // ── Preview ────────────────────────────────────────────────────────────────
  function showPreview(item) {
    if (!els.preview || !els.previewEl) return;
    const type = item.mediaType || item.media_type;
    const url  = item.public_url || item.url;

    els.previewEl.innerHTML = '';

    if (type === 'video') {
      const vid = document.createElement('video');
      vid.src = url;
      vid.controls = true;
      vid.autoplay = true;
      vid.style.cssText = 'max-width:100%;max-height:70vh;border-radius:8px;';
      els.previewEl.appendChild(vid);
    } else if (type === 'image') {
      const img = document.createElement('img');
      img.src = url;
      img.alt = item.name;
      img.style.cssText = 'max-width:100%;max-height:70vh;border-radius:8px;object-fit:contain;';
      els.previewEl.appendChild(img);
    } else if (type === 'audio') {
      const wrapper = document.createElement('div');
      wrapper.style.cssText = 'text-align:center;padding:2rem;';
      const icon = document.createElement('div');
      icon.innerHTML = '<i class="fa-solid fa-music" style="font-size:4rem;color:#00f3ff;margin-bottom:1rem;display:block;"></i>';
      const nameEl = document.createElement('p');
      nameEl.style.color = '#fff';
      nameEl.textContent = item.name;
      const aud = document.createElement('audio');
      aud.src = url;
      aud.controls = true;
      aud.autoplay = true;
      aud.style.cssText = 'width:100%;margin-top:1rem;';
      wrapper.append(icon, nameEl, aud);
      els.previewEl.appendChild(wrapper);
    }

    // Preview metadata bar
    const metaBar = document.createElement('div');
    metaBar.className = 'media-preview-meta';
    const metaParts = [];
    if (item.width && item.height) metaParts.push(item.width + '×' + item.height);
    if (item.duration) metaParts.push(formatDuration(item.duration));
    if (item.fps) metaParts.push(Math.round(item.fps) + 'fps');
    if (item.file_size) metaParts.push(formatBytes(item.file_size));
    metaBar.textContent = item.name + (metaParts.length ? '  ·  ' + metaParts.join(' · ') : '');
    els.previewEl.appendChild(metaBar);

    els.preview.style.display = 'flex';
  }

  function closePreview() {
    if (els.preview) els.preview.style.display = 'none';
    if (els.previewEl) {
      // Pause and unload any media
      els.previewEl.querySelectorAll('video,audio').forEach(el => {
        el.pause();
        el.src = '';
      });
      els.previewEl.innerHTML = '';
    }
  }

  // ── Rename ─────────────────────────────────────────────────────────────────
  function startRename(item, btn) {
    const card = btn.closest('.media-card');
    if (!card) return;
    const nameEl = card.querySelector('.media-card-name');
    if (!nameEl) return;

    const currentName = item.name;
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentName;
    input.className = 'media-rename-input';
    nameEl.replaceWith(input);
    input.focus();
    input.select();

    function doRename() {
      const newName = input.value.trim();
      if (!newName || newName === currentName) {
        const restored = document.createElement('div');
        restored.className = 'media-card-name';
        restored.textContent = currentName;
        restored.title = currentName;
        input.replaceWith(restored);
        return;
      }
      const fd = new FormData();
      fd.append('action', 'rename');
      fd.append('id', item.id);
      fd.append('name', newName);
      fd.append('csrf_token', window.csrfToken || '');
      fetch(API, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          const finalName = data.success ? data.name : currentName;
          item.name = finalName;
          const restored = document.createElement('div');
          restored.className = 'media-card-name';
          restored.textContent = finalName;
          restored.title = finalName;
          input.replaceWith(restored);
          if (data.success) showToast('Renamed to: ' + finalName);
          else showToast('❌ ' + (data.message || 'Rename failed.'), 'error');
        })
        .catch(() => { input.replaceWith(document.createTextNode(currentName)); });
    }

    input.addEventListener('blur', doRename);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
      if (e.key === 'Escape') { input.value = currentName; input.blur(); }
    });
  }

  // ── Delete ─────────────────────────────────────────────────────────────────
  function confirmDelete(item) {
    if (!confirm(`Delete "${item.name}"?\nThis cannot be undone.`)) return;

    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', item.id);
    fd.append('csrf_token', window.csrfToken || '');

    fetch(API, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          state.media = state.media.filter(m => m.id !== item.id);
          document.querySelector(`.media-card[data-id="${item.id}"]`)?.remove();
          if (state.media.length === 0 && els.emptyState) els.emptyState.style.display = 'flex';
          showToast('🗑️ Deleted: ' + item.name);
        } else {
          showToast('❌ ' + (data.message || 'Delete failed.'), 'error');
        }
      })
      .catch(() => showToast('❌ Network error.', 'error'));
  }

  // ── Search / Filter / Sort ─────────────────────────────────────────────────
  function bindSearchFilterSort() {
    els.searchInput?.addEventListener('input', debounce(() => {
      state.search = els.searchInput.value;
      fetchLibrary();
    }, 350));

    els.filterBtns?.forEach(btn => {
      btn.addEventListener('click', () => {
        els.filterBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        state.filter = btn.dataset.mediaFilter;
        fetchLibrary();
      });
    });

    els.sortSelect?.addEventListener('change', () => {
      const val = els.sortSelect.value.split(':');
      state.sort  = val[0];
      state.order = val[1] || 'DESC';
      fetchLibrary();
    });

    // Preview close
    els.previewClose?.addEventListener('click', closePreview);
    els.preview?.addEventListener('click', (e) => {
      if (e.target === els.preview) closePreview();
    });
  }

  // ── Utilities ──────────────────────────────────────────────────────────────
  function formatDuration(secs) {
    secs = Math.round(secs);
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }

  function formatBytes(bytes) {
    if (bytes < 1024)       return bytes + ' B';
    if (bytes < 1048576)    return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
    return (bytes / 1073741824).toFixed(2) + ' GB';
  }

  function truncate(str, max) {
    return str.length > max ? str.slice(0, max) + '…' : str;
  }

  function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function debounce(fn, delay) {
    let timer;
    return function(...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  function showToast(msg, type = 'success') {
    if (window.CapCutEditor?.showSplitToast) {
      window.CapCutEditor.showSplitToast(msg);
      return;
    }
    const t = document.createElement('div');
    t.style.cssText = `
      position:fixed;bottom:80px;right:20px;z-index:9999;
      background:${type === 'error' ? '#ef4444' : '#10b981'};
      color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;
      box-shadow:0 4px 20px rgba(0,0,0,0.4);pointer-events:none;
    `;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
  }

  // ── Init ───────────────────────────────────────────────────────────────────
  function init() {
    initDOMRefs();
    if (!els.grid && !els.dropZone) return; // Not on the studio page

    bindUploadEvents();
    bindSearchFilterSort();
    fetchLibrary();
  }

  document.addEventListener('DOMContentLoaded', init);

  // Observe sidebar tab switches to refresh library when media tab activated
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-sidebar-tab]').forEach(btn => {
      btn.addEventListener('click', () => {
        if (btn.dataset.sidebarTab === 'media') {
          fetchLibrary();
        }
      });
    });
  });

  // ── Public API ─────────────────────────────────────────────────────────────
  window.StudioMedia = {
    cancelUpload,
    refresh: fetchLibrary,
    addToTimeline,
    showPreview,
    closePreview,
  };

})();
