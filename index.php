<?php
// ==========================================
// VIRAL REELS MAKER v57.0 (SYNTAX FIXED)
// Corrige el error "No such filter" reparando la cadena de filtros.
// Mantiene: Smart Layout (Vertical/Horizontal), Titular Noticia, Hash Changer.
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

function getVideoDimensions($filePath, $ffprobePath) {
    $cmd = "$ffprobePath -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 " . escapeshellarg($filePath);
    $output = shell_exec($cmd);
    $dims = explode('x', trim($output));
    if (count($dims) == 2 && is_numeric($dims[0]) && is_numeric($dims[1])) {
        return ['w' => intval($dims[0]), 'h' => intval($dims[1])];
    }
    return null; // Fallback
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
        header('Content-Disposition: attachment; filename="VIRAL_FIX_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!$status['ffmpeg']) { echo json_encode(['status'=>'error', 'msg'=>'Error: FFmpeg no encontrado.']); exit; }

    $jobId = uniqid('v57_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status'=>'error', 'msg'=>'Error al subir archivo.']); exit;
    }
    chmod($inputFile, 0777);

    // ANALIZAR VIDEO
    $dims = getVideoDimensions($inputFile, $ffprobePath);
    $isVertical = false;
    if ($dims) {
        $aspectRatio = $dims['w'] / $dims['h'];
        $isVertical = ($aspectRatio < 0.8); // Consideramos vertical si es más alto que ancho
    }

    // DATOS
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    $rawTitle = mb_strtoupper($_POST['videoTitle'] ?? '');
    $wrappedText = wordwrap($rawTitle, 16, "\n", true);
    $lines = explode("\n", $wrappedText);
    if(count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    // CONFIGURACIÓN LAYOUT
    $canvasW = 720;
    $canvasH = 1280;
    // Espacio reservado para el titular (Barra superior)
    $headlineH = ($count == 3) ? 420 : (($count == 2) ? 320 : 220);

    // --- CONSTRUCCIÓN DEL COMANDO (CORREGIDA) ---
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($useAudio) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";
    
    // 1. BASE NEGRA
    $filter .= "color=c=black:s={$canvasW}x{$canvasH}[bg];";
    
    // 2. VIDEO (SMART POSITIONING)
    if ($isVertical) {
        // Si es vertical, lo encogemos un poco y lo bajamos para dejar espacio al texto
        $vidH = $canvasH - $headlineH;
        $filter .= "[0:v]scale=-1:{$vidH}:force_original_aspect_ratio=decrease,setsar=1{$mirrorCmd}[vid];";
        $filter .= "[bg][vid]overlay=(W-w)/2:{$headlineH}:shortest=1[base];"; // Overlay starts AFTER headline
    } else {
        // Si es horizontal, va al centro
        $filter .= "[0:v]scale={$canvasW}:-1:force_original_aspect_ratio=decrease,setsar=1{$mirrorCmd}[vid];";
        $filter .= "[bg][vid]overlay=(W-w)/2:(H-h)/2:shortest=1[base];";
    }
    
    // 3. BARRA DE TITULAR (SÓLIDA)
    // Usamos el stream [base] creado arriba
    $filter .= "[base]drawbox=x=0:y=0:w=iw:h={$headlineH}:color=black@1:t=fill[v_boxed];";
    $lastStream = "[v_boxed]"; // Puntero al último stream válido

    // 4. TEXTO (NOTICIA)
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        $fSize = ($count == 1) ? 100 : (($count == 2) ? 90 : 80);
        $startYs = ($count == 1) ? [80] : (($count == 2) ? [70, 170] : [60, 155, 250]);

        foreach ($lines as $i => $line) {
            $y = $startYs[$i];
            // Aquí está la corrección: Encadenamos el filtro al stream anterior
            $filter .= "{$lastStream}drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=5:bordercolor=black:shadowx=3:shadowy=3:x=(w-text_w)/2:y={$y}[v_text_{$i}];";
            $lastStream = "[v_text_{$i}]"; // Actualizamos el puntero
        }
    }

    // 5. LOGO
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:70[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=30:30[vfinal]";
        $lastStream = "[vfinal]";
    } else {
        // Si no hay logo, renombramos el último stream a vfinal
        $filter .= "{$lastStream}copy[vfinal]";
    }

    // 6. AUDIO MIX
    if ($useAudio) {
        $mIdx = $useLogo ? "2" : "1";
        $filter .= ";[{$mIdx}:a]volume=0.2[bgm];[0:a]volume=1.0[voice];[voice][bgm]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= ";[0:a]atempo=1.0[afinal]";
    }

    // EJECUCIÓN
    $cmd = "nice -n 10 " . escapeshellarg($ffmpegPath) . " -y $inputs -filter_complex \"$filter\" -map \"[vfinal]\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -threads 2 -crf 27 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

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
    <title>Viral v57 Fixed</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background: #000; color: #fff; padding: 20px; font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #111; border: 1px solid #333; max-width: 500px; width: 100%; padding: 25px; border-radius: 20px; box-shadow: 0 0 40px rgba(255, 200, 0, 0.15); }
        .status-table { width: 100%; margin-bottom: 20px; font-size: 0.85rem; }
        .ok { color: #0f0; font-weight: bold; } .fail { color: #f00; font-weight: bold; }
        .btn-go { width: 100%; padding: 15px; background: linear-gradient(45deg, #FFD700, #FFA500); color: #000; font-family: 'Anton'; font-size: 1.3rem; border: none; border-radius: 10px; cursor: pointer; transition: transform 0.2s; }
        .btn-go:hover { transform: scale(1.03); }
        .hidden { display: none; }
        #videoContainer { width: 100%; aspect-ratio: 9/16; background: #000; margin-top: 20px; border-radius: 10px; overflow: hidden; border: 1px solid #333; }
        video { width: 100%; height: 100%; object-fit: cover; }
    </style>
</head>
<body>

<div class="card">
    <h2 class="text-center mb-4 text-warning fw-bold" style="font-family: 'Anton';">SISTEMA v57</h2>

    <div class="small mb-3 text-center text-muted">
        Motor: <span class="<?php echo $status['ffmpeg']?'ok':'fail'; ?>"><?php echo $status['ffmpeg']?'ACTIVO':'ERROR'; ?></span> | 
        Texto: <span class="<?php echo $status['drawtext']?'ok':'fail'; ?>"><?php echo $status['drawtext']?'OK':'ERROR'; ?></span>
    </div>

    <?php if ($status['ffmpeg'] && $status['drawtext']): ?>
        <div id="uiInput">
            <textarea id="tIn" class="form-control bg-dark text-white border-warning mb-3 fw-bold text-center" placeholder="TITULAR DE NOTICIA..." rows="3" style="font-family: 'Anton'; font-size: 1.2rem; resize: none;"></textarea>
            <input type="file" id="fIn" class="form-control bg-dark text-white border-secondary mb-3" accept="video/*">
            <div class="d-flex justify-content-center align-items-center gap-2 mb-4 p-2 bg-black rounded">
                <div class="form-check form-switch m-0">
                    <input class="form-check-input" type="checkbox" id="mirrorCheck">
                </div>
                <span class="small text-white">Modo Espejo</span>
            </div>
            <button class="btn-go" onclick="process()">GENERAR VIDEO</button>
        </div>
    <?php else: ?>
        <div class="alert alert-danger text-center">❌ SISTEMA NO DISPONIBLE</div>
    <?php endif; ?>

    <div id="uiProcess" class="hidden text-center mt-5">
        <div class="spinner-border text-warning mb-4"></div>
        <h4 class="fw-bold text-warning" style="font-family: 'Anton';">PROCESANDO...</h4>
    </div>

    <div id="uiResult" class="hidden text-center mt-4">
        <div id="videoContainer"></div>
        <a id="dlLink" href="#" class="btn btn-warning w-100 mt-3 fw-bold py-3" style="font-family: 'Anton'; font-size: 1.2rem;">⬇️ DESCARGAR</a>
        <button onclick="location.reload()" class="btn btn-outline-light w-100 mt-3 btn-sm">Nuevo</button>
    </div>
</div>

<script>
async function process() {
    const tIn = document.getElementById('tIn').value;
    const fIn = document.getElementById('fIn').files[0];
    if(!fIn) return alert("¡Sube un video!");
    if(!tIn.trim() && !confirm("¿Sin título?")) return;

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
    } catch(e) { alert("Error de red"); location.reload(); }
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
