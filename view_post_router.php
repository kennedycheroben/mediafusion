<?php
/**
 * MediaFusion - Standalone Platform Post Router & Button Showcase
 * 
 * DESIGN & SECURITY DIRECTIVES:
 * 1. Safe Routing Engine: Sanitizes incoming platform and post_id/url values.
 * 2. Whitelist Verification: Restricts 'url' redirects strictly to native domains (preventing Open Redirect attacks).
 * 3. Brand Matching: Redirects users to official platform wrappers (e.g. YouTube Watch, TikTok Video, Facebook Post, Instagram P).
 * 4. Cyberpunk UI Panel: A glorious, dark-mode dashboard when visited directly.
 * 5. Interactive Button Library: Copy-pasteable premium Bootstrap branding templates.
 * 
 * PARAMETERS:
 * - platform: (youtube | tiktok | facebook | instagram) [Required for routing]
 * - post_id:  (Alphanumeric/hyphens unique post/video ID)
 * - url:      (Direct full provider URL to authenticate and redirect)
 */

declare(strict_types=1);

// Enable session to check user context or logs if needed (isolated and safe)
if (session_status() === PHP_SESSION_ACTIVE) {
    // Keep session active
} else {
    session_start();
}

// ----------------------------------------------------
// 1. Sanitization & Input Extraction
// ----------------------------------------------------
$platform = isset($_GET['platform']) ? trim(strtolower((string)$_GET['platform'])) : null;
$postId   = isset($_GET['post_id'])  ? trim((string)$_GET['post_id'])  : null;
$rawUrl   = isset($_GET['url'])      ? trim((string)$_GET['url'])      : null;

$errorMessage = '';
$redirectUrl  = '';

// Supported platforms list
$validPlatforms = ['youtube', 'tiktok', 'facebook', 'instagram'];

// ----------------------------------------------------
// 2. Open Redirect Protection (Host Check)
// ----------------------------------------------------
/**
 * Safely validates if a given URL belongs to the allowed platform domains.
 * Prevents arbitrary phishing site redirects while allowing legitimate social links.
 */
function isValidPlatformUrl(string $url, string $platform): bool {
    $parsed = parse_url($url);
    if (!isset($parsed['host'])) {
        return false;
    }
    $host = strtolower($parsed['host']);
    
    // Map platform to allowed host substrings
    $whitelist = [
        'youtube'   => ['youtube.com', 'youtu.be', 'www.youtube.com'],
        'tiktok'    => ['tiktok.com', 'www.tiktok.com', 'vm.tiktok.com'],
        'facebook'  => ['facebook.com', 'www.facebook.com', 'fb.watch', 'm.facebook.com'],
        'instagram' => ['instagram.com', 'www.instagram.com', 'instagr.am']
    ];
    
    if (!isset($whitelist[$platform])) {
        return false;
    }
    
    foreach ($whitelist[$platform] as $allowedDomain) {
        if ($host === $allowedDomain || str_ends_with($host, '.' . $allowedDomain)) {
            return true;
        }
    }
    return false;
}

// ----------------------------------------------------
// 3. Routing Controller Logic
// ----------------------------------------------------
if ($platform !== null) {
    if (!in_array($platform, $validPlatforms, true)) {
        $errorMessage = "Invalid platform selected. Choose from: youtube, tiktok, facebook, or instagram.";
    } else {
        // If a direct URL is supplied
        if ($rawUrl !== null && $rawUrl !== '') {
            if (isValidPlatformUrl($rawUrl, $platform)) {
                $redirectUrl = $rawUrl;
            } else {
                $errorMessage = "Security Alert: The URL provided does not match the native host structure for " . ucfirst($platform) . ".";
            }
        } 
        // If only a post_id is supplied, construct clean native platform redirects
        elseif ($postId !== null && $postId !== '') {
            // Remove any potential malicious characters from post_id
            $cleanPostId = preg_replace('/[^a-zA-Z0-9_\-\.\?\&]/', '', $postId);
            
            switch ($platform) {
                case 'youtube':
                    // Map to watch page
                    $redirectUrl = "https://www.youtube.com/watch?v=" . $cleanPostId;
                    break;
                case 'tiktok':
                    // Map to absolute video structure
                    $redirectUrl = "https://www.tiktok.com/video/" . $cleanPostId;
                    break;
                case 'facebook':
                    // Map to standard feed/posts layout
                    $redirectUrl = "https://www.facebook.com/" . $cleanPostId;
                    break;
                case 'instagram':
                    // Map to photo/video p path
                    $redirectUrl = "https://www.instagram.com/p/" . $cleanPostId . "/";
                    break;
            }
        } else {
            $errorMessage = "Insufficient routing parameters. Please supply either 'post_id' or a valid native 'url'.";
        }
    }

    // Execute safe HTTP redirect if valid destination was generated
    if ($redirectUrl !== '') {
        header("Location: " . $redirectUrl);
        exit;
    }
}

// ----------------------------------------------------
// 4. Fallback Interface and Button Showcase View
// ----------------------------------------------------
// If we reach here, it's either a direct browser request or an input mismatch.
// Display a premium, highly responsive cyberpunk UI to showcase redirect button templates.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Router Control Center — MediaFusion</title>
    
    <!-- External CSS Libraries matching header.php -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <!-- Embedded styling implementing premium aesthetics, cyberpunk dark mode, and glassmorphism -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=Space+Grotesk:wght@400;700&display=swap');
        
        :root {
            --bg-color: #050505;
            --bg-gradient: radial-gradient(circle at top right, #110e1f, #050505 70%);
            --text-primary: #ffffff;
            --text-secondary: #a0a0b0;
            --glass-bg: rgba(15, 15, 20, 0.65);
            --glass-border: rgba(255, 255, 255, 0.08);
            
            --neon-cyan: #00f3ff;
            --neon-magenta: #ff00ff;
            
            --youtube-red: #ff0000;
            --tiktok-cyan: #00f2fe;
            --tiktok-pink: #fe0979;
            --meta-blue: #1877f2;
            --instagram-orange: #f09433;
            --instagram-purple: #bc1888;
        }

        body {
            background-color: var(--bg-color);
            background-image: var(--bg-gradient);
            color: var(--text-primary);
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        h1, h2, h3, h4, h5 {
            font-family: 'Space Grotesk', sans-serif;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 2.5rem;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.6), 0 0 15px rgba(255, 255, 255, 0.03);
            width: 100%;
            max-width: 900px;
        }

        .text-gradient-cyan {
            background: linear-gradient(90deg, var(--neon-cyan), #0088ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .text-gradient-magenta {
            background: linear-gradient(90deg, var(--neon-magenta), #ff0077);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Branding Buttons Styles */
        .btn-brand-youtube {
            background-color: transparent;
            color: #fff !important;
            border: 1px solid var(--youtube-red) !important;
            box-shadow: 0 0 10px rgba(255, 0, 0, 0.2);
            transition: all 0.3s ease;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .btn-brand-youtube:hover {
            background-color: var(--youtube-red) !important;
            box-shadow: 0 0 20px rgba(255, 0, 0, 0.5);
            transform: translateY(-2px);
        }

        .btn-brand-tiktok {
            background-color: transparent;
            color: #fff !important;
            border: 1px solid var(--tiktok-cyan) !important;
            box-shadow: 0 0 10px rgba(0, 242, 254, 0.15);
            transition: all 0.3s ease;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .btn-brand-tiktok:hover {
            background-color: #121212 !important;
            border-color: var(--tiktok-pink) !important;
            box-shadow: -3px -3px 0px var(--tiktok-cyan), 3px 3px 0px var(--tiktok-pink);
            transform: translateY(-2px);
        }

        .btn-brand-facebook {
            background-color: transparent;
            color: #fff !important;
            border: 1px solid var(--meta-blue) !important;
            box-shadow: 0 0 10px rgba(24, 119, 242, 0.2);
            transition: all 0.3s ease;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .btn-brand-facebook:hover {
            background-color: var(--meta-blue) !important;
            box-shadow: 0 0 20px rgba(24, 119, 242, 0.5);
            transform: translateY(-2px);
        }

        .btn-brand-instagram {
            background-color: transparent;
            color: #fff !important;
            border: 1px solid var(--neon-magenta) !important;
            box-shadow: 0 0 10px rgba(255, 0, 255, 0.2);
            transition: all 0.3s ease;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .btn-brand-instagram:hover {
            background: linear-gradient(45deg, var(--instagram-orange), var(--instagram-purple), var(--neon-magenta)) !important;
            border-color: transparent !important;
            box-shadow: 0 0 20px rgba(255, 0, 255, 0.5);
            transform: translateY(-2px);
        }

        .code-box {
            background: rgba(0, 0, 0, 0.55);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 1.2rem;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.85rem;
            color: #76ffb6;
            overflow-x: auto;
            position: relative;
        }
        .copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            color: var(--text-secondary);
            border-radius: 4px;
            font-size: 0.72rem;
            padding: 0.3rem 0.6rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .copy-btn:hover {
            color: #fff;
            background: rgba(255,255,255,0.15);
        }

        .router-form input, .router-form select {
            background-color: rgba(0, 0, 0, 0.4);
            border: 1px solid var(--glass-border);
            color: #fff;
        }
        .router-form input:focus, .router-form select:focus {
            background-color: rgba(0, 0, 0, 0.6);
            border-color: var(--neon-cyan);
            box-shadow: 0 0 10px rgba(0, 243, 255, 0.25);
            color: #fff;
        }
    </style>
</head>
<body>

<div class="glass-card">
    <!-- Header Block -->
    <div class="d-flex align-items-center justify-content-between mb-4 border-bottom border-secondary pb-3">
        <div>
            <h2 class="text-gradient-cyan mb-1">Post Routing Console</h2>
            <p class="text-secondary mb-0" style="font-size: 0.85rem;">
                Standalone routing engine for safe native external post redirection.
            </p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-light btn-sm px-3" style="border-radius: 4px; font-size: 0.8rem;">
                <i class="fa-solid fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Security Error Alert -->
    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4" style="background: rgba(255,0,0,0.06); border: 1px solid rgba(255,0,0,0.25); color: #ff8080;" role="alert">
            <i class="fa-solid fa-triangle-exclamation fa-lg me-3"></i>
            <div>
                <strong>Routing Error:</strong> <?= htmlspecialchars($errorMessage) ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Interactive Testing Segment -->
        <div class="col-lg-5">
            <h4 class="text-white mb-3 text-gradient-magenta"><i class="fa-solid fa-compass me-2"></i>Dynamic Router Test</h4>
            <div class="p-3 rounded mb-4" style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border);">
                <form class="router-form" method="GET" action="view_post_router.php">
                    <div class="mb-3">
                        <label class="form-label text-secondary text-uppercase" style="font-size: 0.7rem;">Target Platform</label>
                        <select name="platform" class="form-select" required>
                            <option value="" disabled selected>Select Social Channel...</option>
                            <option value="youtube">YouTube</option>
                            <option value="tiktok">TikTok</option>
                            <option value="facebook">Facebook</option>
                            <option value="instagram">Instagram</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary text-uppercase" style="font-size: 0.7rem;">Post ID (Native Layout)</label>
                        <input type="text" name="post_id" class="form-control" placeholder="e.g. dQw4w9WgXcQ or 7234591...">
                        <div class="form-text text-secondary" style="font-size: 0.65rem;">Converts internally to native platform endpoints.</div>
                    </div>
                    <div class="mb-3 text-center text-secondary" style="font-size: 0.75rem;">— OR —</div>
                    <div class="mb-3">
                        <label class="form-label text-secondary text-uppercase" style="font-size: 0.7rem;">Full Native URL (Safe Whitelisted)</label>
                        <input type="url" name="url" class="form-control" placeholder="https://www.youtube.com/watch?...">
                        <div class="form-text text-secondary" style="font-size: 0.65rem;">Direct redirect whitelisted explicitly to provider domains.</div>
                    </div>
                    <button type="submit" class="btn btn-info w-100 py-2 fw-bold text-uppercase" style="border-radius: 4px; box-shadow: 0 0 10px rgba(0, 243, 255, 0.3);">
                        Trigger Redirect <i class="fa-solid fa-paper-plane ms-2"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- Button Template Library Segment -->
        <div class="col-lg-7">
            <h4 class="text-white mb-3 text-gradient-cyan"><i class="fa-solid fa-cubes me-2"></i>Bootstrap Branding Buttons</h4>
            <p class="text-secondary" style="font-size: 0.8rem;">
                Incorporate these clean, responsive Bootstrap button classes with matching custom brand outlines into post lists and dashboards:
            </p>

            <div class="d-flex flex-column gap-4">
                <!-- YouTube Button Pattern -->
                <div class="p-3 rounded" style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-white fw-bold" style="font-size: 0.85rem;"><i class="fa-brands fa-youtube me-2 text-danger"></i>YouTube Button</span>
                        <a href="view_post_router.php?platform=youtube&post_id=dQw4w9WgXcQ" target="_blank" class="btn btn-sm btn-brand-youtube px-3 py-1">
                            View Post <i class="fa-solid fa-arrow-up-right-from-square ms-2"></i>
                        </a>
                    </div>
                    <div class="code-box">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
                        <span>&lt;a href="view_post_router.php?platform=youtube&amp;post_id=dQw4w9WgXcQ" class="btn btn-outline-danger btn-brand-youtube" target="_blank"&gt;<br>&nbsp;&nbsp;&nbsp;&nbsp;View Post &lt;i class="fa-solid fa-arrow-up-right-from-square ms-1"&gt;&lt;/i&gt;<br>&lt;/a&gt;</span>
                    </div>
                </div>

                <!-- TikTok Button Pattern -->
                <div class="p-3 rounded" style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-white fw-bold" style="font-size: 0.85rem;"><i class="fa-brands fa-tiktok me-2"></i>TikTok Button</span>
                        <a href="view_post_router.php?platform=tiktok&post_id=7123456789" target="_blank" class="btn btn-sm btn-brand-tiktok px-3 py-1">
                            View Post <i class="fa-solid fa-arrow-up-right-from-square ms-2"></i>
                        </a>
                    </div>
                    <div class="code-box">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
                        <span>&lt;a href="view_post_router.php?platform=tiktok&amp;post_id=7123456789" class="btn btn-dark btn-brand-tiktok" target="_blank"&gt;<br>&nbsp;&nbsp;&nbsp;&nbsp;View Post &lt;i class="fa-solid fa-arrow-up-right-from-square ms-1"&gt;&lt;/i&gt;<br>&lt;/a&gt;</span>
                    </div>
                </div>

                <!-- Facebook Button Pattern -->
                <div class="p-3 rounded" style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-white fw-bold" style="font-size: 0.85rem;"><i class="fa-brands fa-facebook me-2 text-primary"></i>Facebook Button</span>
                        <a href="view_post_router.php?platform=facebook&post_id=1020304050" target="_blank" class="btn btn-sm btn-brand-facebook px-3 py-1">
                            View Post <i class="fa-solid fa-arrow-up-right-from-square ms-2"></i>
                        </a>
                    </div>
                    <div class="code-box">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
                        <span>&lt;a href="view_post_router.php?platform=facebook&amp;post_id=1020304050" class="btn btn-primary btn-brand-facebook" target="_blank"&gt;<br>&nbsp;&nbsp;&nbsp;&nbsp;View Post &lt;i class="fa-solid fa-arrow-up-right-from-square ms-1"&gt;&lt;/i&gt;<br>&lt;/a&gt;</span>
                    </div>
                </div>

                <!-- Instagram Button Pattern -->
                <div class="p-3 rounded" style="background: rgba(255,255,255,0.02); border: 1px solid var(--glass-border);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-white fw-bold" style="font-size: 0.85rem;"><i class="fa-brands fa-instagram me-2 text-gradient-magenta"></i>Instagram Button</span>
                        <a href="view_post_router.php?platform=instagram&post_id=CxYz123_abc" target="_blank" class="btn btn-sm btn-brand-instagram px-3 py-1">
                            View Post <i class="fa-solid fa-arrow-up-right-from-square ms-2"></i>
                        </a>
                    </div>
                    <div class="code-box">
                        <button class="copy-btn" onclick="copyCode(this)">Copy</button>
                        <span>&lt;a href="view_post_router.php?platform=instagram&amp;post_id=CxYz123_abc" class="btn btn-outline-secondary btn-brand-instagram" target="_blank"&gt;<br>&nbsp;&nbsp;&nbsp;&nbsp;View Post &lt;i class="fa-solid fa-arrow-up-right-from-square ms-1"&gt;&lt;/i&gt;<br>&lt;/a&gt;</span>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
    /**
     * Copies code from container text cleanly to clipboard
     */
    function copyCode(btn) {
        const textContainer = btn.nextElementSibling;
        const tempTextarea = document.createElement("textarea");
        // Decode HTML entities
        tempTextarea.innerHTML = textContainer.innerHTML.replace(/<br>/g, "\n").replace(/&nbsp;/g, " ");
        const decodedText = tempTextarea.value;
        
        navigator.clipboard.writeText(decodedText.trim()).then(() => {
            const originalText = btn.innerText;
            btn.innerText = "Copied!";
            btn.style.color = "#00ff66";
            setTimeout(() => {
                btn.innerText = originalText;
                btn.style.color = "";
            }, 1500);
        }).catch(err => {
            console.error("Copy failed: ", err);
        });
    }
</script>
</body>
</html>
