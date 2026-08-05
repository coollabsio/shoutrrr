<?php

return [
    // Decode guard for GD image processing in the publish worker. Peak GD memory is
    // ~2 x (W x H x 4 bytes) because ->scale() clones the source canvas, so this is
    // calibrated to stay under a 256M worker memory_limit. Images over this are
    // shipped untouched by the compressor, rejected by the JPEG converter, or
    // refused at upload, rather than OOMing the worker.
    'max_image_pixels' => (int) env('MEDIA_MAX_IMAGE_PIXELS', 16_000_000),
];
