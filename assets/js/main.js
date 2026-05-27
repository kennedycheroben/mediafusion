document.addEventListener('DOMContentLoaded', () => {
    // 1. Initialize Locomotive Scroll
    const scrollEl = document.querySelector('[data-scroll-container]');
    let locoScroll = null;
    if (scrollEl) {
        locoScroll = new LocomotiveScroll({
            el: scrollEl,
            smooth: true,
            multiplier: 1.2,
            class: 'is-reveal'
        });

        // 2. Integrate GSAP with Locomotive Scroll
        gsap.registerPlugin(ScrollTrigger);
        
        locoScroll.on('scroll', ScrollTrigger.update);
        
        ScrollTrigger.scrollerProxy(scrollEl, {
            scrollTop(value) {
                return arguments.length ? locoScroll.scrollTo(value, 0, 0) : locoScroll.scroll.instance.scroll.y;
            },
            getBoundingClientRect() {
                return {top: 0, left: 0, width: window.innerWidth, height: window.innerHeight};
            },
            pinType: scrollEl.style.transform ? "transform" : "fixed"
        });
        
        ScrollTrigger.addEventListener('refresh', () => locoScroll.update());
        ScrollTrigger.refresh();
    }

    // 3. GSAP Fade-Ins
    const fadeElements = document.querySelectorAll('.gsap-fade-in');
    fadeElements.forEach((el) => {
        gsap.fromTo(el, 
            { opacity: 0, y: 50 }, 
            { 
                opacity: 1, 
                y: 0, 
                duration: 1, 
                ease: 'power3.out',
                scrollTrigger: locoScroll ? {
                    trigger: el,
                    scroller: scrollEl,
                    start: "top 85%",
                } : null
            }
        );
    });

    // 4. Magnetic Hover Effects for Buttons
    const magneticBtns = document.querySelectorAll('.btn-magnetic');
    magneticBtns.forEach(btn => {
        btn.addEventListener('mousemove', function(e) {
            const position = btn.getBoundingClientRect();
            const x = e.pageX - position.left - position.width / 2;
            const y = e.pageY - position.top - position.height / 2;
            
            gsap.to(btn, {
                x: x * 0.3,
                y: y * 0.5,
                duration: 0.5,
                ease: 'power3.out'
            });
        });
        
        btn.addEventListener('mouseleave', function() {
            gsap.to(btn, {
                x: 0,
                y: 0,
                duration: 0.5,
                ease: 'elastic.out(1, 0.3)'
            });
        });
    });

    // 5. Update Navbar background on scroll
    if (locoScroll) {
        locoScroll.on('scroll', (args) => {
            const nav = document.querySelector('.navbar-glass');
            if(nav) {
                if (args.scroll.y > 50) {
                    nav.style.background = 'rgba(10, 10, 15, 0.8)';
                    nav.style.borderBottom = '1px solid rgba(0, 243, 255, 0.2)';
                } else {
                    nav.style.background = 'var(--glass-bg)';
                    nav.style.borderBottom = '1px solid var(--glass-border)';
                }
            }
        });
    }
// 6. Password Toggle Helper
function initPasswordToggle(btnId, inputId, iconId) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    btn.addEventListener('click', function() {
        const p = document.getElementById(inputId);
        const i = document.getElementById(iconId);
        if(p && i) {
            p.type = p.type === 'password' ? 'text' : 'password';
            i.classList.toggle('fa-eye');
            i.classList.toggle('fa-eye-slash');
        }
    });
}
initPasswordToggle('togglePwd', 'l-pass', 'eyeIcon'); // Login
initPasswordToggle('togglePwdR', 'r-pass', 'eyeIconR'); // Register
initPasswordToggle('toggleCur', 'cur-pass', 'eyeCur'); // Profile current pass
initPasswordToggle('toggleNew', 'new-pass', 'eyeNew'); // Profile new pass

// 7. Password Strength Meter (Register)
const rPass = document.getElementById('r-pass');
if (rPass) {
    rPass.addEventListener('input', function() {
        const v = this.value;
        const fill = document.getElementById('strengthFill');
        const lbl = document.getElementById('strengthLabel');
        if(!fill || !lbl) return;
        
        let score = 0;
        if (v.length >= 8) score++;
        if (/[A-Z]/.test(v)) score++;
        if (/[0-9]/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        
        const levels = [
            {w:'15%', c:'#ff4444', t:'Weak'},
            {w:'40%', c:'#ffaa00', t:'Fair'},
            {w:'70%', c:'#00f3ff', t:'Good'},
            {w:'100%', c:'#00ff66', t:'Strong'}
        ];
        const l = levels[Math.min(score, levels.length-1)];
        fill.style.width = v.length ? l.w : '0'; 
        fill.style.background = l.c;
        lbl.textContent = v.length ? l.t : '';
    });
}

// 8. Confirm Password Match (Register)
const rPass2 = document.getElementById('r-pass2');
if (rPass2) {
    rPass2.addEventListener('input', function() {
        const err = document.getElementById('matchErr');
        if(err && rPass) {
            err.classList.toggle('d-none', this.value === rPass.value);
        }
    });
}

// 9. Login/Register Form Submit States
['loginForm', 'registerForm'].forEach(formId => {
    const form = document.getElementById(formId);
    if(form) {
        form.addEventListener('submit', function(e) {
            if(formId === 'registerForm') {
                const p1 = document.getElementById('r-pass').value;
                const p2 = document.getElementById('r-pass2').value;
                if (p1 !== p2) {
                    e.preventDefault();
                    document.getElementById('matchErr').classList.remove('d-none');
                    return;
                }
            }
            const btn = this.querySelector('button[type="submit"]');
            if(btn) {
                const text = formId === 'loginForm' ? 'Authenticating...' : 'Registering...';
                btn.innerHTML = `<span class="neon-spinner" style="width:16px;height:16px;border-width:2px;display:inline-block;margin-right:8px;"></span>${text}`;
                btn.disabled = true;
            }
        });
    }
});

// 10. Async Profile Update Logic
const profileForm = document.getElementById('profileForm');
if(profileForm) {
    profileForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const btn = document.getElementById('saveBtn');
        const originalBtnHtml = btn.innerHTML;
        btn.innerHTML = '<span class="neon-spinner" style="width:16px;height:16px;border-width:2px;display:inline-block;margin-right:8px;"></span>Saving...';
        btn.disabled = true;

        const formData = new FormData(this);
        
        fetch('backend/profile_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // Remove existing alerts
            document.querySelectorAll('.profile-alert').forEach(el => el.remove());
            
            // Create new alert
            const alertDiv = document.createElement('div');
            alertDiv.className = data.success ? 'success-alert mb-4 profile-alert' : 'danger-alert mb-4 profile-alert';
            alertDiv.innerHTML = data.success 
                ? `<i class="fa-solid fa-circle-check"></i>${data.message}`
                : `<i class="fa-solid fa-triangle-exclamation"></i>${data.message}`;
            
            // Insert alert before the identity card
            const identityCard = document.querySelector('.glass-card');
            identityCard.parentNode.insertBefore(alertDiv, identityCard);
            
            // Update UI if successful display_name change
            if(data.success && data.display_name) {
                document.querySelector('.profile-display-name').textContent = data.display_name;
                document.querySelector('input[name="display_name"]').placeholder = data.display_name;
            }
            
            // Clear password fields
            document.getElementById('cur-pass').value = '';
            document.getElementById('new-pass').value = '';
            
            // Reset button
            btn.innerHTML = originalBtnHtml;
            btn.disabled = false;
        })
        .catch(error => {
            console.error('Error:', error);
            btn.innerHTML = originalBtnHtml;
            btn.disabled = false;
            alert('An unexpected error occurred.');
        });
    });
}

// 11. Real-time Status Polling (History Dashboard)
function startDashboardPolling() {
    setInterval(() => {
        fetch('backend/get_status.php', { credentials: 'same-origin' })
            .then(res => res.json())
            .then(data => {
                if (!data || !data.success || !Array.isArray(data.uploads)) return;
                const table = document.getElementById('uploadsTable');
                if (!table) return;

                const byId = new Map();
                data.uploads.forEach(u => byId.set(String(u.id), u));

                document.querySelectorAll('tr[data-id]').forEach(row => {
                    const id = row.getAttribute('data-id');
                    const u = byId.get(String(id));
                    if (!u) return;
                    const badge = row.querySelector('.status-badge');
                    if (!badge) return;

                    const status = String(u.status || 'unknown').toLowerCase();
                    const icon = (() => {
                        if (status === 'live') return '<i class="fa-solid fa-circle-check"></i>';
                        if (status === 'processing') return '<div class="neon-spinner" style="width:16px; height:16px; border-width: 2px;"></div>';
                        if (status === 'pending') return '<i class="fa-solid fa-clock"></i>';
                        if (status === 'failed') return '<i class="fa-solid fa-triangle-exclamation"></i>';
                        return '<i class="fa-solid fa-clock"></i>';
                    })();

                    badge.classList.remove('status-live', 'status-uploading', 'status-failed');
                    if (status === 'live') badge.classList.add('status-live');
                    else if (status === 'processing') badge.classList.add('status-uploading');
                    else if (status === 'failed') badge.classList.add('status-failed');

                    badge.innerHTML = `${icon} ${status.charAt(0).toUpperCase() + status.slice(1)}`;
                });
            })
            .catch(() => {});
    }, 5000);
}

    if (document.getElementById('uploadsTable')) startDashboardPolling();
});
