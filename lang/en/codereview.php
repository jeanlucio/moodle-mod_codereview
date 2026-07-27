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
 * English strings for mod_codereview.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
// phpcs:disable moodle.Files.LineLength

$string['aicompleted'] = 'AI review finished';
$string['aierror'] = 'The AI review could not be completed';
$string['aipending'] = 'AI review in progress...';
$string['aiskipped'] = 'No AI review for this activity';
$string['authorshipnotice'] = 'Repository metadata is analysed to verify the authorship of this submission.';
$string['checkrunnotcounted'] = 'Not counted towards the grade (not a GitHub Actions check)';
$string['checkruns'] = 'Automated checks';
$string['cichecking'] = 'Automated checks are running...';
$string['cicompleted'] = 'Automated checks finished';
$string['cierror'] = 'The automated checks could not be read';
$string['citimeout'] = 'Automated check timeout (minutes)';
$string['citimeout_help'] = 'How long to keep polling GitHub for automated check results before giving up and reporting that no CI was detected.';
$string['codereview:addinstance'] = 'Add a new CodeReview activity';
$string['codereview:grade'] = 'Review submissions and approve grades';
$string['codereview:submit'] = 'Submit a repository for review';
$string['codereview:usepersonaltoken'] = 'Use a personal GitHub token';
$string['codereview:view'] = 'View CodeReview activity';
$string['codereview:viewreports'] = 'View submission reports';
$string['commitsha'] = 'Commit SHA';
$string['commitsha_help'] = 'The full 40-character SHA of the commit to be assessed. Branch names and tags are not accepted, because they can change after submission.';
$string['cutoffdate'] = 'Cut-off date';
$string['cutoffdate_help'] = 'After this date no new submission or resubmission is accepted. Leave disabled to allow submissions indefinitely.';
$string['duedate'] = 'Due date';
$string['duedate_help'] = 'Submissions sent after this date are flagged as late for the teacher, but are not blocked.';
$string['enablepersonaltokens'] = 'Allow personal GitHub tokens';
$string['enablepersonaltokens_desc'] = 'When enabled, teachers holding the corresponding capability can store their own GitHub token and use it for their activities, instead of relying on the site token.';
$string['erroralreadygraded'] = 'This submission has already been graded. Ask your teacher to reopen it before submitting again.';
$string['errorcommitnotfound'] = 'That commit was not found in this repository. Check the SHA and try again.';
$string['errorcutoffpassed'] = 'The cut-off date for this activity has passed.';
$string['errorgithubapi'] = 'The GitHub API could not be reached. Please try again in a few minutes.';
$string['errorgithubratelimit'] = 'The GitHub API rate limit was reached. Please try again later.';
$string['errorinvalidcommitsha'] = 'Enter the full 40-character commit SHA, using hexadecimal characters only.';
$string['errorinvalidrepourl'] = 'Enter a valid public GitHub repository URL, for example https://github.com/owner/repository.';
$string['errormalformedairesponse'] = 'The AI provider returned an answer that could not be used.';
$string['errornoreviewablecode'] = 'No reviewable source file was found in this commit.';
$string['errornotpublic'] = 'That repository is not public. This activity can only assess public repositories.';
$string['errorrecheckpending'] = 'This submission has not been checked yet. Wait for the first automated check before requesting another.';
$string['errorrepositorynotfound'] = 'That repository was not found on GitHub. Check the URL and try again.';
$string['errorrepotoolarge'] = 'This repository is too large to be reviewed automatically.';
$string['errortokeninvalid'] = 'The GitHub token in use is no longer valid. Ask your teacher or administrator to update it.';
$string['eventrepo_submitted'] = 'Repository submitted';
$string['integritychecks'] = 'Verify authorship';
$string['integritychecks_help'] = 'Compares repository metadata and file content hashes across submissions to detect exact duplicates. Results are shown to the teacher as evidence only and never change a grade automatically.';
$string['messagenocidetected'] = 'No automated check appeared for commit {$a->commit} in the activity {$a->activity} before the timeout. If the repository has a GitHub Actions workflow, check that it ran, then use "Check again now".';
$string['messagenocidetectedsubject'] = 'No automated check detected in {$a}';
$string['messageprovider:nocidetected'] = 'No automated check detected on a submission';
$string['modulename'] = 'CodeReview';
$string['modulename_help'] = 'CodeReview assesses programming work hosted on GitHub. Students submit a repository URL and a commit SHA; the activity reads the automated check results GitHub Actions already produced for that commit, optionally adds an AI review, and presents everything on a dedicated screen where the teacher approves the final grade.';
$string['modulenameplural'] = 'CodeReviews';
$string['mytoken'] = 'My GitHub token';
$string['personaltoken'] = 'Personal GitHub token';
$string['personaltoken_help'] = 'A fine-grained personal access token with read-only access. It is stored encrypted, is never displayed again after saving, and is used only to read the repositories submitted to your activities.';
$string['personaltokennotset'] = 'No personal token stored.';
$string['personaltokenremove'] = 'Remove my token';
$string['personaltokenremoved'] = 'Your GitHub token was removed.';
$string['personaltokensaved'] = 'Your GitHub token was saved.';
$string['personaltokenstored'] = 'A personal token is stored. Saving a new one replaces it.';
$string['pluginadministration'] = 'CodeReview administration';
$string['pluginname'] = 'CodeReview';
$string['privacy:metadata:codereview_submissions'] = 'Repository submissions made by the student.';
$string['privacy:metadata:codereview_submissions:commitsha'] = 'The SHA of the submitted commit.';
$string['privacy:metadata:codereview_submissions:repourl'] = 'The URL of the submitted repository.';
$string['privacy:metadata:codereview_submissions:timecreated'] = 'When the submission was made.';
$string['privacy:metadata:codereview_submissions:userid'] = 'The student who made the submission.';
$string['privacy:metadata:github'] = 'Repository and commit identifiers are sent to the GitHub API to read the repository and its automated check results.';
$string['privacy:metadata:github:commitsha'] = 'The SHA of the commit being assessed.';
$string['privacy:metadata:github:repourl'] = 'The repository being assessed.';
$string['privacy:metadata:preference:githubtoken'] = 'Your personal GitHub access token, stored encrypted and used to authenticate requests for your activities.';
$string['privacy:redacted'] = 'The stored value is not exported for security reasons.';
$string['publicrepowarning'] = 'The repository must be public, so your work will be visible to anyone on the internet.';
$string['repourl'] = 'Repository URL';
$string['repourl_help'] = 'The full URL of your public GitHub repository, for example https://github.com/owner/repository.';
$string['rubric'] = 'Assessment rubric';
$string['rubric_help'] = 'Criteria used by the AI reviewer when suggesting a grade. It is not shown to students.';
$string['sitetoken'] = 'Site GitHub token';
$string['sitetoken_desc'] = 'A fine-grained personal access token with read-only access to public repositories. Without it the GitHub API allows only 60 requests per hour for the whole site, which is enough for demonstration but not for real use.';
$string['submitrepo'] = 'Submit repository';
$string['taskpollcheckruns'] = 'Read GitHub automated check results';
$string['taskreconcilesubmissions'] = 'Close out submissions whose checks never finished';
$string['taskrunaireview'] = 'Generate AI grade suggestions';
$string['templaterepourl'] = 'Template repository URL';
$string['templaterepourl_help'] = 'The repository you distributed to students. Its files are used as a baseline so that shared template code is not reported as duplicated work.';
$string['tokeninuse'] = 'Using the GitHub token of {$a}.';
$string['tokenusemine'] = 'Use my personal token for this activity';
$string['weightai'] = 'AI review weight (%)';
$string['weightai_help'] = 'How much the AI review contributes to the suggested grade. Set it to zero to disable the AI review entirely, in which case no code is sent to any external AI provider.';
$string['weightsmustsum'] = 'The automated checks weight and the AI review weight must add up to 100.';
$string['weighttests'] = 'Automated checks weight (%)';
$string['weighttests_help'] = 'How much the GitHub Actions results contribute to the suggested grade. This weight and the AI review weight must add up to 100.';
