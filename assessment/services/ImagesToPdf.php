<?php
declare(strict_types=1);

/**
 * Combines one or more photographed pages (JPEG/PNG) into a single PDF, so
 * a physical paper script - photographed page by page on a phone - can be
 * stored and marked exactly like any other PDF submission via
 * OneDriveService::uploadScannedScript(). Written from scratch using only
 * core PHP (GD + raw PDF object/xref construction), since this shared host
 * has no Imagick, FPDF, or Composer packages available.
 */
final class ImagesToPdf
{
    private const PAGE_WIDTH = 595.28;  // A4 in PDF points
    private const PAGE_HEIGHT = 841.89;
    private const MARGIN = 24.0;
    private const MAX_DIMENSION = 2000; // downscale phone photos before embedding
    private const JPEG_QUALITY = 85;

    /**
     * @param array<int, string> $imageBinaries raw JPEG/PNG bytes, one per page, in order
     */
    public static function build(array $imageBinaries): string
    {
        if (!$imageBinaries) {
            throw new InvalidArgumentException('At least one photo is required.');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('The GD extension is required to process photo uploads.');
        }

        $catalogId = 1;
        $pagesId = 2;
        $nextId = 3;
        $objects = [];
        $pageIds = [];

        foreach ($imageBinaries as $binary) {
            [$jpeg, $width, $height] = self::normalize($binary);

            $imageId = $nextId++;
            $contentId = $nextId++;
            $pageId = $nextId++;

            [$drawWidth, $drawHeight, $x, $y] = self::fitToPage($width, $height);
            $content = sprintf("q\n%.2F 0 0 %.2F %.2F %.2F cm\n/Im%d Do\nQ", $drawWidth, $drawHeight, $x, $y, $imageId);

            $objects[$imageId] = self::imageObject($imageId, $jpeg, $width, $height);
            $objects[$contentId] = self::streamObject($contentId, $content);
            $objects[$pageId] = self::pageObject($pageId, $pagesId, $contentId, $imageId);
            $pageIds[] = $pageId;
        }

        $objects[$pagesId] = self::pagesObject($pagesId, $pageIds);
        $objects[$catalogId] = "{$catalogId} 0 obj\n<< /Type /Catalog /Pages {$pagesId} 0 R >>\nendobj\n";

        return self::assemble($objects, $catalogId);
    }

    /**
     * Decodes, EXIF-rotates, flattens, downscales, and re-encodes a photo
     * as JPEG so every embedded page is upright and a sane file size
     * regardless of the source phone's camera output.
     *
     * @return array{0: string, 1: int, 2: int} [jpegBinary, width, height]
     */
    private static function normalize(string $binary): array
    {
        $orientation = self::readOrientation($binary);

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new InvalidArgumentException('One of the uploaded files is not a readable image.');
        }

        $image = self::applyOrientation($image, $orientation);
        $image = self::flattenToWhite($image);
        $image = self::downscale($image);

        ob_start();
        imagejpeg($image, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();
        $width = imagesx($image);
        $height = imagesy($image);
        imagedestroy($image);

        return [$jpeg, $width, $height];
    }

    private static function readOrientation(string $binary): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $binary);
        rewind($stream);
        $exif = @exif_read_data($stream, null, false);
        fclose($stream);
        return is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
    }

    /**
     * Only the three exact-multiple-of-90 rotations are handled - these
     * cover every non-mirrored phone camera orientation and, unlike
     * arbitrary angles, never leave gaps/corners to fill in.
     */
    private static function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 3:
                $rotated = imagerotate($image, 180, 0);
                break;
            case 6:
                $rotated = imagerotate($image, -90, 0);
                break;
            case 8:
                $rotated = imagerotate($image, 90, 0);
                break;
            default:
                return $image;
        }
        imagedestroy($image);
        return $rotated;
    }

    /** JPEG has no alpha channel - flatten any transparency (e.g. from a PNG upload) onto white first, or it renders black. */
    private static function flattenToWhite(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $flat = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefill($flat, 0, 0, $white);
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);
        return $flat;
    }

    private static function downscale(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest <= self::MAX_DIMENSION) {
            return $image;
        }
        $scale = self::MAX_DIMENSION / $longest;
        $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
        imagedestroy($image);
        return $resized;
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} [drawWidth, drawHeight, x, y] */
    private static function fitToPage(int $pxWidth, int $pxHeight): array
    {
        $availWidth = self::PAGE_WIDTH - 2 * self::MARGIN;
        $availHeight = self::PAGE_HEIGHT - 2 * self::MARGIN;
        $scale = min($availWidth / $pxWidth, $availHeight / $pxHeight);
        $drawWidth = $pxWidth * $scale;
        $drawHeight = $pxHeight * $scale;
        $x = (self::PAGE_WIDTH - $drawWidth) / 2;
        $y = (self::PAGE_HEIGHT - $drawHeight) / 2;
        return [$drawWidth, $drawHeight, $x, $y];
    }

    private static function imageObject(int $id, string $jpeg, int $width, int $height): string
    {
        $length = strlen($jpeg);
        return "{$id} 0 obj\n<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} "
            . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$length} >>\n"
            . "stream\n{$jpeg}\nendstream\nendobj\n";
    }

    private static function streamObject(int $id, string $content): string
    {
        $length = strlen($content);
        return "{$id} 0 obj\n<< /Length {$length} >>\nstream\n{$content}\nendstream\nendobj\n";
    }

    private static function pageObject(int $id, int $parentId, int $contentId, int $imageId): string
    {
        $w = self::PAGE_WIDTH;
        $h = self::PAGE_HEIGHT;
        return "{$id} 0 obj\n<< /Type /Page /Parent {$parentId} 0 R /MediaBox [0 0 {$w} {$h}] "
            . "/Resources << /XObject << /Im{$imageId} {$imageId} 0 R >> >> /Contents {$contentId} 0 R >>\nendobj\n";
    }

    private static function pagesObject(int $id, array $kids): string
    {
        $kidsStr = implode(' ', array_map(static fn($k) => "{$k} 0 R", $kids));
        return "{$id} 0 obj\n<< /Type /Pages /Kids [{$kidsStr}] /Count " . count($kids) . " >>\nendobj\n";
    }

    private static function assemble(array $objects, int $catalogId): string
    {
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $body;
        }

        $maxId = max(array_keys($objects));
        $xrefStart = strlen($pdf);
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= isset($offsets[$id]) ? sprintf("%010d 00000 n \n", $offsets[$id]) : "0000000000 00000 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root {$catalogId} 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";
        return $pdf;
    }
}
