<?php
// ==========================================
// VIRAL REELS MAKER v72.0 - AUDIO ALTO + EMOJIS + 5 MIN
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

// Limpieza (archivos > 3 horas para videos largos)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    if(is_dir($dir)){
        foreach (glob("$dir/*") as $file) {
            if (is_file($file) && (time() - filemtime($file) > 10800)) @unlink($file);
        }
    }
}

function sendJson($data) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Escapar texto para filtros FFmpeg (compatible con emojis)
function escapeFFmpegText($text) {
    // Primero escapamos backslashes
    $text = str_replace("\\", "\\\\", $text);
    // Luego los caracteres especiales de FFmpeg
    $text = str_replace("'", "'\\''", $text); // Escapar comillas simples para shell
    $text = str_replace(":", "\\:", $text);
    $text = str_replace("[", "\\[", $text);
    $text = str_replace("]", "\\]", $text);
    $text = str_replace("%", "\\%", $text);
    return $text;
}

$action = $_GET['action'] ?? '';
$ffmpegPath = trim(shell_exec('which ffmpeg'));
$hasFfmpeg = !empty($ffmpegPath);

// Verificar archivos obligatorios
$hasLogo = file_exists($logoPath);
$hasFont = file_exists($fontPath);
$hasAudio = file_exists($audioPath);
$missingFiles = [];
if (!$hasLogo) $missingFiles[] = 'logo.png';
if (!$hasFont) $missingFiles[] = 'font.ttf (debe soportar emojis, ej: NotoColorEmoji)';
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

    $jobId = uniqid('v72_');
    $ext = strtolower(pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION));
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $tempVideo = "$uploadDir/{$jobId}_temp.mp4";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";
    $scriptFile = "$jobsDir/{$jobId}.sh";
    $filterFile = "$jobsDir/{$jobId}_filter.txt"; // Filtro en archivo separado

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        sendJson(['status'=>'error', 'msg'=>'Error guardando archivo.']);
    }
    chmod($inputFile, 0777);
    gc_collect_cycles();

    // Obtener duración
    $ffprobePath = trim(shell_exec('which ffprobe'));
    if (!empty($ffprobePath)) {
        $durCmd = "$ffprobePath -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile);
        $seconds = floatval(trim(shell_exec($durCmd)));
    } else {
        $durCmd = "$ffmpegPath -i " . escapeshellarg($inputFile) . " 2>&1 | grep Duration";
        $durOut = shell_exec($durCmd);
        preg_match('/Duration: (\d{2}):(\d{2}):(\d{2}\.\d{2})/', $durOut, $matches);
        $seconds = 60;
        if (!empty($matches)) {
            $seconds = ($matches[1] * 3600) + ($matches[2] * 60) + floatval($matches[3]);
        }
    }
    
    if ($seconds < 1) $seconds = 60;
    
    // Límite de 5 minutos (300 segundos)
    if ($seconds > 300) {
        @unlink($inputFile);
        sendJson(['status'=>'error', 'msg'=>'El video excede 5 minutos. Máximo permitido: 5:00']);
    }

    $mirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    // TÍTULO: Mayúsculas (preservando emojis)
    $title = mb_strtoupper($title, 'UTF-8');
    // Solo limpiamos caracteres de control, mantenemos emojis y unicode
    $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title);
    $title = preg_replace('/\s+/', ' ', $title);

    // ---------------------------------------------------------
    // CONFIGURACIÓN DE LAYOUT
    // ---------------------------------------------------------
    $cw = 720; $ch = 1280;
    
    // Wordwrap compatible con emojis
    $titleLen = mb_strlen($title, 'UTF-8');
    $lines = [];
    if ($titleLen <= 18) {
        $lines[] = $title;
    } else {
        // Dividir por palabras respetando emojis
        $words = preg_split('/\s+/u', $title);
        $currentLine = '';
        foreach ($words as $word) {
            $testLine = $currentLine ? "$currentLine $word" : $word;
            if (mb_strlen($testLine, 'UTF-8') <= 18) {
                $currentLine = $testLine;
            } else {
                if ($currentLine) $lines[] = $currentLine;
                $currentLine = $word;
            }
        }
        if ($currentLine) $lines[] = $currentLine;
    }
    
    // Máximo 2 líneas
    if (count($lines) > 2) {
        $lines = array_slice($lines, 0, 2);
        $lines[1] = mb_substr($lines[1], 0, 15, 'UTF-8') . '..';
    }
    
    $numLines = count($lines);
    $txtSize = ($numLines == 1) ? 72 : 64;
    $txtY = ($numLines == 1) ? [40] : [30, 100];
    $vidY = ($numLines == 1) ? 130 : 190;

    // ---------------------------------------------------------
    // PASO 1: VIDEO + TEXTO + LOGO
    // Usamos archivo de filtro para evitar problemas de escape
    // ---------------------------------------------------------
    $fFile = str_replace('\\', '/', realpath($fontPath));
    
    $fc = "";
    $hflip = $mirror ? ",hflip" : "";
    
    // Fondo + Video
    $fc .= "color=c=#080808:s={$cw}x{$ch}[bg];";
    $fc .= "[0:v]scale={$cw}:-1,setsar=1{$hflip},eq=saturation=1.15:contrast=1.05[vid];";
    $fc .= "[bg][vid]overlay=0:{$vidY}:shortest=1[base];";
    
    // Texto con soporte UTF-8/Emojis
    $last = "[base]";
    foreach ($lines as $k => $l) {
        $escapedLine = escapeFFmpegText($l);
        $y = $txtY[$k];
        // Usamos textfile para mejor compatibilidad con emojis, pero inline funciona si la fuente soporta
        $fc .= "{$last}drawtext=fontfile='{$fFile}':text='{$escapedLine}':fontcolor=#FFD700:fontsize={$txtSize}:borderw=4:bordercolor=black:x=(w-text_w)/2:y={$y}[t{$k}];";
        $last = "[t{$k}]";
    }
    
    // Logo
    $fc .= "[1:v]scale=-1:80[lg];{$last}[lg]overlay=30:H-120[vfin]";

    // Guardar filtro en archivo (evita problemas de shell con emojis)
    file_put_contents($filterFile, $fc);
    chmod($filterFile, 0644);

    $inputs = "-i " . escapeshellarg($inputFile) . " -i " . escapeshellarg($logoPath);

    // Comando 1: Video (usando filter_script para emojis)
    $cmdStep1 = "$ffmpegPath -y $inputs -filter_complex_script " . escapeshellarg($filterFile) . " -map \"[vfin]\" -an -c:v libx264 -preset medium -threads 2 -crf 23 -movflags +faststart " . escapeshellarg($tempVideo);

    // ---------------------------------------------------------
    // PASO 2: AUDIO EN LOOP CON VOLUMEN ALTO
    // Subimos el volumen de la música de fondo a 0.6 (era 0.15)
    // ---------------------------------------------------------
    $cmdStep2 = "$ffmpegPath -y -i " . escapeshellarg($tempVideo) . " -i " . escapeshellarg($inputFile) . " -stream_loop -1 -i " . escapeshellarg($audioPath);
    
    // VOLUMEN AJUSTADO:
    // - Audio original del video: 0.8 (ligeramente reducido para que no tape la música)
    // - Música de fondo (news.mp3): 0.6 (SUBIDO de 0.15)
    $filterAudio = "[1:a]aresample=async=1,volume=0.8[vorig];[2:a]aresample=async=1,volume=0.6[vmusic];[vorig][vmusic]amix=inputs=2:duration=first:dropout_transition=2:normalize=0[afin]";
    
    $cmdStep2 .= " -filter_complex \"$filterAudio\" -map 0:v -map \"[afin]\" -c:v copy -c:a aac -b:a 192k " . escapeshellarg($outputFile);

    // Guardar Estado
    file_put_contents($jobFile, json_encode([
        'status' => 'processing',
        'file' => $outputFileName,
        'start' => time(),
        'duration' => $seconds
    ]));

    // SCRIPT BASH
    $scriptContent = "#!/bin/bash\n";
    $scriptContent .= "export LANG=en_US.UTF-8\n"; // Soporte UTF-8
    $scriptContent .= "export LC_ALL=en_US.UTF-8\n";
    $scriptContent .= "cd " . escapeshellarg($baseDir) . "\n";
    $scriptContent .= "echo '=== INICIO: '\$(date)' ===' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "echo 'Duracion del video: {$seconds}s' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "echo 'PASO 1: Video + Texto + Logo...' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= $cmdStep1 . " >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "STEP1=\$?\n";
    $scriptContent .= "if [ \$STEP1 -eq 0 ]; then\n";
    $scriptContent .= "  echo 'PASO 2: Audio en loop (vol 0.6)...' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "  " . $cmdStep2 . " >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "  STEP2=\$?\n";
    $scriptContent .= "  if [ \$STEP2 -eq 0 ]; then\n";
    $scriptContent .= "    echo 'EXITO: Video generado' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "  else\n";
    $scriptContent .= "    echo 'ERROR en paso 2 (codigo: '\$STEP2')' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "  fi\n";
    $scriptContent .= "else\n";
    $scriptContent .= "  echo 'ERROR en paso 1 (codigo: '\$STEP1')' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "fi\n";
    $scriptContent .= "rm -f " . escapeshellarg($tempVideo) . "\n";
    $scriptContent .= "rm -f " . escapeshellarg($filterFile) . "\n";
    $scriptContent .= "echo '=== FIN: '\$(date)' ===' >> " . escapeshellarg($logFile) . " 2>&1\n";

    file_put_contents($scriptFile, $scriptContent);
    chmod($scriptFile, 0755);

    $fullCommand = "nohup nice -n 19 bash " . escapeshellarg($scriptFile) . " > /dev/null 2>&1 &";
    exec($fullCommand);

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
            $lastMod = filemtime($fullPath);
            if ((time() - $lastMod) > 5) {
                sendJson(['status' => 'finished', 'file' => $data['file']]);
            }
        }
        
        // Timeout: 4x duración + 5 min buffer (para videos de 5 min)
        $timeout = max(600, ($data['duration'] * 4) + 300);
        if ((time() - $data['start']) > $timeout) { 
            sendJson(['status' => 'error', 'msg' => 'Timeout después de ' . round($timeout/60) . ' minutos. Revisa ?action=debug']);
        }

        $elapsed = time() - $data['start'];
        // Para videos largos, estimamos ~2.5x tiempo real
        $estimatedTotal = $data['duration'] * 2.5;
        $progress = min(99, ($elapsed / max(1, $estimatedTotal)) * 100); 
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
        header('Content-Disposition: attachment; filename="VIRAL_'.date('md_Hi').'.mp4"');
        header('Content-Length: '.filesize($p));
        readfile($p);
        exit;
    }
}

if ($action === 'delete_job' && isset($_GET['file'])) {
    $f = basename($_GET['file']);
    @unlink("$processedDir/$f");
    $jid = explode('_', $f)[0];
    foreach(glob("$uploadDir/{$jid}*") as $tmp) @unlink($tmp);
    foreach(glob("$jobsDir/{$jid}*") as $tmp) @unlink($tmp);
    header('Location: ?deleted');
    exit;
}

if ($action === 'debug') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== VIRAL MAKER v72 - DEBUG ===\n\n";
    echo "FFmpeg: " . ($hasFfmpeg ? "OK ($ffmpegPath)" : "NO INSTALADO") . "\n";
    echo "Logo (logo.png): " . ($hasLogo ? "OK (" . filesize($logoPath) . " bytes)" : "FALTA") . "\n";
    echo "Fuente (font.ttf): " . ($hasFont ? "OK (" . filesize($fontPath) . " bytes)" : "FALTA") . "\n";
    echo "Audio (news.mp3): " . ($hasAudio ? "OK (" . round(filesize($audioPath)/1024) . " KB)" : "FALTA") . "\n";
    echo "Límite video: 5 minutos (300s)\n";
    echo "Volumen música: 0.6 (60%)\n\n";
    
    echo "=== JOBS ACTIVOS ===\n";
    foreach (glob("$jobsDir/*.json") as $jf) {
        $jd = json_decode(file_get_contents($jf), true);
        $age = time() - $jd['start'];
        echo basename($jf) . " - " . $jd['status'] . " - {$jd['duration']}s - hace {$age}s\n";
    }
    
    echo "\n=== LOG FFMPEG ===\n";
    if (file_exists($logFile)) {
        // Últimas 100 líneas
        $lines = file($logFile);
        $lines = array_slice($lines, -100);
        echo implode('', $lines);
    } else {
        echo "No hay log disponible\n";
    }
    exit;
}

if ($action === 'clear_log') {
    @unlink($logFile);
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
    <title>Viral Maker v72</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&display=swap" rel="stylesheet">
    <style>
        body { background: #050505; color: #fff; font-family: sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #121212; border: 1px solid #333; max-width: 500px; width: 100%; padding: 30px; border-radius: 20px; box-shadow: 0 0 60px rgba(255, 215, 0, 0.05); }
        h2 { font-family: 'Anton', sans-serif; letter-spacing: 1px; }
        .form-control { background: #000 !important; color: #FFD700 !important; border: 1px solid #333; text-align: center; border-radius: 10px; padding: 15px; text-transform: uppercase; }
        .form-control::placeholder { text-transform: none; color: #666; }
        .form-control:focus { border-color: #FFD700; box-shadow: none; }
        .btn-go { width: 100%; padding: 15px; background: #FFD700; color: #000; font-family: 'Anton'; font-size: 1.5rem; border: none; border-radius: 10px; margin-top: 15px; transition: all 0.3s; }
        .btn-go:hover { background: #ffed4a; transform: scale(1.02); }
        .btn-go:disabled { background: #444; color: #222; cursor: not-allowed; transform: none; }
        .hidden { display: none; }
        video { width: 100%; border-radius: 10px; margin-top: 20px; border: 1px solid #333; }
        .progress { height: 8px; background: #222; margin-top: 20px; border-radius: 4px; overflow: hidden; }
        .progress-bar { background: linear-gradient(90deg, #FFD700, #ff8c00); transition: width 0.5s; }
        .file-info { font-size: 0.8rem; color: #888; margin-top: 5px; }
        .alert-missing { background: #2a1a1a; border: 1px solid #ff4444; color: #ff8888; font-size: 0.85rem; }
        .char-count { font-size: 0.75rem; color: #666; text-align: right; margin-top: 2px; }
        .char-count.warning { color: #ff8800; }
        .char-count.danger { color: #ff4444; }
        .duration-warning { color: #ff8800; font-size: 0.8rem; }
        .duration-ok { color: #44ff44; font-size: 0.8rem; }
        .emoji-hint { font-size: 0.7rem; color: #555; margin-top: 3px; }
    </style>
</head>
<body>

<div class="card">
    <div class="text-center mb-4">
        <h2>VIRAL MAKER <span class="text-warning">v72</span></h2>
        <p class="text-secondary small">EMOJIS ✨ • AUDIO ALTO 🔊 • HASTA 5 MIN</p>
    </div>

    <div id="uiInput">
        <?php if(!$hasFfmpeg): ?>
            <div class="alert alert-danger">⚠️ FFMPEG NO INSTALADO</div>
        <?php elseif(!empty($missingFiles)): ?>
            <div class="alert alert-missing">
                <strong>⚠️ Archivos faltantes:</strong><br>
                <?php foreach($missingFiles as $mf): ?>
                    • <?= htmlspecialchars($mf) ?><br>
                <?php endforeach; ?>
                <small class="d-block mt-2">Sube estos archivos a la misma carpeta del script.</small>
            </div>
        <?php else: ?>
            <input type="text" id="tIn" class="form-control mb-1" placeholder="TÍTULO CON EMOJIS 🔥✨" maxlength="40" required>
            <div class="d-flex justify-content-between">
                <span class="emoji-hint">Puedes usar emojis 🎬🔥✨💰</span>
                <span id="charCount" class="char-count">0 / 40</span>
            </div>
            
            <input type="file" id="fIn" class="form-control mb-2 mt-3" accept="video/*" required>
            <div id="fileInfo" class="file-info text-center"></div>
            
            <div class="form-check form-switch d-flex justify-content-center gap-2 mt-3">
                <input class="form-check-input" type="checkbox" id="mirrorCheck">
                <label class="form-check-label text-secondary">Modo Espejo</label>
            </div>

            <button id="btnGo" class="btn-go" onclick="process()" disabled>🎬 RENDERIZAR</button>
            <p class="text-center text-secondary small mt-2">Máximo 5 minutos • Título en MAYÚSCULAS</p>
        <?php endif; ?>
    </div>

    <div id="uiProcess" class="hidden text-center">
        <div class="spinner-border text-warning mb-3"></div>
        <h4>PROCESANDO...</h4>
        <p class="text-secondary small" id="processInfo">
            Paso 1: Video + Texto + Logo<br>
            Paso 2: Audio en Loop 🔊
        </p>
        <div class="progress">
            <div id="progressBar" class="progress-bar" style="width: 0%"></div>
        </div>
        <div id="progressText" class="small text-muted mt-2">0%</div>
        <div id="timeEstimate" class="small text-muted mt-1"></div>
    </div>

    <div id="uiResult" class="hidden text-center">
        <div id="videoContainer"></div>
        <a id="dlLink" class="btn btn-success w-100 mt-3 py-3 fw-bold">⬇️ DESCARGAR VIDEO</a>
        <button onclick="location.reload()" class="btn btn-outline-secondary w-100 mt-2">🎬 NUEVO VIDEO</button>
    </div>
</div>

<script>
const titleInput = document.getElementById('tIn');
const fileInput = document.getElementById('fIn');
const btnGo = document.getElementById('btnGo');
const charCount = document.getElementById('charCount');
const fileInfo = document.getElementById('fileInfo');

let videoDuration = 0;
const MAX_DURATION = 300; // 5 minutos

function validateForm() {
    const hasTitle = titleInput && titleInput.value.trim().length > 0;
    const hasFile = fileInput && fileInput.files.length > 0;
    const validDuration = videoDuration <= MAX_DURATION || videoDuration === 0;
    
    if (btnGo) {
        btnGo.disabled = !(hasTitle && hasFile && validDuration);
    }
}

if (titleInput) {
    titleInput.addEventListener('input', function() {
        // Contar caracteres (los emojis cuentan como 1-2)
        const len = [...this.value].length;
        charCount.textContent = len + ' / 40';
        charCount.className = 'char-count';
        if (len > 32) charCount.classList.add('warning');
        if (len > 38) charCount.classList.add('danger');
        validateForm();
    });
}

if (fileInput) {
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const sizeMB = (file.size / (1024*1024)).toFixed(1);
            
            // Obtener duración del video
            const video = document.createElement('video');
            video.preload = 'metadata';
            video.onloadedmetadata = function() {
                videoDuration = video.duration;
                URL.revokeObjectURL(video.src);
                
                const mins = Math.floor(videoDuration / 60);
                const secs = Math.floor(videoDuration % 60);
                const durText = `${mins}:${secs.toString().padStart(2, '0')}`;
                
                if (videoDuration > MAX_DURATION) {
                    fileInfo.innerHTML = `📁 ${file.name}<br>
                        <span class="duration-warning">⚠️ Duración: ${durText} (máx 5:00)</span><br>
                        Tamaño: ${sizeMB} MB`;
                } else {
                    fileInfo.innerHTML = `📁 ${file.name}<br>
                        <span class="duration-ok">✓ Duración: ${durText}</span><br>
                        Tamaño: ${sizeMB} MB`;
                }
                validateForm();
            };
            video.onerror = function() {
                fileInfo.innerHTML = `📁 ${file.name}<br>Tamaño: ${sizeMB} MB`;
                videoDuration = 0;
                validateForm();
            };
            video.src = URL.createObjectURL(file);
        }
        validateForm();
    });
}

async function process() {
    const file = fileInput.files[0];
    const title = titleInput.value.trim();
    
    if (!title) return alert("El título es obligatorio");
    if (!file) return alert("Selecciona un video");
    if (videoDuration > MAX_DURATION) return alert("El video excede 5 minutos");

    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData();
    fd.append('videoFile', file);
    fd.append('videoTitle', title);
    fd.append('mirrorMode', document.getElementById('mirrorCheck').checked);

    try {
        let res = await fetch('?action=upload', { method: 'POST', body: fd });
        let text = await res.text();
        
        try {
            var data = JSON.parse(text);
        } catch(e) {
            console.error("Respuesta:", text);
            throw new Error("Error del servidor. Revisa ?action=debug");
        }
        
        if (data.status === 'error') {
            alert(data.msg);
            location.reload();
            return;
        }

        track(data.jobId);

    } catch(e) {
        alert("Error: " + e.message);
        location.reload();
    }
}

function track(id) {
    const bar = document.getElementById('progressBar');
    const txt = document.getElementById('progressText');
    const timeEst = document.getElementById('timeEstimate');
    const startTime = Date.now();
    
    const interval = setInterval(async () => {
        try {
            let res = await fetch(`?action=status&jobId=${id}`);
            let data = await res.json();

            if (data.status === 'finished') {
                clearInterval(interval);
                showResult(data.file);
            } else if (data.status === 'error') {
                clearInterval(interval);
                alert(data.msg);
                location.reload();
            } else {
                bar.style.width = data.progress + '%';
                txt.innerText = Math.round(data.progress) + '%';
                
                const elapsed = (Date.now() - startTime) / 1000;
                if (data.progress > 5) {
                    const total = elapsed / (data.progress / 100);
                    const remaining = Math.max(0, total - elapsed);
                    const mins = Math.floor(remaining / 60);
                    const secs = Math.floor(remaining % 60);
                    timeEst.innerText = `≈ ${mins}m ${secs}s restantes`;
                }
            }
        } catch(e) {
            console.log("Verificando...");
        }
    }, 3000);
}

function showResult(file) {
    document.getElementById('uiProcess').classList.add('hidden');
    document.getElementById('uiResult').classList.remove('hidden');
    
    const url = `processed/${file}?t=${Date.now()}`;
    document.getElementById('videoContainer').innerHTML = 
        `<video src="${url}" controls autoplay loop playsinline></video>`;
    document.getElementById('dlLink').href = `?action=download&file=${file}`;
}
</script>

</body>
</html>
