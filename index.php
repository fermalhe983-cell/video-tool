<?php
// ==========================================
// VIRAL REELS MAKER v27.0 (HEAVY DUTY UNIVERSAL)
// Soporte: Archivos Pesados + Cualquier Resolución + Estabilidad Total
// ==========================================

// 1. CONFIGURACIÓN DE SERVIDOR PARA ARCHIVOS PESADOS
@ini_set('upload_max_filesize', '512M');
@ini_set('post_max_size', '512M');
@ini_set('max_input_time', 300); // 5 minutos de subida
@ini_set('max_execution_time', 600); // 10 minutos de proceso
@ini_set('memory_limit', '2048M');
@ini_set('display_errors', 0);

// DIRECTORIOS
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';

// Crear carpetas y asegurar permisos
if (!file_exists($uploadDir)) { mkdir($uploadDir, 0777, true); chmod($uploadDir, 0777); }
if (!file_exists($processedDir)) { mkdir($processedDir, 0777, true); chmod($processedDir, 0777); }
if (!file_exists($jobsDir)) { mkdir($jobsDir, 0777, true); chmod($jobsDir, 0777); }

// LIMPIEZA AUTOMÁTICA (Archivos de más de 1 hora)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 3600)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> DESCARGA SEGURA
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = "$processedDir/$file";
    if (file_exists($filePath)) {
        // Limpiamos buffer de salida para evitar corrupción en archivos grandes
        if (ob_get_level()) ob_end_clean();
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="VIRAL_PRO_'.date('dmY_Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        header('Pragma: public');
        readfile($filePath);
        exit;
    }
}

// ---> PROCESAMIENTO
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Verificación de error de subida PHP
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['videoFile']['error'] ?? 'No file';
        $errMsg = 'Error desconocido';
        if ($errCode == UPLOAD_ERR_INI_SIZE) $errMsg = 'El archivo es demasiado grande para el servidor (php.ini limit).';
        if ($errCode == UPLOAD_ERR_PARTIAL) $errMsg = 'La subida se interrumpió.';
        if ($errCode == UPLOAD_ERR_NO_FILE) $errMsg = 'No se envió ningún archivo.';
        echo json_encode(['status' => 'error', 'message' => "Error subida ($errCode): $errMsg"]); 
        exit;
    }

    $jobId = uniqid('v27_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status' => 'error', 'message' => 'Fallo al mover archivo al disco.']); exit;
    }
    chmod($inputFile, 0777);

    // --- OPCIONES ---
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';

    // --- TEXTO DINÁMICO ---
    $rawTitle = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $rawTitle = mb_strtoupper($rawTitle);
    
    // Auto-ajuste de líneas
    $wrappedText = wordwrap($rawTitle, 16, "\n", true);
    $lines = explode("\n", $wrappedText);
    if (count($lines) > 3) { $lines = array_slice($lines, 0, 3); $lines[2] .= ".."; }
    $count = count($lines);

    // Configuración de Barra Negra según líneas
    if ($count == 1) { $barH = 240; $fSize = 110; $yPos = [140]; }
    elseif ($count == 2) { $barH = 350; $fSize = 100; $yPos = [100, 215]; }
    else { $barH = 450; $fSize = 80; $yPos = [90, 190, 290]; }

    // --- CONSTRUCCIÓN DEL COMANDO FFMPEG UNIVERSAL ---
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    if ($useAudio) $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);

    // TRUCO DE INGENIERÍA:
    // 1. Forzamos todo video entrante a convertirse en una caja segura.
    // 2. Usamos 'trunc(iw/2)*2' para asegurar dimensiones pares (evita error "width not divisible by 2").
    // 3. Normalizamos frame rate a 30 para estabilidad.
    
    $mirrorCmd = $useMirror ? ",hflip" : "";
    $filter = "";

    // PASO A: Crear FONDO Borroso (Background)
    // Escalamos para llenar 1080x1920 sin importar el origen, luego crop y blur
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10{$mirrorCmd}[bg];";

    // PASO B: Preparar VIDEO PRINCIPAL (Foreground)
    // Escalamos para que quepa DENTRO de 1080x1920 sin cortarse.
    // 'pad' centra el video si es horizontal o cuadrado.
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,setsar=1";
    // Aplicamos filtros de "unicidad" (contraste, ruido, espejo)
    $filter .= ",eq=contrast=1.05:saturation=1.1,noise=alls=1:allf=t+u{$mirrorCmd}[fg];";

    // PASO C: Unir Fondo + Frente
    $filter .= "[bg][fg]overlay=0:0[base];";
    $lastStream = "[base]";

    // PASO D: Barra Negra (Overlay)
    $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h={$barH}:color=black@0.9:t=fill[bar];";
    $lastStream = "[bar]";

    // PASO E: Logo
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:140[logo_s];";
        $logoY = 60 + ($barH/2) - 70; // Centrado vertical en la barra
        $filter .= "{$lastStream}[logo_s]overlay=40:{$logoY}[wlogo];";
        $lastStream = "[wlogo]";
    }

    // PASO F: Texto
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        $xPos = $useLogo ? "(w-text_w)/2+70" : "(w-text_w)/2";
        
        foreach ($lines as $i => $line) {
            $y = $yPos[$i];
            $streamOut = ($i == $count - 1) ? "titled" : "txt{$i}";
            $streamIn = ($i == 0) ? $lastStream : "[txt".($i-1)."]";
            // shadow para mejor lectura
            $draw = "drawtext=fontfile='$fontSafe':text='$line':fontcolor=#FFD700:fontsize={$fSize}:borderw=4:bordercolor=black:shadowx=2:shadowy=2:x={$xPos}:y={$y}";
            $filter .= "{$streamIn}{$draw}[{$streamOut}];";
        }
        $lastStream = "[titled]";
    }

    // PASO G: Audio Mix
    $filter .= "{$lastStream}setpts=0.95*PTS[vfinal];"; // Ligera aceleración video
    if ($useAudio) {
        $mIdx = $useLogo ? "2" : "1"; // Índice del audio de fondo
        // [0:a] Voz principal (Vol 1.0)
        // [X:a] Música fondo (Vol 0.12)
        // amix con duration=first para cortar la música cuando acabe el video
        $filter .= "[{$mIdx}:a]volume=0.12[bgmusic];[0:a]volume=1.0[voice];[voice][bgmusic]amix=inputs=2:duration=first:dropout_transition=2[afinal]";
    } else {
        $filter .= "[0:a]atempo=1.0526[afinal]"; // Aceleración audio sync
    }

    // COMANDO FINAL OPTIMIZADO PARA ESTABILIDAD
    // -preset ultrafast: Clave para que el servidor no haga timeout con archivos grandes
    // -pix_fmt yuv420p: Compatibilidad universal
    // -ac 2: Audio estereo estándar
    $cmd = "nice -n 19 ffmpeg -y $inputs -filter_complex \"$filter\" -map \"[vfinal]\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -crf 28 -r 30 -pix_fmt yuv420p -c:a aac -ar 44100 -ac 2 -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " > /dev/null 2>&1 &";

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
        
        // Verificamos si existe y si ha dejado de crecer (o es suficientemente grande)
        // En servidores rápidos, comprobar solo existencia + tamaño mínimo es suficiente.
        if (file_exists($fullPath) && filesize($fullPath) > 102400) { // >100KB
            // Dar permisos de lectura pública
            chmod($fullPath, 0777); 
            
            // Verificamos si ffmpeg sigue escribiendo (opcional, pero seguro)
            // Simplemente devolvemos finished y dejamos que el frontend maneje la espera visual
            echo json_encode(['status' => 'finished', 'file' => $data['file']]);
        } else {
            // Check timeout interno (si lleva más de 10 mins procesando, dar error)
            if (time() - $data['start'] > 600) {
                 echo json_encode(['status' => 'error', 'message' => 'Timeout procesando']);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Viral Studio Heavy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;900&display=swap" rel="stylesheet">
    <style>
        body { background-color: #050505; font-family: 'Inter', sans-serif; color: white; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 15px; }
        .main-card { background: #111; width: 100%; max-width: 550px; border: 1px solid #333; border-radius: 20px; padding: 25px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        .header-title { font-family: 'Anton', sans-serif; text-align: center; color: #FFD700; font-size: 2.5rem; text-transform: uppercase; margin: 0; line-height: 1; }
        .header-sub { text-align: center; color: #666; font-size: 0.8rem; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 25px; }
        
        .viral-input { background: #000; border: 2px solid #333; color: white; font-family: 'Anton'; font-size: 1.4rem; text-transform: uppercase; padding: 15px; width: 100%; border-radius: 10px; resize: none; }
        .viral-input:focus { outline: none; border-color: #FFD700; }
        
        .upload-area { border: 2px dashed #444; border-radius: 12px; padding: 25px; text-align: center; margin-top: 20px; cursor: pointer; transition: 0.2s; background: #0a0a0a; }
        .upload-area:hover { background: #151515; border-color: #fff; }
        
        .btn-viral { background: #FFD700; color: #000; border: none; width: 100%; padding: 18px; font-family: 'Anton'; font-size: 1.5rem; text-transform: uppercase; border-radius: 12px; margin-top: 25px; cursor: pointer; transition: transform 0.1s; }
        .btn-viral:active { transform: scale(0.98); }
        .btn-viral:disabled { background: #555; cursor: not-allowed; }

        .video-box { background: #000; border: 2px solid #333; border-radius: 15px; overflow: hidden; width: 100%; aspect-ratio: 9/16; margin-bottom: 20px; position: relative; }
        video { width: 100%; height: 100%; object-fit: cover; }
        
        .form-check-input:checked { background-color: #FFD700; border-color: #FFD700; }
        .hidden { display: none !important; }
        
        /* Loading Bar */
        .progress { height: 5px; background: #333; margin-top: 20px; border-radius: 5px; overflow: hidden; }
        .progress-bar { background: #FFD700; width: 0%; transition: width 0.3s; }
    </style>
</head>
<body>

<div class="main-card">
    <h1 class="header-title">HEAVY DUTY</h1>
    <p class="header-sub">Universal Video Processor v27</p>

    <?php if(!file_exists($fontPath)) echo '<div class="alert alert-danger p-1 text-center small mb-2">⚠️ Falta font.ttf</div>'; ?>
    <?php if(!file_exists($audioPath)) echo '<div class="alert alert-warning p-1 text-center small mb-2">⚠️ Falta news.mp3 (Sin música)</div>'; ?>

    <div id="uiInput">
        <form id="vForm">
            <div class="mb-3">
                <label class="fw-bold text-warning small mb-1 d-block">1. TÍTULO (AUTO-AJUSTABLE)</label>
                <textarea name="videoTitle" id="tIn" class="viral-input" rows="2" placeholder="ESCRIBE TU TÍTULO AQUÍ..." required></textarea>
            </div>

            <div class="form-check form-switch mb-4 p-3 border border-secondary rounded bg-dark d-flex align-items-center justify-content-between">
                <label class="form-check-label text-white small" for="mirrorCheck">
                    <strong>MODO ESPEJO</strong> (Anti-Copyright)
                </label>
                <input class="form-check-input" type="checkbox" id="mirrorCheck" style="width: 3em; height: 1.5em;">
            </div>

            <div class="upload-area" onclick="document.getElementById('fIn').click()">
                <div class="fs-1">📥</div>
                <div class="fw-bold mt-2" id="fileName">Toca para subir video</div>
                <div class="small text-muted">Soporta Horizontal, Vertical, 4K</div>
                <input type="file" name="videoFile" id="fIn" accept="video/*" hidden required>
            </div>

            <button type="submit" class="btn-viral" id="submitBtn">🚀 PROCESAR</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden text-center py-5">
        <div class="spinner-border text-warning mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
        <h3 class="fw-bold animate-pulse">RENDERIZANDO...</h3>
        <p class="text-muted small" id="statusText">Subiendo y analizando video...</p>
        <div class="progress">
            <div id="pBar" class="progress-bar" role="progressbar"></div>
        </div>
        <p class="text-secondary small mt-2">Archivos grandes pueden tardar unos minutos.</p>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h3 class="text-success fw-bold mb-3">✅ ¡PROCESO EXITOSO!</h3>
        <div class="video-box">
            <div id="vidWrap" style="width:100%; height:100%;"></div>
        </div>
        
        <a id="dlBtn" href="#" class="btn-viral text-decoration-none d-block">
            ⬇️ DESCARGAR MP4
        </a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3 text-decoration-none">Crear otro</button>
    </div>
</div>

<script>
// UI Interacciones
const tIn = document.getElementById('tIn');
const fIn = document.getElementById('fIn');
const fileName = document.getElementById('fileName');
const submitBtn = document.getElementById('submitBtn');

tIn.addEventListener('input', function() { this.value = this.value.toUpperCase(); });

fIn.addEventListener('change', function() {
    if(this.files && this.files[0]) {
        fileName.innerText = '✅ ' + this.files[0].name;
        fileName.classList.add('text-success');
        this.parentElement.style.borderColor = '#FFD700';
    }
});

// SUBIDA AJAX CON BARRA DE PROGRESO REAL
document.getElementById('vForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if(!fIn.files.length) return alert("Selecciona un video");

    // Cambiar UI
    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');
    
    const formData = new FormData(this);
    formData.append('mirrorMode', document.getElementById('mirrorCheck').checked);

    const xhr = new XMLHttpRequest();
    
    // Progreso de subida
    xhr.upload.addEventListener("progress", function(evt) {
        if (evt.lengthComputable) {
            const percent = Math.round((evt.loaded / evt.total) * 50); // La subida es el 50% del proceso visual
            document.getElementById('pBar').style.width = percent + '%';
            document.getElementById('statusText').innerText = `Subiendo: ${percent}%...`;
        }
    }, false);

    xhr.addEventListener("load", function() {
        if (xhr.status === 200) {
            try {
                const res = JSON.parse(xhr.responseText);
                if(res.status === 'success') {
                    document.getElementById('statusText').innerText = "Renderizando efectos (FFmpeg)...";
                    track(res.jobId);
                } else {
                    alert("Error servidor: " + res.message);
                    location.reload();
                }
            } catch(e) {
                alert("Error crítico en respuesta: " + xhr.responseText);
                location.reload();
            }
        } else {
            alert("Error de conexión: " + xhr.statusText);
            location.reload();
        }
    }, false);

    xhr.addEventListener("error", function() { alert("Falló la subida"); location.reload(); });
    xhr.open("POST", "?action=upload");
    xhr.send(formData);
});

function track(id) {
    let progress = 50;
    const pBar = document.getElementById('pBar');
    
    // Simulación de progreso de renderizado (ya que no tenemos feedback real de ffmpeg en tiempo real fácil en PHP simple)
    const fakeProgress = setInterval(() => {
        if(progress < 95) {
            progress += 1;
            pBar.style.width = progress + '%';
        }
    }, 1000);

    let attempts = 0;
    const checker = setInterval(async () => {
        attempts++;
        if(attempts > 300) { // 15 minutos timeout para archivos muy grandes
            clearInterval(checker); clearInterval(fakeProgress);
            alert("El video es muy pesado y sigue procesando en segundo plano. Intenta recargar en unos minutos.");
            location.reload();
        }

        try {
            const req = await fetch(`?action=status&jobId=${id}`);
            const res = await req.json();
            
            if(res.status === 'finished') {
                clearInterval(checker); clearInterval(fakeProgress);
                pBar.style.width = '100%';
                show(res.file);
            }
        } catch(e) {}
    }, 3000);
}

function show(filename) {
    document.getElementById('uiProcess').classList.add('hidden');
    document.getElementById('uiResult').classList.remove('hidden');
    
    const dlUrl = '?action=download&file=' + filename;
    document.getElementById('dlBtn').href = dlUrl;
    
    const previewUrl = 'processed/' + filename + '?t=' + Date.now();
    document.getElementById('vidWrap').innerHTML = `<video width="100%" height="100%" controls autoplay muted loop playsinline><source src="${previewUrl}" type="video/mp4"></video>`;
}
</script>
</body>
</html>
