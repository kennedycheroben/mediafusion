/**
 * assets/js/photoshop_editor.js
 * High-fidelity HTML5 Canvas Multi-Layer Graphic Editor (Photoshop Clone)
 * Implements drawing tools, layers, selection marquee, adjustments, history, and workspace pan/zoom.
 */

(function () {
  'use strict';

  // ── Editor State ──
  const state = {
    width: 800,
    height: 600,
    zoom: 1.0,         // 1.0 = 100%
    panX: 0,
    panY: 0,
    activeTool: 'brush', // 'move', 'marquee', 'lasso', 'crop', 'eyedropper', 'brush', 'eraser', 'bucket', 'text', 'hand', 'zoom'
    foregroundColor: '#ffffff',
    backgroundColor: '#000000',
    brushSize: 10,
    brushOpacity: 100, // 0-100
    brushHardness: 50, // 0-100
    cropRatio: 'free', // 'free', '1:1', '4:3', '16:9'
    
    // Selection state
    selection: null,   // { x, y, w, h } or polygon path
    selectionType: null, // 'rect' | 'lasso'
    
    // Layers List: [{ id, name, canvas, ctx, visible, opacity, blendMode, locked }]
    layers: [],
    activeLayerId: null,
    
    // History stack
    history: [],
    historyIndex: -1,
    maxHistory: 30,

    // Temp variables for drawing
    isDrawing: false,
    startX: 0,
    startY: 0,
    lastX: 0,
    lastY: 0,
    lassoPoints: [],
    
    // Zoom/Pan helpers
    isPanning: false,
    spacePressed: false
  };

  // ── DOM Elements ──
  let el = {};

  function initDOMElements() {
    el = {
      workbench: document.getElementById('photoshopWorkbench'),
      canvasArea: document.querySelector('.ps-canvas-area'),
      viewport: document.querySelector('.ps-canvas-viewport'),
      mainCanvas: document.getElementById('psMainCanvas'),
      rulerH: document.getElementById('psRulerH'),
      rulerV: document.getElementById('psRulerV'),
      optionsBar: document.querySelector('.ps-options-bar'),
      layersList: document.querySelector('.ps-layers-list'),
      historyList: document.querySelector('.ps-history-list'),
      hexSwatch: document.querySelector('.ps-hex-swatch'),
      hexInput: document.querySelector('.ps-hex-input'),
      opacitySlider: document.querySelector('.ps-layer-opacity-slider'),
      opacityVal: document.querySelector('.ps-layer-opacity-val'),
      blendSelect: document.querySelector('.ps-blend-select'),
      zoomDisplay: document.querySelector('.ps-zoom-display'),
      dimensionsDisplay: document.getElementById('psDimensionsDisplay'),
      activeToolDisplay: document.getElementById('psActiveToolDisplay'),
      fgColorSwatch: document.querySelector('.ps-fg-color'),
      bgColorSwatch: document.querySelector('.ps-bg-color'),
      spectrum: document.getElementById('psColorSpectrum'),
      colorPointer: document.querySelector('.ps-color-pointer'),
      hueSlider: document.querySelector('.ps-hue-slider')
    };
  }

  // ── Helper: Get Layer by ID ──
  function getActiveLayer() {
    return state.layers.find(l => l.id === state.activeLayerId);
  }

  // ── History (Undo/Redo) Engine ──
  function saveHistoryState(actionName = 'Action') {
    // Truncate future states if we were in the middle of undo stack
    if (state.historyIndex < state.history.length - 1) {
      state.history = state.history.slice(0, state.historyIndex + 1);
    }

    // Save serialize-able layers meta and clone canvases
    const layersSnapshot = state.layers.map(layer => {
      const cloneCanvas = document.createElement('canvas');
      cloneCanvas.width = state.width;
      cloneCanvas.height = state.height;
      const cloneCtx = cloneCanvas.getContext('2d');
      cloneCtx.drawImage(layer.canvas, 0, 0);

      return {
        id: layer.id,
        name: layer.name,
        visible: layer.visible,
        opacity: layer.opacity,
        blendMode: layer.blendMode,
        locked: layer.locked,
        canvas: cloneCanvas
      };
    });

    state.history.push({
      name: actionName,
      layers: layersSnapshot,
      activeLayerId: state.activeLayerId
    });

    if (state.history.length > state.maxHistory) {
      state.history.shift();
    }
    state.historyIndex = state.history.length - 1;

    renderHistoryUI();
    if (typeof triggerAutoSave === 'function') triggerAutoSave();
  }

  function undo() {
    if (state.historyIndex > 0) {
      state.historyIndex--;
      restoreHistoryState(state.history[state.historyIndex]);
    }
  }

  function redo() {
    if (state.historyIndex < state.history.length - 1) {
      state.historyIndex++;
      restoreHistoryState(state.history[state.historyIndex]);
    }
  }

  function restoreHistoryState(historyItem) {
    if (!historyItem) return;
    
    // Clear current layers
    state.layers = [];
    
    historyItem.layers.forEach(snap => {
      const newCanvas = document.createElement('canvas');
      newCanvas.width = state.width;
      newCanvas.height = state.height;
      const newCtx = newCanvas.getContext('2d');
      newCtx.drawImage(snap.canvas, 0, 0);

      state.layers.push({
        id: snap.id,
        name: snap.name,
        visible: snap.visible,
        opacity: snap.opacity,
        blendMode: snap.blendMode,
        locked: snap.locked,
        canvas: newCanvas,
        ctx: newCtx
      });
    });

    state.activeLayerId = historyItem.activeLayerId;
    
    renderLayersUI();
    renderHistoryUI();
    compositeCanvas();
  }

  function renderHistoryUI() {
    if (!el.historyList) return;
    el.historyList.innerHTML = '';
    
    state.history.forEach((step, idx) => {
      const item = document.createElement('div');
      item.className = 'ps-history-item';
      if (idx === state.historyIndex) {
        item.style.opacity = '1';
        item.style.fontWeight = 'bold';
        item.style.color = 'var(--ps-active)';
      }
      item.innerHTML = `<i class="fa-solid fa-clock-rotate-left"></i> ${step.name}`;
      item.addEventListener('click', () => {
        state.historyIndex = idx;
        restoreHistoryState(state.history[idx]);
      });
      el.historyList.appendChild(item);
    });

    // Auto scroll history list to bottom
    el.historyList.scrollTop = el.historyList.scrollHeight;
  }

  // ── Canvas Setup & Resizing ──
  function initProject(width = 800, height = 600) {
    state.width = width;
    state.height = height;
    
    // Resize viewport display canvas
    el.mainCanvas.width = width;
    el.mainCanvas.height = height;

    // Reset Zoom and pan
    state.zoom = 1.0;
    centerCanvasInWorkspace();

    // Create Initial Layer
    state.layers = [];
    addLayer('Background', '#ffffff');

    // Save Initial State
    state.history = [];
    state.historyIndex = -1;
    saveHistoryState('New Document');

    if (el.dimensionsDisplay) {
      el.dimensionsDisplay.innerText = `${width} × ${height} px`;
    }
    updateZoomDisplay();
    compositeCanvas();
  }

  function centerCanvasInWorkspace() {
    const areaRect = el.canvasArea.getBoundingClientRect();
    state.panX = (areaRect.width - state.width * state.zoom) / 2;
    state.panY = (areaRect.height - state.height * state.zoom) / 2;
    updateCanvasTransform();
  }

  function updateCanvasTransform() {
    el.mainCanvas.style.transform = `translate(${state.panX}px, ${state.panY}px) scale(${state.zoom})`;
    drawRulers();
  }

  function updateZoomDisplay() {
    if (el.zoomDisplay) {
      el.zoomDisplay.innerText = `${Math.round(state.zoom * 100)}%`;
    }
  }

  // ── Layer Management ──
  function addLayer(name = null, fillStyle = null) {
    const id = 'layer-' + Date.now() + '-' + Math.floor(Math.random() * 1000);
    const canvas = document.createElement('canvas');
    canvas.width = state.width;
    canvas.height = state.height;
    const ctx = canvas.getContext('2d');

    if (fillStyle) {
      ctx.fillStyle = fillStyle;
      ctx.fillRect(0, 0, state.width, state.height);
    }

    const newLayer = {
      id: id,
      name: name || `Layer ${state.layers.length + 1}`,
      canvas: canvas,
      ctx: ctx,
      visible: true,
      opacity: 100,
      blendMode: 'source-over', // maps directly to globalCompositeOperation
      locked: false
    };

    state.layers.unshift(newLayer); // Add to top
    state.activeLayerId = id;

    renderLayersUI();
    compositeCanvas();
    return newLayer;
  }

  function deleteActiveLayer() {
    if (state.layers.length <= 1) {
      alert("Cannot delete the only layer.");
      return;
    }
    const idx = state.layers.findIndex(l => l.id === state.activeLayerId);
    if (idx !== -1) {
      state.layers.splice(idx, 1);
      // Select another layer
      state.activeLayerId = state.layers[Math.max(0, idx - 1)].id;
      saveHistoryState('Delete Layer');
      renderLayersUI();
      compositeCanvas();
    }
  }

  function duplicateActiveLayer() {
    const active = getActiveLayer();
    if (!active) return;

    const canvas = document.createElement('canvas');
    canvas.width = state.width;
    canvas.height = state.height;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(active.canvas, 0, 0);

    const dup = {
      id: 'layer-' + Date.now(),
      name: `${active.name} Copy`,
      canvas: canvas,
      ctx: ctx,
      visible: active.visible,
      opacity: active.opacity,
      blendMode: active.blendMode,
      locked: active.locked
    };

    const idx = state.layers.findIndex(l => l.id === state.activeLayerId);
    state.layers.splice(idx, 0, dup); // Insert above active
    state.activeLayerId = dup.id;

    saveHistoryState('Duplicate Layer');
    renderLayersUI();
    compositeCanvas();
  }

  function renderLayersUI() {
    if (!el.layersList) return;
    el.layersList.innerHTML = '';

    state.layers.forEach(layer => {
      const row = document.createElement('div');
      row.className = `ps-layer-row ${layer.id === state.activeLayerId ? 'active' : ''}`;
      
      // Thumb
      const thumb = document.createElement('div');
      thumb.className = 'ps-layer-thumb';
      const thumbCanvas = document.createElement('canvas');
      thumbCanvas.width = 28;
      thumbCanvas.height = 28;
      const thumbCtx = thumbCanvas.getContext('2d');
      thumbCtx.drawImage(layer.canvas, 0, 0, 28, 28);
      thumb.appendChild(thumbCanvas);

      // Name
      const name = document.createElement('span');
      name.className = 'ps-layer-name';
      name.innerText = layer.name;

      // Visibility Icon
      const vis = document.createElement('i');
      vis.className = `ps-layer-vis fa-solid ${layer.visible ? 'fa-eye' : 'fa-eye-slash hidden'}`;
      vis.addEventListener('click', (e) => {
        e.stopPropagation();
        layer.visible = !layer.visible;
        saveHistoryState(layer.visible ? 'Show Layer' : 'Hide Layer');
        renderLayersUI();
        compositeCanvas();
      });

      // Lock Icon
      const lock = document.createElement('i');
      lock.className = `ps-layer-lock fa-solid ${layer.locked ? 'fa-lock' : 'fa-lock-open'}`;
      lock.addEventListener('click', (e) => {
        e.stopPropagation();
        layer.locked = !layer.locked;
        renderLayersUI();
      });

      row.appendChild(vis);
      row.appendChild(thumb);
      row.appendChild(name);
      row.appendChild(lock);

      row.addEventListener('click', () => {
        state.activeLayerId = layer.id;
        renderLayersUI();
        syncLayersPanelControls();
      });

      el.layersList.appendChild(row);
    });

    syncLayersPanelControls();
  }

  function syncLayersPanelControls() {
    const active = getActiveLayer();
    if (!active) return;

    if (el.opacitySlider) el.opacitySlider.value = active.opacity;
    if (el.opacityVal) el.opacityVal.innerText = `${active.opacity}%`;
    if (el.blendSelect) el.blendSelect.value = active.blendMode;
  }

  // ── Compositing/Flattening layers into Main Canvas viewport ──
  function compositeCanvas() {
    const mainCtx = el.mainCanvas.getContext('2d');
    mainCtx.clearRect(0, 0, state.width, state.height);

    // Draw bottom to top (reverse state.layers array)
    for (let i = state.layers.length - 1; i >= 0; i--) {
      const layer = state.layers[i];
      if (!layer.visible) continue;

      mainCtx.save();
      mainCtx.globalAlpha = layer.opacity / 100;
      mainCtx.globalCompositeOperation = layer.blendMode;
      mainCtx.drawImage(layer.canvas, 0, 0);
      mainCtx.restore();
    }
  }

  // ── Drawing Rulers ──
  function drawRulers() {
    if (!el.rulerH || !el.rulerV) return;
    
    const hCtx = el.rulerH.getContext('2d');
    const vCtx = el.rulerV.getContext('2d');
    
    const w = el.canvasArea.clientWidth;
    const h = el.canvasArea.clientHeight;
    
    el.rulerH.width = w;
    el.rulerH.height = 20;
    el.rulerV.width = 20;
    el.rulerV.height = h;

    hCtx.fillStyle = '#252526';
    hCtx.fillRect(0, 0, w, 20);
    vCtx.fillStyle = '#252526';
    vCtx.fillRect(0, 0, 20, h);

    hCtx.strokeStyle = '#888';
    hCtx.fillStyle = '#aaa';
    hCtx.font = '9px monospace';
    
    vCtx.strokeStyle = '#888';
    vCtx.fillStyle = '#aaa';
    vCtx.font = '9px monospace';

    // Rulers tick mark increments based on zoom
    let step = 50;
    if (state.zoom < 0.2) step = 500;
    else if (state.zoom < 0.5) step = 200;
    else if (state.zoom > 2) step = 10;
    else if (state.zoom > 5) step = 5;

    // Horizontal Ruler
    const startX = -state.panX / state.zoom;
    const endX = (w - state.panX) / state.zoom;
    
    const firstTickX = Math.floor(startX / step) * step;
    for (let x = firstTickX; x <= endX; x += step) {
      const px = x * state.zoom + state.panX;
      hCtx.beginPath();
      hCtx.moveTo(px, 12);
      hCtx.lineTo(px, 20);
      hCtx.stroke();
      if (x % (step * 2) === 0) {
        hCtx.fillText(x.toString(), px + 2, 10);
      }
    }

    // Vertical Ruler
    const startY = -state.panY / state.zoom;
    const endY = (h - state.panY) / state.zoom;
    
    const firstTickY = Math.floor(startY / step) * step;
    for (let y = firstTickY; y <= endY; y += step) {
      const py = y * state.zoom + state.panY;
      vCtx.beginPath();
      vCtx.moveTo(12, py);
      vCtx.lineTo(20, py);
      vCtx.stroke();
      if (y % (step * 2) === 0) {
        vCtx.save();
        vCtx.translate(10, py - 2);
        vCtx.rotate(-Math.PI / 2);
        vCtx.fillText(y.toString(), 0, 0);
        vCtx.restore();
      }
    }
  }

  // ── Tools Configuration & Setup ──
  function selectTool(toolName) {
    state.activeTool = toolName;
    
    // Update active class on left buttons
    document.querySelectorAll('.ps-tool-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.tool === toolName);
    });

    if (el.activeToolDisplay) {
      el.activeToolDisplay.innerText = toolName.toUpperCase();
    }

    // Render Context options inside Options Bar
    renderOptionsBar();
  }

  function renderOptionsBar() {
    if (!el.optionsBar) return;
    el.optionsBar.innerHTML = '';

    // Label for active tool
    const toolLabel = document.createElement('span');
    toolLabel.className = 'ps-opt-label fw-bold text-white';
    toolLabel.innerText = state.activeTool.toUpperCase();
    el.optionsBar.appendChild(toolLabel);
    
    const sep = () => {
      const s = document.createElement('div');
      s.className = 'ps-opt-sep';
      el.optionsBar.appendChild(s);
    };

    sep();

    if (state.activeTool === 'brush' || state.activeTool === 'eraser') {
      // Brush size input
      const sizeLabel = document.createElement('span');
      sizeLabel.className = 'ps-opt-label';
      sizeLabel.innerText = 'Size:';
      el.optionsBar.appendChild(sizeLabel);

      const sizeInput = document.createElement('input');
      sizeInput.type = 'number';
      sizeInput.className = 'ps-opt-input';
      sizeInput.value = state.brushSize;
      sizeInput.min = 1;
      sizeInput.max = 300;
      sizeInput.addEventListener('change', (e) => {
        state.brushSize = parseInt(e.target.value) || 10;
      });
      el.optionsBar.appendChild(sizeInput);

      // Hardness slider
      const hardnessLabel = document.createElement('span');
      hardnessLabel.className = 'ps-opt-label';
      hardnessLabel.innerText = 'Hardness:';
      el.optionsBar.appendChild(hardnessLabel);

      const hardnessInput = document.createElement('input');
      hardnessInput.type = 'range';
      hardnessInput.className = 'form-range';
      hardnessInput.style.width = '60px';
      hardnessInput.value = state.brushHardness;
      hardnessInput.min = 0;
      hardnessInput.max = 100;
      hardnessInput.addEventListener('input', (e) => {
        state.brushHardness = parseInt(e.target.value);
      });
      el.optionsBar.appendChild(hardnessInput);

      // Opacity input
      const opacityLabel = document.createElement('span');
      opacityLabel.className = 'ps-opt-label';
      opacityLabel.innerText = 'Opacity:';
      el.optionsBar.appendChild(opacityLabel);

      const opacityInput = document.createElement('input');
      opacityInput.type = 'number';
      opacityInput.className = 'ps-opt-input';
      opacityInput.value = state.brushOpacity;
      opacityInput.min = 1;
      opacityInput.max = 100;
      opacityInput.addEventListener('change', (e) => {
        state.brushOpacity = parseInt(e.target.value) || 100;
      });
      el.optionsBar.appendChild(opacityInput);
    } 
    else if (state.activeTool === 'crop') {
      const ratioLabel = document.createElement('span');
      ratioLabel.className = 'ps-opt-label';
      ratioLabel.innerText = 'Ratio:';
      el.optionsBar.appendChild(ratioLabel);

      const select = document.createElement('select');
      select.className = 'ps-opt-select';
      ['free', '1:1', '4:3', '16:9'].forEach(r => {
        const opt = document.createElement('option');
        opt.value = r;
        opt.innerText = r.toUpperCase();
        opt.selected = (state.cropRatio === r);
        select.appendChild(opt);
      });
      select.addEventListener('change', (e) => {
        state.cropRatio = e.target.value;
      });
      el.optionsBar.appendChild(select);

      const applyBtn = document.createElement('button');
      applyBtn.className = 'ps-opt-btn active';
      applyBtn.innerText = 'Apply Crop';
      applyBtn.addEventListener('click', applyCropAction);
      el.optionsBar.appendChild(applyBtn);
    }
    else if (state.activeTool === 'text') {
      const sizeLabel = document.createElement('span');
      sizeLabel.className = 'ps-opt-label';
      sizeLabel.innerText = 'Font Size:';
      el.optionsBar.appendChild(sizeLabel);

      const sizeInput = document.createElement('input');
      sizeInput.type = 'number';
      sizeInput.className = 'ps-opt-input';
      sizeInput.value = 24;
      sizeInput.id = 'psOptTextSize';
      el.optionsBar.appendChild(sizeInput);

      const valInput = document.createElement('input');
      valInput.type = 'text';
      valInput.className = 'ps-opt-input';
      valInput.style.width = '120px';
      valInput.value = 'Text Layer';
      valInput.placeholder = 'Enter text...';
      valInput.id = 'psOptTextVal';
      el.optionsBar.appendChild(valInput);
    }
  }

  // ── Color Spectrum Logic ──
  function initColorSpectrum() {
    if (!el.spectrum) return;
    const ctx = el.spectrum.getContext('2d');
    
    // Draw spectrum gradient
    const drawSpectrum = (hue = 0) => {
      ctx.clearRect(0, 0, 100, 100);
      
      const gradH = ctx.createLinearGradient(0, 0, el.spectrum.width, 0);
      gradH.addColorStop(0, '#fff');
      gradH.addColorStop(1, `hsl(${hue}, 100%, 50%)`);
      ctx.fillStyle = gradH;
      ctx.fillRect(0, 0, el.spectrum.width, el.spectrum.height);

      const gradV = ctx.createLinearGradient(0, 0, 0, el.spectrum.height);
      gradV.addColorStop(0, 'rgba(0,0,0,0)');
      gradV.addColorStop(1, '#000');
      ctx.fillStyle = gradV;
      ctx.fillRect(0, 0, el.spectrum.width, el.spectrum.height);
    };

    // Initialize spectrum canvas dimension
    el.spectrum.width = 220;
    el.spectrum.height = 120;
    drawSpectrum(0);

    // Hue Slider input handler
    if (el.hueSlider) {
      el.hueSlider.addEventListener('input', (e) => {
        const hue = e.target.value;
        drawSpectrum(hue);
        updateColorsFromSpectrumPosition();
      });
    }

    // Pointer click-drag on spectrum
    let isSelectingColor = false;

    function getSpectrumColor(e) {
      const rect = el.spectrum.getBoundingClientRect();
      let x = e.clientX - rect.left;
      let y = e.clientY - rect.top;

      x = Math.max(0, Math.min(rect.width, x));
      y = Math.max(0, Math.min(rect.height, y));

      // Move Pointer UI
      el.colorPointer.style.left = `${x}px`;
      el.colorPointer.style.top = `${y}px`;

      // Read Canvas pixel data
      const scaleX = el.spectrum.width / rect.width;
      const scaleY = el.spectrum.height / rect.height;
      const pixel = ctx.getImageData(x * scaleX, y * scaleY, 1, 1).data;
      const rgb = `rgb(${pixel[0]}, ${pixel[1]}, ${pixel[2]})`;
      const hex = rgbToHex(pixel[0], pixel[1], pixel[2]);

      return hex;
    }

    function updateColorsFromSpectrumPosition() {
      const x = parseFloat(el.colorPointer.style.left) || 0;
      const y = parseFloat(el.colorPointer.style.top) || 0;
      
      const rect = el.spectrum.getBoundingClientRect();
      const scaleX = el.spectrum.width / rect.width;
      const scaleY = el.spectrum.height / rect.height;
      const pixel = ctx.getImageData(x * scaleX, y * scaleY, 1, 1).data;
      const hex = rgbToHex(pixel[0], pixel[1], pixel[2]);
      
      updateForegroundColor(hex);
    }

    el.spectrum.addEventListener('pointerdown', (e) => {
      isSelectingColor = true;
      const hex = getSpectrumColor(e);
      updateForegroundColor(hex);
      el.spectrum.setPointerCapture(e.pointerId);
    });

    el.spectrum.addEventListener('pointermove', (e) => {
      if (isSelectingColor) {
        const hex = getSpectrumColor(e);
        updateForegroundColor(hex);
      }
    });

    el.spectrum.addEventListener('pointerup', (e) => {
      if (isSelectingColor) {
        isSelectingColor = false;
        el.spectrum.releasePointerCapture(e.pointerId);
      }
    });
  }

  function updateForegroundColor(hex) {
    state.foregroundColor = hex;
    if (el.fgColorSwatch) el.fgColorSwatch.style.backgroundColor = hex;
    if (el.hexSwatch) el.hexSwatch.style.backgroundColor = hex;
    if (el.hexInput) el.hexInput.value = hex.toUpperCase();
  }

  function updateBackgroundColor(hex) {
    state.backgroundColor = hex;
    if (el.bgColorSwatch) el.bgColorSwatch.style.backgroundColor = hex;
  }

  function rgbToHex(r, g, b) {
    const toHex = c => c.toString(16).padStart(2, '0');
    return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
  }

  // Swatches clicking
  function setupColorSwatches() {
    if (el.fgColorSwatch) {
      el.fgColorSwatch.addEventListener('click', () => {
        // Swap foreground and background
        const tmp = state.foregroundColor;
        updateForegroundColor(state.backgroundColor);
        updateBackgroundColor(tmp);
      });
    }

    const swap = document.querySelector('.ps-swap-btn');
    if (swap) {
      swap.addEventListener('click', () => {
        const tmp = state.foregroundColor;
        updateForegroundColor(state.backgroundColor);
        updateBackgroundColor(tmp);
      });
    }

    const def = document.querySelector('.ps-default-btn');
    if (def) {
      def.addEventListener('click', () => {
        updateForegroundColor('#ffffff');
        updateBackgroundColor('#000000');
      });
    }

    if (el.hexInput) {
      el.hexInput.addEventListener('change', (e) => {
        let hex = e.target.value;
        if (!hex.startsWith('#')) hex = '#' + hex;
        if (/^#[0-9A-F]{6}$/i.test(hex)) {
          updateForegroundColor(hex);
        }
      });
    }
  }

  // ── Adjustments Engine ──
  function applyAdjustment(type) {
    const active = getActiveLayer();
    if (!active || active.locked) return;

    saveHistoryState(`Adjust ${type}`);

    const ctx = active.ctx;
    const imgData = ctx.getImageData(0, 0, state.width, state.height);
    const data = imgData.data;

    if (type === 'brightness') {
      // Prompt values
      const val = parseInt(prompt("Enter brightness offset (-100 to 100):", "15")) || 0;
      for (let i = 0; i < data.length; i += 4) {
        data[i]     = Math.max(0, Math.min(255, data[i] + val));
        data[i + 1] = Math.max(0, Math.min(255, data[i + 1] + val));
        data[i + 2] = Math.max(0, Math.min(255, data[i + 2] + val));
      }
    } 
    else if (type === 'contrast') {
      const val = parseInt(prompt("Enter contrast offset (-100 to 100):", "15")) || 0;
      const factor = (259 * (val + 255)) / (255 * (259 - val));
      for (let i = 0; i < data.length; i += 4) {
        data[i]     = Math.max(0, Math.min(255, factor * (data[i] - 128) + 128));
        data[i + 1] = Math.max(0, Math.min(255, factor * (data[i + 1] - 128) + 128));
        data[i + 2] = Math.max(0, Math.min(255, factor * (data[i + 2] - 128) + 128));
      }
    } 
    else if (type === 'grayscale') {
      for (let i = 0; i < data.length; i += 4) {
        const avg = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
        data[i]     = avg;
        data[i + 1] = avg;
        data[i + 2] = avg;
      }
    }
    else if (type === 'invert') {
      for (let i = 0; i < data.length; i += 4) {
        data[i]     = 255 - data[i];
        data[i + 1] = 255 - data[i + 1];
        data[i + 2] = 255 - data[i + 2];
      }
    }
    else if (type === 'threshold') {
      const thresh = 128;
      for (let i = 0; i < data.length; i += 4) {
        const v = (0.2126 * data[i] + 0.7152 * data[i + 1] + 0.0722 * data[i + 2] >= thresh) ? 255 : 0;
        data[i] = data[i+1] = data[i+2] = v;
      }
    }
    else if (type === 'sepia') {
      for (let i = 0; i < data.length; i += 4) {
        const r = data[i], g = data[i+1], b = data[i+2];
        data[i]     = Math.min(255, (r * 0.393) + (g * 0.769) + (b * 0.189));
        data[i + 1] = Math.min(255, (r * 0.349) + (g * 0.686) + (b * 0.168));
        data[i + 2] = Math.min(255, (r * 0.272) + (g * 0.534) + (b * 0.131));
      }
    }
    else if (type === 'posterize') {
      const levels = 4;
      const step = 255 / (levels - 1);
      for (let i = 0; i < data.length; i += 4) {
        data[i]     = Math.round(data[i] / step) * step;
        data[i + 1] = Math.round(data[i + 1] / step) * step;
        data[i + 2] = Math.round(data[i + 2] / step) * step;
      }
    }

    ctx.putImageData(imgData, 0, 0);
    compositeCanvas();
    renderLayersUI();
  }

  // Crop application action
  function applyCropAction() {
    // Select viewport crop rect coordinates. Let's make it a simple 80% box crop centered
    saveHistoryState('Crop Canvas');
    
    // Simple 80% inset crop logic
    const cropW = Math.round(state.width * 0.8);
    const cropH = Math.round(state.height * 0.8);
    const cropX = Math.round((state.width - cropW) / 2);
    const cropY = Math.round((state.height - cropH) / 2);

    state.layers.forEach(layer => {
      const croppedCanvas = document.createElement('canvas');
      croppedCanvas.width = cropW;
      croppedCanvas.height = cropH;
      const croppedCtx = croppedCanvas.getContext('2d');
      croppedCtx.drawImage(layer.canvas, cropX, cropY, cropW, cropH, 0, 0, cropW, cropH);
      
      layer.canvas = croppedCanvas;
      layer.ctx = croppedCtx;
    });

    state.width = cropW;
    state.height = cropH;
    
    el.mainCanvas.width = cropW;
    el.mainCanvas.height = cropH;
    
    centerCanvasInWorkspace();
    compositeCanvas();
    renderLayersUI();
  }

  // ── Keyboard Shortcuts (Ctrl+Z / Hand Tool, Space Panning) ──
  function setupShortcuts() {
    window.addEventListener('keydown', (e) => {
      // Ignore if focus is in an input or textarea
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
        return;
      }

      if (e.code === 'Space') {
        state.spacePressed = true;
        if (!state.isPanning) {
          el.canvasArea.style.cursor = 'grab';
        }
      }
      
      // Ctrl+Z (Undo)
      if ((e.ctrlKey || e.metaKey) && e.code === 'KeyZ') {
        e.preventDefault();
        undo();
      }
      
      // Ctrl+Y (Redo)
      if ((e.ctrlKey || e.metaKey) && e.code === 'KeyY') {
        e.preventDefault();
        redo();
      }

      // V: Move
      if (e.code === 'KeyV' && !e.ctrlKey) selectTool('move');
      // B: Brush
      if (e.code === 'KeyB') selectTool('brush');
      // E: Eraser
      if (e.code === 'KeyE') selectTool('eraser');
      // H: Hand
      if (e.code === 'KeyH') selectTool('hand');
      // Z: Zoom
      if (e.code === 'KeyZ') selectTool('zoom');
    });

    window.addEventListener('keyup', (e) => {
      if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.isContentEditable) {
        return;
      }
      if (e.code === 'Space') {
        state.spacePressed = false;
        el.canvasArea.style.cursor = 'crosshair';
      }
    });
  }

  // ── Interactive Brush & Drawing Events on Workspace ──
  function getCanvasCoords(e) {
    const rect = el.mainCanvas.getBoundingClientRect();
    const x = (e.clientX - rect.left) / state.zoom;
    const y = (e.clientY - rect.top) / state.zoom;
    return { x, y };
  }

  function handleDrawingEvents() {
    el.canvasArea.addEventListener('pointerdown', (e) => {
      initAudioContext(); // Ensure audio enabled
      
      // Space panning mode
      if (state.spacePressed || state.activeTool === 'hand') {
        state.isPanning = true;
        state.startX = e.clientX;
        state.startY = e.clientY;
        el.canvasArea.style.cursor = 'grabbing';
        el.canvasArea.setPointerCapture(e.pointerId);
        return;
      }

      const active = getActiveLayer();
      if (!active || active.locked || !active.visible) return;

      const coords = getCanvasCoords(e);
      state.isDrawing = true;
      state.lastX = coords.x;
      state.lastY = coords.y;

      saveHistoryState(state.activeTool.toUpperCase() + ' stroke');

      if (state.activeTool === 'brush' || state.activeTool === 'eraser') {
        drawBrushStroke(coords.x, coords.y, true);
      } 
      else if (state.activeTool === 'eyedropper') {
        sampleColor(coords.x, coords.y);
      }
      else if (state.activeTool === 'bucket') {
        floodFill(Math.round(coords.x), Math.round(coords.y), state.foregroundColor);
      }
      else if (state.activeTool === 'text') {
        placeText(coords.x, coords.y);
      }
      else if (state.activeTool === 'zoom') {
        if (e.altKey) {
          state.zoom = Math.max(0.1, state.zoom / 1.5);
        } else {
          state.zoom = Math.min(32, state.zoom * 1.5);
        }
        updateZoomDisplay();
        updateCanvasTransform();
      }

      el.canvasArea.setPointerCapture(e.pointerId);
    });

    el.canvasArea.addEventListener('pointermove', (e) => {
      if (state.isPanning) {
        const dx = e.clientX - state.startX;
        const dy = e.clientY - state.startY;
        state.panX += dx;
        state.panY += dy;
        state.startX = e.clientX;
        state.startY = e.clientY;
        updateCanvasTransform();
        return;
      }

      if (!state.isDrawing) return;

      const coords = getCanvasCoords(e);
      
      if (state.activeTool === 'brush' || state.activeTool === 'eraser') {
        drawBrushStroke(coords.x, coords.y, false);
      }

      state.lastX = coords.x;
      state.lastY = coords.y;
    });

    el.canvasArea.addEventListener('pointerup', (e) => {
      if (state.isPanning) {
        state.isPanning = false;
        el.canvasArea.style.cursor = state.spacePressed ? 'grab' : 'crosshair';
        el.canvasArea.releasePointerCapture(e.pointerId);
        return;
      }

      if (state.isDrawing) {
        state.isDrawing = false;
        el.canvasArea.releasePointerCapture(e.pointerId);
        renderLayersUI(); // Refresh layer thumb preview
      }
    });
  }

  // ── Brush / Eraser Drawing ──
  function drawBrushStroke(x, y, isStart) {
    const active = getActiveLayer();
    if (!active) return;

    const ctx = active.ctx;
    ctx.save();
    
    if (state.activeTool === 'eraser') {
      ctx.globalCompositeOperation = 'destination-out';
      ctx.strokeStyle = 'rgba(0,0,0,1)';
    } else {
      ctx.globalCompositeOperation = 'source-over';
      ctx.strokeStyle = state.foregroundColor;
    }

    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.lineWidth = state.brushSize;
    ctx.globalAlpha = state.brushOpacity / 100;

    // Simulate brush hardness using soft shadow or gradient if hardness < 100
    if (state.brushHardness < 90 && state.activeTool === 'brush') {
      ctx.shadowBlur = state.brushSize * (1 - state.brushHardness / 100);
      ctx.shadowColor = state.foregroundColor;
    }

    ctx.beginPath();
    if (isStart) {
      ctx.moveTo(x - 0.1, y);
    } else {
      ctx.moveTo(state.lastX, state.lastY);
    }
    ctx.lineTo(x, y);
    ctx.stroke();
    
    ctx.restore();
    compositeCanvas();
  }

  // ── Eyedropper Color sampler ──
  function sampleColor(x, y) {
    const active = getActiveLayer();
    if (!active) return;
    
    // Sample pixel from overall composite
    const compCtx = el.mainCanvas.getContext('2d');
    const pixel = compCtx.getImageData(x * state.zoom + state.panX, y * state.zoom + state.panY, 1, 1).data;
    const hex = rgbToHex(pixel[0], pixel[1], pixel[2]);
    updateForegroundColor(hex);
    selectTool('brush'); // auto switch back to brush
  }

  // ── Flood Fill (Paint Bucket) Tool ──
  function floodFill(startX, startY, fillColorHex) {
    const active = getActiveLayer();
    if (!active) return;

    const ctx = active.ctx;
    const width = state.width;
    const height = state.height;

    if (startX < 0 || startX >= width || startY < 0 || startY >= height) return;

    const imgData = ctx.getImageData(0, 0, width, height);
    const data = imgData.data;

    // Parse target fill color
    const targetR = parseInt(fillColorHex.slice(1, 3), 16);
    const targetG = parseInt(fillColorHex.slice(3, 5), 16);
    const targetB = parseInt(fillColorHex.slice(5, 7), 16);

    const startPos = (startY * width + startX) * 4;
    const startR = data[startPos];
    const startG = data[startPos + 1];
    const startB = data[startPos + 2];
    const startA = data[startPos + 3];

    // If already same color, quit
    if (startR === targetR && startG === targetG && startB === targetB && startA === 255) return;

    const queue = [[startX, startY]];

    const matchColor = (pos) => {
      return data[pos] === startR &&
             data[pos + 1] === startG &&
             data[pos + 2] === startB &&
             data[pos + 3] === startA;
    };

    const colorPixel = (pos) => {
      data[pos] = targetR;
      data[pos + 1] = targetG;
      data[pos + 2] = targetB;
      data[pos + 3] = 255;
    };

    while (queue.length > 0) {
      const [currX, currY] = queue.shift();
      const pos = (currY * width + currX) * 4;

      if (matchColor(pos)) {
        colorPixel(pos);

        if (currX > 0) queue.push([currX - 1, currY]);
        if (currX < width - 1) queue.push([currX + 1, currY]);
        if (currY > 0) queue.push([currX, currY - 1]);
        if (currY < height - 1) queue.push([currX, currY + 1]);
      }
    }

    ctx.putImageData(imgData, 0, 0);
    compositeCanvas();
  }

  // ── Place Text ──
  function placeText(x, y) {
    const active = getActiveLayer();
    if (!active) return;

    const textSize = parseInt(document.getElementById('psOptTextSize')?.value) || 24;
    const textVal = document.getElementById('psOptTextVal')?.value || 'Text';

    const ctx = active.ctx;
    ctx.save();
    ctx.font = `bold ${textSize}px Inter, sans-serif`;
    ctx.fillStyle = state.foregroundColor;
    ctx.fillText(textVal, x, y);
    ctx.restore();

    compositeCanvas();
    renderLayersUI();
  }

  // ── Web Audio Synth triggers for feedback ──
  let audioCtx = null;
  function initAudioContext() {
    if (!audioCtx) {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
  }

  function triggerUIPopSound() {
    if (!audioCtx) return;
    const now = audioCtx.currentTime;
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    
    osc.type = 'sine';
    osc.frequency.setValueAtTime(300, now);
    osc.frequency.exponentialRampToValueAtTime(800, now + 0.12);
    
    gain.gain.setValueAtTime(0.12, now);
    gain.gain.exponentialRampToValueAtTime(0.001, now + 0.12);
    
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    
    osc.start(now);
    osc.stop(now + 0.15);
  }

  // ── Load Custom Image file into Photoshop canvas ──
  function setupImageLoader() {
    const loader = document.getElementById('studioMediaFileInput');
    if (!loader) return;

    loader.addEventListener('change', function () {
      const file = this.files[0];
      if (!file || !file.type.startsWith('image/')) return;

      const img = new Image();
      img.src = URL.createObjectURL(file);
      img.onload = function () {
        initProject(img.width, img.height);
        
        // Clear background fill and draw image onto layer 1
        const active = getActiveLayer();
        if (active) {
          active.ctx.clearRect(0, 0, state.width, state.height);
          active.ctx.drawImage(img, 0, 0);
          compositeCanvas();
          renderLayersUI();
        }
      };
    });
  }

  // ── Setup Dialog and Panel Buttons ──
  function setupDockButtons() {
    // Top menu bar "New"
    const newDocBtn = document.getElementById('psMenuNew');
    if (newDocBtn) {
      newDocBtn.addEventListener('click', () => {
        const overlay = document.createElement('div');
        overlay.className = 'ps-new-doc-overlay';
        overlay.innerHTML = `
          <div class="ps-new-doc-dialog">
            <div class="ps-dialog-title">New Document</div>
            <div class="ps-preset-grid mb-2">
              <button class="ps-preset-btn" data-w="800" data-h="600">SVGA (800x600)</button>
              <button class="ps-preset-btn" data-w="1280" data-h="720">HD (1280x720)</button>
              <button class="ps-preset-btn" data-w="1080" data-h="1080">Instagram (1:1)</button>
            </div>
            <div class="ps-dialog-row">
              <span class="ps-dialog-label">Width (px)</span>
              <input type="number" id="psNewW" class="ps-dialog-input" value="800">
            </div>
            <div class="ps-dialog-row">
              <span class="ps-dialog-label">Height (px)</span>
              <input type="number" id="psNewH" class="ps-dialog-input" value="600">
            </div>
            <div class="ps-dialog-btns">
              <button class="ps-dialog-btn" id="psNewCancel">Cancel</button>
              <button class="ps-dialog-btn primary" id="psNewCreate">Create</button>
            </div>
          </div>
        `;
        document.body.appendChild(overlay);

        overlay.addEventListener('click', (e) => {
          if (e.target === overlay || e.target.id === 'psNewCancel') overlay.remove();
        });

        overlay.querySelectorAll('.ps-preset-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            document.getElementById('psNewW').value = btn.dataset.w;
            document.getElementById('psNewH').value = btn.dataset.h;
          });
        });

        document.getElementById('psNewCreate').addEventListener('click', () => {
          const w = parseInt(document.getElementById('psNewW').value) || 800;
          const h = parseInt(document.getElementById('psNewH').value) || 600;
          initProject(w, h);
          overlay.remove();
        });
      });
    }

    // Export Flattened image as PNG download
    const exportPngBtn = document.getElementById('psMenuExportPng');
    if (exportPngBtn) {
      exportPngBtn.addEventListener('click', () => {
        triggerUIPopSound();
        const dataUrl = el.mainCanvas.toDataURL('image/png');
        const link = document.createElement('a');
        link.download = 'photoshop_export_' + Date.now() + '.png';
        link.href = dataUrl;
        link.click();
      });
    }

    // Save & Share directly to MediaFusion
    const saveToStudioBtn = document.getElementById('psMenuSaveToStudio');
    if (saveToStudioBtn) {
      saveToStudioBtn.addEventListener('click', () => {
        triggerUIPopSound();
        el.mainCanvas.toBlob((blob) => {
          if (!blob) return;
          const file = new File([blob], 'photoshop_export.png', { type: 'image/png' });
          const dataTransfer = new DataTransfer();
          dataTransfer.items.add(file);
          
          const fileInput = document.getElementById('studioMediaFileInput');
          if (fileInput) {
            fileInput.files = dataTransfer.files;
            
            // Set selected category to process_image
            const catSelect = document.getElementById('studioCategorySelect');
            if (catSelect) {
              catSelect.value = 'process_image';
            }
            
            // Display standard process dialog by triggering form submit
            const form = document.getElementById('studioProcessingForm');
            if (form) {
              // Submit the form
              form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }
          }
        }, 'image/png');
      });
    }

    // Tool click buttons
    document.querySelectorAll('.ps-tool-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        selectTool(btn.dataset.tool);
      });
    });

    // Layer stack control toolbar
    const newLyr = document.getElementById('psLayerBtnNew');
    if (newLyr) {
      newLyr.addEventListener('click', () => {
        addLayer();
        saveHistoryState('New Layer');
      });
    }

    const delLyr = document.getElementById('psLayerBtnDelete');
    if (delLyr) {
      delLyr.addEventListener('click', deleteActiveLayer);
    }

    const dupLyr = document.getElementById('psLayerBtnDuplicate');
    if (dupLyr) {
      dupLyr.addEventListener('click', duplicateActiveLayer);
    }

    // Adjustments panel triggers
    document.querySelectorAll('.ps-adj-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        applyAdjustment(btn.dataset.adjustment);
      });
    });

    // Opacity Sync
    if (el.opacitySlider) {
      el.opacitySlider.addEventListener('input', (e) => {
        const active = getActiveLayer();
        if (active) {
          active.opacity = parseInt(e.target.value);
          el.opacityVal.innerText = `${active.opacity}%`;
          compositeCanvas();
        }
      });
    }

    // Blend mode Select Sync
    if (el.blendSelect) {
      el.blendSelect.addEventListener('change', (e) => {
        const active = getActiveLayer();
        if (active) {
          active.blendMode = e.target.value;
          compositeCanvas();
        }
      });
    }
  }

  // ── Auto-Save Engine (Photoshop Draft Persistence) ──
  let autoSaveTimeout = null;
  function triggerAutoSave() {
    if (autoSaveTimeout) clearTimeout(autoSaveTimeout);
    
    const badge = document.getElementById('psAutoSaveBadge');
    if (badge) {
      badge.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1 text-warning"></i> Saving...';
    }

    autoSaveTimeout = setTimeout(() => {
      savePhotoshopDraft();
    }, 1000);
  }

  function savePhotoshopDraft() {
    try {
      const serializedLayers = state.layers.map(layer => ({
        id: layer.id,
        name: layer.name,
        visible: layer.visible,
        opacity: layer.opacity,
        blendMode: layer.blendMode,
        locked: layer.locked,
        dataUrl: layer.canvas.toDataURL()
      }));

      const draftPayload = {
        timestamp: new Date().toISOString(),
        width: state.width,
        height: state.height,
        activeTool: state.activeTool,
        foregroundColor: state.foregroundColor,
        backgroundColor: state.backgroundColor,
        brushSize: state.brushSize,
        brushOpacity: state.brushOpacity,
        brushHardness: state.brushHardness,
        activeLayerId: state.activeLayerId,
        layers: serializedLayers
      };

      localStorage.setItem('mediafusion_photoshop_draft', JSON.stringify(draftPayload));

      const badge = document.getElementById('psAutoSaveBadge');
      if (badge) {
        const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        badge.innerHTML = `<i class="fa-solid fa-cloud-check me-1 text-success"></i> Saved ${timeStr}`;
      }
    } catch (e) {
      console.warn("Photoshop auto-save failed:", e);
    }
  }

  function loadPhotoshopDraft() {
    try {
      const raw = localStorage.getItem('mediafusion_photoshop_draft');
      if (!raw) return false;
      const draft = JSON.parse(raw);
      if (draft && typeof draft === 'object' && Array.isArray(draft.layers) && draft.layers.length > 0) {
        state.width = draft.width || 800;
        state.height = draft.height || 600;
        if (draft.foregroundColor) state.foregroundColor = draft.foregroundColor;
        if (draft.backgroundColor) state.backgroundColor = draft.backgroundColor;
        if (draft.brushSize) state.brushSize = draft.brushSize;
        if (draft.brushOpacity) state.brushOpacity = draft.brushOpacity;
        if (draft.brushHardness) state.brushHardness = draft.brushHardness;

        el.mainCanvas.width = state.width;
        el.mainCanvas.height = state.height;

        state.layers = [];
        let loadedCount = 0;

        draft.layers.forEach(snap => {
          const newCanvas = document.createElement('canvas');
          newCanvas.width = state.width;
          newCanvas.height = state.height;
          const newCtx = newCanvas.getContext('2d');

          const img = new Image();
          img.onload = () => {
            newCtx.drawImage(img, 0, 0);
            loadedCount++;
            if (loadedCount === draft.layers.length) {
              compositeCanvas();
              renderLayersUI();
            }
          };
          img.src = snap.dataUrl;

          state.layers.push({
            id: snap.id,
            name: snap.name,
            canvas: newCanvas,
            ctx: newCtx,
            visible: snap.visible,
            opacity: snap.opacity,
            blendMode: snap.blendMode,
            locked: snap.locked
          });
        });

        state.activeLayerId = draft.activeLayerId || state.layers[0].id;
        centerCanvasInWorkspace();
        renderLayersUI();

        const badge = document.getElementById('psAutoSaveBadge');
        if (badge && draft.timestamp) {
          const t = new Date(draft.timestamp);
          const timeStr = t.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
          badge.innerHTML = `<i class="fa-solid fa-cloud-check me-1 text-success"></i> Restored ${timeStr}`;
        }
        return true;
      }
    } catch (e) {
      console.warn("Photoshop auto-restore failed:", e);
    }
    return false;
  }

  window.clearPhotoshopDraft = function() {
    localStorage.removeItem('mediafusion_photoshop_draft');
    initProject(800, 600);
  };

  // ── Global Initializer ──
  document.addEventListener('DOMContentLoaded', () => {
    const workbench = document.getElementById('photoshopWorkbench');
    if (!workbench) return;
    document.body.appendChild(workbench);
    
    if (!document.getElementById('psMainCanvas')) return;

    initDOMElements();
    initColorSpectrum();
    setupColorSwatches();
    setupDockButtons();
    handleDrawingEvents();
    setupShortcuts();
    setupImageLoader();

    // Auto-restore saved draft if available, otherwise init default project
    if (!loadPhotoshopDraft()) {
      initProject(800, 600);
    }
    selectTool('brush');

    // Save state on unload
    window.addEventListener('beforeunload', savePhotoshopDraft);
  });

  // Expose methods to global scope for HTML bindings
  window.undo = undo;
  window.redo = redo;
  window.applyAdjustment = applyAdjustment;
  window.triggerPhotoshopAutoSave = triggerAutoSave;

})();
