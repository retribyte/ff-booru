<?php

declare(strict_types=1);

namespace Shimmie2;

final class SiteDescriptionTest extends ShimmiePHPUnitTestCase
{
    public function testSiteDescription(): void
    {
        Ctx::$config->set_string(SiteDescriptionConfig::DESCRIPTION, "A Shimmie testbed");
        self::get_page("post/list");
        self::assertStringContainsString(
            "<meta name='description' content='A Shimmie testbed' />",
            (string)Ctx::$page->get_all_html_headers()
        );
    }

    public function testSiteKeywords(): void
    {
        Ctx::$config->set_string(SiteDescriptionConfig::KEYWORDS, "foo,bar,baz");
        self::get_page("post/list");
        self::assertStringContainsString(
            "<meta name='keywords' content='foo,bar,baz' />",
            (string)Ctx::$page->get_all_html_headers()
        );
    }
}
