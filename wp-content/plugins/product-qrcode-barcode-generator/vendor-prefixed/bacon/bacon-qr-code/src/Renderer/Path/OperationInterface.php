<?php

declare (strict_types=1);
namespace ProductQrBarcode\Vendor\BaconQrCode\Renderer\Path;

defined( 'ABSPATH' ) || exit;

interface OperationInterface
{
    /**
     * Translates the operation's coordinates.
     */
    public function translate(float $x, float $y): self;
    /**
     * Rotates the operation's coordinates.
     */
    public function rotate(int $degrees): self;
}
