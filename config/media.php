<?php

return [
    // Decode guard for GD image processing in the publish worker. Peak GD memory is
    // ~2 x (W x H x 4 bytes) because ->scale() clones the source canvas, so this is
    // calibrated to stay under a 256M worker memory_limit. Images over this are
    // shipped untouched by the compressor, rejected by the JPEG converter, or
    // refused at upload, rather than OOMing the worker.
    //
    // Validate the override to a positive integer: a zero/negative/non-numeric value
    // would make the two decode guards disagree (ImageCompressor would skip
    // compression while ImageToJpegConverter rejects every image), so an invalid
    // override falls back to the calibrated default rather than being trusted.
    'max_image_pixels' => filter_var(
        env('MEDIA_MAX_IMAGE_PIXELS'),
        FILTER_VALIDATE_INT,
        ['options' => ['default' => 16_000_000, 'min_range' => 1]],
    ),
];
