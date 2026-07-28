/**
 * assets/js/about.js
 * Interactive canvas animation stream loop and controller bindings for about.php tutorial preview.
 */
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('tutorialCanvas');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const playPauseBtn = document.getElementById('playPauseBtn');
    const playIcon = document.getElementById('playIcon');
    const playerTimeCode = document.getElementById('playerTimeCode');
    const scrubProgress = document.getElementById('scrubProgress');
    const scrubThumb = document.getElementById('scrubThumb');
    const scrubContainer = document.getElementById('scrubContainer');
    
    let isPlaying = false;
    let currentTime = 0;
    const totalDuration = 30; // 30 seconds tutorial simulation
    let animationFrameId = null;
    let lastTimestamp = 0;
    
    // Custom canvas graphics nodes for simulation
    const connectionNodes = [
        { x: 200, y: 150, radius: 8, color: '#00f3ff', label: 'Local Upload' },
        { x: 450, y: 150, radius: 12, color: '#ff00ff', label: 'MediaFusion Core' },
        { x: 700, y: 80, radius: 8, color: '#ff0000', label: 'YouTube API' },
        { x: 700, y: 150, radius: 8, color: '#00f3ff', label: 'TikTok SDK' },
        { x: 700, y: 220, radius: 8, color: '#1877f2', label: 'Meta Graph' }
    ];

    // Resize function to map resolution beautifully
    function resizeCanvas() {
        const rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * window.devicePixelRatio;
        canvas.height = rect.height * window.devicePixelRatio;
        ctx.scale(window.devicePixelRatio, window.devicePixelRatio);
    }
    
    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    function drawWaveform(time) {
        ctx.beginPath();
        ctx.strokeStyle = 'rgba(0, 243, 255, 0.4)';
        ctx.lineWidth = 2;
        const w = canvas.width / window.devicePixelRatio;
        const h = canvas.height / window.devicePixelRatio;
        
        for (let i = 0; i < w; i += 8) {
            const amp = Math.sin(i * 0.02 + time * 0.005) * Math.cos(i * 0.005 + time * 0.002) * 35;
            ctx.moveTo(i, h - 60 - amp);
            ctx.lineTo(i, h - 60 + amp);
        }
        ctx.stroke();
    }

    function draw(timestamp) {
        if (!lastTimestamp) lastTimestamp = timestamp;
        const elapsed = (timestamp - lastTimestamp) / 1000;
        lastTimestamp = timestamp;
        
        if (isPlaying) {
            currentTime += elapsed;
            if (currentTime >= totalDuration) {
                currentTime = 0;
                isPlaying = false;
                if (playIcon) playIcon.className = 'fa-solid fa-play';
            }
        }
        
        // Map to UI elements
        const progressPct = (currentTime / totalDuration) * 100;
        if (scrubProgress) scrubProgress.style.width = progressPct + '%';
        if (scrubThumb) scrubThumb.style.left = progressPct + '%';
        
        // Format timecode strings
        const curMinutes = Math.floor(currentTime / 60).toString().padStart(2, '0');
        const curSeconds = Math.floor(currentTime % 60).toString().padStart(2, '0');
        if (playerTimeCode) {
            playerTimeCode.innerText = `${curMinutes}:${curSeconds} / 00:30`;
        }
        
        // Clear Canvas
        const w = canvas.width / window.devicePixelRatio;
        const h = canvas.height / window.devicePixelRatio;
        ctx.fillStyle = '#07070c';
        ctx.fillRect(0, 0, w, h);
        
        // Draw background tech grid lines
        ctx.strokeStyle = 'rgba(0, 243, 255, 0.03)';
        ctx.lineWidth = 1;
        for (let i = 0; i < w; i += 40) {
            ctx.beginPath();
            ctx.moveTo(i, 0);
            ctx.lineTo(i, h);
            ctx.stroke();
        }
        for (let j = 0; j < h; j += 40) {
            ctx.beginPath();
            ctx.moveTo(0, j);
            ctx.lineTo(w, j);
            ctx.stroke();
        }
        
        // Draw waveform audio monitoring
        drawWaveform(timestamp);
        
        // Draw Node Connectors lines
        ctx.strokeStyle = 'rgba(255,255,255,0.08)';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(connectionNodes[0].x, connectionNodes[0].y);
        ctx.lineTo(connectionNodes[1].x, connectionNodes[1].y);
        ctx.lineTo(connectionNodes[2].x, connectionNodes[2].y);
        ctx.moveTo(connectionNodes[1].x, connectionNodes[1].y);
        ctx.lineTo(connectionNodes[3].x, connectionNodes[3].y);
        ctx.moveTo(connectionNodes[1].x, connectionNodes[1].y);
        ctx.lineTo(connectionNodes[4].x, connectionNodes[4].y);
        ctx.stroke();
        
        // Dynamic pulse scanning
        const pulse = (Math.sin(timestamp * 0.005) + 1) / 2;
        
        // Draw glowing node points
        connectionNodes.forEach((node, idx) => {
            ctx.beginPath();
            ctx.fillStyle = node.color;
            ctx.arc(node.x, node.y, node.radius + (idx === 1 ? pulse * 4 : pulse * 2), 0, Math.PI * 2);
            ctx.shadowColor = node.color;
            ctx.shadowBlur = 15;
            ctx.fill();
            ctx.shadowBlur = 0; // reset
            
            // Label nodes
            ctx.fillStyle = '#8f9cae';
            ctx.font = 'bold 9px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(node.label, node.x, node.y - node.radius - 8);
        });
        
        // Draw floating instructional overlays relative to timeline
        ctx.fillStyle = '#fff';
        ctx.font = 'bold 16px Courier New';
        ctx.textAlign = 'left';
        
        let statusText = "STANDBY // PRESS PLAY TO INITIATE";
        if (isPlaying) {
            if (currentTime < 8) {
                statusText = "STEP 1: BROWSE MEDIA & SYNC METADATA";
            } else if (currentTime < 16) {
                statusText = "STEP 2: PREVIEW FRAME & SLIDE TO TRIM";
            } else if (currentTime < 24) {
                statusText = "STEP 3: DEPLOY BACKEND FFmpeg RENDER";
            } else {
                statusText = "STEP 4: VAULT DISTRIBUTION COMPLETE";
            }
        }
        
        ctx.fillText(statusText, 35, 45);
        
        // Small overlay metadata indicators
        ctx.fillStyle = 'rgba(0, 243, 255, 0.4)';
        ctx.font = '9px monospace';
        ctx.fillText(`MEM: 12.4GB // ENGINE: FFmpeg-v6.0 // ACTIVE_VAULT: OK`, w - 300, 45);

        animationFrameId = requestAnimationFrame(draw);
    }

    // Toggle state function
    function togglePlayback() {
        isPlaying = !isPlaying;
        if (isPlaying) {
            if (playIcon) playIcon.className = 'fa-solid fa-pause';
            lastTimestamp = 0;
        } else {
            if (playIcon) playIcon.className = 'fa-solid fa-play';
        }
    }
    
    if (playPauseBtn) playPauseBtn.addEventListener('click', togglePlayback);
    
    // Manual timeline scrubbing
    if (scrubContainer) {
        scrubContainer.addEventListener('click', function(e) {
            const rect = scrubContainer.getBoundingClientRect();
            const clickX = e.clientX - rect.left;
            const pct = Math.min(Math.max(clickX / rect.width, 0), 1);
            currentTime = pct * totalDuration;
            
            if (!isPlaying) {
                lastTimestamp = 0;
            }
        });
    }

    // Start animation frame loop
    animationFrameId = requestAnimationFrame(draw);
});
