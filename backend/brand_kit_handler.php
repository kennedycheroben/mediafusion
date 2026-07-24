<?php
/**
 * backend/brand_kit_handler.php
 * Brand Kit integration endpoint for fetching, saving, and managing brand assets.
 *
 * Security:
 *  - Requires authenticated session
 *  - CSRF protection on state-changing operations
 *  - Rate-limited
 *  - Input validation and sanitization
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/bootstrap.php';

// ── 1. Authentication ────────────────────────────────────────────────────────
$userId = requireAuth();

// ── 2. Rate Limiting ─────────────────────────────────────────────────────────
rateLimitPolicy('brand_kit');

$brandDir = __DIR__ . '/../uploads/brand';
if (!is_dir($brandDir)) {
    @mkdir($brandDir, 0755, true);
}

// Use user-specific brand kit file to prevent cross-user access
$brandFile = __DIR__ . '/../uploads/brand/brand_kit_' . $userId . '.json';

// Default fallback configuration
$defaultBrandKit = [
    'colors' => ['#ff007f', '#3498db', '#2ecc71', '#f1c40f', '#ffffff', '#000000'],
    'fonts' => ['Space Grotesk', 'Inter', 'Outfit', 'Playfair Display'],
    'logoUrl' => 'assets/images/logo_placeholder.png',
    'watermarks' => [
        [
            'id' => 'wm_logo',
            'name' => 'Primary Brand Watermark',
            'url' => 'assets/images/logo_placeholder.png',
            'scale' => 0.3,
            'x' => 85,
            'y' => 15,
            'opacity' => 0.7
        ]
    ]
];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // CSRF Validation
    require_csrf();

    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) $input = [];
    
    // Check if multipart form upload for logo
    if (isset($_FILES['logo_file'])) {
        rateLimitPolicy('upload_studio');

        $file = $_FILES['logo_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid logo file format. Supported: PNG, JPG, JPEG, SVG, WEBP.']);
            exit;
        }

        if ($file['size'] > 5 * 1024 * 1024) { // 5MB max
            echo json_encode(['success' => false, 'message' => 'Logo file too large. Maximum size is 5MB.']);
            exit;
        }
        
        $logoName = 'brand_logo_' . $userId . '_' . time() . '.' . $ext;
        $logoDest = $brandDir . '/' . $logoName;
        
        if (move_uploaded_file($file['tmp_name'], $logoDest)) {
            $logoUrl = 'uploads/brand/' . $logoName;
            
            $currentKit = is_file($brandFile) ? json_decode(file_get_contents($brandFile), true) : $defaultBrandKit;
            $currentKit['logoUrl'] = $logoUrl;
            
            if (isset($currentKit['watermarks'][0])) {
                $currentKit['watermarks'][0]['url'] = $logoUrl;
            }
            
            file_put_contents($brandFile, json_encode($currentKit));
            
            echo json_encode(['success' => true, 'logoUrl' => $logoUrl, 'brandKit' => $currentKit]);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save logo file.']);
            exit;
        }
    }

    $action = $input['action'] ?? '';

    if ($action === 'save_brand_kit') {
        $kit = $input['brandKit'] ?? null;
        if (!$kit || !is_array($kit)) {
            echo json_encode(['success' => false, 'message' => 'Invalid Brand Kit payload.']);
            exit;
        }

        $currentKit = is_file($brandFile) ? json_decode(file_get_contents($brandFile), true) : $defaultBrandKit;
        if (isset($kit['colors']) && is_array($kit['colors'])) {
            $currentKit['colors'] = array_slice(array_map('strval', $kit['colors']), 0, 12);
        }
        if (isset($kit['fonts']) && is_array($kit['fonts'])) {
            $currentKit['fonts'] = array_slice(array_map('strval', $kit['fonts']), 0, 6);
        }
        if (isset($kit['logoUrl']) && is_string($kit['logoUrl'])) {
            $currentKit['logoUrl'] = $kit['logoUrl'];
        }
        if (isset($kit['watermarks']) && is_array($kit['watermarks'])) {
            $currentKit['watermarks'] = $kit['watermarks'];
        }

        file_put_contents($brandFile, json_encode($currentKit));

        echo json_encode(['success' => true, 'brandKit' => $currentKit]);
        exit;
    }
}

// ── Get Brand Kit (GET or fallback action) ──
$currentKit = is_file($brandFile) ? json_decode(file_get_contents($brandFile), true) : $defaultBrandKit;

echo json_encode(['success' => true, 'brandKit' => $currentKit]);
exit;
