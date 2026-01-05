<?php
// ==========================================
// GENERADOR REELS VIRAL PRO v5.0 (Diseño Luminoso + Título Impacto)
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
// AUTO-LIMPIEZA (GARBAGE COLLECTOR)
// ==========================================
function limpiarBasura($dir) {
    if (!is_dir($dir)) return;
    $files = glob($dir . '*');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file)) {
            // Borrar archivos de más de 20 minutos
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
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        
        // AUTO-DESTRUCCIÓN INMEDIATA
        @unlink($filePath);
        $jobId = str_replace('_reel.mp4', '', $file);
        @unlink($jobsDir . $jobId . '.json');
        exit;
    } else {
        die("Error: El archivo ya fue descargado y eliminado del servidor.");
    }
}

// ---> ACCIÓN: SUBIR Y PROCESAR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida.']); exit;
    }

    $rawTitle = $_POST['videoTitle'] ?? '';
    // Limpieza de título para FFmpeg (permitimos mayúsculas, signos básicos)
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
        
        // 1. Entradas
        $inputs = "-i " . escapeshellarg($targetPath);
        if ($hasLogo) $inputs .= " -i " . escapeshellarg($logoPath);

        // 2. Filtros Pro
        $filter = "";
        
        // Fondo Blur 9:16 (Más oscuro para que resalte el frente)
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10,colorlevels=rimax=0.5:gimax=0.5:bimax=0.5[bg];";
        // Video Principal (Centrado)
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
        // Unir Fondo y Frente
        $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
        $lastStream = "[base]";

        // LOGO PROFESIONAL (Esquina Superior Derecha, Margen 30px)
        if ($hasLogo) {
            // Si el logo es muy grande, lo escalamos a 150px de ancho
            $filter .= "[1:v]scale=150:-1[logo_scaled];";
            $filter .= "{$lastStream}[logo_scaled]overlay=main_w-overlay_w-30:30[watermarked];";
            $lastStream = "[watermarked]";
        }

        // TÍTULO VIRAL IMPACTANTE (Amarillo con Borde Negro, Arriba)
        if ($hasFont && !empty($cleanTitle)) {
            $fontFileSafe = str_replace('\\', '/', $fontPath);
            
            // fontsize=85: Tamaño grande
            // fontcolor=yellow: Color amarillo chillón
            // borderw=6: Borde muy grueso
            // bordercolor=black: Borde negro para contraste máximo
            // y=160: Posición superior, debajo del área de notificaciones del celular
            
            $drawText = "drawtext=fontfile='$fontFileSafe':text='$cleanTitle':fontcolor=yellow:fontsize=85:x=(w-text_w)/2:y=160:borderw=6:bordercolor=black";
            
            $filter .= "{$lastStream}{$drawText}[titled];";
            $lastStream = "[titled]";
        }

        // Aceleración y Anti-Hash
        $filter .= "{$lastStream}setpts=0.94*PTS[v_final];[0:a]atempo=1.0638[a_final]";

        // Comando Final
        $ffmpegCmd = "nice -n 19 ffmpeg $inputs -threads 2 -filter_complex \"$filter\" -map \"[v_final]\" -map \"[a_final]\" -map_metadata -1 -c:v libx264 -preset veryfast -crf 24 -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputPath) . " > /dev/null 2>&1 &";

        exec("nohup $ffmpegCmd");

        file_put_contents($jobFile, json_encode([
            'status' => 'processing',
            'filename' => $outputFilename,
            'start_time' => time()
        ]));

        echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error al recibir archivo.']);
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
        if (file_exists($processedDir . $jobData['filename']) && filesize($processedDir . $jobData['filename']) > 51200) {
            sleep(1); 
            $jobData['status'] = 'finished';
            $jobData['download_url'] = '?action=download&file=' . $jobData['filename'];
            file_put_contents($jobFile, json_encode($jobData));
            
            // Borrar input original
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
    <title>Reels Viral Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* NUEVO DISEÑO LUMINOSO Y PROFESIONAL */
        body { 
            background-color: #f4f7f6; /* Gris claro elegante */
            color: #333; 
            font-family: 'Montserrat', sans-serif; 
        }
        .app-container { max-width: 550px; margin: 40px auto; }
        .card { 
            border: none; 
            border-radius: 20px; 
            box-shadow: 0 15px 35px rgba(0,0,0,0.08); 
            background: #fff;
            overflow: hidden;
        }
        .header-viral { 
            background: linear-gradient(135deg, #FFD700, #FF8C00); /* Gradiente Oro/Naranja */
            padding: 25px; 
            text-align: center; 
            color: #000;
        }
        .header-title {
            font-family: 'Anton', sans-serif;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
        }
        .form-label {
            font-weight: 700;
            color: #FF8C00; /* Naranja para resaltar etiquetas */
            text-transform: uppercase;
            font-size: 0.9rem;
        }
        .form-control {
            background-color: #f8f9fa;
            border: 2px solid #e9ecef;
            padding: 15px;
            font-weight: 600;
            border-radius: 10px;
        }
        .form-control:focus {
            border-color: #FFD700;
            box-shadow: none;
            background-color: #fff;
        }
        .btn-viral { 
            background-color: #000; 
            color: #FFD700; 
            font-family: 'Anton', sans-serif; 
            text-transform: uppercase; 
            border: none; 
            font-size: 1.2rem;
            padding: 15px;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        .btn-viral:hover { 
            background-color: #333; 
            color: #fff; 
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .preview-box { 
            background: #000; 
            border-radius: 12px; 
            overflow: hidden; 
            max-width: 280px; 
            margin: 20px auto; 
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }
        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffecb5;
            color: #664d03;
            border-radius: 10px;
        }
        .spinner-border { border-width: 4px; }
    </style>
</head>
<body>

<div class="container app-container">
    <div class="card">
        <div class="header-viral">
            <h2 class="header-title">⚡ Generador Reels Viral Pro</h2>
            <p class="mb-0 small opacity-75 fw-bold">Edición Impacto + Auto-Limpieza</p>
        </div>
        <div class="card-body p-4 p-md-5">

            <?php if (!$hasFont): ?>
                <div class="alert alert-warning small mb-4 d-flex align-items-center">
                    <span class="me-2 fs-4">⚠️</span> 
                    <div>Falta <code>font.ttf</code>. Sube la fuente "Anton" para activar los títulos amarillos de impacto.</div>
                </div>
            <?php endif; ?>

            <div id="inputSection">
                <form id="uploadForm">
                    <div class="mb-4">
                        <label class="form-label">1. Título Gancho (Mayúsculas Auto)</label>
                        <input type="text" name="videoTitle" class="form-control" placeholder="Ej: ¡ESTO TE VOLARÁ LA CABEZA! 🤯" maxlength="50" required>
                        <div class="form-text text-muted small mt-2">Se mostrará en amarillo con borde negro en la parte superior.</div>
                    </div>

                    <div class="mb-5">
                        <label class="form-label">2. Selecciona tu Video</label>
                        <input class="form-control" type="file" name="videoFile" accept="video/*" required>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-viral">
                            🚀 CREAR VIDEO VIRAL AHORA
                        </button>
                    </div>
                </form>
            </div>

            <div id="progressSection" class="d-none text-center py-5 my-3">
                <div class="spinner-border text-warning mb-4" style="width: 4rem; height: 4rem;" role="status"></div>
                <h4 class="fw-bold mb-3">PROCESANDO...</h4>
                <p class="text-muted px-3">Estamos aplicando el formato viral, quemando el título y limpiando los metadatos. Por favor espera.</p>
            </div>

            <div id="resultSection" class="d-none text-center">
                <div class="mb-4">
                    <span class="badge bg-success fs-5 px-4 py-2 rounded-pill">✅ ¡VIDEO ÉPICO LISTO!</span>
                </div>
                
                <p class="small text-muted fw-bold mb-2">VISTA PREVIA (Baja resolución):</p>
                <div class="preview-box mb-4">
                    <div id="videoContainer"></div>
                </div>

                <div class="d-grid gap-3">
                    <a id="downloadBtn" href="#" class="btn btn-viral">
                        ⬇️ DESCARGAR Y BORRAR
                    </a>
                    <button onclick="location.reload()" class="btn btn-outline-dark fw-bold py-2 rounded-pill">✨ Crear Otro Video</button>
                </div>
                <p class="mt-3 small text-muted fw-bold">⚠️ IMPORTANTE: Al hacer clic en descargar, el archivo se elimina instantáneamente del servidor por seguridad.</p>
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
            throw new Error(data.message);
        }
    } catch (err) {
        alert('Error: ' + err.message);
        location.reload();
    }
});

function trackJob(jobId) {
    let attempts = 0;
    const interval = setInterval(async () => {
        attempts++;
        if(attempts > 120) { // Timeout 6 mins
            clearInterval(interval); alert("Tiempo de espera agotado."); location.reload();
        }
        try {
            const res = await fetch(`?action=status&jobId=${jobId}`);
            const data = await res.json();
            if (data.status === 'finished') {
                clearInterval(interval);
                showResult(data);
            }
        } catch (e) { console.log("Esperando..."); }
    }, 3000);
}

function showResult(data) {
    sections.progress.classList.add('d-none');
    sections.result.classList.remove('d-none');
    
    const btn = document.getElementById('downloadBtn');
    btn.href = data.download_url; 
    
    // Reconstruir ruta para preview (no usa el link de borrado)
    const realPath = 'processed/' + data.filename;
    document.getElementById('videoContainer').innerHTML = `
        <video width="100%" height="100%" controls autoplay muted loop style="object-fit: cover;">
            <source src="${realPath}" type="video/mp4">
        </video>
    `;
}
</script>

</body>
</html>
