<?php
// ==========================================
// VIRAL REELS MAKER v78.0 - PRODUCTION READY
// Anti-fingerprint + Debug completo + Multiples validaciones
// ==========================================

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
ini_set('memory_limit', '2048M');
ini_set('upload_max_filesize', '2048M');
ini_set('post_max_size', '2048M');
ini_set('max_execution_time', 0);
ini_set('max_input_time', -1);
set_time_limit(0);
ignore_user_abort(true);

// Logging inicial
error_log("=== NEW REQUEST: " . date('Y-m-d H:i:s') . " ===");
error_log("Action: " . ($_GET['action'] ?? 'home'));
error_log("Method: " . $_SERVER['REQUEST_METHOD']);

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

// Crear directorios con permisos
foreach ([$uploadDir, $processedDir, $jobsDir, $assetsDir] as $dir) {
    if (!file_exists($dir)) {
        @mkdir($dir, 0777, true);
        @chmod($dir, 0777);
    }
}

// Crear archivos de log
if (!file_exists($logFile)) {
    @touch($logFile);
    @chmod($logFile, 0666);
}

// Limpieza (archivos > 4 horas)
foreach ([$uploadDir, $processedDir, $jobsDir, $assetsDir] as $dir) {
    if (is_dir($dir)) {
        foreach (glob("$dir/*") as $file) {
            if (is_file($file) && (time() - filemtime($file) > 14400)) {
                @unlink($file);
            }
        }
    }
}

function sendJson($data) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    global $logFile;
    if (isset($data['status']) && $data['status'] === 'error') {
        logMsg("ERROR JSON: " . ($data['msg'] ?? 'sin mensaje'));
        error_log("ERROR JSON: " . json_encode($data));
    }
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function logMsg($msg) {
    global $logFile;
    $time = date('Y-m-d H:i:s');
    $line = "[$time] $msg\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
    error_log($msg);
}

// Limpiar texto
function cleanTitle($text) {
    $text = mb_strtoupper($text, 'UTF-8');
    
    // Quitar emojis
    $text = preg_replace('/[\x{1F600}-\x{1F64F}]/u', '', $text);
    $text = preg_replace('/[\x{1F300}-\x{1F5FF}]/u', '', $text);
    $text = preg_replace('/[\x{1F680}-\x{1F6FF}]/u', '', $text);
    $text = preg_replace('/[\x{1F1E0}-\x{1F1FF}]/u', '', $text);
    $text = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $text);
    $text = preg_replace('/[\x{2700}-\x{27BF}]/u', '', $text);
    $text = preg_replace('/[\x{FE00}-\x{FE0F}]/u', '', $text);
    $text = preg_replace('/[\x{1F900}-\x{1F9FF}]/u', '', $text);
    $text = preg_replace('/[\x{1FA00}-\x{1FA6F}]/u', '', $text);
    $text = preg_replace('/[\x{1FA70}-\x{1FAFF}]/u', '', $text);
    $text = preg_replace('/[\x{200D}]/u', '', $text);
    
    // Solo letras, números, espacios y puntuación básica
    $text = preg_replace('/[^\p{L}\p{N}\s\-\.\!\?\,]/u', '', $text);
    $text = preg_replace('/\s+/', ' ', trim($text));
    
    return $text;
}

// Generar imagen PNG con título
function createTitleImage($title, $fontPath, $outputPath, $width = 720) {
    try {
        logMsg("Creando título: '$title'");
        
        if (!file_exists($fontPath)) {
            throw new Exception("Font no encontrado: $fontPath");
        }
        
        $fontSize = 54;
        $maxWidth = $width - 80;
        $lineHeight = 72;
        $paddingTop = 35;
        $paddingBottom = 25;
        
        $title = cleanTitle($title);
        if (empty($title)) $title = "VIDEO VIRAL";
        
        // Dividir en líneas
        $words = preg_split('/\s+/u', $title);
        $lines = [];
        $currentLine = '';
        
        $tempImg = @imagecreatetruecolor(1, 1);
        if (!$tempImg) {
            throw new Exception("No se pudo crear imagen temporal");
        }
        
        foreach ($words as $word) {
            $testLine = $currentLine ? "$currentLine $word" : $word;
            $bbox = @imagettfbbox($fontSize, 0, $fontPath, $testLine);
            if ($bbox === false) {
                imagedestroy($tempImg);
                throw new Exception("Error en imagettfbbox - verifica que font.ttf sea válido");
            }
            $testWidth = abs($bbox[2] - $bbox[0]);
            
            if ($testWidth <= $maxWidth) {
                $currentLine = $testLine;
            } else {
                if ($currentLine) $lines[] = $currentLine;
                $currentLine = $word;
            }
        }
        if ($currentLine) $lines[] = $currentLine;
        imagedestroy($tempImg);
        
        // Máximo 2 líneas
        if (count($lines) > 2) {
            $lines = array_slice($lines, 0, 2);
            if (mb_strlen($lines[1], 'UTF-8') > 16) {
                $lines[1] = mb_substr($lines[1], 0, 14, 'UTF-8') . '..';
            }
        }
        
        if (empty($lines)) $lines = ["VIDEO VIRAL"];
        
        // Calcular altura
        $totalHeight = $paddingTop + (count($lines) * $lineHeight) + $paddingBottom;
        
        // Crear imagen
        $img = @imagecreatetruecolor($width, $totalHeight);
        if (!$img) {
            throw new Exception("No se pudo crear imagen de título");
        }
        
        imagesavealpha($img, true);
        imagealphablending($img, false);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        imagealphablending($img, true);
        
        // Colores
        $gold = imagecolorallocate($img, 255, 215, 0);
        $black = imagecolorallocate($img, 0, 0, 0);
        
        // Dibujar cada línea
        $y = $paddingTop + $fontSize;
        foreach ($lines as $line) {
            $bbox = @imagettfbbox($fontSize, 0, $fontPath, $line);
            if ($bbox === false) continue;
            
            $textWidth = abs($bbox[2] - $bbox[0]);
            $x = ($width - $textWidth) / 2;
            
            // Sombra
            for ($sx = 3; $sx <= 5; $sx++) {
                for ($sy = 3; $sy <= 5; $sy++) {
                    @imagettftext($img, $fontSize, 0, $x + $sx, $y + $sy, $black, $fontPath, $line);
                }
            }
            
            // Borde negro
            for ($bx = -3; $bx <= 3; $bx++) {
                for ($by = -3; $by <= 3; $by++) {
                    if ($bx != 0 || $by != 0) {
                        @imagettftext($img, $fontSize, 0, $x + $bx, $y + $by, $black, $fontPath, $line);
                    }
                }
            }
            
            // Texto dorado
            @imagettftext($img, $fontSize, 0, $x, $y, $gold, $fontPath, $line);
            $y += $lineHeight;
        }
        
        // Guardar
        $result = @imagepng($img, $outputPath, 9);
        imagedestroy($img);
        
        if (!$result) {
            throw new Exception("No se pudo guardar PNG");
        }
        
        if (!file_exists($outputPath)) {
            throw new Exception("PNG no existe después de guardar");
        }
        
        logMsg("Título creado OK: " . filesize($outputPath) . " bytes");
        return $totalHeight;
        
    } catch (Exception $e) {
        logMsg("ERROR createTitleImage: " . $e->getMessage());
        throw $e;
    }
}

// Anti-fingerprint
function getAntiFingerprint() {
    return [
        'speed' => round(0.98 + (mt_rand(0, 40) / 1000), 3),
        'saturation' => round(1.05 + (mt_rand(0, 120) / 1000), 3),
        'contrast' => round(1.02 + (mt_rand(0, 60) / 1000), 3),
        'brightness' => round((mt_rand(-15, 15) / 1000), 3),
        'hue' => mt_rand(-4, 4),
        'gamma' => round(0.97 + (mt_rand(0, 60) / 1000), 3),
        'noise' => mt_rand(1, 2),
    ];
}

$action = $_GET['action'] ?? '';
$ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null') ?? '');
$ffprobePath = trim(shell_exec('which ffprobe 2>/dev/null') ?? '');
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
    
    logMsg("========================================");
    logMsg("UPLOAD REQUEST INICIADO");
    logMsg("Content-Length: " . ($_SERVER['CONTENT_LENGTH'] ?? 'N/A'));
    logMsg("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'N/A'));
    
    // Debug completo
    logMsg("POST vars: " . print_r($_POST, true));
    logMsg("FILES info: " . print_r($_FILES, true));
    
    // Validar FILES
    if (!isset($_FILES['videoFile'])) {
        logMsg("ERROR: videoFile no está en \$_FILES");
        sendJson(['status' => 'error', 'msg' => 'No se detectó el campo de archivo']);
    }
    
    // Validar errores de upload
    $uploadError = $_FILES['videoFile']['error'] ?? UPLOAD_ERR_NO_FILE;
    logMsg("Upload error code: $uploadError");
    
    if ($uploadError !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'Archivo excede upload_max_filesize (' . ini_get('upload_max_filesize') . ')',
            UPLOAD_ERR_FORM_SIZE => 'Archivo excede MAX_FILE_SIZE del formulario',
            UPLOAD_ERR_PARTIAL => 'Archivo subido parcialmente. Intenta de nuevo.',
            UPLOAD_ERR_NO_FILE => 'No se seleccionó archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta carpeta temporal en el servidor',
            UPLOAD_ERR_CANT_WRITE => 'Error escribiendo en disco',
            UPLOAD_ERR_EXTENSION => 'Extensión PHP bloqueó el upload',
        ];
        
        $msg = $errorMessages[$uploadError] ?? "Error desconocido: $uploadError";
        logMsg("Upload error: $msg");
        sendJson(['status' => 'error', 'msg' => $msg]);
    }
    
    // Validar tmp_name
    if (empty($_FILES['videoFile']['tmp_name'])) {
        logMsg("ERROR: tmp_name está vacío");
        sendJson(['status' => 'error', 'msg' => 'tmp_name vacío - problema del servidor']);
    }
    
    // Validar que el archivo temporal existe
    if (!file_exists($_FILES['videoFile']['tmp_name'])) {
        logMsg("ERROR: Archivo temporal no existe: " . $_FILES['videoFile']['tmp_name']);
        sendJson(['status' => 'error', 'msg' => 'Archivo temporal no existe']);
    }
    
    // Validar tamaño
    $fileSize = filesize($_FILES['videoFile']['tmp_name']);
    if ($fileSize === false || $fileSize === 0) {
        logMsg("ERROR: Archivo vacío o inaccesible");
        sendJson(['status' => 'error', 'msg' => 'Archivo vacío o corrupto']);
    }
    
    $sizeMB = round($fileSize / 1048576, 2);
    logMsg("Archivo recibido: {$sizeMB} MB");
    
    // Límite de tamaño: 500MB
    if ($fileSize > 500 * 1024 * 1024) {
        logMsg("ERROR: Archivo muy grande: {$sizeMB} MB");
        sendJson(['status' => 'error', 'msg' => "Archivo muy grande ({$sizeMB} MB). Máximo 500MB."]);
    }
    
    // Validaciones de sistema
    if (!$hasFfmpeg) {
        logMsg("ERROR: FFmpeg no instalado");
        sendJson(['status' => 'error', 'msg' => 'FFmpeg no instalado en el servidor']);
    }
    
    if (!$hasGD) {
        logMsg("ERROR: PHP GD no instalado");
        sendJson(['status' => 'error', 'msg' => 'PHP GD no instalado en el servidor']);
    }
    
    if (!$hasLogo) {
        logMsg("ERROR: Falta logo.png");
        sendJson(['status' => 'error', 'msg' => 'Falta logo.png en el servidor']);
    }
    
    if (!$hasFont) {
        logMsg("ERROR: Falta font.ttf");
        sendJson(['status' => 'error', 'msg' => 'Falta font.ttf en el servidor']);
    }
    
    if (!$hasAudio) {
        logMsg("ERROR: Falta news.mp3");
        sendJson(['status' => 'error', 'msg' => 'Falta news.mp3 en el servidor']);
    }
    
    // Validar título
    $title = trim($_POST['videoTitle'] ?? '');
    logMsg("Título recibido: '$title'");
    
    if (empty($title)) {
        logMsg("ERROR: Título vacío");
        sendJson(['status' => 'error', 'msg' => 'El título es obligatorio']);
    }
    
    $titleOriginal = $title;
    $title = cleanTitle($title);
    $title = mb_substr($title, 0, 36, 'UTF-8');
    
    logMsg("Título limpio: '$title' (original: '$titleOriginal')");
    
    if (empty($title)) {
        logMsg("Título vacío después de limpiar, usando default");
        $title = "VIDEO VIRAL";
    }

    // Generar ID único
    $jobId = 'v78_' . time() . '_' . mt_rand(1000, 9999);
    logMsg("Job ID: $jobId");
    
    // Determinar extensión
    $originalName = $_FILES['videoFile']['name'] ?? 'video.mp4';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $validExts = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', '3gp', 'flv', 'wmv'];
    
    if (!in_array($ext, $validExts)) {
        logMsg("Extensión inválida: $ext, usando mp4");
        $ext = 'mp4';
    }
    
    logMsg("Extensión: $ext");
    
    // Rutas de archivos
    $inputFile = "$uploadDir/{$jobId}_input.$ext";
    $titleImgFile = "$assetsDir/{$jobId}_title.png";
    $outputFileName = "{$jobId}_viral.mp4";
    $outputFile = "$processedDir/$outputFileName";
    $jobFile = "$jobsDir/{$jobId}.json";
    $scriptFile = "$jobsDir/{$jobId}.sh";

    // Mover archivo subido
    logMsg("Moviendo archivo a: $inputFile");
    if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
        $error = error_get_last();
        logMsg("ERROR al mover archivo: " . print_r($error, true));
        sendJson(['status' => 'error', 'msg' => 'Error al guardar archivo en el servidor']);
    }
    
    @chmod($inputFile, 0666);
    
    if (!file_exists($inputFile)) {
        logMsg("ERROR: Archivo no existe después de mover");
        sendJson(['status' => 'error', 'msg' => 'Error: archivo no se guardó correctamente']);
    }
    
    $finalSize = filesize($inputFile);
    logMsg("Archivo guardado OK: " . round($finalSize / 1048576, 2) . " MB");

    // Obtener info del video con FFprobe
    $seconds = 60;
    $videoWidth = 720;
    $videoHeight = 1280;
    $hasVideoAudio = false;
    
    if (!empty($ffprobePath)) {
        logMsg("Analizando video con FFprobe...");
        
        // Duración
        $cmd = "$ffprobePath -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputFile) . " 2>&1";
        $duration = trim(shell_exec($cmd) ?? '');
        logMsg("FFprobe duración output: '$duration'");
        
        if (is_numeric($duration) && $duration > 0) {
            $seconds = floatval($duration);
            logMsg("Duración detectada: {$seconds}s (" . gmdate("i:s", $seconds) . ")");
        } else {
            logMsg("ADVERTENCIA: No se pudo obtener duración, usando 60s");
        }
        
        // Dimensiones
        $cmd = "$ffprobePath -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 " . escapeshellarg($inputFile) . " 2>&1";
        $dims = trim(shell_exec($cmd) ?? '');
        logMsg("FFprobe dimensiones: '$dims'");
        
        if (preg_match('/(\d+)x(\d+)/', $dims, $m)) {
            $videoWidth = intval($m[1]);
            $videoHeight = intval($m[2]);
            logMsg("Dimensiones: {$videoWidth}x{$videoHeight}");
        }
        
        // Audio
        $cmd = "$ffprobePath -v error -select_streams a -show_entries stream=codec_type -of csv=p=0 " . escapeshellarg($inputFile) . " 2>&1";
        $audioCheck = trim(shell_exec($cmd) ?? '');
        $hasVideoAudio = !empty($audioCheck);
        logMsg("Audio en video: " . ($hasVideoAudio ? 'SI' : 'NO'));
        
    } else {
        logMsg("ADVERTENCIA: FFprobe no disponible, usando valores por defecto");
    }
    
    // Validar duración
    if ($seconds < 1) {
        logMsg("Duración inválida, usando 60s");
        $seconds = 60;
    }
    
    if ($seconds > 300) {
        $mins = round($seconds / 60, 1);
        @unlink($inputFile);
        logMsg("ERROR: Video muy largo: {$seconds}s ({$mins} min)");
        sendJson(['status' => 'error', 'msg' => "Video muy largo ({$mins} min). Máximo 5 minutos."]);
    }
    
    // Modo espejo
    $mirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
    logMsg("Modo espejo: " . ($mirror ? 'SI' : 'NO'));
    
    // Crear imagen del título
    logMsg("Creando imagen de título...");
    
    try {
        $titleHeight = createTitleImage($title, $fontPath, $titleImgFile, 720);
        
        if (!file_exists($titleImgFile)) {
            throw new Exception("Imagen no se creó en: $titleImgFile");
        }
        
        $titleSize = filesize($titleImgFile);
        if ($titleSize === false || $titleSize < 100) {
            throw new Exception("Imagen creada pero tamaño inválido: $titleSize bytes");
        }
        
        logMsg("Imagen título OK: {$titleSize} bytes, altura: {$titleHeight}px");
        
    } catch (Exception $e) {
        logMsg("ERROR creando título: " . $e->getMessage());
        @unlink($inputFile);
        sendJson(['status' => 'error', 'msg' => 'Error creando título: ' . $e->getMessage()]);
    }

    // Anti-fingerprint
    $af = getAntiFingerprint();
    $vidY = $titleHeight + 15;
    $cw = 720;
    $ch = 1280;
    $atempo = round(1 / $af['speed'], 4);
    
    logMsg("Configuración:");
    logMsg("- Video: {$seconds}s, {$videoWidth}x{$videoHeight}");
    logMsg("- Speed: {$af['speed']}, Sat: {$af['saturation']}, Con: {$af['contrast']}");
    logMsg("- Hue: {$af['hue']}, Gamma: {$af['gamma']}, Noise: {$af['noise']}");

    // Determinar preset según duración y tamaño
    if ($seconds > 180 || $fileSize > 100 * 1024 * 1024) {
        $preset = 'veryfast';
        $threads = 1;
        $crf = 26; // Más compresión
        logMsg("Preset: veryfast (video largo o pesado)");
    } elseif ($seconds > 120) {
        $preset = 'faster';
        $threads = 1;
        $crf = 24;
        logMsg("Preset: faster");
    } else {
        $preset = 'fast';
        $threads = 2;
        $crf = 23;
        logMsg("Preset: fast");
    }
    
    // Filtros de video
    $hflip = $mirror ? ",hflip" : "";
    
    $vf = "";
    $vf .= "color=c=#0a0a0a:s={$cw}x{$ch}:d=" . ceil($seconds + 2) . "[bg];";
    $vf .= "[0:v]scale={$cw}:-2:flags=bilinear,setsar=1{$hflip},";
    $vf .= "setpts=" . round(1/$af['speed'], 4) . "*PTS,";
    $vf .= "eq=saturation={$af['saturation']}:contrast={$af['contrast']}:brightness={$af['brightness']}:gamma={$af['gamma']},";
    $vf .= "hue=h={$af['hue']},";
    $vf .= "noise=alls={$af['noise']}:allf=t";
    $vf .= "[vid];";
    $vf .= "[bg][vid]overlay=0:{$vidY}:shortest=1[v1];";
    $vf .= "[1:v]scale={$cw}:-1[title];";
    $vf .= "[v1][title]overlay=0:0[v2];";
    $vf .= "[2:v]scale=-1:80[logo];";
    $vf .= "[v2][logo]overlay=30:H-110[vout]";
    
    // Filtros de audio
    if ($hasVideoAudio) {
        $af_filter = "[0:a]aresample=async=1000,atempo={$atempo},volume=0.75[a1];";
        $af_filter .= "[3:a]aresample=async=1000,volume=0.3[a2];";
        $af_filter .= "[a1][a2]amix=inputs=2:duration=first:dropout_transition=3:normalize=0[aout]";
    } else {
        $af_filter = "[3:a]aresample=async=1000,volume=0.5[aout]";
    }
    
    $fullFilter = $vf . ";" . $af_filter;
    
    // Comando FFmpeg
    $cmd = "$ffmpegPath -y ";
    $cmd .= "-i " . escapeshellarg($inputFile) . " ";
    $cmd .= "-i " . escapeshellarg($titleImgFile) . " ";
    $cmd .= "-i " . escapeshellarg($logoPath) . " ";
    $cmd .= "-stream_loop -1 -i " . escapeshellarg($audioPath) . " ";
    $cmd .= "-filter_complex " . escapeshellarg($fullFilter) . " ";
    $cmd .= "-map \"[vout]\" -map \"[aout]\" ";
    $cmd .= "-c:v libx264 -preset {$preset} -crf {$crf} -threads {$threads} ";
    $cmd .= "-c:a aac -b:a 192k -ar 44100 ";
    $cmd .= "-movflags +faststart ";
    $cmd .= "-t " . ceil($seconds / $af['speed']) . " ";
    $cmd .= "-metadata title=\"VID" . date('ymdHis') . mt_rand(100,999) . "\" ";
    $cmd .= escapeshellarg($outputFile);

    logMsg("Comando FFmpeg generado (" . strlen($cmd) . " chars)");

    // Guardar estado del job
    $jobData = [
        'status' => 'processing',
        'file' => $outputFileName,
        'start' => time(),
        'duration' => $seconds,
        'title' => $title,
        'af' => $af,
        'filesize' => $fileSize,
        'preset' => $preset,
    ];
    
    file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT));
    logMsg("Job file creado: $jobFile");

    // Crear script bash
    $script = "#!/bin/bash\n";
    $script .= "set -e\n"; // Exit on error
    $script .= "cd " . escapeshellarg($baseDir) . "\n\n";
    $script .= "echo \"\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo \"========================================\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] JOB INICIADO: $jobId\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo \"Título: $title\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo \"Duración: {$seconds}s\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo \"Tamaño: {$sizeMB} MB\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo \"Preset: $preset\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo \"\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo \"Comando FFmpeg:\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo " . escapeshellarg($cmd) . " >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo \"\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo \"Ejecutando FFmpeg...\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo \"\" >> " . escapeshellarg($logFile) . "\n";
    
    // Ejecutar FFmpeg
    $script .= $cmd . " >> " . escapeshellarg($logFile) . " 2>&1\n";
    $script .= "FFMPEG_EXIT=\$?\n\n";
    
    // Verificar resultado
    $script .= "echo \"\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "if [ \$FFMPEG_EXIT -eq 0 ]; then\n";
    $script .= "  if [ -f " . escapeshellarg($outputFile) . " ]; then\n";
    $script .= "    SIZE=\$(du -h " . escapeshellarg($outputFile) . " 2>/dev/null | cut -f1)\n";
    $script .= "    echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] SUCCESS: Video creado (\$SIZE)\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "  else\n";
    $script .= "    echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] ERROR: FFmpeg exitó 0 pero archivo no existe\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "  fi\n";
    $script .= "else\n";
    $script .= "  echo \"[\$(date '+%Y-%m-%d %H:%M:%S')] ERROR: FFmpeg falló con código \$FFMPEG_EXIT\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "fi\n\n";
    
    $script .= "echo \"========================================\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "echo \"\" >> " . escapeshellarg($logFile) . "\n\n";
    
    // Limpiar archivos temporales
    $script .= "echo \"Limpiando archivos temporales...\" >> " . escapeshellarg($logFile) . "\n";
    $script .= "rm -f " . escapeshellarg($inputFile) . " 2>/dev/null || true\n";
    $script .= "rm -f " . escapeshellarg($titleImgFile) . " 2>/dev/null || true\n";
    $script .= "echo \"Job completado\" >> " . escapeshellarg($logFile) . "\n";
    
    file_put_contents($scriptFile, $script);
    chmod($scriptFile, 0755);
    
    logMsg("Script bash creado: $scriptFile");
    logMsg("Iniciando procesamiento en background...");
    
    // Ejecutar en background
    $execCmd = "nohup nice -n 19 bash " . escapeshellarg($scriptFile) . " > /dev/null 2>&1 &";
    exec($execCmd, $output, $return);
    
    logMsg("Comando exec return code: $return");
    logMsg("Proceso background iniciado");
    logMsg("========================================");

    sendJson(['status' => 'success', 'jobId' => $jobId]);
}

// ==========================================
// API: STATUS
// ==========================================
if ($action === 'status') {
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['jobId'] ?? '');
    if (empty($id)) {
        logMsg("Status check: ID vacío");
        sendJson(['status' => 'error', 'msg' => 'ID inválido']);
    }
    
    $jFile = "$jobsDir/{$id}.json";
    if (!file_exists($jFile)) {
        logMsg("Status check: Job no encontrado: $id");
        sendJson(['status' => 'error', 'msg' => 'Job no encontrado']);
    }
    
    $data = json_decode(file_get_contents($jFile), true);
    if (!$data) {
        logMsg("Status check: Error leyendo JSON: $id");
        sendJson(['status' => 'error', 'msg' => 'Error leyendo job']);
    }
    
    $outputPath = "$processedDir/" . $data['file'];
    
    // Verificar si terminó
    if (file_exists($outputPath)) {
        clearstatcache(true, $outputPath);
        $size = filesize($outputPath);
        $mtime = filemtime($outputPath);
        
        // Dar tiempo para que termine de escribir (5 segundos)
        if ($size > 50000 && (time() - $mtime) > 5) {
            $sizeMB = round($size / 1048576, 2);
            logMsg("Job completado: $id ({$sizeMB} MB)");
            sendJson(['status' => 'finished', 'file' => $data['file'], 'size' => $sizeMB]);
        }
    }
    
    // Calcular timeout dinámico
    $baseTimeout = 600; // 10 minutos base
    $perSecondTimeout = 5; // 5 segundos por cada segundo de video
    $timeout = $baseTimeout + ($data['duration'] * $perSecondTimeout);
    
    $elapsed = time() - $data['start'];
    
    if ($elapsed > $timeout) {
        logMsg("Job timeout: $id después de {$elapsed}s (timeout: {$timeout}s)");
        sendJson(['status' => 'error', 'msg' => 'Procesamiento tomó demasiado tiempo. Intenta con un video más corto.']);
    }
    
    // Progreso estimado (más preciso)
    $estimatedTime = $data['duration'] * 2.5; // Estimación conservadora
    $progress = min(95, round(($elapsed / $estimatedTime) * 100));
    
    sendJson(['status' => 'processing', 'progress' => $progress, 'elapsed' => $elapsed]);
}

// ==========================================
// API: DOWNLOAD
// ==========================================
if ($action === 'download' && isset($_GET['file'])) {
    $file = basename($_GET['file']);
    $path = "$processedDir/$file";
    
    logMsg("Download request: $file");
    
    if (!file_exists($path)) {
        logMsg("Download: Archivo no encontrado");
        http_response_code(404);
        die('Archivo no encontrado');
    }
    
    while (ob_get_level()) ob_end_clean();
    
    $size = filesize($path);
    logMsg("Download: Enviando archivo (" . round($size/1048576, 2) . " MB)");
    
    header('Content-Type: video/mp4');
    header('Content-Disposition: attachment; filename="VIRAL_' . date('md_His') . '.mp4"');
    header('Content-Length: ' . $size);
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=0');
    
    readfile($path);
    exit;
}

// ==========================================
// API: DEBUG
// ==========================================
if ($action === 'debug') {
    header('Content-Type: text/plain; charset=utf-8');
    
    echo "=== VIRAL MAKER v78 - DEBUG INFO ===\n\n";
    echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";
    
    echo "SISTEMA:\n";
    echo "PHP: " . PHP_VERSION . "\n";
    echo "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') . "\n";
    echo "OS: " . PHP_OS . "\n";
    echo "Memory limit: " . ini_get('memory_limit') . "\n";
    echo "Upload max: " . ini_get('upload_max_filesize') . "\n";
    echo "Post max: " . ini_get('post_max_size') . "\n";
    echo "Max execution: " . ini_get('max_execution_time') . "\n";
    echo "Temp dir: " . sys_get_temp_dir() . "\n\n";
    
    echo "DEPENDENCIAS:\n";
    echo "FFmpeg: " . ($hasFfmpeg ? "✓ OK ($ffmpegPath)" : "✗ NO INSTALADO") . "\n";
    
    if ($hasFfmpeg) {
        $version = shell_exec("$ffmpegPath -version 2>&1 | head -n 1");
        echo "  Version: " . trim($version) . "\n";
    }
    
    echo "FFprobe: " . (!empty($ffprobePath) ? "✓ OK ($ffprobePath)" : "✗ NO") . "\n";
    echo "PHP GD: " . ($hasGD ? "✓ OK" : "✗ NO INSTALADO") . "\n";
    
    if ($hasGD) {
        $gdInfo = gd_info();
        echo "  GD Version: " . ($gdInfo['GD Version'] ?? 'N/A') . "\n";
        echo "  FreeType: " . ($gdInfo['FreeType Support'] ? 'YES' : 'NO') . "\n";
        echo "  JPEG: " . ($gdInfo['JPEG Support'] ? 'YES' : 'NO') . "\n";
        echo "  PNG: " . ($gdInfo['PNG Support'] ? 'YES' : 'NO') . "\n";
    }
    
    echo "\n";
    
    echo "ARCHIVOS REQUERIDOS:\n";
    foreach (['logo.png', 'font.ttf', 'news.mp3'] as $file) {
        $path = $baseDir . '/' . $file;
        if (file_exists($path)) {
            $size = filesize($path);
            echo "✓ $file (" . round($size/1024, 1) . " KB)\n";
        } else {
            echo "✗ $file - FALTA\n";
        }
    }
    echo "\n";
    
    echo "DIRECTORIOS:\n";
    foreach (['uploads', 'processed', 'jobs', 'assets'] as $dir) {
        $path = $baseDir . '/' . $dir;
        $exists = is_dir($path);
        $writable = is_writable($path);
        $status = $exists ? ($writable ? '✓ OK' : '⚠ No escribible') : '✗ No existe';
        echo "$status - /$dir\n";
        
        if ($exists) {
            $files = glob("$path/*");
            echo "  Archivos: " . count($files) . "\n";
        }
    }
    echo "\n";
    
    echo "JOBS ACTIVOS:\n";
    $jobs = glob("$jobsDir/*.json");
    if (empty($jobs)) {
        echo "  (ninguno)\n";
    } else {
        foreach ($jobs as $jobFile) {
            $job = json_decode(file_get_contents($jobFile), true);
            if ($job) {
                $elapsed = time() - $job['start'];
                echo "  " . basename($jobFile, '.json') . " - " . $job['status'];
                echo " (hace " . gmdate("i:s", $elapsed) . ")\n";
            }
        }
    }
    echo "\n";
    
    echo "ANTI-FINGERPRINT CONFIG:\n";
    echo "Velocidad: 0.98x - 1.02x\n";
    echo "Saturación: 1.05 - 1.17\n";
    echo "Contraste: 1.02 - 1.08\n";
    echo "Brillo: -0.015 a +0.015\n";
    echo "Hue: -4 a +4\n";
    echo "Gamma: 0.97 - 1.03\n";
    echo "Noise: 1-2\n";
    echo "Audio música: 0.3\n\n";
    
    echo "=== ÚLTIMAS 150 LÍNEAS DEL LOG FFMPEG ===\n";
    if (file_exists($logFile) && filesize($logFile) > 0) {
        $lines = file($logFile);
        $lines = array_slice($lines, -150);
        echo implode("", $lines);
    } else {
        echo "(Log vacío o no existe)\n";
    }
    
    echo "\n=== ÚLTIMAS 100 LÍNEAS DEL ERROR LOG PHP ===\n";
    $phpErrorLog = $baseDir . '/php_errors.log';
    if (file_exists($phpErrorLog) && filesize($phpErrorLog) > 0) {
        $lines = file($phpErrorLog);
        $lines = array_slice($lines, -100);
        echo implode("", $lines);
    } else {
        echo "(Sin errores PHP registrados)\n";
    }
    
    exit;
}

// ==========================================
// API: CLEAR
// ==========================================
if ($action === 'clear') {
    logMsg("CLEAR: Limpiando logs y archivos temporales");
    
    @unlink($logFile);
    @unlink($baseDir . '/php_errors.log');
    
    $deleted = 0;
    foreach ([$jobsDir, $uploadDir, $assetsDir] as $dir) {
        foreach (glob("$dir/*") as $file) {
            if (@unlink($file)) $deleted++;
        }
    }
    
    logMsg("CLEAR: $deleted archivos eliminados");
    
    header('Location: ?action=debug');
    exit;
}

// ==========================================
// HTML INTERFACE
// ==========================================
if (ob_get_level()) ob_end_clean();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viral Maker v78</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root { --gold: #FFD700; --dark: #0a0a0a; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: linear-gradient(145deg, #050505 0%, #0f0f1a 100%);
            color: #fff;
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
        }
        .card {
            background: #111;
            border: 1px solid #222;
            max-width: 500px;
            width: 100%;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 30px 90px rgba(0,0,0,0.8);
        }
        h2 { 
            font-family: 'Anton', sans-serif; 
            font-size: 2.2rem; 
            letter-spacing: 4px; 
            margin: 0;
            text-shadow: 0 0 20px rgba(255,215,0,0.3);
        }
        .subtitle { 
            color: #666; 
            font-size: 0.7rem; 
            letter-spacing: 2px; 
            text-transform: uppercase;
            margin-top: 5px;
        }
        .form-control {
            background: var(--dark) !important;
            color: var(--gold) !important;
            border: 2px solid #1a1a1a;
            border-radius: 12px;
            padding: 16px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        .form-control:focus { 
            border-color: var(--gold); 
            box-shadow: 0 0 20px rgba(255,215,0,0.15); 
            outline: none; 
        }
        .form-control::placeholder { color: #444; }
        .btn-go {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, var(--gold), #e6a800);
            color: #000;
            font-family: 'Anton', sans-serif;
            font-size: 1.3rem;
            letter-spacing: 3px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            margin-top: 20px;
            transition: all 0.3s;
            text-transform: uppercase;
        }
        .btn-go:hover:not(:disabled) { 
            transform: translateY(-3px); 
            box-shadow: 0 10px 30px rgba(255,215,0,0.3); 
        }
        .btn-go:disabled { 
            background: #222; 
            color: #555; 
            cursor: not-allowed; 
            transform: none;
        }
        .hidden { display: none !important; }
        .progress { 
            height: 12px; 
            background: #1a1a1a; 
            border-radius: 6px; 
            margin: 25px 0; 
            overflow: hidden;
        }
        .progress-bar { 
            background: linear-gradient(90deg, var(--gold), #ff8c00); 
            transition: width 0.5s ease;
            box-shadow: 0 0 15px rgba(255,215,0,0.5);
        }
        video { 
            width: 100%; 
            border-radius: 12px; 
            margin: 20px 0; 
            border: 2px solid #222;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
        }
        .info { 
            font-size: 0.85rem; 
            color: #666; 
            margin-top: 8px; 
            padding: 8px 12px;
            border-radius: 8px;
            background: rgba(255,255,255,0.02);
        }
        .info.ok { color: #4a4; background: rgba(74,170,74,0.1); }
        .info.warn { color: #da8; background: rgba(221,170,136,0.1); }
        .char-counter { 
            font-size: 0.75rem; 
            color: #555; 
            text-align: right; 
            margin-top: 5px; 
        }
        .char-counter.warn { color: #da8; }
        .char-counter.max { color: #d44; }
        .tag { 
            display: inline-block; 
            background: rgba(255,215,0,0.1); 
            color: var(--gold); 
            font-size: 0.65rem; 
            padding: 5px 12px; 
            border-radius: 20px; 
            margin: 4px; 
            border: 1px solid rgba(255,215,0,0.2);
        }
        .alert-box { 
            background: rgba(170,50,50,0.15); 
            border: 1px solid #844; 
            color: #daa; 
            border-radius: 12px; 
            padding: 20px; 
            font-size: 0.9rem; 
            line-height: 1.6;
        }
        .spinner-border { 
            width: 3.5rem; 
            height: 3.5rem; 
            border-width: 4px;
        }
        .debug-link { 
            position: fixed; 
            bottom: 15px; 
            right: 15px; 
            opacity: 0.4; 
            transition: opacity 0.3s;
            background: rgba(0,0,0,0.5);
            padding: 8px 15px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.85rem;
        }
        .debug-link:hover { opacity: 1; }
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .status-processing {
            background: rgba(255,165,0,0.2);
            color: #ffa500;
            border: 1px solid rgba(255,165,0,0.3);
        }
        .status-success {
            background: rgba(74,170,74,0.2);
            color: #4a4;
            border: 1px solid rgba(74,170,74,0.3);
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .pulsing { animation: pulse 2s infinite; }
    </style>
</head>
<body>

<div class="card">
    <div class="text-center mb-4">
        <h2>VIRAL MAKER <span style="color:var(--gold)">v78</span></h2>
        <p class="subtitle">Anti-fingerprint · Production Ready</p>
        <div class="mt-3">
            <span class="tag">🔒 Hash Único</span>
            <span class="tag">⏱️ 5 Min Max</span>
            <span class="tag">💾 500MB Max</span>
        </div>
    </div>

    <div id="formSection">
        <?php if (!$hasFfmpeg): ?>
            <div class="alert-box">
                <strong>❌ FFmpeg no instalado</strong><br>
                <small>Instala con: apt-get install ffmpeg</small>
            </div>
        <?php elseif (!$hasGD): ?>
            <div class="alert-box">
                <strong>❌ PHP GD no instalado</strong><br>
                <small>Instala con: apt-get install php-gd<br>
                O usa el Dockerfile proporcionado</small>
            </div>
        <?php elseif (!empty($missingFiles)): ?>
            <div class="alert-box">
                <strong>❌ Archivos faltantes:</strong><br>
                <?php foreach($missingFiles as $f): ?>
                    • <?php echo htmlspecialchars($f); ?><br>
                <?php endforeach; ?>
                <small class="mt-2 d-block">Sube estos archivos a la raíz del proyecto</small>
            </div>
        <?php else: ?>
            <div class="mb-3">
                <label class="form-label text-secondary small">Título del Video</label>
                <input type="text" id="titleInput" class="form-control" placeholder="INGRESA UN TÍTULO LLAMATIVO" maxlength="36">
                <div class="char-counter" id="charCounter">0 / 36</div>
            </div>
            
            <div class="mb-3">
                <label class="form-label text-secondary small">Selecciona Video</label>
                <input type="file" id="videoInput" class="form-control" accept="video/*">
                <div class="info" id="videoInfo"></div>
            </div>
            
            <div class="form-check form-switch d-flex justify-content-center align-items-center gap-2 mb-2">
                <input class="form-check-input" type="checkbox" id="mirrorCheck" style="cursor:pointer;width:50px;height:25px;">
                <label class="form-check-label text-secondary" style="cursor:pointer;">Modo Espejo</label>
            </div>
            
            <button id="submitBtn" class="btn-go" disabled>🚀 Crear Video Viral</button>
            
            <p class="text-center text-secondary small mt-3 mb-0" style="line-height:1.6;">
                📊 Máximo 5 minutos de duración<br>
                💿 Máximo 500MB de tamaño<br>
                ✨ Cada video tiene hash único para evitar detección
            </p>
        <?php endif; ?>
    </div>

    <div id="processSection" class="hidden text-center">
        <div class="spinner-border text-warning mb-3 pulsing" role="status"></div>
        <h4 class="mb-3">
            <span class="status-badge status-processing">Procesando Video</span>
        </h4>
        <p class="text-secondary small mb-3">Aplicando efectos anti-fingerprint únicos...</p>
        <div class="progress">
            <div id="progressBar" class="progress-bar" style="width:0%" role="progressbar"></div>
        </div>
        <div id="progressText" class="text-warning fw-bold" style="font-size:1.5rem">0%</div>
        <div id="timeInfo" class="text-secondary small mt-2"></div>
        <div id="estimateInfo" class="text-muted small mt-2"></div>
        <p class="text-muted small mt-4" style="line-height:1.6;">
            ⏳ Esto puede tardar varios minutos<br>
            💡 No cierres esta página<br>
            🎬 El progreso se actualiza automáticamente
        </p>
    </div>

    <div id="resultSection" class="hidden text-center">
        <h4 class="mb-3">
            <span class="status-badge status-success">✓ Video Listo</span>
        </h4>
        <video id="resultVideo" controls autoplay loop playsinline></video>
        <div id="videoSize" class="text-secondary small mb-3"></div>
        <a id="downloadBtn" class="btn btn-success w-100 py-3 fw-bold mb-2" style="border-radius:12px;">
            📥 DESCARGAR VIDEO
        </a>
        <button onclick="location.reload()" class="btn btn-outline-warning w-100 py-2" style="border-radius:12px;">
            ➕ Crear Nuevo Video
        </button>
        <p class="text-secondary small mt-3 mb-0" style="line-height:1.6;">
            ✨ Hash único aplicado<br>
            🎯 Listo para TikTok, Instagram, YouTube<br>
            🔒 Protegido contra detección automática
        </p>
    </div>
</div>

<a href="?action=debug" class="debug-link text-light" target="_blank">
    🔧 Debug & Logs
</a>

<script>
console.log('Viral Maker v78 - Initialized');

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
const estimateInfo = document.getElementById('estimateInfo');

let videoDuration = 0;
let videoSizeMB = 0;

function validate() {
    const hasTitle = titleInput && titleInput.value.trim().length > 0;
    const hasVideo = videoInput && videoInput.files.length > 0;
    const validDur = videoDuration <= 300 || videoDuration === 0;
    const validSize = videoSizeMB <= 500 || videoSizeMB === 0;
    
    if (submitBtn) {
        submitBtn.disabled = !(hasTitle && hasVideo && validDur && validSize);
    }
}

if (titleInput) {
    titleInput.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
        const len = this.value.length;
        charCounter.textContent = len + ' / 36';
        charCounter.className = 'char-counter';
        if (len > 28) charCounter.classList.add('warn');
        if (len >= 36) charCounter.classList.add('max');
        validate();
    });
}

if (videoInput) {
    videoInput.addEventListener('change', function() {
        const file = this.files[0];
        if (!file) { 
            videoDuration = 0;
            videoSizeMB = 0;
            videoInfo.textContent = '';
            videoInfo.className = 'info';
            validate(); 
            return; 
        }
        
        videoSizeMB = (file.size / 1048576);
        console.log('Archivo seleccionado:', file.name, videoSizeMB.toFixed(1), 'MB');
        
        videoInfo.textContent = '⏳ Analizando video...';
        videoInfo.className = 'info';
        
        const video = document.createElement('video');
        video.preload = 'metadata';
        
        video.onloadedmetadata = function() {
            videoDuration = video.duration;
            const m = Math.floor(videoDuration / 60);
            const s = Math.floor(videoDuration % 60);
            const dur = m + ':' + String(s).padStart(2, '0');
            
            console.log('Duración detectada:', videoDuration, 'segundos');
            
            videoInfo.className = 'info';
            let message = '';
            
            if (videoDuration > 300) {
                videoInfo.classList.add('warn');
                message = '⚠️ ' + dur + ' (máximo 5:00) • ' + videoSizeMB.toFixed(1) + ' MB';
            } else if (videoSizeMB > 500) {
                videoInfo.classList.add('warn');
                message = '⚠️ ' + videoSizeMB.toFixed(1) + ' MB (máximo 500MB) • ' + dur;
            } else if (videoSizeMB > 100) {
                videoInfo.classList.add('warn');
                message = '⚠️ ' + dur + ' • ' + videoSizeMB.toFixed(1) + ' MB (tomará varios minutos)';
            } else {
                videoInfo.classList.add('ok');
                message = '✓ ' + dur + ' • ' + videoSizeMB.toFixed(1) + ' MB';
            }
            
            videoInfo.innerHTML = message;
            URL.revokeObjectURL(video.src);
            validate();
        };
        
        video.onerror = function(e) {
            console.error('Error cargando video:', e);
            videoInfo.className = 'info warn';
            videoInfo.textContent = '⚠️ ' + videoSizeMB.toFixed(1) + ' MB (no se pudo analizar duración)';
            videoDuration = 60; // Asumir 1 minuto si no se puede detectar
            URL.revokeObjectURL(video.src);
            validate();
        };
        
        try {
            video.src = URL.createObjectURL(file);
        } catch(e) {
            console.error('Error creando URL:', e);
            videoInfo.className = 'info warn';
            videoInfo.textContent = '❌ Error al leer el archivo';
            validate();
        }
    });
}

if (submitBtn) {
    submitBtn.addEventListener('click', async function() {
        const title = titleInput.value.trim();
        const file = videoInput.files[0];
        
        console.log('Submit clicked - Title:', title, 'File:', file ? file.name : 'none');
        
        if (!title || !file || videoDuration > 300 || videoSizeMB > 500) {
            alert('❌ Por favor completa todos los campos correctamente');
            return;
        }
        
        // Confirmar si el archivo es grande
        if (videoSizeMB > 150 || videoDuration > 120) {
            const mins = Math.round(videoDuration / 60);
            if (!confirm(`⚠️ Video: ${mins} min, ${videoSizeMB.toFixed(0)} MB\n\nEl procesamiento puede tardar ${mins * 2}-${mins * 3} minutos.\n\n¿Continuar?`)) {
                return;
            }
        }
        
        console.log('Starting upload...');
        formSection.classList.add('hidden');
        processSection.classList.remove('hidden');
        
        const fd = new FormData();
        fd.append('videoFile', file);
        fd.append('videoTitle', title);
        fd.append('mirrorMode', document.getElementById('mirrorCheck').checked);
        
        console.log('FormData prepared, sending request...');
        
        try {
            const res = await fetch('?action=upload', { 
                method: 'POST', 
                body: fd 
            });
            
            console.log('Response received:', res.status, res.statusText);
            
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${res.statusText}`);
            }
            
            const txt = await res.text();
            console.log('Response text:', txt.substring(0, 500));
            
            let data;
            try { 
                data = JSON.parse(txt); 
            } catch(e) { 
                console.error('JSON Parse error:', e);
                console.error('Response:', txt);
                throw new Error('Respuesta inválida del servidor. Revisa el debug.'); 
            }
            
            console.log('Parsed response:', data);
            
            if (data.status === 'error') { 
                alert('❌ Error: ' + data.msg); 
                location.reload(); 
                return; 
            }
            
            if (data.status === 'success' && data.jobId) {
                console.log('Job started:', data.jobId);
                track(data.jobId);
            } else {
                throw new Error('Respuesta inesperada del servidor');
            }
            
        } catch(e) {
            console.error('Upload error:', e);
            alert('❌ Error: ' + e.message + '\n\nRevisa la consola (F12) y el debug para más información.');
            location.reload();
        }
    });
}

function track(jobId) {
    console.log('Starting job tracking:', jobId);
    const start = Date.now();
    let checkCount = 0;
    let lastProgress = 0;
    
    const iv = setInterval(async () => {
        checkCount++;
        
        try {
            const res = await fetch('?action=status&jobId=' + jobId);
            
            if (!res.ok) {
                console.error('Status check failed:', res.status);
                throw new Error(`HTTP ${res.status}`);
            }
            
            const data = await res.json();
            console.log(`Status check #${checkCount}:`, data);
            
            if (data.status === 'finished') {
                clearInterval(iv);
                console.log('Job finished:', data.file);
                
                processSection.classList.add('hidden');
                resultSection.classList.remove('hidden');
                
                document.getElementById('resultVideo').src = 'processed/' + data.file + '?t=' + Date.now();
                document.getElementById('downloadBtn').href = '?action=download&file=' + data.file;
                
                if (data.size) {
                    document.getElementById('videoSize').textContent = `📦 Tamaño: ${data.size} MB`;
                }
                
            } else if (data.status === 'error') {
                clearInterval(iv);
                console.error('Job error:', data.msg);
                alert('❌ Error: ' + data.msg);
                location.reload();
                
            } else {
                // Processing
                const progress = data.progress || 0;
                
                if (progress > lastProgress) {
                    progressBar.style.width = progress + '%';
                    progressText.textContent = progress + '%';
                    lastProgress = progress;
                }
                
                const elapsed = data.elapsed || Math.floor((Date.now() - start) / 1000);
                const mins = Math.floor(elapsed / 60);
                const secs = elapsed % 60;
                timeInfo.textContent = `⏱️ Tiempo: ${mins}m ${secs}s`;
                
                // Estimación de tiempo restante
                if (progress > 5) {
                    const estimatedTotal = (elapsed / progress) * 100;
                    const remaining = estimatedTotal - elapsed;
                    const remMins = Math.floor(remaining / 60);
                    const remSecs = Math.floor(remaining % 60);
                    estimateInfo.textContent = `⏳ Tiempo estimado restante: ~${remMins}m ${remSecs}s`;
                }
            }
        } catch(e) {
            console.error('Status check error:', e);
            // No detener el polling por errores temporales
        }
    }, 3000); // Check cada 3 segundos
    
    // Timeout de seguridad (30 minutos)
    setTimeout(() => {
        if (processSection.classList.contains('hidden')) return; // Ya terminó
        
        clearInterval(iv);
        console.error('Timeout reached (30 min)');
        alert('⚠️ Tiempo máximo de procesamiento alcanzado (30 min).\n\nEl video puede ser muy largo o pesado.\n\nPrueba con un video más corto.');
        location.reload();
    }, 30 * 60 * 1000);
}

// Log de inicio
console.log('Script loaded successfully');
console.log('PHP GD:', <?php echo $hasGD ? 'true' : 'false'; ?>);
console.log('FFmpeg:', <?php echo $hasFfmpeg ? 'true' : 'false'; ?>);
</script>

</body>
</html>
