<?php

namespace Sea;

class HexMath
{
    public static function axialToPixel(float $q, float $r): array
    {
        $x = Config::hexSize() * sqrt(3) * ($q + $r / 2.0);
        $y = Config::hexSize() * 1.5 * $r;
        return [$x, $y];
    }

    public static function pixelToAxial(float $x, float $y): array
    {
        $q = ((sqrt(3) / 3.0) * $x - (1.0 / 3.0) * $y) / Config::hexSize();
        $r = ((2.0 / 3.0) * $y) / Config::hexSize();
        return [$q, $r];
    }

    private static function axialToCube(float $q, float $r): array
    {
        $x = $q;
        $z = $r;
        $y = -$x - $z;
        return [$x, $y, $z];
    }

    private static function cubeToAxial(float $x, float $y, float $z): array
    {
        return [$x, $z];
    }

    private static function cubeRound(float $x, float $y, float $z): array
    {
        $rx = round($x);
        $ry = round($y);
        $rz = round($z);

        $xDiff = abs($rx - $x);
        $yDiff = abs($ry - $y);
        $zDiff = abs($rz - $z);

        if ($xDiff > $yDiff && $xDiff > $zDiff) {
            $rx = -$ry - $rz;
        } elseif ($yDiff > $zDiff) {
            $ry = -$rx - $rz;
        } else {
            $rz = -$rx - $ry;
        }

        return [$rx, $ry, $rz];
    }

    public static function axialRound(float $q, float $r): array
    {
        [$x, $y, $z] = self::axialToCube($q, $r);
        [$rx, $ry, $rz] = self::cubeRound($x, $y, $z);
        return self::cubeToAxial($rx, $ry, $rz);
    }

    public static function hexCorners(array $center): array
    {
        $corners = [];
        for ($i = 0; $i < 6; $i++) {
            $angle = deg2rad(60 * $i - 30);
            $corners[] = [
                $center[0] + Config::hexSize() * cos($angle),
                $center[1] + Config::hexSize() * sin($angle),
            ];
        }
        return $corners;
    }

    public static function pointInsideHex(array $hex, float $x, float $y): bool
    {
        $corners = $hex['corners'];
        $inside = false;
        $j = count($corners) - 1;
        for ($i = 0; $i < count($corners); $i++) {
            [$xi, $yi] = $corners[$i];
            [$xj, $yj] = $corners[$j];
            $intersects = (($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);
            if ($intersects) {
                $inside = !$inside;
            }
            $j = $i;
        }
        return $inside;
    }

    public static function axialDistance(float $q1, float $r1, float $q2, float $r2): int
    {
        [$x1, $y1, $z1] = self::axialToCube($q1, $r1);
        [$x2, $y2, $z2] = self::axialToCube($q2, $r2);
        return (int) max(abs($x1 - $x2), abs($y1 - $y2), abs($z1 - $z2));
    }

    public static function hexLine(float $q1, float $r1, float $q2, float $r2): array
    {
        $distance = self::axialDistance($q1, $r1, $q2, $r2);
        $distance = max($distance, 1);
        $results = [];
        [$x1, $y1, $z1] = self::axialToCube($q1, $r1);
        [$x2, $y2, $z2] = self::axialToCube($q2, $r2);
        for ($i = 0; $i <= $distance; $i++) {
            $t = $distance === 0 ? 0.0 : $i / $distance;
            [$lx, $ly, $lz] = self::cubeLerp([$x1, $y1, $z1], [$x2, $y2, $z2], $t);
            [$rq, $rr] = self::cubeToAxial(...self::cubeRound($lx, $ly, $lz));
            $results[] = ['q' => (int) $rq, 'r' => (int) $rr];
        }
        return $results;
    }

    private static function cubeLerp(array $a, array $b, float $t): array
    {
        return [
            $a[0] + ($b[0] - $a[0]) * $t,
            $a[1] + ($b[1] - $a[1]) * $t,
            $a[2] + ($b[2] - $a[2]) * $t,
        ];
    }
}
