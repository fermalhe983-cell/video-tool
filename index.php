<?php
// ==========================================
// VIRAL REELS MAKER v70.0 (FIXED SHELL ESCAPING)
// ==========================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('memory_limit', '256M');
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

// Limpieza (archivos > 2 horas para videos largos)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    if(is_dir($dir)){
        foreach (glob("$dir/*") as $file) {
            if (is_file($file) && (time() - filemtime($file) > 7200)) @unlink($file);
        }
    }
}

function sendJson($data) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// FUNCIÓN CRÍTICA: Escapar texto para filtros FFmpeg
function escapeFFmpegText($text) {
    // Escapar caracteres especiales para drawtext de FFmpeg
    $text = str_replace("\\", "\\\\\\\\", $text);
    $text = str_replace("'", "\\'", $text);
    $text = str_replace(":", "\\:", $text);
    $text = str_replace("[", "\\[", $text);
    $text = str_replace("]", "\\]", $text);
    $text = str_replace("(", "\\(", $text);
    $text = str_replace(")", "\\)", $text);
    $text = str_replace(",", "\\,", $text);
    $text = str_replace(";", "\\;", $text);
    return $text;
}

$action = $_GET['action'] ?? '';
$ffmpegPath = trim(shell_exec('which ffmpeg'));
$hasFfmpeg = !empty($ffmpegPath);

// ==========================================
// API: UPLOAD
// ==========================================
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        sendJson(['status'=>'error', 'msg'=>'Archivo excede límite PHP.']);
    }

    if (!$hasFfmpeg) sendJson(['status'=>'error', 'msg'=>'FFmpeg no instalado.']);

    $jobId = uniqid('v70_');
    $ext = strtolower(pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION));
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $tempVideo = "$uploadDir/{$jobId}_temp.mp4";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";
    $scriptFile = "$jobsDir/{$jobId}.sh"; // Script separado

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        sendJson(['status'=>'error', 'msg'=>'Error guardando archivo.']);
    }
    chmod($inputFile, 0777);
    gc_collect_cycles();

    // Obtener duración con ffprobe (más preciso)
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

    // Configuración
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    $mirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    // LIMPIAR TÍTULO - Solo caracteres seguros
    $title = $_POST['videoTitle'] ?? '';
    $title = mb_strtoupper(trim($title));
    // Remover caracteres problemáticos
    $title = preg_replace('/[^\p{L}\p{N}\s\-\.!\?]/u', '', $title);
    $title = preg_replace('/\s+/', ' ', $title); // Múltiples espacios a uno

    // ---------------------------------------------------------
    // PASO 1: PROCESAMIENTO DE VIDEO
    // ---------------------------------------------------------
    
    $cw = 720; $ch = 1280;
    $wrapped = wordwrap($title, 18, "\n", true);
    $lines = explode("\n", $wrapped);
    if(count($lines) > 2) { 
        $lines = array_slice($lines, 0, 2); 
        $lines[1] = mb_substr($lines[1], 0, 15) . ".."; 
    }
    $txtSize = (count($lines) == 1 || empty($title)) ? 80 : 70;
    $txtY = (count($lines) == 1 || empty($title)) ? [30] : [30, 110];
    $vidY = (count($lines) == 1 || empty($title)) ? 135 : 210;
    
    if (empty($title)) {
        $vidY = 0; // Sin título, video desde arriba
    }

    // Construir filtro
    $fc = "";
    $hflip = $mirror ? ",hflip" : "";
    
    // Para videos largos: no especificar duración fija
    $fc .= "color=c=#080808:s={$cw}x{$ch}[bg];";
    $fc .= "[0:v]scale={$cw}:-1,setsar=1{$hflip},eq=saturation=1.15:contrast=1.05[vid];";
    $fc .= "[bg][vid]overlay=0:{$vidY}:shortest=1[base];";
    $last = "[base]";

    if ($useFont && !empty($title)) {
        $fFile = str_replace('\\', '/', realpath($fontPath));
        foreach($lines as $k => $l) {
            $l = escapeFFmpegText($l);
            $y = $txtY[$k];
            $fc .= "{$last}drawtext=fontfile='{$fFile}':text='{$l}':fontcolor=#FFD700:fontsize={$txtSize}:borderw=4:bordercolor=black:x=(w-text_w)/2:y={$y}[t{$k}];";
            $last = "[t{$k}]";
        }
    }

    if ($useLogo) {
        $fc .= "[1:v]scale=-1:80[lg];{$last}[lg]overlay=30:H-120[vfin]";
    } else {
        $fc = rtrim($fc, ';');
        $fc = preg_replace('/\[base\]$/', '[vfin]', $fc);
        $fc = preg_replace('/\[t\d+\]$/', '[vfin]', $fc);
        if (strpos($fc, '[vfin]') === false) {
            $fc .= ";{$last}null[vfin]";
        }
    }

    // Inputs
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);

    // Comando 1: Solo video (sin audio)
    $cmdStep1 = "$ffmpegPath -y $inputs -filter_complex \"{$fc}\" -map \"[vfin]\" -an -c:v libx264 -preset fast -threads 2 -crf 26 -movflags +faststart " . escapeshellarg($tempVideo);

    // ---------------------------------------------------------
    // PASO 2: MEZCLA DE AUDIO
    // ---------------------------------------------------------
    
    if ($useAudio) {
        $cmdStep2 = "$ffmpegPath -y -i " . escapeshellarg($tempVideo) . " -i " . escapeshellarg($inputFile) . " -stream_loop -1 -i " . escapeshellarg($audioPath);
        $filterAudio = "[1:a]aresample=async=1,volume=1.0[v];[2:a]volume=0.15[m];[v][m]amix=inputs=2:duration=first:dropout_transition=0[afin]";
        $cmdStep2 .= " -filter_complex \"{$filterAudio}\" -map 0:v -map \"[afin]\" -c:v copy -c:a aac -b:a 128k -shortest " . escapeshellarg($outputFile);
    } else {
        // Sin música de fondo
        $cmdStep2 = "$ffmpegPath -y -i " . escapeshellarg($tempVideo) . " -i " . escapeshellarg($inputFile);
        $cmdStep2 .= " -map 0:v -map 1:a? -c:v copy -c:a aac -b:a 128k -shortest " . escapeshellarg($outputFile);
    }

    // Guardar Estado
    file_put_contents($jobFile, json_encode([
        'status' => 'processing',
        'file' => $outputFileName,
        'start' => time(),
        'duration' => $seconds
    ]));

    // CREAR SCRIPT DE SHELL SEPARADO (evita problemas de escape)
    $scriptContent = "#!/bin/bash\n";
    $scriptContent .= "cd " . escapeshellarg($baseDir) . "\n";
    $scriptContent .= "echo 'PASO 1: Procesando video...' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= $cmdStep1 . " >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "if [ \$? -eq 0 ]; then\n";
    $scriptContent .= "  echo 'PASO 2: Mezclando audio...' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "  " . $cmdStep2 . " >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "fi\n";
    $scriptContent .= "rm -f " . escapeshellarg($tempVideo) . "\n";
    $scriptContent .= "echo 'COMPLETADO' >> " . escapeshellarg($logFile) . " 2>&1\n";

    file_put_contents($scriptFile, $scriptContent);
    chmod($scriptFile, 0755);

    // Ejecutar script en background con nice
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

        // Verificar éxito
        if (!empty($data['file']) && file_exists($fullPath) && filesize($fullPath) > 10000) {
            clearstatcache(true, $fullPath);
            $lastMod = filemtime($fullPath);
            // Esperar a que el archivo no cambie por 5 segundos
            if ((time() - $lastMod) > 5) {
                sendJson(['status' => 'finished', 'file' => $data['file']]);
            }
        }
        
        // Timeout dinámico basado en duración (3x duración + 5 min buffer)
        $timeout = max(900, ($data['duration'] * 3) + 300);
        if ((time() - $data['start']) > $timeout) { 
            sendJson(['status' => 'error', 'msg' => 'Timeout después de ' . round($timeout/60) . ' minutos.']);
        }

        $elapsed = time() - $data['start'];
        // Estimación más realista: ~1.5x tiempo real para renderizado
        $estimatedTotal = $data['duration'] * 2;
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
        header('Content-Disposition: attachment; filename="VIDEO_'.date('md_Hi').'.mp4"');
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

// DEBUG: Ver log
if ($action === 'debug') {
    header('Content-Type: text/plain');
    if (file_exists($logFile)) {
        echo file_get_contents($logFile);
    } else {
        echo "No hay log disponible";
    }
    exit;
}

if (ob_get_length()) ob_end_clean();
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral Maker v70</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&display=swap" rel="stylesheet">
    <style>
        body { background: #050505; color: #fff; font-family: sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #121212; border: 1px solid #333; max-width: 500px; width: 100%; padding: 30px; border-radius: 20px; box-shadow: 0 0 60px rgba(255, 215, 0, 0.05); }
        h2 { font-family: 'Anton', sans-serif; letter-spacing: 1px; }
        .form-control { background: #000 !important; color: #FFD700 !important; border: 1px solid #333; text-align: center; border-radius: 10px; padding: 15px; }
        .form-control:focus { border-color: #FFD700; box-shadow: none; }
        .btn-go { width: 100%; padding: 15px; background: #FFD700; color: #000; font-family: 'Anton'; font-size: 1.5rem; border: none; border-radius: 10px; margin-top: 15px; transition: all 0.3s; }
        .btn-go:hover { background: #ffed4a; transform: scale(1.02); }
        .hidden { display: none; }
        video { width: 100%; border-radius: 10px; margin-top: 20px; border: 1px solid #333; }
        .progress { height: 6px; background: #222; margin-top: 20px; border-radius: 3px; }
        .progress-bar { background: linear-gradient(90deg, #FFD700, #ffed4a); transition: width 0.5s; }
        .file-info { font-size: 0.8rem; color: #888; margin-top: 5px; }
    </style>
</head>
<body>

<div class="card">
    <div class="text-center mb-4">
        <h2>VIRAL MAKER <span class="text-warning">v70</span></h2>
        <p class="text-secondary small">RENDERIZADO SEGURO • VIDEOS LARGOS OK</p>
    </div>

    <div id="uiInput">
        <?php if(!$hasFfmpeg): ?>
            <div class="alert alert-danger">⚠️ FFMPEG NO INSTALADO</div>
        <?php else: ?>
            <input type="text" id="tIn" class="form-control mb-3" placeholder="TITULAR CORTO (OPCIONAL)" maxlength="36">
            <input type="file" id="fIn" class="form-control mb-3" accept="video/*">
            <div id="fileInfo" class="file-info text-center"></div>
            
            <div class="form-check form-switch d-flex justify-content-center gap-2 mt-3">
                <input class="form-check-input" type="checkbox" id="mirrorCheck">
                <label class="form-check-label text-secondary">Modo Espejo</label>
            </div>

            <button class="btn-go" onclick="process()">RENDERIZAR</button>
        <?php endif; ?>
    </div>

    <div id="uiProcess" class="hidden text-center">
        <div class="spinner-border text-warning mb-3"></div>
        <h4>PROCESANDO...</h4>
        <p class="text-secondary small" id="processInfo">Paso 1: Video | Paso 2: Audio</p>
        <div class="progress">
            <div id="progressBar" class="progress-bar" style="width: 0%"></div>
        </div>
        <div id="progressText" class="small text-muted mt-2">0%</div>
        <div id="timeEstimate" class="small text-muted mt-1"></div>
    </div>

    <div id="uiResult" class="hidden text-center">
        <div id="videoContainer"></div>
        <a id="dlLink" class="btn btn-success w-100 mt-3 py-3 fw-bold">⬇️ DESCARGAR</a>
        <button onclick="location.reload()" class="btn btn-outline-secondary w-100 mt-2">NUEVO VIDEO</button>
    </div>
</div>

<script>
// Mostrar info del archivo
document.getElementById('fIn').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const sizeMB = (file.size / (1024*1024)).toFixed(1);
        document.getElementById('fileInfo').innerHTML = `📁 ${file.name}<br>Tamaño: ${sizeMB} MB`;
    }
});

let videoDuration = 60; // default

async function process() {
    const file = document.getElementById('fIn').files[0];
    if(!file) return alert("Selecciona un video");

    // Obtener duración del video
    const video = document.createElement('video');
    video.preload = 'metadata';
    video.onloadedmetadata = function() {
        videoDuration = video.duration;
        URL.revokeObjectURL(video.src);
    }
    video.src = URL.createObjectURL(file);

    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData();
    fd.append('videoFile', file);
    fd.append('videoTitle', document.getElementById('tIn').value);
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
        
        if(data.status === 'error') {
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

            if(data.status === 'finished') {
                clearInterval(interval);
                showResult(data.file);
            } else if(data.status === 'error') {
                clearInterval(interval);
                alert(data.msg);
                location.reload();
            } else {
                bar.style.width = data.progress + '%';
                txt.innerText = Math.round(data.progress) + '%';
                
                // Estimación de tiempo
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
    
    const url = `processed/${file}`;
    document.getElementById('videoContainer').innerHTML = 
        `<video src="${url}" controls autoplay loop playsinline></video>`;
    document.getElementById('dlLink').href = `?action=download&file=${file}`;
}
</script>

</body>
</html>
