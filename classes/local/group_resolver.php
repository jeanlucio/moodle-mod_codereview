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

namespace mod_codereview\local;

use stdClass;

/**
 * Decides which group a student submits for.
 *
 * Follows mod_assign's rule that a student must resolve to exactly one group:
 * belonging to none, or to more than one, is equally unusable, because both leave
 * the question "whose repository is this?" without a single answer.
 *
 * Where this deliberately parts company with mod_assign is what happens then.
 * Assign lets an ungrouped student submit into a pseudo-group of id zero, so every
 * ungrouped student in the course shares one submission. For an activity built on
 * one repository per team that is not a lenient fallback, it is a wrong answer, so
 * a student who does not resolve to a group is blocked instead.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_resolver {
    /** @var stdClass The codereview instance row. */
    protected stdClass $instance;

    /** @var array Resolved group per user id, to keep repeated lookups off the database. */
    protected array $cache = [];

    /**
     * Constructor.
     *
     * @param stdClass $instance The codereview instance row.
     */
    public function __construct(stdClass $instance) {
        $this->instance = $instance;
    }

    /**
     * Returns true when this instance collects one submission per group.
     *
     * @return bool
     */
    public function is_team_submission(): bool {
        return !empty($this->instance->teamsubmission);
    }

    /**
     * Returns every group of the user that counts for this instance.
     *
     * @param int $userid The user to look up.
     * @return array Group records keyed by id.
     */
    public function groups_for(int $userid): array {
        if (!isset($this->cache[$userid])) {
            $this->cache[$userid] = groups_get_all_groups(
                (int) $this->instance->course,
                $userid,
                (int) $this->instance->teamsubmissiongroupingid,
                'g.*',
                false,
                true
            );
        }

        return $this->cache[$userid];
    }

    /**
     * Returns the group the user submits for, or 0 when they cannot submit.
     *
     * Zero is also the correct answer for an instance that is not a team
     * submission, where it means the submission belongs to the user alone.
     *
     * @param int $userid The user to look up.
     * @return int The group id, or 0.
     */
    public function group_for(int $userid): int {
        if (!$this->is_team_submission()) {
            return 0;
        }

        $groups = $this->groups_for($userid);

        return count($groups) === 1 ? (int) reset($groups)->id : 0;
    }

    /**
     * Returns why the user cannot submit, or an empty string when they can.
     *
     * The two reasons are told apart because they call for opposite fixes: one
     * student needs to be put in a group, the other needs to be taken out of one.
     *
     * @param int $userid The user to check.
     * @return string A language string identifier, or '' when submission is allowed.
     */
    public function blocked_reason(int $userid): string {
        if (!$this->is_team_submission()) {
            return '';
        }

        $count = count($this->groups_for($userid));

        if ($count === 0) {
            return 'nogroupwarning';
        }

        return $count > 1 ? 'multiplegroupswarning' : '';
    }

    /**
     * Counts the students who cannot submit because no single group resolves.
     *
     * The teacher's screen lists submissions, so a student blocked from making one
     * leaves no trace on it. Without this they would be discovered when the grades
     * are due, which is exactly too late to fix the group setup.
     *
     * @param stdClass $instance The codereview instance row.
     * @param \context_module $context The activity context.
     * @param int $groupid Restrict to one group, or 0 for the whole activity.
     * @return int How many students resolve to no single group.
     */
    public static function count_ungrouped(stdClass $instance, \context_module $context, int $groupid = 0): int {
        // A group filter is already asking about people who are in that group, so
        // there is nobody ungrouped left to warn about.
        if ($groupid > 0 || empty($instance->teamsubmission)) {
            return 0;
        }

        $resolver = new self($instance);
        $stranded = 0;

        foreach (get_enrolled_users($context, 'mod/codereview:submit', 0, 'u.id') as $user) {
            if (count($resolver->groups_for((int) $user->id)) !== 1) {
                $stranded++;
            }
        }

        return $stranded;
    }

    /**
     * Returns the ids of everyone the submission counts for.
     *
     * On an individual submission that is the submitter alone. On a team
     * submission it is the group's membership at the moment of asking, which is
     * what the gradebook and completion have to be written against: a member who
     * joins after the work was submitted still owns the group's grade.
     *
     * @param int $groupid The group the submission belongs to, or 0.
     * @param int $userid The submitter, used when there is no group.
     * @return int[] User ids.
     */
    public function members_of(int $groupid, int $userid): array {
        if ($groupid <= 0) {
            return [$userid];
        }

        $members = groups_get_members($groupid, 'u.id', 'u.id ASC');

        return $members ? array_map('intval', array_keys($members)) : [];
    }
}
