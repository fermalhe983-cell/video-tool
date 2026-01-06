<?php
// ==========================================
// VIRAL REELS MAKER v46.0 (MASTER ENGINE + SOLID CORE)
// Solución: Usa la versión 'GIT-MASTER' de FFmpeg que sí tiene 'drawtext'.
// Elimina efectos de memoria (Blur) para evitar archivos corruptos (moov atom).
// ==========================================

// Configuración de Servidor
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', 1800); 
@ini_set('memory_limit', '2048M'); 
@ini_set('display_errors', 0);

// Directorios
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$binDir = $baseDir . '/bin'; 
$ffmpegBin = $binDir . '/ffmpeg'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

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

// ---> DIAGNÓSTICO MOTOR
$engineStatus = 'missing';
if (file_exists($ffmpegBin)) {
    if (filesize($ffmpegBin) > 30000000) $engineStatus = 'installed'; // La versión Master pesa >30MB
    else $engineStatus = 'corrupt';
}

// ---> DESCARGAR MOTOR MASTER (La versión completa que tiene drawtext)
if ($action === 'download_engine') {
    header('Content-Type: application/json');
    
    // URL de la versión GIT (Completa)
    $url = "https://johnvansickle.com/ffmpeg/builds/ffmpeg-git-amd64-static.tar.xz";
    $tarFile = $baseDir . '/master.tar.xz';
    
    $fp = fopen($tarFile, 'w+');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 900); // 15 minutos de timeout
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    
    if ($httpCode !== 200 || filesize($tarFile) < 1000000) {
        echo json_encode(['status'=>'error', 'msg'=>'Error descarga. Revisa internet.']); exit;
    }
    
    shell_exec("tar -xf " . escapeshellarg($tarFile) . " -C " . escapeshellarg($binDir));
    
    $subDirs = glob($binDir . '/ffmpeg-*-static');
    if (!empty($subDirs)) {
        rename($subDirs[0] . '/ffmpeg', $ffmpegBin);
        chmod($ffmpegBin, 0775);
        shell_exec("rm -rf " . escapeshellarg($subDirs[0]));
        unlink($tarFile);
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'Error al descomprimir Master.']);
    }
    exit;
}

// ---> TESTEAR MOTOR
if ($action === 'test_engine') {
    header('Content-Type: application/json');
    if (!file_exists($ffmpegBin)) { echo json_encode(['status'=>'error', 'msg'=>'Falta motor']); exit; }
    $output = shell_exec($ffmpegBin . " -version 2>&1");
    // Buscamos si soporta drawtext
    $filters = shell_exec($ffmpegBin . " -filters 2>&1");
    
    if (strpos($filters, 'drawtext') !== false) {
        echo json_encode(['status'=>'success', 'msg'=>'¡MOTOR PERFECTO! (Texto activado)']);
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'Motor instalado pero sin soporte de texto.']);
    }
    exit;
}

// ---> DESCARGA
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = "$processedDir/$file";
    if (file_exists($filePath)) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="VIRAL_MASTER_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// ---> PROCESAMIENTO
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!file_exists($ffmpegBin)) { echo json_encode(['status'=>'error', 'msg'=>'Falta motor.']); exit; }

    $jobId = uniqid('v46_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status'=>'error', 'msg'=>'Error al subir archivo.']); exit;
    }
    chmod($inputFile, 0777);

    // Params
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

    // Ajustes 720p (Equilibrio)
    if ($count == 1) { $barH = 160; $fSize = 75; $yPos = [90]; }
    elseif ($count == 2) { $barH = 240; $fSize = 65; $yPos = [70, 145]; }
    else { $barH = 300; $fSize = 55; $yPos = [60, 130, 200]; }

    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($audioPath) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";
    
    // 1. FONDO SÓLIDO (CLAVE PARA EVITAR CRASH)
    // El "blur" consume demasiada RAM. Usamos negro #000000.
    $filter .= "color=c=black:s=720x1280[bg];";
    
    // 2. VIDEO CENTRAL
    $filter .= "[0:v]scale=720:1280:force_original_aspect_ratio=decrease{$mirrorCmd}[fg];";
    
    // 3. MEZCLA
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2:format=auto[base];";
    $lastStream = "[base]";

    // 4. BARRA
    $filter .= "{$lastStream}drawbox=x=0:y=40:w=iw:h={$barH}:color=black@0.9:t=fill";

    // 5. TEXTO (Ahora sí funcionará con el motor Master)
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

    // 7. AUDIO (Separador ';' añadido)
    if ($audioPath) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= ";[{$mIdx}:a]volume=0.15[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= ";[0:a]atempo=1.0[afinal]";
    }

    // EJECUCIÓN (Usamos el binario descargado)
    $cmd = "nice -n 10 " . escapeshellarg($ffmpegBin) . " -y $inputs -filter_complex \"$filter\" -map \"$lastStream\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -threads 2 -crf 27 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    file_put_contents($logFile, "\n--- JOB $jobId (MASTER) ---\nCMD: $cmd\n", FILE_APPEND);
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
            $logTail = shell_exec("tail -n 3 " . escapeshellarg($logFile));
            if (strpos($logTail, 'Error') !== false || strpos($logTail, 'Invalid') !== false) {
                 echo json_encode(['status' => 'error', 'msg' => 'Error Motor: ' . substr($logTail, 0, 100)]);
            } elseif (time() - $data['start'] > 900) {
                 echo json_encode(['status' => 'error', 'msg' => 'Timeout.']);
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
    <title>Viral v46 Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background: #050505; color: #fff; padding: 20px; font-family: sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #111; border: 1px solid #333; max-width: 500px; width: 100%; padding: 25px; border-radius: 20px; }
        h2 { color: #00ff88; text-align: center; text-transform: uppercase; font-weight: 800; }
        .btn-action { width: 100%; padding: 15px; border-radius: 10px; border: none; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .btn-dl { background: #007bff; color: white; }
        .btn-test { background: #ffc107; color: black; }
        .btn-go { background: #00ff88; color: #000; }
        .status-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .bg-red { background: #dc3545; }
        .bg-green { background: #28a745; }
        .log-box { font-family: monospace; font-size: 0.7rem; color: #00ff88; background: #000; padding: 10px; border-radius: 5px; margin-top: 10px; min-height: 40px; word-break: break-all; }
        .hidden { display: none; }
    </style>
</head>
<body>

<div class="card">
    <h2>Sistema v46</h2>
    <p class="text-center text-muted small mb-4">Master Engine + Solid Core</p>

    <div class="step mb-4 p-3 border border-secondary rounded bg-dark">
        <h6 class="text-muted text-uppercase mb-3">1. Estado del Motor</h6>
        <div class="d-flex align-items-center mb-2">
            <span class="status-dot <?php echo $engineStatus == 'installed' ? 'bg-green' : 'bg-red'; ?>"></span>
            <span class="small"><?php echo $engineStatus == 'installed' ? 'INSTALADO (Master)' : 'NO INSTALADO'; ?></span>
        </div>
        <?php if($engineStatus != 'installed'): ?>
            <button class="btn-dl btn-action" onclick="runAction('download_engine')">DESCARGAR MOTOR (MASTER)</button>
        <?php else: ?>
            <button class="btn-test btn-action" onclick="runAction('test_engine')">TESTEAR MOTOR</button>
        <?php endif; ?>
        <div id="log1" class="log-box">Esperando...</div>
    </div>

    <?php if($engineStatus == 'installed'): ?>
    <div class="step p-3 border border-secondary rounded bg-dark">
        <h6 class="text-muted text-uppercase mb-3">2. Editor</h6>
        <input type="text" id="tIn" class="form-control bg-black text-white border-secondary mb-2" placeholder="TÍTULO GANCHO..." autocomplete="off">
        <input type="file" id="fIn" class="form-control bg-black text-white border-secondary mb-2">
        
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="mirrorCheck">
            <label class="form-check-label text-white small">Modo Espejo</label>
        </div>

        <button class="btn-go btn-action" onclick="uploadVideo()">RENDERIZAR AHORA</button>
    </div>
    
    <div id="processBox" class="hidden text-center mt-3">
        <div class="spinner-border text-success mb-2"></div>
        <p class="small text-muted">Procesando...</p>
        <div id="procLog" class="log-box text-start">Iniciando...</div>
        <a id="dlLink" href="#" class="btn-action btn-primary mt-2 d-block text-decoration-none hidden">BAJAR VIDEO</a>
    </div>
    <?php endif; ?>
    
    <a href="?action=viewlog" target="_blank" class="d-block text-center mt-3 small text-decoration-none text-secondary">Ver Logs</a>
</div>

<script>
async function runAction(act) {
    document.getElementById('log1').innerText = "Descargando... (Puede tardar 2 mins)";
    try {
        const res = await fetch('?action=' + act);
        const data = await res.json();
        document.getElementById('log1').innerText = data.msg || data.status;
        if(data.status === 'success') setTimeout(() => location.reload(), 1500);
    } catch(e) {
        document.getElementById('log1').innerText = "Error de red.";
    }
}

async function uploadVideo() {
    const tIn = document.getElementById('tIn').value;
    const fIn = document.getElementById('fIn').files[0];
    if(!fIn) return alert("Sube un video");

    document.getElementById('processBox').classList.remove('hidden');
    
    const fd = new FormData();
    fd.append('videoTitle', tIn.toUpperCase());
    fd.append('videoFile', fIn);
    fd.append('mirrorMode', document.getElementById('mirrorCheck').checked);

    try {
        const res = await fetch('?action=upload', {method:'POST', body:fd});
        const data = await res.json();
        if(data.status === 'success') track(data.jobId);
        else document.getElementById('procLog').innerText = "Error: " + data.msg;
    } catch(e) {
        document.getElementById('procLog').innerText = "Error subida.";
    }
}

function track(id) {
    const i = setInterval(async () => {
        try {
            const res = await fetch(`?action=status&jobId=${id}`);
            const data = await res.json();
            
            if(data.status === 'finished') {
                clearInterval(i);
                document.getElementById('procLog').innerText = "¡TERMINADO!";
                document.getElementById('dlLink').href = '?action=download&file=' + data.file;
                document.getElementById('dlLink').classList.remove('hidden');
            } else if(data.status === 'error') {
                clearInterval(i);
                document.getElementById('procLog').innerText = data.msg;
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
