<?php

declare(strict_types=1);

namespace Shimmie2;

class ThumbnailUtil
{
    /**
     * Given a full size pair of dimensions, return a pair scaled down to fit
     * into the configured thumbnail square, with ratio intact.
     * Optionally uses the High-DPI scaling setting to adjust the final resolution.
     *
     * @param 0|positive-int $orig_width
     * @param 0|positive-int $orig_height
     * @param bool $use_dpi_scaling Enables the High-DPI scaling.
     * @return array{0: positive-int, 1: positive-int}
     */
    public static function get_thumbnail_size(int $orig_width, int $orig_height, bool $use_dpi_scaling = false): array
    {
        global $config;

        $fit = $config->get_string(ThumbnailConfig::FIT);

        if (in_array($fit, [
                Media::RESIZE_TYPE_FILL,
                Media::RESIZE_TYPE_STRETCH,
                Media::RESIZE_TYPE_FIT_BLUR,
                Media::RESIZE_TYPE_FIT_BLUR_PORTRAIT
            ])) {
            return [$config->get_int(ThumbnailConfig::WIDTH), $config->get_int(ThumbnailConfig::HEIGHT)];
        }

        if ($orig_width === 0) {
            $orig_width = 192;
        }
        if ($orig_height === 0) {
            $orig_height = 192;
        }

        if ($orig_width > $orig_height * 5) {
            $orig_width = $orig_height * 5;
        }
        if ($orig_height > $orig_width * 5) {
            $orig_height = $orig_width * 5;
        }


        if ($use_dpi_scaling) {
            list($max_width, $max_height) = self::get_thumbnail_max_size_scaled();
        } else {
            $max_width = $config->get_int(ThumbnailConfig::WIDTH);
            $max_height = $config->get_int(ThumbnailConfig::HEIGHT);
        }

        list($width, $height, $scale) = self::get_scaled_by_aspect_ratio($orig_width, $orig_height, $max_width, $max_height);

        if ($scale > 1 && $config->get_bool(ThumbnailConfig::UPSCALE)) {
            return [(int)$orig_width, (int)$orig_height];
        } else {
            return [$width, $height];
        }
    }

    /**
     * @param positive-int $original_width
     * @param positive-int $original_height
     * @param positive-int $max_width
     * @param positive-int $max_height
     * @return array{0: positive-int, 1: positive-int, 2: float}
     */
    public static function get_scaled_by_aspect_ratio(int $original_width, int $original_height, int $max_width, int $max_height): array
    {
        $xscale = ($max_width / $original_width);
        $yscale = ($max_height / $original_height);
        $scale = ($yscale < $xscale) ? $yscale : $xscale;
        assert($scale > 0);

        $new_width = (int)($original_width * $scale);
        $new_height = (int)($original_height * $scale);
        assert($new_width > 0);
        assert($new_height > 0);

        return [$new_width, $new_height, $scale];
    }

    /**
     * Fetches the thumbnails height and width settings and applies the High-DPI scaling setting before returning the dimensions.
     *
     * @return array{0: positive-int, 1: positive-int}
     */
    public static function get_thumbnail_max_size_scaled(): array
    {
        global $config;

        $scaling = $config->get_int(ThumbnailConfig::SCALING);
        $max_width  = $config->get_int(ThumbnailConfig::WIDTH) * ($scaling / 100);
        $max_height = $config->get_int(ThumbnailConfig::HEIGHT) * ($scaling / 100);
        assert($max_width > 0);
        assert($max_height > 0);
        return [$max_width, $max_height];
    }

    public static function create_image_thumb(Image $image, ?string $engine = null): void
    {
        global $config;
        self::create_scaled_image(
            $image->get_image_filename(),
            $image->get_thumb_filename(),
            self::get_thumbnail_max_size_scaled(),
            $image->get_mime(),
            $engine,
            $config->get_string(ThumbnailConfig::FIT)
        );
    }

    /**
     * @param array{0: positive-int, 1: positive-int} $tsize
     */
    public static function create_scaled_image(
        string $inname,
        string $outname,
        array $tsize,
        string $mime,
        ?string $engine = null,
        ?string $resize_type = null
    ): void {
        global $config;
        $engine ??= $config->get_string(ThumbnailConfig::ENGINE);
        $resize_type ??= $config->get_string(ThumbnailConfig::FIT);
        $output_mime = $config->get_string(ThumbnailConfig::MIME);

        send_event(new MediaResizeEvent(
            $engine,
            $inname,
            $mime,
            $outname,
            $tsize[0],
            $tsize[1],
            $resize_type,
            $output_mime,
            $config->get_string(ThumbnailConfig::ALPHA_COLOR),
            $config->get_int(ThumbnailConfig::QUALITY),
            true,
            true
        ));
    }
}
