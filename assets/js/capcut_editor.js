/**
 * assets/js/capcut_editor.js
 * Advanced Multi-Track Video Timeline Editor (CapCut Clone)
 * Handles playhead scrubbing, audio synthesis, text/sticker overlay dragging, and timeline manipulation.
 */

(function () {
  'use strict';

  // ── Centralized Single Source of Truth Project Store ──
  const StudioProjectStore = (function() {
    function generateUUID(prefix = 'item') {
      return prefix + '_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 7);
    }

    function createDefaultState() {
      return {
        projectSettings: {
          id: 'proj_' + Date.now(),
          name: 'Untitled Project',
          aspectRatio: '16:9',
          width: 1920,
          height: 1080,
          fps: 30,
          background: '#000000',
          duration: 15.0
        },
        mediaLibrary: [],
        tracks: [
          { id: 'track_video_main', type: 'video', name: 'Video Track 1', muted: false, locked: false, visible: true, zIndex: 1 },
          { id: 'track_text_overlay', type: 'text', name: 'Text & Overlay', muted: false, locked: false, visible: true, zIndex: 2 },
          { id: 'track_audio_sfx', type: 'audio', name: 'Audio Track 1', muted: false, locked: false, visible: true, zIndex: 0 }
        ],
        items: [
          {
            id: 'clip_vid_1001',
            trackId: 'track_video_main',
            type: 'video',
            sourceMediaId: null,
            name: 'Main Video Clip',
            start: 0.0,
            duration: 15.0,
            sourceStart: 0.0,
            sourceEnd: 15.0,
            speed: 1.0,
            position: { x: 50, y: 50, z: 1 },
            scale: { x: 1.0, y: 1.0 },
            rotation: 0,
            opacity: 1.0,
            volume: 100,
            effects: [],
            filters: { saturation: 100, contrast: 100, brightness: 100, exposure: 100, temperature: 0, tint: 0 },
            transitions: { in: null, out: null },
            keyframes: [],
            masks: [],
            animations: []
          }
        ],
        captions: [],
        exportSettings: {
          format: 'mp4',
          resolution: '1080p',
          fps: 30,
          quality: 'high'
        }
      };
    }

    let currentProject = createDefaultState();
    let undoStack = [];
    let redoStack = [];
    const subscribers = [];

    function getState() {
      return currentProject;
    }

    function setState(newState, saveUndo = true) {
      if (saveUndo) {
        undoStack.push(JSON.stringify(currentProject));
        if (undoStack.length > 50) undoStack.shift();
        redoStack = [];
      }
      currentProject = JSON.parse(JSON.stringify(newState));
      notifySubscribers();
      if (typeof window.triggerAutoSave === 'function') {
        window.triggerAutoSave();
      }
    }

    function subscribe(callback) {
      if (typeof callback === 'function') {
        subscribers.push(callback);
      }
    }

    function notifySubscribers() {
      subscribers.forEach(cb => {
        try { cb(currentProject); } catch(e) { console.warn("Subscriber error:", e); }
      });
    }

    function undo() {
      if (undoStack.length === 0) return false;
      redoStack.push(JSON.stringify(currentProject));
      currentProject = JSON.parse(undoStack.pop());
      notifySubscribers();
      if (typeof window.triggerAutoSave === 'function') window.triggerAutoSave();
      return true;
    }

    function redo() {
      if (redoStack.length === 0) return false;
      undoStack.push(JSON.stringify(currentProject));
      currentProject = JSON.parse(redoStack.pop());
      notifySubscribers();
      if (typeof window.triggerAutoSave === 'function') window.triggerAutoSave();
      return true;
    }

    function createTimelineItem(params) {
      const type = params.type || 'video';
      let defaultTrackId = 'track_video_main';
      if (type === 'audio') defaultTrackId = 'track_audio_sfx';
      else if (type === 'text') defaultTrackId = 'track_text_overlay';
      else if (type === 'caption') defaultTrackId = 'track_caption_main';
      else if (type === 'overlay') defaultTrackId = 'track_overlay_main';

      return {
        id: params.id || generateUUID('clip_' + type),
        trackId: params.trackId || defaultTrackId,
        type: type,
        sourceMediaId: params.sourceMediaId || null,
        url: params.url || null,
        name: params.name || (type.toUpperCase() + ' Clip'),
        start: parseFloat(params.start) || 0.0,
        duration: parseFloat(params.duration) || 5.0,
        sourceStart: parseFloat(params.sourceStart) || 0.0,
        sourceEnd: parseFloat(params.sourceEnd) || (parseFloat(params.duration) || 5.0),
        speed: parseFloat(params.speed) || 1.0,
        position: params.position || { x: 50, y: 50, z: 1 },
        scale: params.scale || { x: 1.0, y: 1.0 },
        rotation: parseFloat(params.rotation) || 0,
        opacity: params.opacity !== undefined ? parseFloat(params.opacity) : 1.0,
        volume: params.volume !== undefined ? parseInt(params.volume) : 100,
        effects: params.effects || [],
        filters: params.filters || { saturation: 100, contrast: 100, brightness: 100, exposure: 100, temperature: 0, tint: 0 },
        transitions: params.transitions || { in: null, out: null },
        keyframes: params.keyframes || [],
        masks: params.masks || [],
        animations: params.animations || [],
        content: params.content || (params.text || params.emoji || ''),
        style: params.style || { color: '#ffffff', fontSize: 24, fontFamily: 'Space Grotesk' },
        // Custom editing tools support
        freezeTime: params.freezeTime !== undefined ? parseFloat(params.freezeTime) : undefined,
        reversed: params.reversed !== undefined ? !!params.reversed : undefined,
        crop: params.crop || undefined,
        flip: params.flip || undefined,
        fit: params.fit || undefined,
        fill: params.fill || undefined,
        fadeIn: params.fadeIn !== undefined ? parseFloat(params.fadeIn) : undefined,
        fadeOut: params.fadeOut !== undefined ? parseFloat(params.fadeOut) : undefined,
        muted: params.muted !== undefined ? !!params.muted : undefined,
        intensity: params.intensity !== undefined ? parseFloat(params.intensity) : undefined,
        volumeKeyframes: params.volumeKeyframes || undefined
      };
    }

    function moveTrack(trackId, direction) {
      const state = JSON.parse(JSON.stringify(currentProject));
      const sorted = [...state.tracks].sort((a, b) => (b.zIndex ?? 0) - (a.zIndex ?? 0));
      const idx = sorted.findIndex(t => t.id === trackId);
      if (idx === -1) return;

      const targetIdx = direction === 'up' ? idx - 1 : idx + 1;
      if (targetIdx < 0 || targetIdx >= sorted.length) return;

      // Swap zIndex
      const tempZ = sorted[idx].zIndex ?? 0;
      sorted[idx].zIndex = sorted[targetIdx].zIndex ?? 0;
      sorted[targetIdx].zIndex = tempZ;

      // Make sure zIndexes are distinct if they were identical
      if (sorted[idx].zIndex === sorted[targetIdx].zIndex) {
        sorted.forEach((tr, i) => { tr.zIndex = sorted.length - i; });
      }

      state.tracks = sorted;
      setState(state);
    }

    return {
      generateUUID,
      createDefaultState,
      getState,
      setState,
      subscribe,
      undo,
      redo,
      createTimelineItem,
      moveTrack
    };
  })();

  window.StudioProjectStore = StudioProjectStore;
  window.undo = () => StudioProjectStore.undo();
  window.redo = () => StudioProjectStore.redo();

  // ── Editor State ──
  const state = {
    isLoading: false,
    duration: 15,          // Default duration in seconds if no video loaded
    currentTime: 0,
    isPlaying: false,
    zoom: 25,              // Pixels per second in timeline
    videoFile: null,
    videoUrl: null,
    
    // Timeline Tracks
    videoClip: {
      id: 'main-video',
      start: 0,            // clip start offset on timeline
      trimStart: 0,        // trim start time inside source video
      trimEnd: 15,         // trim end time inside source video
      sourceDuration: 15,
      speed: 1.0,
      volume: 100,
      
      // Color grading variables
      saturation: 100,
      contrast: 100,
      brightness: 100,
      exposure: 100,
      temperature: 0,      // warm/cool shift
      tint: 0,             // tint shift
      shadows: 0,
      highlights: 0
    },
    textClips: [
      { id: 'txt-1', text: 'CYBERPUNK STUDIO', start: 1, end: 5, x: 50, y: 30, size: 28, color: '#00f3ff', font: 'Space Grotesk', anim: 'fade' },
      { id: 'txt-2', text: 'EDIT LIKE CAPCUT', start: 6, end: 11, x: 50, y: 75, size: 24, color: '#ff00ff', font: 'Outfit', anim: 'typewriter' }
    ],
    stickerClips: [],      // emoji sticker overlays: { id, emoji, start, end, x, y, size }
    audioClips: [
      { id: 'aud-1', name: 'Synthwave Bassline', type: 'beat', start: 0, duration: 15, volume: 80 },
      { id: 'aud-2', name: 'Transition Whoosh', type: 'whoosh', start: 5, duration: 2, volume: 90 }
    ],
    
    selectedId: null,      // Selected clip ID
    selectedType: null,    // 'video' | 'text' | 'audio' | 'sticker'
    
    // Web Audio Synthesizer Context
    audioCtx: null,
    synthInterval: null
  };

  // ── DOM Elements ──
  let elements = {};
  
  function initDOMElements() {
    elements = {
      video: document.getElementById('capcutVideo'),
      playBtn: document.getElementById('capcutPlayBtn'),
      playIcon: document.getElementById('capcutPlayIcon'),
      splitBtn: document.getElementById('capcutSplitBtn'),
      deleteBtn: document.getElementById('capcutDeleteBtn'),
      addTextBtn: document.getElementById('capcutAddTextBtn'),
      timecode: document.getElementById('capcutTimecode'),
      zoomSlider: document.getElementById('capcutZoomRange'),
      rulerTicks: document.getElementById('capcutRulerTicks'),
      playhead: document.getElementById('capcutPlayhead'),
      timelineScroll: document.getElementById('capcutTimelineScroll'),
      timelineContent: document.getElementById('capcutTimelineContent'),
      tracksContainer: document.getElementById('capcutTracksContainer'),
      overlayLayer: document.getElementById('capcutOverlay'),
      inspectorTitle: document.getElementById('capcutInspectorTitle'),
      inspectorContent: document.getElementById('capcutInspectorContent'),
      fileInput: document.getElementById('capcutMediaFileInput'),
      aspectSelect: document.getElementById('capcutAspectSelect'),
      playerWrapper: document.getElementById('capcutPlayerWrapper'),
      exportForm: document.getElementById('studioProcessingForm')
    };
  }

  // ── Audio Engine: Synced HTML5 Audio Clips ──
  function initAudioContext() {
    // Keep as placeholder for backward compatibility
  }

  function triggerSynthSound(type) {
    try {
      const audio = new Audio("assets/audio/" + type + ".mp3");
      audio.volume = 0.4;
      audio.play().catch(() => {});
    } catch (e) {
      console.warn("Static audio play failed:", e);
    }
  }

  function syncAudioClipsPlayback() {
    if (!state.audioClips) return;
    if (!state.playingAudioInstances) {
      state.playingAudioInstances = {};
    }
    
    state.audioClips.forEach(clip => {
      const isWithinRange = state.currentTime >= clip.start && state.currentTime <= (clip.start + clip.duration);
      
      if (isWithinRange && state.isPlaying) {
        if (!state.playingAudioInstances[clip.id]) {
          const audio = new Audio(`assets/audio/${clip.type}.mp3`);
          audio.volume = (clip.volume ?? 100) / 100 * 0.4; // normal volume cap
          
          const offset = state.currentTime - clip.start;
          audio.currentTime = offset;
          audio.play().catch(() => {});
          
          state.playingAudioInstances[clip.id] = audio;
        } else {
          const audio = state.playingAudioInstances[clip.id];
          const expectedTime = state.currentTime - clip.start;
          if (Math.abs(audio.currentTime - expectedTime) > 0.3) {
            audio.currentTime = expectedTime;
          }
        }
      } else {
        if (state.playingAudioInstances[clip.id]) {
          state.playingAudioInstances[clip.id].pause();
          delete state.playingAudioInstances[clip.id];
        }
      }
    });
  }

  function stopAllAudioClips() {
    if (state.playingAudioInstances) {
      Object.keys(state.playingAudioInstances).forEach(id => {
        state.playingAudioInstances[id].pause();
      });
      state.playingAudioInstances = {};
    }
  }

  function startBackgroundSequencer() {
    // Keep as placeholder
  }

  // ── Timeline Calculations ──
  function timeToX(time) {
    return time * state.zoom;
  }

  function xToTime(x) {
    return x / state.zoom;
  }

  // ── Render Ticks & Ruler ──
  function renderTimelineRuler() {
    if (!elements.rulerTicks) return;
    elements.rulerTicks.innerHTML = '';
    
    const width = timeToX(state.duration);
    elements.timelineContent.style.width = (width + 200) + 'px'; // add buffer space
    
    const increment = state.zoom < 15 ? 5 : (state.zoom < 35 ? 1 : 0.5);
    
    for (let time = 0; time <= state.duration; time += increment) {
      const tick = document.createElement('div');
      tick.className = 'capcut-ruler-tick';
      if (Number.isInteger(time)) {
        tick.className += ' major';
        tick.innerText = formatTimecode(time);
      }
      tick.style.left = timeToX(time) + 'px';
      elements.rulerTicks.appendChild(tick);
    }
    
    updatePlayheadPosition();
  }

  function formatTimecode(sec) {
    const min = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    const ms = Math.floor((sec % 1) * 100);
    return `${min.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}.${ms.toString().padStart(2, '0')}`;
  }

  function updatePlayheadPosition() {
    if (!elements.playhead) return;
    const x = timeToX(state.currentTime);
    elements.playhead.style.left = x + 'px';
    if (elements.timecode) {
      elements.timecode.innerText = `${formatTimecode(state.currentTime)} / ${formatTimecode(state.duration)}`;
    }
    
    // Check audio clips that need to trigger effects
    checkAudioClipSynthesizers();
  }

  let lastSynthCheckTime = 0;
  function checkAudioClipSynthesizers() {
    if (!state.isPlaying || !state.audioCtx) return;
    
    state.audioClips.forEach(clip => {
      // If playhead just passed the start time, trigger whoosh or chime sound effect
      if (state.currentTime >= clip.start && lastSynthCheckTime < clip.start) {
        if (clip.type === 'whoosh') {
          triggerSynthSound('whoosh');
        } else if (clip.type === 'chime') {
          triggerSynthSound('chime');
        } else if (clip.type === 'cyber') {
          triggerSynthSound('cyber');
        }
      }
    });
    lastSynthCheckTime = state.currentTime;
  }

  // ── Render Clips on Tracks ──
  function renderTimelineClips() {
    if (window.StudioTimeline) {
      window.StudioTimeline.render();
      return;
    }
    if (!elements.tracksContainer) return;
    elements.tracksContainer.innerHTML = '';
    
    // Track 1: Video Clip Lane
    const vLane = createTrackLane('Video');
    if (state.videoClip) {
      const vClip = createClipElement('video', state.videoClip.id, 'Source Video', state.videoClip.start, state.videoClip.trimEnd - state.videoClip.trimStart);
      vLane.appendChild(vClip);
    }
    elements.tracksContainer.appendChild(vLane);
    
    // Track 2: Text/Captions Lane
    const tLane = createTrackLane('Text');
    state.textClips.forEach(clip => {
      const clipEl = createClipElement('text', clip.id, clip.text, clip.start, clip.end - clip.start);
      tLane.appendChild(clipEl);
    });
    elements.tracksContainer.appendChild(tLane);

    // Track 3: Stickers Lane
    const sLane = createTrackLane('Sticker');
    state.stickerClips.forEach(clip => {
      const clipEl = createClipElement('text', clip.id, `Sticker (${clip.emoji})`, clip.start, clip.end - clip.start);
      sLane.appendChild(clipEl);
    });
    elements.tracksContainer.appendChild(sLane);
    
    // Track 4: Audio/SFX Lane
    const aLane = createTrackLane('Audio');
    state.audioClips.forEach(clip => {
      const clipEl = createClipElement('audio', clip.id, clip.name, clip.start, clip.duration);
      aLane.appendChild(clipEl);
    });
    elements.tracksContainer.appendChild(aLane);
    
    updateSelectionUI();
    renderPlayerOverlay();
  }

  function createTrackLane(label) {
    const row = document.createElement('div');
    row.className = 'capcut-track-row';
    
    const trackLabel = document.createElement('div');
    trackLabel.className = 'capcut-track-label';
    trackLabel.innerText = label;
    row.appendChild(trackLabel);
    
    const lane = document.createElement('div');
    lane.className = 'capcut-track-lane';
    row.appendChild(lane);
    
    return lane;
  }

  function createClipElement(type, id, labelText, start, duration) {
    const clip = document.createElement('div');
    clip.className = `capcut-clip ${type}-clip`;
    clip.dataset.id = id;
    clip.dataset.type = type;
    clip.style.left = timeToX(start) + 'px';
    clip.style.width = timeToX(duration) + 'px';
    clip.innerText = labelText;
    
    // Left & Right Drag Trimming handles
    const leftHandle = document.createElement('div');
    leftHandle.className = 'capcut-clip-handle left-handle';
    const rightHandle = document.createElement('div');
    rightHandle.className = 'capcut-clip-handle right-handle';
    
    clip.appendChild(leftHandle);
    clip.appendChild(rightHandle);
    
    // Attach Drag & Drop and click handlers
    clip.addEventListener('click', (e) => {
      e.stopPropagation();
      initAudioContext(); // Enable audio on interaction
      selectElement(id, type);
    });
    
    makeClipDraggable(clip);
    
    return clip;
  }

  // ── Drag & Resize Timeline Clip blocks ──
  function makeClipDraggable(clipEl) {
    let startX = 0;
    let originalLeft = 0;
    let originalWidth = 0;
    let isDragging = false;
    let dragMode = 'move'; // 'move' | 'trim-start' | 'trim-end'
    const id = clipEl.dataset.id;
    const type = clipEl.dataset.type;
    
    clipEl.addEventListener('pointerdown', (e) => {
      e.stopPropagation();
      isDragging = true;
      startX = e.clientX;
      originalLeft = parseFloat(clipEl.style.left);
      originalWidth = parseFloat(clipEl.style.width);
      
      clipEl.setPointerCapture(e.pointerId);
      
      if (e.target.classList.contains('left-handle')) {
        dragMode = 'trim-start';
      } else if (e.target.classList.contains('right-handle')) {
        dragMode = 'trim-end';
      } else {
        dragMode = 'move';
      }
      
      selectElement(id, type);
    });
    
    clipEl.addEventListener('pointermove', (e) => {
      if (!isDragging) return;
      const dx = e.clientX - startX;
      
      if (dragMode === 'move') {
        let newLeft = originalLeft + dx;
        if (newLeft < 0) newLeft = 0;
        clipEl.style.left = newLeft + 'px';
      } 
      else if (dragMode === 'trim-start') {
        let newLeft = originalLeft + dx;
        let newWidth = originalWidth - dx;
        if (newLeft < 0) {
          newWidth += newLeft;
          newLeft = 0;
        }
        if (newWidth > 15) {
          clipEl.style.left = newLeft + 'px';
          clipEl.style.width = newWidth + 'px';
        }
      } 
      else if (dragMode === 'trim-end') {
        let newWidth = originalWidth + dx;
        if (newWidth > 15) {
          clipEl.style.width = newWidth + 'px';
        }
      }
    });
    
    clipEl.addEventListener('pointerup', (e) => {
      if (!isDragging) return;
      isDragging = false;
      clipEl.releasePointerCapture(e.pointerId);
      
      const newStart = xToTime(parseFloat(clipEl.style.left));
      const newDuration = xToTime(parseFloat(clipEl.style.width));
      
      // Update our state values
      if (type === 'video') {
        state.videoClip.start = newStart;
        state.videoClip.trimStart = newStart; // map trim start relative
        state.videoClip.trimEnd = newStart + newDuration;
      } 
      else if (type === 'text') {
        const textClip = state.textClips.find(c => c.id === id);
        if (textClip) {
          textClip.start = newStart;
          textClip.end = newStart + newDuration;
        }
      } 
      else if (type === 'sticker') {
        const sticker = state.stickerClips.find(c => c.id === id);
        if (sticker) {
          sticker.start = newStart;
          sticker.end = newStart + newDuration;
        }
      }
      else if (type === 'audio') {
        const audioClip = state.audioClips.find(c => c.id === id);
        if (audioClip) {
          audioClip.start = newStart;
          audioClip.duration = newDuration;
        }
      }
      
      // Re-render and recalculate duration
      recalculateTimelineDuration();
      renderTimelineRuler();
      renderTimelineClips();
      if (typeof triggerAutoSave === 'function') triggerAutoSave();
    });
  }

  function recalculateTimelineDuration() {
    let maxTime = 15; // floor default
    if (state.videoClip) {
      maxTime = Math.max(maxTime, state.videoClip.start + (state.videoClip.trimEnd - state.videoClip.trimStart));
    }
    state.textClips.forEach(c => maxTime = Math.max(maxTime, c.end));
    state.stickerClips.forEach(c => maxTime = Math.max(maxTime, c.end));
    state.audioClips.forEach(c => maxTime = Math.max(maxTime, c.start + c.duration));
    
    state.duration = Math.ceil(maxTime);
  }

  // ── Select Clip and Update Inspector ──
  function selectElement(id, type) {
    state.selectedId = id;
    state.selectedType = type;
    
    updateSelectionUI();
    renderInspector();
    renderPlayerOverlay();
  }

  function updateSelectionUI() {
    document.querySelectorAll('.capcut-clip').forEach(el => {
      if (el.dataset.id === state.selectedId) {
        el.classList.add('selected');
      } else {
        el.classList.remove('selected');
      }
    });
    
    if (elements.deleteBtn) {
      elements.deleteBtn.disabled = !state.selectedId;
    }
    if (elements.splitBtn) {
      elements.splitBtn.disabled = (state.selectedType !== 'video');
    }
  }

  // Apply CSS Filters for live color grade simulation
  function applyColorGradingFilters() {
    if (!elements.video) return;
    const clip = state.videoClip;
    
    // Saturation, Brightness, Contrast map to standard filters
    const filters = [];
    filters.push(`saturate(${clip.saturation}%)`);
    filters.push(`brightness(${clip.brightness}%)`);
    filters.push(`contrast(${clip.contrast}%)`);
    
    // Shift color hue-rotate as simple tint/temp approximation
    if (clip.temperature !== 0) {
      filters.push(`hue-rotate(${clip.temperature * 0.15}deg)`);
    }
    
    elements.video.style.filter = filters.join(' ');
  }

  // ── Render Viewport Text & Sticker Overlays ──
  // NOTE: PreviewEngine handles video/image/audio layers via previewLayerContainer.
  // This function handles legacy text/sticker overlays in #capcutOverlay only.
  // When PreviewEngine is loaded it also handles text layers; this function
  // continues to handle the draggable text overlays created via "Add Text" button.
  const overlayNodeCache = {};

  function renderPlayerOverlay() {
    // Notify PreviewEngine of state changes
    if (window.PreviewEngine) {
      window.PreviewEngine.onStateChange(StudioProjectStore.getState());
    }
    if (!elements.overlayLayer) return;
    
    const activeIds = new Set();
    
    const renderOverlayNode = (clip, type) => {
      activeIds.add(clip.id);
      let overlay = overlayNodeCache[clip.id];
      
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'capcut-text-overlay';
        
        const textNode = document.createElement('span');
        textNode.className = 'overlay-content-node';
        overlay.appendChild(textNode);
        
        const scaleH = document.createElement('div');
        scaleH.className = 'capcut-overlay-handle scale-handle';
        const rotateH = document.createElement('div');
        rotateH.className = 'capcut-overlay-handle rotate-handle';
        
        overlay.appendChild(scaleH);
        overlay.appendChild(rotateH);
        
        makeOverlayManipulatable(overlay, clip, type);
        elements.overlayLayer.appendChild(overlay);
        overlayNodeCache[clip.id] = overlay;
      }
      
      if (clip.id === state.selectedId) {
        if (!overlay.classList.contains('selected')) overlay.classList.add('selected');
      } else {
        if (overlay.classList.contains('selected')) overlay.classList.remove('selected');
      }
      
      overlay.style.left = clip.x + '%';
      overlay.style.top = clip.y + '%';
      
      const textNode = overlay.querySelector('.overlay-content-node');
      
      if (type === 'text') {
        overlay.style.fontSize = clip.size + 'px';
        overlay.style.color = clip.color;
        overlay.style.fontFamily = clip.font + ', sans-serif';
        if (textNode.innerText !== clip.text) textNode.innerText = clip.text;
      } else if (type === 'sticker') {
        overlay.style.fontSize = (clip.size || 40) + 'px';
        if (textNode.innerText !== clip.emoji) textNode.innerText = clip.emoji;
      }
      
      updateOverlayTransform(overlay, clip);
    };

    // Text overlays
    state.textClips.forEach(clip => {
      if (state.currentTime >= clip.start && state.currentTime <= clip.end) {
        renderOverlayNode(clip, 'text');
      }
    });

    // Sticker overlays
    state.stickerClips.forEach(clip => {
      if (state.currentTime >= clip.start && state.currentTime <= clip.end) {
        renderOverlayNode(clip, 'sticker');
      }
    });
    
    // Cleanup inactive nodes
    Object.keys(overlayNodeCache).forEach(id => {
      if (!activeIds.has(id)) {
        const node = overlayNodeCache[id];
        if (node && node.parentNode) {
          node.parentNode.removeChild(node);
        }
        delete overlayNodeCache[id];
      }
    });
  }

  function makeOverlayManipulatable(overlayEl, clip, type) {
    let activeAction = null; // 'move' | 'scale' | 'rotate'
    let startX = 0, startY = 0;
    let origX = 0, origY = 0;
    let origSize = 0;
    let origRotation = clip.rotation || 0;
    let centerX = 0, centerY = 0;
    let startAngle = 0;
    let startDist = 1;

    overlayEl.addEventListener('pointerdown', (e) => {
      e.stopPropagation();
      initAudioContext();
      selectElement(clip.id, type);

      startX = e.clientX;
      startY = e.clientY;
      origX = clip.x;
      origY = clip.y;
      origSize = clip.size || 24;
      origRotation = clip.rotation || 0;

      const rect = overlayEl.getBoundingClientRect();
      centerX = rect.left + rect.width / 2;
      centerY = rect.top + rect.height / 2;

      const handle = e.target;
      if (handle.classList.contains('scale-handle')) {
        activeAction = 'scale';
        const dx = e.clientX - centerX;
        const dy = e.clientY - centerY;
        startDist = Math.sqrt(dx*dx + dy*dy) || 1;
      } else if (handle.classList.contains('rotate-handle')) {
        activeAction = 'rotate';
        startAngle = Math.atan2(e.clientY - centerY, e.clientX - centerX);
      } else {
        activeAction = 'move';
      }

      // Cache parent layout to avoid thrashing in move
      overlayEl._cachedParentRect = elements.overlayLayer.getBoundingClientRect();

      overlayEl.setPointerCapture(e.pointerId);
    });

    overlayEl.addEventListener('pointermove', (e) => {
      if (!activeAction) return;

      if (activeAction === 'move') {
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;
        const parentRect = overlayEl._cachedParentRect || elements.overlayLayer.getBoundingClientRect();
        const pctDx = (dx / parentRect.width) * 100;
        const pctDy = (dy / parentRect.height) * 100;
        let newX = origX + pctDx;
        let newY = origY + pctDy;
        newX = Math.max(5, Math.min(95, newX));
        newY = Math.max(5, Math.min(95, newY));
        overlayEl.style.left = newX + '%';
        overlayEl.style.top = newY + '%';
        clip.x = newX;
        clip.y = newY;
      } 
      else if (activeAction === 'scale') {
        const dx = e.clientX - centerX;
        const dy = e.clientY - centerY;
        const curDist = Math.sqrt(dx*dx + dy*dy) || 1;
        let newSize = Math.round(origSize * (curDist / startDist));
        newSize = Math.max(10, Math.min(150, newSize));
        overlayEl.style.fontSize = newSize + 'px';
        clip.size = newSize;
        
        // If it's a sticker, update size range input in inspector
        const sizeInput = document.getElementById('stickerSizeRange');
        if (sizeInput) sizeInput.value = newSize;
      } 
      else if (activeAction === 'rotate') {
        const curAngle = Math.atan2(e.clientY - centerY, e.clientX - centerX);
        const angleDiff = curAngle - startAngle;
        const degDiff = angleDiff * (180 / Math.PI);
        let newDeg = Math.round((origRotation + degDiff) % 360);
        if (newDeg < 0) newDeg += 360;
        
        clip.rotation = newDeg;
        updateOverlayTransform(overlayEl, clip);
      }
    });

    overlayEl.addEventListener('pointerup', (e) => {
      if (!activeAction) return;
      activeAction = null;
      overlayEl.releasePointerCapture(e.pointerId);
      overlayEl._cachedParentRect = null;
      if (typeof triggerAutoSave === 'function') triggerAutoSave();
      if (typeof saveHistoryState === 'function') saveHistoryState();
    });
  }

  function updateOverlayTransform(overlayEl, clip) {
    let transform = 'translate(-50%, -50%)';
    if (clip.rotation) {
      transform += ` rotate(${clip.rotation}deg)`;
    }
    if (clip.anim === 'bounce') {
      transform += ` scale(${1.0 + Math.sin(state.currentTime * 5) * 0.1})`;
    }
    overlayEl.style.transform = transform;
  }

  // ── History Undo/Redo Manager ──
  const history = [];
  let historyIndex = -1;

  function saveHistoryState() {
    if (historyIndex < history.length - 1) {
      history.splice(historyIndex + 1);
    }
    const draftPayload = {
      videoClip: JSON.parse(JSON.stringify(state.videoClip)),
      textClips: JSON.parse(JSON.stringify(state.textClips)),
      stickerClips: JSON.parse(JSON.stringify(state.stickerClips)),
      audioClips: JSON.parse(JSON.stringify(state.audioClips))
    };
    history.push(draftPayload);
    historyIndex = history.length - 1;
  }

  window.undo = function() {
    const store = window.StudioProjectStore;
    if (store) {
      return store.undo();
    }
    return false;
  };

  window.redo = function() {
    const store = window.StudioProjectStore;
    if (store) {
      return store.redo();
    }
    return false;
  };

  function applyHistoryState(hState) {
    state.videoClip = JSON.parse(JSON.stringify(hState.videoClip));
    state.textClips = JSON.parse(JSON.stringify(hState.textClips));
    state.stickerClips = JSON.parse(JSON.stringify(hState.stickerClips));
    state.audioClips = JSON.parse(JSON.stringify(hState.audioClips));
    
    renderTimelineClips();
    renderInspector();
    renderPlayerOverlay();
  }

  const commitStoreState = (st, pushUndo = false) => {
    const store = window.StudioProjectStore;
    if (store) store.setState(st, pushUndo);
    if (window.StudioTimeline && typeof window.StudioTimeline.render === 'function') {
      window.StudioTimeline.render();
    }
  };

  function updateNleItemProperty(item, updaterFn, pushUndo = false) {
    const store = window.StudioProjectStore;
    if (!store) return;
    const st = store.getState();
    const activeItem = st.items.find(i => i.id === item.id);
    if (!activeItem) return;

    const playheadTime = window.PreviewEngine?.currentTime ?? 0;
    const relTime = Math.max(0, playheadTime - activeItem.start);

    if (activeItem.keyframes && activeItem.keyframes.length > 0) {
      let kf = activeItem.keyframes.find(k => Math.abs(k.time - relTime) <= 0.15);
      if (!kf) {
        let currentKfProps = {
          position: { x: activeItem.position?.x ?? 50, y: activeItem.position?.y ?? 50 },
          scale: { x: activeItem.scale?.x ?? 1.0, y: activeItem.scale?.y ?? 1.0 },
          rotation: activeItem.rotation ?? 0,
          opacity: activeItem.opacity !== undefined ? activeItem.opacity : 1.0,
          volume: activeItem.volume !== undefined ? activeItem.volume : 100,
          effectIntensity: activeItem.intensity ?? 100,
          filterIntensity: 100,
          filters: {
            brightness: activeItem.filters?.brightness ?? 100,
            contrast: activeItem.filters?.contrast ?? 100,
            saturation: activeItem.filters?.saturation ?? 100,
            exposure: activeItem.filters?.exposure ?? 100,
            temperature: activeItem.filters?.temperature ?? 0,
            tint: activeItem.filters?.tint ?? 0
          },
          style: {
            fontSize: activeItem.style?.fontSize ?? 28,
            color: activeItem.style?.color ?? '#ffffff',
            strokeWidth: activeItem.style?.strokeWidth ?? 2,
            strokeColor: activeItem.style?.strokeColor ?? '#ffffff'
          },
          mask: {
            type: activeItem.mask?.type ?? 'none',
            x: activeItem.mask?.x ?? 50,
            y: activeItem.mask?.y ?? 50,
            width: activeItem.mask?.width ?? 30,
            height: activeItem.mask?.height ?? 30,
            rotation: activeItem.mask?.rotation ?? 0,
            feather: activeItem.mask?.feather ?? 0,
            invert: activeItem.mask?.invert ?? false
          }
        };
        if (window.PreviewEngine && typeof window.PreviewEngine.interpolateKeyframeProperties === 'function') {
          const interp = window.PreviewEngine.interpolateKeyframeProperties(activeItem, relTime);
          currentKfProps = JSON.parse(JSON.stringify(interp));
        }
        kf = {
          time: relTime,
          interpolation: 'linear',
          properties: currentKfProps
        };
        activeItem.keyframes.push(kf);
      }
      updaterFn(kf.properties);
    } else {
      updaterFn(activeItem);
    }

    commitStoreState(st, pushUndo);
  }

  function renderInspectorKeyframesSection(item) {
    if (!elements.inspectorContent) return;

    // Create wrapper div
    const wrapper = document.createElement('div');
    wrapper.className = 'border-top pt-3 mt-3';

    const playheadTime = window.PreviewEngine?.currentTime ?? 0;
    const relTime = Math.max(0, playheadTime - item.start);

    const currentKfs = item.keyframes || [];
    const activeKf = currentKfs.find(kf => Math.abs(kf.time - relTime) <= 0.15);

    wrapper.innerHTML = `
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="capcut-control-label"><i class="fa-solid fa-key text-cyber me-1"></i> Keyframe Engine</div>
        <button class="btn btn-cyber btn-sm" id="btnUnifiedAddKf" style="font-size: 11px; padding: 2px 8px;">
          <i class="fa-solid fa-plus-circle"></i> Add Kf
        </button>
      </div>

      ${activeKf ? `
      <div class="capcut-control-group mb-2 p-2 border border-secondary rounded bg-dark" style="background-color: rgba(255,255,255,0.03) !important;">
        <label class="small text-cyber font-weight-bold mb-1 d-block">Active Keyframe at ${activeKf.time.toFixed(2)}s</label>
        <div class="row g-1 align-items-center">
          <div class="col-7">
            <select class="form-select form-control-cyber form-select-sm" id="propKfInterp">
              <option value="linear" ${activeKf.interpolation === 'linear' ? 'selected' : ''}>Linear</option>
              <option value="ease-in" ${activeKf.interpolation === 'ease-in' ? 'selected' : ''}>Ease In</option>
              <option value="ease-out" ${activeKf.interpolation === 'ease-out' ? 'selected' : ''}>Ease Out</option>
              <option value="ease-in-out" ${activeKf.interpolation === 'ease-in-out' ? 'selected' : ''}>Ease In/Out</option>
            </select>
          </div>
          <div class="col-5">
            <input type="number" step="0.1" class="form-control form-control-cyber form-control-sm" id="propKfMoveTime" value="${activeKf.time.toFixed(2)}" placeholder="Time">
          </div>
        </div>
      </div>
      ` : ''}

      <div id="unifiedKeyframesList" class="small text-secondary mt-1" style="max-height: 120px; overflow-y: auto;">
        <!-- Dynamically rendered -->
      </div>
    `;

    elements.inspectorContent.appendChild(wrapper);

    // Render keyframes list
    const listEl = document.getElementById('unifiedKeyframesList');
    if (listEl) {
      if (currentKfs.length === 0) {
        listEl.innerHTML = '<div class="text-center py-2 text-muted italic">No keyframes added yet</div>';
      } else {
        const sorted = [...currentKfs].sort((a, b) => a.time - b.time);
        listEl.innerHTML = sorted.map(kf => `
          <div class="d-flex justify-content-between align-items-center p-1 border-bottom border-secondary mb-1">
            <span style="font-size:10px; cursor:pointer;" class="btn-seek-kf-unified" data-time="${kf.time}">
              <i class="fa-solid fa-clock-rotate-left me-1 text-cyber"></i> ${kf.time.toFixed(2)}s (${kf.interpolation || 'linear'})
            </span>
            <button class="btn btn-sm btn-link text-danger p-0 btn-del-kf-unified" data-time="${kf.time}" style="text-decoration:none;">
              <i class="fa-solid fa-trash-can"></i>
            </button>
          </div>
        `).join('');

        listEl.querySelectorAll('.btn-seek-kf-unified').forEach(btn => {
          btn.addEventListener('click', () => {
            const t = parseFloat(btn.dataset.time);
            if (window.PreviewEngine) {
              window.PreviewEngine.seek(item.start + t);
            }
          });
        });

        listEl.querySelectorAll('.btn-del-kf-unified').forEach(btn => {
          btn.addEventListener('click', () => {
            const t = parseFloat(btn.dataset.time);
            const store = window.StudioProjectStore;
            const st = store.getState();
            const activeItem = st.items.find(i => i.id === item.id);
            if (activeItem) {
              activeItem.keyframes = (activeItem.keyframes || []).filter(k => Math.abs(k.time - t) > 0.01);
              commitStoreState(st, true);
              renderInspector();
            }
          });
        });
      }
    }

    // Add keyframe listener
    document.getElementById('btnUnifiedAddKf').addEventListener('click', () => {
      const store = window.StudioProjectStore;
      const st = store.getState();
      const activeItem = st.items.find(i => i.id === item.id);
      if (activeItem) {
        const pTime = window.PreviewEngine?.currentTime ?? 0;
        const rTime = Math.max(0, pTime - activeItem.start);
        
        if (!activeItem.keyframes) activeItem.keyframes = [];
        activeItem.keyframes = activeItem.keyframes.filter(k => Math.abs(k.time - rTime) > 0.05);

        let currentProps = {
          position: { x: activeItem.position?.x ?? 50, y: activeItem.position?.y ?? 50 },
          scale: { x: activeItem.scale?.x ?? 1.0, y: activeItem.scale?.y ?? 1.0 },
          rotation: activeItem.rotation ?? 0,
          opacity: activeItem.opacity !== undefined ? activeItem.opacity : 1.0,
          volume: activeItem.volume !== undefined ? activeItem.volume : 100,
          effectIntensity: activeItem.intensity ?? 100,
          filterIntensity: 100,
          filters: {
            brightness: activeItem.filters?.brightness ?? 100,
            contrast: activeItem.filters?.contrast ?? 100,
            saturation: activeItem.filters?.saturation ?? 100,
            exposure: activeItem.filters?.exposure ?? 100,
            temperature: activeItem.filters?.temperature ?? 0,
            tint: activeItem.filters?.tint ?? 0
          },
          style: {
            fontSize: activeItem.style?.fontSize ?? 28,
            color: activeItem.style?.color ?? '#ffffff',
            strokeWidth: activeItem.style?.strokeWidth ?? 2,
            strokeColor: activeItem.style?.strokeColor ?? '#ffffff'
          },
          mask: {
            type: activeItem.mask?.type ?? 'none',
            x: activeItem.mask?.x ?? 50,
            y: activeItem.mask?.y ?? 50,
            width: activeItem.mask?.width ?? 30,
            height: activeItem.mask?.height ?? 30,
            rotation: activeItem.mask?.rotation ?? 0,
            feather: activeItem.mask?.feather ?? 0,
            invert: activeItem.mask?.invert ?? false
          }
        };

        if (window.PreviewEngine && typeof window.PreviewEngine.interpolateKeyframeProperties === 'function') {
          const interp = window.PreviewEngine.interpolateKeyframeProperties(activeItem, rTime);
          currentProps = JSON.parse(JSON.stringify(interp));
        }

        activeItem.keyframes.push({
          time: rTime,
          interpolation: 'linear',
          properties: currentProps
        });

        console.log('[DEBUG AddKf] Pushed keyframe at relTime =', rTime, 'New total keyframes =', activeItem.keyframes.length);

        commitStoreState(st, true);
        renderInspector();
      }
    });

    if (activeKf) {
      document.getElementById('propKfInterp').addEventListener('change', (e) => {
        const store = window.StudioProjectStore;
        const st = store.getState();
        const activeItem = st.items.find(i => i.id === item.id);
        if (activeItem) {
          const kf = (activeItem.keyframes || []).find(k => Math.abs(k.time - activeKf.time) <= 0.1);
          if (kf) kf.interpolation = e.target.value;
          commitStoreState(st, true);
          renderInspector();
        }
      });

      document.getElementById('propKfMoveTime').addEventListener('change', (e) => {
        const store = window.StudioProjectStore;
        const st = store.getState();
        const activeItem = st.items.find(i => i.id === item.id);
        if (activeItem) {
          const kf = (activeItem.keyframes || []).find(k => Math.abs(k.time - activeKf.time) <= 0.1);
          if (kf) kf.time = Math.max(0, parseFloat(e.target.value) || 0);
          commitStoreState(st, true);
          renderInspector();
        }
      });
    }
  }

  // ── Render Properties in Right Panel Inspector ──
  // ── Render Properties in Right Panel Inspector ──
  function renderInspector() {
    if (!elements.inspectorTitle || !elements.inspectorContent) return;
    
    if (!state.selectedId) {
      elements.inspectorTitle.innerText = 'Inspector';
      elements.inspectorContent.innerHTML = `
        <div class="text-center py-5 text-secondary">
          <i class="fa-solid fa-circle-info fa-2x mb-3" style="opacity: 0.3;"></i>
          <p class="small mb-0">Select any clip on the timeline or drag overlay layers to adjust properties.</p>
        </div>
      `;
      return;
    }

    const store = window.StudioProjectStore;
    if (!store) return;
    const storeState = store.getState();
    const item = storeState.items.find(i => i.id === state.selectedId);

    if (window.StudioMaskCutout && typeof window.StudioMaskCutout.syncWorkspaceUI === 'function') {
      window.StudioMaskCutout.syncWorkspaceUI(item);
    }

    if (!item) {
      elements.inspectorTitle.innerText = 'Inspector';
      elements.inspectorContent.innerHTML = `
        <div class="text-center py-5 text-secondary">
          <i class="fa-solid fa-circle-info fa-2x mb-3" style="opacity: 0.3;"></i>
          <p class="small mb-0">Select a valid clip to view details.</p>
        </div>
      `;
      return;
    }

    // Helper to push undo state
    const commitState = (st, pushUndo = false) => {
      store.setState(st, pushUndo);
      // Synchronize timeline visually where needed
      if (window.StudioTimeline && typeof window.StudioTimeline.render === 'function') {
        window.StudioTimeline.render();
      }
    };
    
    if (item.type === 'video' || item.type === 'image') {
      elements.inspectorTitle.innerText = item.type === 'video' ? 'Video Properties' : 'Image Properties';
      
      const px = item.position?.x ?? 50;
      const py = item.position?.y ?? 50;
      const sx = item.scale?.x ?? 1.0;
      const sy = item.scale?.y ?? 1.0;
      const r = item.rotation ?? 0;
      const op = item.opacity !== undefined ? item.opacity : 1.0;
      const volumeVal = item.volume !== undefined ? item.volume : 100;

      // Crop
      const cL = item.crop?.left ?? 0;
      const cR = item.crop?.right ?? 0;
      const cT = item.crop?.top ?? 0;
      const cB = item.crop?.bottom ?? 0;

      // Flip & Fit
      const flipH = item.flip?.horizontal ?? false;
      const flipV = item.flip?.vertical ?? false;
      const fitMode = item.fit ?? 'contain';
      const fillVal = item.fill ?? '#000000';

      let replaceBlock = '';
      if (item.isPlaceholder) {
        const uploaded = storeState.items.filter(i => (i.type === 'video' || i.type === 'image') && !i.isPlaceholder);
        const optionsHtml = uploaded.map(u => `<option value="${u.url}">${u.name}</option>`).join('');
        
        replaceBlock = `
          <div class="alert alert-cyber alert-info p-2 mb-3">
            <div class="small font-weight-bold mb-1"><i class="fa-solid fa-lightbulb text-info me-1"></i> Placeholder Clip</div>
            <p class="small text-secondary mb-2" style="font-size:10px;">Select any uploaded media to replace this template placeholder.</p>
            <div class="d-flex gap-2 align-items-center">
              <select id="selectReplaceMedia" class="form-select form-select-sm form-control-cyber" style="flex:1;">
                <option value="">-- Choose Media --</option>
                ${optionsHtml}
              </select>
              <button type="button" class="btn btn-cyber btn-sm" id="btnApplyReplace">Swap</button>
            </div>
          </div>
        `;
      }

      elements.inspectorContent.innerHTML = `
        ${replaceBlock}
        <!-- Position & Transform -->
        <div class="border-bottom pb-3 mb-3">
          <div class="capcut-control-label mb-2">Transform</div>
          <div class="row g-2">
            <div class="col-6">
              <label class="small text-secondary">Position X (%)</label>
              <input type="number" class="form-control form-control-cyber form-control-sm" id="propPosX" value="${Math.round(px)}">
            </div>
            <div class="col-6">
              <label class="small text-secondary">Position Y (%)</label>
              <input type="number" class="form-control form-control-cyber form-control-sm" id="propPosY" value="${Math.round(py)}">
            </div>
          </div>
          <div class="row g-2 mt-2">
            <div class="col-6">
              <label class="small text-secondary">Scale X (%)</label>
              <input type="number" class="form-control form-control-cyber form-control-sm" id="propScaleX" value="${Math.round(sx * 100)}">
            </div>
            <div class="col-6">
              <label class="small text-secondary">Scale Y (%)</label>
              <input type="number" class="form-control form-control-cyber form-control-sm" id="propScaleY" value="${Math.round(sy * 100)}">
            </div>
          </div>
          <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" id="propScaleLink" checked>
            <label class="form-check-label small text-secondary" for="propScaleLink">Link Aspect Ratio</label>
          </div>
          <div class="capcut-control-group mt-3">
            <label class="capcut-control-label">Rotation (Deg)</label>
            <input type="range" class="form-range" id="propRot" min="-180" max="180" value="${r}">
            <span class="small text-secondary" id="propRotVal">${r}°</span>
          </div>
          <div class="capcut-control-group mt-2">
            <label class="capcut-control-label">Opacity</label>
            <input type="range" class="form-range" id="propOp" min="0" max="100" value="${Math.round(op * 100)}">
            <span class="small text-secondary" id="propOpVal">${Math.round(op * 100)}%</span>
          </div>
        </div>

        <!-- Flip & Fit -->
        <div class="border-bottom pb-3 mb-3">
          <div class="capcut-control-label mb-2">Flip & Fitting</div>
          <div class="d-flex gap-2 mb-2">
            <button class="btn btn-cyber btn-sm flex-1 ${flipH ? 'active' : ''}" id="btnFlipH"><i class="fa-solid fa-arrows-left-right me-1"></i> Flip H</button>
            <button class="btn btn-cyber btn-sm flex-1 ${flipV ? 'active' : ''}" id="btnFlipV"><i class="fa-solid fa-arrows-up-down me-1"></i> Flip V</button>
          </div>
          <div class="capcut-control-group mt-2">
            <label class="capcut-control-label">Canvas Fit</label>
            <select id="propFit" class="form-select form-control-cyber form-select-sm">
              <option value="contain" ${fitMode === 'contain' ? 'selected' : ''}>Contain (Fit)</option>
              <option value="cover" ${fitMode === 'cover' ? 'selected' : ''}>Cover (Zoom)</option>
              <option value="fill" ${fitMode === 'fill' ? 'selected' : ''}>Fill (Stretch)</option>
            </select>
          </div>
          <div class="capcut-control-group mt-2">
            <label class="capcut-control-label">Fit Background Fill</label>
            <input type="color" id="propFill" class="form-control form-control-cyber form-control-sm" style="height:34px;padding:3px;" value="${fillVal}">
          </div>
        </div>

        <!-- Speed Adjustment -->
        <div class="border-bottom pb-3 mb-3">
          <div class="capcut-control-label mb-2">Speed Adjustment</div>
          <div class="row g-1 mb-2">
            <div class="col-4">
              <button class="btn btn-cyber btn-sm w-100 btn-speed-preset" data-speed="0.5">0.5x Slow</button>
            </div>
            <div class="col-4">
              <button class="btn btn-cyber btn-sm w-100 btn-speed-preset" data-speed="1.0">1.0x Norm</button>
            </div>
            <div class="col-4">
              <button class="btn btn-cyber btn-sm w-100 btn-speed-preset" data-speed="2.0">2.0x Fast</button>
            </div>
          </div>
          <div class="capcut-control-group mt-2">
            <label class="capcut-control-label">Custom Speed</label>
            <input type="range" class="form-range" id="propSpeed" min="10" max="1000" step="10" value="${Math.round((item.speed ?? 1.0) * 100)}">
            <span class="small text-secondary" id="propSpeedVal">${(item.speed ?? 1.0).toFixed(2)}x</span>
          </div>
        </div>

        <!-- Crop -->
        <div class="border-bottom pb-3 mb-3">
          <div class="capcut-control-label mb-2">Crop Bounds (%)</div>
          <div class="row g-1">
            <div class="col-6">
              <label class="small text-secondary">Left</label>
              <input type="number" class="form-control form-control-cyber form-control-sm" id="propCropL" min="0" max="100" value="${cL}">
            </div>
            <div class="col-6">
              <label class="small text-secondary">Right</label>
              <input type="number" class="form-control form-control-cyber form-control-sm" id="propCropR" min="0" max="100" value="${cR}">
            </div>
            <div class="col-6 mt-1">
              <label class="small text-secondary">Top</label>
              <input type="number" class="form-control form-control-cyber form-control-sm" id="propCropT" min="0" max="100" value="${cT}">
            </div>
            <div class="col-6 mt-1">
              <label class="small text-secondary">Bottom</label>
              <input type="number" class="form-control form-control-cyber form-control-sm" id="propCropB" min="0" max="100" value="${cB}">
            </div>
          </div>
        </div>

        <!-- Volume (Video items with audio) -->
        ${item.type === 'video' ? `
        <div class="border-bottom pb-3 mb-3">
          <div class="capcut-control-label mb-2">Audio Volume</div>
          <input type="range" class="form-range" id="propVolume" min="0" max="100" value="${volumeVal}">
          <span class="small text-secondary" id="propVolumeVal">${volumeVal}%</span>
        </div>
        ` : ''}
      `;

      // Live dragging update helper
      const updateVal = (updater, pushUndo = false) => {
        updateNleItemProperty(item, updater, pushUndo);
      };

      // Listeners
      document.getElementById('propPosX').addEventListener('input', (e) => updateVal(it => { it.position.x = parseFloat(e.target.value) || 0; }));
      document.getElementById('propPosY').addEventListener('input', (e) => updateVal(it => { it.position.y = parseFloat(e.target.value) || 0; }));
      
      const scX = document.getElementById('propScaleX');
      const scY = document.getElementById('propScaleY');
      const scLink = document.getElementById('propScaleLink');

      scX.addEventListener('input', (e) => {
        const val = (parseFloat(e.target.value) || 100) / 100;
        updateVal(it => {
          it.scale.x = val;
          if (scLink.checked) {
            it.scale.y = val;
            scY.value = Math.round(val * 100);
          }
        });
      });

      scY.addEventListener('input', (e) => {
        const val = (parseFloat(e.target.value) || 100) / 100;
        updateVal(it => {
          it.scale.y = val;
          if (scLink.checked) {
            it.scale.x = val;
            scX.value = Math.round(val * 100);
          }
        });
      });

      document.getElementById('propRot').addEventListener('input', (e) => {
        const val = parseInt(e.target.value);
        document.getElementById('propRotVal').innerText = val + '°';
        updateVal(it => { it.rotation = val; });
      });

      document.getElementById('propOp').addEventListener('input', (e) => {
        const val = parseInt(e.target.value) / 100;
        document.getElementById('propOpVal').innerText = Math.round(val * 100) + '%';
        updateVal(it => { it.opacity = val; });
      });

      document.getElementById('btnFlipH').addEventListener('click', (e) => {
        e.target.classList.toggle('active');
        updateVal(it => {
          if (!it.flip) it.flip = { horizontal: false, vertical: false };
          it.flip.horizontal = !it.flip.horizontal;
        }, true);
      });

      document.getElementById('btnFlipV').addEventListener('click', (e) => {
        e.target.classList.toggle('active');
        updateVal(it => {
          if (!it.flip) it.flip = { horizontal: false, vertical: false };
          it.flip.vertical = !it.flip.vertical;
        }, true);
      });

      document.getElementById('propFit').addEventListener('change', (e) => {
        updateVal(it => { it.fit = e.target.value; }, true);
      });

      document.getElementById('propFill').addEventListener('input', (e) => {
        updateVal(it => { it.fill = e.target.value; });
      });

      // Crop elements
      const updateCrop = (key, val) => {
        updateVal(it => {
          if (!it.crop) it.crop = { left: 0, right: 0, top: 0, bottom: 0 };
          it.crop[key] = Math.max(0, Math.min(100, parseFloat(val) || 0));
        });
      };
      document.getElementById('propCropL').addEventListener('input', (e) => updateCrop('left', e.target.value));
      document.getElementById('propCropR').addEventListener('input', (e) => updateCrop('right', e.target.value));
      document.getElementById('propCropT').addEventListener('input', (e) => updateCrop('top', e.target.value));
      document.getElementById('propCropB').addEventListener('input', (e) => updateCrop('bottom', e.target.value));

      // Speed listeners
      document.querySelectorAll('.btn-speed-preset').forEach(btn => {
        btn.addEventListener('click', (e) => {
          const speed = parseFloat(e.currentTarget.dataset.speed);
          document.getElementById('propSpeed').value = Math.round(speed * 100);
          document.getElementById('propSpeedVal').innerText = speed.toFixed(2) + 'x';
          updateVal(it => { it.speed = speed; }, true);
        });
      });

      document.getElementById('propSpeed').addEventListener('input', (e) => {
        const speed = parseInt(e.target.value) / 100;
        document.getElementById('propSpeedVal').innerText = speed.toFixed(2) + 'x';
        updateVal(it => { it.speed = speed; });
      });

      if (item.type === 'video') {
        document.getElementById('propVolume').addEventListener('input', (e) => {
          const val = parseInt(e.target.value);
          document.getElementById('propVolumeVal').innerText = val + '%';
          updateVal(it => { it.volume = val; });
        });
      }

      if (item.isPlaceholder) {
        const replaceSelect = document.getElementById('selectReplaceMedia');
        const applyBtn = document.getElementById('btnApplyReplace');
        if (replaceSelect && applyBtn) {
          applyBtn.addEventListener('click', () => {
            const val = replaceSelect.value;
            if (!val) {
              alert('Please select an uploaded media clip first.');
              return;
            }
            const name = replaceSelect.options[replaceSelect.selectedIndex].text;
            if (window.StudioTemplates && typeof window.StudioTemplates.replacePlaceholderMedia === 'function') {
              window.StudioTemplates.replacePlaceholderMedia(item.id, val, name);
              renderInspector();
            }
          });
        }
      }
    }
    
    else if (item.type === 'text' || item.type === 'caption') {
      elements.inspectorTitle.innerText = item.type === 'text' ? 'Text Style' : 'Caption Style';
      
      const text = item.content || '';
      const style = item.style || {};
      const fontSize = style.fontSize || 24;
      const color = style.color || '#ffffff';
      const fontFamily = style.fontFamily || 'Space Grotesk';
      const weight = style.fontWeight || style.weight || 'bold';
      const align = style.textAlign || 'center';

      // Spacing
      const letterSp = style.letterSpacing ?? 0;
      const lineHt = style.lineHeight ?? 1.2;

      // Stroke
      const hasStroke = style.stroke?.width > 0;
      const strokeW = style.stroke?.width ?? 2;
      const strokeC = style.stroke?.color ?? '#000000';

      // Shadow
      const hasShadow = !!style.shadow;
      const shX = style.shadow?.x ?? 2;
      const shY = style.shadow?.y ?? 2;
      const shB = style.shadow?.blur ?? 4;
      const shC = style.shadow?.color ?? 'rgba(0,0,0,0.5)';

      // Background
      const hasBg = style.background?.enabled ?? false;
      const bgC = style.background?.color ?? '#000000';
      const bgOp = style.background?.opacity ?? 60;
      const bgPad = style.background?.padding ?? 8;

      let brandShortcuts = '';
      if (window.StudioBrand && typeof window.StudioBrand.getBrandKit === 'function') {
        const kit = window.StudioBrand.getBrandKit();
        if (kit) {
          const colorsHtml = (kit.colors || []).map(c => `
            <button type="button" class="btn-brand-shortcut-color border border-secondary rounded-circle" 
                    style="width: 20px; height: 20px; background-color: ${c}; padding: 0; cursor: pointer;" 
                    data-color="${c}" title="Apply ${c}"></button>
          `).join('');

          const fontsHtml = (kit.fonts || []).map(f => `
            <button type="button" class="btn btn-cyber btn-xxs btn-brand-shortcut-font" 
                    data-font="${f}" style="font-family: '${f}', sans-serif; font-size: 9px; padding: 2px 5px;">
              ${f}
            </button>
          `).join('');

          brandShortcuts = `
            <div class="border-bottom pb-2 mb-3">
              <div class="capcut-control-label mb-1" style="font-size: 10px; color: var(--capcut-cyber);"><i class="fa-solid fa-gem me-1"></i> Brand Kit Presets</div>
              <div class="d-flex gap-1 flex-wrap mb-2">
                ${colorsHtml}
              </div>
              <div class="d-flex gap-1 flex-wrap">
                ${fontsHtml}
              </div>
            </div>
          `;
        }
      }

      elements.inspectorContent.innerHTML = `
        ${brandShortcuts}
        <!-- Content -->
        <div class="capcut-control-group mb-3">
          <label class="capcut-control-label">Text Content</label>
          <textarea id="propTextContent" class="form-control form-control-cyber form-control-sm" rows="2">${text}</textarea>
        </div>

        <!-- Typography -->
        <div class="border-bottom pb-3 mb-3">
          <div class="capcut-control-label mb-2">Typography</div>
          <div class="row g-2">
            <div class="col-7">
              <label class="small text-secondary">Font Family</label>
              <select id="propTextFont" class="form-select form-control-cyber form-select-sm">
                <option value="Space Grotesk" ${fontFamily === 'Space Grotesk' ? 'selected' : ''}>Space Grotesk</option>
                <option value="Outfit" ${fontFamily === 'Outfit' ? 'selected' : ''}>Outfit</option>
                <option value="Inter" ${fontFamily === 'Inter' ? 'selected' : ''}>Inter</option>
                <option value="Arial" ${fontFamily === 'Arial' ? 'selected' : ''}>Arial</option>
              </select>
            </div>
            <div class="col-5">
              <label class="small text-secondary">Font Color</label>
              <input type="color" id="propTextColor" class="form-control form-control-cyber form-control-sm" style="height:34px;padding:3px;" value="${color}">
            </div>
          </div>
          <div class="row g-2 mt-2">
            <div class="col-6">
              <label class="small text-secondary">Font Size</label>
              <input type="number" id="propTextSize" class="form-control form-control-cyber form-control-sm" value="${fontSize}">
            </div>
            <div class="col-6">
              <label class="small text-secondary">Weight</label>
              <select id="propTextWeight" class="form-select form-control-cyber form-select-sm">
                <option value="normal" ${weight === 'normal' ? 'selected' : ''}>Normal</option>
                <option value="bold" ${weight === 'bold' ? 'selected' : ''}>Bold</option>
              </select>
            </div>
          </div>
          <div class="mt-3">
            <label class="small text-secondary mb-1">Alignment</label>
            <div class="d-flex gap-1" id="btnGroupAlign">
              <button class="btn btn-cyber btn-sm flex-1 ${align === 'left' ? 'active' : ''}" data-align="left"><i class="fa-solid fa-align-left"></i></button>
              <button class="btn btn-cyber btn-sm flex-1 ${align === 'center' ? 'active' : ''}" data-align="center"><i class="fa-solid fa-align-center"></i></button>
              <button class="btn btn-cyber btn-sm flex-1 ${align === 'right' ? 'active' : ''}" data-align="right"><i class="fa-solid fa-align-right"></i></button>
              <button class="btn btn-cyber btn-sm flex-1 ${align === 'justify' ? 'active' : ''}" data-align="justify"><i class="fa-solid fa-align-justify"></i></button>
            </div>
          </div>
        </div>

        <!-- Spacing -->
        <div class="border-bottom pb-3 mb-3">
          <div class="capcut-control-label mb-2">Spacing</div>
          <div class="capcut-control-group">
            <label class="small text-secondary">Letter Spacing (px)</label>
            <input type="range" class="form-range" id="propTextLetterSp" min="-5" max="25" value="${letterSp}">
            <span class="small text-secondary" id="propTextLetterSpVal">${letterSp}px</span>
          </div>
          <div class="capcut-control-group mt-2">
            <label class="small text-secondary">Line Height</label>
            <input type="range" class="form-range" id="propTextLineHt" min="5" max="30" value="${Math.round(lineHt * 10)}">
            <span class="small text-secondary" id="propTextLineHtVal">${lineHt}</span>
          </div>
        </div>

        <!-- Stroke -->
        <div class="border-bottom pb-3 mb-3">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="propTextStrokeEnabled" ${hasStroke ? 'checked' : ''}>
            <label class="form-check-label capcut-control-label" for="propTextStrokeEnabled">Text Stroke</label>
          </div>
          <div id="strokeSettings" style="display: ${hasStroke ? 'block' : 'none'};">
            <div class="capcut-control-group">
              <label class="small text-secondary">Stroke Width</label>
              <input type="range" class="form-range" id="propTextStrokeW" min="1" max="12" value="${strokeW}">
              <span class="small text-secondary" id="propTextStrokeWVal">${strokeW}px</span>
            </div>
            <div class="capcut-control-group mt-2">
              <label class="small text-secondary">Stroke Color</label>
              <input type="color" id="propTextStrokeC" class="form-control form-control-cyber form-control-sm" style="height:34px;padding:3px;" value="${strokeC}">
            </div>
          </div>
        </div>

        <!-- Shadow -->
        <div class="border-bottom pb-3 mb-3">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="propTextShadowEnabled" ${hasShadow ? 'checked' : ''}>
            <label class="form-check-label capcut-control-label" for="propTextShadowEnabled">Text Drop Shadow</label>
          </div>
          <div id="shadowSettings" style="display: ${hasShadow ? 'block' : 'none'};">
            <div class="row g-2">
              <div class="col-6">
                <label class="small text-secondary">Offset X</label>
                <input type="number" id="propTextShX" class="form-control form-control-cyber form-control-sm" value="${shX}">
              </div>
              <div class="col-6">
                <label class="small text-secondary">Offset Y</label>
                <input type="number" id="propTextShY" class="form-control form-control-cyber form-control-sm" value="${shY}">
              </div>
            </div>
            <div class="capcut-control-group mt-2">
              <label class="small text-secondary">Shadow Blur</label>
              <input type="range" class="form-range" id="propTextShB" min="0" max="30" value="${shB}">
              <span class="small text-secondary" id="propTextShBVal">${shB}px</span>
            </div>
            <div class="capcut-control-group mt-2">
              <label class="small text-secondary">Shadow Color</label>
              <input type="color" id="propTextShC" class="form-control form-control-cyber form-control-sm" style="height:34px;padding:3px;" value="${shC.startsWith('rgba') ? '#000000' : shC}">
            </div>
          </div>
        </div>

        <!-- Background -->
        <div class="pb-3 mb-3">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="propTextBgEnabled" ${hasBg ? 'checked' : ''}>
            <label class="form-check-label capcut-control-label" for="propTextBgEnabled">Background Box</label>
          </div>
          <div id="bgSettings" style="display: ${hasBg ? 'block' : 'none'};">
            <div class="row g-2">
              <div class="col-7">
                <label class="small text-secondary">Box Color</label>
                <input type="color" id="propTextBgC" class="form-control form-control-cyber form-control-sm" style="height:34px;padding:3px;" value="${bgC}">
              </div>
              <div class="col-5">
                <label class="small text-secondary">Padding (px)</label>
                <input type="number" id="propTextBgPad" class="form-control form-control-cyber form-control-sm" value="${bgPad}">
              </div>
            </div>
            <div class="capcut-control-group mt-2">
              <label class="small text-secondary">Box Opacity</label>
              <input type="range" class="form-range" id="propTextBgOp" min="10" max="100" value="${bgOp}">
              <span class="small text-secondary" id="propTextBgOpVal">${bgOp}%</span>
            </div>
          </div>
        </div>

        <!-- Extra Styling: Italic, Opacity, Glow, Blur -->
        <div class="border-top pt-3 mt-3 pb-3 mb-3">
          <div class="capcut-control-label mb-2">Effects & Filters</div>
          <div class="row g-2">
            <div class="col-6">
              <label class="small text-secondary">Font Style</label>
              <select id="propTextStyle" class="form-select form-control-cyber form-select-sm">
                <option value="normal" ${style.fontStyle === 'normal' ? 'selected' : ''}>Normal</option>
                <option value="italic" ${style.fontStyle === 'italic' ? 'selected' : ''}>Italic</option>
              </select>
            </div>
            <div class="col-6">
              <label class="small text-secondary">Blur Filter (px)</label>
              <input type="range" class="form-range" id="propTextBlur" min="0" max="10" value="${style.blur ?? 0}">
              <span class="small text-secondary" id="propTextBlurVal">${style.blur ?? 0}px</span>
            </div>
          </div>
          <div class="capcut-control-group mt-2">
            <label class="small text-secondary">Text Opacity</label>
            <input type="range" class="form-range" id="propTextOpacity" min="10" max="100" value="${Math.round((item.opacity ?? 1.0) * 100)}">
            <span class="small text-secondary" id="propTextOpacityVal">${Math.round((item.opacity ?? 1.0) * 100)}%</span>
          </div>

          <!-- Glow Switch -->
          <div class="form-check form-switch mt-3 mb-2">
            <input class="form-check-input" type="checkbox" id="propTextGlowEnabled" ${style.glow?.enabled ? 'checked' : ''}>
            <label class="form-check-label capcut-control-label" for="propTextGlowEnabled">Outer Glow</label>
          </div>
          <div id="glowSettings" style="display: ${style.glow?.enabled ? 'block' : 'none'};">
            <div class="row g-2">
              <div class="col-7">
                <label class="small text-secondary">Glow Color</label>
                <input type="color" id="propTextGlowC" class="form-control form-control-cyber form-control-sm" style="height:34px;padding:3px;" value="${style.glow?.color ?? '#00ffff'}">
              </div>
              <div class="col-5">
                <label class="small text-secondary">Radius (px)</label>
                <input type="number" id="propTextGlowR" class="form-control form-control-cyber form-control-sm" value="${style.glow?.radius ?? 8}">
              </div>
            </div>
          </div>
        </div>

        <!-- Presets & Templates -->
        <div class="border-top pt-3 mt-3 pb-3 mb-3">
          <div class="capcut-control-label mb-2">Presets & Templates</div>
          <div class="capcut-control-group">
            <label class="small text-secondary">Style Presets</label>
            <select id="propTextPreset" class="form-select form-control-cyber form-select-sm">
              <option value="">-- Apply Preset --</option>
              <option value="cyberpunk">Cyberpunk Neon</option>
              <option value="retro">Retro Arcade</option>
              <option value="minimalist">Minimalist Shadow</option>
              <option value="gold">Title Gold</option>
            </select>
          </div>
          <div class="capcut-control-group mt-2">
            <label class="small text-secondary">Text Layout Templates</label>
            <select id="propTextTemplate" class="form-select form-control-cyber form-select-sm">
              <option value="">-- Apply Template --</option>
              <option value="subtitle">Standard Subtitle</option>
              <option value="lowerthird">Lower Third Left</option>
              <option value="fullscreen">Fullscreen Intro</option>
            </select>
          </div>
        </div>

        <!-- Animations: In, Out, Combo -->
        <div class="border-top pt-3 mt-3 pb-3 mb-3">
          <div class="capcut-control-label mb-2">Text Animations</div>
          <div class="row g-2">
            <div class="col-4">
              <label class="small text-secondary">In Anim</label>
              <select id="propTextAnimIn" class="form-select form-control-cyber form-select-sm">
                <option value="" ${!item.animations?.in ? 'selected' : ''}>None</option>
                <option value="fade" ${item.animations?.in === 'fade' ? 'selected' : ''}>Fade In</option>
                <option value="zoom" ${item.animations?.in === 'zoom' ? 'selected' : ''}>Zoom In</option>
                <option value="slide" ${item.animations?.in === 'slide' ? 'selected' : ''}>Slide In</option>
              </select>
            </div>
            <div class="col-4">
              <label class="small text-secondary">Out Anim</label>
              <select id="propTextAnimOut" class="form-select form-control-cyber form-select-sm">
                <option value="" ${!item.animations?.out ? 'selected' : ''}>None</option>
                <option value="fade" ${item.animations?.out === 'fade' ? 'selected' : ''}>Fade Out</option>
                <option value="zoom" ${item.animations?.out === 'zoom' ? 'selected' : ''}>Zoom Out</option>
                <option value="slide" ${item.animations?.out === 'slide' ? 'selected' : ''}>Slide Out</option>
              </select>
            </div>
            <div class="col-4">
              <label class="small text-secondary">Combo</label>
              <select id="propTextAnimCombo" class="form-select form-control-cyber form-select-sm">
                <option value="" ${!item.animations?.combo ? 'selected' : ''}>None</option>
                <option value="bounce" ${item.animations?.combo === 'bounce' ? 'selected' : ''}>Bounce</option>
                <option value="pulse" ${item.animations?.combo === 'pulse' ? 'selected' : ''}>Pulse</option>
                <option value="wave" ${item.animations?.combo === 'wave' ? 'selected' : ''}>Wave</option>
              </select>
            </div>
          </div>
        </div>
      `;

      // Typography update helper
      const updateStyle = (updater) => {
        updateNleItemProperty(item, updater, false);
      };

      // Brand kit shortcut click handlers
      document.querySelectorAll('.btn-brand-shortcut-color').forEach(btn => {
        btn.addEventListener('click', () => {
          const col = btn.dataset.color;
          if (window.StudioBrand && typeof window.StudioBrand.applyColorToSelected === 'function') {
            window.StudioBrand.applyColorToSelected(col);
            renderInspector();
          }
        });
      });

      document.querySelectorAll('.btn-brand-shortcut-font').forEach(btn => {
        btn.addEventListener('click', () => {
          const font = btn.dataset.font;
          if (window.StudioBrand && typeof window.StudioBrand.applyFontToSelected === 'function') {
            window.StudioBrand.applyFontToSelected(font);
            renderInspector();
          }
        });
      });

      // Typography Listeners
      document.getElementById('propTextContent').addEventListener('input', (e) => {
        const st = store.getState();
        const activeItem = st.items.find(i => i.id === item.id);
        if (activeItem) {
          activeItem.content = e.target.value;
          commitState(st, false);
        }
      });

      document.getElementById('propTextFont').addEventListener('change', (e) => {
        updateStyle(it => { it.style.fontFamily = e.target.value; });
      });

      document.getElementById('propTextColor').addEventListener('input', (e) => {
        updateStyle(it => { it.style.color = e.target.value; });
      });

      document.getElementById('propTextSize').addEventListener('input', (e) => {
        updateStyle(it => { it.style.fontSize = parseInt(e.target.value) || 12; });
      });

      document.getElementById('propTextWeight').addEventListener('change', (e) => {
        updateStyle(it => { it.style.fontWeight = e.target.value; });
      });

      // Align buttons
      document.querySelectorAll('#btnGroupAlign button').forEach(btn => {
        btn.addEventListener('click', (e) => {
          const alignVal = btn.dataset.align;
          document.querySelectorAll('#btnGroupAlign button').forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
          updateStyle(it => { it.style.textAlign = alignVal; });
        });
      });

      // Spacing Listeners
      document.getElementById('propTextLetterSp').addEventListener('input', (e) => {
        const val = parseInt(e.target.value);
        document.getElementById('propTextLetterSpVal').innerText = val + 'px';
        updateStyle(it => { it.style.letterSpacing = val; });
      });

      document.getElementById('propTextLineHt').addEventListener('input', (e) => {
        const val = parseFloat(e.target.value) / 10;
        document.getElementById('propTextLineHtVal').innerText = val;
        updateStyle(it => { it.style.lineHeight = val; });
      });

      // Stroke toggle and parameters
      const strEnabled = document.getElementById('propTextStrokeEnabled');
      const strSettings = document.getElementById('strokeSettings');
      strEnabled.addEventListener('change', (e) => {
        strSettings.style.display = e.target.checked ? 'block' : 'none';
        updateStyle(it => {
          if (!it.style.stroke) it.style.stroke = { width: 0, color: '#000000' };
          it.style.stroke.width = e.target.checked ? parseInt(document.getElementById('propTextStrokeW').value) : 0;
        });
      });

      document.getElementById('propTextStrokeW').addEventListener('input', (e) => {
        const val = parseInt(e.target.value);
        document.getElementById('propTextStrokeWVal').innerText = val + 'px';
        updateStyle(it => {
          if (!it.style.stroke) it.style.stroke = { width: 0, color: '#000000' };
          it.style.stroke.width = val;
        });
      });

      document.getElementById('propTextStrokeC').addEventListener('input', (e) => {
        updateStyle(it => {
          if (!it.style.stroke) it.style.stroke = { width: 0, color: '#000000' };
          it.style.stroke.color = e.target.value;
        });
      });

      // Shadow toggle and parameters
      const shEnabled = document.getElementById('propTextShadowEnabled');
      const shSettings = document.getElementById('shadowSettings');
      shEnabled.addEventListener('change', (e) => {
        shSettings.style.display = e.target.checked ? 'block' : 'none';
        updateStyle(it => {
          if (e.target.checked) {
            it.style.shadow = {
              x: parseFloat(document.getElementById('propTextShX').value) || 2,
              y: parseFloat(document.getElementById('propTextShY').value) || 2,
              blur: parseFloat(document.getElementById('propTextShB').value) || 4,
              color: document.getElementById('propTextShC').value
            };
          } else {
            it.style.shadow = null;
          }
        });
      });

      const updateShadowVal = (key, val) => {
        updateStyle(it => {
          if (!it.style.shadow) it.style.shadow = { x: 2, y: 2, blur: 4, color: 'rgba(0,0,0,0.5)' };
          it.style.shadow[key] = val;
        });
      };

      document.getElementById('propTextShX').addEventListener('input', (e) => updateShadowVal('x', parseFloat(e.target.value) || 0));
      document.getElementById('propTextShY').addEventListener('input', (e) => updateShadowVal('y', parseFloat(e.target.value) || 0));
      document.getElementById('propTextShB').addEventListener('input', (e) => {
        const val = parseInt(e.target.value);
        document.getElementById('propTextShBVal').innerText = val + 'px';
        updateShadowVal('blur', val);
      });
      document.getElementById('propTextShC').addEventListener('input', (e) => updateShadowVal('color', e.target.value));

      // Background toggle and parameters
      const bgEnabled = document.getElementById('propTextBgEnabled');
      const bgSettings = document.getElementById('bgSettings');
      bgEnabled.addEventListener('change', (e) => {
        bgSettings.style.display = e.target.checked ? 'block' : 'none';
        updateStyle(it => {
          if (!it.style.background) it.style.background = { enabled: false, color: '#000000', opacity: 60, padding: 8 };
          it.style.background.enabled = e.target.checked;
        });
      });

      document.getElementById('propTextBgC').addEventListener('input', (e) => {
        updateStyle(it => {
          if (!it.style.background) it.style.background = { enabled: true, color: '#000000', opacity: 60, padding: 8 };
          it.style.background.color = e.target.value;
        });
      });

      document.getElementById('propTextBgPad').addEventListener('input', (e) => {
        updateStyle(it => {
          if (!it.style.background) it.style.background = { enabled: true, color: '#000000', opacity: 60, padding: 8 };
          it.style.background.padding = parseInt(e.target.value) || 0;
        });
      });

      document.getElementById('propTextBgOp').addEventListener('input', (e) => {
        const val = parseInt(e.target.value);
        document.getElementById('propTextBgOpVal').innerText = val + '%';
        updateStyle(it => {
          if (!it.style.background) it.style.background = { enabled: true, color: '#000000', opacity: 60, padding: 8 };
          it.style.background.opacity = val;
        });
      });

      document.getElementById('propTextStyle').addEventListener('change', (e) => {
        updateStyle(it => { it.style.fontStyle = e.target.value; });
      });

      document.getElementById('propTextBlur').addEventListener('input', (e) => {
        const val = parseInt(e.target.value);
        document.getElementById('propTextBlurVal').innerText = val + 'px';
        updateStyle(it => { it.style.blur = val; });
      });

      document.getElementById('propTextOpacity').addEventListener('input', (e) => {
        const val = parseInt(e.target.value) / 100;
        document.getElementById('propTextOpacityVal').innerText = Math.round(val * 100) + '%';
        const st = store.getState();
        const activeItem = st.items.find(i => i.id === item.id);
        if (activeItem) {
          activeItem.opacity = val;
          commitState(st, false);
        }
      });

      // Glow Toggle & parameters
      const glowEnabled = document.getElementById('propTextGlowEnabled');
      const glowSettings = document.getElementById('glowSettings');
      glowEnabled.addEventListener('change', (e) => {
        glowSettings.style.display = e.target.checked ? 'block' : 'none';
        updateStyle(it => {
          if (!it.style.glow) it.style.glow = { enabled: false, color: '#00ffff', radius: 8 };
          it.style.glow.enabled = e.target.checked;
        });
      });

      document.getElementById('propTextGlowC').addEventListener('input', (e) => {
        updateStyle(it => {
          if (!it.style.glow) it.style.glow = { enabled: true, color: '#00ffff', radius: 8 };
          it.style.glow.color = e.target.value;
        });
      });

      document.getElementById('propTextGlowR').addEventListener('input', (e) => {
        const val = parseInt(e.target.value) || 8;
        updateStyle(it => {
          if (!it.style.glow) it.style.glow = { enabled: true, color: '#00ffff', radius: 8 };
          it.style.glow.radius = val;
        });
      });

      // Style Presets
      document.getElementById('propTextPreset').addEventListener('change', (e) => {
        const preset = e.target.value;
        if (!preset) return;

        updateStyle(it => {
          if (!it.style) it.style = {};
          if (preset === 'cyberpunk') {
            it.style.fontFamily = 'Outfit';
            it.style.fontSize = 36;
            it.style.color = '#ff007f';
            it.style.glow = { enabled: true, color: '#ff007f', radius: 10 };
            it.style.stroke = { width: 1, color: '#00ffff' };
            it.style.background = { enabled: false };
          } else if (preset === 'retro') {
            it.style.fontFamily = 'Arial';
            it.style.fontSize = 32;
            it.style.color = '#facc15';
            it.style.stroke = { width: 3, color: '#000000' };
            it.style.shadow = { x: 3, y: 3, blur: 0, color: '#f97316' };
            it.style.glow = { enabled: false };
            it.style.background = { enabled: false };
          } else if (preset === 'minimalist') {
            it.style.fontFamily = 'Inter';
            it.style.fontSize = 24;
            it.style.color = '#ffffff';
            it.style.shadow = { x: 1, y: 1, blur: 2, color: 'rgba(0,0,0,0.4)' };
            it.style.stroke = { width: 0, color: '#000000' };
            it.style.glow = { enabled: false };
            it.style.background = { enabled: false };
          } else if (preset === 'gold') {
            it.style.fontFamily = 'Space Grotesk';
            it.style.fontSize = 40;
            it.style.color = '#d97706';
            it.style.stroke = { width: 2, color: '#fef08a' };
            it.style.shadow = { x: 4, y: 4, blur: 4, color: 'rgba(0,0,0,0.8)' };
            it.style.glow = { enabled: false };
            it.style.background = { enabled: false };
          }
        });
        window.CapCutEditor.renderInspector();
      });

      // Layout Templates
      document.getElementById('propTextTemplate').addEventListener('change', (e) => {
        const template = e.target.value;
        if (!template) return;

        const st = store.getState();
        const activeItem = st.items.find(i => i.id === item.id);
        if (activeItem) {
          if (!activeItem.style) activeItem.style = {};
          if (!activeItem.position) activeItem.position = { x: 50, y: 50 };
          
          if (template === 'subtitle') {
            activeItem.position = { x: 50, y: 85 };
            activeItem.style.fontSize = 22;
            activeItem.style.textAlign = 'center';
            activeItem.style.background = { enabled: true, color: '#000000', opacity: 60, padding: 6 };
          } else if (template === 'lowerthird') {
            activeItem.position = { x: 20, y: 75 };
            activeItem.style.fontSize = 24;
            activeItem.style.textAlign = 'left';
            activeItem.style.background = { enabled: true, color: '#10b981', opacity: 90, padding: 8 };
          } else if (template === 'fullscreen') {
            activeItem.position = { x: 50, y: 50 };
            activeItem.style.fontSize = 44;
            activeItem.style.textAlign = 'center';
            activeItem.style.background = { enabled: false };
          }
          commitState(st, true);
          window.CapCutEditor.renderInspector();
        }
      });

      // Animations Listeners
      document.getElementById('propTextAnimIn').addEventListener('change', (e) => {
        const st = store.getState();
        const activeItem = st.items.find(i => i.id === item.id);
        if (activeItem) {
          if (!activeItem.animations) activeItem.animations = {};
          activeItem.animations.in = e.target.value || null;
          commitState(st, true);
        }
      });

      document.getElementById('propTextAnimOut').addEventListener('change', (e) => {
        const st = store.getState();
        const activeItem = st.items.find(i => i.id === item.id);
        if (activeItem) {
          if (!activeItem.animations) activeItem.animations = {};
          activeItem.animations.out = e.target.value || null;
          commitState(st, true);
        }
      });

      document.getElementById('propTextAnimCombo').addEventListener('change', (e) => {
        const st = store.getState();
        const activeItem = st.items.find(i => i.id === item.id);
        if (activeItem) {
          if (!activeItem.animations) activeItem.animations = {};
          activeItem.animations.combo = e.target.value || null;
          commitState(st, true);
        }
      });
    }
    
    else if (item.type === 'audio') {
      elements.inspectorTitle.innerText = 'Audio Properties';
      
      const volumeVal = item.volume !== undefined ? item.volume : 100;
      const fadeIn = item.fadeIn ?? 0;
      const fadeOut = item.fadeOut ?? 0;
      const isMuted = item.muted ?? false;

      elements.inspectorContent.innerHTML = `
        <div class="capcut-control-group">
          <label class="capcut-control-label">Audio Volume</label>
          <input type="range" class="form-range" id="propAudVolume" min="0" max="100" value="${volumeVal}">
          <span class="small text-secondary" id="propAudVolumeVal">${volumeVal}%</span>
        </div>

        <div class="capcut-control-group mt-3">
          <label class="capcut-control-label">Fade In Duration (s)</label>
          <input type="range" class="form-range" id="propAudFadeIn" min="0" max="100" value="${Math.round(fadeIn * 10)}">
          <span class="small text-secondary" id="propAudFadeInVal">${fadeIn.toFixed(1)}s</span>
        </div>

        <div class="capcut-control-group mt-3">
          <label class="capcut-control-label">Fade Out Duration (s)</label>
          <input type="range" class="form-range" id="propAudFadeOut" min="0" max="100" value="${Math.round(fadeOut * 10)}">
          <span class="small text-secondary" id="propAudFadeOutVal">${fadeOut.toFixed(1)}s</span>
        </div>

        <div class="form-check form-switch mt-3">
          <input class="form-check-input" type="checkbox" id="propAudMute" ${isMuted ? 'checked' : ''}>
          <label class="form-check-label capcut-control-label" for="propAudMute">Mute Audio Clip</label>
        </div>

        <!-- Speed Adjustment -->
        <div class="border-top pt-3 mt-3">
          <div class="capcut-control-label mb-2">Speed Adjustment</div>
          <div class="row g-1 mb-2">
            <div class="col-4">
              <button class="btn btn-cyber btn-sm w-100 btn-speed-preset" data-speed="0.5">0.5x Slow</button>
            </div>
            <div class="col-4">
              <button class="btn btn-cyber btn-sm w-100 btn-speed-preset" data-speed="1.0">1.0x Norm</button>
            </div>
            <div class="col-4">
              <button class="btn btn-cyber btn-sm w-100 btn-speed-preset" data-speed="2.0">2.0x Fast</button>
            </div>
          </div>
          <div class="capcut-control-group mt-2">
            <label class="capcut-control-label">Custom Speed</label>
            <input type="range" class="form-range" id="propSpeed" min="10" max="1000" step="10" value="${Math.round((item.speed ?? 1.0) * 100)}">
            <span class="small text-secondary" id="propSpeedVal">${(item.speed ?? 1.0).toFixed(2)}x</span>
          </div>
        </div>

        <!-- Volume Keyframes -->
        <div class="border-top pt-3 mt-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="capcut-control-label">Volume Keyframes</div>
            <button class="btn btn-cyber btn-sm" id="btnAddVolumeKf" style="font-size: 11px; padding: 2px 8px;">
              <i class="fa-solid fa-plus-circle"></i> Add Kf
            </button>
          </div>
          <div id="volumeKfsList" class="small text-secondary mt-1" style="max-height: 120px; overflow-y: auto;">
            <!-- Render dynamically -->
          </div>
        </div>
      `;

      const updateAud = (updater, pushUndo = false) => {
        updateNleItemProperty(item, updater, pushUndo);
      };

      document.getElementById('propAudVolume').addEventListener('input', (e) => {
        const val = parseInt(e.target.value);
        document.getElementById('propAudVolumeVal').innerText = val + '%';
        updateAud(it => { it.volume = val; });
      });

      document.getElementById('propAudFadeIn').addEventListener('input', (e) => {
        const val = parseInt(e.target.value) / 10;
        document.getElementById('propAudFadeInVal').innerText = val.toFixed(1) + 's';
        updateAud(it => { it.fadeIn = val; });
      });

      document.getElementById('propAudFadeOut').addEventListener('input', (e) => {
        const val = parseInt(e.target.value) / 10;
        document.getElementById('propAudFadeOutVal').innerText = val.toFixed(1) + 's';
        updateAud(it => { it.fadeOut = val; });
      });

      document.getElementById('propAudMute').addEventListener('change', (e) => {
        updateAud(it => { it.muted = e.target.checked; });
      });

      // Speed listeners
      document.querySelectorAll('.btn-speed-preset').forEach(btn => {
        btn.addEventListener('click', (e) => {
          const speed = parseFloat(e.currentTarget.dataset.speed);
          document.getElementById('propSpeed').value = Math.round(speed * 100);
          document.getElementById('propSpeedVal').innerText = speed.toFixed(2) + 'x';
          updateAud(it => { it.speed = speed; }, true);
        });
      });

      document.getElementById('propSpeed').addEventListener('input', (e) => {
        const speed = parseInt(e.target.value) / 100;
        document.getElementById('propSpeedVal').innerText = speed.toFixed(2) + 'x';
        updateAud(it => { it.speed = speed; });
      });

      // Volume Keyframes List Rendering and Event Bindings
      const renderVolumeKfsList = () => {
        const listEl = document.getElementById('volumeKfsList');
        if (!listEl) return;

        const currentKfs = item.volumeKeyframes || [];
        if (currentKfs.length === 0) {
          listEl.innerHTML = '<span class="text-muted italic">No keyframes added</span>';
          return;
        }

        // Sort keyframes by time
        const sorted = [...currentKfs].sort((a, b) => a.time - b.time);
        listEl.innerHTML = sorted.map((kf, i) => `
          <div class="d-flex align-items-center justify-content-between p-1 mb-1 border-bottom" style="border-color: var(--capcut-border) !important;">
            <span>${kf.time.toFixed(2)}s: <strong>${kf.volume}%</strong></span>
            <div class="d-flex align-items-center">
              <input type="range" class="form-range d-inline-block mx-2" style="width: 80px;" 
                value="${kf.volume}" min="0" max="100" data-idx="${i}">
              <button class="btn btn-sm text-danger btn-delete-kf" data-idx="${i}" style="padding: 0 4px; border:none; background:none;">
                <i class="fa-solid fa-trash-can"></i>
              </button>
            </div>
          </div>
        `).join('');

        // Range input update listeners
        listEl.querySelectorAll('input[type="range"]').forEach(input => {
          input.addEventListener('input', (e) => {
            const idx = parseInt(e.target.dataset.idx);
            const val = parseInt(e.target.value);
            updateAud(it => {
              const kfs = [...(it.volumeKeyframes || [])].sort((a, b) => a.time - b.time);
              if (kfs[idx]) {
                kfs[idx].volume = val;
              }
              it.volumeKeyframes = kfs;
            });
            e.target.closest('div').previousElementSibling.querySelector('strong').innerText = val + '%';
          });
        });

        // Keyframe deletion listeners
        listEl.querySelectorAll('.btn-delete-kf').forEach(btn => {
          btn.addEventListener('click', (e) => {
            const idx = parseInt(e.currentTarget.dataset.idx);
            updateAud(it => {
              const kfs = [...(it.volumeKeyframes || [])].sort((a, b) => a.time - b.time);
              kfs.splice(idx, 1);
              it.volumeKeyframes = kfs;
            }, true);
            renderVolumeKfsList();
          });
        });
      };

      // Add new keyframe listener
      document.getElementById('btnAddVolumeKf').addEventListener('click', () => {
        const playheadTime = window.PreviewEngine?.currentTime ?? 0;
        const relTime = Math.max(0, playheadTime - item.start);
        updateAud(it => {
          if (!it.volumeKeyframes) it.volumeKeyframes = [];
          if (!it.volumeKeyframes.some(kf => Math.abs(kf.time - relTime) < 0.05)) {
            it.volumeKeyframes.push({ time: relTime, volume: 100 });
          }
        }, true);
        renderVolumeKfsList();
      });

      renderVolumeKfsList();
    }

    else if (item.type === 'sticker') {
      elements.inspectorTitle.innerText = 'Element Properties';
      
      const px = item.position?.x ?? 50;
      const py = item.position?.y ?? 50;
      const sx = item.scale?.x ?? 1.0;
      const sy = item.scale?.y ?? 1.0;
      const r = item.rotation ?? 0;
      const op = item.opacity !== undefined ? item.opacity : 1.0;
      const style = item.style || {};

      elements.inspectorContent.innerHTML = `
        <!-- Position & Transform -->
        <div class="border-bottom pb-3 mb-3">
          <div class="capcut-control-label mb-2">Transform</div>
          <div class="row g-2">
            <div class="col-6">
              <label class="small text-secondary">Position X (%)</label>
              <input type="number" class="form-control form-control-cyber form-control-sm" id="propElPosX" value="${Math.round(px)}">
            </div>
            <div class="col-6">
              <label class="small text-secondary">Position Y (%)</label>
              <input type="number" class="form-control form-control-cyber form-control-sm" id="propElPosY" value="${Math.round(py)}">
            </div>
          </div>
          <div class="row g-2 mt-2">
            <div class="col-6">
              <label class="small text-secondary">Scale X (%)</label>
              <input type="number" class="form-control form-control-cyber form-control-sm" id="propElScaleX" value="${Math.round(sx * 100)}">
            </div>
            <div class="col-6">
              <label class="small text-secondary">Scale Y (%)</label>
              <input type="number" class="form-control form-control-cyber form-control-sm" id="propElScaleY" value="${Math.round(sy * 100)}">
            </div>
          </div>
          <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" id="propElScaleLink" checked>
            <label class="form-check-label small text-secondary" for="propElScaleLink">Link Aspect Ratio</label>
          </div>
          <div class="capcut-control-group mt-3">
            <label class="capcut-control-label">Rotation (Deg)</label>
            <input type="range" class="form-range" id="propElRot" min="-180" max="180" value="${r}">
            <span class="small text-secondary" id="propElRotVal">${r}°</span>
          </div>
          <div class="capcut-control-group mt-2">
            <label class="capcut-control-label">Opacity</label>
            <input type="range" class="form-range" id="propElOp" min="0" max="100" value="${Math.round(op * 100)}">
            <span class="small text-secondary" id="propElOpVal">${Math.round(op * 100)}%</span>
          </div>
        </div>

        <!-- Shape / Emoji Style Settings -->
        ${item.elementType === 'shape' ? `
        <div class="border-bottom pb-3 mb-3">
          <div class="capcut-control-label mb-2">Vector Shape Settings</div>
          <div class="row g-2">
            <div class="col-6">
              <label class="small text-secondary">Fill Color</label>
              <input type="color" id="propShapeFill" class="form-control form-control-cyber form-control-sm" style="height:34px;padding:3px;" value="${style.color || '#ec4899'}">
            </div>
            <div class="col-6">
              <label class="small text-secondary">Stroke Color</label>
              <input type="color" id="propShapeStroke" class="form-control form-control-cyber form-control-sm" style="height:34px;padding:3px;" value="${style.strokeColor || '#ffffff'}">
            </div>
          </div>
          <div class="capcut-control-group mt-2">
            <label class="small text-secondary">Stroke Width</label>
            <input type="range" class="form-range" id="propShapeStrokeW" min="0" max="15" value="${style.strokeWidth ?? 2}">
            <span class="small text-secondary" id="propShapeStrokeWVal">${style.strokeWidth ?? 2}px</span>
          </div>
        </div>
        ` : ''}

        ${item.elementType === 'emoji' ? `
        <div class="border-bottom pb-3 mb-3">
          <div class="capcut-control-label mb-2">Emoji Settings</div>
          <label class="small text-secondary">Unicode Character</label>
          <input type="text" id="propEmojiChar" class="form-control form-control-cyber form-control-sm" value="${style.emoji || item.emoji || '🔥'}">
        </div>
        ` : ''}

        <!-- Animations -->
        <div class="border-bottom pb-3 mb-3">
          <div class="capcut-control-label mb-2">Animations</div>
          <div class="capcut-control-group">
            <label class="small text-secondary">Animation In</label>
            <select class="form-select form-control-cyber form-select-sm" id="propElAnimIn">
              <option value="" ${!item.animations?.in ? 'selected' : ''}>None</option>
              <option value="fade" ${item.animations?.in === 'fade' ? 'selected' : ''}>Fade In</option>
              <option value="zoom" ${item.animations?.in === 'zoom' ? 'selected' : ''}>Zoom In</option>
              <option value="slide" ${item.animations?.in === 'slide' ? 'selected' : ''}>Slide Up</option>
            </select>
          </div>
          <div class="capcut-control-group mt-2">
            <label class="small text-secondary">Animation Out</label>
            <select class="form-select form-control-cyber form-select-sm" id="propElAnimOut">
              <option value="" ${!item.animations?.out ? 'selected' : ''}>None</option>
              <option value="fade" ${item.animations?.out === 'fade' ? 'selected' : ''}>Fade Out</option>
              <option value="zoom" ${item.animations?.out === 'zoom' ? 'selected' : ''}>Zoom Out</option>
            </select>
          </div>
          <div class="capcut-control-group mt-2">
            <label class="small text-secondary">Combo Animation</label>
            <select class="form-select form-control-cyber form-select-sm" id="propElAnimCombo">
              <option value="" ${!item.animations?.combo ? 'selected' : ''}>None</option>
              <option value="bounce" ${item.animations?.combo === 'bounce' ? 'selected' : ''}>Bounce Jump</option>
              <option value="pulse" ${item.animations?.combo === 'pulse' ? 'selected' : ''}>Pulsing Glow</option>
              <option value="spin" ${item.animations?.combo === 'spin' ? 'selected' : ''}>Spin Loop</option>
            </select>
          </div>
        </div>

        <!-- Keyframes -->
        <div class="pb-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="capcut-control-label">Element Keyframes</div>
            <button class="btn btn-cyber btn-sm" id="btnAddElKf" style="font-size: 11px; padding: 2px 8px;">
              <i class="fa-solid fa-plus-circle"></i> Add Kf
            </button>
          </div>
          <div id="elKeyframesList" class="small text-secondary mt-1" style="max-height: 120px; overflow-y: auto;">
            <!-- Render dynamically -->
          </div>
        </div>
      `;

      const updateEl = (updater, pushUndo = false) => {
        updateNleItemProperty(item, updater, pushUndo);
      };

      // Listeners
      document.getElementById('propElPosX').addEventListener('input', (e) => updateEl(it => { if (!it.position) it.position = {}; it.position.x = parseFloat(e.target.value) || 0; }));
      document.getElementById('propElPosY').addEventListener('input', (e) => updateEl(it => { if (!it.position) it.position = {}; it.position.y = parseFloat(e.target.value) || 0; }));
      
      const scX = document.getElementById('propElScaleX');
      const scY = document.getElementById('propElScaleY');
      const scLink = document.getElementById('propElScaleLink');

      scX.addEventListener('input', (e) => {
        const val = (parseFloat(e.target.value) || 100) / 100;
        updateEl(it => {
          if (!it.scale) it.scale = { x: 1, y: 1 };
          it.scale.x = val;
          if (scLink.checked) {
            it.scale.y = val;
            scY.value = Math.round(val * 100);
          }
        });
      });

      scY.addEventListener('input', (e) => {
        const val = (parseFloat(e.target.value) || 100) / 100;
        updateEl(it => {
          if (!it.scale) it.scale = { x: 1, y: 1 };
          it.scale.y = val;
          if (scLink.checked) {
            it.scale.x = val;
            scX.value = Math.round(val * 100);
          }
        });
      });

      document.getElementById('propElRot').addEventListener('input', (e) => {
        const val = parseInt(e.target.value);
        document.getElementById('propElRotVal').innerText = val + '°';
        updateEl(it => { it.rotation = val; });
      });

      document.getElementById('propElOp').addEventListener('input', (e) => {
        const val = parseInt(e.target.value) / 100;
        document.getElementById('propElOpVal').innerText = Math.round(val * 100) + '%';
        updateEl(it => { it.opacity = val; });
      });

      if (item.elementType === 'shape') {
        document.getElementById('propShapeFill').addEventListener('input', (e) => {
          updateEl(it => { if (!it.style) it.style = {}; it.style.color = e.target.value; });
        });
        document.getElementById('propShapeStroke').addEventListener('input', (e) => {
          updateEl(it => { if (!it.style) it.style = {}; it.style.strokeColor = e.target.value; });
        });
        document.getElementById('propShapeStrokeW').addEventListener('input', (e) => {
          const val = parseInt(e.target.value);
          document.getElementById('propShapeStrokeWVal').innerText = val + 'px';
          updateEl(it => { if (!it.style) it.style = {}; it.style.strokeWidth = val; });
        });
      }

      if (item.elementType === 'emoji') {
        document.getElementById('propEmojiChar').addEventListener('input', (e) => {
          updateEl(it => { if (!it.style) it.style = {}; it.style.emoji = e.target.value; });
        });
      }

      document.getElementById('propElAnimIn').addEventListener('change', (e) => {
        updateEl(it => { if (!it.animations) it.animations = {}; it.animations.in = e.target.value || null; }, true);
      });
      document.getElementById('propElAnimOut').addEventListener('change', (e) => {
        updateEl(it => { if (!it.animations) it.animations = {}; it.animations.out = e.target.value || null; }, true);
      });
      document.getElementById('propElAnimCombo').addEventListener('change', (e) => {
        updateEl(it => { 
          if (!it.animations) it.animations = {}; 
          it.animations.combo = e.target.value || null; 
          if (!it.style) it.style = {};
          it.style.animationClass = e.target.value || '';
        }, true);
      });

      // Keyframes
      const renderElKfsList = () => {
        const kfsList = document.getElementById('elKeyframesList');
        if (!kfsList) return;
        const currentKfs = item.keyframes || [];
        if (currentKfs.length === 0) {
          kfsList.innerHTML = '<div class="text-center py-2 text-muted italic">No keyframes added yet</div>';
          return;
        }

        const sortedKfs = [...currentKfs].sort((a, b) => a.time - b.time);
        kfsList.innerHTML = sortedKfs.map((kf, index) => `
          <div class="d-flex justify-content-between align-items-center p-1 border-bottom border-secondary mb-1">
            <span style="font-size:10px; cursor:pointer;" class="btn-seek-kf" data-time="${kf.time}">
              <i class="fa-solid fa-clock-rotate-left me-1"></i> ${kf.time.toFixed(2)}s
            </span>
            <button class="btn btn-sm btn-link text-danger p-0 btn-del-el-kf" data-time="${kf.time}" style="text-decoration:none;">
              <i class="fa-solid fa-trash-can"></i>
            </button>
          </div>
        `).join('');

        kfsList.querySelectorAll('.btn-seek-kf').forEach(btn => {
          btn.addEventListener('click', () => {
            const time = parseFloat(btn.dataset.time);
            if (window.PreviewEngine) {
              window.PreviewEngine.seek(item.start + time);
            }
          });
        });

        kfsList.querySelectorAll('.btn-del-el-kf').forEach(btn => {
          btn.addEventListener('click', () => {
            const time = parseFloat(btn.dataset.time);
            updateEl(it => {
              it.keyframes = (it.keyframes || []).filter(kf => Math.abs(kf.time - time) > 0.01);
            }, true);
            renderElKfsList();
          });
        });
      };

      document.getElementById('btnAddElKf').addEventListener('click', () => {
        const playheadTime = window.PreviewEngine?.currentTime ?? 0;
        const relTime = Math.max(0, playheadTime - item.start);
        updateEl(it => {
          if (!it.keyframes) it.keyframes = [];
          it.keyframes = it.keyframes.filter(kf => Math.abs(kf.time - relTime) > 0.05);
          it.keyframes.push({
            time: relTime,
            position: { x: it.position?.x ?? 50, y: it.position?.y ?? 50 },
            scale: { x: it.scale?.x ?? 1.0, y: it.scale?.y ?? 1.0 },
            rotation: it.rotation ?? 0,
            opacity: it.opacity ?? 1.0
          });
        }, true);
        renderElKfsList();
      });

      renderElKfsList();
    }

    else if (item.type === 'effect' || item.type === 'filter' || item.type === 'overlay') {
      elements.inspectorTitle.innerText = 'Effect & Overlay';
      
      const intensity = item.intensity !== undefined ? item.intensity : 100;
      const duration = item.duration ?? 5.0;
      const speed = item.parameters?.speed ?? 50;

      elements.inspectorContent.innerHTML = `
        <div class="capcut-control-group">
          <label class="capcut-control-label">Effect Intensity</label>
          <input type="range" class="form-range" id="propEffIntensity" min="0" max="100" value="${intensity}">
          <span class="small text-secondary" id="propEffIntensityVal">${intensity}%</span>
        </div>

        <div class="capcut-control-group mt-3">
          <label class="capcut-control-label">Duration (s)</label>
          <input type="number" step="0.1" class="form-control form-control-cyber form-control-sm" id="propEffDuration" value="${duration}">
        </div>

        <div class="capcut-control-group mt-3">
          <label class="capcut-control-label">Effect Speed / Frequency</label>
          <input type="range" class="form-range" id="propEffSpeed" min="0" max="100" value="${speed}">
          <span class="small text-secondary" id="propEffSpeedVal">${speed}%</span>
        </div>
      `;

      const updateEff = (updater, pushUndo = false) => {
        updateNleItemProperty(item, updater, pushUndo);
      };

      document.getElementById('propEffIntensity').addEventListener('input', (e) => {
        const val = parseInt(e.target.value);
        document.getElementById('propEffIntensityVal').innerText = val + '%';
        updateEff(it => { it.intensity = val; });
      });

      document.getElementById('propEffDuration').addEventListener('input', (e) => {
        const val = Math.max(0.1, parseFloat(e.target.value) || 1.0);
        updateEff(it => { it.duration = val; });
      });

      document.getElementById('propEffSpeed').addEventListener('input', (e) => {
        const val = parseInt(e.target.value);
        document.getElementById('propEffSpeedVal').innerText = val + '%';
        updateEff(it => {
          if (!it.parameters) it.parameters = {};
          it.parameters.speed = val;
        });
      });
    }

    // Render the unified Keyframe Engine UI at the bottom of the inspector content
    renderInspectorKeyframesSection(item);

    // Bind change/mouseup events to push a clean history checkpoint to the undo/redo stack
    elements.inspectorContent.querySelectorAll('input, select, textarea').forEach(input => {
      input.addEventListener('change', () => {
        const st = store.getState();
        commitState(st, true); // Push to undo stack
      });
    });
  }

  // ── Media File Import Setup ──
  function handleMediaFileImport() {
    if (!elements.fileInput) return;
    
    elements.fileInput.addEventListener('change', function () {
      const file = this.files[0];
      if (!file) return;
      
      const objectUrl = URL.createObjectURL(file);
      state.videoUrl = objectUrl;

      // Sync to StudioProjectStore
      const store = window.StudioProjectStore;
      if (store) {
        const newState = JSON.parse(JSON.stringify(store.getState()));
        let mainClip = newState.items.find(i => i.id === 'clip_vid_1001' || i.type === 'video');
        if (mainClip) {
          mainClip.url = objectUrl;
          mainClip.name = file.name;
        } else {
          mainClip = store.createTimelineItem({
            type: 'video',
            trackId: 'track_video_main',
            url: objectUrl,
            name: file.name,
            start: 0,
            duration: 15
          });
          newState.items.push(mainClip);
        }
        store.setState(newState);
      }
      
      // Reset existing file when a new local file is picked
      const existingInput = document.getElementById('capcutExistingFile');
      if (existingInput) existingInput.value = '';

      if (elements.video) {
        elements.video.src = objectUrl;
        elements.video.load();
        
        elements.video.onloadedmetadata = function () {
          const duration = elements.video.duration;
          state.duration = Math.ceil(duration);
          state.videoClip.trimEnd = duration;
          state.videoClip.sourceDuration = duration;
          
          state.currentTime = 0;
          
          renderTimelineRuler();
          renderTimelineClips();
        };
      }
    });
  }

  window.importVideoFromLibrary = function(videoUrl, title) {
    if (!videoUrl) return;
    state.videoUrl = videoUrl;

    // Sync to StudioProjectStore
    const store = window.StudioProjectStore;
    if (store) {
      const newState = JSON.parse(JSON.stringify(store.getState()));
      let mainClip = newState.items.find(i => i.id === 'clip_vid_1001' || i.type === 'video');
      if (mainClip) {
        mainClip.url = videoUrl;
        mainClip.name = title || 'Imported Video';
      } else {
        mainClip = store.createTimelineItem({
          type: 'video',
          trackId: 'track_video_main',
          url: videoUrl,
          name: title || 'Imported Video',
          start: 0,
          duration: 15
        });
        newState.items.push(mainClip);
      }
      store.setState(newState);
    }
    
    let existingInput = document.getElementById('capcutExistingFile');
    if (!existingInput) {
      existingInput = document.createElement('input');
      existingInput.type = 'hidden';
      existingInput.id = 'capcutExistingFile';
      existingInput.name = 'existing_file';
      if (elements.exportForm) {
        elements.exportForm.appendChild(existingInput);
      }
    }
    existingInput.value = videoUrl;

    if (elements.video) {
      elements.video.src = videoUrl;
      elements.video.load();
      
      elements.video.onloadedmetadata = function () {
        const duration = elements.video.duration;
        state.duration = Math.ceil(duration);
        state.videoClip.trimEnd = duration;
        state.videoClip.sourceDuration = duration;
        state.videoClip.filePath = videoUrl;
        
        state.currentTime = 0;
        
        renderTimelineRuler();
        renderTimelineClips();
        triggerAutoSave();
      };
    }
    showSplitToast('📥 Imported library video to timeline!');
  };

  window.loadMediaLibrary = function() {
    fetch('backend/get_status.php')
      .then(res => res.json())
      .then(data => {
        if (data.success && data.uploads) {
          const list = document.querySelector('#sidebar-section-media .capcut-asset-list');
          if (!list) return;
          list.innerHTML = '';
          
          const videos = data.uploads.filter(u => u.status === 'live' || u.status === 'completed' || u.status === 'success');
          if (videos.length === 0) {
            list.innerHTML = '<div class="small text-muted text-center py-3">No videos found. Upload a video using standard upload tab first!</div>';
            return;
          }
          
          videos.forEach(v => {
            if (!v.video_url) return;
            const card = document.createElement('div');
            card.className = 'capcut-asset-card';
            
            const info = document.createElement('div');
            info.className = 'capcut-asset-info';
            
            const name = document.createElement('span');
            name.className = 'capcut-asset-name';
            name.innerText = `Upload #${v.id}`;
            info.appendChild(name);
            
            const desc = document.createElement('span');
            desc.className = 'capcut-asset-desc';
            desc.innerText = `Platform Draft ready`;
            info.appendChild(desc);
            card.appendChild(info);
            
            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'capcut-asset-add';
            addBtn.innerHTML = '<i class="fa-solid fa-plus"></i>';
            addBtn.addEventListener('click', () => {
              importVideoFromLibrary(v.video_url, `Upload #${v.id}`);
            });
            card.appendChild(addBtn);
            
            list.appendChild(card);
          });
        }
      })
      .catch(err => {
        console.warn("Loading media library failed:", err);
      });
  };

  // ── Layout Aspect Ratio Manager ──
  function handleAspectRatioSelect() {
    if (!elements.aspectSelect || !elements.playerWrapper) return;
    
    elements.aspectSelect.addEventListener('change', function () {
      const ratio = this.value;
      
      elements.playerWrapper.className = 'capcut-player-wrapper';
      
      if (ratio === '16:9') {
        elements.playerWrapper.classList.add('ratio-16-9');
      } else if (ratio === '9:16') {
        elements.playerWrapper.classList.add('ratio-9-16');
      } else if (ratio === '4:5') {
        elements.playerWrapper.classList.add('ratio-4-5');
      } else if (ratio === '21:9') {
        elements.playerWrapper.classList.add('ratio-21-9');
      } else {
        elements.playerWrapper.classList.add('ratio-1-1');
      }
    });
  }

  // ── Interactive Timeline Navigation & Playhead Scrubbing ──
  function handleTimelineScrubbing() {
    let scrubbing = false;
    
    if (!elements.timelineScroll) return;
    
    elements.timelineScroll.addEventListener('pointerdown', (e) => {
      if (e.target.closest('#capcutRulerTicks')) {
        scrubbing = true;
        initAudioContext();
        updateTimeFromScrub(e);
        elements.timelineScroll.setPointerCapture(e.pointerId);
      }
    });
    
    elements.timelineScroll.addEventListener('pointermove', (e) => {
      if (scrubbing) {
        updateTimeFromScrub(e);
      }
    });
    
    elements.timelineScroll.addEventListener('pointerup', (e) => {
      if (scrubbing) {
        scrubbing = false;
        elements.timelineScroll.releasePointerCapture(e.pointerId);
      }
    });
    
    // Optimized playhead scrubbing to avoid layout thrashing
    let handleDragging = false;
    let cachedLaneRect = null;
    let cachedScrollLeft = 0;

    const playheadHandle = document.getElementById('capcutPlayheadHandle');
    if (playheadHandle) {
      playheadHandle.addEventListener('pointerdown', (e) => {
        e.stopPropagation();
        initAudioContext();
        handleDragging = true;
        playheadHandle.setPointerCapture(e.pointerId);
        // Cache layout measurements on pointerdown instead of pointermove
        cachedLaneRect = elements.tracksContainer.getBoundingClientRect();
        cachedScrollLeft = elements.timelineScroll.scrollLeft;
      });
      playheadHandle.addEventListener('pointermove', (e) => {
        if (handleDragging && cachedLaneRect) {
          updateTimeFromScrub(e, cachedLaneRect, cachedScrollLeft);
        }
      });
      playheadHandle.addEventListener('pointerup', (e) => {
        if (handleDragging) {
          handleDragging = false;
          playheadHandle.releasePointerCapture(e.pointerId);
          cachedLaneRect = null;
        }
      });
    }
  }

  function updateTimeFromScrub(e, laneRect, scrollLeft) {
    // Fallback if not using cached measurements
    const rect = laneRect || elements.tracksContainer.getBoundingClientRect();
    const scroll = scrollLeft !== undefined ? scrollLeft : elements.timelineScroll.scrollLeft;
    
    const x = e.clientX - rect.left + scroll - 70;
    let time = xToTime(x);

    if (time < 0) time = 0;
    if (time > state.duration) time = state.duration;

    state.currentTime = time;

    // ── Delegate seek to PreviewEngine (single source of truth) ──
    if (window.PreviewEngine) {
      window.PreviewEngine.seek(time);
    } else if (elements.video) {
      elements.video.currentTime = time;
    }

    updatePlayheadPosition();
    renderPlayerOverlay();
  }

  // ── Animation Loop for Playback Progress ──
  function playbackTick() {
    if (!state.isPlaying) return;
    
    if (elements.video) {
      state.currentTime = elements.video.currentTime;
      
      if (elements.video.ended || state.currentTime >= state.duration) {
        pausePlayback();
      }
    } else {
      state.currentTime += 1/60;
      if (state.currentTime >= state.duration) {
        pausePlayback();
      }
    }
    
    syncAudioClipsPlayback();
    updatePlayheadPosition();
    renderPlayerOverlay();
    
    requestAnimationFrame(playbackTick);
  }

  function startPlayback() {
    // ── Delegate to PreviewEngine (authoritative) ──
    if (window.PreviewEngine) {
      window.PreviewEngine.play();
      state.isPlaying = true;
      return;
    }
    // Fallback for environments without PreviewEngine
    state.isPlaying = true;
    if (elements.video && state.videoUrl) {
      elements.video.play().catch(() => { pausePlayback(); });
    }
    if (elements.playIcon) elements.playIcon.className = 'fa-solid fa-pause';
    syncAudioClipsPlayback();
    playbackTick();
  }

  function pausePlayback() {
    // ── Delegate to PreviewEngine (authoritative) ──
    if (window.PreviewEngine) {
      window.PreviewEngine.pause();
      state.isPlaying = false;
      return;
    }
    // Fallback
    state.isPlaying = false;
    if (elements.video) elements.video.pause();
    if (elements.playIcon) elements.playIcon.className = 'fa-solid fa-play';
    stopAllAudioClips();
  }

  // ── Asset Drawer click hooks ──
  function setupSidebarTabs() {
    const navItems = document.querySelectorAll('.capcut-nav-item');
    const sections = document.querySelectorAll('.capcut-sidebar-section');
    
    navItems.forEach(item => {
      item.addEventListener('click', () => {
        navItems.forEach(nav => nav.classList.remove('active'));
        item.classList.add('active');
        
        const tab = item.dataset.sidebarTab;
        let targetId = `sidebar-section-${tab}`;
        if (!document.getElementById(targetId)) {
          if (tab === 'templates' || tab === 'elements' || tab === 'effects') {
            targetId = 'sidebar-section-filters';
          } else if (tab === 'transitions') {
            targetId = 'sidebar-section-audio';
          } else {
            targetId = 'sidebar-section-media';
          }
        }

        sections.forEach(sec => {
          if (sec.id === targetId) {
            sec.classList.remove('d-none');
          } else {
            sec.classList.add('d-none');
          }
        });
      });
    });
  }

  function setupAssetDrawerAdders() {
    // Make entire asset cards clickable
    document.querySelectorAll('.capcut-asset-card').forEach(card => {
      card.addEventListener('click', (e) => {
        // If clicked directly on card or non-button inner text, find child action or trigger card action
        const textBtn = card.querySelector('[data-add-text]');
        const audioBtn = card.querySelector('[data-add-audio]');
        const filterBtn = card.querySelector('[data-add-filter]');
        const stickerBtn = card.querySelector('.capcut-sticker-item');

        if (e.target.closest('[data-add-text]') || textBtn) {
          const btn = e.target.closest('[data-add-text]') || textBtn;
          const textType = btn.dataset.addText;
          const cardNameEl = card.querySelector('.capcut-asset-name');
          const titleText = cardNameEl ? cardNameEl.innerText : 'Heading Title';

          let color = '#ffffff';
          let font = 'Space Grotesk';
          if (textType === 'neon') color = '#00f3ff';
          else if (textType === 'glitch') color = '#ff00ff';
          else if (textType === 'bold') color = '#fcee0a';

          const newText = {
            id: 'txt-' + Date.now(),
            text: titleText,
            start: state.currentTime,
            end: Math.min(state.duration, state.currentTime + 4),
            x: 50,
            y: 50,
            size: 26,
            color: color,
            font: font,
            anim: 'none'
          };
          
          state.textClips.push(newText);
          recalculateTimelineDuration();
          renderTimelineRuler();
          renderTimelineClips();
          selectElement(newText.id, 'text');
          triggerSynthSound('chime');
          showSplitToast('✨ Added Text Layer: "' + titleText + '"');
          if (typeof triggerAutoSave === 'function') triggerAutoSave();
        }
        else if (e.target.closest('[data-add-audio]') || audioBtn) {
          const btn = e.target.closest('[data-add-audio]') || audioBtn;
          const audType = btn.dataset.addAudio;
          const cardNameEl = card.querySelector('.capcut-asset-name');
          const name = cardNameEl ? cardNameEl.innerText : 'SFX Audio Track';

          const newAudio = {
            id: 'aud-' + Date.now(),
            name: name,
            type: audType,
            start: state.currentTime,
            duration: audType === 'beat' ? 10 : 3,
            volume: 85
          };
          
          state.audioClips.push(newAudio);
          recalculateTimelineDuration();
          renderTimelineRuler();
          renderTimelineClips();
          selectElement(newAudio.id, 'audio');
          triggerSynthSound(audType);
          showSplitToast('🎵 Added Audio Track: "' + name + '"');
          if (typeof triggerAutoSave === 'function') triggerAutoSave();
        }
        else if (e.target.closest('[data-add-filter]') || filterBtn) {
          const btn = e.target.closest('[data-add-filter]') || filterBtn;
          const filterType = btn.dataset.addFilter;
          const cardNameEl = card.querySelector('.capcut-asset-name');
          const name = cardNameEl ? cardNameEl.innerText : 'Video Filter';

          if (filterType === 'vhs') {
            state.videoClip.saturation = 80;
            state.videoClip.contrast = 120;
            state.videoClip.brightness = 110;
            state.videoClip.temperature = -15;
          } else if (filterType === 'cyan') {
            state.videoClip.saturation = 130;
            state.videoClip.contrast = 105;
            state.videoClip.brightness = 95;
            state.videoClip.temperature = 25;
          }
          applyColorGradingFilters();
          triggerSynthSound('cyber');
          showSplitToast('🎨 Applied Filter: "' + name + '"');
          if (typeof triggerAutoSave === 'function') triggerAutoSave();
        }
        else if (e.target.closest('.capcut-sticker-item') || stickerBtn) {
          const btn = e.target.closest('.capcut-sticker-item') || stickerBtn;
          const emoji = btn.innerText || '🔥';
          const newSticker = {
            id: 'stk-' + Date.now(),
            emoji: emoji,
            start: state.currentTime,
            end: Math.min(state.duration, state.currentTime + 3),
            x: 50,
            y: 50,
            size: 44
          };

          state.stickerClips.push(newSticker);
          recalculateTimelineDuration();
          renderTimelineRuler();
          renderTimelineClips();
          selectElement(newSticker.id, 'sticker');
          triggerSynthSound('chime');
          showSplitToast('⭐ Added Sticker: ' + emoji);
          if (typeof triggerAutoSave === 'function') triggerAutoSave();
        }
      });
    });
  }

  function setupKeyboardShortcuts() {
    window.addEventListener('keydown', (e) => {
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) return;
      if (!document.getElementById('capcutWorkbench') || document.getElementById('capcutWorkbench').classList.contains('d-none')) return;

      if (e.key === 's' || e.key === 'S') {
        e.preventDefault();
        const splitBtn = document.getElementById('capcutSplitBtn');
        if (splitBtn) splitBtn.click();
      } else if (e.key === 'Delete' || e.key === 'Backspace') {
        e.preventDefault();
        const deleteBtn = document.getElementById('capcutDeleteBtn');
        if (deleteBtn) deleteBtn.click();
      } else if (e.code === 'Space') {
        e.preventDefault();
        if (window.PreviewEngine) return; // Prevent double-triggering toggle()
        const playBtn = document.getElementById('capcutPlayBtn');
        if (playBtn) playBtn.click();
      }
    });
  }

  // ── Toast notification for split feedback ──
  function showSplitToast(msg) {
    let toast = document.getElementById('capcutSplitToast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'capcutSplitToast';
      toast.style.cssText = [
        'position:fixed', 'bottom:24px', 'left:50%', 'transform:translateX(-50%)',
        'background:rgba(0,243,255,0.12)', 'border:1px solid rgba(0,243,255,0.4)',
        'backdrop-filter:blur(10px)', 'color:#00f3ff', 'padding:10px 22px',
        'border-radius:8px', 'font-size:0.82rem', 'font-family:Space Grotesk,sans-serif',
        'letter-spacing:0.5px', 'z-index:9999', 'pointer-events:none',
        'transition:opacity 0.3s'
      ].join(';');
      document.body.appendChild(toast);
    }
    toast.textContent = msg;
    toast.style.opacity = '1';
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => { toast.style.opacity = '0'; }, 2500);
  }

  // ── Split, Delete & Timeline Toolbar Buttons ──
  function setupTimelineToolbar() {

    if (elements.playBtn) {
      elements.playBtn.addEventListener('click', () => {
        if (window.PreviewEngine) return; // Let studio_preview.js handle it
        if (state.isPlaying) {
          pausePlayback();
        } else {
          startPlayback();
        }
      });
    }
    
    if (elements.zoomSlider) {
      elements.zoomSlider.addEventListener('input', (e) => {
        state.zoom = parseInt(e.target.value);
        renderTimelineRuler();
        renderTimelineClips();
      });
    }
    
    if (elements.deleteBtn) {
      elements.deleteBtn.addEventListener('click', () => {
        if (!state.selectedId) return;
        
        state.textClips = state.textClips.filter(c => c.id !== state.selectedId);
        state.stickerClips = state.stickerClips.filter(c => c.id !== state.selectedId);
        state.audioClips = state.audioClips.filter(c => c.id !== state.selectedId);
        
        state.selectedId = null;
        state.selectedType = null;
        
        recalculateTimelineDuration();
        renderTimelineRuler();
        renderTimelineClips();
        renderInspector();
      });
    }
    
    if (elements.splitBtn) {
      elements.splitBtn.addEventListener('click', () => {
        const splitAt = state.currentTime;
        triggerSynthSound('cyber');

        if (state.selectedType === 'video') {
          const clip = state.videoClip;
          const clipStart   = clip.trimStart;
          const clipEnd     = clip.trimEnd;

          if (splitAt <= clipStart + 0.1 || splitAt >= clipEnd - 0.1) {
            showSplitToast('⚠️ Move playhead inside the clip to split.');
            return;
          }

          clip.trimEnd     = splitAt;

          state.selectedId   = null;
          state.selectedType = null;
        } else if (state.selectedType === 'text') {
          const idx  = state.textClips.findIndex(c => c.id === state.selectedId);
          if (idx === -1) return;
          const clip = state.textClips[idx];

          if (splitAt <= clip.start + 0.1 || splitAt >= clip.end - 0.1) {
            showSplitToast('⚠️ Move playhead inside the text clip to split.');
            return;
          }

          const origEnd = clip.end;
          clip.end      = splitAt;

          state.textClips.splice(idx + 1, 0, {
            id:    'txt-' + Date.now(),
            text:  clip.text,
            start: splitAt,
            end:   origEnd,
            x:     clip.x,
            y:     clip.y,
            size:  clip.size,
            color: clip.color,
            font:  clip.font,
            anim:  clip.anim
          });

          state.selectedId   = null;
          state.selectedType = null;
        }

        recalculateTimelineDuration();
        renderTimelineRuler();
        renderTimelineClips();
        renderInspector();
        updateSelectionUI();
        showSplitToast('✂️ Clip split at ' + splitAt.toFixed(2) + 's');
      });
    }
    
    if (elements.addTextBtn) {
      elements.addTextBtn.addEventListener('click', () => {
        const textAssetBtn = document.querySelector('.capcut-asset-add[data-add-text]');
        if (textAssetBtn) textAssetBtn.click();
      });
    }
  }

  // ── Mobile Responsive Tabs Controller ──
  function setupMobileTabs() {
    const tabs = document.querySelectorAll('.capcut-mobile-tab');
    const sidebar = document.querySelector('.capcut-sidebar');
    const inspector = document.querySelector('.capcut-inspector');
    const timeline = document.querySelector('.capcut-timeline');
    
    tabs.forEach(tab => {
      tab.addEventListener('click', () => {
        tabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        
        const target = tab.dataset.tab;
        
        sidebar.classList.add('mobile-hide');
        sidebar.classList.remove('mobile-show');
        inspector.classList.add('mobile-hide');
        inspector.classList.remove('mobile-show');
        timeline.classList.add('mobile-hide');
        timeline.classList.remove('mobile-show');
        
        if (target === 'media') {
          sidebar.classList.add('mobile-show');
          sidebar.classList.remove('mobile-hide');
        } else if (target === 'inspector') {
          inspector.classList.add('mobile-show');
          inspector.classList.remove('mobile-hide');
        } else if (target === 'timeline') {
          timeline.classList.add('mobile-show');
          timeline.classList.remove('mobile-hide');
          renderTimelineRuler();
        }
      });
    });
  }

  // ── Export Modal settings ──
  function setupExportSettings() {
    const exportBtn = document.getElementById('capcutExportBtn');
    const modal = document.querySelector('.capcut-export-modal');
    if (!exportBtn || !modal) return;

    exportBtn.addEventListener('click', () => {
      modal.style.display = 'flex';
    });

    const closeBtn = document.getElementById('capcutExportCancel');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        modal.style.display = 'none';
      });
    }

    const confirmBtn = document.getElementById('capcutExportConfirm');
    if (confirmBtn) {
      confirmBtn.addEventListener('click', () => {
        modal.style.display = 'none';
        
        // Show progress modal
        const progressModal = document.getElementById('capcutExportProgressModal');
        if (progressModal) progressModal.style.display = 'flex';
        
        document.getElementById('exportProgressStateProcessing').style.display = 'block';
        document.getElementById('exportProgressStateCompleted').style.display = 'none';
        document.getElementById('exportProgressStateFailed').style.display = 'none';
        document.getElementById('exportProgressBar').style.width = '0%';
        document.getElementById('exportProgressPercent').innerText = '0%';
        document.getElementById('exportProgressStatusText').innerText = 'Preparing Export...';

        startAsyncExport();
      });
    }
    
    // Add retry button handler
    const retryBtn = document.getElementById('exportRetryBtn');
    if (retryBtn) {
      retryBtn.addEventListener('click', () => {
        document.getElementById('exportProgressStateProcessing').style.display = 'block';
        document.getElementById('exportProgressStateCompleted').style.display = 'none';
        document.getElementById('exportProgressStateFailed').style.display = 'none';
        document.getElementById('exportProgressBar').style.width = '0%';
        document.getElementById('exportProgressPercent').innerText = '0%';
        document.getElementById('exportProgressStatusText').innerText = 'Preparing Export...';
        startAsyncExport();
      });
    }
  }

  // ── Async Export Polling Logic ──
  function startAsyncExport() {
    const storeState = StudioProjectStore.getState();
    const filename = document.getElementById('exportFilenameInput').value || 'Untitled Project';
    const resolution = document.getElementById('exportResolutionSelect').value || '1080p';
    const fps = document.getElementById('exportFpsSelect').value || '30';
    const quality = document.getElementById('exportQualitySelect').value || 'medium';
    const format = document.getElementById('exportFormatSelect').value || 'mp4';

    const payload = {
      action: 'process_video',
      filename,
      resolution,
      fps,
      quality,
      format,
      project_data: storeState,
      csrf_token: window.csrfToken || ''
    };

    fetch('backend/export_job.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
      if (data.success && data.job_id) {
        pollExportProgress(data.job_id);
      } else {
        showExportFailed(data.message || 'Failed to start export job.');
      }
    })
    .catch(err => {
      showExportFailed('Network error: ' + err.message);
    });
  }

  function pollExportProgress(jobId) {
    document.getElementById('exportProgressStatusText').innerText = 'Rendering Video...';
    const interval = setInterval(() => {
      fetch('backend/get_export_progress.php?job_id=' + encodeURIComponent(jobId))
      .then(r => r.json())
      .then(data => {
        if (data.status === 'completed') {
          clearInterval(interval);
          showExportCompleted(data.output_url, jobId);
        } else if (data.status === 'failed') {
          clearInterval(interval);
          showExportFailed(data.message || 'Render failed unexpectedly.');
        } else if (data.status === 'processing') {
          const pct = Math.floor(data.percent || 0);
          document.getElementById('exportProgressBar').style.width = pct + '%';
          document.getElementById('exportProgressPercent').innerText = pct + '%';
        }
      })
      .catch(err => {
        // tolerate occasional network drops
        console.warn('Progress poll error:', err);
      });
    }, 1500);
  }

  function showExportFailed(msg) {
    document.getElementById('exportProgressStateProcessing').style.display = 'none';
    document.getElementById('exportProgressStateCompleted').style.display = 'none';
    document.getElementById('exportProgressStateFailed').style.display = 'block';
    const errMsg = document.getElementById('exportErrorMsg');
    if (errMsg) errMsg.innerText = msg;
  }

  function showExportCompleted(url, jobId) {
    document.getElementById('exportProgressStateProcessing').style.display = 'none';
    document.getElementById('exportProgressStateFailed').style.display = 'none';
    document.getElementById('exportProgressStateCompleted').style.display = 'block';
    
    const downloadBtn = document.getElementById('exportDownloadBtn');
    if (downloadBtn) {
      downloadBtn.href = url;
    }
    
    const publishBtn = document.getElementById('capcutPublishBtn');
    if (publishBtn) {
      publishBtn.onclick = () => {
        document.getElementById('exportProgressStateCompleted').style.display = 'none';
        document.getElementById('exportProgressStatePublish').style.display = 'block';
      };
    }

    const publishFilePath = document.getElementById('publishFilePath');
    if (publishFilePath) publishFilePath.value = url;

    const publishMediaId = document.getElementById('publishMediaId');
    if (publishMediaId) publishMediaId.value = jobId || '';

    const format = document.getElementById('exportFormatSelect').value || 'mp4';
    const publishMimeType = document.getElementById('publishMimeType');
    if (publishMimeType) publishMimeType.value = format === 'mp4' ? 'video/mp4' : 'video/webm';

    const resolution = document.getElementById('exportResolutionSelect').value || '1080p';
    const publishDimensions = document.getElementById('publishDimensions');
    if (publishDimensions) publishDimensions.value = resolution;

    const storeState = StudioProjectStore.getState();
    let maxDuration = 0;
    if (storeState.items) {
      storeState.items.forEach(i => {
        const end = (i.start || 0) + (i.duration || 0);
        if (end > maxDuration) maxDuration = end;
      });
    }
    const publishDuration = document.getElementById('publishDuration');
    if (publishDuration) publishDuration.value = maxDuration;
  }

  function setupExportSync() {
    // Legacy form sync logic removed. Handled in startAsyncExport() now.
  }

  let autoSaveTimeout = null;
  let retryTimeout = null;
  let isSavingInProgress = false;
  let saveQueued = false;

  function triggerAutoSave() {
    if (state.isLoading) return;
    if (autoSaveTimeout) clearTimeout(autoSaveTimeout);
    
    const badge = document.getElementById('capcutAutoSaveBadge');
    if (badge) {
      badge.innerHTML = '<i class="fa-solid fa-pen-fancy me-1 text-warning"></i> Unsaved changes';
    }

    autoSaveTimeout = setTimeout(() => {
      saveCapCutDraft();
    }, 1500);
  }

  function saveCapCutDraft() {
    if (state.isLoading) return;
    if (isSavingInProgress) {
      saveQueued = true;
      return;
    }

    try {
      const projectId = parseInt(document.getElementById('capcutProjectId').value) || 0;
      const projectName = document.getElementById('capcutProjectTitleInput').value || 'Untitled Project';

      const badge = document.getElementById('capcutAutoSaveBadge');
      if (badge) {
        badge.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1 text-warning"></i> Saving...';
      }

      isSavingInProgress = true;
      if (retryTimeout) clearTimeout(retryTimeout);

      const draftPayload = {
        projectId: projectId,
        timestamp: new Date().toISOString(),
        videoClip: state.videoClip,
        textClips: state.textClips,
        stickerClips: state.stickerClips,
        audioClips: state.audioClips,
        zoom: state.zoom,
        aspectRatio: elements.aspectSelect ? elements.aspectSelect.value : '16:9'
      };

      const fullStoreState = StudioProjectStore.getState() || {};
      if (!fullStoreState.projectSettings) {
        fullStoreState.projectSettings = {};
      }
      fullStoreState.projectSettings.name = projectName;
      fullStoreState.projectSettings.aspectRatio = draftPayload.aspectRatio;

      draftPayload.fullStoreState = fullStoreState;

      // Save local backup immediately before network request
      localStorage.setItem('mediafusion_capcut_draft_pending_' + projectId, JSON.stringify(draftPayload));
      localStorage.setItem('mediafusion_capcut_draft', JSON.stringify(draftPayload)); // legacy backup

      const formData = new FormData();
      formData.append('action', 'save_project');
      formData.append('project_id', projectId);
      formData.append('name', projectName);
      formData.append('aspect_ratio', draftPayload.aspectRatio);
      formData.append('width', fullStoreState.projectSettings.width || 1920);
      formData.append('height', fullStoreState.projectSettings.height || 1080);
      formData.append('fps', fullStoreState.projectSettings.fps || 30);
      formData.append('background', fullStoreState.projectSettings.background || '#000000');
      const exportSettings = (fullStoreState && fullStoreState.exportSettings) ? fullStoreState.exportSettings : {};
      formData.append('export_format', exportSettings.format || 'mp4');
      formData.append('export_resolution', exportSettings.resolution || '1080p');
      formData.append('project_data', JSON.stringify(fullStoreState));
      formData.append('timeline_json', JSON.stringify(draftPayload));
      formData.append('csrf_token', window.csrfToken || '');

      fetch('process_studio_media.php', {
        method: 'POST',
        body: formData
      })
      .then(res => {
        if (!res.ok) throw new Error(`HTTP status ${res.status}`);
        return res.json();
      })
      .then(data => {
        isSavingInProgress = false;
        if (data.success) {
          const newProjectId = data.project_id;
          document.getElementById('capcutProjectId').value = newProjectId;
          
          // Move from pending to clean local storage draft
          localStorage.removeItem('mediafusion_capcut_draft_pending_' + projectId);
          localStorage.setItem('mediafusion_capcut_draft_' + newProjectId, JSON.stringify(draftPayload));

          const badge = document.getElementById('capcutAutoSaveBadge');
          if (badge) {
            const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            badge.innerHTML = `<i class="fa-solid fa-cloud-check me-1 text-success"></i> Saved ${timeStr}`;
          }

          if (saveQueued) {
            saveQueued = false;
            saveCapCutDraft();
          }
        } else {
          throw new Error(data.message || 'Unknown save error');
        }
      })
      .catch(err => {
        isSavingInProgress = false;
        console.warn("DB save failed:", err);
        const badge = document.getElementById('capcutAutoSaveBadge');
        if (badge) {
          badge.innerHTML = '<i class="fa-solid fa-circle-exclamation me-1 text-danger"></i> Save Failed (Offline)';
        }
        // Retry in 5 seconds
        retryTimeout = setTimeout(() => {
          saveCapCutDraft();
        }, 5000);
      });
      
    } catch (e) {
      isSavingInProgress = false;
      console.warn("CapCut auto-save failed:", e);
    }
  }

  function loadCapCutDraft() {
    state.isLoading = true;
    try {
      const projectId = parseInt(document.getElementById('capcutProjectId').value) || 0;
      const pendingKey = 'mediafusion_capcut_draft_pending_' + projectId;
      const cleanKey = 'mediafusion_capcut_draft_' + projectId;
      const raw = localStorage.getItem(pendingKey) || localStorage.getItem(cleanKey) || localStorage.getItem('mediafusion_capcut_draft');
      if (!raw) return;
      const draft = JSON.parse(raw);
      if (draft && typeof draft === 'object') {
        if (draft.fullStoreState && window.StudioProjectStore) {
          window.StudioProjectStore.setState(draft.fullStoreState, false);
        }
        if (draft.videoClip) Object.assign(state.videoClip, draft.videoClip);
        if (Array.isArray(draft.textClips)) state.textClips = draft.textClips;
        if (Array.isArray(draft.stickerClips)) state.stickerClips = draft.stickerClips;
        if (Array.isArray(draft.audioClips)) state.audioClips = draft.audioClips;
        if (draft.zoom) state.zoom = draft.zoom;
        if (draft.aspectRatio && elements.aspectSelect) {
          elements.aspectSelect.value = draft.aspectRatio;
          elements.aspectSelect.dispatchEvent(new Event('change'));
        }
        
        if (elements.video) {
          elements.video.volume = state.videoClip.volume / 100;
        }
        
        renderTimelineClips();
        renderInspector();
        
        const badge = document.getElementById('capcutAutoSaveBadge');
        if (badge && draft.timestamp) {
          const t = new Date(draft.timestamp);
          const timeStr = t.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
          badge.innerHTML = `<i class="fa-solid fa-clock-rotate-left me-1 text-info"></i> Draft Restored ${timeStr}`;
        }
      }
    } catch (e) {
      console.warn("CapCut auto-restore failed:", e);
    } finally {
      state.isLoading = false;
    }
  }

  window.listProjects = function() {
    const formData = new FormData();
    formData.append('action', 'list_projects');
    formData.append('csrf_token', window.csrfToken || '');
    fetch('process_studio_media.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        populateProjectDropdown(data.projects);
      }
    });
  };

  function populateProjectDropdown(projects) {
    const dropdownList = document.getElementById('projectDropdownList');
    if (!dropdownList) return;
    dropdownList.innerHTML = '';
    
    if (projects.length === 0) {
      dropdownList.innerHTML = '<div class="dropdown-item small text-muted text-center py-2">No drafts found</div>';
      return;
    }
    
    projects.forEach(p => {
      const item = document.createElement('div');
      item.className = 'dropdown-item small d-flex justify-content-between align-items-center py-2';
      item.style.cursor = 'pointer';
      
      const titleSpan = document.createElement('span');
      titleSpan.innerText = p.name;
      titleSpan.style.flex = "1";
      titleSpan.addEventListener('click', (e) => {
        e.preventDefault();
        loadProjectDraft(p.id);
      });
      item.appendChild(titleSpan);
      
      const deleteBtn = document.createElement('button');
      deleteBtn.className = 'btn btn-link btn-sm text-danger p-0 border-0 ms-2';
      deleteBtn.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
      deleteBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        e.preventDefault();
        if (confirm(`Are you sure you want to delete "${p.name}"?`)) {
          deleteProjectDraft(p.id);
        }
      });
      item.appendChild(deleteBtn);
      
      dropdownList.appendChild(item);
    });
  }

  window.loadProjectDraft = function(projectId) {
    state.isLoading = true;
    const formData = new FormData();
    formData.append('action', 'load_project');
    formData.append('project_id', projectId);
    formData.append('csrf_token', window.csrfToken || '');
    
    fetch('process_studio_media.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.success && data.project) {
        const p = data.project;
        document.getElementById('capcutProjectId').value = p.id;
        document.getElementById('capcutProjectTitleInput').value = p.name;

        // Check if there is a local storage draft with a newer timestamp than the database version
        let localDraft = null;
        try {
          const rawPending = localStorage.getItem('mediafusion_capcut_draft_pending_' + p.id);
          const rawClean = localStorage.getItem('mediafusion_capcut_draft_' + p.id);
          const draftPending = rawPending ? JSON.parse(rawPending) : null;
          const draftClean = rawClean ? JSON.parse(rawClean) : null;

          const timePending = draftPending && draftPending.timestamp ? new Date(draftPending.timestamp).getTime() : 0;
          const timeClean = draftClean && draftClean.timestamp ? new Date(draftClean.timestamp).getTime() : 0;

          localDraft = timePending > timeClean ? draftPending : draftClean;
        } catch(e) {}

        const dbTime = new Date(p.updated_at).getTime();
        const localTime = localDraft && localDraft.timestamp ? new Date(localDraft.timestamp).getTime() : 0;

        if (localDraft && localTime > dbTime) {
          // Restore local draft
          if (localDraft.fullStoreState && window.StudioProjectStore) {
            window.StudioProjectStore.setState(localDraft.fullStoreState, false);
          }
          if (localDraft.videoClip) Object.assign(state.videoClip, localDraft.videoClip);
          if (Array.isArray(localDraft.textClips)) state.textClips = localDraft.textClips;
          if (Array.isArray(localDraft.stickerClips)) state.stickerClips = localDraft.stickerClips;
          if (Array.isArray(localDraft.audioClips)) state.audioClips = localDraft.audioClips;
          if (localDraft.zoom) state.zoom = localDraft.zoom;
          if (localDraft.aspectRatio && elements.aspectSelect) {
            elements.aspectSelect.value = localDraft.aspectRatio;
            elements.aspectSelect.dispatchEvent(new Event('change'));
          }
          
          state.isLoading = false;
          // Trigger background auto-save to sync local modifications back to DB
          triggerAutoSave();
          
          const badge = document.getElementById('capcutAutoSaveBadge');
          if (badge) {
            badge.innerHTML = '<i class="fa-solid fa-clock-rotate-left me-1 text-info"></i> Recovered Unsaved Draft';
          }
        } else {
          // Hydrate from authoritative project_data from DB
          try {
            const fullState = p.project_data ? JSON.parse(p.project_data) : null;
            if (fullState && fullState.projectSettings) {
              StudioProjectStore.setState(fullState, false);
            }
          } catch (e) {
            console.warn('StudioProjectStore hydration failed, falling back to timeline_json:', e);
          }
          
          try {
            const draft = JSON.parse(p.timeline_json || '{}');
            if (draft && typeof draft === 'object') {
              if (draft.videoClip) Object.assign(state.videoClip, draft.videoClip);
              if (Array.isArray(draft.textClips)) state.textClips = draft.textClips;
              if (Array.isArray(draft.stickerClips)) state.stickerClips = draft.stickerClips;
              if (Array.isArray(draft.audioClips)) state.audioClips = draft.audioClips;
              if (draft.zoom) state.zoom = draft.zoom;
              if (draft.aspectRatio && elements.aspectSelect) {
                elements.aspectSelect.value = draft.aspectRatio;
                elements.aspectSelect.dispatchEvent(new Event('change'));
              }
            }
          } catch (e) {
            console.warn('Legacy timeline_json parse failed:', e);
          }
          
          state.isLoading = false;
          const badge = document.getElementById('capcutAutoSaveBadge');
          if (badge) {
            badge.innerHTML = '<i class="fa-solid fa-cloud-check me-1 text-success"></i> Cloud Loaded';
          }
        }

        state.currentTime = 0;
        state.isPlaying = false;
        if (elements.video) {
          elements.video.src = state.videoUrl || '';
          elements.video.volume = state.videoClip.volume / 100;
        }
        
        renderTimelineRuler();
        renderTimelineClips();
        renderInspector();
        renderPlayerOverlay();
      }
    })
    .catch(err => {
      state.isLoading = false;
      console.warn("Load project draft failed:", err);
    });
  };

  window.deleteProjectDraft = function(projectId) {
    const formData = new FormData();
    formData.append('action', 'delete_project');
    formData.append('project_id', projectId);
    formData.append('csrf_token', window.csrfToken || '');
    fetch('process_studio_media.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        listProjects();
        if (parseInt(document.getElementById('capcutProjectId').value) === projectId) {
          createNewProject(null);
        }
      }
    });
  };

  window.createNewProject = function(e) {
    if (e) e.preventDefault();
    state.isLoading = true;
    const oldId = document.getElementById('capcutProjectId').value;
    document.getElementById('capcutProjectId').value = '0';
    document.getElementById('capcutProjectTitleInput').value = 'Untitled Project';

    // ── Reset StudioProjectStore to a clean default state ──
    const freshState = StudioProjectStore.createDefaultState();
    StudioProjectStore.setState(freshState, false);
    
    // Clear localStorage drafts
    localStorage.removeItem('mediafusion_capcut_draft');
    localStorage.removeItem('mediafusion_capcut_draft_' + oldId);
    localStorage.removeItem('mediafusion_capcut_draft_pending_' + oldId);
    localStorage.removeItem('mediafusion_capcut_draft_0');
    localStorage.removeItem('mediafusion_capcut_draft_pending_0');
    
    // ── Reset legacy state ──
    state.videoClip = {
      id: 'main-video',
      start: 0,
      trimStart: 0,
      trimEnd: 15,
      sourceDuration: 15,
      speed: 1.0,
      volume: 100,
      saturation: 100,
      contrast: 100,
      brightness: 100,
      exposure: 100,
      temperature: 0,
      tint: 0,
      shadows: 0,
      highlights: 0
    };
    state.textClips = [];
    state.stickerClips = [];
    state.audioClips = [];
    state.selectedId = null;
    state.selectedType = null;
    state.currentTime = 0;
    state.videoFile = null;
    state.videoUrl = null;
    
    if (elements.video) {
      elements.video.src = '';
      elements.video.load();
    }
    
    renderTimelineRuler();
    renderTimelineClips();
    renderInspector();
    renderPlayerOverlay();
    
    state.isLoading = false;
    const badge = document.getElementById('capcutAutoSaveBadge');
    if (badge) {
      badge.innerHTML = '<i class="fa-solid fa-file-circle-plus me-1 text-info"></i> New Project';
    }
  };

  window.toggleProjectListDropdown = function(e) {
    if (e) e.stopPropagation();
    const dropdown = document.getElementById('projectListDropdown');
    if (dropdown) {
      const isVisible = dropdown.style.display === 'block';
      dropdown.style.display = isVisible ? 'none' : 'block';
      if (!isVisible) {
        listProjects();
      }
    }
  };

  window.clearCapCutDraft = function() {
    localStorage.removeItem('mediafusion_capcut_draft');
    location.reload();
  };

  function setupWheelScrollIsolation() {
    const panels = document.querySelectorAll('#capcutWorkbench, .capcut-sidebar, .capcut-sidebar-nav, .capcut-sidebar-content, .capcut-inspector, .capcut-inspector-content, .capcut-timeline');
    panels.forEach(p => {
      p.setAttribute('data-lenis-prevent', 'true');
      p.addEventListener('wheel', (e) => {
        e.stopPropagation();
      }, { passive: false, capture: false });
    });

    // Stop Lenis entirely when CapCut workbench is active to prevent scroll hijacking
    const workbench = document.getElementById('capcutWorkbench');
    if (workbench) {
      const observer = new MutationObserver(() => {
        const isVisible = !workbench.classList.contains('d-none');
        if (typeof window.lenis !== 'undefined' && window.lenis) {
          if (isVisible) window.lenis.stop();
          else window.lenis.start();
        }
      });
      observer.observe(workbench, { attributes: true, attributeFilter: ['class'] });
      // Initial check
      if (!workbench.classList.contains('d-none')) {
        if (typeof window.lenis !== 'undefined' && window.lenis) window.lenis.stop();
      }
    }
  }

  // ── Page Initializer ──
  document.addEventListener('DOMContentLoaded', () => {
    const workbench = document.getElementById('capcutWorkbench');
    if (!workbench) return;
    document.body.appendChild(workbench);
    
    initDOMElements();
    StudioProjectStore.subscribe((newProjState) => {
      const valid = newProjState.items.some(i => i.id === state.selectedId);
      if (!valid) state.selectedId = null;
      renderInspector();
      renderPlayerOverlay();
      if (typeof triggerAutoSave === 'function') {
        triggerAutoSave();
      }
    });
    renderTimelineRuler();
    renderTimelineClips();
    renderInspector();
    loadCapCutDraft();
    const titleInput = document.getElementById('capcutProjectTitleInput');
    if (titleInput) {
      titleInput.addEventListener('input', () => {
        triggerAutoSave();
      });
    }
    loadMediaLibrary();
    
    handleMediaFileImport();
    handleAspectRatioSelect();
    handleTimelineScrubbing();
    setupSidebarTabs();
    setupAssetDrawerAdders();
    setupTimelineToolbar();
    setupMobileTabs();
    setupExportSettings();
    setupExportSync();
    setupWheelScrollIsolation();
    setupKeyboardShortcuts();
    saveHistoryState();
  });

  // Expose modular subsystems as window.CapCutEditor
  window.CapCutEditor = {
    // ── Single Source of Truth ──
    ProjectStore: StudioProjectStore,
    updateNleItemProperty,
    renderInspectorKeyframesSection,
    // ── Legacy state (bridge until full migration) ──
    State: state,
    SelectionManager: {
      selectElement,
      updateSelectionUI,
      makeOverlayManipulatable,
      updateOverlayTransform
    },
    TimelineManager: {
      renderTimelineRuler,
      renderTimelineClips,
      makeClipDraggable,
      recalculateTimelineDuration
    },
    PlaybackController: {
      startPlayback,
      pausePlayback,
      playbackTick
    },
    MediaManager: {
      handleMediaFileImport,
      handleAspectRatioSelect
    },
    AudioManager: {
      initAudioContext,
      triggerSynthSound
    },
    ExportManager: {
      setupExportSettings,
      setupExportSync
    },
    AutosaveManager: {
      triggerAutoSave,
      saveCapCutDraft,
      loadCapCutDraft
    },
    HistoryManager: {
      saveHistoryState,
      undo: window.undo,
      redo: window.redo
    },
    KeyboardShortcuts: {
      setupKeyboardShortcuts
    },
    renderInspector: renderInspector
  };

})();
