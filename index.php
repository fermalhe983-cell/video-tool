<?php
// ==========================================
// VIRAL REELS MAKER v43.0 (STANDALONE DIAGNOSTIC)
// Solución: Evita tocar el sistema operativo. Usa binarios portátiles.
// Incluye herramientas de diagnóstico para ver por qué falla.
// ==========================================

// Configuración
@ini_set('upload_max_filesize', '1024M');
@ini_set('post_max_size', '1024M');
@ini_set('max_execution_time', 1200); 
@ini_set('memory_limit', '1024M');
@ini_set('display_errors', 0);

// Directorios
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$binDir = $baseDir . '/bin'; 
$ffmpegBin = $binDir . '/ffmpeg'; // Nuestro motor privado
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$logFile = $baseDir . '/process_log.txt';

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

// ---> DIAGNÓSTICO: ¿Tenemos motor?
$engineStatus = 'missing';
if (file_exists($ffmpegBin)) {
    if (filesize($ffmpegBin) > 10000000) $engineStatus = 'installed';
    else $engineStatus = 'corrupt';
}

// ---> ACCIÓN 1: DESCARGAR MOTOR (Sin apt-get)
if ($action === 'download_engine') {
    header('Content-Type: application/json');
    
    $url = "https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz";
    $tarFile = $baseDir . '/engine.tar.xz';
    
    // Descarga con Curl
    $fp = fopen($tarFile, 'w+');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    
    if ($httpCode !== 200 || filesize($tarFile) < 1000000) {
        echo json_encode(['status'=>'error', 'msg'=>'Error descarga (Code: '.$httpCode.')']); exit;
    }
    
    // Descomprimir
    shell_exec("tar -xf " . escapeshellarg($tarFile) . " -C " . escapeshellarg($binDir));
    
    // Mover y limpiar
    $subDirs = glob($binDir . '/ffmpeg-*-static');
    if (!empty($subDirs)) {
        rename($subDirs[0] . '/ffmpeg', $ffmpegBin);
        chmod($ffmpegBin, 0775); // Permisos de ejecución
        shell_exec("rm -rf " . escapeshellarg($subDirs[0]));
        unlink($tarFile);
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'Error al descomprimir archivo.']);
    }
    exit;
}

// ---> ACCIÓN 2: TESTEAR MOTOR
if ($action === 'test_engine') {
    header('Content-Type: application/json');
    if (!file_exists($ffmpegBin)) { echo json_encode(['status'=>'error', 'msg'=>'No existe el archivo ffmpeg']); exit; }
    
    // Ejecuta el comando de versión
    $output = shell_exec($ffmpegBin . " -version 2>&1");
    
    if (strpos($output, 'ffmpeg version') !== false) {
        echo json_encode(['status'=>'success', 'msg'=>'¡MOTOR OPERATIVO! ' . substr($output, 0, 20)]);
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'El motor no arranca. Permisos o arquitectura incorrecta.']);
    }
    exit;
}

// ---> ACCIÓN 3: PROCESAR VIDEO
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!file_exists($ffmpegBin)) { echo json_encode(['status'=>'error', 'msg'=>'Falta el motor.']); exit; }

    $jobId = uniqid('v43_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile);
    chmod($inputFile, 0777);

    // Params
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $audioPath = file_exists($audioPath) ? $audioPath : false;
    
    // Texto
    $rawTitle = mb_strtoupper($_POST['videoTitle'] ?? '');
    $wrappedText = wordwrap($rawTitle, 18, "\n", true);
    $lines = explode("\n", $wrappedText);
    if(count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    // Ajustes 720p (Seguro para 16GB RAM)
    if ($count == 1) { $barH = 160; $fSize = 75; $yPos = [90]; }
    elseif ($count == 2) { $barH = 240; $fSize = 65; $yPos = [70, 145]; }
    else { $barH = 300; $fSize = 55; $yPos = [60, 130, 200]; }

    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($audioPath) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $filter = "";
    
    // 1. FONDO OSCURO (Cero RAM)
    $filter .= "color=c=#111111:s=720x1280[bg];";
    
    // 2. VIDEO ESCALADO
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

    // 7. AUDIO (Copia simple si falla el mix, para probar)
    // Intentamos mix primero
    if ($audioPath) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= "[{$mIdx}:a]volume=0.15[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= "[0:a]atempo=1.0[afinal]";
    }

    // COMANDO DE EJECUCIÓN FANTASMA
    // Usamos 'nohup' y desconectamos stdin/stdout/stderr para que PHP no espere ni se cuelgue
    $cmd = "nohup " . escapeshellarg($ffmpegBin) . " -y $inputs -filter_complex \"$filter\" -map \"$lastStream\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -threads 1 -crf 28 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " > " . escapeshellarg($logFile) . " 2>&1 & echo $!";

    $pid = shell_exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName, 'start' => time(), 'pid' => trim($pid)]));
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> VERIFICAR ESTADO
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . $data['file'];
        
        if (file_exists($fullPath) && filesize($fullPath) > 100000) { // >100KB
            chmod($fullPath, 0777);
            echo json_encode(['status' => 'finished', 'file' => $data['file']]);
        } else {
            // Leer últimas líneas del log para ver si hay error de FFmpeg
            $logTail = shell_exec("tail -n 5 " . escapeshellarg($logFile));
            if (strpos($logTail, 'Error') !== false || strpos($logTail, 'Invalid') !== false) {
                 echo json_encode(['status' => 'error', 'msg' => 'FFmpeg falló: ' . $logTail]);
            } elseif (time() - $data['start'] > 600) {
                 echo json_encode(['status' => 'error', 'msg' => 'Tiempo agotado.']);
            } else {
                 echo json_encode(['status' => 'processing', 'debug' => $logTail]);
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
    <title>Viral v43 - System Check</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #000; color: #fff; padding: 20px; font-family: sans-serif; }
        .card { background: #111; border: 1px solid #333; max-width: 500px; margin: 0 auto; padding: 20px; border-radius: 15px; }
        h2 { color: #00ff00; text-align: center; text-transform: uppercase; }
        .step { margin-bottom: 20px; padding: 15px; border: 1px solid #333; border-radius: 10px; background: #0a0a0a; }
        .step h5 { margin-bottom: 15px; color: #aaa; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; }
        button { width: 100%; padding: 12px; font-weight: bold; border-radius: 8px; border: none; cursor: pointer; transition: 0.2s; }
        .btn-dl { background: #007bff; color: white; }
        .btn-test { background: #ffc107; color: black; }
        .btn-go { background: #28a745; color: white; }
        .status-dot { height: 10px; width: 10px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .bg-red { background: #dc3545; }
        .bg-green { background: #28a745; }
        .log-box { font-family: monospace; font-size: 0.7rem; color: #00ff00; background: #000; padding: 10px; border-radius: 5px; margin-top: 10px; min-height: 40px; }
        .hidden { display: none; }
    </style>
</head>
<body>

<div class="card">
    <h2>Sistema v43</h2>
    
    <div class="step">
        <h5>1. Motor de Video</h5>
        <div class="d-flex align-items-center mb-2">
            <span class="status-dot <?php echo $engineStatus == 'installed' ? 'bg-green' : 'bg-red'; ?>"></span>
            <span class="small"><?php echo $engineStatus == 'installed' ? 'INSTALADO' : 'NO INSTALADO'; ?></span>
        </div>
        <?php if($engineStatus != 'installed'): ?>
            <button class="btn-dl" onclick="runAction('download_engine')">DESCARGAR MOTOR</button>
        <?php else: ?>
            <button class="btn-test" onclick="runAction('test_engine')">TESTEAR MOTOR</button>
        <?php endif; ?>
        <div id="log1" class="log-box">Esperando acción...</div>
    </div>

    <?php if($engineStatus == 'installed'): ?>
    <div class="step">
        <h5>2. Editor Viral</h5>
        <input type="text" id="tIn" class="form-control bg-dark text-white border-secondary mb-2" placeholder="TÍTULO...">
        <input type="file" id="fIn" class="form-control bg-dark text-white border-secondary mb-2">
        
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="mirrorCheck">
            <label class="form-check-label text-white small">Modo Espejo</label>
        </div>

        <button class="btn-go" onclick="uploadVideo()">RENDERIZAR</button>
    </div>
    
    <div id="processBox" class="step hidden text-center">
        <div class="spinner-border text-success mb-2"></div>
        <p class="small text-muted">Procesando video...</p>
        <div id="procLog" class="log-box text-start">Iniciando...</div>
        <a id="dlLink" href="#" class="btn btn-primary mt-2 hidden">BAJAR VIDEO</a>
    </div>
    <?php endif; ?>
    
    <a href="?action=viewlog" target="_blank" class="d-block text-center small text-decoration-none text-secondary">Ver Logs Completos</a>
</div>

<script>
async function runAction(act) {
    document.getElementById('log1').innerText = "Procesando...";
    try {
        const res = await fetch('?action=' + act);
        const data = await res.json();
        document.getElementById('log1').innerText = data.msg || data.status;
        if(data.status === 'success') setTimeout(() => location.reload(), 1000);
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
        else document.getElementById('procLog').innerText = "Error: " + data.message;
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
                if(data.debug) document.getElementById('procLog').innerText = data.debug;
            }
        } catch {}
    }, 2000);
}
</script>
</body>
</html>
