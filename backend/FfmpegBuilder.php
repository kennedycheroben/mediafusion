<?php
declare(strict_types=1);

class FfmpegBuilder {
    private $projectData;
    private $inputData;
    private $inputs = [];
    private $visualStreams = [];
    private $audioStreams = [];
    private $filterComplex = [];
    private $baseDir;
    private $urlResolver;
    private $tempFiles = [];

    public function __construct(array $projectData, array $inputData) {
        $this->projectData = $projectData;
        $this->inputData = $inputData;
        $this->baseDir = dirname(__DIR__, 2); // /opt/lampp/htdocs/mediafusion

        // Use MediaUrlResolver if available
        if (class_exists(\MediaFusion\Storage\MediaUrlResolver::class)) {
            $this->urlResolver = new \MediaFusion\Storage\MediaUrlResolver();
        }
    }

    public function getProjectDuration(): float {
        // Find the maximum end time across all items
        $maxDuration = 0;
        $items = $this->projectData['items'] ?? [];
        foreach ($items as $item) {
            $end = ($item['start'] ?? 0) + ($item['duration'] ?? 0);
            if ($end > $maxDuration) {
                $maxDuration = $end;
            }
        }
        return $maxDuration > 0 ? $maxDuration : 10.0;
    }

    private function getResolution(): array {
        $reqRes = $this->inputData['resolution'] ?? '1080p';
        if ($reqRes === '4k') return [3840, 2160];
        if ($reqRes === '720p') return [1280, 720];
        return [1920, 1080];
    }

    private function getFps(): int {
        return intval($this->inputData['fps'] ?? 30);
    }
    
    private function getCrf(): int {
        $q = $this->inputData['quality'] ?? 'medium';
        if ($q === 'high') return 18;
        if ($q === 'low') return 28;
        return 23;
    }

    private function resolveLocalPath(string $url): ?string {
        if (empty($url)) return null;

        // Use MediaUrlResolver if available (supports object storage)
        if ($this->urlResolver !== null) {
            // Try to extract storage key from URL
            // Pattern matches: users/{userId}/{category}/{uuid}/{variant}.{ext}
            $key = null;
            if (preg_match('#(users/\d+/[a-zA-Z0-9_-]+/[a-f0-9]+/[a-zA-Z0-9_.-]+)#i', $url, $matches)) {
                $key = $matches[1];
            }

            if ($key !== null) {
                $localPath = $this->urlResolver->getLocalPath($key);
                if ($localPath !== null) {
                    return $localPath;
                }
                // File is in object storage — download to temp
                $tmpPath = $this->urlResolver->ensureLocal($key, $url);
                if ($tmpPath !== null) {
                    $this->tempFiles[] = $tmpPath;
                    return $tmpPath;
                }
            }

            // Try legacy path resolution
            $resolved = $this->urlResolver->resolveLegacy($url);
            // Convert URL back to local path
            $urlPath = parse_url($resolved, PHP_URL_PATH);
            if ($urlPath !== null) {
                $localPath = $this->baseDir . '/' . ltrim($urlPath, '/');
                if (is_file($localPath)) {
                    return $localPath;
                }
            }
        }

        // Fallback: legacy path resolution
        $url = parse_url($url, PHP_URL_PATH);
        if (!$url) return null;
        
        $url = ltrim($url, '/');
        if (strpos($url, 'mediafusion/') === 0) {
            $url = substr($url, 12);
        }
        
        $localPath = $this->baseDir . '/' . $url;
        if (is_file($localPath)) {
            return $localPath;
        }
        return null;
    }

    private function addInput(string $path): int {
        $idx = array_search($path, $this->inputs, true);
        if ($idx !== false) {
            return $idx;
        }
        $this->inputs[] = $path;
        return count($this->inputs) - 1;
    }

    private function escapeText(string $text): string {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace("'", "'\\\\''", $text);
        $text = str_replace(':', '\\:', $text);
        $text = str_replace('%', '\\%', $text);
        return $text;
    }

    public function buildFfmpegCommand(string $outPath, string $progressLog): string {
        list($w, $h) = $this->getResolution();
        $fps = $this->getFps();
        $duration = $this->getProjectDuration();
        
        $bg = $this->projectData['projectSettings']['background'] ?? '#000000';
        if (!preg_match('/^#[a-fA-F0-9]{6}$/', $bg)) {
            $bg = '#000000';
        }
        $bgHex = ltrim($bg, '#');
        $bg = '0x' . $bgHex;

        // 1. Generate base canvas
        $this->filterComplex[] = "color=c={$bg}:s={$w}x{$h}:r={$fps}:d={$duration}[base]";
        $lastVisualLayer = 'base';

        // 2. Sort tracks and items
        $tracks = $this->projectData['tracks'] ?? [];
        $items = $this->projectData['items'] ?? [];
        
        usort($tracks, function($a, $b) {
            return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
        });

        // 3. Process each visual layer
        $visualIndex = 0;
        foreach ($tracks as $track) {
            if ($track['type'] === 'audio') continue;

            $trackItems = array_filter($items, function($i) use ($track) {
                return $i['trackId'] === $track['id'];
            });

            // Sort items by start time
            usort($trackItems, function($a, $b) {
                return ($a['start'] ?? 0) <=> ($b['start'] ?? 0);
            });

            foreach ($trackItems as $item) {
                $type = $item['type'];
                $start = floatval($item['start'] ?? 0);
                $dur = floatval($item['duration'] ?? 5);
                $end = $start + $dur;
                
                $outStream = "[v{$visualIndex}]";
                $filters = [];

                if ($type === 'video' || $type === 'image') {
                    $localPath = $this->resolveLocalPath($item['sourceUrl'] ?? '');
                    if (!$localPath) continue;
                    
                    $inIdx = $this->addInput($localPath);
                    $inStream = "[{$inIdx}:v]";
                    
                    // Basic Trim
                    $sourceStart = floatval($item['sourceStart'] ?? 0);
                    if ($type === 'video') {
                        $filters[] = "trim=start={$sourceStart}:duration={$dur}";
                        $filters[] = "setpts=PTS-STARTPTS";
                    } else {
                        // Image looping
                        $inStream = "loop=loop=-1:size=1:start=0[{$inIdx}:v]";
                        $filters[] = "trim=duration={$dur}";
                        $filters[] = "setpts=PTS-STARTPTS";
                    }

                    // Speed
                    $speed = floatval($item['speed'] ?? 1.0);
                    if ($speed !== 1.0 && $speed > 0) {
                        $ptsFactor = 1.0 / $speed;
                        $filters[] = "setpts={$ptsFactor}*PTS";
                    }

                    // Filters (Brightness/Contrast/Saturation)
                    $f = $item['filters'] ?? [];
                    if (!empty($f)) {
                        $b = floatval($f['brightness'] ?? 100);
                        $c = floatval($f['contrast'] ?? 100);
                        $s = floatval($f['saturation'] ?? 100);
                        if ($b !== 100 || $c !== 100 || $s !== 100) {
                            $normB = ($b - 100) / 100.0;
                            $normC = $c / 100.0;
                            $normS = $s / 100.0;
                            $filters[] = "eq=brightness={$normB}:contrast={$normC}:saturation={$normS}";
                        }
                    }

                    // Scale
                    $itemW = intval($item['width'] ?? $w);
                    $itemH = intval($item['height'] ?? $h);
                    $filters[] = "scale={$itemW}:{$itemH}:force_original_aspect_ratio=decrease,pad={$itemW}:{$itemH}:(ow-iw)/2:(oh-ih)/2";

                    $this->filterComplex[] = $inStream . implode(',', $filters) . $outStream;

                    // Overlay
                    $posX = intval($item['position']['x'] ?? 0);
                    $posY = intval($item['position']['y'] ?? 0);
                    $nextBase = "[base_next_{$visualIndex}]";
                    $this->filterComplex[] = "[{$lastVisualLayer}]{$outStream}overlay=x={$posX}:y={$posY}:enable='between(t,{$start},{$end})'{$nextBase}";
                    $lastVisualLayer = rtrim(ltrim($nextBase, '['), ']');
                    
                    $visualIndex++;
                } else if ($type === 'text') {
                    $txt = $this->escapeText($item['content'] ?? '');
                    if ($txt === '') continue;
                    
                    $fontfile = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
                    $fontSize = intval($item['style']['fontSize'] ?? 48);
                    $color = $item['style']['color'] ?? 'white';
                    if (!preg_match('/^#[a-fA-F0-9]{3,8}$|^[a-zA-Z0-9_]+$/', $color)) {
                        $color = 'white';
                    }
                    $posX = intval($item['position']['x'] ?? 0);
                    $posY = intval($item['position']['y'] ?? 0);
                    
                    $nextBase = "[base_next_txt_{$visualIndex}]";
                    $this->filterComplex[] = "[{$lastVisualLayer}]drawtext=fontfile='{$fontfile}':text='{$txt}':x={$posX}:y={$posY}:fontsize={$fontSize}:fontcolor='{$color}':enable='between(t,{$start},{$end})':shadowx=2:shadowy=2:shadowcolor=black@0.8{$nextBase}";
                    $lastVisualLayer = rtrim(ltrim($nextBase, '['), ']');
                    $visualIndex++;
                } else if ($type === 'sticker') {
                    $txt = $this->escapeText($item['content'] ?? '');
                    if ($txt === '') continue;
                    
                    $fontfile = '/usr/share/fonts/truetype/noto/NotoColorEmoji.ttf';
                    if (!is_file($fontfile)) $fontfile = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
                    
                    $fontSize = 120; // default large size for sticker
                    $posX = intval($item['position']['x'] ?? 0);
                    $posY = intval($item['position']['y'] ?? 0);
                    
                    $nextBase = "[base_next_stk_{$visualIndex}]";
                    $this->filterComplex[] = "[{$lastVisualLayer}]drawtext=fontfile='{$fontfile}':text='{$txt}':x={$posX}:y={$posY}:fontsize={$fontSize}:enable='between(t,{$start},{$end})'{$nextBase}";
                    $lastVisualLayer = rtrim(ltrim($nextBase, '['), ']');
                    $visualIndex++;
                }
            }
        }
        
        // Output visual stream is $lastVisualLayer
        
        // 4. Process Audio
        $audioStreams = [];
        $audioIndex = 0;
        foreach ($items as $item) {
            $localPath = null;
            $start = floatval($item['start'] ?? 0);
            $dur = floatval($item['duration'] ?? 5);
            
            if ($item['type'] === 'video') {
                // Try to use video audio
                $localPath = $this->resolveLocalPath($item['sourceUrl'] ?? '');
            } else if ($item['type'] === 'audio') {
                if (isset($item['sourceMediaId'])) {
                    $mediaId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$item['sourceMediaId']);
                    $localPath = $this->baseDir . '/assets/audio/' . $mediaId . '.mp3';
                }
            }

            if ($localPath && is_file($localPath)) {
                $inIdx = $this->addInput($localPath);
                $vol = floatval($item['volume'] ?? 100) / 100.0;
                $delayMs = intval($start * 1000);
                
                $sourceStart = floatval($item['sourceStart'] ?? 0);
                
                $aFilters = "atrim=start={$sourceStart}:duration={$dur},asetpts=PTS-STARTPTS,volume={$vol},adelay={$delayMs}|{$delayMs}";
                $aOut = "[a{$audioIndex}]";
                $this->filterComplex[] = "[{$inIdx}:a]{$aFilters}{$aOut}";
                $audioStreams[] = $aOut;
                $audioIndex++;
            }
        }

        $audioMap = "";
        if (count($audioStreams) > 0) {
            $ac = count($audioStreams);
            $this->filterComplex[] = implode('', $audioStreams) . "amix=inputs={$ac}:duration=first:dropout_transition=2[a_mix]";
            $audioMap = "-map \"[a_mix]\" -c:a aac";
        } else {
            // Generate silence
            $this->filterComplex[] = "anullsrc=channel_layout=stereo:sample_rate=48000:duration={$duration}[silence]";
            $audioMap = "-map \"[silence]\" -c:a aac";
        }

        // Assemble command
        $ffmpegBin = '/usr/bin/ffmpeg';
        $cmd = "env -u LD_LIBRARY_PATH {$ffmpegBin} -y ";
        foreach ($this->inputs as $in) {
            $cmd .= "-i " . escapeshellarg($in) . " ";
        }
        
        $crf = $this->getCrf();
        
        $fc = implode(';', $this->filterComplex);
        $cmd .= "-filter_complex " . escapeshellarg($fc) . " -map \"[{$lastVisualLayer}]\" {$audioMap} ";
        $cmd .= "-c:v libx264 -preset superfast -crf {$crf} -progress " . escapeshellarg($progressLog) . " " . escapeshellarg($outPath);
        
        return $cmd;
    }

    /**
     * Clean up any temporary files downloaded from object storage.
     * Must be called after FFmpeg processing completes.
     */
    public function cleanup(): void {
        foreach ($this->tempFiles as $tmpFile) {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
        $this->tempFiles = [];
    }
}
