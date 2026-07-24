/**
 * assets/js/profile.js
 * Visual togglers and asynchronous AJAX profile photo uploading handler.
 */
document.addEventListener('DOMContentLoaded', () => {
    // Eye toggler helpers for passwords
    const togglePasswordVisibility = (btnId, inputId, iconId) => {
        const btn = document.getElementById(btnId);
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (!btn || !input) return;

        btn.addEventListener('click', () => {
            if (input.type === 'password') {
                input.type = 'text';
                if (icon) icon.className = 'fa-solid fa-eye-slash';
            } else {
                input.type = 'password';
                if (icon) icon.className = 'fa-solid fa-eye';
            }
        });
    };

    togglePasswordVisibility('toggleCur', 'cur-pass', 'eyeCur');
    togglePasswordVisibility('toggleNew', 'new-pass', 'eyeNew');

    // Asynchronous profile picture upload handler
    const profilePicInput = document.getElementById('profilePicInput');
    if (profilePicInput) {
        profilePicInput.addEventListener('change', function(e) {
            const file = this.files[0];
            if (!file) return;
            
            if (file.size > 2 * 1024 * 1024) {
                alert("File size exceeds the 2MB limit.");
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'local_upload');
            formData.append('avatar_file', file);
            
            const imageWrapper = document.getElementById('avatarImageWrapper');
            const originalContent = imageWrapper ? imageWrapper.innerHTML : '';
            
            if (imageWrapper) {
                imageWrapper.innerHTML = `<div class="spinner-border text-info" style="width: 1.5rem; height: 1.5rem;" role="status"></div>`;
            }
            
            fetch('sync_social_profile.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error("HTTP error " + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const objectUrl = URL.createObjectURL(file);
                    if (imageWrapper) {
                        imageWrapper.innerHTML = `<img id="avatarImageElement" src="${objectUrl}" alt="Profile Photo" style="width: 100%; height: 100%; object-fit: cover;">`;
                    }
                    alert("Profile picture updated successfully.");
                } else {
                    if (imageWrapper) imageWrapper.innerHTML = originalContent;
                    alert("Upload failed: " + data.message);
                }
            })
            .catch(err => {
                if (imageWrapper) imageWrapper.innerHTML = originalContent;
                console.error("AJAX Profile Upload Error:", err);
                alert("An error occurred while uploading your profile picture.");
            });
        });
    }
});
