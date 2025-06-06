<?php

declare(strict_types=1);

namespace Shimmie2;

use GQLA\{Field, Mutation, Type};

use function MicroHTML\{A, emptyHTML};

#[Type(name: "NumericScoreVote")]
final class NumericScoreVote
{
    public int $image_id;
    public int $user_id;

    #[Field]
    public int $score;

    #[Field]
    public function post(): Image
    {
        return Image::by_id_ex($this->image_id);
    }

    #[Field]
    public function user(): User
    {
        return User::by_id($this->user_id);
    }

    #[Field(extends: "Post")]
    public static function score(Image $post): int
    {
        global $database;
        if ($post['score'] ?? null) {
            return $post['score'];
        }
        return $database->get_one(
            "SELECT sum(score) FROM numeric_score_votes WHERE image_id=:image_id",
            ['image_id' => $post->id]
        ) ?? 0;
    }

    /**
     * @return NumericScoreVote[]
     */
    #[Field(extends: "Post", type: "[NumericScoreVote!]!")]
    public static function votes(Image $post): array
    {
        global $database;
        $rows = $database->get_all(
            "SELECT * FROM numeric_score_votes WHERE image_id=:image_id",
            ['image_id' => $post->id]
        );
        $votes = [];
        foreach ($rows as $row) {
            $nsv = new NumericScoreVote();
            $nsv->image_id = $row["image_id"];
            $nsv->user_id = $row["user_id"];
            $nsv->score = $row["score"];
            $votes[] = $nsv;
        }
        return $votes;
    }

    #[Field(extends: "Post", type: "Int!")]
    public static function my_vote(Image $post): int
    {
        return Ctx::$database->get_one(
            "SELECT score FROM numeric_score_votes WHERE image_id=:image_id AND user_id=:user_id",
            ['image_id' => $post->id, "user_id" => Ctx::$user->id]
        ) ?? 0;
    }

    #[Mutation]
    public static function create_vote(int $post_id, int $score): bool
    {
        if (Ctx::$user->can(NumericScorePermission::CREATE_VOTE)) {
            assert($score === 0 || $score === -1 || $score === 1);
            send_event(new NumericScoreSetEvent($post_id, Ctx::$user, $score));
            return true;
        }
        return false;
    }
}

final class NumericScoreSetEvent extends Event
{
    public function __construct(
        public int $image_id,
        public User $user,
        public int $score
    ) {
        parent::__construct();
    }
}

final class NumericScore extends Extension
{
    public const KEY = "numeric_score";
    /** @var NumericScoreTheme */
    protected Themelet $theme;

    public function onInitExt(InitExtEvent $event): void
    {
        Image::$prop_types["numeric_score"] = ImagePropType::INT;
    }

    public function onDisplayingImage(DisplayingImageEvent $event): void
    {
        if (Ctx::$user->can(NumericScorePermission::CREATE_VOTE)) {
            $this->theme->get_voter($event->image);
        }
    }

    public function onUserPageBuilding(UserPageBuildingEvent $event): void
    {
        if (Ctx::$user->can(NumericScorePermission::EDIT_OTHER_VOTE)) {
            $this->theme->get_nuller($event->display_user);
        }

        $n_up = Search::count_images(["upvoted_by={$event->display_user->name}"]);
        $link_up = search_link(["upvoted_by={$event->display_user->name}"]);
        $n_down = Search::count_images(["downvoted_by={$event->display_user->name}"]);
        $link_down = search_link(["downvoted_by={$event->display_user->name}"]);
        $event->add_part(emptyHTML(
            A(["href" => $link_up], "$n_up Upvotes"),
            " / ",
            A(["href" => $link_down], "$n_down Downvotes"),
        ));
    }

    public function onPageRequest(PageRequestEvent $event): void
    {
        global $database;
        $user = Ctx::$user;
        $page = Ctx::$page;

        if ($event->page_matches("numeric_score/votes/{image_id}")) {
            $image_id = $event->get_iarg('image_id');
            $x = $database->get_all(
                "SELECT users.name as username, user_id, score
				FROM numeric_score_votes
				JOIN users ON numeric_score_votes.user_id=users.id
				WHERE image_id=:image_id",
                ['image_id' => $image_id]
            );
            $html = "<table style='width: 100%;'>";
            foreach ($x as $vote) {
                $html .= "<tr><td>";
                $html .= "<a href='".make_link("user/{$vote['username']}")."'>{$vote['username']}</a>";
                $html .= "</td><td width='10'>";
                $html .= $vote['score'];
                $html .= "</td></tr>";
            }
            $page->set_data(MimeType::HTML, $html);
        } elseif ($event->page_matches("numeric_score/vote", method: "POST", permission: NumericScorePermission::CREATE_VOTE)) {
            $image_id = int_escape($event->POST->req("image_id"));
            $score = int_escape($event->POST->req("vote"));
            if (($score === -1 || $score === 0 || $score === 1) && $image_id > 0) {
                send_event(new NumericScoreSetEvent($image_id, $user, $score));
            }
            $page->set_redirect(make_link("post/view/$image_id"));
        } elseif ($event->page_matches("numeric_score/remove_votes_on", method: "POST", permission: NumericScorePermission::EDIT_OTHER_VOTE)) {
            $image_id = int_escape($event->POST->req("image_id"));
            $database->execute(
                "DELETE FROM numeric_score_votes WHERE image_id=:image_id",
                ['image_id' => $image_id]
            );
            $database->execute(
                "UPDATE images SET numeric_score=0 WHERE id=:id",
                ['id' => $image_id]
            );
            $page->set_redirect(make_link("post/view/$image_id"));
        } elseif ($event->page_matches("numeric_score/remove_votes_by", method: "POST", permission: NumericScorePermission::EDIT_OTHER_VOTE)) {
            $this->delete_votes_by(int_escape($event->POST->req('user_id')));
            $page->set_redirect(make_link());
        } elseif ($event->page_matches("popular_by_day") || $event->page_matches("popular_by_month") || $event->page_matches("popular_by_year")) {
            //FIXME: popular_by isn't linked from anywhere
            list($day, $month, $year) = [date("d"), date("m"), date("Y")];

            if ($event->GET->get('day')) {
                $D = (int) $event->GET->get('day');
                $day = clamp($D, 1, 31);
            }
            if ($event->GET->get('month')) {
                $M = (int) $event->GET->get('month');
                $month = clamp($M, 1, 12);
            }
            if ($event->GET->get('year')) {
                $Y = (int) $event->GET->get('year');
                $year = clamp($Y, 1970, 2100);
            }

            $totaldate = $year."/".$month."/".$day;

            if ($database->get_driver_id() === DatabaseDriverID::SQLITE) {
                $sql = "SELECT id FROM images WHERE strftime('%Y', posted) = cast(:year as text)";
                $month = str_pad(strval($month), 2, "0", STR_PAD_LEFT);
                $day = str_pad(strval($day), 2, "0", STR_PAD_LEFT);
            } else {
                $sql = "SELECT id FROM images WHERE EXTRACT(YEAR FROM posted) = :year";
            }
            $args = ["limit" => Ctx::$config->get(IndexConfig::IMAGES), "year" => $year];

            if ($event->page_matches("popular_by_day")) {
                if ($database->get_driver_id() === DatabaseDriverID::SQLITE) {
                    $sql .= " AND strftime('%m', posted) = cast(:month as text) AND strftime('%d', posted) = cast(:day as text)";
                } else {
                    $sql .= " AND EXTRACT(MONTH FROM posted) = :month AND EXTRACT(DAY FROM posted) = :day";
                }
                $args = array_merge($args, ["month" => $month, "day" => $day]);

                $current = date("F jS, Y", \Safe\strtotime($totaldate));
                $before = \Safe\strtotime("-1 day", \Safe\strtotime($totaldate));
                $after = \Safe\strtotime("+1 day", \Safe\strtotime($totaldate));
                $b_dte = make_link("popular_by_day", ["year" => date("Y", $before), "month" => date("m", $before), "day" => date("d", $before)]);
                $f_dte = make_link("popular_by_day", ["year" => date("Y", $after), "month" => date("m", $after), "day" => date("d", $after)]);
            } elseif ($event->page_matches("popular_by_month")) {
                if ($database->get_driver_id() === DatabaseDriverID::SQLITE) {
                    $sql .=	" AND strftime('%m', posted) = cast(:month as text)";
                } else {
                    $sql .=	" AND EXTRACT(MONTH FROM posted) = :month";
                }
                $args = array_merge($args, ["month" => $month]);
                // PHP's -1 month and +1 month functionality break when modifying dates that are on the 31st of the month.
                // See Example #3 on https://www.php.net/manual/en/datetime.modify.php
                // To get around this, set the day to 1 when doing month work.
                $totaldate = $year."/".$month."/01";

                $current = date("F Y", \Safe\strtotime($totaldate));
                $before = \Safe\strtotime("-1 month", \Safe\strtotime($totaldate));
                $after = \Safe\strtotime("+1 month", \Safe\strtotime($totaldate));
                $b_dte = make_link("popular_by_month", ["year" => date("Y", $before), "month" => date("m", $before)]);
                $f_dte = make_link("popular_by_month", ["year" => date("Y", $after), "month" => date("m", $after)]);
            } elseif ($event->page_matches("popular_by_year")) {
                $current = "$year";
                $before = \Safe\strtotime("-1 year", \Safe\strtotime($totaldate));
                $after = \Safe\strtotime("+1 year", \Safe\strtotime($totaldate));
                $b_dte = make_link("popular_by_year", ["year" => date("Y", $before)]);
                $f_dte = make_link("popular_by_year", ["year" => date("Y", $after)]);
            } else {
                // this should never happen due to the fact that the page event is already matched against earlier.
                throw new \UnexpectedValueException("Error: Invalid page event.");
            }
            $sql .= " AND NOT numeric_score=0 ORDER BY numeric_score DESC LIMIT :limit OFFSET 0";

            //filter images by score != 0 + date > limit to max images on one page > order from highest to lowest score
            $ids = $database->get_col($sql, $args);
            $images = Search::get_images($ids);
            $this->theme->view_popular($images, $current, $b_dte, $f_dte);
        }
    }

    public function onNumericScoreSet(NumericScoreSetEvent $event): void
    {
        Log::debug("numeric_score", "Rated >>{$event->image_id} as {$event->score}", "Rated Post");
        $this->add_vote($event->image_id, Ctx::$user->id, $event->score);
    }

    public function onImageDeletion(ImageDeletionEvent $event): void
    {
        Ctx::$database->execute("DELETE FROM numeric_score_votes WHERE image_id=:id", ["id" => $event->image->id]);
    }

    public function onUserDeletion(UserDeletionEvent $event): void
    {
        $this->delete_votes_by($event->id);
    }

    public function delete_votes_by(int $user_id): void
    {
        $image_ids = Ctx::$database->get_col("SELECT image_id FROM numeric_score_votes WHERE user_id=:user_id", ['user_id' => $user_id]);

        if (count($image_ids) === 0) {
            return;
        }

        // vote recounting is pretty heavy, and often hits statement timeouts
        // if you try to recount all the images in one go
        Ctx::$event_bus->set_timeout(null);
        foreach (array_chunk($image_ids, 100) as $chunk) {
            $id_list = implode(",", $chunk);
            Ctx::$database->execute(
                // @phpstan-ignore-next-line
                "DELETE FROM numeric_score_votes WHERE user_id=:user_id AND image_id IN (".$id_list.")",
                ['user_id' => $user_id]
            );
            // @phpstan-ignore-next-line
            Ctx::$database->execute("
				UPDATE images
				SET numeric_score=COALESCE(
					(
						SELECT SUM(score)
						FROM numeric_score_votes
						WHERE image_id=images.id
					),
					0
				)
				WHERE images.id IN (".$id_list.")");
        }
    }

    public function onParseLinkTemplate(ParseLinkTemplateEvent $event): void
    {
        $event->replace('$score', (string)$event->image['numeric_score']);
    }

    public function onHelpPageBuilding(HelpPageBuildingEvent $event): void
    {
        if ($event->key === HelpPages::SEARCH) {
            $event->add_section("Numeric Score", $this->theme->get_help_html());
        }
    }

    public function onSearchTermParse(SearchTermParseEvent $event): void
    {
        if ($matches = $event->matches("/^score([:]?<|[:]?>|[:]?<=|[:]?>=|[:|=])(-?\d+)$/i")) {
            $cmp = ltrim($matches[1], ":") ?: "=";
            $score = $matches[2];
            $event->add_querylet(new Querylet("numeric_score $cmp $score"));
        } elseif ($matches = $event->matches("/^upvoted_by[=|:](.*)$/i")) {
            $duser = User::by_name($matches[1]);
            $event->add_querylet(new Querylet(
                "images.id in (SELECT image_id FROM numeric_score_votes WHERE user_id=:ns_user_id AND score=1)",
                ["ns_user_id" => $duser->id]
            ));
        } elseif ($matches = $event->matches("/^downvoted_by[=|:](.*)$/i")) {
            $duser = User::by_name($matches[1]);
            $event->add_querylet(new Querylet(
                "images.id in (SELECT image_id FROM numeric_score_votes WHERE user_id=:ns_user_id AND score=-1)",
                ["ns_user_id" => $duser->id]
            ));
        } elseif ($matches = $event->matches("/^upvoted_by_id[=|:](\d+)$/i")) {
            $iid = int_escape($matches[1]);
            $event->add_querylet(new Querylet(
                "images.id in (SELECT image_id FROM numeric_score_votes WHERE user_id=:ns_user_id AND score=1)",
                ["ns_user_id" => $iid]
            ));
        } elseif ($matches = $event->matches("/^downvoted_by_id[=|:](\d+)$/i")) {
            $iid = int_escape($matches[1]);
            $event->add_querylet(new Querylet(
                "images.id in (SELECT image_id FROM numeric_score_votes WHERE user_id=:ns_user_id AND score=-1)",
                ["ns_user_id" => $iid]
            ));
        } elseif ($matches = $event->matches("/^order[=|:](?:numeric_)?(score)(?:_(desc|asc))?$/i")) {
            $default_order_for_column = "DESC";
            $sort = isset($matches[2]) ? strtoupper($matches[2]) : $default_order_for_column;
            $event->order = "images.numeric_score $sort";
        }
    }

    public function onTagTermCheck(TagTermCheckEvent $event): void
    {
        if ($event->matches("/^vote[=|:](up|down|remove)$/i")) {
            $event->metatag = true;
        }
    }

    public function onTagTermParse(TagTermParseEvent $event): void
    {
        if ($matches = $event->matches("/^vote[=|:](up|down|remove)$/")) {
            $score = ($matches[1] === "up" ? 1 : ($matches[1] === "down" ? -1 : 0));
            if (Ctx::$user->can(NumericScorePermission::CREATE_VOTE)) {
                send_event(new NumericScoreSetEvent($event->image_id, Ctx::$user, $score));
            }
        }
    }

    public function onPageSubNavBuilding(PageSubNavBuildingEvent $event): void
    {
        if ($event->parent === "posts") {
            $event->add_nav_link(make_link('popular_by_day'), "Popular by Day");
            $event->add_nav_link(make_link('popular_by_month'), "Popular by Month");
            $event->add_nav_link(make_link('popular_by_year'), "Popular by Year");
        }
    }

    public function onDatabaseUpgrade(DatabaseUpgradeEvent $event): void
    {
        global $database;

        if ($this->get_version() < 1) {
            $database->execute("ALTER TABLE images ADD COLUMN numeric_score INTEGER NOT NULL DEFAULT 0");
            $database->execute("CREATE INDEX images__numeric_score ON images(numeric_score)");
            $database->create_table("numeric_score_votes", "
				image_id INTEGER NOT NULL,
				user_id INTEGER NOT NULL,
				score INTEGER NOT NULL,
				UNIQUE(image_id, user_id),
				FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
				FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
			");
            $database->execute("CREATE INDEX numeric_score_votes_image_id_idx ON numeric_score_votes(image_id)", []);
            $this->set_version(1);
        }
        if ($this->get_version() < 2) {
            $database->execute("CREATE INDEX numeric_score_votes__user_votes ON numeric_score_votes(user_id, score)");
            $this->set_version(2);
        }
    }

    private function add_vote(int $image_id, int $user_id, int $score): void
    {
        global $database;
        $database->execute(
            "DELETE FROM numeric_score_votes WHERE image_id=:imageid AND user_id=:userid",
            ["imageid" => $image_id, "userid" => $user_id]
        );
        if ($score !== 0) {
            $database->execute(
                "INSERT INTO numeric_score_votes(image_id, user_id, score) VALUES(:imageid, :userid, :score)",
                ["imageid" => $image_id, "userid" => $user_id, "score" => $score]
            );
        }
        $database->execute(
            "UPDATE images SET numeric_score=(
				COALESCE(
					(SELECT SUM(score) FROM numeric_score_votes WHERE image_id=:imageid),
					0
				)
			) WHERE id=:id",
            ["imageid" => $image_id, "id" => $image_id]
        );
    }
}
