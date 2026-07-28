<?php
/**
 * MediaFusion - Media Studio & Distribution Dashboard
 * 
 * CORE ARCHITECTURAL FEATURES:
 * 1. Dual Mode Upload System: Direct Upload vs. Advanced Caption & Edit Studio.
 * 2. Precision Video Length Editor: Trims local/uploaded assets based on frames.
 * 3. Light Image Editor: Interactive crop coordinate fields, brightness and contrast sliders.
 * 4. Center-Burned Persistent Caption Engine: Burn text physically into content across all platforms.
 * 5. Direct distribution pathway: Connects processed master copies cleanly with background upload daemons.
 */

declare(strict_types=1);

$pageTitle  = 'Studio — MediaFusion';
$activePage = 'studio';
$extraHead  = '
<script src="https://cdnjs.cloudflare.com/ajax/libs/resumable.js/1.1.0/resumable.min.js"></script>
<style>
    /* Premium Photopea-Style Dark-Neon Workspace */
    :root {
        --bg-darker: #07070a;
        --bg-panel: #0d0d12;
        --border-neon: rgba(0, 243, 255, 0.2);
        --border-neon-glow: rgba(0, 243, 255, 0.45);
        --border-pink: rgba(255, 0, 255, 0.2);
        --border-pink-glow: rgba(255, 0, 255, 0.45);
        --text-primary: #f0f0f5;
        --text-muted: #8e92a2;
    }

    /* Custom workbench layout grid */
    .studio-workbench-grid {
        display: grid;
        grid-template-columns: 70px 1fr 340px;
        gap: 20px;
        align-items: start;
        margin-top: 10px;
    }

    @media (max-width: 991.98px) {
        .studio-workbench-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (min-width: 992px) {
        .studio-workbench-grid {
            height: calc(100vh - 160px);
            overflow: hidden;
            align-items: stretch;
        }
        .workbench-left {
            height: 100%;
            position: relative;
        }
        .workbench-center {
            height: 100%;
            overflow: hidden; /* Sticky/locked view: strictly static center viewport */
            display: flex;
            flex-direction: column;
            gap: 15px;
            min-width: 0;
        }
        .workbench-right {
            height: 100%;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }
        .workbench-right-scroll-container {
            flex: 1;
            overflow-y: auto;
            padding-right: 8px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            scrollbar-width: thin;
            scrollbar-color: rgba(0, 243, 255, 0.25) transparent;
        }
        .workbench-right-scroll-container::-webkit-scrollbar {
            width: 6px;
        }
        .workbench-right-scroll-container::-webkit-scrollbar-thumb {
            background-color: rgba(0, 243, 255, 0.25);
            border-radius: 3px;
        }
    }

    /* Left tool strip style */
    .studio-tool-dock {
        display: flex;
        flex-direction: column;
        gap: 12px;
        background: var(--bg-panel);
        border: 1px solid var(--border-neon);
        border-radius: 8px;
        padding: 15px 8px;
        box-shadow: 0 4px 30px rgba(0, 0, 0, 0.8), 0 0 15px rgba(0, 243, 255, 0.05);
        align-items: center;
        position: sticky;
        top: 100px;
    }

    .tool-dock-btn {
        width: 46px;
        height: 46px;
        border-radius: 8px;
        border: 1px solid transparent;
        background: transparent;
        color: var(--text-muted);
        font-size: 1.3rem;
        display: flex;
        justify-content: center;
        align-items: center;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .tool-dock-btn:hover {
        color: var(--neon-cyan);
        background: rgba(0, 243, 255, 0.08);
        border-color: var(--border-neon-glow);
        box-shadow: 0 0 12px rgba(0, 243, 255, 0.2);
    }

    .tool-dock-btn.active {
        color: var(--neon-cyan);
        background: rgba(0, 243, 255, 0.15);
        border-color: var(--neon-cyan);
        box-shadow: 0 0 15px rgba(0, 243, 255, 0.35);
    }

    /* Center main window stage */
    .workbench-center {
        display: flex;
        flex-direction: column;
        gap: 15px;
        min-width: 0;
    }

    /* Top toolbar */
    .img-editor-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 15px;
        background: var(--bg-panel);
        border: 1px solid var(--border-neon);
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.5);
    }

    /* Canvas wrapping & viewport overrides */
    .img-editor-canvas-wrap {
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid var(--border-neon) !important;
        background: #030305 !important;
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.8) !important;
        position: relative;
    }

    .img-editor-canvas-wrap:hover {
        border-color: var(--border-neon-glow) !important;
    }

    .img-editor-viewport {
        height: 480px;
        position: relative;
        width: 100%;
        overflow: hidden;
        background: #0d0d12;
    }

    /* Right sidebar panels style */
    .workbench-right, .workbench-dist {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .glass-card {
        background: var(--bg-panel) !important;
        border: 1px solid var(--border-neon) !important;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.6) !important;
        border-radius: 10px !important;
        padding: 20px !important;
    }

    .glass-card:hover {
        border-color: var(--border-neon-glow) !important;
    }

    /* Neon glow buttons styling */
    .btn-info {
        background: linear-gradient(135deg, rgba(0, 243, 255, 0.8), rgba(0, 243, 255, 0.5));
        border: 1px solid var(--neon-cyan);
        color: #fff;
        transition: all 0.3s ease;
    }

    .btn-info:hover {
        background: linear-gradient(135deg, var(--neon-cyan), rgba(0, 243, 255, 0.7));
        box-shadow: 0 0 15px var(--border-neon-glow);
    }

    /* Range input neon sliders styling */
    input[type="range"].form-range::-webkit-slider-thumb {
        background: var(--neon-cyan);
        box-shadow: 0 0 10px var(--neon-cyan);
    }
    input[type="range"].form-range::-moz-range-thumb {
        background: var(--neon-cyan);
        box-shadow: 0 0 10px var(--neon-cyan);
    }

    /* Custom simple platform display in panels */
    .platform-switch-label {
        font-size: 0.8rem;
        color: var(--text-muted);
        text-align: center;
        margin-top: 5px;
        font-weight: 500;
    }

    /* Styling adjustments for Caption Studio integrations */
    .studio-tab-selector {
        display: flex;
        gap: 1rem;
        background: rgba(0, 0, 0, 0.4);
        padding: 0.4rem;
        border-radius: 8px;
        border: 1px solid var(--glass-border);
        margin-bottom: 2rem;
    }
    .studio-tab-btn {
        flex: 1;
        background: transparent;
        border: none;
        color: var(--text-secondary);
        font-weight: 600;
        padding: 0.75rem 1.2rem;
        border-radius: 6px;
        text-transform: uppercase;
        font-size: 0.82rem;
        letter-spacing: 1px;
        transition: all 0.3s ease;
    }
    .studio-tab-btn.active {
        color: #fff;
        background: rgba(0, 243, 255, 0.15);
        border: 1px solid rgba(0, 243, 255, 0.3);
        box-shadow: 0 0 10px rgba(0, 243, 255, 0.15);
        text-shadow: 0 0 5px var(--neon-cyan);
    }
    .form-section-header {
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #fff;
        border-bottom: 1px solid rgba(255,255,255,0.06);
        padding-bottom: 0.5rem;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    /* Dual Range Slider Styles */
    .studio-range-input {
        -webkit-appearance: none;
        appearance: none;
        background: transparent;
        border: none;
        outline: none;
    }
    
    .studio-range-input::-webkit-slider-thumb {
        -webkit-appearance: none;
        appearance: none;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: transparent;
        border: none;
        cursor: pointer;
        box-shadow: 0 0 8px rgba(0, 243, 255, 0.6);
    }
    
    .studio-range-input::-moz-range-thumb {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: transparent;
        border: none;
        cursor: pointer;
        box-shadow: 0 0 8px rgba(0, 243, 255, 0.6);
    }
    
    .studio-range-input.studio-range-start::-webkit-slider-thumb {
        background: var(--neon-cyan);
        border: 2px solid #fff;
    }
    
    .studio-range-input.studio-range-end::-webkit-slider-thumb {
        background: var(--neon-magenta);
        border: 2px solid #fff;
    }
    
    .studio-range-input.studio-range-start::-moz-range-thumb {
        background: var(--neon-cyan);
        border: 2px solid #fff;
    }
    
    .studio-range-input.studio-range-end::-moz-range-thumb {
        background: var(--neon-magenta);
        border: 2px solid #fff;
    }

    /* ── WhatsApp-Style Caption Overlay Editor ── */
    .wa-editor-wrap {
        position: relative;
        background: #0d0d0d;
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid rgba(255,255,255,0.12);
        min-height: 480px;
        height: 480px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: text;
    }
    .wa-media-bg {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        z-index: 1;
        display: block;
    }
    .wa-caption-layer {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 20;
    }
    .wa-caption-pill {
        display: flex;
        align-items: center;
        gap: 8px;
        background: rgba(0,0,0,0.55);
        border: 2px solid #25D366;
        border-radius: 4px;
        padding: 6px 14px;
        min-width: 170px;
        max-width: 88%;
        box-shadow: 0 2px 16px rgba(0,0,0,0.6);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        transition: box-shadow 0.2s;
    }
    .wa-caption-pill:focus-within {
        box-shadow: 0 0 0 3px rgba(37,211,102,0.25), 0 2px 16px rgba(0,0,0,0.6);
    }
    .wa-emoji-trigger {
        background: none;
        border: none;
        color: rgba(255,255,255,0.75);
        font-size: 1.3rem;
        cursor: pointer;
        padding: 0 2px;
        line-height: 1;
        flex-shrink: 0;
        transition: transform 0.2s, color 0.2s;
        user-select: none;
    }
    .wa-emoji-trigger:hover { transform: scale(1.18); color: #fff; }
    .wa-caption-field {
        flex: 1;
        background: transparent;
        border: none;
        outline: none;
        color: #fff;
        font-size: 1rem;
        font-weight: 500;
        text-align: center;
        font-family: "Outfit", sans-serif;
        min-width: 80px;
        caret-color: #25D366;
    }
    .wa-caption-field::placeholder {
        color: rgba(255,255,255,0.42);
        font-weight: 400;
        font-size: 0.9rem;
    }
    .wa-placeholder-state {
        position: relative;
        z-index: 5;
        text-align: center;
        pointer-events: none;
    }
    .wa-placeholder-icon {
        opacity: 0.1;
        font-size: 2.8rem;
        color: #fff;
        display: block;
        margin-bottom: 6px;
    }
    .wa-no-file-hint {
        position: absolute;
        bottom: 10px;
        left: 50%;
        transform: translateX(-50%);
        font-size: 0.58rem;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: rgba(255,255,255,0.18);
        white-space: nowrap;
        z-index: 5;
        pointer-events: none;
    }
    .wa-emoji-panel {
        position: absolute;
        bottom: calc(100% + 8px);
        left: 50%;
        transform: translateX(-50%);
        z-index: 40;
        background: rgba(18,18,18,0.97);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 10px;
        padding: 10px 12px;
        display: none;
        flex-wrap: wrap;
        gap: 6px;
        width: 220px;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        box-shadow: 0 8px 30px rgba(0,0,0,0.7);
    }
    .wa-emoji-panel.open { display: flex; }
    .wa-emoji-option {
        cursor: pointer;
        font-size: 1.4rem;
        padding: 4px 6px;
        border-radius: 6px;
        transition: background 0.15s;
        line-height: 1;
        user-select: none;
    }
    .wa-emoji-option:hover { background: rgba(255,255,255,0.1); }
</style>
';

include 'header.php';
?>

<main style="padding-top: 100px; min-height: 100vh;">
    <div class="container py-5">
        <div class="text-center mb-4">
            <h1 class="display-5 text-gradient-cyan">Media Studio</h1>
            <p class="text-secondary">Upload raw assets or edit layouts with custom overlays.</p>
        </div>

        <!-- Mode Tab Selector -->
        <div class="studio-tab-selector max-width-600 mx-auto" style="max-width: 600px;">
            <button class="studio-tab-btn active" id="modeStandardBtn" onclick="toggleStudioMode('standard')">
                <i class="fa-solid fa-cloud-arrow-up me-2"></i>Direct Upload
            </button>
            <button class="studio-tab-btn" id="modeStudioBtn" onclick="toggleStudioMode('studio')">
                <i class="fa-solid fa-wand-magic-sparkles me-2"></i>Studio Editor
            </button>
        </div>

        <!-- WORKSPACE 1: STANDARD DIRECT CHUNKED UPLOAD -->
        <div class="row g-5" id="workspaceStandard">
            <!-- Left Column: Upload Zone & Preview -->
            <div class="col-lg-6">
                <div id="uploadZone" class="upload-zone">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <h3 class="mb-3 text-white">Drag & Drop Video here</h3>
                    <p class="text-secondary mb-4">or</p>
                    <button id="browseFileBtn" class="btn btn-outline-light rounded-pill px-4">Choose File</button>
                </div>

                <!-- Live Preview -->
                <div id="videoPreviewContainer">
                    <video id="videoPreview" controls></video>
                </div>

                <!-- Progress Bar -->
                <div id="uploadProgress" class="progress-cyber">
                    <div id="uploadProgressBar" class="progress-bar-cyber"></div>
                </div>
            </div>

            <!-- Right Column: Metadata & Distribution Switches -->
            <div class="col-lg-6">
                <div class="glass-card">
                    <h4 class="mb-4 text-white">Post Details</h4>
                    
                    <div class="mb-4">
                        <label class="form-label text-secondary text-uppercase" style="font-size: 0.8rem; letter-spacing: 1px;">Title</label>
                        <input type="text" id="videoTitle" class="form-control form-control-cyber" placeholder="Enter an engaging title...">
                    </div>
                    
                    <div class="mb-5">
                        <label class="form-label text-secondary text-uppercase" style="font-size: 0.8rem; letter-spacing: 1px;">Description</label>
                        <textarea id="videoDesc" class="form-control form-control-cyber" rows="4" placeholder="Add details and hashtag strings..."></textarea>
                    </div>

                    <h4 class="mb-4 text-white">Share to Platforms</h4>
                    <div class="platform-switch-group d-flex justify-content-around align-items-center">
                        <!-- YouTube Switch -->
                        <div class="d-flex flex-column align-items-center">
                            <label class="platform-switch">
                                <input type="checkbox" id="platformYT">
                                <span class="slider yt"></span>
                                <i class="fa-brands fa-youtube platform-icon yt"></i>
                            </label>
                            <span class="platform-switch-label">YouTube</span>
                        </div>

                        <!-- TikTok Switch -->
                        <div class="d-flex flex-column align-items-center">
                            <label class="platform-switch">
                                <input type="checkbox" id="platformTT">
                                <span class="slider tt"></span>
                                <i class="fa-brands fa-tiktok platform-icon tt"></i>
                            </label>
                            <span class="platform-switch-label">TikTok</span>
                        </div>

                        <!-- Meta Switch -->
                        <div class="d-flex flex-column align-items-center">
                            <label class="platform-switch">
                                <input type="checkbox" id="platformMeta">
                                <span class="slider meta"></span>
                                <i class="fa-brands fa-meta platform-icon meta"></i>
                            </label>
                            <span class="platform-switch-label">Facebook & Instagram</span>
                        </div>
                    </div>

                    <button id="uploadBtn" class="btn-magnetic w-100 mt-4" style="font-size: 1.1rem; padding: 1rem;">
                        Share Now <i class="fa-solid fa-rocket ms-2"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- WORKSPACE 2: INTERACTIVE CAPTION & EDIT STUDIO (Photopea side-by-side Layout) -->
        <div class="studio-workbench-grid d-none" id="workspaceStudio">
                
            <!-- 1. FAR-LEFT COLUMN: THE TOOL DOCK (Narrow Vertical Bar) -->
            <div class="workbench-left">
                <div class="studio-tool-dock">
                    <button type="button" class="tool-dock-btn active" id="toolDockPointer" title="Select Tool" onclick="selectDockTool('pointer')">
                        <i class="fa-solid fa-arrow-pointer"></i>
                    </button>
                    <button type="button" class="tool-dock-btn" id="toolDockCrop" title="Crop Tool" onclick="selectDockTool('crop')">
                        <i class="fa-solid fa-crop-simple"></i>
                    </button>
                    <button type="button" class="tool-dock-btn" id="toolDockText" title="Caption Tool" onclick="selectDockTool('text')">
                        <i class="fa-solid fa-font"></i>
                    </button>
                    <button type="button" class="tool-dock-btn" id="toolDockAdjust" title="Image Adjustments" onclick="selectDockTool('adjust')">
                        <i class="fa-solid fa-sliders"></i>
                    </button>
                </div>
            </div>

            <!-- 2. MAIN LEFT-CENTER COLUMN: THE LIVE PREVIEW CANVAS (Wide Workspace Column) -->
            <div class="workbench-center">
                <!-- Top bar directly above the image container for quick actions -->
                <div class="img-editor-toolbar">
                    <div class="img-editor-toolbar-left">
                        <button type="button" class="img-editor-tool-btn active" id="imgToolCrop" title="Crop Tool">
                            <i class="fa-solid fa-crop-simple"></i>
                        </button>
                        <button type="button" class="img-editor-tool-btn" id="imgToolMove" title="Move Tool">
                            <i class="fa-solid fa-up-down-left-right"></i>
                        </button>
                        <div class="img-editor-separator"></div>
                        <button type="button" class="img-editor-tool-btn" id="imgToolReset" title="Reset All Adjustments">
                            <i class="fa-solid fa-arrow-rotate-left"></i>
                        </button>
                    </div>
                    <div class="img-editor-toolbar-right">
                        <button type="button" class="img-editor-tool-btn" id="imgZoomOut" title="Zoom Out">
                            <i class="fa-solid fa-magnifying-glass-minus"></i>
                        </button>
                        <span class="img-editor-zoom-label text-white" id="imgZoomLabel">100%</span>
                        <button type="button" class="img-editor-tool-btn" id="imgZoomIn" title="Zoom In">
                            <i class="fa-solid fa-magnifying-glass-plus"></i>
                        </button>
                        <button type="button" class="img-editor-tool-btn" id="imgZoomFit" title="Fit to View">
                            <i class="fa-solid fa-expand"></i>
                        </button>
                    </div>
                </div>

                <!-- Combined Live Studio Viewport Container with floating WhatsApp-style captions -->
                <div class="wa-editor-wrap" id="waEditorWrap">
                    
                    <!-- Video Preview Player (video category only) -->
                    <div class="d-none w-100 h-100" id="studioVideoControlsCenter">
                        <div class="ratio ratio-16x9 bg-black rounded overflow-hidden border border-secondary shadow-lg h-100" id="studioVideoPreviewContainer" style="display:none; position:relative;">
                            <video id="studioVideoPreview" style="width:100%;height:100%;object-fit:contain;"></video>
                        </div>
                        <div id="studioVideoPreviewPlaceholder" class="text-center p-5 rounded border border-dashed border-secondary h-100 d-flex flex-column justify-content-center align-items-center" style="background:rgba(0,0,0,0.3);">
                            <i class="fa-solid fa-video fa-2x text-secondary mb-3" style="opacity:0.4;"></i>
                            <p class="text-secondary small mb-0">Select a video file in the right sidebar to start editing</p>
                        </div>
                    </div>

                    <!-- Interactive Image Editor Canvas (image category only) -->
                    <div class="img-editor-canvas-wrap d-none w-100 h-100" id="imgEditorCanvasWrap">
                        <!-- Canvas viewport -->
                        <div class="img-editor-viewport h-100" id="imgEditorViewport" style="position:relative;">
                            <div class="img-editor-placeholder" id="imgEditorPlaceholder">
                                <i class="fa-solid fa-image fa-3x" style="opacity:0.12;"></i>
                                <p class="text-secondary small mt-3 mb-0">Upload a photo in the right sidebar to start editing</p>
                            </div>
                            <canvas id="imgEditorCanvas"></canvas>
                            <canvas id="imgCropOverlay"></canvas>
                        </div>
                    </div>

                    <!-- Hidden but required dummy elements so studio.js continues to function perfectly without errors -->
                    <video id="waCaptionVideoPreview" class="wa-media-bg" muted loop style="display:none;"></video>
                    <img id="waCaptionImagePreview" class="wa-media-bg" alt="" style="display:none;">
                    <span class="wa-no-file-hint" id="waNoFileHint" style="display:none;"></span>
                    <div class="wa-placeholder-state" id="waPlaceholderState" style="display:none;"></div>
                </div>

                <!-- Canvas Info / Dimensions bar underneath viewport -->
                <div class="img-editor-infobar" id="imgEditorInfobar" style="display:none; margin-top: -10px;">
                    <span id="imgInfoDimensions"><i class="fa-solid fa-ruler-combined me-1"></i>—</span>
                    <span id="imgInfoCrop"><i class="fa-solid fa-vector-square me-1"></i>No crop</span>
                    <span id="imgInfoFilesize"><i class="fa-solid fa-weight-hanging me-1"></i>—</span>
                </div>

                <!-- Hidden inputs carrying the caption value into form submission -->
                <input type="hidden" name="caption" id="captionHiddenInput" form="studioProcessingForm">

                <div class="form-text text-secondary mt-1" style="font-size: 0.68rem; text-align: center;">
                    <i class="fa-solid fa-fire-flame-curved me-1" style="color:#ff6b35;"></i>This text is saved onto the image or video permanently.
                </div>
            </div>

            <!-- 3. RIGHT COLUMN: THE MASTER CONTROLS PANEL (Vertical Stack with Independent Scroll) -->
            <div class="workbench-right">
                <div class="workbench-right-scroll-container">
                    <!-- Form wrapping all editing properties for unified ajax submission -->
                    <form id="studioProcessingForm" onsubmit="executeStudioProcess(event)" class="d-flex flex-column gap-3">
                        
                        <!-- CARD A: Editor Settings -->
                        <div class="glass-card">
                            <div class="form-section-header">
                                <i class="fa-solid fa-circle-nodes text-warning me-2"></i>Editor Settings
                            </div>
                            
                            <!-- Media type selector toggle -->
                            <div class="mb-3">
                                <label class="form-label form-label-cyber text-secondary text-uppercase mb-2" style="font-size:0.72rem;">Media Type</label>
                                <select name="action" id="studioCategorySelect" class="form-select form-control-cyber" onchange="toggleStudioControls(this.value)" required>
                                    <option value="process_video">Video Editing & Captions</option>
                                    <option value="process_image">Image Editing & Captions</option>
                                </select>
                            </div>

                            <!-- Asset file upload -->
                            <div class="mb-2">
                                <label class="form-label form-label-cyber text-secondary text-uppercase mb-2" style="font-size:0.72rem;">Choose File</label>
                                <input type="file" name="media_file" id="studioMediaFileInput" class="form-control form-control-cyber" required>
                            </div>
                        </div>

                        <!-- Video Trimming Control Panel (video only) -->
                        <div id="studioVideoControls" class="glass-card">
                            <div class="form-section-header">
                                <i class="fa-solid fa-scissors text-danger me-2"></i>Trim Video
                            </div>

                            <!-- Dual-Handle Timeline Range Slider -->
                            <div class="mb-2">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label text-secondary text-uppercase" style="font-size:0.65rem;margin:0;">Select Start and End</label>
                                    <div class="d-flex flex-column align-items-end" style="font-size: 0.65rem;">
                                        <div>
                                            <span class="text-info fw-bold" id="studioStartDisplay">0s</span>
                                            <span class="text-secondary ms-1">→</span>
                                            <span class="text-info fw-bold" id="studioEndDisplay">0s</span>
                                        </div>
                                        <span class="text-secondary mt-1" id="studioDurationDisplay">(Duration: 0s)</span>
                                    </div>
                                </div>
                                
                                <!-- Dual Range Slider Container -->
                                <div class="dual-range-slider mb-3" id="studioDualRangeSlider" style="position:relative;height:50px;background:rgba(0,0,0,0.3);border-radius:8px;border:1px solid rgba(0,243,255,0.2);padding:15px 10px;">
                                    <div style="position:relative;height:20px;margin:0;">
                                        <div id="studioRangeTrackFill" style="position:absolute;height:4px;background:linear-gradient(90deg, var(--neon-cyan), var(--neon-magenta));border-radius:2px;top:8px;left:0;right:0;pointer-events:none;z-index:1;"></div>
                                        <input type="range" id="studioStartHandle" class="studio-range-input studio-range-start" min="0" max="100" value="0" style="position:absolute;width:100%;height:100%;top:0;left:0;z-index:5;opacity:0;cursor:pointer;pointer-events:auto;">
                                        <input type="range" id="studioEndHandle" class="studio-range-input studio-range-end" min="0" max="100" value="100" style="position:absolute;width:100%;height:100%;top:0;left:0;z-index:4;opacity:0;cursor:pointer;pointer-events:auto;">
                                        <div id="studioStartThumb" class="range-thumb" style="position:absolute;width:16px;height:16px;background:var(--neon-cyan);border-radius:50%;border:2px solid #fff;top:2px;left:0;transform:translateX(-50%);box-shadow:0 0 8px rgba(0,243,255,0.6);z-index:6;pointer-events:none;transition:box-shadow 0.2s ease;"></div>
                                        <div id="studioEndThumb" class="range-thumb" style="position:absolute;width:16px;height:16px;background:var(--neon-magenta);border-radius:50%;border:2px solid #fff;top:2px;right:0;transform:translateX(50%);box-shadow:0 0 8px rgba(255,0,255,0.6);z-index:6;pointer-events:none;transition:box-shadow 0.2s ease;"></div>
                                    </div>
                                </div>
                                
                                <small class="text-secondary d-block" style="font-size:0.65rem;">Drag handles to choose the part you want to keep.</small>
                            </div>

                            <!-- Hidden inputs to submit with form -->
                            <input type="hidden" name="start_time" id="studioStartTimeInput" value="0">
                            <input type="hidden" name="end_time" id="studioEndTimeInput" value="">
                        </div>

                        <!-- Image Only Properties Panel Stack -->
                        <div id="studioImageControls" class="d-none" style="display: contents;">
                            
                            <!-- CARD B: Crop Settings -->
                            <div class="glass-card mb-3">
                                <div class="form-section-header">
                                    <i class="fa-solid fa-crop text-info me-2"></i>Crop Settings
                                </div>
                                
                                <!-- Aspect Ratio Presets -->
                                <div class="mb-3">
                                    <label class="form-label text-secondary text-uppercase mb-2" style="font-size:0.68rem; letter-spacing:0.5px;">Crop Shape</label>
                                    <div class="img-editor-aspect-btns" id="imgAspectBtns">
                                        <button type="button" class="img-aspect-btn active" data-ratio="free">Free</button>
                                        <button type="button" class="img-aspect-btn" data-ratio="1:1">1:1</button>
                                        <button type="button" class="img-aspect-btn" data-ratio="4:3">4:3</button>
                                        <button type="button" class="img-aspect-btn" data-ratio="16:9">16:9</button>
                                        <button type="button" class="img-aspect-btn" data-ratio="9:16">9:16</button>
                                        <button type="button" class="img-aspect-btn" data-ratio="3:2">3:2</button>
                                    </div>
                                </div>

                                <!-- Precise Crop Coordinate Readouts -->
                                <div class="row g-2">
                                    <div class="col-3">
                                        <label class="form-label text-secondary small text-uppercase" style="font-size:0.6rem;">Left (X)</label>
                                        <input type="number" name="crop_x" id="imgCropX" class="form-control form-control-cyber form-control-sm text-center" placeholder="X" min="0">
                                    </div>
                                    <div class="col-3">
                                        <label class="form-label text-secondary small text-uppercase" style="font-size:0.6rem;">Top (Y)</label>
                                        <input type="number" name="crop_y" id="imgCropY" class="form-control form-control-cyber form-control-sm text-center" placeholder="Y" min="0">
                                    </div>
                                    <div class="col-3">
                                        <label class="form-label text-secondary small text-uppercase" style="font-size:0.6rem;">Width</label>
                                        <input type="number" name="crop_width" id="imgCropW" class="form-control form-control-cyber form-control-sm text-center" placeholder="W" min="1">
                                    </div>
                                    <div class="col-3">
                                        <label class="form-label text-secondary small text-uppercase" style="font-size:0.6rem;">Height</label>
                                        <input type="number" name="crop_height" id="imgCropH" class="form-control form-control-cyber form-control-sm text-center" placeholder="H" min="1">
                                    </div>
                                </div>
                            </div>

                            <!-- CARD C: Color & Light Adjustments -->
                            <div class="glass-card mb-3">
                                <div class="form-section-header">
                                    <i class="fa-solid fa-sliders text-success me-2"></i>Color & Light
                                </div>
                                
                                <!-- Brightness -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between text-secondary small mb-1">
                                        <span class="text-uppercase" style="font-size:0.68rem;">Brightness</span>
                                        <span class="text-info fw-bold" id="studioValBright">0%</span>
                                    </div>
                                    <input type="range" name="brightness" id="imgBrightnessRange" class="form-range" min="-100" max="100" value="0">
                                </div>

                                <!-- Contrast -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between text-secondary small mb-1">
                                        <span class="text-uppercase" style="font-size:0.68rem;">Contrast</span>
                                        <span class="text-info fw-bold" id="studioValContrast">0%</span>
                                    </div>
                                    <input type="range" name="contrast" id="imgContrastRange" class="form-range" min="-100" max="100" value="0">
                                </div>

                                <!-- Container for dynamically added advanced controls -->
                                <div id="imgAdvancedControlsContainer"></div>
                            </div>
                        </div>

                        <!-- Save & Generate Panel -->
                        <div class="glass-card">
                            <div class="form-section-header">
                                <i class="fa-solid fa-check-circle text-info me-2"></i>Save & Generate
                            </div>
                            
                            <button type="submit" class="btn btn-info w-100 py-3 fw-bold text-uppercase" style="border-radius: 6px; box-shadow: 0 0 15px rgba(0, 243, 255, 0.25);">
                                Save Changes <i class="fa-solid fa-floppy-disk ms-2"></i>
                            </button>
                        </div>
                    </form> <!-- CLOSE processing form cleanly before distribution -->

                    <!-- CARD D: Post Details & Share (Distribution Panel stacking neatly at bottom) -->
                    <div class="workbench-dist d-none" id="studioDistributionCard">
                        <div class="glass-card">
                            
                            <!-- Defensive placeholder for JS checks -->
                            <div id="studioPreviewPlaceholder" style="display:none;"></div>
                            
                            <!-- Processing preloader display -->
                            <div id="studioPreviewLoader" class="d-none py-4 text-center">
                                <div class="spinner-border text-info mb-3" style="width: 2.2rem; height: 2.2rem;" role="status"></div>
                                <p class="text-secondary text-uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 2px;">Processing media...</p>
                            </div>

                            <!-- Processed final previews containers inside Card D -->
                            <div id="studioPreviewOutput" class="d-none text-center">
                                <div class="ratio ratio-16x9 bg-black rounded mb-3 overflow-hidden border border-secondary" id="studioPreviewVideoContainer">
                                    <video id="studioPreviewVideo" controls src=""></video>
                                </div>
                                <div class="text-center bg-black rounded mb-3 overflow-hidden border border-secondary p-2 d-none" id="studioPreviewImageContainer">
                                    <img id="studioPreviewImage" class="img-fluid" src="" style="max-height: 250px;">
                                </div>
                                <div class="badge bg-success border border-success text-uppercase py-2 px-3 mb-3 d-inline-block" style="font-size: 0.62rem; letter-spacing: 1.2px;">
                                    <i class="fa-solid fa-circle-check me-1"></i>Finished Image Ready
                                </div>
                            </div>

                            <h4 class="mb-4 text-white"><i class="fa-solid fa-share-nodes text-gradient-magenta me-2"></i>Post Details & Share</h4>
                            
                            <form id="studioDirectForm" onsubmit="submitDirectDistribution(event)">
                                <input type="hidden" id="processedFilePath" name="processed_file_path" value="">

                                <div class="mb-3">
                                    <label class="form-label text-secondary text-uppercase" style="font-size: 0.72rem; letter-spacing: 1px;">Title</label>
                                    <input type="text" name="title" class="form-control form-control-cyber" placeholder="Enter a descriptive title..." required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label text-secondary text-uppercase" style="font-size: 0.72rem; letter-spacing: 1px;">Description</label>
                                    <textarea name="description" class="form-control form-control-cyber" rows="3" placeholder="Add descriptive details and tags..."></textarea>
                                </div>
                                
                                <div class="mb-4">
                                    <h5 class="text-secondary text-uppercase mb-3" style="font-size: 0.72rem; letter-spacing: 1px;">Share to Platforms</h5>
                                    <div class="platform-switch-group d-flex justify-content-around align-items-center">
                                        <!-- YouTube Switch -->
                                        <div class="d-flex flex-column align-items-center">
                                            <label class="platform-switch">
                                                <input type="checkbox" name="platforms[]" value="youtube" id="studioPlatformYT">
                                                <span class="slider yt"></span>
                                                <i class="fa-brands fa-youtube platform-icon yt"></i>
                                            </label>
                                            <span class="platform-switch-label">YouTube</span>
                                        </div>

                                        <!-- TikTok Switch -->
                                        <div class="d-flex flex-column align-items-center">
                                            <label class="platform-switch">
                                                <input type="checkbox" name="platforms[]" value="tiktok" id="studioPlatformTT">
                                                <span class="slider tt"></span>
                                                <i class="fa-brands fa-tiktok platform-icon tt"></i>
                                            </label>
                                            <span class="platform-switch-label">TikTok</span>
                                        </div>

                                        <!-- Meta Switch -->
                                        <div class="d-flex flex-column align-items-center">
                                            <label class="platform-switch">
                                                <input type="checkbox" name="platforms[]" value="meta" id="studioPlatformMeta">
                                                <span class="slider meta"></span>
                                                <i class="fa-brands fa-meta platform-icon meta"></i>
                                            </label>
                                            <span class="platform-switch-label">Facebook & Instagram</span>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn-magnetic w-100 py-3" style="font-size: 1rem; padding: 0.8rem;">
                                    Share Now <i class="fa-solid fa-rocket ms-2"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</main>

<script src="assets/js/studio.js?v=4.0"></script>
<script src="assets/js/image_editor.js?v=4.0"></script>

<?php
$extraScripts = '<script src="assets/js/uploader.js?v=1.1"></script>';
include_once 'includes/footer.php';
?>
