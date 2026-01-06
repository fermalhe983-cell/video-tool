<?php
// ==========================================
// VIRAL REELS MAKER v28.0 (ROBUST OVERLAY FIX)
// Solución: Corrige el video en blanco simplificando el centrado universal.
// ==========================================

// 1. CONFIGURACIÓN DE SERVIDOR (Mantenemos soporte para archivos pesados)
@ini_set('upload_max_filesize', '1024M');
@ini_set('post_max_size', '1024M');
@ini_set('max_input_time', 600); 
@ini_set('max_execution_time', 600); 
@ini_set('memory_limit', '2048M');
@ini_set('display_errors', 0);

// DIRECTORIOS
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';

// Crear carpetas y asegurar permisos
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); chmod($uploadDir, 0777); }
if (!file_exists($processedDir)) { mkdir($processedDir, 0777, true); chmod($processedDir, 0777); }
if (!file_exists($jobsDir)) { mkdir($jobsDir, 0777, true); chmod($jobsDir, 0777); }

// LIMPIEZA AUTOMÁTICA (1 hora)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 3600)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> DESCARGA SEGURA
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = "$processedDir/$file";
    if (file_exists($filePath)) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="VIRAL_v28_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        header('Pragma: public');
        readfile($filePath);
        exit;
    }
}

// ---> PROCESAMIENTO
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['videoFile']['error'];
        echo json_encode(['status' => 'error', 'message' => "Error subida PHP: $errCode"]); 
        exit;
    }

    $jobId = uniqid('v28_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status' => 'error', 'message' => 'Fallo al mover archivo.']); exit;
    }
    chmod($inputFile, 0777);

    // --- OPCIONES ---
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';

    // --- TEXTO DINÁMICO ---
    $rawTitle = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $rawTitle = mb_strtoupper($rawTitle);
    $wrappedText = wordwrap($rawTitle, 16, "\n", true);
    $lines = explode("\n", $wrappedText);
    if (count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    // Configuración Barra
    if ($count == 1) { $barH = 240; $fSize = 110; $yPos = [140]; }
    elseif ($count == 2) { $barH = 350; $fSize = 100; $yPos = [100, 215]; }
    else { $barH = 450; $fSize = 80; $yPos = [90, 190, 290]; }

    // --- COMANDO FFMPEG CORREGIDO (FIX BLANK VIDEO) ---
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($useAudio) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";

    // PASO A: Crear FONDO Borroso (Background)
    // Escala para llenar y recorta a 1080x1920
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10{$mirrorCmd}[bg];";

    // PASO B: Preparar VIDEO PRINCIPAL (Foreground) - CORREGIDO
    // 1. Escalar para que quepa DENTRO (decrease)
    // 2. Aplicar efectos visuales (mirror, color, ruido)
    // NOTA: Ya NO usamos 'pad' aquí, eso causaba el video blanco.
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease,setsar=1{$mirrorCmd},eq=contrast=1.05:saturation=1.1,noise=alls=1:allf=t+u[fg];";

    // PASO C: Unir Fondo + Frente (ROBUST CENTERING)
    // Usamos la matemática interna de overlay para centrar automáticamente: (W-w)/2
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
    $lastStream = "[base]";

    // PASO D: Barra Negra
    $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h={$barH}:color=black@0.9:t=fill[bar];";
    $lastStream = "[bar]";

    // PASO E: Logo
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:140[logo_s];";
        $logoY = 60 + ($barH/2) - 70; 
        $filter .= "{$lastStream}[logo_s]overlay=40:{$logoY}[wlogo];";
        $lastStream = "[wlogo]";
    }

    // PASO F: Texto
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        $xPos = $useLogo ? "(w-text_w)/2+70" : "(w-text_w)/2";
        foreach ($lines as $i => $line) {
            $y = $yPos[$i];
            $streamOut = ($i == $count - 1) ? "titled" : "txt{$i}";
            $streamIn = ($i == 0) ? $lastStream : "[txt".($i-1)."]";
            $draw = "drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=4:bordercolor=black:shadowx=2:shadowy=2:x={$xPos}:y={$y}";
            $filter .= "{$streamIn}{$draw}[{$streamOut}];";
        }
        $lastStream = "[titled]";
    }

    // PASO G: Audio Mix
    $filter .= "{$lastStream}setpts=0.95*PTS[vfinal];"; 
    if ($useAudio) {
        $mIdx = $useLogo ? "2" : "1"; 
        $filter .= "[{$mIdx}:a]volume=0.12[bgmusic];[0:a]volume=1.0[voice];[voice][bgmusic]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= "[0:a]atempo=1.0526[afinal]"; 
    }

    // COMANDO FINAL
    // Usamos 'nice' para que no ahogue el servidor
    $cmd = "nice -n 10 ffmpeg -y $inputs -filter_complex \"$filter\" -map \"[vfinal]\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -crf 28 -r 30 -pix_fmt yuv420p -c:a aac -ar 44100 -ac 2 -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " > /dev/null 2>&1 &";

    exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName, 'start' => time()]));
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> CONSULTA DE ESTADO
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . $data['file'];
        
        // Verificamos existencia y tamaño mínimo
        if (file_exists($fullPath) && filesize($fullPath) > 102400) { // >100KB
            chmod($fullPath, 0777); 
            echo json_encode(['status' => 'finished', 'file' => $data['file']]);
        } else {
            // Timeout 15 mins
            if (time() - $data['start'] > 900) {
                 echo json_encode(['status' => 'error', 'message' => 'Timeout: El video es demasiado pesado o falló.']);
            } else {
                 echo json_encode(['status' => 'processing']);
            }
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
    <title>Viral Studio v28 Fixed</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background-color: #050505; font-family: 'Inter', sans-serif; color: white; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 15px; }
        .main-card { background: #111; width: 100%; max-width: 550px; border: 1px solid #333; border-radius: 20px; padding: 25px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        .header-title { font-family: 'Anton', sans-serif; text-align: center; color: #FFD700; font-size: 2.5rem; text-transform: uppercase; margin: 0; line-height: 1; }
        .header-sub { text-align: center; color: #666; font-size: 0.8rem; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 25px; }
        
        .viral-input { background: #000; border: 2px solid #333; color: white; font-family: 'Anton'; font-size: 1.4rem; text-transform: uppercase; padding: 15px; width: 100%; border-radius: 10px; resize: none; }
        .viral-input:focus { outline: none; border-color: #FFD700; }
        
        .upload-area { border: 2px dashed #444; border-radius: 12px; padding: 25px; text-align: center; margin-top: 20px; cursor: pointer; transition: 0.2s; background: #0a0a0a; }
        .upload-area:hover { background: #151515; border-color: #fff; }
        
        .btn-viral { background: #FFD700; color: #000; border: none; width: 100%; padding: 18px; font-family: 'Anton'; font-size: 1.5rem; text-transform: uppercase; border-radius: 12px; margin-top: 25px; cursor: pointer; transition: transform 0.1s; }
        .btn-viral:active { transform: scale(0.98); }

        .video-box { background: #000; border: 2px solid #333; border-radius: 15px; overflow: hidden; width: 100%; aspect-ratio: 9/16; margin-bottom: 20px; position: relative; }
        video { width: 100%; height: 100%; object-fit: cover; }
        
        .form-check-input:checked { background-color: #FFD700; border-color: #FFD700; }
        .hidden { display: none !important; }
        
        .progress { height: 5px; background: #333; margin-top: 20px; border-radius: 5px; overflow: hidden; }
        .progress-bar { background: #FFD700; width: 0%; transition: width 0.3s; }
    </style>
</head>
<body>

<div class="main-card">
    <h1 class="header-title">VIRAL FIX v28</h1>
    <p class="header-sub">Robust Video Processor</p>

    <?php if(!file_exists($fontPath)) echo '<div class="alert alert-danger p-1 text-center small mb-2">⚠️ Falta font.ttf</div>'; ?>
    <?php if(!file_exists($audioPath)) echo '<div class="alert alert-warning p-1 text-center small mb-2">⚠️ Falta news.mp3</div>'; ?>

    <div id="uiInput">
        <form id="vForm">
            <div class="mb-3">
                <label class="fw-bold text-warning small mb-1 d-block">1. TÍTULO NOTICIA</label>
                <textarea name="videoTitle" id="tIn" class="viral-input" rows="2" placeholder="ESCRIBE TU TÍTULO..." required></textarea>
            </div>

            <div class="form-check form-switch mb-4 p-3 border border-secondary rounded bg-dark d-flex align-items-center justify-content-between">
                <label class="form-check-label text-white small" for="mirrorCheck">
                    <strong>MODO ESPEJO</strong> (Opcional)
                </label>
                <input class="form-check-input" type="checkbox" id="mirrorCheck" style="width: 3em; height: 1.5em;">
            </div>

            <div class="upload-area" onclick="document.getElementById('fIn').click()">
                <div class="fs-1">📥</div>
                <div class="fw-bold mt-2" id="fileName">Toca para subir video</div>
                <div class="small text-muted">Soporta cualquier tamaño</div>
                <input type="file" name="videoFile" id="fIn" accept="video/*" hidden required>
            </div>

            <button type="submit" class="btn-viral" id="submitBtn">🚀 PROCESAR</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-5">
        <div class="spinner-border text-warning mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
        <h3 class="fw-bold animate-pulse">PROCESANDO...</h3>
        <p class="text-muted small" id="statusText">Iniciando carga...</p>
        <div class="progress"><div id="pBar" class="progress-bar"></div></div>
        <p class="text-secondary small mt-2">Archivos grandes pueden tardar varios minutos.</p>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h3 class="text-success fw-bold mb-3">✅ ¡VIDEO EXITOSO!</h3>
        <div class="video-box"><div id="vidWrap" style="width:100%; height:100%;"></div></div>
        <a id="dlBtn" href="#" class="btn-viral text-decoration-none d-block">⬇️ DESCARGAR MP4</a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3 text-decoration-none">Crear otro</button>
    </div>
</div>

<script>
const tIn = document.getElementById('tIn');
const fIn = document.getElementById('fIn');
const fileName = document.getElementById('fileName');

tIn.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
fIn.addEventListener('change', function() {
    if(this.files[0]) {
        fileName.innerText = '✅ ' + this.files[0].name;
        fileName.classList.add('text-success');
        this.parentElement.style.borderColor = '#FFD700';
    }
});

document.getElementById('vForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if(!fIn.files.length) return alert("Selecciona un video");

    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');
    
    const formData = new FormData(this);
    formData.append('mirrorMode', document.getElementById('mirrorCheck').checked);

    const xhr = new XMLHttpRequest();
    xhr.upload.addEventListener("progress", function(evt) {
        if (evt.lengthComputable) {
            const percent = Math.round((evt.loaded / evt.total) * 40); 
            document.getElementById('pBar').style.width = percent + '%';
            document.getElementById('statusText').innerText = `Subiendo: ${percent}%...`;
        }
    }, false);

    xhr.addEventListener("load", function() {
        if (xhr.status === 200) {
            try {
                const res = JSON.parse(xhr.responseText);
                if(res.status === 'success') {
                    document.getElementById('statusText').innerText = "Renderizando efectos (Esto demora)...";
                    track(res.jobId);
                } else { alert(res.message); location.reload(); }
            } catch(e) { alert("Error respuesta servidor"); location.reload(); }
        } else { alert("Error conexión"); location.reload(); }
    }, false);
    xhr.send(formData);
});

function track(id) {
    let progress = 40;
    const pBar = document.getElementById('pBar');
    const fakeProgress = setInterval(() => {
        if(progress < 95) { progress += 0.5; pBar.style.width = progress + '%'; }
    }, 1000);

    let attempts = 0;
    const checker = setInterval(async () => {
        attempts++;
        if(attempts > 450) { // 15 mins timeout extendido
            clearInterval(checker); clearInterval(fakeProgress);
            alert("Timeout: El video es muy pesado. Intenta recargar la página en 5 minutos.");
        }
        try {
            const res = await (await fetch(`?action=status&jobId=${id}`)).json();
            if(res.status === 'finished') {
                clearInterval(checker); clearInterval(fakeProgress);
                pBar.style.width = '100%';
                show(res.file);
            } else if (res.status === 'error') {
                clearInterval(checker); clearInterval(fakeProgress);
                alert(res.message); location.reload();
            }
        } catch(e) {}
    }, 2000);
}

function show(filename) {
    document.getElementById('uiProcess').classList.add('hidden');
    document.getElementById('uiResult').classList.remove('hidden');
    document.getElementById('dlBtn').href = '?action=download&file=' + filename;
    document.getElementById('vidWrap').innerHTML = `<video width="100%" height="100%" controls autoplay muted loop playsinline><source src="processed/${filename}?t=${Date.now()}" type="video/mp4"></video>`;
}
</script>
</body>
</html>
