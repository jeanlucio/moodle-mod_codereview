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
 * Tests for the mod_codereview pre-uninstallation hook.
 *
 * @package    mod_codereview
 * @category   test
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_codereview;

use mod_codereview\local\github_token;

/**
 * Tests for xmldb_codereview_uninstall(). Every table in db/install.xml is dropped
 * automatically by core, so the only thing worth exercising here is the one piece of
 * cleanup core does not do for us: the encrypted personal GitHub token stored in
 * user_preferences by github_token::set_personal_token().
 *
 * The function is called here by the exact literal name core derives for a "mod"
 * plugin (uninstall_plugin() in lib/adminlib.php uses the short module name, never the
 * "mod_" prefixed component) — so a future rename to the wrong
 * xmldb_mod_codereview_uninstall() form fails this test with "Call to undefined
 * function" instead of silently leaving personal access tokens in the database
 * forever.
 *
 * @covers ::xmldb_codereview_uninstall
 */
final class uninstall_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/codereview/db/uninstall.php');
    }

    /**
     * Tests that the uninstall hook removes a stored personal GitHub token, leaving
     * unrelated preferences (including those of other plugins) untouched.
     *
     * @return void
     */
    public function test_uninstall_removes_personal_github_token(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        github_token::set_personal_token((int) $user->id, 'ghp_faketokenvalue');
        set_user_preference('unrelated_pref', 'keep', $user);

        $this->assertNotSame('', github_token::get_personal_token((int) $user->id));

        $result = xmldb_codereview_uninstall();

        $this->assertTrue($result);
        $this->assertFalse($DB->record_exists('user_preferences', [
            'userid' => $user->id,
            'name' => github_token::PREFERENCE,
        ]));
        $this->assertTrue($DB->record_exists('user_preferences', [
            'userid' => $user->id,
            'name' => 'unrelated_pref',
        ]));
    }

    /**
     * Tests that running the hook with no matching preferences at all is a harmless
     * no-op.
     *
     * @return void
     */
    public function test_uninstall_with_no_matching_preferences_is_a_noop(): void {
        $this->assertTrue(xmldb_codereview_uninstall());
    }
}
