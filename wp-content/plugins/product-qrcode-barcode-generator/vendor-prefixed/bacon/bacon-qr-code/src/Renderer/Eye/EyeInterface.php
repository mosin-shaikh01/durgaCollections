<?php

declare (strict_types=1);
namespace ProductQrBarcode\Vendor\BaconQrCode\Renderer\Eye;

defined( 'ABSPATH' ) || exit;

use ProductQrBarcode\Vendor\BaconQrCode\Renderer\Path\Path;
/**
 * Interface for describing the look of an eye.
 */
interface EyeInterface
{
    /**
     * Returns the path of the external eye element.
     *
     * The path origin point (0, 0) must be anchored at the middle of the path.
     */
    public function getExternalPath(): Path;
    /**
     * Returns the path of the internal eye element.
     *
     * The path origin point (0, 0) must be anchored at the middle of the path.
     */
    public function getInternalPath(): Path;
}
