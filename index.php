<?php
// ==========================================
// REELS VIRAL MAKER v7.0 (Psychology Edition)
// ==========================================
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');

// 1. CONFIGURACIÓN DE RUTAS
$baseDir = __DIR__ . '/';
$uploadDir = $baseDir . 'uploads/';
$processedDir = $baseDir . 'processed/';
$jobsDir = $baseDir . 'jobs/'; 
$logoPath = $baseDir . 'logo.png'; 
$fontPath = $baseDir . 'font.ttf'; 

// Crear carpetas con permisos full
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); chmod($uploadDir, 0777); }
if (!file_exists($processedDir)) { mkdir($processedDir, 0777, true); chmod($processedDir, 0777); }
if (!file_exists($jobsDir)) { mkdir($jobsDir, 0777, true); chmod($jobsDir, 0777); }

$hasLogo = file_exists($logoPath);
$hasFont = file_exists($fontPath);

// 2. GARBAGE COLLECTOR (Limpieza Automática)
function limpiarBasura($dir) {
    if (!is_dir($dir)) return;
    $files = glob($dir . '*');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file) >= 1200)) { // 20 mins
            @unlink($file);
        }
    }
}
limpiarBasura($uploadDir);
limpiarBasura($processedDir);
limpiarBasura($jobsDir);

// 3. LÓGICA DEL BACKEND
$action = $_GET['action'] ?? '';

// ---> DESCARGAR Y ELIMINAR
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = $processedDir . $file;
    if (file_exists($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="VIRAL_HOOK_'.date('YmdHis').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        @unlink($filePath); // Borrar video
        $jobId = str_replace('_reel.mp4', '', $file);
        @unlink($jobsDir . $jobId . '.json'); // Borrar registro
        exit;
    } else { die("El video ya fue descargado o expiró."); }
}

// ---> SUBIR Y PROCESAR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error en archivo.']); exit;
    }

    $rawTitle = $_POST['videoTitle'] ?? '';
    // TRUCO PSICOLÓGICO: Forzar MAYÚSCULAS (Impacto visual)
    $cleanTitle = mb_strtoupper(str_replace(["'", "\"", "\\", ":"], "", $rawTitle), 'UTF-8');
    // Limitamos a 35 caracteres. Títulos largos NO son virales. 
    // "MENOS ES MÁS" en redes sociales.
    $cleanTitle = mb_substr($cleanTitle, 0, 35); 

    $file = $_FILES['videoFile'];
    $jobId = uniqid('v7');
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $inputFilename = $jobId . '_i.' . $ext;
    $outputFilename = $jobId . '_reel.mp4';
    $targetPath = $uploadDir . $inputFilename;
    $outputPath = $processedDir . $outputFilename;
    $jobFile = $jobsDir . $jobId . '.json';

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        chmod($targetPath, 0777);

        // Inputs
        $inputs = "-i " . escapeshellarg($targetPath);
        if ($hasLogo) $inputs .= " -i " . escapeshellarg($logoPath);

        // --- FILTROS FFMPEG (La Magia) ---
        $filter = "";
        
        // A. FONDO (Blur oscuro para resaltar texto)
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10,colorlevels=rimax=0.5:gimax=0.5:bimax=0.5[bg];";
        
        // B. VIDEO PRINCIPAL (Centrado)
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
        $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
        $lastStream = "[base]";

        // C. BARRA DE IMPACTO (Header)
        // Aumentamos altura a 220px para que el texto respire y sea MASIVO.
        // Opacidad 0.85 (casi sólido) para lectura perfecta.
        $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h=220:color=black@0.85:t=fill[with_bar];";
        $lastStream = "[with_bar]";

        // D. LOGO (Marca Personal)
        if ($hasLogo) {
            // Logo más grande (160px) alineado a la izquierda
            $filter .= "[1:v]scale=-1:160[logo_scaled];";
            $filter .= "{$lastStream}[logo_scaled]overlay=40:90[watermarked];";
            $lastStream = "[watermarked]";
        }

        // E. TÍTULO VIRAL (El Gancho)
        if ($hasFont && !empty($cleanTitle)) {
            $fontFileSafe = str_replace('\\', '/', $fontPath);
            // fontsize=95: GIGANTE.
            // fontcolor=yellow: El color que más rápido procesa el ojo humano.
            // borderw=5: Borde negro sólido para contraste.
            // y=120: Centrado verticalmente en la barra nueva de 220px.
            // x=(w-text_w)/2 + 70: Desplazado a la derecha para dejar sitio al logo.
            
            $drawText = "drawtext=fontfile='$fontFileSafe':text='$cleanTitle':fontcolor=#FFFF00:fontsize=95:borderw=5:bordercolor=black:x=(w-text_w)/2+80:y=120";
            
            $filter .= "{$lastStream}{$drawText}[titled];";
            $lastStream = "[titled]";
        }

        // F. ACELERACIÓN (Retención de audiencia)
        $filter .= "{$lastStream}setpts=0.92*PTS[v_final];[0:a]atempo=1.086[a_final]";

        // EJECUCIÓN
        $ffmpegCmd = "nice -n 19 ffmpeg $inputs -threads 2 -filter_complex \"$filter\" -map \"[v_final]\" -map \"[a_final]\" -map_metadata -1 -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputPath) . " > /dev/null 2>&1 &";

        exec("nohup $ffmpegCmd");

        file_put_contents($jobFile, json_encode(['status' => 'processing', 'filename' => $outputFilename, 'start_time' => time()]));
        echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    } else { echo json_encode(['status' => 'error', 'message' => 'Error subida.']); }
    exit;
}

// ---> ESTADO DEL PROCESO
if ($action === 'status' && isset($_GET['jobId'])) {
    header('Content-Type: application/json');
    $jobId = preg_replace('/[^a-z0-9_]/i', '', $_GET['jobId']);
    $jobFile = $jobsDir . $jobId . '.json';
    if (!file_exists($jobFile)) { echo json_encode(['status' => 'not_found']); exit; }
    $jobData = json_decode(file_get_contents($jobFile), true);

    if ($jobData['status'] === 'processing') {
        $realPath = $processedDir . $jobData['filename'];
        if (file_exists($realPath) && filesize($realPath) > 51200) {
            chmod($realPath, 0777); // Fix para que el navegador pueda leerlo
            sleep(1); 
            $jobData['status'] = 'finished';
            $jobData['download_url'] = '?action=download&file=' . $jobData['filename'];
            file_put_contents($jobFile, json_encode($jobData));
            // Borrar el archivo crudo de entrada para ahorrar espacio
            $iF = glob($uploadDir . $jobId . '_i.*'); if(!empty($iF)) @unlink($iF[0]);
        }
    }
    echo json_encode($jobData);
    exit;
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral Hooks Maker v7</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;800&display=swap" rel="stylesheet">
    <style>
        /* ESTÉTICA "HIGH RETENTION" */
        :root {
            --viral-yellow: #FFFF00; /* Amarillo puro 100% */
            --bg-dark: #050505;
            --card-surface: #111;
        }
        body { 
            background-color: var(--bg-dark); 
            color: #fff; 
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
        }
        .app-card {
            width: 100%; max-width: 480px;
            background: var(--card-surface);
            border: 2px solid #333;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 0 50px rgba(0,0,0,0.8);
        }
        .header-bar {
            background: var(--viral-yellow);
            padding: 20px; text-align: center;
        }
        .header-title {
            font-family: 'Anton', sans-serif;
            font-size: 2rem;
            color: #000;
            text-transform: uppercase;
            margin: 0; letter-spacing: 1px;
            line-height: 1;
        }
        .form-section { padding: 30px; }
        
        /* INPUT GIGANTE */
        .viral-input-label {
            color: var(--viral-yellow);
            font-weight: 800;
            font-size: 0.9rem;
            letter-spacing: 1px;
            margin-bottom: 10px;
            display: block;
            text-transform: uppercase;
        }
        .viral-input {
            background: #000;
            border: 2px solid #444;
            color: #fff;
            font-family: 'Anton', sans-serif; /* Ves la fuente viral mientras escribes */
            font-size: 1.5rem;
            padding: 15px;
            text-transform: uppercase; /* FORZAR MAYÚSCULAS VISUALMENTE */
            border-radius: 12px;
            width: 100%;
            transition: 0.3s;
        }
        .viral-input:focus {
            outline: none;
            border-color: var(--viral-yellow);
            box-shadow: 0 0 20px rgba(255, 255, 0, 0.2);
        }
        .char-count { text-align: right; font-size: 0.8rem; color: #666; margin-top: 5px; }
        
        .file-input-wrapper {
            margin-top: 25px;
            border: 2px dashed #444;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: 0.3s;
        }
        .file-input-wrapper:hover { border-color: #fff; background: #1a1a1a; }
        
        .cta-btn {
            background: var(--viral-yellow);
            color: #000;
            font-family: 'Anton', sans-serif;
            font-size: 1.5rem;
            width: 100%;
            padding: 18px;
            border: none;
            border-radius: 12px;
            margin-top: 25px;
            text-transform: uppercase;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .cta-btn:active { transform: scale(0.98); }

        /* RESULTADOS */
        .preview-container {
            width: 100%; aspect-ratio: 9/16;
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 20px;
            position: relative;
        }
        video { width: 100%; height: 100%; object-fit: cover; }
        
        .hidden { display: none; }
    </style>
</head>
<body>

<div class="app-card">
    <div class="header-bar">
        <h1 class="header-title">VIRAL HOOK MAKER</h1>
    </div>
    
    <div class="form-section">
        
        <?php if (!$hasFont || !$hasLogo): ?>
            <div class="alert alert-warning p-2 small text-center mb-3 text-black fw-bold bg-warning border-0">
                ⚠️ FALTAN RECURSOS: Sube <code>font.ttf</code> y <code>logo.png</code>
            </div>
        <?php endif; ?>

        <div id="stepInput">
            <form id="uploadForm">
                <div>
                    <label class="viral-input-label">1. Escribe tu Gancho (Hook)</label>
                    <input type="text" name="videoTitle" id="titleInput" class="viral-input" placeholder="¡ESTO ES INCREÍBLE!" maxlength="35" required autocomplete="off">
                    <div class="char-count"><span id="charNum">0</span>/35 (Corto = Viral)</div>
                </div>

                <div class="file-input-wrapper" onclick="document.getElementById('fileInput').click()">
                    <div class="fw-bold text-white">2. Toca para subir Video</div>
                    <div class="small text-muted mt-1">MP4, MOV (Vertical recomendado)</div>
                    <input type="file" name="videoFile" id="fileInput" accept="video/*" hidden required onchange="document.querySelector('.file-input-wrapper div').innerText = '✅ ' + this.files[0].name">
                </div>

                <button type="submit" class="cta-btn">🚀 CREAR VIRAL</button>
            </form>
        </div>

        <div id="stepProcess" class="hidden text-center py-5">
            <div class="spinner-border text-warning mb-4" style="width: 3rem; height: 3rem;"></div>
            <h3 class="fw-bold text-white">COCINANDO EL VIRAL...</h3>
            <p class="text-secondary small mt-2">Maximizando retención • Renderizando header • Limpiando metadatos</p>
        </div>

        <div id="stepResult" class="hidden text-center">
            <h3 class="fw-bold text-success mb-3">✅ ¡LISTO PARA SUBIR!</h3>
            
            <div class="preview-container">
                <div id="videoWrapper" style="width:100%; height:100%;"></div>
            </div>

            <a id="downloadLink" href="#" class="cta-btn d-block text-decoration-none pt-3">
                ⬇️ DESCARGAR Y BORRAR
            </a>
            <button onclick="location.reload()" class="btn btn-link text-secondary mt-3 text-decoration-none">🔄 Crear otro hook</button>
        </div>

    </div>
</div>

<script>
// Contador de caracteres para psicología de "Hook Corto"
const input = document.getElementById('titleInput');
const counter = document.getElementById('charNum');
input.addEventListener('input', function() {
    this.value = this.value.toUpperCase(); // Forzar mayúsculas en input
    counter.innerText = this.value.length;
    if(this.value.length >= 35) counter.style.color = 'red';
    else counter.style.color = '#666';
});

// Manejo del proceso
const form = document.getElementById('uploadForm');
const steps = {
    input: document.getElementById('stepInput'),
    process: document.getElementById('stepProcess'),
    result: document.getElementById('stepResult')
};

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if(!document.getElementById('fileInput').files.length) { alert("Selecciona un video"); return; }
    
    steps.input.classList.add('hidden');
    steps.process.classList.remove('hidden');

    const formData = new FormData(form);
    
    try {
        const res = await fetch('?action=upload', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.status === 'success') {
            trackJob(data.jobId);
        } else {
            alert(data.message); location.reload();
        }
    } catch (err) {
        alert("Error de conexión"); location.reload();
    }
});

function trackJob(jobId) {
    let attempts = 0;
    const interval = setInterval(async () => {
        attempts++;
        if (attempts > 120) { clearInterval(interval); alert("Tiempo agotado"); location.reload(); }
        
        try {
            const res = await fetch(`?action=status&jobId=${jobId}`);
            const data = await res.json();
            
            if (data.status === 'finished') {
                clearInterval(interval);
                showResult(data);
            }
        } catch (e) { console.log("Waiting..."); }
    }, 3000); // Revisar cada 3 seg
}

function showResult(data) {
    steps.process.classList.add('hidden');
    steps.result.classList.remove('hidden');
    
    document.getElementById('downloadLink').href = data.download_url;
    
    // Timestamp para evitar caché y que salga en blanco
    const ts = new Date().getTime();
    const realPath = 'processed/' + data.filename + '?t=' + ts;
    
    document.getElementById('videoWrapper').innerHTML = `
        <video width="100%" height="100%" controls autoplay muted playsinline loop>
            <source src="${realPath}" type="video/mp4">
        </video>
    `;
}
</script>

</body>
</html>
