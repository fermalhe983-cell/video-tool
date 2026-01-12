<?php
// ==========================================
// VIRAL REELS MAKER v63.1 (GOLD MASTER PRO)
// Versión Mejorada - Soporte videos hasta 5 minutos
// - Audio en loop automático para videos largos
// - Optimizado para videos cortos (10s) y largos (5min)
// ==========================================

// Configuración
@ini_set('display_errors', 0);
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', 1800); // 30 minutos para videos largos

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

// Limpieza Automática (Archivos viejos de más de 2 horas para videos largos)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 7200)) @unlink($file);
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

// ---> DESCARGA
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = "$processedDir/$file";
    if (file_exists($filePath)) {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="NOTICIA_VIRAL_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// ---> BORRADO MANUAL
if ($action === 'delete_job' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    // Borrar el video final
    if (file_exists("$processedDir/$file")) @unlink("$processedDir/$file");
    
    // Intentar borrar los archivos temporales asociados (por ID)
    $jobId = explode('_', $file)[0]; // "v63_xxxx"
    foreach (glob("$uploadDir/{$jobId}_*") as $f) @unlink($f);
    foreach (glob("$jobsDir/{$jobId}*") as $f) @unlink($f);
    
    header('Location: ?msg=deleted');
    exit;
}

// ---> UPLOAD & PROCESS
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!$status['ffmpeg']) { 
        echo json_encode(['status'=>'error', 'msg'=>'Error: FFmpeg no encontrado.']); 
        exit; 
    }

    $jobId = uniqid('v63_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile);
    chmod($inputFile, 0777);

    // Obtener duración del video para calcular repeticiones del audio
    $durationCmd = escapeshellarg($ffmpegPath) . " -i " . escapeshellarg($inputFile) . " 2>&1 | grep Duration";
    $durationOutput = shell_exec($durationCmd);
    preg_match('/Duration: (\d{2}):(\d{2}):(\d{2}\.\d{2})/', $durationOutput, $matches);
    $videoDuration = 0;
    if (count($matches) >= 4) {
        $videoDuration = ($matches[1] * 3600) + ($matches[2] * 60) + $matches[3];
    }

    // DATOS
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    // TEXTO (18 chars wrap, max 2 lines)
    $rawTitle = mb_strtoupper($_POST['videoTitle'] ?? '');
    $wrappedText = wordwrap($rawTitle, 18, "\n", true); 
    $lines = explode("\n", $wrappedText);
    
    if(count($lines) > 2) { 
        $lines = array_slice($lines, 0, 2); 
        $lines[1] = substr($lines[1], 0, 15) . ".."; 
    }
    $count = count($lines);

    // LAYOUT
    $canvasW = 720;
    $canvasH = 1280;
    
    if ($count == 1) {
        $fSize = 80; $startYs = [30]; $videoY = 135;
    } else {
        $fSize = 70; $startYs = [30, 110]; $videoY = 210;
    }

    // INPUTS
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    
    // Audio de fondo con loop infinito (-stream_loop -1 hace que se repita indefinidamente)
    if ($useAudio) {
        $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);
    }

    $mirrorCmd = $useMirror ? ",hflip" : "";
    
    // FILTROS
    $filter = "";
    
    // 1. LIENZO
    $filter .= "color=c=#080808:s={$canvasW}x{$canvasH}:d=" . ceil($videoDuration) . "[bg];";
    
    // 2. VIDEO (SPEED + COLOR)
    $filter .= "[0:v]scale={$canvasW}:-1,setsar=1{$mirrorCmd},eq=saturation=1.15:contrast=1.05,setpts=0.98*PTS[vid];";
    $filter .= "[bg][vid]overlay=0:{$videoY}:shortest=1[base];";
    $lastStream = "[base]";

    // 3. TEXTO
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        foreach ($lines as $i => $line) {
            $y = $startYs[$i];
            $lineSafe = str_replace("'", "\\'", $line);
            $filter .= "{$lastStream}drawtext=fontfile='$fontSafe':text='$lineSafe':fontcolor=#FFD700:fontsize={$fSize}:borderw=4:bordercolor=black:shadowx=4:shadowy=4:x=(w-text_w)/2:y={$y}[v_text_{$i}];";
            $lastStream = "[v_text_{$i}]";
        }
    }

    // 4. LOGO
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:80[logo_s];";
        $logoPosY = $canvasH - 120;
        $filter .= "{$lastStream}[logo_s]overlay=30:{$logoPosY}[vfinal]";
        $lastStream = "[vfinal]";
    } else {
        $filter .= "{$lastStream}copy[vfinal]";
    }

    // 5. AUDIO - MEJORADO PARA VIDEOS LARGOS
    if ($useAudio) {
        $mIdx = $useLogo ? "2" : "1";
        
        // Procesar audio original del video (voz)
        $filter .= ";[0:a]atempo=1.02,volume=1.0[voice];";
        
        // Audio de fondo (BGM) - ya está en loop por -stream_loop -1
        // Usamos aloop para asegurar que se repita internamente también
        $filter .= "[{$mIdx}:a]volume=0.15,aloop=loop=-1:size=2e+09[bgm];";
        
        // Mezclar ambos audios - duration=first toma la duración del primer input (voice)
        // Esto asegura que el audio dure lo mismo que el video
        $filter .= "[voice][bgm]amix=inputs=2:duration=first:dropout_transition=0[afinal]";
    } else {
        // Solo audio del video original
        $filter .= ";[0:a]atempo=1.02,volume=1.0[afinal]";
    }

    // COMANDO FFMPEG OPTIMIZADO
    $cmd = "nice -n 10 " . escapeshellarg($ffmpegPath) . " -y $inputs " .
           "-filter_complex \"$filter\" " .
           "-map \"[vfinal]\" -map \"[afinal]\" " .
           "-c:v libx264 -preset ultrafast -threads 4 -crf 26 " .
           "-pix_fmt yuv420p " .
           "-c:a aac -b:a 128k -ar 44100 " .
           "-movflags +faststart " .
           "-shortest " .  // IMPORTANTE: Detiene cuando el stream más corto termina
           escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    exec($cmd);

    file_put_contents($jobFile, json_encode([
        'status' => 'processing', 
        'file' => $outputFileName, 
        'start' => time(),
        'duration' => $videoDuration
    ]));
    
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
        
        if (file_exists($fullPath) && filesize($fullPath) > 50000) {
            chmod($fullPath, 0777);
            echo json_encode(['status' => 'finished', 'file' => $data['file']]);
        } elseif (time() - $data['start'] > 1500) { // 25 minutos timeout para videos largos
            echo json_encode(['status' => 'error', 'msg' => 'Timeout - Video muy largo']);
        } else {
            // Calcular progreso aproximado
            $elapsed = time() - $data['start'];
            $progress = min(95, ($elapsed / max(1, ($data['duration'] ?? 60))) * 100);
            echo json_encode(['status' => 'processing', 'progress' => round($progress)]);
        }
    } else { 
        echo json_encode(['status' => 'error', 'msg' => 'Job no encontrado']); 
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral Maker Gold Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&display=swap" rel="stylesheet">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>⚡</text></svg>">
    <style>
        body { background: #000; color: #fff; padding: 15px; font-family: sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: #111; border: 1px solid #333; max-width: 480px; width: 100%; padding: 25px; border-radius: 25px; box-shadow: 0 0 50px rgba(255, 215, 0, 0.1); }
        h2 { font-family: 'Anton', sans-serif; letter-spacing: 1px; color: #fff; }
        
        .form-control { background: #000 !important; color: #FFD700 !important; border: 2px solid #333; font-weight: bold; text-align: center; border-radius: 12px; }
        .form-control:focus { border-color: #FFD700; box-shadow: 0 0 15px rgba(255,215,0,0.2); }
        
        #tIn { font-family: 'Anton', sans-serif; font-size: 1.4rem; text-transform: uppercase; }
        .char-counter { font-size: 0.8rem; color: #666; text-align: right; margin-top: 5px; font-weight: bold; }
        
        .btn-go { width: 100%; padding: 18px; background: linear-gradient(135deg, #FFD700, #FFA500); color: #000; font-family: 'Anton'; font-size: 1.4rem; border: none; border-radius: 12px; cursor: pointer; transition: 0.2s; }
        .btn-go:hover { transform: scale(1.02); background: #fff; }
        .btn-go:disabled { opacity: 0.5; cursor: not-allowed; }

        .btn-clean { background: #333; color: #aaa; border: 1px solid #444; width: 100%; padding: 12px; border-radius: 12px; font-weight: bold; transition: 0.2s; }
        .btn-clean:hover { background: #f00; color: #fff; border-color: #f00; }
        
        .hidden { display: none; }
        #videoContainer { width: 100%; aspect-ratio: 9/16; background: #000; margin-top: 20px; border-radius: 12px; overflow: hidden; border: 1px solid #333; }
        video { width: 100%; height: 100%; object-fit: cover; }
        
        .progress { height: 8px; background: #222; border-radius: 10px; overflow: hidden; }
        .progress-bar { background: linear-gradient(90deg, #FFD700, #FFA500); transition: width 0.3s; }
        
        .alert-info { background: #1a3a4a; border-color: #2a5a7a; color: #6fc3df; }
    </style>
</head>
<body>

<div class="card">
    <div class="text-center mb-4">
        <h2>VIRAL MAKER <span class="text-warning">PRO v63.1</span></h2>
        <p class="text-muted small m-0">SOPORTE HASTA 5 MINUTOS</p>
    </div>

    <?php if ($status['ffmpeg']): ?>
        <div id="uiInput">
            <div class="alert alert-info small mb-3">
                ℹ️ Soporta videos de 10 segundos hasta 5 minutos. El audio se repetirá automáticamente.
            </div>
            
            <div class="mb-3">
                <input type="text" id="tIn" class="form-control py-3" placeholder="TITULAR IMPACTANTE" maxlength="36" autocomplete="off">
                <div id="charCount" class="char-counter">0 / 36</div>
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
        <div class="spinner-border text-warning mb-4" style="width: 4rem; height: 4rem;"></div>
        <h3 class="text-white" style="font-family: 'Anton'">RENDERIZANDO...</h3>
        <p class="text-success small mb-3">Aplicando Evasión Pro + Titular + Audio Loop...</p>
        <div class="progress">
            <div id="progressBar" class="progress-bar" style="width: 0%"></div>
        </div>
        <p class="text-muted small mt-2"><span id="progressText">0</span>% completado</p>
    </div>

    <div id="uiResult" class="hidden text-center mt-3">
        <div id="videoContainer"></div>
        <a id="dlLink" href="#" class="btn btn-warning w-100 mt-3 fw-bold py-3" style="font-family: 'Anton'; font-size: 1.2rem;">⬇️ DESCARGAR VIDEO</a>
        
        <div class="row mt-3 g-2">
            <div class="col-6">
                 <button onclick="cleanAndNew()" class="btn-clean">🗑️ Borrar y Nuevo</button>
            </div>
            <div class="col-6">
                 <button onclick="location.reload()" class="btn-clean">🔄 Solo Nuevo</button>
            </div>
        </div>
        <p class="text-muted small mt-2">"Borrar y Nuevo" libera espacio en el servidor.</p>
    </div>
</div>

<script>
let currentFile = '';

const tIn = document.getElementById('tIn');
const counter = document.getElementById('charCount');

if(tIn) {
    tIn.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
        counter.innerText = `${this.value.length} / 36`;
    });
}

async function process() {
    const fIn = document.getElementById('fIn').files[0];
    if(!fIn) return alert("¡Falta el video!");
    
    // Validar tamaño (máximo 500MB)
    if(fIn.size > 500 * 1024 * 1024) {
        return alert("⚠️ El video es muy grande. Máximo 500MB.");
    }
    
    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData();
    fd.append('videoTitle', tIn.value);
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
        alert("Error de conexión: " + e.message); 
        location.reload(); 
    }
}

function track(id) {
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    
    const i = setInterval(async () => {
        try {
            const res = await fetch(`?action=status&jobId=${id}`);
            const data = await res.json();
            
            if(data.status === 'finished') {
                clearInterval(i);
                currentFile = data.file;
                document.getElementById('uiProcess').classList.add('hidden');
                document.getElementById('uiResult').classList.remove('hidden');
                document.getElementById('dlLink').href = '?action=download&file=' + data.file;
                document.getElementById('videoContainer').innerHTML = 
                    `<video src="processed/${data.file}?t=${Date.now()}" controls autoplay muted loop class="w-100 h-100"></video>`;
            } else if(data.status === 'error') {
                clearInterval(i);
                alert("❌ Error: " + (data.msg || 'Error desconocido')); 
                location.reload();
            } else if(data.status === 'processing') {
                // Actualizar barra de progreso
                if(data.progress) {
                    progressBar.style.width = data.progress + '%';
                    progressText.textContent = data.progress;
                }
            }
        } catch(e) {
            console.error('Error checking status:', e);
        }
    }, 2000);
}

function cleanAndNew() {
    if(currentFile) {
        if(confirm('¿Borrar el video del servidor y crear uno nuevo?')) {
            window.location.href = '?action=delete_job&file=' + currentFile;
        }
    } else {
        location.reload();
    }
}
</script>
</body>
</html>
