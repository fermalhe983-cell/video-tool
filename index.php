<?php
// ==========================================
// VIRAL REELS MAKER v12.0 (DIRECT LINK + CLEAN DESIGN)
// ==========================================
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');

// 1. CONFIGURACIÓN
$baseDir = __DIR__; // Ruta base del servidor
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 

// Crear carpetas
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) mkdir($jobsDir, 0777, true);

// Limpieza automática (Archivos viejos > 20 mins)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    foreach (glob("$dir/*") as $file) {
        if (is_file($file) && (time() - filemtime($file) > 1200)) @unlink($file);
    }
}

$action = $_GET['action'] ?? '';

// ---> SUBIR Y PROCESAR
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Error al subir el video.']); exit;
    }

    $jobId = uniqid('v12_');
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; // Nombre limpio
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status' => 'error', 'message' => 'Error guardando archivo.']); exit;
    }
    
    // Asegurar permisos de lectura para todos (CLAVE PARA DESCARGA DIRECTA)
    chmod($inputFile, 0777);

    // Configuración FFmpeg
    $title = preg_replace('/[^a-zA-Z0-9 áéíóúÁÉÍÓÚñÑ!?]/u', '', $_POST['videoTitle'] ?? '');
    $title = mb_strtoupper(mb_substr($title, 0, 40));
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);

    // Construcción del comando
    $inputs = "-i " . escapeshellarg($inputFile);
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);

    // Filtros
    $filter = "[0:v]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,boxblur=20:10[bg];";
    $filter .= "[0:v]scale=1080:1920:force_original_aspect_ratio=decrease[fg];";
    $filter .= "[bg][fg]overlay=(W-w)/2:(H-h)/2[base];";
    $lastStream = "[base]";

    // Header Negro
    $filter .= "{$lastStream}drawbox=x=0:y=60:w=iw:h=240:color=black@0.9:t=fill[bar];";
    $lastStream = "[bar]";

    // Logo
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:160[logo_s];";
        $filter .= "{$lastStream}[logo_s]overlay=40:100[wlogo];";
        $lastStream = "[wlogo]";
    }

    // Texto
    if ($useFont && !empty($title)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        $xPos = $useLogo ? "(w-text_w)/2+80" : "(w-text_w)/2";
        $filter .= "{$lastStream}drawtext=fontfile='$fontSafe':text='$title':fontcolor=#FFD700:fontsize=85:borderw=4:bordercolor=black:x=$xPos:y=135[titled];";
        $lastStream = "[titled]";
    }

    $filter .= "{$lastStream}setpts=0.94*PTS[vfinal];[0:a]atempo=1.0638[afinal]";

    // Comando Final (Optimizada compatibilidad)
    $cmd = "ffmpeg -y $inputs -filter_complex \"$filter\" -map \"[vfinal]\" -map \"[afinal]\" -c:v libx264 -preset ultrafast -r 30 -pix_fmt yuv420p -c:a aac -ar 44100 -b:a 128k -movflags +faststart " . escapeshellarg($outputFile) . " > /dev/null 2>&1 &";

    exec($cmd);

    // Guardamos solo el nombre del archivo para crear el link directo luego
    file_put_contents($jobFile, json_encode(['status' => 'processing', 'file' => $outputFileName, 'start' => time()]));
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> CONSULTAR ESTADO
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . $data['file'];
        
        // Verificamos si existe y tiene tamaño válido (>50KB)
        if (file_exists($fullPath) && filesize($fullPath) > 51200) {
            // Dar permisos finales de lectura pública
            chmod($fullPath, 0777);
            
            // DEVOLVEMOS LA URL DIRECTA DEL SERVIDOR
            // Esto evita que PHP tenga que procesar la descarga.
            echo json_encode(['status' => 'finished', 'url' => "processed/" . $data['file']]);
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
    <title>Viral Studio Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Anton&display=swap" rel="stylesheet">
    <style>
        /* DISEÑO PROFESIONAL (Estilo Stripe/SaaS) */
        body {
            background-color: #f0f2f5; /* Gris suave profesional */
            font-family: 'Inter', sans-serif;
            color: #1c1e21;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .app-card {
            background: #ffffff;
            width: 100%;
            max-width: 550px;
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            padding: 40px;
            border: 1px solid #e1e4e8;
        }
        .brand-header {
            text-align: center; margin-bottom: 35px;
        }
        .brand-header h1 {
            font-family: 'Anton', sans-serif;
            font-size: 2.2rem;
            text-transform: uppercase;
            color: #1a1a1a;
            margin: 0;
            letter-spacing: -0.5px;
        }
        .brand-header p {
            color: #65676b; font-size: 0.95rem; margin-top: 5px; font-weight: 500;
        }

        /* Inputs */
        .form-label {
            font-weight: 700; font-size: 0.8rem; text-transform: uppercase; color: #606770; letter-spacing: 0.5px;
        }
        .form-control-lg {
            background: #f7f8fa;
            border: 2px solid #eaecf0;
            border-radius: 12px;
            font-size: 1.1rem;
            padding: 15px;
            font-weight: 600;
            color: #1c1e21;
        }
        .form-control-lg:focus {
            background: #fff; border-color: #007bff; box-shadow: 0 0 0 4px rgba(0,123,255,0.1);
        }

        /* Upload Area */
        .upload-box {
            border: 2px dashed #ccd0d5;
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #fafafa;
            margin-top: 20px;
        }
        .upload-box:hover { background: #fff; border-color: #007bff; }
        .upload-icon { font-size: 2.5rem; margin-bottom: 10px; display: block; }
        
        /* Botón Principal */
        .btn-primary-custom {
            background-color: #007bff; /* Azul profesional */
            color: white;
            border: none;
            width: 100%;
            padding: 18px;
            border-radius: 12px;
            font-size: 1.2rem;
            font-weight: 700;
            text-transform: uppercase;
            margin-top: 30px;
            cursor: pointer;
            transition: 0.2s;
            box-shadow: 0 4px 12px rgba(0,123,255,0.2);
        }
        .btn-primary-custom:hover { background-color: #0069d9; transform: translateY(-2px); }

        /* Estados */
        .hidden { display: none; }
        .loading-container { text-align: center; padding: 40px 0; }
        .spinner-border { width: 3.5rem; height: 3.5rem; color: #007bff; }
        
        /* Video Result */
        .video-wrapper {
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            width: 100%;
            aspect-ratio: 9/16;
            margin-bottom: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        video { width: 100%; height: 100%; object-fit: cover; }
    </style>
</head>
<body>

<div class="app-card">
    <div class="brand-header">
        <h1>Viral Studio</h1>
        <p>Automatización de Contenido Vertical</p>
    </div>

    <div id="uiInput">
        <form id="vForm">
            <div class="mb-3">
                <label class="form-label">Título del Video (Hook)</label>
                <input type="text" name="videoTitle" class="form-control form-control-lg" placeholder="¡ESCRIBE TU TÍTULO AQUÍ!" maxlength="40" required autocomplete="off">
            </div>

            <div class="upload-box" onclick="document.getElementById('fileIn').click()">
                <span class="upload-icon">☁️</span>
                <div class="fw-bold fs-5">Sube tu video aquí</div>
                <div class="small text-muted">Soporta MP4, MOV (Vertical)</div>
                <input type="file" name="videoFile" id="fileIn" accept="video/*" hidden required onchange="this.parentElement.style.borderColor='#007bff'; this.parentElement.querySelector('.fw-bold').innerText='✅ Video Listo'">
            </div>

            <button type="submit" class="btn-primary-custom">✨ Procesar Video</button>
        </form>
    </div>

    <div id="uiProcess" class="hidden loading-container">
        <div class="spinner-border mb-4" role="status"></div>
        <h4 class="fw-bold">Generando Video...</h4>
        <p class="text-muted">Optimizando audio, renderizando textos y formato.</p>
    </div>

    <div id="uiResult" class="hidden text-center">
        <h3 class="fw-bold text-success mb-3">¡Video Completado!</h3>
        
        <div class="video-wrapper">
            <div id="vidContainer" style="width:100%; height:100%;"></div>
        </div>

        <a id="dlBtn" href="#" class="btn-primary-custom text-decoration-none d-block" download>
            ⬇️ Descargar Video
        </a>
        <button onclick="location.reload()" class="btn btn-link text-secondary mt-3 text-decoration-none fw-bold">Crear Nuevo Video</button>
    </div>

</div>

<script>
document.getElementById('vForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    if(!document.getElementById('fileIn').files.length) return alert("Selecciona un video primero.");

    // Cambiar UI
    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData(e.target);
    try {
        const req = await fetch('?action=upload', { method: 'POST', body: fd });
        const res = await req.json();
        
        if(res.status === 'success') {
            track(res.jobId);
        } else {
            alert(res.message); location.reload();
        }
    } catch (err) {
        alert("Error de conexión."); location.reload();
    }
});

function track(id) {
    let attempts = 0;
    const interval = setInterval(async () => {
        attempts++;
        if(attempts > 120) { // 6 minutos
            clearInterval(interval); 
            alert("El proceso tardó demasiado. Intenta con un video más corto."); 
            location.reload();
        }

        try {
            const req = await fetch(`?action=status&jobId=${id}`);
            const res = await req.json();

            if(res.status === 'finished') {
                clearInterval(interval);
                showResult(res.url);
            }
        } catch(e) {}
    }, 3000);
}

function showResult(url) {
    document.getElementById('uiProcess').classList.add('hidden');
    document.getElementById('uiResult').classList.remove('hidden');
    
    // Asignar descarga directa
    document.getElementById('dlBtn').href = url;
    
    // Mostrar preview (con timestamp para evitar caché)
    const previewUrl = url + '?t=' + Date.now();
    document.getElementById('vidContainer').innerHTML = `
        <video width="100%" height="100%" controls autoplay muted playsinline>
            <source src="${previewUrl}" type="video/mp4">
            Tu navegador no soporta video.
        </video>
    `;
}
</script>

</body>
</html>
