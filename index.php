<?php
// ==========================================
// VIRAL REELS MAKER v46.0 (BACK TO BASICS)
// Regreso a la arquitectura v6 (Estable) con diseño v45.
// Sin instalaciones de sistema, sin filtros pesados.
// ==========================================

// Configuración Máxima
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', 1200); 
@ini_set('memory_limit', '2048M'); 
@ini_set('display_errors', 0);

// Rutas
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$binDir = $baseDir . '/bin'; 
$ffmpegBin = $binDir . '/ffmpeg'; // Motor portátil
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

// Carpetas
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
$hasEngine = file_exists($ffmpegBin) && filesize($ffmpegBin) > 10000000;

// ---> DESCARGAR MOTOR (Método Seguro PHP - Sin apt-get)
if ($action === 'download_engine') {
    header('Content-Type: application/json');
    
    $url = "https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz";
    $tarFile = $baseDir . '/engine.tar.xz';
    
    // Descarga pura con PHP
    $fp = fopen($tarFile, 'w+');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    
    if (!file_exists($tarFile) || filesize($tarFile) < 1000000) {
        echo json_encode(['status'=>'error', 'msg'=>'Error de red descargando motor.']); exit;
    }
    
    // Descomprimir
    shell_exec("tar -xf " . escapeshellarg($tarFile) . " -C " . escapeshellarg($binDir));
    
    // Mover
    $subDirs = glob($binDir . '/ffmpeg-*-static');
    if (!empty($subDirs)) {
        rename($subDirs[0] . '/ffmpeg', $ffmpegBin);
        chmod($ffmpegBin, 0775);
        shell_exec("rm -rf " . escapeshellarg($subDirs[0]));
        unlink($tarFile);
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'Error al descomprimir.']);
    }
    exit;
}

// ---> DESCARGA VIDEO
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
    
    if (!$hasEngine) { echo json_encode(['status'=>'error', 'msg'=>'Falta el motor.']); exit; }

    $jobId = uniqid('v46_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status'=>'error', 'msg'=>'Error al guardar video.']); exit;
    }
    chmod($inputFile, 0777);

    // --- CONFIGURACIÓN ESTABLE ---
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $audioPath = file_exists($audioPath) ? $audioPath : false;
    
    // Texto
    $rawTitle = mb_strtoupper($_POST['videoTitle'] ?? '');
    $wrappedText = wordwrap($rawTitle, 18, "\n", true);
    $lines = explode("\n", $wrappedText);
    if(count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    // Ajustes 720p (Balance Perfecto)
    if ($count == 1) { $barH = 160; $fSize = 75; $yPos = [90]; }
    elseif ($count == 2) { $barH = 240; $fSize = 65; $yPos = [70, 145]; }
    else { $barH = 300; $fSize = 55; $yPos = [60, 130, 200]; }

    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($audioPath) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $filter = "";
    
    // 1. FONDO SÓLIDO (Evita crash de memoria compartida)
    $filter .= "color=c=#111111:s=720x1280[bg];";
    
    // 2. VIDEO
    $filter .= "[0:v]scale=720:1280:force_original_aspect_ratio=decrease[fg];";
    
    // 3. MEZCLA
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2:format=auto[base];";
    $lastStream = "[base]";

    // 4. BARRA
    $filter .= "{$lastStream}drawbox=x=0:y=40:w=iw:h={$barH}:color=black@0.9:t=fill";

    // 5. TEXTO
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        foreach ($lines as $i => $line) {
            $y = $yPos[$i];
            $filter .= ",drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=3:bordercolor=black:shadowx=2:shadowy=2:x=(w-text_w)/2:y={$y}";
        }
    }
    $filter .= "[vtext];";
    $lastStream = "[vtext]";

    // 6. LOGO
    if ($useLogo) {
        $logoY = 40 + ($barH/2) - 45;
        $filter .= "[1:v]scale=-1:90[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=25:{$logoY}[vfinal]";
        $lastStream = "[vfinal]";
    } else {
        $filter .= "{$lastStream}copy[vfinal]";
    }

    // 7. AUDIO (Con punto y coma crítico ;)
    if ($audioPath) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= ";[{$mIdx}:a]volume=0.15[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= ";[0:a]atempo=1.0[afinal]";
    }

    // EJECUCIÓN SEGURA
    // Usamos el binario portátil descargado
    $cmd = "nice -n 10 " . escapeshellarg($ffmpegBin) . " -y $inputs -filter_complex \"$filter\" -map \"$lastStream\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -threads 2 -crf 27 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    file_put_contents($logFile, "\n--- JOB $jobId ---\nCMD: $cmd\n", FILE_APPEND);
    exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName, 'start' => time()]));
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> ESTADO
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . $data['file'];
        
        if (file_exists($fullPath) && filesize($fullPath) > 100000) {
            chmod($fullPath, 0777);
            echo json_encode(['status' => 'finished', 'file' => $data['file']]);
        } else {
            // Leer error de log
            $logTail = shell_exec("tail -n 3 " . escapeshellarg($logFile));
            if (strpos($logTail, 'Error') !== false || strpos($logTail, 'Invalid') !== false) {
                 echo json_encode(['status' => 'error', 'msg' => 'Error: ' . substr($logTail, 0, 100)]);
            } elseif (time() - $data['start'] > 600) {
                 echo json_encode(['status' => 'error', 'msg' => 'Tiempo agotado.']);
            } else {
                 echo json_encode(['status' => 'processing']);
            }
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
    <title>Viral v46 Safe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background: #000; color: #fff; padding: 20px; font-family: sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #111; border: 1px solid #333; max-width: 500px; width: 100%; padding: 25px; border-radius: 20px; }
        h2 { color: #00ffc8; text-align: center; text-transform: uppercase; font-weight: 800; }
        .btn-action { width: 100%; padding: 15px; border-radius: 10px; border: none; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .btn-primary { background: #00ffc8; color: #000; }
        .btn-install { background: #ff3d00; color: #fff; animation: pulse 2s infinite; }
        .form-control { background: #000; border: 1px solid #333; color: white; padding: 12px; margin-bottom: 15px; }
        .form-control:focus { background: #000; color: white; border-color: #00ffc8; box-shadow: none; }
        .status-box { padding: 15px; border-radius: 10px; text-align: center; font-size: 0.9rem; margin-bottom: 20px; }
        .ready { background: rgba(0, 255, 200, 0.1); color: #00ffc8; border: 1px solid #00ffc8; }
        .not-ready { background: rgba(255, 61, 0, 0.1); color: #ff3d00; border: 1px solid #ff3d00; }
        .hidden { display: none; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.8; } 100% { opacity: 1; } }
        #videoContainer { width: 100%; aspect-ratio: 9/16; background: #000; margin-top: 20px; border-radius: 10px; overflow: hidden; border: 1px solid #333; }
        video { width: 100%; height: 100%; object-fit: cover; }
    </style>
</head>
<body>

<div class="card">
    <h2>Sistema v46</h2>
    <p class="text-center text-muted small mb-4">Back to Basics</p>

    <?php if($hasEngine): ?>
        <div class="status-box ready">✅ MOTOR PORTÁTIL LISTO</div>
        
        <div id="editorUI">
            <input type="text" id="tIn" class="form-control" placeholder="TÍTULO GANCHO..." autocomplete="off">
            <input type="file" id="fIn" class="form-control">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="mirrorCheck">
                <label class="form-check-label text-white small">Modo Espejo</label>
            </div>
            <button class="btn-action btn-primary" onclick="processVideo()">RENDERIZAR</button>
        </div>

    <?php else: ?>
        <div class="status-box not-ready">⚠️ FALTA MOTOR</div>
        <p class="text-center small text-secondary">Descargar versión segura (Sin apt-get).</p>
        <button id="btnInstall" class="btn-action btn-install" onclick="installEngine()">📥 DESCARGAR MOTOR (SAFE)</button>
        <div id="installLog" class="text-center small mt-2 text-muted"></div>
    <?php endif; ?>

    <div id="progressUI" class="hidden text-center mt-4">
        <div class="spinner-border text-primary mb-2"></div>
        <div id="procLog" class="small text-white">Iniciando...</div>
    </div>

    <div id="resultUI" class="hidden text-center mt-4">
        <div id="videoContainer"></div>
        <a id="dlLink" href="#" class="btn-action btn-primary d-block text-decoration-none mt-3">⬇️ DESCARGAR</a>
        <button onclick="location.reload()" class="btn btn-outline-secondary w-100 mt-2">Nuevo</button>
    </div>
    
    <a href="?action=viewlog" target="_blank" class="d-block text-center mt-4 text-secondary small text-decoration-none" style="opacity:0.3;">Logs</a>
</div>

<script>
async function installEngine() {
    const btn = document.getElementById('btnInstall');
    const log = document.getElementById('installLog');
    btn.disabled = true;
    btn.innerText = "⏳ DESCARGANDO...";
    
    try {
        const res = await fetch('?action=download_engine');
        const data = await res.json();
        if(data.status === 'success') {
            log.innerText = "¡Listo! Recargando...";
            setTimeout(() => location.reload(), 1500);
        } else {
            btn.disabled = false;
            btn.innerText = "REINTENTAR";
            alert("Error: " + data.msg);
        }
    } catch(e) {
        btn.disabled = false;
        alert("Error de red.");
    }
}

async function processVideo() {
    const tIn = document.getElementById('tIn').value;
    const fIn = document.getElementById('fIn').files[0];
    if(!fIn) return alert("Sube un video");

    document.getElementById('editorUI').classList.add('hidden');
    document.getElementById('progressUI').classList.remove('hidden');

    const fd = new FormData();
    fd.append('videoTitle', tIn.toUpperCase());
    fd.append('videoFile', fIn);
    fd.append('mirrorMode', document.getElementById('mirrorCheck').checked);

    try {
        const res = await fetch('?action=upload', {method:'POST', body:fd});
        const data = await res.json();
        if(data.status === 'success') track(data.jobId);
        else { alert(data.msg || data.message); location.reload(); }
    } catch(e) { alert("Error subida"); location.reload(); }
}

function track(id) {
    const i = setInterval(async () => {
        try {
            const res = await fetch(`?action=status&jobId=${id}`);
            const data = await res.json();
            
            if(data.status === 'finished') {
                clearInterval(i);
                document.getElementById('progressUI').classList.add('hidden');
                document.getElementById('resultUI').classList.remove('hidden');
                document.getElementById('dlLink').href = '?action=download&file=' + data.file;
                document.getElementById('videoContainer').innerHTML = `<video width="100%" height="100%" controls autoplay muted loop playsinline><source src="processed/${data.file}?t=${Date.now()}" type="video/mp4"></video>`;
            } else if(data.status === 'error') {
                clearInterval(i);
                alert(data.msg); location.reload();
            } else {
                if(data.debug) document.getElementById('procLog').innerText = "Procesando...";
            }
        } catch {}
    }, 2000);
}

document.getElementById('tIn')?.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
</script>
</body>
</html>
