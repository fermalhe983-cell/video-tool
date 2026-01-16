<?php
// ==========================================
// VIRAL REELS MAKER v64.0 (LONG VIDEO FIX)
// - Solución a "Unexpected token <" (Timeouts)
// - Solución a consumo de RAM (Audio Loop optimizado)
// - Soporte real para videos largos en segundo plano
// ==========================================

// Configuración CRÍTICA para videos largos
@ini_set('display_errors', 0);
@ini_set('memory_limit', '1024M'); // Aumentamos memoria PHP
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@set_time_limit(0); // Evita que PHP muera a los 30 segundos
ignore_user_abort(true); // Permite que el script siga aunque el navegador cierre conexión

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

// Limpieza Automática
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
        header('Content-Disposition: attachment; filename="VIDEO_VIRAL_'.date('Hi').'.mp4"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// ---> BORRADO MANUAL
if ($action === 'delete_job' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    if (file_exists("$processedDir/$file")) @unlink("$processedDir/$file");
    $jobId = explode('_', $file)[0];
    foreach (glob("$uploadDir/{$jobId}_*") as $f) @unlink($f);
    foreach (glob("$jobsDir/{$jobId}*") as $f) @unlink($f);
    header('Location: ?msg=deleted');
    exit;
}

// ---> UPLOAD & PROCESS
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Limpiamos cualquier salida previa para asegurar JSON limpio
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    if (!$status['ffmpeg']) { 
        echo json_encode(['status'=>'error', 'msg'=>'Error: FFmpeg no encontrado.']); 
        exit; 
    }

    $jobId = uniqid('v64_'); // ID nueva versión
    $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        echo json_encode(['status'=>'error', 'msg'=>'Error al subir archivo.']);
        exit;
    }
    chmod($inputFile, 0777);

    // Obtener duración
    $durationCmd = escapeshellarg($ffmpegPath) . " -i " . escapeshellarg($inputFile) . " 2>&1 | grep Duration";
    $durationOutput = shell_exec($durationCmd);
    preg_match('/Duration: (\d{2}):(\d{2}):(\d{2}\.\d{2})/', $durationOutput, $matches);
    $videoDuration = 60; // Default
    if (count($matches) >= 4) {
        $videoDuration = ($matches[1] * 3600) + ($matches[2] * 60) + $matches[3];
    }

    // DATOS
    $useLogo = file_exists($logoPath);
    $useFont = file_exists($fontPath);
    $useAudio = file_exists($audioPath);
    $useMirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    // TEXTO
    $rawTitle = mb_strtoupper($_POST['videoTitle'] ?? '');
    $wrappedText = wordwrap($rawTitle, 18, "\n", true); 
    $lines = explode("\n", $wrappedText);
    if(count($lines) > 2) { 
        $lines = array_slice($lines, 0, 2); 
        $lines[1] = substr($lines[1], 0, 15) . ".."; 
    }
    
    // LAYOUT
    $canvasW = 720;
    $canvasH = 1280;
    $count = count($lines);
    if ($count == 1) { $fSize = 80; $startYs = [30]; $videoY = 135; } 
    else { $fSize = 70; $startYs = [30, 110]; $videoY = 210; }

    // --- CONSTRUCCIÓN DE COMANDO OPTIMIZADA ---
    
    $inputs = "-i " . escapeshellarg($inputFile);
    
    if ($useLogo) $inputs .= " -i " . escapeshellarg($logoPath);
    
    // CAMBIO CRÍTICO: Usamos -stream_loop en el INPUT en lugar de filtro aloop.
    // Esto repite el audio infinitamente sin consumir memoria RAM.
    if ($useAudio) {
        $inputs .= " -stream_loop -1 -i " . escapeshellarg($audioPath);
    }

    $mirrorCmd = $useMirror ? ",hflip" : "";
    
    // FILTROS
    $filter = "";
    
    // 1. LIENZO Y VIDEO
    // Nota: duration del color ayuda a FFmpeg a saber cuándo parar
    $filter .= "color=c=#080808:s={$canvasW}x{$canvasH}:d=" . ($videoDuration + 2) . "[bg];";
    $filter .= "[0:v]scale={$canvasW}:-1,setsar=1{$mirrorCmd},eq=saturation=1.15:contrast=1.05,setpts=0.98*PTS[vid];";
    $filter .= "[bg][vid]overlay=0:{$videoY}:shortest=1[base];"; // Shortest aquí corta el video visualmente
    $lastStream = "[base]";

    // 2. TEXTO
    if ($useFont && !empty($lines)) {
        $fontSafe = str_replace('\\', '/', realpath($fontPath));
        foreach ($lines as $i => $line) {
            $y = $startYs[$i];
            $lineSafe = str_replace("'", "\\'", $line);
            // Usamos text_w/2 para centrar dinámicamente
            $filter .= "{$lastStream}drawtext=fontfile='$fontSafe':text='$lineSafe':fontcolor=#FFD700:fontsize={$fSize}:borderw=4:bordercolor=black:shadowx=4:shadowy=4:x=(w-text_w)/2:y={$y}[v_text_{$i}];";
            $lastStream = "[v_text_{$i}]";
        }
    }

    // 3. LOGO
    if ($useLogo) {
        $filter .= "[1:v]scale=-1:80[logo_s];";
        $logoPosY = $canvasH - 120;
        $filter .= "{$lastStream}[logo_s]overlay=30:{$logoPosY}[vfinal]";
        $lastStream = "[vfinal]";
    } else {
        $filter .= "{$lastStream}copy[vfinal]";
    }

    // 4. AUDIO (OPTIMIZADO)
    if ($useAudio) {
        $mIdx = $useLogo ? "2" : "1";
        
        // Voz original
        $filter .= ";[0:a]atempo=1.02,volume=1.0[voice];";
        
        // BGM (Ya viene loopeado desde el input, solo ajustamos volumen)
        $filter .= "[{$mIdx}:a]volume=0.15[bgm];";
        
        // Mezcla: duration=first hace que el audio final dure lo mismo que el video original (voice)
        // Esto corta el loop infinito automáticamente.
        $filter .= "[voice][bgm]amix=inputs=2:duration=first:dropout_transition=0[afinal]";
    } else {
        $filter .= ";[0:a]atempo=1.02,volume=1.0[afinal]";
    }

    // Crear archivo de estado ANTES de ejecutar el comando pesado
    file_put_contents($jobFile, json_encode([
        'status' => 'processing', 
        'file' => $outputFileName, 
        'start' => time(),
        'duration' => $videoDuration
    ]));

    // COMANDO FINAL CON NOHUP
    // Usamos 'nohup' para que el proceso sobreviva aunque PHP termine la solicitud HTTP.
    $cmd = "nohup " . escapeshellarg($ffmpegPath) . " -y $inputs " .
           "-filter_complex \"$filter\" " .
           "-map \"[vfinal]\" -map \"[afinal]\" " .
           "-c:v libx264 -preset ultrafast -threads 4 -crf 26 " .
           "-pix_fmt yuv420p " .
           "-c:a aac -b:a 128k -ar 44100 " .
           "-movflags +faststart " .
           "-shortest " . 
           escapeshellarg($outputFile) . " > $logFile 2>&1 &";

    exec($cmd);

    // Responder inmediatamente al navegador
    echo json_encode(['status' => 'success', 'jobId' => $jobId]);
    exit;
}

// ---> VERIFICAR ESTADO
if ($action === 'status') {
    header('Content-Type: application/json');
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId']);
    $jFile = "$jobsDir/$id.json";
    
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . $data['file'];
        
        // Verificar si el archivo final existe y no está creciendo (o FFmpeg terminó)
        // Para simplificar, revisamos si existe y tiene un tamaño decente, 
        // pero la clave es que el frontend siga preguntando.
        // Una mejora: verificar si el proceso ffmpeg sigue corriendo, pero es complejo en PHP simple.
        
        // Verificamos si ya pasó el tiempo estimado + margen
        clearstatcache();
        if (file_exists($fullPath) && filesize($fullPath) > 100000) {
            // Un chequeo simple: si el archivo fue modificado hace más de 5 segundos, asumimos que terminó
            if ((time() - filemtime($fullPath)) > 3) {
                 echo json_encode(['status' => 'finished', 'file' => $data['file']]);
                 exit;
            }
        }
        
        // Calculamos progreso
        $elapsed = time() - $data['start'];
        // Si tarda más de 30 mins, error
        if ($elapsed > 1800) {
             echo json_encode(['status' => 'error', 'msg' => 'Tiempo de espera agotado']);
             exit;
        }

        $progress = min(98, ($elapsed / max(1, ($data['duration'] * 0.8))) * 100); // 0.8 factor velocidad ultrafast
        echo json_encode(['status' => 'processing', 'progress' => round($progress)]);
        
    } else { 
        echo json_encode(['status' => 'error', 'msg' => 'Job no encontrado']); 
    }
    exit;
}
?>
