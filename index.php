<?php
// ==========================================
// VIRAL REELS MAKER v15.0 (5-MIN RETENTION + VIRAL PSYCHOLOGY)
// ==========================================
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');

// 1. DIRECTORIOS (Rutas Absolutas)
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$logFile = $baseDir . '/debug_log.txt';

// Crear carpetas si no existen
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) mkdir($jobsDir, 0777, true);

// ==========================================
// RECOLECTOR DE BASURA (Regla de los 5 Minutos)
// ==========================================
// Borra archivos que tengan más de 300 segundos (5 mins) de antigüedad.
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 300)) {
            @unlink($file);
        }
    }
}

$action = $_GET['action'] ?? '';

// ---> DEBUG LOG
if ($action === 'viewlog') {
    header('Content-Type: text/plain');
    if (file_exists($logFile)) echo file_get_contents($logFile);
    else echo "Log limpio.";
    exit;
}

// ---> SUBIR Y PROCESAR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida.']); exit;
    }

    $jobId = uniqid('v15_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status' => 'error', 'message' => 'Error guardando archivo.']); exit;
    }
    chmod($inputFile, 0777); 

    // --- CONFIGURACIÓN PSICOLÓGICA ---
    $title = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $title = mb_strtoupper(mb_substr($title, 0, 40)); // Mayúsculas agresivas
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);

    // Inputs FFmpeg
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);

    // --- FILTROS DE ATENCIÓN ---
    // 1. Fondo Blur 9:16 (Ocupa toda la pantalla del celular)
    $filter = "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10[bg];";
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
    $lastStream = "[base]";

    // 2. Barra Negra Sólida (Contrast)
    // Altura 250px para dar espacio a letras gigantes.
    $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h=250:color=black@0.9:t=fill[bar];";
    $lastStream = "[bar]";

    // 3. Logo (Branding)
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:150[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=40:110[wlogo];";
        $lastStream = "[wlogo]";
    }

    // 4. Título Gancho (Amarillo #FFD700)
    if ($useFont && !empty($title)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        // Ajuste inteligente de centro
        $xPos = $useLogo ? "(w-text_w)/2+70" : "(w-text_w)/2"; 
        
        // Fontsize 95 (Muy grande) + Borde Negro Grueso
        $filter .= "{$lastStream}drawtext=fontfile='$fontSafe':text='$title':fontcolor=#FFD700:fontsize=95:borderw=6:bordercolor=black:x=$xPos:y=140[titled];";
        $lastStream = "[titled]";
    }

    // 5. Ajustes Técnicos
    $filter .= "{$lastStream}setpts=0.94*PTS[vfinal];[0:a]atempo=1.0638[afinal]";

    // Ejecutar FFmpeg (Guardando log para debug)
    $cmd = "ffmpeg -y $inputs -filter_complex \"$filter\" -map \"[vfinal]\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -r 30 -pix_fmt yuv420p -c:a aac -ar 44100 -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName]));
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> ESTADO
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . $data['file'];
        
        // Si el archivo existe y pesa más de 50KB
        if (file_exists($fullPath) && filesize($fullPath) > 51200) {
            chmod($fullPath, 0777); // Permiso público para que el navegador lo vea
            echo json_encode(['status' => 'finished', 'file' => $data['file']]);
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
    <title>Viral Maker v15</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        /* ESTÉTICA DARK MODE (Alta Atención) */
        body { 
            background-color: #050505; 
            font-family: 'Inter', sans-serif; 
            color: white; 
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
        }
        .app-card {
            background: #111;
            width: 100%; max-width: 500px;
            border: 2px solid #333;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 0 50px rgba(255, 215, 0, 0.1);
        }
        .header-title {
            font-family: 'Anton', sans-serif;
            text-align: center;
            color: #FFD700;
            font-size: 2.5rem;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .header-sub { text-align: center; color: #666; font-size: 0.8rem; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 30px; }
        
        .form-label { color: #FFD700; font-weight: 900; text-transform: uppercase; font-size: 0.9rem; }
        .viral-input {
            background: #000; border: 2px solid #444; color: white;
            font-family: 'Anton'; font-size: 1.5rem; text-transform: uppercase;
            padding: 15px; width: 100%; border-radius: 10px;
        }
        .viral-input:focus { outline: none; border-color: #FFD700; }
        
        .upload-area {
            border: 2px dashed #444; border-radius: 10px; padding: 20px;
            text-align: center; margin-top: 20px; cursor: pointer; transition: 0.2s;
        }
        .upload-area:hover { background: #1a1a1a; border-color: #fff; }

        .btn-viral {
            background: #FFD700; color: #000; border: none; width: 100%;
            padding: 20px; font-family: 'Anton'; font-size: 1.5rem; text-transform: uppercase;
            border-radius: 10px; margin-top: 25px; cursor: pointer;
        }
        .btn-viral:hover { background: #fff; }

        /* VIDEO PREVIEW */
        .video-container {
            background: #000; border: 2px solid #333; border-radius: 15px;
            overflow: hidden; width: 100%; aspect-ratio: 9/16; margin-bottom: 20px;
        }
        video { width: 100%; height: 100%; object-fit: cover; }
        
        .hidden { display: none; }
    </style>
</head>
<body>

<div class="app-card">
    <h1 class="header-title">Viral Maker</h1>
    <p class="header-sub">Retención + Diseño de Impacto</p>

    <?php if(!file_exists($fontPath)) echo '<div class="alert alert-danger p-1 text-center small">Falta font.ttf</div>'; ?>
    <?php if(!file_exists($logoPath)) echo '<div class="alert alert-danger p-1 text-center small">Falta logo.png</div>'; ?>

    <div id="uiInput">
        <form id="vForm">
            <div>
                <label class="form-label">1. Título Gancho</label>
                <input type="text" name="videoTitle" id="tInput" class="viral-input" placeholder="¡ESTO ES BRUTAL!" maxlength="40" required autocomplete="off">
            </div>

            <div class="upload-area" onclick="document.getElementById('fIn').click()">
                <div class="fs-1">📂</div>
                <div class="fw-bold mt-2">2. Subir Video</div>
                <input type="file" name="videoFile" id="fIn" accept="video/*" hidden required onchange="this.parentElement.style.borderColor='#FFD700'; this.parentElement.querySelector('.fw-bold').innerText='✅ Video Listo'">
            </div>

            <button type="submit" class="btn-viral">🚀 Crear Video</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-5">
        <div class="spinner-grow text-warning mb-3"></div>
        <h3 class="fw-bold">Renderizando...</h3>
        <p class="text-muted small">Aplicando filtros de atención.</p>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h3 class="text-success fw-bold mb-3">¡Video Listo!</h3>
        
        <div class="video-container">
            <div id="vidWrap" style="width:100%; height:100%;"></div>
        </div>

        <a id="dlBtn" href="#" class="btn-viral text-decoration-none d-block" download>
            ⬇️ Descargar MP4
        </a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3 text-decoration-none">Crear otro (Se borra en 5 min)</button>
    </div>

    <a href="?action=viewlog" target="_blank" class="d-block text-center mt-3 text-secondary text-decoration-none small">Ver Logs</a>
</div>

<script>
// Mayúsculas Automáticas
document.getElementById('tInput').addEventListener('input', function() { this.value = this.value.toUpperCase(); });

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
                show(res.file);
            }
        } catch {}
    }, 3000);
}

function show(filename) {
    document.getElementById('uiProcess').classList.add('hidden');
    document.getElementById('uiResult').classList.remove('hidden');
    
    // URL DIRECTA a la carpeta processed (Mucho más rápido y compatible)
    const url = 'processed/' + filename;
    
    document.getElementById('dlBtn').href = url;
    
    // Vista previa con timestamp para forzar recarga
    const prevUrl = url + '?t=' + Date.now();
    document.getElementById('vidWrap').innerHTML = `<video width="100%" height="100%" controls autoplay muted playsinline><source src="${prevUrl}" type="video/mp4"></video>`;
}
</script>

</body>
</html>
