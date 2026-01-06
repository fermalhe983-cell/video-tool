<?php
// ==========================================
// VIRAL REELS MAKER v37.0 (EMERGENCY CORE)
// Optimización Extrema: Algoritmos de bajo consumo + Límite de Buffer.
// ==========================================

// Configuración de Supervivencia
@ini_set('upload_max_filesize', '512M'); // Bajamos a 512MB para no saturar entrada
@ini_set('post_max_size', '512M');
@ini_set('max_execution_time', 600); 
@ini_set('memory_limit', '256M'); // PHP ligero
@ini_set('display_errors', 0);

// Directorios
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

// Carpetas
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); chmod($uploadDir, 0777); }
if (!file_exists($processedDir)) { mkdir($processedDir, 0777, true); chmod($processedDir, 0777); }
if (!file_exists($jobsDir)) { mkdir($jobsDir, 0777, true); chmod($jobsDir, 0777); }

// Limpieza (Archivos > 20 min se borran)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 1200)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> LOGS
if ($action === 'viewlog') {
    header('Content-Type: text/plain');
    echo file_exists($logFile) ? file_get_contents($logFile) : "Log vacío.";
    exit;
}

// ---> DESCARGA
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = "$processedDir/$file";
    if (file_exists($filePath)) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="VIRAL_LITE_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// ---> SUBIDA
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida (Posible falta de RAM).']); exit;
    }

    $jobId = uniqid('v37_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if(!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status' => 'error', 'message' => 'Fallo al mover archivo.']); exit;
    }
    chmod($inputFile, 0777);

    // Recursos
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';

    // Texto
    $rawTitle = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $rawTitle = mb_strtoupper($rawTitle);
    $wrappedText = wordwrap($rawTitle, 18, "\n", true);
    $lines = explode("\n", $wrappedText);
    if (count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    // Ajustes para 480p (480x854)
    if ($count == 1) { $barH = 100; $fSize = 45; $yPos = [55]; }
    elseif ($count == 2) { $barH = 160; $fSize = 40; $yPos = [45, 95]; }
    else { $barH = 210; $fSize = 35; $yPos = [35, 80, 125]; }

    // --- COMANDO DE EMERGENCIA ---
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($useAudio) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";

    // 1. ESCALADO RÁPIDO (flags=neighbor ahorra 80% CPU/RAM)
    // Forzamos 480 de ancho, alto proporcional, padding negro si falta.
    $filter .= "[0:v]scale=480:854:force_original_aspect_ratio=decrease:flags=neighbor,pad=480:854:(ow-iw)/2:(oh-ih)/2:color=black{$mirrorCmd}[base];";
    $lastStream = "[base]";

    // 2. CAJA NEGRA
    $filter .= "{$lastStream}drawbox=x=0:y=20:w=iw:h={$barH}:color=black@0.9:t=fill";

    // 3. TEXTO
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        foreach ($lines as $i => $line) {
            $y = $yPos[$i];
            $filter .= ",drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=2:bordercolor=black:shadowx=1:shadowy=1:x=(w-text_w)/2:y={$y}";
        }
    }
    $filter .= "[vtext];";
    $lastStream = "[vtext]";

    // 4. LOGO (Pequeño)
    if ($useLogo) {
        $logoY = 20 + ($barH/2) - 25; 
        $filter .= "[1:v]scale=-1:50:flags=neighbor[logo_s];"; // Neighbor aquí también
        $filter .= "{$lastStream}[logo_s]overlay=10:{$logoY}[vfinal_out];";
        $lastStream = "[vfinal_out]";
    } else {
        $filter .= "{$lastStream}copy[vfinal_out];";
    }

    // 5. AUDIO MIX SIMPLIFICADO
    if ($useAudio) {
        $mIdx = $useLogo ? "2" : "1";
        // Bajamos el volumen de música y mezclamos
        $filter .= "[{$mIdx}:a]volume=0.15[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=0[afinal_out]";
    } else {
        $filter .= "[0:a]atempo=1.0[afinal_out]";
    }

    // EJECUCIÓN (LÍMITES DUROS)
    // -threads 1: 1 solo núcleo.
    // -t 60: Corta el video a 60 segundos MÁXIMO (Evita crash por duración).
    // -max_muxing_queue_size 400: Reduce consumo de buffer.
    $cmd = "ffmpeg -y $inputs -filter_complex \"$filter\" -map \"$lastStream\" -map \"[afinal_out]\" -c:v libx264 -preset ultrafast -threads 1 -t 60 -crf 30 -pix_fmt yuv420p -c:a aac -b:a 64k -ac 1 -ar 44100 -max_muxing_queue_size 400 -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    file_put_contents($logFile, "\n--- JOB $jobId (LOW SPEC) ---\nCMD: $cmd\n", FILE_APPEND);
    exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName, 'start' => time()]));
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> STATUS
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . $data['file'];
        
        // Si existe y pesa > 1KB
        if (file_exists($fullPath) && filesize($fullPath) > 1024) {
            chmod($fullPath, 0777); 
            echo json_encode(['status' => 'finished', 'file' => $data['file']]);
        } else {
            // Timeout rápido (5 mins)
            if (time() - $data['start'] > 300) echo json_encode(['status' => 'error', 'message' => 'Crash: Video muy pesado para este VPS.']);
            else echo json_encode(['status' => 'processing']);
        }
    } else { echo json_encode(['status' => 'error']); }
    exit;
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Viral Lite v37</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background-color: #000; font-family: 'Inter', sans-serif; color: white; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 15px; }
        .main-card { background: #111; width: 100%; max-width: 480px; border: 1px solid #333; border-radius: 15px; padding: 25px; }
        .header-title { font-family: 'Anton', sans-serif; color: #ff3d00; font-size: 2rem; text-transform: uppercase; text-align: center; margin: 0; }
        .sub-title { text-align: center; color: #666; font-size: 0.8rem; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; }
        
        .viral-input { background: #000; border: 2px solid #333; color: white; font-family: 'Anton'; font-size: 1.2rem; padding: 12px; width: 100%; border-radius: 8px; margin-bottom: 15px; }
        .upload-btn { border: 2px dashed #444; border-radius: 10px; padding: 20px; text-align: center; cursor: pointer; background: #0a0a0a; margin-bottom: 20px; }
        .btn-action { background: #ff3d00; color: #fff; border: none; width: 100%; padding: 15px; font-family: 'Anton'; font-size: 1.2rem; text-transform: uppercase; border-radius: 8px; cursor: pointer; }
        
        .hidden { display: none; }
        .video-box { width: 100%; aspect-ratio: 9/16; background: #000; margin-bottom: 15px; border-radius: 10px; overflow: hidden; border: 1px solid #333; }
        video { width: 100%; height: 100%; object-fit: cover; }
        
        .progress-bar { height: 4px; background: #333; width: 100%; margin-top: 15px; border-radius: 2px; overflow: hidden; }
        .progress-fill { height: 100%; background: #ff3d00; width: 0%; transition: width 0.3s; }
        .form-check-input:checked { background-color: #ff3d00; border-color: #ff3d00; }
    </style>
</head>
<body>

<div class="main-card">
    <h1 class="header-title">VIRAL LITE v37</h1>
    <p class="sub-title">Low Memory Core</p>

    <div id="uiInput">
        <form id="vForm">
            <label class="small text-secondary fw-bold mb-1">TÍTULO</label>
            <input type="text" name="videoTitle" id="tIn" class="viral-input" placeholder="ESCRIBE AQUÍ..." maxlength="50" required autocomplete="off">

            <div class="form-check form-switch mb-3 p-2 border border-secondary rounded bg-dark d-flex justify-content-between">
                <label class="form-check-label text-white small" for="mirrorCheck">Espejo (Mirror)</label>
                <input class="form-check-input" type="checkbox" id="mirrorCheck">
            </div>

            <div class="upload-btn" onclick="document.getElementById('fIn').click()">
                <div class="fs-1">📂</div>
                <div class="fw-bold mt-2" id="fName">Subir Video</div>
                <div class="small text-muted">Max 1 Minuto (Recomendado)</div>
                <input type="file" name="videoFile" id="fIn" accept="video/*" hidden required>
            </div>

            <button type="submit" class="btn-action">RENDERIZAR</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-4">
        <div class="spinner-border text-danger mb-3"></div>
        <h4 class="fw-bold">PROCESANDO...</h4>
        <p class="text-muted small">Optimizando para servidor ligero.</p>
        <div class="progress-bar"><div id="pFill" class="progress-fill"></div></div>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h4 class="text-success fw-bold mb-3">✅ ¡LISTO!</h4>
        <div class="video-box"><div id="vidWrap" style="width:100%; height:100%;"></div></div>
        <a id="dlBtn" href="#" class="btn-action text-decoration-none d-block">⬇️ DESCARGAR</a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-2 text-decoration-none">Nuevo</button>
    </div>
    
    <a href="?action=viewlog" target="_blank" class="d-block text-center mt-3 text-secondary text-decoration-none small" style="opacity:0.5;">Debug Logs</a>
</div>

<script>
// Logic
const tIn = document.getElementById('tIn');
const fIn = document.getElementById('fIn');
tIn.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
fIn.addEventListener('change', function() { if(this.files[0]) document.getElementById('fName').innerText = '✅ ' + this.files[0].name; });

document.getElementById('vForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if(!fIn.files.length) return alert("Sube video");

    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData(this);
    fd.append('mirrorMode', document.getElementById('mirrorCheck').checked);
    
    const xhr = new XMLHttpRequest();
    
    xhr.upload.addEventListener("progress", (evt) => {
        if (evt.lengthComputable) {
            const p = Math.round((evt.loaded / evt.total) * 50);
            document.getElementById('pFill').style.width = p + '%';
        }
    });

    xhr.onload = () => {
        if (xhr.status === 200) {
            try {
                const res = JSON.parse(xhr.responseText);
                if(res.status === 'success') track(res.jobId);
                else { alert(res.message); location.reload(); }
            } catch { alert("Error respuesta"); location.reload(); }
        } else { alert("Error conexión"); location.reload(); }
    };
    xhr.send(fd);
});

function track(id) {
    let p = 50;
    const fill = document.getElementById('pFill');
    const fake = setInterval(() => { if(p < 95) { p+=0.5; fill.style.width = p+'%'; } }, 1000);

    let attempts = 0;
    const check = setInterval(async () => {
        attempts++;
        if(attempts > 120) { clearInterval(check); clearInterval(fake); alert("Timeout (Memoria llena)"); location.reload(); }
        try {
            const res = await (await fetch(`?action=status&jobId=${id}`)).json();
            if(res.status === 'finished') {
                clearInterval(check); clearInterval(fake);
                fill.style.width = '100%';
                show(res.file);
            } else if(res.status === 'error') {
                clearInterval(check); clearInterval(fake);
                alert(res.message); location.reload();
            }
        } catch {}
    }, 3000);
}

function show(file) {
    document.getElementById('uiProcess').classList.add('hidden');
    document.getElementById('uiResult').classList.remove('hidden');
    document.getElementById('dlBtn').href = '?action=download&file=' + file;
    document.getElementById('vidWrap').innerHTML = `<video width="100%" height="100%" controls autoplay muted loop playsinline><source src="processed/${file}?t=${Date.now()}" type="video/mp4"></video>`;
}
</script>
</body>
</html>
