<?php
/**
 * MediaFusion - Standalone Media Processor & Center Caption Studio
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

require_once __DIR__ . '/backend/bootstrap.php';

// On POST, validate auth, CSRF, and rate limit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = requireAuth();
    require_csrf();
    rateLimitPolicy('processing_video');
    enforce_concurrent_job_limit($userId, 'ffmpeg');
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

    // --- Save Project ---
    if ($action === 'save_project') {
        require_once __DIR__ . '/backend/db.php';
        $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
        $name = isset($_POST['name']) ? trim((string)$_POST['name']) : 'Untitled Project';
        $aspectRatio = isset($_POST['aspect_ratio']) ? trim((string)$_POST['aspect_ratio']) : '16:9';
        $width = isset($_POST['width']) ? intval($_POST['width']) : 1920;
        $height = isset($_POST['height']) ? intval($_POST['height']) : 1080;
        $fps = isset($_POST['fps']) ? intval($_POST['fps']) : 30;
        $background = isset($_POST['background']) ? trim((string)$_POST['background']) : '#000000';
        $exportFormat = isset($_POST['export_format']) ? trim((string)$_POST['export_format']) : 'mp4';
        $exportResolution = isset($_POST['export_resolution']) ? trim((string)$_POST['export_resolution']) : '1080p';
        $projectData = isset($_POST['project_data']) ? trim((string)$_POST['project_data']) : (isset($_POST['timeline_json']) ? trim((string)$_POST['timeline_json']) : '{}');
        $timelineJson = isset($_POST['timeline_json']) ? trim((string)$_POST['timeline_json']) : $projectData;

        try {
            if ($projectId > 0) {
                // Strict ownership check
                $checkStmt = $pdo->prepare("SELECT id FROM studio_projects WHERE id = ? AND user_id = ?");
                $checkStmt->execute([$projectId, $_SESSION['user_id']]);
                if ($checkStmt->fetch()) {
                    $stmt = $pdo->prepare("UPDATE studio_projects SET name = ?, aspect_ratio = ?, width = ?, height = ?, fps = ?, background = ?, export_format = ?, export_resolution = ?, project_data = ?, timeline_json = ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$name, $aspectRatio, $width, $height, $fps, $background, $exportFormat, $exportResolution, $projectData, $timelineJson, $projectId, $_SESSION['user_id']]);
                } else {
                    $projectId = 0; // fallback to insert if not owner
                }
            }
            
            if ($projectId === 0) {
                $stmt = $pdo->prepare("INSERT INTO studio_projects (user_id, name, aspect_ratio, width, height, fps, background, export_format, export_resolution, project_data, timeline_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $name, $aspectRatio, $width, $height, $fps, $background, $exportFormat, $exportResolution, $projectData, $timelineJson]);
                $projectId = (int)$pdo->lastInsertId();
            }
            $response['success'] = true;
            $response['message'] = "Project saved successfully.";
            $response['project_id'] = $projectId;
        } catch (Exception $e) {
            error_log("Project save error: " . $e->getMessage());
            $response['message'] = "A system error occurred. Please try again.";
        }
        echo json_encode($response);
        exit;
    }

    // --- Load Project ---
    if ($action === 'load_project') {
        require_once __DIR__ . '/backend/db.php';
        $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;

        try {
            $stmt = $pdo->prepare("SELECT * FROM studio_projects WHERE id = ? AND user_id = ?");
            $stmt->execute([$projectId, $_SESSION['user_id']]);
            $project = $stmt->fetch();
            if ($project) {
                $response['success'] = true;
                $response['project'] = $project;
            } else {
                $response['message'] = "Project not found.";
            }
        } catch (Exception $e) {
            error_log("Project load error: " . $e->getMessage());
            $response['message'] = "A system error occurred. Please try again.";
        }
        echo json_encode($response);
        exit;
    }

    // --- List Projects ---
    if ($action === 'list_projects') {
        require_once __DIR__ . '/backend/db.php';

        try {
            $stmt = $pdo->prepare("SELECT id, name, aspect_ratio, updated_at FROM studio_projects WHERE user_id = ? ORDER BY updated_at DESC");
            $stmt->execute([$_SESSION['user_id']]);
            $projects = $stmt->fetchAll();
            $response['success'] = true;
            $response['projects'] = $projects;
        } catch (Exception $e) {
            error_log("Project list error: " . $e->getMessage());
            $response['message'] = "A system error occurred. Please try again.";
        }
        echo json_encode($response);
        exit;
    }

    // --- Delete Project ---
    if ($action === 'delete_project') {
        require_once __DIR__ . '/backend/db.php';
        $projectId = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;

        try {
            $stmt = $pdo->prepare("DELETE FROM studio_projects WHERE id = ? AND user_id = ?");
            $stmt->execute([$projectId, $_SESSION['user_id']]);
            $response['success'] = true;
            $response['message'] = "Project deleted.";
        } catch (Exception $e) {
            error_log("Project delete error: " . $e->getMessage());
            $response['message'] = "A system error occurred. Please try again.";
        }
        echo json_encode($response);
        exit;
    }

    if ($action === 'process_video') {
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
            require_once __DIR__ . '/backend/db.php';
            $checkStmt = $pdo->prepare("SELECT id FROM uploads WHERE filename = ? AND user_id = ?");
            $checkStmt->execute([$existing, $_SESSION['user_id']]);
            if (!$checkStmt->fetch()) {
                $response['message'] = "Unauthorized access to media file.";
                echo json_encode($response);
                exit;
            }
            $existingPath = $uploadDir . '/' . $existing;
            if (is_file($existingPath)) {
                $tempPath = $existingPath;
            } else {
                $existingPath = $uploadDir . '/videos/' . $existing;
                if (is_file($existingPath)) {
                    $tempPath = $existingPath;
                }
            }
        }

        if ($tempPath === '') {
            $response['message'] = "No video source provided. Upload a file or pick an existing path.";
            echo json_encode($response);
            exit;
        }

        // Parameters
        $timelinePayload = isset($_POST['timeline_payload']) ? trim((string)$_POST['timeline_payload']) : '';
        $timelineData = json_decode($timelinePayload, true);
        
        $startTime = '0';
        $endTime   = '';
        $aspectRatio = '16:9';

        if (is_array($timelineData)) {
            $aspectRatio = $timelineData['aspectRatio'] ?? '16:9';
            if (isset($timelineData['trimStart'])) {
                $startTime = parseTimeInput((string)$timelineData['trimStart']);
            }
            if (isset($timelineData['trimEnd'])) {
                $endTime = parseTimeInput((string)$timelineData['trimEnd']);
            }
        } else {
            $startTime = parseTimeInput($_POST['start_time'] ?? '0');
            $endTime   = parseTimeInput($_POST['end_time'] ?? '');
        }

        // Detect Audio Stream presence via ffprobe
        $hasAudio = false;
        $ffprobeBin = '/usr/bin/ffprobe';
        if (is_executable($ffprobeBin)) {
            $probeCmd = "env -u LD_LIBRARY_PATH {$ffprobeBin} -v error -select_streams a -show_entries stream=codec_name -of default=noprint_wrappers=1 " . escapeshellarg($tempPath);
            $probeOut = shell_exec($probeCmd);
            if ($probeOut && trim($probeOut) !== '') {
                $hasAudio = true;
            }
        }

        // Conforming target resolution
        $targetW = 1920;
        $targetH = 1080;
        if ($aspectRatio === '9:16') {
            $targetW = 1080;
            $targetH = 1920;
        } elseif ($aspectRatio === '1:1') {
            $targetW = 1080;
            $targetH = 1080;
        } elseif ($aspectRatio === '4:5') {
            $targetW = 1080;
            $targetH = 1350;
        } elseif ($aspectRatio === '21:9') {
            $targetW = 2560;
            $targetH = 1080;
        }

        $filterComplex = [];
        $filterComplexOutputs = [];
        
        // Video conform stream filter
        $videoFilters = "[0:v]scale={$targetW}:{$targetH}:force_original_aspect_ratio=decrease,pad={$targetW}:{$targetH}:(ow-iw)/2:(oh-ih)/2";

        // Playback speed
        if (is_array($timelineData) && isset($timelineData['speed'])) {
            $speed = floatval($timelineData['speed']);
            if ($speed <= 0) $speed = 1.0;
            if ($speed != 1.0) {
                $ptsFactor = 1.0 / $speed;
                $videoFilters .= ",setpts={$ptsFactor}*PTS";
            }
        }

        // Color Grading (eq filter)
        $saturationVal = 100;
        $contrastVal = 100;
        $brightnessVal = 100;
        if (is_array($timelineData) && isset($timelineData['videoClip'])) {
            $vClip = $timelineData['videoClip'];
            $saturationVal = isset($vClip['saturation']) ? intval($vClip['saturation']) : 100;
            $contrastVal = isset($vClip['contrast']) ? intval($vClip['contrast']) : 100;
            $brightnessVal = isset($vClip['brightness']) ? intval($vClip['brightness']) : 100;
        }
        if ($saturationVal !== 100 || $contrastVal !== 100 || $brightnessVal !== 100) {
            $normB = ($brightnessVal - 100) / 100.0;
            $normC = $contrastVal / 100.0;
            $normS = $saturationVal / 100.0;
            $videoFilters .= ",eq=brightness={$normB}:contrast={$normC}:saturation={$normS}";
        }

        // Process text clips
        $fontfile = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        if (is_array($timelineData) && isset($timelineData['texts']) && is_array($timelineData['texts'])) {
            foreach ($timelineData['texts'] as $textClip) {
                if (!empty($textClip['_isSplitMarker'])) continue;
                
                $txt = trim((string)($textClip['text'] ?? ''));
                if ($txt === '') continue;
                
                $escapedText = escapeFfmpegDrawtext($txt);
                $start = floatval($textClip['start'] ?? 0);
                $duration = floatval($textClip['duration'] ?? ($textClip['end'] - $textClip['start']));
                $end = $start + $duration;
                $x = floatval($textClip['x'] ?? 50);
                $y = floatval($textClip['y'] ?? 50);
                $size = intval($textClip['size'] ?? 24);
                $color = trim((string)($textClip['color'] ?? 'white'));
                
                $rotation = floatval($textClip['rotation'] ?? 0);
                $rotStr = "";
                if ($rotation !== 0.0) {
                    $rad = deg2rad($rotation);
                    $rotStr = ":rotation={$rad}";
                }
                
                $videoFilters .= ",drawtext=fontfile='{$fontfile}':text='{$escapedText}':x=(w*{$x}/100)-text_w/2:y=(h*{$y}/100)-text_h/2:fontsize={$size}:fontcolor='{$color}':enable='between(t,{$start},{$end})'{$rotStr}:shadowx=2:shadowy=2:shadowcolor=black@0.9:box=1:boxcolor=black@0.35:boxborderw=8";
            }
        }

        // Process sticker clips
        if (is_array($timelineData) && isset($timelineData['stickers']) && is_array($timelineData['stickers'])) {
            foreach ($timelineData['stickers'] as $stkClip) {
                $emoji = trim((string)($stkClip['emoji'] ?? ''));
                if ($emoji === '') continue;
                
                $escapedEmoji = escapeFfmpegDrawtext($emoji);
                $start = floatval($stkClip['start'] ?? 0);
                $duration = floatval($stkClip['duration'] ?? ($stkClip['end'] - $stkClip['start']));
                $end = $start + $duration;
                $x = floatval($stkClip['x'] ?? 50);
                $y = floatval($stkClip['y'] ?? 50);
                $size = intval($stkClip['size'] ?? 40);
                
                $rotation = floatval($stkClip['rotation'] ?? 0);
                $rotStr = "";
                if ($rotation !== 0.0) {
                    $rad = deg2rad($rotation);
                    $rotStr = ":rotation={$rad}";
                }
                
                $emojiFont = '/usr/share/fonts/truetype/noto/NotoColorEmoji.ttf';
                $activeFont = is_file($emojiFont) ? $emojiFont : $fontfile;
                
                $videoFilters .= ",drawtext=fontfile='{$activeFont}':text='{$escapedEmoji}':x=(w*{$x}/100)-text_w/2:y=(h*{$y}/100)-text_h/2:fontsize={$size}:enable='between(t,{$start},{$end})'{$rotStr}";
            }
        }

        $filterComplex[] = $videoFilters . "[v_final]";

        // Audio track setup
        $audioInputArgs = [];
        $inputIndex = 1;

        if ($hasAudio) {
            $volDb = 1.0;
            if (is_array($timelineData) && isset($timelineData['volume'])) {
                $volDb = floatval($timelineData['volume']) / 100.0;
            }
            $mainAudioFilters = "volume={$volDb}";
            
            if (is_array($timelineData) && isset($timelineData['speed'])) {
                $speed = floatval($timelineData['speed']);
                if ($speed > 0 && $speed != 1.0) {
                    $mainAudioFilters .= ",atempo={$speed}";
                }
            }
            $filterComplex[] = "[0:a]{$mainAudioFilters}[main_audio]";
            $filterComplexOutputs[] = "[main_audio]";
        } else {
            $projDuration = ($endTime !== '') ? (floatval($endTime) - floatval($startTime)) : 15.0;
            if ($projDuration <= 0) $projDuration = 15.0;
            $filterComplex[] = "anullsrc=channel_layout=stereo:sample_rate=48000:duration={$projDuration}[silence]";
            $filterComplexOutputs[] = "[silence]";
        }

        // Add additional timeline audios
        if (is_array($timelineData) && isset($timelineData['audios']) && is_array($timelineData['audios'])) {
            foreach ($timelineData['audios'] as $clip) {
                $type = basename((string)($clip['type'] ?? ''));
                $clipPath = __DIR__ . "/assets/audio/{$type}.mp3";
                if (is_file($clipPath)) {
                    $audioInputArgs[] = "-i " . escapeshellarg($clipPath);
                    
                    $clipStart = floatval($clip['start'] ?? 0);
                    $clipDuration = floatval($clip['duration'] ?? 2);
                    $clipVol = floatval(($clip['volume'] ?? 100)) / 100.0;
                    $delayMs = intval($clipStart * 1000);
                    
                    $filterComplex[] = "[{$inputIndex}:a]atrim=0:{$clipDuration},asetpts=PTS-STARTPTS,volume={$clipVol},adelay={$delayMs}|{$delayMs}[aud{$inputIndex}]";
                    $filterComplexOutputs[] = "[aud{$inputIndex}]";
                    $inputIndex++;
                }
            }
        }

        // Mix all audio sources
        $mixInputsCount = count($filterComplexOutputs);
        if ($mixInputsCount > 1) {
            $filterComplex[] = implode('', $filterComplexOutputs) . "amix=inputs={$mixInputsCount}:duration=first:dropout_transition=2[a_mix]";
            $audioOutMap = "[a_mix]";
        } else {
            $audioOutMap = $filterComplexOutputs[0];
        }

        $filterComplexStr = implode(';', $filterComplex);

        // Generate target output
        $outName = 'trimmed_' . time() . '.mp4';
        $outPath = $processedDir . '/' . $outName;

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

        // Combine all input arguments
        $inputArgsStr = "-i " . escapeshellarg($tempPath);
        if (!empty($audioInputArgs)) {
            $inputArgsStr .= " " . implode(' ', $audioInputArgs);
        }

        $cmd = "env -u LD_LIBRARY_PATH {$ffmpegBin} -y " . implode(' ', $timeArgs) . " {$inputArgsStr} -filter_complex " . escapeshellarg($filterComplexStr) . " -map \"[v_final]\" -map \"{$audioOutMap}\" -c:v libx264 -preset superfast -crf 23 -c:a aac " . escapeshellarg($outPath) . " 2>&1";

        $response['cmd_log'] = $cmd;

        // Execute proc_open safely to catch errors and stdout
        $descriptors = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]); // close stdin immediately
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $output   = $stdout . $stderr;
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
            require_once __DIR__ . '/backend/db.php';
            $checkStmt = $pdo->prepare("SELECT id FROM uploads WHERE filename = ? AND user_id = ?");
            $checkStmt->execute([$existing, $_SESSION['user_id']]);
            if (!$checkStmt->fetch()) {
                $response['message'] = "Unauthorized access to media file.";
                echo json_encode($response);
                exit;
            }
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

        $cmd = "env -u LD_LIBRARY_PATH {$ffmpegBin} -y -i " . escapeshellarg($tempPath) . " {$filterStr} " . escapeshellarg($outPath) . " 2>&1";
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
    <title>Media Caption Studio & Processing Center — MediaFusion</title>
    
    <!-- CSS Library Setup -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <!-- Premium Cyberpunk Theme Custom Rules -->
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
        }

        body {
            background-color: var(--bg-color);
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
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--card-radius);
            padding: 2.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
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

        .cyber-input {
            background-color: #ffffff !important;
            border: 1px solid var(--card-border) !important;
            color: var(--text-primary) !important;
        }
        .cyber-input:focus {
            background-color: #ffffff !important;
            border-color: var(--primary-bg) !important;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15) !important;
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
            border: 1px solid #cbd5e1;
            color: var(--text-secondary);
            font-weight: 600;
            padding: 0.6rem 1.5rem;
            border-radius: 6px;
            transition: all 0.3s ease;
            background: #ffffff;
        }
        .studio-nav-btn.active, .studio-nav-btn:hover {
            color: var(--primary-text);
            border-color: var(--primary-bg);
            background: var(--primary-bg);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
        }

        .range-slider-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .range-value {
            color: var(--primary-bg);
            font-weight: 600;
            font-size: 0.85rem;
        }

        .console-log {
            background: #0f172a;
            border: 1px solid #1e293b;
            font-family: 'Courier New', Courier, monospace;
            color: #38bdf8;
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
            Trim and edit your videos and images, then download the final files directly.
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
                <h3 class="text-gradient-magenta mb-4"><i class="fa-solid fa-scissors me-2"></i>Trim & Burn Video</h3>
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

                    <button type="submit" class="btn btn-primary w-100 py-3 fw-bold text-uppercase" style="border-radius: 6px; background: var(--primary-bg); border-color: var(--primary-bg); color: var(--primary-text);">
                        Execute Video Trim & Burn <i class="fa-solid fa-arrows-spin ms-2"></i>
                    </button>
                </form>
            </div>

            <!-- Image Control Card (Hidden by default) -->
            <div class="glass-card d-none" id="imageStudioPanel">
                <h3 class="text-gradient-cyan mb-4"><i class="fa-solid fa-sliders me-2"></i>Crop & Fine-Tune Image</h3>
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

                    <button type="submit" class="btn btn-primary w-100 py-3 fw-bold text-uppercase" style="border-radius: 6px; background: var(--primary-bg); border-color: var(--primary-bg); color: var(--primary-text);">
                        Execute Image Crop & Burn <i class="fa-solid fa-arrows-spin ms-2"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- Output display console -->
        <div class="col-lg-6">
            <div class="glass-card h-100 d-flex flex-column justify-content-between">
                <div>
                    <h3 class="mb-4"><i class="fa-solid fa-chart-line me-2 text-gradient-cyan"></i>Real-Time Output Panel</h3>
                    
                    <!-- Preloading spinner -->
                    <div class="text-center py-5 d-none" id="processingLoader">
                        <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;" role="status"></div>
                        <p class="text-secondary text-uppercase fw-bold" style="font-size: 0.8rem; letter-spacing: 2px;">
                            Media Processing Active...
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
                        <div class="ratio ratio-16x9 bg-black rounded mb-3 overflow-hidden border border-light" id="videoOutputBox">
                            <video id="outputVideo" controls src=""></video>
                        </div>
                        <div class="text-center bg-black rounded mb-3 overflow-hidden border border-light p-2 d-none" id="imageOutputBox">
                            <img id="outputImage" class="img-fluid" src="" style="max-height: 350px;">
                        </div>

                        <!-- Downloader and statistics link -->
                        <div class="d-flex gap-2 mb-3">
                            <a id="downloadAssetBtn" href="" download class="btn btn-success w-100 fw-bold" style="border-radius: 4px;">
                                Download Output <i class="fa-solid fa-download ms-2"></i>
                            </a>
                            <a id="previewAssetBtn" href="" target="_blank" class="btn btn-outline-primary px-4" style="border-radius: 4px;">
                                <i class="fa-solid fa-up-right-from-square"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Live Command Pipeline logs -->
                <div class="mt-4">
                    <label class="form-label form-label-cyber">Processing Logs</label>
                    <pre class="console-log mb-0" id="consoleLogs">Waiting for media processing...</pre>
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
