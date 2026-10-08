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
use context_system;

/**
 * Tests for the AI gateway.
 *
 * The hub is not installed where these run, so its two entry points are replaced by a
 * subclass that records what it was asked, which is all that matters here.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_codereview\local\ai_gateway
 */
final class ai_gateway_test extends advanced_testcase {
    /**
     * Builds a gateway whose hub is a recorder.
     *
     * @return ai_gateway
     */
    private function recording_gateway(): ai_gateway {
        return new class extends ai_gateway {
            /** @var array The users the hub was asked about, one entry per question. */
            public array $asked = [];

            #[\Override]
            protected function hub_installed(): bool {
                return true;
            }

            #[\Override]
            protected function hub_is_available(?int $userid): bool {
                $this->asked[] = ['available', $userid];

                return true;
            }

            #[\Override]
            protected function hub_generate(string $system, string $user, ?int $userid): array {
                $this->asked[] = ['generate', $userid];

                return ['success' => true, 'data' => 'hello', 'provider' => 'Groq', 'model' => 'm', 'message' => ''];
            }
        };
    }

    /**
     * An owner's id reaches the hub, which is what lets it use that teacher's personal keys
     * when the request is made by cron, where nobody is logged in.
     *
     * @return void
     */
    public function test_the_owner_reaches_the_hub(): void {
        $gateway = $this->recording_gateway();
        $context = context_system::instance();

        $this->assertTrue($gateway->is_available($context, 42));
        $result = $gateway->generate('system', 'user', $context, 42);

        $this->assertTrue($result['success']);
        $this->assertSame('hello', $result['text']);
        $this->assertSame([['available', 42], ['generate', 42]], $gateway->asked);
    }

    /**
     * With no owner the hub is asked the way it always was, for whoever is logged in.
     *
     * @return void
     */
    public function test_without_an_owner_the_hub_decides_by_itself(): void {
        $gateway = $this->recording_gateway();
        $context = context_system::instance();

        $gateway->is_available($context);
        $gateway->generate('system', 'user', $context);

        $this->assertSame([['available', null], ['generate', null]], $gateway->asked);
    }
}
