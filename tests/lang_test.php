<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_codereview;

use advanced_testcase;

/**
 * Checks that every string the plugin asks for is one the plugin defines.
 *
 * A missing string is invisible to PHPCS, to the PHPDoc checker and to every test
 * that does not happen to render the element using it: the page simply shows
 * [[identifier]] to whoever opened it. Twelve of them accumulated unnoticed before
 * this test existed, which is why it scans the source rather than trusting review.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\output\renderer
 */
final class lang_test extends advanced_testcase {
    /** @var string[] Identifiers built at runtime, which no scan can find. */
    private const RUNTIME_IDENTIFIERS = [
        'cipending', 'cichecking', 'cicompleted', 'cinocidetected', 'cierror',
        'aiskipped', 'aipending', 'aicompleted', 'aierror',
        'severityinfo', 'severitywarning', 'severityhigh',
        'flagforkofpeer', 'flagidenticalcommit', 'flagsharedhistory', 'flagcontentoverlap',
        'flagforeignauthor', 'flagimportedhistory', 'flagduplicaterepo',
        'completiondetail:submit', 'completiondetail:checks',
    ];

    /**
     * Returns every identifier the plugin asks the string manager for.
     *
     * @return string[]
     */
    private function requested_identifiers(): array {
        global $CFG;

        $root = $CFG->dirroot . '/mod/codereview';
        $wanted = self::RUNTIME_IDENTIFIERS;

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (!preg_match('/\.(php|mustache)$/', $path) || str_contains($path, '/amd/build/')) {
                continue;
            }

            $contents = file_get_contents($path);

            preg_match_all("/get_string\(\s*'([a-z0-9_:]+)'\s*,\s*'mod_codereview'/", $contents, $direct);
            preg_match_all("/new lang_string\(\s*'([a-z0-9_:]+)'\s*,\s*'mod_codereview'/", $contents, $lazy);
            preg_match_all('/\{\{#str\}\}\s*([a-z0-9_:]+)\s*,\s*mod_codereview\s*\{\{\/str\}\}/', $contents, $mustache);

            $wanted = array_merge($wanted, $direct[1], $lazy[1], $mustache[1]);
        }

        return array_values(array_unique($wanted));
    }

    /**
     * Returns the identifiers a language file defines.
     *
     * @param string $lang The language directory.
     * @return string[]
     */
    private function defined_identifiers(string $lang): array {
        global $CFG;

        $contents = file_get_contents($CFG->dirroot . '/mod/codereview/lang/' . $lang . '/codereview.php');
        preg_match_all("/\\\$string\['([^']+)'\]/", $contents, $matches);

        return $matches[1];
    }

    /**
     * Every requested identifier exists in English.
     *
     * @return void
     */
    public function test_every_requested_string_is_defined(): void {
        $missing = array_diff($this->requested_identifiers(), $this->defined_identifiers('en'));

        $this->assertSame([], array_values($missing), 'Strings requested but never defined: ' . implode(', ', $missing));
    }

    /**
     * The two languages hold exactly the same identifiers.
     *
     * @return void
     */
    public function test_translations_are_in_step(): void {
        $en = $this->defined_identifiers('en');
        $ptbr = $this->defined_identifiers('pt_br');

        $this->assertSame([], array_values(array_diff($en, $ptbr)), 'Missing from pt_br');
        $this->assertSame([], array_values(array_diff($ptbr, $en)), 'Missing from en');
    }

    /**
     * Keys stay in alphabetical order, which is what keeps insertions from drifting
     * to the end of the file where nobody notices duplicates.
     *
     * @return void
     */
    public function test_keys_are_sorted(): void {
        foreach (['en', 'pt_br'] as $lang) {
            $keys = $this->defined_identifiers($lang);
            $sorted = $keys;
            sort($sorted, SORT_STRING);

            $this->assertSame($sorted, $keys, $lang . ' keys are out of order');
        }
    }
}
