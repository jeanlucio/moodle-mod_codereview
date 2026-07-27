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

use advanced_testcase;
use context_module;
use mod_codereview\fixtures\ai_gateway_stub;
use mod_codereview\fixtures\github_client_stub;
use stdClass;

/**
 * Tests for the AI reviewer.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\ai_reviewer
 */
final class ai_reviewer_test extends advanced_testcase {
    /** @var string A valid looking commit SHA used across the tests. */
    private const SHA = '1234567890abcdef1234567890abcdef12345678';

    /** @var stdClass The activity instance under test. */
    private stdClass $instance;

    /** @var stdClass The submission being reviewed. */
    private stdClass $submission;

    /** @var context_module The activity context. */
    private context_module $context;

    /**
     * Loads the shared test doubles.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/codereview/tests/fixtures/github_client_stub.php');
        require_once($CFG->dirroot . '/mod/codereview/tests/fixtures/ai_gateway_stub.php');

        parent::setUpBeforeClass();
    }

    /**
     * Creates an instance and a submission with the checks already settled.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('codereview', [
            'course' => $course->id,
            'weighttests' => 50,
            'weightai' => 50,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->instance = $DB->get_record('codereview', ['id' => $module->id], '*', MUST_EXIST);
        $this->context = context_module::instance($module->cmid);

        $submission = (object) [
            'codereview' => $this->instance->id,
            'userid' => $student->id,
            'repourl' => 'https://github.com/octocat/hello-world',
            'repoowner' => 'octocat',
            'reponame' => 'hello-world',
            'commitsha' => self::SHA,
            'cistatus' => submission_service::CI_COMPLETED,
            'aistatus' => submission_service::AI_SKIPPED,
            'gradestatus' => submission_service::GRADE_NOTGRADED,
            'truncated' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $submission->id = $DB->insert_record('codereview_submissions', $submission);

        $this->submission = $submission;
    }

    /**
     * Builds a client stub whose tree and archive contain the given files.
     *
     * @param array $files Paths mapped to contents.
     * @return github_client_stub
     */
    private function client_with(array $files): github_client_stub {
        $tree = [];
        foreach ($files as $path => $contents) {
            $tree[] = ['path' => $path, 'type' => 'blob', 'size' => strlen($contents), 'sha' => sha1($contents)];
        }

        $stub = new github_client_stub();
        $stub->set_response(
            '/repos/octocat/hello-world/git/trees/' . self::SHA,
            ['sha' => self::SHA, 'tree' => $tree, 'truncated' => false]
        );
        $stub->set_archive($this->build_zip($files));

        return $stub;
    }

    /**
     * Builds a zip laid out the way GitHub's archive endpoint returns one.
     *
     * @param array $files Paths mapped to contents.
     * @return string The raw zip bytes.
     */
    private function build_zip(array $files): string {
        $path = tempnam(make_temp_directory('mod_codereview_test'), 'zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString('octocat-hello-world-abc1234/' . $name, $contents);
        }

        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * A well formed answer is stored as a suggestion.
     *
     * @return void
     */
    public function test_review_stores_suggestion(): void {
        global $DB;

        $client = $this->client_with(['main.py' => "def add(a, b):\n    return a + b\n"]);
        $gateway = new ai_gateway_stub('{"grade": 85, "feedback": "Clear and correct."}');

        $status = (new ai_reviewer($client, $gateway))->review($this->instance, $this->submission, $this->context);

        $this->assertSame(submission_service::AI_COMPLETED, $status);

        $result = $DB->get_record('codereview_airesults', ['submission' => $this->submission->id]);
        $this->assertEqualsWithDelta(85.0, (float) $result->suggestedgrade, 0.0001);
        $this->assertSame('Clear and correct.', $result->feedback);
    }

    /**
     * With no AI weight the provider is never contacted, so no student code leaves
     * the site at all.
     *
     * @return void
     */
    public function test_zero_weight_makes_no_external_call(): void {
        global $DB;

        $this->instance->weightai = 0;
        $DB->update_record('codereview', $this->instance);

        $client = $this->client_with(['main.py' => 'print(1)']);
        $gateway = new ai_gateway_stub('{"grade": 85, "feedback": "x"}');

        $status = (new ai_reviewer($client, $gateway))->review($this->instance, $this->submission, $this->context);

        $this->assertSame(submission_service::AI_SKIPPED, $status);
        $this->assertSame(0, $gateway->generatecalls);
        $this->assertSame([], $client->get_calls());
        $this->assertSame(0, $DB->count_records('codereview_airesults', ['submission' => $this->submission->id]));
    }

    /**
     * Dependency directories and binaries never reach the prompt.
     *
     * @return void
     */
    public function test_dependencies_and_binaries_are_filtered_out(): void {
        $client = $this->client_with([
            'main.py' => 'print("hello")',
            'node_modules/left-pad/index.js' => 'module.exports = 1;',
            'vendor/autoload.php' => '<?php return 1;',
            'amd/build/app.min.js' => 'var a=1;',
            'logo.png' => 'PNGDATA',
            'docs/readme.md' => '# Title',
        ]);
        $gateway = new ai_gateway_stub('{"grade": 50, "feedback": "ok"}');

        (new ai_reviewer($client, $gateway))->review($this->instance, $this->submission, $this->context);

        $this->assertStringContainsString('main.py', $gateway->lastuserprompt);
        $this->assertStringContainsString('docs/readme.md', $gateway->lastuserprompt);
        $this->assertStringNotContainsString('node_modules', $gateway->lastuserprompt);
        $this->assertStringNotContainsString('vendor/autoload.php', $gateway->lastuserprompt);
        $this->assertStringNotContainsString('amd/build', $gateway->lastuserprompt);
        $this->assertStringNotContainsString('logo.png', $gateway->lastuserprompt);
    }

    /**
     * The results of the automated checks go into the prompt, so the reviewer knows
     * what already failed.
     *
     * @return void
     */
    public function test_check_results_reach_the_prompt(): void {
        global $DB;

        $DB->insert_record('codereview_checkruns', (object) [
            'submission' => $this->submission->id,
            'externalid' => 1,
            'checkname' => 'pytest',
            'conclusion' => 'failure',
            'counted' => 1,
            'timecreated' => time(),
        ]);

        $client = $this->client_with(['main.py' => 'print(1)']);
        $gateway = new ai_gateway_stub('{"grade": 40, "feedback": "Tests fail."}');

        (new ai_reviewer($client, $gateway))->review($this->instance, $this->submission, $this->context);

        $this->assertStringContainsString('pytest: failure', $gateway->lastuserprompt);
    }

    /**
     * Answers the provider might plausibly return that must not be trusted.
     *
     * @return array[]
     */
    public static function bad_response_provider(): array {
        return [
            'prose instead of json' => ['The student did well overall.'],
            'grade above the maximum' => ['{"grade": 5000, "feedback": "great"}'],
            'negative grade' => ['{"grade": -10, "feedback": "bad"}'],
            'grade is not a number' => ['{"grade": "excellent", "feedback": "great"}'],
            'feedback missing' => ['{"grade": 50}'],
            'empty feedback' => ['{"grade": 50, "feedback": "   "}'],
            'empty answer' => [''],
        ];
    }

    /**
     * Model output is untrusted input: anything unusable is recorded as an error
     * rather than reaching the suggestion table.
     *
     * @dataProvider bad_response_provider
     * @param string $response The raw provider answer.
     * @return void
     */
    public function test_unusable_responses_are_rejected(string $response): void {
        global $DB;

        $client = $this->client_with(['main.py' => 'print(1)']);
        $status = (new ai_reviewer($client, new ai_gateway_stub($response)))
            ->review($this->instance, $this->submission, $this->context);

        $this->assertSame(submission_service::AI_ERROR, $status);
        $this->assertSame(0, $DB->count_records('codereview_airesults', [
            'submission' => $this->submission->id,
            'status' => 'completed',
        ]));
    }

    /**
     * Markup in the feedback is stripped before it is stored, so a later template
     * cannot be made to render provider-authored HTML.
     *
     * @return void
     */
    public function test_feedback_is_stripped_of_markup(): void {
        global $DB;

        $client = $this->client_with(['main.py' => 'print(1)']);
        $gateway = new ai_gateway_stub('{"grade": 50, "feedback": "Nice <script>alert(1)</script> work"}');

        (new ai_reviewer($client, $gateway))->review($this->instance, $this->submission, $this->context);

        $feedback = $DB->get_field('codereview_airesults', 'feedback', ['submission' => $this->submission->id]);
        $this->assertStringNotContainsString('<script>', $feedback);
        $this->assertStringContainsString('Nice', $feedback);
    }

    /**
     * A JSON object wrapped in explanatory prose or a fenced block is still read,
     * because that is what providers commonly return.
     *
     * @return void
     */
    public function test_json_wrapped_in_prose_is_recovered(): void {
        $client = $this->client_with(['main.py' => 'print(1)']);
        $fence = str_repeat(chr(96), 3);
        $gateway = new ai_gateway_stub(
            "Here is my review:\n" . $fence . "json\n" . '{"grade": 70, "feedback": "Good"}' . "\n" . $fence
        );

        $this->assertSame(
            submission_service::AI_COMPLETED,
            (new ai_reviewer($client, $gateway))->review($this->instance, $this->submission, $this->context)
        );
    }
}
