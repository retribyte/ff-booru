<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\IMG;

class SVGFileHandlerTheme extends Themelet
{
    public function display_image(Image $image): void
    {
        $html = IMG([
            'alt' => 'main image',
            'src' => $image->get_image_link(),
            'id' => 'main_image',
            'class' => 'shm-main-image',
            'data-width' => $image->width,
            'data-height' => $image->height,
        ]);
        Ctx::$page->add_block(new Block(null, $html, "main", 10));
    }
}
