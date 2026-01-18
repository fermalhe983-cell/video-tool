<?php
// ==========================================
// VIRAL REELS MAKER v74.0 - ANTI-FINGERPRINT COMPLETO
// ==========================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('memory_limit', '512M');
ini_set('upload_max_filesize', '2048M');
ini_set('post_max_size', '2048M');
set_time_limit(0);
ignore_user_abort(true);

ob_start();

$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

if (!file_exists($uploadDir)) @mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) @mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) @mkdir($jobsDir, 0777, true);

foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    if(is_dir($dir)){
        foreach (glob("$dir/*") as $file) {
            if (is_file($file) && (time() - filemtime($file) > 10800)) @unlink($file);
        }
    }
}

function sendJson($data) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function escapeFFmpegText($text) {
    $text = str_replace("\\", "\\\\", $text);
    $text = str_replace("'", "'\\''", $text);
    $text = str_replace(":", "\\:", $text);
    $text = str_replace("[", "\\[", $text);
    $text = str_replace("]", "\\]", $text);
    $text = str_replace("%", "\\%", $text);
    return $text;
}

// Anti-fingerprint: valores aleatorios sutiles
function getAntiFingerprint() {
    return [
        'speed' => round(0.97 + (mt_rand(0, 60) / 1000), 3),          // 0.970 - 1.030
        'saturation' => round(1.05 + (mt_rand(0, 150) / 1000), 3),    // 1.05 - 1.20
        'contrast' => round(1.02 + (mt_rand(0, 80) / 1000), 3),       // 1.02 - 1.10
        'brightness' => round((mt_rand(-20, 20) / 1000), 3),          // -0.02 - 0.02
        'hue' => mt_rand(-5, 5),                                       // -5 - 5 grados
        'gamma' => round(0.95 + (mt_rand(0, 100) / 1000), 3),         // 0.95 - 1.05
        'noise' => mt_rand(1, 3),                                      // 1 - 3
        'zoom' => round(1.005 + (mt_rand(0, 20) / 1000), 3),          // 1.005 - 1.025
    ];
}

$action = $_GET['action'] ?? '';
$ffmpegPath = trim(shell_exec('which ffmpeg'));
$hasFfmpeg = !empty($ffmpegPath);

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
    
    if (empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        sendJson(['status'=>'error', 'msg'=>'Archivo excede límite PHP.']);
    }

    if (!$hasFfmpeg) sendJson(['status'=>'error', 'msg'=>'FFmpeg no instalado.']);
    if (!$hasLogo) sendJson(['status'=>'error', 'msg'=>'Falta logo.png']);
    if (!$hasFont) sendJson(['status'=>'error', 'msg'=>'Falta font.ttf']);
    if (!$hasAudio) sendJson(['status'=>'error', 'msg'=>'Falta news.mp3']);
    
    $title = trim($_POST['videoTitle'] ?? '');
    if (empty($title)) {
        sendJson(['status'=>'error', 'msg'=>'El título es obligatorio.']);
    }

    $jobId = uniqid('v74_');
    $ext = strtolower(pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION));
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $tempVideo = "$uploadDir/{$jobId}_temp.mp4";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";
    $scriptFile = "$jobsDir/{$jobId}.sh";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        sendJson(['status'=>'error', 'msg'=>'Error guardando archivo.']);
    }
    chmod($inputFile, 0777);

    // Info del video
    $ffprobePath = trim(shell_exec('which ffprobe'));
    $seconds = 60;
    $videoWidth = 720;
    $videoHeight = 1280;
    
    if (!empty($ffprobePath)) {
        $durCmd = "$ffprobePath -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile);
        $seconds = floatval(trim(shell_exec($durCmd)));
        
        $dimCmd = "$ffprobePath -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 " . escapeshellarg($inputFile);
        $dimensions = trim(shell_exec($dimCmd));
        if (preg_match('/(\d+)x(\d+)/', $dimensions, $dm)) {
            $videoWidth = intval($dm[1]);
            $videoHeight = intval($dm[2]);
        }
    }
    
    if ($seconds < 1) $seconds = 60;
    if ($seconds > 300) {
        @unlink($inputFile);
        sendJson(['status'=>'error', 'msg'=>'Video excede 5 minutos']);
    }

    $mirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    // Título: mayúsculas, sin emojis (solo texto)
    $title = mb_strtoupper($title, 'UTF-8');
    // Remover emojis y caracteres especiales problemáticos
    $title = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $title);
    $title = preg_replace('/[\x{1F300}-\x{1F5FF}]/u', '', $title);
    $title = preg_replace('/[\x{1F680}-\x{1F6FF}]/u', '', $title);
    $title = preg_replace('/[\x{1F1E0}-\x{1F1FF}]/u', '', $title);
    $title = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $title);
    $title = preg_replace('/[\x{2700}-\x{27BF}]/u', '', $title);
    $title = preg_replace('/[\x{1F900}-\x{1F9FF}]/u', '', $title);
    $title = preg_replace('/[\x{1FA00}-\x{1FAFF}]/u', '', $title);
    $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title);
    $title = preg_replace('/\s+/', ' ', trim($title));
    
    // Limitar a 36 caracteres
    if (mb_strlen($title, 'UTF-8') > 36) {
        $title = mb_substr($title, 0, 36, 'UTF-8');
    }

    // Anti-fingerprint
    $af = getAntiFingerprint();

    // Layout
    $cw = 720; 
    $ch = 1280;
    
    // Wordwrap
    $maxChars = 18;
    $lines = [];
    $titleLen = mb_strlen($title, 'UTF-8');
    
    if ($titleLen <= $maxChars) {
        $lines[] = $title;
    } else {
        $words = preg_split('/\s+/u', $title);
        $currentLine = '';
        foreach ($words as $word) {
            $testLine = $currentLine ? "$currentLine $word" : $word;
            if (mb_strlen($testLine, 'UTF-8') <= $maxChars) {
                $currentLine = $testLine;
            } else {
                if ($currentLine) $lines[] = $currentLine;
                $currentLine = $word;
            }
        }
        if ($currentLine) $lines[] = $currentLine;
    }
    
    if (count($lines) > 2) {
        $lines = array_slice($lines, 0, 2);
        $lines[1] = mb_substr($lines[1], 0, 15, 'UTF-8') . '..';
    }
    
    $numLines = count($lines);
    $txtSize = ($numLines == 1) ? 72 : 64;
    $txtY = ($numLines == 1) ? [50] : [40, 115];
    $vidY = ($numLines == 1) ? 150 : 200;

    // ---------------------------------------------------------
    // FILTROS DE VIDEO CON ANTI-FINGERPRINT COMPLETO
    // ---------------------------------------------------------
    $fFile = str_replace('\\', '/', realpath($fontPath));
    $hflip = $mirror ? ",hflip" : "";
    
    $fc = "";
    
    // Fondo
    $fc .= "color=c=#080808:s={$cw}x{$ch}[bg];";
    
    // Video con TODOS los efectos anti-fingerprint
    $fc .= "[0:v]scale={$cw}:-1:flags=lanczos,setsar=1{$hflip},";
    $fc .= "setpts=" . round(1/$af['speed'], 4) . "*PTS,";                    // Velocidad
    $fc .= "eq=saturation={$af['saturation']}:contrast={$af['contrast']}:brightness={$af['brightness']}:gamma={$af['gamma']},"; // Color
    $fc .= "hue=h={$af['hue']},";                                              // Tono
    $fc .= "noise=alls={$af['noise']}:allf=t,";                               // Ruido
    $fc .= "scale=iw*{$af['zoom']}:ih*{$af['zoom']},";                        // Zoom
    $fc .= "crop={$cw}:ih:(iw-{$cw})/2:0";                                    // Crop centrado
    $fc .= "[vid];";
    
    // Overlay video
    $fc .= "[bg][vid]overlay=0:{$vidY}:shortest=1[base];";
    
    // Texto
    $last = "[base]";
    foreach ($lines as $k => $l) {
        $escapedLine = escapeFFmpegText($l);
        $y = $txtY[$k];
        $fc .= "{$last}drawtext=fontfile='{$fFile}':text='{$escapedLine}':fontcolor=#FFD700:fontsize={$txtSize}:";
        $fc .= "borderw=4:bordercolor=black:shadowcolor=black@0.5:shadowx=2:shadowy=2:";
        $fc .= "x=(w-text_w)/2:y={$y}[t{$k}];";
        $last = "[t{$k}]";
    }
    
    // Logo
    $fc .= "[1:v]scale=-1:80[lg];{$last}[lg]overlay=30:H-120[vfin]";

    $inputs = "-i " . escapeshellarg($inputFile) . " -i " . escapeshellarg($logoPath);

    // Comando 1: Video
    $cmdStep1 = "$ffmpegPath -y $inputs -filter_complex \"{$fc}\" -map \"[vfin]\" -an ";
    $cmdStep1 .= "-c:v libx264 -preset medium -threads 2 -crf 23 -movflags +faststart ";
    $cmdStep1 .= "-metadata title=\"VID-" . date('YmdHis') . "-" . mt_rand(1000,9999) . "\" ";
    $cmdStep1 .= "-metadata comment=\"ID:$jobId\" ";
    $cmdStep1 .= escapeshellarg($tempVideo);

    // ---------------------------------------------------------
    // AUDIO: 0.3 de volumen para música de fondo
    // ---------------------------------------------------------
    $atempo = round(1 / $af['speed'], 4);
    
    $cmdStep2 = "$ffmpegPath -y -i " . escapeshellarg($tempVideo) . " -i " . escapeshellarg($inputFile) . " -stream_loop -1 -i " . escapeshellarg($audioPath);
    
    $filterAudio = "[1:a]aresample=async=1,atempo={$atempo},volume=0.8[vorig];";
    $filterAudio .= "[2:a]aresample=async=1,volume=0.3[vmusic];";  // VOLUMEN 0.3
    $filterAudio .= "[vorig][vmusic]amix=inputs=2:duration=first:dropout_transition=2:normalize=0[afin]";
    
    $cmdStep2 .= " -filter_complex \"$filterAudio\" -map 0:v -map \"[afin]\" -c:v copy -c:a aac -b:a 192k ";
    $cmdStep2 .= escapeshellarg($outputFile);

    // Guardar estado
    file_put_contents($jobFile, json_encode([
        'status' => 'processing',
        'file' => $outputFileName,
        'start' => time(),
        'duration' => $seconds,
        'af' => $af
    ]));

    // Script
    $scriptContent = "#!/bin/bash\n";
    $scriptContent .= "export LANG=en_US.UTF-8\n";
    $scriptContent .= "cd " . escapeshellarg($baseDir) . "\n";
    $scriptContent .= "echo '=== JOB: $jobId ===' >> " . escapeshellarg($logFile) . "\n";
    $scriptContent .= "echo 'Inicio: '\$(date) >> " . escapeshellarg($logFile) . "\n";
    $scriptContent .= "echo 'Video: {$seconds}s | {$videoWidth}x{$videoHeight}' >> " . escapeshellarg($logFile) . "\n";
    $scriptContent .= "echo 'AF: spd={$af['speed']} sat={$af['saturation']} con={$af['contrast']} hue={$af['hue']} gam={$af['gamma']}' >> " . escapeshellarg($logFile) . "\n";
    $scriptContent .= $cmdStep1 . " >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "if [ \$? -eq 0 ]; then\n";
    $scriptContent .= "  echo 'Paso 1 OK, procesando audio...' >> " . escapeshellarg($logFile) . "\n";
    $scriptContent .= "  " . $cmdStep2 . " >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "  if [ \$? -eq 0 ]; then\n";
    $scriptContent .= "    echo 'COMPLETADO: '\$(du -h " . escapeshellarg($outputFile) . " | cut -f1) >> " . escapeshellarg($logFile) . "\n";
    $scriptContent .= "  else\n";
    $scriptContent .= "    echo 'ERROR en audio' >> " . escapeshellarg($logFile) . "\n";
    $scriptContent .= "  fi\n";
    $scriptContent .= "else\n";
    $scriptContent .= "  echo 'ERROR en video' >> " . escapeshellarg($logFile) . "\n";
    $scriptContent .= "fi\n";
    $scriptContent .= "rm -f " . escapeshellarg($tempVideo) . "\n";
    $scriptContent .= "echo 'Fin: '\$(date) >> " . escapeshellarg($logFile) . "\n";
    $scriptContent .= "echo '===================' >> " . escapeshellarg($logFile) . "\n";

    file_put_contents($scriptFile, $scriptContent);
    chmod($scriptFile, 0755);

    exec("nohup nice -n 19 bash " . escapeshellarg($scriptFile) . " > /dev/null 2>&1 &");

    sendJson(['status' => 'success', 'jobId' => $jobId]);
}

// ==========================================
// API: STATUS
// ==========================================
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId'] ?? '');
    $jFile = "$jobsDir/$id.json";

    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . ($data['file'] ?? '');

        if (!empty($data['file']) && file_exists($fullPath) && filesize($fullPath) > 10000) {
            clearstatcache(true, $fullPath);
            if ((time() - filemtime($fullPath)) > 5) {
                sendJson(['status' => 'finished', 'file' => $data['file']]);
            }
        }
        
        $timeout = max(600, ($data['duration'] * 4) + 300);
        if ((time() - $data['start']) > $timeout) { 
            sendJson(['status' => 'error', 'msg' => 'Timeout']);
        }

        $elapsed = time() - $data['start'];
        $progress = min(99, ($elapsed / max(1, $data['duration'] * 2.5)) * 100); 
        sendJson(['status' => 'processing', 'progress' => round($progress)]);
    }
    sendJson(['status' => 'error', 'msg' => 'Iniciando...']);
}

// ==========================================
// UTILS
// ==========================================
if ($action === 'download' && isset($_GET['file'])) {
    $f = basename($_GET['file']);
    $p = "$processedDir/$f";
    if (file_exists($p)) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="VIRAL_'.date('md_Hi').'_'.mt_rand(100,999).'.mp4"');
        header('Content-Length: '.filesize($p));
        readfile($p);
        exit;
    }
}

if ($action === 'debug') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== VIRAL MAKER v74 ===\n\n";
    echo "FFmpeg: " . ($hasFfmpeg ? "OK" : "NO") . "\n";
    echo "Logo: " . ($hasLogo ? "OK" : "FALTA") . "\n";
    echo "Font: " . ($hasFont ? "OK" : "FALTA") . "\n";
    echo "Audio: " . ($hasAudio ? "OK" : "FALTA") . "\n\n";
    
    echo "=== ANTI-FINGERPRINT ===\n";
    echo "✓ Velocidad aleatoria (0.97-1.03x)\n";
    echo "✓ Saturación (1.05-1.20)\n";
    echo "✓ Contraste (1.02-1.10)\n";
    echo "✓ Brillo (-0.02 a +0.02)\n";
    echo "✓ Hue/Tono (-5 a +5 grados)\n";
    echo "✓ Gamma (0.95-1.05)\n";
    echo "✓ Noise sutil (1-3)\n";
    echo "✓ Zoom sutil (1.005-1.025)\n";
    echo "✓ Metadata única por video\n";
    echo "✓ Audio tempo sincronizado\n\n";
    
    echo "=== LOG ===\n";
    if (file_exists($logFile)) {
        echo implode('', array_slice(file($logFile), -60));
    }
    exit;
}

if ($action === 'clear') {
    @unlink($logFile);
    foreach(glob("$jobsDir/*") as $f) @unlink($f);
    foreach(glob("$uploadDir/*") as $f) @unlink($f);
    header('Location: ?action=debug');
    exit;
}

if (ob_get_length()) ob_end_clean();
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral Maker v74</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&display=swap" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #080808 0%, #151520 100%); color: #fff; font-family: system-ui, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: rgba(15, 15, 15, 0.95); border: 1px solid #2a2a2a; max-width: 480px; width: 100%; padding: 30px; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.6); }
        h2 { font-family: 'Anton', sans-serif; letter-spacing: 2px; }
        .form-control { background: #0a0a0a !important; color: #FFD700 !important; border: 2px solid #222; text-align: center; border-radius: 12px; padding: 14px; transition: all 0.3s; }
        .form-control:focus { border-color: #FFD700; box-shadow: 0 0 15px rgba(255,215,0,0.1); }
        .form-control::placeholder { color: #444; }
        .btn-go { width: 100%; padding: 16px; background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%); color: #000; font-family: 'Anton'; font-size: 1.3rem; border: none; border-radius: 12px; margin-top: 15px; transition: all 0.3s; cursor: pointer; }
        .btn-go:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(255,215,0,0.25); }
        .btn-go:disabled { background: #333; color: #555; cursor: not-allowed; }
        .hidden { display: none; }
        video { width: 100%; border-radius: 12px; margin-top: 15px; border: 2px solid #222; }
        .progress { height: 8px; background: #1a1a1a; margin-top: 20px; border-radius: 4px; overflow: hidden; }
        .progress-bar { background: linear-gradient(90deg, #FFD700, #FFA500); }
        .file-info { font-size: 0.8rem; color: #555; margin-top: 6px; }
        .alert-missing { background: rgba(200,50,50,0.1); border: 1px solid #aa3333; color: #ff6666; border-radius: 10px; padding: 12px; font-size: 0.85rem; }
        .char-count { font-size: 0.7rem; color: #444; }
        .char-count.warn { color: #FFA500; }
        .char-count.max { color: #ff4444; }
        .tag { display: inline-block; background: rgba(255,215,0,0.1); color: #FFD700; font-size: 0.65rem; padding: 2px 8px; border-radius: 10px; margin: 2px; }
        .ok { color: #44ff88; }
        .warn { color: #FFA500; }
    </style>
</head>
<body>

<div class="card">
    <div class="text-center mb-4">
        <h2>VIRAL MAKER <span class="text-warning">v74</span></h2>
        <p class="text-secondary small mb-2">ANTI-FINGERPRINT COMPLETO</p>
        <div>
            <span class="tag">Hash Único</span>
            <span class="tag">Velocidad Variable</span>
            <span class="tag">Color Shift</span>
            <span class="tag">Audio 0.3</span>
        </div>
    </div>

    <div id="uiInput">
        <?php if(!$hasFfmpeg): ?>
            <div class="alert alert-danger">FFmpeg no instalado</div>
        <?php elseif(!empty($missingFiles)): ?>
            <div class="alert-missing">
                <strong>Archivos faltantes:</strong><br>
                <?php foreach($missingFiles as $mf) echo "• $mf<br>"; ?>
            </div>
        <?php else: ?>
            <div class="mb-3">
                <input type="text" id="tIn" class="form-control" placeholder="TÍTULO OBLIGATORIO" maxlength="36">
                <div class="d-flex justify-content-between px-1 mt-1">
                    <span class="text-secondary" style="font-size:0.7rem">Solo texto (sin emojis)</span>
                    <span id="charCount" class="char-count">0/36</span>
                </div>
            </div>
            
            <input type="file" id="fIn" class="form-control" accept="video/*">
            <div id="fileInfo" class="file-info text-center"></div>
            
            <div class="form-check form-switch d-flex justify-content-center gap-2 mt-3">
                <input class="form-check-input" type="checkbox" id="mirrorCheck" style="cursor:pointer">
                <label class="form-check-label text-secondary" for="mirrorCheck">Modo Espejo</label>
            </div>

            <button id="btnGo" class="btn-go" onclick="process()" disabled>CREAR VIDEO VIRAL</button>
            
            <p class="text-center text-secondary small mt-3 mb-0">
                Máx 5 minutos • Título en mayúsculas<br>
                <span class="text-warning">Cada video tiene hash único</span>
            </p>
        <?php endif; ?>
    </div>

    <div id="uiProcess" class="hidden text-center">
        <div class="spinner-border text-warning mb-3"></div>
        <h5>PROCESANDO</h5>
        <p class="text-secondary small">Aplicando anti-fingerprint...</p>
        <div class="progress">
            <div id="progressBar" class="progress-bar" style="width:0%"></div>
        </div>
        <div id="progressText" class="mt-2 text-warning fw-bold">0%</div>
        <div id="timeEst" class="small text-secondary"></div>
    </div>

    <div id="uiResult" class="hidden text-center">
        <div id="videoBox"></div>
        <a id="dlLink" class="btn btn-success w-100 mt-3 py-3 fw-bold">DESCARGAR</a>
        <button onclick="location.reload()" class="btn btn-outline-warning w-100 mt-2">NUEVO VIDEO</button>
        <p class="small text-secondary mt-2 mb-0">✓ Hash único • Listo para Facebook/TikTok</p>
    </div>
</div>

<script>
const tIn = document.getElementById('tIn');
const fIn = document.getElementById('fIn');
const btnGo = document.getElementById('btnGo');
const charCount = document.getElementById('charCount');
const fileInfo = document.getElementById('fileInfo');

let vDur = 0;

function validate() {
    const hasT = tIn && tIn.value.trim().length > 0;
    const hasF = fIn && fIn.files.length > 0;
    const okDur = vDur <= 300 || vDur === 0;
    if(btnGo) btnGo.disabled = !(hasT && hasF && okDur);
}

if(tIn) {
    tIn.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
        const len = this.value.length;
        charCount.textContent = len + '/36';
        charCount.className = 'char-count';
        if(len > 28) charCount.classList.add('warn');
        if(len >= 36) charCount.classList.add('max');
        validate();
    });
}

if(fIn) {
    fIn.addEventListener('change', function() {
        const file = this.files[0];
        if(file) {
            const mb = (file.size/1048576).toFixed(1);
            const v = document.createElement('video');
            v.preload = 'metadata';
            v.onloadedmetadata = function() {
                vDur = v.duration;
                const m = Math.floor(vDur/60);
                const s = Math.floor(vDur%60);
                const dur = m + ':' + String(s).padStart(2,'0');
                const dim = v.videoWidth + 'x' + v.videoHeight;
                if(vDur > 300) {
                    fileInfo.innerHTML = `<span class="warn">⚠ ${dur} (máx 5:00)</span> | ${dim} | ${mb}MB`;
                } else {
                    fileInfo.innerHTML = `<span class="ok">✓ ${dur}</span> | ${dim} | ${mb}MB`;
                }
                URL.revokeObjectURL(v.src);
                validate();
            };
            v.src = URL.createObjectURL(file);
        }
    });
}

async function process() {
    if(!tIn.value.trim()) return alert('Título obligatorio');
    if(!fIn.files[0]) return alert('Selecciona video');
    if(vDur > 300) return alert('Video muy largo');

    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData();
    fd.append('videoFile', fIn.files[0]);
    fd.append('videoTitle', tIn.value);
    fd.append('mirrorMode', document.getElementById('mirrorCheck').checked);

    try {
        const res = await fetch('?action=upload', {method:'POST', body:fd});
        const txt = await res.text();
        let data;
        try { data = JSON.parse(txt); } catch(e) { throw new Error('Error servidor'); }
        if(data.status === 'error') { alert(data.msg); location.reload(); return; }
        track(data.jobId);
    } catch(e) {
        alert(e.message);
        location.reload();
    }
}

function track(id) {
    const bar = document.getElementById('progressBar');
    const txt = document.getElementById('progressText');
    const est = document.getElementById('timeEst');
    const t0 = Date.now();
    
    const iv = setInterval(async () => {
        try {
            const res = await fetch('?action=status&jobId=' + id);
            const data = await res.json();
            
            if(data.status === 'finished') {
                clearInterval(iv);
                document.getElementById('uiProcess').classList.add('hidden');
                document.getElementById('uiResult').classList.remove('hidden');
                document.getElementById('videoBox').innerHTML = '<video src="processed/'+data.file+'?t='+Date.now()+'" controls autoplay loop playsinline></video>';
                document.getElementById('dlLink').href = '?action=download&file=' + data.file;
            } else if(data.status === 'error') {
                clearInterval(iv);
                alert(data.msg);
                location.reload();
            } else {
                bar.style.width = data.progress + '%';
                txt.textContent = data.progress + '%';
                const el = (Date.now() - t0) / 1000;
                if(data.progress > 5) {
                    const rem = Math.max(0, (el / data.progress * 100) - el);
                    est.textContent = '≈ ' + Math.floor(rem/60) + 'm ' + Math.floor(rem%60) + 's';
                }
            }
        } catch(e) {}
    }, 3000);
}
</script>

</body>
</html>
