<?php
// ==========================================
// VIRAL REELS MAKER v26.0 (STREET NEWS EDITION)
// Características: Espejo Opcional + Protección sin Voltear
// ==========================================
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '2048M');

// 1. DIRECTORIOS
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';

// Crear carpetas
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); chmod($uploadDir, 0777); }
if (!file_exists($processedDir)) { mkdir($processedDir, 0777, true); chmod($processedDir, 0777); }
if (!file_exists($jobsDir)) { mkdir($jobsDir, 0777, true); chmod($jobsDir, 0777); }

// LIMPIEZA (1 Hora)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 3600)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> DESCARGA FORZADA
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = "$processedDir/$file";
    if (file_exists($filePath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="NOTICIA_VIRAL_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        ob_clean(); flush(); readfile($filePath);
        exit;
    }
}

// ---> PROCESAR VIDEO
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida.']); exit;
    }

    $jobId = uniqid('v26_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile);
    chmod($inputFile, 0777);

    // Opciones del Usuario
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true'; // ¿Activar Espejo?

    // --- LÓGICA DE TEXTO (Manual v23) ---
    $rawTitle = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $rawTitle = mb_strtoupper($rawTitle);
    
    // Cortar líneas (16 chars)
    $wrappedText = wordwrap($rawTitle, 16, "\n", true);
    $lines = explode("\n", $wrappedText);
    if (count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    // Tamaños
    if ($count == 1) {
        $barH = 240; $fSize = 115; $yPos = [135]; 
    } elseif ($count == 2) {
        $barH = 330; $fSize = 100; $yPos = [100, 210]; 
    } else {
        $barH = 420; $fSize = 80; $yPos = [90, 190, 290]; 
    }

    // --- COMANDO FFMPEG ---
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($useAudio) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $filter = "";
    
    // 1. FILTROS VISUALES (Original vs Espejo)
    // Siempre aplicamos un pequeño ZOOM (scale w+20) y CROP para quitar bordes sucios
    // Siempre aplicamos COLOR y RUIDO para cambiar el Hash.
    
    $mirrorFilter = $useMirror ? ",hflip" : ""; // Solo voltea si se pide
    
    // boxblur=20:10 -> Fondo borroso
    // scale=...crop -> Zoom sutil (Anti-bordes negros)
    // eq=... -> Corrección de color HD
    // noise -> Huella digital única
    
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10{$mirrorFilter}[bg];";
    
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,setsar=1,eq=contrast=1.1:saturation=1.15,noise=alls=2:allf=t+u{$mirrorFilter}[fg];";
    
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
    $lastStream = "[base]";

    // 2. BARRA NEGRA
    $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h={$barH}:color=black@0.9:t=fill[bar];";
    $lastStream = "[bar]";

    // 3. LOGO
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:130[logo_s];";
        $logoY = 60 + ($barH/2) - 65; 
        $filter .= "{$lastStream}[logo_s]overlay=40:{$logoY}[wlogo];";
        $lastStream = "[wlogo]";
    }

    // 4. TEXTO
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        $xPos = $useLogo ? "(w-text_w)/2+70" : "(w-text_w)/2";
        
        foreach ($lines as $i => $line) {
            $y = $yPos[$i];
            $streamOut = ($i == $count - 1) ? "titled" : "txt{$i}";
            $streamIn = ($i == 0) ? $lastStream : "[txt".($i-1)."]";
            $draw = "drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=5:bordercolor=black:x={$xPos}:y={$y}";
            $filter .= "{$streamIn}{$draw}[{$streamOut}];";
        }
        $lastStream = "[titled]";
    }

    // 5. AUDIO
    $filter .= "{$lastStream}setpts=0.94*PTS[vfinal];";
    if ($useAudio) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= "[{$mIdx}:a]volume=0.15[bgmusic];[0:a]volume=1.0[voice];[voice][bgmusic]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= "[0:a]atempo=1.0638[afinal]";
    }

    // EJECUCIÓN
    $cmd = "ffmpeg -y $inputs -filter_complex \"$filter\" -map \"[vfinal]\" -map \"[afinal]\" -c:v libx264 -preset veryfast -crf 26 -r 30 -pix_fmt yuv420p -c:a aac -ar 44100 -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " > /dev/null 2>&1 &";

    exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName]));
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> STATUS
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . $data['file'];
        
        if (file_exists($fullPath) && filesize($fullPath) > 50000) {
            chmod($fullPath, 0777); 
            echo json_encode(['status' => 'finished', 'file' => $data['file']]);
        } else {
            echo json_encode(['status' => 'processing']);
        }
    } else { echo json_encode(['status' => 'error']); }
    exit;
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Street News Maker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background-color: #000; font-family: 'Inter', sans-serif; color: white; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .main-card { background: #111; width: 100%; max-width: 500px; border: 2px solid #333; border-radius: 20px; padding: 30px; }
        .header-title { font-family: 'Anton', sans-serif; text-align: center; color: #FFD700; font-size: 2.5rem; text-transform: uppercase; margin: 0; }
        
        .viral-input { background: #000; border: 2px solid #444; color: white; font-family: 'Anton'; font-size: 1.5rem; text-transform: uppercase; padding: 15px; width: 100%; border-radius: 10px; }
        .upload-area { border: 2px dashed #444; border-radius: 10px; padding: 20px; text-align: center; margin-top: 20px; cursor: pointer; transition: 0.2s; }
        .upload-area:hover { background: #1a1a1a; border-color: #fff; }
        
        .btn-viral { background: #FFD700; color: #000; border: none; width: 100%; padding: 20px; font-family: 'Anton'; font-size: 1.5rem; text-transform: uppercase; border-radius: 10px; margin-top: 25px; cursor: pointer; }
        
        .video-box { background: #000; border: 2px solid #333; border-radius: 15px; overflow: hidden; width: 100%; aspect-ratio: 9/16; margin-bottom: 20px; }
        video { width: 100%; height: 100%; object-fit: cover; }
        
        /* Checkbox Custom */
        .form-check-input:checked { background-color: #FFD700; border-color: #FFD700; }
        .hidden { display: none; }
    </style>
</head>
<body>

<div class="main-card">
    <h1 class="header-title">STREET NEWS</h1>
    <p class="text-center text-secondary small">Formato Original (Sin Espejo) por Defecto</p>

    <?php if(!file_exists($audioPath)) echo '<div class="alert alert-warning p-1 text-center small">⚠️ Falta news.mp3</div>'; ?>

    <div id="uiInput">
        <form id="vForm">
            <div class="mb-3">
                <label class="fw-bold text-warning small">TÍTULO NOTICIA</label>
                <textarea name="videoTitle" id="tIn" class="viral-input" rows="2" placeholder="ESCRIBE TU TÍTULO..." required></textarea>
            </div>

            <div class="form-check form-switch mb-4 p-3 border border-secondary rounded bg-dark">
                <input class="form-check-input" type="checkbox" id="mirrorCheck">
                <label class="form-check-label text-white ms-2" for="mirrorCheck">
                    <strong>Activar Modo Espejo</strong><br>
                    <span class="text-muted small">(Úsalo solo si el video NO tiene texto)</span>
                </label>
            </div>

            <div class="upload-area" onclick="document.getElementById('fIn').click()">
                <div class="fs-1">📂</div>
                <div class="fw-bold mt-2">Subir Video</div>
                <input type="file" name="videoFile" id="fIn" accept="video/*" hidden required onchange="this.parentElement.style.borderColor='#FFD700'; this.parentElement.querySelector('.fw-bold').innerText='✅ Video Listo'">
            </div>

            <button type="submit" class="btn-viral">🚀 PROCESAR</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-5">
        <div class="spinner-grow text-warning mb-3"></div>
        <h3 class="fw-bold">Procesando...</h3>
        <p class="text-muted small">Aplicando protección de copyright.</p>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h3 class="text-success fw-bold mb-3">¡Noticia Lista!</h3>
        <div class="video-box">
            <div id="vidWrap" style="width:100%; height:100%;"></div>
        </div>
        
        <a id="dlBtn" href="#" class="btn-viral text-decoration-none d-block">⬇️ Descargar MP4</a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3 text-decoration-none">Crear otro</button>
    </div>
</div>

<script>
document.getElementById('tIn').addEventListener('input', function() { this.value = this.value.toUpperCase(); });

document.getElementById('vForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if(!document.getElementById('fIn').files.length) return alert("Sube un video");

    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData(e.target);
    // Enviamos el estado del checkbox
    fd.append('mirrorMode', document.getElementById('mirrorCheck').checked);

    try {
        const req = await fetch('?action=upload', { method: 'POST', body: fd });
        const res = await req.json();
        if(res.status === 'success') track(res.jobId);
        else { alert(res.message); location.reload(); }
    } catch { alert("Error de red"); location.reload(); }
});

function track(id) {
    let t = 0;
    const i = setInterval(async () => {
        t++;
        if(t > 180) { clearInterval(i); alert("Tiempo agotado"); location.reload(); }
        try {
            const req = await fetch(`?action=status&jobId=${id}`);
            const res = await req.json();
            if(res.status === 'finished') {
                clearInterval(i);
                show(res.file);
            }
        } catch {}
    }, 3000);
}

function show(filename) {
    document.getElementById('uiProcess').classList.add('hidden');
    document.getElementById('uiResult').classList.remove('hidden');
    
    const fileUrl = 'processed/' + filename;
    const previewUrl = fileUrl + '?t=' + Date.now();
    const downloadUrl = '?action=download&file=' + filename;
    
    document.getElementById('dlBtn').href = downloadUrl;
    document.getElementById('vidWrap').innerHTML = `<video width="100%" height="100%" controls autoplay muted loop playsinline><source src="${previewUrl}" type="video/mp4"></video>`;
}
</script>
</body>
</html>
