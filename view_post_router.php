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
    <title>Post Link Viewer — MediaFusion</title>
    
    <!-- External CSS Libraries matching header.php -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <!-- Embedded styling implementing premium aesthetics, cyberpunk dark mode, and glassmorphism -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=Space+Grotesk:wght@400;700&display=swap');
        
        :root {
            --bg-color: #f8fafc;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --card-radius: 16px;
            --card-shadow: 0 8px 30px rgba(15, 23, 42, 0.08);
            
            --primary-bg: #4f46e5;
            --primary-hover: #4338ca;
            --primary-text: #ffffff;
            
            --neon-cyan: #06b6d4;
            --neon-magenta: #ec4899;
            --neon-green: #10b981;
            
            --youtube-red: #ff0000;
            --meta-blue: #1877f2;
            --instagram-orange: #f09433;
            --instagram-purple: #bc1888;
        }

        body {
            background-color: var(--bg-color);
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
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--card-radius);
            padding: 2.5rem;
            box-shadow: var(--card-shadow);
            width: 100%;
            max-width: 900px;
        }

        .text-gradient-cyan {
            background: linear-gradient(90deg, var(--neon-cyan), #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .text-gradient-magenta {
            background: linear-gradient(90deg, var(--neon-magenta), #d946ef);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Branding Buttons Styles */
        .btn-brand-youtube {
            background-color: transparent;
            color: var(--youtube-red) !important;
            border: 1px solid var(--youtube-red) !important;
            box-shadow: 0 2px 5px rgba(255, 0, 0, 0.05);
            transition: all 0.3s ease;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .btn-brand-youtube:hover {
            background-color: var(--youtube-red) !important;
            color: #fff !important;
            box-shadow: 0 4px 12px rgba(255, 0, 0, 0.25);
            transform: translateY(-2px);
        }

        .btn-brand-tiktok {
            background-color: transparent;
            color: var(--text-primary) !important;
            border: 1px solid var(--text-primary) !important;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .btn-brand-tiktok:hover {
            background-color: #000000 !important;
            border-color: #000000 !important;
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
            transform: translateY(-2px);
        }

        .btn-brand-facebook {
            background-color: transparent;
            color: var(--meta-blue) !important;
            border: 1px solid var(--meta-blue) !important;
            box-shadow: 0 2px 5px rgba(24, 119, 242, 0.05);
            transition: all 0.3s ease;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .btn-brand-facebook:hover {
            background-color: var(--meta-blue) !important;
            color: #fff !important;
            box-shadow: 0 4px 12px rgba(24, 119, 242, 0.25);
            transform: translateY(-2px);
        }

        .btn-brand-instagram {
            background-color: transparent;
            color: var(--neon-magenta) !important;
            border: 1px solid var(--neon-magenta) !important;
            box-shadow: 0 2px 5px rgba(255, 0, 255, 0.05);
            transition: all 0.3s ease;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .btn-brand-instagram:hover {
            background: linear-gradient(45deg, var(--instagram-orange), var(--instagram-purple), var(--neon-magenta)) !important;
            color: #fff !important;
            border-color: transparent !important;
            box-shadow: 0 4px 12px rgba(255, 0, 255, 0.25);
            transform: translateY(-2px);
        }

        .code-box {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 1.2rem;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.85rem;
            color: var(--primary-bg);
            overflow-x: auto;
            position: relative;
        }
        .copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: var(--text-secondary);
            border-radius: 4px;
            font-size: 0.72rem;
            padding: 0.3rem 0.6rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .copy-btn:hover {
            color: var(--text-primary);
            background: #e2e8f0;
        }

        .router-form input, .router-form select {
            background-color: #ffffff;
            border: 1px solid var(--card-border);
            color: var(--text-primary);
        }
        .router-form input:focus, .router-form select:focus {
            background-color: #ffffff;
            border-color: var(--primary-bg);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
            color: var(--text-primary);
        }
    </style>
</head>
<body>

<div class="glass-card">
    <!-- Header Block -->
    <div class="d-flex align-items-center justify-content-between mb-4 border-bottom border-light pb-3">
        <div>
            <h2 class="text-gradient-cyan mb-1">Post Link Viewer</h2>
            <p class="text-secondary mb-0" style="font-size: 0.85rem;">
                Safely view social posts on YouTube, TikTok, Facebook, and Instagram.
            </p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-dark btn-sm px-3" style="border-radius: 4px; font-size: 0.8rem;">
                <i class="fa-solid fa-arrow-left me-2"></i>Back to Hub
            </a>
        </div>
    </div>

    <!-- Security Error Alert -->
    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4" style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2); color: #991b1b;" role="alert">
            <i class="fa-solid fa-triangle-exclamation fa-lg me-3"></i>
            <div>
                <strong>Routing Error:</strong> <?= htmlspecialchars($errorMessage) ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Interactive Testing Segment -->
        <div class="col-lg-5">
            <h4 class="mb-3 text-gradient-magenta"><i class="fa-solid fa-compass me-2"></i>Test Post Link</h4>
            <div class="p-3 rounded mb-4" style="background: #f8fafc; border: 1px solid var(--card-border);">
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
                        <div class="form-text text-secondary" style="font-size: 0.65rem;">Go directly to the social post.</div>
                    </div>
                    <div class="mb-3 text-center text-secondary" style="font-size: 0.75rem;">— OR —</div>
                    <div class="mb-3">
                        <label class="form-label text-secondary text-uppercase" style="font-size: 0.7rem;">Full Native URL (Safe Whitelisted)</label>
                        <input type="url" name="url" class="form-control" placeholder="https://www.youtube.com/watch?...">
                        <div class="form-text text-secondary" style="font-size: 0.65rem;">Direct link to social account post.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold text-uppercase" style="border-radius: 4px; background: var(--primary-bg); border-color: var(--primary-bg); color: var(--primary-text);">
                        Go to Post <i class="fa-solid fa-paper-plane ms-2"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- Button Template Library Segment -->
        <div class="col-lg-7">
            <h4 class="mb-3 text-gradient-cyan"><i class="fa-solid fa-cubes me-2"></i>Branded Sharing Buttons</h4>
            <p class="text-secondary" style="font-size: 0.8rem;">
                Use these clean, responsive branded buttons to display sharing options on your dashboards:
            </p>

            <div class="d-flex flex-column gap-4">
                <!-- YouTube Button Pattern -->
                <div class="p-3 rounded" style="background: #f8fafc; border: 1px solid var(--card-border);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-dark fw-bold" style="font-size: 0.85rem;"><i class="fa-brands fa-youtube me-2 text-danger"></i>YouTube Button</span>
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
                <div class="p-3 rounded" style="background: #f8fafc; border: 1px solid var(--card-border);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-dark fw-bold" style="font-size: 0.85rem;"><i class="fa-brands fa-tiktok me-2"></i>TikTok Button</span>
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
                <div class="p-3 rounded" style="background: #f8fafc; border: 1px solid var(--card-border);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-dark fw-bold" style="font-size: 0.85rem;"><i class="fa-brands fa-facebook me-2 text-primary"></i>Facebook Button</span>
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
                <div class="p-3 rounded" style="background: #f8fafc; border: 1px solid var(--card-border);">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-dark fw-bold" style="font-size: 0.85rem;"><i class="fa-brands fa-instagram me-2 text-gradient-magenta"></i>Instagram Button</span>
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
