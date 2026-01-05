<?php
// ==========================================
// VIRAL REELS MAKER v8.0 (Universal Fix + NeuroMarketing)
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

// Crear carpetas
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); chmod($uploadDir, 0777); }
if (!file_exists($processedDir)) { mkdir($processedDir, 0777, true); chmod($processedDir, 0777); }
if (!file_exists($jobsDir)) { mkdir($jobsDir, 0777, true); chmod($jobsDir, 0777); }

$hasLogo = file_exists($logoPath);
$hasFont = file_exists($fontPath);

// AUTO-LIMPIEZA (Borra basura de hace 20 mins)
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

$action = $_GET['action'] ?? '';

// ---> DESCARGA SEGURA
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = $processedDir . $file;
    if (file_exists($filePath)) {
        // Headers universales para MP4
        header('Content-Description: File Transfer');
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="VIRAL_'.date('His').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        header('Pragma: public');
        ob_clean();
        flush();
        readfile($filePath);
        // Borrar después de enviar
        @unlink($filePath);
        $jobId = str_replace('_reel.mp4', '', $file);
        @unlink($jobsDir . $jobId . '.json');
        exit;
    } else { die("El archivo ya no existe (fue borrado por seguridad)."); }
}

// ---> SUBIDA Y PROCESAMIENTO
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error al subir.']); exit;
    }

    // PSICOLOGÍA: Títulos cortos (Max 40 chars) y MAYÚSCULAS para urgencia
    $rawTitle = $_POST['videoTitle'] ?? '';
    $cleanTitle = mb_strtoupper(str_replace(["'", "\"", "\\", ":"], "", $rawTitle), 'UTF-8');
    $cleanTitle = mb_substr($cleanTitle, 0, 40); 

    $file = $_FILES['videoFile'];
    $jobId = uniqid('v8');
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

        // --- FILTROS DE INGENIERÍA VIRAL ---
        $filter = "";
        
        // 1. BASE: Crear canvas 1080x1920 (Blur de fondo)
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10,colorlevels=rimax=0.5:gimax=0.5:bimax=0.5[bg];";
        
        // 2. VIDEO: Escalar video principal para que quepa sin cortarse
        $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
        
        // 3. COMPOSICIÓN: Poner video sobre fondo blur
        $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
        $lastStream = "[base]";

        // 4. HEADER NEGRO (Zona de Titular) - Aumentada a 240px para impacto
        $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h=240:color=black@0.9:t=fill[with_bar];";
        $lastStream = "[with_bar]";

        // 5. LOGO (Izquierda)
        if ($hasLogo) {
            $filter .= "[1:v]scale=-1:160[logo_scaled];";
            $filter .= "{$lastStream}[logo_scaled]overlay=40:100[watermarked];";
            $lastStream = "[watermarked]";
        }

        // 6. TÍTULO (Amarillo + Borde Negro + Fuente Impacto)
        // Posición y=135: Centro óptico de la barra negra
        if ($hasFont && !empty($cleanTitle)) {
            $fontFileSafe = str_replace('\\', '/', $fontPath);
            $drawText = "drawtext=fontfile='$fontFileSafe':text='$cleanTitle':fontcolor=#FFD700:fontsize=90:borderw=4:bordercolor=black:x=(w-text_w)/2+80:y=135";
            $filter .= "{$lastStream}{$drawText}[titled];";
            $lastStream = "[titled]";
        }

        // 7. ACELERACIÓN (1.06x) - Imperceptible pero aumenta retención
        $filter .= "{$lastStream}setpts=0.94*PTS[v_final];[0:a]atempo=1.0638[a_final]";

        // --- COMANDO DE EXPORTACIÓN (FIX PANTALLA NEGRA) ---
        // -pix_fmt yuv420p: CLAVE para que se vea en todos los dispositivos
        // -movflags +faststart: CLAVE para que empiece a reproducir de inmediato
        $ffmpegCmd = "nice -n 19 ffmpeg $inputs -threads 2 -filter_complex \"$filter\" -map \"[v_final]\" -map \"[a_final]\" -map_metadata -1 -c:v libx264 -preset veryfast -crf 23 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputPath) . " > /dev/null 2>&1 &";

        exec("nohup $ffmpegCmd");

        file_put_contents($jobFile, json_encode(['status' => 'processing', 'filename' => $outputFilename, 'start_time' => time()]));
        echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    } else { echo json_encode(['status' => 'error', 'message' => 'Error subida.']); }
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
        if (file_exists($realPath) && filesize($realPath) > 102400) { // Esperar a >100KB
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
    <title>Viral Video Factory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Oswald:wght@700&display=swap" rel="stylesheet">
    <style>
        :root { --accent: #FFD700; --bg: #090909; }
        body { background: var(--bg); color: white; font-family: 'Oswald', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .main-container { width: 100%; max-width: 450px; padding: 20px; }
        
        .hook-input {
            background: #1a1a1a; border: 2px solid #333; color: var(--accent);
            font-size: 1.4rem; font-family: 'Anton', sans-serif; text-transform: uppercase;
            padding: 15px; width: 100%; border-radius: 12px; margin-bottom: 5px;
        }
        .hook-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 15px rgba(255, 215, 0, 0.3); }
        .label-viral { color: #888; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; display: block; }

        .btn-upload-wrapper {
            background: #1a1a1a; border: 2px dashed #444; border-radius: 12px;
            padding: 25px; text-align: center; cursor: pointer; transition: 0.2s; margin-top: 20px;
        }
        .btn-upload-wrapper:hover { background: #252525; border-color: var(--accent); }
        
        .btn-action {
            background: var(--accent); color: black; font-size: 1.5rem; font-family: 'Anton', sans-serif;
            width: 100%; border: none; padding: 18px; border-radius: 12px; margin-top: 25px;
            text-transform: uppercase; cursor: pointer; box-shadow: 0 5px 20px rgba(255, 215, 0, 0.2);
        }
        .btn-action:active { transform: scale(0.98); }

        .preview-area {
            background: black; border-radius: 12px; overflow: hidden;
            aspect-ratio: 9/16; border: 1px solid #333; margin-bottom: 20px;
        }
        .hidden { display: none; }
    </style>
</head>
<body>

<div class="main-container">
    
    <div class="text-center mb-4">
        <h1 style="font-family: 'Anton'; color: var(--accent); font-size: 2.5rem; margin:0;">VIRAL FACTORY</h1>
        <p style="color: #666; font-size: 0.9rem;">TECNOLOGÍA DE RETENCIÓN DE AUDIENCIA</p>
    </div>

    <?php if (!$hasFont): ?>
        <div class="alert alert-danger text-center p-2 small">⚠️ SUBE "font.ttf" PARA ACTIVAR TÍTULOS</div>
    <?php endif; ?>

    <div id="step1">
        <form id="uploadForm">
            <label class="label-viral">1. Escribe el Gancho (Hook)</label>
            <input type="text" name="videoTitle" id="titleIn" class="hook-input" placeholder="¡ESTO ES IMPOSIBLE!" maxlength="40" required autocomplete="off">
            <div class="text-end text-muted small"><span id="charCount">0</span>/40</div>

            <div class="btn-upload-wrapper" onclick="document.getElementById('vFile').click()">
                <div style="font-size: 2rem;">📂</div>
                <div class="fw-bold mt-2">2. Toca para subir Video</div>
                <div class="small text-muted">Formato Vertical Recomendado</div>
                <input type="file" name="videoFile" id="vFile" accept="video/*" hidden required onchange="this.parentElement.style.borderColor='#FFD700'; this.parentElement.querySelector('.fw-bold').innerText='✅ VIDEO SELECCIONADO'">
            </div>

            <button type="submit" class="btn-action">🚀 GENERAR AHORA</button>
        </form>
    </div>

    <div id="step2" class="hidden text-center py-5">
        <div class="spinner-border text-warning mb-4" style="width: 3rem; height: 3rem;"></div>
        <h3 class="fw-bold">RENDERIZANDO...</h3>
        <p class="text-muted small">Aplicando corrección de color y formato viral.</p>
    </div>

    <div id="step3" class="hidden text-center">
        <h3 class="text-success fw-bold mb-3">✅ ¡VIDEO LISTO!</h3>
        <div class="preview-area">
            <div id="vidContainer" style="width:100%; height:100%;"></div>
        </div>
        <a id="dlBtn" href="#" class="btn-action text-decoration-none d-block">⬇️ DESCARGAR</a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3 text-decoration-none">CREAR OTRO</button>
    </div>

</div>

<script>
// Contador caracteres
document.getElementById('titleIn').addEventListener('input', function(){
    this.value = this.value.toUpperCase();
    document.getElementById('charCount').innerText = this.value.length;
});

// Lógica de App
const form = document.getElementById('uploadForm');
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if(!document.getElementById('vFile').files.length) return alert("Falta el video");
    
    document.getElementById('step1').classList.add('hidden');
    document.getElementById('step2').classList.remove('hidden');

    const formData = new FormData(form);
    try {
        const res = await fetch('?action=upload', { method: 'POST', body: formData });
        const data = await res.json();
        if(data.status === 'success') track(data.jobId);
        else { alert(data.message); location.reload(); }
    } catch { alert("Error de red"); location.reload(); }
});

function track(id) {
    let t = 0;
    const i = setInterval(async () => {
        t++; if(t>120) { clearInterval(i); alert("Tiempo agotado"); location.reload(); }
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
    document.getElementById('step2').classList.add('hidden');
    document.getElementById('step3').classList.remove('hidden');
    document.getElementById('dlBtn').href = data.download_url;
    
    // Truco anti-caché y permisos
    const url = 'processed/' + data.filename + '?t=' + Date.now();
    document.getElementById('vidContainer').innerHTML = `<video width="100%" height="100%" controls autoplay muted loop playsinline><source src="${url}" type="video/mp4"></video>`;
}
</script>
</body>
</html>
