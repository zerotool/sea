<?php

namespace Sea;

class GridService
{
    public static function buildGrid(): array
    {
        $grid = [];
        $rowLabels = Config::rowLabels();
        $cols = Config::gridCols();
        foreach ($rowLabels as $rowIdx => $label) {
            for ($col = 0; $col < $cols; $col++) {
                $center = HexMath::axialToPixel($col, $rowIdx);
                $grid[] = [
                    'q' => $col,
                    'r' => $rowIdx,
                    'label' => sprintf('%s-%d', $label, $col + 1),
                    'center' => $center,
                    'corners' => HexMath::hexCorners($center),
                ];
            }
        }
        return $grid;
    }

    public static function gridPayload(): array
    {
        $labels = [];
        $rowLabels = Config::rowLabels();
        $cols = Config::gridCols();
        foreach ($rowLabels as $label) {
            $labels[] = array_map(
                fn($col) => sprintf('%s-%d', $label, $col + 1),
                range(0, $cols - 1)
            );
        }

        return [
            'hexSize' => Config::hexSize(),
            'rows' => count(Config::rowLabels()),
            'cols' => Config::gridCols(),
            'labels' => $labels,
        ];
    }

    public static function findHexAt(array $hexes, float $x, float $y): ?array
    {
        [$q, $r] = HexMath::pixelToAxial($x, $y);
        [$rq, $rr] = HexMath::axialRound($q, $r);
        foreach ($hexes as $hex) {
            if ($hex['q'] === (int)$rq && $hex['r'] === (int)$rr && HexMath::pointInsideHex($hex, $x, $y)) {
                return $hex;
            }
        }
        return null;
    }
}
