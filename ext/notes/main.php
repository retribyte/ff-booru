<?php

declare(strict_types=1);

namespace Shimmie2;

/**
 * @phpstan-type NoteHistory array{image_id:int,note_id:int,review_id:int,user_name:string,note:string,date:string}
 * @phpstan-type Note array{id:int,x1:int,y1:int,height:int,width:int,note:string}
 */
final class Notes extends Extension
{
    public const KEY = "notes";
    /** @var NotesTheme */
    protected Themelet $theme;

    public function onInitExt(InitExtEvent $event): void
    {
        Image::$prop_types["notes"] = ImagePropType::INT;
    }

    public function onDatabaseUpgrade(DatabaseUpgradeEvent $event): void
    {
        $database = Ctx::$database;

        // shortcut to latest
        if ($this->get_version() < 1) {
            $database->execute("ALTER TABLE images ADD COLUMN notes INTEGER NOT NULL DEFAULT 0");
            $database->create_table("notes", "
					id SCORE_AIPK,
					enable INTEGER NOT NULL,
					image_id INTEGER NOT NULL,
					user_id INTEGER NOT NULL,
					user_ip CHAR(15) NOT NULL,
					date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
					x1 INTEGER NOT NULL,
					y1 INTEGER NOT NULL,
					height INTEGER NOT NULL,
					width INTEGER NOT NULL,
					note TEXT NOT NULL,
					FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
					FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
					");
            $database->execute("CREATE INDEX notes_image_id_idx ON notes(image_id)", []);

            $database->create_table("note_request", "
					id SCORE_AIPK,
					image_id INTEGER NOT NULL,
					user_id INTEGER NOT NULL,
					date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
					FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
					FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
					");
            $database->execute("CREATE INDEX note_request_image_id_idx ON note_request(image_id)", []);

            $database->create_table("note_histories", "
					id SCORE_AIPK,
					note_enable INTEGER NOT NULL,
					note_id INTEGER NOT NULL,
					review_id INTEGER NOT NULL,
					image_id INTEGER NOT NULL,
					user_id INTEGER NOT NULL,
					user_ip CHAR(15) NOT NULL,
					date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
					x1 INTEGER NOT NULL,
					y1 INTEGER NOT NULL,
					height INTEGER NOT NULL,
					width INTEGER NOT NULL,
					note TEXT NOT NULL,
					FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
					FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
					");
            $database->execute("CREATE INDEX note_histories_image_id_idx ON note_histories(image_id)", []);

            $this->set_version(1);
        }
    }

    public function onPageNavBuilding(PageNavBuildingEvent $event): void
    {
        $event->add_nav_link(make_link('note/requests'), "Notes", category: "note");
    }

    public function onPageSubNavBuilding(PageSubNavBuildingEvent $event): void
    {
        if ($event->parent === "note") {
            $event->add_nav_link(make_link('note/requests'), "Requests");
            $event->add_nav_link(make_link('note/list'), "List");
            $event->add_nav_link(make_link('note/updated'), "Updates");
            $event->add_nav_link(make_link('ext_doc/notes'), "Help");
        }
    }

    public function onPageRequest(PageRequestEvent $event): void
    {
        $page = Ctx::$page;
        if ($event->page_matches("note/list", paged: true)) {
            $this->get_notes_list($event->get_iarg('page_num', 1) - 1); // This should show images like post/list but i don't know how do that.
        }
        if ($event->page_matches("note/requests", paged: true)) {
            $this->get_notes_requests($event->get_iarg('page_num', 1) - 1); // This should show images like post/list but i don't know how do that.
        }
        if ($event->page_matches("note/updated", paged: true)) {
            $this->get_histories($event->get_iarg('page_num', 1) - 1);
        }
        if ($event->page_matches("note/history/{note_id}", paged: true)) {
            $this->get_history($event->get_iarg('note_id'), $event->get_iarg('page_num', 1) - 1);
        }
        if ($event->page_matches("note_history/{image_id}", paged: true)) {
            $this->get_image_history($event->get_iarg('image_id'), $event->get_iarg('page_num', 1) - 1);
        }
        if ($event->page_matches("note/revert/{noteID}/{reviewID}", permission: NotesPermission::EDIT)) {
            $noteID = $event->get_iarg('noteID');
            $reviewID = $event->get_iarg('reviewID');
            $this->revert_history($noteID, $reviewID);
            $page->set_redirect(make_link("note/updated"));
        }
        if ($event->page_matches("note/add_request", permission: NotesPermission::REQUEST)) {
            $image_id = int_escape($event->POST->req("image_id"));
            $this->add_note_request($image_id);
            $page->set_redirect(make_link("post/view/$image_id"));
        }
        if ($event->page_matches("note/nuke_requests", permission: NotesPermission::ADMIN)) {
            $image_id = int_escape($event->POST->req("image_id"));
            $this->nuke_requests($image_id);
            $page->set_redirect(make_link("post/view/$image_id"));
        }
        if ($event->page_matches("note/create_note", permission: NotesPermission::CREATE)) {
            $note_id = $this->add_new_note();
            $page->set_data(MimeType::JSON, \Safe\json_encode([
                'status' => 'success',
                'note_id' => $note_id,
            ]));
        }
        if ($event->page_matches("note/update_note", permission: NotesPermission::EDIT)) {
            $this->update_note();
            $page->set_data(MimeType::JSON, \Safe\json_encode(['status' => 'success']));
        }
        if ($event->page_matches("note/delete_note", permission: NotesPermission::ADMIN)) {
            $this->delete_note();
            $page->set_data(MimeType::JSON, \Safe\json_encode(['status' => 'success']));
        }
        if ($event->page_matches("note/nuke_notes", permission: NotesPermission::ADMIN)) {
            $image_id = int_escape($event->POST->req("image_id"));
            $this->nuke_notes($image_id);
            $page->set_redirect(make_link("post/view/$image_id"));
        }
    }

    public function onRobotsBuilding(RobotsBuildingEvent $event): void
    {
        $event->add_disallow("note_history");
    }

    public function onDisplayingImage(DisplayingImageEvent $event): void
    {
        $this->theme->display_note_system(
            $event->image->id,
            $this->get_notes($event->image->id),
            Ctx::$user->can(NotesPermission::ADMIN),
            Ctx::$user->can(NotesPermission::EDIT)
        );
    }

    public function onImageAdminBlockBuilding(ImageAdminBlockBuildingEvent $event): void
    {
        if (Ctx::$user->can(NotesPermission::CREATE)) {
            $event->add_part($this->theme->note_button($event->image->id));

            if (Ctx::$user->can(NotesPermission::ADMIN)) {
                $event->add_part($this->theme->nuke_notes_button($event->image->id));
                $event->add_part($this->theme->nuke_requests_button($event->image->id));
            }
        }
        if (Ctx::$user->can(NotesPermission::REQUEST)) {
            $event->add_part($this->theme->request_button($event->image->id));
        }

        $event->add_button("View Note History", "note_history/{$event->image->id}", 20);
    }

    public function onSearchTermParse(SearchTermParseEvent $event): void
    {
        if ($matches = $event->matches("/^note[=|:](.*)$/i")) {
            $notes = int_escape($matches[1]);
            $event->add_querylet(new Querylet("images.id IN (SELECT image_id FROM notes WHERE note = $notes)"));
        } elseif ($matches = $event->matches("/^notes([:]?<|[:]?>|[:]?<=|[:]?>=|[:|=])(\d+)%/i")) {
            $cmp = ltrim($matches[1], ":") ?: "=";
            $notes = $matches[2];
            $event->add_querylet(new Querylet("images.id IN (SELECT id FROM images WHERE notes $cmp $notes)"));
        } elseif ($matches = $event->matches("/^notes_by[=|:](.*)$/i")) {
            $user_id = User::name_to_id($matches[1]);
            $event->add_querylet(new Querylet("images.id IN (SELECT image_id FROM notes WHERE user_id = $user_id)"));
        } elseif ($matches = $event->matches("/^(notes_by_userno|notes_by_user_id)[=|:](\d+)$/i")) {
            $user_id = int_escape($matches[2]);
            $event->add_querylet(new Querylet("images.id IN (SELECT image_id FROM notes WHERE user_id = $user_id)"));
        }
    }

    public function onHelpPageBuilding(HelpPageBuildingEvent $event): void
    {
        if ($event->key === HelpPages::SEARCH) {
            $event->add_section("Notes", $this->theme->get_help_html());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function get_notes(int $imageID): array
    {
        return Ctx::$database->get_all("
            SELECT *
            FROM notes
            WHERE enable = :enable AND image_id = :image_id
            ORDER BY date ASC, id ASC
        ", ['enable' => '1', 'image_id' => $imageID]);
    }

    private function add_new_note(): int
    {
        global $database;

        $note = \Safe\json_decode(\Safe\file_get_contents('php://input'), true);

        $database->execute(
            "
				INSERT INTO notes (enable, image_id, user_id, user_ip, date, x1, y1, height, width, note)
				VALUES (:enable, :image_id, :user_id, :user_ip, now(), :x1, :y1, :height, :width, :note)",
            [
                'enable' => 1,
                'image_id' => $note['image_id'],
                'user_id' => Ctx::$user->id,
                'user_ip' => Network::get_real_ip(),
                'x1' => $note['x1'],
                'y1' => $note['y1'],
                'height' => $note['height'],
                'width' => $note['width'],
                'note' => $note['note'],
            ]
        );

        $noteID = $database->get_last_insert_id('notes_id_seq');

        Log::info("notes", "Note added {$noteID} by " . Ctx::$user->name);

        $database->execute("UPDATE images SET notes=(SELECT COUNT(*) FROM notes WHERE image_id=:id) WHERE id=:id", ['id' => $note['image_id']]);

        $this->add_history(
            1,
            $noteID,
            $note['image_id'],
            $note['x1'],
            $note['y1'],
            $note['height'],
            $note['width'],
            $note['note']
        );

        return $noteID;
    }

    private function add_note_request(int $image_id): void
    {
        Ctx::$database->execute(
            "
				INSERT INTO note_request (image_id, user_id, date)
				VALUES (:image_id, :user_id, now())",
            ['image_id' => $image_id, 'user_id' => Ctx::$user->id]
        );

        $resultID = Ctx::$database->get_last_insert_id('note_request_id_seq');

        Log::info("notes", "Note requested {$resultID} by " . Ctx::$user->name);
    }

    private function update_note(): void
    {
        $note = \Safe\json_decode(\Safe\file_get_contents('php://input'), true);
        if (empty($note['note'])) {
            return;
        }

        Ctx::$database->execute("
			UPDATE notes
			SET x1 = :x1, y1 = :y1, height = :height, width = :width, note = :note
			WHERE image_id = :image_id AND id = :note_id", $note);

        $this->add_history(1, $note['note_id'], $note['image_id'], $note['x1'], $note['y1'], $note['height'], $note['width'], $note['note']);
    }

    private function delete_note(): void
    {
        $note = \Safe\json_decode(\Safe\file_get_contents('php://input'), true);
        Ctx::$database->execute("
			UPDATE notes SET enable = :enable
			WHERE image_id = :image_id AND id = :id
		", ['enable' => 0, 'image_id' => $note["image_id"], 'id' => $note["note_id"]]);

        Log::info("notes", "Note deleted {$note["note_id"]} by " . Ctx::$user->name);
    }

    private function nuke_notes(int $image_id): void
    {
        Ctx::$database->execute("DELETE FROM notes WHERE image_id = :image_id", ['image_id' => $image_id]);
        Log::info("notes", "Notes deleted from {$image_id} by " . Ctx::$user->name);
    }

    private function nuke_requests(int $image_id): void
    {
        Ctx::$database->execute("DELETE FROM note_request WHERE image_id = :image_id", ['image_id' => $image_id]);
        Log::info("notes", "Requests deleted from {$image_id} by " . Ctx::$user->name);
    }

    private function get_notes_list(int $pageNumber): void
    {
        global $database;

        $notesPerPage = Ctx::$config->get(NotesConfig::NOTES_PER_PAGE);
        $totalPages = (int) ceil($database->get_one("SELECT COUNT(DISTINCT image_id) FROM notes") / $notesPerPage);

        $image_ids = $database->get_col(
            "
			SELECT DISTINCT image_id
			FROM (
			    SELECT * FROM notes
			    WHERE enable = :enable
			    ORDER BY date DESC, id DESC
			) AS subquery
			LIMIT :limit OFFSET :offset",
            ['enable' => 1, 'offset' => $pageNumber * $notesPerPage, 'limit' => $notesPerPage]
        );

        $images = [];
        foreach ($image_ids as $id) {
            $images[] = Image::by_id_ex($id);
        }

        $this->theme->display_note_list($images, $pageNumber + 1, $totalPages);
    }

    private function get_notes_requests(int $pageNumber): void
    {
        global $database;

        $requestsPerPage = Ctx::$config->get(NotesConfig::REQUESTS_PER_PAGE);

        $result = $database->execute(
            "
				SELECT DISTINCT image_id
				FROM (
				    SELECT *
					FROM note_request
				    ORDER BY date DESC, id DESC
				) AS subquery
				LIMIT :limit OFFSET :offset",
            ["offset" => $pageNumber * $requestsPerPage, "limit" => $requestsPerPage]
        );

        $totalPages = (int) ceil($database->get_one("SELECT COUNT(*) FROM note_request") / $requestsPerPage);

        $images = [];
        while ($row = $result->fetch()) {
            $images[] = Image::by_id_ex($row["image_id"]);
        }

        $this->theme->display_note_requests($images, $pageNumber + 1, $totalPages);
    }

    private function add_history(int $noteEnable, int $noteID, int $imageID, int $noteX1, int $noteY1, int $noteHeight, int $noteWidth, string $noteText): void
    {
        global $database;

        $reviewID = $database->get_one("SELECT COUNT(*) FROM note_histories WHERE note_id = :note_id", ['note_id' => $noteID]);
        $reviewID = $reviewID + 1;

        $database->execute(
            "
				INSERT INTO note_histories (note_enable, note_id, review_id, image_id, user_id, user_ip, date, x1, y1, height, width, note)
				VALUES (:note_enable, :note_id, :review_id, :image_id, :user_id, :user_ip, now(), :x1, :y1, :height, :width, :note)
			",
            [
                'note_enable' => $noteEnable,
                'note_id' => $noteID,
                'review_id' => $reviewID,
                'image_id' => $imageID,
                'user_id' => Ctx::$user->id,
                'user_ip' => Network::get_real_ip(),
                'x1' => $noteX1,
                'y1' => $noteY1,
                'height' => $noteHeight,
                'width' => $noteWidth,
                'note' => $noteText
            ]
        );
    }

    private function get_histories(int $pageNumber): void
    {
        global $database;

        $historiesPerPage = Ctx::$config->get(NotesConfig::HISTORIES_PER_PAGE);

        //ORDER BY IMAGE & DATE
        $histories = $database->get_all(
            "SELECT h.note_id, h.review_id, h.image_id, h.date, h.note, u.name AS user_name
            FROM note_histories AS h
            INNER JOIN users AS u
            ON u.id = h.user_id
            ORDER BY date DESC, note_id DESC
            LIMIT :limit OFFSET :offset",
            ['offset' => $pageNumber * $historiesPerPage, 'limit' => $historiesPerPage]
        );

        $totalPages = (int) ceil($database->get_one("SELECT COUNT(*) FROM note_histories") / $historiesPerPage);

        $this->theme->display_histories($histories, $pageNumber + 1, $totalPages);
    }

    private function get_history(int $noteID, int $pageNumber): void
    {
        global $database;

        $historiesPerPage = Ctx::$config->get(NotesConfig::HISTORIES_PER_PAGE);

        $histories = $database->get_all(
            "SELECT h.note_id, h.review_id, h.image_id, h.date, h.note, u.name AS user_name
            FROM note_histories AS h
            INNER JOIN users AS u
            ON u.id = h.user_id
            WHERE note_id = :note_id
            ORDER BY date DESC, note_id DESC
            LIMIT :limit OFFSET :offset",
            ['note_id' => $noteID, 'offset' => $pageNumber * $historiesPerPage, 'limit' => $historiesPerPage]
        );

        $count = $database->get_one("SELECT COUNT(*) FROM note_histories WHERE note_id = :note_id", ['note_id' => $noteID]);
        if ($count === 0) {
            throw new HistoryNotFound("No note history for Note #$noteID was found.");
        }
        $totalPages = (int) ceil($count / $historiesPerPage);

        $this->theme->display_history($histories, $pageNumber + 1, $totalPages);
    }

    private function get_image_history(int $imageID, int $pageNumber): void
    {
        $historiesPerPage = Ctx::$config->get(NotesConfig::HISTORIES_PER_PAGE);

        /** @var array<NoteHistory> $histories */
        $histories = Ctx::$database->get_all(
            "SELECT h.note_id, h.review_id, h.image_id, h.date, h.note, u.name AS user_name
            FROM note_histories AS h
            INNER JOIN users AS u
            ON u.id = h.user_id
            WHERE image_id = :image_id
            ORDER BY date DESC, note_id DESC
            LIMIT :limit OFFSET :offset",
            ['image_id' => $imageID, 'offset' => $pageNumber * $historiesPerPage, 'limit' => $historiesPerPage]
        );

        $count = Ctx::$database->get_one(
            "SELECT COUNT(*) FROM note_histories WHERE image_id = :image_id",
            ['image_id' => $imageID]
        );
        if ($count === 0) {
            throw new HistoryNotFound("No note history for Post #$imageID was found.");
        }
        $totalPages = (int) ceil($count / $historiesPerPage);

        $this->theme->display_image_history($histories, $imageID, $pageNumber + 1, $totalPages);
    }

    /**
     * HERE GO BACK IN HISTORY AND SET THE OLD NOTE. IF WAS REMOVED WE RE-ADD IT.
     */
    private function revert_history(int $noteID, int $reviewID): void
    {
        global $database;

        $history = $database->get_row(
            "SELECT * FROM note_histories WHERE note_id = :note_id AND review_id = :review_id",
            ['note_id' => $noteID, 'review_id' => $reviewID]
        );

        $noteEnable = $history['note_enable'];
        $noteID = $history['note_id'];
        $imageID = $history['image_id'];
        $noteX1 = $history['x1'];
        $noteY1 = $history['y1'];
        $noteHeight = $history['height'];
        $noteWidth = $history['width'];
        $noteText = $history['note'];

        $database->execute("
			UPDATE notes
			SET enable = :enable, x1 = :x1, y1 = :y1, height = :height, width = :width, note = :note
			WHERE image_id = :image_id AND id = :id
		", ['enable' => 1, 'x1' => $noteX1, 'y1' => $noteY1, 'height' => $noteHeight, 'width' => $noteWidth, 'note' => $noteText, 'image_id' => $imageID, 'id' => $noteID]);

        $this->add_history($noteEnable, $noteID, $imageID, $noteX1, $noteY1, $noteHeight, $noteWidth, $noteText);
    }
}
