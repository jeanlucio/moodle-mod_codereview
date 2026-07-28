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
 * Database upgrade steps.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Applies the schema changes for a version upgrade.
 *
 * @param int $oldversion The version the site is upgrading from.
 * @return bool
 */
function xmldb_codereview_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026072704) {
        $table = new xmldb_table('codereview_commits');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('submission', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('codereview', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sha', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('position', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('submission', XMLDB_KEY_FOREIGN, ['submission'], 'codereview_submissions', ['id']);
        $table->add_key('codereview', XMLDB_KEY_FOREIGN, ['codereview'], 'codereview', ['id']);
        $table->add_index('codereview-sha', XMLDB_INDEX_NOTUNIQUE, ['codereview', 'sha']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026072704, 'codereview');
    }

    if ($oldversion < 2026072800) {
        $table = new xmldb_table('codereview');
        $field = new xmldb_field('completionsubmit', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'cutoffdate');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072800, 'codereview');
    }

    if ($oldversion < 2026072805) {
        $instance = new xmldb_table('codereview');

        $teamsubmission = new xmldb_field(
            'teamsubmission',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'tokenuserid'
        );
        if (!$dbman->field_exists($instance, $teamsubmission)) {
            $dbman->add_field($instance, $teamsubmission);
        }

        $grouping = new xmldb_field(
            'teamsubmissiongroupingid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'teamsubmission'
        );
        if (!$dbman->field_exists($instance, $grouping)) {
            $dbman->add_field($instance, $grouping);
        }

        // Zero on every existing row, which is what an individual submission carries.
        $submissions = new xmldb_table('codereview_submissions');
        $groupid = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'userid');
        if (!$dbman->field_exists($submissions, $groupid)) {
            $dbman->add_field($submissions, $groupid);
        }

        // Not unique: individual submissions all share groupid 0, so one row per
        // group is enforced by submission_service instead of by the schema.
        $index = new xmldb_index('codereview-groupid', XMLDB_INDEX_NOTUNIQUE, ['codereview', 'groupid']);
        if (!$dbman->index_exists($submissions, $index)) {
            $dbman->add_index($submissions, $index);
        }

        upgrade_mod_savepoint(true, 2026072805, 'codereview');
    }

    return true;
}
