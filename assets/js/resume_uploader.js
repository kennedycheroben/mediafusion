document.addEventListener('DOMContentLoaded', () => {
    const resumeBtns = document.querySelectorAll('.resume-btn');
    
    resumeBtns.forEach(btn => {
        const uploadId = btn.getAttribute('data-id');
        const expectedName = btn.getAttribute('data-filename');
        const expectedSize = parseInt(btn.getAttribute('data-size'));
        
        const fileInput = document.getElementById('file-input-' + uploadId);
        const progressBar = document.getElementById('progress-bar-' + uploadId);
        const statusText = document.getElementById('status-' + uploadId);
        
        const r = new Resumable({
            target: 'backend/upload_handler.php',
            chunkSize: 5 * 1024 * 1024,
            simultaneousUploads: 3,
            testChunks: true, // Crucial for resuming!
            throttleProgressCallbacks: 1,
            query: {
                title: 'Resumed Upload',
                description: 'Resumed from Incomplete Library',
                platforms: '[]' // Adjust as needed or prompt user
            }
        });

        r.assignBrowse(fileInput);

        r.on('fileAdded', function(file) {
            // Verify it's the correct file
            if (file.file.name !== expectedName || file.file.size !== expectedSize) {
                alert(`File mismatch. Please select the exact file: ${expectedName}`);
                r.removeFile(file);
                return;
            }
            
            btn.disabled = true;
            btn.innerHTML = '<span class="neon-spinner" style="width:16px;height:16px;border-width:2px;display:inline-block;margin-right:8px;"></span> Resuming...';
            statusText.innerHTML = '<span class="text-warning">Verifying chunks and uploading...</span>';
            
            r.upload();
        });

        r.on('progress', function() {
            const percent = Math.floor(r.progress() * 100);
            progressBar.style.width = percent + '%';
            statusText.innerHTML = `<span class="text-info">Uploading... ${percent}%</span>`;
        });

        r.on('fileSuccess', function(file, message) {
            progressBar.style.width = '100%';
            progressBar.style.background = 'var(--neon-green)';
            progressBar.style.boxShadow = '0 0 10px var(--neon-green)';
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Completed';
            statusText.innerHTML = '<span class="text-success">Upload successfully resumed and completed!</span>';
            
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        });

        r.on('fileError', function(file, message) {
            alert('Error resuming upload: ' + message);
            progressBar.style.background = '#ff4444';
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Try Again';
            statusText.innerHTML = '<span class="text-danger">Upload failed. Check connection.</span>';
        });
    });

    const discardBtns = document.querySelectorAll('.discard-btn');
    discardBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            if (!confirm('Are you sure you want to discard this upload? This will delete all cached temporary files from the server.')) {
                return;
            }

            const uploadId = btn.getAttribute('data-id');
            const formData = new FormData();
            formData.append('id', uploadId);

            fetch('backend/delete_incomplete.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    window.location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(err => {
                console.error(err);
                alert('An error occurred while discarding the upload.');
            });
        });
    });
});
