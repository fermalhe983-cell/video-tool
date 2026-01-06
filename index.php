<?php
// ==========================================
// VIRAL REELS MAKER v17.0 (AUTO-WRAP TEXT + BACKGROUND MUSIC)
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
$audioPath = $baseDir . '/news.mp3'; // NUEVO: Archivo de audio de fondo
$logFile = $baseDir . '/debug_log.txt';

// Crear carpetas
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); chmod($uploadDir, 0777); }
if (!file_exists($processedDir)) { mkdir($processedDir, 0777, true); chmod($processedDir, 0777); }
if (!file_exists($jobsDir)) { mkdir($jobsDir, 0777, true); chmod($jobsDir, 0777); }

// RECOLECTOR DE BASURA (10 mins)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 600)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> VER LOGS
if ($action === 'viewlog') {
    header('Content-Type: text/plain');
    echo file_exists($logFile) ? file_get_contents($logFile) : "Log vacío.";
    exit;
}

// ---> SUBIR Y PROCESAR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida.']); exit;
    }

    $jobId = uniqid('v17_');
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

    // --- PROCESAMIENTO DEL TÍTULO (AUTO-WRAP) ---
    // Limpiamos y convertimos a mayúsculas
    $rawTitle = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $rawTitle = mb_strtoupper($rawTitle);
    
    // Dividimos en líneas de máximo 18 caracteres (para que quepan bien grandes)
    // wordwrap devuelve un string con saltos de línea (\n)
    $wrappedText = wordwrap($rawTitle, 18, "\n", true);
    $lines = explode("\n", $wrappedText); // Convertimos a array
    
    // Limitamos a máximo 2 líneas para no tapar el video (Psicología visual)
    if (count($lines) > 2) {
        $lines = array_slice($lines, 0, 2); 
        $lines[1] .= "..."; // Indicador de corte si es muy largo
    }

    // --- COMANDO FFMPEG ---
    // Input 0: Video
    $inputs = "-i " . escapeshellarg($inputFile);
    // Input 1: Logo (si existe)
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    // Input 2: Música de fondo (si existe) - Usamos -stream_loop -1 para que se repita infinita
    if ($useAudio) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    // FILTROS
    $filter = "";
    
    // 1. VIDEO BASE (Blur + Centrado)
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10[bg];";
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
    $lastStream = "[base]";

    // 2. BARRA NEGRA (Aumentada a 280px para soportar 2 líneas de texto)
    $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h=280:color=black@0.9:t=fill[bar];";
    $lastStream = "[bar]";

    // 3. LOGO
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:140[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=40:120[wlogo];";
        $lastStream = "[wlogo]";
    }

    // 4. TEXTO INTELIGENTE (1 o 2 líneas)
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        // Posición X: Si hay logo, movemos a la derecha (+80), si no, centrado exacto.
        $xPos = $useLogo ? "(w-text_w)/2+70" : "(w-text_w)/2";
        
        if (count($lines) === 1) {
            // UNA SOLA LÍNEA (Centrada verticalmente en la barra)
            // y=160 (aprox centro de barra que va de 60 a 340)
            $txt = $lines[0];
            $draw = "drawtext=fontfile='$fontSafe':text='$txt':fontcolor=#FFD700:fontsize=90:borderw=5:bordercolor=black:x=$xPos:y=160";
            $filter .= "{$lastStream}{$draw}[titled];";
            $lastStream = "[titled]";
        } else {
            // DOS LÍNEAS (Una arriba, una abajo)
            // Línea 1
            $txt1 = $lines[0];
            // Línea 2
            $txt2 = $lines[1];
            
            // fontsize=80 (Un poco más pequeña para que quepan dos)
            $draw1 = "drawtext=fontfile='$fontSafe':text='$txt1':fontcolor=#FFD700:fontsize=80:borderw=5:bordercolor=black:x=$xPos:y=110";
            $filter .= "{$lastStream}{$draw1}[t1];";
            
            $draw2 = "drawtext=fontfile='$fontSafe':text='$txt2':fontcolor=#FFD700:fontsize=80:borderw=5:bordercolor=black:x=$xPos:y=200";
            $filter .= "[t1]{$draw2}[titled];";
            $lastStream = "[titled]";
        }
    }

    // 5. VIDEO FINAL (Aceleración)
    $filter .= "{$lastStream}setpts=0.94*PTS[vfinal];";

    // 6. AUDIO MIX (Mezcla Profesional)
    if ($useAudio) {
        // [0:a] es la voz original (Volumen 1.0)
        // [2:a] es la música (Volumen 0.15 = Suave)
        // amix=inputs=2:duration=first (Corta la música cuando termina el video)
        // Map [2:a] as input index depends on if logo exists. 
        // If logo exists, inputs are 0,1,2. Music is 2.
        // If logo NO exists, inputs are 0,1. Music is 1.
        $musicIndex = $useLogo ? "2" : "1";
        
        $filter .= "[{$musicIndex}:a]volume=0.15[bgmusic];"; // Baja el volumen de la música
        $filter .= "[0:a]volume=1.0[voice];"; // Mantiene la voz normal
        $filter .= "[voice][bgmusic]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        // Si no hay música, solo procesamos el audio original
        $filter .= "[0:a]atempo=1.0638[afinal]";
    }

    // EJECUTAR
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
    <title>Viral News Maker</title>
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
    <h1 class="header-title">Viral News</h1>
    <p class="header-sub">Texto Dinámico + Audio Mix</p>

    <?php if(!file_exists($audioPath)) echo '<div class="alert alert-warning p-1 text-center small">⚠️ Falta news.mp3 (No habrá música de fondo)</div>'; ?>

    <div id="uiInput">
        <form id="vForm">
            <div>
                <label class="form-label">Título de la Noticia</label>
                <input type="text" name="videoTitle" id="tIn" class="viral-input" placeholder="¡ESCRIBE ALGO LARGO PARA PROBAR!" maxlength="50" required autocomplete="off">
                <div class="text-end text-muted small mt-1">Se ajustará a 2 líneas si es necesario</div>
            </div>

            <div class="upload-area" onclick="document.getElementById('fIn').click()">
                <div class="fs-1">📂</div>
                <div class="fw-bold mt-2">Subir Video Original</div>
                <input type="file" name="videoFile" id="fIn" accept="video/*" hidden required onchange="this.parentElement.style.borderColor='#FFD700'; this.parentElement.querySelector('.fw-bold').innerText='✅ Video Listo'">
            </div>

            <button type="submit" class="btn-viral">🚀 PROCESAR AHORA</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-5">
        <div class="spinner-grow text-warning mb-3"></div>
        <h3 class="fw-bold">Produciendo...</h3>
        <p class="text-muted small">Mezclando audio y ajustando textos.</p>
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
