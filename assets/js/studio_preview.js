/**
 * assets/js/studio_preview.js
 *
 * Preview Compositing Engine for MediaFusion Studio.
 *
 * Architecture:
 *  - Subscribes to StudioProjectStore as the single source of truth
 *  - Maintains a pool of DOM elements keyed by item.id (no unnecessary recreation)
 *  - Uses a single rAF loop as the master clock
 *  - The first video-type item (lowest zIndex track) is the master clock source
 *  - All other layers are shown/hidden and transformed from state.currentTime
 *  - Audio items are managed as HTML5 Audio instances synced to currentTime
 *
 * Public API (window.PreviewEngine):
 *  .play()         — start playback
 *  .pause()        — pause playback
 *  .seek(t)        — seek to time in seconds
 *  .toggle()       — play/pause toggle
 *  .setMuted(bool) — mute/unmute all media
 *  .setVolume(v)   — set master volume 0..1
 *  .setFitMode(m)  — 'contain' | 'cover' | 'crop'
 *  .onStateChange(projectState) — called by StudioProjectStore subscriber
 */

(function () {
  'use strict';

  // ── Internal state ──────────────────────────────────────────────────────────
  const engine = {
    isPlaying:    false,
    currentTime:  0,
    duration:     15,
    muted:        false,
    volume:       1.0,
    fitMode:      'contain',   // 'contain' | 'cover' | 'crop'
    rafId:        null,
    lastRAFTime:  null,

    // Keyed by item.id
    layerPool:    new Map(),   // id → { el, type }
    audioPool:    new Map(),   // id → HTMLAudioElement

    masterVideoEl: null,       // The primary video clock source element
    masterVideoItemId: null,

    projectState:  null,
  };

  // ── DOM refs ────────────────────────────────────────────────────────────────
  let els = {};

  function initDOMRefs() {
    els = {
      playerWrapper:   document.getElementById('capcutPlayerWrapper'),
      layerContainer:  document.getElementById('previewLayerContainer'),
      seekBar:         document.getElementById('previewSeekBar'),
      timecode:        document.getElementById('previewTimecode'),
      playBtn:         document.getElementById('previewPlayBtn'),
      playIcon:        document.getElementById('previewPlayIcon'),
      muteBtn:         document.getElementById('previewMuteBtn'),
      muteIcon:        document.getElementById('previewMuteIcon'),
      volumeSlider:    document.getElementById('previewVolumeSlider'),
      fitBtn:          document.getElementById('previewFitBtn'),
      fullscreenBtn:   document.getElementById('previewFullscreenBtn'),
      zoomDisplay:     document.querySelector('.ps-zoom-display'),
      // Timeline bridge
      timelinePlayBtn: document.getElementById('capcutPlayBtn'),
      timelinePlayIcon:document.getElementById('capcutPlayIcon'),
      timelineTimecode:document.getElementById('capcutTimecode'),
      timelinePlayhead:document.getElementById('capcutPlayhead'),
    };
  }

  // ── StudioProjectStore subscriber ───────────────────────────────────────────
  function onStateChange(projectState) {
    engine.projectState = projectState;

    const settings = projectState.projectSettings;
    engine.duration = settings.duration || 15;

    // Update aspect ratio class on wrapper
    if (els.playerWrapper) {
      const ratioClass = 'ratio-' + (settings.aspectRatio || '16:9').replace(':', '-');
      ['ratio-16-9','ratio-9-16','ratio-1-1','ratio-4-5','ratio-21-9'].forEach(c =>
        els.playerWrapper.classList.remove(c)
      );
      els.playerWrapper.classList.add(ratioClass);
    }

    // Apply canvas background
    if (els.layerContainer) {
      els.layerContainer.style.background = settings.background || '#000';
    }

    // Sync seek bar max
    if (els.seekBar) {
      els.seekBar.max = engine.duration;
    }

    // Rebuild layer pool from items
    syncLayerPool(projectState);

    // Render current frame
    renderFrame(engine.currentTime);

    // Update timecode display
    updateTimecodeDisplay();
  }

  // ── Layer Pool Management ───────────────────────────────────────────────────
  function syncLayerPool(projectState) {
    if (!els.layerContainer) return;

    const items   = projectState.items || [];
    const tracks  = projectState.tracks || [];
    const library = projectState.mediaLibrary || [];

    // Build set of current item IDs
    const activeIds = new Set(items.map(i => i.id));

    // Remove stale layers
    engine.layerPool.forEach((entry, id) => {
      if (!activeIds.has(id)) {
        entry.el.remove();
        engine.layerPool.delete(id);
      }
    });

    // Remove stale audio
    engine.audioPool.forEach((audio, id) => {
      if (!activeIds.has(id)) {
        audio.pause();
        engine.audioPool.delete(id);
      }
    });

    // Track lookup map
    const trackMap = new Map(tracks.map(t => [t.id, t]));

    // Find master video item (lowest zIndex track, type=video)
    let masterItem = null;
    let masterZIndex = Infinity;

    // Sort items by track zIndex then position for consistent z-ordering
    const sortedItems = [...items].sort((a, b) => {
      const zA = (trackMap.get(a.trackId)?.zIndex ?? 0);
      const zB = (trackMap.get(b.trackId)?.zIndex ?? 0);
      return zA - zB;
    });

    sortedItems.forEach((item, layerIdx) => {
      const track = trackMap.get(item.trackId);
      if (!track) return;

      const trackZIndex = track.zIndex ?? 0;

      // Master video: first video item on lowest zIndex track, not frozen and not reversed
      if (item.type === 'video' && item.freezeTime === undefined && item.reversed !== true && trackZIndex < masterZIndex) {
        masterZIndex = trackZIndex;
        masterItem = item;
      }

      if (item.type === 'audio') {
        ensureAudioElement(item, library);
        return; // no visual element
      }

      ensureLayerElement(item, track, library, layerIdx);
    });

    // Update master video reference
    if (masterItem) {
      const entry = engine.layerPool.get(masterItem.id);
      engine.masterVideoEl = entry ? entry.el : null;
      engine.masterVideoItemId = masterItem.id;
    } else {
      engine.masterVideoEl = null;
      engine.masterVideoItemId = null;
    }
  }

  function ensureLayerElement(item, track, library, layerIdx) {
    let entry = engine.layerPool.get(item.id);

    if (!entry) {
      let el;
      if (item.type === 'video') {
        const capcutVid = document.getElementById('capcutVideo');
        if (capcutVid && (!capcutVid.dataset.itemId || capcutVid.dataset.itemId === item.id)) {
          el = capcutVid;
          // Ensure the reused element is visible and properly styled
          el.style.display = '';
          el.className = 'preview-layer preview-video-layer capcut-video';
        } else {
          el = document.createElement('video');
          el.className = 'preview-layer preview-video-layer';
          els.layerContainer.appendChild(el);
        }
        el.preload = 'auto';
        el.playsInline = true;
        el.setAttribute('playsinline', '');
        el.muted = engine.muted;
        el.volume = engine.volume;
      } else if (item.type === 'image') {
        el = document.createElement('img');
        el.className = 'preview-layer preview-image-layer';
        el.alt = item.name || '';
        el.draggable = false;
        els.layerContainer.appendChild(el);
      } else if (item.type === 'text' || item.type === 'caption' || item.type === 'overlay' || item.type === 'sticker') {
        el = document.createElement('div');
        el.className = `preview-layer preview-${item.type}-layer`;
        els.layerContainer.appendChild(el);
        
        if (item.type === 'text' || item.type === 'caption') {
          el.addEventListener('dblclick', (e) => {
            e.stopPropagation();
            const currentText = el.textContent || '';
            const newText = prompt('Edit Text Content:', currentText);
            if (newText !== null) {
              const store = window.StudioProjectStore;
              if (store) {
                const st = store.getState();
                const activeItem = st.items.find(i => i.id === item.id);
                if (activeItem) {
                  activeItem.content = newText;
                  store.setState(st, true);
                }
              }
            }
          });
        }
      } else {
        return; // unknown type
      }

      el.dataset.itemId = item.id;
      
      // Drag & Select translation on the preview canvas
      el.style.cursor = 'move';
      el.style.pointerEvents = 'auto';

      let isDragging = false;
      let startX, startY;
      let startPx, startPy;

      el.addEventListener('pointerdown', (e) => {
        e.stopPropagation();
        el.setPointerCapture(e.pointerId);
        isDragging = true;
        startX = e.clientX;
        startY = e.clientY;
        
        const store = window.StudioProjectStore;
        if (store) {
          const storeState = store.getState();
          const currentItem = storeState.items.find(i => i.id === item.id);
          if (currentItem) {
            startPx = currentItem.position?.x ?? 50;
            startPy = currentItem.position?.y ?? 50;
          } else {
            startPx = 50;
            startPy = 50;
          }
        } else {
          startPx = 50;
          startPy = 50;
        }

        if (window.StudioTimeline && typeof window.StudioTimeline.selectClip === 'function') {
          window.StudioTimeline.selectClip(item.id);
        }
      });

      el.addEventListener('pointermove', (e) => {
        if (!isDragging) return;
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;
        const parentRect = els.layerContainer.getBoundingClientRect();
        
        const pctDx = (dx / parentRect.width) * 100;
        const pctDy = (dy / parentRect.height) * 100;

        const store = window.StudioProjectStore;
        if (store) {
          const storeState = store.getState();
          const itemToUpdate = storeState.items.find(i => i.id === item.id);
          if (itemToUpdate) {
            itemToUpdate.position = {
              x: Math.max(0, Math.min(100, startPx + pctDx)),
              y: Math.max(0, Math.min(100, startPy + pctDy))
            };
            store.setState(storeState, false);
          }
        }
      });

      el.addEventListener('pointerup', (e) => {
        if (!isDragging) return;
        isDragging = false;
        el.releasePointerCapture(e.pointerId);

        const store = window.StudioProjectStore;
        if (store) {
          store.setState(store.getState(), true);
        }
      });

      entry = { el, type: item.type };
      engine.layerPool.set(item.id, entry);
    }

    const el = entry.el;

    // ── Source URL ──
    const mediaItem = library.find(m => m.id === item.sourceMediaId);
    let url = item.url || mediaItem?.url || null;
    if (!url && item.type === 'video') {
      url = window.CapCutEditor?.State?.videoUrl || window.CapCutEditor?.State?.videoClip?.filePath || null;
    }

    if (item.type === 'video') {
      if (url && el.getAttribute('data-src') !== url) {
        el.setAttribute('data-src', url);
        el.src = url;
        el.muted  = engine.muted || track.muted;
        el.volume = engine.volume;
        el.style.objectFit = engine.fitMode === 'cover' ? 'cover' : 'contain';
        try { el.load(); } catch(e) {}
      } else {
        el.muted = engine.muted || track.muted;
      }
    } else if (item.type === 'image') {
      if (url && el.getAttribute('data-src') !== url) {
        el.setAttribute('data-src', url);
        el.src = url;
        el.style.objectFit = engine.fitMode === 'cover' ? 'cover' : 'contain';
      }
    } else if (item.type === 'text' || item.type === 'caption' || item.type === 'overlay') {
      const text  = item.content || item.name || '';
      const style = item.style || {};
      if (el.textContent !== text) el.textContent = text;
      el.style.color          = style.color || (item.type === 'caption' ? '#facc15' : '#ffffff');
      el.style.fontSize       = (style.fontSize || (item.type === 'caption' ? 22 : 28)) + 'px';
      el.style.fontFamily     = (style.fontFamily || 'Space Grotesk') + ', sans-serif';
      el.style.fontWeight     = style.fontWeight || style.weight || 'bold';
      el.style.fontStyle      = style.fontStyle || 'normal';
      el.style.textAlign      = style.textAlign || 'center';
      el.style.letterSpacing  = (style.letterSpacing ?? 0) + 'px';
      el.style.lineHeight     = style.lineHeight ?? 1.2;
      el.style.pointerEvents  = 'auto';

      // Text Stroke
      if (style.stroke?.width > 0) {
        el.style.webkitTextStroke = `${style.stroke.width}px ${style.stroke.color || '#000000'}`;
      } else {
        el.style.webkitTextStroke = '';
      }

      // Glow and Shadow
      if (style.glow?.enabled) {
        const glowC = style.glow.color || '#00ffff';
        const glowR = style.glow.radius ?? 8;
        el.style.textShadow = `0 0 ${glowR}px ${glowC}, 0 0 ${glowR * 2}px ${glowC}`;
      } else if (style.shadow) {
        el.style.textShadow = `${style.shadow.x || 0}px ${style.shadow.y || 0}px ${style.shadow.blur || 0}px ${style.shadow.color || 'rgba(0,0,0,0.5)'}`;
      } else {
        el.style.textShadow = '';
      }

      // Blur filter
      if (style.blur && style.blur > 0) {
        el.style.filter = `blur(${style.blur}px)`;
      } else {
        el.style.filter = '';
      }

      // Background Box
      if (style.background?.enabled) {
        const bgHex = style.background.color || '#000000';
        const bgOp  = (style.background.opacity ?? 60) / 100;
        const bgPad = style.background.padding ?? 8;
        let r = 0, g = 0, b = 0;
        if (bgHex.startsWith('#')) {
          const cleanHex = bgHex.replace('#', '');
          r = parseInt(cleanHex.substring(0, 2), 16) || 0;
          g = parseInt(cleanHex.substring(2, 4), 16) || 0;
          b = parseInt(cleanHex.substring(4, 6), 16) || 0;
        }
        el.style.backgroundColor = `rgba(${r}, ${g}, ${b}, ${bgOp})`;
        el.style.padding = `${bgPad}px`;
        el.style.borderRadius = '4px';
        el.style.border = '';
      } else if (item.type === 'caption') {
        el.style.backgroundColor = 'rgba(0,0,0,0.65)';
        el.style.padding = '4px 12px';
        el.style.borderRadius = '6px';
        el.style.border = '1px solid rgba(250,204,21,0.4)';
      } else {
        el.style.backgroundColor = 'transparent';
        el.style.padding = '0px';
        el.style.borderRadius = '';
        el.style.border = '';
      }
    } else if (item.type === 'sticker') {
      const et = item.elementType || 'emoji';
      const style = item.style || {};
      const url = style.url || '';
      const animation = style.animationClass || '';

      let targetHtml = '';
      if (et === 'emoji') {
        targetHtml = `<span style="font-size: 64px; font-family: sans-serif; display: block; text-align: center; line-height: 1;">${style.emoji || item.emoji || '🔥'}</span>`;
      } else if (et === 'shape') {
        const strokeW = style.strokeWidth ?? 2;
        const fill = style.color ?? '#ec4899';
        const stroke = style.strokeColor ?? '#ffffff';
        
        if (style.shape === 'circle') {
          targetHtml = `<svg viewBox="0 0 100 100" style="width:100%; height:100%; display:block;"><circle cx="50" cy="50" r="45" fill="${fill}" stroke="${stroke}" stroke-width="${strokeW * 3}" /></svg>`;
        } else if (style.shape === 'rect') {
          targetHtml = `<svg viewBox="0 0 100 100" style="width:100%; height:100%; display:block;"><rect x="10" y="10" width="80" height="80" fill="${fill}" stroke="${stroke}" stroke-width="${strokeW * 3}" /></svg>`;
        } else if (style.shape === 'triangle') {
          targetHtml = `<svg viewBox="0 0 100 100" style="width:100%; height:100%; display:block;"><polygon points="50,10 90,90 10,90" fill="${fill}" stroke="${stroke}" stroke-width="${strokeW * 3}" /></svg>`;
        } else if (style.shape === 'star') {
          targetHtml = `<svg viewBox="0 0 100 100" style="width:100%; height:100%; display:block;"><polygon points="50,10 62,42 96,42 68,62 78,94 50,74 22,94 32,62 4,42 38,42" fill="${fill}" stroke="${stroke}" stroke-width="${strokeW * 3}" /></svg>`;
        } else if (style.shape === 'arrow') {
          targetHtml = `<svg viewBox="0 0 100 100" style="width:100%; height:100%; display:block;"><polygon points="10,40 55,40 55,20 90,50 55,80 55,60 10,60" fill="${fill}" stroke="${stroke}" stroke-width="${strokeW * 3}" /></svg>`;
        }
      } else {
        // stickers, graphics, animated
        const animClass = animation ? `animated-element ${animation}` : '';
        targetHtml = `<img src="${url}" class="${animClass}" style="width:100%; height:100%; display:block; object-fit:contain; pointer-events:none;">`;
      }

      if (el.innerHTML !== targetHtml) {
        el.innerHTML = targetHtml;
      }
      
      // Reset generic styles to avoid text styling overrides leaking to elements
      el.style.color = '';
      el.style.fontSize = '';
      el.style.fontFamily = '';
      el.style.fontWeight = '';
      el.style.fontStyle = '';
      el.style.textAlign = '';
      el.style.letterSpacing = '';
      el.style.lineHeight = '';
      el.style.webkitTextStroke = '';
      el.style.textShadow = '';
      el.style.backgroundColor = '';
      el.style.padding = '0px';
      el.style.borderRadius = '';
      el.style.border = '';
    }

    // ── Z-index ──
    el.style.zIndex = (track.zIndex ?? 0) * 10 + layerIdx;

    // ── CSS transform: position, scale, rotation, opacity ──
    applyItemTransform(el, item);
  }

  function ensureAudioElement(item, library) {
    let audio = engine.audioPool.get(item.id);
    const mediaItem = library.find(m => m.id === item.sourceMediaId);
    let url = item.url || mediaItem?.url || null;

    if (!audio && url) {
      audio = new Audio(url);
      audio.preload = 'auto';
      audio.muted   = engine.muted;
      audio.volume  = Math.min(1, ((item.volume ?? 100) / 100) * engine.volume);
      engine.audioPool.set(item.id, audio);
    } else if (audio && url && audio.getAttribute('data-src') !== url) {
      audio.src = url;
      audio.setAttribute('data-src', url);
    }

    if (audio) {
      audio.muted  = engine.muted;
      audio.volume = Math.min(1, ((item.volume ?? 100) / 100) * engine.volume);
    }
  }

  function calculateAudioVolume(item, t) {
    if (item.muted === true) return 0;
    
    const relT = t - item.start;
    
    // First, try new unified keyframe properties
    const kfProps = interpolateKeyframeProperties(item, relT);
    let baseVolume = kfProps.volume / 100;
    
    // If no keyframes are defined but legacy volumeKeyframes exists, use it
    if ((!item.keyframes || item.keyframes.length === 0) && item.volumeKeyframes && item.volumeKeyframes.length > 0) {
      const kfs = [...item.volumeKeyframes].sort((a, b) => a.time - b.time);
      if (relT <= kfs[0].time) {
        baseVolume = kfs[0].volume / 100;
      } else if (relT >= kfs[kfs.length - 1].time) {
        baseVolume = kfs[kfs.length - 1].volume / 100;
      } else {
        for (let i = 0; i < kfs.length - 1; i++) {
          const kfA = kfs[i];
          const kfB = kfs[i + 1];
          if (relT >= kfA.time && relT <= kfB.time) {
            const range = kfB.time - kfA.time;
            const factor = range > 0 ? (relT - kfA.time) / range : 0;
            const interpVolume = kfA.volume + (kfB.volume - kfA.volume) * factor;
            baseVolume = interpVolume / 100;
            break;
          }
        }
      }
    }

    let fadeFactor = 1.0;
    if (item.fadeIn && item.fadeIn > 0) {
      const elapsed = t - item.start;
      if (elapsed < item.fadeIn) {
        fadeFactor = Math.max(0, elapsed / item.fadeIn);
      }
    }
    if (item.fadeOut && item.fadeOut > 0) {
      const remaining = (item.start + item.duration) - t;
      if (remaining < item.fadeOut) {
        fadeFactor = Math.min(fadeFactor, Math.max(0, remaining / item.fadeOut));
      }
    }
    
    return baseVolume * fadeFactor;
  }

  function interpolateKeyframeProperties(item, relTime) {
    const kfs = item.keyframes;
    
    // Base helper to merge keyframe properties with item fallbacks
    const getProperties = (kf) => {
      const p = kf.properties || {};
      return {
        position: {
          x: p.position?.x ?? item.position?.x ?? 50,
          y: p.position?.y ?? item.position?.y ?? 50
        },
        scale: {
          x: p.scale?.x ?? item.scale?.x ?? 1.0,
          y: p.scale?.y ?? item.scale?.y ?? 1.0
        },
        rotation: p.rotation ?? item.rotation ?? 0,
        opacity: p.opacity !== undefined ? p.opacity : (item.opacity !== undefined ? item.opacity : 1.0),
        volume: p.volume ?? item.volume ?? 100,
        effectIntensity: p.effectIntensity ?? item.intensity ?? 100,
        filterIntensity: p.filterIntensity ?? 100,
        filters: {
          saturation: p.filters?.saturation ?? item.filters?.saturation ?? 100,
          contrast: p.filters?.contrast ?? item.filters?.contrast ?? 100,
          brightness: p.filters?.brightness ?? item.filters?.brightness ?? 100,
          exposure: p.filters?.exposure ?? item.filters?.exposure ?? 100,
          temperature: p.filters?.temperature ?? item.filters?.temperature ?? 0,
          tint: p.filters?.tint ?? item.filters?.tint ?? 0
        },
        style: {
          fontSize: p.style?.fontSize ?? item.style?.fontSize ?? 28,
          color: p.style?.color ?? item.style?.color ?? '#ffffff',
          strokeWidth: p.style?.strokeWidth ?? item.style?.strokeWidth ?? 2,
          strokeColor: p.style?.strokeColor ?? item.style?.strokeColor ?? '#ffffff'
        },
        mask: {
          type: p.mask?.type ?? item.mask?.type ?? 'none',
          x: p.mask?.x ?? item.mask?.x ?? 50,
          y: p.mask?.y ?? item.mask?.y ?? 50,
          width: p.mask?.width ?? item.mask?.width ?? 30,
          height: p.mask?.height ?? item.mask?.height ?? 30,
          rotation: p.mask?.rotation ?? item.mask?.rotation ?? 0,
          feather: p.mask?.feather ?? item.mask?.feather ?? 0,
          invert: p.mask?.invert !== undefined ? p.mask?.invert : (item.mask?.invert !== undefined ? item.mask?.invert : false)
        }
      };
    };

    if (!Array.isArray(kfs) || kfs.length === 0) {
      return getProperties({ properties: {} });
    }

    const sorted = [...kfs].sort((a, b) => a.time - b.time);

    if (relTime <= sorted[0].time) {
      return getProperties(sorted[0]);
    }

    if (relTime >= sorted[sorted.length - 1].time) {
      return getProperties(sorted[sorted.length - 1]);
    }

    let kfA = sorted[0];
    let kfB = sorted[sorted.length - 1];
    for (let i = 0; i < sorted.length - 1; i++) {
      if (relTime >= sorted[i].time && relTime <= sorted[i + 1].time) {
        kfA = sorted[i];
        kfB = sorted[i + 1];
        break;
      }
    }

    const dt = kfB.time - kfA.time;
    const rawF = dt > 0 ? (relTime - kfA.time) / dt : 1.0;
    
    // Easing interpolation support
    const easing = kfA.interpolation || 'linear';
    let f = rawF;
    if (easing === 'ease-in') {
      f = rawF * rawF;
    } else if (easing === 'ease-out') {
      f = 1 - (1 - rawF) * (1 - rawF);
    } else if (easing === 'ease-in-out') {
      f = rawF < 0.5 ? 2 * rawF * rawF : 1 - Math.pow(-2 * rawF + 2, 2) / 2;
    }

    const lerp = (a, b) => a + (b - a) * f;

    const lerpColor = (c1, c2, factor) => {
      const hex = (x) => {
        const val = Math.round(Math.max(0, Math.min(255, x))).toString(16);
        return val.length === 1 ? '0' + val : val;
      };
      
      const parse = (c) => {
        let clean = (c || '#ffffff').replace('#', '');
        if (clean.length === 3) {
          clean = clean.split('').map(x => x + x).join('');
        }
        return {
          r: parseInt(clean.substring(0, 2), 16) || 0,
          g: parseInt(clean.substring(2, 4), 16) || 0,
          b: parseInt(clean.substring(4, 6), 16) || 0
        };
      };

      const p1 = parse(c1);
      const p2 = parse(c2);
      const r = p1.r + (p2.r - p1.r) * factor;
      const g = p1.g + (p2.g - p1.g) * factor;
      const b = p1.b + (p2.b - p1.b) * factor;
      return `#${hex(r)}${hex(g)}${hex(b)}`;
    };

    const propsA = getProperties(kfA);
    const propsB = getProperties(kfB);

    return {
      position: {
        x: lerp(propsA.position.x, propsB.position.x),
        y: lerp(propsA.position.y, propsB.position.y)
      },
      scale: {
        x: lerp(propsA.scale.x, propsB.scale.x),
        y: lerp(propsA.scale.y, propsB.scale.y)
      },
      rotation: lerp(propsA.rotation, propsB.rotation),
      opacity: lerp(propsA.opacity, propsB.opacity),
      volume: lerp(propsA.volume, propsB.volume),
      effectIntensity: lerp(propsA.effectIntensity, propsB.effectIntensity),
      filterIntensity: lerp(propsA.filterIntensity, propsB.filterIntensity),
      filters: {
        saturation: lerp(propsA.filters.saturation, propsB.filters.saturation),
        contrast: lerp(propsA.filters.contrast, propsB.filters.contrast),
        brightness: lerp(propsA.filters.brightness, propsB.filters.brightness),
        exposure: lerp(propsA.filters.exposure, propsB.filters.exposure),
        temperature: lerp(propsA.filters.temperature, propsB.filters.temperature),
        tint: lerp(propsA.filters.tint, propsB.filters.tint)
      },
      style: {
        fontSize: lerp(propsA.style.fontSize, propsB.style.fontSize),
        color: lerpColor(propsA.style.color, propsB.style.color, f),
        strokeWidth: lerp(propsA.style.strokeWidth, propsB.style.strokeWidth),
        strokeColor: lerpColor(propsA.style.strokeColor, propsB.style.strokeColor, f)
      },
      mask: {
        type: propsB.mask.type,
        x: lerp(propsA.mask.x, propsB.mask.x),
        y: lerp(propsA.mask.y, propsB.mask.y),
        width: lerp(propsA.mask.width, propsB.mask.width),
        height: lerp(propsA.mask.height, propsB.mask.height),
        rotation: lerp(propsA.mask.rotation, propsB.mask.rotation),
        feather: lerp(propsA.mask.feather, propsB.mask.feather),
        invert: propsB.mask.invert
      }
    };
  }

  function interpolateTrajectoryPoint(trajectory, t) {
    if (!Array.isArray(trajectory) || trajectory.length === 0) return null;
    const sorted = [...trajectory].sort((a, b) => a.time - b.time);
    if (t <= sorted[0].time) return sorted[0];
    if (t >= sorted[sorted.length - 1].time) return sorted[sorted.length - 1];
    let a = sorted[0];
    let b = sorted[sorted.length - 1];
    for (let i = 0; i < sorted.length - 1; i++) {
      if (t >= sorted[i].time && t <= sorted[i + 1].time) {
        a = sorted[i];
        b = sorted[i + 1];
        break;
      }
    }
    const dt = b.time - a.time;
    const f = dt > 0 ? (t - a.time) / dt : 1.0;
    return {
      x: a.x + (b.x - a.x) * f,
      y: a.y + (b.y - a.y) * f,
      width: a.width + (b.width - a.width) * f,
      height: a.height + (b.height - a.height) * f
    };
  }

  function applyItemTransform(el, item) {
    el.style.boxShadow = '';
    el.style.backgroundImage = '';
    el.style.clipPath = '';

    const relTime = engine.currentTime - item.start;
    const kfProps = interpolateKeyframeProperties(item, relTime);

    let px = kfProps.position.x;
    let py = kfProps.position.y;
    let sx = kfProps.scale.x;
    let sy = kfProps.scale.y;
    let r  = kfProps.rotation;
    let op = kfProps.opacity;

    // Apply motion tracking offsets if attached
    if (item.tracking && item.tracking.sourceItemId && engine.projectState) {
      const source = (engine.projectState.items || []).find(i => i.id === item.tracking.sourceItemId);
      if (source && Array.isArray(item.tracking.trajectory)) {
        const trackT = engine.currentTime - source.start;
        const trackPt = interpolateTrajectoryPoint(item.tracking.trajectory, trackT);
        if (trackPt) {
          px += (trackPt.x - 50.0);
          py += (trackPt.y - 50.0);
          const scaleFactor = (trackPt.width ?? 30.0) / 30.0;
          sx *= scaleFactor;
          sy *= scaleFactor;
        }
      }
    }

    el.style.opacity = op;

    let translateXOffset = 0;
    let translateYOffset = 0;

    // Apply text animations dynamically
    if (item.type === 'text' || item.type === 'caption') {
      const t = engine.currentTime;
      const relT = t - item.start;
      const totalDuration = item.duration;
      const inAnim = item.animations?.in;
      const outAnim = item.animations?.out;
      const comboAnim = item.animations?.combo;

      const inDur = 0.8;
      const outDur = 0.8;

      // In animation
      if (inAnim && relT < inDur && relT >= 0) {
        const factor = relT / inDur;
        if (inAnim === 'fade') {
          op *= factor;
        } else if (inAnim === 'zoom') {
          sx *= factor;
          sy *= factor;
        } else if (inAnim === 'slide') {
          translateYOffset += (1 - factor) * 50;
        }
      }

      // Out animation
      const remaining = totalDuration - relT;
      if (outAnim && remaining < outDur && remaining >= 0) {
        const factor = remaining / outDur;
        if (outAnim === 'fade') {
          op *= factor;
        } else if (outAnim === 'zoom') {
          sx *= factor;
          sy *= factor;
        } else if (outAnim === 'slide') {
          translateYOffset += (1 - factor) * -50;
        }
      }

      // Combo animation (cyclic loop)
      if (comboAnim && relT >= 0) {
        if (comboAnim === 'bounce') {
          translateYOffset += Math.sin(relT * 6) * 15;
        } else if (comboAnim === 'pulse') {
          op *= (0.6 + 0.4 * Math.sin(relT * 5));
        } else if (comboAnim === 'wave') {
          r += Math.sin(relT * 6) * 8;
        }
      }
    }

    el.style.opacity   = op;
    el.style.left      = px + '%';
    el.style.top       = py + '%';
    
    // Apply Horizontal and Vertical Flip
    const flipH = item.flip?.horizontal ? -1 : 1;
    const flipV = item.flip?.vertical ? -1 : 1;
    el.style.transform = `translate(calc(-50% + ${translateXOffset}px), calc(-50% + ${translateYOffset}px)) scale(${sx * flipH}, ${sy * flipV}) rotate(${r}deg)`;

    // Crop via clip-path inset
    if (item.crop) {
      const { left = 0, right = 0, top = 0, bottom = 0 } = item.crop;
      el.style.clipPath = `inset(${top}% ${right}% ${bottom}% ${left}%)`;
    } else {
      el.style.clipPath = '';
    }

    // Fit and Fill for image/video elements
    if (item.type === 'video' || item.type === 'image') {
      el.style.objectFit = item.fit || 'contain';
      el.style.backgroundColor = item.fill || 'transparent';
    }

    // CSS color filters
    const f = (item.keyframes && item.keyframes.length > 0) ? kfProps.filters : (item.filters || {});
    const parts = [];

    // Custom SVG filter link (for Curves, HSL, Sharpness etc)
    if (item.colorAdjustment?.curves || (item.colorAdjustment?.sharpness && item.colorAdjustment.sharpness > 0)) {
      updateSvgFilters(item);
      parts.push(`url(#capcutFilter-${item.id})`);
    }

    const sat = f.saturation ?? 100;
    const br = f.brightness ?? 100;
    const con = f.contrast ?? 100;
    const exp = f.exposure ?? 100;
    const temp = f.temperature ?? 0;
    const tint = f.tint ?? 0;
    const vib = f.vibrance ?? 0;
    const shadows = f.shadows ?? 0;
    const highlights = f.highlights ?? 0;

    // Combine standard filters
    const computedBrightness = br * (exp / 100);
    const computedSaturation = sat + (vib * 0.4);
    const computedContrast = con + (shadows * -0.3) + (highlights * 0.2);

    parts.push(`saturate(${computedSaturation}%)`);
    parts.push(`brightness(${computedBrightness}%)`);
    parts.push(`contrast(${computedContrast}%)`);
    
    if (temp !== 0) {
      parts.push(`hue-rotate(${temp * 0.15}deg)`);
    }
    if (tint !== 0) {
      parts.push(`hue-rotate(${tint * 0.1}deg)`);
    }

    // Effect specific parameters rendering
    if (item.type === 'effect' || item.type === 'filter' || item.type === 'sticker' || item.type === 'overlay') {
      const intensity = item.intensity !== undefined ? item.intensity : 100;
      const name = (item.name || '').toLowerCase();
      if (name.includes('noir') || name.includes('black')) {
        parts.push(`grayscale(${intensity}%)`);
      } else if (name.includes('blur') || name.includes('focus')) {
        parts.push(`blur(${(intensity * 0.15)}px)`);
      } else if (name.includes('bloom') || name.includes('glow')) {
        parts.push(`brightness(${1.0 + (intensity / 100)}) contrast(${1.0 - (intensity / 300)})`);
      } else if (name.includes('vintage') || name.includes('sepia')) {
        parts.push(`sepia(${intensity}%)`);
      } else if (name.includes('cold') || name.includes('cool')) {
        parts.push(`hue-rotate(${intensity * 0.2}deg) saturate(${100 - intensity * 0.3}%)`);
      }
    }

    // Apply dynamic composable effects
    const currentTime = engine.currentTime;
    const clipRelTime = currentTime - item.start;

    if (Array.isArray(item.effects)) {
      item.effects.forEach(eff => {
        const effStart = eff.startOffset ?? 0;
        const effEnd = effStart + (eff.duration ?? item.duration);
        if (clipRelTime >= effStart && clipRelTime <= effEnd) {
          const intensity = (item.keyframes && item.keyframes.length > 0) ? kfProps.effectIntensity : (eff.intensity ?? 100);
          const id = eff.id;
          
          if (id === 'gaussian_blur') {
            parts.push(`blur(${(intensity * 0.15)}px)`);
          } else if (id === 'vintage_sepia') {
            parts.push(`sepia(${intensity}%)`);
          } else if (id === 'noir_mono') {
            parts.push(`grayscale(${intensity}%)`);
          } else if (id === 'rgb_split') {
            parts.push(`drop-shadow(${intensity * 0.08}px 0px 0px rgba(255,0,0,0.8)) drop-shadow(${-intensity * 0.08}px 0px 0px rgba(0,0,255,0.8))`);
          } else if (id === 'cyber_bloom' || id === 'neon_pulse' || id === 'cinematic_anamorphic') {
            parts.push(`brightness(${1.0 + (intensity / 100)}) contrast(${1.0 - (intensity / 300)})`);
          } else if (id === 'camera_shake') {
            const amt = intensity * 0.15;
            translateXOffset += Math.sin(currentTime * 24) * amt;
            translateYOffset += Math.cos(currentTime * 24) * amt;
          } else if (id === 'light_leak') {
            el.style.backgroundImage = `linear-gradient(${currentTime * 45}deg, rgba(255,0,0,0.15) 0%, rgba(0,255,255,0.15) 50%, rgba(255,0,255,0.15) 100%)`;
          } else if (id === 'vignette_dark' || id === 'lens_fisheye') {
            el.style.boxShadow = `inset 0 0 ${intensity * 1.5}px rgba(0,0,0,0.95)`;
          } else if (id === 'ripple_wave') {
            updateSvgFilters(item, eff);
            parts.push(`url(#capcutFilter-${item.id})`);
          }
        }
      });
    }

    // Reapply transform with shake translation offsets
    el.style.transform = `translate(calc(-50% + ${translateXOffset}px), calc(-50% + ${translateYOffset}px)) scale(${sx * flipH}, ${sy * flipV}) rotate(${r}deg)`;

    el.style.filter = parts.length > 0 ? parts.join(' ') : '';
  }

  // ── Transition Helper Functions ─────────────────────────────────────────────
  function applyEasing(p, easing) {
    if (easing === 'ease-in') {
      return p * p;
    } else if (easing === 'ease-out') {
      return 1 - (1 - p) * (1 - p);
    } else if (easing === 'ease-in-out') {
      return p < 0.5 ? 2 * p * p : 1 - Math.pow(-2 * p + 2, 2) / 2;
    }
    return p; // default: linear
  }

  function computeActiveTransitions(items, t) {
    const active = {}; // itemId -> { role, progress, transition }
    
    // Group video and image items by track
    const tracksClips = {};
    items.forEach(item => {
      if (item.type === 'video' || item.type === 'image') {
        if (!tracksClips[item.trackId]) tracksClips[item.trackId] = [];
        tracksClips[item.trackId].push(item);
      }
    });

    // For each track, sort clips by start time and find transitions
    for (const trackId in tracksClips) {
      const list = tracksClips[trackId].sort((a, b) => a.start - b.start);
      for (let i = 1; i < list.length; i++) {
        const incoming = list[i];
        if (incoming.transitionIn) {
          const outgoing = list[i - 1];
          const dur = incoming.transitionIn.duration ?? 0.5;
          const boundaryStart = incoming.start;
          const boundaryEnd = boundaryStart + dur;

          if (t >= boundaryStart && t < boundaryEnd) {
            // Active transition!
            const rawProgress = (t - boundaryStart) / dur;
            const easedProgress = applyEasing(rawProgress, incoming.transitionIn.easing);
            
            active[outgoing.id] = {
              role: 'outgoing',
              progress: easedProgress,
              transition: incoming.transitionIn
            };
            active[incoming.id] = {
              role: 'incoming',
              progress: easedProgress,
              transition: incoming.transitionIn
            };
          }
        }
      }
    }
    return active;
  }

  function applyTransitionStyles(el, role, p, transition) {
    const id = transition.id;

    if (id === 'cross_fade') {
      el.style.opacity = role === 'outgoing' ? (1 - p) : p;
    } else if (id === 'fade_black') {
      el.style.opacity = role === 'outgoing' 
        ? (p < 0.5 ? 1 - p * 2 : 0)
        : (p < 0.5 ? 0 : (p - 0.5) * 2);
    } else if (id === 'fade_white') {
      el.style.opacity = role === 'outgoing' ? (1 - p) : p;
      const b = role === 'outgoing' ? 1 + p * 4 : 1 + (1 - p) * 4;
      el.style.filter += ` brightness(${b})`;
    } else if (id === 'dissolve') {
      el.style.opacity = role === 'outgoing' ? (1 - p) : p;
    } else if (id === 'film_grain') {
      el.style.opacity = role === 'outgoing' ? (1 - p) : p;
      const c = role === 'outgoing' ? 100 - p * 50 : 50 + p * 50;
      el.style.filter += ` contrast(${c}%)`;
    } else if (id === 'blur_dissolve') {
      el.style.opacity = role === 'outgoing' ? (1 - p) : p;
      const b = role === 'outgoing' ? p * 20 : (1 - p) * 20;
      el.style.filter += ` blur(${b}px)`;
    } else if (id === 'motion_blur') {
      el.style.opacity = role === 'outgoing' ? (1 - p) : p;
      const x = role === 'outgoing' ? -p * 60 : (1 - p) * 60;
      const b = role === 'outgoing' ? p * 15 : (1 - p) * 15;
      el.style.transform += ` translateX(${x}px)`;
      el.style.filter += ` blur(${b}px)`;
    } else if (id === 'slide_left') {
      const x = role === 'outgoing' ? -p * 100 : (1 - p) * 100;
      el.style.transform += ` translateX(${x}%)`;
    } else if (id === 'slide_right') {
      const x = role === 'outgoing' ? p * 100 : -(1 - p) * 100;
      el.style.transform += ` translateX(${x}%)`;
    } else if (id === 'slide_up') {
      const y = role === 'outgoing' ? -p * 100 : (1 - p) * 100;
      el.style.transform += ` translateY(${y}%)`;
    } else if (id === 'slide_down') {
      const y = role === 'outgoing' ? p * 100 : -(1 - p) * 100;
      el.style.transform += ` translateY(${y}%)`;
    } else if (id === 'zoom_in') {
      const s = role === 'outgoing' ? 1 + p * 0.5 : 0.5 + p * 0.5;
      el.style.opacity = role === 'outgoing' ? (1 - p) : p;
      el.style.transform += ` scale(${s})`;
    } else if (id === 'zoom_out') {
      const s = role === 'outgoing' ? 1 - p * 0.5 : 1.5 - p * 0.5;
      el.style.opacity = role === 'outgoing' ? (1 - p) : p;
      el.style.transform += ` scale(${s})`;
    } else if (id === 'zoom_blur') {
      const s = role === 'outgoing' ? 1 + p * 0.5 : 0.5 + p * 0.5;
      const b = role === 'outgoing' ? p * 12 : (1 - p) * 12;
      el.style.opacity = role === 'outgoing' ? (1 - p) : p;
      el.style.transform += ` scale(${s})`;
      el.style.filter += ` blur(${b}px)`;
    } else if (id === 'spin_cw') {
      const r = role === 'outgoing' ? p * 180 : -180 + p * 180;
      const s = role === 'outgoing' ? 1 - p : p;
      el.style.opacity = role === 'outgoing' ? (1 - p) : p;
      el.style.transform += ` rotate(${r}deg) scale(${s})`;
    } else if (id === 'spin_ccw') {
      const r = role === 'outgoing' ? -p * 180 : 180 - p * 180;
      const s = role === 'outgoing' ? 1 - p : p;
      el.style.opacity = role === 'outgoing' ? (1 - p) : p;
      el.style.transform += ` rotate(${r}deg) scale(${s})`;
    } else if (id === 'wipe_left') {
      const w = role === 'outgoing' ? p * 100 : (1 - p) * 100;
      el.style.clipPath = role === 'outgoing' ? `inset(0 ${w}% 0 0)` : `inset(0 0 0 ${w}%)`;
    } else if (id === 'wipe_right') {
      const w = role === 'outgoing' ? p * 100 : (1 - p) * 100;
      el.style.clipPath = role === 'outgoing' ? `inset(0 0 0 ${w}%)` : `inset(0 ${w}% 0 0)`;
    } else if (id === 'wipe_up') {
      const w = role === 'outgoing' ? p * 100 : (1 - p) * 100;
      el.style.clipPath = role === 'outgoing' ? `inset(0 0 ${w}% 0)` : `inset(${w}% 0 0 0)`;
    } else if (id === 'wipe_down') {
      const w = role === 'outgoing' ? p * 100 : (1 - p) * 100;
      el.style.clipPath = role === 'outgoing' ? `inset(${w}% 0 0 0)` : `inset(0 0 ${w}% 0)`;
    } else if (id === 'split_h') {
      const w = role === 'outgoing' ? p * 50 : (1 - p) * 50;
      el.style.clipPath = `inset(${w}% 0 ${w}% 0)`;
    } else if (id === 'split_v') {
      const w = role === 'outgoing' ? p * 50 : (1 - p) * 50;
      el.style.clipPath = `inset(0 ${w}% 0 ${w}%)`;
    } else if (id === 'mask_circle') {
      const rad = role === 'outgoing' ? Math.max(0, (1 - p) * 100) : p * 150;
      el.style.clipPath = `circle(${rad}% at 50% 50%)`;
    } else if (id === 'mask_diamond') {
      const w = role === 'outgoing' ? p * 50 : (1 - p) * 50;
      el.style.clipPath = `polygon(50% ${w}%, ${100 - w}% 50%, 50% ${100 - w}%, ${w}% 50%)`;
    } else if (id === 'wave_warp') {
      el.style.opacity = role === 'outgoing' ? (1 - p) : p;
      const s = Math.sin(p * Math.PI * 4) * 20;
      const b = role === 'outgoing' ? p * 8 : (1 - p) * 8;
      el.style.filter += ` skewX(${s}deg) blur(${b}px)`;
    } else if (id === 'glitch_cut') {
      const show = role === 'outgoing' ? (Math.random() > p) : (Math.random() < p);
      el.style.opacity = show ? 1.0 : 0.0;
      if (role === 'outgoing') {
        el.style.filter += ` hue-rotate(${p * 180}deg)`;
      } else {
        const dx = (Math.random() - 0.5) * 20;
        const dy = (Math.random() - 0.5) * 20;
        el.style.transform += ` translate(${dx}px, ${dy}px)`;
      }
    }
  }

  // ── Frame Rendering ─────────────────────────────────────────────────────────
  function renderFrame(t) {
    if (!engine.projectState) return;
    const items  = engine.projectState.items || [];
    const tracks = engine.projectState.tracks || [];
    const trackMap = new Map(tracks.map(tr => [tr.id, tr]));

    // Compute active transitions at playhead t
    const activeTransitions = computeActiveTransitions(items, t);

    items.forEach(item => {
      const track = trackMap.get(item.trackId);
      if (!track) return;

      const transActive = activeTransitions[item.id];
      const isOutgoing = transActive && transActive.role === 'outgoing';

      const visible = (
        track.visible !== false &&
        ((t >= item.start && t < item.start + item.duration) || isOutgoing)
      );

      if (item.type === 'audio') {
        syncAudioItem(item, t, visible);
        return;
      }

      const entry = engine.layerPool.get(item.id);
      if (!entry) return;
      const el = entry.el;

      if (!visible) {
        el.style.display = 'none';
        if (entry.canvasEl) {
          entry.canvasEl.style.display = 'none';
        }
        return;
      }

      // ── MASK & CUTOUT INTEGRATIONS ──
      // Dynamic cutout url non-destructive replacement
      if (item.cutoutUrl) {
        if (el.tagName === 'IMG' || el.tagName === 'VIDEO') {
          if (el.src !== item.cutoutUrl && !el.src.endsWith(item.cutoutUrl)) {
            el.src = item.cutoutUrl;
          }
        }
      } else {
        if (el.tagName === 'IMG' || el.tagName === 'VIDEO') {
          if (item.url && el.src !== item.url && !el.src.endsWith(item.url)) {
            el.src = item.url;
          }
        }
      }

      // Chroma key real-time canvas mapping
      const hasChroma = !!(item.chromaKey && item.chromaKey.enabled);
      if (hasChroma) {
        el.style.display = 'none';
        if (!entry.canvasEl) {
          entry.canvasEl = document.createElement('canvas');
          entry.canvasEl.className = el.className;
          el.parentNode.insertBefore(entry.canvasEl, el);
          entry.canvasEl.style.cursor = 'move';
          entry.canvasEl.style.pointerEvents = 'auto';
          entry.canvasEl.dataset.itemId = item.id;
          entry.canvasEl.addEventListener('pointerdown', (e) => {
            if (window.CapCutEditor && window.CapCutEditor.SelectionManager) {
              window.CapCutEditor.SelectionManager.selectElement(item.id, item.type);
            }
          });
        }
        entry.canvasEl.style.display = '';
        applyItemTransform(entry.canvasEl, item);
        applyChromaKey(el, entry.canvasEl, item.chromaKey.color || '#00ff00', item.chromaKey.similarity ?? 30, item.chromaKey.smoothness ?? 10);
      } else {
        if (entry.canvasEl) {
          entry.canvasEl.style.display = 'none';
        }
        el.style.display = '';
        applyItemTransform(el, item);
      }

      // Dynamic mask updates
      const kfProps = interpolateKeyframeProperties(item, t - item.start);
      updateSvgMask(item, kfProps.mask);

      // If active in a transition, override transforms/opacity/clipPaths
      if (transActive) {
        applyTransitionStyles(el, transActive.role, transActive.progress, transActive.transition);
      }

      // Sync video position within source and apply audio properties
      if (item.type === 'video' && el.tagName === 'VIDEO') {
        el.muted = engine.muted || track.muted || item.muted === true;
        el.volume = calculateAudioVolume(item, t) * engine.volume;

        // Frozen outgoing partner stays on last frame
        let relT = t - item.start;
        if (isOutgoing) {
          relT = item.duration;
        }

        let sourceOffset;
        if (item.freezeTime !== undefined) {
          sourceOffset = item.freezeTime;
        } else if (item.reversed === true) {
          const start = item.sourceStart ?? 0;
          const end = item.sourceEnd ?? item.duration;
          sourceOffset = end - relT * (item.speed ?? 1);
          sourceOffset = Math.max(start, Math.min(end, sourceOffset));
        } else {
          sourceOffset = Math.max(0, relT * (item.speed ?? 1) + (item.sourceStart ?? 0));
        }

        // Seek video if not playing master, OR if paused
        if (el !== engine.masterVideoEl || !engine.isPlaying) {
          if (Math.abs(el.currentTime - sourceOffset) > 0.15) {
            try { el.currentTime = sourceOffset; } catch (e) {}
          }
          if (engine.isPlaying && el.paused) {
            el.play().catch(() => {});
          } else if (!engine.isPlaying && !el.paused) {
            el.pause();
          }
        }
      }
    });
  }

  function syncAudioItem(item, t, visible) {
    const audio = engine.audioPool.get(item.id);
    if (!audio) return;

    const trackMap = new Map((engine.projectState?.tracks || []).map(tr => [tr.id, tr]));
    const track = trackMap.get(item.trackId);
    audio.muted = engine.muted || (track && track.muted) || item.muted === true;

    if (visible && engine.isPlaying) {
      let sourceOffset;
      if (item.reversed === true) {
        const start = item.sourceStart ?? 0;
        const end = item.sourceEnd ?? item.duration;
        sourceOffset = end - (t - item.start) * (item.speed ?? 1);
        sourceOffset = Math.max(start, Math.min(end, sourceOffset));
      } else {
        sourceOffset = Math.max(0, (t - item.start) * (item.speed ?? 1) + (item.sourceStart ?? 0));
      }

      if (audio.paused) {
        audio.currentTime = sourceOffset;
        audio.play().catch(() => {});
      } else if (Math.abs(audio.currentTime - sourceOffset) > 0.5) {
        audio.currentTime = sourceOffset;
      }
      audio.volume = calculateAudioVolume(item, t) * engine.volume;
    } else {
      if (!audio.paused) audio.pause();
    }
  }

  // ── rAF Tick Loop ───────────────────────────────────────────────────────────
  function tick(rafTime) {
    if (!engine.isPlaying) return;

    if (engine.masterVideoEl && !engine.masterVideoEl.paused) {
      // Derive time from master video element
      const masterItem = engine.projectState?.items?.find(i => i.id === engine.masterVideoItemId);
      if (masterItem) {
        const sourceOffset = engine.masterVideoEl.currentTime - (masterItem.sourceStart ?? 0);
        engine.currentTime = masterItem.start + sourceOffset / (masterItem.speed ?? 1);
      }
    } else {
      // Fallback: wall-clock increment (for image-only or audio-only projects)
      if (engine.lastRAFTime !== null) {
        const delta = (rafTime - engine.lastRAFTime) / 1000;
        engine.currentTime += delta;
      }
    }

    engine.lastRAFTime = rafTime;

    if (engine.currentTime >= engine.duration) {
      engine.currentTime = engine.duration;
      renderFrame(engine.currentTime);
      updateSeekBar();
      updateTimecodeDisplay();
      pauseInternal();
      return;
    }

    renderFrame(engine.currentTime);
    updateSeekBar();
    updateTimecodeDisplay();
    updateTimelineBridge();

    engine.rafId = requestAnimationFrame(tick);
  }

  // ── Playback Controls ───────────────────────────────────────────────────────
  function play() {
    if (engine.currentTime >= engine.duration) {
      engine.currentTime = 0;
      seekAllMedia(0);
    }
    engine.isPlaying = true;
    engine.lastRAFTime = null;

    if (engine.masterVideoEl) {
      const p = engine.masterVideoEl.play();
      if (p !== undefined) {
        p.catch(err => {
          if (err.name === 'NotAllowedError') {
            console.warn('[PreviewEngine] Unmuted play blocked by browser, retrying muted:', err);
            engine.masterVideoEl.muted = true;
            engine.muted = true;
            updateMuteBtn();
            engine.masterVideoEl.play().catch(e => {
              console.error('[PreviewEngine] Play failed:', e);
              pauseInternal();
            });
          } else if (err.name !== 'AbortError') {
            console.error('[PreviewEngine] Play failed:', err);
            pauseInternal();
          }
        });
      }
    }

    setPlayBtnState(true);
    engine.rafId = requestAnimationFrame(tick);
  }

  function pause() {
    pauseInternal();
  }

  function pauseInternal() {
    engine.isPlaying = false;
    if (engine.rafId) {
      cancelAnimationFrame(engine.rafId);
      engine.rafId = null;
    }

    // Pause all video layers
    engine.layerPool.forEach(entry => {
      if (entry.el.tagName === 'VIDEO') entry.el.pause();
    });

    // Pause all audio items
    engine.audioPool.forEach(audio => audio.pause());

    setPlayBtnState(false);
  }

  function toggle() {
    if (engine.isPlaying) pause();
    else play();
  }

  function seek(t) {
    t = Math.max(0, Math.min(engine.duration, t));
    engine.currentTime = t;
    engine.lastRAFTime = null;

    seekAllMedia(t);
    renderFrame(t);
    updateSeekBar();
    updateTimecodeDisplay();
    updateTimelineBridge();
  }

  function seekAllMedia(t) {
    if (!engine.projectState) return;
    const items = engine.projectState.items || [];
    items.forEach(item => {
      if (item.type === 'video') {
        const entry = engine.layerPool.get(item.id);
        if (!entry || entry.el.tagName !== 'VIDEO') return;
        const sourceOffset = (t - item.start) * (item.speed ?? 1) + (item.sourceStart ?? 0);
        if (t >= item.start && t < item.start + item.duration) {
          try { entry.el.currentTime = Math.max(0, sourceOffset); } catch (e) {}
        }
      } else if (item.type === 'audio') {
        const audio = engine.audioPool.get(item.id);
        if (!audio) return;
        if (t >= item.start && t < item.start + item.duration) {
          const offset = (t - item.start) * (item.speed ?? 1) + (item.sourceStart ?? 0);
          try { audio.currentTime = Math.max(0, offset); } catch (e) {}
        }
      }
    });
  }

  function setMuted(muted) {
    engine.muted = muted;
    engine.layerPool.forEach(entry => {
      if (entry.el.tagName === 'VIDEO') entry.el.muted = muted;
    });
    engine.audioPool.forEach(audio => { audio.muted = muted; });
    updateMuteBtn();
  }

  function setVolume(v) {
    engine.volume = Math.max(0, Math.min(1, v));
    engine.layerPool.forEach(entry => {
      if (entry.el.tagName === 'VIDEO') entry.el.volume = engine.volume;
    });
    if (engine.projectState) {
      (engine.projectState.items || []).forEach(item => {
        if (item.type === 'audio') {
          const audio = engine.audioPool.get(item.id);
          if (audio) audio.volume = Math.min(1, ((item.volume ?? 100) / 100) * engine.volume);
        }
      });
    }
  }

  function setFitMode(mode) {
    engine.fitMode = mode;
    engine.layerPool.forEach(entry => {
      if (entry.el.tagName === 'VIDEO' || entry.el.tagName === 'IMG') {
        entry.el.style.objectFit = (mode === 'cover') ? 'cover' : 'contain';
      }
    });
    if (els.fitBtn) {
      els.fitBtn.title = 'Fit mode: ' + mode;
      els.fitBtn.querySelector('i').className = mode === 'cover'
        ? 'fa-solid fa-crop-alt'
        : 'fa-solid fa-expand';
    }
  }

  // ── UI Updates ──────────────────────────────────────────────────────────────
  function updateSeekBar() {
    if (!els.seekBar || engine.seekBarDragging) return;
    els.seekBar.value = engine.currentTime;
  }

  function updateTimecodeDisplay() {
    const cur = formatTC(engine.currentTime);
    const tot = formatTC(engine.duration);
    if (els.timecode) els.timecode.textContent = cur + ' / ' + tot;
    if (els.timelineTimecode) els.timelineTimecode.textContent = cur + ' / ' + tot;
  }

  function updateTimelineBridge() {
    // Keep the legacy timeline playhead in sync
    if (!els.timelinePlayhead) return;
    // zoom is stored on window.CapCutEditor.State.zoom
    const zoom = window.CapCutEditor?.State?.zoom ?? 25;
    els.timelinePlayhead.style.left = (engine.currentTime * zoom) + 'px';
    // Also update legacy state so inspector/timeline renders correctly
    if (window.CapCutEditor?.State) {
      window.CapCutEditor.State.currentTime = engine.currentTime;
    }
  }

  function setPlayBtnState(playing) {
    const iconClass = playing ? 'fa-solid fa-pause' : 'fa-solid fa-play';
    if (els.playIcon) els.playIcon.className = iconClass;
    if (els.timelinePlayIcon) els.timelinePlayIcon.className = iconClass;
  }

  function updateMuteBtn() {
    if (!els.muteIcon) return;
    els.muteIcon.className = engine.muted
      ? 'fa-solid fa-volume-xmark'
      : 'fa-solid fa-volume-high';
  }

  function formatTC(s) {
    s = Math.max(0, s || 0);
    const m  = Math.floor(s / 60);
    const ss = Math.floor(s % 60);
    const cs = Math.floor((s % 1) * 100);
    return pad(m) + ':' + pad(ss) + '.' + pad(cs);
  }

  function pad(n) { return String(n).padStart(2, '0'); }

  // ── Controls Bar Event Binding ──────────────────────────────────────────────
  function bindControlEvents() {
    // Play/Pause button
    els.playBtn?.addEventListener('click', toggle);

    // Seek bar
    if (els.seekBar) {
      els.seekBar.addEventListener('mousedown', () => { engine.seekBarDragging = true; });
      els.seekBar.addEventListener('touchstart', () => { engine.seekBarDragging = true; });
      els.seekBar.addEventListener('input', () => {
        seek(parseFloat(els.seekBar.value));
      });
      els.seekBar.addEventListener('change', () => {
        engine.seekBarDragging = false;
        seek(parseFloat(els.seekBar.value));
      });
      els.seekBar.addEventListener('mouseup', () => { engine.seekBarDragging = false; });
    }

    // Mute
    els.muteBtn?.addEventListener('click', () => setMuted(!engine.muted));

    // Volume slider
    els.volumeSlider?.addEventListener('input', () => {
      setVolume(parseFloat(els.volumeSlider.value));
    });

    // Fit mode cycle
    const fitModes = ['contain', 'cover'];
    els.fitBtn?.addEventListener('click', () => {
      const next = fitModes[(fitModes.indexOf(engine.fitMode) + 1) % fitModes.length];
      setFitMode(next);
    });

    // Fullscreen
    els.fullscreenBtn?.addEventListener('click', () => {
      const target = els.playerWrapper || document.documentElement;
      if (!document.fullscreenElement) {
        target.requestFullscreen?.() || target.webkitRequestFullscreen?.();
      } else {
        document.exitFullscreen?.() || document.webkitExitFullscreen?.();
      }
    });

    // Spacebar shortcut — play/pause when not in a text input
    document.addEventListener('keydown', (e) => {
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) return;
      if (e.code === 'Space') { e.preventDefault(); toggle(); }
      if (e.code === 'ArrowLeft')  { e.preventDefault(); seek(engine.currentTime - 1/30); }
      if (e.code === 'ArrowRight') { e.preventDefault(); seek(engine.currentTime + 1/30); }
      if (e.code === 'Home') { e.preventDefault(); seek(0); }
      if (e.code === 'End')  { e.preventDefault(); seek(engine.duration); }
    });

    // Fullscreen change events
    document.addEventListener('fullscreenchange', updateFullscreenBtn);
    document.addEventListener('webkitfullscreenchange', updateFullscreenBtn);

    // Timeline play button bridge (existing #capcutPlayBtn still works)
    els.timelinePlayBtn?.addEventListener('click', toggle);

    // Timeline ruler click → seek (delegate to existing capcut_editor.js via state update)
    // PreviewEngine.seek() is wired there
  }

  function updateFullscreenBtn() {
    if (!els.fullscreenBtn) return;
    const icon = els.fullscreenBtn.querySelector('i');
    if (!icon) return;
    icon.className = document.fullscreenElement
      ? 'fa-solid fa-compress'
      : 'fa-solid fa-expand';
  }

  // ── Player Wrapper click to seek ────────────────────────────────────────────
  function bindCanvasSeek() {
    // Click on the canvas background (not on a layer) seeks to that position
    // Only on the bottom strip to avoid interfering with layer drag
    if (!els.layerContainer) return;
    els.layerContainer.addEventListener('dblclick', () => {
      toggle(); // double-click = play/pause
    });
  }

  // ── Aspect Ratio Selector ───────────────────────────────────────────────────
  function bindAspectSelect() {
    const sel = document.getElementById('capcutAspectSelect');
    if (!sel) return;
    sel.addEventListener('change', () => {
      const store = window.StudioProjectStore;
      if (!store) return;
      const newState = JSON.parse(JSON.stringify(store.getState()));

      const ratioMap = {
        '16:9': { w: 1920, h: 1080 },
        '9:16': { w: 1080, h: 1920 },
        '1:1':  { w: 1080, h: 1080 },
        '4:5':  { w: 1080, h: 1350 },
        '21:9': { w: 2560, h: 1080 },
      };

      const ratio = sel.value;
      newState.projectSettings.aspectRatio = ratio;
      if (ratioMap[ratio]) {
        newState.projectSettings.width  = ratioMap[ratio].w;
        newState.projectSettings.height = ratioMap[ratio].h;
      }
      store.setState(newState);
    });
  }

  function updateSvgFilters(item, eff) {
    let svgContainer = document.getElementById('capcutDynamicSvgFilters');
    if (!svgContainer) {
      svgContainer = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svgContainer.id = 'capcutDynamicSvgFilters';
      svgContainer.style.cssText = 'position:absolute; width:0; height:0; pointer-events:none;';
      document.body.appendChild(svgContainer);
    }

    const filterId = `capcutFilter-${item.id}`;
    let filterEl = document.getElementById(filterId);
    if (!filterEl) {
      filterEl = document.createElementNS('http://www.w3.org/2000/svg', 'filter');
      filterEl.id = filterId;
      svgContainer.appendChild(filterEl);
    } else {
      filterEl.innerHTML = '';
    }

    // 1. Sharpness (Convolution Matrix)
    const sharpness = item.colorAdjustment?.sharpness ?? 0;
    if (sharpness > 0) {
      const conv = document.createElementNS('http://www.w3.org/2000/svg', 'feConvolveMatrix');
      conv.setAttribute('order', '3');
      const k = sharpness / 100;
      const matrix = `0 ${-k} 0 ${-k} ${1 + 4*k} ${-k} 0 ${-k} 0`;
      conv.setAttribute('kernelMatrix', matrix);
      filterEl.appendChild(conv);
    }

    // 2. Curves (Component Transfer)
    if (item.colorAdjustment?.curves) {
      const transfer = document.createElementNS('http://www.w3.org/2000/svg', 'feComponentTransfer');
      const curves = item.colorAdjustment.curves;
      ['R', 'G', 'B'].forEach(channel => {
        const func = document.createElementNS('http://www.w3.org/2000/svg', `feFunc${channel}`);
        func.setAttribute('type', 'table');
        const points = curves[channel.toLowerCase()] || [[0,0], [255,255]];
        const tableValues = interpolateCurvePoints(points);
        func.setAttribute('tableValues', tableValues.join(' '));
        transfer.appendChild(func);
      });
      filterEl.appendChild(transfer);
    }

    // 3. Ripple water wave (Glitch/Turbulence displacement)
    if (eff && eff.id === 'ripple_wave') {
      const turb = document.createElementNS('http://www.w3.org/2000/svg', 'feTurbulence');
      turb.setAttribute('type', 'fractalNoise');
      const freq = 0.02 + Math.sin(engine.currentTime * 5) * 0.01;
      turb.setAttribute('baseFrequency', `${freq} 0.08`);
      turb.setAttribute('numOctaves', '1');
      turb.setAttribute('result', 'noise');
      filterEl.appendChild(turb);

      const disp = document.createElementNS('http://www.w3.org/2000/svg', 'feDisplacementMap');
      disp.setAttribute('in', 'SourceGraphic');
      disp.setAttribute('in2', 'noise');
      disp.setAttribute('scale', (eff.intensity * 0.4).toFixed(1));
      disp.setAttribute('xChannelSelector', 'R');
      disp.setAttribute('yChannelSelector', 'G');
      filterEl.appendChild(disp);
    }
  }

  function interpolateCurvePoints(points) {
    const sorted = [...points].sort((a, b) => a[0] - b[0]);
    const values = [];
    for (let i = 0; i <= 10; i++) {
      const x = (i / 10) * 255;
      let y = x;
      if (x <= sorted[0][0]) {
        y = sorted[0][1];
      } else if (x >= sorted[sorted.length - 1][0]) {
        y = sorted[sorted.length - 1][1];
      } else {
        for (let j = 0; j < sorted.length - 1; j++) {
          const ptA = sorted[j];
          const ptB = sorted[j + 1];
          if (x >= ptA[0] && x <= ptB[0]) {
            const range = ptB[0] - ptA[0];
            const factor = range > 0 ? (x - ptA[0]) / range : 0;
            y = ptA[1] + (ptB[1] - ptA[1]) * factor;
            break;
          }
        }
      }
      values.push((y / 255).toFixed(3));
    }
    return values;
  }

  // ── Init ────────────────────────────────────────────────────────────────────
  function init() {
    initDOMRefs();
    if (!els.layerContainer) return; // Not on studio page

    bindControlEvents();
    bindCanvasSeek();
    bindAspectSelect();

    // Subscribe to StudioProjectStore
    const store = window.StudioProjectStore;
    if (store) {
      store.subscribe(onStateChange);
      // Render initial state
      onStateChange(store.getState());
    }

    // Render initial frame at t=0
    renderFrame(0);
    updateTimecodeDisplay();

    console.info('[PreviewEngine] Initialized.');
  }

  document.addEventListener('DOMContentLoaded', init);

  function updateSvgMask(item, maskProps) {
    if (!maskProps || !maskProps.type || maskProps.type === 'none') {
      const entry = engine.layerPool.get(item.id);
      if (entry) {
        entry.el.style.webkitMaskImage = '';
        entry.el.style.maskImage = '';
        if (entry.canvasEl) {
          entry.canvasEl.style.webkitMaskImage = '';
          entry.canvasEl.style.maskImage = '';
        }
      }
      return;
    }

    let svgContainer = document.getElementById('capcutDynamicSvgFilters');
    if (!svgContainer) {
      svgContainer = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svgContainer.id = 'capcutDynamicSvgFilters';
      svgContainer.style.cssText = 'position:absolute; width:0; height:0; pointer-events:none;';
      document.body.appendChild(svgContainer);
    }

    const maskId = `capcutMask-${item.id}`;
    let maskEl = document.getElementById(maskId);
    if (!maskEl) {
      maskEl = document.createElementNS('http://www.w3.org/2000/svg', 'mask');
      maskEl.id = maskId;
      svgContainer.appendChild(maskEl);
    } else {
      maskEl.innerHTML = '';
    }

    const blurFilterId = `capcutMaskBlur-${item.id}`;
    let blurFilterEl = document.getElementById(blurFilterId);
    if (!blurFilterEl) {
      blurFilterEl = document.createElementNS('http://www.w3.org/2000/svg', 'filter');
      blurFilterEl.id = blurFilterId;
      svgContainer.appendChild(blurFilterEl);
    } else {
      blurFilterEl.innerHTML = '';
    }

    const feather = maskProps.feather ?? 0;
    if (feather > 0) {
      const blurNode = document.createElementNS('http://www.w3.org/2000/svg', 'feGaussianBlur');
      blurNode.setAttribute('stdDeviation', (feather * 0.25).toString());
      blurFilterEl.appendChild(blurNode);
    }

    const invert = !!maskProps.invert;
    const bgFill = invert ? '#ffffff' : '#000000';
    const shapeFill = invert ? '#000000' : '#ffffff';

    const bgRect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
    bgRect.setAttribute('x', '-1000');
    bgRect.setAttribute('y', '-1000');
    bgRect.setAttribute('width', '3000');
    bgRect.setAttribute('height', '3000');
    bgRect.setAttribute('fill', bgFill);
    maskEl.appendChild(bgRect);

    const gEl = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    const mx = maskProps.x ?? 50;
    const my = maskProps.y ?? 50;
    const mw = maskProps.width ?? 30;
    const mh = maskProps.height ?? 30;
    const rot = maskProps.rotation ?? 0;

    gEl.setAttribute('transform', `translate(${mx} ${my}) rotate(${rot})`);
    if (feather > 0) {
      gEl.setAttribute('filter', `url(#${blurFilterId})`);
    }

    let shapeNode;
    if (maskProps.type === 'rectangle') {
      shapeNode = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
      shapeNode.setAttribute('x', (-mw / 2).toString());
      shapeNode.setAttribute('y', (-mh / 2).toString());
      shapeNode.setAttribute('width', mw.toString());
      shapeNode.setAttribute('height', mh.toString());
      shapeNode.setAttribute('fill', shapeFill);
    } else if (maskProps.type === 'circle') {
      shapeNode = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      shapeNode.setAttribute('cx', '0');
      shapeNode.setAttribute('cy', '0');
      shapeNode.setAttribute('r', (mw / 2).toString());
      shapeNode.setAttribute('fill', shapeFill);
    } else if (maskProps.type === 'linear') {
      shapeNode = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
      shapeNode.setAttribute('x', '-1000');
      shapeNode.setAttribute('y', '0');
      shapeNode.setAttribute('width', '2000');
      shapeNode.setAttribute('height', '1000');
      shapeNode.setAttribute('fill', shapeFill);
    }

    if (shapeNode) {
      gEl.appendChild(shapeNode);
    }
    maskEl.appendChild(gEl);

    const entry = engine.layerPool.get(item.id);
    if (entry) {
      const maskVal = `url(#${maskId})`;
      entry.el.style.webkitMaskImage = maskVal;
      entry.el.style.maskImage = maskVal;
      if (entry.canvasEl) {
        entry.canvasEl.style.webkitMaskImage = maskVal;
        entry.canvasEl.style.maskImage = maskVal;
      }
    }
  }

  function applyChromaKey(sourceEl, canvasEl, keyColorHex, similarity = 30, smoothness = 10) {
    const ctx = canvasEl.getContext('2d');
    const w = sourceEl.videoWidth || sourceEl.naturalWidth || sourceEl.width || 300;
    const h = sourceEl.videoHeight || sourceEl.naturalHeight || sourceEl.height || 150;
    if (canvasEl.width !== w || canvasEl.height !== h) {
      canvasEl.width = w;
      canvasEl.height = h;
    }
    ctx.drawImage(sourceEl, 0, 0, w, h);
    
    let clean = (keyColorHex || '#00ff00').replace('#', '');
    if (clean.length === 3) clean = clean.split('').map(x => x + x).join('');
    const targetR = parseInt(clean.substring(0, 2), 16) || 0;
    const targetG = parseInt(clean.substring(2, 4), 16) || 0;
    const targetB = parseInt(clean.substring(4, 6), 16) || 0;

    const imgData = ctx.getImageData(0, 0, w, h);
    const data = imgData.data;

    for (let i = 0; i < data.length; i += 4) {
      const r = data[i];
      const g = data[i+1];
      const b = data[i+2];

      const distance = Math.sqrt(
        Math.pow(r - targetR, 2) +
        Math.pow(g - targetG, 2) +
        Math.pow(b - targetB, 2)
      );

      if (distance < similarity) {
        data[i+3] = 0;
      } else if (distance < similarity + smoothness) {
        const factor = (distance - similarity) / smoothness;
        data[i+3] = Math.round(data[i+3] * factor);
      }
    }
    ctx.putImageData(imgData, 0, 0);
  }

  // ── Public API ──────────────────────────────────────────────────────────────
  window.PreviewEngine = {
    play,
    pause,
    seek,
    toggle,
    setMuted,
    setVolume,
    setFitMode,
    onStateChange,
    calculateAudioVolume,
    interpolateKeyframeProperties,
    applyChromaKey,
    updateSvgMask,
    interpolateTrajectoryPoint,
    applyItemTransform,
    get currentTime()  { return engine.currentTime; },
    get duration()     { return engine.duration; },
    get isPlaying()    { return engine.isPlaying; },
    get muted()        { return engine.muted; },
    get volume()       { return engine.volume; },
  };

})();
