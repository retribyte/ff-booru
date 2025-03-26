<?php

declare(strict_types=1);

namespace Shimmie2;

final class Terms extends Extension
{
    public const KEY = "terms";
    /** @var TermsTheme */
    protected Themelet $theme;

    public function onPageRequest(PageRequestEvent $event): void
    {
        global $page;
        if ($event->page_starts_with("accept_terms")) {
            $page->add_cookie("accepted_terms", "true", time() + 60 * 60 * 24 * Ctx::$config->req_int(UserAccountsConfig::LOGIN_MEMORY), "/");
            $page->set_redirect(make_link(explode('/', $event->path, 2)[1]));
        } else {
            // run on all pages unless any of:
            // - user is logged in
            // - cookie exists
            // - user is viewing the wiki (because that's where the privacy policy / TOS / etc are)
            if (
                Ctx::$user->is_anonymous()
                && !$page->get_cookie('accepted_terms')
                && !$event->page_starts_with("wiki")
            ) {
                $sitename = Ctx::$config->req_string(SetupConfig::TITLE);
                $body = format_text(Ctx::$config->req_string(TermsConfig::MESSAGE));
                $this->theme->display_page($sitename, $event->path, $body);
            }
        }
    }
}
