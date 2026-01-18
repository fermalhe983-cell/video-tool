<?php
// ==========================================
// VIRAL REELS MAKER v73.0 - ANTI-FINGERPRINT + EMOJIS + UNIVERSAL
// Cambios sutiles para evitar detección de contenido duplicado
// ==========================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('memory_limit', '512M');
ini_set('upload_max_filesize', '2048M');
ini_set('post_max_size', '2048M');
set_time_limit(0);
ignore_user_abort(true);

ob_start();

$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$emojiFontPath = $baseDir . '/NotoColorEmoji.ttf'; // Fuente separada para emojis
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

if (!file_exists($uploadDir)) @mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) @mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) @mkdir($jobsDir, 0777, true);

// Limpieza (archivos > 3 horas)
foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
    if(is_dir($dir)){
        foreach (glob("$dir/*") as $file) {
            if (is_file($file) && (time() - filemtime($file) > 10800)) @unlink($file);
        }
    }
}

function sendJson($data) {
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Escapar texto para FFmpeg
function escapeFFmpegText($text) {
    $text = str_replace("\\", "\\\\", $text);
    $text = str_replace("'", "'\\''", $text);
    $text = str_replace(":", "\\:", $text);
    $text = str_replace("[", "\\[", $text);
    $text = str_replace("]", "\\]", $text);
    $text = str_replace("%", "\\%", $text);
    $text = str_replace("\"", "\\\"", $text);
    return $text;
}

// Separar emojis del texto
function separateEmojis($text) {
    // Patrón para detectar emojis
    $emojiPattern = '/[\x{1F600}-\x{1F64F}]|[\x{1F300}-\x{1F5FF}]|[\x{1F680}-\x{1F6FF}]|[\x{1F1E0}-\x{1F1FF}]|[\x{2600}-\x{26FF}]|[\x{2700}-\x{27BF}]|[\x{FE00}-\x{FE0F}]|[\x{1F900}-\x{1F9FF}]|[\x{1FA00}-\x{1FA6F}]|[\x{1FA70}-\x{1FAFF}]|[\x{231A}-\x{231B}]|[\x{23E9}-\x{23F3}]|[\x{23F8}-\x{23FA}]|[\x{25AA}-\x{25AB}]|[\x{25B6}]|[\x{25C0}]|[\x{25FB}-\x{25FE}]|[\x{2614}-\x{2615}]|[\x{2648}-\x{2653}]|[\x{267F}]|[\x{2693}]|[\x{26A1}]|[\x{26AA}-\x{26AB}]|[\x{26BD}-\x{26BE}]|[\x{26C4}-\x{26C5}]|[\x{26CE}]|[\x{26D4}]|[\x{26EA}]|[\x{26F2}-\x{26F3}]|[\x{26F5}]|[\x{26FA}]|[\x{26FD}]|[\x{2702}]|[\x{2705}]|[\x{2708}-\x{270D}]|[\x{270F}]|[\x{2712}]|[\x{2714}]|[\x{2716}]|[\x{271D}]|[\x{2721}]|[\x{2728}]|[\x{2733}-\x{2734}]|[\x{2744}]|[\x{2747}]|[\x{274C}]|[\x{274E}]|[\x{2753}-\x{2755}]|[\x{2757}]|[\x{2763}-\x{2764}]|[\x{2795}-\x{2797}]|[\x{27A1}]|[\x{27B0}]|[\x{27BF}]|[\x{2934}-\x{2935}]|[\x{2B05}-\x{2B07}]|[\x{2B1B}-\x{2B1C}]|[\x{2B50}]|[\x{2B55}]|[\x{3030}]|[\x{303D}]|[\x{3297}]|[\x{3299}]|[\x{200D}]|[\x{20E3}]|[\x{FE0F}]/u';
    
    // Extraer emojis
    preg_match_all($emojiPattern, $text, $emojis);
    $emojiList = $emojis[0];
    
    // Texto sin emojis
    $textOnly = preg_replace($emojiPattern, '', $text);
    $textOnly = preg_replace('/\s+/', ' ', trim($textOnly));
    
    return [
        'text' => $textOnly,
        'emojis' => $emojiList,
        'emojiString' => implode('', $emojiList)
    ];
}

// Generar valores aleatorios sutiles para anti-fingerprint
function getAntiFingerprint() {
    return [
        // Velocidad: entre 0.97 y 1.03 (casi imperceptible)
        'speed' => round(0.97 + (mt_rand(0, 60) / 1000), 3),
        // Pitch del audio: compensar velocidad
        'atempo' => round(1 / (0.97 + (mt_rand(0, 60) / 1000)), 4),
        // Saturación: entre 1.05 y 1.20
        'saturation' => round(1.05 + (mt_rand(0, 150) / 1000), 3),
        // Contraste: entre 1.02 y 1.10
        'contrast' => round(1.02 + (mt_rand(0, 80) / 1000), 3),
        // Brillo: entre -0.02 y 0.02
        'brightness' => round((mt_rand(-20, 20) / 1000), 3),
        // Hue: entre -5 y 5 grados
        'hue' => mt_rand(-5, 5),
        // Zoom muy sutil: entre 1.00 y 1.03
        'zoom' => round(1.00 + (mt_rand(0, 30) / 1000), 3),
        // Noise muy sutil
        'noise' => mt_rand(1, 3),
        // Rotación mínima: entre -0.5 y 0.5 grados
        'rotation' => round((mt_rand(-5, 5) / 10), 2),
        // Gamma: entre 0.95 y 1.05
        'gamma' => round(0.95 + (mt_rand(0, 100) / 1000), 3),
    ];
}

$action = $_GET['action'] ?? '';
$ffmpegPath = trim(shell_exec('which ffmpeg'));
$hasFfmpeg = !empty($ffmpegPath);

$hasLogo = file_exists($logoPath);
$hasFont = file_exists($fontPath);
$hasEmojiFont = file_exists($emojiFontPath);
$hasAudio = file_exists($audioPath);
$missingFiles = [];
if (!$hasLogo) $missingFiles[] = 'logo.png';
if (!$hasFont) $missingFiles[] = 'font.ttf';
if (!$hasAudio) $missingFiles[] = 'news.mp3';

// ==========================================
// API: UPLOAD
// ==========================================
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        sendJson(['status'=>'error', 'msg'=>'Archivo excede límite PHP.']);
    }

    if (!$hasFfmpeg) sendJson(['status'=>'error', 'msg'=>'FFmpeg no instalado.']);
    if (!$hasLogo) sendJson(['status'=>'error', 'msg'=>'Falta logo.png']);
    if (!$hasFont) sendJson(['status'=>'error', 'msg'=>'Falta font.ttf']);
    if (!$hasAudio) sendJson(['status'=>'error', 'msg'=>'Falta news.mp3']);
    
    $title = trim($_POST['videoTitle'] ?? '');
    if (empty($title)) {
        sendJson(['status'=>'error', 'msg'=>'El título es obligatorio.']);
    }

    $jobId = uniqid('v73_');
    $ext = strtolower(pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION));
    $inputFile = "$uploadDir/{$jobId}_in.$ext";
    $tempVideo = "$uploadDir/{$jobId}_temp.mp4";
    $outputFileName = "{$jobId}_viral.mp4"; 
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/$jobId.json";
    $scriptFile = "$jobsDir/{$jobId}.sh";

    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        sendJson(['status'=>'error', 'msg'=>'Error guardando archivo.']);
    }
    chmod($inputFile, 0777);

    // Obtener info del video
    $ffprobePath = trim(shell_exec('which ffprobe'));
    $seconds = 60;
    $videoWidth = 0;
    $videoHeight = 0;
    
    if (!empty($ffprobePath)) {
        // Duración
        $durCmd = "$ffprobePath -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile);
        $seconds = floatval(trim(shell_exec($durCmd)));
        
        // Dimensiones
        $dimCmd = "$ffprobePath -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 " . escapeshellarg($inputFile);
        $dimensions = trim(shell_exec($dimCmd));
        if (preg_match('/(\d+)x(\d+)/', $dimensions, $dm)) {
            $videoWidth = intval($dm[1]);
            $videoHeight = intval($dm[2]);
        }
    }
    
    if ($seconds < 1) $seconds = 60;
    if ($seconds > 300) {
        @unlink($inputFile);
        sendJson(['status'=>'error', 'msg'=>'Video excede 5 minutos (máx 5:00)']);
    }

    $mirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    
    // Procesar título
    $title = mb_strtoupper($title, 'UTF-8');
    $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title);
    $title = preg_replace('/\s+/', ' ', trim($title));
    
    // Separar texto y emojis
    $parsed = separateEmojis($title);
    $textOnly = $parsed['text'];
    $emojiString = $parsed['emojiString'];
    $hasEmojis = !empty($parsed['emojis']);

    // Anti-fingerprint values
    $af = getAntiFingerprint();

    // ---------------------------------------------------------
    // CALCULAR LAYOUT RESPONSIVE
    // ---------------------------------------------------------
    // Canvas de salida: 720x1280 (9:16 vertical)
    $cw = 720; 
    $ch = 1280;
    
    // Determinar orientación del video original
    $isVertical = ($videoHeight > $videoWidth);
    $isSquare = (abs($videoWidth - $videoHeight) < 50);
    $isHorizontal = ($videoWidth > $videoHeight);
    
    // Wordwrap del texto
    $maxChars = 18;
    $titleLen = mb_strlen($textOnly, 'UTF-8');
    $lines = [];
    
    if ($titleLen <= $maxChars) {
        $lines[] = $textOnly;
    } else {
        $words = preg_split('/\s+/u', $textOnly);
        $currentLine = '';
        foreach ($words as $word) {
            $testLine = $currentLine ? "$currentLine $word" : $word;
            if (mb_strlen($testLine, 'UTF-8') <= $maxChars) {
                $currentLine = $testLine;
            } else {
                if ($currentLine) $lines[] = $currentLine;
                $currentLine = $word;
            }
        }
        if ($currentLine) $lines[] = $currentLine;
    }
    
    if (count($lines) > 2) {
        $lines = array_slice($lines, 0, 2);
        $lines[1] = mb_substr($lines[1], 0, 14, 'UTF-8') . '..';
    }
    
    // Añadir emojis a la primera línea si hay espacio, o como línea separada visual
    $emojiLine = $emojiString;
    
    $numLines = count($lines);
    $txtSize = ($numLines == 1) ? 68 : 60;
    $emojiSize = 56;
    
    // Posiciones Y del texto
    $txtY = ($numLines == 1) ? [45] : [35, 105];
    $emojiY = ($numLines == 1) ? 120 : 175;
    
    // Posición Y del video (debajo del texto y emojis)
    $vidY = $hasEmojis ? ($emojiY + 70) : (($numLines == 1) ? 130 : 185);

    // ---------------------------------------------------------
    // CONSTRUIR FILTROS DE VIDEO (ANTI-FINGERPRINT)
    // ---------------------------------------------------------
    $fFile = str_replace('\\', '/', realpath($fontPath));
    $emojiFont = $hasEmojiFont ? str_replace('\\', '/', realpath($emojiFontPath)) : $fFile;
    
    $hflip = $mirror ? ",hflip" : "";
    
    // Calcular escala del video para que quepa en el canvas
    // El video se escala para ocupar el ancho completo (720px)
    // y se posiciona verticalmente según el espacio disponible
    
    $fc = "";
    
    // 1. FONDO con gradiente sutil (más interesante que color sólido)
    $fc .= "color=c=#0a0a0a:s={$cw}x{$ch}[bg0];";
    $fc .= "[bg0]drawbox=x=0:y=0:w={$cw}:h=200:color=#111111@0.5:t=fill[bg];";
    
    // 2. VIDEO: Escalar, aplicar efectos anti-fingerprint
    // setpts cambia velocidad, eq cambia colores, hue rota colores
    $fc .= "[0:v]scale={$cw}:-1:flags=lanczos,setsar=1{$hflip},";
    // Velocidad sutil
    $fc .= "setpts=" . (1/$af['speed']) . "*PTS,";
    // Efectos de color (anti-fingerprint)
    $fc .= "eq=saturation={$af['saturation']}:contrast={$af['contrast']}:brightness={$af['brightness']}:gamma={$af['gamma']},";
    // Hue shift sutil
    $fc .= "hue=h={$af['hue']},";
    // Noise muy sutil (cambia hash)
    $fc .= "noise=alls={$af['noise']}:allf=t,";
    // Zoom muy sutil con crop y scale
    $fc .= "scale=iw*{$af['zoom']}:ih*{$af['zoom']},crop={$cw}:ih";
    $fc .= "[vid];";
    
    // 3. OVERLAY video sobre fondo
    $fc .= "[bg][vid]overlay=0:{$vidY}:shortest=1[base];";
    
    // 4. TEXTO (sin emojis)
    $last = "[base]";
    if (!empty($textOnly)) {
        foreach ($lines as $k => $l) {
            $escapedLine = escapeFFmpegText($l);
            $y = $txtY[$k];
            $fc .= "{$last}drawtext=fontfile='{$fFile}':text='{$escapedLine}':fontcolor=#FFD700:fontsize={$txtSize}:";
            $fc .= "borderw=4:bordercolor=black:shadowcolor=black@0.6:shadowx=3:shadowy=3:";
            $fc .= "x=(w-text_w)/2:y={$y}[t{$k}];";
            $last = "[t{$k}]";
        }
    }
    
    // 5. EMOJIS (con fuente especial si existe)
    if ($hasEmojis) {
        $escapedEmoji = escapeFFmpegText($emojiString);
        $fc .= "{$last}drawtext=fontfile='{$emojiFont}':text='{$escapedEmoji}':fontsize={$emojiSize}:";
        $fc .= "x=(w-text_w)/2:y={$emojiY}[temoji];";
        $last = "[temoji]";
    }
    
    // 6. LOGO
    $fc .= "[1:v]scale=-1:80[lg];{$last}[lg]overlay=30:H-120[vfin]";

    // Inputs
    $inputs = "-i " . escapeshellarg($inputFile) . " -i " . escapeshellarg($logoPath);

    // Comando 1: Video (sin audio)
    $cmdStep1 = "$ffmpegPath -y $inputs -filter_complex \"{$fc}\" -map \"[vfin]\" -an ";
    $cmdStep1 .= "-c:v libx264 -preset medium -threads 2 -crf 23 -movflags +faststart ";
    $cmdStep1 .= "-metadata title=\"Generated " . date('Y-m-d H:i:s') . " - ID:$jobId\" "; // Metadata única
    $cmdStep1 .= escapeshellarg($tempVideo);

    // ---------------------------------------------------------
    // PASO 2: AUDIO (con tempo ajustado para compensar velocidad)
    // ---------------------------------------------------------
    $cmdStep2 = "$ffmpegPath -y -i " . escapeshellarg($tempVideo) . " -i " . escapeshellarg($inputFile) . " -stream_loop -1 -i " . escapeshellarg($audioPath);
    
    // Audio original ajustado a la nueva velocidad + música de fondo
    // atempo compensa el cambio de velocidad del video
    $atempo = round(1 / $af['speed'], 4);
    $filterAudio = "[1:a]aresample=async=1,atempo={$atempo},volume=0.75[vorig];";
    $filterAudio .= "[2:a]aresample=async=1,volume=0.4[vmusic];"; // VOLUMEN 0.4 como pediste
    $filterAudio .= "[vorig][vmusic]amix=inputs=2:duration=first:dropout_transition=2:normalize=0[afin]";
    
    $cmdStep2 .= " -filter_complex \"$filterAudio\" -map 0:v -map \"[afin]\" -c:v copy -c:a aac -b:a 192k ";
    $cmdStep2 .= "-metadata comment=\"Unique ID: $jobId\" "; // Más metadata única
    $cmdStep2 .= escapeshellarg($outputFile);

    // Guardar Estado
    file_put_contents($jobFile, json_encode([
        'status' => 'processing',
        'file' => $outputFileName,
        'start' => time(),
        'duration' => $seconds,
        'antifingerprint' => $af,
        'dimensions' => "{$videoWidth}x{$videoHeight}"
    ], JSON_UNESCAPED_UNICODE));

    // SCRIPT BASH
    $scriptContent = "#!/bin/bash\n";
    $scriptContent .= "export LANG=en_US.UTF-8\n";
    $scriptContent .= "export LC_ALL=en_US.UTF-8\n";
    $scriptContent .= "cd " . escapeshellarg($baseDir) . "\n";
    $scriptContent .= "echo '========================================' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "echo 'JOB: $jobId' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "echo 'INICIO: '\$(date) >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "echo 'Duracion: {$seconds}s | Dimensiones: {$videoWidth}x{$videoHeight}' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "echo 'Anti-FP: speed={$af['speed']} sat={$af['saturation']} cont={$af['contrast']} hue={$af['hue']}' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "echo '----------------------------------------' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "echo 'PASO 1: Video + Texto + Logo + Efectos...' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= $cmdStep1 . " >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "STEP1=\$?\n";
    $scriptContent .= "if [ \$STEP1 -eq 0 ]; then\n";
    $scriptContent .= "  echo 'PASO 2: Audio (vol 0.4) + Tempo sync...' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "  " . $cmdStep2 . " >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "  STEP2=\$?\n";
    $scriptContent .= "  if [ \$STEP2 -eq 0 ]; then\n";
    $scriptContent .= "    SIZE=\$(du -h " . escapeshellarg($outputFile) . " | cut -f1)\n";
    $scriptContent .= "    echo 'EXITO: '\$SIZE >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "  else\n";
    $scriptContent .= "    echo 'ERROR PASO 2 (code: '\$STEP2')' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "  fi\n";
    $scriptContent .= "else\n";
    $scriptContent .= "  echo 'ERROR PASO 1 (code: '\$STEP1')' >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "fi\n";
    $scriptContent .= "rm -f " . escapeshellarg($tempVideo) . "\n";
    $scriptContent .= "echo 'FIN: '\$(date) >> " . escapeshellarg($logFile) . " 2>&1\n";
    $scriptContent .= "echo '========================================' >> " . escapeshellarg($logFile) . " 2>&1\n";

    file_put_contents($scriptFile, $scriptContent);
    chmod($scriptFile, 0755);

    exec("nohup nice -n 19 bash " . escapeshellarg($scriptFile) . " > /dev/null 2>&1 &");

    sendJson(['status' => 'success', 'jobId' => $jobId]);
}

// ==========================================
// API: STATUS
// ==========================================
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId'] ?? '');
    $jFile = "$jobsDir/$id.json";

    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . ($data['file'] ?? '');

        if (!empty($data['file']) && file_exists($fullPath) && filesize($fullPath) > 10000) {
            clearstatcache(true, $fullPath);
            if ((time() - filemtime($fullPath)) > 5) {
                sendJson(['status' => 'finished', 'file' => $data['file']]);
            }
        }
        
        $timeout = max(600, ($data['duration'] * 4) + 300);
        if ((time() - $data['start']) > $timeout) { 
            sendJson(['status' => 'error', 'msg' => 'Timeout. Revisa ?action=debug']);
        }

        $elapsed = time() - $data['start'];
        $estimatedTotal = $data['duration'] * 2.5;
        $progress = min(99, ($elapsed / max(1, $estimatedTotal)) * 100); 
        sendJson(['status' => 'processing', 'progress' => round($progress)]);
    }
    sendJson(['status' => 'error', 'msg' => 'Iniciando...']);
}

// ==========================================
// UTILS
// ==========================================
if ($action === 'download' && isset($_GET['file'])) {
    $f = basename($_GET['file']);
    $p = "$processedDir/$f";
    if (file_exists($p)) {
        if (ob_get_length()) ob_clean();
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="VIRAL_'.date('md_Hi').'_'.substr(uniqid(),5).'.mp4"');
        header('Content-Length: '.filesize($p));
        readfile($p);
        exit;
    }
}

if ($action === 'delete_job' && isset($_GET['file'])) {
    $f = basename($_GET['file']);
    @unlink("$processedDir/$f");
    $jid = explode('_', $f)[0] . '_' . explode('_', $f)[1];
    foreach(glob("$uploadDir/{$jid}*") as $tmp) @unlink($tmp);
    foreach(glob("$jobsDir/{$jid}*") as $tmp) @unlink($tmp);
    header('Location: ?deleted');
    exit;
}

if ($action === 'debug') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== VIRAL MAKER v73 - ANTI-FINGERPRINT ===\n\n";
    echo "FFmpeg: " . ($hasFfmpeg ? "OK" : "NO") . "\n";
    echo "Logo: " . ($hasLogo ? "OK" : "FALTA") . "\n";
    echo "Font: " . ($hasFont ? "OK" : "FALTA") . "\n";
    echo "Emoji Font: " . ($hasEmojiFont ? "OK (NotoColorEmoji.ttf)" : "NO (usará font.ttf)") . "\n";
    echo "Audio: " . ($hasAudio ? "OK" : "FALTA") . "\n\n";
    
    echo "=== ANTI-FINGERPRINT FEATURES ===\n";
    echo "- Velocidad aleatoria (0.97-1.03x)\n";
    echo "- Saturación variable\n";
    echo "- Contraste variable\n";
    echo "- Brillo variable\n";
    echo "- Hue shift aleatorio\n";
    echo "- Noise sutil\n";
    echo "- Zoom sutil\n";
    echo "- Gamma variable\n";
    echo "- Metadata única por video\n\n";
    
    echo "=== JOBS ===\n";
    foreach (glob("$jobsDir/*.json") as $jf) {
        $jd = json_decode(file_get_contents($jf), true);
        echo basename($jf) . " | " . ($jd['status']??'?') . " | " . ($jd['duration']??0) . "s | " . ($jd['dimensions']??'?') . "\n";
    }
    
    echo "\n=== LOG (últimas 80 líneas) ===\n";
    if (file_exists($logFile)) {
        $lines = file($logFile);
        echo implode('', array_slice($lines, -80));
    }
    exit;
}

if ($action === 'clear_log') {
    @unlink($logFile);
    header('Location: ?action=debug');
    exit;
}

if (ob_get_length()) ob_end_clean();
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral Maker v73 - Anti-Fingerprint</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 100%); color: #fff; font-family: 'Segoe UI', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: rgba(18, 18, 18, 0.95); border: 1px solid #333; max-width: 520px; width: 100%; padding: 35px; border-radius: 24px; box-shadow: 0 20px 60px rgba(0,0,0,0.5), 0 0 100px rgba(255, 215, 0, 0.03); }
        h2 { font-family: 'Anton', sans-serif; letter-spacing: 2px; font-size: 2rem; }
        .subtitle { color: #888; font-size: 0.75rem; letter-spacing: 1px; }
        .form-control { background: #0a0a0a !important; color: #FFD700 !important; border: 2px solid #222; text-align: center; border-radius: 12px; padding: 16px; font-size: 1rem; transition: all 0.3s; }
        .form-control:focus { border-color: #FFD700; box-shadow: 0 0 20px rgba(255, 215, 0, 0.15); }
        .form-control::placeholder { color: #555; }
        .btn-go { width: 100%; padding: 18px; background: linear-gradient(135deg, #FFD700 0%, #ff8c00 100%); color: #000; font-family: 'Anton'; font-size: 1.4rem; letter-spacing: 1px; border: none; border-radius: 12px; margin-top: 20px; transition: all 0.3s; cursor: pointer; }
        .btn-go:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(255, 215, 0, 0.3); }
        .btn-go:disabled { background: #333; color: #666; cursor: not-allowed; transform: none; }
        .hidden { display: none; }
        video { width: 100%; border-radius: 12px; margin-top: 20px; border: 2px solid #333; }
        .progress { height: 10px; background: #1a1a1a; margin-top: 25px; border-radius: 5px; overflow: hidden; }
        .progress-bar { background: linear-gradient(90deg, #FFD700, #ff8c00, #FFD700); background-size: 200% 100%; animation: shimmer 2s infinite; }
        @keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
        .file-info { font-size: 0.8rem; color: #666; margin-top: 8px; line-height: 1.6; }
        .alert-missing { background: rgba(255, 68, 68, 0.1); border: 1px solid #ff4444; color: #ff8888; font-size: 0.85rem; border-radius: 12px; padding: 15px; }
        .char-count { font-size: 0.7rem; color: #555; }
        .char-count.warning { color: #ff8800; }
        .char-count.danger { color: #ff4444; }
        .duration-warning { color: #ff8800; }
        .duration-ok { color: #00ff88; }
        .badge-feature { display: inline-block; background: rgba(255, 215, 0, 0.1); color: #FFD700; font-size: 0.65rem; padding: 3px 8px; border-radius: 20px; margin: 2px; }
        .features-row { margin-top: 10px; text-align: center; }
        .form-check-input:checked { background-color: #FFD700; border-color: #FFD700; }
        .emoji-picker { font-size: 1.2rem; cursor: pointer; user-select: none; }
        .emoji-picker span { margin: 0 3px; transition: transform 0.2s; display: inline-block; }
        .emoji-picker span:hover { transform: scale(1.3); }
    </style>
</head>
<body>

<div class="card">
    <div class="text-center mb-4">
        <h2>VIRAL MAKER <span class="text-warning">v73</span></h2>
        <p class="subtitle">ANTI-FINGERPRINT • ANTI-DETECCIÓN • MONETIZACIÓN SAFE</p>
        <div class="features-row">
            <span class="badge-feature">🎬 Hash Único</span>
            <span class="badge-feature">⚡ Velocidad Variable</span>
            <span class="badge-feature">🎨 Colores Aleatorios</span>
            <span class="badge-feature">🔊 Audio 0.4</span>
        </div>
    </div>

    <div id="uiInput">
        <?php if(!$hasFfmpeg): ?>
            <div class="alert alert-danger">⚠️ FFMPEG NO INSTALADO</div>
        <?php elseif(!empty($missingFiles)): ?>
            <div class="alert-missing">
                <strong>⚠️ Archivos faltantes:</strong><br>
                <?php foreach($missingFiles as $mf): ?>
                    • <?= htmlspecialchars($mf) ?><br>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="mb-3">
                <input type="text" id="tIn" class="form-control" placeholder="TÍTULO OBLIGATORIO" maxlength="45">
                <div class="d-flex justify-content-between mt-1 px-1">
                    <div class="emoji-picker">
                        <span onclick="addEmoji('🔥')">🔥</span>
                        <span onclick="addEmoji('✨')">✨</span>
                        <span onclick="addEmoji('💰')">💰</span>
                        <span onclick="addEmoji('🚀')">🚀</span>
                        <span onclick="addEmoji('💎')">💎</span>
                        <span onclick="addEmoji('⚡')">⚡</span>
                        <span onclick="addEmoji('🎯')">🎯</span>
                        <span onclick="addEmoji('👑')">👑</span>
                    </div>
                    <span id="charCount" class="char-count">0/45</span>
                </div>
            </div>
            
            <input type="file" id="fIn" class="form-control mb-2" accept="video/*">
            <div id="fileInfo" class="file-info text-center"></div>
            
            <div class="form-check form-switch d-flex justify-content-center gap-2 mt-3">
                <input class="form-check-input" type="checkbox" id="mirrorCheck">
                <label class="form-check-label text-secondary" for="mirrorCheck">Modo Espejo (flip horizontal)</label>
            </div>

            <button id="btnGo" class="btn-go" onclick="process()" disabled>
                🎬 CREAR VIDEO VIRAL
            </button>
            
            <p class="text-center text-secondary small mt-3 mb-0">
                Máximo 5 minutos • Título en MAYÚSCULAS<br>
                <span class="text-warning">Cada video genera un hash único</span>
            </p>
            
            <?php if(!$hasEmojiFont): ?>
            <p class="text-center small mt-2 mb-0" style="color:#665500">
                💡 Tip: Sube NotoColorEmoji.ttf para emojis en color
            </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div id="uiProcess" class="hidden text-center">
        <div class="spinner-border text-warning mb-3" style="width: 3rem; height: 3rem;"></div>
        <h4>PROCESANDO VIDEO</h4>
        <p class="text-secondary small">
            Aplicando efectos anti-fingerprint...<br>
            <span class="text-warning">Cada video es único</span>
        </p>
        <div class="progress">
            <div id="progressBar" class="progress-bar" style="width: 0%"></div>
        </div>
        <div id="progressText" class="mt-2" style="color: #FFD700; font-size: 1.2rem; font-weight: bold;">0%</div>
        <div id="timeEstimate" class="small text-muted mt-1"></div>
    </div>

    <div id="uiResult" class="hidden text-center">
        <div id="videoContainer"></div>
        <a id="dlLink" class="btn btn-success w-100 mt-3 py-3 fw-bold" style="font-size: 1.1rem;">
            ⬇️ DESCARGAR VIDEO ÚNICO
        </a>
        <button onclick="location.reload()" class="btn btn-outline-warning w-100 mt-2">
            🎬 CREAR OTRO VIDEO
        </button>
        <p class="text-center small text-muted mt-2 mb-0">
            ✓ Hash único generado<br>
            ✓ Listo para subir a Facebook/TikTok
        </p>
    </div>
</div>

<script>
const titleInput = document.getElementById('tIn');
const fileInput = document.getElementById('fIn');
const btnGo = document.getElementById('btnGo');
const charCount = document.getElementById('charCount');
const fileInfo = document.getElementById('fileInfo');

let videoDuration = 0;
const MAX_DURATION = 300;

function addEmoji(emoji) {
    if (titleInput.value.length < 43) {
        titleInput.value += emoji;
        titleInput.dispatchEvent(new Event('input'));
        titleInput.focus();
    }
}

function validateForm() {
    const hasTitle = titleInput && titleInput.value.trim().length > 0;
    const hasFile = fileInput && fileInput.files.length > 0;
    const validDuration = videoDuration <= MAX_DURATION || videoDuration === 0;
    if (btnGo) btnGo.disabled = !(hasTitle && hasFile && validDuration);
}

if (titleInput) {
    titleInput.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
        const len = [...this.value].length;
        charCount.textContent = len + '/45';
        charCount.className = 'char-count';
        if (len > 35) charCount.classList.add('warning');
        if (len > 42) charCount.classList.add('danger');
        validateForm();
    });
}

if (fileInput) {
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const sizeMB = (file.size / (1024*1024)).toFixed(1);
            const video = document.createElement('video');
            video.preload = 'metadata';
            video.onloadedmetadata = function() {
                videoDuration = video.duration;
                const mins = Math.floor(videoDuration / 60);
                const secs = Math.floor(videoDuration % 60);
                const durText = `${mins}:${secs.toString().padStart(2, '0')}`;
                const dimText = `${video.videoWidth}x${video.videoHeight}`;
                
                if (videoDuration > MAX_DURATION) {
                    fileInfo.innerHTML = `📁 ${file.name}<br><span class="duration-warning">⚠️ ${durText} (máx 5:00)</span> | ${dimText} | ${sizeMB} MB`;
                } else {
                    fileInfo.innerHTML = `📁 ${file.name}<br><span class="duration-ok">✓ ${durText}</span> | ${dimText} | ${sizeMB} MB`;
                }
                URL.revokeObjectURL(video.src);
                validateForm();
            };
            video.onerror = function() {
                fileInfo.innerHTML = `📁 ${file.name} | ${sizeMB} MB`;
                videoDuration = 0;
                validateForm();
            };
            video.src = URL.createObjectURL(file);
        }
    });
}

async function process() {
    const file = fileInput.files[0];
    const title = titleInput.value.trim();
    
    if (!title) return alert("El título es obligatorio");
    if (!file) return alert("Selecciona un video");
    if (videoDuration > MAX_DURATION) return alert("Video excede 5 minutos");

    document.getElementById('uiInput').classList.add('hidden');
    document.getElementById('uiProcess').classList.remove('hidden');

    const fd = new FormData();
    fd.append('videoFile', file);
    fd.append('videoTitle', title);
    fd.append('mirrorMode', document.getElementById('mirrorCheck').checked);

    try {
        let res = await fetch('?action=upload', { method: 'POST', body: fd });
        let text = await res.text();
        
        let data;
        try {
            data = JSON.parse(text);
        } catch(e) {
            console.error("Response:", text);
            throw new Error("Error del servidor");
        }
        
        if (data.status === 'error') {
            alert(data.msg);
            location.reload();
            return;
        }

        track(data.jobId);

    } catch(e) {
        alert("Error: " + e.message);
        location.reload();
    }
}

function track(id) {
    const bar = document.getElementById('progressBar');
    const txt = document.getElementById('progressText');
    const timeEst = document.getElementById('timeEstimate');
    const startTime = Date.now();
    
    const interval = setInterval(async () => {
        try {
            let res = await fetch(`?action=status&jobId=${id}`);
            let data = await res.json();

            if (data.status === 'finished') {
                clearInterval(interval);
                showResult(data.file);
            } else if (data.status === 'error') {
                clearInterval(interval);
                alert(data.msg);
                location.reload();
            } else {
                bar.style.width = data.progress + '%';
                txt.innerText = Math.round(data.progress) + '%';
                
                const elapsed = (Date.now() - startTime) / 1000;
                if (data.progress > 5) {
                    const total = elapsed / (data.progress / 100);
                    const remaining = Math.max(0, total - elapsed);
                    const mins = Math.floor(remaining / 60);
                    const secs = Math.floor(remaining % 60);
                    timeEst.innerText = `≈ ${mins}m ${secs}s restantes`;
                }
            }
        } catch(e) {}
    }, 3000);
}

function showResult(file) {
    document.getElementById('uiProcess').classList.add('hidden');
    document.getElementById('uiResult').classList.remove('hidden');
    
    const url = `processed/${file}?t=${Date.now()}`;
    document.getElementById('videoContainer').innerHTML = 
        `<video src="${url}" controls autoplay loop playsinline></video>`;
    document.getElementById('dlLink').href = `?action=download&file=${file}`;
}
</script>

</body>
</html>
