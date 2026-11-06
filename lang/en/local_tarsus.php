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
 * English strings for local_tarsus.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH | Vadym Nersesov <nersesov.vadim@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['apikey'] = 'API key';
$string['apikey_desc'] = 'The Tarsus API key. Stored site-wide and never sent to the browser.';
$string['baseurl'] = 'Base URL';
$string['baseurl_desc'] = 'The Tarsus API base URL (data residency: Germany). Enter only the host, without the /api/v1/... path.';
$string['blocked_breaker'] = 'Outbound call blocked: the circuit breaker is open.';
$string['blocked_budget'] = 'Outbound call blocked: the credit budget is exhausted.';
$string['blocked_killswitch'] = 'Outbound call blocked: the kill switch is engaged.';
$string['blocked_ratelimit'] = 'Outbound call blocked: the rate limit was reached.';
$string['brain_activate'] = 'Activate';
$string['brain_activate_failed'] = 'That brain cannot be activated: it is unknown to this API key or no longer active.';
$string['brain_activated'] = 'This site now uses brain "{$a}".';
$string['brain_active'] = 'Active';
$string['brain_col_action'] = 'Action';
$string['brain_col_created'] = 'Created';
$string['brain_col_id'] = 'Brain ID';
$string['brain_col_language'] = 'Language';
$string['brain_col_status'] = 'State';
$string['brain_col_tag'] = 'Tag';
$string['brain_deleted'] = 'Deleted';
$string['brain_gotosettings'] = 'Back to Tarsus settings';
$string['brain_inactive'] = 'Inactive';
$string['brain_inuse'] = 'In use';
$string['brain_missing'] = 'This site is configured to use brain "{$a}", but the API key no longer reports it. It was most likely deleted. Activate an existing brain below.';
$string['brain_none_found'] = 'The API key returned no brains. Check the base URL and API key, or ask Tarsus to activate the key.';
$string['brain_none_selected'] = 'No brain is selected yet. Indexing and chat stay blocked until you activate one.';
$string['brainid'] = 'Brain ID';
$string['brainid_desc'] = 'The single Tarsus brain used by this site.';
$string['brains'] = 'Tarsus brains';
$string['brains_desc'] = 'Every brain reachable by the API key above, with its state. <a href="{$a}">Open the brain list</a> to pick the one this site uses.';
$string['creditbudget'] = 'Credit budget (per period)';
$string['creditbudget_desc'] = 'Maximum aiCredits spent per period before calls are refused.';
$string['deletions'] = 'Tarsus deletions';
$string['deletions_none'] = 'Everything removed from this site has been removed from the brain.';
$string['deletions_pending'] = 'The brain has not confirmed {$a} deletion(s). This happens when it is unreachable at the moment content is deleted; the content is gone from Moodle but may still answer questions.';
$string['deletions_resource'] = 'Resource';
$string['deletions_retried'] = '{$a} deletion(s) completed.';
$string['deletions_retry'] = 'Retry now';
$string['deletions_since'] = 'Awaiting removal since';
$string['killswitch'] = 'Kill switch';
$string['killswitch_desc'] = 'When enabled, all outbound calls are blocked immediately. Status reads keep working; the queue holds.';
$string['lockfailed'] = 'Could not acquire the guardrail state lock.';
$string['mockmode'] = 'Mock mode';
$string['mockmode_desc'] = 'When enabled, all Tarsus traffic is served by a deterministic mock — no live API calls.';
$string['nobrainselected'] = 'No Tarsus brain is selected. Pick one in the Tarsus brains page.';
$string['notconfigured'] = 'Tarsus is not configured: set the base URL, API key and brain ID, or enable mock mode.';
$string['pluginname'] = 'Tarsus';
$string['privacy:metadata:local_tarsus_usage'] = 'Local ledger of Tarsus operations for usage attribution.';
$string['privacy:metadata:local_tarsus_usage:contextid'] = 'The context in which the operation occurred.';
$string['privacy:metadata:local_tarsus_usage:credits'] = 'The aiCredits consumed by the operation.';
$string['privacy:metadata:local_tarsus_usage:operation'] = 'The operation performed (e.g. grounded query).';
$string['privacy:metadata:local_tarsus_usage:timecreated'] = 'When the operation occurred.';
$string['privacy:metadata:local_tarsus_usage:userid'] = 'The user who triggered the operation.';
$string['privacy:metadata:tarsus'] = 'Data transmitted to the Tarsus RAG engine to ground answers in course material.';
$string['privacy:metadata:tarsus:language'] = 'The user\'s interface language, so the assistant answers in it.';
$string['privacy:metadata:tarsus:query'] = 'The query text sent for grounded retrieval.';
$string['privacy:metadata:tarsus:userid'] = 'A stable pseudonym (HMAC of the Moodle user id); email addresses are never sent. As the pseudonym is reversible it remains personal data.';
$string['privacy:metadata:tarsus:username'] = 'The user\'s display name, so the assistant can address them personally in the chat.';
$string['ratelimit'] = 'Rate limit (calls per minute)';
$string['ratelimit_desc'] = 'Maximum outbound calls per minute (token bucket).';
$string['robotavatar'] = 'Assistant avatar';
$string['robotavatar_desc'] = 'The image shown next to the assistant\'s answers in the chat. Square images work best.';
$string['robotdescription'] = 'Assistant description';
$string['robotdescription_desc'] = 'A short description of the assistant, e.g. its role or instructions.';
$string['robotid'] = 'Assistant ID';
$string['robotid_desc'] = 'The database id of this assistant on the Tarsus side, as provided by Tarsus.';
$string['robotname'] = 'Assistant name';
$string['robotname_desc'] = 'The global name of the AI assistant, as learners see it.';
$string['settings_assistant'] = 'Assistant';
$string['settings_assistant_desc'] = 'How the AI assistant introduces itself in the activity chat. One identity for the whole organisation.';
$string['settings_connection'] = 'Tarsus connection';
$string['settings_connection_desc'] = 'Connect this site to Tarsus. One brain per client — entered once, no lifecycle management.';
$string['settings_guardrails'] = 'Guardrails';
$string['settings_guardrails_desc'] = 'Bound availability and credit spend. These protect the endpoint and the budget.';
$string['status:error'] = 'Error';
$string['status:indexed'] = 'Indexed';
$string['status:notindexed'] = 'Not indexed';
$string['status:pending'] = 'Pending';
$string['status:pendingconfirmation'] = 'Awaiting confirmation';
$string['status:pendingdeletion'] = 'Pending removal';
$string['status:pendingreindex'] = 'Content changed';
$string['transporterror'] = 'Tarsus transport error (HTTP {$a}).';
$string['voice'] = 'Voice';
$string['voice_desc'] = 'A voice from the brain\'s list. Used for everyone in fixed mode, and as the fallback in automatic mode.';
$string['voice_female'] = 'female';
$string['voice_label'] = '{$a->alias} ({$a->language}, {$a->gender})';
$string['voice_male'] = 'male';
$string['voice_mode'] = 'Voice selection';
$string['voice_mode_auto'] = 'By the learner\'s language (the voice below when the language has none)';
$string['voice_mode_desc'] = 'How the voice that reads learning units aloud is chosen.';
$string['voice_mode_fixed'] = 'One voice for everyone';
