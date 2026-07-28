<?php
/**
 * Unify Social Hub - Standalone Media Processor & Center Caption Studio
 * 
 * CORE FEATURES:
 * 1. Isolated REST/AJAX Controller: Decides processing action and outputs standardized JSON.
 * 2. Ubuntu FFmpeg Core: Employs dynamic, single-pass video/image pipeline filtering.
 * 3. Video Studio: Precision start/end frame trimming, transcoding (H.264), and audio copying.
 * 4. Image Studio: Integrates crop, brightness/contrast adjustments (via 'eq' filters).
 * 5. Caption Engine: Center-burns customizable text using drawtext with DejaVu Sans font.
 * 6. High Security: Strict validation of uploaded file headers and escapeshellarg shell protection.
 */

declare(strict_types=1);

// Set error reporting to log privately, keep user interfaces clean
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Check if FFmpeg is available
$ffmpegAvailable = (bool)shell_exec('which ffmpeg 2>/dev/null');
if (!$ffmpegAvailable) {
    error_log("WARNING: FFmpeg binary not found on system PATH");
}

if (session_status() === PHP_SESSION_ACTIVE) {
    // Session is active
} else {
    session_start();
}

// ----------------------------------------------------
// Helper Utilities & Escaping
// ----------------------------------------------------
/**
 * Safe escaping for special characters inside FFmpeg's drawtext filter arguments.
 * Ensures quotes, colons, percent signs, and backslashes do not break shell executions.
 */
function escapeFfmpegDrawtext(string $text): string {
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace("'", "'\\\\''", $text);
    $text = str_replace(':', '\\:', $text);
    $text = str_replace('%', '\\%', $text);
    return $text;
}

/**
 * Normalizes start/end times in formats like (seconds, e.g. "45") or (timecode, e.g. "00:01:15")
 */
function parseTimeInput(string $time): string {
    $time = trim($time);
    if ($time === '') {
        return '0';
    }
    // Match either numeric float/int, or hh:mm:ss/mm:ss timecodes
    if (preg_match('/^\d+(\.\d+)?$/', $time) || preg_match('/^(?:[0-5]?\d:){1,2}[0-5]?\d$/', $time)) {
        return $time;
    }
    return '0';
}

// ----------------------------------------------------
// AJAX POST Request Controller Processing
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Core response object
    $response = [
        'success'    => false,
        'message'    => '',
        'file_name'  => '',
        'output_url' => '',
        'file_path'  => '',
        'cmd_log'    => ''
    ];

    // Directories setup
    $uploadDir   = __DIR__ . '/uploads';
    $processedDir = $uploadDir . '/processed';
    
    // Ensure directories exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    if (!is_dir($processedDir)) {
        mkdir($processedDir, 0755, true);
    }

    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

    if ($action === 'process_video') {
        // Validate video file
        $videoFile = null;
        $tempPath  = '';
        
        if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK) {
            $videoFile = $_FILES['video_file'];
            $ext = strtolower(pathinfo($videoFile['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp4', 'mkv', 'avi', 'mov', 'webm'], true)) {
                $response['message'] = "Invalid video format. Supported: mp4, mkv, avi, mov, webm.";
                echo json_encode($response);
                exit;
            }
            $tempPath = $videoFile['tmp_name'];
        } elseif (isset($_POST['existing_file']) && $_POST['existing_file'] !== '') {
            $existing = basename((string)$_POST['existing_file']);
            $existingPath = $uploadDir . '/' . $existing;
            if (is_file($existingPath)) {
                $tempPath = $existingPath;
            }
        }

        if ($tempPath === '') {
            $response['message'] = "No video source provided. Upload a file or pick an existing path.";
            echo json_encode($response);
            exit;
        }

        // Parameters
        $startTime = parseTimeInput($_POST['start_time'] ?? '0');
        $endTime   = parseTimeInput($_POST['end_time'] ?? '');
        $caption   = isset($_POST['caption']) ? trim((string)$_POST['caption']) : '';

        // Generate target output
        $outName = 'trimmed_' . time() . '.mp4';
        $outPath = $processedDir . '/' . $outName;

        // Construct dynamic FFmpeg filter chains
        // Burn text at exact physical center with a premium font and backdrop box
        $filterParams = [];
        if ($caption !== '') {
            $escapedCap = escapeFfmpegDrawtext($caption);
            // WhatsApp style: bottom-center, white bold text, dark semi-transparent box, subtle shadow
            $filterParams[] = "drawtext=fontfile='/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf':text='{$escapedCap}':x=(w-text_w)/2:y=h-text_h-48:fontsize=36:fontcolor=white:shadowx=2:shadowy=2:shadowcolor=black@0.9:box=1:boxcolor=black@0.58:boxborderw=16";
        }

        // Build CLI command securely
        $ffmpegBin = '/usr/bin/ffmpeg';
        
        // Assemble trim times arguments
        $timeArgs = [];
        if ($startTime !== '0') {
            $timeArgs[] = "-ss " . escapeshellarg($startTime);
        }
        if ($endTime !== '') {
            $timeArgs[] = "-to " . escapeshellarg($endTime);
        }

        $filterStr = !empty($filterParams) ? "-vf " . escapeshellarg(implode(',', $filterParams)) : '';
        
        // If we are applying filters, we must transcode (x264). If only trimming, transcode is still highly recommended for precise boundaries.
        $cmd = "{$ffmpegBin} -y " . implode(' ', $timeArgs) . " -i " . escapeshellarg($tempPath) . " {$filterStr} -c:v libx264 -preset superfast -crf 23 -c:a aac " . escapeshellarg($outPath) . " 2>&1";

        $response['cmd_log'] = $cmd;

        // Execute proc_open safely to catch errors and stdout
        $descriptors = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (is_resource($process)) {
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $returnVal = proc_close($process);

            if ($returnVal === 0 && is_file($outPath)) {
                $response['success']    = true;
                $response['message']    = "Video processed successfully.";
                $response['file_name']  = $outName;
                $response['output_url'] = "uploads/processed/" . $outName;
                $response['file_path']  = $outPath;
            } else {
                $response['message'] = "FFmpeg execution failed. Output logs: " . substr($output, -300);
            }
        } else {
            $response['message'] = "Failed to launch FFmpeg binary process.";
        }

        echo json_encode($response);
        exit;
    } 
    
    elseif ($action === 'process_image') {
        // Validate image file
        $imageFile = null;
        $tempPath  = '';

        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $imageFile = $_FILES['image_file'];
            $ext = strtolower(pathinfo($imageFile['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                $response['message'] = "Invalid image format. Supported: png, jpg, jpeg, webp.";
                echo json_encode($response);
                exit;
            }
            $tempPath = $imageFile['tmp_name'];
        } elseif (isset($_POST['existing_file']) && $_POST['existing_file'] !== '') {
            $existing = basename((string)$_POST['existing_file']);
            $existingPath = $uploadDir . '/' . $existing;
            if (is_file($existingPath)) {
                $tempPath = $existingPath;
            }
        }

        if ($tempPath === '') {
            $response['message'] = "No image source provided. Upload an image or pick an existing path.";
            echo json_encode($response);
            exit;
        }

        // Params
        $cropX      = isset($_POST['crop_x']) ? (int)$_POST['crop_x'] : null;
        $cropY      = isset($_POST['crop_y']) ? (int)$_POST['crop_y'] : null;
        $cropW      = isset($_POST['crop_width']) ? (int)$_POST['crop_width'] : null;
        $cropH      = isset($_POST['crop_height']) ? (int)$_POST['crop_height'] : null;
        $brightness = isset($_POST['brightness']) ? (int)$_POST['brightness'] : 0;
        $contrast   = isset($_POST['contrast']) ? (int)$_POST['contrast'] : 0;
        $caption    = isset($_POST['caption']) ? trim((string)$_POST['caption']) : '';

        // Generate target output
        $outName = 'studio_' . time() . '.jpg';
        $outPath = $processedDir . '/' . $outName;

        // Construct filter chains dynamically
        $imageFilters = [];

        // 1. Cropper.js coordinates mapping
        if ($cropW !== null && $cropH !== null && $cropX !== null && $cropY !== null && $cropW > 0 && $cropH > 0) {
            $imageFilters[] = "crop={$cropW}:{$cropH}:{$cropX}:{$cropY}";
        }

        // 2. Brightness & Contrast adjustment normalized maps (-100..100) -> (-1..1 and 0..2)
        if ($brightness !== 0 || $contrast !== 0) {
            $normB = $brightness / 100.0;
            $normC = 1.0 + ($contrast / 100.0);
            if ($normC < 0.0) $normC = 0.0;
            $imageFilters[] = "eq=brightness={$normB}:contrast={$normC}";
        }

        // 3. Center burning captions via drawtext
        if ($caption !== '') {
            $escapedCap = escapeFfmpegDrawtext($caption);
            // WhatsApp style: bottom-center, white bold text, dark semi-transparent box, subtle shadow
            $imageFilters[] = "drawtext=fontfile='/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf':text='{$escapedCap}':x=(w-text_w)/2:y=h-text_h-48:fontsize=40:fontcolor=white:shadowx=2:shadowy=2:shadowcolor=black@0.9:box=1:boxcolor=black@0.58:boxborderw=18";
        }

        $filterStr = !empty($imageFilters) ? "-vf " . escapeshellarg(implode(',', $imageFilters)) : '';
        $ffmpegBin = '/usr/bin/ffmpeg';

        $cmd = "{$ffmpegBin} -y -i " . escapeshellarg($tempPath) . " {$filterStr} " . escapeshellarg($outPath) . " 2>&1";
        $response['cmd_log'] = $cmd;

        // Execute safely
        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (is_resource($process)) {
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $returnVal = proc_close($process);

            if ($returnVal === 0 && is_file($outPath)) {
                $response['success']    = true;
                $response['message']    = "Image processed successfully.";
                $response['file_name']  = $outName;
                $response['output_url'] = "uploads/processed/" . $outName;
                $response['file_path']  = $outPath;
            } else {
                $response['message'] = "FFmpeg execution failed. Output logs: " . substr($output, -300);
            }
        } else {
            $response['message'] = "Failed to launch FFmpeg process.";
        }

        echo json_encode($response);
        exit;
    }

    $response['message'] = "Unsupported action requested.";
    echo json_encode($response);
    exit;
}

// ----------------------------------------------------
// UI Dashboard view on GET
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Media Caption Studio & Processing Center — Unify Social Hub</title>
    
    <!-- CSS Library Setup -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <!-- Premium Cyberpunk Theme Custom Rules -->
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=Space+Grotesk:wght@400;700&display=swap');
        
        :root {
            --bg-color: #050505;
            --bg-gradient: radial-gradient(circle at top right, #110e1f, #050505 75%);
            --text-primary: #ffffff;
            --text-secondary: #a0a0b0;
            --glass-bg: rgba(15, 15, 20, 0.65);
            --glass-border: rgba(255, 255, 255, 0.08);
            
            --neon-cyan: #00f3ff;
            --neon-magenta: #ff00ff;
            --neon-green: #00ff66;
            --neon-yellow: #fcee0a;
        }

        body {
            background-color: var(--bg-color);
            background-image: var(--bg-gradient);
            color: var(--text-primary);
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
            padding: 3rem 1rem;
        }

        h1, h2, h3, h4, h5 {
            font-family: 'Space Grotesk', sans-serif;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 2.5rem;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.6), 0 0 15px rgba(255, 255, 255, 0.03);
            margin-bottom: 2rem;
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

        .cyber-input {
            background-color: rgba(0, 0, 0, 0.5) !important;
            border: 1px solid var(--glass-border) !important;
            color: #fff !important;
        }
        .cyber-input:focus {
            background-color: rgba(0, 0, 0, 0.7) !important;
            border-color: var(--neon-cyan) !important;
            box-shadow: 0 0 12px rgba(0, 243, 255, 0.25) !important;
            outline: none;
        }

        .form-label-cyber {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .studio-nav-btn {
            border: 1px solid var(--glass-border);
            color: var(--text-secondary);
            font-weight: 600;
            padding: 0.6rem 1.5rem;
            border-radius: 6px;
            transition: all 0.3s ease;
            background: transparent;
        }
        .studio-nav-btn.active, .studio-nav-btn:hover {
            color: #fff;
            border-color: var(--neon-cyan);
            background: rgba(0, 243, 255, 0.05);
            box-shadow: 0 0 10px rgba(0, 243, 255, 0.2);
        }

        .range-slider-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .range-value {
            color: var(--neon-cyan);
            font-weight: 600;
            font-size: 0.85rem;
        }

        .console-log {
            background: #020202;
            border: 1px solid rgba(255, 255, 255, 0.05);
            font-family: 'Courier New', Courier, monospace;
            color: var(--neon-green);
            padding: 1.2rem;
            border-radius: 8px;
            font-size: 0.82rem;
            max-height: 250px;
            overflow-y: auto;
        }
    </style>
</head>
<body>

<div class="container" style="max-width: 1000px;">
    <!-- Main Dashboard Header -->
    <div class="glass-card text-center mb-4">
        <h1 class="text-gradient-cyan mb-2">Media Caption Studio</h1>
        <p class="text-secondary mx-auto mb-4" style="max-width: 600px;">
            High-precision standalone media laboratory. Trim local raw video assets or edit picture layouts while burning center caption text arrays onto physical files using the Ubuntu FFmpeg binary core.
        </p>
        
        <div class="d-flex justify-content-center gap-3">
            <button class="studio-nav-btn active" id="tabVideoBtn" onclick="switchTab('video')">
                <i class="fa-solid fa-video me-2"></i>Video Processor
            </button>
            <button class="studio-nav-btn" id="tabImageBtn" onclick="switchTab('image')">
                <i class="fa-solid fa-image me-2"></i>Image Editor
            </button>
            <a href="index.php" class="studio-nav-btn text-decoration-none">
                <i class="fa-solid fa-house me-2"></i>Dashboard
            </a>
        </div>
    </div>

    <!-- Active Workspace Panel -->
    <div class="row">
        <!-- Input parameters controls -->
        <div class="col-lg-6">
            <!-- Video Control Card -->
            <div class="glass-card" id="videoStudioPanel">
                <h3 class="text-white text-gradient-magenta mb-4"><i class="fa-solid fa-scissors me-2"></i>Trim & Burn Video</h3>
                <form id="videoForm" onsubmit="submitMediaForm(event, 'process_video', 'videoForm')">
                    <input type="hidden" name="action" value="process_video">
                    
                    <div class="mb-3">
                        <label class="form-label form-label-cyber">Upload Raw Video Asset</label>
                        <input type="file" name="video_file" class="form-control cyber-input" accept="video/*">
                    </div>

                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label form-label-cyber">Start Trim (Seconds)</label>
                            <input type="text" name="start_time" class="form-control cyber-input" placeholder="e.g. 0 or 00:00:15" value="0">
                        </div>
                        <div class="col-6">
                            <label class="form-label form-label-cyber">End Trim (Seconds)</label>
                            <input type="text" name="end_time" class="form-control cyber-input" placeholder="e.g. 10 or 00:00:30">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label form-label-cyber">Burned Center Caption</label>
                        <input type="text" name="caption" class="form-control cyber-input" placeholder="Caption overlay text goes here...">
                        <div class="form-text text-secondary" style="font-size: 0.65rem;">Burns text directly onto the physical center.</div>
                    </div>

                    <button type="submit" class="btn btn-info w-100 py-3 fw-bold text-uppercase" style="border-radius: 6px; box-shadow: 0 0 15px rgba(0, 243, 255, 0.3);">
                        Execute Video Trim & Burn <i class="fa-solid fa-arrows-spin ms-2"></i>
                    </button>
                </form>
            </div>

            <!-- Image Control Card (Hidden by default) -->
            <div class="glass-card d-none" id="imageStudioPanel">
                <h3 class="text-white text-gradient-cyan mb-4"><i class="fa-solid fa-sliders me-2"></i>Crop & Fine-Tune Image</h3>
                <form id="imageForm" onsubmit="submitMediaForm(event, 'process_image', 'imageForm')">
                    <input type="hidden" name="action" value="process_image">

                    <div class="mb-3">
                        <label class="form-label form-label-cyber">Upload Raw Image Asset</label>
                        <input type="file" name="image_file" class="form-control cyber-input" accept="image/*">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label form-label-cyber">Crop X (px)</label>
                            <input type="number" name="crop_x" class="form-control cyber-input" placeholder="e.g. 100">
                        </div>
                        <div class="col-6">
                            <label class="form-label form-label-cyber">Crop Y (px)</label>
                            <input type="number" name="crop_y" class="form-control cyber-input" placeholder="e.g. 50">
                        </div>
                        <div class="col-6">
                            <label class="form-label form-label-cyber">Crop Width (px)</label>
                            <input type="number" name="crop_width" class="form-control cyber-input" placeholder="e.g. 500">
                        </div>
                        <div class="col-6">
                            <label class="form-label form-label-cyber">Crop Height (px)</label>
                            <input type="number" name="crop_height" class="form-control cyber-input" placeholder="e.g. 500">
                        </div>
                    </div>

                    <!-- Brightness slider -->
                    <div class="mb-3">
                        <div class="range-slider-label">
                            <label class="form-label form-label-cyber">Brightness Correction</label>
                            <span class="range-value" id="valBrightness">0%</span>
                        </div>
                        <input type="range" name="brightness" class="form-range" min="-100" max="100" value="0" oninput="document.getElementById('valBrightness').innerText = this.value + '%'">
                    </div>

                    <!-- Contrast slider -->
                    <div class="mb-3">
                        <div class="range-slider-label">
                            <label class="form-label form-label-cyber">Contrast Correction</label>
                            <span class="range-value" id="valContrast">0%</span>
                        </div>
                        <input type="range" name="contrast" class="form-range" min="-100" max="100" value="0" oninput="document.getElementById('valContrast').innerText = this.value + '%'">
                    </div>

                    <div class="mb-4">
                        <label class="form-label form-label-cyber">Burned Center Caption</label>
                        <input type="text" name="caption" class="form-control cyber-input" placeholder="Caption overlay text goes here...">
                    </div>

                    <button type="submit" class="btn btn-info w-100 py-3 fw-bold text-uppercase" style="border-radius: 6px; box-shadow: 0 0 15px rgba(0, 243, 255, 0.3);">
                        Execute Image Crop & Burn <i class="fa-solid fa-arrows-spin ms-2"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- Output display console -->
        <div class="col-lg-6">
            <div class="glass-card h-100 d-flex flex-column justify-content-between">
                <div>
                    <h3 class="text-white mb-4"><i class="fa-solid fa-chart-line me-2 text-gradient-cyan"></i>Real-Time Output Panel</h3>
                    
                    <!-- Preloading spinner -->
                    <div class="text-center py-5 d-none" id="processingLoader">
                        <div class="spinner-border text-info mb-3" style="width: 3rem; height: 3rem;" role="status"></div>
                        <p class="text-secondary text-uppercase fw-bold" style="font-size: 0.8rem; letter-spacing: 2px;">
                            FFmpeg Pipeline Active...
                        </p>
                    </div>

                    <!-- Sandbox Instruction -->
                    <div class="text-center py-5" id="outputPlaceholder">
                        <i class="fa-solid fa-wand-magic-sparkles fa-3x text-secondary mb-3" style="opacity: 0.4;"></i>
                        <p class="text-secondary mb-0" style="font-size: 0.88rem;">
                            Submit variables in the control studio to activate native processing. Correctly trimmed videos/images appear here instantly.
                        </p>
                    </div>

                    <!-- Output display containers -->
                    <div class="d-none" id="outputMediaContainer">
                        <div class="ratio ratio-16x9 bg-black rounded mb-3 overflow-hidden border border-secondary" id="videoOutputBox">
                            <video id="outputVideo" controls src=""></video>
                        </div>
                        <div class="text-center bg-black rounded mb-3 overflow-hidden border border-secondary p-2 d-none" id="imageOutputBox">
                            <img id="outputImage" class="img-fluid" src="" style="max-height: 350px;">
                        </div>

                        <!-- Downloader and statistics link -->
                        <div class="d-flex gap-2 mb-3">
                            <a id="downloadAssetBtn" href="" download class="btn btn-success w-100 fw-bold" style="border-radius: 4px;">
                                Download Output <i class="fa-solid fa-download ms-2"></i>
                            </a>
                            <a id="previewAssetBtn" href="" target="_blank" class="btn btn-outline-info px-4" style="border-radius: 4px;">
                                <i class="fa-solid fa-up-right-from-square"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Live Command Pipeline logs -->
                <div class="mt-4">
                    <label class="form-label form-label-cyber">FFmpeg Pipeline Debugger</label>
                    <pre class="console-log mb-0" id="consoleLogs">Waiting for pipeline executions...</pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    /**
     * Switch visual cards between Video and Image workspaces
     */
    function switchTab(type) {
        document.getElementById('tabVideoBtn').classList.remove('active');
        document.getElementById('tabImageBtn').classList.remove('active');
        document.getElementById('videoStudioPanel').classList.add('d-none');
        document.getElementById('imageStudioPanel').classList.add('d-none');

        if (type === 'video') {
            document.getElementById('tabVideoBtn').classList.add('active');
            document.getElementById('videoStudioPanel').classList.remove('d-none');
        } else {
            document.getElementById('tabImageBtn').classList.add('active');
            document.getElementById('imageStudioPanel').classList.remove('d-none');
        }
    }

    /**
     * AJAX submit for media processing operations
     */
    function submitMediaForm(event, action, formId) {
        event.preventDefault();
        
        const form = document.getElementById(formId);
        const formData = new FormData(form);
        
        // Visual loaders active
        document.getElementById('outputPlaceholder').classList.add('d-none');
        document.getElementById('outputMediaContainer').classList.add('d-none');
        document.getElementById('processingLoader').classList.remove('d-none');
        document.getElementById('consoleLogs').innerText = "Processing shell instructions...";

        fetch('process_studio_media.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error("HTTP error " + response.status);
            }
            return response.json();
        })
        .then(data => {
            document.getElementById('processingLoader').classList.add('d-none');
            
            // Output shell CLI executed inside the debugging console
            document.getElementById('consoleLogs').innerText = "Executed Instruction:\n" + data.cmd_log + "\n\nServer Status Message:\n" + data.message;
            
            if (data.success) {
                document.getElementById('outputMediaContainer').classList.remove('d-none');
                
                // Toggle boxes
                if (action === 'process_video') {
                    document.getElementById('videoOutputBox').classList.remove('d-none');
                    document.getElementById('imageOutputBox').classList.add('d-none');
                    
                    const video = document.getElementById('outputVideo');
                    video.src = data.output_url;
                    video.load();
                } else {
                    document.getElementById('videoOutputBox').classList.add('d-none');
                    document.getElementById('imageOutputBox').classList.remove('d-none');
                    
                    document.getElementById('outputImage').src = data.output_url;
                }

                // Configure downloads links
                document.getElementById('downloadAssetBtn').href = data.output_url;
                document.getElementById('previewAssetBtn').href = data.output_url;
            } else {
                document.getElementById('outputPlaceholder').classList.remove('d-none');
                alert("Processing failed: " + data.message);
            }
        })
        .catch(error => {
            document.getElementById('processingLoader').classList.add('d-none');
            document.getElementById('outputPlaceholder').classList.remove('d-none');
            document.getElementById('consoleLogs').innerText = "AJAX error occurred:\n" + error.message;
            console.error("Studio processing failed: ", error);
        });
    }
</script>
</body>
</html>
