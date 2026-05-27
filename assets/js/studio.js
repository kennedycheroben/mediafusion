/**
 * assets/js/studio.js
 * Media captions processing, chunked upload modes, and CapCut-style dual-slider video trimming controllers.
 */

/**
 * Swaps modes between Standard Chunked Uploads and Advanced Media Caption Studio
 */
function toggleStudioMode(mode) {
    const stdBtn = document.getElementById('modeStandardBtn');
    const stdWrk = document.getElementById('workspaceStandard');
    const stuBtn = document.getElementById('modeStudioBtn');
    const stuWrk = document.getElementById('workspaceStudio');

    if (stdBtn) stdBtn.classList.remove('active');
    if (stuBtn) stuBtn.classList.remove('active');
    if (stdWrk) stdWrk.classList.add('d-none');
    if (stuWrk) stuWrk.classList.add('d-none');

    if (mode === 'standard') {
        if (stdBtn) stdBtn.classList.add('active');
        if (stdWrk) stdWrk.classList.remove('d-none');
    } else {
        if (stuBtn) stuBtn.classList.add('active');
        if (stuWrk) stuWrk.classList.remove('d-none');
    }
}

/**
 * Toggles specific parameters panel based on Image/Video category
 */
function toggleStudioControls(category) {
    const vidControls = document.getElementById('studioVideoControls');
    const imgControls = document.getElementById('studioImageControls');
    const imgCanvasWrap = document.getElementById('imgEditorCanvasWrap');
    const vidControlsCenter = document.getElementById('studioVideoControlsCenter');
    
    if (vidControls) vidControls.classList.add('d-none');
    if (imgControls) imgControls.classList.add('d-none');
    if (imgCanvasWrap) imgCanvasWrap.classList.add('d-none');
    if (vidControlsCenter) vidControlsCenter.classList.add('d-none');
    
    // Update input accepts
    const fileInput = document.getElementById('studioMediaFileInput');

    if (category === 'process_video') {
        if (vidControls) vidControls.classList.remove('d-none');
        if (vidControlsCenter) vidControlsCenter.classList.remove('d-none');
        if (fileInput) fileInput.accept = "video/*";
    } else {
        if (imgControls) imgControls.classList.remove('d-none');
        if (imgCanvasWrap) imgCanvasWrap.classList.remove('d-none');
        if (fileInput) fileInput.accept = "image/*";
    }
}

/**
 * Dispatches media parameters to process_studio_media.php via AJAX
 */
function executeStudioProcess(event) {
    event.preventDefault();
    
    const form = document.getElementById('studioProcessingForm');
    if (!form) return;
    
    const formData = new FormData(form);
    
    // Map file input properly depending on selected category
    const fileInput = document.getElementById('studioMediaFileInput');
    const categorySelect = document.getElementById('studioCategorySelect');
    const category = categorySelect ? categorySelect.value : 'process_video';

    if (!fileInput || fileInput.files.length === 0) {
        alert("Please select a raw media file to process.");
        return;
    }

    // Validate file size (max 500MB)
    const maxFileSize = 500 * 1024 * 1024;
    if (fileInput.files[0].size > maxFileSize) {
        alert("File size exceeds 500MB limit. Please select a smaller file.");
        return;
    }

    // Ensure action is set in formData
    formData.set('action', category);

    if (category === 'process_video') {
        formData.append('video_file', fileInput.files[0]);
    } else {
        formData.append('image_file', fileInput.files[0]);
    }

    // Show loading panels
    const placeholder = document.getElementById('studioPreviewPlaceholder');
    const output = document.getElementById('studioPreviewOutput');
    const loader = document.getElementById('studioPreviewLoader');
    const distributionCard = document.getElementById('studioDistributionCard');

    if (placeholder) placeholder.classList.add('d-none');
    if (output) output.classList.add('d-none');
    if (loader) loader.classList.remove('d-none');
    if (distributionCard) distributionCard.classList.add('d-none');

    fetch('process_studio_media.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error("HTTP " + response.status + ": FFmpeg compositing process failure.");
        }
        return response.json();
    })
    .then(data => {
        if (loader) loader.classList.add('d-none');
        
        if (data.success) {
            if (output) output.classList.remove('d-none');
            
            const pVidContainer = document.getElementById('studioPreviewVideoContainer');
            const pImgContainer = document.getElementById('studioPreviewImageContainer');
            
            if (category === 'process_video') {
                if (pVidContainer) pVidContainer.classList.remove('d-none');
                if (pImgContainer) pImgContainer.classList.add('d-none');
                
                const video = document.getElementById('studioPreviewVideo');
                if (video) {
                    video.src = data.output_url;
                    video.load();
                }
            } else {
                if (pVidContainer) pVidContainer.classList.add('d-none');
                if (pImgContainer) pImgContainer.classList.remove('d-none');
                
                const image = document.getElementById('studioPreviewImage');
                if (image) image.src = data.output_url;
            }

            // Hydrate details into final distribution card
            const processedPathInput = document.getElementById('processedFilePath');
            if (processedPathInput) processedPathInput.value = data.output_url;
            if (distributionCard) distributionCard.classList.remove('d-none');
        } else {
            if (placeholder) placeholder.classList.remove('d-none');
            if (output) output.classList.add('d-none');
            const errorMsg = data.message || "Unknown error occurred.";
            alert("Media Processing Failed: " + errorMsg);
            console.error("Studio processing response error:", data);
        }
    })
    .catch(err => {
        if (loader) loader.classList.add('d-none');
        if (placeholder) placeholder.classList.remove('d-none');
        if (output) output.classList.add('d-none');
        console.error("Studio processing failure:", err);
        alert("Error: " + err.message + "\n\nPossible causes:\n- File too large\n- Unsupported format\n- Server error\n- FFmpeg not installed");
    });
}

/**
 * Submits metadata details and the pre-processed file path to backend/direct_distribution.php
 */
function submitDirectDistribution(event) {
    event.preventDefault();
    
    const form = document.getElementById('studioDirectForm');
    if (!form) return;
    const formData = new FormData(form);

    fetch('backend/direct_distribution.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error("HTTP connection error " + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.href = 'history.php';
        } else {
            alert("Distribution Failed: " + data.message);
        }
    })
    .catch(err => {
        console.error("Distribution trigger failed:", err);
        alert("Database write error occurred during distribution initiation.");
    });
}

// ----------------------------------------------------
// CapCut-Style Video Trimming & Frame Preview Layout
// ----------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const mediaInput = document.getElementById('studioMediaFileInput');
    const categorySelect = document.getElementById('studioCategorySelect');
    const videoPreview = document.getElementById('studioVideoPreview');
    const previewPlaceholder = document.getElementById('studioVideoPreviewPlaceholder');
    const previewContainer = document.getElementById('studioVideoPreviewContainer');
    
    const startHandle = document.getElementById('studioStartHandle');
    const endHandle = document.getElementById('studioEndHandle');
    const startThumb = document.getElementById('studioStartThumb');
    const endThumb = document.getElementById('studioEndThumb');
    const trackFill = document.getElementById('studioRangeTrackFill');
    
    const startDisplay = document.getElementById('studioStartDisplay');
    const endDisplay = document.getElementById('studioEndDisplay');
    const durationDisplay = document.getElementById('studioDurationDisplay');
    
    const startTimeInput = document.getElementById('studioStartTimeInput');
    const endTimeInput = document.getElementById('studioEndTimeInput');
    const sliderContainer = document.getElementById('studioDualRangeSlider');

    function updateSliderUI() {
        if (!videoPreview || isNaN(videoPreview.duration)) return;
        
        const duration = videoPreview.duration;
        const startVal = parseFloat(startHandle.value);
        const endVal = parseFloat(endHandle.value);
        
        const startPct = (startVal / duration) * 100;
        const endPct = (endVal / duration) * 100;
        
        // Position visual thumbs
        if (startThumb) startThumb.style.left = startPct + '%';
        if (endThumb) {
            endThumb.style.right = 'auto'; // Clear original CSS right position
            endThumb.style.left = endPct + '%';
        }
        
        if (trackFill) {
            trackFill.style.left = startPct + '%';
            trackFill.style.width = (endPct - startPct) + '%';
        }
        
        // Set text displays
        if (startDisplay) startDisplay.innerText = startVal.toFixed(1) + 's';
        if (endDisplay) endDisplay.innerText = endVal.toFixed(1) + 's';
        if (durationDisplay) durationDisplay.innerText = '(Duration: ' + (endVal - startVal).toFixed(1) + 's)';
        
        // Bind values directly to the hidden inputs for FFmpeg
        if (startTimeInput) startTimeInput.value = startVal.toFixed(2);
        if (endTimeInput) endTimeInput.value = endVal.toFixed(2);
    }

    if (mediaInput) {
        let previousVideoUrl = null;
        
        mediaInput.addEventListener('change', function() {
            const file = this.files[0];
            if (!file) return;
            
            // Validate file type
            if (!file.type.startsWith('video/') && !file.type.startsWith('image/')) {
                alert('Please select a valid video or image file.');
                this.value = '';
                return;
            }
            
            const category = categorySelect ? categorySelect.value : 'process_video';
            
            if (category === 'process_video' && file.type.startsWith('video/')) {
                // Clean up previous URL
                if (previousVideoUrl) {
                    URL.revokeObjectURL(previousVideoUrl);
                }
                
                const objectUrl = URL.createObjectURL(file);
                previousVideoUrl = objectUrl;
                
                if (videoPreview) {
                    videoPreview.src = objectUrl;
                    
                    // Reveal video workspace & hide placeholder
                    if (previewPlaceholder) previewPlaceholder.style.display = 'none';
                    if (previewContainer) previewContainer.style.display = 'block';
                    
                    // Initialize controls when video metadata loads
                    videoPreview.onloadedmetadata = function() {
                        const duration = videoPreview.duration;
                        
                        if (startHandle && endHandle) {
                            startHandle.min = 0;
                            startHandle.max = duration;
                            startHandle.value = 0;
                            
                            endHandle.min = 0;
                            endHandle.max = duration;
                            endHandle.value = duration;
                        }
                        
                        updateSliderUI();
                    };
                }
            }
        });
    }

    // Attach event listeners to dual slider inputs
    if (startHandle) {
        startHandle.addEventListener('input', function() {
            const endVal = parseFloat(endHandle.value);
            let startVal = parseFloat(this.value);
            
            if (startVal >= endVal) {
                startVal = endVal - 0.1;
                this.value = startVal;
            }
            
            updateSliderUI();
            
            if (videoPreview) {
                videoPreview.currentTime = startVal;
            }
        });
    }

    if (endHandle) {
        endHandle.addEventListener('input', function() {
            const startVal = parseFloat(startHandle.value);
            let endVal = parseFloat(this.value);
            
            if (endVal <= startVal) {
                endVal = startVal + 0.1;
                this.value = endVal;
            }
            
            updateSliderUI();
            
            if (videoPreview) {
                videoPreview.currentTime = endVal;
            }
        });
    }

    // Handle z-index sorting on clicking/tapping closest thumb
    if (sliderContainer && startHandle && endHandle) {
        sliderContainer.addEventListener('pointerdown', function(e) {
            if (!videoPreview || isNaN(videoPreview.duration)) return;
            
            const duration = videoPreview.duration;
            const rect = sliderContainer.getBoundingClientRect();
            const clickX = e.clientX - rect.left;
            const pct = clickX / rect.width;
            const clickVal = pct * duration;
            
            const startVal = parseFloat(startHandle.value);
            const endVal = parseFloat(endHandle.value);
            
            const distToStart = Math.abs(clickVal - startVal);
            const distToEnd = Math.abs(clickVal - endVal);
            
            if (distToStart < distToEnd) {
                startHandle.style.zIndex = "5";
                endHandle.style.zIndex = "4";
            } else {
                startHandle.style.zIndex = "4";
                endHandle.style.zIndex = "5";
            }
        });
    }

    // Sync initial controls on page load based on current selection
    if (categorySelect) {
        toggleStudioControls(categorySelect.value);
    }
});

// ─────────────────────────────────────────────────────────
// WhatsApp-Style Caption Overlay Controller
// ─────────────────────────────────────────────────────────
(function () {
    const waCaptionField     = document.getElementById('waCaptionField');
    const captionHiddenInput = document.getElementById('captionHiddenInput');
    const waEditorWrap       = document.getElementById('waEditorWrap');
    const waPlaceholderState = document.getElementById('waPlaceholderState');
    const waNoFileHint       = document.getElementById('waNoFileHint');
    const waCaptionVideo     = document.getElementById('waCaptionVideoPreview');
    const waCaptionImage     = document.getElementById('waCaptionImagePreview');
    const waEmojiToggle      = document.getElementById('waEmojiToggle');
    const waEmojiPanel       = document.getElementById('waEmojiPanel');
    const mediaFileInput     = document.getElementById('studioMediaFileInput');
    const categorySelect     = document.getElementById('studioCategorySelect');

    // ── Sync overlay input → hidden form field in real-time ──
    if (waCaptionField && captionHiddenInput) {
        waCaptionField.addEventListener('input', function () {
            captionHiddenInput.value = this.value;
        });
    }

    // ── Clicking the editor wrap focuses the caption input ──
    if (waEditorWrap && waCaptionField) {
        waEditorWrap.addEventListener('click', function (e) {
            if (!e.target.classList.contains('wa-emoji-option') && e.target !== waEmojiToggle) {
                waCaptionField.focus();
            }
        });
    }

    // ── Load media into the overlay background when file is selected ──
    if (mediaFileInput) {
        mediaFileInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;

            const category  = categorySelect ? categorySelect.value : 'process_video';
            const objectUrl = URL.createObjectURL(file);

            if (waPlaceholderState) waPlaceholderState.style.display = 'none';
            if (waNoFileHint)       waNoFileHint.style.display       = 'none';

            if (category === 'process_video' && file.type.startsWith('video/')) {
                if (waCaptionImage) waCaptionImage.style.display = 'none';
                if (waCaptionVideo) {
                    waCaptionVideo.src = objectUrl;
                    waCaptionVideo.style.display = 'block';
                    waCaptionVideo.play().catch(function () {});
                }
            } else if (category === 'process_image' && file.type.startsWith('image/')) {
                if (waCaptionVideo) {
                    waCaptionVideo.pause();
                    waCaptionVideo.style.display = 'none';
                }
                if (waCaptionImage) {
                    waCaptionImage.src = objectUrl;
                    waCaptionImage.style.display = 'block';
                }
            }

            if (waCaptionField) waCaptionField.focus();
        });
    }

    // ── Reset preview when category switches ──
    if (categorySelect) {
        categorySelect.addEventListener('change', function () {
            if (waCaptionVideo) { waCaptionVideo.pause(); waCaptionVideo.style.display = 'none'; waCaptionVideo.src = ''; }
            if (waCaptionImage) { waCaptionImage.style.display = 'none'; waCaptionImage.src = ''; }
            if (waPlaceholderState) waPlaceholderState.style.display = '';
            if (waNoFileHint)       waNoFileHint.style.display       = '';
        });
    }

    // ── Emoji picker toggle ──
    if (waEmojiToggle && waEmojiPanel) {
        waEmojiToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            waEmojiPanel.classList.toggle('open');
        });

        waEmojiPanel.querySelectorAll('.wa-emoji-option').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.stopPropagation();
                if (waCaptionField) {
                    var pos    = waCaptionField.selectionStart || waCaptionField.value.length;
                    var before = waCaptionField.value.slice(0, pos);
                    var after  = waCaptionField.value.slice(pos);
                    waCaptionField.value = before + this.textContent + after;
                    waCaptionField.dispatchEvent(new Event('input'));
                    waCaptionField.focus();
                    var newPos = pos + this.textContent.length;
                    waCaptionField.setSelectionRange(newPos, newPos);
                }
                waEmojiPanel.classList.remove('open');
            });
        });

        document.addEventListener('click', function (e) {
            if (waEmojiPanel.classList.contains('open') &&
                !waEmojiPanel.contains(e.target) &&
                e.target !== waEmojiToggle) {
                waEmojiPanel.classList.remove('open');
            }
        });
    }
}());

// Photoshop Workbench Left Sidebar Tool Dock Interactions
window.selectDockTool = function (tool) {
    document.querySelectorAll('.tool-dock-btn').forEach(btn => btn.classList.remove('active'));
    
    if (tool === 'pointer') {
        const pointerBtn = document.getElementById('toolDockPointer');
        if (pointerBtn) pointerBtn.classList.add('active');
        const moveBtn = document.getElementById('imgToolMove');
        if (moveBtn) moveBtn.click();
    } else if (tool === 'crop') {
        const cropBtnDock = document.getElementById('toolDockCrop');
        if (cropBtnDock) cropBtnDock.classList.add('active');
        const cropBtn = document.getElementById('imgToolCrop');
        if (cropBtn) cropBtn.click();
        
        const cropPanel = document.getElementById('imgAspectBtns');
        if (cropPanel) {
            cropPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
            cropPanel.style.outline = '2px solid #00f3ff';
            setTimeout(() => { cropPanel.style.outline = 'none'; }, 1500);
        }
    } else if (tool === 'text') {
        const textBtn = document.getElementById('toolDockText');
        if (textBtn) textBtn.classList.add('active');
        const capField = document.getElementById('waCaptionField');
        if (capField) {
            capField.focus();
            capField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    } else if (tool === 'adjust') {
        const adjustBtn = document.getElementById('toolDockAdjust');
        if (adjustBtn) adjustBtn.classList.add('active');
        const brightRange = document.getElementById('imgBrightnessRange');
        if (brightRange) {
            brightRange.scrollIntoView({ behavior: 'smooth', block: 'center' });
            const brightParent = brightRange.parentElement;
            if (brightParent) {
                brightParent.style.outline = '2px solid #00f3ff';
                brightParent.style.outlineOffset = '4px';
                brightParent.style.borderRadius = '4px';
                setTimeout(() => { brightParent.style.outline = 'none'; }, 1500);
            }
        }
    }
};

