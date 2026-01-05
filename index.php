<?php
// ==========================================
// VIRAL REELS PRO v14.0 (Motor v6 Estable + Psicología Viral Extrema)
// ==========================================
// Mismo motor técnico que funcionó, pero con diseño visual agresivo.

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

// Asegurar carpetas y permisos
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); chmod($uploadDir, 0777); }
if (!file_exists($processedDir)) { mkdir($processedDir, 0777, true); chmod($processedDir, 0777); }
if (!file_exists($jobsDir)) { mkdir($jobsDir, 0777, true); chmod($jobsDir, 0777); }

$hasLogo = file_exists($logoPath);
$hasFont = file_exists($fontPath);

// AUTO-LIMPIEZA (Cada 20 mins)
function limpiarBasura($dir) {
    if (!is_dir($dir)) return;
    $files = glob($dir . '*');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file)) {
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

// ---> ACCIÓN: DESCARGAR
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = $processedDir . $file;
    if (file_exists($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="VIRAL_HOOK_'.date('Ymd_Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        // NOTA: Comentamos el unlink inmediato para que puedas descargarlo varias veces si falla la primera
        // @unlink($filePath); 
        exit;
    } else { die("El archivo ya no existe."); }
}

// ---> ACCIÓN: SUBIR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida.']); exit;
    }

    $rawTitle = $_POST['videoTitle'] ?? '';
    // PSICOLOGÍA: Forzamos MAYÚSCULAS extremas
    $cleanTitle = mb_strtoupper(str_replace(["'", "\"", "\\", ":"], "", $rawTitle), 'UTF-8');
    // Limitamos a 40 chars. Más de eso, la gente no lee.
    $cleanTitle = mb_substr($cleanTitle, 0, 40); 

    $file = $_FILES['videoFile'];
    $jobId = uniqid('v14'); // ID v14
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

        // --- CONSTRUCCIÓN DEL FILTRO VIRAL PSICOLÓGICO ---
        $filter = "";
        
        // 1. BASE: Fondo Blur + Video Centrado (Formato 9:16 OBLIGATORIO)
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10,colorlevels=rimax=0.5:gimax=0.5:bimax=0.5[bg];";
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
        $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
        $lastStream = "[base]";

        // 2. BARRA DE NOTICIAS (Aumentada a 250px para texto GIGANTE)
        // y=60: Zona Segura Superior.
        // color=black@0.9: Casi sólido para máximo contraste.
        $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h=250:color=black@0.9:t=fill[with_bar];";
        $lastStream = "[with_bar]";

        // 3. LOGO (Izquierda)
        if ($hasLogo) {
            $filter .= "[1:v]scale=-1:150[logo_scaled];";
            $filter .= "{$lastStream}[logo_scaled]overlay=40:110[watermarked];";
            $lastStream = "[watermarked]";
        }

        // 4. TÍTULO VIRAL (AMARILLO PURO + TAMAÑO MONSTRUOSO)
        if ($hasFont && !empty($cleanTitle)) {
            $fontFileSafe = str_replace('\\', '/', $fontPath);
            // fontsize=95: ¡GIGANTE!
            // fontcolor=#FFFF00: Amarillo Puro (Señal de Alerta)
            // borderw=6: Borde negro grueso para lectura instantánea
            // y=140: Centrado verticalmente en la barra de 250px
            $drawText = "drawtext=fontfile='$fontFileSafe':text='$cleanTitle':fontcolor=#FFFF00:fontsize=95:borderw=6:bordercolor=black:x=(w-text_w)/2+70:y=140";
            
            $filter .= "{$lastStream}{$drawText}[titled];";
            $lastStream = "[titled]";
        }

        // 5. Aceleración (Retención) + Fix Audio
        $filter .= "{$lastStream}setpts=0.94*PTS[v_final];[0:a]atempo=1.0638[a_final]";

        // COMANDO DE EJECUCIÓN (Usando la estructura que SÍ funcionó en v6)
        $ffmpegCmd = "nice -n 19 ffmpeg $inputs -threads 2 -filter_complex \"$filter\" -map \"[v_final]\" -map \"[a_final]\" -map_metadata -1 -c:v libx264 -preset veryfast -crf 24 -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputPath) . " > /dev/null 2>&1 &";

        exec("nohup $ffmpegCmd");

        file_put_contents($jobFile, json_encode(['status' => 'processing', 'filename' => $outputFilename, 'start_time' => time()]));
        echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    } else { echo json_encode(['status' => 'error', 'message' => 'Error permisos.']); }
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
        // Esperamos a que el archivo tenga algo de peso (>50KB)
        if (file_exists($realPath) && filesize($realPath) > 51200) {
            chmod($realPath, 0777); 
            sleep(1); 
            $jobData['status'] = 'finished';
            $jobData['download_url'] = '?action=download&file=' . $jobData['filename'];
            file_put_contents($jobFile, json_encode($jobData));
            // Borramos el input para ahorrar espacio, pero dejamos el output un rato
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
    <title>Viral Reels v14 (Psychology Edition)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        /* DISEÑO DE ALTO IMPACTO */
        :root {
            --viral-yellow: #FFFF00; /* Amarillo Puro */
            --bg-black: #000000;
        }
        body { 
            background-color: var(--bg-black); 
            font-family: 'Inter', sans-serif; 
            color: #fff;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
        }
        .main-card { 
            width: 100%; max-width: 500px; 
            background: #111; 
            border: 2px solid #333; 
            border-radius: 20px; 
            padding: 30px;
            box-shadow: 0 0 40px rgba(255, 255, 0, 0.1);
        }
        .header-title { 
            font-family: 'Anton', sans-serif; 
            text-transform: uppercase; 
            font-size: 3rem;
            color: var(--viral-yellow);
            text-align: center;
            line-height: 1;
            margin-bottom: 10px;
        }
        .header-sub { text-align: center; color: #666; font-size: 0.8rem; margin-bottom: 30px; letter-spacing: 2px; text-transform: uppercase; }

        .input-label { color: var(--viral-yellow); font-weight: 900; font-size: 0.9rem; text-transform: uppercase; margin-bottom: 5px; display: block; }
        
        .viral-input {
            background: #000;
            border: 2px solid #444;
            color: #fff;
            font-family: 'Anton', sans-serif;
            font-size: 1.5rem;
            padding: 15px;
            text-transform: uppercase;
            width: 100%;
            border-radius: 10px;
        }
        .viral-input:focus { outline: none; border-color: var(--viral-yellow); }

        .btn-viral { 
            background-color: var(--viral-yellow); 
            color: #000; 
            font-family: 'Anton', sans-serif; 
            text-transform: uppercase; 
            font-size: 1.5rem;
            padding: 20px;
            border-radius: 10px;
            border: none;
            width: 100%; 
            margin-top: 20px;
            cursor: pointer;
        }
        .btn-viral:hover { background-color: #fff; }
        
        .preview-container { 
            background: #000; 
            border: 2px solid #333;
            border-radius: 15px; 
            overflow: hidden; 
            width: 100%; 
            aspect-ratio: 9/16; 
            margin-top: 20px;
        }
        .spinner-grow { color: var(--viral-yellow); }
    </style>
</head>
<body>

<div class="main-card">
    <h1 class="header-title">VIRAL MAKER</h1>
    <p class="header-sub">Psicología de Atención Aplicada</p>

    <?php if (!$hasFont || !$hasLogo): ?>
        <div class="alert alert-danger text-center small p-1">⚠️ FALTAN ARCHIVOS (logo.png / font.ttf)</div>
    <?php endif; ?>

    <div id="inputSection">
        <form id="uploadForm">
            <div class="mb-4">
                <label class="input-label">1. Escribe el Gancho (Hook)</label>
                <input type="text" name="videoTitle" id="titleInput" class="viral-input" placeholder="¡ESTO ES INCREÍBLE!" maxlength="40" required autocomplete="off">
                <div class="text-end text-muted small mt-1">Se convertirá a MAYÚSCULAS automáticamente</div>
            </div>

            <div class="mb-4">
                <label class="input-label">2. Sube el Video</label>
                <input class="form-control bg-dark text-white border-secondary" type="file" name="videoFile" accept="video/*" required>
            </div>

            <button type="submit" class="btn-viral">🚀 CREAR AHORA</button>
        </form>
    </div>

    <div id="progressSection" class="d-none text-center py-5">
        <div class="spinner-grow mb-3" role="status"></div>
        <h3 class="fw-bold text-white">RENDERIZANDO...</h3>
        <p class="text-muted small">Aplicando contraste visual y zona segura.</p>
    </div>

    <div id="resultSection" class="d-none text-center">
        <h3 class="fw-bold text-success">¡LISTO PARA SUBIR!</h3>
        <div class="preview-container">
            <div id="videoContainer" style="width:100%; height:100%;"></div>
        </div>
        <a id="downloadBtn" href="#" class="btn-viral">⬇️ DESCARGAR</a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3 text-decoration-none">Crear otro</button>
    </div>
</div>

<script>
// Forzar mayúsculas visualmente mientras escribes
document.getElementById('titleInput').addEventListener('input', function() {
    this.value = this.value.toUpperCase();
});

const form = document.getElementById('uploadForm');
const sections = { input: document.getElementById('inputSection'), progress: document.getElementById('progressSection'), result: document.getElementById('resultSection') };

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    sections.input.classList.add('d-none'); sections.progress.classList.remove('d-none');
    const formData = new FormData(form);
    try {
        const res = await fetch('?action=upload', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') trackJob(data.jobId); else { alert(data.message); location.reload(); }
    } catch (err) { alert('Error conexión'); location.reload(); }
});

function trackJob(jobId) {
    let attempts = 0;
    const interval = setInterval(async () => {
        attempts++; if(attempts>180){clearInterval(interval);alert("Tiempo agotado");location.reload();}
        try {
            const res = await fetch(`?action=status&jobId=${jobId}`);
            const data = await res.json();
            if (data.status === 'finished') { clearInterval(interval); showResult(data); }
        } catch (e) {}
    }, 3000);
}

function showResult(data) {
    sections.progress.classList.add('d-none'); sections.result.classList.remove('d-none');
    document.getElementById('downloadBtn').href = data.download_url;
    // Timestamp para evitar caché
    const realPath = 'processed/' + data.filename + '?t=' + new Date().getTime();
    document.getElementById('videoContainer').innerHTML = `<video width="100%" height="100%" controls autoplay muted playsinline><source src="${realPath}" type="video/mp4"></video>`;
}
</script>
</body>
</html>
