<?php
// ==========================================
// GENERADOR REELS VIRAL PRO v5.1 (Fix Preview + Título Sweet Spot)
// ==========================================
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');

// DIRECTORIOS
$baseDir = __DIR__ . '/';
$uploadDir = $baseDir . 'uploads/';
$processedDir = $baseDir . 'processed/';
$jobsDir = $baseDir . 'jobs/'; 
$logoPath = $baseDir . 'logo.png'; 
$fontPath = $baseDir . 'font.ttf'; 

// Crear carpetas y asegurar permisos
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); chmod($uploadDir, 0777); }
if (!file_exists($processedDir)) { mkdir($processedDir, 0777, true); chmod($processedDir, 0777); }
if (!file_exists($jobsDir)) { mkdir($jobsDir, 0777, true); chmod($jobsDir, 0777); }

$hasLogo = file_exists($logoPath);
$hasFont = file_exists($fontPath);

// ==========================================
// AUTO-LIMPIEZA
// ==========================================
function limpiarBasura($dir) {
    if (!is_dir($dir)) return;
    $files = glob($dir . '*');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file)) {
            // Borrar archivos viejos (> 20 mins)
            if ($now - filemtime($file) >= 1200) { 
                @unlink($file);
            }
        }
    }
}
limpiarBasura($uploadDir);
limpiarBasura($processedDir);
limpiarBasura($jobsDir);

// ==========================================
// BACKEND
// ==========================================
$action = $_GET['action'] ?? '';

// ---> ACCIÓN: DESCARGAR Y BORRAR
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = $processedDir . $file;

    if (file_exists($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="REEL_VIRAL_'.date('Ymd_His').'.mp4"');
        header('Expires: 0');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        
        // AUTO-DESTRUCCIÓN
        @unlink($filePath);
        $jobId = str_replace('_reel.mp4', '', $file);
        @unlink($jobsDir . $jobId . '.json');
        exit;
    } else {
        die("El archivo ya fue descargado.");
    }
}

// ---> ACCIÓN: SUBIR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida.']); exit;
    }

    $rawTitle = $_POST['videoTitle'] ?? '';
    // Limpieza de título
    $cleanTitle = mb_strtoupper(str_replace(["'", "\"", "\\", ":"], "", $rawTitle), 'UTF-8');
    $cleanTitle = mb_substr($cleanTitle, 0, 50); 

    $file = $_FILES['videoFile'];
    $jobId = uniqid('j');
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $inputFilename = $jobId . '_i.' . $ext;
    $outputFilename = $jobId . '_reel.mp4';
    
    $targetPath = $uploadDir . $inputFilename;
    $outputPath = $processedDir . $outputFilename;
    $jobFile = $jobsDir . $jobId . '.json';

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        chmod($targetPath, 0777); // Permisos para que ffmpeg lea bien

        // 1. Entradas
        $inputs = "-i " . escapeshellarg($targetPath);
        if ($hasLogo) $inputs .= " -i " . escapeshellarg($logoPath);

        // 2. Filtros
        $filter = "";
        
        // Fondo Blur 9:16
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10,colorlevels=rimax=0.5:gimax=0.5:bimax=0.5[bg];";
        // Frente
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
        // Overlay
        $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
        $lastStream = "[base]";

        // Logo (Esq. Sup. Derecha)
        if ($hasLogo) {
            $filter .= "[1:v]scale=150:-1[logo_scaled];";
            $filter .= "{$lastStream}[logo_scaled]overlay=main_w-overlay_w-30:40[watermarked];";
            $lastStream = "[watermarked]";
        }

        // TÍTULO "SWEET SPOT" (y=250)
        // Bajamos a 250px para evitar la UI superior de Reels/TikTok
        if ($hasFont && !empty($cleanTitle)) {
            $fontFileSafe = str_replace('\\', '/', $fontPath);
            $drawText = "drawtext=fontfile='$fontFileSafe':text='$cleanTitle':fontcolor=yellow:fontsize=90:x=(w-text_w)/2:y=250:borderw=6:bordercolor=black";
            $filter .= "{$lastStream}{$drawText}[titled];";
            $lastStream = "[titled]";
        }

        // Aceleración
        $filter .= "{$lastStream}setpts=0.94*PTS[v_final];[0:a]atempo=1.0638[a_final]";

        // Ejecutar
        $ffmpegCmd = "nice -n 19 ffmpeg $inputs -threads 2 -filter_complex \"$filter\" -map \"[v_final]\" -map \"[a_final]\" -map_metadata -1 -c:v libx264 -preset veryfast -crf 24 -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputPath) . " > /dev/null 2>&1 &";

        exec("nohup $ffmpegCmd");

        file_put_contents($jobFile, json_encode([
            'status' => 'processing',
            'filename' => $outputFilename,
            'start_time' => time()
        ]));

        echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error permisos carpeta.']);
    }
    exit;
}

// ---> ESTADO
if ($action === 'status' && isset($_GET['jobId'])) {
    header('Content-Type: application/json');
    $jobId = preg_replace('/[^a-z0-9_]/i', '', $_GET['jobId']);
    $jobFile = $jobsDir . $jobId . '.json';

    if (!file_exists($jobFile)) { echo json_encode(['status' => 'not_found']); exit; }
    $jobData = json_decode(file_get_contents($jobFile), true);

    if ($jobData['status'] === 'processing') {
        $realPath = $processedDir . $jobData['filename'];
        if (file_exists($realPath) && filesize($realPath) > 51200) {
            // FIX PREVIEW: Dar permisos de lectura pública al archivo generado
            chmod($realPath, 0777); 
            
            sleep(1); 
            $jobData['status'] = 'finished';
            $jobData['download_url'] = '?action=download&file=' . $jobData['filename'];
            file_put_contents($jobFile, json_encode($jobData));
            
            // Limpiar input
            $inputFiles = glob($uploadDir . $jobId . '_i.*');
            if(!empty($inputFiles)) @unlink($inputFiles[0]);
        }
    }
    echo json_encode($jobData);
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reels Viral Pro 5.1</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; color: #333; font-family: 'Montserrat', sans-serif; }
        .app-container { max-width: 550px; margin: 40px auto; }
        .card { border: none; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.08); background: #fff; overflow: hidden; }
        .header-viral { background: linear-gradient(135deg, #FFD700, #FF8C00); padding: 25px; text-align: center; color: #000; }
        .header-title { font-family: 'Anton', sans-serif; text-transform: uppercase; letter-spacing: 1px; }
        .form-control { background-color: #f8f9fa; border: 2px solid #e9ecef; padding: 15px; font-weight: 600; border-radius: 10px; }
        .form-control:focus { border-color: #FFD700; box-shadow: none; background-color: #fff; }
        .btn-viral { background-color: #000; color: #FFD700; font-family: 'Anton', sans-serif; text-transform: uppercase; border: none; font-size: 1.2rem; padding: 15px; border-radius: 10px; width: 100%; transition: all 0.3s; }
        .btn-viral:hover { background-color: #333; transform: translateY(-2px); color: #fff; }
        /* Preview container fix */
        .preview-box { background: #000; border-radius: 12px; overflow: hidden; width: 100%; max-width: 280px; margin: 20px auto; aspect-ratio: 9/16; position: relative; }
        video { width: 100%; height: 100%; object-fit: cover; }
    </style>
</head>
<body>

<div class="container app-container">
    <div class="card">
        <div class="header-viral">
            <h2 class="header-title">⚡ Viral Reels 5.1</h2>
            <p class="mb-0 small opacity-75 fw-bold">Posición Título Corregida + Preview Fix</p>
        </div>
        <div class="card-body p-4">

            <?php if (!$hasFont): ?>
                <div class="alert alert-warning small">⚠️ Falta <code>font.ttf</code> (Sube la fuente Anton).</div>
            <?php endif; ?>

            <div id="inputSection">
                <form id="uploadForm">
                    <div class="mb-4">
                        <label class="form-label fw-bold text-uppercase text-warning">1. Título Gancho</label>
                        <input type="text" name="videoTitle" class="form-control" placeholder="¡ESTO ES INCREÍBLE!" maxlength="50" required>
                    </div>

                    <div class="mb-5">
                        <label class="form-label fw-bold text-uppercase text-warning">2. Video</label>
                        <input class="form-control" type="file" name="videoFile" accept="video/*" required>
                    </div>

                    <button type="submit" class="btn btn-viral">🚀 PROCESAR AHORA</button>
                </form>
            </div>

            <div id="progressSection" class="d-none text-center py-5">
                <div class="spinner-border text-warning mb-4" style="width: 3rem; height: 3rem;" role="status"></div>
                <h4 class="fw-bold">CREANDO VIRAL...</h4>
            </div>

            <div id="resultSection" class="d-none text-center">
                <div class="mb-3"><span class="badge bg-success fs-6">✅ ¡LISTO!</span></div>
                
                <p class="small text-muted fw-bold">VISTA PREVIA:</p>
                <div class="preview-box">
                    <div id="videoContainer" style="width:100%; height:100%;"></div>
                </div>

                <div class="d-grid gap-3 mt-4">
                    <a id="downloadBtn" href="#" class="btn btn-viral">⬇️ DESCARGAR Y BORRAR</a>
                    <button onclick="location.reload()" class="btn btn-outline-dark fw-bold rounded-pill">🔄 Otro Video</button>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
const form = document.getElementById('uploadForm');
const sections = {
    input: document.getElementById('inputSection'),
    progress: document.getElementById('progressSection'),
    result: document.getElementById('resultSection')
};

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    sections.input.classList.add('d-none');
    sections.progress.classList.remove('d-none');
    const formData = new FormData(form);
    try {
        const res = await fetch('?action=upload', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') trackJob(data.jobId);
        else { alert(data.message); location.reload(); }
    } catch (err) { alert('Error de conexión'); location.reload(); }
});

function trackJob(jobId) {
    let attempts = 0;
    const interval = setInterval(async () => {
        attempts++;
        if(attempts > 120) { clearInterval(interval); alert("Tiempo agotado"); location.reload(); }
        try {
            const res = await fetch(`?action=status&jobId=${jobId}`);
            const data = await res.json();
            if (data.status === 'finished') {
                clearInterval(interval);
                showResult(data);
            }
        } catch (e) {}
    }, 3000);
}

function showResult(data) {
    sections.progress.classList.add('d-none');
    sections.result.classList.remove('d-none');
    
    document.getElementById('downloadBtn').href = data.download_url;
    
    // TRUCO: Agregamos timestamp ?t=... para evitar que el navegador use caché vieja
    const timestamp = new Date().getTime();
    const realPath = 'processed/' + data.filename + '?t=' + timestamp;
    
    const container = document.getElementById('videoContainer');
    container.innerHTML = `
        <video width="100%" height="100%" controls autoplay muted playsinline>
            <source src="${realPath}" type="video/mp4">
            Tu navegador no soporta video.
        </video>
    `;
    
    // Forzar play para móviles
    const vid = container.querySelector('video');
    vid.load();
}
</script>

</body>
</html>
