<?php
// ==========================================
// GENERADOR REELS VIRAL PRO v6.0 (Black Edition + Branded Header)
// ==========================================
// La versión definitiva con diseño de barra de noticias integrada.
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

// ==========================================
// AUTO-LIMPIEZA
// ==========================================
function limpiarBasura($dir) {
    if (!is_dir($dir)) return;
    $files = glob($dir . '*');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file)) {
            if ($now - filemtime($file) >= 1200) { // 20 minutos
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
        header('Content-Disposition: attachment; filename="VIRAL_REEL_'.date('Ymd_Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        @unlink($filePath); // Auto-destrucción
        $jobId = str_replace('_reel.mp4', '', $file);
        @unlink($jobsDir . $jobId . '.json');
        exit;
    } else { die("Archivo caducado o inexistente."); }
}

// ---> ACCIÓN: SUBIR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida.']); exit;
    }

    $rawTitle = $_POST['videoTitle'] ?? '';
    $cleanTitle = mb_strtoupper(str_replace(["'", "\"", "\\", ":"], "", $rawTitle), 'UTF-8');
    $cleanTitle = mb_substr($cleanTitle, 0, 45); // Limitamos a 45 chars para que quepa en la barra

    $file = $_FILES['videoFile'];
    $jobId = uniqid('v6');
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

        // --- CONSTRUCCIÓN DEL FILTRO PROFESIONAL v6 ---
        $filter = "";
        
        // 1. Crear Base 9:16 (Fondo Blur + Video Centrado)
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10,colorlevels=rimax=0.5:gimax=0.5:bimax=0.5[bg];";
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
        $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
        $lastStream = "[base]";

        // 2. DIBUJAR BARRA DE NOTICIAS (BRANDED HEADER)
        // Crea una caja negra semitransparente (opacity 0.8) en la parte superior
        // y=50: Empieza 50px abajo del borde superior (espacio para UI del celular)
        // h=160: Altura de la barra de 160px
        $filter .= "{$lastStream}drawbox=x=0:y=50:w=iw:h=160:color=black@0.8:t=fill[with_bar];";
        $lastStream = "[with_bar]";

        // 3. INSERTAR LOGO DENTRO DE LA BARRA (Izquierda)
        if ($hasLogo) {
            // Escalar logo a un tamaño manejable (ej: 120px altura)
            $filter .= "[1:v]scale=-1:120[logo_scaled];";
            // Posición: x=40 (margen izq), y=70 (centrado verticalmente en la barra que empieza en y=50)
            $filter .= "{$lastStream}[logo_scaled]overlay=40:70[watermarked];";
            $lastStream = "[watermarked]";
        }

        // 4. INSERTAR TÍTULO DENTRO DE LA BARRA (Centro/Derecha)
        if ($hasFont && !empty($cleanTitle)) {
            $fontFileSafe = str_replace('\\', '/', $fontPath);
            // fontsize=70: Un poco más pequeño para caber en la barra
            // y=95: Posición vertical dentro de la barra
            // x=(w-text_w)/2 + 60: Centrado, pero desplazado un poco a la derecha para dar espacio al logo
            $drawText = "drawtext=fontfile='$fontFileSafe':text='$cleanTitle':fontcolor=yellow:fontsize=70:borderw=3:bordercolor=black:x=(w-text_w)/2+60:y=95";
            $filter .= "{$lastStream}{$drawText}[titled];";
            $lastStream = "[titled]";
        }

        // 5. Aceleración Final
        $filter .= "{$lastStream}setpts=0.94*PTS[v_final];[0:a]atempo=1.0638[a_final]";

        // Comando Exec
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
        if (file_exists($realPath) && filesize($realPath) > 51200) {
            chmod($realPath, 0777); // Fix permisos preview
            sleep(1); 
            $jobData['status'] = 'finished';
            $jobData['download_url'] = '?action=download&file=' . $jobData['filename'];
            file_put_contents($jobFile, json_encode($jobData));
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
    <title>Viral Reels v6 Black Edition</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Montserrat:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        /* DISEÑO CYBERPUNK PREMIUM (Black & Neon Yellow) */
        :root {
            --neon-yellow: #FFD700;
            --dark-bg: #0a0a0a;
            --card-bg: #141414;
        }
        body { 
            background-color: var(--dark-bg); 
            font-family: 'Montserrat', sans-serif; 
            color: #eee;
            background-image: radial-gradient(circle at top center, #222 0%, #0a0a0a 70%);
            min-height: 100vh;
        }
        .app-container { max-width: 500px; margin: 60px auto; }
        .card { 
            border: 1px solid #333; 
            border-radius: 25px; 
            background: var(--card-bg);
            box-shadow: 0 20px 50px rgba(0,0,0,0.5);
            overflow: hidden;
        }
        .header-viral { 
            padding: 35px 20px; 
            text-align: center; 
            background: transparent;
            border-bottom: 2px solid var(--neon-yellow);
        }
        .header-title { 
            font-family: 'Anton', sans-serif; 
            text-transform: uppercase; 
            font-size: 2.5rem;
            color: var(--neon-yellow);
            text-shadow: 0 0 15px rgba(255, 215, 0, 0.3);
            margin-bottom: 5px;
        }
        .header-subtitle { color: #888; font-weight: 600; letter-spacing: 1px; font-size: 0.9rem; }
        
        .form-label { color: var(--neon-yellow); font-weight: 800; letter-spacing: 0.5px; }
        .form-control {
            background-color: #222;
            border: 2px solid #333;
            color: var(--neon-yellow);
            font-weight: 600;
            padding: 15px;
            border-radius: 12px;
        }
        .form-control:focus {
            background-color: #000;
            border-color: var(--neon-yellow);
            box-shadow: 0 0 15px rgba(255, 215, 0, 0.2);
            color: #fff;
        }
        .form-control::placeholder { color: #555; }

        .btn-viral { 
            background-color: var(--neon-yellow); 
            color: #000; 
            font-family: 'Anton', sans-serif; 
            text-transform: uppercase; 
            font-size: 1.4rem;
            padding: 18px;
            border-radius: 12px;
            border: none;
            width: 100%; 
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            box-shadow: 0 5px 15px rgba(255, 215, 0, 0.2);
        }
        .btn-viral:hover { 
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 10px 25px rgba(255, 215, 0, 0.4);
            color: #000;
            background-color: #ffea00;
        }
        
        .preview-box { 
            background: #000; 
            border-radius: 15px; 
            overflow: hidden; 
            width: 100%; max-width: 260px; 
            margin: 30px auto; 
            aspect-ratio: 9/16; 
            border: 3px solid #333;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .alert-warning { background: #332b00; border-color: #FFD700; color: #FFD700; }
        .spinner-grow { color: var(--neon-yellow); }
    </style>
</head>
<body>

<div class="container app-container">
    <div class="card">
        <div class="header-viral">
            <h1 class="header-title">VIRAL REELS PRO</h1>
            <p class="header-subtitle">SISTEMA DE EDICIÓN AUTOMÁTICA v6.0</p>
        </div>
        <div class="card-body p-4 p-md-5">

            <?php if (!$hasFont || !$hasLogo): ?>
                <div class="alert alert-warning small fw-bold">⚠️ Faltan recursos: Asegura <code>font.ttf</code> y <code>logo.png</code> para el diseño de barra.</div>
            <?php endif; ?>

            <div id="inputSection">
                <form id="uploadForm">
                    <div class="mb-4">
                        <label class="form-label">1. TÍTULO GANCHO (Max 45 letras)</label>
                        <input type="text" name="videoTitle" class="form-control" placeholder="¡ESTO ES UNA LOCURA! 🤯" maxlength="45" required>
                    </div>

                    <div class="mb-5">
                        <label class="form-label">2. ARCHIVO DE VIDEO</label>
                        <input class="form-control" type="file" name="videoFile" accept="video/*" required>
                    </div>

                    <button type="submit" class="btn btn-viral">🔥 GENERAR VIDEO VIRAL</button>
                </form>
            </div>

            <div id="progressSection" class="d-none text-center py-5">
                <div class="spinner-grow mb-4" style="width: 3rem; height: 3rem;" role="status"></div>
                <h4 class="fw-bold text-white">RENDERIZANDO...</h4>
                <p class="text-muted small">Creando barra de marca, aplicando efectos y limpiando metadatos.</p>
            </div>

            <div id="resultSection" class="d-none text-center">
                <h3 class="fw-bold text-success mb-4">✅ ¡RENDER COMPLETADO!</h3>
                
                <div class="preview-box">
                    <div id="videoContainer" style="width:100%; height:100%;"></div>
                </div>

                <div class="d-grid gap-3 mt-4">
                    <a id="downloadBtn" href="#" class="btn btn-viral">⬇️ DESCARGAR (AUTO-BORRADO)</a>
                    <button onclick="location.reload()" class="btn btn-outline-light py-3 fw-bold rounded-pill">🔄 Procesar Otro Video</button>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
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
    const realPath = 'processed/' + data.filename + '?t=' + new Date().getTime();
    document.getElementById('videoContainer').innerHTML = `<video width="100%" height="100%" controls autoplay muted playsinline><source src="${realPath}" type="video/mp4"></video>`;
}
</script>
</body>
</html>
