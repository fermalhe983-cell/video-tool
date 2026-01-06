<?php
// ==========================================
// VIRAL REELS MAKER v48.0 (AUTO-INSTALLER WIZARD)
// Solución: Script PHP que ejecuta los comandos de terminal por ti.
// Si el servidor borra el archivo, este script lo recupera con un clic.
// ==========================================

// Configuración
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
$ffmpegBin = $baseDir . '/ffmpeg'; // El motor vivirá aquí
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

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

// ---> DIAGNÓSTICO DEL MOTOR
$engineStatus = 'missing';
if (file_exists($ffmpegBin)) {
    if (filesize($ffmpegBin) > 30000000) { // Mayor a 30MB
        $engineStatus = 'installed';
        if (!is_executable($ffmpegBin)) chmod($ffmpegBin, 0775);
    }
}

// ---> INSTALADOR AUTOMÁTICO (WGET VIA PHP)
if ($action === 'force_install') {
    header('Content-Type: application/json');
    
    // 1. Descargar (Usando wget del sistema o curl de PHP)
    $url = "https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz";
    $tarFile = $baseDir . '/engine.tar.xz';
    
    // Intentamos descargar con PHP nativo para no depender de wget
    $fp = fopen($tarFile, 'w+');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    
    if (!file_exists($tarFile) || filesize($tarFile) < 1000000) {
        echo json_encode(['status'=>'error', 'msg'=>'Error descargando el archivo.']); exit;
    }
    
    // 2. Descomprimir
    shell_exec("tar -xf " . escapeshellarg($tarFile) . " -C " . escapeshellarg($baseDir));
    
    // 3. Buscar la carpeta extraída y mover el archivo
    $subDirs = glob($baseDir . '/ffmpeg-*-static');
    if (!empty($subDirs)) {
        $extractedBin = $subDirs[0] . '/ffmpeg';
        if(file_exists($extractedBin)) {
            // Mover a la raíz
            rename($extractedBin, $ffmpegBin);
            // Permisos
            chmod($ffmpegBin, 0775);
            // Limpieza
            shell_exec("rm -rf " . escapeshellarg($subDirs[0]));
            unlink($tarFile);
            echo json_encode(['status'=>'success']);
        } else {
            echo json_encode(['status'=>'error', 'msg'=>'No se encontró el ejecutable ffmpeg dentro del zip.']);
        }
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
    
    if ($engineStatus !== 'installed') {
        echo json_encode(['status'=>'error', 'msg'=>'El motor no está instalado. Pulsa el botón rojo primero.']); exit;
    }

    $jobId = uniqid('v48_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status'=>'error', 'msg'=>'Error subida PHP.']); exit;
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

    // Ajustes 720p (Estándar de Oro)
    if ($count == 1) { $barH = 160; $fSize = 75; $yPos = [90]; }
    elseif ($count == 2) { $barH = 240; $fSize = 65; $yPos = [70, 145]; }
    else { $barH = 300; $fSize = 55; $yPos = [60, 130, 200]; }

    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($audioPath) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";
    
    // 1. FONDO OSCURO (Sólido = Cero RAM extra)
    $filter .= "color=c=black:s=720x1280[bg];";
    
    // 2. VIDEO ESCALADO
    $filter .= "[0:v]scale=720:1280:force_original_aspect_ratio=decrease{$mirrorCmd}[fg];";
    
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

    // 7. AUDIO (Correcto ;)
    if ($audioPath) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= ";[{$mIdx}:a]volume=0.15[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= ";[0:a]atempo=1.0[afinal]";
    }

    // EJECUCIÓN (Usando binario local)
    $cmd = "nice -n 10 " . escapeshellarg($ffmpegBin) . " -y $inputs -filter_complex \"$filter\" -map \"$lastStream\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -threads 2 -crf 27 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

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
        
        if (file_exists($fullPath) && filesize($fullPath) > 100000) {
            chmod($fullPath, 0777); 
            echo json_encode(['status' => 'finished', 'file' => $data['file']]);
        } else {
            $logTail = shell_exec("tail -n 3 " . escapeshellarg($logFile));
            if (strpos($logTail, 'Error') !== false || strpos($logTail, 'Invalid') !== false) {
                 echo json_encode(['status' => 'error', 'msg' => 'FFmpeg Error: ' . substr($logTail, 0, 100)]);
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
    <title>Viral v48 Auto-Install</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #050505; color: #fff; padding: 20px; font-family: sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #111; border: 1px solid #333; max-width: 500px; width: 100%; padding: 25px; border-radius: 20px; }
        .status-box { padding: 20px; text-align: center; border-radius: 10px; margin-bottom: 20px; border: 1px dashed #555; }
        .success { border-color: #0f0; color: #0f0; background: rgba(0,255,0,0.1); }
        .fail { border-color: #f00; color: #f00; background: rgba(255,0,0,0.1); }
        .btn-go { width: 100%; padding: 15px; background: #0f0; color: #000; font-weight: bold; border: none; border-radius: 10px; cursor: pointer; }
        .btn-install { width: 100%; padding: 15px; background: #f00; color: #fff; font-weight: bold; border: none; border-radius: 10px; cursor: pointer; animation: pulse 2s infinite; }
        .hidden { display: none; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.8; } 100% { opacity: 1; } }
    </style>
</head>
<body>

<div class="card">
    <h2 class="text-center mb-4 text-white">SISTEMA v48</h2>

    <?php if ($engineStatus === 'installed'): ?>
        <div class="status-box success">
            <h4>✅ MOTOR LISTO</h4>
            <p class="small mb-0">Sistema operativo y funcional.</p>
        </div>

        <div id="uiInput">
            <input type="text" id="tIn" class="form-control bg-dark text-white mb-3" placeholder="TÍTULO..." autocomplete="off">
            <input type="file" id="fIn" class="form-control bg-dark text-white mb-3">
            <div class="form-check form-switch mb-3 text-center">
                <input class="form-check-input float-none" type="checkbox" id="mirrorCheck">
                <label class="text-white small">Espejo</label>
            </div>
            <button class="btn-go" onclick="process()">RENDERIZAR</button>
        </div>

    <?php else: ?>
        <div class="status-box fail">
            <h4>❌ FALTA EL MOTOR</h4>
            <p class="small">El servidor se reinició y borró el motor. Haz clic abajo para reinstalarlo automáticamente.</p>
        </div>
        <button id="btnInstall" onclick="installNow()" class="btn-install">⬇️ INSTALAR MOTOR AHORA</button>
        <div id="installLog" class="text-center mt-2 text-muted small"></div>
    <?php endif; ?>

    <div id="uiProcess" class="hidden text-center mt-4">
        <div class="spinner-border text-success mb-2"></div>
        <p>Procesando...</p>
        <div id="log" class="small text-muted font-monospace">Iniciando...</div>
    </div>

    <div id="uiResult" class="hidden text-center mt-4">
        <div id="vidContainer" class="ratio ratio-9x16 bg-black mb-3 border border-secondary rounded"></div>
        <a id="dlLink" href="#" class="btn btn-primary w-100">DESCARGAR</a>
        <button onclick="location.reload()" class="btn btn-outline-secondary w-100 mt-2">Nuevo</button>
    </div>
</div>

<script>
async function installNow() {
    const btn = document.getElementById('btnInstall');
    btn.disabled = true;
    btn.innerText = "INSTALANDO... (ESPERA)";
    document.getElementById('installLog').innerText = "Descargando (Puede tardar 30s)...";
    
    try {
        const res = await fetch('?action=force_install');
        const data = await res.json();
        if(data.status === 'success') {
            document.getElementById('installLog').innerText = "¡Instalado! Recargando...";
            setTimeout(() => location.reload(), 1500);
        } else {
            alert("Error: " + data.msg);
            btn.disabled = false;
            btn.innerText = "REINTENTAR";
        }
    } catch(e) {
        alert("Error de red.");
        btn.disabled = false;
    }
}

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
        if(data.status === 'success') track(data.jobId);
        else { alert(data.msg); location.reload(); }
    } catch(e) { alert("Error"); location.reload(); }
}

function track(id) {
    const i = setInterval(async () => {
        try {
            const res = await fetch(`?action=status&jobId=${id}`);
            const data = await res.json();
            if(data.status === 'finished') {
                clearInterval(i);
                document.getElementById('uiProcess').classList.add('hidden');
                document.getElementById('uiResult').classList.remove('hidden');
                document.getElementById('dlLink').href = '?action=download&file='+data.file;
                document.getElementById('vidContainer').innerHTML = `<video src="processed/${data.file}" controls autoplay muted loop class="w-100 h-100"></video>`;
            } else if(data.status === 'error') {
                clearInterval(i);
                alert(data.msg); location.reload();
            }
        } catch {}
    }, 2000);
}
document.getElementById('tIn')?.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
</script>
</body>
</html>
