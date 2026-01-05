<?php
// CONFIGURACIÓN
ini_set('display_errors', 1);
$uploadDir = 'uploads/';
$processedDir = 'processed/';
$message = '';
$videoData = null;

// Crear carpetas si no existen
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
if (!file_exists($processedDir)) mkdir($processedDir, 0777, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['videoFile'])) {
    $file = $_FILES['videoFile'];
    $filename = uniqid() . '_' . basename($file['name']);
    $targetPath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $originalHash = md5_file($targetPath);
        $outputFilename = 'clean_' . $filename;
        $outputPath = $processedDir . $outputFilename;
        
        // COMANDO SEGURO PARA TU VPS KVM4
        // nice -n 19: Prioridad baja (no bloquea el servidor)
        // -threads 2: Solo usa 2 de tus 4 núcleos
        $ffmpegCmd = "nice -n 19 ffmpeg -i " . escapeshellarg($targetPath) . " -threads 2 -filter_complex \"[0:v]split=2[bg][fg];[bg]scale=1080:1080:force_original_aspect_ratio=decrease,pad=1080:1080:(ow-iw)/2:(oh-ih)/2,setsar=1,boxblur=20:10[bg_blurred];[fg]scale=1080:1080:force_original_aspect_ratio=decrease[fg_scaled];[bg_blurred][fg_scaled]overlay=(W-w)/2:(H-h)/2[mixed];[mixed]setpts=0.94*PTS[v_final];[0:a]atempo=1.0638[a_final]\" -map \"[v_final]\" -map \"[a_final]\" -map_metadata -1 -c:v libx264 -preset ultrafast -crf 26 -c:a aac -b:a 128k " . escapeshellarg($outputPath) . " 2>&1";

        exec($ffmpegCmd, $output, $returnVar);
        
        if ($returnVar === 0) {
            $newHash = md5_file($outputPath);
            $videoData = [
                'original_name' => $file['name'],
                'original_hash' => $originalHash,
                'new_hash'      => $newHash,
                'download_url'  => $outputPath
            ];
            $message = "✅ Video procesado exitosamente.";
        } else {
            $message = "❌ Error procesando el video.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lavadora de Videos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="card shadow-sm">
        <div class="card-body text-center">
            <h3 class="mb-4">🎥 Lavadora de Hash (Anti-FB)</h3>
            
            <?php if ($message): ?>
                <div class="alert alert-info"><?= $message ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input class="form-control mb-3" type="file" name="videoFile" accept="video/*" required>
                <button type="submit" class="btn btn-primary w-100">Procesar Video</button>
            </form>

            <?php if ($videoData): ?>
            <div class="mt-4 text-start">
                <div class="alert alert-success">
                    <p><strong>Hash Viejo:</strong> <?= $videoData['original_hash'] ?></p>
                    <p><strong>Hash Nuevo:</strong> <?= $videoData['new_hash'] ?></p>
                    <a href="<?= $videoData['download_url'] ?>" class="btn btn-success w-100" download>⬇️ Descargar</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
