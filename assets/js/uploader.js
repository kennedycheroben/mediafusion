document.addEventListener('DOMContentLoaded', () => {
    const browseFileBtn = document.getElementById('browseFileBtn');
    const uploadZone = document.getElementById('uploadZone');
    const videoPreviewContainer = document.getElementById('videoPreviewContainer');
    const videoPreview = document.getElementById('videoPreview');
    const uploadProgress = document.getElementById('uploadProgress');
    const uploadProgressBar = document.getElementById('uploadProgressBar');
    const uploadBtn = document.getElementById('uploadBtn');
    
    if (!browseFileBtn || !uploadZone) return;

    // 1. Live Preview Logic
    let selectedFile = null;

    const handleFileSelect = (file) => {
        if (file && file.type.startsWith('video/')) {
            selectedFile = file;
            const fileURL = URL.createObjectURL(file);
            videoPreview.src = fileURL;
            videoPreviewContainer.style.display = 'block';
            uploadZone.querySelector('h3').textContent = file.name;
        } else {
            alert('Please select a valid video file.');
        }
    };

    // 2. Drag & Drop interactions
    uploadZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadZone.classList.add('dragover');
    });

    uploadZone.addEventListener('dragleave', () => {
        uploadZone.classList.remove('dragover');
    });

    uploadZone.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadZone.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            handleFileSelect(e.dataTransfer.files[0]);
            // Manually add the file to Resumable later
        }
    });

    // 3. Initialize Resumable.js
    const r = new Resumable({
        target: 'backend/upload_handler.php',
        chunkSize: 5 * 1024 * 1024, // 5MB chunks
        simultaneousUploads: 3,
        testChunks: false,
        throttleProgressCallbacks: 1,
    });

    r.assignBrowse(browseFileBtn);
    r.assignDrop(uploadZone);

    r.on('fileAdded', function(file){
        handleFileSelect(file.file);
    });

    // 4. Handle form submission
    uploadBtn.addEventListener('click', () => {
        if (!r.files.length) {
            alert('Please select a file first.');
            return;
        }

        const title = document.getElementById('videoTitle').value;
        const desc = document.getElementById('videoDesc').value;
        
        const platforms = [];
        if (document.getElementById('platformYT').checked)  platforms.push('youtube');
        if (document.getElementById('platformTT').checked)  platforms.push('tiktok');
        if (document.getElementById('platformFB').checked)  platforms.push('facebook');
        if (document.getElementById('platformIG').checked)  platforms.push('instagram');

        if (platforms.length === 0) {
            alert('Please select at least one platform.');
            return;
        }

        if (!title) {
            alert('Please provide a title.');
            return;
        }

        // Attach metadata to the resumable query
        r.opts.query = {
            title: title,
            description: desc,
            platforms: JSON.stringify(platforms)
        };

        uploadProgress.style.display = 'block';
        r.upload();
    });

    // 5. Resumable Events
    r.on('progress', function() {
        const percent = Math.floor(r.progress() * 100);
        uploadProgressBar.style.width = percent + '%';
    });

    r.on('fileSuccess', function(file, message){
        uploadProgressBar.style.width = '100%';
        uploadProgressBar.style.background = 'var(--neon-green)';
        uploadProgressBar.style.boxShadow = '0 0 10px var(--neon-green)';
        
        setTimeout(() => {
            alert('Upload completed');
            window.location.href = 'history.php';
        }, 1000);
    });

    r.on('fileError', function(file, message){
        alert('Error uploading file: ' + message);
        uploadProgressBar.style.background = '#ff4444';
        uploadProgressBar.style.boxShadow = '0 0 10px #ff4444';
    });
});

// Dashboard Polling (For history.php)
function startDashboardPolling() {
    setInterval(() => {
        fetch('backend/get_status.php', { credentials: 'same-origin' })
            .then(res => res.json())
            .then(data => {
                // Assuming data is an array of upload statuses
                // Update the DOM dynamically here
                // This would trigger a re-render of the table rows based on the ID
                console.log("Polled statuses:", data);
                // Implementation depends on the exact table structure in history.php
            })
            .catch(err => console.error("Polling error", err));
    }, 5000);
}
