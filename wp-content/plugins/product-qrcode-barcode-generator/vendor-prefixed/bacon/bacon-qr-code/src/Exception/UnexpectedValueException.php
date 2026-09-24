<?php

declare (strict_types=1);
namespace ProductQrBarcode\Vendor\BaconQrCode\Exception;

defined( 'ABSPATH' ) || exit;

final class UnexpectedValueException extends \UnexpectedValueException implements ExceptionInterface
{
}
