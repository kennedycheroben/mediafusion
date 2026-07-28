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

// Ensure upload directories exist with proper permissions
@include_once __DIR__ . '/backend/init_storage.php';

$pageTitle  = 'Studio — MediaFusion';
$activePage = 'studio';
$extraHead  = '
<script src="https://cdnjs.cloudflare.com/ajax/libs/resumable.js/1.1.0/resumable.min.js"></script>
<style>
    /* Modern Creator Theme — Studio local overrides */
    :root {
        --bg-darker: #0f172a;
        --bg-panel: #1e293b;
        --border-neon: rgba(79, 70, 229, 0.25);
        --border-neon-glow: rgba(79, 70, 229, 0.5);
        --border-pink: rgba(236, 72, 153, 0.2);
        --border-pink-glow: rgba(236, 72, 153, 0.45);
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
            scrollbar-color: rgba(79, 70, 229, 0.3) transparent;
        }
        .workbench-right-scroll-container::-webkit-scrollbar {
            width: 6px;
        }
        .workbench-right-scroll-container::-webkit-scrollbar-thumb {
            background-color: rgba(79, 70, 229, 0.3);
            border-radius: 3px;
        }
    }

    /* Left tool strip style */
    .studio-tool-dock {
        display: flex;
        flex-direction: column;
        gap: 12px;
        background: var(--card-bg, #ffffff);
        border: 1px solid var(--card-border, #e2e8f0);
        border-radius: 8px;
        padding: 15px 8px;
        box-shadow: var(--card-shadow, 0 8px 30px rgba(15,23,42,.08));
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
        color: var(--primary-bg, #4f46e5);
        background: rgba(79, 70, 229, 0.08);
        border-color: rgba(79, 70, 229, 0.4);
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.15);
    }

    .tool-dock-btn.active {
        color: var(--primary-text, #ffffff);
        background: var(--primary-bg, #4f46e5);
        border-color: var(--primary-bg, #4f46e5);
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
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

    /* Range input primary sliders styling */

    input[type="range"].form-range::-webkit-slider-thumb {
        background: var(--primary-bg, #4f46e5);
        box-shadow: 0 0 6px rgba(79, 70, 229, 0.4);
    }
    input[type="range"].form-range::-moz-range-thumb {
        background: var(--primary-bg, #4f46e5);
        box-shadow: 0 0 6px rgba(79, 70, 229, 0.4);
    }

    /* Custom simple platform display in panels */
    .platform-switch-label {
        font-size: 0.8rem;
        color: var(--text-muted);
        text-align: center;
        margin-top: 5px;
        font-weight: 500;
    }

    /* Styling adjustments for Mode Tab Selector */
    .studio-tab-selector {
        display: flex;
        gap: 1rem;
        background: var(--secondary-bg, #f8fafc);
        padding: 0.4rem;
        border-radius: 12px;
        border: 1px solid var(--card-border, #e2e8f0);
        margin-bottom: 2rem;
    }
    .studio-tab-btn {
        flex: 1;
        background: transparent;
        border: none;
        color: var(--text-secondary, #475569);
        font-weight: 600;
        padding: 0.75rem 1.2rem;
        border-radius: 8px;
        text-transform: uppercase;
        font-size: 0.82rem;
        letter-spacing: 1px;
        transition: all 0.3s ease;
    }
    .studio-tab-btn.active {
        color: var(--primary-text, #ffffff);
        background: var(--primary-bg, #4f46e5);
        border: 1px solid var(--primary-bg, #4f46e5);
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
    }
    .form-section-header {
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--text-primary, #0f172a);
        border-bottom: 1px solid var(--card-border, #e2e8f0);
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
        box-shadow: 0 0 6px rgba(79, 70, 229, 0.5);
    }
    
    .studio-range-input::-moz-range-thumb {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: transparent;
        border: none;
        cursor: pointer;
        box-shadow: 0 0 6px rgba(79, 70, 229, 0.5);
    }
    
    .studio-range-input.studio-range-start::-webkit-slider-thumb {
        background: var(--primary-bg, #4f46e5);
        border: 2px solid #fff;
    }
    
    .studio-range-input.studio-range-end::-webkit-slider-thumb {
        background: var(--neon-magenta, #ec4899);
        border: 2px solid #fff;
    }
    
    .studio-range-input.studio-range-start::-moz-range-thumb {
        background: var(--primary-bg, #4f46e5);
        border: 2px solid #fff;
    }
    
    .studio-range-input.studio-range-end::-moz-range-thumb {
        background: var(--neon-magenta, #ec4899);
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

$extraHead .= '
<link rel="stylesheet" href="assets/css/capcut_editor.css?v=1.2">
<link rel="stylesheet" href="assets/css/photoshop_editor.css?v=1.2">
';

include 'header.php';
?>

<main style="padding-top: 100px; min-height: 100vh;">
    <div class="container py-5">
        <div class="text-center mb-4">
            <h1 class="display-5 text-gradient-cyan">Media Studio</h1>
            <p class="text-secondary">Upload videos or images, add captions, and customize details.</p>
        </div>

        <!-- Mode Tab Selector -->
        <div class="studio-tab-selector max-width-600 mx-auto" style="max-width: 600px; display: flex; gap: 10px; justify-content: center;">
            <button class="studio-tab-btn active" id="modeStandardBtn" onclick="toggleStudioMode('standard')">
                <i class="fa-solid fa-cloud-arrow-up me-2"></i>Direct Upload
            </button>
            <button class="studio-tab-btn" id="modeCapcutBtn" onclick="toggleStudioMode('video')">
                <i class="fa-solid fa-video me-2"></i>Video Editor
            </button>
            <button class="studio-tab-btn" id="modePhotoshopBtn" onclick="toggleStudioMode('photoshop')">
                <i class="fa-solid fa-image me-2"></i>Image Editor
            </button>
        </div>

        <!-- WORKSPACE 1: STANDARD DIRECT CHUNKED UPLOAD -->
        <div class="row g-5" id="workspaceStandard">
            <!-- Left Column: Upload Zone & Preview -->
            <div class="col-lg-6">
                <div id="uploadZone" class="upload-zone">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    <h3 class="mb-3" style="color: var(--text-primary);">Drag &amp; Drop Video here</h3>
                    <p class="text-secondary mb-4">or</p>
                    <button id="browseFileBtn" class="btn btn-outline-primary rounded-pill px-4">Choose File</button>
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
                    <h4 class="mb-4">Post Details</h4>
                    
                    <div class="mb-4">
                        <label class="form-label text-secondary text-uppercase" style="font-size: 0.8rem; letter-spacing: 1px;">Title</label>
                        <input type="text" id="videoTitle" class="form-control form-control-cyber" placeholder="Enter an engaging title...">
                    </div>
                    
                    <div class="mb-5">
                        <label class="form-label text-secondary text-uppercase" style="font-size: 0.8rem; letter-spacing: 1px;">Description</label>
                        <textarea id="videoDesc" class="form-control form-control-cyber" rows="4" placeholder="Add details and hashtag strings..."></textarea>
                    </div>

                    <h4 class="mb-4">Share to Social Accounts</h4>
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

                        <!-- Facebook Switch -->
                        <div class="d-flex flex-column align-items-center">
                            <label class="platform-switch">
                                <input type="checkbox" id="platformFB">
                                <span class="slider fb"></span>
                                <i class="fa-brands fa-facebook-f platform-icon fb"></i>
                            </label>
                            <span class="platform-switch-label">Facebook</span>
                        </div>

                        <!-- Instagram Switch -->
                        <div class="d-flex flex-column align-items-center">
                            <label class="platform-switch">
                                <input type="checkbox" id="platformIG">
                                <span class="slider ig"></span>
                                <i class="fa-brands fa-instagram platform-icon ig"></i>
                            </label>
                            <span class="platform-switch-label">Instagram</span>
                        </div>
                    </div>

                    <button id="uploadBtn" class="btn-magnetic w-100 mt-4" style="font-size: 1.1rem; padding: 1rem;">
                        Share Now <i class="fa-solid fa-rocket ms-2"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- WORKSPACE 2B: CAPCUT VIDEO EDITOR (Peer to workspaceStudio, mutually exclusive) -->
        <div id="capcutWorkbench" class="capcut-container mb-4 d-none" style="width: 100%;" data-lenis-prevent>
                <!-- CapCut Top Header Bar (Matching CapCut Web UI) -->
                <div class="capcut-header-bar">
                    <div class="capcut-header-left">
                        <button type="button" class="capcut-logo-square" title="CapCut Studio">
                            <i class="fa-solid fa-scissors"></i>
                        </button>
                        <div class="capcut-project-title-box position-relative" style="cursor: pointer;">
                            <i class="fa-solid fa-cloud text-info me-1" style="font-size:0.75rem;"></i>
                            <input type="text" class="capcut-project-title-input" id="capcutProjectTitleInput" value="Untitled Project" title="Project Title">
                            <i class="fa-solid fa-chevron-down ms-1 text-muted" id="projectListToggle" style="font-size:0.6rem;" onclick="toggleProjectListDropdown(event)"></i>
                            
                            <!-- Hidden Project ID tracker -->
                            <input type="hidden" id="capcutProjectId" value="0">
                            
                            <!-- Project List Dropdown Menu -->
                            <div class="dropdown-menu dropdown-menu-dark p-2" id="projectListDropdown" style="display: none; position: absolute; top: 100%; left: 0; min-width: 240px; z-index: 10000; border: 1px solid var(--capcut-border); background: var(--capcut-bg-panel); box-shadow: 0 10px 25px rgba(0,0,0,0.5);">
                                <div class="dropdown-header text-uppercase font-weight-bold" style="font-size:0.65rem; color:var(--capcut-teal); padding-left:8px; margin-bottom:4px;">My Studio Drafts</div>
                                <div id="projectDropdownList" style="max-height: 200px; overflow-y: auto;">
                                    <!-- Populated dynamically via AJAX -->
                                </div>
                                <div class="dropdown-divider" style="border-top: 1px solid var(--capcut-border); margin: 6px 0;"></div>
                                <a class="dropdown-item small d-flex align-items-center gap-2" href="#" onclick="createNewProject(event)" style="color: #25D366; font-weight:600;">
                                    <i class="fa-solid fa-plus"></i> New Project
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="capcut-header-center">
                        <button type="button" class="capcut-hdr-icon-btn active" title="Pointer Tool"><i class="fa-solid fa-mouse-pointer"></i></button>
                        <button type="button" class="capcut-hdr-icon-btn" title="Hand Tool"><i class="fa-solid fa-hand"></i></button>
                        <select class="capcut-hdr-select" title="Zoom Viewport">
                            <option value="fit">Fit</option>
                            <option value="100%" selected>100%</option>
                            <option value="75%">75%</option>
                            <option value="50%">50%</option>
                        </select>
                        <div class="capcut-hdr-sep"></div>
                        <button type="button" class="capcut-hdr-icon-btn" onclick="window.undo ? window.undo() : null" title="Undo (Ctrl+Z)"><i class="fa-solid fa-rotate-left"></i></button>
                        <button type="button" class="capcut-hdr-icon-btn" onclick="window.redo ? window.redo() : null" title="Redo (Ctrl+Y)"><i class="fa-solid fa-rotate-right"></i></button>
                    </div>
                    
                    <div class="capcut-header-right">
                        <div id="capcutAutoSaveBadge" class="capcut-autosave-badge" title="Draft Auto-Saved">
                            <i class="fa-solid fa-cloud-check me-1 text-success"></i> Auto-Saved
                        </div>
                        <button type="button" class="capcut-hdr-icon-btn" title="User Profile"><i class="fa-solid fa-user"></i></button>
                        <button type="button" class="capcut-hdr-icon-btn" title="Share Project"><i class="fa-solid fa-user-plus"></i></button>
                        <button type="button" class="capcut-btn-export-blue" id="capcutExportBtn">
                            <i class="fa-solid fa-download me-1"></i> Export
                        </button>
                        <button type="button" class="capcut-hdr-icon-btn" id="capcutFullscreenToggleBtn" onclick="toggleFullscreenCapCut()" title="Toggle Fullscreen Mode">
                            <i class="fa-solid fa-expand"></i>
                        </button>
                        <button type="button" class="capcut-hdr-icon-btn text-secondary" onclick="toggleStudioMode('standard')" title="Exit Editor">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <!-- Mobile Navigation Tabs -->
                <div class="capcut-mobile-nav">
                    <div class="capcut-mobile-tab active" data-tab="timeline">
                        <i class="fa-solid fa-clock me-1"></i>Timeline
                    </div>
                    <div class="capcut-mobile-tab" data-tab="media">
                        <i class="fa-solid fa-folder-open me-1"></i>Library
                    </div>
                    <div class="capcut-mobile-tab" data-tab="inspector">
                        <i class="fa-solid fa-sliders me-1"></i>Inspector
                    </div>
                </div>

                <div class="capcut-main-row">
                    <!-- 1. LEFT SIDEBAR: ASSET DRAWER -->
                    <div class="capcut-sidebar mobile-hide" data-lenis-prevent>
                        <div class="capcut-sidebar-nav" data-lenis-prevent>
                            <div class="capcut-nav-item active" data-sidebar-tab="media" title="Media Library">
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                <span>Media</span>
                            </div>
                            <div class="capcut-nav-item" data-sidebar-tab="templates" title="Templates">
                                <i class="fa-solid fa-table-cells-large"></i>
                                <span>Templates</span>
                            </div>
                            <div class="capcut-nav-item" data-sidebar-tab="elements" title="Elements">
                                <i class="fa-solid fa-icons"></i>
                                <span>Elements</span>
                            </div>
                            <div class="capcut-nav-item" data-sidebar-tab="audio" title="Audio Effects">
                                <i class="fa-solid fa-music"></i>
                                <span>Audio</span>
                            </div>
                            <div class="capcut-nav-item" data-sidebar-tab="text" title="Text Presets">
                                <i class="fa-solid fa-font"></i>
                                <span>Text</span>
                            </div>
                            <div class="capcut-nav-item" data-sidebar-tab="captions" title="Captions">
                                <i class="fa-solid fa-closed-captioning"></i>
                                <span>Captions</span>
                            </div>
                            <div class="capcut-nav-item" data-sidebar-tab="transcript" title="Transcript">
                                <i class="fa-solid fa-file-audio"></i>
                                <span>Transcript</span>
                            </div>
                            <div class="capcut-nav-item" data-sidebar-tab="effects" title="Effects">
                                <i class="fa-solid fa-wand-magic-sparkles"></i>
                                <span>Effects</span>
                            </div>
                            <div class="capcut-nav-item" data-sidebar-tab="transitions" title="Transitions">
                                <i class="fa-solid fa-diagram-project"></i>
                                <span>Transitions</span>
                            </div>
                            <div class="capcut-nav-item" data-sidebar-tab="filters" title="Video Filters">
                                <i class="fa-solid fa-sliders"></i>
                                <span>Filters</span>
                            </div>
                            <div class="capcut-nav-item" data-sidebar-tab="mask-cutout" title="Mask & Cutout">
                                <i class="fa-solid fa-mask"></i>
                                <span>Mask</span>
                            </div>
                            <div class="capcut-nav-item" data-sidebar-tab="tracking" title="Motion Tracking">
                                <i class="fa-solid fa-crosshairs"></i>
                                <span>Tracking</span>
                            </div>
                            <div class="capcut-nav-item" data-sidebar-tab="brand" title="Brand Kit">
                                <i class="fa-solid fa-gem"></i>
                                <span>Brand Kit</span>
                            </div>
                        </div>
                        
                        <div class="capcut-sidebar-content" data-lenis-prevent>
                            <!-- ══ MEDIA WORKSPACE ══ -->
                            <div class="capcut-sidebar-section" id="sidebar-section-media">
                                <div class="capcut-sidebar-title">Media Library</div>

                                <!-- Drop Zone -->
                                <div id="mediaDropZone" class="media-drop-zone" role="region" aria-label="Media upload drop zone">
                                    <div class="media-drop-inner">
                                        <i class="fa-solid fa-cloud-arrow-up fa-2x media-drop-icon"></i>
                                        <p class="media-drop-text">Drag &amp; drop files here</p>
                                        <p class="media-drop-sub">or</p>
                                        <button id="mediaBrowseBtn" class="btn btn-sm btn-cyber-outline" type="button">
                                            <i class="fa-solid fa-folder-open me-1"></i> Browse Files
                                        </button>
                                        <p class="media-drop-formats">MP4, MOV, WebM, AVI, MKV, JPG, PNG, GIF, WebP, MP3, WAV, AAC, OGG, FLAC</p>
                                    </div>
                                </div>
                                <!-- Hidden multi-file input -->
                                <input type="file" id="mediaFileInput"
                                    accept="video/*,image/*,audio/*"
                                    multiple
                                    style="display:none;">

                                <!-- Upload Queue -->
                                <div id="mediaUploadQueue" class="media-upload-queue"></div>

                                <!-- Search + Filter + Sort -->
                                <div class="media-controls">
                                    <div class="media-search-wrap">
                                        <i class="fa-solid fa-magnifying-glass media-search-icon"></i>
                                        <input id="mediaSearchInput" type="search" class="media-search-input"
                                            placeholder="Search media…" aria-label="Search media library">
                                    </div>
                                    <div class="media-filter-bar">
                                        <button class="media-filter-btn active" data-media-filter="all">All</button>
                                        <button class="media-filter-btn" data-media-filter="video">
                                            <i class="fa-solid fa-film"></i>
                                        </button>
                                        <button class="media-filter-btn" data-media-filter="image">
                                            <i class="fa-solid fa-image"></i>
                                        </button>
                                        <button class="media-filter-btn" data-media-filter="audio">
                                            <i class="fa-solid fa-music"></i>
                                        </button>
                                        <select id="mediaSortSelect" class="media-sort-select" aria-label="Sort media">
                                            <option value="created_at:DESC">Newest</option>
                                            <option value="created_at:ASC">Oldest</option>
                                            <option value="name:ASC">Name A–Z</option>
                                            <option value="name:DESC">Name Z–A</option>
                                            <option value="file_size:DESC">Largest</option>
                                            <option value="duration:DESC">Longest</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Media Grid -->
                                <div id="mediaLibraryGrid" class="media-library-grid"></div>

                                <!-- Empty state -->
                                <div id="mediaEmptyState" class="media-empty-state">
                                    <i class="fa-solid fa-photo-film fa-2x mb-2" style="opacity:.3;"></i>
                                    <p>No media yet.<br>Upload files above to get started.</p>
                                </div>
                            </div>
                            
                            <!-- Text Section -->
                            <div class="capcut-sidebar-section d-none" id="sidebar-section-text">
                                <div class="capcut-sidebar-title">Text Library</div>
                                <div class="capcut-asset-list">
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Neon Cyan Heading</span>
                                            <span class="capcut-asset-desc">Space Grotesk, Teal</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-text="neon">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Glitch Magenta Title</span>
                                            <span class="capcut-asset-desc">Outfit font, Pink</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-text="glitch">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Yellow Bold Sub</span>
                                            <span class="capcut-asset-desc">Classic Subtitle</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-text="bold">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Cyberpunk Glow 3D</span>
                                            <span class="capcut-asset-desc">Neon Electric Outline</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-text="neon">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Retro Arcade Title</span>
                                            <span class="capcut-asset-desc">Pixel Pixelated Font</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-text="glitch">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Minimal Sans Clean</span>
                                            <span class="capcut-asset-desc">Inter Modern White</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-text="bold">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Lower Third Name</span>
                                            <span class="capcut-asset-desc">Social Media Tag</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-text="bold">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Kinetic Motion Title</span>
                                            <span class="capcut-asset-desc">Dynamic Animated Text</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-text="neon">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Gradient Bubble Tag</span>
                                            <span class="capcut-asset-desc">Colorful Vlog Caption</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-text="bold">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Audio Section -->
                            <div class="capcut-sidebar-section d-none" id="sidebar-section-audio">
                                <div class="capcut-sidebar-title">SFX & Music Assets</div>
                                <div class="capcut-asset-list">
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Whoosh Transition</span>
                                            <span class="capcut-asset-desc">Noise Sweeper</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-audio="whoosh">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Neon Chime Bell</span>
                                            <span class="capcut-asset-desc">FM Synth Chord</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-audio="chime">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Cyber Hit Chime</span>
                                            <span class="capcut-asset-desc">Laser sweep sound</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-audio="cyber">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Synth Bass Loop</span>
                                            <span class="capcut-asset-desc">Sequencer Drum Loop</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-audio="beat">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Vinyl Scratch Pop</span>
                                            <span class="capcut-asset-desc">Retro turntable DJ FX</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-audio="whoosh">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Sub Bass Drop</span>
                                            <span class="capcut-asset-desc">Cinematic Trailer Impact</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-audio="cyber">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="capcut-asset-card">
                                        <div class="capcut-asset-info">
                                            <span class="capcut-asset-name">Camera Shutter Click</span>
                                            <span class="capcut-asset-desc">Photo Flash Snap SFX</span>
                                        </div>
                                        <button type="button" class="capcut-asset-add" data-add-audio="chime">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Filters Section -->
                            <!-- Filters Section -->
                            <div class="capcut-sidebar-section d-none" id="sidebar-section-filters" style="max-height: calc(100vh - 120px); overflow-y: auto; padding-right: 4px;">
                                <div class="capcut-sidebar-title">Filters & Color Grading</div>
                                
                                <!-- Categories -->
                                <div class="d-flex gap-1 mb-2 overflow-x-auto pb-1" style="scrollbar-width: none;">
                                    <button type="button" class="btn btn-cyber btn-sm px-2 py-1 filter-cat-btn active" data-cat="all" style="font-size:10px;">All</button>
                                    <button type="button" class="btn btn-cyber btn-sm px-2 py-1 filter-cat-btn" data-cat="cinematic" style="font-size:10px;">Cinematic</button>
                                    <button type="button" class="btn btn-cyber btn-sm px-2 py-1 filter-cat-btn" data-cat="retro" style="font-size:10px;">Retro</button>
                                    <button type="button" class="btn btn-cyber btn-sm px-2 py-1 filter-cat-btn" data-cat="monochrome" style="font-size:10px;">Monochrome</button>
                                </div>

                                <!-- Filter Grid -->
                                <div class="row g-2 mb-3" id="filtersListGrid">
                                    <!-- Dynamic list of filters with category tags -->
                                </div>

                                <!-- Intensity Slider & Reset -->
                                <div class="p-2 border rounded mb-3" style="border-color: var(--capcut-border) !important; background: var(--capcut-panel-bg);">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="small text-secondary font-weight-bold mb-0">Filter Intensity</label>
                                        <span class="small text-secondary" id="lblFilterIntensity">100%</span>
                                    </div>
                                    <input type="range" class="form-range mb-2" id="sliderFilterIntensity" min="0" max="100" value="100">
                                    <button type="button" class="btn btn-outline-secondary btn-cyber-outline btn-sm w-100" id="btnResetFilters">
                                        <i class="fa-solid fa-rotate-left"></i> Reset All Adjustments
                                    </button>
                                </div>

                                <!-- Color adjustments -->
                                <div class="border-bottom pb-2 mb-2">
                                    <div class="capcut-sidebar-title small mb-2 d-flex justify-content-between align-items-center" style="cursor:pointer;" id="hdrColorAdjustments">
                                        <span>Color Adjustments</span>
                                        <i class="fa-solid fa-chevron-down" id="icoColorAdjustments"></i>
                                    </div>
                                    
                                    <div id="bodyColorAdjustments" class="d-none">
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Brightness</label><span class="small text-secondary" id="valAdjBrightness">100%</span></div>
                                            <input type="range" class="form-range adj-slider" data-prop="brightness" min="50" max="150" value="100">
                                        </div>
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Contrast</label><span class="small text-secondary" id="valAdjContrast">100%</span></div>
                                            <input type="range" class="form-range adj-slider" data-prop="contrast" min="50" max="150" value="100">
                                        </div>
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Saturation</label><span class="small text-secondary" id="valAdjSaturation">100%</span></div>
                                            <input type="range" class="form-range adj-slider" data-prop="saturation" min="0" max="200" value="100">
                                        </div>
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Exposure</label><span class="small text-secondary" id="valAdjExposure">100%</span></div>
                                            <input type="range" class="form-range adj-slider" data-prop="exposure" min="50" max="150" value="100">
                                        </div>
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Highlights</label><span class="small text-secondary" id="valAdjHighlights">0</span></div>
                                            <input type="range" class="form-range adj-slider" data-prop="highlights" min="-50" max="50" value="0">
                                        </div>
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Shadows</label><span class="small text-secondary" id="valAdjShadows">0</span></div>
                                            <input type="range" class="form-range adj-slider" data-prop="shadows" min="-50" max="50" value="0">
                                        </div>
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Temperature</label><span class="small text-secondary" id="valAdjTemp">0</span></div>
                                            <input type="range" class="form-range adj-slider" data-prop="temperature" min="-50" max="50" value="0">
                                        </div>
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Tint</label><span class="small text-secondary" id="valAdjTint">0</span></div>
                                            <input type="range" class="form-range adj-slider" data-prop="tint" min="-50" max="50" value="0">
                                        </div>
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Sharpness</label><span class="small text-secondary" id="valAdjSharpness">0</span></div>
                                            <input type="range" class="form-range adj-slider" data-prop="sharpness" min="0" max="100" value="0">
                                        </div>
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Vibrance</label><span class="small text-secondary" id="valAdjVibrance">0</span></div>
                                            <input type="range" class="form-range adj-slider" data-prop="vibrance" min="0" max="100" value="0">
                                        </div>
                                    </div>
                                </div>

                                <!-- Curves (RGB Splines) -->
                                <div class="border-bottom pb-2 mb-2">
                                    <div class="capcut-sidebar-title small mb-2 d-flex justify-content-between align-items-center" style="cursor:pointer;" id="hdrCurves">
                                        <span>Curves (RGB Splines)</span>
                                        <i class="fa-solid fa-chevron-down" id="icoCurves"></i>
                                    </div>
                                    
                                    <div id="bodyCurves" class="d-none">
                                        <div class="d-flex gap-1 mb-2 justify-content-center">
                                            <button type="button" class="btn btn-sm btn-outline-light btn-cyber-outline py-0 px-2 curve-channel-btn active" data-channel="rgb" style="font-size:10px;">RGB</button>
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-cyber-outline py-0 px-2 curve-channel-btn text-danger" data-channel="r" style="font-size:10px;">Red</button>
                                            <button type="button" class="btn btn-sm btn-outline-success btn-cyber-outline py-0 px-2 curve-channel-btn text-success" data-channel="g" style="font-size:10px;">Green</button>
                                            <button type="button" class="btn btn-sm btn-outline-info btn-cyber-outline py-0 px-2 curve-channel-btn text-info" data-channel="b" style="font-size:10px;">Blue</button>
                                        </div>
                                        <div class="text-center mb-2">
                                            <canvas id="curveAdjustmentCanvas" width="160" height="160" class="border bg-dark rounded" style="cursor:crosshair;"></canvas>
                                            <div class="small text-muted" style="font-size:10px;">Double-click to add points; Drag to modify</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- HSL & Selective Color -->
                                <div class="pb-2 mb-2">
                                    <div class="capcut-sidebar-title small mb-2 d-flex justify-content-between align-items-center" style="cursor:pointer;" id="hdrHSL">
                                        <span>HSL & Selective Color</span>
                                        <i class="fa-solid fa-chevron-down" id="icoHSL"></i>
                                    </div>
                                    
                                    <div id="bodyHSL" class="d-none">
                                        <div class="d-flex gap-1 overflow-x-auto pb-1 mb-2" style="scrollbar-width: none;" id="hslColorTabs">
                                            <button type="button" class="btn btn-sm p-1 rounded-circle hsl-color-tab active" data-color="red" style="background:#ff0000; width:16px; height:16px;" title="Red"></button>
                                            <button type="button" class="btn btn-sm p-1 rounded-circle hsl-color-tab" data-color="orange" style="background:#ffa500; width:16px; height:16px;" title="Orange"></button>
                                            <button type="button" class="btn btn-sm p-1 rounded-circle hsl-color-tab" data-color="yellow" style="background:#ffff00; width:16px; height:16px;" title="Yellow"></button>
                                            <button type="button" class="btn btn-sm p-1 rounded-circle hsl-color-tab" data-color="green" style="background:#00ff00; width:16px; height:16px;" title="Green"></button>
                                            <button type="button" class="btn btn-sm p-1 rounded-circle hsl-color-tab" data-color="cyan" style="background:#00ffff; width:16px; height:16px;" title="Cyan"></button>
                                            <button type="button" class="btn btn-sm p-1 rounded-circle hsl-color-tab" data-color="blue" style="background:#0000ff; width:16px; height:16px;" title="Blue"></button>
                                            <button type="button" class="btn btn-sm p-1 rounded-circle hsl-color-tab" data-color="magenta" style="background:#ff00ff; width:16px; height:16px;" title="Magenta"></button>
                                        </div>
                                        
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Hue</label><span class="small text-secondary" id="valHslHue">0</span></div>
                                            <input type="range" class="form-range hsl-slider" data-hsl="h" min="-180" max="180" value="0">
                                        </div>
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Saturation</label><span class="small text-secondary" id="valHslSat">0</span></div>
                                            <input type="range" class="form-range hsl-slider" data-hsl="s" min="-100" max="100" value="0">
                                        </div>
                                        <div class="capcut-control-group mb-2">
                                            <div class="d-flex justify-content-between"><label class="small text-secondary">Luminance</label><span class="small text-secondary" id="valHslLum">0</span></div>
                                            <input type="range" class="form-range hsl-slider" data-hsl="l" min="-100" max="100" value="0">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ══ MASK & CUTOUT WORKSPACE ══ -->
                            <div class="capcut-sidebar-section d-none" id="sidebar-section-mask-cutout" style="max-height: calc(100vh - 120px); overflow-y: auto; padding-right: 4px;">
                                <div class="capcut-sidebar-title">Mask Adjustments</div>
                                
                                <div class="capcut-control-group mb-2">
                                    <label class="small text-secondary font-weight-bold mb-1">Mask Shape</label>
                                    <select id="maskTypeSelect" class="form-select form-control-cyber form-select-sm">
                                        <option value="none">None 🚫</option>
                                        <option value="rectangle">Rectangle ⬜</option>
                                        <option value="circle">Circle ⚪</option>
                                        <option value="linear">Linear 📏</option>
                                    </select>
                                </div>

                                <div class="border rounded p-2 mb-3 bg-dark border-secondary">
                                    <div class="capcut-control-group mb-2">
                                        <div class="d-flex justify-content-between">
                                            <label class="small text-secondary">Position X</label>
                                            <span class="small text-secondary" id="lblMaskX">50%</span>
                                        </div>
                                        <input type="range" class="form-range" id="sliderMaskX" min="0" max="100" value="50">
                                    </div>
                                    <div class="capcut-control-group mb-2">
                                        <div class="d-flex justify-content-between">
                                            <label class="small text-secondary">Position Y</label>
                                            <span class="small text-secondary" id="lblMaskY">50%</span>
                                        </div>
                                        <input type="range" class="form-range" id="sliderMaskY" min="0" max="100" value="50">
                                    </div>
                                    <div class="capcut-control-group mb-2">
                                        <div class="d-flex justify-content-between">
                                            <label class="small text-secondary">Width</label>
                                            <span class="small text-secondary" id="lblMaskWidth">30%</span>
                                        </div>
                                        <input type="range" class="form-range" id="sliderMaskWidth" min="1" max="100" value="30">
                                    </div>
                                    <div class="capcut-control-group mb-2">
                                        <div class="d-flex justify-content-between">
                                            <label class="small text-secondary">Height</label>
                                            <span class="small text-secondary" id="lblMaskHeight">30%</span>
                                        </div>
                                        <input type="range" class="form-range" id="sliderMaskHeight" min="1" max="100" value="30">
                                    </div>
                                    <div class="capcut-control-group mb-2">
                                        <div class="d-flex justify-content-between">
                                            <label class="small text-secondary">Rotation</label>
                                            <span class="small text-secondary" id="lblMaskRotation">0°</span>
                                        </div>
                                        <input type="range" class="form-range" id="sliderMaskRotation" min="-180" max="180" value="0">
                                    </div>
                                    <div class="capcut-control-group mb-2">
                                        <div class="d-flex justify-content-between">
                                            <label class="small text-secondary">Feathering</label>
                                            <span class="small text-secondary" id="lblMaskFeather">0</span>
                                        </div>
                                        <input type="range" class="form-range" id="sliderMaskFeather" min="0" max="100" value="0">
                                    </div>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" id="chkMaskInvert">
                                        <label class="form-check-label small text-secondary" for="chkMaskInvert">Invert Mask</label>
                                    </div>
                                </div>

                                <div class="capcut-sidebar-title mt-3">Smart Cutout & Chroma Key</div>
                                
                                <div class="border rounded p-2 mb-3 bg-dark border-secondary">
                                    <button type="button" class="btn btn-cyber btn-sm w-100 mb-2" id="btnAutoBgRemove">
                                        <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Auto Background Removal
                                    </button>
                                    <button type="button" class="btn btn-cyber btn-sm w-100 mb-2" id="btnAiSubjectCutout">
                                        <i class="fa-solid fa-user-ninja me-1"></i> AI Subject Cutout
                                    </button>

                                    <!-- Provider unconfigured warning -->
                                    <div class="alert alert-cyber alert-warning py-2 px-3 small d-none" id="cutoutConfigError">
                                        <i class="fa-solid fa-circle-exclamation me-1 text-warning"></i> Cutout providers are unconfigured. Please define <code>REMOVE_BG_API_KEY</code>, <code>CLIPDROP_API_KEY</code>, or <code>PHOTOROOM_API_KEY</code> in your environment.
                                    </div>

                                    <hr class="border-secondary my-2">

                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="chkChromaEnabled">
                                        <label class="form-check-label small text-secondary" for="chkChromaEnabled">Chroma Key (Green Screen)</label>
                                    </div>
                                    <div class="capcut-control-group mb-2">
                                        <label class="small text-secondary">Key Color</label>
                                        <input type="color" class="form-control form-control-cyber form-control-sm p-0" id="colorChromaKey" value="#00ff00" style="height:30px;">
                                    </div>
                                    <div class="capcut-control-group mb-2">
                                        <div class="d-flex justify-content-between">
                                            <label class="small text-secondary">Similarity</label>
                                            <span class="small text-secondary" id="lblChromaSim">30%</span>
                                        </div>
                                        <input type="range" class="form-range" id="sliderChromaSim" min="1" max="100" value="30">
                                    </div>
                                    <div class="capcut-control-group mb-2">
                                        <div class="d-flex justify-content-between">
                                            <label class="small text-secondary">Smoothness</label>
                                            <span class="small text-secondary" id="lblChromaSmooth">10%</span>
                                        </div>
                                        <input type="range" class="form-range" id="sliderChromaSmooth" min="1" max="100" value="10">
                                    </div>
                                </div>
                            </div>

                            <!-- ══ MOTION TRACKING WORKSPACE ══ -->
                            <div class="capcut-sidebar-section d-none" id="sidebar-section-tracking" style="max-height: calc(100vh - 120px); overflow-y: auto; padding-right: 4px;">
                                <div class="capcut-sidebar-title">AI Motion Tracking</div>
                                
                                <div class="border rounded p-2 mb-3 bg-dark border-secondary">
                                    <div class="capcut-control-group mb-2">
                                        <label class="small text-secondary font-weight-bold mb-1">Source Video</label>
                                        <select id="trackerMediaSelect" class="form-select form-control-cyber form-select-sm">
                                            <!-- Dynamically populated video clips -->
                                        </select>
                                    </div>

                                    <div class="capcut-control-group mb-2">
                                        <label class="small text-secondary font-weight-bold mb-1">Target Overlay</label>
                                        <select id="trackerTargetSelect" class="form-select form-select-sm form-control-cyber">
                                            <!-- Dynamically populated overlay layers -->
                                        </select>
                                    </div>

                                    <div class="capcut-control-group mb-2">
                                        <label class="small text-secondary font-weight-bold mb-1">Tracking Category</label>
                                        <select id="trackerTypeSelect" class="form-select form-select-sm form-control-cyber">
                                            <option value="point">Point Tracking 📍</option>
                                            <option value="face">Face Tracking 👤</option>
                                            <option value="object">Object Tracking 📦</option>
                                        </select>
                                    </div>

                                    <button type="button" class="btn btn-cyber btn-sm w-100 mt-2 mb-2" id="btnStartTracking">
                                        <i class="fa-solid fa-crosshairs me-1"></i> Start Motion Tracking
                                    </button>

                                    <!-- Provider unconfigured warning -->
                                    <div class="alert alert-cyber alert-warning py-2 px-3 small d-none" id="trackerConfigError">
                                        <i class="fa-solid fa-circle-exclamation me-1 text-warning"></i> Tracking provider is unconfigured. Define <code>TRACKING_PROVIDER</code> and associated credentials in your environment.
                                    </div>

                                    <!-- Progress bar wrapper -->
                                    <div class="mt-2 d-none" id="trackerProgressWrapper">
                                        <div class="d-flex justify-content-between mb-1 small text-secondary">
                                            <span id="trackerProgressText">Initializing tracker...</span>
                                        </div>
                                        <div class="progress progress-cyber" style="height: 6px; background-color: var(--capcut-bg-darker);">
                                            <div class="progress-bar progress-bar-cyber bg-cyber" id="trackerProgressBar" role="progressbar" style="width: 0%;"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="capcut-sidebar-title mt-3">Active Attached Paths</div>
                                <div id="activeTracksList" class="small text-secondary">
                                    <!-- Dynamic list of attached trajectories -->
                                </div>
                            </div>

                            <!-- ══ BRAND KIT WORKSPACE ══ -->
                            <div class="capcut-sidebar-section d-none" id="sidebar-section-brand" style="max-height: calc(100vh - 120px); overflow-y: auto; padding-right: 4px;">
                                <div class="capcut-sidebar-title">Brand Colors</div>
                                <div class="border rounded p-2 mb-3 bg-dark border-secondary">
                                    <div class="d-flex gap-2 flex-wrap mb-2" id="brandColorsList">
                                        <!-- Colors list loaded dynamically -->
                                    </div>
                                    <div class="d-flex gap-2 align-items-center mt-2">
                                        <input type="color" id="brandColorPickerInput" class="form-control form-control-cyber form-control-sm p-0" value="#ff007f" style="height:32px; width:45px;">
                                        <button type="button" class="btn btn-cyber btn-xs" id="btnAddBrandColor">
                                            <i class="fa-solid fa-plus me-1"></i> Add Color
                                        </button>
                                    </div>
                                </div>

                                <div class="capcut-sidebar-title">Brand Fonts</div>
                                <div class="border rounded p-2 mb-3 bg-dark border-secondary" id="brandFontsList">
                                    <!-- Fonts list loaded dynamically -->
                                </div>

                                <div class="capcut-sidebar-title">Brand Logos</div>
                                <div class="border rounded p-2 mb-3 bg-dark border-secondary">
                                    <div id="brandLogosList" class="mb-2">
                                        <!-- Logos list loaded dynamically -->
                                    </div>
                                    <input type="file" id="logoUploadInput" class="d-none" accept="image/*">
                                    <button type="button" class="btn btn-cyber btn-sm w-100" id="btnUploadLogo">
                                        <i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload Logo
                                    </button>
                                </div>

                                <div class="capcut-sidebar-title">Reusable Brand Watermarks</div>
                                <div id="brandWatermarksList">
                                    <!-- Reusable brand assets -->
                                </div>
                            </div>

                            <!-- Templates Section -->
                            <div class="capcut-sidebar-section d-none" id="sidebar-section-templates" style="max-height: calc(100vh - 120px); overflow-y: auto; padding-right: 4px;">
                                <div class="capcut-sidebar-title">Multi-Track Templates</div>
                                <div class="capcut-asset-list">
                                    <!-- Populated dynamically via assets/js/studio_templates.js -->
                                </div>
                            </div>

                            <!-- Elements Section -->
                            <!-- Elements Section -->
                            <div class="capcut-sidebar-section d-none" id="sidebar-section-elements" style="max-height: calc(100vh - 120px); overflow-y: auto; padding-right: 4px;">
                                <div class="capcut-sidebar-title">Stickers & Elements</div>
                                
                                <!-- Categories -->
                                <div class="mb-2">
                                    <label class="small text-secondary font-weight-bold mb-1">Select Library Tab</label>
                                    <select id="elementsCategorySelect" class="form-select form-control-cyber form-select-sm">
                                        <option value="stickers">Stickers 👆</option>
                                        <option value="emojis">Emojis 🔥</option>
                                        <option value="shapes">Vector Shapes ⚪</option>
                                        <option value="graphics">Graphics & Badges 🛡️</option>
                                        <option value="animated">Animated Elements ✨</option>
                                    </select>
                                </div>

                                <!-- Elements Grid -->
                                <div class="row g-2 mb-3" id="elementsListGrid">
                                    <!-- Dynamically populated elements -->
                                </div>
                            </div>

                            <!-- Captions Section -->
                            <div class="capcut-sidebar-section d-none" id="sidebar-section-captions">
                                <div class="capcut-sidebar-title">Auto Captions & Subtitles</div>
                                
                                <div class="p-2 border rounded mb-3" style="border-color: var(--capcut-border) !important; background: var(--capcut-panel-bg);">
                                    <div class="mb-2">
                                        <label class="small text-secondary font-weight-bold mb-1">Select Media Track Source</label>
                                        <select id="sttMediaSelect" class="form-select form-control-cyber form-select-sm">
                                            <!-- Dynamically populated video/audio clips -->
                                        </select>
                                    </div>
                                    <div class="mb-2">
                                        <label class="small text-secondary font-weight-bold mb-1">Language</label>
                                        <select id="sttLangSelect" class="form-select form-control-cyber form-select-sm">
                                            <option value="en">English (US/UK)</option>
                                            <option value="es">Spanish</option>
                                            <option value="fr">French</option>
                                            <option value="de">German</option>
                                            <option value="it">Italian</option>
                                        </select>
                                    </div>
                                    <button type="button" class="btn btn-cyber btn-sm w-100 mb-2" id="btnGenerateSTT">
                                        <i class="fa-solid fa-wand-magic-sparkles"></i> Generate AI Captions
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-cyber-outline btn-sm w-100" id="btnAddManualCaption">
                                        <i class="fa-solid fa-plus-circle"></i> Add Manual Caption
                                    </button>
                                </div>

                                <!-- Error config boundary state -->
                                <div id="sttConfigError" class="alert alert-danger p-2 small d-none" role="alert">
                                    <i class="fa-solid fa-triangle-exclamation"></i> <strong>STT Configuration Error:</strong> OpenAI API Key is missing. Please define `OPENAI_API_KEY` in your `.env` file.
                                </div>

                                <div class="capcut-sidebar-title small mt-2 mb-1">Captions List</div>
                                <div id="captionsManagerList" style="max-height: 250px; overflow-y: auto;" class="small text-secondary">
                                    <!-- Dynamic list of captions, timeline times, split/merge controls -->
                                </div>
                            </div>

                            <!-- Transcript Section -->
                            <div class="capcut-sidebar-section d-none" id="sidebar-section-transcript">
                                <div class="capcut-sidebar-title">Video Speech Transcript</div>
                                <div class="p-1 mb-2">
                                    <input type="text" id="transcriptSearch" class="form-control form-control-cyber form-control-sm" placeholder="Search spoken text...">
                                </div>
                                <div id="transcriptViewArea" style="max-height: 400px; overflow-y: auto; line-height: 1.5;" class="small text-secondary p-1">
                                    <!-- Dynamic word-for-word transcript display, synced with playhead -->
                                </div>
                            </div>

                            <!-- Effects Section -->
                            <div class="capcut-sidebar-section d-none" id="sidebar-section-effects" style="max-height: calc(100vh - 120px); overflow-y: auto; padding-right: 4px;">
                                <div class="capcut-sidebar-title">Extensible Effects Registry</div>
                                
                                <!-- Categories -->
                                <div class="mb-2">
                                    <label class="small text-secondary font-weight-bold mb-1">Select Category</label>
                                    <select id="effectCategorySelect" class="form-select form-control-cyber form-select-sm">
                                        <option value="trending">Trending 🔥</option>
                                        <option value="basic">Basic ⚙️</option>
                                        <option value="retro">Retro 📻</option>
                                        <option value="cinematic">Cinematic 🎬</option>
                                        <option value="glitch">Glitch 👾</option>
                                        <option value="light">Light ⚡</option>
                                        <option value="lens">Lens 🔍</option>
                                        <option value="blur">Blur 🌫️</option>
                                        <option value="distortion">Distortion 🌀</option>
                                        <option value="nature">Nature 🍃</option>
                                        <option value="spark">Spark ✨</option>
                                        <option value="love">Love ❤️</option>
                                        <option value="motion">Motion 🎥</option>
                                    </select>
                                </div>

                                <!-- Effects Grid -->
                                <div class="row g-2 mb-3" id="effectsListGrid">
                                    <!-- Dynamic list of registered effects for selected category -->
                                </div>

                                <div class="capcut-sidebar-title small mt-2 mb-1">Applied Effects on Selected Clip</div>
                                <div id="activeClipEffectsArea" style="max-height: 250px; overflow-y: auto;" class="small text-secondary">
                                    <!-- Dynamic list of active effects on the selected timeline item, with intensity & duration inputs -->
                                </div>
                            </div>

                            <!-- Transitions Section -->
                            <div class="capcut-sidebar-section d-none" id="sidebar-section-transitions" style="max-height: calc(100vh - 120px); overflow-y: auto; padding-right: 4px;">
                                <div class="capcut-sidebar-title">Video Transitions</div>
                                
                                <!-- Categories -->
                                <div class="mb-2">
                                    <label class="small text-secondary font-weight-bold mb-1">Select Category</label>
                                    <select id="transitionCategorySelect" class="form-select form-control-cyber form-select-sm">
                                        <option value="fade">Fade 🌫️</option>
                                        <option value="dissolve">Dissolve 🌫️</option>
                                        <option value="blur">Blur 🌫️</option>
                                        <option value="slide">Slide ⬅️</option>
                                        <option value="zoom">Zoom 🔍</option>
                                        <option value="spin">Spin 🔄</option>
                                        <option value="wipe">Wipe ⬅️</option>
                                        <option value="split">Split ✂️</option>
                                        <option value="mask">Mask ⚪</option>
                                        <option value="distortion">Distortion 🌀</option>
                                    </select>
                                </div>

                                <!-- Transitions Grid -->
                                <div class="row g-2 mb-3" id="transitionsListGrid">
                                    <!-- Dynamic list of registered transitions -->
                                </div>

                                <div class="capcut-sidebar-title small mt-2 mb-1">Applied Transition to Clip</div>
                                <div id="activeClipTransitionArea" class="small text-secondary">
                                    <!-- Applied transition properties (duration & easing) -->
                                </div>
                            </div>
                        </div> <!-- CLOSE capcut-sidebar-content -->
                    </div> <!-- CLOSE capcut-sidebar -->

                    <!-- 2. CENTER PANEL: PREVIEW PLAYER -->
                    <div class="capcut-center">
                        <div class="capcut-top-bar">
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-secondary small font-weight-bold" style="font-size: 0.65rem;">RATIO:</span>
                                <select id="capcutAspectSelect" class="form-select form-control-cyber form-select-sm py-0 px-2" style="width: 115px; height: 24px; font-size: 0.7rem; background-color: var(--capcut-bg-darker);">
                                    <option value="16:9">16:9 YT</option>
                                    <option value="9:16">9:16 TT</option>
                                    <option value="1:1">1:1 Square</option>
                                    <option value="4:5">4:5 Portrait</option>
                                    <option value="21:9">21:9 Cinema</option>
                                </select>
                            </div>
                            <div class="text-secondary small font-weight-bold" style="font-size:0.65rem;letter-spacing:1.5px;">VIDEO PREVIEW</div>
                            <div class="d-flex align-items-center gap-1">
                                <button id="previewFitBtn" class="preview-ctrl-btn" title="Fit mode: contain">
                                    <i class="fa-solid fa-expand"></i>
                                </button>
                                <button id="previewFullscreenBtn" class="preview-ctrl-btn" title="Fullscreen">
                                    <i class="fa-solid fa-up-right-and-down-left-from-center"></i>
                                </button>
                            </div>
                        </div>

                        <!-- ══ COMPOSITING CANVAS ══ -->
                        <div id="capcutPlayerWrapper" class="capcut-player-wrapper ratio-16-9">
                            <!--
                                #previewLayerContainer: absolutely fills the wrapper.
                                PreviewEngine injects/manages <video>, <img>, and <div>
                                elements here as composited layers keyed by item.id.
                            -->
                            <div id="previewLayerContainer" class="preview-layer-container">
                                <video id="capcutVideo" class="preview-layer preview-video-layer capcut-video" preload="auto" playsinline></video>
                            </div>

                            <!--
                                #capcutOverlay is preserved for legacy text/sticker drag
                                from the existing capcut_editor.js overlay system.
                                PreviewEngine text layers use previewLayerContainer.
                            -->
                            <div id="capcutOverlay" class="capcut-overlay-layer" style="z-index:50;"></div>
                        </div>

                        <!-- ══ PREVIEW CONTROLS BAR ══ -->
                        <div class="preview-controls-bar" id="previewControlsBar">
                            <!-- Play / Pause -->
                            <button id="previewPlayBtn" class="preview-ctrl-btn preview-ctrl-play" title="Play / Pause (Space)" aria-label="Play">
                                <i id="previewPlayIcon" class="fa-solid fa-play"></i>
                            </button>

                            <!-- Mute -->
                            <button id="previewMuteBtn" class="preview-ctrl-btn" title="Mute / Unmute (M)" aria-label="Mute">
                                <i id="previewMuteIcon" class="fa-solid fa-volume-high"></i>
                            </button>

                            <!-- Volume -->
                            <input id="previewVolumeSlider" type="range" class="preview-volume-slider"
                                min="0" max="1" step="0.02" value="1"
                                title="Volume" aria-label="Volume">

                            <!-- Seek Bar -->
                            <div class="preview-seek-wrap">
                                <input id="previewSeekBar" type="range" class="preview-seek-bar"
                                    min="0" max="15" step="0.033" value="0"
                                    title="Seek" aria-label="Seek position">
                            </div>

                            <!-- Timecode -->
                            <div id="previewTimecode" class="preview-timecode" aria-live="polite">
                                00:00.00 / 00:15.00
                            </div>
                        </div>
                    </div>


                    <!-- 3. RIGHT PANEL: PROPERTIES -->
                    <div class="capcut-inspector mobile-hide">
                        <div class="capcut-inspector-tabs">
                            <div class="capcut-inspector-tab active">PROPERTIES</div>
                            <div class="capcut-inspector-tab">PROJECT</div>
                        </div>
                        <div class="capcut-inspector-content">
                            <h6 id="capcutInspectorTitle" class="text-white border-bottom pb-2" style="font-size:0.75rem;">PROPERTIES</h6>
                            <div id="capcutInspectorContent">
                                <!-- Contextual slider ranges injected here -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. BOTTOM PANEL: TIMELINE -->
                <div class="capcut-timeline mobile-hide">
                    <div class="capcut-timeline-toolbar">
                        <div class="capcut-timeline-tools-left">
                            <button type="button" id="capcutPlayBtn" class="capcut-timeline-btn highlight" title="Play / Pause (Space)">
                                <i id="capcutPlayIcon" class="fa-solid fa-play"></i>
                            </button>
                            <div class="capcut-timecode" id="capcutTimecode">00:00:00 | 00:00:15</div>
                            <div class="border-start h-25 mx-1" style="border-color: var(--capcut-border) !important;"></div>
                            <button type="button" id="capcutSplitBtn" class="capcut-timeline-btn" title="Split Clip (S)">
                                <i class="fa-solid fa-scissors"></i> Split
                            </button>
                            <button type="button" id="capcutCopyBtn" class="capcut-timeline-btn" title="Copy Clip (Ctrl+C)">
                                <i class="fa-solid fa-copy"></i> Copy
                            </button>
                            <button type="button" id="capcutPasteBtn" class="capcut-timeline-btn" disabled title="Paste Clip (Ctrl+V)">
                                <i class="fa-solid fa-paste"></i> Paste
                            </button>
                            <button type="button" id="capcutDuplicateBtn" class="capcut-timeline-btn" disabled title="Duplicate Clip (Ctrl+D)">
                                <i class="fa-solid fa-clone"></i> Duplicate
                            </button>
                            <button type="button" id="capcutReverseBtn" class="capcut-timeline-btn" disabled title="Reverse Clip">
                                <i class="fa-solid fa-backward"></i> Reverse
                            </button>
                            <button type="button" id="capcutFreezeBtn" class="capcut-timeline-btn" disabled title="Freeze Frame">
                                <i class="fa-solid fa-snowflake"></i> Freeze
                            </button>
                            <button type="button" id="capcutDetachAudioBtn" class="capcut-timeline-btn" disabled title="Detach Audio from Video">
                                <i class="fa-solid fa-volume-xmark"></i> Detach Audio
                            </button>
                            <button type="button" id="capcutReplaceAudioBtn" class="capcut-timeline-btn" disabled title="Replace Clip Audio Source">
                                <i class="fa-solid fa-file-audio"></i> Replace Audio
                            </button>
                            <button type="button" id="capcutDeleteBtn" class="capcut-timeline-btn danger" disabled title="Delete Clip (Del)">
                                <i class="fa-solid fa-trash"></i> Delete
                            </button>
                            <div class="border-start h-25 mx-1" style="border-color: var(--capcut-border) !important;"></div>
                            <button type="button" id="capcutAddTextBtn" class="capcut-timeline-btn" title="Add Text Layer">
                                <i class="fa-solid fa-font"></i> Text
                            </button>
                            <button type="button" id="capcutAddTrackBtn" class="capcut-timeline-btn" title="Add New Track">
                                <i class="fa-solid fa-plus"></i> Track
                            </button>
                            <button type="button" id="capcutSnapBtn" class="capcut-timeline-btn active" title="Toggle Magnetic Snap">
                                <i class="fa-solid fa-magnet"></i> Snap
                            </button>
                            <button type="button" id="capcutRippleBtn" class="capcut-timeline-btn" title="Toggle Magnetic Timeline (Ripple Edit)">
                                <i class="fa-solid fa-left-right"></i> Magnetic
                            </button>
                        </div>
                        <div class="capcut-timeline-tools-right">
                            <div class="capcut-zoom-slider me-2">
                                <i class="fa-solid fa-minus"></i>
                                <input type="range" id="capcutZoomRange" min="10" max="60" value="25" title="Timeline Zoom">
                                <i class="fa-solid fa-plus"></i>
                            </div>
                            <button type="button" class="capcut-timeline-btn" onclick="toggleFullscreenCapCut()" title="Canvas Fullscreen"><i class="fa-solid fa-expand"></i></button>
                        </div>
                    </div>
                    
                    <div class="capcut-timeline-scroll" id="capcutTimelineScroll">
                        <div class="capcut-timeline-content" id="capcutTimelineContent">
                            <div class="capcut-playhead" id="capcutPlayhead">
                                <div class="capcut-playhead-handle" id="capcutPlayheadHandle"></div>
                            </div>
                            
                            <div class="capcut-ruler" id="capcutRuler">
                                <div class="capcut-ruler-ticks" id="capcutRulerTicks"></div>
                            </div>
                            
                            <div class="capcut-tracks-container" id="capcutTracksContainer">
                                <!-- Channels injected here -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Export Settings Modal (Matches CapCut Web UI) -->
                <div class="capcut-export-modal" id="capcutExportModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 100000; align-items: center; justify-content: center;">
                    <div class="glass-card" style="width: 450px; padding: 2rem; border: 1px solid var(--capcut-border); background: var(--capcut-bg-panel); color: #fff; box-shadow: 0 15px 40px rgba(0,0,0,0.8);">
                        <h4 class="mb-4 text-gradient-cyan"><i class="fa-solid fa-download me-2"></i>Export Settings</h4>
                        
                        <div class="mb-3">
                            <label class="form-label form-label-cyber text-secondary">Filename</label>
                            <input type="text" id="exportFilenameInput" class="form-control cyber-input" style="background: #0f172a; color: #fff; border-color: var(--capcut-border);" placeholder="Project Name">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label form-label-cyber text-secondary">Resolution</label>
                                <select id="exportResolutionSelect" class="form-select form-control-cyber" style="background: #0f172a; color: #fff; border-color: var(--capcut-border);">
                                    <option value="4k">4K (2160p)</option>
                                    <option value="1080p" selected>1080p (Full HD)</option>
                                    <option value="720p">720p (HD)</option>
                                </select>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label form-label-cyber text-secondary">Frame Rate</label>
                                <select id="exportFpsSelect" class="form-select form-control-cyber" style="background: #0f172a; color: #fff; border-color: var(--capcut-border);">
                                    <option value="60">60 fps</option>
                                    <option value="30" selected>30 fps</option>
                                    <option value="24">24 fps</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label form-label-cyber text-secondary">Quality</label>
                                <select id="exportQualitySelect" class="form-select form-control-cyber" style="background: #0f172a; color: #fff; border-color: var(--capcut-border);">
                                    <option value="high">High (CRF 18)</option>
                                    <option value="medium" selected>Medium (CRF 23)</option>
                                    <option value="low">Low (CRF 28)</option>
                                </select>
                            </div>
                            <div class="col-6 mb-4">
                                <label class="form-label form-label-cyber text-secondary">Format</label>
                                <select id="exportFormatSelect" class="form-select form-control-cyber" style="background: #0f172a; color: #fff; border-color: var(--capcut-border);">
                                    <option value="mp4" selected>MP4 (H.264)</option>
                                    <option value="webm">WebM (VP9)</option>
                                </select>
                            </div>
                        </div>

                        <div class="d-flex gap-3 justify-content-end">
                            <button type="button" class="btn btn-outline-secondary" id="capcutExportCancel" style="border-radius: 20px; padding: 6px 20px; border: 1px solid var(--capcut-border); color: #fff;">Cancel</button>
                            <button type="button" class="btn btn-primary" id="capcutExportConfirm" style="border-radius: 20px; padding: 6px 20px; background: #00a2ff; border: none; color: #fff;">Export Video</button>
                        </div>
                    </div>
                </div>

                <!-- Export Progress Modal -->
                <div class="capcut-export-modal" id="capcutExportProgressModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.9); z-index: 100001; align-items: center; justify-content: center;">
                    <div class="glass-card text-center" style="width: 500px; padding: 3rem; border: 1px solid var(--capcut-border); background: var(--capcut-bg-panel); color: #fff; box-shadow: 0 15px 40px rgba(0,0,0,0.8);">
                        <div id="exportProgressStateProcessing">
                            <h3 class="mb-2 text-gradient-cyan" id="exportProgressStatusText">Preparing Export...</h3>
                            <p class="text-secondary mb-4">Please do not close this window.</p>
                            
                            <div class="progress mb-3" style="height: 12px; background: #1e293b; border-radius: 6px;">
                                <div id="exportProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%; background: linear-gradient(90deg, var(--neon-cyan), #3b82f6);"></div>
                            </div>
                            <div class="d-flex justify-content-between text-secondary" style="font-size: 0.85rem; font-family: 'Space Grotesk', sans-serif;">
                                <span id="exportProgressPercent">0%</span>
                                <span id="exportProgressTime">Estimating time...</span>
                            </div>
                        </div>
                        
                        <div id="exportProgressStateCompleted" style="display: none;">
                            <div style="font-size: 4rem; color: var(--neon-green); margin-bottom: 1rem;"><i class="fa-solid fa-circle-check"></i></div>
                            <h3 class="mb-3 text-white">Export Completed</h3>
                            <a id="exportDownloadBtn" href="#" download class="btn btn-primary" style="border-radius: 20px; padding: 10px 30px; background: var(--neon-green); border: none; color: #000; font-weight: bold;"><i class="fa-solid fa-download me-2"></i>Download Video</a>
                            <button type="button" class="btn btn-primary mt-3 d-block mx-auto" id="capcutPublishBtn" style="border-radius: 20px; padding: 10px 30px; background: #00a2ff; border: none; color: #fff; font-weight: bold;"><i class="fa-solid fa-share-nodes me-2"></i>Publish to Socials</button>
                            <button type="button" class="btn btn-outline-secondary mt-3 d-block mx-auto" onclick="document.getElementById('capcutExportProgressModal').style.display='none'" style="border-radius: 20px; padding: 6px 20px; border: 1px solid var(--capcut-border); color: #fff;">Close</button>
                        </div>
                        
                        <div id="exportProgressStateFailed" style="display: none;">
                            <div style="font-size: 4rem; color: var(--neon-magenta); margin-bottom: 1rem;"><i class="fa-solid fa-circle-xmark"></i></div>
                            <h3 class="mb-3 text-white">Export Failed</h3>
                            <p class="text-secondary mb-4" id="exportErrorMsg">An error occurred during rendering.</p>
                            <button type="button" class="btn btn-primary" id="exportRetryBtn" style="border-radius: 20px; padding: 10px 30px; background: var(--neon-magenta); border: none; color: #fff; font-weight: bold;"><i class="fa-solid fa-rotate-right me-2"></i>Retry Export</button>
                            <button type="button" class="btn btn-outline-secondary mt-3 d-block mx-auto" onclick="document.getElementById('capcutExportProgressModal').style.display='none'" style="border-radius: 20px; padding: 6px 20px; border: 1px solid var(--capcut-border); color: #fff;">Close</button>
                        </div>
                        
                        <!-- Publish Form State -->
                        <div id="exportProgressStatePublish" style="display: none; text-align: left;">
                            <h3 class="mb-4 text-gradient-cyan"><i class="fa-solid fa-share-nodes me-2"></i>Publish to Socials</h3>
                            <form id="studioDirectForm" onsubmit="submitDirectDistribution(event)">
                                <input type="hidden" name="processed_file_path" id="publishFilePath">
                                <input type="hidden" name="media_id" id="publishMediaId">
                                <input type="hidden" name="duration" id="publishDuration">
                                <input type="hidden" name="dimensions" id="publishDimensions">
                                <input type="hidden" name="mime_type" id="publishMimeType">
                                
                                <div class="mb-3">
                                    <label class="form-label form-label-cyber text-secondary">Title</label>
                                    <input type="text" name="title" class="form-control form-control-cyber" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label form-label-cyber text-secondary">Description</label>
                                    <textarea name="description" class="form-control form-control-cyber" rows="3" required></textarea>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label form-label-cyber text-secondary">Platforms</label>
                                    <div class="d-flex gap-3 flex-wrap">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="platforms[]" value="youtube" id="plat_yt">
                                            <label class="form-check-label text-white" for="plat_yt"><i class="fa-brands fa-youtube me-1 text-danger"></i> YouTube</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="platforms[]" value="tiktok" id="plat_tt">
                                            <label class="form-check-label text-white" for="plat_tt"><i class="fa-brands fa-tiktok me-1 text-info"></i> TikTok</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="platforms[]" value="facebook" id="plat_fb">
                                            <label class="form-check-label text-white" for="plat_fb"><i class="fa-brands fa-facebook me-1 text-primary"></i> Facebook</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="platforms[]" value="instagram" id="plat_ig">
                                            <label class="form-check-label text-white" for="plat_ig"><i class="fa-brands fa-instagram me-1 text-warning"></i> Instagram</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('exportProgressStatePublish').style.display='none'; document.getElementById('exportProgressStateCompleted').style.display='block';" style="border-radius: 20px; padding: 6px 20px; border: 1px solid var(--capcut-border); color: #fff;">Back</button>
                                    <button type="submit" class="btn btn-primary" style="border-radius: 20px; padding: 6px 20px; background: #00a2ff; border: none; font-weight: bold; color: #fff;"><i class="fa-solid fa-paper-plane me-2"></i>Share Now</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Hidden export form -->
                <form id="studioProcessingForm" style="display:none;" onsubmit="executeStudioProcess(event)">
                    <input type="hidden" name="action" value="process_video">
                    <input type="hidden" name="timeline_payload" id="capcutTimelinePayload">
                    <input type="hidden" name="caption" id="captionHiddenInput">
                    <input type="hidden" name="start_time" id="studioStartTimeInput">
                    <input type="hidden" name="end_time" id="studioEndTimeInput">
                </form>
        </div>

        <!-- WORKSPACE 2A: PHOTOSHOP CLONE (Canvas Multi-Layer Graphic Editor) -->
        <div id="photoshopWorkbench" class="d-none">
            <!-- Top Menu Bar -->
            <div class="ps-menu-bar">
                <div class="ps-logo">PS<span>.</span></div>
                <div class="ps-menu-item">
                    File
                    <div class="ps-menu-dropdown">
                        <div class="ps-menu-dropdown-item" id="psMenuNew">New Document <span class="ps-menu-shortcut">Ctrl+N</span></div>
                        <div class="ps-menu-dropdown-sep"></div>
                        <div class="ps-menu-dropdown-item" id="psMenuExportPng">Export PNG <span class="ps-menu-shortcut">Ctrl+S</span></div>
                        <div class="ps-menu-dropdown-item" id="psMenuSaveToStudio">Save & Share <span class="ps-menu-shortcut">Ctrl+Shift+S</span></div>
                    </div>
                </div>
                <div class="ps-menu-item">
                    Edit
                    <div class="ps-menu-dropdown">
                        <div class="ps-menu-dropdown-item" onclick="window.undo ? window.undo() : null">Undo <span class="ps-menu-shortcut">Ctrl+Z</span></div>
                        <div class="ps-menu-dropdown-item" onclick="window.redo ? window.redo() : null">Redo <span class="ps-menu-shortcut">Ctrl+Y</span></div>
                    </div>
                </div>
                <div class="ps-menu-item">
                    Adjustments
                    <div class="ps-menu-dropdown">
                        <div class="ps-menu-dropdown-item" onclick="window.applyAdjustment ? window.applyAdjustment('brightness') : null">Brightness/Contrast</div>
                        <div class="ps-menu-dropdown-item" onclick="window.applyAdjustment ? window.applyAdjustment('contrast') : null">Contrast</div>
                        <div class="ps-menu-dropdown-item" onclick="window.applyAdjustment ? window.applyAdjustment('grayscale') : null">Grayscale</div>
                        <div class="ps-menu-dropdown-item" onclick="window.applyAdjustment ? window.applyAdjustment('invert') : null">Invert colors</div>
                        <div class="ps-menu-dropdown-item" onclick="window.applyAdjustment ? window.applyAdjustment('threshold') : null">Threshold</div>
                        <div class="ps-menu-dropdown-item" onclick="window.applyAdjustment ? window.applyAdjustment('sepia') : null">Sepia Tone</div>
                        <div class="ps-menu-dropdown-item" onclick="window.applyAdjustment ? window.applyAdjustment('posterize') : null">Posterize</div>
                    </div>
                </div>
                <div class="ps-menu-item">
                    Layer
                    <div class="ps-menu-dropdown">
                        <div class="ps-menu-dropdown-item" onclick="document.getElementById('psLayerBtnNew') ? document.getElementById('psLayerBtnNew').click() : null">New Layer</div>
                        <div class="ps-menu-dropdown-item" onclick="document.getElementById('psLayerBtnDuplicate') ? document.getElementById('psLayerBtnDuplicate').click() : null">Duplicate Layer</div>
                        <div class="ps-menu-dropdown-item" onclick="document.getElementById('psLayerBtnDelete') ? document.getElementById('psLayerBtnDelete').click() : null">Delete Layer</div>
                    </div>
                </div>
                <div class="ps-menu-item">
                    Filters
                    <div class="ps-menu-dropdown">
                        <div class="ps-menu-dropdown-item" onclick="window.applyAdjustment ? window.applyAdjustment('sepia') : null">Sepia Filter</div>
                        <div class="ps-menu-dropdown-item" onclick="window.applyAdjustment ? window.applyAdjustment('posterize') : null">Posterize Filter</div>
                        <div class="ps-menu-dropdown-item" onclick="window.applyAdjustment ? window.applyAdjustment('grayscale') : null">B&W Film Filter</div>
                    </div>
                </div>
                <div class="ms-auto d-flex align-items-center gap-2 pe-2">
                    <span id="psAutoSaveBadge" class="ps-autosave-badge" title="Draft Auto-Saved">
                        <i class="fa-solid fa-cloud-check me-1 text-success"></i> Auto-Saved
                    </span>
                    <button type="button" class="ps-opt-btn" onclick="toggleFullscreenPhotoshop()" title="Full Screen Editing"><i class="fa-solid fa-expand me-1"></i> Fullscreen</button>
                    <button type="button" class="ps-opt-btn" onclick="toggleStudioMode('standard')" title="Back to Dashboard"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </div>

            <!-- Options Bar -->
            <div class="ps-options-bar">
                <!-- Dynamic options loaded by JS -->
            </div>

            <!-- Main Body -->
            <div class="ps-body">
                <!-- Left Toolbar -->
                <div class="ps-toolbar">
                    <button class="ps-tool-btn active" data-tool="brush" title="Brush Tool (B)">
                        <i class="fa-solid fa-brush"></i>
                        <span>Brush</span>
                    </button>
                    <button class="ps-tool-btn" data-tool="eraser" title="Eraser Tool (E)">
                        <i class="fa-solid fa-eraser"></i>
                        <span>Eraser</span>
                    </button>
                    <button class="ps-tool-btn" data-tool="bucket" title="Paint Bucket (G)">
                        <i class="fa-solid fa-fill-drip"></i>
                        <span>Bucket</span>
                    </button>
                    <button class="ps-tool-btn" data-tool="eyedropper" title="Eyedropper Tool (I)">
                        <i class="fa-solid fa-eye-dropper"></i>
                        <span>Sample</span>
                    </button>
                    <button class="ps-tool-btn" data-tool="text" title="Horizontal Text (T)">
                        <i class="fa-solid fa-font"></i>
                        <span>Text</span>
                    </button>
                    <button class="ps-tool-btn" data-tool="crop" title="Crop Tool (C)">
                        <i class="fa-solid fa-crop-simple"></i>
                        <span>Crop</span>
                    </button>
                    <button class="ps-tool-btn" data-tool="hand" title="Hand Tool (H)">
                        <i class="fa-solid fa-hand"></i>
                        <span>Hand</span>
                    </button>
                    <button class="ps-tool-btn" data-tool="zoom" title="Zoom Tool (Z)">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <span>Zoom</span>
                    </button>

                    <div class="ps-tool-sep"></div>

                    <!-- Color boxes -->
                    <div class="ps-fg-bg-box">
                        <div class="ps-fg-color" style="background-color: #ffffff;"></div>
                        <div class="ps-bg-color" style="background-color: #000000;"></div>
                        <button class="ps-swap-btn" title="Swap Colors (X)"><i class="fa-solid fa-arrows-rotate"></i></button>
                        <button class="ps-default-btn" title="Default Colors (D)"><i class="fa-solid fa-border-all"></i></button>
                    </div>
                </div>

                <!-- Canvas Area -->
                <div class="ps-canvas-area">
                    <div class="ps-ruler-corner"></div>
                    <div class="ps-ruler-h"><canvas id="psRulerH"></canvas></div>
                    <div class="ps-ruler-v"><canvas id="psRulerV"></canvas></div>

                    <div class="ps-canvas-viewport">
                        <canvas id="psMainCanvas"></canvas>
                    </div>
                </div>

                <!-- Right Dock Panels -->
                <div class="ps-right-dock">
                    <!-- Layers Panel -->
                    <div class="ps-panel">
                        <div class="ps-panel-header">
                            <span>Layers</span>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>
                        <div class="ps-panel-body">
                            <div class="d-flex gap-2 align-items-center mb-2">
                                <select class="ps-blend-select form-select form-select-sm" style="flex:1;">
                                    <option value="source-over">Normal</option>
                                    <option value="multiply">Multiply</option>
                                    <option value="screen">Screen</option>
                                    <option value="overlay">Overlay</option>
                                    <option value="darken">Darken</option>
                                    <option value="lighten">Lighten</option>
                                    <option value="color-dodge">Color Dodge</option>
                                    <option value="color-burn">Color Burn</option>
                                    <option value="hard-light">Hard Light</option>
                                    <option value="soft-light">Soft Light</option>
                                    <option value="difference">Difference</option>
                                    <option value="exclusion">Exclusion</option>
                                </select>
                                <div class="ps-layer-opacity-row" style="width: 100px;">
                                    <span class="ps-layer-opacity-label">Opacity:</span>
                                    <input type="range" class="ps-layer-opacity-slider" min="0" max="100" value="100" style="width: 40px;">
                                    <span class="ps-layer-opacity-val">100%</span>
                                </div>
                            </div>

                            <div class="ps-layers-list">
                                <!-- Loaded dynamically -->
                            </div>

                            <div class="ps-layers-toolbar">
                                <button type="button" class="ps-layers-toolbar-btn" id="psLayerBtnNew" title="New Layer"><i class="fa-solid fa-plus"></i></button>
                                <button type="button" class="ps-layers-toolbar-btn" id="psLayerBtnDuplicate" title="Duplicate Layer"><i class="fa-solid fa-clone"></i></button>
                                <button type="button" class="ps-layers-toolbar-btn danger" id="psLayerBtnDelete" title="Delete Layer"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </div>
                    </div>

                    <!-- Color Panel -->
                    <div class="ps-panel">
                        <div class="ps-panel-header">
                            <span>Color</span>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>
                        <div class="ps-panel-body ps-color-panel-body">
                            <div class="ps-color-spectrum" style="position: relative;">
                                <canvas id="psColorSpectrum"></canvas>
                                <div class="ps-color-pointer" style="left:0px; top:0px;"></div>
                            </div>
                            <input type="range" class="ps-hue-slider" min="0" max="360" value="0">
                            
                            <div class="ps-hex-row">
                                <div class="ps-hex-swatch" style="background-color: #ffffff;"></div>
                                <input type="text" class="ps-hex-input" value="#FFFFFF">
                            </div>
                        </div>
                    </div>

                    <!-- History Panel -->
                    <div class="ps-panel">
                        <div class="ps-panel-header">
                            <span>History</span>
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>
                        <div class="ps-panel-body">
                            <div class="ps-history-list">
                                <!-- History stack items loaded dynamically -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Bar -->
            <div class="ps-status-bar">
                <div class="ps-status-item">
                    <span>Doc:</span>
                    <span id="psDimensionsDisplay">800 × 600 px</span>
                </div>
                <div class="ps-status-item">
                    <span>Tool:</span>
                    <span id="psActiveToolDisplay">BRUSH</span>
                </div>
                <div class="ps-status-item" style="margin-left: auto;">
                    <span>Zoom:</span>
                    <span class="ps-zoom-display">100%</span>
                </div>
            </div>
    </div>

<!-- ══ MEDIA PREVIEW OVERLAY ══ -->
<div id="mediaPreviewOverlay" class="media-preview-overlay" style="display:none;" role="dialog" aria-modal="true" aria-label="Media preview">
    <div class="media-preview-dialog">
        <button id="mediaPreviewClose" class="media-preview-close" title="Close preview" aria-label="Close preview">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div id="mediaPreviewContent" class="media-preview-content"></div>
    </div>
</div>

</main>

<?php require_once __DIR__ . '/includes/security.php'; ?>
<script>const csrfToken = "<?= generate_csrf_token() ?>";</script>

<script src="assets/js/studio.js?v=4.2"></script>
<script src="assets/js/capcut_editor.js?v=1.3"></script>
<script src="assets/js/studio_preview.js?v=1.0"></script>
<script src="assets/js/studio_timeline.js?v=1.0"></script>
<script src="assets/js/studio_captions.js?v=1.0"></script>
<script src="assets/js/studio_filters.js?v=1.0"></script>
<script src="assets/js/studio_effects.js?v=1.0"></script>
<script src="assets/js/studio_transitions.js?v=1.0"></script>
<script src="assets/js/studio_elements.js?v=1.0"></script>
<script src="assets/js/studio_media.js?v=1.0"></script>
<script src="assets/js/studio_mask_cutout.js?v=1.0"></script>
<script src="assets/js/studio_tracking.js?v=1.0"></script>
<script src="assets/js/studio_templates.js?v=1.0"></script>
<script src="assets/js/studio_brand.js?v=1.0"></script>
<script src="assets/js/photoshop_editor.js?v=1.2"></script>

<?php
$extraScripts = '<script src="assets/js/uploader.js?v=1.1"></script>';
include_once 'includes/footer.php';
?>
