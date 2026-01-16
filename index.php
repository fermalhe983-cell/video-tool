<?php
// ==========================================
// VIRAL REELS MAKER v65.0 (BULLETPROOF EDITION)
// ==========================================

// 1. Ocultar errores visibles que rompen el JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 2. Aumentar límites CRÍTICOS (Esto intenta forzar al servidor, pero depende de php.ini)
@ini_set('memory_limit', '2048M'); 
@ini_set('upload_max_filesize', '2048M');
@ini_set('post_max_size', '2048M');
@ini_set('max_execution_time', 0);
@ini_set('max_input_time', 0);
ignore_user_abort(true); 

// 3. Iniciar Buffer de Salida (Captura cualquier error impreso por accidente)
ob_start();

// --- FUNCIÓN HELPER PARA RESPONDER JSON Y CORTAR ---
function sendJsonAndExit($data) {
    // Borramos cualquier salida anterior (HTML, espacios, warnings)
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json');
    echo json_encode($data);
    exit; // MATAMOS EL SCRIPT AQUÍ. IMPOSIBLE QUE SALGA HTML.
}

// Rutas
$baseDir = __DIR__;
$uploadDir = $baseDir . '/uploads';
$processedDir = $baseDir . '/processed';
$jobsDir = $baseDir . '/jobs'; 
$logoPath = $baseDir . '/logo.png'; 
$fontPath = $baseDir . '/font.ttf'; 
$audioPath = $baseDir . '/news.mp3';
$logFile = $baseDir . '/ffmpeg_log.txt';

// Crear carpetas si no existen
if (!file_exists($uploadDir)) @mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) @mkdir($processedDir, 0777, true);
if (!file_exists($jobsDir)) @mkdir($jobsDir, 0777, true);

// Limpieza de archivos viejos (>2 horas)
try {
    foreach ([$uploadDir, $processedDir, $jobsDir] as $dir) {
        if(is_dir($dir)){
            foreach (glob("$dir/*") as $file) {
                if (is_file($file) && (time() - filemtime($file) > 7200)) @unlink($file);
            }
        }
    }
} catch (Exception $e) { /* Ignorar errores de limpieza */ }

$action = $_GET['action'] ?? '';

// ==========================================
// 1. DETECCIÓN DE HERRAMIENTAS
// ==========================================
$ffmpegPath = trim(shell_exec('which ffmpeg'));
$hasFfmpeg = !empty($ffmpegPath);

// ==========================================
// 2. API: STATUS (Polling)
// ==========================================
if ($action === 'status') {
    $id = preg_replace('/[^a-z0-9_]/', '', $_GET['jobId'] ?? '');
    $jFile = "$jobsDir/$id.json";
    
    if (file_exists($jFile)) {
        $data = json_decode(file_get_contents($jFile), true);
        $fullPath = "$processedDir/" . ($data['file'] ?? '');
        
        // Verificamos si terminó
        if (!empty($data['file']) && file_exists($fullPath) && filesize($fullPath) > 1000) {
            // Un pequeño delay para asegurar que FFmpeg soltó el archivo
            if ((time() - filemtime($fullPath)) > 2) {
                 sendJsonAndExit(['status' => 'finished', 'file' => $data['file']]);
            }
        }
        
        // Timeout de seguridad (30 min)
        if ((time() - $data['start']) > 1800) {
             sendJsonAndExit(['status' => 'error', 'msg' => 'Timeout: El video tardó demasiado.']);
        }

        // Progreso estimado
        $elapsed = time() - $data['start'];
        $progress = min(98, ($elapsed / max(1, ($data['duration'] * 0.9))) * 100);
        sendJsonAndExit(['status' => 'processing', 'progress' => round($progress)]);
    }
    
    sendJsonAndExit(['status' => 'error', 'msg' => 'Procesando...']);
}

// ==========================================
// 3. API: UPLOAD (Procesamiento)
// ==========================================
if ($action === 'upload') {
    // CHECK CRÍTICO: ¿Llegó vacío por límite de PHP?
    if (empty($_FILES) && empty($_POST) && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
        $maxSize = ini_get('post_max_size');
        sendJsonAndExit(['status'=>'error', 'msg'=>"El archivo es más grande que el límite del servidor ($maxSize). Revisa php.ini"]);
    }

    if (!$hasFfmpeg) sendJsonAndExit(['status'=>'error', 'msg'=>'FFmpeg no está instalado en el servidor.']);
    
    if (!isset($_FILES['videoFile']) || $_FILES['videoFile']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['videoFile']['error'] ?? 'No file';
        sendJsonAndExit(['status'=>'error', 'msg'=>"Error subiendo archivo (Código: $err)"]);
    }

    try {
        $jobId = uniqid('v65_');
        $ext = pathinfo($_FILES['videoFile']['name'], PATHINFO_EXTENSION);
        $inputFile = "$uploadDir/{$jobId}_in.$ext";
        $outputFileName = "{$jobId}_viral.mp4"; 
        $outputFile = "$processedDir/$outputFileName";
        $jobFile = "$jobsDir/$jobId.json";

        if (!move_uploaded_file($_FILES['videoFile']['tmp_name'], $inputFile)) {
            sendJsonAndExit(['status'=>'error', 'msg'=>'No se pudo mover el archivo subido. Permisos?']);
        }
        chmod($inputFile, 0777);

        // --- Obtener duración ---
        $durationCmd = "$ffmpegPath -i " . escapeshellarg($inputFile) . " 2>&1 | grep Duration";
        $durOut = shell_exec($durationCmd);
        preg_match('/Duration: (\d{2}):(\d{2}):(\d{2}\.\d{2})/', $durOut, $matches);
        $seconds = 60;
        if (!empty($matches)) {
            $seconds = ($matches[1] * 3600) + ($matches[2] * 60) + $matches[3];
        }

        // --- Preparar Filtros ---
        $useLogo = file_exists($logoPath);
        $useFont = file_exists($fontPath);
        $useAudio = file_exists($audioPath);
        $mirror = isset($_POST['mirrorMode']) && $_POST['mirrorMode'] === 'true';
        
        $title = mb_strtoupper($_POST['videoTitle'] ?? '');
        $wrapped = wordwrap($title, 18, "\n", true);
        $lines = explode("\n", $wrapped);
        if(count($lines)>2) { $lines=array_slice($lines,0,2); $lines[1]=substr($lines[1],0,15).".."; }

        // INPUTS
        $cmdIn = "-i " . escapeshellarg($inputFile);
        if ($useLogo) $cmdIn .= " -i " . escapeshellarg($logoPath);
        if ($useAudio) $cmdIn .= " -stream_loop -1 -i " . escapeshellarg($audioPath); // Loop optimizado

        // FILTER COMPLEX
        $cw=720; $ch=1280;
        $txtSize = (count($lines)==1) ? 80 : 70;
        $txtY = (count($lines)==1) ? [30] : [30, 110];
        $vidY = (count($lines)==1) ? 135 : 210;

        $fc = "";
        // Background negro
        $fc .= "color=c=#080808:s={$cw}x{$ch}:d=".($seconds+1)."[bg];";
        // Video procesado
        $hflip = $mirror ? ",hflip" : "";
        $fc .= "[0:v]scale={$cw}:-1,setsar=1{$hflip},eq=saturation=1.15:contrast=1.05,setpts=0.98*PTS[vid];";
        $fc .= "[bg][vid]overlay=0:{$vidY}:shortest=1[base];";
        $last = "[base]";

        // Texto
        if ($useFont && !empty($title)) {
            $fFile = str_replace('\\','/', realpath($fontPath));
            foreach($lines as $k => $l) {
                $l = str_replace("'", "\\'", $l);
                $y = $txtY[$k];
                $fc .= "{$last}drawtext=fontfile='$fFile':text='$l':fontcolor=#FFD700:fontsize={$txtSize}:borderw=4:bordercolor=black:x=(w-text_w)/2:y={$y}[t{$k}];";
                $last = "[t{$k}]";
            }
        }

        // Logo
        if ($useLogo) {
            $fc .= "[1:v]scale=-1:80[lg];{$last}[lg]overlay=30:H-120[vfin]";
            $last = "[vfin]";
        } else {
            $fc .= "{$last}copy[vfin]";
        }

        // Audio Mix (Voice + Loop Music)
        if ($useAudio) {
            $idx = $useLogo ? 2 : 1;
            // 'duration=first' corta el audio cuando termina el video (voz)
            $fc .= ";[0:a]volume=1.0[v];[{$idx}:a]volume=0.15[m];[v][m]amix=inputs=2:duration=first[afin]";
        } else {
            $fc .= ";[0:a]volume=1.0[afin]";
        }

        // Guardar estado
        file_put_contents($jobFile, json_encode([
            'status' => 'processing',
            'file' => $outputFileName,
            'start' => time(),
            'duration' => $seconds
        ]));

        // EJECUTAR EN SEGUNDO PLANO (NOHUP)
        $finalCmd = "nohup $ffmpegPath -y $cmdIn -filter_complex \"$fc\" -map \"[vfin]\" -map \"[afin]\" -c:v libx264 -preset ultrafast -threads 4 -crf 28 -c:a aac -b:a 128k -movflags +faststart -shortest " . escapeshellarg($outputFile) . " > $logFile 2>&1 &";
        
        exec($finalCmd);

        sendJsonAndExit(['status' => 'success', 'jobId' => $jobId]);

    } catch (Exception $e) {
        sendJsonAndExit(['status'=>'error', 'msg'=>$e->getMessage()]);
    }
}

// ==========================================
// 4. OTROS (Descarga/Borrar)
// ==========================================
if ($action === 'download' && isset($_GET['file'])) {
    $f = basename($_GET['file']);
    $p = "$processedDir/$f";
    if (file_exists($p)) {
        if(ob_get_length()) ob_clean();
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="VIDEO_'.date('md_Hi').'.mp4"');
        header('Content-Length: '.filesize($p));
        readfile($p);
        exit;
    }
}

if ($action === 'delete_job' && isset($_GET['file'])) {
    $f = basename($_GET['file']);
    @unlink("$processedDir/$f");
    header('Location: ?deleted');
    exit;
}

// Si llegamos aquí, NO ES UNA ACCIÓN DE API.
// Limpiamos el buffer para imprimir el HTML limpio.
if (ob_get_length()) ob_end_clean();
?>
