<?php
// ==========================================
// VIRAL AGENCY MAKER v11.0 (Clean Design + Audio Fix)
// ==========================================
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');

// 1. CONFIGURACIÓN (Rutas Absolutas para evitar errores)
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$logFile = $baseDir . '/ffmpeg_log.txt';

// Crear carpetas
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) mkdir($jobsDir, 0777, true);

// Limpieza automática
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 1800)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> VER LOG (Para debug)
if ($action === 'log') {
    echo "<pre>" . (file_exists($logFile) ? file_get_contents($logFile) : "Log vacío") . "</pre>";
    exit;
}

// ---> DESCARGAR
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $filePath = "$processedDir/$file";
    if (file_exists($filePath)) {
        header('Content-Type: video/mp4');
        header("Content-Disposition: attachment; filename=\"VIRAL_REEL.mp4\"");
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
    die("Archivo no encontrado o expirado.");
}

// ---> PROCESAR VIDEO
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error al subir archivo.']); exit;
    }

    $jobId = uniqid('v11_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFile = "$processedDir/{$jobId}_out.mp4";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status' => 'error', 'message' => 'Error guardando temporal.']); exit;
    }

    // Configuración del Comando
    $title = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $title = mb_strtoupper(mb_substr($title, 0, 40));
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);

    // Inputs
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);

    // Filtros
    // 1. Escalar fondo y frente
    $filter = "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10[bg];";
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
    $lastStream = "[base]";

    // 2. Barra Negra (Header)
    $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h=240:color=black@0.9:t=fill[bar];";
    $lastStream = "[bar]";

    // 3. Logo
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:160[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=40:100[wlogo];";
        $lastStream = "[wlogo]";
    }

    // 4. Texto
    if ($useFont && !empty($title)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        $xPos = $useLogo ? "(w-text_w)/2+80" : "(w-text_w)/2";
        $filter .= "{$lastStream}drawtext=fontfile='$fontSafe':text='$title':fontcolor=#FFD700:fontsize=85:borderw=4:bordercolor=black:x=$xPos:y=135[titled];";
        $lastStream = "[titled]";
    }

    // 5. Finalizar (Aceleración)
    $filter .= "{$lastStream}setpts=0.94*PTS[vfinal];[0:a]atempo=1.0638[afinal]";

    // COMANDO FINAL (FIX: -ar 44100 para arreglar audio, -r 30 para frames estables)
    $cmd = "ffmpeg -y $inputs -filter_complex \"$filter\" -map \"[vfinal]\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -r 30 -pix_fmt yuv420p -c:a aac -ar 44100 -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " >> $logFile 2>&1 &";

    exec($cmd);

    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => basename($outputFile)]));
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> CONSULTAR ESTADO
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $outFile = "$processedDir/" . $data['file'];
        
        // Si el archivo existe y pesa más de 50KB, está listo
        if (file_exists($outFile) && filesize($outFile) > 51200) {
            echo json_encode(['status' => 'finished', 'url' => "?action=download&file=" . $data['file']]);
        } else {
            echo json_encode(['status' => 'processing']);
        }
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral Agency Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&family=Anton&display=swap" rel="stylesheet">
    <style>
        /* DISEÑO PREMIUM CLEAN (Estilo Stripe/SaaS) */
        body {
            background-color: #f4f6f8;
            font-family: 'Inter', sans-serif;
            color: #1a1a1a;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh;
        }
        .main-card {
            background: #ffffff;
            width: 100%; max-width: 520px;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.08);
            padding: 40px;
            border: 1px solid #eaeaea;
        }
        .header-brand {
            text-align: center; margin-bottom: 30px;
        }
        .header-brand h1 {
            font-family: 'Anton', sans-serif;
            font-size: 2.5rem;
            color: #000;
            margin: 0;
            letter-spacing: -1px;
            text-transform: uppercase;
        }
        .header-brand p {
            color: #666; font-size: 0.95rem; font-weight: 500;
        }
        
        /* Inputs modernos */
        .form-label {
            font-weight: 700; font-size: 0.85rem; text-transform: uppercase; color: #888; letter-spacing: 0.5px;
        }
        .input-viral {
            background: #f9f9f9;
            border: 2px solid #eee;
            border-radius: 12px;
            padding: 18px;
            font-size: 1.1rem;
            font-weight: 700;
            width: 100%;
            transition: all 0.2s;
            font-family: 'Inter', sans-serif;
        }
        .input-viral:focus {
            background: #fff;
            border-color: #000;
            outline: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .input-viral::placeholder { color: #ccc; font-weight: 400; }

        /* File Upload */
        .upload-area {
            border: 2px dashed #ddd;
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            margin-top: 20px;
            transition: 0.2s;
            background: #fafafa;
        }
        .upload-area:hover { border-color: #000; background: #fff; }
        .upload-icon { font-size: 2rem; margin-bottom: 10px; display: block; }
        
        /* Botón Viral */
        .btn-cta {
            background: #000;
            color: #fff;
            width: 100%;
            padding: 20px;
            border-radius: 14px;
            border: none;
            font-family: 'Anton', sans-serif;
            font-size: 1.4rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 30px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-cta:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            background: #222;
        }
        
        /* Status */
        .hidden { display: none; }
        .status-box { text-align: center; padding: 40px 0; }
        .spinner-border { width: 3rem; height: 3rem; color: #000; }
        .alert-custom { background: #fff4e5; color: #663c00; padding: 10px; border-radius: 8px; font-size: 0.85rem; text-align: center; margin-bottom: 20px; font-weight: 600; }
    </style>
</head>
<body>

<div class="main-card">
    <div class="header-brand">
        <h1>Viral Agency</h1>
        <p>Generador de Contenido Automatizado</p>
    </div>

    <?php if(!file_exists($fontPath)) echo '<div class="alert-custom">⚠️ Advertencia: No se detectó font.ttf. El título no se mostrará.</div>'; ?>
    <?php if(!file_exists($logoPath)) echo '<div class="alert-custom">⚠️ Advertencia: No se detectó logo.png. El logo no se mostrará.</div>'; ?>

    <div id="uiInput">
        <form id="viralForm">
            <div class="mb-3">
                <label class="form-label">Título Gancho (Hook)</label>
                <input type="text" name="videoTitle" class="input-viral" placeholder="¡ESTO CAMBIARÁ TU NEGOCIO!" maxlength="40" required autocomplete="off">
            </div>

            <div class="upload-area" onclick="document.getElementById('vFile').click()">
                <span class="upload-icon">📂</span>
                <div class="fw-bold">Haz clic para subir video</div>
                <div class="small text-muted">Recomendado: Formato Vertical (MP4)</div>
                <input type="file" name="videoFile" id="vFile" accept="video/*" hidden required onchange="this.parentElement.style.borderColor='#000'; this.parentElement.querySelector('.fw-bold').innerText='✅ Video Seleccionado'">
            </div>

            <button type="submit" class="btn-cta">🚀 Generar Viral</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden status-box">
        <div class="spinner-border mb-4" role="status"></div>
        <h3 class="fw-bold">Renderizando...</h3>
        <p class="text-muted small">Corrigiendo audio y aplicando diseño.</p>
    </div>

    <div id="uiResult" class="hidden status-box">
        <div style="font-size: 3rem; margin-bottom: 20px;">🎉</div>
        <h3 class="fw-bold mb-3">¡Video Completado!</h3>
        <a id="dlLink" href="#" class="btn-cta text-decoration-none d-block">⬇️ Descargar Video</a>
        <button onclick="location.reload()" class="btn btn-link text-muted mt-3 text-decoration-none">Crear otro video</button>
    </div>
</div>

<div style="position:fixed; bottom:10px; right:10px;">
    <a href="?action=log" target="_blank" style="color:#ccc; font-size:11px; text-decoration:none;">System Logs</a>
</div>

<script>
document.getElementById('viralForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if(!document.getElementById('vFile').files.length) return alert("Por favor selecciona un video.");
    
    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData(e.target);
    try {
        const req = await fetch('?action=upload', { method: 'POST', body: fd });
        const res = await req.json();
        
        if(res.status === 'success') {
            trackJob(res.jobId);
        } else {
            alert(res.message); location.reload();
        }
    } catch (err) {
        alert("Error de conexión con el servidor."); location.reload();
    }
});

function trackJob(id) {
    let attempts = 0;
    const interval = setInterval(async () => {
        attempts++;
        if(attempts > 90) { // 4.5 minutos timeout
            clearInterval(interval); 
            if(confirm("El proceso está tardando. ¿Ver errores?")) window.open('?action=log');
            location.reload();
        }

        try {
            const req = await fetch(`?action=status&jobId=${id}`);
            const res = await req.json();

            if(res.status === 'finished') {
                clearInterval(interval);
                document.getElementById('uiProcess').classList.add('hidden');
                document.getElementById('uiResult').classList.remove('hidden');
                document.getElementById('dlLink').href = res.url;
            }
        } catch(e) {}
    }, 3000);
}
</script>

</body>
</html>
