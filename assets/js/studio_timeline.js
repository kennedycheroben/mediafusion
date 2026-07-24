/**
 * assets/js/studio_timeline.js
 *
 * Professional Multi-Track Timeline Engine for MediaFusion Studio.
 *
 * Capabilities:
 *  - Dynamic multi-track rendering (Video, Audio, Text, Caption, Overlay tracks)
 *  - Track controls: Name, type icon, Mute, Lock, Hide/Visible, Move Up/Down, Delete Track, Add Track
 *  - Clip manipulation: Add, Move (time + track), Trim (left/right handles), Split at playhead,
 *    Delete, Duplicate, Copy, Paste, Multi-Select (Shift/Ctrl + click)
 *  - Precision Playhead scrubbing & zooming (slider + Ctrl+wheel)
 *  - Magnetic Snapping to playhead and adjacent clip boundaries with visual snap guide line
 *  - Clip thumbnails & audio waveform representations
 *  - 100% single source of truth: all edits update StudioProjectStore, triggering PreviewEngine & Autosave
 */

(function () {
  'use strict';

  // ── Engine State ───────────────────────────────────────────────────────────
  const engine = {
    zoom:           25,      // Pixels per second
    selectedIds:    new Set(), // Multi-select ID set
    clipboard:      [],      // Copy/paste buffer
    snapEnabled:    true,    // Magnetic snapping toggle
    snapThreshold:  8,       // Snap distance in pixels (~0.3s)
    isScrubbing:    false,
    projectState:   null,
    rippleEnabled:  false,   // Magnetic timeline / Ripple edit mode toggle
  };

  // ── DOM References ─────────────────────────────────────────────────────────
  let els = {};

  function initDOMRefs() {
    els = {
      timeline:        document.querySelector('.capcut-timeline'),
      scrollArea:      document.getElementById('capcutTimelineScroll'),
      contentArea:     document.getElementById('capcutTimelineContent'),
      tracksContainer: document.getElementById('capcutTracksContainer'),
      rulerTicks:      document.getElementById('capcutRulerTicks'),
      playhead:        document.getElementById('capcutPlayhead'),
      playheadHandle:  document.getElementById('capcutPlayheadHandle'),
      timecode:        document.getElementById('capcutTimecode'),
      zoomSlider:      document.getElementById('capcutZoomRange'),
      // Buttons
      playBtn:         document.getElementById('capcutPlayBtn'),
      splitBtn:        document.getElementById('capcutSplitBtn'),
      copyBtn:         document.getElementById('capcutCopyBtn'),
      pasteBtn:        document.getElementById('capcutPasteBtn'),
      duplicateBtn:    document.getElementById('capcutDuplicateBtn'),
      reverseBtn:      document.getElementById('capcutReverseBtn'),
      freezeBtn:       document.getElementById('capcutFreezeBtn'),
      detachAudioBtn:  document.getElementById('capcutDetachAudioBtn'),
      replaceAudioBtn: document.getElementById('capcutReplaceAudioBtn'),
      deleteBtn:       document.getElementById('capcutDeleteBtn'),
      addTextBtn:      document.getElementById('capcutAddTextBtn'),
      addTrackBtn:     document.getElementById('capcutAddTrackBtn'),
      snapBtn:         document.getElementById('capcutSnapBtn'),
      rippleBtn:       document.getElementById('capcutRippleBtn'),
    };
  }

  // ── StudioProjectStore Subscriber ──────────────────────────────────────────
  function onStateChange(projectState) {
    engine.projectState = projectState;

    // Prune selections that no longer exist
    const validIds = new Set((projectState.items || []).map(i => i.id));
    engine.selectedIds.forEach(id => {
      if (!validIds.has(id)) engine.selectedIds.delete(id);
    });

    renderRuler();
    renderTracksAndClips();
    updatePlayheadPosition();
    updateToolbarButtonsState();
    if (els.rippleBtn) {
      els.rippleBtn.classList.toggle('active', engine.rippleEnabled);
    }
  }

  // ── Ruler Rendering ────────────────────────────────────────────────────────
  function renderRuler() {
    if (!els.rulerTicks || !engine.projectState) return;
    els.rulerTicks.innerHTML = '';

    const duration = engine.projectState.projectSettings?.duration || 15;
    const totalWidth = timeToX(duration);
    if (els.contentArea) {
      els.contentArea.style.width = Math.max(totalWidth + 300, els.scrollArea?.clientWidth || 800) + 'px';
    }

    const minSpacing = 80;
    let labelInterval = 1;
    const intervals = [0.1, 0.2, 0.5, 1, 2, 5, 10, 15, 30, 60, 120, 300, 600];
    for (let i of intervals) {
      if (i * engine.zoom >= minSpacing) {
        labelInterval = i;
        break;
      }
    }
    const increment = labelInterval / 10;

    for (let time = 0; time <= duration + increment; time += increment) {
      if (time > duration) time = duration;
      const tick = document.createElement('div');
      tick.className = 'capcut-ruler-tick';
      
      const isMajor = Math.abs((time % labelInterval)) < 0.001;
      const isMiddle = !isMajor && Math.abs((time % (labelInterval / 2))) < 0.001;
      
      if (isMajor) {
        tick.className += ' major';
        tick.innerText = formatTimecode(time);
      } else if (isMiddle) {
        tick.className += ' middle';
        tick.style.height = '8px';
      }
      
      tick.style.left = timeToX(time) + 'px';
      els.rulerTicks.appendChild(tick);
      
      if (time === duration) break;
    }
  }

  // ── Multi-Track & Clip Rendering ───────────────────────────────────────────
  function renderTracksAndClips() {
    if (!els.tracksContainer || !engine.projectState) return;
    els.tracksContainer.innerHTML = '';

    const tracks  = engine.projectState.tracks || [];
    const items   = engine.projectState.items || [];
    const library = engine.projectState.mediaLibrary || [];

    // Sort tracks by zIndex descending for timeline UI (highest layer on top)
    const sortedTracks = [...tracks].sort((a, b) => (b.zIndex ?? 0) - (a.zIndex ?? 0));

    sortedTracks.forEach(track => {
      const trackRow = createTrackRowElement(track);
      const lane = trackRow.querySelector('.capcut-track-lane');

      // Filter items belonging to this track
      const trackItems = items.filter(i => i.trackId === track.id);
      trackItems.forEach(item => {
        const media = library.find(m => m.id === item.sourceMediaId);
        const clipEl = createClipElement(item, track, media);
        lane.appendChild(clipEl);
      });

      els.tracksContainer.appendChild(trackRow);
    });
  }

  function createTrackRowElement(track) {
    const row = document.createElement('div');
    row.className = 'capcut-track-row';
    row.dataset.trackId = track.id;
    row.dataset.trackType = track.type;
    if (track.locked) row.classList.add('track-locked');
    if (!track.visible) row.classList.add('track-hidden');

    // Track Header / Controls Column
    const header = document.createElement('div');
    header.className = 'capcut-track-header';

    const typeIcon = track.type === 'video'   ? 'fa-film' :
                     track.type === 'audio'   ? 'fa-music' :
                     track.type === 'text'    ? 'fa-font' :
                     track.type === 'caption' ? 'fa-closed-captioning' :
                     track.type === 'overlay' ? 'fa-layer-group' : 'fa-icons';

    header.innerHTML = `
      <div class="track-header-left">
        <i class="fa-solid ${typeIcon} track-type-icon"></i>
        <span class="track-name" title="${escHtml(track.name)}">${escHtml(track.name)}</span>
      </div>
      <div class="track-controls">
        <button class="track-ctrl-btn" title="Move Track Up" onclick="StudioTimeline.moveTrackUp('${track.id}')">
          <i class="fa-solid fa-chevron-up"></i>
        </button>
        <button class="track-ctrl-btn" title="Move Track Down" onclick="StudioTimeline.moveTrackDown('${track.id}')">
          <i class="fa-solid fa-chevron-down"></i>
        </button>
        <button class="track-ctrl-btn ${track.muted ? 'active' : ''}" title="${track.muted ? 'Unmute Track' : 'Mute Track'}" onclick="StudioTimeline.toggleTrackMute('${track.id}')">
          <i class="fa-solid ${track.muted ? 'fa-volume-xmark' : 'fa-volume-high'}"></i>
        </button>
        <button class="track-ctrl-btn ${track.locked ? 'active' : ''}" title="${track.locked ? 'Unlock Track' : 'Lock Track'}" onclick="StudioTimeline.toggleTrackLock('${track.id}')">
          <i class="fa-solid ${track.locked ? 'fa-lock' : 'fa-lock-open'}"></i>
        </button>
        <button class="track-ctrl-btn ${!track.visible ? 'active' : ''}" title="${track.visible ? 'Hide Track' : 'Show Track'}" onclick="StudioTimeline.toggleTrackVisibility('${track.id}')">
          <i class="fa-solid ${track.visible ? 'fa-eye' : 'fa-eye-slash'}"></i>
        </button>
        <button class="track-ctrl-btn track-del-btn" title="Delete Track" onclick="StudioTimeline.deleteTrack('${track.id}')">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
    `;

    // Track Lane
    const lane = document.createElement('div');
    lane.className = 'capcut-track-lane';
    lane.dataset.trackId = track.id;

    row.appendChild(header);
    row.appendChild(lane);

    return row;
  }

  function createClipElement(item, track, media) {
    const clip = document.createElement('div');
    clip.className = `capcut-clip ${item.type}-clip`;
    clip.dataset.id = item.id;
    clip.dataset.type = item.type;
    clip.dataset.trackId = item.trackId;

    if (engine.selectedIds.has(item.id)) {
      clip.classList.add('selected');
    }

    const leftPx = timeToX(item.start);
    const widthPx = Math.max(12, timeToX(item.duration));

    clip.style.left  = leftPx + 'px';
    clip.style.width = widthPx + 'px';

    // Background styling / Thumbnails / Waveform
    const thumbUrl = media?.thumbnail || item.thumbnail || (item.type === 'image' ? item.url : null);
    if ((item.type === 'video' || item.type === 'image') && thumbUrl) {
      const thumbBg = document.createElement('div');
      thumbBg.className = 'clip-thumb-strip';
      thumbBg.style.backgroundImage = `url('${thumbUrl}')`;
      clip.appendChild(thumbBg);
    } else if (item.type === 'audio' || (item.type === 'video' && !thumbUrl)) {
      const waveform = document.createElement('div');
      waveform.className = 'clip-audio-waveform';
      waveform.innerHTML = generateProceduralWaveformSVG(widthPx, item.id, item.type);
      clip.appendChild(waveform);
    }

    // Clip label
    const label = document.createElement('span');
    label.className = 'clip-label';
    label.textContent = item.content || item.name || (item.type.toUpperCase() + ' Clip');
    clip.appendChild(label);

    // Duration badge
    const durBadge = document.createElement('span');
    durBadge.className = 'clip-duration-tag';
    durBadge.textContent = item.duration.toFixed(1) + 's';
    clip.appendChild(durBadge);

    // Trimming handles (only if track not locked)
    if (!track.locked) {
      const leftHandle = document.createElement('div');
      leftHandle.className = 'capcut-clip-handle left-handle';
      const rightHandle = document.createElement('div');
      rightHandle.className = 'capcut-clip-handle right-handle';
      clip.appendChild(leftHandle);
      clip.appendChild(rightHandle);
    }

    // Clip interaction events
    clip.addEventListener('click', (e) => {
      e.stopPropagation();
      handleClipClick(item.id, e);
    });

    // Transition Badge / Slot
    const trackItems = (engine.projectState?.items || []).filter(i => i.trackId === item.trackId && i.id !== item.id);
    const adjacentLeft = trackItems.find(i => Math.abs((i.start + i.duration) - item.start) < 0.25);
    
    if (adjacentLeft || item.transitionIn) {
      const transBadge = document.createElement('div');
      transBadge.className = 'clip-transition-badge' + (item.transitionIn ? ' active' : ' empty');
      transBadge.innerHTML = item.transitionIn 
        ? `<i class="fa-solid fa-shuffle"></i>` 
        : `<i class="fa-solid fa-plus"></i>`;
      transBadge.title = item.transitionIn 
        ? `Transition: ${item.transitionIn.name} (${item.transitionIn.duration}s). Click to edit.` 
        : `Add transition between clips.`;
      
      transBadge.addEventListener('click', (e) => {
        e.stopPropagation();
        handleClipClick(item.id, e);
        const navTab = document.querySelector('.capcut-nav-item[data-sidebar-tab="transitions"]');
        if (navTab) navTab.click();
      });
      clip.appendChild(transBadge);
    }

    if (!track.locked) {
      makeClipDraggableAndResizable(clip, item);
    }

    return clip;
  }

  // ── Clip Selection Handling ────────────────────────────────────────────────
  function handleClipClick(itemId, e) {
    if (e.shiftKey || e.ctrlKey || e.metaKey) {
      // Toggle selection in multi-select set
      if (engine.selectedIds.has(itemId)) {
        engine.selectedIds.delete(itemId);
      } else {
        engine.selectedIds.add(itemId);
      }
    } else {
      // Single select
      engine.selectedIds.clear();
      engine.selectedIds.add(itemId);
    }

    // Update DOM selection classes
    document.querySelectorAll('.capcut-clip').forEach(el => {
      if (engine.selectedIds.has(el.dataset.id)) {
        el.classList.add('selected');
      } else {
        el.classList.remove('selected');
      }
    });

    // Sync state for inspector panel
    const firstSelectedId = Array.from(engine.selectedIds)[0] || null;
    if (window.CapCutEditor?.State) {
      window.CapCutEditor.State.selectedId = firstSelectedId;
      const item = engine.projectState?.items?.find(i => i.id === firstSelectedId);
      window.CapCutEditor.State.selectedType = item?.type || null;
      if (window.CapCutEditor.renderInspector) {
        window.CapCutEditor.renderInspector();
      }
    }

    updateToolbarButtonsState();
  }

  function clearSelection() {
    engine.selectedIds.clear();
    document.querySelectorAll('.capcut-clip').forEach(el => el.classList.remove('selected'));
    if (window.CapCutEditor?.State) {
      window.CapCutEditor.State.selectedId = null;
      window.CapCutEditor.State.selectedType = null;
      if (window.CapCutEditor.renderInspector) {
        window.CapCutEditor.renderInspector();
      }
    }
    updateToolbarButtonsState();
  }

  // ── Dragging & Trimming Engine with Magnetic Snapping ──────────────────────
  function makeClipDraggableAndResizable(clipEl, item) {
    let startX = 0;
    let originalStart = item.start;
    let originalDuration = item.duration;
    let isDragging = false;
    let dragMode = 'move'; // 'move' | 'trim-left' | 'trim-right'
    let initialSelectedTimes = new Map(); // id → start

    clipEl.addEventListener('pointerdown', (e) => {
      e.stopPropagation();
      isDragging = true;
      startX = e.clientX;
      originalStart = item.start;
      originalDuration = item.duration;

      clipEl.setPointerCapture(e.pointerId);

      if (e.target.classList.contains('left-handle')) {
        dragMode = 'trim-left';
      } else if (e.target.classList.contains('right-handle')) {
        dragMode = 'trim-right';
      } else {
        dragMode = 'move';
      }

      // Ensure clicked item is part of selection
      if (!engine.selectedIds.has(item.id)) {
        if (!e.shiftKey && !e.ctrlKey) engine.selectedIds.clear();
        engine.selectedIds.add(item.id);
      }

      // Record initial start times of all selected items for multi-move
      initialSelectedTimes.clear();
      engine.selectedIds.forEach(id => {
        const it = engine.projectState?.items?.find(x => x.id === id);
        if (it) initialSelectedTimes.set(id, it.start);
      });
    });

    clipEl.addEventListener('pointermove', (e) => {
      if (!isDragging) return;

      const dx = e.clientX - startX;
      const deltaTime = xToTime(dx);
      const currentMaxTime = engine.projectState?.projectSettings?.duration ?? 15;

      if (dragMode === 'move') {
        let targetStart = Math.max(0, originalStart + deltaTime);

        // Apply Magnetic Snapping
        if (engine.snapEnabled) {
          targetStart = getSnappedTime(targetStart, originalDuration, item.id);
        }

        const actualDelta = targetStart - originalStart;
        let dragMaxEnd = currentMaxTime;

        // Move all selected items together
        engine.selectedIds.forEach(selectedId => {
          const selectedEl = document.querySelector(`.capcut-clip[data-id="${selectedId}"]`);
          const initStart  = initialSelectedTimes.get(selectedId) ?? 0;
          const newStart   = Math.max(0, initStart + actualDelta);
          if (selectedEl) {
            selectedEl.style.left = timeToX(newStart) + 'px';
          }
          const it = engine.projectState?.items?.find(x => x.id === selectedId);
          if (it) {
            dragMaxEnd = Math.max(dragMaxEnd, newStart + it.duration);
          }
        });

        if (dragMaxEnd > currentMaxTime) {
          expandTimelineWidth(dragMaxEnd);
        }

        // Vertical Track Hover Check (moving across compatible tracks)
        checkTrackHover(e, item);

      } else if (dragMode === 'trim-left') {
        let newStart = Math.max(0, originalStart + deltaTime);
        let newDur   = originalDuration - (newStart - originalStart);

        if (engine.snapEnabled) {
          newStart = getSnappedTime(newStart, 0, item.id);
          newDur   = originalDuration - (newStart - originalStart);
        }

        if (newDur >= 0.2) {
          clipEl.style.left  = timeToX(newStart) + 'px';
          clipEl.style.width = timeToX(newDur) + 'px';
        }

      } else if (dragMode === 'trim-right') {
        let newDur = Math.max(0.2, originalDuration + deltaTime);

        if (engine.snapEnabled) {
          const rightEdge = getSnappedTime(originalStart + newDur, 0, item.id);
          newDur = Math.max(0.2, rightEdge - originalStart);
        }

        clipEl.style.width = timeToX(newDur) + 'px';

        const dragMaxEnd = originalStart + newDur;
        if (dragMaxEnd > currentMaxTime) {
          expandTimelineWidth(dragMaxEnd);
        }
      }
    });

    clipEl.addEventListener('pointerup', (e) => {
      if (!isDragging) return;
      isDragging = false;
      clipEl.releasePointerCapture(e.pointerId);
      hideSnapGuide();

      const dx = e.clientX - startX;
      const deltaTime = xToTime(dx);

      const store = window.StudioProjectStore;
      if (!store) return;
      const newState = JSON.parse(JSON.stringify(store.getState()));

      if (dragMode === 'move') {
        let targetStart = Math.max(0, originalStart + deltaTime);
        if (engine.snapEnabled) {
          targetStart = getSnappedTime(targetStart, originalDuration, item.id);
        }
        const actualDelta = targetStart - originalStart;

        // Check target track from hover
        const targetTrackId = getTargetTrackFromPointer(e, item.type) || item.trackId;

        newState.items.forEach(it => {
          if (engine.selectedIds.has(it.id)) {
            const initStart = initialSelectedTimes.get(it.id) ?? it.start;
            it.start = Math.max(0, initStart + actualDelta);
            if (it.id === item.id) {
              it.trackId = targetTrackId;
            }
          }
        });

      } else if (dragMode === 'trim-left') {
        let newStart = Math.max(0, originalStart + deltaTime);
        let newDur   = originalDuration - (newStart - originalStart);
        if (engine.snapEnabled) {
          newStart = getSnappedTime(newStart, 0, item.id);
          newDur   = originalDuration - (newStart - originalStart);
        }
        if (newDur >= 0.2) {
          const targetItem = newState.items.find(x => x.id === item.id);
          if (targetItem) {
            const trimmedSec = newStart - targetItem.start;
            targetItem.start = newStart;
            targetItem.duration = newDur;
            targetItem.sourceStart = Math.max(0, (targetItem.sourceStart || 0) + trimmedSec);

            if (engine.rippleEnabled) {
              newState.items.forEach(it => {
                if (it.trackId === targetItem.trackId && it.start > targetItem.start && it.id !== targetItem.id) {
                  it.start = Math.max(0, it.start - trimmedSec);
                }
              });
            }
          }
        }

      } else if (dragMode === 'trim-right') {
        let newDur = Math.max(0.2, originalDuration + deltaTime);
        if (engine.snapEnabled) {
          const rightEdge = getSnappedTime(originalStart + newDur, 0, item.id);
          newDur = Math.max(0.2, rightEdge - originalStart);
        }
        const targetItem = newState.items.find(x => x.id === item.id);
        if (targetItem) {
          const trimmedSec = newDur - targetItem.duration;
          targetItem.duration = newDur;
          targetItem.sourceEnd = (targetItem.sourceStart || 0) + newDur;

          if (engine.rippleEnabled) {
            newState.items.forEach(it => {
              if (it.trackId === targetItem.trackId && it.start > targetItem.start && it.id !== targetItem.id) {
                it.start = Math.max(0, it.start + trimmedSec);
              }
            });
          }
        }
      }

      // Recalculate duration
      recalculateProjectDuration(newState);
      store.setState(newState);
    });
  }

  // ── Snapping Helper ────────────────────────────────────────────────────────
  function getSnappedTime(time, duration = 0, excludeItemId = null) {
    if (!engine.projectState) return time;

    const snapPoints = [];

    // 1. Snap to Playhead
    const currentPlayheadTime = window.PreviewEngine?.currentTime ?? 0;
    snapPoints.push(currentPlayheadTime);

    // 2. Snap to clip boundaries (start & end of all items)
    (engine.projectState.items || []).forEach(it => {
      if (it.id === excludeItemId) return;
      snapPoints.push(it.start);
      snapPoints.push(it.start + it.duration);
    });

    const snapThresholdSec = xToTime(engine.snapThreshold);

    // Check start edge
    for (const pt of snapPoints) {
      if (Math.abs(time - pt) <= snapThresholdSec) {
        showSnapGuide(pt);
        return pt;
      }
    }

    // Check end edge (if duration > 0)
    if (duration > 0) {
      const endEdge = time + duration;
      for (const pt of snapPoints) {
        if (Math.abs(endEdge - pt) <= snapThresholdSec) {
          const snappedStart = pt - duration;
          showSnapGuide(pt);
          return snappedStart;
        }
      }
    }

    hideSnapGuide();
    return time;
  }

  function showSnapGuide(snapTimeSec) {
    let guide = document.getElementById('capcutSnapGuide');
    if (!guide) {
      guide = document.createElement('div');
      guide.id = 'capcutSnapGuide';
      guide.className = 'capcut-snap-guide';
      els.contentArea?.appendChild(guide);
    }
    guide.style.left = timeToX(snapTimeSec) + 'px';
    guide.style.display = 'block';
  }

  function hideSnapGuide() {
    const guide = document.getElementById('capcutSnapGuide');
    if (guide) guide.style.display = 'none';
  }

  function checkTrackHover(e, item) {
    const targetTrackId = getTargetTrackFromPointer(e, item.type);
    document.querySelectorAll('.capcut-track-row').forEach(row => {
      if (row.dataset.trackId === targetTrackId) {
        row.classList.add('track-drop-hover');
      } else {
        row.classList.remove('track-drop-hover');
      }
    });
  }

  function getTargetTrackFromPointer(e, clipType) {
    const el = document.elementFromPoint(e.clientX, e.clientY);
    const row = el?.closest('.capcut-track-row');
    if (!row) return null;

    const trackId   = row.dataset.trackId;
    const trackType = row.dataset.trackType;

    // Check track lock
    const track = engine.projectState?.tracks?.find(t => t.id === trackId);
    if (track && track.locked) return null;

    // Type compatibility check
    if (clipType === 'video'   && trackType !== 'video') return null;
    if (clipType === 'audio'   && trackType !== 'audio') return null;
    if ((clipType === 'text' || clipType === 'sticker' || clipType === 'caption') &&
        (trackType !== 'text' && trackType !== 'caption' && trackType !== 'video')) return null;

    return trackId;
  }

  // ── Timeline Toolbar Operations ────────────────────────────────────────────

  // 1. SPLIT CLIP at Playhead
  function splitSelectedClips() {
    const store = window.StudioProjectStore;
    if (!store) return;

    const currentTime = window.PreviewEngine?.currentTime ?? 0;
    const newState = JSON.parse(JSON.stringify(store.getState()));

    let splitOccurred = false;

    // If items selected, split only selected; otherwise split any clip under playhead
    const targetIds = engine.selectedIds.size > 0
      ? Array.from(engine.selectedIds)
      : newState.items.filter(i => currentTime > i.start && currentTime < i.start + i.duration).map(i => i.id);

    targetIds.forEach(id => {
      const idx = newState.items.findIndex(i => i.id === id);
      if (idx === -1) return;

      const item = newState.items[idx];
      const track = newState.tracks.find(t => t.id === item.trackId);
      if (track && track.locked) return; // skip locked track clips

      if (currentTime <= item.start || currentTime >= item.start + item.duration) return;

      const firstDur  = currentTime - item.start;
      const secondDur = item.duration - firstDur;

      // First part: shorten duration
      item.duration  = firstDur;
      item.sourceEnd = (item.sourceStart || 0) + firstDur;

      // Second part: clone as new item starting at playhead
      const newItem = store.createTimelineItem({
        type:          item.type,
        trackId:       item.trackId,
        sourceMediaId: item.sourceMediaId,
        name:          item.name + ' (Part 2)',
        start:         currentTime,
        duration:      secondDur,
        sourceStart:   (item.sourceStart || 0) + firstDur,
        sourceEnd:     item.sourceEnd,
        speed:         item.speed,
        position:      { ...item.position },
        scale:         { ...item.scale },
        rotation:      item.rotation,
        opacity:       item.opacity,
        volume:        item.volume,
        content:       item.content,
        style:         { ...item.style }
      });

      newState.items.splice(idx + 1, 0, newItem);
      splitOccurred = true;
    });

    if (splitOccurred) {
      store.setState(newState);
      showToast('✂️ Split clip at playhead');
    }
  }

  // 2. DELETE CLIP
  function deleteSelectedClips() {
    if (engine.selectedIds.size === 0) return;

    const store = window.StudioProjectStore;
    if (!store) return;

    const newState = JSON.parse(JSON.stringify(store.getState()));

    if (engine.rippleEnabled) {
      const clipsToDelete = newState.items.filter(i => engine.selectedIds.has(i.id));
      const byTrack = {};
      clipsToDelete.forEach(c => {
        const tr = newState.tracks.find(t => t.id === c.trackId);
        if (tr && tr.locked) return; // skip locked
        if (!byTrack[c.trackId]) byTrack[c.trackId] = [];
        byTrack[c.trackId].push(c);
      });

      Object.keys(byTrack).forEach(trackId => {
        const trackClipsToDelete = byTrack[trackId].sort((a, b) => b.start - a.start);
        trackClipsToDelete.forEach(delClip => {
          const shiftStart = delClip.start;
          const shiftAmount = delClip.duration;
          newState.items.forEach(it => {
            if (it.trackId === trackId && it.start > shiftStart && !engine.selectedIds.has(it.id)) {
              it.start = Math.max(0, it.start - shiftAmount);
            }
          });
        });
      });
    }

    newState.items = newState.items.filter(i => {
      if (engine.selectedIds.has(i.id)) {
        const track = newState.tracks.find(t => t.id === i.trackId);
        if (track && track.locked) return true; // keep locked track clips
        return false;
      }
      return true;
    });

    engine.selectedIds.clear();
    recalculateProjectDuration(newState);
    store.setState(newState);
    showToast('🗑️ Deleted selected clip(s)');
  }

  // 3. DUPLICATE CLIP
  function duplicateSelectedClips() {
    if (engine.selectedIds.size === 0) return;

    const store = window.StudioProjectStore;
    if (!store) return;

    const newState = JSON.parse(JSON.stringify(store.getState()));
    const newSelectedIds = new Set();

    engine.selectedIds.forEach(id => {
      const item = newState.items.find(i => i.id === id);
      if (!item) return;

      const track = newState.tracks.find(t => t.id === item.trackId);
      if (track && track.locked) return; // skip locked track clips

      const dup = store.createTimelineItem({
        ...JSON.parse(JSON.stringify(item)),
        id:          store.generateUUID('clip_' + item.type),
        name:        item.name + ' (Copy)',
        start:       item.start + item.duration + 0.2 // place immediately after
      });

      newState.items.push(dup);
      newSelectedIds.add(dup.id);
    });

    engine.selectedIds = newSelectedIds;
    recalculateProjectDuration(newState);
    store.setState(newState);
    showToast('📋 Duplicated clip(s)');
  }

  // 4. COPY CLIPS
  function copySelectedClips() {
    if (engine.selectedIds.size === 0) return;
    engine.clipboard = [];
    engine.selectedIds.forEach(id => {
      const item = engine.projectState?.items?.find(i => i.id === id);
      if (item) engine.clipboard.push(JSON.parse(JSON.stringify(item)));
    });
    showToast(`📋 Copied ${engine.clipboard.length} clip(s)`);
    updateToolbarButtonsState();
  }

  // 5. PASTE CLIPS
  function pasteClips() {
    if (engine.clipboard.length === 0) return;

    const store = window.StudioProjectStore;
    if (!store) return;

    const pasteTime = window.PreviewEngine?.currentTime ?? 0;
    const newState  = JSON.parse(JSON.stringify(store.getState()));

    // Find min start of clipboard items for offset
    const minStart = Math.min(...engine.clipboard.map(c => c.start));
    const newSelectedIds = new Set();

    engine.clipboard.forEach(clip => {
      const track = newState.tracks.find(t => t.id === clip.trackId);
      if (track && track.locked) return; // skip locked track paste

      const offset = clip.start - minStart;
      const dup = store.createTimelineItem({
        ...JSON.parse(JSON.stringify(clip)),
        id:    store.generateUUID('clip_' + clip.type),
        name:  clip.name + ' (Pasted)',
        start: pasteTime + offset
      });

      newState.items.push(dup);
      newSelectedIds.add(dup.id);
    });

    engine.selectedIds = newSelectedIds;
    recalculateProjectDuration(newState);
    store.setState(newState);
    showToast(`📌 Pasted ${engine.clipboard.length} clip(s)`);
  }

  // 6. ADD TRACK
  function addTrack(type = 'video', name = null) {
    const store = window.StudioProjectStore;
    if (!store) return;

    const newState = JSON.parse(JSON.stringify(store.getState()));
    const tracks   = newState.tracks || [];

    const countOfType = tracks.filter(t => t.type === type).length + 1;
    const defaultName = name || (type.charAt(0).toUpperCase() + type.slice(1) + ' Track ' + countOfType);

    // Calculate zIndex
    const maxZ = tracks.reduce((m, t) => Math.max(m, t.zIndex ?? 0), 0);

    const newTrack = {
      id:      store.generateUUID('track_' + type),
      type:    type,
      name:    defaultName,
      muted:   false,
      locked:  false,
      visible: true,
      zIndex:  maxZ + 1
    };

    newState.tracks.push(newTrack);
    store.setState(newState);
    showToast(`➕ Added ${defaultName}`);
  }

  // 7. DELETE TRACK
  function deleteTrack(trackId) {
    const store = window.StudioProjectStore;
    if (!store) return;

    const newState = JSON.parse(JSON.stringify(store.getState()));
    if (newState.tracks.length <= 1) {
      showToast('❌ Cannot delete the last track', 'error');
      return;
    }

    newState.tracks = newState.tracks.filter(t => t.id !== trackId);
    newState.items  = newState.items.filter(i => i.trackId !== trackId);

    store.setState(newState);
    showToast('🗑️ Track deleted');
  }

  // 8. TOGGLE TRACK CONTROLS
  function toggleTrackMute(trackId) {
    updateTrackProp(trackId, t => { t.muted = !t.muted; });
  }

  function toggleTrackLock(trackId) {
    updateTrackProp(trackId, t => { t.locked = !t.locked; });
  }

  function toggleTrackVisibility(trackId) {
    updateTrackProp(trackId, t => { t.visible = !t.visible; });
  }

  function updateTrackProp(trackId, modifier) {
    const store = window.StudioProjectStore;
    if (!store) return;
    const newState = JSON.parse(JSON.stringify(store.getState()));
    const track = newState.tracks.find(t => t.id === trackId);
    if (track) {
      modifier(track);
      store.setState(newState);
    }
  }

  // ── Utilities & Helper Functions ────────────────────────────────────────────
  function timeToX(timeSec) {
    return timeSec * engine.zoom;
  }

  function expandTimelineWidth(duration) {
    const totalWidth = timeToX(duration);
    if (els.contentArea) {
      els.contentArea.style.width = Math.max(totalWidth + 300, els.scrollArea?.clientWidth || 800) + 'px';
    }
    if (els.rulerTicks) {
      els.rulerTicks.innerHTML = '';
      const minSpacing = 80;
      let labelInterval = 1;
      const intervals = [0.1, 0.2, 0.5, 1, 2, 5, 10, 15, 30, 60, 120, 300, 600];
      for (let i of intervals) {
        if (i * engine.zoom >= minSpacing) {
          labelInterval = i;
          break;
        }
      }
      const increment = labelInterval / 10;

      for (let time = 0; time <= duration + increment; time += increment) {
        if (time > duration) time = duration;
        const tick = document.createElement('div');
        tick.className = 'capcut-ruler-tick';
        
        const isMajor = Math.abs((time % labelInterval)) < 0.001;
        const isMiddle = !isMajor && Math.abs((time % (labelInterval / 2))) < 0.001;
        
        if (isMajor) {
          tick.className += ' major';
          tick.innerText = formatTimecode(time);
        } else if (isMiddle) {
          tick.className += ' middle';
          tick.style.height = '8px';
        }
        
        tick.style.left = timeToX(time) + 'px';
        els.rulerTicks.appendChild(tick);
        
        if (time === duration) break;
      }
    }
  }

  function xToTime(px) {
    return px / engine.zoom;
  }

  function formatTimecode(sec) {
    const min = Math.floor(sec / 60);
    const s   = Math.floor(sec % 60);
    const ms  = Math.floor((sec % 1) * 100);
    return pad(min) + ':' + pad(s) + '.' + pad(ms);
  }

  function pad(n) { return String(n).padStart(2, '0'); }

  function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function recalculateProjectDuration(stateObj) {
    let maxEnd = 15.0; // floor
    (stateObj.items || []).forEach(it => {
      maxEnd = Math.max(maxEnd, it.start + it.duration);
    });
    stateObj.projectSettings.duration = Math.ceil(maxEnd);
  }

  function updateToolbarButtonsState() {
    const hasSelection = engine.selectedIds.size > 0;
    if (els.splitBtn)     els.splitBtn.disabled = false; // can split at playhead
    if (els.deleteBtn)    els.deleteBtn.disabled = !hasSelection;
    if (els.duplicateBtn) els.duplicateBtn.disabled = !hasSelection;
    if (els.copyBtn)      els.copyBtn.disabled = !hasSelection;
    if (els.pasteBtn)     els.pasteBtn.disabled = (engine.clipboard.length === 0);

    let hasVideoSelected = false;
    let hasAudioOrVideoSelected = false;
    if (hasSelection && engine.projectState?.items) {
      engine.selectedIds.forEach(id => {
        const item = engine.projectState.items.find(i => i.id === id);
        if (item) {
          if (item.type === 'video') {
            hasVideoSelected = true;
            hasAudioOrVideoSelected = true;
          }
          if (item.type === 'audio') {
            hasAudioOrVideoSelected = true;
          }
        }
      });
    }

    if (els.reverseBtn) els.reverseBtn.disabled = !hasAudioOrVideoSelected;
    if (els.freezeBtn)  els.freezeBtn.disabled = !hasVideoSelected;
    if (els.detachAudioBtn) els.detachAudioBtn.disabled = !hasVideoSelected;
    if (els.replaceAudioBtn) els.replaceAudioBtn.disabled = !hasAudioOrVideoSelected;
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
    if (document.body) document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
  }

  function updatePlayheadPosition() {
    if (!els.playhead) return;
    const currentTime = window.PreviewEngine?.currentTime ?? 0;
    const duration    = engine.projectState?.projectSettings?.duration ?? 15;

    const playheadX = timeToX(currentTime);
    els.playhead.style.left = playheadX + 'px';

    if (els.timecode) {
      els.timecode.textContent = `${formatTimecode(currentTime)} | ${formatTimecode(duration)}`;
    }

    // Auto-scroll horizontal container during playback if playhead moves near screen edge
    if (window.PreviewEngine?.isPlaying && els.scrollArea) {
      const scrollLeft = els.scrollArea.scrollLeft;
      const clientWidth = els.scrollArea.clientWidth;
      if (playheadX > scrollLeft + clientWidth - 80 || playheadX < scrollLeft) {
        els.scrollArea.scrollLeft = Math.max(0, playheadX - 100);
      }
    }
  }

  function moveTrackUp(trackId) {
    if (window.StudioProjectStore?.moveTrack) {
      window.StudioProjectStore.moveTrack(trackId, 'up');
      showToast('⬆️ Moved track up');
    }
  }

  function moveTrackDown(trackId) {
    if (window.StudioProjectStore?.moveTrack) {
      window.StudioProjectStore.moveTrack(trackId, 'down');
      showToast('⬇️ Moved track down');
    }
  }

  function selectAllClips() {
    if (!engine.projectState) return;
    engine.selectedIds.clear();
    (engine.projectState.items || []).forEach(it => engine.selectedIds.add(it.id));
    document.querySelectorAll('.capcut-clip').forEach(el => el.classList.add('selected'));
    updateToolbarButtonsState();
    showToast(`✨ Selected all ${engine.selectedIds.size} clip(s)`);
  }

  function generateProceduralWaveformSVG(widthPx, seedStr = 'seed', type = 'audio') {
    const numBars = Math.max(12, Math.floor(widthPx / 4));
    let seed = 0;
    for (let i = 0; i < seedStr.length; i++) seed += seedStr.charCodeAt(i);

    let pathD = '';
    const midY = 20;
    const barWidth = Math.max(1, (widthPx / numBars) * 0.6);
    const color = type === 'audio' ? 'rgba(0,255,102,0.4)' : 'rgba(0,243,255,0.3)';

    for (let i = 0; i < numBars; i++) {
      const x = (i / numBars) * widthPx;
      const noise = Math.sin(i * 0.45 + seed) * Math.cos(i * 0.85 + seed);
      const height = Math.max(2, Math.min(18, Math.abs(noise) * 18 + 2));
      pathD += `M ${x.toFixed(1)} ${(midY - height).toFixed(1)} L ${x.toFixed(1)} ${(midY + height).toFixed(1)} `;
    }

    return `<svg viewBox="0 0 ${Math.max(10, widthPx)} 40" preserveAspectRatio="none"><path d="${pathD}" stroke="${color}" stroke-width="${barWidth.toFixed(1)}" stroke-linecap="round"/></svg>`;
  }

  function bindEvents() {
    // Click timeline background to clear selection if not dragging rubberband
    els.contentArea?.addEventListener('click', (e) => {
      if (!e.target.closest('.capcut-clip') && !e.target.closest('.capcut-rubberband')) {
        clearSelection();
      }
    });

    // Playhead Scrubbing & Marquee Rubberband Selection inside Timeline Scroll Area
    if (els.scrollArea) {
      let isInteracting = false;
      let interactMode = null; // 'scrub' | 'rubberband'
      let startX = 0, startY = 0;
      let rubberbandEl = null;

      function updateScrub(e) {
        const laneRect = els.contentArea?.getBoundingClientRect();
        if (!laneRect) return;
        const x = e.clientX - laneRect.left;
        const targetTime = Math.max(0, xToTime(x));
        if (window.PreviewEngine) {
          window.PreviewEngine.seek(targetTime);
        }
      }

      els.scrollArea.addEventListener('pointerdown', (e) => {
        if (e.target.closest('.capcut-clip') || e.target.closest('.capcut-track-header')) return;
        isInteracting = true;
        startX = e.clientX;
        startY = e.clientY;

        const isRuler = !!e.target.closest('#capcutRuler') || !!e.target.closest('#capcutPlayheadHandle');
        if (isRuler) {
          interactMode = 'scrub';
          updateScrub(e);
        } else {
          interactMode = 'rubberband';
          if (!e.shiftKey && !e.ctrlKey) {
            clearSelection();
          }
        }
        els.scrollArea.setPointerCapture(e.pointerId);
      });

      els.scrollArea.addEventListener('pointermove', (e) => {
        if (!isInteracting) return;

        if (interactMode === 'scrub') {
          updateScrub(e);
        } else if (interactMode === 'rubberband') {
          const contentRect = els.contentArea.getBoundingClientRect();
          const currX = e.clientX;
          const currY = e.clientY;

          const rectLeft   = Math.min(startX, currX) - contentRect.left;
          const rectTop    = Math.min(startY, currY) - contentRect.top;
          const rectWidth  = Math.abs(currX - startX);
          const rectHeight = Math.abs(currY - startY);

          if (rectWidth > 5 || rectHeight > 5) {
            if (!rubberbandEl) {
              rubberbandEl = document.createElement('div');
              rubberbandEl.className = 'capcut-rubberband';
              els.contentArea.appendChild(rubberbandEl);
            }

            rubberbandEl.style.left   = rectLeft + 'px';
            rubberbandEl.style.top    = rectTop + 'px';
            rubberbandEl.style.width  = rectWidth + 'px';
            rubberbandEl.style.height = rectHeight + 'px';

            // Test collision with clip elements
            const boxR = rectLeft + rectWidth;
            const boxB = rectTop + rectHeight;

            document.querySelectorAll('.capcut-clip').forEach(clipEl => {
              const clipL = parseFloat(clipEl.style.left) || 0;
              const clipW = parseFloat(clipEl.style.width) || 0;
              const clipR = clipL + clipW;

              const parentRow = clipEl.closest('.capcut-track-row');
              const rowTop = parentRow ? parentRow.offsetTop : 0;
              const rowB = rowTop + (parentRow ? parentRow.offsetHeight : 40);

              const isIntersecting = (clipL < boxR && clipR > rectLeft && rowTop < boxB && rowB > rectTop);
              const clipId = clipEl.dataset.id;

              if (isIntersecting) {
                engine.selectedIds.add(clipId);
                clipEl.classList.add('selected');
              } else if (!e.shiftKey && !e.ctrlKey) {
                engine.selectedIds.delete(clipId);
                clipEl.classList.remove('selected');
              }
            });

            updateToolbarButtonsState();
          }
        }
      });

      els.scrollArea.addEventListener('pointerup', (e) => {
        if (!isInteracting) return;
        isInteracting = false;
        els.scrollArea.releasePointerCapture(e.pointerId);

        if (rubberbandEl) {
          rubberbandEl.remove();
          rubberbandEl = null;
        } else if (interactMode === 'rubberband') {
          // Pure single click on lane background -> seek playhead
          updateScrub(e);
        }
        interactMode = null;
      });
    }

    // Zoom slider
    if (els.zoomSlider) {
      els.zoomSlider.addEventListener('input', () => {
        engine.zoom = parseFloat(els.zoomSlider.value);
        if (window.CapCutEditor?.State) window.CapCutEditor.State.zoom = engine.zoom;
        renderRuler();
        renderTracksAndClips();
        updatePlayheadPosition();
      });
    }

    // Ctrl + Wheel Zooming inside timeline
    els.scrollArea?.addEventListener('wheel', (e) => {
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        const delta = e.deltaY < 0 ? 4 : -4;
        engine.zoom = Math.max(10, Math.min(120, engine.zoom + delta));
        if (els.zoomSlider) els.zoomSlider.value = engine.zoom;
        if (window.CapCutEditor?.State) window.CapCutEditor.State.zoom = engine.zoom;
        renderRuler();
        renderTracksAndClips();
        updatePlayheadPosition();
      }
    }, { passive: false });

    // Toolbar Buttons
    els.splitBtn?.addEventListener('click', splitSelectedClips);
    els.reverseBtn?.addEventListener('click', reverseSelectedClip);
    els.freezeBtn?.addEventListener('click', freezeFrameSelectedClip);
    els.detachAudioBtn?.addEventListener('click', detachAudioFromSelectedClip);
    els.replaceAudioBtn?.addEventListener('click', replaceAudioOfSelectedClip);
    els.deleteBtn?.addEventListener('click', deleteSelectedClips);
    els.duplicateBtn?.addEventListener('click', duplicateSelectedClips);
    els.copyBtn?.addEventListener('click', copySelectedClips);
    els.pasteBtn?.addEventListener('click', pasteClips);
    els.addTextBtn?.addEventListener('click', () => {
      const store = window.StudioProjectStore;
      if (!store) return;
      const currentTime = window.PreviewEngine?.currentTime ?? 0;
      const newState = JSON.parse(JSON.stringify(store.getState()));

      const newText = store.createTimelineItem({
        type:      'text',
        trackId:   'track_text_overlay',
        name:      'Title Text',
        start:     currentTime,
        duration:  4.0,
        content:   'NEW TEXT LAYER',
        style:     { color: '#00f3ff', fontSize: 32, fontFamily: 'Space Grotesk' }
      });

      newState.items.push(newText);
      recalculateProjectDuration(newState);
      store.setState(newState);
      showToast('✨ Added text clip at playhead');
    });

    els.addTrackBtn?.addEventListener('click', () => {
      const type = prompt('Select Track Type (video / audio / text / caption / overlay):', 'video');
      if (type && ['video', 'audio', 'text', 'caption', 'overlay'].includes(type.toLowerCase())) {
        addTrack(type.toLowerCase());
      }
    });

    els.snapBtn?.addEventListener('click', () => {
      engine.snapEnabled = !engine.snapEnabled;
      els.snapBtn.classList.toggle('active', engine.snapEnabled);
      showToast(engine.snapEnabled ? '🧲 Magnetic Snap ON' : '⚡ Snap OFF');
    });

    els.rippleBtn?.addEventListener('click', () => {
      engine.rippleEnabled = !engine.rippleEnabled;
      els.rippleBtn.classList.toggle('active', engine.rippleEnabled);
      showToast(engine.rippleEnabled ? '🔗 Magnetic Timeline ON' : '🔓 Ripple Edit OFF');
    });

    // Keyboard Shortcuts (Split S, Del/Backspace, Copy Ctrl+C, Paste Ctrl+V, Duplicate Ctrl+D, Undo Ctrl+Z, Redo Ctrl+Y, Select All Ctrl+A, Spacebar Play, Left/Right Arrow frame step)
    document.addEventListener('keydown', (e) => {
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;

      const currentTime = window.PreviewEngine?.currentTime ?? 0;

      if (e.code === 'KeyS' && !e.ctrlKey && !e.metaKey) {
        e.preventDefault();
        splitSelectedClips();
      } else if (e.code === 'Delete' || e.code === 'Backspace') {
        e.preventDefault();
        deleteSelectedClips();
      } else if ((e.ctrlKey || e.metaKey) && e.code === 'KeyA') {
        e.preventDefault();
        selectAllClips();
      } else if ((e.ctrlKey || e.metaKey) && e.code === 'KeyC') {
        e.preventDefault();
        copySelectedClips();
      } else if ((e.ctrlKey || e.metaKey) && e.code === 'KeyV') {
        e.preventDefault();
        pasteClips();
      } else if ((e.ctrlKey || e.metaKey) && e.code === 'KeyD') {
        e.preventDefault();
        duplicateSelectedClips();
      } else if ((e.ctrlKey || e.metaKey) && e.code === 'KeyZ') {
        e.preventDefault();
        if (e.shiftKey) window.redo();
        else window.undo();
      } else if ((e.ctrlKey || e.metaKey) && e.code === 'KeyY') {
        e.preventDefault();
        window.redo();
      }
    });
  }

  function reverseSelectedClip() {
    if (engine.selectedIds.size === 0) return;
    const store = window.StudioProjectStore;
    if (!store) return;

    const newState = JSON.parse(JSON.stringify(store.getState()));
    let count = 0;

    engine.selectedIds.forEach(id => {
      const item = newState.items.find(i => i.id === id);
      if (!item) return;

      const track = newState.tracks.find(t => t.id === item.trackId);
      if (track && track.locked) return; // skip locked track clips

      if (item.type === 'video' || item.type === 'audio') {
        item.reversed = !item.reversed;
        count++;
      }
    });

    if (count > 0) {
      store.setState(newState);
      showToast(`🔄 Toggled reverse mode for ${count} clip(s)`);
    }
  }

  function freezeFrameSelectedClip() {
    if (engine.selectedIds.size === 0) return;
    const store = window.StudioProjectStore;
    if (!store) return;

    const playheadTime = window.PreviewEngine?.currentTime ?? 0;
    const newState = JSON.parse(JSON.stringify(store.getState()));
    
    // Find selected clip that matches the playhead position
    let targetItem = null;
    let targetIdx = -1;
    
    engine.selectedIds.forEach(id => {
      const idx = newState.items.findIndex(i => i.id === id);
      if (idx !== -1) {
        const item = newState.items[idx];
        const track = newState.tracks.find(t => t.id === item.trackId);
        if (track && track.locked) return; // skip locked tracks
        
        if (item.type === 'video' && playheadTime >= item.start && playheadTime <= item.start + item.duration) {
          targetItem = item;
          targetIdx = idx;
        }
      }
    });

    if (!targetItem) {
      showToast('⚠️ Playhead must be positioned over the selected video clip', 'error');
      return;
    }

    // Split clip at the playhead position and insert a 3-second freeze frame!
    const splitTimeRel = playheadTime - targetItem.start;
    const sourceOffset = splitTimeRel * (targetItem.speed ?? 1) + (targetItem.sourceStart ?? 0);

    // Part 1 duration
    const p1Dur = splitTimeRel;
    // Part 2 duration
    const p2Dur = targetItem.duration - p1Dur;

    // Freeze clip duration (3 seconds)
    const freezeDuration = 3.0;

    // Create the Freeze Frame clip
    const freezeClip = store.createTimelineItem({
      ...JSON.parse(JSON.stringify(targetItem)),
      id:          store.generateUUID('clip_video'),
      name:        targetItem.name + ' (Freeze Frame)',
      start:       targetItem.start + p1Dur,
      duration:    freezeDuration,
      freezeTime:  sourceOffset
    });

    // Update Part 1 duration
    targetItem.duration = p1Dur;
    targetItem.sourceEnd = targetItem.sourceStart + p1Dur * (targetItem.speed ?? 1);

    // Create Part 2 clip
    const part2Clip = store.createTimelineItem({
      ...JSON.parse(JSON.stringify(targetItem)),
      id:          store.generateUUID('clip_video'),
      name:        targetItem.name + ' (Part 2)',
      start:       targetItem.start + p1Dur + freezeDuration,
      duration:    p2Dur,
      sourceStart: sourceOffset,
      sourceEnd:   targetItem.sourceEnd + p2Dur * (targetItem.speed ?? 1)
    });

    // Update start times of all downstream clips on the same track to close/shift gaps (ripple)
    newState.items.forEach(it => {
      if (it.trackId === targetItem.trackId && it.id !== targetItem.id && it.start >= playheadTime) {
        it.start += freezeDuration;
      }
    });

    // Insert freezeClip and part2Clip
    newState.items.splice(targetIdx + 1, 0, freezeClip, part2Clip);

    // Update selection
    engine.selectedIds = new Set([freezeClip.id]);
    recalculateProjectDuration(newState);
    store.setState(newState);
    
    // Position playhead to the start of the freeze frame
    window.PreviewEngine?.seek(targetItem.start + p1Dur);

    showToast('❄️ Inserted 3s freeze frame at playhead');
  }

  function detachAudioFromSelectedClip() {
    if (engine.selectedIds.size === 0) return;
    const store = window.StudioProjectStore;
    if (!store) return;

    const newState = JSON.parse(JSON.stringify(store.getState()));
    let count = 0;

    engine.selectedIds.forEach(id => {
      const idx = newState.items.findIndex(i => i.id === id);
      if (idx === -1) return;

      const item = newState.items[idx];
      const track = newState.tracks.find(t => t.id === item.trackId);
      if (track && track.locked) return; // skip locked track clips

      if (item.type === 'video') {
        // Create detached audio clip
        const audioClip = store.createTimelineItem({
          type:        'audio',
          trackId:     'track_audio_sfx',
          sourceMediaId: item.sourceMediaId,
          url:         item.url,
          name:        item.name + ' (Detached Audio)',
          start:       item.start,
          duration:    item.duration,
          sourceStart: item.sourceStart,
          sourceEnd:   item.sourceEnd,
          speed:       item.speed ?? 1.0,
          volume:      item.volume ?? 100,
          fadeIn:      item.fadeIn,
          fadeOut:     item.fadeOut,
          muted:       item.muted,
          volumeKeyframes: item.volumeKeyframes ? JSON.parse(JSON.stringify(item.volumeKeyframes)) : undefined
        });

        // Mute the original video clip's audio
        item.volume = 0;
        item.muted = true;

        newState.items.push(audioClip);
        count++;
      }
    });

    if (count > 0) {
      recalculateProjectDuration(newState);
      store.setState(newState);
      showToast(`🔊 Detached audio from ${count} video clip(s)`);
    }
  }

  function replaceAudioOfSelectedClip() {
    if (engine.selectedIds.size === 0) return;
    const store = window.StudioProjectStore;
    if (!store) return;

    const newUrl = prompt('Enter the new audio URL (e.g. assets/audio/replaced.mp3):', 'assets/audio/replaced.mp3');
    if (!newUrl) return;

    const newState = JSON.parse(JSON.stringify(store.getState()));
    let count = 0;

    engine.selectedIds.forEach(id => {
      const item = newState.items.find(i => i.id === id);
      if (!item) return;

      const track = newState.tracks.find(t => t.id === item.trackId);
      if (track && track.locked) return; // skip locked tracks

      if (item.type === 'video' || item.type === 'audio') {
        item.url = newUrl;
        item.name = item.name + ' (Replaced)';
        count++;
      }
    });

    if (count > 0) {
      store.setState(newState);
      showToast(`🎵 Replaced audio source on ${count} clip(s)`);
    }
  }

  // ── Init ───────────────────────────────────────────────────────────────────
  function init() {
    initDOMRefs();
    if (!els.tracksContainer) return; // Not on studio page

    bindEvents();

    const store = window.StudioProjectStore;
    if (store) {
      store.subscribe(onStateChange);
      onStateChange(store.getState());
    }

    console.info('[StudioTimeline] Engine initialized.');
  }

  document.addEventListener('DOMContentLoaded', init);

  // ── Public API ─────────────────────────────────────────────────────────────
  window.StudioTimeline = {
    render: () => onStateChange(window.StudioProjectStore?.getState()),
    addClip: (params) => {
      const store = window.StudioProjectStore;
      if (!store) return;
      const newState = JSON.parse(JSON.stringify(store.getState()));
      const item = store.createTimelineItem(params);
      newState.items.push(item);
      recalculateProjectDuration(newState);
      store.setState(newState);
    },
    selectClip: (itemId) => handleClipClick(itemId, {}),
    selectAllClips,
    splitSelectedClips,
    deleteSelectedClips,
    duplicateSelectedClips,
    copySelectedClips,
    pasteClips,
    addTrack,
    deleteTrack,
    moveTrackUp,
    moveTrackDown,
    toggleTrackMute,
    toggleTrackLock,
    toggleTrackVisibility,
    get selectedIds() { return engine.selectedIds; },
    get rippleEnabled() { return engine.rippleEnabled; },
    set rippleEnabled(val) {
      engine.rippleEnabled = !!val;
      if (els.rippleBtn) els.rippleBtn.classList.toggle('active', engine.rippleEnabled);
    }
  };

})();
