<?php

declare (strict_types=1);
namespace ProductQrBarcode\Vendor\BaconQrCode\Exception;

defined( 'ABSPATH' ) || exit;

final class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface
{
}
