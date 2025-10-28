<?php

# HANDLE IMAGES IN REPLIES

function scale_image_from_path($src_path, $dest_path, $scaleFactor) {
    $image_data = @file_get_contents($src_path);
    if ($image_data === false) {
        error_log("Failed to read image for scaling: $src_path");
        return false;
    }

    $source_img = @imagecreatefromstring($image_data);
    if (!$source_img) {
        error_log("Invalid image format: $src_path");
        return false;
    }

    $orig_width = imagesx($source_img);
    $orig_height = imagesy($source_img);

    $new_width = (int)($orig_width * $scaleFactor);
    $new_height = (int)($orig_height * $scaleFactor);

    $dest_img = imagecreatetruecolor($new_width, $new_height);
    imagecopyresampled($dest_img, $source_img, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);

    $success = imagepng($dest_img, $dest_path);
    imagedestroy($source_img);
    imagedestroy($dest_img);

    if (!$success) {
        error_log("Failed to save scaled image: $dest_path");
    }

    return $success;
}

/**
 * Convert a local image file into a data URL for inline storage.
 */
function local_image_to_data_url(string $image_path, ?string $mimeType = null): string
{
    if (!is_file($image_path)) {
        error_log("local_image_to_data_url: missing file $image_path");
        return '';
    }

    $mimeType = $mimeType ?: 'application/octet-stream';

    $binary = @file_get_contents($image_path);
    if ($binary === false) {
        error_log("local_image_to_data_url: unable to read $image_path");
        return '';
    }

    $encoded = base64_encode($binary);
    return 'data:' . $mimeType . ';base64,' . $encoded;
}

function parse_size_to_bytes($value): int
{
    if (is_int($value)) {
        return max(0, $value);
    }
    if (is_float($value)) {
        return max(0, (int)$value);
    }
    $raw = trim((string)$value);
    if ($raw === '') {
        return 0;
    }
    if (is_numeric($raw)) {
        return max(0, (int)$raw);
    }

    if (preg_match('/^(\d+)\s*([kmg]?)(b)?$/i', $raw, $matches)) {
        $number = (int)$matches[1];
        $unit = strtolower($matches[2] ?? '');
        switch ($unit) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }

    return 0;
}

function parse_config_bool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value !== 0;
    }
    $raw = strtolower(trim((string)$value));
    if ($raw === '') {
        return false;
    }
    return in_array($raw, ['1', 'y', 'yes', 'true', 'on'], true);
}

function get_image_processing_config(array $config): array
{
    $section = [];
    if (isset($config['images']) && is_array($config['images'])) {
        $section = $config['images'];
    } elseif (isset($config['image']) && is_array($config['image'])) {
        $section = $config['image'];
    }

    $defaults = [
        'max_width_px'   => 2048,
        'max_bytes'      => 1572864, // ≈1.5 MB
        'keep_original'  => false,
    ];

    $maxWidth = isset($section['max_width_px']) ? (int)$section['max_width_px'] : $defaults['max_width_px'];
    $maxWidth = $maxWidth < 0 ? 0 : $maxWidth;
    $maxBytes = isset($section['max_bytes']) ? parse_size_to_bytes($section['max_bytes']) : $defaults['max_bytes'];
    $keepOriginal = isset($section['keep_original']) ? parse_config_bool($section['keep_original']) : $defaults['keep_original'];

    return [
        'max_width_px'  => $maxWidth,
        'max_bytes'     => $maxBytes,
        'keep_original' => $keepOriginal,
    ];
}

function downscale_image_if_needed(string $inputPath, array $options): array
{
    $meta = [
        'downscaled'          => false,
        'width'               => null,
        'height'              => null,
        'bytes'               => null,
        'original_width'      => null,
        'original_height'     => null,
        'original_bytes'      => null,
        'keep_original'       => !empty($options['keep_original']),
        'original_copy_path'  => null,
        'error'               => null,
    ];

    if (!is_file($inputPath)) {
        $meta['error'] = 'Input image not found';
        return $meta;
    }

    $dimensions = @getimagesize($inputPath);
    if ($dimensions === false) {
        $meta['error'] = 'Unable to read image dimensions';
        return $meta;
    }

    $meta['original_width'] = (int)($dimensions[0] ?? 0);
    $meta['original_height'] = (int)($dimensions[1] ?? 0);
    $sizeBytes = @filesize($inputPath);
    if ($sizeBytes !== false) {
        $meta['original_bytes'] = (int)$sizeBytes;
    }

    $maxWidth = max(0, (int)($options['max_width_px'] ?? 0));
    $maxBytes = max(0, (int)($options['max_bytes'] ?? 0));

    $needsWidthDownscale = $maxWidth > 0 && $meta['original_width'] !== null && $meta['original_width'] > $maxWidth;
    $needsByteDownscale  = $maxBytes > 0 && $meta['original_bytes'] !== null && $meta['original_bytes'] > $maxBytes;

    if (!$needsWidthDownscale && !$needsByteDownscale) {
        $meta['width']  = $meta['original_width'];
        $meta['height'] = $meta['original_height'];
        $meta['bytes']  = $meta['original_bytes'];
        return $meta;
    }

    if (!empty($options['keep_original'])) {
        $storeDir = $options['original_store_dir'] ?? null;
        if ($storeDir) {
            if (!is_dir($storeDir)) {
                @mkdir($storeDir, 0775, true);
            }
            if (is_dir($storeDir) && is_writable($storeDir)) {
                $copyName = 'orig_' . uniqid('', true) . '_' . basename($inputPath);
                $copyPath = rtrim($storeDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $copyName;
                if (@copy($inputPath, $copyPath)) {
                    $meta['original_copy_path'] = $copyPath;
                } else {
                    error_log("downscale_image_if_needed: failed to copy original to {$copyPath}");
                }
            } else {
                error_log("downscale_image_if_needed: original_store_dir {$storeDir} not writable");
            }
        }
    }

    $minWidth = isset($options['min_width']) ? (int)$options['min_width'] : 96;
    $minWidth = max(16, $minWidth);
    $minWidth = min($minWidth, $meta['original_width']);

    $mime = isset($dimensions['mime']) ? strtolower($dimensions['mime']) : '';
    $sourceImage = create_image_resource_from_path($inputPath, $mime);
    if (!$sourceImage) {
        $meta['error'] = 'Unsupported image format for downsampling';
        return $meta;
    }

    $initialScale = 1.0;
    $scaleFactors = [];
    if ($maxWidth > 0 && $meta['original_width'] > $maxWidth) {
        $scaleFactors[] = $maxWidth / $meta['original_width'];
    }
    if ($maxBytes > 0 && $meta['original_bytes'] && $meta['original_bytes'] > $maxBytes) {
        $scaleFactors[] = sqrt($maxBytes / $meta['original_bytes']);
    }
    if (!empty($scaleFactors)) {
        $initialScale = min($scaleFactors);
    }
    $initialScale = max(0.01, min(1.0, $initialScale));

    $targetWidth = (int)round($meta['original_width'] * $initialScale);
    $targetWidth = max($minWidth, $targetWidth);
    $targetWidth = min($targetWidth, $meta['original_width']);

    $quality = determine_image_quality($mime);
    $qualityFloor = determine_image_quality_floor($mime, $quality);

    $attemptWidth = $targetWidth;
    $attemptQuality = $quality;
    $attempt = 0;
    $maxAttempts = 10;
    $tempPath = $inputPath . '.tmp';

    while ($attempt < $maxAttempts) {
        $attempt++;

        if ($attemptWidth >= $meta['original_width'] && $attempt > 1) {
            break;
        }
        if ($attemptWidth < $minWidth) {
            $attemptWidth = $minWidth;
        }

        $resized = resize_image_resource($sourceImage, $attemptWidth, $mime);
        if (!$resized) {
            break;
        }

        $saved = save_image_resource_to_path($resized, $tempPath, $mime, $attemptQuality);
        imagedestroy($resized);
        if (!$saved || !is_file($tempPath)) {
            break;
        }

        $resizedBytes = @filesize($tempPath);
        $resizedInfo = @getimagesize($tempPath);
        $resizedWidth = isset($resizedInfo[0]) ? (int)$resizedInfo[0] : $attemptWidth;
        $resizedHeight = isset($resizedInfo[1]) ? (int)$resizedInfo[1] : 0;

        $fitsBytes = ($maxBytes <= 0) || ($resizedBytes !== false && $resizedBytes <= $maxBytes);
        $fitsWidth = ($maxWidth <= 0) || ($resizedWidth <= $maxWidth);

        if ($fitsBytes && $fitsWidth) {
            if (!@rename($tempPath, $inputPath)) {
                @unlink($tempPath);
                break;
            }
            $meta['downscaled'] = ($resizedWidth < $meta['original_width']) || ($resizedBytes !== false && $resizedBytes < $meta['original_bytes']);
            $meta['width'] = $resizedWidth;
            $meta['height'] = $resizedHeight;
            $meta['bytes'] = $resizedBytes !== false ? (int)$resizedBytes : $meta['original_bytes'];
            imagedestroy($sourceImage);
            return $meta;
        }

        @unlink($tempPath);

        if ($maxBytes > 0 && $resizedBytes !== false && $resizedBytes > $maxBytes) {
            $attemptWidth = max($minWidth, (int)floor($resizedWidth * 0.85));
            if ($attemptQuality !== null && $attemptQuality > $qualityFloor) {
                $attemptQuality = max($qualityFloor, $attemptQuality - 8);
            }
            continue;
        }

        if ($maxWidth > 0 && $resizedWidth > $maxWidth) {
            $attemptWidth = max($minWidth, $attemptWidth - max(32, (int)floor($attemptWidth * 0.1)));
            continue;
        }

        break;
    }

    imagedestroy($sourceImage);
    if (is_file($tempPath)) {
        @unlink($tempPath);
    }

    if ($maxBytes > 0 && $meta['original_bytes'] && $meta['original_bytes'] > $maxBytes) {
        $meta['error'] = 'Unable to downscale within size limits';
    } else {
        $meta['error'] = 'Downscale skipped';
    }
    $meta['width'] = $meta['original_width'];
    $meta['height'] = $meta['original_height'];
    $meta['bytes'] = $meta['original_bytes'];

    return $meta;
}

function create_image_resource_from_path(string $path, string $mime)
{
    $mime = strtolower($mime);
    try {
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                return @imagecreatefromjpeg($path);
            case 'image/png':
                return @imagecreatefrompng($path);
            case 'image/gif':
                return @imagecreatefromgif($path);
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    return @imagecreatefromwebp($path);
                }
                break;
        }
        return @imagecreatefromstring(@file_get_contents($path));
    } catch (Throwable $e) {
        error_log('create_image_resource_from_path failed: ' . $e->getMessage());
        return false;
    }
}

function resize_image_resource($source, int $targetWidth, string $mime)
{
    if (!$source) {
        return false;
    }
    $origWidth = imagesx($source);
    $origHeight = imagesy($source);
    if ($origWidth <= 0 || $origHeight <= 0) {
        return false;
    }
    if ($targetWidth >= $origWidth) {
        $targetWidth = $origWidth;
    }
    $targetHeight = (int)max(1, round($origHeight * ($targetWidth / $origWidth)));

    $dest = imagecreatetruecolor($targetWidth, $targetHeight);
    if (in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefill($dest, 0, 0, $transparent);
    }

    if (!imagecopyresampled($dest, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $origWidth, $origHeight)) {
        imagedestroy($dest);
        return false;
    }

    return $dest;
}

function save_image_resource_to_path($resource, string $path, string $mime, ?int $quality = null): bool
{
    $mime = strtolower($mime);
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $quality = $quality !== null ? max(10, min(100, $quality)) : 85;
            return @imagejpeg($resource, $path, $quality);
        case 'image/png':
            $compression = $quality !== null ? max(0, min(9, (int)round((100 - $quality) / 10))) : 6;
            return @imagepng($resource, $path, $compression);
        case 'image/gif':
            return @imagegif($resource, $path);
        case 'image/webp':
            if (function_exists('imagewebp')) {
                $quality = $quality !== null ? max(10, min(100, $quality)) : 80;
                return @imagewebp($resource, $path, $quality);
            }
            break;
    }
    return @imagepng($resource, $path, 6);
}

function determine_image_quality(string $mime): ?int
{
    $mime = strtolower($mime);
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            return 88;
        case 'image/webp':
            return 82;
        case 'image/png':
            return 90;
        default:
            return null;
    }
}

function determine_image_quality_floor(string $mime, ?int $quality): ?int
{
    if ($quality === null) {
        return null;
    }
    $mime = strtolower($mime);
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            return 55;
        case 'image/webp':
            return 60;
        case 'image/png':
            return 70;
        default:
            return $quality;
    }
}
