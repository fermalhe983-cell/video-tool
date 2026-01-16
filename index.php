<?php
// ==========================================
// VIRAL REELS MAKER v68.0 (ANTI-CRASH EDITION)
// Especial para VPS/Easypanel con muy poca RAM
// ==========================================

// 1. LIMITAR PHP PARA DEJAR RAM A FFMPEG
error_reporting(E_ALL);
ini_set('display_errors', 0);
// Reducimos memoria de PHP a 256M. Lo que sobra lo usa FFmpeg.
ini_set('memory_limit', '256M'); 
ini_set('upload_max_filesize', '2048M');
ini_set('post_max_size', '2048M');
set_time_limit(0); 
ignore_user_abort(true);

ob_start();

// Rutas
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

// Directorios
if (!file_exists($uploadDir)) @mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) @mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) @mkdir($jobsDir, 0777, true);

// Limpieza agresiva (1 hora)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    if(is_dir($dir)){
        foreach (glob("$dir/*") as $file) {
            if (is_file($file) && (time() - filemtime($file) > 3600)) @unlink($file);
        }
    }
}

function sendJson($data) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

$action = $_GET['action'] ?? '';
$ffmpegPath = trim(shell_exec('which ffmpeg'));
$hasFfmpeg = !empty($ffmpegPath);

// ==========================================
// API: UPLOAD
// ==========================================
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Check rápido de error de carga
    if (empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        sendJson(['status'=>'error', 'msg'=>'Archivo demasiado grande (PHP Limit).']);
    }

    if (!$hasFfmpeg) sendJson(['status'=>'error', 'msg'=>'FFmpeg no instalado.']);

    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        sendJson(['status'=>'error', 'msg'=>'Error subiendo archivo.']);
    }

    $jobId = uniqid('v68_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        sendJson(['status'=>'error', 'msg'=>'Error guardando archivo.']);
    }
    chmod($inputFile, 0777);

    // Liberar memoria PHP inmediatamente después de subir
    gc_collect_cycles();

    // Duración
    $durCmd = "$ffmpegPath -i " . escapeshellarg($inputFile) . " 2>&1 | grep Duration";
    $durOut = shell_exec($durCmd);
    preg_match('/Duration: (\d{2}):(\d{2}):(\d{2}\.\d{2})/', $durOut, $matches);
    $seconds = 60;
    if (!empty($matches)) {
        $seconds = ($matches[1] * 3600) + ($matches[2] * 60) + $matches[3];
    }

    // Configuración
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    $mirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    $title = mb_strtoupper($_POST['videoTitle'] ?? '');

    // --- COMANDO FFMPEG "MODO SEGURO" ---
    $cmdIn = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $cmdIn .= " -i " . escapeshellarg($logoPath);
    if ($useAudio) $cmdIn .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    // Filtros
    $cw = 720; $ch = 1280;
    $wrapped = wordwrap($title, 18, "\n", true);
    $lines = explode("\n", $wrapped);
    if(count($lines)>2) { $lines=array_slice($lines,0,2); $lines[1]=substr($lines[1],0,15).".."; }
    $txtSize = (count($lines)==1) ? 80 : 70;
    $txtY = (count($lines)==1) ? [30] : [30, 110];
    $vidY = (count($lines)==1) ? 135 : 210;

    $fc = "";
    // Video Base
    $hflip = $mirror ? ",hflip" : "";
    $fc .= "color=c=#080808:s={$cw}x{$ch}:d=".($seconds+2)."[bg];";
    $fc .= "[0:v]scale={$cw}:-1,setsar=1{$hflip},eq=saturation=1.15:contrast=1.05,setpts=0.98*PTS[vid];";
    $fc .= "[bg][vid]overlay=0:{$vidY}:shortest=1[base];";
    $last = "[base]";

    // Texto
    if ($useFont && !empty($title)) {
        $fFile = str_replace('\\','/', realpath($fontPath));
        foreach($lines as $k => $l) {
            $l = str_replace("'", "\\'", $l);
            $y = $txtY[$k];
            $fc .= "{$last}drawtext=fontfile='$fFile':text='$l':fontcolor=#FFD700:fontsize={$txtSize}:borderw=4:bordercolor=black:x=(w-text_w)/2:y={$y}[t{$k}];";
            $last = "[t{$k}]";
        }
    }

    // Logo
    if ($useLogo) {
        $fc .= "[1:v]scale=-1:80[lg];{$last}[lg]overlay=30:H-120[vfin]";
        $last = "[vfin]";
    } else {
        $fc .= "{$last}copy[vfin]";
    }

    // Audio
    if ($useAudio) {
        $idx = $useLogo ? 2 : 1;
        $fc .= ";[0:a]volume=1.0[v];[{$idx}:a]volume=0.15[m];[v][m]amix=inputs=2:duration=first[afin]";
    } else {
        $fc .= ";[0:a]volume=1.0[afin]";
    }

    file_put_contents($jobFile, json_encode([
        'status' => 'processing',
        'file' => $outputFileName,
        'start' => time(),
        'duration' => $seconds
    ]));

    // FLAG MAGICAS PARA BAJA RAM:
    // -threads 1 (Solo 1 hilo de CPU)
    // -filter_complex_threads 1 (Solo 1 hilo para filtros -> AHORRA MUCHA RAM)
    // -preset superfast (No usa buffers gigantes como ultrafast)
    // nice -n 19 (Baja prioridad en el sistema)
    
    $finalCmd = "nice -n 19 nohup $ffmpegPath -y $cmdIn " .
                "-filter_complex_threads 1 " . 
                "-filter_complex \"$fc\" " .
                "-map \"[vfin]\" -map \"[afin]\" " .
                "-c:v libx264 -preset superfast -threads 1 -max_muxing_queue_size 512 -crf 28 " .
                "-c:a aac -b:a 128k -movflags +faststart " .
                "-shortest " . escapeshellarg($outputFile) . " > $logFile 2>&1 &";
    
    exec($finalCmd);

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

        // Si existe y tiene tamaño, terminó
        if (!empty($data['file']) && file_exists($fullPath) && filesize($fullPath) > 50000) {
            if ((time() - filemtime($fullPath)) > 3) {
                sendJson(['status' => 'finished', 'file' => $data['file']]);
            }
        }
        
        // Timeout
        if ((time() - $data['start']) > 1800) { 
            sendJson(['status' => 'error', 'msg' => 'Timeout: El servidor se reinició o tardó mucho.']);
        }

        // Progreso estimado (Más lento porque usamos 1 hilo)
        $elapsed = time() - $data['start'];
        $progress = min(98, ($elapsed / max(1, ($data['duration'] * 2.0))) * 100); 
        sendJson(['status' => 'processing', 'progress' => round($progress)]);
    }
    sendJson(['status' => 'error', 'msg' => 'Iniciando...']);
}

// UTILS
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

if (ob_get_length()) ob_end_clean();
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral Maker Safe Mode</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&display=swap" rel="stylesheet">
    <style>
        body { background: #050505; color: #fff; font-family: sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #121212; border: 1px solid #333; max-width: 500px; width: 100%; padding: 30px; border-radius: 20px; box-shadow: 0 0 60px rgba(255, 215, 0, 0.05); }
        h2 { font-family: 'Anton', sans-serif; letter-spacing: 1px; }
        .form-control { background: #000 !important; color: #FFD700 !important; border: 1px solid #333; text-align: center; border-radius: 10px; padding: 15px; }
        .form-control:focus { border-color: #FFD700; box-shadow: none; }
        .btn-go { width: 100%; padding: 15px; background: #FFD700; color: #000; font-family: 'Anton'; font-size: 1.5rem; border: none; border-radius: 10px; margin-top: 15px; }
        .hidden { display: none; }
        video { width: 100%; border-radius: 10px; margin-top: 20px; border: 1px solid #333; }
        .progress { height: 6px; background: #222; margin-top: 20px; }
        .progress-bar { background: #FFD700; }
    </style>
</head>
<body>

<div class="card">
    <div class="text-center mb-4">
        <h2>VIRAL MAKER <span class="text-warning">v68</span></h2>
        <p class="text-secondary small">MODO ANTI-CRASH (SINGLE THREAD)</p>
    </div>

    <div id="uiInput">
        <?php if(!$hasFfmpeg): ?>
            <div class="alert alert-danger">⚠️ FFMPEG NO INSTALADO</div>
        <?php else: ?>
            <input type="text" id="tIn" class="form-control mb-3" placeholder="TITULAR CORTO (OPCIONAL)" maxlength="36">
            <input type="file" id="fIn" class="form-control mb-3" accept="video/*">
            
            <div class="form-check form-switch d-flex justify-content-center gap-2">
                <input class="form-check-input" type="checkbox" id="mirrorCheck">
                <label class="form-check-label text-secondary">Modo Espejo</label>
            </div>

            <button class="btn-go" onclick="process()">RENDERIZAR</button>
        <?php endif; ?>
    </div>

    <div id="uiProcess" class="hidden text-center">
        <div class="spinner-border text-warning mb-3"></div>
        <h4>PROCESANDO...</h4>
        <p class="text-secondary small">Procesando lento para no saturar memoria.<br>Esto tardará más de lo normal.</p>
        <div class="progress">
            <div id="progressBar" class="progress-bar" style="width: 0%"></div>
        </div>
        <div id="progressText" class="small text-muted mt-2">0%</div>
    </div>

    <div id="uiResult" class="hidden text-center">
        <div id="videoContainer"></div>
        <a id="dlLink" class="btn btn-success w-100 mt-3 py-3 fw-bold">DESCARGAR</a>
        <button onclick="location.reload()" class="btn btn-outline-secondary w-100 mt-2">NUEVO VIDEO</button>
    </div>
</div>

<script>
async function process() {
    const file = document.getElementById('fIn').files[0];
    if(!file) return alert("Selecciona un video");

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
            throw new Error("Error del servidor: Posible reinicio por falta de RAM.");
        }
        
        if(data.status === 'error') {
            alert(data.msg);
            location.reload();
            return;
        }

        track(data.jobId);

    } catch(e) {
        alert("Error crítico: " + e.message);
        location.reload();
    }
}

function track(id) {
    const bar = document.getElementById('progressBar');
    const txt = document.getElementById('progressText');
    
    // Polling cada 4 segundos para no estresar el servidor
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
            }
        } catch(e) {
            console.log("Esperando servidor...");
        }
    }, 4000);
}

function showResult(file) {
    document.getElementById('uiProcess').classList.add('hidden');
    document.getElementById('uiResult').classList.remove('hidden');
    
    const url = `processed/${file}`;
    document.getElementById('videoContainer').innerHTML = 
        `<video src="${url}" controls autoplay loop></video>`;
    document.getElementById('dlLink').href = `?action=download&file=${file}`;
}
</script>

</body>
</html>
