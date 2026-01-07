<?php
// ==========================================
// VIRAL REELS MAKER v58.0 (VIRAL ARCHITECTURE)
// Diseño optimizado para retención de audiencia (Facebook Psychology).
// - Título: Arriba, Ajustado, Amarillo Noticia.
// - Video: Ancho completo (Full Width), subido para aprovechar espacio.
// - Logo: Esquina Inferior Izquierda (Marca de agua no intrusiva).
// - Motor: Sistema v7.0.2 Estable.
// ==========================================

// Configuración
@ini_set('display_errors', 0);
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', 1200);

// Rutas
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

// Carpetas
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

// ==========================================
// 1. DETECCIÓN
// ==========================================
$ffmpegPath = trim(shell_exec('which ffmpeg'));
$ffprobePath = trim(shell_exec('which ffprobe'));

$status = [
    'ffmpeg' => !empty($ffmpegPath),
    'ffprobe' => !empty($ffprobePath),
    'drawtext' => false,
    'font' => file_exists($fontPath),
    'audio' => file_exists($audioPath)
];

if ($status['ffmpeg']) {
    $filters = shell_exec("$ffmpegPath -filters 2>&1");
    $status['drawtext'] = (strpos($filters, 'drawtext') !== false);
}

// ==========================================
// 2. BACKEND
// ==========================================

if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = "$processedDir/$file";
    if (file_exists($filePath)) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="VIRAL_NEWS_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!$status['ffmpeg']) { echo json_encode(['status'=>'error', 'msg'=>'Error: FFmpeg no encontrado.']); exit; }

    $jobId = uniqid('v58_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status'=>'error', 'msg'=>'Error al subir archivo.']); exit;
    }
    chmod($inputFile, 0777);

    // DATOS
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    // TEXTO (Ajustado para ser más compacto)
    $rawTitle = mb_strtoupper($_POST['videoTitle'] ?? '');
    $wrappedText = wordwrap($rawTitle, 20, "\n", true); // Más caracteres por línea
    $lines = explode("\n", $wrappedText);
    if(count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    // --- GEOMETRÍA VIRAL ---
    $canvasW = 720;
    $canvasH = 1280;
    
    // Zona del título (Cabecera)
    // Reducimos un poco la altura reservada para subir el video
    $headerHeight = ($count == 1) ? 180 : (($count == 2) ? 280 : 380);
    
    // Video Start Y (Donde empieza el video)
    // Lo pegamos justo debajo del título para no desperdiciar espacio
    $videoY = $headerHeight + 20; 

    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($useAudio) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";
    
    // 1. LIENZO PREMIUM (Gris muy oscuro, casi negro - Mejor contraste)
    $filter .= "color=c=#101010:s={$canvasW}x{$canvasH}[bg];";
    
    // 2. VIDEO (FULL WIDTH)
    // scale=720:-1 fuerza que el ancho sea 720px (toda la pantalla)
    // y la altura se ajuste automáticamente.
    $filter .= "[0:v]scale={$canvasW}:-1,setsar=1{$mirrorCmd}[vid];";
    
    // Posicionamiento del video
    $filter .= "[bg][vid]overlay=0:{$videoY}:shortest=1[base];";
    $lastStream = "[base]";

    // 3. TEXTO (TITULARES)
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        // Tamaño un poco más pequeño para elegancia, pero sigue siendo grande
        $fSize = ($count == 1) ? 85 : (($count == 2) ? 75 : 65);
        // Posiciones Y (Arriba del todo)
        $startYs = ($count == 1) ? [80] : (($count == 2) ? [70, 160] : [60, 145, 230]);

        foreach ($lines as $i => $line) {
            $y = $startYs[$i];
            // Amarillo Intenso (#FFD700) con Borde Negro Grueso (Impacto Visual)
            $filter .= "{$lastStream}drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=4:bordercolor=black:shadowx=2:shadowy=2:x=(w-text_w)/2:y={$y}[v_text_{$i}];";
            $lastStream = "[v_text_{$i}]";
        }
    }

    // 4. LOGO (ABAJO IZQUIERDA - MARCA DE AGUA)
    if ($useLogo) {
        // Redimensionar logo a un tamaño discreto pero visible (80px alto)
        $filter .= "[1:v]scale=-1:80[logo_s];";
        // Posición: x=30 (margen izq), y=H-120 (margen abajo)
        $logoPosY = $canvasH - 120;
        $filter .= "{$lastStream}[logo_s]overlay=30:{$logoPosY}[vfinal]";
        $lastStream = "[vfinal]";
    } else {
        $filter .= "{$lastStream}copy[vfinal]";
    }

    // 5. AUDIO HASH CHANGER (Mezcla noticias)
    if ($useAudio) {
        $mIdx = $useLogo ? "2" : "1";
        // Volumen noticias: 0.15 (Sutil, para no tapar voz original pero cambiar hash)
        $filter .= ";[{$mIdx}:a]volume=0.15[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= ";[0:a]atempo=1.0[afinal]";
    }

    // EJECUCIÓN ROBUSTA
    $cmd = "nice -n 10 " . escapeshellarg($ffmpegPath) . " -y $inputs -filter_complex \"$filter\" -map \"[vfinal]\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -threads 2 -crf 26 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    file_put_contents($logFile, "\n--- JOB $jobId ---\nCMD: $cmd\n", FILE_APPEND);
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
            chmod($fullPath, 0777);
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
    <title>Viral v58 Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background: #000; color: #fff; padding: 20px; font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #111; border: 1px solid #333; max-width: 500px; width: 100%; padding: 25px; border-radius: 20px; box-shadow: 0 0 40px rgba(255, 200, 0, 0.15); }
        
        .status-badge { display: inline-block; padding: 5px 10px; border-radius: 5px; font-size: 0.75rem; font-weight: bold; margin-right: 5px; }
        .bg-ok { background: rgba(0, 255, 0, 0.2); color: #0f0; border: 1px solid #0f0; }
        .bg-fail { background: rgba(255, 0, 0, 0.2); color: #f00; border: 1px solid #f00; }

        .btn-go { width: 100%; padding: 15px; background: linear-gradient(45deg, #FFD700, #FFA500); color: #000; font-family: 'Anton'; font-size: 1.3rem; border: none; border-radius: 10px; cursor: pointer; transition: transform 0.2s; box-shadow: 0 5px 15px rgba(255, 215, 0, 0.3); }
        .btn-go:hover { transform: scale(1.03); }
        
        .hidden { display: none; }
        #videoContainer { width: 100%; aspect-ratio: 9/16; background: #000; margin-top: 20px; border-radius: 10px; overflow: hidden; border: 1px solid #333; }
        video { width: 100%; height: 100%; object-fit: cover; }
    </style>
</head>
<body>

<div class="card">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-warning fw-bold m-0" style="font-family: 'Anton'; letter-spacing: 2px;">NOTICIA v58</h2>
        <div class="text-end">
            <span class="status-badge <?php echo $status['ffmpeg']?'bg-ok':'bg-fail'; ?>">MOTOR</span>
            <span class="status-badge <?php echo $status['audio']?'bg-ok':'bg-fail'; ?>">HASH</span>
        </div>
    </div>

    <?php if ($status['ffmpeg'] && $status['drawtext']): ?>
        <div id="uiInput">
            <label class="small text-secondary mb-1 ms-1">TITULAR GANCHO (Ej: ¡INCREÍBLE LO QUE PASÓ!)</label>
            <textarea id="tIn" class="form-control bg-dark text-warning mb-3 fw-bold text-center border-secondary" placeholder="ESCRIBE AQUÍ..." rows="2" style="font-family: 'Anton'; font-size: 1.4rem; resize: none; line-height: 1.2;"></textarea>
            
            <input type="file" id="fIn" class="form-control bg-dark text-white border-secondary mb-4" accept="video/*">
            
            <div class="d-flex justify-content-between align-items-center mb-4 px-2">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="mirrorCheck">
                    <label class="small text-white">Modo Espejo</label>
                </div>
                <small class="text-muted" style="font-size: 0.7rem;">Diseño: Full Width + Logo Bottom</small>
            </div>
            
            <button class="btn-go" onclick="process()">⚡ RENDERIZAR AHORA</button>
        </div>
    <?php else: ?>
        <div class="alert alert-danger text-center">❌ SISTEMA NO DISPONIBLE</div>
    <?php endif; ?>

    <div id="uiProcess" class="hidden text-center mt-5">
        <div class="spinner-border text-warning mb-4" style="width: 3rem; height: 3rem;"></div>
        <h4 class="fw-bold text-white" style="font-family: 'Anton';">PROCESANDO...</h4>
        <p class="text-muted small">Aplicando psicología viral...</p>
    </div>

    <div id="uiResult" class="hidden text-center mt-4">
        <div id="videoContainer"></div>
        <a id="dlLink" href="#" class="btn btn-warning w-100 mt-3 fw-bold py-3" style="font-family: 'Anton'; font-size: 1.2rem;">⬇️ DESCARGAR VIDEO</a>
        <button onclick="location.reload()" class="btn btn-outline-light w-100 mt-3 btn-sm">Crear Otro</button>
    </div>
</div>

<script>
async function process() {
    const tIn = document.getElementById('tIn').value;
    const fIn = document.getElementById('fIn').files[0];
    if(!fIn) return alert("¡Sube un video!");
    
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
        else { alert("Error: " + data.msg); location.reload(); }
    } catch(e) { alert("Error red"); location.reload(); }
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
                document.getElementById('dlLink').href = '?action=download&file=' + data.file;
                document.getElementById('videoContainer').innerHTML = 
                    `<video src="processed/${data.file}?t=${Date.now()}" controls autoplay muted loop class="w-100 h-100"></video>`;
            } else if(data.status === 'error') {
                clearInterval(i);
                alert("Error: " + data.msg); location.reload();
            }
        } catch {}
    }, 2000);
}
document.getElementById('tIn')?.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
</script>
</body>
</html>
