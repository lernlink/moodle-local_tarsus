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
 * German strings for local_tarsus.
 *
 * @package    local_tarsus
 * @copyright  2026 lern.link GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Tarsus-Grundlage';

// Verbindungseinstellungen.
$string['settings_connection'] = 'Tarsus-Verbindung';
$string['settings_connection_desc'] = 'Diese Website mit Tarsus verbinden. Ein Brain pro Kunde — einmalig eingetragen, keine Verwaltung des Lebenszyklus.';
$string['apikey'] = 'API-Schlüssel';
$string['apikey_desc'] = 'Der Tarsus-API-Schlüssel. Wird websiteweit gespeichert und nie an den Browser gesendet.';
$string['brainid'] = 'Brain-ID';
$string['brainid_desc'] = 'Das einzige von dieser Website genutzte Tarsus-Brain.';
$string['baseurl'] = 'Basis-URL';
$string['baseurl_desc'] = 'Die Basis-URL der Tarsus-API (Datenstandort: Deutschland).';

// Guardrail-Einstellungen.
$string['settings_guardrails'] = 'Schutzmechanismen';
$string['settings_guardrails_desc'] = 'Begrenzen Verfügbarkeit und Credit-Verbrauch. Sie schützen den Endpunkt und das Budget.';
$string['mockmode'] = 'Mock-Modus';
$string['mockmode_desc'] = 'Wenn aktiviert, wird der gesamte Tarsus-Verkehr von einem deterministischen Mock bedient — keine Live-API-Aufrufe.';
$string['killswitch'] = 'Not-Aus';
$string['killswitch_desc'] = 'Wenn aktiviert, werden alle ausgehenden Aufrufe sofort blockiert. Statusabfragen funktionieren weiter; die Warteschlange bleibt erhalten.';
$string['ratelimit'] = 'Ratenbegrenzung (Aufrufe pro Minute)';
$string['ratelimit_desc'] = 'Maximale Anzahl ausgehender Aufrufe pro Minute (Token-Bucket).';
$string['creditbudget'] = 'Credit-Budget (pro Zeitraum)';
$string['creditbudget_desc'] = 'Maximale aiCredits pro Zeitraum, bevor Aufrufe abgelehnt werden.';

// Fehler.
$string['transporterror'] = 'Tarsus-Übertragungsfehler (HTTP {$a}).';
$string['notconfigured'] = 'Tarsus ist nicht konfiguriert: Basis-URL, API-Schlüssel und Brain-ID hinterlegen oder den Mock-Modus aktivieren.';
$string['lockfailed'] = 'Die Sperre für den Schutzmechanismus-Status konnte nicht erlangt werden.';
$string['blocked_killswitch'] = 'Ausgehender Aufruf blockiert: Der Not-Aus ist aktiviert.';
$string['blocked_breaker'] = 'Ausgehender Aufruf blockiert: Der Schutzschalter ist offen.';
$string['blocked_budget'] = 'Ausgehender Aufruf blockiert: Das Credit-Budget ist aufgebraucht.';
$string['blocked_ratelimit'] = 'Ausgehender Aufruf blockiert: Die Ratenbegrenzung wurde erreicht.';

// Geplante Aufgaben.
$string['task:processqueue'] = 'Tarsus-Indexierungswarteschlange verarbeiten';
$string['task:reconcile'] = 'Tarsus-Indexstatus abgleichen';

// Bezeichnungen für den Indexierungsstatus.
$string['status:notindexed'] = 'Nicht indexiert';
$string['status:pending'] = 'Ausstehend';
$string['status:pendingconfirmation'] = 'Warten auf Bestätigung';
$string['status:indexed'] = 'Indexiert';
$string['status:pendingreindex'] = 'Neuindexierung';
$string['status:pendingdeletion'] = 'Entfernung ausstehend';
$string['status:error'] = 'Fehler';

// Datenschutz.
$string['privacy:metadata:local_tarsus_usage'] = 'Lokales Protokoll der Tarsus-Vorgänge zur Nutzungszuordnung.';
$string['privacy:metadata:local_tarsus_usage:contextid'] = 'Der Kontext, in dem der Vorgang stattfand.';
$string['privacy:metadata:local_tarsus_usage:userid'] = 'Die Person, die den Vorgang ausgelöst hat.';
$string['privacy:metadata:local_tarsus_usage:operation'] = 'Der ausgeführte Vorgang (z. B. fundierte Abfrage).';
$string['privacy:metadata:local_tarsus_usage:credits'] = 'Die vom Vorgang verbrauchten aiCredits.';
$string['privacy:metadata:local_tarsus_usage:timecreated'] = 'Zeitpunkt des Vorgangs.';
$string['privacy:metadata:tarsus'] = 'An die Tarsus-RAG-Engine übermittelte Daten, um Antworten in Kursmaterialien zu verankern.';
$string['privacy:metadata:tarsus:userid'] = 'Ein stabiles Pseudonym (HMAC der Moodle-Benutzer-ID); Namen und E-Mail-Adressen werden nie gesendet. Da das Pseudonym umkehrbar ist, bleibt es personenbezogen.';
$string['privacy:metadata:tarsus:query'] = 'Der für die fundierte Abfrage gesendete Abfragetext.';
