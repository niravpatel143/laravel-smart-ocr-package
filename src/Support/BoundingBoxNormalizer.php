<?php declare(strict_types=1);

namespace LaravelSmartOCR\Support;

class BoundingBoxNormalizer
{
    /**
     * Normalize Google Vision boundingPoly vertices into standard format.
     * Vertices: [['x'=>x,'y'=>y], ...]
     */
    public static function fromVertices(array $vertices): array
    {
        if (empty($vertices)) {
            return self::empty();
        }
        $xs   = array_column($vertices, 'x');
        $ys   = array_column($vertices, 'y');
        $minX = (int) min($xs);
        $minY = (int) min($ys);
        return [
            'x'      => $minX,
            'y'      => $minY,
            'width'  => (int) max($xs) - $minX,
            'height' => (int) max($ys) - $minY,
            'points' => array_map(fn($v) => ['x' => (int)($v['x'] ?? 0), 'y' => (int)($v['y'] ?? 0)], $vertices),
        ];
    }

    /**
     * Normalize AWS Textract Geometry.BoundingBox {Left,Top,Width,Height} fractions
     * relative to image dimensions.
     */
    public static function fromAwsFraction(array $box, int $pageWidth = 1000, int $pageHeight = 1000): array
    {
        $x = (int) round(($box['Left']   ?? 0) * $pageWidth);
        $y = (int) round(($box['Top']    ?? 0) * $pageHeight);
        $w = (int) round(($box['Width']  ?? 0) * $pageWidth);
        $h = (int) round(($box['Height'] ?? 0) * $pageHeight);
        return [
            'x'      => $x,
            'y'      => $y,
            'width'  => $w,
            'height' => $h,
            'points' => [
                ['x' => $x,     'y' => $y],
                ['x' => $x + $w,'y' => $y],
                ['x' => $x + $w,'y' => $y + $h],
                ['x' => $x,     'y' => $y + $h],
            ],
        ];
    }

    /**
     * Normalize Azure OCR boundingBox [x1,y1,x2,y2,x3,y3,x4,y4] or [x,y,w,h].
     */
    public static function fromAzureArray(array $box): array
    {
        if (count($box) === 8) {
            // polygon points: x1,y1,x2,y2,x3,y3,x4,y4
            $vertices = [];
            for ($i = 0; $i < 8; $i += 2) {
                $vertices[] = ['x' => (int)$box[$i], 'y' => (int)$box[$i + 1]];
            }
            return self::fromVertices($vertices);
        }
        if (count($box) === 4) {
            $x = (int)$box[0]; $y = (int)$box[1]; $w = (int)$box[2]; $h = (int)$box[3];
            return [
                'x'      => $x,
                'y'      => $y,
                'width'  => $w,
                'height' => $h,
                'points' => [
                    ['x' => $x,     'y' => $y],
                    ['x' => $x + $w,'y' => $y],
                    ['x' => $x + $w,'y' => $y + $h],
                    ['x' => $x,     'y' => $y + $h],
                ],
            ];
        }
        return self::empty();
    }

    public static function empty(): array
    {
        return ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0, 'points' => []];
    }
}
