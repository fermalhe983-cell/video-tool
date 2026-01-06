<?php
// ==========================================
// VIRAL REELS MAKER v49.0 (BULLETPROOF EDITION)
// Motor: Usa el FFmpeg v7.0.2 que instalaste manualmente por terminal.
// Seguridad: Verifica estrictamente el filtro 'drawtext' antes de procesar.
// ==========================================

// Configuración para Servidor 16GB
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', 1200); 
@ini_set('memory_limit', '4096M'); 
@ini_set('display_errors', 0);

// Rutas
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$ffmpegBin = $baseDir . '/ffmpeg'; // TU ARCHIVO INSTALADO
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

// Crear carpetas necesarias
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) mkdir($jobsDir, 0777, true);

// Limpieza automática (borra archivos viejos de más de 1 hora)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 3600)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ==========================================
// 1. DIAGNÓSTICO DEL SISTEMA
// ==========================================
$status = [
    'engine_exists' => false,
    'executable' => false,
    'drawtext' => false,
    'font_found' => file_exists($fontPath)
];

if (file_exists($ffmpegBin)) {
    $status['engine_exists'] = true;
    
    // Intentar dar permisos si no los tiene
    if (!is_executable($ffmpegBin)) chmod($ffmpegBin, 0775);
    
    if (is_executable($ffmpegBin)) {
        $status['executable'] = true;
        // Prueba de fuego: ¿Tiene el filtro de texto?
        $check = shell_exec($ffmpegBin . " -filters 2>&1");
        if (strpos($check, 'drawtext') !== false) {
            $status['drawtext'] = true;
        }
    }
}

// ==========================================
// 2. FUNCIONES DE BACKEND
// ==========================================

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

// ---> PROCESAMIENTO DE VIDEO
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Validaciones de seguridad
    if (!$status['engine_exists']) { echo json_encode(['status'=>'error', 'msg'=>'No encuentro el archivo ffmpeg.']); exit; }
    if (!$status['drawtext']) { echo json_encode(['status'=>'error', 'msg'=>'El motor instalado no soporta texto.']); exit; }

    $jobId = uniqid('v49_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status'=>'error', 'msg'=>'Error al subir archivo al servidor.']); exit;
    }
    chmod($inputFile, 0777);

    // Recursos
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $audioPath = file_exists($audioPath) ? $audioPath : false;
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    // Procesamiento de Texto
    $rawTitle = mb_strtoupper($_POST['videoTitle'] ?? '');
    $wrappedText = wordwrap($rawTitle, 18, "\n", true);
    $lines = explode("\n", $wrappedText);
    if(count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    // Ajustes Visuales (720p HD)
    if ($count == 1) { $barH = 160; $fSize = 75; $yPos = [90]; }
    elseif ($count == 2) { $barH = 240; $fSize = 65; $yPos = [70, 145]; }
    else { $barH = 300; $fSize = 55; $yPos = [60, 130, 200]; }

    // Construcción del Comando FFmpeg
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($audioPath) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";
    
    // A. FONDO SÓLIDO (Gris Oscuro #111) - Evita crashes de memoria
    $filter .= "color=c=#111111:s=720x1280[bg];";
    
    // B. VIDEO ESCALADO
    $filter .= "[0:v]scale=720:1280:force_original_aspect_ratio=decrease{$mirrorCmd}[fg];";
    
    // C. MEZCLA
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2:format=auto[base];";
    $lastStream = "[base]";

    // D. BARRA NEGRA
    $filter .= "{$lastStream}drawbox=x=0:y=40:w=iw:h={$barH}:color=black@0.9:t=fill";

    // E. TEXTO (Aquí usa la librería freetype que verificamos en terminal)
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        foreach ($lines as $i => $line) {
            $y = $yPos[$i];
            $filter .= ",drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=3:bordercolor=black:shadowx=2:shadowy=2:x=(w-text_w)/2:y={$y}";
        }
    }
    $filter .= "[vtext];";
    $lastStream = "[vtext]";

    // F. LOGO
    if ($useLogo) {
        $logoY = 40 + ($barH/2) - 45;
        $filter .= "[1:v]scale=-1:90[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=25:{$logoY}[vfinal]";
        $lastStream = "[vfinal]";
    } else {
        $filter .= "{$lastStream}copy[vfinal]";
    }

    // G. AUDIO (Con punto y coma crítico ;)
    if ($audioPath) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= ";[{$mIdx}:a]volume=0.15[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= ";[0:a]atempo=1.0[afinal]";
    }

    // EJECUCIÓN (Usando el binario local ./ffmpeg)
    $cmd = "nice -n 10 " . escapeshellarg($ffmpegBin) . " -y $inputs -filter_complex \"$filter\" -map \"$lastStream\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -threads 2 -crf 27 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    file_put_contents($logFile, "\n--- JOB $jobId ---\nCMD: $cmd\n", FILE_APPEND);
    exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName, 'start' => time()]));
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> CONSULTA DE ESTADO
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
            // Leer últimas líneas del log para detectar errores específicos
            $logTail = shell_exec("tail -n 3 " . escapeshellarg($logFile));
            if (strpos($logTail, 'Error') !== false || strpos($logTail, 'Invalid') !== false) {
                 echo json_encode(['status' => 'error', 'msg' => 'FFmpeg Error: ' . substr($logTail, 0, 100)]);
            } elseif (time() - $data['start'] > 900) {
                 echo json_encode(['status' => 'error', 'msg' => 'Tiempo agotado (Timeout).']);
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
    <title>Viral v49 Final</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background: #000; color: #fff; padding: 20px; font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #111; border: 1px solid #333; max-width: 500px; width: 100%; padding: 25px; border-radius: 20px; box-shadow: 0 0 30px rgba(0,255,100,0.05); }
        .header-title { font-family: 'Anton', sans-serif; color: #0f0; text-align: center; margin-bottom: 20px; letter-spacing: 1px; }
        
        .status-table { width: 100%; margin-bottom: 20px; border-collapse: separate; border-spacing: 0 5px; font-size: 0.85rem; }
        .status-table td { padding: 8px 12px; background: #1a1a1a; border-radius: 4px; }
        .status-table tr td:first-child { font-weight: bold; color: #aaa; }
        .ok { color: #0f0; font-weight: bold; text-align: right; } 
        .fail { color: #f00; font-weight: bold; text-align: right; }
        
        .form-control { background: #000; border: 1px solid #333; color: #fff; padding: 12px; margin-bottom: 15px; }
        .form-control:focus { background: #050505; color: #fff; border-color: #0f0; box-shadow: none; }
        
        .btn-go { width: 100%; padding: 15px; background: #0f0; color: #000; font-family: 'Anton'; font-size: 1.2rem; border: none; border-radius: 10px; cursor: pointer; transition: transform 0.2s; }
        .btn-go:hover { transform: scale(1.02); background: #fff; }
        
        .hidden { display: none; }
        #videoContainer { width: 100%; aspect-ratio: 9/16; background: #000; margin-top: 20px; border-radius: 10px; overflow: hidden; border: 1px solid #333; }
        video { width: 100%; height: 100%; object-fit: cover; }
    </style>
</head>
<body>

<div class="card">
    <h2 class="header-title">SISTEMA v49</h2>

    <table class="status-table">
        <tr>
            <td>Motor Instalado</td>
            <td class="<?php echo $status['engine_exists']?'ok':'fail'; ?>"><?php echo $status['engine_exists']?'SÍ':'NO'; ?></td>
        </tr>
        <tr>
            <td>Librería de Texto</td>
            <td class="<?php echo $status['drawtext']?'ok':'fail'; ?>"><?php echo $status['drawtext']?'ACTIVA':'ERROR'; ?></td>
        </tr>
        <tr>
            <td>Fuente (font.ttf)</td>
            <td class="<?php echo $status['font_found']?'ok':'fail'; ?>"><?php echo $status['font_found']?'OK':'FALTA'; ?></td>
        </tr>
    </table>

    <?php if ($status['drawtext']): ?>
        <div id="uiInput">
            <input type="text" id="tIn" class="form-control" placeholder="TÍTULO GANCHO AQUÍ..." autocomplete="off">
            <input type="file" id="fIn" class="form-control" accept="video/*">
            
            <div class="d-flex justify-content-center align-items-center gap-2 mb-3">
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" id="mirrorCheck">
                </div>
                <span class="small text-secondary">Modo Espejo (Anti-Copyright)</span>
            </div>

            <button class="btn-go" onclick="process()">⚡ RENDERIZAR VIDEO</button>
        </div>
    <?php else: ?>
        <div class="alert alert-danger text-center p-3 border border-danger rounded bg-transparent">
            ❌ <strong>ERROR CRÍTICO</strong><br>
            El motor instalado no soporta texto.<br>
            <span class="small">Vuelve a la terminal y ejecuta los comandos de instalación de la versión RELEASE.</span>
        </div>
    <?php endif; ?>

    <div id="uiProcess" class="hidden text-center mt-4">
        <div class="spinner-border text-success mb-3" style="width: 3rem; height: 3rem;"></div>
        <h5 class="fw-bold">PROCESANDO...</h5>
        <p class="text-muted small">Esto puede tardar unos segundos.</p>
        <div id="log" class="small text-secondary font-monospace mt-2" style="font-size: 0.7rem;">Iniciando...</div>
    </div>

    <div id="uiResult" class="hidden text-center mt-4">
        <div id="videoContainer"></div>
        <a id="dlLink" href="#" class="btn btn-primary w-100 mt-3 fw-bold py-3">⬇️ DESCARGAR RESULTADO</a>
        <button onclick="location.reload()" class="btn btn-outline-secondary w-100 mt-2 btn-sm">Crear Nuevo</button>
    </div>
</div>

<script>
// Lógica Principal
async function process() {
    const tIn = document.getElementById('tIn').value;
    const fIn = document.getElementById('fIn').files[0];
    
    if(!fIn) return alert("⚠️ Por favor sube un video primero.");
    if(!tIn) if(!confirm("¿Seguro que quieres el video SIN título?")) return;

    // UI Change
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
        } else {
            alert("Error: " + data.msg);
            location.reload();
        }
    } catch(e) {
        alert("Error de conexión con el servidor.");
        location.reload();
    }
}

// Rastreo del trabajo
function track(id) {
    const i = setInterval(async () => {
        try {
            const res = await fetch(`?action=status&jobId=${id}`);
            const data = await res.json();
            
            if(data.status === 'finished') {
                clearInterval(i);
                // Mostrar resultado
                document.getElementById('uiProcess').classList.add('hidden');
                document.getElementById('uiResult').classList.remove('hidden');
                document.getElementById('dlLink').href = '?action=download&file=' + data.file;
                document.getElementById('videoContainer').innerHTML = 
                    `<video src="processed/${data.file}?t=${Date.now()}" controls autoplay muted loop class="w-100 h-100"></video>`;
            } else if(data.status === 'error') {
                clearInterval(i);
                alert("Error durante el renderizado: " + data.msg);
                location.reload();
            }
        } catch {}
    }, 2000);
}

// Auto Mayúsculas
document.getElementById('tIn')?.addEventListener('input', function() { 
    this.value = this.value.toUpperCase(); 
});
</script>
</body>
</html>
