<?php
// ==========================================
// VIRAL REELS MAKER v59.0 (ULTRA COMPACT)
// Optimización de espacio vertical:
// - Título pegado al borde superior (Top safe area).
// - Video subido inmediatamente debajo del título.
// - Interfaz limita caracteres para forzar estilo "Punchy" (2 líneas máx).
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
$status = [
    'ffmpeg' => !empty($ffmpegPath),
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
        header('Content-Disposition: attachment; filename="VIRAL_MAX_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!$status['ffmpeg']) { echo json_encode(['status'=>'error', 'msg'=>'Error: FFmpeg no encontrado.']); exit; }

    $jobId = uniqid('v59_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile);
    chmod($inputFile, 0777);

    // DATOS
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    // TEXTO (Limitado y Compacto)
    $rawTitle = mb_strtoupper($_POST['videoTitle'] ?? '');
    // Wrap a 22 caracteres para aprovechar el ancho completo
    $wrappedText = wordwrap($rawTitle, 22, "\n", true); 
    $lines = explode("\n", $wrappedText);
    
    // FORZAMOS MÁXIMO 2 LÍNEAS (Psicología Viral: Si es más largo, aburre)
    if(count($lines) > 2) { 
        $lines = array_slice($lines, 0, 2); 
        $lines[1] .= ".."; // Indicador de corte
    }
    $count = count($lines);

    // --- GEOMETRÍA ULTRA COMPACTA ---
    $canvasW = 720;
    $canvasH = 1280;
    
    // Configuración Geométrica
    if ($count == 1) {
        // 1 Línea: Título gigante, video sube mucho
        $fSize = 95;
        $startYs = [25]; // Casi tocando el borde (margen mínimo de seguridad)
        $videoY = 140;   // Video empieza inmediatamente después
    } else {
        // 2 Líneas
        $fSize = 85;
        $startYs = [25, 115]; // Línea 1 arriba, Línea 2 pegadita
        $videoY = 230;        // Video empieza justo debajo
    }

    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($useAudio) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";
    
    // 1. LIENZO DARK MODE (#101010)
    $filter .= "color=c=#101010:s={$canvasW}x{$canvasH}[bg];";
    
    // 2. VIDEO FULL WIDTH (Estirado a los lados, posición dinámica)
    $filter .= "[0:v]scale={$canvasW}:-1,setsar=1{$mirrorCmd}[vid];";
    // Overlay en Y variable según cuántas líneas de texto haya
    $filter .= "[bg][vid]overlay=0:{$videoY}:shortest=1[base];";
    $lastStream = "[base]";

    // 3. TEXTO (AMARILLO IMPACTO)
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        foreach ($lines as $i => $line) {
            $y = $startYs[$i];
            // Amarillo puro, Borde negro sólido (4px), Sombra dura
            $filter .= "{$lastStream}drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=4:bordercolor=black:shadowx=3:shadowy=3:x=(w-text_w)/2:y={$y}[v_text_{$i}];";
            $lastStream = "[v_text_{$i}]";
        }
    }

    // 4. LOGO (ABAJO IZQUIERDA - Marca de agua)
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:80[logo_s];";
        // Margen inferior ajustado
        $logoPosY = $canvasH - 120;
        $filter .= "{$lastStream}[logo_s]overlay=30:{$logoPosY}[vfinal]";
        $lastStream = "[vfinal]";
    } else {
        $filter .= "{$lastStream}copy[vfinal]";
    }

    // 5. AUDIO MIX
    if ($useAudio) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= ";[{$mIdx}:a]volume=0.15[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= ";[0:a]atempo=1.0[afinal]";
    }

    // EJECUCIÓN
    $cmd = "nice -n 10 " . escapeshellarg($ffmpegPath) . " -y $inputs -filter_complex \"$filter\" -map \"[vfinal]\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -threads 2 -crf 26 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

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
    <title>Viral v59 Ultra</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&display=swap" rel="stylesheet">
    <style>
        body { background: #050505; color: #fff; padding: 15px; font-family: sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #151515; border: 1px solid #333; max-width: 480px; width: 100%; padding: 25px; border-radius: 25px; box-shadow: 0 0 50px rgba(255, 215, 0, 0.1); }
        
        h2 { font-family: 'Anton', sans-serif; letter-spacing: 2px; color: #FFD700; text-shadow: 0 0 10px rgba(255, 215, 0, 0.5); }
        
        .form-control { background: #000 !important; color: #FFD700 !important; border: 2px solid #333; font-weight: bold; text-align: center; border-radius: 12px; }
        .form-control:focus { border-color: #FFD700; box-shadow: 0 0 15px rgba(255,215,0,0.2); }
        
        /* Input de Título Grande y Limitado */
        #tIn { font-family: 'Anton', sans-serif; font-size: 1.5rem; text-transform: uppercase; height: auto; }
        
        .char-counter { font-size: 0.8rem; color: #666; text-align: right; margin-top: 5px; font-weight: bold; }
        .char-counter.limit { color: #f00; }
        
        .btn-go { width: 100%; padding: 18px; background: #FFD700; color: #000; font-family: 'Anton'; font-size: 1.4rem; border: none; border-radius: 12px; cursor: pointer; transition: 0.2s; text-transform: uppercase; letter-spacing: 1px; }
        .btn-go:hover { background: #fff; transform: scale(1.02); box-shadow: 0 0 20px rgba(255,215,0,0.4); }
        
        .hidden { display: none; }
        #videoContainer { width: 100%; aspect-ratio: 9/16; background: #000; margin-top: 20px; border-radius: 12px; overflow: hidden; border: 1px solid #333; }
        video { width: 100%; height: 100%; object-fit: cover; }
    </style>
</head>
<body>

<div class="card">
    <div class="text-center mb-4">
        <h2>VIRAL MAKER v59</h2>
        <p class="text-muted small m-0">DISEÑO ALTO IMPACTO (MAX 45 CARACTERES)</p>
    </div>

    <?php if ($status['ffmpeg']): ?>
        <div id="uiInput">
            <div class="mb-3">
                <input type="text" id="tIn" class="form-control py-3" placeholder="TITULAR CORTO AQUÍ" maxlength="45" autocomplete="off">
                <div id="charCount" class="char-counter">0 / 45</div>
            </div>
            
            <input type="file" id="fIn" class="form-control mb-4" accept="video/*" style="font-family: sans-serif; font-size: 0.9rem; padding: 10px;">
            
            <div class="d-flex justify-content-center gap-3 mb-4">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="mirrorCheck">
                    <label class="text-secondary small fw-bold">Modo Espejo</label>
                </div>
            </div>
            
            <button class="btn-go" onclick="process()">⚡ CREAR VIDEO ⚡</button>
        </div>
    <?php else: ?>
        <div class="alert alert-danger text-center fw-bold">⚠️ FFMPEG NO DETECTADO</div>
    <?php endif; ?>

    <div id="uiProcess" class="hidden text-center py-4">
        <div class="spinner-border text-warning mb-4" style="width: 4rem; height: 4rem; border-width: 5px;"></div>
        <h3 class="text-white" style="font-family: 'Anton'">RENDERIZANDO...</h3>
        <p class="text-muted small">Ajustando geometría y audio...</p>
    </div>

    <div id="uiResult" class="hidden text-center mt-3">
        <div id="videoContainer"></div>
        <a id="dlLink" href="#" class="btn btn-warning w-100 mt-3 fw-bold py-3" style="font-family: 'Anton'; font-size: 1.2rem;">⬇️ DESCARGAR AHORA</a>
        <button onclick="location.reload()" class="btn btn-outline-secondary w-100 mt-3">Crear Nuevo</button>
    </div>
</div>

<script>
// Contador de Caracteres para Psicología Viral
const tIn = document.getElementById('tIn');
const counter = document.getElementById('charCount');

if(tIn) {
    tIn.addEventListener('input', function() {
        this.value = this.value.toUpperCase(); // Forzar mayúsculas
        const len = this.value.length;
        counter.innerText = `${len} / 45`;
        if(len >= 45) counter.classList.add('limit');
        else counter.classList.remove('limit');
    });
}

async function process() {
    const fIn = document.getElementById('fIn').files[0];
    if(!fIn) return alert("¡Falta el video!");
    if(!tIn.value.trim() && !confirm("¿Video sin título?")) return;

    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData();
    fd.append('videoTitle', tIn.value);
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
</script>
</body>
</html>
