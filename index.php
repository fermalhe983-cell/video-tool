<?php
// ==========================================
// VIRAL REELS MAKER v18.0 (3-LINES SUPPORT + META COMPLIANCE)
// ==========================================
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');

// 1. DIRECTORIOS
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/debug_log.txt';

// Crear carpetas
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); chmod($uploadDir, 0777); }
if (!file_exists($processedDir)) { mkdir($processedDir, 0777, true); chmod($processedDir, 0777); }
if (!file_exists($jobsDir)) { mkdir($jobsDir, 0777, true); chmod($jobsDir, 0777); }

// GARBAGE COLLECTOR (10 mins)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 600)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> LOGS
if ($action === 'viewlog') {
    header('Content-Type: text/plain');
    echo file_exists($logFile) ? file_get_contents($logFile) : "Log limpio.";
    exit;
}

// ---> SUBIR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida.']); exit;
    }

    $jobId = uniqid('v18_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status' => 'error', 'message' => 'Error guardando archivo.']); exit;
    }
    chmod($inputFile, 0777);

    // --- RECURSOS ---
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);

    // --- LÓGICA DE TEXTO (3 LÍNEAS) ---
    $rawTitle = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $rawTitle = mb_strtoupper($rawTitle);
    
    // Envolvemos a 15 caracteres para forzar saltos de línea en frases largas
    $wrappedText = wordwrap($rawTitle, 15, "\n", true);
    $lines = explode("\n", $wrappedText);
    
    // Máximo 3 líneas
    if (count($lines) > 3) {
        $lines = array_slice($lines, 0, 3);
        $lines[2] .= "..";
    }
    $lineCount = count($lines);

    // Configuración Dinámica según cantidad de líneas
    // Altura de barra y Tamaño de fuente
    if ($lineCount == 1) {
        $barHeight = 220;
        $fontSize = 90;
        $yPositions = [140]; // Centro
    } elseif ($lineCount == 2) {
        $barHeight = 280;
        $fontSize = 80;
        $yPositions = [110, 200]; // Arriba, Abajo
    } else {
        // 3 LÍNEAS (Tu caso específico)
        $barHeight = 360; // Barra más grande
        $fontSize = 65;   // Letra más pequeña para que quepa
        $yPositions = [100, 180, 260]; // Distribución
    }

    // --- FFMPEG ---
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($useAudio) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $filter = "";
    
    // 1. MEJORA DE CALIDAD (Color Grading + Noise)
    // eq=contrast=1.1:saturation=1.2 -> Colores más vivos (Quality)
    // noise=alls=10 -> Grano sutil (Hash Único)
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10[bg];";
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease,eq=contrast=1.1:saturation=1.2,noise=alls=10:allf=t+u[fg];";
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
    $lastStream = "[base]";

    // 2. BARRA NEGRA DINÁMICA
    $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h={$barHeight}:color=black@0.9:t=fill[bar];";
    $lastStream = "[bar]";

    // 3. LOGO
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:130[logo_s];";
        // Ajustamos posición del logo según la altura de la barra
        $logoY = 60 + ($barHeight/2) - 65; // Centrado vertical en la barra
        $filter .= "{$lastStream}[logo_s]overlay=40:{$logoY}[wlogo];";
        $lastStream = "[wlogo]";
    }

    // 4. TEXTO DINÁMICO
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        $xPos = $useLogo ? "(w-text_w)/2+70" : "(w-text_w)/2";
        
        foreach ($lines as $index => $line) {
            $y = $yPositions[$index];
            $streamName = ($index == $lineCount - 1) ? "titled" : "t{$index}";
            $prevStream = ($index == 0) ? $lastStream : "[t".($index-1)."]";
            
            $draw = "drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fontSize}:borderw=5:bordercolor=black:x={$xPos}:y={$y}";
            $filter .= "{$prevStream}{$draw}[{$streamName}];";
        }
        $lastStream = "[titled]";
    }

    // 5. ACELERACIÓN
    $filter .= "{$lastStream}setpts=0.94*PTS[vfinal];";

    // 6. AUDIO MIX
    if ($useAudio) {
        $musicIndex = $useLogo ? "2" : "1";
        $filter .= "[{$musicIndex}:a]volume=0.15[bgmusic];"; 
        $filter .= "[0:a]volume=1.0[voice];"; 
        $filter .= "[voice][bgmusic]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= "[0:a]atempo=1.0638[afinal]";
    }

    // EJECUCIÓN
    $cmd = "ffmpeg -y $inputs -filter_complex \"$filter\" -map \"[vfinal]\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -r 30 -pix_fmt yuv420p -c:a aac -ar 44100 -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName]));
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
        
        if (file_exists($fullPath) && filesize($fullPath) > 51200) {
            chmod($fullPath, 0777); 
            echo json_encode(['status' => 'finished', 'url' => "processed/" . $data['file']]);
        } else {
            echo json_encode(['status' => 'processing']);
        }
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral News v18</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background-color: #000; font-family: 'Inter', sans-serif; color: white; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .main-card { background: #111; width: 100%; max-width: 500px; border: 2px solid #333; border-radius: 20px; padding: 30px; }
        .header-title { font-family: 'Anton', sans-serif; text-align: center; color: #FFD700; font-size: 2.5rem; text-transform: uppercase; margin: 0; }
        .header-sub { text-align: center; color: #666; font-size: 0.8rem; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 30px; }
        
        .form-label { color: #FFD700; font-weight: 900; text-transform: uppercase; font-size: 0.9rem; }
        .viral-input { background: #000; border: 2px solid #444; color: white; font-family: 'Anton'; font-size: 1.5rem; text-transform: uppercase; padding: 15px; width: 100%; border-radius: 10px; }
        .viral-input:focus { outline: none; border-color: #FFD700; }
        
        .upload-area { border: 2px dashed #444; border-radius: 10px; padding: 20px; text-align: center; margin-top: 20px; cursor: pointer; transition: 0.2s; }
        .upload-area:hover { background: #1a1a1a; border-color: #fff; }

        .btn-viral { background: #FFD700; color: #000; border: none; width: 100%; padding: 20px; font-family: 'Anton'; font-size: 1.5rem; text-transform: uppercase; border-radius: 10px; margin-top: 25px; cursor: pointer; }
        .btn-viral:hover { background: #fff; }

        .video-box { background: #000; border: 2px solid #333; border-radius: 15px; overflow: hidden; width: 100%; aspect-ratio: 9/16; margin-bottom: 20px; }
        video { width: 100%; height: 100%; object-fit: cover; }
        .hidden { display: none; }
    </style>
</head>
<body>

<div class="main-card">
    <h1 class="header-title">Meta Viral v18</h1>
    <p class="header-sub">Calidad Mejorada + Multi-Línea</p>

    <?php if(!file_exists($audioPath)) echo '<div class="alert alert-warning p-1 text-center small">⚠️ Falta news.mp3</div>'; ?>

    <div id="uiInput">
        <form id="vForm">
            <div>
                <label class="form-label">Título de la Noticia</label>
                <input type="text" name="videoTitle" id="tIn" class="viral-input" placeholder="VENEZOLANOS EN CALI ENOJADOS POR TRUMP" maxlength="60" required autocomplete="off">
                <div class="text-end text-muted small mt-1">Soporta hasta 3 líneas automáticas</div>
            </div>

            <div class="upload-area" onclick="document.getElementById('fIn').click()">
                <div class="fs-1">📂</div>
                <div class="fw-bold mt-2">Subir Video</div>
                <input type="file" name="videoFile" id="fIn" accept="video/*" hidden required onchange="this.parentElement.style.borderColor='#FFD700'; this.parentElement.querySelector('.fw-bold').innerText='✅ Video Listo'">
            </div>

            <button type="submit" class="btn-viral">🚀 PROCESAR</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-5">
        <div class="spinner-grow text-warning mb-3"></div>
        <h3 class="fw-bold">Produciendo...</h3>
        <p class="text-muted small">Aplicando color grading, mezcla de audio y textos.</p>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h3 class="text-success fw-bold mb-3">¡Video Listo!</h3>
        <div class="video-box">
            <div id="vidWrap" style="width:100%; height:100%;"></div>
        </div>
        <a id="dlBtn" href="#" class="btn-viral text-decoration-none d-block" target="_blank">⬇️ Descargar MP4</a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3 text-decoration-none">Crear otro</button>
    </div>

    <a href="?action=viewlog" target="_blank" class="d-block text-center mt-3 text-secondary text-decoration-none small">Ver Logs</a>
</div>

<script>
document.getElementById('tIn').addEventListener('input', function() { this.value = this.value.toUpperCase(); });

document.getElementById('vForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if(!document.getElementById('fIn').files.length) return alert("Sube un video");

    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData(e.target);
    try {
        const req = await fetch('?action=upload', { method: 'POST', body: fd });
        const res = await req.json();
        if(res.status === 'success') track(res.jobId);
        else { alert(res.message); location.reload(); }
    } catch { alert("Error de red"); location.reload(); }
});

function track(id) {
    let t = 0;
    const i = setInterval(async () => {
        t++;
        if(t > 120) { clearInterval(i); alert("Tiempo agotado"); location.reload(); }
        try {
            const req = await fetch(`?action=status&jobId=${id}`);
            const res = await req.json();
            if(res.status === 'finished') {
                clearInterval(i);
                show(res.url);
            }
        } catch {}
    }, 3000);
}

function show(url) {
    document.getElementById('uiProcess').classList.add('hidden');
    document.getElementById('uiResult').classList.remove('hidden');
    
    document.getElementById('dlBtn').href = url;
    const finalUrl = url + '?t=' + Date.now();
    document.getElementById('vidWrap').innerHTML = `<video width="100%" height="100%" controls autoplay muted loop playsinline><source src="${finalUrl}" type="video/mp4"></video>`;
}
</script>

</body>
</html>
