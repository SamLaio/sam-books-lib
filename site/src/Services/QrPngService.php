<?php

namespace Calibre\Services;

final class QrPngService
{
    private const VERSION = 6;
    private const SIZE = 41;
    private const DATA_CODEWORDS = 136;
    private const BLOCK_DATA_CODEWORDS = 68;
    private const ECC_CODEWORDS = 18;

    /**
     * @return string PNG bytes
     */
    public function renderPng(string $text, int $scale = 6, int $quietZone = 4): string
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        if (count($bytes) > 134) {
            throw new \RuntimeException('Magic login URL is too long for the built-in QR generator.');
        }

        $matrix = $this->buildMatrix($this->buildCodewords($bytes));
        $moduleCount = self::SIZE + ($quietZone * 2);
        $imageSize = $moduleCount * $scale;
        $image = imagecreatetruecolor($imageSize, $imageSize);
        if ($image === false) {
            throw new \RuntimeException('Cannot create QR image.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 15, 23, 42);
        if ($white === false || $black === false) {
            imagedestroy($image);
            throw new \RuntimeException('Cannot allocate QR image colors.');
        }

        imagefilledrectangle($image, 0, 0, $imageSize, $imageSize, $white);
        for ($y = 0; $y < self::SIZE; $y++) {
            for ($x = 0; $x < self::SIZE; $x++) {
                if (!$matrix[$y][$x]) {
                    continue;
                }
                $left = ($x + $quietZone) * $scale;
                $top = ($y + $quietZone) * $scale;
                imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black);
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    /**
     * @param array<int,int> $bytes
     * @return array<int,int>
     */
    private function buildCodewords(array $bytes): array
    {
        $bits = [0, 1, 0, 0];
        $length = count($bytes);
        for ($i = 7; $i >= 0; $i--) {
            $bits[] = ($length >> $i) & 1;
        }
        foreach ($bytes as $byte) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($byte >> $i) & 1;
            }
        }

        $maxBits = self::DATA_CODEWORDS * 8;
        for ($i = 0, $limit = min(4, $maxBits - count($bits)); $i < $limit; $i++) {
            $bits[] = 0;
        }
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $data = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $value = 0;
            for ($j = 0; $j < 8; $j++) {
                $value = ($value << 1) | $bits[$i + $j];
            }
            $data[] = $value;
        }

        $pad = [0xec, 0x11];
        $padIndex = 0;
        while (count($data) < self::DATA_CODEWORDS) {
            $data[] = $pad[$padIndex % 2];
            $padIndex++;
        }

        $blocks = [
            array_slice($data, 0, self::BLOCK_DATA_CODEWORDS),
            array_slice($data, self::BLOCK_DATA_CODEWORDS, self::BLOCK_DATA_CODEWORDS),
        ];
        $eccBlocks = [
            $this->reedSolomonRemainder($blocks[0], self::ECC_CODEWORDS),
            $this->reedSolomonRemainder($blocks[1], self::ECC_CODEWORDS),
        ];

        $codewords = [];
        for ($i = 0; $i < self::BLOCK_DATA_CODEWORDS; $i++) {
            $codewords[] = $blocks[0][$i];
            $codewords[] = $blocks[1][$i];
        }
        for ($i = 0; $i < self::ECC_CODEWORDS; $i++) {
            $codewords[] = $eccBlocks[0][$i];
            $codewords[] = $eccBlocks[1][$i];
        }

        return $codewords;
    }

    /**
     * @param array<int,int> $codewords
     * @return array<int,array<int,bool>>
     */
    private function buildMatrix(array $codewords): array
    {
        $matrix = array_fill(0, self::SIZE, array_fill(0, self::SIZE, false));
        $reserved = array_fill(0, self::SIZE, array_fill(0, self::SIZE, false));

        $this->drawFinder($matrix, $reserved, 0, 0);
        $this->drawFinder($matrix, $reserved, self::SIZE - 7, 0);
        $this->drawFinder($matrix, $reserved, 0, self::SIZE - 7);
        $this->drawAlignment($matrix, $reserved, 34, 34);

        for ($i = 8; $i < self::SIZE - 8; $i++) {
            $matrix[6][$i] = $i % 2 === 0;
            $matrix[$i][6] = $i % 2 === 0;
            $reserved[6][$i] = true;
            $reserved[$i][6] = true;
        }

        $matrix[self::SIZE - 8][8] = true;
        $reserved[self::SIZE - 8][8] = true;
        $this->reserveFormatAreas($reserved);

        $bits = [];
        foreach ($codewords as $codeword) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = (($codeword >> $i) & 1) === 1;
            }
        }

        $bitIndex = 0;
        $upward = true;
        for ($right = self::SIZE - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right--;
            }
            for ($vertical = 0; $vertical < self::SIZE; $vertical++) {
                $y = $upward ? self::SIZE - 1 - $vertical : $vertical;
                for ($column = 0; $column < 2; $column++) {
                    $x = $right - $column;
                    if ($reserved[$y][$x]) {
                        continue;
                    }
                    $value = $bits[$bitIndex] ?? false;
                    $bitIndex++;
                    if ($this->mask($x, $y)) {
                        $value = !$value;
                    }
                    $matrix[$y][$x] = $value;
                }
            }
            $upward = !$upward;
        }

        $this->drawFormatBits($matrix, $reserved);

        return $matrix;
    }

    /**
     * @param array<int,array<int,bool>> $matrix
     * @param array<int,array<int,bool>> $reserved
     */
    private function drawFinder(array &$matrix, array &$reserved, int $x, int $y): void
    {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx < 0 || $yy < 0 || $xx >= self::SIZE || $yy >= self::SIZE) {
                    continue;
                }
                $reserved[$yy][$xx] = true;
                if ($dx < 0 || $dx > 6 || $dy < 0 || $dy > 6) {
                    $matrix[$yy][$xx] = false;
                    continue;
                }
                $matrix[$yy][$xx] = $dx === 0 || $dx === 6 || $dy === 0 || $dy === 6
                    || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4);
            }
        }
    }

    /**
     * @param array<int,array<int,bool>> $matrix
     * @param array<int,array<int,bool>> $reserved
     */
    private function drawAlignment(array &$matrix, array &$reserved, int $centerX, int $centerY): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $x = $centerX + $dx;
                $y = $centerY + $dy;
                $reserved[$y][$x] = true;
                $matrix[$y][$x] = max(abs($dx), abs($dy)) !== 1;
            }
        }
    }

    /**
     * @param array<int,array<int,bool>> $reserved
     */
    private function reserveFormatAreas(array &$reserved): void
    {
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][self::SIZE - 1 - $i] = true;
            $reserved[self::SIZE - 1 - $i][8] = true;
        }
    }

    /**
     * @param array<int,array<int,bool>> $matrix
     * @param array<int,array<int,bool>> $reserved
     */
    private function drawFormatBits(array &$matrix, array &$reserved): void
    {
        $format = $this->formatBits(1, 0);
        for ($i = 0; $i <= 5; $i++) {
            $matrix[8][$i] = (($format >> $i) & 1) === 1;
        }
        $matrix[8][7] = (($format >> 6) & 1) === 1;
        $matrix[8][8] = (($format >> 7) & 1) === 1;
        $matrix[7][8] = (($format >> 8) & 1) === 1;
        for ($i = 9; $i < 15; $i++) {
            $matrix[14 - $i][8] = (($format >> $i) & 1) === 1;
        }

        for ($i = 0; $i < 8; $i++) {
            $matrix[self::SIZE - 1 - $i][8] = (($format >> $i) & 1) === 1;
        }
        for ($i = 8; $i < 15; $i++) {
            $matrix[8][self::SIZE - 15 + $i] = (($format >> $i) & 1) === 1;
        }
    }

    private function formatBits(int $eccLevelBits, int $mask): int
    {
        $data = ($eccLevelBits << 3) | $mask;
        $value = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ((($value >> $i) & 1) !== 0) {
                $value ^= 0x537 << ($i - 10);
            }
        }

        return (($data << 10) | $value) ^ 0x5412;
    }

    private function mask(int $x, int $y): bool
    {
        return (($x + $y) % 2) === 0;
    }

    /**
     * @param array<int,int> $data
     * @return array<int,int>
     */
    private function reedSolomonRemainder(array $data, int $degree): array
    {
        $generator = $this->reedSolomonDivisor($degree);
        $result = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $result[0];
            array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $result[$i] ^= $this->gfMultiply($generator[$i], $factor);
            }
        }

        return $result;
    }

    /**
     * @return array<int,int>
     */
    private function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = $this->gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = $this->gfMultiply($root, 2);
        }

        return $result;
    }

    private function gfMultiply(int $x, int $y): int
    {
        $result = 0;
        while ($y > 0) {
            if (($y & 1) !== 0) {
                $result ^= $x;
            }
            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= 0x11d;
            }
            $y >>= 1;
        }

        return $result & 0xff;
    }
}
