<?php
// ==========================================
// VIRAL REELS MAKER v10.0 (BLINDADO Y ROBUSTO)
// ==========================================
// Esta versión prioriza que el video SE GENERE SÍ O SÍ.
// Si falla la fuente o el logo, el video se crea igual (sin ellos) para no dar error.

ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');

// 1. CONFIGURACIÓN DE RUTAS (USAMOS RUTAS ABSOLUTAS)
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$logFile = $baseDir . '/ffmpeg_error_log.txt'; // Archivo de registro de errores

// Crear carpetas
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) mkdir($jobsDir, 0777, true);

// Limpieza automática (Archivos > 30 mins)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 1800)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> VER LOGS DE ERROR (SI ALGO FALLA)
if ($action === 'viewlog') {
    if (file_exists($logFile)) echo "<pre>" . file_get_contents($logFile) . "</pre>";
    else echo "No hay errores registrados.";
    exit;
}

// ---> DESCARGAR
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = "$processedDir/$file";
    if (file_exists($filePath)) {
        header('Content-Type: video/mp4');
        header("Content-Disposition: attachment; filename=\"VIRAL_REEL.mp4\"");
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
    die("Error: Archivo no encontrado.");
}

// ---> SUBIR Y PROCESAR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error en la subida.']); exit;
    }

    // Datos del trabajo
    $jobId = uniqid('v10');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFile = "$processedDir/{$jobId}_out.mp4";
    $jobFile = "$jobsDir/$jobId.json";

    // Mover archivo
    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo guardar el video.']); exit;
    }

    // --- CONSTRUCCIÓN INTELIGENTE DEL COMANDO ---
    // Verificamos recursos REALES
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $title = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $title = mb_strtoupper(mb_substr($title, 0, 40));

    // 1. Inputs
    $cmdInputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $cmdInputs .= " -i " . escapeshellarg($logoPath);

    // 2. Filtros
    // Empezamos creando el fondo blur y poniendo el video encima (SIEMPRE SE HACE)
    $filters = "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10[bg];";
    $filters .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
    $filters .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
    $lastStream = "[base]";

    // Agregamos BARRA NEGRA (Siempre, da contraste)
    $filters .= "{$lastStream}drawbox=x=0:y=60:w=iw:h=240:color=black@0.9:t=fill[bar];";
    $lastStream = "[bar]";

    // Agregamos LOGO (Solo si existe el archivo)
    if ($useLogo) {
        $filters .= "[1:v]scale=-1:160[logo];";
        $filters .= "{$lastStream}[logo]overlay=40:100[wlogo];";
        $lastStream = "[wlogo]";
    }

    // Agregamos TEXTO (Solo si existe fuente Y hay texto)
    if ($useFont && !empty($title)) {
        // Escapamos rutas para FFmpeg (esto suele ser el error #1)
        $fontSafe = str_replace('\\', '/', realpath($fontPath)); // Ruta absoluta segura
        // Ajustamos posición x dependiendo de si hay logo o no
        $xPos = $useLogo ? "(w-text_w)/2+80" : "(w-text_w)/2"; 
        
        $filters .= "{$lastStream}drawtext=fontfile='$fontSafe':text='$title':fontcolor=#FFD700:fontsize=85:borderw=4:bordercolor=black:x=$xPos:y=135[titled];";
        $lastStream = "[titled]";
    }

    // Aceleración Viral (Audio y Video)
    $filters .= "{$lastStream}setpts=0.94*PTS[vfinal];[0:a]atempo=1.0638[afinal]";

    // 3. Ejecutar
    // Usamos '2>' para mandar errores al log, y '&' para segundo plano.
    $cmd = "ffmpeg -y $cmdInputs -filter_complex \"$filters\" -map \"[vfinal]\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";
    
    // Escribir comando en log para debug
    file_put_contents($logFile, "\n--- NUEVO TRABAJO $jobId ---\nCMD: $cmd\n", FILE_APPEND);
    
    exec($cmd);

    // Guardar estado
    file_put_contents($jobFile, json_encode([
        'status' => 'processing',
        'file' => basename($outputFile),
        'start' => time()
    ]));

    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> ESTADO
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    
    if (!file_exists($jFile)) { echo json_encode(['status' => 'error']); exit; }
    
    $data = json_decode(file_get_contents($jFile), true);
    $outFile = "$processedDir/" . $data['file'];

    // Verificar si terminó (si el archivo existe y es mayor a 10KB)
    if (file_exists($outFile) && filesize($outFile) > 10240) {
        echo json_encode(['status' => 'finished', 'url' => "?action=download&file=" . $data['file']]);
    } else {
        echo json_encode(['status' => 'processing']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral Maker v10 (Stable)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&display=swap" rel="stylesheet">
    <style>
        body { background: #000; color: #fff; font-family: sans-serif; display: flex; justify-content: center; min-height: 100vh; align-items: center; }
        .card-custom { background: #111; border: 1px solid #333; border-radius: 15px; width: 100%; max-width: 500px; padding: 20px; box-shadow: 0 0 30px rgba(255, 215, 0, 0.1); }
        h1 { font-family: 'Anton', sans-serif; color: #FFD700; text-align: center; text-transform: uppercase; }
        .form-control { background: #222; border: 1px solid #444; color: #FFD700; font-weight: bold; text-transform: uppercase; }
        .form-control:focus { background: #000; color: #fff; border-color: #FFD700; box-shadow: none; }
        .btn-viral { background: #FFD700; color: #000; font-family: 'Anton'; font-size: 1.5rem; width: 100%; border: none; padding: 10px; margin-top: 15px; }
        .btn-viral:hover { background: #ffea00; }
        .hidden { display: none; }
        .debug-btn { position: fixed; bottom: 10px; right: 10px; opacity: 0.5; font-size: 10px; color: #555; text-decoration: none; }
    </style>
</head>
<body>

<div class="card-custom">
    <h1>Viral Factory v10</h1>
    
    <?php if(!file_exists($fontPath)) echo '<div class="alert alert-danger p-1 text-center small">Falta font.ttf (El titulo no saldrá)</div>'; ?>
    <?php if(!file_exists($logoPath)) echo '<div class="alert alert-warning p-1 text-center small">Falta logo.png (El logo no saldrá)</div>'; ?>

    <div id="step1">
        <form id="vForm">
            <div class="mb-3">
                <label>Título Viral (Hook)</label>
                <input type="text" name="videoTitle" class="form-control form-control-lg" placeholder="¡ESTO ES INCREÍBLE!" required>
            </div>
            <div class="mb-3">
                <label>Video Original</label>
                <input type="file" name="videoFile" class="form-control" accept="video/*" required>
            </div>
            <button type="submit" class="btn-viral">CREAR AHORA</button>
        </form>
    </div>

    <div id="step2" class="hidden text-center py-5">
        <div class="spinner-border text-warning" role="status"></div>
        <h3 class="mt-3">Procesando...</h3>
        <p class="text-muted">Esto toma unos minutos.</p>
    </div>

    <div id="step3" class="hidden text-center">
        <h2 class="text-success">¡LISTO!</h2>
        <a id="dlBtn" href="#" class="btn-viral">DESCARGAR VIDEO</a>
        <button onclick="location.reload()" class="btn btn-outline-light btn-sm mt-3">Volver al inicio</button>
    </div>
</div>

<a href="?action=viewlog" target="_blank" class="debug-btn">Ver Log de Errores</a>

<script>
document.getElementById('vForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    document.getElementById('step1').classList.add('hidden');
    document.getElementById('step2').classList.remove('hidden');

    const fd = new FormData(e.target);
    try {
        const req = await fetch('?action=upload', { method: 'POST', body: fd });
        const res = await req.json();
        if(res.status === 'success') check(res.jobId);
        else { alert(res.message); location.reload(); }
    } catch (err) { alert("Error de red"); location.reload(); }
});

function check(id) {
    let t = 0;
    const interval = setInterval(async () => {
        t++;
        if (t > 60) { // 3 minutos max
            clearInterval(interval);
            if(confirm("Tarda mucho. ¿Ver errores?")) window.open('?action=viewlog');
            location.reload();
        }
        try {
            const req = await fetch(`?action=status&jobId=${id}`);
            const res = await req.json();
            if(res.status === 'finished') {
                clearInterval(interval);
                document.getElementById('step2').classList.add('hidden');
                document.getElementById('step3').classList.remove('hidden');
                document.getElementById('dlBtn').href = res.url;
            }
        } catch {}
    }, 3000);
}
</script>

</body>
</html>
