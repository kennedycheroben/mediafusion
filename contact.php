<?php
declare(strict_types=1);

/**
 * contact.php — Customer Service & Support page.
 * - Integrates with global header.php and includes/footer.php.
 * - Provides direct support channels (Email, WhatsApp).
 * - Links customer support to platform social media networks.
 * - Features an interactive, premium contact inquiry form with rich validation and GSAP effects.
 */

$pageTitle  = 'Support & Contact - MediaFusion';
$activePage = 'contact';

// Page-specific SEO overrides
$metaDescription = 'Contact the MediaFusion support team. Connect via WhatsApp, Email, or our social channels for instant assistance with sharing your media.';
$metaKeywords = 'customer service, contact support, WhatsApp support, email support, MediaFusion help';

include 'header.php';

$contactNameVal = '';
$contactEmailVal = '';
if (isset($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/backend/db.php';
        $stmt = $pdo->prepare("SELECT username, display_name, email FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $currUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($currUser) {
            $contactNameVal = htmlspecialchars($currUser['display_name'] ?? $currUser['username'] ?? '');
            $contactEmailVal = htmlspecialchars($currUser['email'] ?? '');
        }
    } catch (Exception $e) {
        error_log("Failed to load user info for contact form: " . $e->getMessage());
    }
}
?>
<main class="py-5" style="background: var(--bg-color); min-height: 100vh; padding-top: 100px !important;">
    <div class="container py-4">
        
        <!-- Header Section -->
        <div class="text-center mb-5 mt-2 gsap-fade-in">
            <span class="badge bg-primary text-white text-uppercase py-2 px-3 mb-3" style="letter-spacing: 2px; font-size: 0.7rem;">Help Center</span>
            <h1 class="glowing-title text-gradient-cyan-magenta mb-2" style="font-size: 2.5rem; font-weight: 800;">Get in Touch</h1>
            <p class="text-secondary mx-auto" style="max-width: 600px; font-size: 1.05rem; line-height: 1.6;">
                Have questions about your social accounts or connections? Our support team is online and ready to assist you.
            </p>
        </div>

        <div class="row g-4 justify-content-center">
            
            <!-- Left Panel: Support Cards & Socials -->
            <div class="col-lg-5 gsap-fade-in-left">
                
                <!-- Email Support Card -->
                <div class="glass-card mb-4 card-channel">
                    <div class="d-flex align-items-center gap-3">
                        <div class="channel-icon-wrapper email-icon">
                            <i class="fa-solid fa-envelope"></i>
                        </div>
                        <div>
                            <h4 class="mb-1" style="font-size: 1.15rem; font-weight: 700;">Email Help Desk</h4>
                            <p class="text-secondary small mb-0">Direct support for administrative & account inquiries.</p>
                            <a href="mailto:kennedycheroben001@mail.com" class="channel-link mt-2 d-inline-flex align-items-center">
                                kennedycheroben001@mail.com <i class="fa-solid fa-arrow-up-right-from-square ms-2" style="font-size: 0.8rem;"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- WhatsApp Support Card -->
                <div class="glass-card mb-4 card-channel">
                    <div class="d-flex align-items-center gap-3">
                        <div class="channel-icon-wrapper wa-icon">
                            <i class="fa-brands fa-whatsapp"></i>
                        </div>
                        <div>
                            <h4 class="mb-1" style="font-size: 1.15rem; font-weight: 700;">WhatsApp Support</h4>
                            <p class="text-secondary small mb-0">Chat live with a support representative instantly.</p>
                            <a href="https://wa.me/254792399815" target="_blank" class="channel-link mt-2 d-inline-flex align-items-center text-success-glow">
                                +254 792 399 815 <i class="fa-solid fa-comment-dots ms-2" style="font-size: 0.8rem;"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Social Support & Community Card -->
                <div class="glass-card mb-4">
                    <h4 class="mb-3 text-gradient-cyan" style="font-size: 1.1rem; font-weight: 700;">
                        <i class="fa-solid fa-users me-2"></i>Social Support
                    </h4>
                    <p class="text-secondary small mb-4">
                        Connect with us, follow updates, and direct-message our teams across all major platforms.
                    </p>
                    <div class="d-flex flex-wrap gap-3 justify-content-start">
                        <a href="https://web.facebook.com/profile.php?id=61589997259788" target="_blank" class="social-support-pill pill-fb">
                            <i class="fa-brands fa-facebook-f me-2"></i>Facebook
                        </a>
                        <a href="https://www.instagram.com/MediaFusion" target="_blank" class="social-support-pill pill-ig">
                            <i class="fa-brands fa-instagram me-2"></i>Instagram
                        </a>
                        <a href="https://www.youtube.com/@MediaFusion" target="_blank" class="social-support-pill pill-yt">
                            <i class="fa-brands fa-youtube me-2"></i>YouTube
                        </a>
                        <a href="https://www.tiktok.com/@MediaFusion" target="_blank" class="social-support-pill pill-tt">
                            <i class="fa-brands fa-tiktok me-2"></i>TikTok
                        </a>
                    </div>
                </div>

            </div>

            <!-- Right Panel: Sleek Inquiry Form -->
            <div class="col-lg-7 gsap-fade-in-right">
                <div class="glass-card p-4 p-md-5 card-magenta">
                    <div class="mb-4">
                        <h3 class="mb-2" style="font-size: 1.3rem; font-weight: 700;">
                            <i class="fa-solid fa-paper-plane text-gradient-magenta me-2"></i>Submit an Inquiry
                        </h3>
                        <p class="text-secondary small">Fill out the details below, and our agent will review your issue immediately.</p>
                    </div>

                    <!-- Alerts Container -->
                    <div id="formAlertContainer" class="mb-4 d-none"></div>

                    <!-- Contact Form -->
                    <form id="contactForm" novalidate>
                        <?= csrf_field() ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="contactName" class="form-label text-secondary small text-uppercase fw-bold" style="letter-spacing: 1px;">Full Name</label>
                                <div class="input-group">
                                    <span class="input-group-text ig-icon-magenta"><i class="fa-solid fa-user"></i></span>
                                    <input type="text" class="form-control form-control-cyber magenta-focus" id="contactName" name="name" placeholder="John Doe" value="<?= $contactNameVal ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="contactEmail" class="form-label text-secondary small text-uppercase fw-bold" style="letter-spacing: 1px;">Email Address</label>
                                <div class="input-group">
                                    <span class="input-group-text ig-icon-magenta"><i class="fa-solid fa-envelope"></i></span>
                                    <input type="email" class="form-control form-control-cyber magenta-focus" id="contactEmail" name="email" placeholder="john@example.com" value="<?= $contactEmailVal ?>" required>
                                </div>
                            </div>
                            <div class="col-12">
                                <label for="contactSubject" class="form-label text-secondary small text-uppercase fw-bold" style="letter-spacing: 1px;">Subject / Topic</label>
                                <div class="input-group">
                                    <span class="input-group-text ig-icon-magenta"><i class="fa-solid fa-tags"></i></span>
                                    <select class="form-select form-control-cyber magenta-focus" id="contactSubject" name="subject" required style="background-image: none; padding-right: 2rem;">
                                        <option value="" disabled selected>Select an option...</option>
                                        <option value="pipeline">Social Account Connection Error</option>
                                        <option value="studio">Video Editing / Studio Bug</option>
                                        <option value="account">Account & Socials Management</option>
                                        <option value="billing">Inquiries & Partnerships</option>
                                        <option value="other">Other Technical Issues</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12">
                                <label for="contactMessage" class="form-label text-secondary small text-uppercase fw-bold" style="letter-spacing: 1px;">Message Details</label>
                                <textarea class="form-control form-control-cyber magenta-focus" id="contactMessage" name="message" rows="5" placeholder="Describe your issue or feedback in detail..." required style="border-top-left-radius: 8px; border-bottom-left-radius: 8px;"></textarea>
                            </div>
                            
                            <div class="col-12 mt-4">
                                <button type="submit" class="btn-magnetic w-100 py-3" style="border-color: var(--neon-magenta); border-radius: 8px; font-weight: 700;" id="submitBtn">
                                    <span class="btn-text">Submit</span> <i class="fa-solid fa-satellite-dish ms-2" id="btnIcon"></i>
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Custom Success State Screen (hidden initially) -->
                    <div id="successState" class="text-center py-5 d-none">
                        <div class="mb-4">
                            <div class="success-ring mx-auto mb-3">
                                <i class="fa-solid fa-circle-check text-success" style="font-size: 3.5rem;"></i>
                            </div>
                            <h4 class="fw-bold text-gradient-cyan">MESSAGE SENT!</h4>
                            <p class="text-secondary small px-3">
                                Your message has been received. Our support agent will connect with you shortly.
                            </p>
                        </div>
                        <div class="d-flex justify-content-center gap-3 mt-4">
                            <button class="btn btn-outline-primary btn-sm px-4 py-2" id="resetFormBtn">
                                <i class="fa-solid fa-redo me-2"></i>Send New Message
                            </button>
                            <a href="index.php" class="btn btn-primary btn-sm px-4 py-2 fw-bold">
                                <i class="fa-solid fa-house me-2"></i>Go Home
                            </a>
                        </div>
                    </div>

                </div>
            </div>

        </div>

    </div>
</main>

<style>
/* Page Specific Custom Layout Styles */
.text-magenta {
    color: var(--neon-magenta);
}
.border-magenta {
    border-color: var(--neon-magenta) !important;
}

/* Contact channel cards styling */
.card-channel {
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
}
.card-channel:hover {
    transform: translateY(-5px);
    border-color: #cbd5e1 !important;
}

.channel-icon-wrapper {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    flex-shrink: 0;
}

.email-icon {
    background: rgba(79, 70, 229, 0.08);
    color: var(--primary-bg, #4f46e5);
    border: 1px solid rgba(79, 70, 229, 0.2);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.1);
}

.wa-icon {
    background: rgba(16, 185, 129, 0.08);
    color: var(--success-bg, #10b981);
    border: 1px solid rgba(16, 185, 129, 0.2);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.1);
}

.channel-link {
    color: var(--text-primary);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
    font-size: 0.95rem;
}

.channel-link:hover {
    color: var(--primary-bg, #4f46e5);
}

.text-success-glow {
    transition: all 0.2s ease;
}
.text-success-glow:hover {
    color: var(--success-bg, #10b981) !important;
}

/* Social pills */
.social-support-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #f1f5f9;
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
}

.social-support-pill:hover {
    transform: translateY(-3px);
    border-color: currentColor;
    background: #ffffff;
}

.pill-fb:hover {
    color: #1877f2;
    box-shadow: 0 0 15px rgba(24, 119, 242, 0.2);
}
.pill-ig:hover {
    color: #e1305c;
    box-shadow: 0 0 15px rgba(225, 48, 92, 0.2);
}
.pill-yt:hover {
    color: #ff0000;
    box-shadow: 0 0 15px rgba(255, 0, 0, 0.2);
}
.pill-tt:hover {
    color: #0f172a;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
}

/* Select element fix */
select.form-control-cyber {
    appearance: none;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23ff00ff' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 1rem center;
    background-size: 10px 10px;
}

/* Success State Styles */
.success-ring {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(0, 255, 102, 0.08);
    border: 1px solid rgba(0, 255, 102, 0.25);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 20px rgba(0, 255, 102, 0.1);
}

/* Animation utilities */
.gsap-fade-in { opacity: 0; }
.gsap-fade-in-left { opacity: 0; transform: translateX(-30px); }
.gsap-fade-in-right { opacity: 0; transform: translateX(30px); }
</style>

<!-- Scripts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. GSAP animations on load
    gsap.to('.gsap-fade-in', { opacity: 1, y: 0, duration: 0.8, ease: 'power2.out', stagger: 0.15 });
    gsap.to('.gsap-fade-in-left', { opacity: 1, x: 0, duration: 0.8, ease: 'power2.out' });
    gsap.to('.gsap-fade-in-right', { opacity: 1, x: 0, duration: 0.8, ease: 'power2.out', delay: 0.2 });

    // 2. Interactive Form validation and submission
    const form = document.getElementById('contactForm');
    const nameInput = document.getElementById('contactName');
    const emailInput = document.getElementById('contactEmail');
    const subjectInput = document.getElementById('contactSubject');
    const messageInput = document.getElementById('contactMessage');
    
    const alertContainer = document.getElementById('formAlertContainer');
    const successState = document.getElementById('successState');
    const submitBtn = document.getElementById('submitBtn');
    const btnText = submitBtn.querySelector('.btn-text');
    const btnIcon = document.getElementById('btnIcon');

    // Display cyber error messages
    function showError(message) {
        alertContainer.innerHTML = `
            <div class="cyber-alert">
                <i class="fa-solid fa-circle-exclamation me-2"></i>
                <div><strong>TRANSMISSION BLOCKED:</strong> ${message}</div>
            </div>
        `;
        alertContainer.classList.remove('d-none');
        
        // Shake animation
        gsap.fromTo('.cyber-alert', { x: -10 }, { x: 0, duration: 0.4, ease: 'rough', clearProps: 'x' });
        
        // Scroll to top of form
        alertContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // Email regex helper
    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    // Submit Action
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        alertContainer.classList.add('d-none');

        // Validation checks
        const name = nameInput.value.trim();
        const email = emailInput.value.trim();
        const subject = subjectInput.value;
        const message = messageInput.value.trim();

        if (!name) {
            showError('Please supply your name identity.');
            nameInput.focus();
            return;
        }
        if (!email || !isValidEmail(email)) {
            showError('A valid email coordinate is required for callback responses.');
            emailInput.focus();
            return;
        }
        if (!subject) {
            showError('Inquiry topic parameter must be selected.');
            subjectInput.focus();
            return;
        }
        if (!message || message.length < 15) {
            showError('Message details must contain at least 15 characters to explain your concern.');
            messageInput.focus();
            return;
        }

        // Real form submission via fetch
        submitBtn.disabled = true;
        btnText.textContent = 'Submitting...';
        btnIcon.className = 'fa-solid fa-spinner fa-spin ms-2';
        
        // Glow effect
        gsap.to(submitBtn, { boxShadow: '0 0 25px rgba(255, 0, 255, 0.8)', duration: 0.3 });

        fetch('backend/submit_contact.php', {
            method: 'POST',
            body: new FormData(form)
        })
        .then(response => response.json().then(data => ({ status: response.status, body: data })))
        .then(res => {
            if (res.status === 200 && res.body.success) {
                // Animate transition to success view
                gsap.to(form, { opacity: 0, height: 0, duration: 0.5, onComplete: () => {
                    form.classList.add('d-none');
                    successState.classList.remove('d-none');
                    gsap.fromTo(successState, { opacity: 0, y: 15 }, { opacity: 1, y: 0, duration: 0.5 });
                }});
            } else {
                submitBtn.disabled = false;
                btnText.textContent = 'Submit';
                btnIcon.className = 'fa-solid fa-satellite-dish ms-2';
                gsap.set(submitBtn, { clearProps: 'boxShadow' });
                showError(res.body.message || 'Transmission failed. Please try again.');
            }
        })
        .catch(err => {
            submitBtn.disabled = false;
            btnText.textContent = 'Submit';
            btnIcon.className = 'fa-solid fa-satellite-dish ms-2';
            gsap.set(submitBtn, { clearProps: 'boxShadow' });
            showError('Network error occurred. Please verify link connectivity.');
        });
    });

    // Reset Form for another inquiry
    document.getElementById('resetFormBtn').addEventListener('click', () => {
        form.reset();
        submitBtn.disabled = false;
        btnText.textContent = 'Submit';
        btnIcon.className = 'fa-solid fa-paper-plane ms-2';
        gsap.set(submitBtn, { clearProps: 'boxShadow' });
        
        gsap.to(successState, { opacity: 0, duration: 0.3, onComplete: () => {
            successState.classList.add('d-none');
            form.classList.remove('d-none');
            gsap.fromTo(form, { opacity: 0, height: 'auto' }, { opacity: 1, duration: 0.5 });
        }});
    });
});
</script>

<?php
include_once 'includes/footer.php';
?>
