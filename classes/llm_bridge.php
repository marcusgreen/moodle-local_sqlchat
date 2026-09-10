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

namespace local_sqlchat;

/**
 * Calls the configured LLM backend directly, with no dependency on tool_ai_bridge.
 *
 * Supports the same three backends tool_ai_bridge did:
 * - core_ai_subsystem: Moodle 4.5+ core AI framework (always available; the default).
 * - local_ai_manager: MEBIS local AI manager (optional plugin).
 * - tool_aimanager: AIConnect tool plugin (optional plugin).
 *
 * If the configured backend's plugin is not installed, silently falls back to
 * core_ai_subsystem rather than failing, so this plugin never hard-requires
 * either optional backend.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class llm_bridge {
    /** @var int Context ID for AI requests. */
    private int $contextid;

    /** @var string Resolved backend key: core_ai_subsystem, local_ai_manager, or tool_aimanager. */
    private string $backend;

    /** @var string Name of the calling plugin (used in error strings). */
    private string $plugin;

    /**
     * @param int $contextid Context to pass to the backend.
     * @param string $plugin Name of the calling plugin (frankenstyle component).
     * @param string $backend Requested backend key; falls back to core_ai_subsystem if its
     *  plugin is not installed.
     */
    public function __construct(int $contextid, string $plugin, string $backend = 'core_ai_subsystem') {
        $this->contextid = $contextid;
        $this->plugin = $plugin;
        $this->backend = $this->resolve_backend($backend);
    }

    /**
     * Downgrade to core_ai_subsystem when the requested backend's plugin is missing.
     *
     * @param string $backend Requested backend key.
     * @return string Backend key that is actually usable.
     */
    private function resolve_backend(string $backend): string {
        if ($backend === 'local_ai_manager' && !class_exists('\local_ai_manager\manager')) {
            return 'core_ai_subsystem';
        }
        if ($backend === 'tool_aimanager' && !class_exists('\tool_aiconnect\ai\ai')) {
            return 'core_ai_subsystem';
        }
        return $backend;
    }

    /**
     * Send a prompt to the LLM via the resolved backend.
     *
     * @param string $prompt The prompt to send.
     * @param string $purpose Purpose identifier for local_ai_manager routing.
     * @return string The AI response content.
     * @throws \moodle_exception If the backend errors or returns no content.
     */
    public function perform_request(string $prompt, string $purpose = 'feedback'): string {
        if ($this->backend === 'local_ai_manager') {
            $manager = new \local_ai_manager\manager($purpose);
            $llmresponse = $manager->perform_request($prompt, $this->plugin, $this->contextid);
            if ($llmresponse->get_code() !== 200) {
                throw new \moodle_exception(
                    'err_retrievingfeedback',
                    $this->plugin,
                    '',
                    'code=' . $llmresponse->get_code() . ' ' . $llmresponse->get_errormessage(),
                    $llmresponse->get_debuginfo()
                );
            }
            return $llmresponse->get_content();
        } else if ($this->backend === 'core_ai_subsystem') {
            global $USER;
            $action = new \core_ai\aiactions\generate_text(
                contextid: $this->contextid,
                userid: $USER->id,
                prompttext: $prompt
            );
            $manager = \core\di::get(\core_ai\manager::class);
            $llmresponse = $manager->process_action($action);
            if (!$llmresponse->get_success()) {
                throw new \moodle_exception(
                    'err_retrievingfeedback',
                    $this->plugin,
                    '',
                    $llmresponse->get_errormessage(),
                    'code=' . $llmresponse->get_errorcode() .
                        ' error=' . $llmresponse->get_error() .
                        ' model=' . ($llmresponse->get_model_used() ?? 'n/a')
                );
            }
            $responsedata = $llmresponse->get_response_data();
            if (is_null($responsedata) || !is_array($responsedata) || !array_key_exists('generatedcontent', $responsedata)) {
                throw new \moodle_exception('err_retrievingfeedback_checkconfig', $this->plugin);
            }
            if (is_null($responsedata['generatedcontent'])) {
                throw new \moodle_exception(
                    'err_retrievingfeedback',
                    $this->plugin,
                    '',
                    'empty generatedcontent',
                    'model=' . ($llmresponse->get_model_used() ?? 'n/a') .
                        ' responsedata=' . json_encode($responsedata)
                );
            }
            return $responsedata['generatedcontent'];
        } else if ($this->backend === 'tool_aimanager') {
            $ai = new \tool_aiconnect\ai\ai();
            $llmresponse = $ai->prompt_completion($prompt);
            $content = $llmresponse['response']['choices'][0]['message']['content'] ?? null;
            if ($content === null) {
                $error = $llmresponse['response']['error']['message']
                    ?? $llmresponse['error']
                    ?? 'no content in response';
                throw new \moodle_exception(
                    'err_retrievingfeedback',
                    $this->plugin,
                    '',
                    $error,
                    json_encode($llmresponse)
                );
            }
            return $content;
        }
        throw new \moodle_exception('err_invalidbackend', 'local_sqlchat');
    }
}
