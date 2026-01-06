<?php
// ==========================================
// VIRAL REELS MAKER v39.0 (PORTABLE ENGINE + 16GB POWER)
// Solución: Descarga un FFmpeg portátil que no se borra al reiniciar.
// Calidad: Full HD (1080p) aprovechando tus 16GB de RAM.
// ==========================================

// Configuración "Bestia" (Aprovechando tu servidor)
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', 1200); 
@ini_set('memory_limit', '4096M'); // 4GB para PHP
@ini_set('display_errors', 0);

// Rutas
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/debug_log.txt';

// Ruta del FFmpeg Portátil
$ffmpegDir = $baseDir . '/bin';
$ffmpegBin = $ffmpegDir . '/ffmpeg'; 

// Crear carpetas
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) mkdir($jobsDir, 0777, true);
if (!file_exists($ffmpegDir)) mkdir($ffmpegDir, 0777, true);

// Limpieza
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 3600)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> VERIFICAR SI TENEMOS MOTOR
$hasFFmpeg = file_exists($ffmpegBin) && is_executable($ffmpegBin);

// ---> ACCIÓN: INSTALAR FFMPEG (Auto-Download)
if ($action === 'install_ffmpeg') {
    header('Content-Type: application/json');
    if ($hasFFmpeg) { echo json_encode(['status'=>'ok', 'msg'=>'Ya instalado']); exit; }
    
    // Descargar versión estática (John Van Sickle)
    $url = "https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz";
    $tarFile = $baseDir . '/ffmpeg.tar.xz';
    
    // 1. Descargar
    file_put_contents($tarFile, fopen($url, 'r'));
    if (!file_exists($tarFile) || filesize($tarFile) < 1000000) {
        echo json_encode(['status'=>'error', 'msg'=>'Error descargando FFmpeg']); exit;
    }
    
    // 2. Descomprimir
    // Usamos shell_exec porque PHP nativo no siempre maneja .tar.xz
    shell_exec("tar -xf " . escapeshellarg($tarFile) . " -C " . escapeshellarg($baseDir));
    
    // 3. Mover binario
    // Busca la carpeta extraída (el nombre cambia con la versión)
    $extractedDirs = glob($baseDir . '/ffmpeg-*-static');
    if (!empty($extractedDirs)) {
        $sourceBin = $extractedDirs[0] . '/ffmpeg';
        rename($sourceBin, $ffmpegBin);
        chmod($ffmpegBin, 0775); // Hacer ejecutable
        
        // Limpieza
        shell_exec("rm -rf " . escapeshellarg($extractedDirs[0]));
        unlink($tarFile);
        
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'Error al descomprimir']);
    }
    exit;
}

// ---> LOGS
if ($action === 'viewlog') {
    header('Content-Type: text/plain');
    echo file_exists($logFile) ? file_get_contents($logFile) : "Log vacío.";
    exit;
}

// ---> DESCARGA
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = "$processedDir/$file";
    if (file_exists($filePath)) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="VIRAL_PRO_16GB.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// ---> SUBIDA Y PROCESAMIENTO
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!$hasFFmpeg) {
        echo json_encode(['status' => 'error', 'message' => 'Motor FFmpeg no instalado. Pulsa el botón instalar.']); exit;
    }

    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error subida.']); exit;
    }

    $jobId = uniqid('v39_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile);
    chmod($inputFile, 0777);

    // --- CONFIGURACIÓN DE VIDEO (1080p FULL HD) ---
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $audioPath = file_exists($audioPath) ? $audioPath : false;
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';

    // Texto
    $rawTitle = mb_strtoupper($_POST['videoTitle'] ?? '');
    $wrappedText = wordwrap($rawTitle, 18, "\n", true);
    $lines = explode("\n", $wrappedText);
    if(count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    // Tamaños para 1080p
    if ($count == 1) { $barH = 240; $fSize = 110; $yPos = [140]; }
    elseif ($count == 2) { $barH = 350; $fSize = 100; $yPos = [100, 215]; }
    else { $barH = 450; $fSize = 80; $yPos = [90, 190, 290]; }

    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($audioPath) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";

    // 1. FONDO (Blur 1080p) - Ahora sí podemos usarlo porque tienes RAM de sobra
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10{$mirrorCmd}[bg];";

    // 2. FRENTE (Video Centrado)
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease{$mirrorCmd}[fg];";

    // 3. OVERLAY
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2:format=auto[base];";
    $lastStream = "[base]";

    // 4. BARRA
    $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h={$barH}:color=black@0.9:t=fill";

    // 5. TEXTO
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        foreach ($lines as $i => $line) {
            $y = $yPos[$i];
            $filter .= ",drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=4:bordercolor=black:shadowx=2:shadowy=2:x=(w-text_w)/2:y={$y}";
        }
    }
    $filter .= "[vtext];";
    $lastStream = "[vtext]";

    // 6. LOGO
    if ($useLogo) {
        $logoY = 60 + ($barH/2) - 70;
        $filter .= "[1:v]scale=-1:140[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=40:{$logoY}[vfinal]";
        $lastStream = "[vfinal]";
    } else {
        $filter .= "{$lastStream}copy[vfinal]";
    }

    // 7. AUDIO
    if ($audioPath) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= "[{$mIdx}:a]volume=0.15[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= "[0:a]atempo=1.0[afinal]";
    }

    // EJECUCIÓN USANDO EL BINARIO PORTÁTIL
    // Usamos el path absoluto $ffmpegBin
    $cmd = "nice -n 10 " . escapeshellarg($ffmpegBin) . " -y $inputs -filter_complex \"$filter\" -map \"$lastStream\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -crf 26 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    file_put_contents($logFile, "\n--- JOB $jobId (16GB POWER) ---\nCMD: $cmd\n", FILE_APPEND);
    exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName, 'start' => time()]));
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
            if (time() - $data['start'] > 900) echo json_encode(['status' => 'error', 'message' => 'Timeout']);
            else echo json_encode(['status' => 'processing']);
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
    <title>Viral PRO 16GB</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background-color: #000; font-family: 'Inter', sans-serif; color: white; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .main-card { background: #111; width: 100%; max-width: 500px; border: 1px solid #333; border-radius: 20px; padding: 30px; box-shadow: 0 0 50px rgba(0,255,200,0.1); }
        .header-title { font-family: 'Anton', sans-serif; color: #00ffc8; font-size: 2.2rem; text-align: center; margin: 0; }
        .viral-input { background: #000; border: 2px solid #333; color: white; font-family: 'Anton'; font-size: 1.4rem; padding: 15px; width: 100%; border-radius: 10px; margin-bottom: 20px; }
        .btn-viral { background: #00ffc8; color: #000; border: none; width: 100%; padding: 18px; font-family: 'Anton'; font-size: 1.4rem; border-radius: 12px; cursor: pointer; transition: 0.2s; }
        .btn-viral:hover { transform: scale(1.02); background: #fff; }
        .btn-install { background: #ff3d00; color: white; font-weight: bold; width: 100%; padding: 15px; border-radius: 10px; border: none; margin-bottom: 20px; cursor: pointer; }
        
        .hidden { display: none; }
        .video-box { width: 100%; aspect-ratio: 9/16; background: #000; margin-bottom: 20px; border-radius: 15px; overflow: hidden; border: 1px solid #333; }
        video { width: 100%; height: 100%; object-fit: cover; }
        .progress-bar { height: 5px; background: #333; width: 100%; margin-top: 20px; border-radius: 3px; }
        .progress-fill { height: 100%; background: #00ffc8; width: 0%; transition: width 0.3s; }
    </style>
</head>
<body>

<div class="main-card">
    <h1 class="header-title">VIRAL PRO 16GB</h1>
    <p class="text-center text-secondary small mb-4">High Performance Mode</p>

    <?php if(!$hasFFmpeg): ?>
    <div id="installScreen" class="text-center">
        <div class="alert alert-warning small">⚠️ Motor de video no detectado</div>
        <p class="small text-secondary">Tu servidor se reinició y borró el motor. Haz clic para reinstalarlo permanentemente en tu carpeta.</p>
        <button id="btnInstall" class="btn-install" onclick="installEngine()">📥 INSTALAR MOTOR (1 MIN)</button>
        <div id="installLog" class="small text-info mt-2"></div>
    </div>
    <?php else: ?>
        <div class="alert alert-success small text-center py-1 mb-4">✅ Motor Activo | RAM Disponible: 16GB</div>
    <?php endif; ?>

    <div id="uiInput" class="<?php echo $hasFFmpeg ? '' : 'hidden'; ?>">
        <form id="vForm">
            <input type="text" name="videoTitle" id="tIn" class="viral-input" placeholder="TÍTULO GANCHO..." required autocomplete="off">
            
            <div class="p-4 border border-secondary border-dashed rounded text-center mb-4" onclick="document.getElementById('fIn').click()" style="cursor:pointer; background:#050505;">
                <div class="fs-1">⚡</div>
                <div class="fw-bold mt-2" id="fName">Subir Video HD</div>
                <input type="file" name="videoFile" id="fIn" accept="video/*" hidden required>
            </div>

            <div class="form-check form-switch mb-3 d-flex justify-content-center gap-2">
                <input class="form-check-input" type="checkbox" id="mirrorCheck">
                <label class="form-check-label text-white small" for="mirrorCheck">Modo Espejo (Anti-Copyright)</label>
            </div>

            <button type="submit" class="btn-viral">🚀 RENDERIZAR</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-5">
        <div class="spinner-border text-info mb-3"></div>
        <h3 class="fw-bold">PROCESANDO...</h3>
        <div class="progress-bar"><div id="pFill" class="progress-fill"></div></div>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h3 class="text-success fw-bold mb-3">✅ VIDEO LISTO</h3>
        <div class="video-box"><div id="vidWrap" style="width:100%; height:100%;"></div></div>
        <a id="dlBtn" href="#" class="btn-viral text-decoration-none d-block">⬇️ DESCARGAR FULL HD</a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3">Nuevo</button>
    </div>
    
    <a href="?action=viewlog" target="_blank" class="d-block text-center mt-4 text-secondary small text-decoration-none" style="opacity:0.3;">Logs</a>
</div>

<script>
// AUTO-INSTALLER LOGIC
async function installEngine() {
    const btn = document.getElementById('btnInstall');
    const log = document.getElementById('installLog');
    btn.disabled = true;
    btn.innerText = "⏳ DESCARGANDO... (NO CIERRES)";
    log.innerText = "Conectando con repositorio...";
    
    try {
        const res = await fetch('?action=install_ffmpeg');
        const data = await res.json();
        if(data.status === 'success' || data.status === 'ok') {
            log.innerText = "¡Instalado! Recargando...";
            setTimeout(() => location.reload(), 1500);
        } else {
            btn.disabled = false;
            btn.innerText = "REINTENTAR";
            alert("Error: " + data.msg);
        }
    } catch(e) {
        btn.disabled = false;
        alert("Error de conexión");
    }
}

// EDITOR LOGIC
const tIn = document.getElementById('tIn');
const fIn = document.getElementById('fIn');
if(tIn) tIn.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
if(fIn) fIn.addEventListener('change', function() { if(this.files[0]) document.getElementById('fName').innerText = '✅ ' + this.files[0].name; });

document.getElementById('vForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    if(!fIn.files.length) return alert("Sube un video");

    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData(this);
    fd.append('mirrorMode', document.getElementById('mirrorCheck').checked);
    const xhr = new XMLHttpRequest();
    
    xhr.upload.addEventListener("progress", (evt) => {
        if (evt.lengthComputable) {
            const p = Math.round((evt.loaded / evt.total) * 40);
            document.getElementById('pFill').style.width = p + '%';
        }
    });

    xhr.onload = () => {
        if (xhr.status === 200) {
            try {
                const res = JSON.parse(xhr.responseText);
                if(res.status === 'success') track(res.jobId);
                else { alert(res.message); location.reload(); }
            } catch { alert("Error respuesta servidor"); location.reload(); }
        } else { alert("Error conexión"); location.reload(); }
    };
    xhr.send(fd);
});

function track(id) {
    let p = 40;
    const fill = document.getElementById('pFill');
    const fake = setInterval(() => { if(p < 95) { p+=0.3; fill.style.width = p+'%'; } }, 1000);

    let attempts = 0;
    const check = setInterval(async () => {
        attempts++;
        if(attempts > 600) { clearInterval(check); clearInterval(fake); alert("Timeout"); location.reload(); }
        try {
            const res = await (await fetch(`?action=status&jobId=${id}`)).json();
            if(res.status === 'finished') {
                clearInterval(check); clearInterval(fake);
                fill.style.width = '100%';
                show(res.file);
            } else if(res.status === 'error') {
                clearInterval(check); clearInterval(fake);
                alert(res.message); location.reload();
            }
        } catch {}
    }, 2000);
}

function show(file) {
    document.getElementById('uiProcess').classList.add('hidden');
    document.getElementById('uiResult').classList.remove('hidden');
    document.getElementById('dlBtn').href = '?action=download&file=' + file;
    document.getElementById('vidWrap').innerHTML = `<video width="100%" height="100%" controls autoplay muted loop playsinline><source src="processed/${file}?t=${Date.now()}" type="video/mp4"></video>`;
}
</script>
</body>
</html>
