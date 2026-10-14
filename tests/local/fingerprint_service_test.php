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

/**
 * Tests for fingerprint_service.
 *
 * @package    mod_codereview
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_codereview\local;

/**
 * Tests for the way fingerprint_service stores the files of a repository tree.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\fingerprint_service
 */
final class fingerprint_service_test extends \advanced_testcase {
    /**
     * A path longer than its 255-character column is cut by characters, not by bytes: cutting a
     * multibyte path at byte 255 splits a character, which PostgreSQL refuses as invalid UTF-8.
     */
    public function test_a_long_multibyte_path_is_cut_by_characters(): void {
        global $DB;
        $this->resetAfterTest();

        $service = (new \ReflectionClass(fingerprint_service::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(fingerprint_service::class, 'store_blobs');

        $path = str_repeat('ç', 300);
        $method->invoke($service, 7, 9, [['type' => 'blob', 'sha' => str_repeat('a', 40), 'path' => $path, 'size' => 3]]);

        $stored = $DB->get_field('codereview_blobs', 'path', ['codereview' => 7, 'submission' => 9], MUST_EXIST);
        $this->assertSame(255, \core_text::strlen($stored));
        $this->assertSame(1, preg_match('//u', $stored), 'The stored path must be valid UTF-8.');
    }
}
