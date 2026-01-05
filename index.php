<?php
// ==========================================
// GENERADOR DE REELS PRO v4.0 (Auto-Limpieza + Diseño News)
// ==========================================
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');

// CONFIGURACIÓN
$baseDir = __DIR__ . '/';
$uploadDir = $baseDir . 'uploads/';
$processedDir = $baseDir . 'processed/';
$jobsDir = $baseDir . 'jobs/'; 
$logoPath = $baseDir . 'logo.png'; 
$fontPath = $baseDir . 'font.ttf'; 

// Crear carpetas
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) mkdir($jobsDir, 0777, true);

$hasLogo = file_exists($logoPath);
$hasFont = file_exists($fontPath);

// ==========================================
// FUNCIÓN DE LIMPIEZA AUTOMÁTICA (GARBAGE COLLECTOR)
// ==========================================
// Borra archivos creados hace más de 30 minutos para que el servidor no explote
function limpiarBasura($dir) {
    if (!is_dir($dir)) return;
    $files = glob($dir . '*');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file)) {
            // Si el archivo tiene más de 30 minutos (1800 segundos), chao
            if ($now - filemtime($file) >= 1800) { 
                @unlink($file);
            }
        }
    }
}
// Ejecutar limpieza en cada carga
limpiarBasura($uploadDir);
limpiarBasura($processedDir);
limpiarBasura($jobsDir);


// ==========================================
// BACKEND
// ==========================================
$action = $_GET['action'] ?? '';

// ---> ACCIÓN: DESCARGAR Y BORRAR (Auto-Destrucción)
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = $processedDir . $file;

    if (file_exists($filePath)) {
        // Forzar descarga
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="REEL_LIMPIO_'.rand(1000,9999).'.mp4"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        
        // Enviar archivo
        readfile($filePath);

        // BORRAR EL ARCHIVO INMEDIATAMENTE DESPUÉS DE ENVIARLO
        @unlink($filePath);
        
        // También intentar borrar el json del trabajo si existe
        $jobId = str_replace('_reel.mp4', '', $file);
        @unlink($jobsDir . $jobId . '.json');
        exit;
    } else {
        die("El archivo ya fue borrado o no existe.");
    }
}

// ---> ACCIÓN: SUBIR Y PROCESAR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida.']); exit;
    }

    $rawTitle = $_POST['videoTitle'] ?? '';
    // Filtro para permitir tildes y signos, pero quitar comillas que rompen FFmpeg
    $cleanTitle = str_replace(["'", "\"", "\\"], "", $rawTitle);
    $cleanTitle = substr($cleanTitle, 0, 60); 

    $file = $_FILES['videoFile'];
    $jobId = uniqid('job_');
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $inputFilename = $jobId . '_in.' . $ext;
    $outputFilename = $jobId . '_reel.mp4';
    
    $targetPath = $uploadDir . $inputFilename;
    $outputPath = $processedDir . $outputFilename;
    $jobFile = $jobsDir . $jobId . '.json';

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        
        // 1. Entradas
        $inputs = "-i " . escapeshellarg($targetPath);
        if ($hasLogo) $inputs .= " -i " . escapeshellarg($logoPath);

        // 2. Filtros
        $filter = "";
        
        // Fondo Blur 9:16
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10[bg];";
        // Video Principal
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
        // Overlay
        $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
        $lastStream = "[base]";

        // Logo (Arriba Derecha)
        if ($hasLogo) {
            $filter .= "{$lastStream}[1:v]overlay=main_w-overlay_w-40:60[watermarked];";
            $lastStream = "[watermarked]";
        }

        // TÍTULO ESTILO "BREAKING NEWS" (Fondo Rojo, Letra Blanca)
        if ($hasFont && !empty($cleanTitle)) {
            $fontFileSafe = str_replace('\\', '/', $fontPath);
            
            // box=1: Activa la caja de fondo
            // boxcolor=#D00000@1: Color Rojo Intenso (Opacidad 100%)
            // boxborderw=20: Margen (padding) alrededor del texto
            // y=180: Posición vertical (un poco más abajo del borde superior)
            
            $drawText = "drawtext=fontfile='$fontFileSafe':text='$cleanTitle':fontcolor=white:fontsize=75:x=(w-text_w)/2:y=180:box=1:boxcolor=#CC0000@1:boxborderw=15";
            
            $filter .= "{$lastStream}{$drawText}[titled];";
            $lastStream = "[titled]";
        }

        // Acelerar y Audio Fix
        $filter .= "{$lastStream}setpts=0.94*PTS[v_final];[0:a]atempo=1.0638[a_final]";

        // Ejecutar
        $ffmpegCmd = "nice -n 19 ffmpeg $inputs -threads 2 -filter_complex \"$filter\" -map \"[v_final]\" -map \"[a_final]\" -map_metadata -1 -c:v libx264 -preset veryfast -crf 26 -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputPath) . " > /dev/null 2>&1 &";

        exec("nohup $ffmpegCmd");

        // NOTA: Borraremos el archivo ORIGINAL (el subido) inmediatamente para ahorrar espacio.
        // Solo guardamos la referencia para el proceso, pero podemos borrar el input en unos segundos.
        // Por seguridad, dejemos que el Garbage Collector lo borre en 30 mins, es más seguro.

        file_put_contents($jobFile, json_encode([
            'status' => 'processing',
            'output_path' => $outputPath,
            'filename' => $outputFilename, // Guardamos nombre para link de descarga
            'start_time' => time()
        ]));

        echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error mover archivo.']);
    }
    exit;
}

// ---> ESTADO DEL TRABAJO
if ($action === 'status' && isset($_GET['jobId'])) {
    header('Content-Type: application/json');
    $jobId = preg_replace('/[^a-z0-9_]/i', '', $_GET['jobId']);
    $jobFile = $jobsDir . $jobId . '.json';

    if (!file_exists($jobFile)) { echo json_encode(['status' => 'not_found']); exit; }
    $jobData = json_decode(file_get_contents($jobFile), true);

    if ($jobData['status'] === 'processing') {
        if (file_exists($jobData['output_path']) && filesize($jobData['output_path']) > 102400) {
            sleep(1); 
            $jobData['status'] = 'finished';
            // El link de descarga apunta a la ACCIÓN DE DESCARGAR (que borra el archivo)
            $jobData['download_url'] = '?action=download&file=' . $jobData['filename'];
            file_put_contents($jobFile, json_encode($jobData));
            
            // TRUCO: Ya podemos borrar el archivo ORIGINAL subido (el input) para ahorrar espacio
            $inputFileGlob = glob($uploadDir . $jobId . '_in.*');
            if(!empty($inputFileGlob)) @unlink($inputFileGlob[0]);
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
    <title>Generador Reels Viral</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&display=swap" rel="stylesheet">
    <style>
        body { background-color: #121212; color: #fff; font-family: 'Segoe UI', sans-serif; }
        .card { background-color: #1e1e1e; border: 1px solid #333; border-radius: 15px; }
        .header-viral { background: linear-gradient(90deg, #D00000, #FF0000); padding: 20px; text-align: center; font-family: 'Anton', sans-serif; letter-spacing: 1px; }
        .form-control { background-color: #2d2d2d; border: 1px solid #444; color: white; }
        .form-control:focus { background-color: #333; color: white; border-color: #D00000; box-shadow: 0 0 0 0.25rem rgba(208, 0, 0, 0.25); }
        .btn-viral { background-color: #fff; color: #D00000; font-weight: bold; font-family: 'Anton', sans-serif; text-transform: uppercase; border: none; }
        .btn-viral:hover { background-color: #e0e0e0; color: #900000; }
        .preview-box { background: black; border-radius: 10px; overflow: hidden; max-width: 300px; margin: 0 auto; border: 2px solid #333; }
        .alert-warning { background-color: #332b00; border-color: #665500; color: #ffdd00; }
    </style>
</head>
<body>

<div class="container mt-5" style="max-width: 600px;">
    <div class="card shadow-lg overflow-hidden">
        <div class="header-viral">
            <h1 class="mb-0">🔥 REELS MAKER VIRAL</h1>
            <p class="mb-0 small text-white-50">Edición Automática + Anti-Copyright</p>
        </div>
        <div class="card-body p-4">

            <div id="alertBox" class="alert d-none"></div>
            <?php if (!$hasFont): ?>
                <div class="alert alert-warning small">⚠️ <strong>FALTA FUENTE:</strong> Sube el archivo <code>font.ttf</code> (Recomiendo "Anton") para activar los títulos rojos.</div>
            <?php endif; ?>

            <div id="inputSection">
                <form id="uploadForm">
                    <div class="mb-3">
                        <label class="form-label text-uppercase fw-bold text-danger">1. Título Gancho (Breaking News)</label>
                        <input type="text" name="videoTitle" class="form-control form-control-lg" placeholder="Ej: ¡IMÁGENES IMPACTANTES!" maxlength="40" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-uppercase fw-bold text-danger">2. Video Original</label>
                        <input class="form-control" type="file" name="videoFile" accept="video/*" required>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-viral btn-lg py-3">
                            🚀 PROCESAR VIDEO AHORA
                        </button>
                    </div>
                    <p class="text-center mt-3 small text-muted">
                        * El video se borrará automáticamente del servidor al descargarlo.
                    </p>
                </form>
            </div>

            <div id="progressSection" class="d-none text-center py-5">
                <div class="spinner-border text-danger mb-3" style="width: 3rem; height: 3rem;" role="status"></div>
                <h4 class="fw-bold">PROCESANDO...</h4>
                <p class="text-white-50">Creando fondo, quemando título y limpiando hash.</p>
            </div>

            <div id="resultSection" class="d-none text-center">
                <div class="alert alert-success fw-bold">✅ ¡VIDEO LISTO!</div>
                
                <p class="small text-muted">Vista previa (Baja Calidad):</p>
                <div class="preview-box mb-4">
                    <div id="videoContainer"></div>
                </div>

                <div class="d-grid gap-2">
                    <a id="downloadBtn" href="#" class="btn btn-viral btn-lg py-3">
                        ⬇️ DESCARGAR Y BORRAR DEL SERVIDOR
                    </a>
                    <button onclick="location.reload()" class="btn btn-outline-light btn-sm">🔄 Crear Otro</button>
                </div>
                <p class="mt-2 small text-white-50">⚠️ Al hacer clic en descargar, el video se elimina del servidor.</p>
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
        
        if (data.status === 'success') {
            trackJob(data.jobId);
        } else {
            alert('Error: ' + data.message);
            location.reload();
        }
    } catch (err) {
        alert('Error de conexión');
        location.reload();
    }
});

function trackJob(jobId) {
    const interval = setInterval(async () => {
        try {
            const res = await fetch(`?action=status&jobId=${jobId}`);
            const data = await res.json();

            if (data.status === 'finished') {
                clearInterval(interval);
                showResult(data);
            }
        } catch (e) {
            console.log("Esperando servidor...");
        }
    }, 3000);
}

function showResult(data) {
    sections.progress.classList.add('d-none');
    sections.result.classList.remove('d-none');
    
    const btn = document.getElementById('downloadBtn');
    btn.href = data.download_url; // Este link activa el borrado automático

    // El reproductor usa el archivo temporalmente antes de que lo borres
    // Nota: El preview NO borra el archivo, solo el botón de descargar.
    // Necesitamos reconstruir la ruta real para el preview, ya que data.download_url es un script PHP.
    const realPath = 'processed/' + data.filename;
    
    document.getElementById('videoContainer').innerHTML = `
        <video width="100%" controls autoplay muted loop>
            <source src="${realPath}" type="video/mp4">
        </video>
    `;
}
</script>

</body>
</html>
