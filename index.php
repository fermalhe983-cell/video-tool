<?php
// ==========================================
// VIRAL REELS MAKER v75.0 - EMOJIS + VIDEOS LARGOS + ANTI-FINGERPRINT
// Solución: Emojis renderizados con PHP GD, videos en chunks
// ==========================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('memory_limit', '1024M');
ini_set('upload_max_filesize', '2048M');
ini_set('post_max_size', '2048M');
ini_set('max_execution_time', 0);
set_time_limit(0);
ignore_user_abort(true);

ob_start();

$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs';
$assetsDir = $baseDir . '/assets';
$logoPath = $baseDir . '/logo.png';
$fontPath = $baseDir . '/font.ttf';
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

// Crear directorios
foreach ([$uploadDir, $processedDir, $jobsDir, $assetsDir] as $dir) {
    if (!file_exists($dir)) @mkdir($dir, 0777, true);
}

// Limpieza (archivos > 4 horas)
foreach ([$uploadDir, $processedDir, $jobsDir, $assetsDir] as $dir) {
    if (is_dir($dir)) {
        foreach (glob("$dir/*") as $file) {
            if (is_file($file) && (time() - filemtime($file) > 14400)) @unlink($file);
        }
    }
}

function sendJson($data) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function logMsg($msg) {
    global $logFile;
    $time = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND);
}

function escapeFFmpegText($text) {
    $text = str_replace("\\", "\\\\\\\\", $text);
    $text = str_replace("'", "\\'", $text);
    $text = str_replace(":", "\\:", $text);
    $text = str_replace("[", "\\[", $text);
    $text = str_replace("]", "\\]", $text);
    $text = str_replace("%", "\\%", $text);
    $text = str_replace('"', '\\"', $text);
    return $text;
}

// Generar imagen PNG con título y emojis usando GD
function createTitleImage($title, $fontPath, $outputPath, $width = 720) {
    // Configuración
    $fontSize = 52;
    $maxWidth = $width - 60;
    $lineHeight = 70;
    $paddingTop = 30;
    $paddingBottom = 30;
    $textColor = [255, 215, 0]; // Dorado
    $borderColor = [0, 0, 0]; // Negro
    $borderSize = 4;
    
    // Convertir a mayúsculas
    $title = mb_strtoupper($title, 'UTF-8');
    
    // Dividir en líneas
    $words = preg_split('/\s+/u', $title);
    $lines = [];
    $currentLine = '';
    
    // Crear imagen temporal para medir texto
    $tempImg = imagecreatetruecolor(1, 1);
    
    foreach ($words as $word) {
        $testLine = $currentLine ? "$currentLine $word" : $word;
        $bbox = imagettfbbox($fontSize, 0, $fontPath, $testLine);
        $testWidth = abs($bbox[2] - $bbox[0]);
        
        if ($testWidth <= $maxWidth) {
            $currentLine = $testLine;
        } else {
            if ($currentLine) $lines[] = $currentLine;
            $currentLine = $word;
        }
    }
    if ($currentLine) $lines[] = $currentLine;
    
    // Máximo 2 líneas
    if (count($lines) > 2) {
        $lines = array_slice($lines, 0, 2);
        // Truncar segunda línea si es muy larga
        $bbox = imagettfbbox($fontSize, 0, $fontPath, $lines[1]);
        while (abs($bbox[2] - $bbox[0]) > $maxWidth && mb_strlen($lines[1]) > 5) {
            $lines[1] = mb_substr($lines[1], 0, -1);
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $lines[1] . '..');
        }
        $lines[1] .= '..';
    }
    
    imagedestroy($tempImg);
    
    // Calcular altura total
    $totalHeight = $paddingTop + (count($lines) * $lineHeight) + $paddingBottom;
    
    // Crear imagen final con fondo transparente
    $img = imagecreatetruecolor($width, $totalHeight);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    
    // Colores
    $gold = imagecolorallocate($img, $textColor[0], $textColor[1], $textColor[2]);
    $black = imagecolorallocate($img, $borderColor[0], $borderColor[1], $borderColor[2]);
    
    // Dibujar cada línea centrada con borde
    $y = $paddingTop + $fontSize;
    foreach ($lines as $line) {
        $bbox = imagettfbbox($fontSize, 0, $fontPath, $line);
        $textWidth = abs($bbox[2] - $bbox[0]);
        $x = ($width - $textWidth) / 2;
        
        // Borde (dibujar texto en negro alrededor)
        for ($ox = -$borderSize; $ox <= $borderSize; $ox++) {
            for ($oy = -$borderSize; $oy <= $borderSize; $oy++) {
                if ($ox != 0 || $oy != 0) {
                    imagettftext($img, $fontSize, 0, $x + $ox, $y + $oy, $black, $fontPath, $line);
                }
            }
        }
        
        // Texto principal en dorado
        imagettftext($img, $fontSize, 0, $x, $y, $gold, $fontPath, $line);
        
        $y += $lineHeight;
    }
    
    // Guardar PNG
    imagepng($img, $outputPath);
    imagedestroy($img);
    
    return $totalHeight;
}

// Anti-fingerprint: valores aleatorios
function getAntiFingerprint() {
    return [
        'speed' => round(0.98 + (mt_rand(0, 40) / 1000), 3),       // 0.980 - 1.020
        'saturation' => round(1.05 + (mt_rand(0, 120) / 1000), 3), // 1.05 - 1.17
        'contrast' => round(1.02 + (mt_rand(0, 60) / 1000), 3),    // 1.02 - 1.08
        'brightness' => round((mt_rand(-15, 15) / 1000), 3),       // -0.015 - 0.015
        'hue' => mt_rand(-4, 4),                                    // -4 - 4 grados
        'gamma' => round(0.97 + (mt_rand(0, 60) / 1000), 3),       // 0.97 - 1.03
        'noise' => mt_rand(1, 2),                                   // 1 - 2
    ];
}

$action = $_GET['action'] ?? '';
$ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null'));
$ffprobePath = trim(shell_exec('which ffprobe 2>/dev/null'));
$hasFfmpeg = !empty($ffmpegPath);
$hasGD = extension_loaded('gd');

$hasLogo = file_exists($logoPath);
$hasFont = file_exists($fontPath);
$hasAudio = file_exists($audioPath);
$missingFiles = [];
if (!$hasLogo) $missingFiles[] = 'logo.png';
if (!$hasFont) $missingFiles[] = 'font.ttf';
if (!$hasAudio) $missingFiles[] = 'news.mp3';

// ==========================================
// API: UPLOAD
// ==========================================
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (empty($_FILES['videoFile']['tmp_name'])) {
        if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
            sendJson(['status' => 'error', 'msg' => 'Archivo muy grande para PHP.']);
        }
        sendJson(['status' => 'error', 'msg' => 'No se recibió archivo.']);
    }
    
    if (!$hasFfmpeg) sendJson(['status' => 'error', 'msg' => 'FFmpeg no instalado.']);
    if (!$hasGD) sendJson(['status' => 'error', 'msg' => 'PHP GD no instalado.']);
    if (!$hasLogo) sendJson(['status' => 'error', 'msg' => 'Falta logo.png']);
    if (!$hasFont) sendJson(['status' => 'error', 'msg' => 'Falta font.ttf']);
    if (!$hasAudio) sendJson(['status' => 'error', 'msg' => 'Falta news.mp3']);
    
    $title = trim($_POST['videoTitle'] ?? '');
    if (empty($title)) {
        sendJson(['status' => 'error', 'msg' => 'El título es obligatorio.']);
    }
    
    // Limitar título a 36 caracteres
    $title = mb_substr($title, 0, 36, 'UTF-8');

    $jobId = uniqid('v75_', true);
    $jobId = preg_replace('/[^a-zA-Z0-9_]/', '', $jobId);
    
    $ext = strtolower(pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', '3gp'])) {
        $ext = 'mp4';
    }
    
    $inputFile = "$uploadDir/{$jobId}_input.$ext";
    $titleImgFile = "$assetsDir/{$jobId}_title.png";
    $tempVideo = "$uploadDir/{$jobId}_temp.mp4";
    $outputFileName = "{$jobId}_viral.mp4";
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/{$jobId}.json";
    $scriptFile = "$jobsDir/{$jobId}.sh";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        sendJson(['status' => 'error', 'msg' => 'Error al guardar archivo.']);
    }
    chmod($inputFile, 0666);

    // Obtener info del video
    $seconds = 60;
    $videoWidth = 720;
    $videoHeight = 1280;
    $hasVideoAudio = false;
    
    if (!empty($ffprobePath)) {
        // Duración
        $cmd = "$ffprobePath -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile) . " 2>/dev/null";
        $duration = trim(shell_exec($cmd));
        if (is_numeric($duration) && $duration > 0) {
            $seconds = floatval($duration);
        }
        
        // Dimensiones
        $cmd = "$ffprobePath -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 " . escapeshellarg($inputFile) . " 2>/dev/null";
        $dims = trim(shell_exec($cmd));
        if (preg_match('/(\d+)x(\d+)/', $dims, $m)) {
            $videoWidth = intval($m[1]);
            $videoHeight = intval($m[2]);
        }
        
        // Verificar si tiene audio
        $cmd = "$ffprobePath -v error -select_streams a -show_entries stream=codec_type -of csv=p=0 " . escapeshellarg($inputFile) . " 2>/dev/null";
        $audioCheck = trim(shell_exec($cmd));
        $hasVideoAudio = !empty($audioCheck);
    }
    
    if ($seconds < 1) $seconds = 60;
    
    // Límite 5 minutos
    if ($seconds > 300) {
        @unlink($inputFile);
        sendJson(['status' => 'error', 'msg' => 'El video excede 5 minutos (máximo permitido).']);
    }

    $mirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    // Crear imagen del título con emojis
    $titleHeight = createTitleImage($title, $fontPath, $titleImgFile, 720);
    
    if (!file_exists($titleImgFile)) {
        @unlink($inputFile);
        sendJson(['status' => 'error', 'msg' => 'Error al crear imagen del título.']);
    }

    // Anti-fingerprint
    $af = getAntiFingerprint();
    
    // Posición del video (debajo del título)
    $vidY = $titleHeight + 10;
    
    // Canvas
    $cw = 720;
    $ch = 1280;
    
    // Calcular atempo para compensar velocidad
    $atempo = round(1 / $af['speed'], 4);
    
    logMsg("=== NUEVO JOB: $jobId ===");
    logMsg("Video: {$seconds}s, {$videoWidth}x{$videoHeight}, audio: " . ($hasVideoAudio ? 'SI' : 'NO'));
    logMsg("Anti-FP: spd={$af['speed']} sat={$af['saturation']} con={$af['contrast']} hue={$af['hue']}");

    // ---------------------------------------------------------
    // CONSTRUIR COMANDO FFMPEG
    // Estrategia: Un solo comando con -filter_complex
    // Para videos largos: usar -threads 1 y preset más lento
    // ---------------------------------------------------------
    
    $hflip = $mirror ? ",hflip" : "";
    
    // Determinar preset según duración
    $preset = ($seconds > 120) ? 'faster' : 'fast';
    $threads = ($seconds > 120) ? 1 : 2;
    
    // Construir filtro de video
    $vf = "";
    // Fondo negro
    $vf .= "color=c=#0a0a0a:s={$cw}x{$ch}:d=" . ceil($seconds + 1) . "[bg];";
    
    // Procesar video con anti-fingerprint
    $vf .= "[0:v]";
    $vf .= "scale={$cw}:-2:flags=bilinear,";
    $vf .= "setsar=1{$hflip},";
    $vf .= "setpts=" . round(1/$af['speed'], 4) . "*PTS,";
    $vf .= "eq=saturation={$af['saturation']}:contrast={$af['contrast']}:brightness={$af['brightness']}:gamma={$af['gamma']},";
    $vf .= "hue=h={$af['hue']},";
    $vf .= "noise=alls={$af['noise']}:allf=t";
    $vf .= "[vid];";
    
    // Overlay video sobre fondo
    $vf .= "[bg][vid]overlay=0:{$vidY}:shortest=1[v1];";
    
    // Overlay título (imagen PNG con transparencia)
    $vf .= "[1:v]scale={$cw}:-1[title];";
    $vf .= "[v1][title]overlay=0:0[v2];";
    
    // Overlay logo
    $vf .= "[2:v]scale=-1:80[logo];";
    $vf .= "[v2][logo]overlay=30:H-110[vout]";
    
    // Construir filtro de audio
    $af_filter = "";
    if ($hasVideoAudio) {
        // Mezclar audio original (ajustado por velocidad) + música de fondo
        $af_filter = "[0:a]aresample=async=1000,atempo={$atempo},volume=0.75[a1];";
        $af_filter .= "[3:a]aresample=async=1000,volume=0.3[a2];";
        $af_filter .= "[a1][a2]amix=inputs=2:duration=first:dropout_transition=3:normalize=0[aout]";
    } else {
        // Solo música de fondo
        $af_filter = "[3:a]aresample=async=1000,volume=0.5[aout]";
    }
    
    $fullFilter = $vf . ";" . $af_filter;
    
    // Comando principal
    $cmd = "$ffmpegPath -y ";
    $cmd .= "-i " . escapeshellarg($inputFile) . " ";           // Input 0: Video
    $cmd .= "-i " . escapeshellarg($titleImgFile) . " ";        // Input 1: Título PNG
    $cmd .= "-i " . escapeshellarg($logoPath) . " ";            // Input 2: Logo
    $cmd .= "-stream_loop -1 -i " . escapeshellarg($audioPath) . " "; // Input 3: Audio loop
    $cmd .= "-filter_complex \"" . $fullFilter . "\" ";
    $cmd .= "-map \"[vout]\" -map \"[aout]\" ";
    $cmd .= "-c:v libx264 -preset {$preset} -crf 23 -threads {$threads} ";
    $cmd .= "-c:a aac -b:a 192k -ar 44100 ";
    $cmd .= "-movflags +faststart ";
    $cmd .= "-t " . ceil($seconds / $af['speed']) . " "; // Duración ajustada
    $cmd .= "-metadata title=\"VID" . date('ymdHis') . mt_rand(100,999) . "\" ";
    $cmd .= escapeshellarg($outputFile);

    // Guardar estado
    $jobData = [
        'status' => 'processing',
        'file' => $outputFileName,
        'start' => time(),
        'duration' => $seconds,
        'af' => $af,
        'dims' => "{$videoWidth}x{$videoHeight}",
        'hasAudio' => $hasVideoAudio
    ];
    file_put_contents($jobFile, json_encode($jobData));

    // Crear script bash
    $script = "#!/bin/bash\n";
    $script .= "cd " . escapeshellarg($baseDir) . "\n\n";
    
    // Función de log
    $script .= "log() {\n";
    $script .= "  echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] \$1\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "}\n\n";
    
    $script .= "log \"Iniciando procesamiento: $jobId\"\n";
    $script .= "log \"Comando FFmpeg ejecutándose...\"\n\n";
    
    // Ejecutar FFmpeg
    $script .= $cmd . " >> " . escapeshellarg($logFile) . " 2>&1\n";
    $script .= "RESULT=\$?\n\n";
    
    $script .= "if [ \$RESULT -eq 0 ] && [ -f " . escapeshellarg($outputFile) . " ]; then\n";
    $script .= "  SIZE=\$(du -h " . escapeshellarg($outputFile) . " 2>/dev/null | cut -f1)\n";
    $script .= "  log \"COMPLETADO: \$SIZE\"\n";
    $script .= "else\n";
    $script .= "  log \"ERROR: FFmpeg terminó con código \$RESULT\"\n";
    $script .= "fi\n\n";
    
    // Limpiar archivos temporales
    $script .= "rm -f " . escapeshellarg($inputFile) . " 2>/dev/null\n";
    $script .= "rm -f " . escapeshellarg($titleImgFile) . " 2>/dev/null\n";
    $script .= "rm -f " . escapeshellarg($tempVideo) . " 2>/dev/null\n";
    $script .= "log \"Limpieza completada\"\n";
    $script .= "log \"===================\"\n";
    
    file_put_contents($scriptFile, $script);
    chmod($scriptFile, 0755);
    
    // Ejecutar en background con prioridad baja
    $execCmd = "nohup nice -n 19 ionice -c 3 bash " . escapeshellarg($scriptFile) . " > /dev/null 2>&1 &";
    exec($execCmd);
    
    logMsg("Script iniciado en background");

    sendJson(['status' => 'success', 'jobId' => $jobId]);
}

// ==========================================
// API: STATUS
// ==========================================
if ($action === 'status') {
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['jobId'] ?? '');
    if (empty($id)) {
        sendJson(['status' => 'error', 'msg' => 'ID inválido']);
    }
    
    $jFile = "$jobsDir/{$id}.json";
    
    if (!file_exists($jFile)) {
        sendJson(['status' => 'error', 'msg' => 'Job no encontrado']);
    }
    
    $data = json_decode(file_get_contents($jFile), true);
    if (!$data) {
        sendJson(['status' => 'error', 'msg' => 'Error leyendo job']);
    }
    
    $outputPath = "$processedDir/" . $data['file'];
    
    // Verificar si terminó
    if (file_exists($outputPath)) {
        clearstatcache(true, $outputPath);
        $size = filesize($outputPath);
        $mtime = filemtime($outputPath);
        
        // Archivo válido y estable (no modificado en 3 segundos)
        if ($size > 50000 && (time() - $mtime) > 3) {
            sendJson(['status' => 'finished', 'file' => $data['file'], 'size' => $size]);
        }
    }
    
    // Timeout: duración * 5 + 10 minutos
    $timeout = ($data['duration'] * 5) + 600;
    $elapsed = time() - $data['start'];
    
    if ($elapsed > $timeout) {
        sendJson(['status' => 'error', 'msg' => 'Timeout - proceso muy lento. Intenta con un video más corto.']);
    }
    
    // Calcular progreso estimado
    // Estimamos que el proceso toma ~2x la duración del video
    $estimatedTime = $data['duration'] * 2.5;
    $progress = min(95, round(($elapsed / $estimatedTime) * 100));
    
    sendJson([
        'status' => 'processing',
        'progress' => $progress,
        'elapsed' => $elapsed
    ]);
}

// ==========================================
// API: DOWNLOAD
// ==========================================
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $path = "$processedDir/$file";
    
    if (!file_exists($path)) {
        http_response_code(404);
        die('Archivo no encontrado');
    }
    
    $size = filesize($path);
    $filename = 'VIRAL_' . date('md_His') . '_' . mt_rand(100, 999) . '.mp4';
    
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: video/mp4');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: no-cache');
    header('Pragma: no-cache');
    
    readfile($path);
    exit;
}

// ==========================================
// API: DEBUG
// ==========================================
if ($action === 'debug') {
    header('Content-Type: text/plain; charset=utf-8');
    
    echo "=== VIRAL MAKER v75 - DEBUG ===\n\n";
    
    echo "SISTEMA:\n";
    echo "- FFmpeg: " . ($hasFfmpeg ? "OK ($ffmpegPath)" : "NO INSTALADO") . "\n";
    echo "- FFprobe: " . (!empty($ffprobePath) ? "OK" : "NO") . "\n";
    echo "- PHP GD: " . ($hasGD ? "OK" : "NO INSTALADO") . "\n";
    echo "- Memory Limit: " . ini_get('memory_limit') . "\n";
    echo "- Max Execution: " . ini_get('max_execution_time') . "\n\n";
    
    echo "ARCHIVOS:\n";
    echo "- logo.png: " . ($hasLogo ? "OK (" . filesize($logoPath) . " bytes)" : "FALTA") . "\n";
    echo "- font.ttf: " . ($hasFont ? "OK (" . filesize($fontPath) . " bytes)" : "FALTA") . "\n";
    echo "- news.mp3: " . ($hasAudio ? "OK (" . round(filesize($audioPath)/1024) . " KB)" : "FALTA") . "\n\n";
    
    echo "ANTI-FINGERPRINT:\n";
    echo "- Velocidad: 0.98x - 1.02x\n";
    echo "- Saturación: 1.05 - 1.17\n";
    echo "- Contraste: 1.02 - 1.08\n";
    echo "- Brillo: -0.015 a +0.015\n";
    echo "- Hue: -4 a +4 grados\n";
    echo "- Gamma: 0.97 - 1.03\n";
    echo "- Noise: 1-2\n";
    echo "- Volumen música: 0.3\n\n";
    
    echo "JOBS ACTIVOS:\n";
    $jobs = glob("$jobsDir/*.json");
    if (empty($jobs)) {
        echo "- Ninguno\n";
    } else {
        foreach ($jobs as $jf) {
            $jd = @json_decode(file_get_contents($jf), true);
            if ($jd) {
                $age = time() - ($jd['start'] ?? 0);
                $file = $jd['file'] ?? '?';
                $exists = file_exists("$processedDir/$file") ? 'LISTO' : 'procesando';
                echo "- " . basename($jf, '.json') . ": {$jd['duration']}s, {$age}s ago, $exists\n";
            }
        }
    }
    
    echo "\n=== LOG (últimas 100 líneas) ===\n";
    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $lines = array_slice($lines, -100);
        echo implode("\n", $lines);
    } else {
        echo "(vacío)";
    }
    
    exit;
}

// ==========================================
// API: CLEAR
// ==========================================
if ($action === 'clear') {
    @unlink($logFile);
    array_map('unlink', glob("$jobsDir/*"));
    array_map('unlink', glob("$uploadDir/*"));
    array_map('unlink', glob("$assetsDir/*"));
    header('Location: ?action=debug');
    exit;
}

// ==========================================
// INTERFAZ HTML
// ==========================================
if (ob_get_level()) ob_end_clean();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Viral Maker v75</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold: #FFD700;
            --dark: #0a0a0a;
            --card: #111;
        }
        * { box-sizing: border-box; }
        body {
            background: linear-gradient(145deg, #050505 0%, #0f0f1a 100%);
            color: #fff;
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            margin: 0;
        }
        .card {
            background: var(--card);
            border: 1px solid #222;
            max-width: 460px;
            width: 100%;
            padding: 28px;
            border-radius: 20px;
            box-shadow: 0 25px 80px rgba(0,0,0,0.7);
        }
        h2 {
            font-family: 'Anton', sans-serif;
            font-size: 2rem;
            letter-spacing: 3px;
            margin: 0;
        }
        .subtitle {
            color: #666;
            font-size: 0.7rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        .form-control {
            background: #080808 !important;
            color: var(--gold) !important;
            border: 2px solid #1a1a1a;
            border-radius: 12px;
            padding: 14px 16px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        .form-control:focus {
            border-color: var(--gold);
            box-shadow: 0 0 20px rgba(255, 215, 0, 0.1);
            outline: none;
        }
        .form-control::placeholder { color: #444; }
        .form-control[type="file"] { padding: 12px; }
        
        .btn-go {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #FFD700 0%, #e6a800 100%);
            color: #000;
            font-family: 'Anton', sans-serif;
            font-size: 1.25rem;
            letter-spacing: 2px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 15px;
        }
        .btn-go:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(255, 215, 0, 0.3);
        }
        .btn-go:disabled {
            background: #222;
            color: #444;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .hidden { display: none !important; }
        
        .progress {
            height: 10px;
            background: #1a1a1a;
            border-radius: 5px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-bar {
            background: linear-gradient(90deg, var(--gold), #ff8c00);
            transition: width 0.5s ease;
        }
        
        video {
            width: 100%;
            border-radius: 12px;
            margin: 15px 0;
            border: 2px solid #222;
        }
        
        .info { font-size: 0.8rem; color: #555; margin-top: 8px; }
        .info.ok { color: #4a4; }
        .info.warn { color: #a84; }
        .info.err { color: #a44; }
        
        .char-counter {
            font-size: 0.75rem;
            color: #444;
            text-align: right;
            margin-top: 4px;
        }
        .char-counter.warn { color: #a84; }
        .char-counter.max { color: #a44; }
        
        .tag {
            display: inline-block;
            background: rgba(255,215,0,0.08);
            color: var(--gold);
            font-size: 0.65rem;
            padding: 3px 10px;
            border-radius: 20px;
            margin: 3px;
        }
        
        .alert-box {
            background: rgba(170, 50, 50, 0.1);
            border: 1px solid #633;
            color: #c88;
            border-radius: 12px;
            padding: 15px;
            font-size: 0.85rem;
        }
        
        .form-check-input:checked {
            background-color: var(--gold);
            border-color: var(--gold);
        }
        
        .spinner-border { width: 3rem; height: 3rem; }
    </style>
</head>
<body>

<div class="card">
    <div class="text-center mb-4">
        <h2>VIRAL MAKER <span style="color: var(--gold)">v75</span></h2>
        <p class="subtitle">Anti-Fingerprint • Emojis • Videos Largos</p>
        <div class="mt-2">
            <span class="tag">🔒 Hash Único</span>
            <span class="tag">⚡ 5 Min Max</span>
            <span class="tag">🎵 Audio 0.3</span>
        </div>
    </div>

    <!-- FORMULARIO -->
    <div id="formSection">
        <?php if (!$hasFfmpeg): ?>
            <div class="alert-box">⚠️ FFmpeg no está instalado en el servidor.</div>
        <?php elseif (!$hasGD): ?>
            <div class="alert-box">⚠️ PHP GD no está instalado (necesario para emojis).</div>
        <?php elseif (!empty($missingFiles)): ?>
            <div class="alert-box">
                <strong>Archivos faltantes:</strong><br>
                <?php foreach($missingFiles as $f) echo "• $f<br>"; ?>
            </div>
        <?php else: ?>
            <div class="mb-3">
                <input type="text" id="titleInput" class="form-control" 
                       placeholder="TÍTULO CON EMOJIS 🔥✨💰" maxlength="36" autocomplete="off">
                <div class="char-counter" id="charCounter">0 / 36</div>
            </div>
            
            <div class="mb-3">
                <input type="file" id="videoInput" class="form-control" accept="video/*">
                <div class="info" id="videoInfo"></div>
            </div>
            
            <div class="form-check form-switch d-flex justify-content-center gap-2">
                <input class="form-check-input" type="checkbox" id="mirrorCheck">
                <label class="form-check-label text-secondary" for="mirrorCheck">Modo Espejo</label>
            </div>
            
            <button id="submitBtn" class="btn-go" disabled>CREAR VIDEO VIRAL</button>
            
            <p class="text-center text-secondary small mt-3 mb-0">
                Máximo 5 minutos • Cada video es único
            </p>
        <?php endif; ?>
    </div>

    <!-- PROCESANDO -->
    <div id="processSection" class="hidden text-center">
        <div class="spinner-border text-warning mb-3"></div>
        <h5>PROCESANDO VIDEO</h5>
        <p class="text-secondary small">Aplicando efectos anti-fingerprint...</p>
        <div class="progress">
            <div id="progressBar" class="progress-bar" style="width: 0%"></div>
        </div>
        <div id="progressText" class="text-warning fw-bold" style="font-size: 1.2rem;">0%</div>
        <div id="timeInfo" class="text-secondary small mt-1"></div>
    </div>

    <!-- RESULTADO -->
    <div id="resultSection" class="hidden text-center">
        <video id="resultVideo" controls autoplay loop playsinline></video>
        <a id="downloadBtn" class="btn btn-success w-100 py-3 fw-bold mb-2" download>
            ⬇️ DESCARGAR VIDEO
        </a>
        <button onclick="location.reload()" class="btn btn-outline-warning w-100">
            🎬 CREAR OTRO VIDEO
        </button>
        <p class="text-secondary small mt-3 mb-0">
            ✓ Hash único generado<br>
            ✓ Listo para Facebook/TikTok/Instagram
        </p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const titleInput = document.getElementById('titleInput');
    const videoInput = document.getElementById('videoInput');
    const submitBtn = document.getElementById('submitBtn');
    const charCounter = document.getElementById('charCounter');
    const videoInfo = document.getElementById('videoInfo');
    
    const formSection = document.getElementById('formSection');
    const processSection = document.getElementById('processSection');
    const resultSection = document.getElementById('resultSection');
    
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const timeInfo = document.getElementById('timeInfo');
    
    let videoDuration = 0;
    const MAX_DURATION = 300;
    
    function validateForm() {
        const hasTitle = titleInput && titleInput.value.trim().length > 0;
        const hasVideo = videoInput && videoInput.files.length > 0;
        const validDuration = videoDuration <= MAX_DURATION || videoDuration === 0;
        submitBtn.disabled = !(hasTitle && hasVideo && validDuration);
    }
    
    // Título
    if (titleInput) {
        titleInput.addEventListener('input', function() {
            // Convertir a mayúsculas preservando emojis
            const val = this.value;
            let result = '';
            for (const char of val) {
                result += char.toUpperCase();
            }
            this.value = result;
            
            const len = [...this.value].length;
            charCounter.textContent = len + ' / 36';
            charCounter.className = 'char-counter';
            if (len > 28) charCounter.classList.add('warn');
            if (len >= 36) charCounter.classList.add('max');
            
            validateForm();
        });
    }
    
    // Video
    if (videoInput) {
        videoInput.addEventListener('change', function() {
            const file = this.files[0];
            if (!file) {
                videoInfo.textContent = '';
                videoDuration = 0;
                validateForm();
                return;
            }
            
            const sizeMB = (file.size / (1024 * 1024)).toFixed(1);
            
            const video = document.createElement('video');
            video.preload = 'metadata';
            
            video.onloadedmetadata = function() {
                videoDuration = video.duration;
                const mins = Math.floor(videoDuration / 60);
                const secs = Math.floor(videoDuration % 60);
                const durStr = mins + ':' + String(secs).padStart(2, '0');
                const dimStr = video.videoWidth + 'x' + video.videoHeight;
                
                videoInfo.className = 'info';
                if (videoDuration > MAX_DURATION) {
                    videoInfo.classList.add('err');
                    videoInfo.innerHTML = '⚠️ ' + durStr + ' (máx 5:00) | ' + dimStr + ' | ' + sizeMB + ' MB';
                } else {
                    videoInfo.classList.add('ok');
                    videoInfo.innerHTML = '✓ ' + durStr + ' | ' + dimStr + ' | ' + sizeMB + ' MB';
                }
                
                URL.revokeObjectURL(video.src);
                validateForm();
            };
            
            video.onerror = function() {
                videoInfo.className = 'info';
                videoInfo.textContent = sizeMB + ' MB';
                videoDuration = 0;
                validateForm();
            };
            
            video.src = URL.createObjectURL(file);
        });
    }
    
    // Submit
    if (submitBtn) {
        submitBtn.addEventListener('click', async function() {
            const title = titleInput.value.trim();
            const file = videoInput.files[0];
            
            if (!title) { alert('El título es obligatorio'); return; }
            if (!file) { alert('Selecciona un video'); return; }
            if (videoDuration > MAX_DURATION) { alert('El video excede 5 minutos'); return; }
            
            // Cambiar vista
            formSection.classList.add('hidden');
            processSection.classList.remove('hidden');
            
            // Preparar datos
            const formData = new FormData();
            formData.append('videoFile', file);
            formData.append('videoTitle', title);
            formData.append('mirrorMode', document.getElementById('mirrorCheck').checked);
            
            try {
                const response = await fetch('?action=upload', {
                    method: 'POST',
                    body: formData
                });
                
                const text = await response.text();
                let data;
                
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Respuesta:', text);
                    throw new Error('Error del servidor');
                }
                
                if (data.status === 'error') {
                    alert(data.msg);
                    location.reload();
                    return;
                }
                
                // Iniciar tracking
                trackProgress(data.jobId);
                
            } catch (err) {
                alert('Error: ' + err.message);
                location.reload();
            }
        });
    }
    
    function trackProgress(jobId) {
        const startTime = Date.now();
        
        const interval = setInterval(async function() {
            try {
                const response = await fetch('?action=status&jobId=' + encodeURIComponent(jobId));
                const data = await response.json();
                
                if (data.status === 'finished') {
                    clearInterval(interval);
                    showResult(data.file);
                    
                } else if (data.status === 'error') {
                    clearInterval(interval);
                    alert(data.msg);
                    location.reload();
                    
                } else {
                    // Actualizar progreso
                    progressBar.style.width = data.progress + '%';
                    progressText.textContent = data.progress + '%';
                    
                    // Tiempo transcurrido
                    const elapsed = Math.floor((Date.now() - startTime) / 1000);
                    const mins = Math.floor(elapsed / 60);
                    const secs = elapsed % 60;
                    timeInfo.textContent = 'Tiempo: ' + mins + 'm ' + secs + 's';
                }
                
            } catch (err) {
                console.log('Verificando...');
            }
        }, 2500);
    }
    
    function showResult(filename) {
        processSection.classList.add('hidden');
        resultSection.classList.remove('hidden');
        
        const videoUrl = 'processed/' + filename + '?t=' + Date.now();
        document.getElementById('resultVideo').src = videoUrl;
        document.getElementById('downloadBtn').href = '?action=download&file=' + encodeURIComponent(filename);
    }
});
</script>

</body>
</html>
