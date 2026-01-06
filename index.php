<?php
// ==========================================
// VIRAL REELS MAKER v42.0 (SOLID ROCK EDITION)
// Solución definitiva: Elimina el filtro 'boxblur' que causa el crash de /dev/shm.
// Usa instalación nativa (APT) para el motor.
// ==========================================

// Configuración Robusta
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', 1200); 
@ini_set('memory_limit', '2048M'); 
@ini_set('display_errors', 0);

// Directorios
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/process_log.txt';

// Crear carpetas
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) mkdir($jobsDir, 0777, true);

// Limpieza
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 3600)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> DETECTAR MOTOR
// Intentamos ejecutar ffmpeg para ver si existe
$ffmpegVersion = shell_exec("ffmpeg -version 2>&1");
$hasEngine = (strpos($ffmpegVersion, 'ffmpeg version') !== false);

// ---> ACCIÓN: REPARAR SISTEMA (Instalación Nativa)
if ($action === 'install_native') {
    header('Content-Type: application/json');
    
    // Ejecutamos los comandos de instalación del sistema directamente
    // Esto funciona porque en Docker el usuario suele ser root o tener permisos
    $cmd = "apt-get update && apt-get install -y ffmpeg";
    $output = shell_exec($cmd . " 2>&1");
    
    // Verificamos de nuevo
    $check = shell_exec("ffmpeg -version 2>&1");
    if (strpos($check, 'ffmpeg version') !== false) {
        echo json_encode(['status'=>'success', 'msg'=>'Motor instalado correctamente.']);
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'Falló la instalación automática. Log: ' . substr($output, 0, 100)]);
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
        header('Content-Disposition: attachment; filename="VIRAL_FINAL_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// ---> PROCESAMIENTO
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!$hasEngine) { echo json_encode(['status'=>'error', 'message'=>'Falta motor.']); exit; }
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status'=>'error', 'message'=>'Error subida.']); exit;
    }

    $jobId = uniqid('v42_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile);
    chmod($inputFile, 0777);

    // Recursos
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

    // Ajustes 720p (HD Estable)
    if ($count == 1) { $barH = 160; $fSize = 75; $yPos = [90]; }
    elseif ($count == 2) { $barH = 240; $fSize = 65; $yPos = [70, 145]; }
    else { $barH = 300; $fSize = 55; $yPos = [60, 130, 200]; }

    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($audioPath) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";

    // --- FILTRO SIN BLUR (ESTABILIDAD TOTAL) ---
    // 1. Fondo Sólido Oscuro (Gris #111) en lugar de Blur
    // Esto evita el uso de memoria compartida (/dev/shm) que causa el crash
    $filter .= "color=c=#111111:s=720x1280[bg];";

    // 2. Video Principal (Escalado para encajar)
    $filter .= "[0:v]scale=720:1280:force_original_aspect_ratio=decrease{$mirrorCmd}[fg];";

    // 3. Composición Simple
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2:format=auto[base];";
    $lastStream = "[base]";

    // 4. Barra Negra
    $filter .= "{$lastStream}drawbox=x=0:y=40:w=iw:h={$barH}:color=black@0.9:t=fill";

    // 5. Texto
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        foreach ($lines as $i => $line) {
            $y = $yPos[$i];
            $filter .= ",drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=3:bordercolor=black:shadowx=2:shadowy=2:x=(w-text_w)/2:y={$y}";
        }
    }
    $filter .= "[vtext];";
    $lastStream = "[vtext]";

    // 6. Logo
    if ($useLogo) {
        $logoY = 40 + ($barH/2) - 45;
        $filter .= "[1:v]scale=-1:90[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=25:{$logoY}[vfinal]";
        $lastStream = "[vfinal]";
    } else {
        $filter .= "{$lastStream}copy[vfinal]";
    }

    // 7. Audio
    if ($audioPath) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= "[{$mIdx}:a]volume=0.15[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= "[0:a]atempo=1.0[afinal]";
    }

    // Ejecución con 1 hilo para evitar picos de CPU
    $cmd = "nice -n 10 ffmpeg -y $inputs -filter_complex \"$filter\" -map \"$lastStream\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -threads 1 -crf 28 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    file_put_contents($logFile, "\n--- JOB $jobId (NO BLUR) ---\nCMD: $cmd\n", FILE_APPEND);
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
            if (time() - $data['start'] > 600) echo json_encode(['status' => 'error', 'message' => 'Timeout.']);
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
    <title>Viral Rock v42</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background-color: #0d0d0d; font-family: 'Inter', sans-serif; color: white; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .main-card { background: #1a1a1a; width: 100%; max-width: 500px; border: 1px solid #333; border-radius: 20px; padding: 30px; box-shadow: 0 0 40px rgba(0,0,0,0.5); }
        .header-title { font-family: 'Anton', sans-serif; color: #fff; font-size: 2.2rem; text-align: center; margin: 0; }
        .accent { color: #ffd700; }
        .viral-input { background: #000; border: 2px solid #333; color: white; font-family: 'Anton'; font-size: 1.4rem; padding: 15px; width: 100%; border-radius: 10px; margin-bottom: 20px; }
        .btn-viral { background: #ffd700; color: #000; border: none; width: 100%; padding: 18px; font-family: 'Anton'; font-size: 1.4rem; border-radius: 12px; cursor: pointer; transition: 0.2s; }
        .btn-fix { background: #dc3545; color: white; width: 100%; padding: 15px; border-radius: 10px; border: none; font-weight: bold; margin-bottom: 20px; cursor: pointer; }
        
        .hidden { display: none; }
        .video-box { width: 100%; aspect-ratio: 9/16; background: #000; margin-bottom: 20px; border-radius: 15px; overflow: hidden; border: 1px solid #333; }
        video { width: 100%; height: 100%; object-fit: cover; }
        .progress-bar { height: 6px; background: #333; width: 100%; margin-top: 20px; border-radius: 3px; }
        .progress-fill { height: 100%; background: #ffd700; width: 0%; transition: width 0.3s; }
    </style>
</head>
<body>

<div class="main-card">
    <h1 class="header-title">VIRAL <span class="accent">ROCK</span></h1>
    <p class="text-center text-secondary small mb-4">Sistema de Estabilidad Total</p>

    <?php if(!$hasEngine): ?>
    <div id="repairScreen" class="text-center p-3 border border-danger rounded mb-3" style="background: #2c0b0e;">
        <h5 class="text-danger fw-bold">⚠️ MOTOR DETENIDO</h5>
        <p class="small text-secondary mb-3">El servidor se reinició. Haz clic para reactivar el sistema.</p>
        <button id="btnRepair" class="btn-fix" onclick="repairSystem()">🔄 REACTIVAR AHORA</button>
        <div id="repairLog" class="small text-white"></div>
    </div>
    <?php else: ?>
        <div class="alert alert-success text-center py-1 small mb-4">✅ Sistema Activo y Listo</div>
    <?php endif; ?>

    <div id="uiInput" class="<?php echo $hasEngine ? '' : 'hidden'; ?>">
        <form id="vForm">
            <input type="text" name="videoTitle" id="tIn" class="viral-input" placeholder="TÍTULO GANCHO..." required autocomplete="off">
            
            <div class="p-4 border border-secondary border-dashed rounded text-center mb-4" onclick="document.getElementById('fIn').click()" style="cursor:pointer; background:#050505;">
                <div class="fs-1">⚡</div>
                <div class="fw-bold mt-2" id="fName">Subir Video</div>
                <input type="file" name="videoFile" id="fIn" accept="video/*" hidden required>
            </div>

            <div class="form-check form-switch mb-3 d-flex justify-content-center gap-2">
                <input class="form-check-input" type="checkbox" id="mirrorCheck">
                <label class="form-check-label text-white small" for="mirrorCheck">Modo Espejo</label>
            </div>

            <button type="submit" class="btn-viral">🚀 PROCESAR</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-5">
        <div class="spinner-border text-warning mb-3"></div>
        <h3 class="fw-bold">PROCESANDO...</h3>
        <div class="progress-bar"><div id="pFill" class="progress-fill"></div></div>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h3 class="text-success fw-bold mb-3">✅ ¡LISTO!</h3>
        <div class="video-box"><div id="vidWrap" style="width:100%; height:100%;"></div></div>
        <a id="dlBtn" href="#" class="btn-viral text-decoration-none d-block">⬇️ DESCARGAR</a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3">Nuevo</button>
    </div>
    
    <a href="?action=viewlog" target="_blank" class="d-block text-center mt-4 text-secondary small text-decoration-none" style="opacity:0.3;">Ver Logs</a>
</div>

<script>
async function repairSystem() {
    const btn = document.getElementById('btnRepair');
    const log = document.getElementById('repairLog');
    btn.disabled = true;
    btn.innerText = "⏳ INSTALANDO...";
    
    try {
        const res = await fetch('?action=install_native');
        const data = await res.json();
        if(data.status === 'success') {
            log.innerText = "¡Éxito! Recargando...";
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
