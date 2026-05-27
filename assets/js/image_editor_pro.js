/**
 * assets/js/image_editor_pro.js
 * Professional Photoshop-style Image Editor with Advanced Editing Tools
 * Features: Crop, Rotate, Flip, Brightness, Contrast, Saturation, Hue, Blur, Sharpness, Filters, Undo/Redo
 */
(function () {
    'use strict';

    // ── DOM References ──
    const viewport = document.getElementById('imgEditorViewport');
    const mainCanvas = document.getElementById('imgEditorCanvas');
    const cropCanvas = document.getElementById('imgCropOverlay');
    const placeholder = document.getElementById('imgEditorPlaceholder');
    const infobar = document.getElementById('imgEditorInfobar');
    const mediaInput = document.getElementById('studioMediaFileInput');
    const categorySelect = document.getElementById('studioCategorySelect');

    // Crop coordinate inputs
    const cropXInput = document.getElementById('imgCropX');
    const cropYInput = document.getElementById('imgCropY');
    const cropWInput = document.getElementById('imgCropW');
    const cropHInput = document.getElementById('imgCropH');

    // Adjustment sliders
    const brightRange = document.getElementById('imgBrightnessRange');
    const contrastRange = document.getElementById('imgContrastRange');
    const brightLabel = document.getElementById('studioValBright');
    const contrastLabel = document.getElementById('studioValContrast');

    // Tools
    const toolCrop = document.getElementById('imgToolCrop');
    const toolMove = document.getElementById('imgToolMove');
    const toolReset = document.getElementById('imgToolReset');
    const zoomInBtn = document.getElementById('imgZoomIn');
    const zoomOutBtn = document.getElementById('imgZoomOut');
    const zoomFitBtn = document.getElementById('imgZoomFit');
    const zoomLabel = document.getElementById('imgZoomLabel');
    const aspectBtns = document.getElementById('imgAspectBtns');

    // Advanced tool buttons (to be added to UI)
    let toolRotateLeft, toolRotateRight, toolFlipH, toolFlipV;
    let saturationRange, hueRange, blurRange, sharpnessRange, filterSelect;
    let undoBtn, redoBtn, satLabel, hueLabel, blurLabel, sharpnessLabel;

    // Info bar spans
    const infoDims = document.getElementById('imgInfoDimensions');
    const infoCrop = document.getElementById('imgInfoCrop');
    const infoSize = document.getElementById('imgInfoFilesize');

    if (!viewport || !mainCanvas || !cropCanvas) return;

    const ctx = mainCanvas.getContext('2d');
    const cctx = cropCanvas.getContext('2d');

    // ── Editor State ──
    let sourceImg = null;   // HTMLImageElement of the raw upload
    let imgNatW = 0;
    let imgNatH = 0;
    let zoom = 1;
    let panX = 0;
    let panY = 0;
    let activeTool = 'crop'; // 'crop' | 'move'
    let aspectRatio = null;   // null = free, or number like 1, 4/3, 16/9

    // Image Adjustments State
    let adjustments = {
        brightness: 0,      // -100 to 100
        contrast: 0,        // -100 to 100
        saturation: 0,      // -100 to 100
        hue: 0,             // 0 to 360
        blur: 0,            // 0 to 50px
        sharpness: 0,       // -100 to 100
        rotation: 0,        // 0, 90, 180, 270 (in degrees)
        flipH: false,       // horizontal flip
        flipV: false,       // vertical flip
        opacity: 100        // 0 to 100%
    };

    // Crop rect in IMAGE coordinates
    let crop = { x: 0, y: 0, w: 0, h: 0, active: false };

    // Drag state
    let dragging = false;
    let dragType = null;
    let dragStartX = 0;
    let dragStartY = 0;
    let dragOrigCrop = null;
    let dragOrigPan = null;

    // Undo/Redo Stack
    let historyStack = [];
    let historyIndex = -1;

    const HANDLE_SIZE = 8;
    const MIN_CROP = 10;
    const MAX_HISTORY = 20;

    // ── Create Advanced Controls Dynamically ──
    function createAdvancedControls() {
        const container = document.getElementById('imgAdvancedControlsContainer') || document.querySelector('.img-editor-canvas-wrap');
        if (!container) return;

        const advancedHtml = `
            <div class="img-editor-advanced-controls" style="background: rgba(0,0,0,0.3); padding: 1rem; border-radius: 8px; margin-top: 1rem; border: 1px solid rgba(0,243,255,0.1);">
                <div class="row g-2">
                    <!-- Rotation -->
                    <div class="col-6">
                        <button type="button" class="img-editor-tool-btn" id="imgToolRotateL" title="Rotate Left" style="width: 48%; display: inline-block; margin-right: 2%;">
                            <i class="fa-solid fa-rotate-left"></i>
                        </button>
                        <button type="button" class="img-editor-tool-btn" id="imgToolRotateR" title="Rotate Right" style="width: 48%; display: inline-block;">
                            <i class="fa-solid fa-rotate-right"></i>
                        </button>
                    </div>
                    
                    <!-- Flip -->
                    <div class="col-6">
                        <button type="button" class="img-editor-tool-btn" id="imgToolFlipH" title="Flip Horizontal" style="width: 48%; display: inline-block; margin-right: 2%;">
                            <i class="fa-solid fa-arrows-left-right"></i>
                        </button>
                        <button type="button" class="img-editor-tool-btn" id="imgToolFlipV" title="Flip Vertical" style="width: 48%; display: inline-block;">
                            <i class="fa-solid fa-arrows-up-down"></i>
                        </button>
                    </div>
                </div>

                <!-- Saturation -->
                <div class="mt-3">
                    <div class="d-flex justify-content-between text-secondary small mb-1">
                        <span class="text-uppercase" style="font-size:0.65rem;">Saturation</span>
                        <span class="text-info fw-bold" id="studioValSaturation">0%</span>
                    </div>
                    <input type="range" id="imgSaturationRange" class="form-range" min="-100" max="100" value="0">
                </div>

                <!-- Hue -->
                <div class="mt-3">
                    <div class="d-flex justify-content-between text-secondary small mb-1">
                        <span class="text-uppercase" style="font-size:0.65rem;">Hue Shift</span>
                        <span class="text-info fw-bold" id="studioValHue">0°</span>
                    </div>
                    <input type="range" id="imgHueRange" class="form-range" min="0" max="360" value="0">
                </div>

                <!-- Blur -->
                <div class="mt-3">
                    <div class="d-flex justify-content-between text-secondary small mb-1">
                        <span class="text-uppercase" style="font-size:0.65rem;">Blur</span>
                        <span class="text-info fw-bold" id="studioValBlur">0px</span>
                    </div>
                    <input type="range" id="imgBlurRange" class="form-range" min="0" max="50" value="0">
                </div>

                <!-- Sharpness -->
                <div class="mt-3">
                    <div class="d-flex justify-content-between text-secondary small mb-1">
                        <span class="text-uppercase" style="font-size:0.65rem;">Sharpness</span>
                        <span class="text-info fw-bold" id="studioValSharpness">0%</span>
                    </div>
                    <input type="range" id="imgSharpnessRange" class="form-range" min="-100" max="100" value="0">
                </div>

                <!-- Opacity -->
                <div class="mt-3">
                    <div class="d-flex justify-content-between text-secondary small mb-1">
                        <span class="text-uppercase" style="font-size:0.65rem;">Opacity</span>
                        <span class="text-info fw-bold" id="studioValOpacity">100%</span>
                    </div>
                    <input type="range" id="imgOpacityRange" class="form-range" min="0" max="100" value="100">
                </div>

                <!-- Filters -->
                <div class="mt-3">
                    <label class="form-label text-secondary small text-uppercase" style="font-size:0.65rem;">Filters</label>
                    <select id="imgFilterSelect" class="form-select form-control-cyber form-select-sm" style="background: rgba(0,0,0,0.4); border: 1px solid rgba(0,243,255,0.2); color: #fff; padding: 0.4rem;">
                        <option value="none">None</option>
                        <option value="grayscale">Grayscale</option>
                        <option value="sepia">Sepia</option>
                        <option value="invert">Invert</option>
                        <option value="vintage">Vintage</option>
                        <option value="cool">Cool Tone</option>
                        <option value="warm">Warm Tone</option>
                    </select>
                </div>

                <!-- Undo/Redo -->
                <div class="row g-2 mt-3">
                    <div class="col-6">
                        <button type="button" class="btn btn-sm" id="imgUndoBtn" title="Undo" style="width: 100%; background: rgba(0,243,255,0.15); border: 1px solid rgba(0,243,255,0.3); color: #fff; border-radius: 4px;">
                            <i class="fa-solid fa-undo me-1"></i>Undo
                        </button>
                    </div>
                    <div class="col-6">
                        <button type="button" class="btn btn-sm" id="imgRedoBtn" title="Redo" style="width: 100%; background: rgba(0,243,255,0.15); border: 1px solid rgba(0,243,255,0.3); color: #fff; border-radius: 4px;">
                            <i class="fa-solid fa-redo me-1"></i>Redo
                        </button>
                    </div>
                </div>
            </div>
        `;

        const advancedDiv = document.createElement('div');
        advancedDiv.innerHTML = advancedHtml;
        container.appendChild(advancedDiv);

        // Get references to new elements
        toolRotateLeft = document.getElementById('imgToolRotateL');
        toolRotateRight = document.getElementById('imgToolRotateR');
        toolFlipH = document.getElementById('imgToolFlipH');
        toolFlipV = document.getElementById('imgToolFlipV');
        saturationRange = document.getElementById('imgSaturationRange');
        hueRange = document.getElementById('imgHueRange');
        blurRange = document.getElementById('imgBlurRange');
        sharpnessRange = document.getElementById('imgSharpnessRange');
        filterSelect = document.getElementById('imgFilterSelect');
        undoBtn = document.getElementById('imgUndoBtn');
        redoBtn = document.getElementById('imgRedoBtn');
        satLabel = document.getElementById('studioValSaturation');
        hueLabel = document.getElementById('studioValHue');
        blurLabel = document.getElementById('studioValBlur');
        sharpnessLabel = document.getElementById('studioValSharpness');
    }

    // ── Utility: viewport → image coords ──
    function viewportToImage(vx, vy) {
        return {
            x: (vx - panX) / zoom,
            y: (vy - panY) / zoom
        };
    }
    function imageToViewport(ix, iy) {
        return {
            x: ix * zoom + panX,
            y: iy * zoom + panY
        };
    }

    // ── Fit image into viewport ──
    function fitToView() {
        if (!sourceImg) return;
        const vw = viewport.clientWidth;
        const vh = viewport.clientHeight;
        const scaleX = vw / imgNatW;
        const scaleY = vh / imgNatH;
        zoom = Math.min(scaleX, scaleY) * 0.92;
        panX = (vw - imgNatW * zoom) / 2;
        panY = (vh - imgNatH * zoom) / 2;
        updateZoomLabel();
        render();
    }

    function updateZoomLabel() {
        if (zoomLabel) zoomLabel.textContent = Math.round(zoom * 100) + '%';
    }

    // ── Apply Filters & Effects ──
    function applyFiltersToCanvas(canvas) {
        if (!sourceImg) return;

        // Build CSS filter string
        let filterChain = [];

        const bright = adjustments.brightness;
        const contrast = adjustments.contrast;
        const sat = adjustments.saturation;
        const hue = adjustments.hue;
        const blur = adjustments.blur;
        const sharp = adjustments.sharpness;

        if (bright !== 0) filterChain.push(`brightness(${100 + bright}%)`);
        if (contrast !== 0) filterChain.push(`contrast(${100 + contrast}%)`);
        if (sat !== 0) filterChain.push(`saturate(${100 + sat}%)`);
        if (hue !== 0) filterChain.push(`hue-rotate(${hue}deg)`);
        if (blur !== 0) filterChain.push(`blur(${blur}px)`);

        // Note: sharpness and filters require additional canvas processing below
        canvas.style.filter = filterChain.join(' ');
    }

    // ── Render main canvas ──
    function render() {
        if (!sourceImg) return;
        const vw = viewport.clientWidth;
        const vh = viewport.clientHeight;
        mainCanvas.width = vw;
        mainCanvas.height = vh;
        cropCanvas.width = vw;
        cropCanvas.height = vh;

        ctx.clearRect(0, 0, vw, vh);
        ctx.save();

        // Apply transformations (rotation, flip)
        const centerX = panX + (imgNatW * zoom) / 2;
        const centerY = panY + (imgNatH * zoom) / 2;

        ctx.translate(centerX, centerY);
        if (adjustments.rotation !== 0) {
            ctx.rotate((adjustments.rotation * Math.PI) / 180);
        }
        if (adjustments.flipH) ctx.scale(-1, 1);
        if (adjustments.flipV) ctx.scale(1, -1);
        ctx.translate(-centerX, -centerY);

        // Apply CSS filters via canvas globalAlpha and filter
        ctx.globalAlpha = adjustments.opacity / 100;
        applyFiltersToCanvas(mainCanvas);

        ctx.drawImage(sourceImg, panX, panY, imgNatW * zoom, imgNatH * zoom);

        // Apply sharpness via canvas if needed (post-processing)
        if (adjustments.sharpness !== 0) {
            applySharpnessFilter(ctx, vw, vh, adjustments.sharpness);
        }

        // Apply filter presets
        if (adjustments.filterPreset && adjustments.filterPreset !== 'none') {
            applyFilterPreset(ctx, vw, vh, adjustments.filterPreset);
        }

        ctx.restore();

        renderCropOverlay();
        syncCropInputs();
        updateInfobar();
    }

    // ── Apply Sharpness Filter ──
    function applySharpnessFilter(context, width, height, amount) {
        if (amount === 0) return;

        const imageData = context.getImageData(0, 0, width, height);
        const data = imageData.data;
        const kernel = [
            0, -1, 0,
            -1, 5, -1,
            0, -1, 0
        ];

        const normalizedAmount = Math.abs(amount) / 100;
        for (let i = 0; i < data.length; i += 4) {
            // Simplified sharpness: increase contrast
            data[i] = Math.min(255, data[i] + normalizedAmount * (data[i] - 128));
            data[i + 1] = Math.min(255, data[i + 1] + normalizedAmount * (data[i + 1] - 128));
            data[i + 2] = Math.min(255, data[i + 2] + normalizedAmount * (data[i + 2] - 128));
        }
        context.putImageData(imageData, 0, 0);
    }

    // ── Apply Filter Presets ──
    function applyFilterPreset(context, width, height, preset) {
        const imageData = context.getImageData(0, 0, width, height);
        const data = imageData.data;

        for (let i = 0; i < data.length; i += 4) {
            const r = data[i];
            const g = data[i + 1];
            const b = data[i + 2];

            if (preset === 'grayscale') {
                const gray = r * 0.299 + g * 0.587 + b * 0.114;
                data[i] = gray;
                data[i + 1] = gray;
                data[i + 2] = gray;
            } else if (preset === 'sepia') {
                const gray = r * 0.299 + g * 0.587 + b * 0.114;
                data[i] = Math.min(255, gray + 100);
                data[i + 1] = Math.min(255, gray + 50);
                data[i + 2] = gray;
            } else if (preset === 'invert') {
                data[i] = 255 - r;
                data[i + 1] = 255 - g;
                data[i + 2] = 255 - b;
            } else if (preset === 'vintage') {
                data[i] = Math.min(255, r * 1.1);
                data[i + 1] = Math.min(255, g * 1.0);
                data[i + 2] = Math.max(0, b * 0.9);
            } else if (preset === 'cool') {
                data[i] = Math.max(0, r * 0.9);
                data[i + 1] = Math.min(255, g * 1.05);
                data[i + 2] = Math.min(255, b * 1.1);
            } else if (preset === 'warm') {
                data[i] = Math.min(255, r * 1.1);
                data[i + 1] = Math.min(255, g * 1.05);
                data[i + 2] = Math.max(0, b * 0.9);
            }
        }

        context.putImageData(imageData, 0, 0);
    }

    // ── Render crop overlay ──
    function renderCropOverlay() {
        const vw = cropCanvas.width;
        const vh = cropCanvas.height;
        cctx.clearRect(0, 0, vw, vh);
        if (!crop.active) return;

        const tl = imageToViewport(crop.x, crop.y);
        const cx = tl.x;
        const cy = tl.y;
        const cw = crop.w * zoom;
        const ch = crop.h * zoom;

        // Semi-transparent mask outside crop
        cctx.fillStyle = 'rgba(0, 0, 0, 0.55)';
        cctx.fillRect(0, 0, vw, cy);
        cctx.fillRect(0, cy + ch, vw, vh - cy - ch);
        cctx.fillRect(0, cy, cx, ch);
        cctx.fillRect(cx + cw, cy, vw - cx - cw, ch);

        // Rule-of-thirds grid
        cctx.strokeStyle = 'rgba(255, 255, 255, 0.25)';
        cctx.lineWidth = 0.5;
        for (let i = 1; i <= 2; i++) {
            cctx.beginPath();
            cctx.moveTo(cx + (cw * i / 3), cy);
            cctx.lineTo(cx + (cw * i / 3), cy + ch);
            cctx.stroke();
            cctx.beginPath();
            cctx.moveTo(cx, cy + (ch * i / 3));
            cctx.lineTo(cx + cw, cy + (ch * i / 3));
            cctx.stroke();
        }

        // Crop border
        cctx.strokeStyle = '#00f3ff';
        cctx.lineWidth = 1.5;
        cctx.shadowColor = 'rgba(0, 243, 255, 0.6)';
        cctx.shadowBlur = 6;
        cctx.strokeRect(cx, cy, cw, ch);
        cctx.shadowBlur = 0;

        // Handles
        const handles = getHandlePositions(cx, cy, cw, ch);
        cctx.fillStyle = '#ffffff';
        cctx.strokeStyle = '#00f3ff';
        cctx.lineWidth = 1.5;
        for (const h of Object.values(handles)) {
            cctx.beginPath();
            cctx.rect(h.x - HANDLE_SIZE / 2, h.y - HANDLE_SIZE / 2, HANDLE_SIZE, HANDLE_SIZE);
            cctx.fill();
            cctx.stroke();
        }

        // Dimension label
        cctx.fillStyle = 'rgba(0, 0, 0, 0.7)';
        const label = Math.round(crop.w) + ' × ' + Math.round(crop.h);
        cctx.font = '600 11px "Outfit", sans-serif';
        const tw = cctx.measureText(label).width;
        const lx = cx + cw / 2 - tw / 2 - 6;
        const ly = cy + ch + 4;
        cctx.fillRect(lx, ly, tw + 12, 18);
        cctx.fillStyle = '#00f3ff';
        cctx.fillText(label, lx + 6, ly + 13);
    }

    function getHandlePositions(cx, cy, cw, ch) {
        return {
            nw: { x: cx, y: cy },
            n: { x: cx + cw / 2, y: cy },
            ne: { x: cx + cw, y: cy },
            e: { x: cx + cw, y: cy + ch / 2 },
            se: { x: cx + cw, y: cy + ch },
            s: { x: cx + cw / 2, y: cy + ch },
            sw: { x: cx, y: cy + ch },
            w: { x: cx, y: cy + ch / 2 }
        };
    }

    // ── Sync crop inputs ──
    function syncCropInputs() {
        if (!crop.active) {
            if (cropXInput) cropXInput.value = '';
            if (cropYInput) cropYInput.value = '';
            if (cropWInput) cropWInput.value = '';
            if (cropHInput) cropHInput.value = '';
            return;
        }
        if (cropXInput) cropXInput.value = Math.round(crop.x);
        if (cropYInput) cropYInput.value = Math.round(crop.y);
        if (cropWInput) cropWInput.value = Math.round(crop.w);
        if (cropHInput) cropHInput.value = Math.round(crop.h);
    }

    function updateInfobar() {
        if (!sourceImg) return;
        if (infoDims) infoDims.innerHTML = '<i class="fa-solid fa-ruler-combined me-1"></i>' + imgNatW + ' × ' + imgNatH + ' px';
        if (infoCrop) {
            infoCrop.innerHTML = crop.active
                ? '<i class="fa-solid fa-vector-square me-1"></i>' + Math.round(crop.w) + '×' + Math.round(crop.h) + ' @ (' + Math.round(crop.x) + ',' + Math.round(crop.y) + ')'
                : '<i class="fa-solid fa-vector-square me-1"></i>No crop';
        }
    }

    // ── Undo/Redo System ──
    function saveState() {
        // Remove any states after current index
        historyStack = historyStack.slice(0, historyIndex + 1);

        // Save current state
        const state = {
            crop: { ...crop },
            adjustments: { ...adjustments }
        };

        historyStack.push(state);
        if (historyStack.length > MAX_HISTORY) {
            historyStack.shift();
        }
        historyIndex = historyStack.length - 1;
        updateUndoRedoButtons();
    }

    function undo() {
        if (historyIndex > 0) {
            historyIndex--;
            restoreState(historyStack[historyIndex]);
        }
    }

    function redo() {
        if (historyIndex < historyStack.length - 1) {
            historyIndex++;
            restoreState(historyStack[historyIndex]);
        }
    }

    function restoreState(state) {
        crop = { ...state.crop };
        adjustments = { ...state.adjustments };
        updateAllControls();
        render();
        updateUndoRedoButtons();
    }

    function updateUndoRedoButtons() {
        if (undoBtn) undoBtn.disabled = historyIndex <= 0;
        if (redoBtn) redoBtn.disabled = historyIndex >= historyStack.length - 1;
    }

    function updateAllControls() {
        if (brightRange) brightRange.value = adjustments.brightness;
        if (contrastRange) contrastRange.value = adjustments.contrast;
        if (saturationRange) saturationRange.value = adjustments.saturation;
        if (hueRange) hueRange.value = adjustments.hue;
        if (blurRange) blurRange.value = adjustments.blur;
        if (sharpnessRange) sharpnessRange.value = adjustments.sharpness;
        if (filterSelect) filterSelect.value = adjustments.filterPreset || 'none';
        if (brightLabel) brightLabel.textContent = adjustments.brightness + '%';
        if (contrastLabel) contrastLabel.textContent = adjustments.contrast + '%';
        if (satLabel) satLabel.textContent = adjustments.saturation + '%';
        if (hueLabel) hueLabel.textContent = adjustments.hue + '°';
        if (blurLabel) blurLabel.textContent = adjustments.blur + 'px';
        if (sharpnessLabel) sharpnessLabel.textContent = adjustments.sharpness + '%';
    }

    // ── Hit-test handles ──
    function hitTestHandle(mx, my) {
        if (!crop.active) return null;
        const tl = imageToViewport(crop.x, crop.y);
        const handles = getHandlePositions(tl.x, tl.y, crop.w * zoom, crop.h * zoom);
        for (const [name, pos] of Object.entries(handles)) {
            if (Math.abs(mx - pos.x) <= HANDLE_SIZE && Math.abs(my - pos.y) <= HANDLE_SIZE) {
                return name;
            }
        }
        return null;
    }

    function hitTestCropBody(mx, my) {
        if (!crop.active) return false;
        const tl = imageToViewport(crop.x, crop.y);
        return mx >= tl.x && mx <= tl.x + crop.w * zoom &&
            my >= tl.y && my <= tl.y + crop.h * zoom;
    }

    // ── Clamp crop to image bounds ──
    function clampCrop() {
        crop.x = Math.max(0, Math.min(crop.x, imgNatW - MIN_CROP));
        crop.y = Math.max(0, Math.min(crop.y, imgNatH - MIN_CROP));
        crop.w = Math.max(MIN_CROP, Math.min(crop.w, imgNatW - crop.x));
        crop.h = Math.max(MIN_CROP, Math.min(crop.h, imgNatH - crop.y));
    }

    // ── Enforce aspect ratio ──
    function enforceAspect() {
        if (!aspectRatio) return;
        const desiredH = crop.w / aspectRatio;
        if (crop.y + desiredH <= imgNatH) {
            crop.h = desiredH;
        } else {
            crop.h = imgNatH - crop.y;
            crop.w = crop.h * aspectRatio;
        }
        clampCrop();
    }

    // ── Pointer Events ──
    function getPointerPos(e) {
        const rect = viewport.getBoundingClientRect();
        return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    function updateCursor(mx, my) {
        viewport.className = 'img-editor-viewport';
        if (activeTool === 'move') {
            viewport.classList.add('cursor-move');
            return;
        }
        if (!crop.active) return;
        const handle = hitTestHandle(mx, my);
        if (handle) {
            viewport.classList.add('cursor-' + handle + '-resize');
            return;
        }
        if (hitTestCropBody(mx, my)) {
            viewport.classList.add('cursor-crop-move');
        }
    }

    viewport.addEventListener('pointerdown', function (e) {
        if (!sourceImg) return;
        e.preventDefault();
        const pos = getPointerPos(e);
        dragStartX = pos.x;
        dragStartY = pos.y;

        if (activeTool === 'move') {
            dragging = true;
            dragType = 'pan';
            dragOrigPan = { x: panX, y: panY };
            viewport.setPointerCapture(e.pointerId);
            return;
        }

        const handle = hitTestHandle(pos.x, pos.y);
        if (handle) {
            dragging = true;
            dragType = handle;
            dragOrigCrop = { ...crop };
            viewport.setPointerCapture(e.pointerId);
            return;
        }

        if (hitTestCropBody(pos.x, pos.y)) {
            dragging = true;
            dragType = 'move-crop';
            dragOrigCrop = { ...crop };
            viewport.setPointerCapture(e.pointerId);
            return;
        }

        const imgCoord = viewportToImage(pos.x, pos.y);
        if (imgCoord.x >= 0 && imgCoord.x <= imgNatW && imgCoord.y >= 0 && imgCoord.y <= imgNatH) {
            dragging = true;
            dragType = 'new';
            crop.x = imgCoord.x;
            crop.y = imgCoord.y;
            crop.w = 1;
            crop.h = 1;
            crop.active = true;
            dragOrigCrop = { ...crop };
            viewport.setPointerCapture(e.pointerId);
        }
    });

    viewport.addEventListener('pointermove', function (e) {
        const pos = getPointerPos(e);
        if (!dragging) {
            updateCursor(pos.x, pos.y);
            return;
        }

        const dx = pos.x - dragStartX;
        const dy = pos.y - dragStartY;

        if (dragType === 'pan') {
            panX = dragOrigPan.x + dx;
            panY = dragOrigPan.y + dy;
            render();
            return;
        }

        if (dragType === 'new') {
            const startImg = viewportToImage(dragStartX, dragStartY);
            const curImg = viewportToImage(pos.x, pos.y);
            crop.x = Math.min(startImg.x, curImg.x);
            crop.y = Math.min(startImg.y, curImg.y);
            crop.w = Math.abs(curImg.x - startImg.x);
            crop.h = Math.abs(curImg.y - startImg.y);
            clampCrop();
            enforceAspect();
            render();
            return;
        }

        if (dragType === 'move-crop') {
            crop.x = dragOrigCrop.x + dx / zoom;
            crop.y = dragOrigCrop.y + dy / zoom;
            crop.w = dragOrigCrop.w;
            crop.h = dragOrigCrop.h;
            crop.x = Math.max(0, Math.min(crop.x, imgNatW - crop.w));
            crop.y = Math.max(0, Math.min(crop.y, imgNatH - crop.h));
            render();
            return;
        }

        resizeByHandle(dragType, dx / zoom, dy / zoom);
        render();
    });

    viewport.addEventListener('pointerup', function () {
        dragging = false;
        dragType = null;
    });

    // ── Handle-based resize ──
    function resizeByHandle(handle, dxImg, dyImg) {
        const o = dragOrigCrop;
        let nx = o.x, ny = o.y, nw = o.w, nh = o.h;

        if (handle.includes('w')) { nx = o.x + dxImg; nw = o.w - dxImg; }
        if (handle.includes('e') || handle === 'e') { nw = o.w + dxImg; }
        if (handle === 'n' || handle.includes('n')) { ny = o.y + dyImg; nh = o.h - dyImg; }
        if (handle === 's' || handle.includes('s')) { nh = o.h + dyImg; }

        if (nw < MIN_CROP) { nw = MIN_CROP; if (handle.includes('w')) nx = o.x + o.w - MIN_CROP; }
        if (nh < MIN_CROP) { nh = MIN_CROP; if (handle.includes('n')) ny = o.y + o.h - MIN_CROP; }

        crop.x = nx; crop.y = ny; crop.w = nw; crop.h = nh;
        clampCrop();
        if (aspectRatio) enforceAspect();
    }

    // ── Zoom via mouse wheel ──
    viewport.addEventListener('wheel', function (e) {
        if (!sourceImg) return;
        e.preventDefault();
        const pos = getPointerPos(e);
        const before = viewportToImage(pos.x, pos.y);
        const factor = e.deltaY < 0 ? 1.1 : 0.9;
        zoom = Math.max(0.05, Math.min(zoom * factor, 10));
        panX = pos.x - before.x * zoom;
        panY = pos.y - before.y * zoom;
        updateZoomLabel();
        render();
    }, { passive: false });

    // ── Tool buttons ──
    function setTool(tool) {
        activeTool = tool;
        if (toolCrop) toolCrop.classList.toggle('active', tool === 'crop');
        if (toolMove) toolMove.classList.toggle('active', tool === 'move');
        viewport.className = 'img-editor-viewport';
        if (tool === 'move') viewport.classList.add('cursor-move');
        
        // Sync back to Left Sidebar Tool Dock
        const docPointer = document.getElementById('toolDockPointer');
        const docCrop = document.getElementById('toolDockCrop');
        if (docPointer) docPointer.classList.toggle('active', tool === 'move');
        if (docCrop) docCrop.classList.toggle('active', tool === 'crop');
    }

    if (toolCrop) toolCrop.addEventListener('click', function () { setTool('crop'); });
    if (toolMove) toolMove.addEventListener('click', function () { setTool('move'); });

    if (toolReset) toolReset.addEventListener('click', function () {
        crop = { x: 0, y: 0, w: 0, h: 0, active: false };
        adjustments = {
            brightness: 0,
            contrast: 0,
            saturation: 0,
            hue: 0,
            blur: 0,
            sharpness: 0,
            rotation: 0,
            flipH: false,
            flipV: false,
            opacity: 100,
            filterPreset: 'none'
        };
        aspectRatio = null;
        if (aspectBtns) {
            aspectBtns.querySelectorAll('.img-aspect-btn').forEach(function (b) {
                b.classList.toggle('active', b.dataset.ratio === 'free');
            });
        }
        updateAllControls();
        fitToView();
        saveState();
    });

    // Zoom buttons
    if (zoomInBtn) zoomInBtn.addEventListener('click', function () { zoom = Math.min(zoom * 1.25, 10); updateZoomLabel(); render(); });
    if (zoomOutBtn) zoomOutBtn.addEventListener('click', function () { zoom = Math.max(zoom * 0.8, 0.05); updateZoomLabel(); render(); });
    if (zoomFitBtn) zoomFitBtn.addEventListener('click', function () { fitToView(); });

    // Aspect ratio buttons
    if (aspectBtns) {
        aspectBtns.addEventListener('click', function (e) {
            const btn = e.target.closest('.img-aspect-btn');
            if (!btn) return;
            aspectBtns.querySelectorAll('.img-aspect-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            const val = btn.dataset.ratio;
            if (val === 'free') { aspectRatio = null; return; }
            const parts = val.split(':');
            aspectRatio = parseInt(parts[0]) / parseInt(parts[1]);
            if (crop.active) { enforceAspect(); render(); }
        });
    }

    // Adjustment sliders
    function onAdjustmentChange() {
        if (brightRange) {
            adjustments.brightness = parseInt(brightRange.value);
            if (brightLabel) brightLabel.textContent = adjustments.brightness + '%';
        }
        if (contrastRange) {
            adjustments.contrast = parseInt(contrastRange.value);
            if (contrastLabel) contrastLabel.textContent = adjustments.contrast + '%';
        }
        if (saturationRange) {
            adjustments.saturation = parseInt(saturationRange.value);
            if (satLabel) satLabel.textContent = adjustments.saturation + '%';
        }
        if (hueRange) {
            adjustments.hue = parseInt(hueRange.value);
            if (hueLabel) hueLabel.textContent = adjustments.hue + '°';
        }
        if (blurRange) {
            adjustments.blur = parseInt(blurRange.value);
            if (blurLabel) blurLabel.textContent = adjustments.blur + 'px';
        }
        if (sharpnessRange) {
            adjustments.sharpness = parseInt(sharpnessRange.value);
            if (sharpnessLabel) sharpnessLabel.textContent = adjustments.sharpness + '%';
        }
        render();
    }

    if (brightRange) brightRange.addEventListener('input', onAdjustmentChange);
    if (contrastRange) contrastRange.addEventListener('input', onAdjustmentChange);
    if (saturationRange) saturationRange.addEventListener('input', onAdjustmentChange);
    if (hueRange) hueRange.addEventListener('input', onAdjustmentChange);
    if (blurRange) blurRange.addEventListener('input', onAdjustmentChange);
    if (sharpnessRange) sharpnessRange.addEventListener('input', onAdjustmentChange);

    // Rotation and Flip buttons
    if (toolRotateLeft) toolRotateLeft.addEventListener('click', function () {
        adjustments.rotation = (adjustments.rotation - 90 + 360) % 360;
        render();
        saveState();
    });

    if (toolRotateRight) toolRotateRight.addEventListener('click', function () {
        adjustments.rotation = (adjustments.rotation + 90) % 360;
        render();
        saveState();
    });

    if (toolFlipH) toolFlipH.addEventListener('click', function () {
        adjustments.flipH = !adjustments.flipH;
        toolFlipH.classList.toggle('active');
        render();
        saveState();
    });

    if (toolFlipV) toolFlipV.addEventListener('click', function () {
        adjustments.flipV = !adjustments.flipV;
        toolFlipV.classList.toggle('active');
        render();
        saveState();
    });

    // Filter select
    if (filterSelect) {
        filterSelect.addEventListener('change', function () {
            adjustments.filterPreset = this.value;
            render();
            saveState();
        });
    }

    // Undo/Redo buttons
    if (undoBtn) undoBtn.addEventListener('click', undo);
    if (redoBtn) redoBtn.addEventListener('click', redo);

    // Crop coordinate inputs
    function onCropInputChange() {
        if (!sourceImg) return;
        crop.x = parseInt(cropXInput.value) || 0;
        crop.y = parseInt(cropYInput.value) || 0;
        crop.w = parseInt(cropWInput.value) || imgNatW;
        crop.h = parseInt(cropHInput.value) || imgNatH;
        crop.active = crop.w > 0 && crop.h > 0;
        clampCrop();
        render();
        saveState();
    }

    if (cropXInput) cropXInput.addEventListener('change', onCropInputChange);
    if (cropYInput) cropYInput.addEventListener('change', onCropInputChange);
    if (cropWInput) cropWInput.addEventListener('change', onCropInputChange);
    if (cropHInput) cropHInput.addEventListener('change', onCropInputChange);

    // Load image from file input
    function loadImage(file) {
        if (!file || !file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = function () {
            sourceImg = img;
            imgNatW = img.naturalWidth;
            imgNatH = img.naturalHeight;
            crop = { x: 0, y: 0, w: 0, h: 0, active: false };
            adjustments = {
                brightness: 0,
                contrast: 0,
                saturation: 0,
                hue: 0,
                blur: 0,
                sharpness: 0,
                rotation: 0,
                flipH: false,
                flipV: false,
                opacity: 100,
                filterPreset: 'none'
            };

            if (placeholder) placeholder.style.display = 'none';
            if (infobar) infobar.style.display = '';

            // File size
            if (infoSize) {
                var kb = (file.size / 1024).toFixed(1);
                var display = kb > 1024 ? (kb / 1024).toFixed(2) + ' MB' : kb + ' KB';
                infoSize.innerHTML = '<i class="fa-solid fa-weight-hanging me-1"></i>' + display;
            }

            // Initialize history
            historyStack = [];
            historyIndex = -1;
            saveState();

            updateAllControls();
            fitToView();

            URL.revokeObjectURL(url);
        };
        img.src = url;
    }

    if (mediaInput) {
        mediaInput.addEventListener('change', function () {
            var cat = categorySelect ? categorySelect.value : '';
            if (cat === 'process_image' && this.files[0]) {
                loadImage(this.files[0]);
            }
        });
    }

    if (categorySelect) {
        categorySelect.addEventListener('change', function () {
            if (this.value !== 'process_image') {
                sourceImg = null;
                crop.active = false;
                if (placeholder) placeholder.style.display = '';
                if (infobar) infobar.style.display = 'none';
                var vw = viewport.clientWidth, vh = viewport.clientHeight;
                mainCanvas.width = vw;
                mainCanvas.height = vh;
                cropCanvas.width = vw;
                cropCanvas.height = vh;
                ctx.clearRect(0, 0, vw, vh);
                cctx.clearRect(0, 0, vw, vh);
            }
        });
    }

    window.addEventListener('resize', function () { if (sourceImg) render(); });

    // Initialize advanced controls
    setTimeout(createAdvancedControls, 100);
}());
