document.addEventListener('DOMContentLoaded', () => {
    // 1. Initialize Lenis Smooth Scroll — body as scroller (standard mode)
    let lenis = null;

    try {
        if (typeof Lenis !== 'undefined') {
            lenis = new Lenis({
                duration: 1.2,
                easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
                orientation: 'vertical',
                gestureOrientation: 'vertical',
                smoothWheel: true,
                wheelMultiplier: 1.2,
                touchMultiplier: 1.5,
                infinite: false,
            });
            window.lenis = lenis; // Expose for editor scroll isolation

            // Register GSAP ScrollTrigger with Lenis
            if (typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined') {
                gsap.registerPlugin(ScrollTrigger);
                lenis.on('scroll', ScrollTrigger.update);

                // Coordinate with GSAP's optimized ticker loop for maximum smoothness
                gsap.ticker.add((time) => {
                    lenis.raf(time * 1000);
                });
                gsap.ticker.lagSmoothing(0);
            } else {
                // Fallback to requestAnimationFrame if GSAP is not loaded
                const raf = (time) => {
                    lenis.raf(time);
                    requestAnimationFrame(raf);
                };
                requestAnimationFrame(raf);
            }
        }
    } catch (err) {
        console.error("Lenis smooth scroll initialization failed:", err);
    }

    // Pause/Resume Lenis during Bootstrap modal cycles to prevent back-scrolling issues
    document.addEventListener('show.bs.modal', () => {
        if (lenis) lenis.stop();
    });
    document.addEventListener('hidden.bs.modal', () => {
        if (lenis) lenis.start();
    });


    // 2. Custom Cursor (from animated_scroll) — hardware accelerated via GSAP
    const cursor = document.querySelector('#cursor');
    const cursorBlur = document.querySelector('#cursor-blur');

    if (cursor && cursorBlur && window.innerWidth > 768 && typeof gsap !== 'undefined') {
        // Center the cursor elements on the mouse pointer
        gsap.set(cursor, { xPercent: -50, yPercent: -50, x: window.innerWidth / 2, y: window.innerHeight / 2 });
        gsap.set(cursorBlur, { xPercent: -50, yPercent: -50, x: window.innerWidth / 2, y: window.innerHeight / 2 });

        const xTo = gsap.quickTo(cursor, "x", { duration: 0.15, ease: "power3.out" });
        const yTo = gsap.quickTo(cursor, "y", { duration: 0.15, ease: "power3.out" });
        
        const xBlurTo = gsap.quickTo(cursorBlur, "x", { duration: 0.4, ease: "power3.out" });
        const yBlurTo = gsap.quickTo(cursorBlur, "y", { duration: 0.4, ease: "power3.out" });

        document.addEventListener('mousemove', (e) => {
            xTo(e.clientX);
            yTo(e.clientY);
            xBlurTo(e.clientX);
            yBlurTo(e.clientY);
        });

        const interactiveElements = document.querySelectorAll(
            'a, button, .btn-magnetic, .upload-zone, .platform-switch, .pw-toggle, .social-glow-btn, .footer-link, input[type="submit"], input[type="button"], .img-editor-tool-btn, .img-aspect-btn, .tool-dock-btn'
        );
        interactiveElements.forEach((el) => {
            el.addEventListener('mouseenter', () => {
                gsap.to(cursor, { scale: 2.5, backgroundColor: '#ffffff', duration: 0.2 });
            });
            el.addEventListener('mouseleave', () => {
                gsap.to(cursor, { scale: 1, backgroundColor: 'var(--neon-cyan)', duration: 0.2 });
            });
        });

        // inputs should restore default cursor for usability
        const textInputs = document.querySelectorAll('input[type="text"], input[type="password"], input[type="email"], textarea, select');
        textInputs.forEach((el) => {
            el.addEventListener('mouseenter', () => {
                gsap.to([cursor, cursorBlur], { opacity: 0, duration: 0.15 });
                document.body.style.cursor = 'text';
            });
            el.addEventListener('mouseleave', () => {
                gsap.to([cursor, cursorBlur], { opacity: 1, duration: 0.15 });
                document.body.style.cursor = 'none';
            });
        });
    }

    // 3. Hero timeline reveal animations (from animated_scroll)
    const heroSection = document.querySelector('.hero-section');
    if (heroSection) {
        const heroTL = gsap.timeline({ defaults: { ease: 'power4.out' } });
        
        heroTL
            .from('.hero-title .line .word', {
                y: 120,
                rotation: 5,
                opacity: 0,
                stagger: 0.12,
                duration: 1.2,
            })
            .from('.hero-section .lead', {
                y: 40,
                opacity: 0,
                duration: 0.8,
            }, '-=0.8')
            .from('.hero-section .btn-magnetic', {
                y: 30,
                opacity: 0,
                duration: 0.6,
            }, '-=0.6')
            .from('.orbit-container', {
                scale: 0.8,
                opacity: 0,
                duration: 1.2,
                ease: 'power3.out',
            }, '-=1.0');

        // Parallax scroll on orbit container
        gsap.to('.orbit-container', {
            scrollTrigger: {
                trigger: '.hero-section',
                start: 'top top',
                end: 'bottom top',
                scrub: 1.5,
            },
            y: -80,
            ease: 'none',
        });
    }

    // 4. GSAP Fade-Ins
    const fadeElements = document.querySelectorAll('.gsap-fade-in');
    fadeElements.forEach((el) => {
        gsap.fromTo(el,
            { opacity: 0, y: 50 },
            {
                opacity: 1,
                y: 0,
                duration: 1,
                ease: 'power3.out',
                scrollTrigger: {
                    trigger: el,
                    start: 'top 85%',
                }
            }
        );
    });



    if (lenis) {
        lenis.on('scroll', (e) => {
            const nav = document.querySelector('.navbar-glass');
            if (nav) {
                const scrollY = (e && typeof e.scroll === 'number') ? e.scroll : (lenis ? lenis.scroll : window.scrollY);
                if (scrollY > 50) {
                    nav.style.background = 'rgba(10, 10, 15, 0.85)';
                    nav.style.backdropFilter = 'blur(20px)';
                    nav.style.borderBottom = '1px solid rgba(0, 243, 255, 0.2)';
                } else {
                    nav.style.background = 'var(--glass-bg)';
                    nav.style.backdropFilter = 'blur(12px)';
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
// Optimized: 8s interval, ETag caching, request deduplication, visibility-aware
function startDashboardPolling() {
    let lastEtag = null;
    let pollInFlight = false;
    let intervalId = null;

    function poll() {
        if (pollInFlight) return;
        if (document.hidden) return;

        pollInFlight = true;
        const options = {
            credentials: 'same-origin',
            headers: {}
        };
        if (lastEtag) options.headers['If-None-Match'] = lastEtag;

        fetch('backend/get_status.php', options)
            .then(res => {
                if (res.status === 304) { pollInFlight = false; return null; }
                const newEtag = res.headers.get('ETag');
                if (newEtag) lastEtag = newEtag;
                return res.json();
            })
            .then(data => {
                pollInFlight = false;
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
            .catch(() => { pollInFlight = false; });
    }

    intervalId = setInterval(poll, 8000);

    // Pause polling when tab is hidden, resume when visible
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearInterval(intervalId);
        } else {
            poll();
            intervalId = setInterval(poll, 8000);
        }
    });
}

    if (document.getElementById('uploadsTable')) startDashboardPolling();
});
