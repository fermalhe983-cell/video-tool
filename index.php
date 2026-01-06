<?php
// ==========================================
// VIRAL REELS MAKER v40.0 (SELF-HEALING + PORTABLE ENGINE)
// Soluciona: Reinicios del servidor y desaparición de FFmpeg.
// Estrategia: Motor portátil local + Calidad HD 720p (Estable).
// ==========================================

// Configuración de Servidor
@ini_set('upload_max_filesize', '1024M');
@ini_set('post_max_size', '1024M');
@ini_set('max_execution_time', 600); // 10 Minutos
@ini_set('memory_limit', '1024M'); 
@ini_set('display_errors', 0);

// 1. DIRECTORIOS
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$binDir = $baseDir . '/bin'; // Carpeta para el motor
$ffmpegBin = $binDir . '/ffmpeg'; // El archivo ejecutable
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/debug_log.txt';

// Crear carpetas
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) mkdir($jobsDir, 0777, true);
if (!file_exists($binDir)) mkdir($binDir, 0777, true);

// Limpieza
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 3600)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> VERIFICAR MOTOR
// Comprobamos si tenemos el motor portátil funcionando
$hasEngine = file_exists($ffmpegBin) && filesize($ffmpegBin) > 1000000;
if ($hasEngine) chmod($ffmpegBin, 0775); // Asegurar permisos siempre

// ---> ACCIÓN: INSTALAR MOTOR (REPARACIÓN)
if ($action === 'install_engine') {
    header('Content-Type: application/json');
    
    // URL del binario estático (Versión ligera y compatible amd64)
    $url = "https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz";
    $tarFile = $baseDir . '/ffmpeg.tar.xz';
    
    // 1. Descargar
    $fp = fopen($tarFile, 'w+');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    
    if (!file_exists($tarFile) || filesize($tarFile) < 1000000) {
        echo json_encode(['status'=>'error', 'msg'=>'Fallo descarga. Internet lento en VPS?']); exit;
    }
    
    // 2. Descomprimir (Usando sistema)
    shell_exec("tar -xf " . escapeshellarg($tarFile) . " -C " . escapeshellarg($binDir));
    
    // 3. Buscar y Mover
    // El tar crea una carpeta tipo 'ffmpeg-5.1.1-amd64-static', hay que buscarla
    $subDirs = glob($binDir . '/ffmpeg-*-static');
    if (!empty($subDirs)) {
        $extractedBin = $subDirs[0] . '/ffmpeg';
        if (file_exists($extractedBin)) {
            rename($extractedBin, $ffmpegBin);
            chmod($ffmpegBin, 0775);
            // Limpieza
            shell_exec("rm -rf " . escapeshellarg($subDirs[0]));
            @unlink($tarFile);
            echo json_encode(['status'=>'success']);
        } else {
            echo json_encode(['status'=>'error', 'msg'=>'No se encontró el binario dentro del zip.']);
        }
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'Fallo descomrpesión.']);
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
        header('Content-Disposition: attachment; filename="VIRAL_PRO_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// ---> PROCESAMIENTO
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!$hasEngine) { echo json_encode(['status'=>'error', 'message'=>'Falta el motor.']); exit; }
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status'=>'error', 'message'=>'Error subida.']); exit;
    }

    $jobId = uniqid('v40_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile);
    chmod($inputFile, 0777);

    // Configuración
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';

    // Texto
    $rawTitle = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $rawTitle = mb_strtoupper($rawTitle);
    $wrappedText = wordwrap($rawTitle, 18, "\n", true);
    $lines = explode("\n", $wrappedText);
    if (count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    // Ajustes 720p (HD - Balance perfecto)
    if ($count == 1) { $barH = 160; $fSize = 75; $yPos = [90]; }
    elseif ($count == 2) { $barH = 240; $fSize = 65; $yPos = [70, 145]; }
    else { $barH = 300; $fSize = 55; $yPos = [60, 130, 200]; }

    // --- COMANDO HD ESTABLE ---
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($useAudio) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";

    // 1. ESCALADO 720p (Eficiente)
    $filter .= "[0:v]scale=720:1280:force_original_aspect_ratio=decrease,pad=720:1280:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1{$mirrorCmd}[base];";
    $lastStream = "[base]";

    // 2. BARRA
    $filter .= "{$lastStream}drawbox=x=0:y=40:w=iw:h={$barH}:color=black@0.9:t=fill";

    // 3. TEXTO
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        foreach ($lines as $i => $line) {
            $y = $yPos[$i];
            $filter .= ",drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=3:bordercolor=black:shadowx=2:shadowy=2:x=(w-text_w)/2:y={$y}";
        }
    }
    $filter .= "[vtext];";
    $lastStream = "[vtext]";

    // 4. LOGO
    if ($useLogo) {
        $logoY = 40 + ($barH/2) - 45;
        $filter .= "[1:v]scale=-1:90[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=25:{$logoY}[vfinal_out];";
        $lastStream = "[vfinal_out]";
    } else {
        $filter .= "{$lastStream}copy[vfinal_out];";
    }

    // 5. AUDIO
    if ($useAudio) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= "[{$mIdx}:a]volume=0.15[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=2[afinal_out]";
    } else {
        $filter .= "[0:a]atempo=1.0[afinal_out]";
    }

    // EJECUCIÓN USANDO EL MOTOR PORTÁTIL
    $cmd = "nice -n 10 " . escapeshellarg($ffmpegBin) . " -y $inputs -filter_complex \"$filter\" -map \"$lastStream\" -map \"[afinal_out]\" -c:v libx264 -preset ultrafast -threads 1 -crf 27 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    file_put_contents($logFile, "\n--- JOB $jobId ---\nCMD: $cmd\n", FILE_APPEND);
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
            if (time() - $data['start'] > 600) echo json_encode(['status' => 'error', 'message' => 'Timeout']);
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
    <title>Viral Fix v40</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background-color: #000; font-family: 'Inter', sans-serif; color: white; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .main-card { background: #111; width: 100%; max-width: 500px; border: 1px solid #333; border-radius: 20px; padding: 30px; box-shadow: 0 0 40px rgba(0,123,255,0.2); }
        .header-title { font-family: 'Anton', sans-serif; color: #0d6efd; font-size: 2rem; text-align: center; margin: 0; }
        .viral-input { background: #000; border: 2px solid #333; color: white; font-family: 'Anton'; font-size: 1.4rem; padding: 15px; width: 100%; border-radius: 10px; margin-bottom: 20px; }
        .btn-viral { background: #0d6efd; color: white; border: none; width: 100%; padding: 18px; font-family: 'Anton'; font-size: 1.4rem; border-radius: 12px; cursor: pointer; transition: 0.2s; }
        .btn-viral:hover { background: #0b5ed7; }
        .btn-fix { background: #dc3545; color: white; font-weight: bold; width: 100%; padding: 15px; border-radius: 10px; border: none; margin-bottom: 20px; animation: pulse 2s infinite; cursor: pointer; }
        
        .hidden { display: none; }
        .video-box { width: 100%; aspect-ratio: 9/16; background: #000; margin-bottom: 20px; border-radius: 15px; overflow: hidden; border: 1px solid #333; }
        video { width: 100%; height: 100%; object-fit: cover; }
        .progress-bar { height: 5px; background: #333; width: 100%; margin-top: 20px; border-radius: 3px; }
        .progress-fill { height: 100%; background: #0d6efd; width: 0%; transition: width 0.3s; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.8; } 100% { opacity: 1; } }
    </style>
</head>
<body>

<div class="main-card">
    <h1 class="header-title">VIRAL FIX v40</h1>
    <p class="text-center text-secondary small mb-4">Auto-Healing System</p>

    <?php if(!$hasEngine): ?>
    <div id="repairScreen" class="text-center">
        <div class="alert alert-danger small">⚠️ ERROR CRÍTICO: MOTOR FALTANTE</div>
        <p class="small text-secondary">El servidor se reinició y borró el software de video. Pulsa abajo para repararlo automáticamente.</p>
        <button id="btnRepair" class="btn-fix" onclick="repairSystem()">🛠️ REPARAR SISTEMA (1 CLICK)</button>
        <div id="repairLog" class="small text-info mt-2"></div>
    </div>
    <?php else: ?>
        <div class="alert alert-success small text-center py-1 mb-4">✅ SISTEMA OPERATIVO Y LISTO</div>
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

            <button type="submit" class="btn-viral">🚀 PROCESAR VIDEO</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-5">
        <div class="spinner-border text-primary mb-3"></div>
        <h3 class="fw-bold">RENDERIZANDO...</h3>
        <div class="progress-bar"><div id="pFill" class="progress-fill"></div></div>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h3 class="text-success fw-bold mb-3">✅ ¡ÉXITO!</h3>
        <div class="video-box"><div id="vidWrap" style="width:100%; height:100%;"></div></div>
        <a id="dlBtn" href="#" class="btn-viral text-decoration-none d-block">⬇️ DESCARGAR VIDEO</a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3">Nuevo</button>
    </div>
    
    <a href="?action=viewlog" target="_blank" class="d-block text-center mt-4 text-secondary small text-decoration-none" style="opacity:0.3;">Logs</a>
</div>

<script>
// LÓGICA DE AUTO-REPARACIÓN
async function repairSystem() {
    const btn = document.getElementById('btnRepair');
    const log = document.getElementById('repairLog');
    btn.disabled = true;
    btn.innerText = "⏳ DESCARGANDO MOTOR...";
    log.innerText = "Conectando al repositorio...";
    
    try {
        const res = await fetch('?action=install_engine');
        const data = await res.json();
        if(data.status === 'success') {
            log.innerText = "¡Instalado! Reiniciando...";
            setTimeout(() => location.reload(), 1500);
        } else {
            btn.disabled = false;
            btn.innerText = "REINTENTAR";
            alert("Error: " + data.msg);
        }
    } catch(e) {
        btn.disabled = false;
        btn.innerText = "ERROR DE RED";
        alert("No se pudo conectar.");
    }
}

// LÓGICA DE EDITOR
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
