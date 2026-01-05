<?php
// ==========================================
// LAVADORA DE VIDEO PRO v2.0 (Asíncrono)
// ==========================================
// Configuración robusta para VPS
ini_set('display_errors', 0); // Ocultar errores en producción
ini_set('max_execution_time', 0); // Sin límite de tiempo para el script
ini_set('memory_limit', '1024M'); // 1GB de RAM permitida

// Directorios
$baseDir = __DIR__ . '/';
$uploadDir = $baseDir . 'uploads/';
$processedDir = $baseDir . 'processed/';
$jobsDir = $baseDir . 'jobs/'; // Carpeta para estado de trabajos
$logoPath = $baseDir . 'logo.png'; // Tu archivo de marca de agua

// Asegurar que directorios existen con permisos
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) mkdir($jobsDir, 0777, true);

// Verificar si existe el logo
$hasLogo = file_exists($logoPath);

// ==========================================
// BACKEND: API PARA MANEJAR PETICIONES
// ==========================================
header('Content-Type: text/html; charset=utf-8');

$action = $_GET['action'] ?? '';

// ---> ACCIÓN 1: Recibir archivo e iniciar proceso en segundo plano
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error en la subida del archivo.']);
        exit;
    }

    $file = $_FILES['videoFile'];
    $jobId = uniqid('job_');
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $inputFilename = $jobId . '_in.' . $ext;
    $outputFilename = $jobId . '_clean.mp4';
    
    $targetPath = $uploadDir . $inputFilename;
    $outputPath = $processedDir . $outputFilename;
    $jobFile = $jobsDir . $jobId . '.json';

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Calcular hash original
        $originalHash = md5_file($targetPath);

        // ---------------------------------------------------------
        // EL COMANDO PROFESIONAL (Blur + Speed + Pitch + Watermark)
        // ---------------------------------------------------------
        // Definimos la entrada del logo si existe
        $logoInput = $hasLogo ? "-i " . escapeshellarg($logoPath) : "";
        
        // Filtro de marca de agua (si hay logo, lo pone arriba derecha. Si no, no hace nada)
        // overlay=main_w-overlay_w-30:30 -> Posición: Arriba a la derecha con margen de 30px
        $watermarkFilter = $hasLogo ? "[mixed][1:v]overlay=main_w-overlay_w-30:30[watermarked];[watermarked]" : "[mixed]";

        $ffmpegCmd = "nice -n 19 ffmpeg -i " . escapeshellarg($targetPath) . " $logoInput -threads 2 -filter_complex \"[0:v]split=2[bg][fg];[bg]scale=1080:1080:force_original_aspect_ratio=decrease,pad=1080:1080:(ow-iw)/2:(oh-ih)/2,setsar=1,boxblur=20:10[bg_blurred];[fg]scale=1080:1080:force_original_aspect_ratio=decrease[fg_scaled];[bg_blurred][fg_scaled]overlay=(W-w)/2:(H-h)/2[mixed];{$watermarkFilter}setpts=0.94*PTS[v_final];[0:a]atempo=1.0638[a_final]\" -map \"[v_final]\" -map \"[a_final]\" -map_metadata -1 -c:v libx264 -preset veryfast -crf 26 -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputPath) . " > /dev/null 2>&1 &";

        // Ejecutar en segundo plano (nohup ... &)
        exec("nohup $ffmpegCmd");

        // Guardar estado inicial del trabajo
        file_put_contents($jobFile, json_encode([
            'status' => 'processing',
            'original_name' => $file['name'],
            'original_hash' => $originalHash,
            'output_path' => $outputPath,
            'start_time' => time()
        ]));

        echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo mover el archivo subido.']);
    }
    exit;
}

// ---> ACCIÓN 2: Consultar estado del proceso (Polling)
if ($action === 'status' && isset($_GET['jobId'])) {
    header('Content-Type: application/json');
    $jobId = preg_replace('/[^a-z0-9_]/i', '', $_GET['jobId']); // Sanitize
    $jobFile = $jobsDir . $jobId . '.json';

    if (!file_exists($jobFile)) {
        echo json_encode(['status' => 'not_found']);
        exit;
    }

    $jobData = json_decode(file_get_contents($jobFile), true);

    if ($jobData['status'] === 'processing') {
        // Verificar si el archivo de salida ya existe y FFmpeg terminó (el archivo JSON sigue diciendo processing)
        // Una forma simple en VPS es verificar si el archivo de salida existe y no ha cambiado de tamaño en 5 segundos, 
        // pero para este ejemplo simple, asumiremos que si el archivo existe y es jugable, terminó.
        // Método más robusto: checkear si el proceso PID sigue corriendo. 
        // Método simple para EasyPanel: Verificar si el archivo destino existe y tiene tamaño > 0.

        if (file_exists($jobData['output_path']) && filesize($jobData['output_path']) > 102400) { // Mayor a 100KB
            // Pequeña espera de seguridad para asegurar que FFmpeg cerró el archivo
            sleep(2);
            $newHash = md5_file($jobData['output_path']);
            $jobData['status'] = 'finished';
            $jobData['new_hash'] = $newHash;
            $jobData['download_url'] = 'processed/' . basename($jobData['output_path']);
            file_put_contents($jobFile, json_encode($jobData)); // Actualizar estado
        }
    }
    
    // Calcular tiempo transcurrido
    $jobData['elapsed'] = time() - $jobData['start_time'];
    echo json_encode($jobData);
    exit;
}

// ==========================================
// FRONTEND: HTML + JAVASCRIPT
// ==========================================
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lavadora de Video PRO v2</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .main-card { max-width: 800px; margin: 40px auto; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .card-header { background: linear-gradient(135deg, #0d6efd, #0a58ca); color: white; border-radius: 15px 15px 0 0 !important; padding: 20px; }
        .hash-box { background: #e9ecef; padding: 8px; border-radius: 6px; font-family: monospace; font-size: 0.9em; word-break: break-all; }
        .video-container video { width: 100%; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .progress-bar-animated { transition: width 0.5s ease; }
    </style>
</head>
<body>

<div class="container">
    <div class="card main-card">
        <div class="card-header text-center">
            <h3>🚀 Lavadora de Video Profesional v2.0</h3>
            <p class="mb-0 opacity-75">Anti-Copyright + Marca de Agua + Procesamiento en Segundo Plano</p>
        </div>
        <div class="card-body p-4">
            
            <div id="alertBox" class="alert d-none"></div>
            <?php if (!$hasLogo): ?>
                <div class="alert alert-warning small">⚠️ Aviso: No se encontró el archivo <code>logo.png</code>. El video se procesará sin marca de agua. Sube tu logo al repositorio para activarla.</div>
            <?php endif; ?>

            <div id="uploadSection">
                <form id="uploadForm">
                    <div class="mb-4">
                        <label for="videoFile" class="form-label h5">1. Selecciona tu video (MP4/MOV)</label>
                        <input class="form-control form-control-lg" type="file" id="videoFile" name="videoFile" accept="video/mp4,video/quicktime,video/x-m4v" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg fw-bold">
                            ▶️ INICIAR PROCESAMIENTO PROFESIONAL
                        </button>
                    </div>
                </form>
            </div>

            <div id="progressSection" class="d-none text-center py-4">
                <h5 class="mb-3">⏳ Procesando Video en el Servidor...</h5>
                <p class="text-muted mb-2">Esto puede tardar unos minutos dependiendo del tamaño.</p>
                <p class="small">Tiempo transcurrido: <span id="timer">0</span> segundos</p>
                <div class="progress" style="height: 25px;">
                    <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-info" role="progressbar" style="width: 100%">PROCESANDO... NO CIERRES ESTA PESTAÑA</div>
                </div>
            </div>

            <div id="resultSection" class="d-none">
                <div class="alert alert-success fw-bold text-center">✅ ¡Video Procesado Exitosamente!</div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6>🔴 Hash Original (Detectado por FB):</h6>
                        <div id="oldHash" class="hash-box text-danger">...</div>
                    </div>
                    <div class="col-md-6">
                        <h6>🟢 Nuevo Hash Único (Limpio):</h6>
                        <div id="newHash" class="hash-box text-success fw-bold">...</div>
                    </div>
                </div>

                <div class="video-container mb-4">
                    <h6>Vista Previa del Resultado:</h6>
                    <div id="videoPlayerContainer"></div>
                </div>

                <div class="d-grid gap-2">
                    <a id="downloadBtn" href="#" class="btn btn-success btn-lg fw-bold" download>
                        ⬇️ DESCARGAR VIDEO LIMPIO
                    </a>
                    <button onclick="location.reload()" class="btn btn-outline-secondary">🔄 Procesar Otro Video</button>
                </div>
                
                <div class="mt-3 small text-muted">
                    <strong>Cambios aplicados:</strong> Formato cuadrado con fondo blur, aceleración imperceptible (audio/video), corrección de tono y marca de agua (si estaba disponible). Metadata eliminada.
                </div>
            </div>

        </div>
    </div>
</div>

<script>
let jobId = null;
let pollingInterval = null;
let startTime = null;
let timerInterval = null;

const form = document.getElementById('uploadForm');
const uploadSection = document.getElementById('uploadSection');
const progressSection = document.getElementById('progressSection');
const resultSection = document.getElementById('resultSection');
const alertBox = document.getElementById('alertBox');
const timerSpan = document.getElementById('timer');

// 1. Manejar el envío del formulario
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(form);
    
    // Mostrar UI de progreso
    uploadSection.classList.add('d-none');
    progressSection.classList.remove('d-none');
    alertBox.classList.add('d-none');
    startTimer();

    try {
        // Subir el archivo
        const response = await fetch('?action=upload', { method: 'POST', body: formData });
        const data = await response.json();

        if (data.status === 'success') {
            jobId = data.jobId;
            // Empezar a preguntar por el estado cada 3 segundos
            pollingInterval = setInterval(checkStatus, 3000);
        } else {
            throw new Error(data.message || 'Error al subir');
        }
    } catch (error) {
        showError(error.message);
        resetUI();
    }
});

// 2. Función para preguntar el estado al servidor
async function checkStatus() {
    try {
        const response = await fetch(`?action=status&jobId=${jobId}`);
        const data = await response.json();

        if (data.status === 'finished') {
            // ¡Terminó!
            clearInterval(pollingInterval);
            stopTimer();
            showResults(data);
        } else if (data.status === 'not_found') {
             clearInterval(pollingInterval);
             showError("Error: El trabajo no se encontró en el servidor.");
             resetUI();
        }
        // Si sigue 'processing', no hacemos nada, esperamos la siguiente consulta.

    } catch (error) {
        console.error("Error de conexión al verificar estado:", error);
        // No detenemos el polling por un error de red momentáneo, el servidor sigue trabajando.
    }
}

// 3. Mostrar los resultados finales
function showResults(data) {
    progressSection.classList.add('d-none');
    resultSection.classList.remove('d-none');

    document.getElementById('oldHash').textContent = data.original_hash;
    document.getElementById('newHash').textContent = data.new_hash;
    document.getElementById('downloadBtn').href = data.download_url;
    document.getElementById('downloadBtn').download = 'video_limpio_' + data.new_hash.substring(0, 8) + '.mp4';

    // Insertar reproductor de video
    const videoHTML = `
        <video controls autoplay muted loop playsinline>
            <source src="${data.download_url}" type="video/mp4">
            Tu navegador no soporta video HTML5.
        </video>
    `;
    document.getElementById('videoPlayerContainer').innerHTML = videoHTML;
}

// Funciones auxiliares de UI
function showError(msg) {
    alertBox.textContent = msg;
    alertBox.classList.remove('d-none', 'alert-success');
    alertBox.classList.add('alert-danger');
}
function resetUI() {
    uploadSection.classList.remove('d-none');
    progressSection.classList.add('d-none');
    stopTimer();
}
function startTimer() {
    startTime = Date.now();
    timerInterval = setInterval(() => {
        const seconds = Math.floor((Date.now() - startTime) / 1000);
        timerSpan.textContent = seconds;
    }, 1000);
}
function stopTimer() {
    clearInterval(timerInterval);
}
</script>

</body>
</html>
