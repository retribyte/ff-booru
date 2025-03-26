<?php

declare(strict_types=1);

namespace Shimmie2;

final class Biography extends Extension
{
    public const KEY = "biography";
    /** @var BiographyTheme */
    protected Themelet $theme;

    public function onUserPageBuilding(UserPageBuildingEvent $event): void
    {
        $duser = $event->display_user;
        $bio = $duser->get_config()->get_string("biography") ?? "";

        if (Ctx::$user->id == $duser->id || Ctx::$user->can(UserAccountsPermission::EDIT_USER_INFO)) {
            $this->theme->display_composer($duser, $bio);
        } else {
            $this->theme->display_biography($bio);
        }
    }

    public function onPageRequest(PageRequestEvent $event): void
    {
        global $page;
        if ($event->page_matches("user/{name}/biography", method: "POST")) {
            $duser = User::by_name($event->get_arg("name"));
            if (Ctx::$user->id == $duser->id || Ctx::$user->can(UserAccountsPermission::EDIT_USER_INFO)) {
                $bio = $event->req_POST('biography');
                Log::info("biography", "Set biography to $bio");
                $duser->get_config()->set_string("biography", $bio);
                $page->flash("Bio Updated");
                $page->set_redirect(Url::referer_or());
            } else {
                throw new PermissionDenied("You do not have permission to edit this user's biography");
            }
        }
    }
}
