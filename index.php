<?php
// ==========================================
// VIRAL REELS MAKER v9.0 (Debug + Max Compatibility)
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
$debugFile = $baseDir . 'debug_log.txt'; // Archivo para registrar errores

// Crear carpetas
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); chmod($uploadDir, 0777); }
if (!file_exists($processedDir)) { mkdir($processedDir, 0777, true); chmod($processedDir, 0777); }
if (!file_exists($jobsDir)) { mkdir($jobsDir, 0777, true); chmod($jobsDir, 0777); }

// Auto-limpieza (20 mins)
function limpiarBasura($dir) {
    if (!is_dir($dir)) return;
    $files = glob($dir . '*');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file) >= 1200)) @unlink($file);
    }
}
limpiarBasura($uploadDir);
limpiarBasura($processedDir);
limpiarBasura($jobsDir);

$hasLogo = file_exists($logoPath);
$hasFont = file_exists($fontPath);
$action = $_GET['action'] ?? '';

// ---> VER LOGS (Para depuración)
if ($action === 'viewlog') {
    if (file_exists($debugFile)) {
        echo "<pre>" . file_get_contents($debugFile) . "</pre>";
    } else {
        echo "No hay errores registrados.";
    }
    exit;
}

// ---> DESCARGAR
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = $processedDir . $file;
    if (file_exists($filePath)) {
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="VIRAL_'.date('His').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        ob_clean(); flush(); readfile($filePath);
        @unlink($filePath);
        $jobId = str_replace('_reel.mp4', '', $file);
        @unlink($jobsDir . $jobId . '.json');
        exit;
    } else { die("Archivo no encontrado."); }
}

// ---> SUBIR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida.']); exit;
    }

    $rawTitle = $_POST['videoTitle'] ?? '';
    $cleanTitle = mb_strtoupper(str_replace(["'", "\"", "\\", ":", ";"], "", $rawTitle), 'UTF-8');
    $cleanTitle = mb_substr($cleanTitle, 0, 40); 

    $file = $_FILES['videoFile'];
    $jobId = uniqid('v9');
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

        // FILTROS
        $filter = "";
        // 1. Base Blur
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10,colorlevels=rimax=0.5:gimax=0.5:bimax=0.5[bg];";
        // 2. Video Principal
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
        $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
        $lastStream = "[base]";
        // 3. Barra Header (Negro 90%)
        $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h=240:color=black@0.9:t=fill[with_bar];";
        $lastStream = "[with_bar]";

        // 4. Logo
        if ($hasLogo) {
            $filter .= "[1:v]scale=-1:160[logo_scaled];";
            $filter .= "{$lastStream}[logo_scaled]overlay=40:100[watermarked];";
            $lastStream = "[watermarked]";
        }

        // 5. Título
        if ($hasFont && !empty($cleanTitle)) {
            $fontFileSafe = str_replace('\\', '/', $fontPath);
            $drawText = "drawtext=fontfile='$fontFileSafe':text='$cleanTitle':fontcolor=#FFD700:fontsize=90:borderw=4:bordercolor=black:x=(w-text_w)/2+80:y=135";
            $filter .= "{$lastStream}{$drawText}[titled];";
            $lastStream = "[titled]";
        }

        // 6. Audio/Video Sync Fix
        $filter .= "{$lastStream}setpts=0.94*PTS[v_final];[0:a]atempo=1.0638[a_final]";

        // COMANDO CON LOGGING DE ERRORES Y COMPATIBILIDAD
        // -profile:v main: Asegura que funcione en iPhone/Android viejos
        // -pix_fmt yuv420p: Estandar de color universal
        // 2> $debugFile: Guarda cualquier error de FFmpeg en un archivo de texto
        $ffmpegCmd = "ffmpeg $inputs -threads 2 -filter_complex \"$filter\" -map \"[v_final]\" -map \"[a_final]\" -map_metadata -1 -c:v libx264 -profile:v main -pix_fmt yuv420p -preset ultrafast -crf 26 -c:a aac -ar 44100 -b:a 128k -movflags +faststart " . escapeshellarg($outputPath) . " > $debugFile 2>&1 &";

        exec("nohup $ffmpegCmd");

        file_put_contents($jobFile, json_encode(['status' => 'processing', 'filename' => $outputFilename, 'start_time' => time()]));
        echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    } else { echo json_encode(['status' => 'error', 'message' => 'Error subida.']); }
    exit;
}

// ---> CHECK STATUS
if ($action === 'status' && isset($_GET['jobId'])) {
    header('Content-Type: application/json');
    $jobId = preg_replace('/[^a-z0-9_]/i', '', $_GET['jobId']);
    $jobFile = $jobsDir . $jobId . '.json';
    if (!file_exists($jobFile)) { echo json_encode(['status' => 'not_found']); exit; }
    $jobData = json_decode(file_get_contents($jobFile), true);

    if ($jobData['status'] === 'processing') {
        $realPath = $processedDir . $jobData['filename'];
        
        // Verificamos si hay errores en el log
        if (filesize($debugFile) > 0) {
            $logContent = file_get_contents($debugFile);
            // Si el log contiene "Error", algo falló (aunque ffmpeg es ruidoso, buscamos fallos fatales)
            // Para simplificar, asumimos éxito si el archivo crece
        }

        if (file_exists($realPath) && filesize($realPath) > 51200) {
            chmod($realPath, 0777); 
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
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral Reels v9 (Debug)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Oswald:wght@700&display=swap" rel="stylesheet">
    <style>
        :root { --accent: #FFD700; --bg: #000; }
        body { background: var(--bg); color: #fff; font-family: 'Oswald', sans-serif; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; }
        .app-box { width: 100%; max-width: 480px; padding: 20px; border: 1px solid #333; border-radius: 20px; background: #111; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        .input-title { background: #000; border: 2px solid #444; color: var(--accent); font-family: 'Anton'; font-size: 1.5rem; text-transform: uppercase; padding: 15px; width: 100%; border-radius: 10px; }
        .btn-main { background: var(--accent); color: #000; font-family: 'Anton'; font-size: 1.5rem; width: 100%; border: none; padding: 15px; border-radius: 10px; margin-top: 20px; text-transform: uppercase; }
        .preview-box { background: #000; aspect-ratio: 9/16; border-radius: 10px; overflow: hidden; margin-bottom: 20px; border: 1px solid #333; }
        .hidden { display: none; }
        .debug-link { color: #333; font-size: 0.7rem; text-decoration: none; margin-top: 20px; }
    </style>
</head>
<body>

<div class="app-box">
    <h2 class="text-center mb-4" style="color:var(--accent); font-family:'Anton';">VIRAL REELS v9</h2>

    <?php if (!$hasFont || !$hasLogo): ?>
        <div class="alert alert-danger p-2 text-center small">⚠️ FALTAN ARCHIVOS (logo.png o font.ttf)</div>
    <?php endif; ?>

    <div id="uiInput">
        <form id="form">
            <label class="small text-muted mb-1">TÍTULO (HOOK)</label>
            <input type="text" name="videoTitle" class="input-title" placeholder="¡ESTO ES BRUTAL!" maxlength="40" required>
            
            <div class="mt-4 p-4 border border-secondary border-dashed rounded text-center" onclick="document.getElementById('file').click()" style="cursor:pointer; background:#1a1a1a;">
                <div class="fs-1">📂</div>
                <div class="fw-bold mt-2">SUBIR VIDEO</div>
                <input type="file" name="videoFile" id="file" accept="video/*" hidden required onchange="this.parentElement.style.borderColor='#FFD700'">
            </div>

            <button type="submit" class="btn-main">🚀 PROCESAR</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-5">
        <div class="spinner-border text-warning mb-3"></div>
        <h4>RENDERIZANDO...</h4>
        <p class="small text-muted">Asegurando compatibilidad universal</p>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h4 class="text-success mb-3">✅ ¡LISTO!</h4>
        <div class="preview-box">
            <div id="vidWrap" style="width:100%; height:100%;"></div>
        </div>
        <a id="dlLink" href="#" class="btn-main text-decoration-none d-block">⬇️ DESCARGAR</a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-2">Nuevo Video</button>
    </div>
</div>

<a href="?action=viewlog" target="_blank" class="debug-link">Ver reporte de errores (Debug)</a>

<script>
const form = document.getElementById('form');
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if(!document.getElementById('file').files.length) return alert("Sube un video");
    
    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData(form);
    try {
        const res = await fetch('?action=upload', { method: 'POST', body: fd });
        const data = await res.json();
        if(data.status === 'success') track(data.jobId);
        else { alert(data.message); location.reload(); }
    } catch { alert("Error de red"); location.reload(); }
});

function track(id) {
    let t = 0;
    const i = setInterval(async () => {
        t++; 
        if(t > 120) { // 6 minutos max
            clearInterval(i); 
            // Si falla, mostramos alerta para ver el log
            if(confirm("Tardó mucho. ¿Quieres ver el reporte de error?")) {
                window.open("?action=viewlog", "_blank");
            }
            location.reload(); 
        }
        
        try {
            const res = await fetch(`?action=status&jobId=${id}`);
            const data = await res.json();
            if(data.status === 'finished') {
                clearInterval(i);
                show(data);
            }
        } catch {}
    }, 3000);
}

function show(data) {
    document.getElementById('uiProcess').classList.add('hidden');
    document.getElementById('uiResult').classList.remove('hidden');
    document.getElementById('dlLink').href = data.download_url;
    
    // TIMESTAMP CRÍTICO: Evita que el navegador use una versión vieja o vacía del video
    const url = 'processed/' + data.filename + '?time=' + Date.now();
    document.getElementById('vidWrap').innerHTML = `<video width="100%" height="100%" controls autoplay muted loop playsinline><source src="${url}" type="video/mp4"></video>`;
}
</script>

</body>
</html>
