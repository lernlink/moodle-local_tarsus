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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * English strings for local_tarsus.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Tarsus foundation';

// Connection settings.
$string['settings_connection'] = 'Tarsus connection';
$string['settings_connection_desc'] = 'Connect this site to Tarsus. One brain per client — entered once, no lifecycle management.';
$string['apikey'] = 'API key';
$string['apikey_desc'] = 'The Tarsus API key. Stored site-wide and never sent to the browser.';
$string['brainid'] = 'Brain ID';
$string['brainid_desc'] = 'The single Tarsus brain used by this site.';
$string['baseurl'] = 'Base URL';
$string['baseurl_desc'] = 'The Tarsus API base URL (data residency: Germany).';

// Guardrail settings.
$string['settings_guardrails'] = 'Guardrails';
$string['settings_guardrails_desc'] = 'Bound availability and credit spend. These protect the endpoint and the budget.';
$string['mockmode'] = 'Mock mode';
$string['mockmode_desc'] = 'When enabled, all Tarsus traffic is served by a deterministic mock — no live API calls.';
$string['killswitch'] = 'Kill switch';
$string['killswitch_desc'] = 'When enabled, all outbound calls are blocked immediately. Status reads keep working; the queue holds.';
$string['ratelimit'] = 'Rate limit (calls per minute)';
$string['ratelimit_desc'] = 'Maximum outbound calls per minute (token bucket).';
$string['creditbudget'] = 'Credit budget (per period)';
$string['creditbudget_desc'] = 'Maximum aiCredits spent per period before calls are refused.';

// Errors.
$string['transporterror'] = 'Tarsus transport error (HTTP {$a}).';
$string['lockfailed'] = 'Could not acquire the guardrail state lock.';
$string['blocked_killswitch'] = 'Outbound call blocked: the kill switch is engaged.';
$string['blocked_breaker'] = 'Outbound call blocked: the circuit breaker is open.';
$string['blocked_budget'] = 'Outbound call blocked: the credit budget is exhausted.';
$string['blocked_ratelimit'] = 'Outbound call blocked: the rate limit was reached.';

// Privacy.
$string['privacy:metadata:local_tarsus_usage'] = 'Local ledger of Tarsus operations for usage attribution.';
$string['privacy:metadata:local_tarsus_usage:contextid'] = 'The context in which the operation occurred.';
$string['privacy:metadata:local_tarsus_usage:userid'] = 'The user who triggered the operation.';
$string['privacy:metadata:local_tarsus_usage:operation'] = 'The operation performed (e.g. grounded query).';
$string['privacy:metadata:local_tarsus_usage:credits'] = 'The aiCredits consumed by the operation.';
$string['privacy:metadata:local_tarsus_usage:timecreated'] = 'When the operation occurred.';
$string['privacy:metadata:tarsus'] = 'Data transmitted to the Tarsus RAG engine to ground answers in course material.';
$string['privacy:metadata:tarsus:userid'] = 'A stable pseudonym (HMAC of the Moodle user id); names and emails are never sent. As the pseudonym is reversible it remains personal data.';
$string['privacy:metadata:tarsus:query'] = 'The query text sent for grounded retrieval.';
