<?php
// ==========================================
// VIRAL REELS MAKER v36.0 (LOW SPEC STABLE - 480p)
// Diseñado específicamente para evitar el error "SIGWINCH" (Crash por falta de RAM).
// ==========================================

// Configuración de Supervivencia
@ini_set('upload_max_filesize', '1024M');
@ini_set('post_max_size', '1024M');
@ini_set('max_execution_time', 300); // 5 minutos máximo
@ini_set('memory_limit', '256M'); // PHP bajo consumo
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

// Crear carpetas
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); chmod($uploadDir, 0777); }
if (!file_exists($processedDir)) { mkdir($processedDir, 0777, true); chmod($processedDir, 0777); }
if (!file_exists($jobsDir)) { mkdir($jobsDir, 0777, true); chmod($jobsDir, 0777); }

// Limpieza agresiva (borra cada 30 mins para liberar disco)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 1800)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> VER LOGS
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
        header('Content-Disposition: attachment; filename="VIRAL_480p_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// ---> SUBIDA
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida.']); exit;
    }

    $jobId = uniqid('v36_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile);
    chmod($inputFile, 0777);

    // Recursos
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    
    // Texto
    $rawTitle = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $rawTitle = mb_strtoupper($rawTitle);
    $wrappedText = wordwrap($rawTitle, 18, "\n", true);
    $lines = explode("\n", $wrappedText);
    if (count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    // --- AJUSTES PARA 480p (ESCALA PEQUEÑA) ---
    // Ancho 480px (Standard Definition)
    if ($count == 1) { $barH = 100; $fSize = 45; $yPos = [60]; }
    elseif ($count == 2) { $barH = 160; $fSize = 40; $yPos = [50, 100]; }
    else { $barH = 220; $fSize = 35; $yPos = [40, 85, 130]; }

    // COMANDO ULTRA LIGERO
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($useAudio) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $filter = "";

    // 1. ESCALADO BÁSICO (480px ancho)
    // Usamos scale=480:-2. Esto mantiene la proporción y asegura dimensiones pares.
    // NO usamos 'pad' ni 'crop' complejos para ahorrar memoria.
    $filter .= "[0:v]scale=480:-2[vscaled];";
    $lastStream = "[vscaled]";

    // 2. DIBUJAR CAJA
    // y=20 (Margen superior pequeño)
    $filter .= "{$lastStream}drawbox=x=0:y=20:w=iw:h={$barH}:color=black@0.9:t=fill";

    // 3. TEXTO
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        foreach ($lines as $i => $line) {
            $y = $yPos[$i];
            // x=(w-text_w)/2 (Centrado horizontal)
            $filter .= ",drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=2:bordercolor=black:shadowx=1:shadowy=1:x=(w-text_w)/2:y={$y}";
        }
    }
    $filter .= "[vtext];";
    $lastStream = "[vtext]";

    // 4. LOGO (Escalado diminuto para 480p)
    if ($useLogo) {
        $logoY = 20 + ($barH/2) - 30; // Centrado en barra
        $filter .= "[1:v]scale=-1:60[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=10:{$logoY}[vfinal_out];";
        $lastStream = "[vfinal_out]";
    } else {
        $filter .= "{$lastStream}copy[vfinal_out];";
    }

    // 5. AUDIO
    if ($useAudio) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= "[{$mIdx}:a]volume=0.15[bgmusic];[0:a]volume=1.0[voice];[voice][bgmusic]amix=inputs=2:duration=first:dropout_transition=2[afinal_out]";
    } else {
        $filter .= "[0:a]atempo=1.0[afinal_out]";
    }

    // EJECUCIÓN (THREADS=1 ESTRICTO)
    // -s 480x854: Forza la resolución de salida si o si.
    $cmd = "nice -n 15 ffmpeg -y $inputs -filter_complex \"$filter\" -map \"$lastStream\" -map \"[afinal_out]\" -c:v libx264 -preset ultrafast -threads 1 -crf 32 -pix_fmt yuv420p -c:a aac -b:a 64k -ac 1 -ar 44100 -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    file_put_contents($logFile, "\n--- JOB $jobId (480p) ---\nCMD: $cmd\n", FILE_APPEND);
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
        
        // Si el archivo existe y pesa más de 5KB (señal de vida)
        if (file_exists($fullPath) && filesize($fullPath) > 5120) {
            chmod($fullPath, 0777); 
            echo json_encode(['status' => 'finished', 'file' => $data['file']]);
        } else {
            // Timeout corto para detectar fallos rápido
            if (time() - $data['start'] > 300) echo json_encode(['status' => 'error', 'message' => 'Servidor saturado.']);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral 480p Stable</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background-color: #000; font-family: 'Inter', sans-serif; color: white; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 15px; }
        .main-card { background: #111; width: 100%; max-width: 480px; border: 1px solid #333; border-radius: 15px; padding: 25px; }
        .header-title { font-family: 'Anton', sans-serif; color: #FFD700; font-size: 2rem; text-transform: uppercase; text-align: center; margin: 0; }
        .sub-title { text-align: center; color: #666; font-size: 0.8rem; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; }
        
        .viral-input { background: #000; border: 2px solid #333; color: white; font-family: 'Anton'; font-size: 1.2rem; padding: 12px; width: 100%; border-radius: 8px; margin-bottom: 15px; }
        .upload-btn { border: 2px dashed #444; border-radius: 10px; padding: 20px; text-align: center; cursor: pointer; background: #0a0a0a; margin-bottom: 20px; }
        .btn-action { background: #FFD700; color: #000; border: none; width: 100%; padding: 15px; font-family: 'Anton'; font-size: 1.2rem; text-transform: uppercase; border-radius: 8px; cursor: pointer; }
        
        .hidden { display: none; }
        .video-box { width: 100%; aspect-ratio: 9/16; background: #000; margin-bottom: 15px; border-radius: 10px; overflow: hidden; border: 1px solid #333; }
        video { width: 100%; height: 100%; object-fit: cover; }
        
        /* Progress */
        .progress-bar { height: 4px; background: #333; width: 100%; margin-top: 15px; border-radius: 2px; overflow: hidden; }
        .progress-fill { height: 100%; background: #FFD700; width: 0%; transition: width 0.3s; }
    </style>
</head>
<body>

<div class="main-card">
    <h1 class="header-title">VIRAL 480p</h1>
    <p class="sub-title">Low Memory Edition</p>

    <div id="uiInput">
        <form id="vForm">
            <label class="small text-secondary fw-bold mb-1">TÍTULO</label>
            <input type="text" name="videoTitle" id="tIn" class="viral-input" placeholder="ESCRIBE AQUÍ..." maxlength="50" required autocomplete="off">

            <div class="upload-btn" onclick="document.getElementById('fIn').click()">
                <div class="fs-1">📂</div>
                <div class="fw-bold mt-2" id="fName">Subir Video</div>
                <input type="file" name="videoFile" id="fIn" accept="video/*" hidden required>
            </div>

            <button type="submit" class="btn-action">RENDERIZAR</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-4">
        <div class="spinner-border text-warning mb-3"></div>
        <h4 class="fw-bold">PROCESANDO...</h4>
        <p class="text-muted small">Optimizando para servidor.</p>
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
    const xhr = new XMLHttpRequest();
    
    // Upload Progress
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
    const fake = setInterval(() => { if(p < 95) { p+=1; fill.style.width = p+'%'; } }, 1000);

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
