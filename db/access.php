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
 * Capability definitions.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    'mod/codereview:addinstance' => [
        'riskbitmask'  => RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/course:manageactivities',
    ],

    'mod/codereview:view' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => [
            'student'        => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    'mod/codereview:submit' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => [
            'student' => CAP_ALLOW,
        ],
    ],

    'mod/codereview:grade' => [
        'riskbitmask'  => RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    'mod/codereview:viewreports' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => [
            'teacher'        => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // Gates the personal GitHub token feature, together with the site-level
    // enablepersonaltokens toggle. See SCOPE.md section 11.
    'mod/codereview:usepersonaltoken' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
];
