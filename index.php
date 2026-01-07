<?php
// ==========================================
// VIRAL REELS MAKER v54.0 (LIVE DEBUGGER)
// Muestra el log de FFmpeg en tiempo real en la pantalla.
// Soluciona el misterio de "se queda procesando".
// ==========================================

// Configuración
@ini_set('display_errors', 0);
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', 1200);

// Directorios
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

// Crear carpetas (Si fallan los permisos, el script avisará)
if (!file_exists($uploadDir)) @mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) @mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) @mkdir($jobsDir, 0777, true);

$action = $_GET['action'] ?? '';

// ==========================================
// 1. DETECCIÓN
// ==========================================
$systemPath = trim(shell_exec('which ffmpeg'));
$status = ['installed' => !empty($systemPath), 'path' => $systemPath];

if ($status['installed']) {
    $filters = shell_exec("$systemPath -filters 2>&1");
    $status['drawtext'] = (strpos($filters, 'drawtext') !== false);
} else {
    $status['drawtext'] = false;
}

// ==========================================
// 2. BACKEND
// ==========================================

// ---> LEER LOG EN VIVO (NUEVO)
if ($action === 'read_log') {
    header('Content-Type: text/plain');
    if (file_exists($logFile)) {
        // Leemos las últimas 20 líneas
        $lines = array_slice(file($logFile), -20);
        echo implode("", $lines);
    } else {
        echo "Esperando inicio del proceso...";
    }
    exit;
}

// ---> DESCARGA
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = "$processedDir/$file";
    if (file_exists($filePath)) {
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="VIRAL_v54_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// ---> PROCESAMIENTO
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Reset Log
    file_put_contents($logFile, "--- INICIANDO PROCESO v54 ---\n");

    if (!$status['installed']) { echo json_encode(['status'=>'error', 'msg'=>'FFmpeg no instalado.']); exit; }

    $jobId = uniqid('v54_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status'=>'error', 'msg'=>'Error de Permisos: No puedo guardar el video subido. Ejecuta el comando CHOWN.']); exit;
    }
    chmod($inputFile, 0777);

    // Ajustes
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $audioPath = file_exists($audioPath) ? $audioPath : false;
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    $rawTitle = mb_strtoupper($_POST['videoTitle'] ?? '');
    $wrappedText = wordwrap($rawTitle, 18, "\n", true);
    $lines = explode("\n", $wrappedText);
    if(count($lines) > 3) $lines = array_slice($lines, 0, 3);
    $count = count($lines);

    if ($count == 1) { $barH = 160; $fSize = 75; $yPos = [90]; }
    elseif ($count == 2) { $barH = 240; $fSize = 65; $yPos = [70, 145]; }
    else { $barH = 300; $fSize = 55; $yPos = [60, 130, 200]; }

    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($audioPath) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";
    
    // FILTROS SEGUROS
    $filter .= "color=c=#111111:s=720x1280[bg];";
    $filter .= "[0:v]scale=720:1280:force_original_aspect_ratio=decrease,setsar=1,format=yuv420p{$mirrorCmd}[fg];";
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2:format=auto:shortest=1[base];";
    $lastStream = "[base]";
    $filter .= "{$lastStream}drawbox=x=0:y=40:w=iw:h={$barH}:color=black@0.9:t=fill";

    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        foreach ($lines as $i => $line) {
            $y = $yPos[$i];
            $filter .= ",drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=3:bordercolor=black:shadowx=2:shadowy=2:x=(w-text_w)/2:y={$y}";
        }
    }
    $filter .= "[vtext];";
    $lastStream = "[vtext]";

    if ($useLogo) {
        $logoY = 40 + ($barH/2) - 45;
        $filter .= "[1:v]scale=-1:90[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=25:{$logoY}[vfinal]";
        $lastStream = "[vfinal]";
    } else {
        $filter .= "{$lastStream}copy[vfinal]";
    }

    if ($audioPath) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= ";[{$mIdx}:a]volume=0.15[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= ";[0:a]atempo=1.0[afinal]";
    }

    // EJECUCIÓN CON SALIDA AL LOG
    $cmd = "nice -n 10 " . escapeshellarg($systemPath) . " -y $inputs -filter_complex \"$filter\" -map \"$lastStream\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -threads 2 -crf 27 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    file_put_contents($logFile, "CMD: $cmd\n----------------\n", FILE_APPEND);
    exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName, 'start' => time()]));
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . $data['file'];
        if (file_exists($fullPath) && filesize($fullPath) > 50000) {
            echo json_encode(['status' => 'finished', 'file' => $data['file']]);
        } elseif (time() - $data['start'] > 900) {
            echo json_encode(['status' => 'error', 'msg' => 'Timeout']);
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
    <title>Viral v54 Debug</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #000; color: #fff; font-family: monospace; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; }
        .terminal { background: #111; border: 1px solid #333; padding: 15px; height: 200px; overflow-y: scroll; font-size: 0.7rem; color: #0f0; margin-top: 10px; white-space: pre-wrap; }
        .btn-go { width: 100%; padding: 15px; background: #0f0; color: #000; font-weight: bold; border: none; margin-top: 10px; cursor: pointer; }
        .hidden { display: none; }
    </style>
</head>
<body>

<div class="container">
    <h3 class="text-center text-success">SISTEMA v54 (LIVE LOG)</h3>
    
    <div id="uiInput">
        <div class="mb-3">
            <label>Estado Motor:</label> 
            <span class="<?php echo $status['installed']?'text-success':'text-danger'; ?>">
                <?php echo $status['installed'] ? $status['path'] : 'NO INSTALADO'; ?>
            </span>
        </div>
        
        <?php if ($status['installed']): ?>
            <input type="text" id="tIn" class="form-control bg-dark text-white mb-2" placeholder="TÍTULO...">
            <input type="file" id="fIn" class="form-control bg-dark text-white mb-2" accept="video/*">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="mirrorCheck">
                <label class="form-check-label text-white small">Espejo</label>
            </div>
            <button class="btn-go" onclick="process()">RENDERIZAR</button>
        <?php else: ?>
            <div class="alert alert-danger">Error: FFmpeg no detectado en sistema.</div>
        <?php endif; ?>
    </div>

    <div id="uiProcess" class="hidden">
        <div class="spinner-border text-primary mb-2"></div>
        <span>Procesando... Mira el log abajo:</span>
        <div id="logViewer" class="terminal">Esperando datos...</div>
    </div>

    <div id="uiResult" class="hidden text-center mt-3">
        <a id="dlLink" href="#" class="btn btn-primary w-100">DESCARGAR VIDEO</a>
        <button onclick="location.reload()" class="btn btn-outline-secondary w-100 mt-2">Nuevo</button>
    </div>
</div>

<script>
async function process() {
    const tIn = document.getElementById('tIn').value;
    const fIn = document.getElementById('fIn').files[0];
    if(!fIn) return alert("Sube video");

    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData();
    fd.append('videoTitle', tIn.toUpperCase());
    fd.append('videoFile', fIn);
    fd.append('mirrorMode', document.getElementById('mirrorCheck').checked);

    try {
        const res = await fetch('?action=upload', {method:'POST', body:fd});
        const data = await res.json();
        if(data.status === 'success') {
            track(data.jobId);
            startLogReader(); // Iniciar lectura de log
        } else { 
            alert("Error: " + data.msg); 
            location.reload(); 
        }
    } catch(e) { alert("Error conexión"); location.reload(); }
}

function startLogReader() {
    const logInt = setInterval(async () => {
        try {
            const res = await fetch('?action=read_log');
            const txt = await res.text();
            const term = document.getElementById('logViewer');
            term.innerText = txt;
            term.scrollTop = term.scrollHeight; // Auto-scroll
            
            // Si vemos 'Output #0' o 'video:' significa que está avanzando
            // Si vemos 'Permission denied' es el error.
        } catch {}
    }, 1000);
    window.logInterval = logInt;
}

function track(id) {
    const i = setInterval(async () => {
        try {
            const res = await fetch(`?action=status&jobId=${id}`);
            const data = await res.json();
            if(data.status === 'finished') {
                clearInterval(i);
                clearInterval(window.logInterval);
                document.getElementById('uiProcess').classList.add('hidden');
                document.getElementById('uiResult').classList.remove('hidden');
                document.getElementById('dlLink').href = '?action=download&file=' + data.file;
            }
        } catch {}
    }, 2000);
}
document.getElementById('tIn')?.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
</script>
</body>
</html>
