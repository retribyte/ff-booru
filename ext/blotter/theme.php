<?php

declare(strict_types=1);

namespace Shimmie2;

use function MicroHTML\A;
use function MicroHTML\BR;
use function MicroHTML\DIV;
use function MicroHTML\INPUT;
use function MicroHTML\LI;
use function MicroHTML\P;
use function MicroHTML\SPAN;
use function MicroHTML\TABLE;
use function MicroHTML\TBODY;
use function MicroHTML\TD;
use function MicroHTML\TEXTAREA;
use function MicroHTML\TFOOT;
use function MicroHTML\TH;
use function MicroHTML\THEAD;
use function MicroHTML\TR;
use function MicroHTML\UL;
use function MicroHTML\emptyHTML;

/**
 * @phpstan-type BlotterEntry array{id:int,entry_date:string,entry_text:string,important:bool}
 */
class BlotterTheme extends Themelet
{
    /**
     * @param BlotterEntry[] $entries
     */
    public function display_editor(array $entries): void
    {
        global $page;

        $tbody = TBODY();
        foreach ($entries as $entry) {
            $tbody->appendChild(
                TR(
                    TD($entry['entry_date']),
                    TD($entry['entry_text']),
                    TD($entry['important'] ? "Yes" : "No"),
                    TD(
                        SHM_SIMPLE_FORM(
                            make_link("blotter/remove"),
                            INPUT(["type" => "hidden", "name" => "id", "value" => $entry['id']]),
                            SHM_SUBMIT("Remove")
                        )
                    )
                )
            );
        }
        $html = emptyHTML(
            TABLE(
                ["id" => "blotter_entries", "class" => "zebra"],
                THEAD(
                    TR(
                        TH("Date"),
                        TH("Message"),
                        TH("Important?"),
                        TH("Action")
                    )
                ),
                $tbody,
                TFOOT(
                    TR(SHM_SIMPLE_FORM(
                        make_link("blotter/add"),
                        TD(["colspan" => 2], TEXTAREA(["name" => "entry_text", "rows" => 2])),
                        TD(INPUT(["type" => "checkbox", "name" => "important"])),
                        TD(SHM_SUBMIT("Add"))
                    ))
                )
            ),
        );
        $page->set_title("Blotter Editor");
        $page->add_block(new Block("Blotter Editor", $html, "main", 10));
        $this->display_navigation();
    }

    /**
     * @param BlotterEntry[] $entries
     */
    public function display_blotter_page(array $entries): void
    {
        global $page;

        $i_color = Ctx::$config->get_string(BlotterConfig::COLOR);

        $html = P();
        foreach ($entries as $entry) {
            $clean_date = date("Y/m/d", \Safe\strtotime($entry['entry_date']));
            $entry_text = $entry['entry_text'];
            $msg = "{$clean_date} - {$entry_text}";
            if ($entry['important']) {
                $msg = SPAN(["style" => "color: $i_color;"], $msg);
            }
            $html->appendChild(emptyHTML($msg, BR(), BR()));
        }

        $page->set_title("Blotter");
        $page->add_block(new Block("Blotter Entries", $html, "main", 10));
    }

    /**
     * @param BlotterEntry[] $entries
     */
    public function display_blotter(array $entries): void
    {
        global $page, $config;

        $i_color = $config->get_string(BlotterConfig::COLOR);
        $position = $config->get_string(BlotterConfig::POSITION);

        $entries_list = UL();
        foreach ($entries as $entry) {
            $clean_date = date("m/d/y", \Safe\strtotime($entry['entry_date']));
            $entry_text = $entry['entry_text'];
            $text = "{$clean_date} - {$entry_text}";
            if ($entry['important'] == 'Y') {
                $text = SPAN(["style" => "color: $i_color"], $text);
            }
            $entries_list->appendChild(LI($text));
        }

        $pos_break = "";
        $pos_align = "text-align: right; position: absolute; right: 0px;";

        if ($position === "left") {
            $pos_break = BR();
            $pos_align = "";
        }

        if (count($entries) === 0) {
            $out_text = "No blotter entries yet.";
            $in_text = "Empty.";
        } else {
            $out_text = "Blotter updated: " . date("m/d/y", \Safe\strtotime($entries[0]['entry_date']));
            $in_text = $entries_list;
        }

        $html = emptyHTML(
            DIV(
                ["id" => "blotter1", "class" => "shm-blotter1"],
                SPAN($out_text),
                $pos_break,
                SPAN(
                    ["style" => $pos_align],
                    A(["href" => "#", "id" => "blotter2-toggle", "class" => "shm-blotter2-toggle"], "Show/Hide"),
                    " ",
                    A(["href" => make_link("blotter/list")], "Show All")
                ),
            ),
            DIV(["id" => "blotter2", "class" => "shm-blotter2"], $in_text)
        );

        $position = $config->req_string(BlotterConfig::POSITION);
        $page->add_block(new Block(null, $html, $position, 20));
    }
}
