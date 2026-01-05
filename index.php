<?php
// ==========================================
// VIRAL REELS MAKER v13.0 (PERSISTENCIA TEMPORAL + VIRAL PSYCHOLOGY)
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

// Crear carpetas
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) mkdir($jobsDir, 0777, true);

// ==========================================
// RECOLECTOR DE BASURA (LIMPIEZA CADA 15 MINUTOS)
// ==========================================
// Esto responde a tu petición: El video vive 15 minutos y luego muere.
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        // 900 segundos = 15 minutos
        if (is_file($file) && (time() - filemtime($file) > 900)) {
            @unlink($file);
        }
    }
}

$action = $_GET['action'] ?? '';

// ---> VER LOGS (DEBUG)
if ($action === 'viewlog') {
    header('Content-Type: text/plain');
    if (file_exists($logFile)) echo file_get_contents($logFile);
    else echo "El archivo de logs está vacío o no existe.";
    exit;
}

// ---> SUBIR Y PROCESAR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error al subir el video.']); exit;
    }

    $jobId = uniqid('v13_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status' => 'error', 'message' => 'Error guardando archivo.']); exit;
    }
    chmod($inputFile, 0777); // Permisos totales

    // --- CONFIGURACIÓN VIRAL ---
    $title = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $title = mb_strtoupper(mb_substr($title, 0, 40)); // Mayúsculas = Grito Visual
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);

    // Inputs
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);

    // --- FILTROS DE PSICOLOGÍA VIRAL ---
    // 1. Fondo Blur (Evita bordes negros aburridos, retiene atención)
    $filter = "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10[bg];";
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
    $lastStream = "[base]";

    // 2. Barra de Impacto (Negro Sólido al 90%)
    // Posición y=60 (Zona segura superior, debajo de la hora del celular)
    $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h=240:color=black@0.9:t=fill[bar];";
    $lastStream = "[bar]";

    // 3. Logo (Branding)
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:160[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=40:100[wlogo];";
        $lastStream = "[wlogo]";
    }

    // 4. Título Gancho (Amarillo #FFD700)
    // El amarillo sobre negro es el contraste más alto posible.
    if ($useFont && !empty($title)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        $xPos = $useLogo ? "(w-text_w)/2+80" : "(w-text_w)/2"; // Centrado inteligente
        $filter .= "{$lastStream}drawtext=fontfile='$fontSafe':text='$title':fontcolor=#FFD700:fontsize=90:borderw=4:bordercolor=black:x=$xPos:y=135[titled];";
        $lastStream = "[titled]";
    }

    // 5. Ajustes Técnicos (Aceleración sutil + Audio Fix)
    $filter .= "{$lastStream}setpts=0.94*PTS[vfinal];[0:a]atempo=1.0638[afinal]";

    // COMANDO ROBUSTO
    // -ar 44100: Arregla videos con audio malo.
    // -pix_fmt yuv420p: Hace que el video se vea en iPhone y Windows.
    // >> $logFile: Guarda errores en archivo visible.
    $cmd = "ffmpeg -y $inputs -filter_complex \"$filter\" -map \"[vfinal]\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -r 30 -pix_fmt yuv420p -c:a aac -ar 44100 -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName]));
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> ESTADO DEL TRABAJO
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . $data['file'];
        
        // Verificamos si existe y tiene tamaño real (>50KB)
        if (file_exists($fullPath) && filesize($fullPath) > 51200) {
            chmod($fullPath, 0777); // Permiso público
            // URL DIRECTA (Sin scripts PHP de por medio)
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
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral Video Maker v13</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { background: #f3f4f6; font-family: 'Inter', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; color: #1f2937; }
        .main-container { background: white; width: 100%; max-width: 500px; padding: 2rem; border-radius: 1.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb; }
        h1 { font-family: 'Anton', sans-serif; text-transform: uppercase; text-align: center; color: #111; margin-bottom: 0.5rem; font-size: 2.2rem; }
        p.subtitle { text-align: center; color: #6b7280; margin-bottom: 2rem; }
        
        .form-label { font-weight: 700; font-size: 0.8rem; text-transform: uppercase; color: #374151; letter-spacing: 0.05em; }
        .viral-input { background: #f9fafb; border: 2px solid #e5e7eb; border-radius: 0.75rem; padding: 0.75rem; width: 100%; font-weight: 600; font-size: 1.1rem; }
        .viral-input:focus { outline: none; border-color: #000; background: white; }
        
        .upload-area { border: 2px dashed #d1d5db; border-radius: 1rem; padding: 2rem; text-align: center; cursor: pointer; transition: 0.2s; background: #f9fafb; margin-top: 1.5rem; }
        .upload-area:hover { border-color: #000; background: white; }
        
        .btn-viral { background: #000; color: #FFD700; width: 100%; padding: 1rem; border-radius: 0.75rem; border: none; font-family: 'Anton'; font-size: 1.25rem; text-transform: uppercase; margin-top: 2rem; cursor: pointer; transition: 0.2s; }
        .btn-viral:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); background: #222; }
        
        .hidden { display: none; }
        .debug-link { display: block; text-align: center; margin-top: 1rem; font-size: 0.8rem; color: #9ca3af; text-decoration: none; }
        
        /* Video Preview */
        .video-box { background: #000; border-radius: 1rem; overflow: hidden; aspect-ratio: 9/16; margin-bottom: 1.5rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2); }
        video { width: 100%; height: 100%; object-fit: cover; }
    </style>
</head>
<body>

<div class="main-container">
    <h1>Viral Factory</h1>
    <p class="subtitle">Creación de Ganchos Automatizada</p>

    <?php if(!file_exists($fontPath)) echo '<div class="alert alert-warning text-center small p-1">⚠️ Falta font.ttf</div>'; ?>
    <?php if(!file_exists($logoPath)) echo '<div class="alert alert-warning text-center small p-1">⚠️ Falta logo.png</div>'; ?>

    <div id="uiInput">
        <form id="vForm">
            <div>
                <label class="form-label">Título Gancho</label>
                <input type="text" name="videoTitle" class="viral-input" placeholder="¡ESTO ES IMPRESIONANTE!" maxlength="40" required autocomplete="off">
            </div>

            <div class="upload-area" onclick="document.getElementById('fIn').click()">
                <div style="font-size: 2rem; margin-bottom: 0.5rem;">📥</div>
                <div class="fw-bold">Subir Video Vertical</div>
                <div class="small text-muted">MP4, MOV</div>
                <input type="file" name="videoFile" id="fIn" accept="video/*" hidden required onchange="this.parentElement.style.borderColor='#000'; this.parentElement.querySelector('.fw-bold').innerText='✅ Video Listo'">
            </div>

            <button type="submit" class="btn-viral">🚀 Generar Video</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-5">
        <div class="spinner-border text-dark mb-4" role="status"></div>
        <h3 class="fw-bold">Renderizando...</h3>
        <p class="text-muted small">Esto puede tomar 1-2 minutos.</p>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h3 class="text-success fw-bold mb-3">¡Video Generado!</h3>
        
        <div class="video-box">
            <div id="vidContainer" style="width:100%; height:100%;"></div>
        </div>

        <a id="dlLink" href="#" class="btn-viral text-decoration-none d-block" target="_blank">
            ⬇️ Descargar MP4
        </a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3 text-decoration-none">Crear Nuevo</button>
    </div>

    <a href="?action=viewlog" target="_blank" class="debug-link">Ver Log de Errores (Si falla)</a>
</div>

<script>
document.getElementById('vForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if(!document.getElementById('fIn').files.length) return alert("Sube un video");

    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData(e.target);
    try {
        const req = await fetch('?action=upload', { method: 'POST', body: fd });
        const res = await req.json();
        
        if(res.status === 'success') {
            track(res.jobId);
        } else {
            alert(res.message); location.reload();
        }
    } catch (err) { alert("Error de conexión"); location.reload(); }
});

function track(id) {
    let t = 0;
    const i = setInterval(async () => {
        t++;
        if(t > 120) { // Timeout 6 mins
            clearInterval(i);
            if(confirm("Tarda mucho. ¿Ver logs?")) window.open('?action=viewlog');
            location.reload();
        }
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
    
    // Enlace directo al archivo
    document.getElementById('dlLink').href = url;
    
    // Preview con timestamp para evitar caché
    const prevUrl = url + '?t=' + Date.now();
    document.getElementById('vidContainer').innerHTML = `<video width="100%" height="100%" controls autoplay muted loop playsinline><source src="${prevUrl}" type="video/mp4"></video>`;
}
</script>

</body>
</html>
