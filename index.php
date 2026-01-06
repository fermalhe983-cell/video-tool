<?php
// ==========================================
// VIRAL REELS MAKER v32.0 (SINGLE STREAM - ULTRA LOW RAM)
// Estrategia: "Padding" en lugar de "Overlay".
// ==========================================

// Configuración de Supervivencia
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', 1800); 
@ini_set('memory_limit', '512M'); // Bajamos el límite de PHP para dejarle RAM a FFmpeg
@ini_set('display_errors', 0);

// DIRECTORIOS
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

// LIMPIEZA
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
        header('Content-Disposition: attachment; filename="VIRAL_FINAL_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// ---> SUBIDA
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida PHP.']); exit;
    }

    $jobId = uniqid('v32_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status' => 'error', 'message' => 'Error disco.']); exit;
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
    $wrappedText = wordwrap($rawTitle, 16, "\n", true);
    $lines = explode("\n", $wrappedText);
    if (count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    if ($count == 1) { $barH = 240; $fSize = 110; $yPos = [140]; }
    elseif ($count == 2) { $barH = 350; $fSize = 100; $yPos = [100, 215]; }
    else { $barH = 450; $fSize = 80; $yPos = [90, 190, 290]; }

    // --- COMANDO SINGLE STREAM (EL MÁS LIGERO POSIBLE) ---
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($useAudio) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";

    // 1. ESCALADO + PADDING (Todo en una sola pasada)
    // scale: Ajusta el video para que quepa en 1080x1920 (sin deformar)
    // pad: Rellena lo que falte con negro hasta llegar a 1080x1920
    // setsar: Evita errores de píxeles
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1{$mirrorCmd}[base];";
    $lastStream = "[base]";

    // 2. BARRA Y TEXTO
    $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h={$barH}:color=black@0.9:t=fill";
    
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        $xPos = $useLogo ? "(w-text_w)/2+70" : "(w-text_w)/2";
        foreach ($lines as $i => $line) {
            $y = $yPos[$i];
            $filter .= ",drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=4:bordercolor=black:shadowx=2:shadowy=2:x={$xPos}:y={$y}";
        }
    }
    $filter .= "[v_text];"; 
    $lastStream = "[v_text]";

    // 3. LOGO
    if ($useLogo) {
        $logoY = 60 + ($barH/2) - 70;
        $filter .= "[1:v]scale=-1:140[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=40:{$logoY}[v_final_out];";
        $lastStream = "[v_final_out]";
    } else {
        $filter .= "{$lastStream}copy[v_final_out];";
    }

    // 4. AUDIO
    if ($useAudio) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= "[{$mIdx}:a]volume=0.12[bgmusic];[0:a]volume=1.0[voice];[voice][bgmusic]amix=inputs=2:duration=first:dropout_transition=2[a_final_out]";
    } else {
        $filter .= "[0:a]atempo=1.0[a_final_out]";
    }

    // EJECUCIÓN (THREADS=1 ESTRICTO)
    // -threads 1: Evita picos de CPU.
    // -preset ultrafast: Prioriza velocidad sobre compresión.
    // -crf 30: Baja un poco la calidad para aliviar carga.
    $cmd = "nice -n 15 ffmpeg -y $inputs -filter_complex \"$filter\" -map \"$lastStream\" -map \"[a_final_out]\" -c:v libx264 -preset ultrafast -threads 1 -crf 30 -r 30 -c:a aac -ar 44100 -ac 2 -b:a 96k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    file_put_contents($logFile, "\n--- JOB $jobId ---\nCMD: $cmd\n", FILE_APPEND);
    exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName, 'start' => time()]));
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> STATUS CHECK
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . $data['file'];
        
        // Verificamos si existe y pesa > 50KB
        if (file_exists($fullPath) && filesize($fullPath) > 51200) {
            chmod($fullPath, 0777); 
            echo json_encode(['status' => 'finished', 'file' => $data['file']]);
        } else {
            // Si pasan 20 mins, error
            if (time() - $data['start'] > 1200) {
                 echo json_encode(['status' => 'error', 'message' => 'El servidor no pudo procesar este video.']);
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
    <title>Viral v32 (Ultra Light)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background-color: #050505; font-family: 'Inter', sans-serif; color: white; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 15px; }
        .main-card { background: #111; width: 100%; max-width: 550px; border: 1px solid #333; border-radius: 20px; padding: 25px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        .header-title { font-family: 'Anton', sans-serif; text-align: center; color: #ff0055; font-size: 2.5rem; text-transform: uppercase; margin: 0; line-height: 1; }
        .viral-input { background: #000; border: 2px solid #333; color: white; font-family: 'Anton'; font-size: 1.4rem; text-transform: uppercase; padding: 15px; width: 100%; border-radius: 10px; resize: none; }
        .upload-area { border: 2px dashed #444; border-radius: 12px; padding: 25px; text-align: center; margin-top: 20px; cursor: pointer; transition: 0.2s; background: #0a0a0a; }
        .btn-viral { background: #ff0055; color: white; border: none; width: 100%; padding: 18px; font-family: 'Anton'; font-size: 1.5rem; text-transform: uppercase; border-radius: 12px; margin-top: 25px; cursor: pointer; }
        .video-box { background: #000; border: 2px solid #333; border-radius: 15px; overflow: hidden; width: 100%; aspect-ratio: 9/16; margin-bottom: 20px; position: relative; }
        video { width: 100%; height: 100%; object-fit: cover; }
        .form-check-input:checked { background-color: #ff0055; border-color: #ff0055; }
        .hidden { display: none !important; }
        .progress { height: 5px; background: #333; margin-top: 20px; border-radius: 5px; }
        .progress-bar { background: #ff0055; width: 0%; transition: width 0.3s; }
    </style>
</head>
<body>

<div class="main-card">
    <h1 class="header-title">SINGLE STREAM v32</h1>
    <p class="text-center text-secondary small mb-4">Minimum Resource Usage</p>

    <?php if(!file_exists($fontPath)) echo '<div class="alert alert-danger p-1 text-center small mb-2">⚠️ Falta font.ttf</div>'; ?>
    <?php if(!file_exists($audioPath)) echo '<div class="alert alert-warning p-1 text-center small mb-2">⚠️ Falta news.mp3</div>'; ?>

    <div id="uiInput">
        <form id="vForm">
            <div class="mb-3">
                <label class="fw-bold text-danger small mb-1 d-block">1. TÍTULO NOTICIA</label>
                <textarea name="videoTitle" id="tIn" class="viral-input" rows="2" placeholder="TÍTULO AQUÍ..." required></textarea>
            </div>

            <div class="form-check form-switch mb-3 p-3 border border-secondary rounded bg-dark d-flex align-items-center justify-content-between">
                <label class="form-check-label text-white small" for="mirrorCheck"><strong>MODO ESPEJO</strong></label>
                <input class="form-check-input" type="checkbox" id="mirrorCheck" style="width: 3em; height: 1.5em;">
            </div>

            <div class="upload-area" onclick="document.getElementById('fIn').click()">
                <div class="fs-1">📥</div>
                <div class="fw-bold mt-2" id="fileName">Subir Video</div>
                <input type="file" name="videoFile" id="fIn" accept="video/*" hidden required>
            </div>

            <button type="submit" class="btn-viral" id="submitBtn">🚀 PROCESAR</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-5">
        <div class="spinner-border text-danger mb-3" style="width: 3rem; height: 3rem;"></div>
        <h3 class="fw-bold animate-pulse">PROCESANDO...</h3>
        <p class="text-muted small" id="statusText">Subiendo...</p>
        <div class="progress"><div id="pBar" class="progress-bar"></div></div>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h3 class="text-success fw-bold mb-3">✅ VIDEO LISTO</h3>
        <div class="video-box"><div id="vidWrap" style="width:100%; height:100%;"></div></div>
        <a id="dlBtn" href="#" class="btn-viral text-decoration-none d-block">⬇️ DESCARGAR</a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3">Nuevo Video</button>
    </div>
    
    <a href="?action=viewlog" target="_blank" class="d-block text-center mt-4 text-secondary small text-decoration-none">Ver Logs</a>
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
    }
});

document.getElementById('vForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if(!fIn.files.length) return alert("Selecciona video");

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
                    document.getElementById('statusText').innerText = "Renderizando (Single Stream)...";
                    track(res.jobId);
                } else { alert(res.message); location.reload(); }
            } catch(e) { alert("Error respuesta"); location.reload(); }
        } else { alert("Error conexión"); location.reload(); }
    }, false);
    xhr.send(formData);
});

function track(id) {
    let progress = 40;
    const pBar = document.getElementById('pBar');
    const fakeProgress = setInterval(() => { if(progress < 95) { progress += 0.2; pBar.style.width = progress + '%'; } }, 1000);

    let attempts = 0;
    const checker = setInterval(async () => {
        attempts++;
        if(attempts > 600) { clearInterval(checker); clearInterval(fakeProgress); alert("Timeout."); }
        try {
            const res = await (await fetch(`?action=status&jobId=${id}`)).json();
            if(res.status === 'finished') {
                clearInterval(checker); clearInterval(fakeProgress);
                pBar.style.width = '100%';
                show(res.file);
            } else if(res.status === 'error') {
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
