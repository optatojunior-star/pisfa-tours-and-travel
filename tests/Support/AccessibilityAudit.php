<?php

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Static accessibility checks over rendered HTML.
 *
 * These are the WCAG failures that can be proved from markup alone: a control
 * with no accessible name, an image with no alt text, a page with no `h1`, a
 * skipped heading level, a positive tabindex, a duplicated element id.
 *
 * What this deliberately does **not** claim to check is everything. Colour
 * contrast, focus visibility, and whether alt text is actually *useful* need a
 * browser or a person. Treating a green run here as "the site is accessible"
 * would be the real failure; it catches the regressions that are cheap to catch
 * on every commit, and leaves the rest to the browser tests and to review.
 */
class AccessibilityAudit
{
    private DOMXPath $xpath;

    /** @var list<string> */
    private array $problems = [];

    public function __construct(string $html)
    {
        $document = new DOMDocument;

        // Blade output is HTML5; libxml only knows HTML4, so its complaints
        // about unknown elements are noise rather than findings.
        libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>'.$html,
            LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();

        $this->xpath = new DOMXPath($document);
    }

    /** @return list<string> */
    public function problems(): array
    {
        $this->problems = [];

        $this->checkPageTitle();
        $this->checkLanguage();
        $this->checkHeadings();
        $this->checkImages();
        $this->checkControlNames();
        $this->checkFormLabels();
        $this->checkLinkText();
        $this->checkTabIndex();
        $this->checkDuplicateIds();
        $this->checkLandmarks();
        $this->checkTables();

        return $this->problems;
    }

    public function passes(): bool
    {
        return $this->problems() === [];
    }

    private function checkPageTitle(): void
    {
        $title = $this->xpath->query('//title');

        if ($title === false || $title->length === 0 || trim((string) $title->item(0)?->textContent) === '') {
            $this->problems[] = 'The page has no <title>.';
        }
    }

    private function checkLanguage(): void
    {
        $html = $this->xpath->query('//html[@lang]');

        if ($html === false || $html->length === 0) {
            $this->problems[] = 'The <html> element has no lang attribute, so a screen reader '
                .'cannot choose a pronunciation.';
        }
    }

    /**
     * One h1, and no skipped levels.
     *
     * Heading structure is how a screen reader user navigates a page. Jumping
     * from h2 to h4 tells them a section is nested inside something that does
     * not exist.
     */
    private function checkHeadings(): void
    {
        $h1 = $this->xpath->query('//h1');
        $count = $h1 === false ? 0 : $h1->length;

        if ($count === 0) {
            $this->problems[] = 'The page has no <h1>.';
        } elseif ($count > 1) {
            $this->problems[] = "The page has {$count} <h1> elements; it should have exactly one.";
        }

        $headings = $this->xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');

        if ($headings === false) {
            return;
        }

        $previous = 0;

        foreach ($headings as $heading) {
            if (! $heading instanceof DOMElement) {
                continue;
            }

            $level = (int) substr($heading->tagName, 1);

            if ($previous !== 0 && $level > $previous + 1) {
                $text = trim(mb_substr($heading->textContent, 0, 40));
                $this->problems[] = "Heading level jumps from h{$previous} to h{$level} at \"{$text}\".";
            }

            $previous = $level;
        }
    }

    private function checkImages(): void
    {
        $images = $this->xpath->query('//img[not(@alt)]');

        if ($images !== false && $images->length > 0) {
            $src = $images->item(0) instanceof DOMElement
                ? $images->item(0)->getAttribute('src')
                : '';

            $this->problems[] = "{$images->length} <img> element(s) have no alt attribute, "
                ."the first being \"{$src}\". Decorative images need alt=\"\", not a missing attribute.";
        }
    }

    /**
     * Every interactive control needs an accessible name.
     *
     * An icon-only button with no name is announced as "button" — the user is
     * told something is clickable but not what it does.
     */
    private function checkControlNames(): void
    {
        $buttons = $this->xpath->query('//button');

        if ($buttons === false) {
            return;
        }

        foreach ($buttons as $button) {
            if (! $button instanceof DOMElement) {
                continue;
            }

            if ($this->accessibleName($button) === '') {
                $this->problems[] = 'A <button> has no accessible name: no text, no aria-label, '
                    .'and no aria-labelledby.';
            }
        }
    }

    /**
     * Every form control is labelled.
     *
     * A placeholder is not a label: it disappears the moment somebody types,
     * and several screen readers do not announce it at all.
     */
    private function checkFormLabels(): void
    {
        $controls = $this->xpath->query(
            '//input[not(@type="hidden")] | //select | //textarea',
        );

        if ($controls === false) {
            return;
        }

        foreach ($controls as $control) {
            if (! $control instanceof DOMElement) {
                continue;
            }

            $type = strtolower($control->getAttribute('type'));

            // A submit button's value is its name.
            if (in_array($type, ['submit', 'button', 'image', 'reset'], true)) {
                continue;
            }

            if ($this->accessibleName($control) !== '') {
                continue;
            }

            $id = $control->getAttribute('id');

            if ($id !== '') {
                $label = $this->xpath->query(sprintf('//label[@for=%s]', $this->quote($id)));

                if ($label !== false && $label->length > 0) {
                    continue;
                }
            }

            // A control wrapped in its own label is labelled.
            $wrapping = $this->xpath->query('ancestor::label', $control);

            if ($wrapping !== false && $wrapping->length > 0) {
                continue;
            }

            $name = $control->getAttribute('name') ?: $control->tagName;

            $this->problems[] = "The control \"{$name}\" has no label. A placeholder is not a label.";
        }
    }

    /**
     * Links say where they go.
     *
     * "Click here" repeated down a page is useless to somebody listing the
     * links, which is a common way to navigate.
     */
    private function checkLinkText(): void
    {
        $links = $this->xpath->query('//a[@href]');

        if ($links === false) {
            return;
        }

        $vague = ['click here', 'here', 'read more', 'more', 'link', 'this'];

        foreach ($links as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $name = $this->accessibleName($link);

            if ($name === '') {
                $href = $link->getAttribute('href');
                $this->problems[] = "A link to \"{$href}\" has no accessible name.";

                continue;
            }

            if (in_array(mb_strtolower(trim($name, " \t\n\r\0\x0B.")), $vague, true)) {
                $this->problems[] = "The link text \"{$name}\" does not say where it goes.";
            }
        }
    }

    /**
     * No positive tabindex.
     *
     * A positive value pulls an element out of document order and in front of
     * everything else on the page, which breaks keyboard navigation for every
     * element that does not have one.
     */
    private function checkTabIndex(): void
    {
        $elements = $this->xpath->query('//*[@tabindex]');

        if ($elements === false) {
            return;
        }

        foreach ($elements as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            if ((int) $element->getAttribute('tabindex') > 0) {
                $this->problems[] = "A <{$element->tagName}> has a positive tabindex, which breaks "
                    .'keyboard order for the rest of the page.';
            }
        }
    }

    private function checkDuplicateIds(): void
    {
        $elements = $this->xpath->query('//*[@id]');

        if ($elements === false) {
            return;
        }

        $seen = [];

        foreach ($elements as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $id = $element->getAttribute('id');

            if ($id === '') {
                continue;
            }

            if (isset($seen[$id])) {
                // A duplicate id breaks every label/aria reference to it.
                $this->problems[] = "The id \"{$id}\" is used more than once.";
            }

            $seen[$id] = true;
        }
    }

    private function checkLandmarks(): void
    {
        $main = $this->xpath->query('//main | //*[@role="main"]');

        if ($main === false || $main->length === 0) {
            $this->problems[] = 'The page has no <main> landmark, so there is nothing to skip to.';
        } elseif ($main->length > 1) {
            $this->problems[] = 'The page has more than one <main> landmark.';
        }
    }

    /** A data table needs a caption or an accessible name to be worth navigating. */
    private function checkTables(): void
    {
        $tables = $this->xpath->query('//table');

        if ($tables === false) {
            return;
        }

        foreach ($tables as $table) {
            if (! $table instanceof DOMElement) {
                continue;
            }

            $caption = $this->xpath->query('caption', $table);
            $hasCaption = $caption !== false && $caption->length > 0;

            if (! $hasCaption && $this->accessibleName($table) === '') {
                $this->problems[] = 'A <table> has no caption and no accessible name.';
            }

            $headers = $this->xpath->query('.//th', $table);

            if ($headers === false || $headers->length === 0) {
                $this->problems[] = 'A <table> has no <th> header cells.';
            }
        }
    }

    /**
     * The accessible name, in roughly the order the accname spec resolves it.
     *
     * Not a complete implementation — it does not walk aria-labelledby through
     * nested references — but enough to tell a named control from an unnamed
     * one, which is what these checks turn on.
     */
    private function accessibleName(DOMElement $element): string
    {
        $ariaLabel = trim($element->getAttribute('aria-label'));

        if ($ariaLabel !== '') {
            return $ariaLabel;
        }

        $labelledBy = trim($element->getAttribute('aria-labelledby'));

        if ($labelledBy !== '') {
            $name = '';

            foreach (preg_split('/\s+/', $labelledBy) ?: [] as $id) {
                $target = $this->xpath->query(sprintf('//*[@id=%s]', $this->quote($id)));

                if ($target !== false && $target->length > 0) {
                    $name .= ' '.trim((string) $target->item(0)?->textContent);
                }
            }

            if (trim($name) !== '') {
                return trim($name);
            }
        }

        $title = trim($element->getAttribute('title'));

        if ($title !== '') {
            return $title;
        }

        // An <img alt> inside a control names the control.
        $images = $this->xpath->query('.//img[@alt]', $element);

        if ($images !== false) {
            foreach ($images as $image) {
                if ($image instanceof DOMElement && trim($image->getAttribute('alt')) !== '') {
                    return trim($image->getAttribute('alt'));
                }
            }
        }

        return trim($element->textContent);
    }

    /** Quotes a value for XPath, which has no escape character of its own. */
    private function quote(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return 'concat("'.str_replace('"', '", \'"\', "', $value).'")';
    }
}
