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

use context;

/**
 * Resolves an AI provider and asks it for text.
 *
 * Tries local_aihub first, because when it is present it already owns the key
 * ladder and the usage log, then falls back to the core_ai subsystem. Isolating
 * this behind one class keeps ai_reviewer testable without either being installed.
 *
 * @package    mod_codereview
 * @copyright  2026 Jean Lúcio
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_gateway {
    /**
     * Returns true when some provider is expected to answer.
     *
     * @param context $context The course or activity context the request belongs to.
     * @return bool
     */
    public function is_available(context $context): bool {
        if (class_exists(\local_aihub\ai::class) && \local_aihub\ai::is_available()) {
            return true;
        }

        return $this->core_ai_available($context);
    }

    /**
     * Asks a provider for a completion.
     *
     * @param string $system The system prompt.
     * @param string $user The user prompt.
     * @param context $context The course or activity context the request belongs to.
     * @return array{success: bool, text: string, provider: string, model: string, error: string}
     */
    public function generate(string $system, string $user, context $context): array {
        if (class_exists(\local_aihub\ai::class)) {
            $result = \local_aihub\ai::generate_text(
                $system,
                $user,
                true,
                'mod_codereview',
                'Code review suggestion'
            );

            if (!empty($result->success)) {
                return [
                    'success' => true,
                    'text' => (string) ($result->text ?? ''),
                    'provider' => 'local_aihub',
                    'model' => (string) ($result->model ?? ''),
                    'error' => '',
                ];
            }
        }

        return $this->generate_with_core_ai($system, $user, $context);
    }

    /**
     * Asks the core_ai subsystem for a completion.
     *
     * @param string $system The system prompt.
     * @param string $user The user prompt.
     * @param context $context The course or activity context the request belongs to.
     * @return array{success: bool, text: string, provider: string, model: string, error: string}
     */
    protected function generate_with_core_ai(string $system, string $user, context $context): array {
        $failure = ['success' => false, 'text' => '', 'provider' => '', 'model' => '', 'error' => 'noprovider'];

        if (!$this->core_ai_available($context)) {
            return $failure;
        }

        try {
            $manager = \core\di::get(\core_ai\manager::class);
            $action = new \core_ai\aiactions\generate_text(
                contextid: $context->id,
                userid: (int) $GLOBALS['USER']->id,
                prompttext: $system . "\n\n" . $user,
            );

            $response = $manager->process_action($action);
            if (!$response->get_success()) {
                return array_merge($failure, ['error' => (string) $response->get_errormessage()]);
            }

            $data = $response->get_response_data();

            return [
                'success' => true,
                'text' => (string) ($data['generatedcontent'] ?? ''),
                'provider' => 'core_ai',
                'model' => (string) ($data['model'] ?? ''),
                'error' => '',
            ];
        } catch (\Throwable $e) {
            return array_merge($failure, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Returns true when core_ai can run a text generation in this context.
     *
     * Respects the per-course "Enable AI tools" override where core supports it. The
     * check does not exist on Moodle 4.5, where its absence must read as "allowed"
     * rather than blocking the feature outright.
     *
     * @param context $context The course or activity context the request belongs to.
     * @return bool
     */
    protected function core_ai_available(context $context): bool {
        if (!class_exists(\core_ai\manager::class)) {
            return false;
        }

        try {
            $manager = \core\di::get(\core_ai\manager::class);
        } catch (\Throwable $e) {
            return false;
        }

        if (method_exists($manager, 'is_action_enabled_in_context')) {
            return (bool) $manager->is_action_enabled_in_context($context, \core_ai\aiactions\generate_text::class);
        }

        return true;
    }
}
