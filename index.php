<?php
// ==========================================
// VIRAL REELS MAKER v79 - MOBILE OPTIMIZED
// ==========================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
ini_set('memory_limit', '2048M');
ini_set('upload_max_filesize', '2048M');
ini_set('post_max_size', '2048M');
ini_set('max_execution_time', 0);
set_time_limit(0);
ignore_user_abort(true);

ob_start();

$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs';
$assetsDir = $baseDir . '/assets';
$logoPath = $baseDir . '/logo.png';
$fontPath = $baseDir . '/font.ttf';
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

foreach ([$uploadDir, $processedDir, $jobsDir, $assetsDir] as $dir) {
    if (!file_exists($dir)) @mkdir($dir, 0777, true);
}

// Limpieza
foreach ([$uploadDir, $processedDir, $jobsDir, $assetsDir] as $dir) {
    if (is_dir($dir)) {
        foreach (glob("$dir/*") as $file) {
            if (is_file($file) && (time() - filemtime($file) > 14400)) @unlink($file);
        }
    }
}

function sendJson($data) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function logMsg($msg) {
    global $logFile;
    $time = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND);
}

function cleanTitle($text) {
    $text = mb_strtoupper($text, 'UTF-8');
    $text = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F1E0}-\x{1F1FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{FE00}-\x{FE0F}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{200D}]/u', '', $text);
    $text = preg_replace('/[^\p{L}\p{N}\s\-\.\!\?\,]/u', '', $text);
    $text = preg_replace('/\s+/', ' ', trim($text));
    return $text;
}

function createTitleImage($title, $fontPath, $outputPath, $width = 720) {
    $fontSize = 54;
    $maxWidth = $width - 80;
    $lineHeight = 72;
    $paddingTop = 35;
    $paddingBottom = 25;
    
    $title = cleanTitle($title);
    if (empty($title)) $title = "VIDEO VIRAL";
    
    $words = preg_split('/\s+/u', $title);
    $lines = [];
    $currentLine = '';
    $tempImg = imagecreatetruecolor(1, 1);
    
    foreach ($words as $word) {
        $testLine = $currentLine ? "$currentLine $word" : $word;
        $bbox = @imagettfbbox($fontSize, 0, $fontPath, $testLine);
        if ($bbox === false) continue;
        $testWidth = abs($bbox[2] - $bbox[0]);
        
        if ($testWidth <= $maxWidth) {
            $currentLine = $testLine;
        } else {
            if ($currentLine) $lines[] = $currentLine;
            $currentLine = $word;
        }
    }
    if ($currentLine) $lines[] = $currentLine;
    imagedestroy($tempImg);
    
    if (count($lines) > 2) {
        $lines = array_slice($lines, 0, 2);
        if (mb_strlen($lines[1], 'UTF-8') > 16) {
            $lines[1] = mb_substr($lines[1], 0, 14, 'UTF-8') . '..';
        }
    }
    
    if (empty($lines)) $lines = ["VIDEO VIRAL"];
    
    $totalHeight = $paddingTop + (count($lines) * $lineHeight) + $paddingBottom;
    
    $img = imagecreatetruecolor($width, $totalHeight);
    imagesavealpha($img, true);
    imagealphablending($img, false);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    imagealphablending($img, true);
    
    $gold = imagecolorallocate($img, 255, 215, 0);
    $black = imagecolorallocate($img, 0, 0, 0);
    
    $y = $paddingTop + $fontSize;
    foreach ($lines as $line) {
        $bbox = @imagettfbbox($fontSize, 0, $fontPath, $line);
        if ($bbox === false) continue;
        
        $textWidth = abs($bbox[2] - $bbox[0]);
        $x = ($width - $textWidth) / 2;
        
        for ($sx = 3; $sx <= 5; $sx++) {
            for ($sy = 3; $sy <= 5; $sy++) {
                @imagettftext($img, $fontSize, 0, $x + $sx, $y + $sy, $black, $fontPath, $line);
            }
        }
        
        for ($bx = -3; $bx <= 3; $bx++) {
            for ($by = -3; $by <= 3; $by++) {
                if ($bx != 0 || $by != 0) {
                    @imagettftext($img, $fontSize, 0, $x + $bx, $y + $by, $black, $fontPath, $line);
                }
            }
        }
        
        @imagettftext($img, $fontSize, 0, $x, $y, $gold, $fontPath, $line);
        $y += $lineHeight;
    }
    
    imagepng($img, $outputPath, 9);
    imagedestroy($img);
    
    return $totalHeight;
}

function getAntiFingerprint() {
    return [
        'speed' => round(0.98 + (mt_rand(0, 40) / 1000), 3),
        'saturation' => round(1.05 + (mt_rand(0, 120) / 1000), 3),
        'contrast' => round(1.02 + (mt_rand(0, 60) / 1000), 3),
        'brightness' => round((mt_rand(-15, 15) / 1000), 3),
        'hue' => mt_rand(-4, 4),
        'gamma' => round(0.97 + (mt_rand(0, 60) / 1000), 3),
        'noise' => mt_rand(1, 2),
    ];
}

$action = $_GET['action'] ?? '';
$ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null') ?? '');
$ffprobePath = trim(shell_exec('which ffprobe 2>/dev/null') ?? '');
$hasFfmpeg = !empty($ffmpegPath);
$hasGD = extension_loaded('gd');
$hasLogo = file_exists($logoPath);
$hasFont = file_exists($fontPath);
$hasAudio = file_exists($audioPath);

// ==========================================
// UPLOAD
// ==========================================
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    
    logMsg("=== UPLOAD INICIADO ===");
    
    if (empty($_FILES['videoFile']['tmp_name'])) {
        sendJson(['status' => 'error', 'msg' => 'No se recibió archivo']);
    }
    
    if (!$hasFfmpeg) sendJson(['status' => 'error', 'msg' => 'FFmpeg no instalado']);
    if (!$hasGD) sendJson(['status' => 'error', 'msg' => 'PHP GD no instalado']);
    if (!$hasLogo) sendJson(['status' => 'error', 'msg' => 'Falta logo.png']);
    if (!$hasFont) sendJson(['status' => 'error', 'msg' => 'Falta font.ttf']);
    if (!$hasAudio) sendJson(['status' => 'error', 'msg' => 'Falta news.mp3']);
    
    $fileSize = filesize($_FILES['videoFile']['tmp_name']);
    if ($fileSize === false || $fileSize === 0) {
        sendJson(['status' => 'error', 'msg' => 'Archivo vacío']);
    }
    
    if ($fileSize > 500 * 1024 * 1024) {
        sendJson(['status' => 'error', 'msg' => 'Archivo muy grande (máx 500MB)']);
    }
    
    $title = trim($_POST['videoTitle'] ?? '');
    if (empty($title)) sendJson(['status' => 'error', 'msg' => 'Título obligatorio']);
    
    $title = cleanTitle($title);
    $title = mb_substr($title, 0, 36, 'UTF-8');
    if (empty($title)) $title = "VIDEO VIRAL";

    $jobId = 'v79_' . time() . '_' . mt_rand(1000, 9999);
    
    $ext = strtolower(pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'])) $ext = 'mp4';
    
    $inputFile = "$uploadDir/{$jobId}_input.$ext";
    $titleImgFile = "$assetsDir/{$jobId}_title.png";
    $outputFileName = "{$jobId}_viral.mp4";
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/{$jobId}.json";
    $scriptFile = "$jobsDir/{$jobId}.sh";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        sendJson(['status' => 'error', 'msg' => 'Error al guardar archivo']);
    }
    
    @chmod($inputFile, 0666);

    // Obtener info del video
    $seconds = 60;
    $videoWidth = 720;
    $videoHeight = 1280;
    $hasVideoAudio = false;
    
    if (!empty($ffprobePath)) {
        $cmd = "$ffprobePath -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile) . " 2>&1";
        $duration = trim(shell_exec($cmd) ?? '');
        if (is_numeric($duration) && $duration > 0) {
            $seconds = floatval($duration);
        }
        
        $cmd = "$ffprobePath -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 " . escapeshellarg($inputFile) . " 2>&1";
        $dims = trim(shell_exec($cmd) ?? '');
        if (preg_match('/(\d+)x(\d+)/', $dims, $m)) {
            $videoWidth = intval($m[1]);
            $videoHeight = intval($m[2]);
        }
        
        $cmd = "$ffprobePath -v error -select_streams a -show_entries stream=codec_type -of csv=p=0 " . escapeshellarg($inputFile) . " 2>&1";
        $audioCheck = trim(shell_exec($cmd) ?? '');
        $hasVideoAudio = !empty($audioCheck);
    }
    
    if ($seconds < 1) $seconds = 60;
    
    if ($seconds > 300) {
        @unlink($inputFile);
        sendJson(['status' => 'error', 'msg' => 'Video muy largo (máx 5 min)']);
    }

    $mirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    // Crear imagen del título
    try {
        $titleHeight = createTitleImage($title, $fontPath, $titleImgFile, 720);
        if (!file_exists($titleImgFile)) {
            throw new Exception("Título no se creó");
        }
    } catch (Exception $e) {
        @unlink($inputFile);
        sendJson(['status' => 'error', 'msg' => 'Error creando título']);
    }

    // Anti-fingerprint
    $af = getAntiFingerprint();
    $atempo = round(1 / $af['speed'], 4);
    
    logMsg("Video: {$seconds}s, {$videoWidth}x{$videoHeight}, Audio: " . ($hasVideoAudio ? 'SI' : 'NO'));
    logMsg("Título: $title");

    // Dimensiones finales del canvas
    $canvasW = 720;
    $canvasH = 1280;
    $titleY = $titleHeight;
    $videoY = $titleY + 15;
    $availableHeight = $canvasH - $videoY - 110; // Espacio disponible para el video
    
    // CORRECCIÓN CRÍTICA: Calcular escala correctamente para videos verticales y horizontales
    $isVertical = $videoHeight > $videoWidth;
    
    if ($isVertical) {
        // Video vertical: ajustar al ancho del canvas
        $scale = "scale={$canvasW}:-2";
        logMsg("Video VERTICAL - Ajustando al ancho: {$canvasW}px");
    } else {
        // Video horizontal: ajustar a la altura disponible
        $scale = "scale=-2:{$availableHeight}";
        logMsg("Video HORIZONTAL - Ajustando a la altura: {$availableHeight}px");
    }
    
    // Preset según duración
    $preset = ($seconds > 120) ? 'faster' : 'fast';
    $crf = ($seconds > 120) ? 24 : 23;
    
    $hflip = $mirror ? ",hflip" : "";
    
    // Filtro de video CORREGIDO
    $vf = "";
    $vf .= "color=c=#0a0a0a:s={$canvasW}x{$canvasH}:d=" . ceil($seconds + 2) . "[bg];";
    $vf .= "[0:v]{$scale}:flags=bilinear,setsar=1{$hflip},";
    $vf .= "setpts=" . round(1/$af['speed'], 4) . "*PTS,";
    $vf .= "eq=saturation={$af['saturation']}:contrast={$af['contrast']}:brightness={$af['brightness']}:gamma={$af['gamma']},";
    $vf .= "hue=h={$af['hue']},";
    $vf .= "noise=alls={$af['noise']}:allf=t";
    $vf .= "[vid];";
    $vf .= "[bg][vid]overlay=(W-w)/2:{$videoY}:shortest=1[v1];";
    $vf .= "[1:v]scale={$canvasW}:-1[title];";
    $vf .= "[v1][title]overlay=0:0[v2];";
    $vf .= "[2:v]scale=-1:80[logo];";
    $vf .= "[v2][logo]overlay=30:H-110[vout]";
    
    // Filtro de audio
    if ($hasVideoAudio) {
        $af_filter = "[0:a]aresample=async=1000,atempo={$atempo},volume=0.75[a1];";
        $af_filter .= "[3:a]aresample=async=1000,volume=0.3[a2];";
        $af_filter .= "[a1][a2]amix=inputs=2:duration=first:dropout_transition=3:normalize=0[aout]";
    } else {
        $af_filter = "[3:a]aresample=async=1000,volume=0.5[aout]";
    }
    
    $fullFilter = $vf . ";" . $af_filter;
    
    // Comando FFmpeg
    $cmd = "$ffmpegPath -y ";
    $cmd .= "-i " . escapeshellarg($inputFile) . " ";
    $cmd .= "-i " . escapeshellarg($titleImgFile) . " ";
    $cmd .= "-i " . escapeshellarg($logoPath) . " ";
    $cmd .= "-stream_loop -1 -i " . escapeshellarg($audioPath) . " ";
    $cmd .= "-filter_complex " . escapeshellarg($fullFilter) . " ";
    $cmd .= "-map \"[vout]\" -map \"[aout]\" ";
    $cmd .= "-c:v libx264 -preset {$preset} -crf {$crf} -threads 2 ";
    $cmd .= "-c:a aac -b:a 192k -ar 44100 ";
    $cmd .= "-movflags +faststart ";
    $cmd .= "-t " . ceil($seconds / $af['speed']) . " ";
    $cmd .= "-metadata title=\"VID" . date('ymdHis') . mt_rand(100,999) . "\" ";
    $cmd .= escapeshellarg($outputFile);

    file_put_contents($jobFile, json_encode([
        'status' => 'processing',
        'file' => $outputFileName,
        'start' => time(),
        'duration' => $seconds,
        'title' => $title,
    ]));

    // Script bash
    $script = "#!/bin/bash\n";
    $script .= "cd " . escapeshellarg($baseDir) . "\n";
    $script .= "echo \"[\" >> " . escapeshellarg($logFile) . "\n";
    $script .= $cmd . " >> " . escapeshellarg($logFile) . " 2>&1\n";
    $script .= "if [ \$? -eq 0 ] && [ -f " . escapeshellarg($outputFile) . " ]; then\n";
    $script .= "  echo \"SUCCESS\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "else\n";
    $script .= "  echo \"ERROR\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "fi\n";
    $script .= "rm -f " . escapeshellarg($inputFile) . " " . escapeshellarg($titleImgFile) . " 2>/dev/null\n";
    
    file_put_contents($scriptFile, $script);
    chmod($scriptFile, 0755);
    
    exec("nohup nice -n 19 bash " . escapeshellarg($scriptFile) . " > /dev/null 2>&1 &");

    sendJson(['status' => 'success', 'jobId' => $jobId]);
}

// ==========================================
// STATUS
// ==========================================
if ($action === 'status') {
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['jobId'] ?? '');
    if (empty($id)) sendJson(['status' => 'error', 'msg' => 'ID inválido']);
    
    $jFile = "$jobsDir/{$id}.json";
    if (!file_exists($jFile)) sendJson(['status' => 'error', 'msg' => 'Job no encontrado']);
    
    $data = json_decode(file_get_contents($jFile), true);
    if (!$data) sendJson(['status' => 'error', 'msg' => 'Error leyendo job']);
    
    $outputPath = "$processedDir/" . $data['file'];
    
    if (file_exists($outputPath)) {
        clearstatcache(true, $outputPath);
        $size = filesize($outputPath);
        $mtime = filemtime($outputPath);
        
        if ($size > 50000 && (time() - $mtime) > 5) {
            sendJson(['status' => 'finished', 'file' => $data['file']]);
        }
    }
    
    $timeout = ($data['duration'] * 5) + 600;
    $elapsed = time() - $data['start'];
    
    if ($elapsed > $timeout) {
        sendJson(['status' => 'error', 'msg' => 'Timeout']);
    }
    
    $progress = min(95, round(($elapsed / ($data['duration'] * 2.5)) * 100));
    sendJson(['status' => 'processing', 'progress' => $progress]);
}

// ==========================================
// DOWNLOAD
// ==========================================
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $path = "$processedDir/$file";
    
    if (!file_exists($path)) {
        http_response_code(404);
        die('Archivo no encontrado');
    }
    
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: video/mp4');
    header('Content-Disposition: attachment; filename="VIRAL_' . date('md_His') . '.mp4"');
    header('Content-Length: ' . filesize($path));
    
    readfile($path);
    exit;
}

// ==========================================
// DEBUG
// ==========================================
if ($action === 'debug') {
    header('Content-Type: text/plain; charset=utf-8');
    
    echo "=== DEBUG v79 ===\n\n";
    echo "FFmpeg: " . ($hasFfmpeg ? "OK" : "NO") . "\n";
    echo "PHP GD: " . ($hasGD ? "OK" : "NO") . "\n";
    echo "logo.png: " . ($hasLogo ? "OK" : "NO") . "\n";
    echo "font.ttf: " . ($hasFont ? "OK" : "NO") . "\n";
    echo "news.mp3: " . ($hasAudio ? "OK" : "NO") . "\n\n";
    
    echo "=== LOG ===\n";
    if (file_exists($logFile)) {
        echo implode("", array_slice(file($logFile), -50));
    }
    exit;
}

if ($action === 'clear') {
    @unlink($logFile);
    array_map('unlink', glob("$jobsDir/*") ?: []);
    array_map('unlink', glob("$uploadDir/*") ?: []);
    array_map('unlink', glob("$assetsDir/*") ?: []);
    header('Location: ?action=debug');
    exit;
}

if (ob_get_level()) ob_end_clean();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Viral Maker</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    background: linear-gradient(145deg, #000 0%, #111 100%);
    color: #fff;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.container {
    background: #0a0a0a;
    border: 1px solid #222;
    max-width: 500px;
    width: 100%;
    padding: 25px;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.8);
}
h1 {
    font-size: 1.8rem;
    letter-spacing: 3px;
    margin-bottom: 5px;
    text-align: center;
    color: #FFD700;
}
.subtitle {
    text-align: center;
    color: #666;
    font-size: 0.75rem;
    margin-bottom: 25px;
    letter-spacing: 2px;
}
.input-group {
    margin-bottom: 20px;
}
label {
    display: block;
    color: #999;
    font-size: 0.85rem;
    margin-bottom: 8px;
}
input[type="text"], input[type="file"] {
    width: 100%;
    padding: 14px;
    background: #000;
    color: #FFD700;
    border: 2px solid #1a1a1a;
    border-radius: 10px;
    font-size: 1rem;
    outline: none;
    transition: border 0.3s;
}
input[type="text"]:focus, input[type="file"]:focus {
    border-color: #FFD700;
}
input[type="text"]::placeholder {
    color: #444;
}
.char-count {
    text-align: right;
    font-size: 0.75rem;
    color: #555;
    margin-top: 5px;
}
.switch-container {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin: 20px 0;
}
.switch {
    position: relative;
    width: 50px;
    height: 26px;
}
.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: #333;
    border-radius: 26px;
    transition: 0.3s;
}
.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 4px;
    bottom: 4px;
    background: white;
    border-radius: 50%;
    transition: 0.3s;
}
input:checked + .slider {
    background: #FFD700;
}
input:checked + .slider:before {
    transform: translateX(24px);
}
.btn {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, #FFD700, #e6a800);
    color: #000;
    font-weight: 700;
    font-size: 1.1rem;
    letter-spacing: 2px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.3s;
}
.btn:disabled {
    background: #222;
    color: #555;
    cursor: not-allowed;
}
.btn:not(:disabled):active {
    transform: scale(0.98);
}
.hidden { display: none !important; }
.progress {
    height: 10px;
    background: #1a1a1a;
    border-radius: 5px;
    margin: 20px 0;
    overflow: hidden;
}
.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #FFD700, #ff8c00);
    transition: width 0.5s;
}
.status {
    text-align: center;
    margin: 20px 0;
}
.spinner {
    border: 4px solid #222;
    border-top: 4px solid #FFD700;
    border-radius: 50%;
    width: 50px;
    height: 50px;
    animation: spin 1s linear infinite;
    margin: 0 auto 20px;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
video {
    width: 100%;
    border-radius: 10px;
    margin: 15px 0;
}
.info {
    font-size: 0.8rem;
    color: #666;
    margin-top: 8px;
    padding: 8px;
    background: rgba(255,255,255,0.02);
    border-radius: 6px;
}
.info.ok { color: #4a4; }
.info.warn { color: #da8; }
.alert {
    background: rgba(170,50,50,0.15);
    border: 1px solid #844;
    color: #daa;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 15px;
}
.debug {
    position: fixed;
    bottom: 10px;
    right: 10px;
    background: rgba(0,0,0,0.7);
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 0.8rem;
    opacity: 0.5;
    text-decoration: none;
    color: #fff;
}
</style>
</head>
<body>

<div class="container">
    <h1>VIRAL MAKER</h1>
    <p class="subtitle">V79 - MOBILE OPTIMIZED</p>

    <div id="formSection">
        <?php if (!$hasFfmpeg || !$hasGD || !$hasLogo || !$hasFont || !$hasAudio): ?>
            <div class="alert">
                <?php if (!$hasFfmpeg): ?>❌ FFmpeg no instalado<br><?php endif; ?>
                <?php if (!$hasGD): ?>❌ PHP GD no instalado<br><?php endif; ?>
                <?php if (!$hasLogo): ?>❌ Falta logo.png<br><?php endif; ?>
                <?php if (!$hasFont): ?>❌ Falta font.ttf<br><?php endif; ?>
                <?php if (!$hasAudio): ?>❌ Falta news.mp3<?php endif; ?>
            </div>
        <?php else: ?>
            <div class="input-group">
                <label>Título</label>
                <input type="text" id="title" placeholder="TÍTULO DEL VIDEO" maxlength="36">
                <div class="char-count" id="charCount">0 / 36</div>
            </div>
            
            <div class="input-group">
                <label>Video</label>
                <input type="file" id="video" accept="video/*">
                <div class="info" id="videoInfo"></div>
            </div>
            
            <div class="switch-container">
                <span style="color:#999;font-size:0.9rem;">Modo Espejo</span>
                <label class="switch">
                    <input type="checkbox" id="mirror">
                    <span class="slider"></span>
                </label>
            </div>
            
            <button class="btn" id="submitBtn" disabled>CREAR VIDEO</button>
        <?php endif; ?>
    </div>

    <div id="processSection" class="hidden">
        <div class="spinner"></div>
        <div class="status">
            <h3 style="color:#FFD700;margin-bottom:10px;">Procesando...</h3>
            <div class="progress">
                <div class="progress-bar" id="progressBar" style="width:0%"></div>
            </div>
            <div id="progressText" style="color:#FFD700;font-size:1.3rem;font-weight:bold;">0%</div>
            <div id="timeInfo" style="color:#666;font-size:0.85rem;margin-top:10px;"></div>
        </div>
    </div>

    <div id="resultSection" class="hidden">
        <h3 style="color:#4a4;text-align:center;margin-bottom:15px;">✓ Video Listo</h3>
        <video id="resultVideo" controls autoplay loop playsinline></video>
        <a id="downloadBtn" class="btn" style="display:block;text-decoration:none;text-align:center;">DESCARGAR</a>
        <button class="btn" onclick="location.reload()" style="margin-top:10px;background:#333;">NUEVO VIDEO</button>
    </div>
</div>

<a href="?action=debug" class="debug" target="_blank">Debug</a>

<script>
const title = document.getElementById('title');
const video = document.getElementById('video');
const submitBtn = document.getElementById('submitBtn');
const charCount = document.getElementById('charCount');
const videoInfo = document.getElementById('videoInfo');
const formSection = document.getElementById('formSection');
const processSection = document.getElementById('processSection');
const resultSection = document.getElementById('resultSection');
const progressBar = document.getElementById('progressBar');
const progressText = document.getElementById('progressText');
const timeInfo = document.getElementById('timeInfo');

let videoDuration = 0;

function validate() {
    const ok = title.value.trim().length > 0 && video.files.length > 0 && (videoDuration <= 300 || videoDuration === 0);
    submitBtn.disabled = !ok;
}

title.addEventListener('input', function() {
    this.value = this.value.toUpperCase();
    charCount.textContent = this.value.length + ' / 36';
    validate();
});

video.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) {
        videoDuration = 0;
        videoInfo.textContent = '';
        validate();
        return;
    }
    
    const sizeMB = (file.size / 1048576).toFixed(1);
    videoInfo.textContent = '⏳ Analizando...';
    
    const v = document.createElement('video');
    v.preload = 'metadata';
    
    v.onloadedmetadata = function() {
        videoDuration = v.duration;
        const m = Math.floor(videoDuration / 60);
        const s = Math.floor(videoDuration % 60);
        const dur = m + ':' + String(s).padStart(2, '0');
        
        videoInfo.className = 'info';
        if (videoDuration > 300) {
            videoInfo.classList.add('warn');
            videoInfo.textContent = '⚠️ ' + dur + ' (máx 5:00) • ' + sizeMB + ' MB';
        } else {
            videoInfo.classList.add('ok');
            videoInfo.textContent = '✓ ' + dur + ' • ' + sizeMB + ' MB';
        }
        URL.revokeObjectURL(v.src);
        validate();
    };
    
    v.onerror = function() {
        videoInfo.className = 'info ok';
        videoInfo.textContent = '✓ ' + sizeMB + ' MB';
        videoDuration = 60;
        URL.revokeObjectURL(v.src);
        validate();
    };
    
    v.src = URL.createObjectURL(file);
});

submitBtn.addEventListener('click', async function() {
    if (!title.value.trim() || !video.files.length) return;
    
    formSection.classList.add('hidden');
    processSection.classList.remove('hidden');
    
    const fd = new FormData();
    fd.append('videoFile', video.files[0]);
    fd.append('videoTitle', title.value);
    fd.append('mirrorMode', document.getElementById('mirror').checked);
    
    try {
        const res = await fetch('?action=upload', { method: 'POST', body: fd });
        const data = await res.json();
        
        if (data.status === 'error') {
            alert('Error: ' + data.msg);
            location.reload();
            return;
        }
        
        track(data.jobId);
    } catch(e) {
        alert('Error: ' + e.message);
        location.reload();
    }
});

function track(jobId) {
    const start = Date.now();
    
    const iv = setInterval(async () => {
        try {
            const res = await fetch('?action=status&jobId=' + jobId);
            const data = await res.json();
            
            if (data.status === 'finished') {
                clearInterval(iv);
                processSection.classList.add('hidden');
                resultSection.classList.remove('hidden');
                document.getElementById('resultVideo').src = 'processed/' + data.file + '?t=' + Date.now();
                document.getElementById('downloadBtn').href = '?action=download&file=' + data.file;
            } else if (data.status === 'error') {
                clearInterval(iv);
                alert('Error: ' + data.msg);
                location.reload();
            } else {
                progressBar.style.width = data.progress + '%';
                progressText.textContent = data.progress + '%';
                const elapsed = Math.floor((Date.now() - start) / 1000);
                timeInfo.textContent = Math.floor(elapsed/60) + 'm ' + (elapsed%60) + 's';
            }
        } catch(e) {}
    }, 3000);
}
</script>

</body>
</html>
