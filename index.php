<?php
// ==========================================
// VIRAL REELS MAKER v76.0 - ANTI-FINGERPRINT + VIDEOS LARGOS
// Sin emojis (PHP GD no los soporta bien)
// ==========================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('memory_limit', '1024M');
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

// Crear directorios
foreach ([$uploadDir, $processedDir, $jobsDir, $assetsDir] as $dir) {
    if (!file_exists($dir)) @mkdir($dir, 0777, true);
}

// Limpieza (archivos > 4 horas)
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
    file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND);
}

// Limpiar texto: quitar emojis y caracteres especiales
function cleanTitle($text) {
    // Convertir a mayúsculas
    $text = mb_strtoupper($text, 'UTF-8');
    
    // Quitar emojis y caracteres especiales unicode
    $text = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $text); // Emoticons
    $text = preg_replace('/[\x{1F300}-\x{1F5FF}]/u', '', $text); // Misc Symbols
    $text = preg_replace('/[\x{1F680}-\x{1F6FF}]/u', '', $text); // Transport
    $text = preg_replace('/[\x{1F1E0}-\x{1F1FF}]/u', '', $text); // Flags
    $text = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $text);   // Misc symbols
    $text = preg_replace('/[\x{2700}-\x{27BF}]/u', '', $text);   // Dingbats
    $text = preg_replace('/[\x{FE00}-\x{FE0F}]/u', '', $text);   // Variation
    $text = preg_replace('/[\x{1F900}-\x{1F9FF}]/u', '', $text); // Supplemental
    $text = preg_replace('/[\x{1FA00}-\x{1FA6F}]/u', '', $text); // Chess
    $text = preg_replace('/[\x{1FA70}-\x{1FAFF}]/u', '', $text); // Symbols
    $text = preg_replace('/[\x{200D}]/u', '', $text);            // Zero width joiner
    
    // Solo permitir letras, números, espacios y puntuación básica
    $text = preg_replace('/[^\p{L}\p{N}\s\-\.\!\?\,]/u', '', $text);
    
    // Limpiar espacios múltiples
    $text = preg_replace('/\s+/', ' ', trim($text));
    
    return $text;
}

// Generar imagen PNG con título usando GD
function createTitleImage($title, $fontPath, $outputPath, $width = 720) {
    $fontSize = 54;
    $maxWidth = $width - 80;
    $lineHeight = 72;
    $paddingTop = 35;
    $paddingBottom = 25;
    
    // Limpiar título
    $title = cleanTitle($title);
    
    if (empty($title)) {
        $title = "VIDEO VIRAL";
    }
    
    // Dividir en líneas
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
    
    // Máximo 2 líneas
    if (count($lines) > 2) {
        $lines = array_slice($lines, 0, 2);
        if (mb_strlen($lines[1], 'UTF-8') > 16) {
            $lines[1] = mb_substr($lines[1], 0, 14, 'UTF-8') . '..';
        }
    }
    
    if (empty($lines)) {
        $lines = ["VIDEO VIRAL"];
    }
    
    // Calcular altura
    $totalHeight = $paddingTop + (count($lines) * $lineHeight) + $paddingBottom;
    
    // Crear imagen con fondo transparente
    $img = imagecreatetruecolor($width, $totalHeight);
    imagesavealpha($img, true);
    imagealphablending($img, false);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    imagealphablending($img, true);
    
    // Colores
    $gold = imagecolorallocate($img, 255, 215, 0);
    $black = imagecolorallocate($img, 0, 0, 0);
    $darkGold = imagecolorallocate($img, 180, 150, 0);
    
    // Dibujar cada línea
    $y = $paddingTop + $fontSize;
    foreach ($lines as $line) {
        $bbox = @imagettfbbox($fontSize, 0, $fontPath, $line);
        if ($bbox === false) continue;
        
        $textWidth = abs($bbox[2] - $bbox[0]);
        $x = ($width - $textWidth) / 2;
        
        // Sombra
        for ($sx = 3; $sx <= 5; $sx++) {
            for ($sy = 3; $sy <= 5; $sy++) {
                @imagettftext($img, $fontSize, 0, $x + $sx, $y + $sy, $black, $fontPath, $line);
            }
        }
        
        // Borde negro grueso
        for ($bx = -3; $bx <= 3; $bx++) {
            for ($by = -3; $by <= 3; $by++) {
                if ($bx != 0 || $by != 0) {
                    @imagettftext($img, $fontSize, 0, $x + $bx, $y + $by, $black, $fontPath, $line);
                }
            }
        }
        
        // Texto dorado
        @imagettftext($img, $fontSize, 0, $x, $y, $gold, $fontPath, $line);
        
        $y += $lineHeight;
    }
    
    // Guardar PNG
    imagepng($img, $outputPath, 9);
    imagedestroy($img);
    
    return $totalHeight;
}

// Anti-fingerprint
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
$ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null'));
$ffprobePath = trim(shell_exec('which ffprobe 2>/dev/null'));
$hasFfmpeg = !empty($ffmpegPath);
$hasGD = extension_loaded('gd');

$hasLogo = file_exists($logoPath);
$hasFont = file_exists($fontPath);
$hasAudio = file_exists($audioPath);
$missingFiles = [];
if (!$hasLogo) $missingFiles[] = 'logo.png';
if (!$hasFont) $missingFiles[] = 'font.ttf';
if (!$hasAudio) $missingFiles[] = 'news.mp3';

// ==========================================
// API: UPLOAD
// ==========================================
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (empty($_FILES['videoFile']['tmp_name'])) {
        if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
            sendJson(['status' => 'error', 'msg' => 'Archivo muy grande.']);
        }
        sendJson(['status' => 'error', 'msg' => 'No se recibió archivo.']);
    }
    
    if (!$hasFfmpeg) sendJson(['status' => 'error', 'msg' => 'FFmpeg no instalado.']);
    if (!$hasGD) sendJson(['status' => 'error', 'msg' => 'PHP GD no instalado.']);
    if (!$hasLogo) sendJson(['status' => 'error', 'msg' => 'Falta logo.png']);
    if (!$hasFont) sendJson(['status' => 'error', 'msg' => 'Falta font.ttf']);
    if (!$hasAudio) sendJson(['status' => 'error', 'msg' => 'Falta news.mp3']);
    
    $title = trim($_POST['videoTitle'] ?? '');
    if (empty($title)) {
        sendJson(['status' => 'error', 'msg' => 'El título es obligatorio.']);
    }
    
    // Limpiar y limitar título
    $title = cleanTitle($title);
    $title = mb_substr($title, 0, 36, 'UTF-8');
    
    if (empty($title)) {
        $title = "VIDEO VIRAL";
    }

    $jobId = 'v76_' . time() . '_' . mt_rand(1000, 9999);
    
    $ext = strtolower(pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', '3gp'])) {
        $ext = 'mp4';
    }
    
    $inputFile = "$uploadDir/{$jobId}_input.$ext";
    $titleImgFile = "$assetsDir/{$jobId}_title.png";
    $outputFileName = "{$jobId}_viral.mp4";
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/{$jobId}.json";
    $scriptFile = "$jobsDir/{$jobId}.sh";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        sendJson(['status' => 'error', 'msg' => 'Error al guardar archivo.']);
    }
    chmod($inputFile, 0666);

    // Obtener info del video
    $seconds = 60;
    $videoWidth = 720;
    $videoHeight = 1280;
    $hasVideoAudio = false;
    
    if (!empty($ffprobePath)) {
        $cmd = "$ffprobePath -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile) . " 2>/dev/null";
        $duration = trim(shell_exec($cmd));
        if (is_numeric($duration) && $duration > 0) {
            $seconds = floatval($duration);
        }
        
        $cmd = "$ffprobePath -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 " . escapeshellarg($inputFile) . " 2>/dev/null";
        $dims = trim(shell_exec($cmd));
        if (preg_match('/(\d+)x(\d+)/', $dims, $m)) {
            $videoWidth = intval($m[1]);
            $videoHeight = intval($m[2]);
        }
        
        $cmd = "$ffprobePath -v error -select_streams a -show_entries stream=codec_type -of csv=p=0 " . escapeshellarg($inputFile) . " 2>/dev/null";
        $audioCheck = trim(shell_exec($cmd));
        $hasVideoAudio = !empty($audioCheck);
    }
    
    if ($seconds < 1) $seconds = 60;
    
    if ($seconds > 300) {
        @unlink($inputFile);
        sendJson(['status' => 'error', 'msg' => 'Video excede 5 minutos.']);
    }

    $mirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    // Crear imagen del título
    $titleHeight = createTitleImage($title, $fontPath, $titleImgFile, 720);
    
    if (!file_exists($titleImgFile)) {
        @unlink($inputFile);
        sendJson(['status' => 'error', 'msg' => 'Error creando título.']);
    }

    // Anti-fingerprint
    $af = getAntiFingerprint();
    
    $vidY = $titleHeight + 15;
    $cw = 720;
    $ch = 1280;
    
    $atempo = round(1 / $af['speed'], 4);
    
    logMsg("=== JOB: $jobId ===");
    logMsg("Video: {$seconds}s, {$videoWidth}x{$videoHeight}, audio: " . ($hasVideoAudio ? 'SI' : 'NO'));
    logMsg("Titulo: $title");
    logMsg("AF: spd={$af['speed']} sat={$af['saturation']} con={$af['contrast']} hue={$af['hue']}");

    // Filtros
    $hflip = $mirror ? ",hflip" : "";
    $preset = ($seconds > 120) ? 'faster' : 'fast';
    $threads = ($seconds > 120) ? 1 : 2;
    
    // Filtro de video
    $vf = "";
    $vf .= "color=c=#0a0a0a:s={$cw}x{$ch}:d=" . ceil($seconds + 2) . "[bg];";
    $vf .= "[0:v]scale={$cw}:-2:flags=bilinear,setsar=1{$hflip},";
    $vf .= "setpts=" . round(1/$af['speed'], 4) . "*PTS,";
    $vf .= "eq=saturation={$af['saturation']}:contrast={$af['contrast']}:brightness={$af['brightness']}:gamma={$af['gamma']},";
    $vf .= "hue=h={$af['hue']},";
    $vf .= "noise=alls={$af['noise']}:allf=t";
    $vf .= "[vid];";
    $vf .= "[bg][vid]overlay=0:{$vidY}:shortest=1[v1];";
    $vf .= "[1:v]scale={$cw}:-1[title];";
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
    $cmd .= "-filter_complex \"" . $fullFilter . "\" ";
    $cmd .= "-map \"[vout]\" -map \"[aout]\" ";
    $cmd .= "-c:v libx264 -preset {$preset} -crf 23 -threads {$threads} ";
    $cmd .= "-c:a aac -b:a 192k -ar 44100 ";
    $cmd .= "-movflags +faststart ";
    $cmd .= "-t " . ceil($seconds / $af['speed']) . " ";
    $cmd .= "-metadata title=\"VID" . date('ymdHis') . mt_rand(100,999) . "\" ";
    $cmd .= escapeshellarg($outputFile);

    // Guardar estado
    file_put_contents($jobFile, json_encode([
        'status' => 'processing',
        'file' => $outputFileName,
        'start' => time(),
        'duration' => $seconds,
        'title' => $title,
        'af' => $af
    ]));

    // Script bash
    $script = "#!/bin/bash\n";
    $script .= "cd " . escapeshellarg($baseDir) . "\n";
    $script .= "echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] Iniciando: $jobId\" >> " . escapeshellarg($logFile) . "\n";
    $script .= $cmd . " >> " . escapeshellarg($logFile) . " 2>&1\n";
    $script .= "RESULT=\$?\n";
    $script .= "if [ \$RESULT -eq 0 ] && [ -f " . escapeshellarg($outputFile) . " ]; then\n";
    $script .= "  SIZE=\$(du -h " . escapeshellarg($outputFile) . " 2>/dev/null | cut -f1)\n";
    $script .= "  echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] COMPLETADO: \$SIZE\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "else\n";
    $script .= "  echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] ERROR: codigo \$RESULT\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "fi\n";
    $script .= "rm -f " . escapeshellarg($inputFile) . " 2>/dev/null\n";
    $script .= "rm -f " . escapeshellarg($titleImgFile) . " 2>/dev/null\n";
    
    file_put_contents($scriptFile, $script);
    chmod($scriptFile, 0755);
    
    exec("nohup nice -n 19 bash " . escapeshellarg($scriptFile) . " > /dev/null 2>&1 &");

    sendJson(['status' => 'success', 'jobId' => $jobId]);
}

// ==========================================
// API: STATUS
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
        
        if ($size > 50000 && (time() - $mtime) > 3) {
            sendJson(['status' => 'finished', 'file' => $data['file']]);
        }
    }
    
    $timeout = ($data['duration'] * 5) + 600;
    $elapsed = time() - $data['start'];
    
    if ($elapsed > $timeout) {
        sendJson(['status' => 'error', 'msg' => 'Timeout. Intenta con video más corto.']);
    }
    
    $progress = min(95, round(($elapsed / ($data['duration'] * 2.5)) * 100));
    sendJson(['status' => 'processing', 'progress' => $progress]);
}

// ==========================================
// API: DOWNLOAD
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
// API: DEBUG
// ==========================================
if ($action === 'debug') {
    header('Content-Type: text/plain; charset=utf-8');
    
    echo "=== VIRAL MAKER v76 ===\n\n";
    echo "FFmpeg: " . ($hasFfmpeg ? "OK" : "NO") . "\n";
    echo "FFprobe: " . (!empty($ffprobePath) ? "OK" : "NO") . "\n";
    echo "PHP GD: " . ($hasGD ? "OK" : "NO") . "\n\n";
    
    echo "logo.png: " . ($hasLogo ? "OK" : "FALTA") . "\n";
    echo "font.ttf: " . ($hasFont ? "OK" : "FALTA") . "\n";
    echo "news.mp3: " . ($hasAudio ? "OK" : "FALTA") . "\n\n";
    
    echo "=== ANTI-FINGERPRINT ===\n";
    echo "Velocidad: 0.98x - 1.02x\n";
    echo "Saturacion: 1.05 - 1.17\n";
    echo "Contraste: 1.02 - 1.08\n";
    echo "Brillo: -0.015 a +0.015\n";
    echo "Hue: -4 a +4\n";
    echo "Gamma: 0.97 - 1.03\n";
    echo "Noise: 1-2\n";
    echo "Audio musica: 0.3\n\n";
    
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral Maker v76</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root { --gold: #FFD700; }
        * { box-sizing: border-box; }
        body {
            background: linear-gradient(145deg, #050505 0%, #0f0f1a 100%);
            color: #fff;
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            margin: 0;
        }
        .card {
            background: #111;
            border: 1px solid #222;
            max-width: 450px;
            width: 100%;
            padding: 28px;
            border-radius: 20px;
            box-shadow: 0 25px 80px rgba(0,0,0,0.7);
        }
        h2 { font-family: 'Anton', sans-serif; font-size: 2rem; letter-spacing: 3px; margin: 0; }
        .subtitle { color: #555; font-size: 0.7rem; letter-spacing: 1px; }
        .form-control {
            background: #080808 !important;
            color: var(--gold) !important;
            border: 2px solid #1a1a1a;
            border-radius: 12px;
            padding: 14px;
            font-size: 1rem;
        }
        .form-control:focus { border-color: var(--gold); box-shadow: 0 0 15px rgba(255,215,0,0.1); outline: none; }
        .form-control::placeholder { color: #444; }
        .btn-go {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #FFD700, #e6a800);
            color: #000;
            font-family: 'Anton', sans-serif;
            font-size: 1.2rem;
            letter-spacing: 2px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            margin-top: 15px;
        }
        .btn-go:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(255,215,0,0.25); }
        .btn-go:disabled { background: #222; color: #444; cursor: not-allowed; }
        .hidden { display: none !important; }
        .progress { height: 10px; background: #1a1a1a; border-radius: 5px; margin: 20px 0; }
        .progress-bar { background: linear-gradient(90deg, var(--gold), #ff8c00); }
        video { width: 100%; border-radius: 12px; margin: 15px 0; border: 2px solid #222; }
        .info { font-size: 0.8rem; color: #555; margin-top: 6px; }
        .info.ok { color: #4a4; }
        .info.warn { color: #a84; }
        .char-counter { font-size: 0.7rem; color: #444; text-align: right; margin-top: 3px; }
        .char-counter.warn { color: #a84; }
        .char-counter.max { color: #a44; }
        .tag { display: inline-block; background: rgba(255,215,0,0.08); color: var(--gold); font-size: 0.65rem; padding: 3px 10px; border-radius: 20px; margin: 3px; }
        .alert-box { background: rgba(170,50,50,0.1); border: 1px solid #633; color: #c88; border-radius: 10px; padding: 15px; font-size: 0.85rem; }
        .spinner-border { width: 3rem; height: 3rem; }
    </style>
</head>
<body>

<div class="card">
    <div class="text-center mb-4">
        <h2>VIRAL MAKER <span style="color:var(--gold)">v76</span></h2>
        <p class="subtitle">ANTI-FINGERPRINT COMPLETO</p>
        <div class="mt-2">
            <span class="tag">Hash Único</span>
            <span class="tag">5 Min Max</span>
            <span class="tag">Audio 0.3</span>
        </div>
    </div>

    <div id="formSection">
        <?php if (!$hasFfmpeg): ?>
            <div class="alert-box">FFmpeg no instalado.</div>
        <?php elseif (!$hasGD): ?>
            <div class="alert-box">PHP GD no instalado.</div>
        <?php elseif (!empty($missingFiles)): ?>
            <div class="alert-box">
                Archivos faltantes:<br>
                <?php foreach($missingFiles as $f) echo "• $f<br>"; ?>
            </div>
        <?php else: ?>
            <div class="mb-3">
                <input type="text" id="titleInput" class="form-control" placeholder="TÍTULO DEL VIDEO" maxlength="36">
                <div class="char-counter" id="charCounter">0 / 36</div>
            </div>
            
            <div class="mb-3">
                <input type="file" id="videoInput" class="form-control" accept="video/*">
                <div class="info" id="videoInfo"></div>
            </div>
            
            <div class="form-check form-switch d-flex justify-content-center gap-2">
                <input class="form-check-input" type="checkbox" id="mirrorCheck" style="cursor:pointer">
                <label class="form-check-label text-secondary">Modo Espejo</label>
            </div>
            
            <button id="submitBtn" class="btn-go" disabled>CREAR VIDEO VIRAL</button>
            
            <p class="text-center text-secondary small mt-3 mb-0">
                Máximo 5 minutos • Cada video es único
            </p>
        <?php endif; ?>
    </div>

    <div id="processSection" class="hidden text-center">
        <div class="spinner-border text-warning mb-3"></div>
        <h5>PROCESANDO</h5>
        <p class="text-secondary small">Aplicando anti-fingerprint...</p>
        <div class="progress">
            <div id="progressBar" class="progress-bar" style="width:0%"></div>
        </div>
        <div id="progressText" class="text-warning fw-bold" style="font-size:1.2rem">0%</div>
        <div id="timeInfo" class="text-secondary small mt-1"></div>
    </div>

    <div id="resultSection" class="hidden text-center">
        <video id="resultVideo" controls autoplay loop playsinline></video>
        <a id="downloadBtn" class="btn btn-success w-100 py-3 fw-bold mb-2">DESCARGAR VIDEO</a>
        <button onclick="location.reload()" class="btn btn-outline-warning w-100">NUEVO VIDEO</button>
        <p class="text-secondary small mt-3 mb-0">Hash único • Listo para redes sociales</p>
    </div>
</div>

<script>
const titleInput = document.getElementById('titleInput');
const videoInput = document.getElementById('videoInput');
const submitBtn = document.getElementById('submitBtn');
const charCounter = document.getElementById('charCounter');
const videoInfo = document.getElementById('videoInfo');
const formSection = document.getElementById('formSection');
const processSection = document.getElementById('processSection');
const resultSection = document.getElementById('resultSection');
const progressBar = document.getElementById('progressBar');
const progressText = document.getElementById('progressText');
const timeInfo = document.getElementById('timeInfo');

let videoDuration = 0;

function validate() {
    const hasTitle = titleInput && titleInput.value.trim().length > 0;
    const hasVideo = videoInput && videoInput.files.length > 0;
    const validDur = videoDuration <= 300 || videoDuration === 0;
    if (submitBtn) submitBtn.disabled = !(hasTitle && hasVideo && validDur);
}

if (titleInput) {
    titleInput.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
        const len = this.value.length;
        charCounter.textContent = len + ' / 36';
        charCounter.className = 'char-counter';
        if (len > 28) charCounter.classList.add('warn');
        if (len >= 36) charCounter.classList.add('max');
        validate();
    });
}

if (videoInput) {
    videoInput.addEventListener('change', function() {
        const file = this.files[0];
        if (!file) { videoDuration = 0; validate(); return; }
        
        const sizeMB = (file.size / 1048576).toFixed(1);
        const video = document.createElement('video');
        video.preload = 'metadata';
        
        video.onloadedmetadata = function() {
            videoDuration = video.duration;
            const m = Math.floor(videoDuration / 60);
            const s = Math.floor(videoDuration % 60);
            const dur = m + ':' + String(s).padStart(2, '0');
            
            videoInfo.className = 'info';
            if (videoDuration > 300) {
                videoInfo.classList.add('warn');
                videoInfo.innerHTML = '⚠️ ' + dur + ' (máx 5:00) | ' + sizeMB + ' MB';
            } else {
                videoInfo.classList.add('ok');
                videoInfo.innerHTML = '✓ ' + dur + ' | ' + sizeMB + ' MB';
            }
            URL.revokeObjectURL(video.src);
            validate();
        };
        video.src = URL.createObjectURL(file);
    });
}

if (submitBtn) {
    submitBtn.addEventListener('click', async function() {
        const title = titleInput.value.trim();
        const file = videoInput.files[0];
        
        if (!title || !file || videoDuration > 300) return;
        
        formSection.classList.add('hidden');
        processSection.classList.remove('hidden');
        
        const fd = new FormData();
        fd.append('videoFile', file);
        fd.append('videoTitle', title);
        fd.append('mirrorMode', document.getElementById('mirrorCheck').checked);
        
        try {
            const res = await fetch('?action=upload', { method: 'POST', body: fd });
            const txt = await res.text();
            let data;
            try { data = JSON.parse(txt); } catch(e) { throw new Error('Error servidor'); }
            
            if (data.status === 'error') { alert(data.msg); location.reload(); return; }
            
            track(data.jobId);
        } catch(e) {
            alert(e.message);
            location.reload();
        }
    });
}

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
                alert(data.msg);
                location.reload();
            } else {
                progressBar.style.width = data.progress + '%';
                progressText.textContent = data.progress + '%';
                const elapsed = Math.floor((Date.now() - start) / 1000);
                timeInfo.textContent = Math.floor(elapsed/60) + 'm ' + (elapsed%60) + 's';
            }
        } catch(e) {}
    }, 2500);
}
</script>

</body>
</html>
