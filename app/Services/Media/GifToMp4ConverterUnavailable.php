<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * The ffmpeg binary needed to convert GIFs to MP4 is not installed on the server.
 * A configuration problem, not a transient one — retrying can't fix it.
 */
final class GifToMp4ConverterUnavailable extends GifToMp4ConversionFailed {}
